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

    // FMS Status Mapping - direkt aus dem Original!
    const FMS_STEIN_MAP = [
        // Divera FMS -> Stein Status
        1 => 'semiready',
        2 => 'ready',
        3 => 'inuse',
        4 => 'inuse',
        6 => 'notready',
        // Stein Status -> Divera FMS
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
        $this->logger->info('Fetching vehicle status from Divera (pull/vehicle-status)');

        try {
            $response = $this->request('GET', 'pull/vehicle-status');
            $vehicles = $response['data'] ?? [];
            if (!is_array($vehicles)) {
                $vehicles = [];
            }

            $this->logger->info('Fetched ' . count($vehicles) . ' vehicles from Divera');

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
     * Setze Fahrzeugstatus in Divera
     * Original: setVehicleStatus($vehicleId, $data)
     */
    public function setVehicleStatus(string $vehicleId, array $data): bool {
        $this->logger->info('Setting vehicle status in Divera', [
            'vehicleId' => $vehicleId,
            'status' => $data['status'] ?? 'unknown'
        ]);

        // Fahrzeug-IDs sind numerisch - alles andere gehört nicht in den URL-Pfad
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $vehicleId)) {
            $this->logger->error('Invalid Divera vehicle id', ['vehicleId' => $vehicleId]);
            return false;
        }

        try {
            $payload = [
                'status' => self::FMS_STEIN_MAP[$data['status'] ?? ''] ?? 6,
                'status_id' => self::FMS_STEIN_MAP[$data['status'] ?? ''] ?? 6
            ];

            if (!empty($data['comment'])) {
                $payload['status_note'] = str_replace("\n", " ", (string)$data['comment']);
            }

            $this->logger->debug('Divera setVehicleStatus payload', $payload);

            $this->request('POST', "using-vehicles/set-status/{$vehicleId}", $payload);
            return true;

        } catch (Exception $e) {
            $this->logger->error('Failed to set Divera vehicle status: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * HTTP Request an Divera API
     * Basiert auf Original makeRequest()
     */
    private function request(string $method, string $endpoint, ?array $data = null): array {
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
