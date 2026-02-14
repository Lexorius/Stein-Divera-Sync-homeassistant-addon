<?php
/**
 * SteinService Class
 * Handles communication with Stein.app API
 * 
 * API Documentation: https://stein.app/api/api/doc/intro
 * OpenAPI Spec: https://stein.app/api/api/doc/api-doc.yaml
 * 
 * WICHTIG: Die Stein.app API synchronisiert ASSETS (Fahrzeuge, Anhänger, Geräte)
 * und NICHT Mitglieder/Helfer!
 */

class SteinService {
    private $config;
    private $logger;
    
    // Korrekte Base URL
    private const BASE_URL = 'https://stein.app/api';
    
    public function __construct(array $config, Logger $logger) {
        $this->config = $config;
        $this->logger = $logger;
    }
    
    /**
     * Teste die Verbindung zur Stein.app API
     */
    public function testConnection(): array {
        $this->logger->info('Testing Stein.app connection', [
            'base_url' => self::BASE_URL,
            'bu_id' => $this->config['business_unit_id'],
            'api_key_length' => strlen($this->config['api_key'] ?? '')
        ]);
        
        try {
            // Teste zuerst userinfo Endpoint
            $userInfo = $this->request('GET', '/api/ext/userinfo');
            
            // Dann teste BU Endpoint
            $buId = $this->config['business_unit_id'];
            $buInfo = $this->request('GET', '/api/ext/bu/' . $buId);
            
            return [
                'success' => true,
                'message' => 'Connection successful',
                'base_url' => self::BASE_URL,
                'user_info' => $userInfo,
                'bu_info' => [
                    'id' => $buInfo['id'] ?? null,
                    'name' => $buInfo['name'] ?? null
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'base_url' => self::BASE_URL,
                'bu_id' => $this->config['business_unit_id']
            ];
        }
    }
    
    /**
     * Hole alle Assets (Fahrzeuge, Anhänger, etc.) eines Ortsverbands
     */
    public function getAssets(): array {
        $buId = $this->config['business_unit_id'];
        $this->logger->info('Fetching assets from Stein.app', ['bu_id' => $buId]);
        
        try {
            // buIds ist ein Array-Parameter
            $response = $this->request('GET', '/api/ext/assets/', ['buIds' => [$buId]]);
            $assets = [];
            
            if (is_array($response)) {
                foreach ($response as $asset) {
                    $assets[] = $this->mapAsset($asset);
                }
            }
            
            $this->logger->info('Fetched ' . count($assets) . ' assets from Stein.app');
            return $assets;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to fetch Stein.app assets: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Hole ein einzelnes Asset
     */
    public function getAsset(int $assetId): ?array {
        try {
            $response = $this->request('GET', '/api/ext/assets/' . $assetId);
            return $this->mapAsset($response);
        } catch (Exception $e) {
            $this->logger->error('Failed to fetch asset: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Aktualisiere ein Asset
     */
    public function updateAsset(int $assetId, array $data): bool {
        $this->logger->info('Updating asset in Stein.app', ['id' => $assetId]);
        
        try {
            $steinData = $this->mapToSteinFormat($data);
            $this->request('PATCH', '/api/ext/assets/' . $assetId, null, $steinData);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update Stein.app asset: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Hole Ortsverband-Informationen
     */
    public function getBu(): ?array {
        $buId = $this->config['business_unit_id'];
        
        try {
            return $this->request('GET', '/api/ext/bu/' . $buId);
        } catch (Exception $e) {
            $this->logger->error('Failed to fetch BU: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Hole User-Informationen (zum Testen der Authentifizierung)
     */
    public function getUserInfo(): ?array {
        try {
            return $this->request('GET', '/api/ext/userinfo');
        } catch (Exception $e) {
            $this->logger->error('Failed to fetch user info: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Mappe Stein Asset zu internem Format
     */
    private function mapAsset(array $data): array {
        return [
            'id' => $data['id'] ?? 0,
            'bu_id' => $data['buId'] ?? 0,
            'group_id' => $data['groupId'] ?? 0,
            'label' => $data['label'] ?? '',
            'name' => $data['name'] ?? '',
            'status' => $data['status'] ?? 'ready',
            'category' => $data['category'] ?? '',
            'comment' => $data['comment'] ?? '',
            'radio_name' => $data['radioName'] ?? '',
            'issi' => $data['issi'] ?? '',
            'sort_order' => $data['sortOrder'] ?? 0,
            'operation_reservation' => $data['operationReservation'] ?? false,
            'hu_valid_until' => $data['huValidUntil'] ?? null,
            'last_modified' => $data['lastModified'] ?? null,
            'last_modified_by' => $data['lastModifiedBy'] ?? null,
            'created' => $data['created'] ?? null,
            'deleted' => $data['deleted'] ?? false,
            'source' => 'stein'
        ];
    }
    
    /**
     * Mappe internes Format zu Stein Format
     */
    private function mapToSteinFormat(array $data): array {
        $steinData = [];
        
        $mapping = [
            'label' => 'label',
            'name' => 'name',
            'status' => 'status',
            'category' => 'category',
            'comment' => 'comment',
            'radio_name' => 'radioName',
            'issi' => 'issi',
            'sort_order' => 'sortOrder',
            'operation_reservation' => 'operationReservation',
            'hu_valid_until' => 'huValidUntil'
        ];
        
        foreach ($mapping as $internal => $stein) {
            if (isset($data[$internal])) {
                $steinData[$stein] = $data[$internal];
            }
        }
        
        return $steinData;
    }
    
    /**
     * HTTP Request an Stein.app API
     */
    private function request(string $method, string $endpoint, array $queryParams = null, array $data = null): array {
        $url = self::BASE_URL . $endpoint;
        
        // Query Parameter hinzufügen
        if ($queryParams) {
            $queryString = http_build_query($queryParams);
            $url .= '?' . $queryString;
        }
        
        // Debug: Log request details
        $this->logger->debug('Stein API Request', [
            'method' => $method,
            'url' => $url,
            'endpoint' => $endpoint,
            'has_data' => $data !== null
        ]);
        
        $ch = curl_init();
        
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->config['api_key']
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data !== null) {
                $jsonData = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
                $this->logger->debug('Stein API Request Body', ['body' => $jsonData]);
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $curlInfo = curl_getinfo($ch);
        curl_close($ch);
        
        // Debug: Log response details
        $this->logger->debug('Stein API Response', [
            'http_code' => $httpCode,
            'url' => $curlInfo['url'],
            'total_time' => $curlInfo['total_time'],
            'response_length' => strlen($response),
            'response_preview' => substr($response, 0, 500)
        ]);
        
        if ($error) {
            $this->logger->error('Stein API CURL Error', [
                'error' => $error,
                'url' => $url
            ]);
            throw new Exception('Stein.app API error: ' . $error);
        }
        
        // Spezielle Behandlung für 404 (IP nicht aus Deutschland?)
        if ($httpCode === 404) {
            $this->logger->error('Stein API 404 Error - möglicherweise IP-Beschränkung', [
                'url' => $url,
                'response' => $response,
                'hint' => 'Die Stein.app API ist auf deutsche IP-Adressen beschränkt!'
            ]);
            throw new Exception('Stein.app API 404: ' . $response . ' (Hinweis: API nur aus Deutschland erreichbar!)');
        }
        
        if ($httpCode === 401) {
            throw new Exception('Stein.app API 401 Unauthorized - API Key ungültig');
        }
        
        if ($httpCode === 429) {
            throw new Exception('Stein.app API 429 Too Many Requests - Rate Limit überschritten (max 20/min)');
        }
        
        if ($httpCode >= 400) {
            $this->logger->error('Stein API HTTP Error', [
                'http_code' => $httpCode,
                'url' => $url,
                'response' => $response
            ]);
            throw new Exception('Stein.app API HTTP ' . $httpCode . ': ' . substr($response, 0, 200));
        }
        
        $decoded = json_decode($response, true);
        if ($decoded === null && $response !== 'null' && !empty($response)) {
            $this->logger->error('Stein API Invalid JSON', [
                'response' => substr($response, 0, 500)
            ]);
            throw new Exception('Invalid JSON response from Stein.app');
        }
        
        return $decoded ?? [];
    }
    
    // ========================================
    // Legacy-Methoden für Kompatibilität
    // (Stein.app hat keine Members API!)
    // ========================================
    
    /**
     * @deprecated Stein.app hat keine Members API - nur Assets!
     */
    public function getMembers(): array {
        $this->logger->warning('getMembers() called but Stein.app only supports Assets (vehicles, equipment)');
        return [];
    }
    
    /**
     * @deprecated Stein.app hat keine Members API
     */
    public function updateMember(string $id, array $data): bool {
        $this->logger->warning('updateMember() called but Stein.app only supports Assets');
        return false;
    }
    
    /**
     * @deprecated Stein.app hat keine Members API
     */
    public function createMember(array $data): ?string {
        $this->logger->warning('createMember() called but Stein.app only supports Assets');
        return null;
    }
}
