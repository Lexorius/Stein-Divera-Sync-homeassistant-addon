<?php
/**
 * SyncService Class
 *
 * Synchronisiert FMS-Status und Notiz zwischen Divera24-7 und Stein.app.
 *
 * MATCHING:
 * - Divera Kennzeichen: Feld 'number'
 * - Stein Kennzeichen: Feld 'name'
 * - Stein Filter: nur die konfigurierten Gruppen (Standard 1 und 5)
 * - Match: Stein 'name' === Divera 'number'
 *
 * ABGLEICH (seit 2.2.0) - je Feld (Status, Notiz) gegen den zuletzt
 * synchronisierten Stand aus der Tabelle sync_state:
 * - nur Stein weicht vom letzten Stand ab  -> Stein  -> Divera
 * - nur Divera weicht vom letzten Stand ab -> Divera -> Stein
 * - beide weichen ab (echter Konflikt) oder es gibt noch keinen
 *   gespeicherten Stand -> die jüngere Seite (Zeitstempel) gewinnt
 *
 * Vorher entschied allein der Zeitstempel für das ganze Fahrzeug. Da jeder
 * Schreibvorgang des Syncs diesen Zeitstempel selbst verändert, wurden Werte,
 * die sich nicht verlustfrei abbilden lassen (FMS 3/4, maint/notready,
 * Zeilenumbrüche, leere Notiz), im nächsten Lauf in Gegenrichtung
 * zurückgeschrieben.
 */

class SyncService {
    private const LOCK_NAME = 'steinapi_sync';

    private $db;
    private $divera;
    private $stein;
    private $logger;
    private $config;
    private $lockHeld = false;
    private $stateAvailable = true;

    public function __construct(
        Database $db,
        DiveraService $divera,
        SteinService $stein,
        Logger $logger,
        array $config
    ) {
        $this->db = $db;
        $this->divera = $divera;
        $this->stein = $stein;
        $this->logger = $logger;
        $this->config = $config;

        // Setze Zeitzone
        date_default_timezone_set('Europe/Berlin');
    }

    // ------------------------------------------------------------------
    // Normalisierung
    // ------------------------------------------------------------------

    /**
     * Einheitliche Vergleichsform einer Notiz: Divera-Notizen sind einzeilig,
     * deshalb werden Zeilenumbrüche (\r\n, \r, \n) zu einem Leerzeichen und
     * der Text getrimmt. null wird zu ''.
     * Ein mehrzeiliger Stein-Kommentar gilt damit als gleich zu seiner
     * einzeiligen Divera-Fassung und wird nicht zurückgeschrieben.
     */
    public static function normalizeComment($comment): string {
        $comment = is_scalar($comment) ? (string)$comment : '';
        $normalized = preg_replace('/\s*\R\s*/u', ' ', $comment);
        if ($normalized === null) {
            // Ungültiges UTF-8: ohne /u-Modifier ersetzen
            $normalized = str_replace(["\r\n", "\r", "\n"], ' ', $comment);
        }
        return trim($normalized);
    }

    /**
     * Stein kennt "maint" und "notready", Divera für beides nur FMS 6.
     * Für den Vergleich zählt "maint" deshalb als "notready"; so wird ein
     * "maint" in Stein nicht durch den Sync auf "notready" gekippt.
     */
    public static function canonicalStatus($steinStatus): ?string {
        if (!is_string($steinStatus) || $steinStatus === '') {
            return null;
        }
        return $steinStatus === 'maint' ? 'notready' : $steinStatus;
    }

    // ------------------------------------------------------------------
    // Sperre und gespeicherter Stand
    // ------------------------------------------------------------------

    /**
     * Verhindert parallele Läufe (Cron, Dashboard, externe Aufrufe).
     * Gibt false zurück, wenn bereits ein Lauf aktiv ist.
     */
    private function acquireLock(): bool {
        try {
            $row = $this->db->fetchOne("SELECT GET_LOCK(?, 0) AS acquired", [self::LOCK_NAME]);
        } catch (Exception $e) {
            // Sperre nicht verfügbar: lieber ohne Sperre synchronisieren als gar nicht
            $this->logger->warning('Could not acquire sync lock: ' . $e->getMessage());
            return true;
        }

        if ((int)($row['acquired'] ?? 0) !== 1) {
            return false;
        }
        $this->lockHeld = true;
        return true;
    }

