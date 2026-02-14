<?php
/**
 * DiveraService Class
 * Based on original working SteinAPI project: DiveraAPI.php
 * 
 * WICHTIG: 
 * - Endpoint: pull/vehicle-status (NICHT pull/all!)
 * - Kennzeichen-Feld in Divera: 'number'
 * - Kennzeichen-Feld in Stein: 'name'
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
        
        try {
            $payload = [
                'status' => self::FMS_STEIN_MAP[$data['status']] ?? 6,
                'status_id' => self::FMS_STEIN_MAP[$data['status']] ?? 6
            ];
            
            if (!empty($data['comment'])) {
                $payload['status_note'] = str_replace("\n", " ", $data['comment']);
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
    private function request(string $method, string $endpoint, array $data = null): array {
        // Basis-URL + Endpoint
        $url = rtrim($this->config['base_url'], '/') . '/' . ltrim($endpoint, '/');
        
        // Access Key hinzufügen
        if (strpos($url, '?') === false) {
            $url .= '?accesskey=' . $this->config['access_key'];
        } else {
            $url .= '&accesskey=' . $this->config['access_key'];
        }
        
        $this->logger->debug('Divera API request', [
            'method' => $method,
            'endpoint' => $endpoint
        ]);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("Divera API curl error: $error");
        }
        
        if ($httpCode !== 200) {
            throw new Exception("Divera API request failed with code $httpCode: $response");
        }
        
        $decoded = json_decode($response, true);
        if ($decoded === null && !empty($response)) {
            throw new Exception("Divera API invalid JSON response");
        }
        
        return $decoded ?? [];
    }
}
