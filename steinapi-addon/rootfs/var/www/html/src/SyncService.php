<?php
/**
 * SyncService Class
 * Based on original working SteinAPI project: SyncManager.php
 * 
 * WICHTIG - MATCHING LOGIC:
 * - Divera Kennzeichen: Feld 'number'
 * - Stein Kennzeichen: Feld 'name'
 * - Stein Filter: nur groupId 1 oder 5
 * - Match: Stein 'name' === Divera 'number'
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
        
        // Setze Zeitzone
        date_default_timezone_set('Europe/Berlin');
    }
    
    /**
     * Hauptsync-Funktion
     * Basiert auf Original sync() Methode
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
            'details' => []
        ];
        
        try {
            // Hole Daten von beiden Systemen
            $diveraData = $this->divera->getVehicleStatus();
            $steinData = $this->stein->getAssets();
            
            $this->logger->info('Fetched data', [
                'divera_count' => count($diveraData),
                'stein_count' => count($steinData)
            ]);
            
            // ========================================
            // ORIGINAL MATCHING LOGIC
            // ========================================
            
            // Divera: Index by 'number' (das Kennzeichen!)
            $diveraAssets = [];
            foreach ($diveraData as $asset) {
                if (!empty($asset['number'])) {
                    $diveraAssets[$asset['number']] = $asset;
                    $this->logger->debug('Divera indexed', [
                        'number' => $asset['number'],
                        'id' => $asset['id'] ?? 'no-id'
                    ]);
                }
            }
            
            // Stein: Index by 'name' (das Kennzeichen!), nur bestimmte Gruppen
            $steinAssets = [];
            $allowedGroups = $this->config['stein_group_ids'] ?? [1, 5];
            
            foreach ($steinData as $asset) {
                // Original: if (in_array($asset['groupId'], [1, 5]))
                if (in_array($asset['groupId'] ?? 0, $allowedGroups)) {
                    if (!empty($asset['name'])) {
                        $steinAssets[$asset['name']] = $asset;
                        $this->logger->debug('Stein indexed', [
                            'name' => $asset['name'],
                            'id' => $asset['id'] ?? 'no-id',
                            'groupId' => $asset['groupId'] ?? 'no-group'
                        ]);
                    }
                }
            }
            
            $this->logger->info('Indexed assets', [
                'divera_indexed' => count($diveraAssets),
                'stein_indexed' => count($steinAssets),
                'divera_keys' => array_keys($diveraAssets),
                'stein_keys' => array_keys($steinAssets)
            ]);
            
            // ========================================
            // SYNC LOGIC - Original aus SyncManager
            // ========================================
            
            // Iteriere über Stein Assets und finde Matches in Divera
            foreach ($steinAssets as $name => $steinAsset) {
                // Suche passendes Divera Asset anhand des Namens (= Kennzeichen)
                if (!isset($diveraAssets[$name])) {
                    $this->logger->debug('No matching Divera asset for Stein', [
                        'stein_name' => $name
                    ]);
                    $results['skipped']++;
                    continue;
                }
                
                $diveraAsset = $diveraAssets[$name];
                $steinComment = $steinAsset['comment'] ?? '';
                
                // Prüfe ob Sync nötig ist
                $needsSync = false;
                
                // Vergleiche Status
                $expectedFms = DiveraService::FMS_STEIN_MAP[$steinAsset['status']] ?? 6;
                $currentFms = $diveraAsset['fmsstatus'] ?? 0;
                
                if ($currentFms != $expectedFms) {
                    $this->logger->debug('Status mismatch', [
                        'vehicle' => $name,
                        'divera_fms' => $currentFms,
                        'stein_status' => $steinAsset['status'] ?? 'unknown',
                        'expected_fms' => $expectedFms
                    ]);
                    $needsSync = true;
                }
                
                // Vergleiche Comment/Note
                $diveraNote = $diveraAsset['fmsstatus_note'] ?? '';
                if ($diveraNote != $steinComment) {
                    $this->logger->debug('Comment mismatch', [
                        'vehicle' => $name,
                        'divera_note' => $diveraNote,
                        'stein_comment' => $steinComment
                    ]);
                    $needsSync = true;
                }
                
                if (!$needsSync) {
                    $this->logger->debug('No sync needed', ['vehicle' => $name]);
                    $results['skipped']++;
                    continue;
                }
                
                // Führe Sync durch
                $syncResult = $this->performSync($diveraAsset, $steinAsset, $direction);
                
                if ($syncResult) {
                    $results['details'][] = $syncResult;
                    if ($syncResult['success']) {
                        $results['synced']++;
                        $results['updated']++;
                    } else {
                        $results['errors']++;
                    }
                    
                    // Log sync
                    $this->logSync($syncResult);
                }
            }
            
            $results['completed_at'] = date('c');
            $results['success'] = true;
            
            $this->updateLastSync();
            $this->logger->info('Sync completed', $results);
            
        } catch (Exception $e) {
            $results['success'] = false;
            $results['error'] = $e->getMessage();
            $results['errors']++;
            $this->logger->error('Sync failed: ' . $e->getMessage());
        }
        
        $this->saveSyncHistory($results);
        
        return $results;
    }
    
    /**
     * Führe Sync für ein einzelnes Fahrzeug durch
     * Basiert auf Original performSync()
     */
    private function performSync(array $diveraAsset, array $steinAsset, string $direction): array {
        $result = [
            'vehicle' => $diveraAsset['name'] ?? $diveraAsset['number'] ?? 'unknown',
            'divera_id' => $diveraAsset['id'],
            'stein_id' => $steinAsset['id'],
            'action' => null,
            'success' => false,
            'fields_synced' => []
        ];
        
        // Timestamps vergleichen
        $diveraTs = $diveraAsset['fmsstatus_ts'] ?? 0;
        
        // Stein Timestamp konvertieren
        $steinTs = 0;
        if (!empty($steinAsset['lastModified'])) {
            try {
                $steinDateTime = new DateTime($steinAsset['lastModified'], new DateTimeZone('Europe/Berlin'));
                $steinTs = $steinDateTime->getTimestamp();
            } catch (Exception $e) {
                $this->logger->warning('Could not parse Stein timestamp', [
                    'lastModified' => $steinAsset['lastModified']
                ]);
            }
        }
        
        $this->logger->debug('Comparing timestamps', [
            'vehicle' => $result['vehicle'],
            'divera_ts' => $diveraTs,
            'stein_ts' => $steinTs
        ]);
        
        // Bestimme Sync-Richtung
        $syncToDivera = false;
        $syncToStein = false;
        
        if ($direction === 'both') {
            // Wer hat neuere Daten?
            if ($steinTs > $diveraTs) {
                $syncToDivera = true;
            } else {
                $syncToStein = true;
            }
        } elseif ($direction === 'stein_to_divera' || $direction === 'divera') {
            $syncToDivera = true;
        } elseif ($direction === 'divera_to_stein' || $direction === 'stein') {
            $syncToStein = true;
        }
        
        try {
            if ($syncToDivera) {
                // Stein -> Divera
                $this->logger->info('Syncing Stein to Divera', ['vehicle' => $result['vehicle']]);
                
                $success = $this->divera->setVehicleStatus($diveraAsset['id'], $steinAsset);
                
                $result['action'] = 'stein_to_divera';
                $result['success'] = $success;
                $result['fields_synced'] = ['status', 'comment'];
                
            } elseif ($syncToStein) {
                // Divera -> Stein
                $this->logger->info('Syncing Divera to Stein', ['vehicle' => $result['vehicle']]);
                
                // Mappe FMS Status zu Stein Status
                $steinStatus = DiveraService::FMS_STEIN_MAP[$diveraAsset['fmsstatus']] ?? 'notready';
                
                $payload = [
                    'status' => $steinStatus,
                    'comment' => $diveraAsset['fmsstatus_note'] ?? ''
                ];
                
                $success = $this->stein->updateAsset($steinAsset['id'], $payload);
                
                $result['action'] = 'divera_to_stein';
                $result['success'] = $success;
                $result['fields_synced'] = ['status', 'comment'];
            }
            
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            $this->logger->error('Sync failed for vehicle', [
                'vehicle' => $result['vehicle'],
                'error' => $e->getMessage()
            ]);
        }
        
        return $result;
    }
    
    /**
     * Speichere Sync in Log
     */
    private function logSync(array $result): void {
        try {
            $this->db->insert('sync_history', [
                'direction' => $result['action'] ?? 'unknown',
                'source' => 'sync',
                'synced_count' => $result['success'] ? 1 : 0,
                'created_count' => 0,
                'updated_count' => $result['success'] ? 1 : 0,
                'error_count' => $result['success'] ? 0 : 1,
                'success' => $result['success'] ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            $this->logger->warning('Could not log sync: ' . $e->getMessage());
        }
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
     * Speichere Sync-History
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
    
    /**
     * Hole Feld-Konfiguration
     */
    public function getFieldConfig(): array {
        return [
            'status' => true,
            'comment' => true
        ];
    }
    
    /**
     * Aktualisiere Feld-Konfiguration (Stub)
     */
    public function updateFieldConfig(string $field, bool $enabled): void {
        $this->logger->info("Field config updated: $field = " . ($enabled ? 'enabled' : 'disabled'));
    }
}
