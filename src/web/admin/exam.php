<?php
declare(strict_types=1);

require_once __DIR__ . '/../components/bootstrap.php';

try {
    $admin = require_admin_page();
    $exam = require_exam($admin, (string) ($_GET['exam'] ?? $_POST['exam_id'] ?? ''));
    $examId = (string) $exam['exam_id'];

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_post_csrf();
        $action = (string) ($_POST['action'] ?? '');
        $result = ['ok' => false, 'message' => 'That action is not available.'];
        if ($action === 'open_round') {
            $result = open_round_action($admin, post_str('round_id'));
        } elseif ($action === 'close_round') {
            $result = close_round_action($admin, post_str('round_id'));
        } elseif ($action === 'add_round') {
            $result = add_round_action($admin, $examId, post_str('start_at'), post_str('deadline'));
        } elseif ($action === 'complete') {
            $result = complete_exam_action($admin, $examId, post_str('confirm_code'));
        } elseif ($action === 'delete_draft') {
            $result = delete_draft_action($admin, $examId, post_str('confirm_code'));
        }
        $result['redirect'] = $result['redirect'] ?? ('admin/exam.php?exam=' . rawurlencode($examId));
        respond($result);
    }

    $round = latest_round($examId);
    $groupCount = (int) db_value('SELECT COUNT(*) FROM project_group WHERE exam_id = ?', [$examId]);
    $assigned = (int) db_value('SELECT COUNT(*) FROM assignment a JOIN project_group g ON g.group_id = a.group_id WHERE g.exam_id = ?', [$examId]);
    $available = (int) db_value('SELECT COUNT(*) FROM exam_timeslot WHERE exam_id = ? AND status = "available"', [$examId]);
    $failed = (int) db_value('SELECT COUNT(*) FROM credential_delivery WHERE exam_id = ? AND status = "failed"', [$examId]);
    $submitted = 0;
    if ($round !== null) {
        $submitted = (int) db_value('SELECT COUNT(*) FROM preference_submission WHERE round_id = ? AND status = "current"', [$round['round_id']]);
    }
    $openRound = db_one('SELECT * FROM exam_round WHERE exam_id = ? AND status = "open" ORDER BY round_index DESC LIMIT 1', [$examId]);
    $waiting = $groupCount - $assigned;
    $acceptRound = sealed_accept_round($examId);
    $showAccept = $exam['status'] === 'active' && $exam['acceptance_mode'] === 'sealed' && $acceptRound !== null && $waiting > 0;

    $actions = '';
    if ($exam['status'] === 'draft') {
        $actions .= '<a class="btn btn-poly" href="' . e(app_url(continue_setup_href($examId))) . '">Continue setup</a>';
        if ($round !== null && in_array($round['status'], ['pending', 'open'], true)) {
            $actions .= '<button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#open-round">Open round</button>';
        }
    }
    if ($exam['status'] === 'active' && $exam['acceptance_mode'] === 'fcfs' && $openRound !== null) {
        $actions .= '<button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#close-round">Close round</button>';
    }
    if ($showAccept) {
        $actions .= '<a class="btn btn-poly" href="' . e(app_url('admin/exam-rounds.php?exam=' . rawurlencode($examId) . '&accept=' . rawurlencode((string) $acceptRound['round_id']))) . '">Accept preferences</a>';
    }
    if ($exam['status'] === 'active') {
        $actions .= '<button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#add-round">Add next round</button>';
    }
    if ($exam['status'] !== 'completed') {
        $actions .= '<button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#complete-exam">Mark completed</button>';
    }
    if ($exam['status'] === 'draft' && draft_can_delete($examId)) {
        $actions .= '<button class="btn btn-outline-danger" type="button" data-bs-toggle="modal" data-bs-target="#delete-draft">Delete draft</button>';
    }

    $body = '<div class="exam-mast"><p class="exam-subject">' . e($exam['subject']) . '</p><p class="exam-code">' . e($exam['exam_code']) . '</p><p class="exam-badges">'
        . exam_badge((string) $exam['status']) . mode_badge((string) $exam['acceptance_mode']) . '</p></div>';
    $body .= '<p class="mode-line">' . e(mode_sentence((string) $exam['acceptance_mode'])) . '</p>';
    $body .= count_strip([
        ['count' => $groupCount, 'label' => 'Groups', 'href' => app_url('admin/exam-groups.php?exam=' . rawurlencode($examId))],
        ['count' => $assigned, 'label' => 'Assigned', 'href' => app_url('admin/exam-assignments.php?exam=' . rawurlencode($examId))],
        ['count' => $available, 'label' => 'Chairs still available', 'href' => app_url('admin/exam-timeslots.php?exam=' . rawurlencode($examId) . '&filter=empty')],
        ['count' => $failed, 'label' => 'Failed emails', 'href' => app_url('admin/exam-deliveries.php?exam=' . rawurlencode($examId) . '&filter=failed')],
    ]);
    $body .= '<div class="dashboard-grid"><section class="panel"><h2 class="h5">This round</h2>';
    if ($round === null) {
        $body .= '<p>Not started.</p>';
    } else {
        $body .= '<p>Round ' . e((string) $round['round_index']) . ' ' . round_badge((string) $round['status']) . '</p>';
        $body .= '<p>Opens ' . e(hk_full((string) $round['start_at'])) . '. Deadline ' . e(hk_full((string) $round['deadline'])) . '.</p>';
        $body .= '<p>' . e((string) $submitted) . ' groups have a latest choice. ' . e((string) max(0, $groupCount - $submitted)) . ' have not.</p>';
    }
    $body .= '<a class="btn btn-outline-secondary" href="' . e(app_url('admin/exam-rounds.php?exam=' . rawurlencode($examId))) . '">Open rounds</a></section>';
    $activity = db_all(
        'SELECT a.event_at, a.detail, e.title, u.display_name AS actor_name
         FROM audit_event a
         JOIN exam e ON e.exam_id = a.exam_id
         LEFT JOIN admin_user u ON u.username = a.actor_admin_username
         WHERE a.exam_id = ?
         ORDER BY a.event_at DESC LIMIT 5',
        [$examId]
    );
    $body .= '<section class="panel"><h2 class="h5">Latest activity</h2>';
    if ($activity === []) {
        $body .= '<p>Nothing has been recorded for this exam yet.</p>';
    } else {
        $body .= '<table id="exam-activity-table" data-client-table data-ignore-filter="true" data-search="false" data-pagination="false" data-show-columns="false"><thead><tr><th data-sortable="true">Time</th><th data-sortable="true">Exam</th><th data-sortable="true">Event</th><th data-sortable="true">User</th></tr></thead><tbody>';
        foreach ($activity as $row) {
            $body .= '<tr><td>' . e(hk_full((string) $row['event_at'])) . '</td><td>' . e($row['title']) . '</td><td>' . e(first_sentence((string) $row['detail'])) . '</td><td>' . e($row['actor_name'] ?: 'System') . '</td></tr>';
        }
        $body .= '</tbody></table>';
    }
    $body .= '<a href="' . e(app_url('admin/exam-audit.php?exam=' . rawurlencode($examId))) . '">Open activity</a></section></div>';

    if ($round !== null) {
        $others = db_all('SELECT round_index FROM exam_round WHERE exam_id = ? AND status = "open" AND round_id <> ?', [$examId, $round['round_id']]);
        $body .= modal_start('open-round', 'Open Round ' . $round['round_index'], 'open_round', ['exam_id' => $examId, 'round_id' => $round['round_id']]);
        if ($others !== []) {
            $body .= '<p>Round ' . e((string) $others[0]['round_index']) . ' is still open. It will be closed before Round ' . e((string) $round['round_index']) . ' opens.</p>';
        } else {
            $body .= '<p>Students can submit preferences while this round is open.</p>';
        }
        $body .= modal_end('Open Round ' . $round['round_index']);
        if ($openRound !== null) {
            $body .= modal_start('close-round', 'Close Round ' . $openRound['round_index'], 'close_round', ['exam_id' => $examId, 'round_id' => $openRound['round_id']]);
            $body .= '<p>Time slots already taken stay with their groups.</p>';
            $body .= modal_end('Close Round ' . $openRound['round_index']);
        }
    }
    $nextIndex = $round === null ? 1 : ((int) $round['round_index'] + 1);
    $body .= modal_start('add-round', 'Add Round ' . $nextIndex, 'add_round', ['exam_id' => $examId]);
    $body .= '<p>Only groups still waiting will choose again. Groups that already have a time slot stay where they are.</p>';
    $body .= text_field('start_at', 'Opens', '', ['type' => 'datetime-local', 'help' => 'Hong Kong time.']);
    $body .= text_field('deadline', 'Deadline', '', ['type' => 'datetime-local', 'help' => 'Hong Kong time.']);
    $body .= modal_end('Add Round ' . $nextIndex);
    $body .= modal_start('complete-exam', 'Mark ' . $exam['title'] . ' completed', 'complete', ['exam_id' => $examId]);
    $body .= '<p>Students will not be able to submit preferences. You can still review results and activity.</p>';
    $body .= text_field('confirm_code', 'Exam code', '', ['match' => $exam['exam_code'], 'help' => 'Type ' . $exam['exam_code'] . ' to confirm.']);
    $body .= modal_end('Mark completed', true, 'Saving', true);
    if ($exam['status'] === 'draft' && draft_can_delete($examId)) {
        $body .= modal_start('delete-draft', 'Delete draft ' . $exam['title'], 'delete_draft', ['exam_id' => $examId]);
        $body .= '<p>This draft has no students, assignments, or email records.</p>';
        $body .= text_field('confirm_code', 'Exam code', '', ['id' => 'delete_code', 'match' => $exam['exam_code'], 'help' => 'Type ' . $exam['exam_code'] . ' to confirm.']);
        $body .= modal_end('Delete draft', true, 'Saving', true);
    }

    render_admin($admin, [
        'title' => (string) $exam['title'],
        'subtitle' => 'Where this exam stands right now.',
        'nav' => 'exams',
        'exam' => $exam,
        'exam_nav' => 'overview',
        'crumbs' => exam_crumbs($exam, 'Overview'),
        'actions' => $actions,
    ], $body);
} catch (Throwable $e) {
    error_log($e->__toString());
    if (wants_json()) {
        json_out(['ok' => false, 'message' => friendly_exception($e)], 500);
    }
    render_error_page('The service is not available right now. Try again later.');
}
