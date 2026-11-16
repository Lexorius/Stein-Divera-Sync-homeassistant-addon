<?php
// tests/sync_simulation.php
// Spielt die Synchronisationslogik ohne echte APIs und ohne Datenbank durch.
//
// Echt sind: SyncService, DiveraService, SteinService (inkl. Payload-Aufbau,
// Validierung und Cache). Ersetzt werden nur die HTTP-Schicht (request()),
// die Datenbank und der Logger.
//
// Aufruf:  php tests/sync_simulation.php
// Exit-Code 0 = alle Erwartungen erfüllt, 1 = mindestens eine nicht.
//
// Zwei Betriebsarten:
//   Standard            Datenbank im Speicher nachgebaut, läuft überall.
//   STEINAPI_REAL_DB=1  echte Database-Klasse gegen eine laufende MariaDB
//                       (im CI: im gestarteten Container). Prüft die Abfragen
//                       für Sync-Stand, Sperre und Verlauf. Schlagen die fehl,
//                       schreibt der Sync nur eine Warnung und fällt auf die
//                       alte Zeitstempel-Logik zurück - ohne diesen Test bliebe
//                       das unbemerkt.
//                       STEINAPI_SRC    = Pfad zu src/ (Standard: aus dem Repo)
//                       STEINAPI_CONFIG = Pfad zur erzeugten config.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

error_reporting(E_ALL);
$GLOBALS['warnings'] = 0;
$GLOBALS['fail'] = 0;
$GLOBALS['now'] = 1794650400;
set_error_handler(function ($no, $str, $file, $line) {
    echo "      [PHP-Warnung] $str ($file:$line)\n";
    $GLOBALS['warnings']++;
    return true;
});

$GLOBALS['realDb'] = getenv('STEINAPI_REAL_DB') === '1';
$src = getenv('STEINAPI_SRC') ?: (($argv[1] ?? dirname(__DIR__)) . '/steinapi-addon/rootfs/var/www/html/src');

// --- Ersatz für Logger und Database (gleiche Klassennamen wie im Add-on) ---

class Logger {
    public array $errors = [];
    public function log(string $level, string $message, array $context = []): void {}
    public function debug(string $message, array $context = []): void {}
    public function info(string $message, array $context = []): void {}
    public function warning(string $message, array $context = []): void {}
    public function error(string $message, array $context = []): void { $this->errors[] = $message; }
}

if ($GLOBALS['realDb']) {
    // Echte Datenbankschicht des Add-ons, Zugangsdaten aus der erzeugten config.php
    require "$src/Database.php";
    $GLOBALS['dbConfig'] = (require (getenv('STEINAPI_CONFIG') ?: '/var/www/html/config/config.php'))['database'];
} else {
    class Database {
        public array $state = [];
        public bool $lockFree = true;
        public bool $stateTableExists = true;

        public function query(string $sql, array $params = []): array {
            if (str_contains($sql, 'RELEASE_LOCK')) { return [['released' => 1]]; }
            if (str_contains($sql, 'GET_LOCK')) { return [['acquired' => $this->lockFree ? 1 : 0]]; }
            if (str_contains($sql, 'FROM sync_state')) {
                if (!$this->stateTableExists) { throw new Exception("Table 'steinapi.sync_state' doesn't exist"); }
                return array_values($this->state);
            }
            return [];
        }
        public function execute(string $sql, array $params = []): int {
            if (str_contains($sql, 'CREATE TABLE IF NOT EXISTS sync_state')) { $this->stateTableExists = true; return 0; }
            if (str_contains($sql, 'INSERT INTO sync_state')) {
                if (!$this->stateTableExists) { throw new Exception("Table 'steinapi.sync_state' doesn't exist"); }
                $this->state[$params[0]] = ['vehicle_key' => $params[0], 'last_status' => $params[1], 'last_comment' => $params[2]];
                return 1;
            }
            return 0;
        }
        public function insert(string $table, array $data): int { return 1; }
        public function fetchOne(string $sql, array $params = []): ?array { $r = $this->query($sql, $params); return $r[0] ?? null; }
    }
}

