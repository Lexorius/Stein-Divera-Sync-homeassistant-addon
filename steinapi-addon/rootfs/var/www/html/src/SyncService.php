<?php
/**
 * SyncService Class
 * Handles synchronization between Divera and Stein.app
 */

class SyncService {
    private $db;
    private $divera;
    private $stein;
    private $logger;
    private $config;
    
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
    }
    
    public function sync(string $direction = 'both', string $source = 'manual'): array {
        $this->logger->info("Starting sync ($direction) from $source");
        
        $stats = [
            'direction' => $direction,
            'source' => $source,
            'started_at' => date('c'),
            'synced' => 0,
            'created' => 0,
            'updated' => 0,
            'errors' => 0
        ];
        
        try {
            // Get enabled fields
            $fields = $this->getFieldConfig();
            
            if ($direction === 'both' || $direction === 'divera_to_stein') {
                $result = $this->syncDiveraToStein($fields);
                $stats['synced'] += $result['synced'];
                $stats['created'] += $result['created'];
                $stats['updated'] += $result['updated'];
                $stats['errors'] += $result['errors'];
            }
            
            if ($direction === 'both' || $direction === 'stein_to_divera') {
                $result = $this->syncSteinToDivera($fields);
                $stats['synced'] += $result['synced'];
                $stats['created'] += $result['created'];
                $stats['updated'] += $result['updated'];
                $stats['errors'] += $result['errors'];
            }
            
            $stats['completed_at'] = date('c');
            $stats['success'] = true;
            
            // Update last sync time
            $this->updateLastSync();
            
            $this->logger->info('Sync completed', $stats);
            
        } catch (Exception $e) {
            $stats['success'] = false;
            $stats['error'] = $e->getMessage();
            $stats['errors']++;
            $this->logger->error('Sync failed: ' . $e->getMessage());
        }
        
        // Save sync history
        $this->saveSyncHistory($stats);
        
        return $stats;
    }
    
    private function syncDiveraToStein(array $fields): array {
        $result = ['synced' => 0, 'created' => 0, 'updated' => 0, 'errors' => 0];
        
        try {
            $diveraMembers = $this->divera->getMembers();
            $steinMembers = $this->stein->getMembers();
            
            // Index Stein members by external_id
            $steinIndex = [];
            foreach ($steinMembers as $member) {
                if ($member['external_id']) {
                    $steinIndex[$member['external_id']] = $member;
                }
            }
            
            foreach ($diveraMembers as $diveraMember) {
                try {
                    $externalId = 'divera_' . $diveraMember['id'];
                    $data = $this->filterFields($diveraMember, $fields);
                    $data['external_id'] = $externalId;
                    
                    if (isset($steinIndex[$externalId])) {
                        // Update existing
                        if ($this->stein->updateMember($steinIndex[$externalId]['id'], $data)) {
                            $result['updated']++;
                        }
                    } else {
                        // Create new
                        if ($this->stein->createMember($data)) {
                            $result['created']++;
                        }
                    }
                    $result['synced']++;
                    
                } catch (Exception $e) {
                    $result['errors']++;
                    $this->logger->warning('Failed to sync member: ' . $e->getMessage());
                }
            }
            
        } catch (Exception $e) {
            $result['errors']++;
            throw $e;
        }
        
        return $result;
    }
    
    private function syncSteinToDivera(array $fields): array {
        $result = ['synced' => 0, 'created' => 0, 'updated' => 0, 'errors' => 0];
        
        try {
            $steinMembers = $this->stein->getMembers();
            $diveraMembers = $this->divera->getMembers();
            
            // Index Divera members by external_id
            $diveraIndex = [];
            foreach ($diveraMembers as $member) {
                if ($member['external_id']) {
                    $diveraIndex[$member['external_id']] = $member;
                }
                // Also index by Divera ID
                $diveraIndex['divera_' . $member['id']] = $member;
            }
            
            foreach ($steinMembers as $steinMember) {
                try {
                    $externalId = $steinMember['external_id'];
                    
                    // Only sync if linked to Divera
                    if ($externalId && strpos($externalId, 'divera_') === 0) {
                        $diveraId = str_replace('divera_', '', $externalId);
                        
                        if (isset($diveraIndex[$externalId])) {
                            $data = $this->filterFields($steinMember, $fields);
                            if ($this->divera->updateMember($diveraId, $data)) {
                                $result['updated']++;
                            }
                            $result['synced']++;
                        }
                    }
                    
                } catch (Exception $e) {
                    $result['errors']++;
                    $this->logger->warning('Failed to sync member: ' . $e->getMessage());
                }
            }
            
        } catch (Exception $e) {
            $result['errors']++;
            throw $e;
        }
        
        return $result;
    }
    
    private function filterFields(array $data, array $fields): array {
        $filtered = [];
        
        $fieldMapping = [
            'name' => ['first_name', 'last_name'],
            'email' => ['email'],
            'phone' => ['phone', 'mobile'],
            'address' => ['address'],
            'status' => ['status', 'active'],
            'qualifications' => ['qualifications'],
            'group' => ['groups'],
            'rank' => ['rank'],
            'notes' => ['notes']
        ];
        
        foreach ($fields as $field => $enabled) {
            if ($enabled && isset($fieldMapping[$field])) {
                foreach ($fieldMapping[$field] as $key) {
                    if (isset($data[$key])) {
                        $filtered[$key] = $data[$key];
                    }
                }
            }
        }
        
        return $filtered;
    }
    
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
            // Get last sync
            $lastSync = $this->db->fetchOne(
                "SELECT * FROM sync_history ORDER BY created_at DESC LIMIT 1"
            );
            
            if ($lastSync) {
                $stats['last_sync'] = $lastSync['created_at'];
                
                // Calculate next sync
                if ($stats['auto_sync_enabled']) {
                    $interval = ($this->config['interval_minutes'] ?? 5) * 60;
                    $lastSyncTime = strtotime($lastSync['created_at']);
                    $stats['next_sync'] = date('c', $lastSyncTime + $interval);
                }
            }
            
            // Get today's sync count
            $today = $this->db->fetchOne(
                "SELECT SUM(synced_count) as total FROM sync_history 
                 WHERE DATE(created_at) = CURDATE()"
            );
            $stats['synced_today'] = (int)($today['total'] ?? 0);
            
            // Get 24h error count
            $errors = $this->db->fetchOne(
                "SELECT COUNT(*) as count FROM sync_logs 
                 WHERE level = 'error' AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            $stats['errors_24h'] = (int)($errors['count'] ?? 0);
            
            // Determine status
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
    
    public function getFieldConfig(): array {
        try {
            $result = $this->db->fetchOne("SELECT config FROM field_config WHERE id = 1");
            if ($result) {
                return json_decode($result['config'], true) ?? $this->config['fields'];
            }
        } catch (Exception $e) {
            // Table might not exist yet
        }
        
        return $this->config['fields'] ?? [
            'name' => true,
            'email' => true,
            'phone' => true,
            'address' => true,
            'status' => true,
            'qualifications' => true,
            'group' => false,
            'rank' => false,
            'notes' => false
        ];
    }
    
    public function updateFieldConfig(string $field, bool $enabled): void {
        $fields = $this->getFieldConfig();
        $fields[$field] = $enabled;
        
        try {
            $this->db->execute(
                "INSERT INTO field_config (id, config) VALUES (1, ?) 
                 ON DUPLICATE KEY UPDATE config = ?",
                [json_encode($fields), json_encode($fields)]
            );
            
            $this->logger->info("Field config updated: $field = " . ($enabled ? 'enabled' : 'disabled'));
        } catch (Exception $e) {
            $this->logger->error('Failed to update field config: ' . $e->getMessage());
            throw $e;
        }
    }
    
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
    
    private function saveSyncHistory(array $stats): void {
        try {
            $this->db->insert('sync_history', [
                'direction' => $stats['direction'],
                'source' => $stats['source'],
                'synced_count' => $stats['synced'],
                'created_count' => $stats['created'],
                'updated_count' => $stats['updated'],
                'error_count' => $stats['errors'],
                'success' => $stats['success'] ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to save sync history: ' . $e->getMessage());
        }
    }
}
