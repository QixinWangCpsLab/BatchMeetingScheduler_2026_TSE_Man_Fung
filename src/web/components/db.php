<?php
declare(strict_types=1);

if (!defined('BMS_APP')) {
    http_response_code(404);
    exit;
}

function db(): mysqli
{
    static $ready = false;
    global $conn;
    if ($ready && $conn instanceof mysqli) {
        return $conn;
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require __DIR__ . '/../conn/conn.php';
    if (!($conn instanceof mysqli)) {
        throw new RuntimeException('Database connection failed');
    }
    $conn->set_charset('utf8mb4');
    $conn->query("SET time_zone = '+08:00'");
    $conn->query("SET lc_time_names = 'en_US'");
    $ready = true;
    return $conn;
}

function db_stmt(string $sql, array $params = []): mysqli_stmt
{
    $stmt = db()->prepare($sql);
    if ($params !== []) {
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

function db_all(string $sql, array $params = []): array
{
    $stmt = db_stmt($sql, $params);
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

function db_one(string $sql, array $params = []): ?array
{
    $rows = db_all($sql, $params);
    return $rows[0] ?? null;
}

function db_value(string $sql, array $params = []): mixed
{
    $stmt = db_stmt($sql, $params);
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_row() : null;
    $stmt->close();
    return $row[0] ?? null;
}

function db_exec(string $sql, array $params = []): int
{
    $stmt = db_stmt($sql, $params);
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

function db_tx(callable $work): mixed
{
    $conn = db();
    $conn->begin_transaction();
    try {
        $result = $work();
        $conn->commit();
        return $result;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