    private function releaseLock(): void {
        if (!$this->lockHeld) {
            return;
        }
        $this->lockHeld = false;
        try {
            $this->db->fetchOne("SELECT RELEASE_LOCK(?) AS released", [self::LOCK_NAME]);
        } catch (Exception $e) {
            $this->logger->warning('Could not release sync lock: ' . $e->getMessage());
        }
    }

    private function fetchState(): array {
        $state = [];
        foreach ($this->db->query("SELECT vehicle_key, last_status, last_comment FROM sync_state") as $row) {
            $state[$row['vehicle_key']] = $row;
        }
        return $state;
    }

    /**
     * Zuletzt synchronisierter Stand je Fahrzeug. install.sql läuft nur bei
     * der Erstinstallation, deshalb legt der Sync die Tabelle bei bestehenden
     * Installationen selbst an. Ist sie nicht verfügbar, entscheidet wie
     * früher allein der Zeitstempel.
     */
    private function loadState(): array {
        $this->stateAvailable = true;
        try {
            return $this->fetchState();
        } catch (Exception $e) {
            try {
                $this->db->execute("
                    CREATE TABLE IF NOT EXISTS sync_state (
                        vehicle_key VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
                        last_status VARCHAR(20) NULL,
                        last_comment TEXT NULL,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
                $this->logger->info('Created table sync_state');
                return $this->fetchState();
            } catch (Exception $e2) {
                $this->stateAvailable = false;
                $this->logger->warning('sync_state not available, falling back to timestamps: ' . $e2->getMessage());
                return [];
            }
        }
    }

    private function saveState(string $vehicleKey, ?string $status, ?string $comment): void {
        try {
            $this->db->execute(
                "INSERT INTO sync_state (vehicle_key, last_status, last_comment) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE last_status = VALUES(last_status), last_comment = VALUES(last_comment)",
                [$vehicleKey, $status, $comment]
            );
        } catch (Exception $e) {
            $this->stateAvailable = false;
            $this->logger->warning("Could not save sync state for $vehicleKey: " . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Synchronisation
    // ------------------------------------------------------------------

    /**
     * Hauptsync-Funktion
     */
    public function sync(string $direction = 'both', string $source = 'manual'): array {
        $this->logger->info("Starting sync ($direction) from $source");

        $timezone = new DateTimeZone('Europe/Berlin');
        $syncStartTime = new DateTime('now', $timezone);

        $results = [
            'direction' => $direction,
            'source' => $source,
            'started_at' => $syncStartTime->format('c'),
            'synced' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'busy' => false,
            'details' => []
        ];

        if (!$this->acquireLock()) {
            // Ein anderer Lauf (Cron oder Dashboard) ist noch aktiv
            $this->logger->info("Sync from $source skipped: another sync is running");
            $results['busy'] = true;
            $results['success'] = true;
            $results['completed_at'] = date('c');
            return $results;
        }

        try {
            // Hole Daten von beiden Systemen
            $diveraData = $this->divera->getVehicleStatus();
            $steinData = $this->stein->getAssets();
            $state = $this->loadState();

            $this->logger->info('Fetched data', [
                'divera_count' => count($diveraData),
                'stein_count' => count($steinData)
            ]);

            // Divera: Index by 'number' (das Kennzeichen!)
            $diveraAssets = [];
            foreach ($diveraData as $asset) {
                if (is_array($asset) && !empty($asset['number'])) {
                    $diveraAssets[$asset['number']] = $asset;
                }
            }

            // Stein: Index by 'name' (das Kennzeichen!), nur bestimmte Gruppen
            $steinAssets = [];
            $allowedGroups = $this->config['stein_group_ids'] ?? [1, 5];

            foreach ($steinData as $asset) {
                if (is_array($asset)
                    && in_array($asset['groupId'] ?? 0, $allowedGroups)
                    && !empty($asset['name'])
                ) {
                    $steinAssets[$asset['name']] = $asset;
                }
            }

            $this->logger->info('Indexed assets', [
                'divera_indexed' => count($diveraAssets),
                'stein_indexed' => count($steinAssets),
                'divera_keys' => array_keys($diveraAssets),
                'stein_keys' => array_keys($steinAssets)
            ]);

            // Iteriere über Stein Assets und finde Matches in Divera
            foreach ($steinAssets as $name => $steinAsset) {
                if (!isset($diveraAssets[$name])) {
                    $this->logger->debug('No matching Divera asset for Stein', [
                        'stein_name' => $name
                    ]);
                    $results['skipped']++;
                    continue;
                }

                $syncResult = $this->syncVehicle(
                    $diveraAssets[$name],
                    $steinAsset,
                    $direction,
                    $state[$name] ?? null
                );

                if ($syncResult === null) {
                    $results['skipped']++;
                    continue;
                }

                $results['details'][] = $syncResult;
                if ($syncResult['success']) {
                    $results['synced']++;
                    $results['updated']++;
                } else {
                    $results['errors']++;
                }
            }

            $results['completed_at'] = date('c');
            $results['success'] = true;
            // false = Tabelle sync_state nicht nutzbar, es galt nur der Zeitstempel
            $results['state_tracking'] = $this->stateAvailable;

            $this->updateLastSync();
            $this->logger->info('Sync completed', $results);

        } catch (Exception $e) {
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            $results['errors']++;
            $this->logger->error('Sync failed: ' . $e->getMessage());
        } finally {
            $this->releaseLock();
        }

        $this->saveSyncHistory($results);

        return $results;
    }

    /**
     * Entscheidet für ein Feld, wohin geschrieben wird.
     * Rückgabe: 'divera' (Stein -> Divera) oder 'stein' (Divera -> Stein).
     */
    private function decideDirection(
        string $direction,
        string $steinValue,
        string $diveraValue,
        ?string $baseValue,
        bool $steinNewer
    ): string {
        if ($direction === 'stein_to_divera' || $direction === 'divera') {
            return 'divera';
        }
        if ($direction === 'divera_to_stein' || $direction === 'stein') {
            return 'stein';
        }

        if ($baseValue !== null) {
            $steinChanged = $steinValue !== $baseValue;
            $diveraChanged = $diveraValue !== $baseValue;

            if ($steinChanged && !$diveraChanged) {
                return 'divera';
            }
            if ($diveraChanged && !$steinChanged) {
                return 'stein';
            }
        }

        // Konflikt (beide geändert) oder kein gespeicherter Stand
        return $steinNewer ? 'divera' : 'stein';
    }

    private function steinTimestamp(array $steinAsset): int {
        if (empty($steinAsset['lastModified']) || !is_string($steinAsset['lastModified'])) {
            return 0;
        }
        try {
            // Die Zeitzone gilt nur für Zeitstempel OHNE Zonenangabe;
            // Werte mit "Z" oder Offset sind davon unabhängig.
            $zone = new DateTimeZone($this->config['stein_timezone'] ?? 'Europe/Berlin');
            return (new DateTime($steinAsset['lastModified'], $zone))->getTimestamp();
        } catch (Exception $e) {
            $this->logger->warning('Could not parse Stein timestamp', [
                'lastModified' => $steinAsset['lastModified']
            ]);
            return 0;
        }
    }

    /**
     * Gleicht ein Fahrzeug ab. Gibt null zurück, wenn nichts zu schreiben war.
     */
    private function syncVehicle(array $diveraAsset, array $steinAsset, string $direction, ?array $base): ?array {
        $key = (string)$steinAsset['name'];
        $vehicle = (string)($diveraAsset['name'] ?? $diveraAsset['number'] ?? $key);

        $steinStatusRaw = $steinAsset['status'] ?? null;
        $steinStatus = self::canonicalStatus($steinStatusRaw);
        $diveraFms = $diveraAsset['fmsstatus'] ?? null;
        // null, wenn der Divera-Status in Stein keine Entsprechung hat (0, 5, 7, 8, 9)
        $diveraStatus = DiveraService::fmsToStein($diveraFms);

        $steinComment = self::normalizeComment($steinAsset['comment'] ?? '');
        $diveraComment = self::normalizeComment($diveraAsset['fmsstatus_note'] ?? '');

        $steinTs = $this->steinTimestamp($steinAsset);
        $diveraTs = (int)($diveraAsset['fmsstatus_ts'] ?? 0);
        $steinNewer = $steinTs > $diveraTs;

        $baseStatus = $base['last_status'] ?? null;
        $baseComment = $base['last_comment'] ?? null;
        $newBaseStatus = $baseStatus;
        $newBaseComment = $baseComment;

        // --- Status ---
        $statusDir = null;
        $statusSyncable = $steinStatus !== null
            && $diveraStatus !== null
            && DiveraService::steinToFms($steinStatusRaw) !== null;

        if ($statusSyncable) {
            if ($steinStatus === $diveraStatus) {
                $newBaseStatus = $steinStatus;
            } else {
                $statusDir = $this->decideDirection($direction, $steinStatus, $diveraStatus, $baseStatus, $steinNewer);
            }
        }

        // --- Notiz ---
        $commentDir = null;
        if ($steinComment === $diveraComment) {
            $newBaseComment = $steinComment;
        } else {
            $commentDir = $this->decideDirection($direction, $steinComment, $diveraComment, $baseComment, $steinNewer);
        }

        $toDivera = [];
        $toStein = [];
        if ($statusDir === 'divera') { $toDivera[] = 'status'; }
        if ($statusDir === 'stein') { $toStein[] = 'status'; }
        if ($commentDir === 'divera') { $toDivera[] = 'comment'; }
        if ($commentDir === 'stein') { $toStein[] = 'comment'; }

        // Divera nimmt eine Notiz nur zusammen mit einem Status an. Steht das
        // Fahrzeug dort auf einem Status ohne Stein-Entsprechung (z. B. 5 =
        // Sprechwunsch), wird dieser nicht erneut gesendet, um keine
        // Alarmierung auszulösen. Die Notiz folgt, sobald der Status wieder
        // zuordenbar ist.
        if ($toDivera === ['comment'] && $diveraStatus === null) {
            $this->logger->debug('Comment to Divera deferred: Divera status has no Stein equivalent', [
                'vehicle' => $vehicle,
                'divera_fms' => $diveraFms
            ]);
            $toDivera = [];
        }

        $this->logger->debug('Sync decision', [
            'vehicle' => $vehicle,
            'stein_status' => $steinStatusRaw,
            'divera_fms' => $diveraFms,
            'base_status' => $baseStatus,
            'stein_comment' => $steinComment,
            'divera_note' => $diveraComment,
            'base_comment' => $baseComment,
            'stein_ts' => $steinTs,
            'divera_ts' => $diveraTs,
            'to_divera' => $toDivera,
            'to_stein' => $toStein
        ]);

        $synced = [];
        $errors = [];

        // --- Stein -> Divera ---
        if ($toDivera) {
            $fms = in_array('status', $toDivera, true)
                ? DiveraService::steinToFms($steinStatusRaw)
                : (int)$diveraFms; // Status unverändert lassen (FMS 4 bleibt 4)
            $note = in_array('comment', $toDivera, true) ? $steinComment : $diveraComment;

            $this->logger->info('Syncing Stein to Divera', ['vehicle' => $vehicle, 'fields' => $toDivera]);

            if ($this->divera->setVehicleStatus((string)($diveraAsset['id'] ?? ''), (int)$fms, $note)) {
                $synced = array_merge($synced, $toDivera);
                if (in_array('status', $toDivera, true)) { $newBaseStatus = $steinStatus; }
                if (in_array('comment', $toDivera, true)) { $newBaseComment = $steinComment; }
            } else {
                $errors[] = 'Divera: ' . ($this->divera->getLastError() ?: 'Update fehlgeschlagen');
            }
        }

        // --- Divera -> Stein ---
        if ($toStein) {
            $payload = [];
            if (in_array('status', $toStein, true)) { $payload['status'] = $diveraStatus; }
            if (in_array('comment', $toStein, true)) { $payload['comment'] = $diveraComment; }

            $this->logger->info('Syncing Divera to Stein', ['vehicle' => $vehicle, 'fields' => $toStein]);

            if ($this->stein->updateAsset((string)($steinAsset['id'] ?? ''), $payload)) {
                $synced = array_merge($synced, $toStein);
                if (in_array('status', $toStein, true)) { $newBaseStatus = $diveraStatus; }
                if (in_array('comment', $toStein, true)) { $newBaseComment = $diveraComment; }
            } else {
                $errors[] = 'Stein: ' . ($this->stein->getLastError() ?: 'Update fehlgeschlagen');
            }
        }

        // Gespeicherten Stand nur für Felder fortschreiben, die jetzt
        // nachweislich auf beiden Seiten gleich sind.
        if ($base === null || $newBaseStatus !== $baseStatus || $newBaseComment !== $baseComment) {
            $this->saveState($key, $newBaseStatus, $newBaseComment);
        }

        if (!$toDivera && !$toStein) {
            return null;
        }

        if ($toDivera && $toStein) {
            $action = 'both';
        } else {
            $action = $toDivera ? 'stein_to_divera' : 'divera_to_stein';
        }

        $result = [
            'vehicle' => $vehicle,
            'divera_id' => $diveraAsset['id'] ?? null,
            'stein_id' => $steinAsset['id'] ?? null,
            'action' => $action,
            'success' => empty($errors),
            'fields_synced' => $synced
        ];

        if ($errors) {
            $result['error'] = implode('; ', $errors);
            $this->logger->error('Sync failed for vehicle', [
                'vehicle' => $vehicle,
                'error' => $result['error']
            ]);
        }

        return $result;
    }

    /**
     * Aktualisiere letzte Sync-Zeit
     */
    private function updateLastSync(): void {
        try {
            $this->db->execute(
                "INSERT INTO settings (name, value) VALUES ('last_sync', ?)
                 ON DUPLICATE KEY UPDATE value = ?",
                [date('c'), date('c')]
            );
        } catch (Exception $e) {
            // Ignore
        }
    }

    /**
     * Speichere Sync-History: genau EINE Zeile je Lauf.
     * Bis 2.2.1 kam zusätzlich eine Zeile je Fahrzeug dazu, wodurch die Kachel
     * "Synchronisiert" (Summe über synced_count) jedes Fahrzeug doppelt zählte.
     */
    private function saveSyncHistory(array $stats): void {
        try {
            $this->db->insert('sync_history', [
                'direction' => $stats['direction'],
                'source' => $stats['source'],
                'synced_count' => $stats['synced'],
                'created_count' => 0,
                'updated_count' => $stats['updated'],
                'error_count' => $stats['errors'],
                'success' => ($stats['success'] ?? false) ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to save sync history: ' . $e->getMessage());
        }
    }

    /**
     * Hole Statistiken
     */
    public function getStats(): array {
        $stats = [
            'last_sync' => null,
            'synced_today' => 0,
            'errors_24h' => 0,
            'status' => 'healthy',
            'auto_sync_enabled' => $this->config['auto_enabled'] ?? true,
            'next_sync' => null
        ];

        try {
            // Letzte Sync-Zeit
            $lastSync = $this->db->fetchOne(
                "SELECT * FROM sync_history ORDER BY created_at DESC LIMIT 1"
            );

            if ($lastSync) {
                $stats['last_sync'] = $lastSync['created_at'];

                if ($stats['auto_sync_enabled']) {
                    $interval = ($this->config['interval_minutes'] ?? 5) * 60;
                    $lastSyncTime = strtotime($lastSync['created_at']);
                    $stats['next_sync'] = date('c', $lastSyncTime + $interval);
                }
            }

            // Syncs heute
            $today = $this->db->fetchOne(
                "SELECT SUM(synced_count) as total FROM sync_history WHERE DATE(created_at) = CURDATE()"
            );
            $stats['synced_today'] = (int)($today['total'] ?? 0);

            // Fehler in 24h
            $errors = $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM sync_logs WHERE level = 'error' AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            $stats['errors_24h'] = (int)($errors['count'] ?? 0);

            // Status bestimmen
            if ($stats['errors_24h'] > 10) {
                $stats['status'] = 'error';
            } elseif ($stats['errors_24h'] > 0) {
                $stats['status'] = 'warning';
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to get stats: ' . $e->getMessage());
        }

        return $stats;
    }
}
