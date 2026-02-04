<?php
/**
 * DiveraService Class
 * Handles communication with Divera24-7 API
 */

class DiveraService {
    private $config;
    private $logger;
    
    public function __construct(array $config, Logger $logger) {
        $this->config = $config;
        $this->logger = $logger;
    }
    
    public function testConnection(): array {
        try {
            $response = $this->request('GET', '/pull/all');
            return [
                'success' => true,
                'message' => 'Connection successful'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    public function getMembers(): array {
        $this->logger->info('Fetching members from Divera');
        
        try {
            $response = $this->request('GET', '/pull/all');
            $members = [];
            
            if (isset($response['data']['cluster']['consumer'])) {
                foreach ($response['data']['cluster']['consumer'] as $id => $member) {
                    $members[] = $this->mapMember($id, $member);
                }
            }
            
            $this->logger->info('Fetched ' . count($members) . ' members from Divera');
            return $members;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to fetch Divera members: ' . $e->getMessage());
            throw $e;
        }
    }
    
    public function updateMember(string $id, array $data): bool {
        $this->logger->info('Updating member in Divera', ['id' => $id]);
        
        try {
            $this->request('PUT', '/consumer/' . $id, $data);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update Divera member: ' . $e->getMessage());
            return false;
        }
    }
    
    private function mapMember(string $id, array $data): array {
        return [
            'id' => $id,
            'external_id' => $data['foreign_id'] ?? null,
            'first_name' => $data['firstname'] ?? '',
            'last_name' => $data['lastname'] ?? '',
            'email' => $data['email'] ?? '',
            'phone' => $data['phone'] ?? '',
            'mobile' => $data['mobile'] ?? '',
            'address' => [
                'street' => $data['address'] ?? '',
                'zip' => $data['zip'] ?? '',
                'city' => $data['city'] ?? ''
            ],
            'status' => $data['status'] ?? 0,
            'qualifications' => $data['qualifications'] ?? [],
            'groups' => $data['group_ids'] ?? [],
            'active' => $data['active'] ?? true,
            'source' => 'divera'
        ];
    }
    
    private function request(string $method, string $endpoint, array $data = null): array {
        $url = rtrim($this->config['base_url'], '/') . $endpoint;
        
        // Add access key
        $separator = strpos($url, '?') !== false ? '&' : '?';
        $url .= $separator . 'accesskey=' . urlencode($this->config['access_key']);
        
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
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('Divera API error: ' . $error);
        }
        
        if ($httpCode >= 400) {
            throw new Exception('Divera API returned error code: ' . $httpCode);
        }
        
        $decoded = json_decode($response, true);
        if ($decoded === null && $response !== 'null') {
            throw new Exception('Invalid JSON response from Divera');
        }
        
        return $decoded ?? [];
    }
}
