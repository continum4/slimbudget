<?php
declare(strict_types=1);

namespace App;

use PDO;
use PDOStatement;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private array $config)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->config['host'], $this->config['port'], $this->config['name']),
            $this->config['user'],
            $this->config['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function row(string $sql, array $params = []): ?array
    {
        return $this->run($sql, $params)->fetch() ?: null;
    }

    public function value(string $sql, array $params = []): mixed
    {
        return $this->run($sql, $params)->fetchColumn();
    }

    /** Keys of $data are column names written in code, never user input. */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $this->run(sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(',', $columns), implode(',', array_fill(0, count($columns), '?'))), array_values($data));
        return (int) $this->pdo()->lastInsertId();
    }

    public function update(string $table, int $id, array $data): void
    {
        $set = implode(',', array_map(static fn (string $column): string => "$column=?", array_keys($data)));
        $this->run("UPDATE $table SET $set WHERE id=?", [...array_values($data), $id]);
    }

    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