require "$src/DiveraService.php";
require "$src/SteinService.php";
require "$src/SyncService.php";

// --- Simulierte Gegenstellen ---

/** Zustand der beiden "Server". Lebt über mehrere Sync-Läufe hinweg. */
class World {
    public array $divera;
    public array $stein;
    public int $diveraWrites = 0;
    public int $steinWrites = 0;
    public bool $diveraFailNext = false;
    public bool $steinFailNext = false;
    public ?array $lastDiveraPayload = null;
}

class FakeDivera extends DiveraService {
    public function __construct(private World $w, Logger $logger) {
        parent::__construct(['base_url' => 'https://divera.invalid/api/v2', 'access_key' => 'k'], $logger);
    }
    protected function request(string $method, string $endpoint, ?array $data = null): array {
        if ($method === 'GET') {
            return ['success' => true, 'data' => [$this->w->divera]];
        }
        if ($this->w->diveraFailNext) {
            $this->w->diveraFailNext = false;
            throw new Exception('Divera API request failed with code 500: boom');
        }
        $this->w->diveraWrites++;
        $this->w->lastDiveraPayload = $data;
        $this->w->divera['fmsstatus'] = $data['status_id'];
        // Annahme zur echten API: ohne status_note bliebe die alte Notiz stehen
        if (array_key_exists('status_note', $data)) {
            $this->w->divera['fmsstatus_note'] = $data['status_note'];
        }
        $this->w->divera['fmsstatus_ts'] = $GLOBALS['now'];
        return ['success' => true];
    }
}

class FakeStein extends SteinService {
    public function __construct(private World $w, Logger $logger) {
        parent::__construct(['base_url' => 'https://stein.invalid/api', 'api_key' => 'k', 'business_unit_id' => '1'], $logger);
    }
    protected function request(string $method, string $endpoint, ?array $data = null): array {
        if ($method === 'GET') {
            return [$this->w->stein];
        }
        if ($this->w->steinFailNext) {
            $this->w->steinFailNext = false;
            throw new Exception('Stein API request failed with code 500: boom');
        }
        if (!in_array($data['status'] ?? null, ['ready', 'semiready', 'notready', 'inuse', 'maint'], true)) {
            throw new Exception('Stein API request failed with code 400: invalid status');
        }
        $this->w->steinWrites++;
        $this->w->stein = $data;
        $this->w->stein['lastModified'] = gmdate('Y-m-d\TH:i:s\Z', $GLOBALS['now']);
        return $this->w->stein;
    }
}

function iso(int $ts): string { return gmdate('Y-m-d\TH:i:s\Z', $ts); }
function j($s): string { return $s === null ? 'null' : json_encode($s, JSON_UNESCAPED_UNICODE); }

class Sim {
    public World $w;
    public Database $db;
    public Logger $log;
    private $lockHolder = null;   // zweite DB-Verbindung, die im Echtbetrieb die Sperre hält

    public function __construct(string $title, array $divera, array $stein) {
        echo "\n=== $title\n";
        $this->w = new World();
        $this->w->divera = $divera + ['id' => 11, 'name' => 'GKW 1', 'number' => 'THW-12345'];
        $this->w->stein = $stein + ['id' => 22, 'name' => 'THW-12345', 'groupId' => 1, 'label' => 'GKW 1'];
        $this->db = self::newDb();
        if ($GLOBALS['realDb']) {
            // Jeder Fall beginnt ohne gespeicherten Stand
            try { $this->db->execute('DELETE FROM sync_state'); } catch (Exception $e) { /* Tabelle fehlt: legt der Sync an */ }
        }
        $this->log = new Logger();
        echo "  Start  : " . $this->state() . "\n";
    }

    private static function newDb(): Database {
        return $GLOBALS['realDb'] ? new Database($GLOBALS['dbConfig']) : new Database();
    }

