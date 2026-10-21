<?php
/**
 * Database Class
 * Handles all database operations
 *
 * Verbindung bevorzugt über den lokalen Unix-Socket (MariaDB läuft mit
 * skip-networking, d.h. es gibt keinen TCP-Port). Fallback: host/port.
 */

class Database {
    private $pdo;

    public function __construct(array $config) {
        $charset = $config['charset'] ?? 'utf8mb4';

        if (!empty($config['socket'])) {
            $dsn = sprintf(
                'mysql:unix_socket=%s;dbname=%s;charset=%s',
                $config['socket'],
                $config['name'],
                $charset
            );
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'] ?? 'localhost',
                $config['port'] ?? 3306,
                $config['name'],
                $charset
            );
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];

        $this->pdo = new PDO($dsn, $config['user'], $config['password'], $options);
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function execute(string $sql, array $params = []): int {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Insert a row. Table and column names come from application code only
     * (never from user input) and are additionally restricted to identifiers.
     */
    public function insert(string $table, array $data): int {
        $this->assertIdentifier($table);
        foreach (array_keys($data) as $column) {
            $this->assertIdentifier((string)$column);
        }

        $columns = implode(', ', array_map(fn($c) => "`$c`", array_keys($data)));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $sql = "INSERT INTO `{$table}` ({$columns}) VALUES ({$placeholders})";
        $this->execute($sql, array_values($data));

        return (int)$this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int {
        $this->assertIdentifier($table);
        foreach (array_keys($data) as $column) {
            $this->assertIdentifier((string)$column);
        }

        $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data)));
        $sql = "UPDATE `{$table}` SET {$set} WHERE {$where}";

        return $this->execute($sql, array_merge(array_values($data), $whereParams));
    }

    public function fetchOne(string $sql, array $params = []): ?array {
        $result = $this->query($sql, $params);
        return $result[0] ?? null;
    }

    private function assertIdentifier(string $name): void {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
            throw new InvalidArgumentException("Invalid SQL identifier: $name");
        }
    }
}
