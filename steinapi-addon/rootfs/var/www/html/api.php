<?php
/**
 * SteinAPI - API Endpoint
 * Handles all API requests for Divera <-> Stein.app synchronization
 */

// Load configuration first to get timezone
$configFile = __DIR__ . '/config/config.php';
if (file_exists($configFile)) {
    $config = require $configFile;
    if (isset($config['timezone'])) {
        date_default_timezone_set($config['timezone']);
    }
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Error handling
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    // Config was already loaded at top for timezone
    if (!isset($config)) {
        $configFile = __DIR__ . '/config/config.php';
        if (!file_exists($configFile)) {
            throw new Exception('Configuration file not found');
        }
        $config = require $configFile;
    }
    
    // Initialize database
    require_once __DIR__ . '/src/Database.php';
    $db = new Database($config['database']);
    
    // Load services
    require_once __DIR__ . '/src/DiveraService.php';
    require_once __DIR__ . '/src/SteinService.php';
    require_once __DIR__ . '/src/SyncService.php';
    require_once __DIR__ . '/src/Logger.php';
    
    $logger = new Logger($db, $config['logging']);
    $diveraService = new DiveraService($config['divera'], $logger);
    $steinService = new SteinService($config['stein'], $logger);
    $syncService = new SyncService($db, $diveraService, $steinService, $logger, $config['sync']);
    
    // Get action from request
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'health':
            // Health check endpoint
            echo json_encode([
                'success' => true,
                'status' => 'healthy',
                'timestamp' => date('c')
            ]);
            break;
            
        case 'stats':
            // Get statistics
            $stats = $syncService->getStats();
            echo json_encode([
                'success' => true,
                'data' => $stats
            ]);
            break;
            
        case 'logs':
            // Get logs
            $limit = intval($_GET['limit'] ?? 50);
            $logs = $logger->getLogs($limit);
            echo json_encode([
                'success' => true,
                'data' => ['logs' => $logs]
            ]);
            break;
            
        case 'sync':
            // Start synchronization
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST method required');
            }
            
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            $direction = $input['direction'] ?? $config['sync']['direction'];
            $source = $input['source'] ?? 'manual';
            
            $result = $syncService->sync($direction, $source);
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;
            
        case 'fieldConfig':
            // Get field configuration
            $fields = $syncService->getFieldConfig();
            echo json_encode([
                'success' => true,
                'data' => ['fields' => $fields]
            ]);
            break;
            
        case 'updateField':
            // Update field configuration
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('POST method required');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!isset($input['field']) || !isset($input['enabled'])) {
                throw new Exception('Field and enabled parameters required');
            }
            
            $syncService->updateFieldConfig($input['field'], $input['enabled']);
            echo json_encode([
                'success' => true,
                'message' => 'Field configuration updated'
            ]);
            break;
            
        case 'testConnection':
            // Test connections to both services
            $results = [
                'divera' => $diveraService->testConnection(),
                'stein' => $steinService->testConnection()
            ];
            echo json_encode([
                'success' => true,
                'data' => $results
            ]);
            break;
            
        case 'debug':
            // Debug endpoint - zeigt Konfiguration und testet Verbindungen
            $debugInfo = [
                'timestamp' => date('c'),
                'timezone' => date_default_timezone_get(),
                'php_version' => PHP_VERSION,
                'config' => [
                    'stein' => [
                        'base_url' => $config['stein']['base_url'] ?? 'NOT SET',
                        'business_unit_id' => $config['stein']['business_unit_id'] ?? 'NOT SET',
                        'api_key_set' => !empty($config['stein']['api_key']),
                        'api_key_length' => strlen($config['stein']['api_key'] ?? '')
                    ],
                    'divera' => [
                        'base_url' => $config['divera']['base_url'] ?? 'NOT SET',
                        'access_key_set' => !empty($config['divera']['access_key']),
                        'access_key_length' => strlen($config['divera']['access_key'] ?? '')
                    ]
                ],
                'connection_tests' => [
                    'stein' => $steinService->testConnection(),
                    'divera' => $diveraService->testConnection()
                ]
            ];
            echo json_encode([
                'success' => true,
                'data' => $debugInfo
            ], JSON_PRETTY_PRINT);
            break;
            
        case 'testSteinRaw':
            // Raw test für Stein API - zeigt genau was passiert
            $testUrl = $config['stein']['base_url'];
            $testEndpoint = $_GET['endpoint'] ?? '/business-units/' . $config['stein']['business_unit_id'];
            $fullUrl = rtrim($testUrl, '/') . $testEndpoint;
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $fullUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HEADER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $config['stein']['api_key']
                ]
            ]);
            
            $response = curl_exec($ch);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $headers = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);
            $info = curl_getinfo($ch);
            $error = curl_error($ch);
            curl_close($ch);
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'request' => [
                        'url' => $fullUrl,
                        'base_url' => $testUrl,
                        'endpoint' => $testEndpoint,
                        'method' => 'GET'
                    ],
                    'response' => [
                        'http_code' => $info['http_code'],
                        'total_time' => $info['total_time'],
                        'headers' => $headers,
                        'body' => $body,
                        'body_decoded' => json_decode($body, true)
                    ],
                    'curl_error' => $error ?: null,
                    'curl_info' => [
                        'effective_url' => $info['url'],
                        'redirect_count' => $info['redirect_count'],
                        'content_type' => $info['content_type']
                    ]
                ]
            ], JSON_PRETTY_PRINT);
            break;
            
        case 'config':
            // Get current configuration (sanitized)
            echo json_encode([
                'success' => true,
                'data' => [
                    'sync_direction' => $config['sync']['direction'],
                    'sync_interval' => $config['sync']['interval_minutes'],
                    'auto_sync' => $config['sync']['auto_enabled'],
                    'divera_enabled' => $config['divera']['enabled'],
                    'stein_enabled' => $config['stein']['enabled']
                ]
            ]);
            break;
            
        default:
            throw new Exception('Unknown action: ' . $action);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
