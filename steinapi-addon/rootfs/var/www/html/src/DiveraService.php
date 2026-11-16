<?php
/**
 * DiveraService Class
 * Based on original working SteinAPI project: DiveraAPI.php
 *
 * WICHTIG:
 * - Endpoint: pull/vehicle-status (NICHT pull/all!)
 * - Kennzeichen-Feld in Divera: 'number'
 * - Kennzeichen-Feld in Stein: 'name'
 * - Die Divera-API erwartet den Access Key als Query-Parameter (API-Design von Divera).
 *   Deshalb werden ausschließlich https-Verbindungen mit Zertifikatsprüfung zugelassen.
 */

class DiveraService {
    private $config;
    private $logger;

    private $lastError = null;

    // Divera FMS -> Stein Status. Nicht umkehrbar: 3 und 4 sind in Stein beide
    // "inuse". FMS 0, 5, 7, 8 und 9 haben in Stein keine Entsprechung.
    const FMS_TO_STEIN = [
        1 => 'semiready',
        2 => 'ready',
        3 => 'inuse',
        4 => 'inuse',
        6 => 'notready'
    ];

    // Stein Status -> Divera FMS. "maint" und "notready" werden beide zu 6.
    const STEIN_TO_FMS = [
        'ready' => 2,
        'semiready' => 1,
        'notready' => 6,
        'inuse' => 3,
        'maint' => 6
    ];

    public function __construct(array $config, Logger $logger) {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Stein-Status zu einem FMS-Status, oder null ohne Entsprechung.
     */
    public static function fmsToStein($fms): ?string {
        if (!is_numeric($fms)) {
            return null;
        }
        return self::FMS_TO_STEIN[(int)$fms] ?? null;
    }

    /**
     * FMS-Status zu einem Stein-Status, oder null bei unbekanntem Status.
     */
    public static function steinToFms($steinStatus): ?int {
        if (!is_string($steinStatus)) {
            return null;
        }
        return self::STEIN_TO_FMS[$steinStatus] ?? null;
    }

    /**
     * Fehlermeldung des letzten fehlgeschlagenen setVehicleStatus(), sonst null.
     */
    public function getLastError(): ?string {
        return $this->lastError;
    }

    /**
     * Teste die Verbindung zur Divera API
     */
    public function testConnection(): array {
        try {
            $response = $this->request('GET', 'pull/vehicle-status');
            $count = is_array($response['data'] ?? null) ? count($response['data']) : 0;
            return [
                'success' => true,
                'message' => 'Connection successful',
                'vehicle_count' => $count
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Hole Fahrzeugstatus aus Divera
     * Original: getVehicleStatus()
     *
     * WICHTIG:
     * - Verwendet 'pull/vehicle-status' Endpoint
     * - Kennzeichen ist im Feld 'number' (nicht 'name'!)
     * - Rückgabe ist direkt $response['data']
     */
    public function getVehicleStatus(): array {
        // Routine-Meldungen eines Laufs stehen auf debug: bei 288 Läufen am Tag
        // verdrängen sie sonst Fehler und Schreibzugriffe aus dem Protokoll.
        $this->logger->debug('Fetching vehicle status from Divera (pull/vehicle-status)');

        try {
            $response = $this->request('GET', 'pull/vehicle-status');
            $vehicles = $response['data'] ?? [];
            if (!is_array($vehicles)) {
                $vehicles = [];
            }

            $this->logger->debug('Fetched ' . count($vehicles) . ' vehicles from Divera');

            // Debug: Zeige alle Fahrzeuge mit ihren Kennzeichen
            foreach ($vehicles as $v) {
                $this->logger->debug('Divera vehicle', [
                    'id' => $v['id'] ?? 'no-id',
                    'number' => $v['number'] ?? 'no-number',  // DAS IST DAS KENNZEICHEN!
                    'name' => $v['name'] ?? 'no-name',
                    'fmsstatus' => $v['fmsstatus'] ?? 0
                ]);
            }

            return $vehicles;

        } catch (Exception $e) {
            $this->logger->error('Failed to fetch Divera vehicles: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Alias für Kompatibilität
     */
    public function getVehicles(): array {
        return $this->getVehicleStatus();
    }

    /**
     * Setze FMS-Status und Notiz eines Fahrzeugs in Divera.
     *
     * Die Notiz wird immer mitgeschickt, auch leer: nur so wird eine in Stein
     * gelöschte Notiz auch in Divera gelöscht. Divera-Notizen sind einzeilig,
     * Zeilenumbrüche werden zu Leerzeichen.
     */
    public function setVehicleStatus(string $vehicleId, int $fmsStatus, string $note = ''): bool {
        $this->lastError = null;
        $this->logger->info('Setting vehicle status in Divera', [
            'vehicleId' => $vehicleId,
            'fmsStatus' => $fmsStatus
        ]);

        // Fahrzeug-IDs sind numerisch - alles andere gehört nicht in den URL-Pfad
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $vehicleId)) {
            $this->lastError = 'Invalid Divera vehicle id';
            $this->logger->error('Invalid Divera vehicle id', ['vehicleId' => $vehicleId]);
            return false;
        }

        if ($fmsStatus < 0 || $fmsStatus > 9) {
            $this->lastError = "Invalid FMS status $fmsStatus";
            $this->logger->error('Invalid FMS status', ['vehicleId' => $vehicleId, 'fmsStatus' => $fmsStatus]);
            return false;
        }

        try {
            $payload = [
                'status' => $fmsStatus,
                'status_id' => $fmsStatus,
                'status_note' => trim((string)preg_replace('/\s*\R\s*/', ' ', $note))
            ];

            $this->logger->debug('Divera setVehicleStatus payload', $payload);

            $this->request('POST', "using-vehicles/set-status/{$vehicleId}", $payload);
            return true;

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->logger->error('Failed to set Divera vehicle status: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * HTTP Request an Divera API
     * Basiert auf Original makeRequest()
     * (protected, damit tests/sync_simulation.php die Gegenstelle ersetzen kann)
     */
    protected function request(string $method, string $endpoint, ?array $data = null): array {
        // Basis-URL + Endpoint
        $url = rtrim($this->config['base_url'], '/') . '/' . ltrim($endpoint, '/');

        // Access Key hinzufügen (von der Divera-API so vorgegeben)
        $url .= (strpos($url, '?') === false ? '?' : '&') . 'accesskey=' . urlencode($this->config['access_key']);

        $this->logger->debug('Divera API request', [
            'method' => $method,
            'endpoint' => $endpoint
        ]);

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            // TLS-Zertifikat IMMER prüfen - sonst kann ein MITM den Access Key abgreifen
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Keine Redirects folgen (Access Key darf nicht an fremde Hosts gesendet werden)
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json']
        ];

        // Nur https zulassen
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        if ($method === 'POST' && $data) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'Accept: application/json'];
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Divera API curl error: $error");
        }

        if ($httpCode !== 200) {
            throw new Exception("Divera API request failed with code $httpCode: " . substr((string)$response, 0, 500));
        }

        $decoded = json_decode((string)$response, true);
        if ($decoded === null && !empty($response)) {
            throw new Exception("Divera API invalid JSON response");
        }

        return is_array($decoded) ? $decoded : [];
    }
}
