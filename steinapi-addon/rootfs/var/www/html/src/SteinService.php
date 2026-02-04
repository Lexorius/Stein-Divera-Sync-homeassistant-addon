<?php
/**
 * SteinService Class
 * Handles communication with Stein.app API
 */

class SteinService {
    private $config;
    private $logger;
    
    public function __construct(array $config, Logger $logger) {
        $this->config = $config;
        $this->logger = $logger;
    }
    
    public function testConnection(): array {
        try {
            $response = $this->request('GET', '/business-units/' . $this->config['business_unit_id']);
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
        $this->logger->info('Fetching members from Stein.app');
        
        try {
            $response = $this->request('GET', '/business-units/' . $this->config['business_unit_id'] . '/members');
            $members = [];
            
            if (isset($response['data'])) {
                foreach ($response['data'] as $member) {
                    $members[] = $this->mapMember($member);
                }
            }
            
            $this->logger->info('Fetched ' . count($members) . ' members from Stein.app');
            return $members;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to fetch Stein.app members: ' . $e->getMessage());
            throw $e;
        }
    }
    
    public function updateMember(string $id, array $data): bool {
        $this->logger->info('Updating member in Stein.app', ['id' => $id]);
        
        try {
            $this->request('PATCH', '/members/' . $id, $this->mapToSteinFormat($data));
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update Stein.app member: ' . $e->getMessage());
            return false;
        }
    }
    
    public function createMember(array $data): ?string {
        $this->logger->info('Creating member in Stein.app');
        
        try {
            $response = $this->request('POST', '/business-units/' . $this->config['business_unit_id'] . '/members', $this->mapToSteinFormat($data));
            return $response['data']['id'] ?? null;
        } catch (Exception $e) {
            $this->logger->error('Failed to create Stein.app member: ' . $e->getMessage());
            return null;
        }
    }
    
    private function mapMember(array $data): array {
        return [
            'id' => $data['id'] ?? '',
            'external_id' => $data['external_id'] ?? null,
            'first_name' => $data['first_name'] ?? '',
            'last_name' => $data['last_name'] ?? '',
            'email' => $data['email'] ?? '',
            'phone' => $data['phone'] ?? '',
            'mobile' => $data['mobile'] ?? '',
            'address' => [
                'street' => $data['address']['street'] ?? '',
                'zip' => $data['address']['postal_code'] ?? '',
                'city' => $data['address']['city'] ?? ''
            ],
            'status' => $data['status'] ?? 'active',
            'qualifications' => $data['qualifications'] ?? [],
            'groups' => $data['groups'] ?? [],
            'active' => $data['active'] ?? true,
            'source' => 'stein'
        ];
    }
    
    private function mapToSteinFormat(array $data): array {
        $steinData = [];
        
        if (isset($data['first_name'])) $steinData['first_name'] = $data['first_name'];
        if (isset($data['last_name'])) $steinData['last_name'] = $data['last_name'];
        if (isset($data['email'])) $steinData['email'] = $data['email'];
        if (isset($data['phone'])) $steinData['phone'] = $data['phone'];
        if (isset($data['mobile'])) $steinData['mobile'] = $data['mobile'];
        if (isset($data['external_id'])) $steinData['external_id'] = $data['external_id'];
        
        if (isset($data['address'])) {
            $steinData['address'] = [
                'street' => $data['address']['street'] ?? '',
                'postal_code' => $data['address']['zip'] ?? '',
                'city' => $data['address']['city'] ?? ''
            ];
        }
        
        return $steinData;
    }
    
    private function request(string $method, string $endpoint, array $data = null): array {
        $url = rtrim($this->config['base_url'], '/') . $endpoint;
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->config['api_key']
            ]
        ]);
        
        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
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
            throw new Exception('Stein.app API error: ' . $error);
        }
        
        if ($httpCode >= 400) {
            throw new Exception('Stein.app API returned error code: ' . $httpCode);
        }
        
        $decoded = json_decode($response, true);
        if ($decoded === null && $response !== 'null') {
            throw new Exception('Invalid JSON response from Stein.app');
        }
        
        return $decoded ?? [];
    }
}
