<?php
/**
 * SteinService Class
 * Based on original working SteinAPI project: SteinAPI.php
 * 
 * WICHTIG:
 * - Base URL: https://stein.app/api/api/ext
 * - Kennzeichen-Feld in Stein: 'name' (NICHT 'label'!)
 * - Rate Limiting: 3 Sekunden zwischen Anfragen (max 20/min)
 */

class SteinService {
    private $config;
    private $logger;
    private $lastRequestTime = 0;
    private $assets = [];  // Cache für Assets
    
    public function __construct(array $config, Logger $logger) {
        $this->config = $config;
        $this->logger = $logger;
    }
    
    /**
     * Rate Limiting - 3 Sekunden zwischen Anfragen (Original)
     */
    private function rateLimit(): void {
        $currentTime = microtime(true);
        $elapsedTime = $currentTime - $this->lastRequestTime;
        if ($elapsedTime < 3) {
            $sleepTime = (int)((3 - $elapsedTime) * 1000000);
            $this->logger->debug('Rate limiting - sleeping', ['microseconds' => $sleepTime]);
            usleep($sleepTime);
        }
        $this->lastRequestTime = microtime(true);
    }
    
    /**
     * Teste die Verbindung zur Stein API
     */
    public function testConnection(): array {
        try {
            $assets = $this->getAssets();
            return [
                'success' => true,
                'message' => 'Connection successful',
                'asset_count' => count($assets)
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Hole alle Assets von Stein.app
     * Original: getAssets()
     * 
     * WICHTIG:
     * - Kennzeichen ist im Feld 'name' (NICHT 'label'!)
     * - Ergebnis wird gecacht
     */
    public function getAssets(): array {
        // Nutze Cache wenn vorhanden
        if (!empty($this->assets)) {
            $this->logger->debug('Returning cached assets', ['count' => count($this->assets)]);
            return $this->assets;
        }
        
        $this->logger->info('Fetching assets from Stein.app');
        
        try {
            $buId = $this->config['business_unit_id'];
            $endpoint = "/assets/?buIds={$buId}";
            
            $this->assets = $this->request('GET', $endpoint);
            
            $this->logger->info('Fetched ' . count($this->assets) . ' assets from Stein.app');
            
            // Debug: Zeige alle Assets mit ihren Namen (= Kennzeichen!)
            foreach ($this->assets as $a) {
                $this->logger->debug('Stein asset', [
                    'id' => $a['id'] ?? 'no-id',
                    'name' => $a['name'] ?? 'no-name',  // DAS IST DAS KENNZEICHEN!
                    'label' => $a['label'] ?? 'no-label',
                    'groupId' => $a['groupId'] ?? 'no-group',
                    'status' => $a['status'] ?? 'no-status'
                ]);
            }
            
            return $this->assets;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to fetch Stein.app assets: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Aktualisiere ein Asset in Stein.app
     * Original: updateAsset($assetId, $updateData, $notify = false)
     * 
     * WICHTIG: Holt zuerst das komplette Asset, merged die Daten, dann PATCH
     */
    public function updateAsset(string $assetId, array $updateData, bool $notify = false): bool {
        $this->logger->info('Updating asset in Stein.app', [
            'assetId' => $assetId,
            'updateData' => $updateData
        ]);
        
        try {
            // Hole alle Assets (aus Cache oder API)
            $assets = $this->getAssets();
            
            // Finde das Asset
            $assetData = null;
            foreach ($assets as $asset) {
                if ($asset['id'] == $assetId) {
                    $assetData = $asset;
                    break;
                }
            }
            
            if (!$assetData) {
                $this->logger->error('Asset not found', ['assetId' => $assetId]);
                return false;
            }
            
            // Entferne 'id' aus updateData falls vorhanden
            unset($updateData['id']);
            
            // Merge Daten - Original: $assetData = array_merge($assetData, $updateData);
            $assetData = array_merge($assetData, $updateData);
            
            // Endpoint mit notify-Parameter
            $endpoint = "/assets/{$assetId}?notifyRadio=" . ($notify ? 'true' : 'false');
            
            $this->logger->debug('Stein PATCH request', [
                'endpoint' => $endpoint,
                'payload' => $assetData
            ]);
            
            $this->request('PATCH', $endpoint, $assetData);
            
            // Cache invalidieren
            $this->assets = [];
            
            return true;
            
        } catch (Exception $e) {
            $this->logger->error("Failed to update asset $assetId: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Cache leeren
     */
    public function clearCache(): void {
        $this->assets = [];
        $this->logger->debug('Asset cache cleared');
    }
    
    /**
     * HTTP Request an Stein API
     * Basiert auf Original makeRequest()
     */
    private function request(string $method, string $endpoint, array $data = null): array {
        // Rate Limiting anwenden
        $this->rateLimit();
        
        // URL zusammenbauen
        // Original Base URL: https://stein.app/api/api/ext
        $baseUrl = rtrim($this->config['base_url'], '/');
        $url = $baseUrl . $endpoint;
        
        $this->logger->debug('Stein API request', [
            'method' => $method,
            'url' => $url
        ]);
        
        $ch = curl_init($url);
        
        $headers = [
            'User-Agent: Mozilla/5.0',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->config['api_key'],
            'Content-Type: application/json'
        ];
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $this->logger->debug('Stein API response', [
            'httpCode' => $httpCode,
            'responseLength' => strlen($response),
            'responsePreview' => substr($response, 0, 300)
        ]);
        
        if ($error) {
            throw new Exception("Stein API curl error: $error");
        }
        
        if ($httpCode !== 200) {
            throw new Exception("Stein API request failed with code $httpCode: $response");
        }
        
        $decoded = json_decode($response, true);
        if ($decoded === null && !empty($response)) {
            throw new Exception("Stein API invalid JSON response");
        }
        
        return $decoded ?? [];
    }
}
