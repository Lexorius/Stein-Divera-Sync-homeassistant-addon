<?php
/**
 * SteinAPI - API Endpoint
 * Handles all API requests for Divera <-> Stein.app vehicle status synchronization
 *
 * Security notes:
 * - Only JSON bodies (php://input) and GET parameters are read; multipart parsing
 *   is disabled in php.ini (enable_post_data_reading = Off).
 * - No CORS headers: the dashboard is served from the same origin.
 * - Debug endpoints are disabled unless `debug_endpoints` is true in the configuration.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// Convert PHP warnings/notices into exceptions so they end up as JSON errors
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/**
 * Send a JSON error response and stop.
 */
function apiError(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

/**
 * Read and decode the JSON request body. Returns an array (empty on missing/invalid body).
 */
function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

const ALLOWED_DIRECTIONS = ['both', 'divera_to_stein', 'stein_to_divera'];
const DEBUG_ACTIONS = ['debug', 'compareData', 'rawDivera', 'rawStein', 'testSteinRaw'];

try {
    // Load configuration
    $configFile = __DIR__ . '/config/config.php';
    if (!file_exists($configFile)) {
        throw new Exception('Configuration file not found');
    }
    $config = require $configFile;
    if (!is_array($config)) {
        throw new Exception('Configuration not yet generated. Please restart the add-on.');
    }
    if (!empty($config['timezone'])) {
        date_default_timezone_set($config['timezone']);
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
    if (!is_string($action) || !preg_match('/^[A-Za-z]{1,32}$/', $action)) {
        apiError(400, 'Invalid action');
    }

    // Debug endpoints are opt-in: they expose raw data of both APIs
    $debugEnabled = !empty($config['debug_endpoints']);
    if (in_array($action, DEBUG_ACTIONS, true) && !$debugEnabled) {
        apiError(403, 'Debug endpoints are disabled. Set debug_endpoints: true in the add-on configuration to enable them temporarily.');
    }

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
            // Get logs (limit clamped to 1..1000)
            $limit = (int)($_GET['limit'] ?? 50);
            $limit = max(1, min(1000, $limit));
            $logs = $logger->getLogs($limit);
            echo json_encode([
                'success' => true,
                'data' => ['logs' => $logs]
            ]);
            break;

        case 'sync':
            // Start synchronization
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                apiError(405, 'POST method required');
            }

            $input = readJsonBody();

            $direction = $input['direction'] ?? $config['sync']['direction'];
            if (!is_string($direction) || !in_array($direction, ALLOWED_DIRECTIONS, true)) {
                apiError(400, 'Invalid direction. Allowed: ' . implode(', ', ALLOWED_DIRECTIONS));
            }

            $source = $input['source'] ?? 'manual';
            if (!is_string($source) || !preg_match('/^[a-z0-9_-]{1,50}$/', $source)) {
                $source = 'manual';
            }

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
                apiError(405, 'POST method required');
            }

            $input = readJsonBody();
            if (!isset($input['field'], $input['enabled'])) {
                apiError(400, 'Field and enabled parameters required');
            }

            $field = $input['field'];
            if (!is_string($field) || !preg_match('/^[a-z_]{1,50}$/', $field)) {
                apiError(400, 'Invalid field name');
            }

            $enabled = filter_var($input['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($enabled === null) {
                apiError(400, 'Parameter enabled must be a boolean');
            }

            $syncService->updateFieldConfig($field, $enabled);
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

        case 'config':
            // Get current configuration (sanitized, no secrets)
            echo json_encode([
                'success' => true,
                'data' => [
                    'sync_direction' => $config['sync']['direction'],
                    'sync_interval' => $config['sync']['interval_minutes'],
                    'auto_sync' => $config['sync']['auto_enabled'],
                    'divera_enabled' => $config['divera']['enabled'],
                    'stein_enabled' => $config['stein']['enabled'],
                    'debug_endpoints' => $debugEnabled
                ]
            ]);
            break;

        // ------------------------------------------------------------------
        // Debug endpoints (only reachable when debug_endpoints = true)
        // ------------------------------------------------------------------

        case 'debug':
            // Debug endpoint - zeigt Konfiguration (ohne Secrets) und testet Verbindungen
            $debugInfo = [
                'timestamp' => date('c'),
                'timezone' => date_default_timezone_get(),
                'php_version' => PHP_VERSION,
                'config' => [
                    'stein' => [
                        'base_url' => $config['stein']['base_url'] ?? 'NOT SET',
                        'business_unit_id' => $config['stein']['business_unit_id'] ?? 'NOT SET',
                        'api_key_set' => !empty($config['stein']['api_key'])
                    ],
                    'divera' => [
                        'base_url' => $config['divera']['base_url'] ?? 'NOT SET',
                        'access_key_set' => !empty($config['divera']['access_key'])
                    ],
                    'sync' => [
                        'direction' => $config['sync']['direction'] ?? 'NOT SET',
                        'stein_group_ids' => $config['sync']['stein_group_ids'] ?? [1, 5]
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

        case 'compareData':
            // Zeigt die Rohdaten beider APIs zum Vergleich
            // WICHTIG: Divera Kennzeichen = 'number', Stein Kennzeichen = 'name'
            $diveraVehicles = $diveraService->getVehicles();
            $steinAssets = $steinService->getAssets();

            // Hole erlaubte Gruppen aus Config
            $allowedGroups = $config['sync']['stein_group_ids'] ?? [1, 5];

            $comparison = [
                'divera_vehicles' => [],
                'stein_assets' => [],
                'potential_matches' => [],
                'config' => [
                    'divera_field' => 'number (Kennzeichen)',
                    'stein_field' => 'name (Kennzeichen)',
                    'allowed_groups' => $allowedGroups
                ]
            ];

            // Divera Fahrzeuge - Kennzeichen ist 'number'!
            foreach ($diveraVehicles as $v) {
                $comparison['divera_vehicles'][] = [
                    'id' => $v['id'] ?? 'no-id',
                    'number' => $v['number'] ?? '',
                    'name' => $v['name'] ?? '',
                    'fmsstatus' => $v['fmsstatus'] ?? 0,
                    'fmsstatus_note' => $v['fmsstatus_note'] ?? ''
                ];
            }

            // Stein Assets - Kennzeichen ist 'name'!, nur erlaubte Gruppen
            foreach ($steinAssets as $a) {
                $groupId = $a['groupId'] ?? 0;
                $comparison['stein_assets'][] = [
                    'id' => $a['id'] ?? 'no-id',
                    'name' => $a['name'] ?? '',
                    'label' => $a['label'] ?? '',
                    'groupId' => $groupId,
                    'in_allowed_groups' => in_array($groupId, $allowedGroups),
                    'status' => $a['status'] ?? '',
                    'comment' => $a['comment'] ?? ''
                ];
            }

            // Finde potentielle Matches (Stein 'name' === Divera 'number')
            foreach ($comparison['divera_vehicles'] as $dv) {
                foreach ($comparison['stein_assets'] as $sa) {
                    if (!$sa['in_allowed_groups']) {
                        continue;
                    }
                    if (!empty($dv['number']) && !empty($sa['name']) && $dv['number'] === $sa['name']) {
                        $comparison['potential_matches'][] = [
                            'divera_id' => $dv['id'],
                            'stein_id' => $sa['id'],
                            'matched_key' => $dv['number'],
                            'divera_status' => $dv['fmsstatus'],
                            'stein_status' => $sa['status']
                        ];
                    }
                }
            }

            echo json_encode([
                'success' => true,
                'summary' => [
                    'divera_count' => count($comparison['divera_vehicles']),
                    'stein_count' => count($comparison['stein_assets']),
                    'stein_in_allowed_groups' => count(array_filter($comparison['stein_assets'], fn($a) => $a['in_allowed_groups'])),
                    'matches_found' => count($comparison['potential_matches'])
                ],
                'data' => $comparison
            ], JSON_PRETTY_PRINT);
            break;

        case 'rawDivera':
            // Zeigt RAW Divera API Response (pull/vehicle-status, Kennzeichen ist 'number')
            $vehicles = $diveraService->getVehicles();
            echo json_encode([
                'success' => true,
                'info' => 'RAW Divera vehicle-status data. WICHTIG: Kennzeichen ist Feld "number"!',
                'endpoint' => 'pull/vehicle-status',
                'vehicle_count' => count($vehicles),
                'first_vehicle_structure' => !empty($vehicles) ? $vehicles[0] : null,
                'all_vehicles' => $vehicles
            ], JSON_PRETTY_PRINT);
            break;

        case 'rawStein':
            // Zeigt RAW Stein API Response (Kennzeichen ist 'name')
            $assets = $steinService->getAssets();
            echo json_encode([
                'success' => true,
                'info' => 'RAW Stein.app asset data. WICHTIG: Kennzeichen ist Feld "name"!',
                'endpoint' => '/assets/?buIds=' . ($config['stein']['business_unit_id'] ?? ''),
                'asset_count' => count($assets),
                'first_asset_structure' => !empty($assets) ? $assets[0] : null,
                'all_assets' => $assets
            ], JSON_PRETTY_PRINT);
            break;

        case 'testSteinRaw':
            // Raw test für Stein API - zeigt genau was passiert.
            // Der Endpoint ist auf einen Pfad unterhalb der konfigurierten Base-URL beschränkt.
            $testEndpoint = $_GET['endpoint'] ?? '/business-units/' . $config['stein']['business_unit_id'];
            if (!is_string($testEndpoint) || !preg_match('#^/(?!/)[A-Za-z0-9._~/?=&%-]{0,200}$#', $testEndpoint)) {
                apiError(400, 'Invalid endpoint: must be a relative path starting with /');
            }

            $raw = $steinService->rawRequest($testEndpoint);
            echo json_encode([
                'success' => true,
                'data' => $raw
            ], JSON_PRETTY_PRINT);
            break;

        default:
            apiError(404, 'Unknown action: ' . $action);
    }

} catch (Exception $e) {
    // Expected application errors (API failures, config problems)
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (Throwable $e) {
    // Unexpected PHP errors: log details, return a generic message
    error_log('SteinAPI internal error: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
