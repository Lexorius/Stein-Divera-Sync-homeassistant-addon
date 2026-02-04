<?php
/**
 * Logger Class
 * Handles logging to database and files
 */

class Logger {
    private $db;
    private $config;
    private $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
    
    public function __construct(Database $db, array $config) {
        $this->db = $db;
        $this->config = $config;
    }
    
    public function log(string $level, string $message, array $context = []): void {
        // Check if level should be logged
        $configLevel = $this->levels[$this->config['level']] ?? 1;
        $messageLevel = $this->levels[$level] ?? 1;
        
        if ($messageLevel < $configLevel) {
            return;
        }
        
        // Insert into database
        try {
            $this->db->insert('sync_logs', [
                'level' => $level,
                'message' => $message,
                'context' => json_encode($context),
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
            // Cleanup old logs
            $this->cleanup();
        } catch (Exception $e) {
            // Fallback to file logging
            $this->logToFile($level, $message, $context);
        }
    }
    
    public function debug(string $message, array $context = []): void {
        $this->log('debug', $message, $context);
    }
    
    public function info(string $message, array $context = []): void {
        $this->log('info', $message, $context);
    }
    
    public function warning(string $message, array $context = []): void {
        $this->log('warning', $message, $context);
    }
    
    public function error(string $message, array $context = []): void {
        $this->log('error', $message, $context);
    }
    
    public function getLogs(int $limit = 50): array {
        try {
            return $this->db->query(
                "SELECT id, level, message, context, timestamp 
                 FROM sync_logs 
                 ORDER BY timestamp DESC 
                 LIMIT ?",
                [$limit]
            );
        } catch (Exception $e) {
            return [];
        }
    }
    
    private function cleanup(): void {
        $maxEntries = $this->config['max_entries'] ?? 1000;
        
        try {
            $this->db->execute(
                "DELETE FROM sync_logs 
                 WHERE id NOT IN (
                     SELECT id FROM (
                         SELECT id FROM sync_logs ORDER BY timestamp DESC LIMIT ?
                     ) AS keep
                 )",
                [$maxEntries]
            );
        } catch (Exception $e) {
            // Ignore cleanup errors
        }
    }
    
    private function logToFile(string $level, string $message, array $context): void {
        $logPath = $this->config['path'] ?? '/var/log/steinapi';
        $logFile = $logPath . '/steinapi.log';
        
        $entry = sprintf(
            "[%s] %s: %s %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            !empty($context) ? json_encode($context) : ''
        );
        
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}