    /** Simuliert einen zweiten, noch laufenden Sync. */
    public function holdLock(): void {
        if ($GLOBALS['realDb']) {
            // Name der Sperre wie SyncService::LOCK_NAME. Die Verbindung bleibt
            // bis zum Ende des Falls offen und gibt die Sperre dann frei.
            $this->lockHolder = self::newDb();
            $this->lockHolder->query("SELECT GET_LOCK('steinapi_sync', 0) AS acquired");
        } else {
            $this->db->lockFree = false;
        }
    }

    /** Zustand vor dem Update von 2.1.0: die Tabelle gibt es noch nicht. */
    public function dropStateTable(): void {
        if ($GLOBALS['realDb']) {
            $this->db->execute('DROP TABLE IF EXISTS sync_state');
        } else {
            $this->db->stateTableExists = false;
        }
    }

    public function stateTableExists(): bool {
        if ($GLOBALS['realDb']) {
            return count($this->db->query("SHOW TABLES LIKE 'sync_state'")) === 1;
        }
        return $this->db->stateTableExists;
    }

    /** Gespeicherter Stand eines Fahrzeugs oder null. */
    public function savedState(string $key): ?array {
        if ($GLOBALS['realDb']) {
            return $this->db->fetchOne('SELECT last_status, last_comment FROM sync_state WHERE vehicle_key = ?', [$key]);
        }
        return $this->db->state[$key] ?? null;
    }
    public function state(): string {
        return sprintf("Divera %s / %s | Stein %s / %s", j($this->w->divera['fmsstatus']), j($this->w->divera['fmsstatus_note']),
            j($this->w->stein['status']), j($this->w->stein['comment']));
    }
    /** Ein Sync-Lauf mit frischen Service-Instanzen, wie bei einem echten Request. */
    public function run(string $direction = 'both'): array {
        $GLOBALS['now'] += 300;
        $svc = new SyncService($this->db, new FakeDivera($this->w, $this->log), new FakeStein($this->w, $this->log), $this->log, ['stein_group_ids' => [1, 5]]);
        $r = $svc->sync($direction, 'test');
        if ($r['busy']) {
            echo "  Lauf   : übersprungen (Sperre belegt)\n";
        } elseif ($r['details']) {
            $x = $r['details'][0];
            echo "  Lauf   : {$x['action']} " . j($x['fields_synced']) . ', success=' . j($x['success']) . (isset($x['error']) ? ', error=' . j($x['error']) : '') . "\n           " . $this->state() . "\n";
        } else {
            echo "  Lauf   : nichts zu tun" . (($r['success'] ?? false) ? '' : ' (Fehler: ' . ($r['error'] ?? '?') . ')') . "\n           " . $this->state() . "\n";
        }
        return $r;
    }
    public function steinEdit(array $c): void {
        $GLOBALS['now'] += 10;
        $this->w->stein = array_merge($this->w->stein, $c);
        $this->w->stein['lastModified'] = iso($GLOBALS['now']);
        echo "  Änderung in Stein : " . j($c) . "\n";
    }
    public function diveraEdit(array $c): void {
        $GLOBALS['now'] += 10;
        $this->w->divera = array_merge($this->w->divera, $c);
        $this->w->divera['fmsstatus_ts'] = $GLOBALS['now'];
        echo "  Änderung in Divera: " . j($c) . "\n";
    }
    public function expect(string $what, bool $ok): void {
        echo "  " . ($ok ? 'OK  ' : 'FEHL') . " : $what\n";
        if (!$ok) { $GLOBALS['fail']++; }
    }
}

$t = $GLOBALS['now'];

$x = new Sim('1) Divera-Status 4, Notizen identisch',
    ['fmsstatus' => 4, 'fmsstatus_note' => 'Einsatz Hochwasser', 'fmsstatus_ts' => $t],
    ['status' => 'inuse', 'comment' => 'Einsatz Hochwasser', 'lastModified' => iso($t - 600)]);
