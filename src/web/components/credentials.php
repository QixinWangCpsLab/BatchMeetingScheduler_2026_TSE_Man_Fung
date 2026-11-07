<?php
declare(strict_types=1);

if (!defined('BMS_APP')) {
    http_response_code(404);
    exit;
}

function students_without_delivery(string $examId): array
{
    return db_all(
        'SELECT s.student_id, s.display_name, s.email
         FROM student_account s
         WHERE s.exam_id = ?
           AND NOT EXISTS (
                SELECT 1 FROM credential_delivery d
                WHERE d.exam_id = s.exam_id
                  AND d.student_id = s.student_id
                  AND d.status IN ("sent", "downloaded")
           )
         ORDER BY s.display_name ASC, s.student_id ASC',
        [$examId]
    );
}

function student_has_delivery(string $examId, string $studentId): bool
{
    return (int) db_value(
        'SELECT COUNT(*) FROM credential_delivery
         WHERE exam_id = ? AND student_id = ? AND status IN ("sent", "downloaded", "failed", "pending")',
        [$examId, $studentId]
    ) > 0;
}

function issue_email_credential(array $exam, array $student, string $adminUsername, bool $again): array
{
    if (!mail_is_configured()) {
        return ['ok' => false, 'message' => 'Email is not configured. Download the spreadsheet instead.'];
    }
    $password = generate_student_password();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $now = hk_now()->format('Y-m-d H:i:s');
    $deliveryId = uuid_v7();
    db_tx(static function () use ($exam, $student, $adminUsername, $hash, $now, $deliveryId): void {
        db_exec(
            'UPDATE student_account SET password_hash = ?, updated_at = ? WHERE student_id = ? AND exam_id = ?',
            [$hash, $now, $student['student_id'], $exam['exam_id']]
        );
        db_exec(
            'INSERT INTO credential_delivery
                (delivery_id, student_id, exam_id, requested_by_admin_username, status, requested_at)
             VALUES (?, ?, ?, ?, "pending", ?)',
            [$deliveryId, $student['student_id'], $exam['exam_id'], $adminUsername, $now]
        );
    });
    $error = send_entry_mail(
        (string) $student['email'],
        (string) $student['display_name'],
        'Your entry code for ' . $exam['title'],
        entry_mail_body($exam, (string) $student['student_id'], $password)
    );
    $finished = hk_now()->format('Y-m-d H:i:s');
    if ($error === null) {
        db_exec(
            'UPDATE credential_delivery SET status = "sent", completed_at = ?, error_message = NULL WHERE delivery_id = ?',
            [$finished, $deliveryId]
        );
        audit_write(
            (string) $exam['exam_id'],
            null,
            null,
            $adminUsername,
            $again ? 'credential_resent' : 'credential_sent',
            ($again ? 'Sent a new entry password to ' : 'Sent an entry code to ')
                . $student['display_name'] . ' (' . $student['student_id'] . ').'
        );
        return ['ok' => true, 'status' => 'sent'];
    }
    db_exec(
        'UPDATE credential_delivery SET status = "failed", completed_at = ?, error_message = ? WHERE delivery_id = ?',
        [$finished, $error, $deliveryId]
    );
    audit_write(
        (string) $exam['exam_id'],
        null,
        null,
        $adminUsername,
        $again ? 'credential_resent' : 'credential_sent',
        'Could not email ' . $student['display_name'] . ' (' . $student['student_id'] . ').'
    );
    return ['ok' => false, 'status' => 'failed', 'message' => 'The email could not be sent.'];
}

function send_entry_batch(array $exam, array $students, string $adminUsername, bool $again): array
{
    $sent = 0;
    $failed = 0;
    foreach ($students as $student) {
        $result = issue_email_credential($exam, $student, $adminUsername, $again || student_has_delivery((string) $exam['exam_id'], (string) $student['student_id']));
        if (($result['status'] ?? '') === 'sent') {
            $sent++;
        } else {
            $failed++;
            if (!mail_is_configured()) {
                return ['ok' => false, 'sent' => $sent, 'failed' => $failed, 'message' => 'Email is not configured. Download the spreadsheet instead.'];
            }
        }
    }
    return ['ok' => $failed === 0, 'sent' => $sent, 'failed' => $failed];
}

function download_credentials(array $exam, string $adminUsername): array
{
    $students = db_all(
        'SELECT student_id, display_name, email FROM student_account WHERE exam_id = ? ORDER BY display_name ASC, student_id ASC',
        [$exam['exam_id']]
    );
    if ($students === []) {
        return ['ok' => false, 'message' => 'This exam has no students yet.'];
    }
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $passwords = [];
    $now = hk_now()->format('Y-m-d H:i:s');
    $sheetRows = [['Student ID', 'Student Name', 'Student Email', 'Password', 'Exam code']];
    foreach ($students as $student) {
        $password = generate_student_password();
        $passwords[] = [
            'student_id' => (string) $student['student_id'],
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'delivery_id' => uuid_v7(),
        ];
        $sheetRows[] = [
            (string) $student['student_id'],
            (string) $student['display_name'],
            (string) $student['email'],
            $password,
            (string) $exam['exam_code'],
        ];
    }
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->getActiveSheet()->fromArray($sheetRows, null, 'A1');
    $temp = tempnam(sys_get_temp_dir(), 'bmscred');
    if ($temp === false) {
        return ['ok' => false, 'message' => 'Not saved. Check the connection and try again.'];
    }
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save($temp);
    try {
        db_tx(static function () use ($exam, $adminUsername, $passwords, $now): void {
            foreach ($passwords as $row) {
                db_exec(
                    'UPDATE student_account SET password_hash = ?, updated_at = ? WHERE student_id = ? AND exam_id = ?',
                    [$row['hash'], $now, $row['student_id'], $exam['exam_id']]
                );
                db_exec(
                    'INSERT INTO credential_delivery
                        (delivery_id, student_id, exam_id, requested_by_admin_username, status, requested_at, completed_at)
                     VALUES (?, ?, ?, ?, "downloaded", ?, ?)',
                    [$row['delivery_id'], $row['student_id'], $exam['exam_id'], $adminUsername, $now, $now]
                );
            }
        });
    } catch (Throwable $e) {
        @unlink($temp);
        throw $e;
    }
    audit_write(
        (string) $exam['exam_id'],
        null,
        null,
        $adminUsername,
        'credential_downloaded',
        'Downloaded credentials for ' . count($students) . ' students.'
    );
    touch_exam((string) $exam['exam_id']);
    $filename = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $exam['exam_code']) . '-credentials.xlsx';
    return ['ok' => true, 'path' => $temp, 'filename' => $filename, 'count' => count($students)];
}

function send_download(string $path, string $filename): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    @unlink($path);
    exit;
}
