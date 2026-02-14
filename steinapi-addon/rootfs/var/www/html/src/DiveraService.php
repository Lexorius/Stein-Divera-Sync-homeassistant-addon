<?php
/**
 * DiveraService Class
 * Handles communication with Divera24-7 API
 * 
 * Divera API Dokumentation: https://api.divera247.com/
 * 
 * WICHTIG: Diese Integration synchronisiert FAHRZEUGE zwischen Divera und Stein.app
 * Die Zuordnung erfolgt über das KENNZEICHEN (muss in beiden Systemen identisch sein)
 */

class DiveraService {
    private $config;
    private $logger;
    
    public function __construct(array $config, Logger $logger) {
        $this->config = $config;
        $this->logger = $logger;
    }
    
    /**
     * Teste die Verbindung zur Divera API
     */
    public function testConnection(): array {
        try {
            $response = $this->request('GET', '/pull/all');
            
            $vehicleCount = 0;
            if (isset($response['data']['cluster']['vehicle'])) {
                $vehicleCount = count($response['data']['cluster']['vehicle']);
            }
            
            return [
                'success' => true,
                'message' => 'Connection successful',
                'vehicle_count' => $vehicleCount
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Hole alle Fahrzeuge aus Divera
     * Die Zuordnung zu Stein.app erfolgt über das Kennzeichen!
     */
    public function getVehicles(): array {
        $this->logger->info('Fetching vehicles from Divera');
        
        try {
            $response = $this->request('GET', '/pull/all');
            $vehicles = [];
            
            if (isset($response['data']['cluster']['vehicle'])) {
                foreach ($response['data']['cluster']['vehicle'] as $id => $vehicle) {
                    $mapped = $this->mapVehicle($id, $vehicle);
                    if ($mapped) {
                        $vehicles[] = $mapped;
                    }
                }
            }
            
            $this->logger->info('Fetched ' . count($vehicles) . ' vehicles from Divera');
            return $vehicles;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to fetch Divera vehicles: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Hole ein einzelnes Fahrzeug
     */
    public function getVehicle(string $id): ?array {
        try {
            $vehicles = $this->getVehicles();
            foreach ($vehicles as $vehicle) {
                if ($vehicle['id'] == $id) {
                    return $vehicle;
                }
            }
            return null;
        } catch (Exception $e) {
            $this->logger->error('Failed to fetch vehicle: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Aktualisiere Fahrzeugstatus in Divera
     */
    public function updateVehicleStatus(string $id, int $status): bool {
        $this->logger->info('Updating vehicle status in Divera', ['id' => $id, 'status' => $status]);
        
        try {
            // Divera verwendet FMS-Status (1-6)
            $this->request('POST', '/v2/using-vehicle', [
                'vehicle_id' => $id,
                'status' => $status
            ]);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update Divera vehicle status: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Mappe Divera Fahrzeug zu internem Format
     */
    private function mapVehicle(string $id, array $data): ?array {
        // Kennzeichen ist das wichtigste Feld für die Zuordnung!
        $licensePlate = $data['name'] ?? '';
        
        // Wenn kein Kennzeichen vorhanden, überspringen
        if (empty($licensePlate)) {
            $this->logger->debug('Vehicle without license plate skipped', ['id' => $id]);
            return null;
        }
        
        // Normalisiere Kennzeichen (Leerzeichen und Bindestriche entfernen)
        $normalizedPlate = $this->normalizeLicensePlate($licensePlate);
        
        return [
            'id' => $id,
            'license_plate' => $licensePlate,
            'license_plate_normalized' => $normalizedPlate,
            'name' => $data['shortname'] ?? $licensePlate,
            'fullname' => $data['fullname'] ?? '',
            'status' => $this->mapDiveraStatus($data['fmsstatus'] ?? 0),
            'fms_status' => $data['fmsstatus'] ?? 0,
            'group_id' => $data['group'] ?? null,
            'issi' => $data['issi'] ?? '',
            'note' => $data['note'] ?? '',
            'color' => $data['color'] ?? '',
            'icon' => $data['icon'] ?? '',
            'ordering' => $data['ordering'] ?? 0,
            'visible' => $data['visible'] ?? true,
            'source' => 'divera'
        ];
    }
    
    /**
     * Normalisiere Kennzeichen für Vergleich
     * Entfernt Leerzeichen, Bindestriche und macht alles Großbuchstaben
     */
    private function normalizeLicensePlate(string $plate): string {
        // Entferne Leerzeichen und Bindestriche
        $normalized = preg_replace('/[\s\-]/', '', $plate);
        // Großbuchstaben
        return strtoupper($normalized);
    }
    
    /**
     * Mappe Divera FMS-Status zu Stein.app Status
     * 
     * Divera FMS Status:
     * 0 = Nicht gesetzt
     * 1 = Einsatzbereit auf Funk
     * 2 = Einsatzbereit auf Wache
     * 3 = Einsatz übernommen / Anfahrt
     * 4 = Am Einsatzort
     * 5 = Sprechwunsch
     * 6 = Nicht einsatzbereit
     * 
     * Stein.app Status:
     * - ready
     * - notready
     * - semiready
     * - inuse
     * - maint
     */
    private function mapDiveraStatus(int $fmsStatus): string {
        switch ($fmsStatus) {
            case 1:
            case 2:
                return 'ready';
            case 3:
            case 4:
                return 'inuse';
            case 5:
                return 'semiready';
            case 6:
                return 'notready';
            default:
                return 'ready';
        }
    }
    
    /**
     * Mappe Stein.app Status zurück zu Divera FMS-Status
     */
    public function mapSteinStatusToDivera(string $steinStatus): int {
        switch ($steinStatus) {
            case 'ready':
                return 2; // Einsatzbereit auf Wache
            case 'inuse':
                return 3; // Einsatz übernommen
            case 'semiready':
                return 5; // Sprechwunsch (bedingt einsatzbereit)
            case 'notready':
            case 'maint':
                return 6; // Nicht einsatzbereit
            default:
                return 2;
        }
    }
    
    /**
     * HTTP Request an Divera API
     */
    private function request(string $method, string $endpoint, array $data = null): array {
        $url = rtrim($this->config['base_url'], '/') . $endpoint;
        
        // Add access key
        $separator = strpos($url, '?') !== false ? '&' : '?';
        $url .= $separator . 'accesskey=' . urlencode($this->config['access_key']);
        
        // Debug: Log request details (ohne Access Key im Log)
        $logUrl = preg_replace('/accesskey=[^&]+/', 'accesskey=***', $url);
        $this->logger->debug('Divera API Request', [
            'method' => $method,
            'url' => $logUrl,
            'endpoint' => $endpoint,
            'base_url' => $this->config['base_url'],
            'has_data' => $data !== null
        ]);
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);
        
        if ($method === 'POST' || $method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data !== null) {
                $jsonData = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
                $this->logger->debug('Divera API Request Body', ['body' => $jsonData]);
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $curlInfo = curl_getinfo($ch);
        curl_close($ch);
        
        // Debug: Log response details
        $this->logger->debug('Divera API Response', [
            'http_code' => $httpCode,
            'total_time' => $curlInfo['total_time'],
            'response_length' => strlen($response),
            'response_preview' => substr($response, 0, 500)
        ]);
        
        if ($error) {
            $this->logger->error('Divera API CURL Error', [
                'error' => $error,
                'url' => $logUrl
            ]);
            throw new Exception('Divera API error: ' . $error);
        }
        
        if ($httpCode >= 400) {
            $this->logger->error('Divera API HTTP Error', [
                'http_code' => $httpCode,
                'url' => $logUrl,
                'response' => $response
            ]);
            throw new Exception('Divera API returned error code: ' . $httpCode . ' - Response: ' . substr($response, 0, 200));
        }
        
        $decoded = json_decode($response, true);
        if ($decoded === null && $response !== 'null' && !empty($response)) {
            $this->logger->error('Divera API Invalid JSON', [
                'response' => substr($response, 0, 500)
            ]);
            throw new Exception('Invalid JSON response from Divera');
        }
        
        return $decoded ?? [];
    }
    
    // ========================================
    // Legacy-Methoden für Kompatibilität
    // ========================================
    
    /**
     * @deprecated Use getVehicles() instead
     */
    public function getMembers(): array {
        $this->logger->warning('getMembers() is deprecated - this integration syncs VEHICLES, not members');
        return [];
    }
    
    /**
     * @deprecated
     */
    public function updateMember(string $id, array $data): bool {
        $this->logger->warning('updateMember() is deprecated');
        return false;
    }
}