$x->run(); $x->run();
$x->expect('kein Schreibzugriff, Divera bleibt auf 4', $x->w->diveraWrites === 0 && $x->w->steinWrites === 0 && $x->w->divera['fmsstatus'] === 4);

$x = new Sim('2) Mehrzeiliger Kommentar in Stein (neuer)',
    ['fmsstatus' => 2, 'fmsstatus_note' => '', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => "Zeile 1\r\nZeile 2", 'lastModified' => iso($t)]);
$x->run(); $x->run(); $x->run();
$x->expect('Divera einzeilig, Stein behält Umbruch, genau ein Schreibzugriff',
    $x->w->divera['fmsstatus_note'] === 'Zeile 1 Zeile 2' && $x->w->stein['comment'] === "Zeile 1\r\nZeile 2" && $x->w->diveraWrites === 1 && $x->w->steinWrites === 0);

$x = new Sim('3) Kommentar in Stein gelöscht',
    ['fmsstatus' => 2, 'fmsstatus_note' => 'TÜV fällig', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => 'TÜV fällig', 'lastModified' => iso($t - 600)]);
$x->run(); $x->steinEdit(['comment' => '']); $x->run(); $x->run();
$x->expect('leere Notiz wird gesendet, Divera und Stein leer',
    $x->w->divera['fmsstatus_note'] === '' && $x->w->stein['comment'] === '' && array_key_exists('status_note', $x->w->lastDiveraPayload ?? []));
$s = $x->savedState('THW-12345');
$x->expect('gespeicherter Stand: leere Notiz als leerer Text, nicht als "kein Stand"', $s !== null && $s['last_comment'] === '' && $s['last_status'] === 'ready');

$x = new Sim('4) Stein "maint", neue Notiz in Divera',
    ['fmsstatus' => 6, 'fmsstatus_note' => 'Werkstatt', 'fmsstatus_ts' => $t - 600],
    ['status' => 'maint', 'comment' => 'Werkstatt', 'lastModified' => iso($t - 600)]);
$x->run(); $x->diveraEdit(['fmsstatus_note' => 'Werkstatt bis Freitag']); $x->run(); $x->run();
$x->expect('Notiz übernommen, Stein bleibt "maint"', $x->w->stein['status'] === 'maint' && $x->w->stein['comment'] === 'Werkstatt bis Freitag');

$x = new Sim('5) Sprechwunsch: Divera wechselt auf Status 5 mit Notiz',
    ['fmsstatus' => 3, 'fmsstatus_note' => '', 'fmsstatus_ts' => $t - 600],
    ['status' => 'inuse', 'comment' => '', 'lastModified' => iso($t - 600)]);
$x->run(); $x->diveraEdit(['fmsstatus' => 5, 'fmsstatus_note' => 'Sprechwunsch']); $x->run(); $x->run(); $x->run();
$x->expect('Stein bleibt "inuse" (nicht "notready"), Divera bleibt auf 5, Notiz in Stein',
    $x->w->stein['status'] === 'inuse' && $x->w->divera['fmsstatus'] === 5 && $x->w->stein['comment'] === 'Sprechwunsch' && $x->w->diveraWrites === 0);

$x = new Sim('5b) Divera-Status 5, Kommentar in Stein geändert',
    ['fmsstatus' => 5, 'fmsstatus_note' => '', 'fmsstatus_ts' => $t - 600],
    ['status' => 'inuse', 'comment' => '', 'lastModified' => iso($t - 600)]);
$x->run(); $x->steinEdit(['comment' => 'Hinweis']); $x->run();
$x->expect('Status 5 wird nicht erneut an Divera gesendet (zurückgestellt)', $x->w->diveraWrites === 0);
$x->diveraEdit(['fmsstatus' => 3]); $x->run();
$x->expect('sobald Divera wieder auf 3 steht, kommt die Notiz an', $x->w->divera['fmsstatus_note'] === 'Hinweis' && $x->w->divera['fmsstatus'] === 3);

