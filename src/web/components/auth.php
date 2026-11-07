<?php
declare(strict_types=1);

if (!defined('BMS_APP')) {
    http_response_code(404);
    exit;
}

function lockout_path(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bms-login-lock.json';
}

function lockout_update(callable $work): mixed
{
    $handle = fopen(lockout_path(), 'c+');
    if ($handle === false) {
        $data = [];
        return $work($data);
    }
    flock($handle, LOCK_EX);
    $raw = stream_get_contents($handle);
    $data = json_decode($raw === false || $raw === '' ? '[]' : $raw, true);
    if (!is_array($data)) {
        $data = [];
    }
    $now = time();
    foreach ($data as $key => $row) {
        $lockedUntil = (int) ($row['locked_until'] ?? 0);
        $fails = (int) ($row['fails'] ?? 0);
        if ($lockedUntil > 0 && $lockedUntil < $now) {
            unset($data[$key]);
        } elseif ($lockedUntil === 0 && $fails === 0) {
            unset($data[$key]);
        }
    }
    $result = $work($data);
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($data));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $result;
}

function lockout_key(string $kind, string $name): string
{
    return $kind . "\n" . $name;
}

function lockout_status(string $kind, string $name): ?int
{
    return lockout_update(static function (array &$data) use ($kind, $name): ?int {
        $row = $data[lockout_key($kind, $name)] ?? null;
        if (!is_array($row)) {
            return null;
        }
        $until = (int) ($row['locked_until'] ?? 0);
        if ($until > time()) {
            return $until;
        }
        return null;
    });
}

function lockout_fail(string $kind, string $name): ?int
{
    return lockout_update(static function (array &$data) use ($kind, $name): ?int {
        $key = lockout_key($kind, $name);
        $row = $data[$key] ?? ['fails' => 0, 'locked_until' => 0];
        $until = (int) ($row['locked_until'] ?? 0);
        if ($until > time()) {
            return $until;
        }
        $fails = (int) ($row['fails'] ?? 0) + 1;
        $locked = 0;
        if ($fails >= 5) {
            $fails = 5;
            $locked = time() + 15 * 60;
        }
        $data[$key] = ['fails' => $fails, 'locked_until' => $locked];
        return $locked > 0 ? $locked : null;
    });
}

function lockout_clear(string $kind, string $name): void
{
    lockout_update(static function (array &$data) use ($kind, $name): null {
        unset($data[lockout_key($kind, $name)]);
        return null;
    });
}

function lockout_message(int $until): string
{
    $seconds = max(1, $until - time());
    $minutes = (int) ceil($seconds / 60);
    if ($minutes <= 1) {
        return 'Sign-in is paused for 1 minute.';
    }
    return 'Sign-in is paused for ' . $minutes . ' minutes.';
}

function end_invalid_session(): void
{
    logout_and_restart();
    flash('Sign in again.', 'danger');
}

function establish_teacher(array $admin): void
{
    session_regenerate_id(true);
    unset($_SESSION['student_id'], $_SESSION['exam_id'], $_SESSION['pending_student']);
    $_SESSION['actor'] = 'teacher';
    $_SESSION['admin_username'] = (string) $admin['username'];
    $_SESSION['signed_in_at'] = hk_now()->format('Y-m-d H:i:s');
    $_SESSION['credential_stamp'] = credential_stamp((string) $admin['password_hash']);
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function establish_student(array $student): void
{
    session_regenerate_id(true);
    unset($_SESSION['admin_username'], $_SESSION['pending_student']);
    $_SESSION['actor'] = 'student';
    $_SESSION['student_id'] = (string) $student['student_id'];
    $_SESSION['exam_id'] = (string) $student['exam_id'];
    $_SESSION['signed_in_at'] = hk_now()->format('Y-m-d H:i:s');
    $_SESSION['credential_stamp'] = credential_stamp((string) $student['password_hash']);
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function current_admin(): ?array
{
    if (($_SESSION['actor'] ?? '') !== 'teacher') {
        return null;
    }
    $username = (string) ($_SESSION['admin_username'] ?? '');
    if ($username === '') {
        end_invalid_session();
        return null;
    }
    $admin = db_one('SELECT * FROM admin_user WHERE username = ?', [$username]);
    $stamp = (string) ($_SESSION['credential_stamp'] ?? '');
    if (
        $admin === null
        || (int) $admin['is_active'] !== 1
        || $stamp === ''
        || !hash_equals(credential_stamp((string) $admin['password_hash']), $stamp)
    ) {
        end_invalid_session();
        return null;
    }
    return $admin;
}

function current_student(): ?array
{
    if (($_SESSION['actor'] ?? '') !== 'student') {
        return null;
    }
    $studentId = (string) ($_SESSION['student_id'] ?? '');
    $examId = (string) ($_SESSION['exam_id'] ?? '');
    $student = db_one(
        'SELECT s.*, e.title AS exam_title, e.exam_code, e.subject, e.status AS exam_status
         FROM student_account s
         JOIN exam e ON e.exam_id = s.exam_id
         WHERE s.student_id = ? AND s.exam_id = ?',
        [$studentId, $examId]
    );
    $stamp = (string) ($_SESSION['credential_stamp'] ?? '');
    if (
        $student === null
        || (int) $student['is_active'] !== 1
        || $stamp === ''
        || !hash_equals(credential_stamp((string) $student['password_hash']), $stamp)
    ) {
        end_invalid_session();
        return null;
    }
    return $student;
}

function require_admin_page(): array
{
    $admin = current_admin();
    if ($admin !== null) {
        return $admin;
    }
    if (($_SESSION['actor'] ?? '') === 'student' || current_student() !== null) {
        if (wants_json()) {
            json_out(['ok' => false, 'message' => 'Sign in again.'], 401);
        }
        redirect_to('student/index.php');
    }
    if (wants_json()) {
        json_out(['ok' => false, 'message' => 'Sign in again.'], 401);
    }
    redirect_to('index.php');
}

function require_professor(array $admin): void
{
    if (($admin['role'] ?? '') === 'prof') {
        return;
    }
    flash('Only a professor can manage admin accounts.', 'danger');
    redirect_to('admin/index.php');
}

function managed_exam(array $admin, string $examId): ?array
{
    if (!is_uuid($examId)) {
        return null;
    }
    return db_one(
        'SELECT e.*
         FROM exam e
         JOIN exam_admin ea ON ea.exam_id = e.exam_id
         WHERE e.exam_id = ? AND ea.admin_username = ?',
        [$examId, $admin['username']]
    );
}

function require_exam(array $admin, string $examId): array
{
    $exam = managed_exam($admin, $examId);
    if ($exam === null) {
        if (wants_json()) {
            json_out(['ok' => false, 'message' => 'That exam is not available.'], 404);
        }
        flash('That exam is not available.', 'danger');
        redirect_to('admin/exams.php');
    }
    return $exam;
}

function can_grant_access(array $admin, array $exam): bool
{
    return ($admin['role'] ?? '') === 'prof'
        || ($exam['created_by_admin_username'] ?? '') === ($admin['username'] ?? '');
}

function require_post_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        if (wants_json()) {
            json_out(['ok' => false, 'message' => 'This page has expired. Reload and try again.'], 405);
        }
        http_response_code(405);
        exit;
    }
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        respond([
            'ok' => false,
            'message' => 'This page has expired. Reload and try again.',
            'redirect' => ltrim((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'), '/'),
        ]);
    }
}

function guard_completed(array $exam): ?string
{
    if (($exam['status'] ?? '') === 'completed') {
        return 'This exam is completed. You can still review it.';
    }
    return null;
}
