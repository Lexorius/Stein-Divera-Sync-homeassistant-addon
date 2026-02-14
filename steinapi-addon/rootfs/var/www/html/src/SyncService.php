<?php
/**
 * SyncService Class
 * Handles synchronization of VEHICLES/ASSETS between Divera and Stein.app
 * 
 * WICHTIG: Die Zuordnung erfolgt über das KENNZEICHEN!
 * Das Kennzeichen muss in beiden Systemen identisch sein.
 * 
 * Stein.app = Assets (Fahrzeuge, Anhänger, Geräte)
 * Divera = Vehicles (Fahrzeuge)
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
    
    /**
     * Starte die Synchronisation
     */
    public function sync(string $direction = 'both', string $source = 'manual'): array {
        $this->logger->info("Starting vehicle sync ($direction) from $source");
        
        $stats = [
            'direction' => $direction,
            'source' => $source,
            'started_at' => date('c'),
            'synced' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => []
        ];
        
        try {
            // Hole Fahrzeuge aus beiden Systemen
            $diveraVehicles = $this->divera->getVehicles();
            $steinAssets = $this->stein->getAssets();
            
            $this->logger->info('Fetched vehicles', [
                'divera_count' => count($diveraVehicles),
                'stein_count' => count($steinAssets)
            ]);
            
            // Erstelle Index nach normalisiertem Kennzeichen
            $diveraIndex = $this->indexByLicensePlate($diveraVehicles);
            $steinIndex = $this->indexByLabel($steinAssets);
            
            if ($direction === 'both' || $direction === 'divera_to_stein') {
                $result = $this->syncDiveraToStein($diveraVehicles, $steinIndex);
                $stats['synced'] += $result['synced'];
                $stats['updated'] += $result['updated'];
                $stats['skipped'] += $result['skipped'];
                $stats['errors'] += $result['errors'];
                $stats['details']['divera_to_stein'] = $result;
            }
            
            if ($direction === 'both' || $direction === 'stein_to_divera') {
                $result = $this->syncSteinToDivera($steinAssets, $diveraIndex);
                $stats['synced'] += $result['synced'];
                $stats['updated'] += $result['updated'];
                $stats['skipped'] += $result['skipped'];
                $stats['errors'] += $result['errors'];
                $stats['details']['stein_to_divera'] = $result;
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
    
    /**
     * Sync von Divera nach Stein.app
     * Aktualisiert den Status in Stein.app basierend auf Divera
     */
    private function syncDiveraToStein(array $diveraVehicles, array $steinIndex): array {
        $result = ['synced' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'vehicles' => []];
        
        $fields = $this->getFieldConfig();
        
        foreach ($diveraVehicles as $diveraVehicle) {
            try {
                $licensePlate = $diveraVehicle['license_plate_normalized'];
                
                // Suche passendes Asset in Stein.app
                $steinAsset = $steinIndex[$licensePlate] ?? null;
                
                if (!$steinAsset) {
                    $this->logger->debug('No matching Stein asset for Divera vehicle', [
                        'license_plate' => $diveraVehicle['license_plate']
                    ]);
                    $result['skipped']++;
                    continue;
                }
                
                // Prüfe ob Update nötig ist
                $needsUpdate = false;
                $updateData = [];
                
                // Status synchronisieren
                if ($fields['status'] ?? true) {
                    $newStatus = $diveraVehicle['status'];
                    if ($steinAsset['status'] !== $newStatus) {
                        $updateData['status'] = $newStatus;
                        $needsUpdate = true;
                    }
                }
                
                // Funkrufname synchronisieren
                if (($fields['radio_name'] ?? true) && !empty($diveraVehicle['issi'])) {
                    if (($steinAsset['issi'] ?? '') !== $diveraVehicle['issi']) {
                        $updateData['issi'] = $diveraVehicle['issi'];
                        $needsUpdate = true;
                    }
                }
                
                // Kommentar/Notiz synchronisieren
                if (($fields['comment'] ?? false) && !empty($diveraVehicle['note'])) {
                    if (($steinAsset['comment'] ?? '') !== $diveraVehicle['note']) {
                        $updateData['comment'] = $diveraVehicle['note'];
                        $needsUpdate = true;
                    }
                }
                
                if ($needsUpdate) {
                    if ($this->stein->updateAsset($steinAsset['id'], $updateData)) {
                        $result['updated']++;
                        $result['vehicles'][] = [
                            'license_plate' => $diveraVehicle['license_plate'],
                            'action' => 'updated',
                            'changes' => array_keys($updateData)
                        ];
                        $this->logger->info('Updated Stein asset', [
                            'license_plate' => $diveraVehicle['license_plate'],
                            'stein_id' => $steinAsset['id'],
                            'changes' => $updateData
                        ]);
                    } else {
                        $result['errors']++;
                    }
                } else {
                    $result['vehicles'][] = [
                        'license_plate' => $diveraVehicle['license_plate'],
                        'action' => 'no_change'
                    ];
                }
                
                $result['synced']++;
                
            } catch (Exception $e) {
                $result['errors']++;
                $this->logger->warning('Failed to sync vehicle: ' . $e->getMessage(), [
                    'license_plate' => $diveraVehicle['license_plate'] ?? 'unknown'
                ]);
            }
        }
        
        return $result;
    }
    
    /**
     * Sync von Stein.app nach Divera
     * Aktualisiert den Status in Divera basierend auf Stein.app
     */
    private function syncSteinToDivera(array $steinAssets, array $diveraIndex): array {
        $result = ['synced' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'vehicles' => []];
        
        $fields = $this->getFieldConfig();
        
        foreach ($steinAssets as $steinAsset) {
            try {
                // Normalisiere das Label (Kennzeichen) von Stein
                $licensePlate = $this->normalizeLicensePlate($steinAsset['label']);
                
                // Suche passendes Fahrzeug in Divera
                $diveraVehicle = $diveraIndex[$licensePlate] ?? null;
                
                if (!$diveraVehicle) {
                    $this->logger->debug('No matching Divera vehicle for Stein asset', [
                        'label' => $steinAsset['label']
                    ]);
                    $result['skipped']++;
                    continue;
                }
                
                // Prüfe ob Status-Update nötig ist
                if ($fields['status'] ?? true) {
                    $currentDiveraStatus = $diveraVehicle['status'];
                    $steinStatus = $steinAsset['status'];
                    
                    // Nur updaten wenn Status unterschiedlich
                    if ($currentDiveraStatus !== $steinStatus) {
                        $newFmsStatus = $this->divera->mapSteinStatusToDivera($steinStatus);
                        
                        if ($this->divera->updateVehicleStatus($diveraVehicle['id'], $newFmsStatus)) {
                            $result['updated']++;
                            $result['vehicles'][] = [
                                'license_plate' => $steinAsset['label'],
                                'action' => 'updated',
                                'old_status' => $currentDiveraStatus,
                                'new_status' => $steinStatus
                            ];
                            $this->logger->info('Updated Divera vehicle status', [
                                'license_plate' => $steinAsset['label'],
                                'divera_id' => $diveraVehicle['id'],
                                'new_fms_status' => $newFmsStatus
                            ]);
                        } else {
                            $result['errors']++;
                        }
                    } else {
                        $result['vehicles'][] = [
                            'license_plate' => $steinAsset['label'],
                            'action' => 'no_change'
                        ];
                    }
                }
                
                $result['synced']++;
                
            } catch (Exception $e) {
                $result['errors']++;
                $this->logger->warning('Failed to sync asset: ' . $e->getMessage(), [
                    'label' => $steinAsset['label'] ?? 'unknown'
                ]);
            }
        }
        
        return $result;
    }
    
    /**
     * Erstelle Index der Divera-Fahrzeuge nach normalisiertem Kennzeichen
     */
    private function indexByLicensePlate(array $vehicles): array {
        $index = [];
        foreach ($vehicles as $vehicle) {
            $normalized = $vehicle['license_plate_normalized'] ?? '';
            if ($normalized) {
                $index[$normalized] = $vehicle;
            }
        }
        return $index;
    }
    
    /**
     * Erstelle Index der Stein-Assets nach normalisiertem Label (Kennzeichen)
     */
    private function indexByLabel(array $assets): array {
        $index = [];
        foreach ($assets as $asset) {
            $label = $asset['label'] ?? '';
            $normalized = $this->normalizeLicensePlate($label);
            if ($normalized) {
                $index[$normalized] = $asset;
            }
        }
        return $index;
    }
    
    /**
     * Normalisiere Kennzeichen für Vergleich
     */
    private function normalizeLicensePlate(string $plate): string {
        // Entferne Leerzeichen und Bindestriche
        $normalized = preg_replace('/[\s\-]/', '', $plate);
        // Großbuchstaben
        return strtoupper($normalized);
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
            'next_sync' => null,
            'vehicle_count' => [
                'divera' => 0,
                'stein' => 0,
                'matched' => 0
            ]
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
            
            // Vehicle counts (optional, may fail if APIs not configured)
            try {
                $diveraVehicles = $this->divera->getVehicles();
                $steinAssets = $this->stein->getAssets();
                
                $stats['vehicle_count']['divera'] = count($diveraVehicles);
                $stats['vehicle_count']['stein'] = count($steinAssets);
                
                // Count matches
                $diveraIndex = $this->indexByLicensePlate($diveraVehicles);
                $matched = 0;
                foreach ($steinAssets as $asset) {
                    $normalized = $this->normalizeLicensePlate($asset['label'] ?? '');
                    if (isset($diveraIndex[$normalized])) {
                        $matched++;
                    }
                }
                $stats['vehicle_count']['matched'] = $matched;
            } catch (Exception $e) {
                // Ignore - APIs might not be configured yet
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
        try {
            $result = $this->db->fetchOne("SELECT config FROM field_config WHERE id = 1");
            if ($result) {
                return json_decode($result['config'], true) ?? $this->getDefaultFieldConfig();
            }
        } catch (Exception $e) {
            // Table might not exist yet
        }
        
        return $this->getDefaultFieldConfig();
    }
    
    /**
     * Standard Feld-Konfiguration für Fahrzeuge
     */
    private function getDefaultFieldConfig(): array {
        return [
            'status' => true,           // Fahrzeugstatus (einsatzbereit, im Einsatz, etc.)
            'radio_name' => true,       // Funkrufname / ISSI
            'comment' => false,         // Kommentar / Notiz
            'category' => false,        // Kategorie
            'name' => false             // Name / Beschreibung
        ];
    }
    
    /**
     * Aktualisiere Feld-Konfiguration
     */
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
    
    /**
     * Update last sync timestamp
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
     * Speichere Sync-Historie
     */
    private function saveSyncHistory(array $stats): void {
        try {
            $this->db->insert('sync_history', [
                'direction' => $stats['direction'],
                'source' => $stats['source'],
                'synced_count' => $stats['synced'],
                'created_count' => $stats['created'] ?? 0,
                'updated_count' => $stats['updated'],
                'error_count' => $stats['errors'],
                'success' => ($stats['success'] ?? false) ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to save sync history: ' . $e->getMessage());
        }
    }
}