$x = new Sim('6) Kommentar "007" in Stein (neuer), "7" in Divera',
    ['fmsstatus' => 2, 'fmsstatus_note' => '7', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => '007', 'lastModified' => iso($t)]);
$x->run();
$x->expect('Unterschied erkannt und übertragen', $x->w->divera['fmsstatus_note'] === '007');

$x = new Sim('7) Kommentar in Stein geändert, DANACH Status in Divera geändert',
    ['fmsstatus' => 2, 'fmsstatus_note' => 'alt', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => 'alt', 'lastModified' => iso($t - 600)]);
$x->run(); $x->steinEdit(['comment' => 'Neuer Hinweis aus Stein']); $x->diveraEdit(['fmsstatus' => 3]); $r = $x->run(); $x->run();
$x->expect('beide Änderungen bleiben erhalten (Notiz -> Divera, Status -> Stein)',
    $x->w->divera['fmsstatus_note'] === 'Neuer Hinweis aus Stein' && $x->w->stein['comment'] === 'Neuer Hinweis aus Stein'
    && $x->w->stein['status'] === 'inuse' && $x->w->divera['fmsstatus'] === 3 && ($r['details'][0]['action'] ?? '') === 'both');
$s = $x->savedState('THW-12345');
$x->expect('gespeicherter Stand entspricht dem Ergebnis', $s !== null && $s['last_status'] === 'inuse' && $s['last_comment'] === 'Neuer Hinweis aus Stein');

$x = new Sim('8) Echter Konflikt: Notiz auf beiden Seiten geändert, Divera zuletzt',
    ['fmsstatus' => 2, 'fmsstatus_note' => 'alt', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => 'alt', 'lastModified' => iso($t - 600)]);
$x->run(); $x->steinEdit(['comment' => 'aus Stein']); $x->diveraEdit(['fmsstatus_note' => 'aus Divera']); $x->run();
$x->expect('jüngere Seite (Divera) gewinnt', $x->w->stein['comment'] === 'aus Divera' && $x->w->divera['fmsstatus_note'] === 'aus Divera');

$x = new Sim('9) Stein-Schreibfehler: als Fehler gemeldet, nächster Lauf holt nach',
    ['fmsstatus' => 2, 'fmsstatus_note' => 'alt', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => 'alt', 'lastModified' => iso($t - 600)]);
$x->run(); $x->diveraEdit(['fmsstatus_note' => 'neu']); $x->w->steinFailNext = true; $r = $x->run();
$x->expect('errors=1 mit Fehlermeldung, kein Rück-Sync nach Divera',
    $r['errors'] === 1 && str_contains($r['details'][0]['error'] ?? '', 'Stein:') && $x->w->divera['fmsstatus_note'] === 'neu');
$x->run();
$x->expect('nachgeholt in Richtung Divera -> Stein', $x->w->stein['comment'] === 'neu' && $x->w->divera['fmsstatus_note'] === 'neu');

$x = new Sim('10) Divera-Schreibfehler: als Fehler gemeldet, nächster Lauf holt nach',
    ['fmsstatus' => 2, 'fmsstatus_note' => 'alt', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => 'alt', 'lastModified' => iso($t - 600)]);
$x->run(); $x->steinEdit(['comment' => 'neu']); $x->w->diveraFailNext = true; $r = $x->run();
$x->expect('errors=1 mit Fehlermeldung', $r['errors'] === 1 && str_contains($r['details'][0]['error'] ?? '', 'Divera:'));
$x->run();
$x->expect('nachgeholt in Richtung Stein -> Divera', $x->w->divera['fmsstatus_note'] === 'neu' && $x->w->stein['comment'] === 'neu');

$x = new Sim('11) Sperre belegt (paralleler Lauf)',
    ['fmsstatus' => 2, 'fmsstatus_note' => 'a', 'fmsstatus_ts' => $t],
    ['status' => 'ready', 'comment' => 'b', 'lastModified' => iso($t - 600)]);
