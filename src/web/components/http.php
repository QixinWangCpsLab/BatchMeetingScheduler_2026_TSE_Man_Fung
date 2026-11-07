<?php
declare(strict_types=1);

if (!defined('BMS_APP')) {
    http_response_code(404);
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_base(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $webRoot = str_replace('\\', '/', (string) realpath(dirname(__DIR__)));
    $docRoot = str_replace('\\', '/', (string) (realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: ''));
    if ($docRoot !== '' && str_starts_with($webRoot, $docRoot)) {
        $base = rtrim(substr($webRoot, strlen($docRoot)), '/');
        return $base;
    }
    $env = trim(str_replace('\\', '/', (string) getenv('ROOT_PATH')));
    $base = rtrim($env, '/');
    return $base;
}

function app_url(string $path): string
{
    $path = ltrim($path, '/');
    $base = app_base();
    return ($base === '' ? '' : $base) . '/' . $path;
}

function request_path(): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    return $path !== '' ? $path : app_url('index.php');
}

function wants_json(): bool
{
    if (isset($_GET['ajax']) || isset($_POST['ajax'])) {
        return true;
    }
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if (str_contains($accept, 'application/json')) {
        return true;
    }
    return (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch');
}

function json_out(array $data, int $status = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    exit;
}

function redirect_to(string $path): never
{
    if (preg_match('#^https?://#i', $path)) {
        $path = 'index.php';
    }
    $location = str_starts_with($path, '/') ? $path : app_url($path);
    header('Location: ' . $location);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_ok(mixed $token): bool
{
    $known = $_SESSION['csrf'] ?? '';
    return is_string($token) && is_string($known) && $known !== '' && hash_equals($known, $token);
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function flash(string $message, string $type = 'success'): void
{
    if (!isset($_SESSION['flashes']) || !is_array($_SESSION['flashes'])) {
        $_SESSION['flashes'] = [];
    }
    $_SESSION['flashes'][] = ['message' => $message, 'type' => $type === 'danger' ? 'danger' : 'success'];
}

function take_flashes(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);
    return is_array($flashes) ? $flashes : [];
}

function post_str(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function respond(array $payload): never
{
    $ok = !empty($payload['ok']);
    if (!empty($payload['redirect'])) {
        $payload['redirect'] = str_starts_with((string) $payload['redirect'], '/')
            ? (string) $payload['redirect']
            : app_url(ltrim((string) $payload['redirect'], '/'));
    }
    if (wants_json()) {
        if ($ok && !empty($payload['message'])) {
            flash((string) $payload['message'], 'success');
        }
        json_out($payload, $ok ? 200 : 422);
    }
    if (!empty($payload['message'])) {
        flash((string) $payload['message'], $ok ? 'success' : 'danger');
    }
    $target = (string) ($payload['redirect'] ?? 'admin/index.php');
    if (str_starts_with($target, '/')) {
        header('Location: ' . $target);
        exit;
    }
    redirect_to($target);
}

function friendly_exception(Throwable $e): string
{
    $message = $e->getMessage();
    if (str_contains($message, 'chk_round_time_range')) {
        return 'The deadline must be after the opening time.';
    }
    if (str_contains($message, 'chk_slot_range')) {
        return 'The time slot end must be after its start.';
    }
    if (str_contains($message, 'uq_exam_code')) {
        return 'That entry code is already used.';
    }
    if (str_contains($message, 'uq_admin_email') || str_contains($message, 'admin_user.email')) {
        return 'That email is already used.';
    }
    error_log($e->__toString());
    return 'Not saved. Check the connection and try again.';
}

function search_like(string $term): string
{
    $term = mb_substr(trim($term), 0, 100);
    $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    return '%' . $term . '%';
}

function sort_dir(string $value, string $fallback = 'DESC'): string
{
    $value = strtolower($value);
    if ($value === 'asc' || $value === 'desc') {
        return strtoupper($value);
    }
    return $fallback;
}

function page_limit(mixed $value): int
{
    $number = (int) $value;
    return in_array($number, [10, 30, 50, 80, 100], true) ? $number : 30;
}

function page_offset(mixed $value): int
{
    return max(0, (int) $value);
}

function table_query(string $orderFallback = 'DESC'): array
{
    $filter = trim((string) ($_GET['filter'] ?? 'all'));
    return [
        'search' => trim((string) ($_GET['search'] ?? '')),
        'sort' => (string) ($_GET['sort'] ?? ''),
        'order' => sort_dir((string) ($_GET['order'] ?? ''), $orderFallback),
        'limit' => page_limit($_GET['limit'] ?? 30),
        'offset' => page_offset($_GET['offset'] ?? 0),
        'filter' => $filter === '' ? 'all' : $filter,
    ];
}

function sort_sql(string $requested, array $map, string $fallback): string
{
    $column = $map[$requested] ?? $fallback;
    if (!is_string($column) || preg_match('/^[A-Za-z0-9_.]+$/', $column) !== 1) {
        return $fallback;
    }
    return $column;
}

function one_of(string $value, array $allowed, string $fallback): string
{
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function sql_like(): string
{
    return "LIKE ? ESCAPE '\\\\'";
}

function page_slice(array $rows, int $offset, int $limit): array
{
    return [
        'total' => count($rows),
        'rows' => array_values(array_slice($rows, $offset, $limit)),
    ];
}

function sql_page(int $limit, int $offset): string
{
    return ' LIMIT ' . page_limit($limit) . ' OFFSET ' . page_offset($offset);
}

function exam_href(string $script, string $examId): string
{
    return 'admin/' . $script . '?exam=' . rawurlencode($examId);
}

function respond_back(array $result, string $back, ?string $detail = null): never
{
    if (empty($result['redirect'])) {
        $target = $back;
        $extra = [];
        if ($detail !== null && $detail !== '') {
            $extra[] = 'detail=' . rawurlencode($detail);
        }
        if (!empty($result['ok']) && !empty($result['row'])) {
            $extra[] = 'flash_row=' . rawurlencode((string) $result['row']);
        }
        if ($extra !== []) {
            $target .= (str_contains($target, '?') ? '&' : '?') . implode('&', $extra);
        }
        $result['redirect'] = $target;
    }
    respond($result);
}

function page_fail(Throwable $e): never
{
    error_log($e->__toString());
    if (wants_json()) {
        json_out(['ok' => false, 'message' => friendly_exception($e)], 500);
    }
    render_error_page('The service is not available right now. Try again later.');
    exit;
}

function sign_out_form(): string
{
    return '<form method="post" action="' . e(app_url('index.php')) . '" class="sign-out-form">'
        . csrf_input()
        . '<input type="hidden" name="action" value="logout">'
        . '<button type="submit" class="btn btn-link sign-out-button">Sign out</button>'
        . '</form>';
}

function root_url(): string
{
    $url = trim((string) getenv('ROOT_URL'));
    if ($url !== '') {
        return rtrim($url, '/');
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scheme = request_is_https() ? 'https' : 'http';
    return $scheme . '://' . $host;
}
