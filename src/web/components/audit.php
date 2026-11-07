<?php
declare(strict_types=1);

if (!defined('BMS_APP')) {
    http_response_code(404);
    exit;
}

function audit_write(
    ?string $examId,
    ?string $roundId,
    ?string $runId,
    ?string $actor,
    string $eventType,
    string $detail
): void {
    db_exec(
        'INSERT INTO audit_event
            (audit_id, exam_id, round_id, run_id, actor_admin_username, event_type, detail, event_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [
            uuid_v7(),
            $examId,
            $roundId,
            $runId,
            $actor,
            $eventType,
            $detail,
            hk_now()->format('Y-m-d H:i:s'),
        ]
    );
}

function touch_exam(string $examId): void
{
    db_exec('UPDATE exam SET updated_at = ? WHERE exam_id = ?', [hk_now()->format('Y-m-d H:i:s'), $examId]);
}