$x->holdLock();
$r = $x->run();
$x->expect('busy=true, kein Schreibzugriff', $r['busy'] === true && $x->w->diveraWrites === 0 && $x->w->steinWrites === 0);

$x = new Sim('12) Statuswechsel Stein ready -> maint, danach Divera 6 -> 2',
    ['fmsstatus' => 2, 'fmsstatus_note' => '', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => '', 'lastModified' => iso($t - 600)]);
$x->run(); $x->steinEdit(['status' => 'maint']); $x->run();
$x->expect('Divera auf 6, Stein bleibt "maint"', $x->w->divera['fmsstatus'] === 6 && $x->w->stein['status'] === 'maint');
$x->diveraEdit(['fmsstatus' => 2]); $x->run();
$x->expect('Stein auf "ready"', $x->w->stein['status'] === 'ready');

$x = new Sim('13) Update von 2.1.0: Tabelle sync_state fehlt noch',
    ['fmsstatus' => 2, 'fmsstatus_note' => 'aus Divera', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => 'aus Stein', 'lastModified' => iso($t)]);
$x->dropStateTable();
$x->run(); $x->run();
$x->expect('Tabelle wird angelegt, erster Lauf entscheidet per Zeitstempel (Stein jünger), danach Ruhe',
    $x->stateTableExists() && $x->w->divera['fmsstatus_note'] === 'aus Stein' && $x->w->diveraWrites === 1 && $x->w->steinWrites === 0
    && $x->savedState('THW-12345') !== null);

$x = new Sim('14) Richtung "divera_to_stein" erzwingt Divera -> Stein',
    ['fmsstatus' => 3, 'fmsstatus_note' => 'D', 'fmsstatus_ts' => $t - 600],
    ['status' => 'ready', 'comment' => 'S', 'lastModified' => iso($t)]);
$x->run('divera_to_stein');
$x->expect('Stein übernimmt Status und Notiz aus Divera', $x->w->stein['status'] === 'inuse' && $x->w->stein['comment'] === 'D' && $x->w->diveraWrites === 0);

$x = new Sim('15) Richtung "stein_to_divera" erzwingt Stein -> Divera',
    ['fmsstatus' => 3, 'fmsstatus_note' => 'D', 'fmsstatus_ts' => $t],
    ['status' => 'ready', 'comment' => 'S', 'lastModified' => iso($t - 600)]);
$x->run('stein_to_divera');
$x->expect('Divera übernimmt Status und Notiz aus Stein', $x->w->divera['fmsstatus'] === 2 && $x->w->divera['fmsstatus_note'] === 'S' && $x->w->steinWrites === 0);

$x = new Sim('16) Stein-PATCH enthält das vollständige Asset (andere Felder bleiben erhalten)',
    ['fmsstatus' => 2, 'fmsstatus_note' => 'neu', 'fmsstatus_ts' => $t],
    ['status' => 'ready', 'comment' => 'alt', 'lastModified' => iso($t - 600), 'radioName' => 'Heros 1/51', 'category' => 'GKW']);
$x->run();
$x->expect('radioName und category unverändert', ($x->w->stein['radioName'] ?? null) === 'Heros 1/51' && ($x->w->stein['category'] ?? null) === 'GKW' && $x->w->stein['comment'] === 'neu');

echo "\n=== Datenbank: " . ($GLOBALS['realDb'] ? 'echte MariaDB' : 'im Speicher nachgebaut') . "\n";
echo "=== Ergebnis: " . ($GLOBALS['fail'] === 0 ? 'alle Erwartungen erfüllt' : $GLOBALS['fail'] . ' Erwartung(en) NICHT erfüllt') . ", PHP-Warnungen: {$GLOBALS['warnings']}\n";
exit($GLOBALS['fail'] === 0 && $GLOBALS['warnings'] === 0 ? 0 : 1);
