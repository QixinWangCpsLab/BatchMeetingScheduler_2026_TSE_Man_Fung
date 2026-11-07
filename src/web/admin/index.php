<?php
declare(strict_types=1);

require_once __DIR__ . '/../components/bootstrap.php';

try {
    $admin = require_admin_page();
    $username = (string) $admin['username'];
    $examCount = (int) db_value('SELECT COUNT(*) FROM exam_admin WHERE admin_username = ?', [$username]);
    $openRounds = (int) db_value(
        'SELECT COUNT(*) FROM exam_round r
         JOIN exam_admin ea ON ea.exam_id = r.exam_id
         WHERE ea.admin_username = ? AND r.status = "open"',
        [$username]
    );
    $waiting = (int) db_value(
        'SELECT COUNT(*) FROM project_group g
         JOIN exam_admin ea ON ea.exam_id = g.exam_id
         LEFT JOIN assignment a ON a.group_id = g.group_id
         WHERE ea.admin_username = ? AND a.assignment_id IS NULL',
        [$username]
    );
    $failedMail = (int) db_value(
        'SELECT COUNT(*) FROM credential_delivery d
         JOIN exam_admin ea ON ea.exam_id = d.exam_id
         WHERE ea.admin_username = ? AND d.status = "failed"',
        [$username]
    );

    $body = count_strip([
        ['count' => $examCount, 'label' => $examCount === 0 ? 'Create an exam' : 'Exams you manage', 'href' => app_url('admin/exams.php')],
        ['count' => $openRounds, 'label' => $openRounds === 0 ? 'No round is open' : 'Open rounds', 'href' => app_url('admin/exams.php?view=open')],
        ['count' => $waiting, 'label' => $waiting === 0 ? 'Everyone has a time' : 'Groups still waiting', 'href' => app_url('admin/exams.php?view=waiting')],
        ['count' => $failedMail, 'label' => $failedMail === 0 ? 'No failed email' : 'Email problems', 'href' => app_url('admin/exams.php?view=email')],
    ]);

    $items = [];
    $rounds = db_all(
        'SELECT r.round_id, r.round_index, r.deadline, e.title, e.exam_id
         FROM exam_round r
         JOIN exam e ON e.exam_id = r.exam_id
         JOIN exam_admin ea ON ea.exam_id = e.exam_id
         WHERE ea.admin_username = ? AND r.status = "open" AND r.deadline > ? AND r.deadline <= ?
         ORDER BY r.deadline ASC',
        [$username, hk_now()->format('Y-m-d H:i:s'), hk_now()->modify('+24 hours')->format('Y-m-d H:i:s')]
    );
    foreach ($rounds as $round) {
        $deadline = hk_parse((string) $round['deadline']);
        $when = 'on ' . hk_short((string) $round['deadline']);
        if ($deadline !== null && $deadline->format('Y-m-d') === hk_now()->format('Y-m-d')) {
            $when = 'today at ' . $deadline->format('H:i');
        } elseif ($deadline !== null && $deadline->format('Y-m-d') === hk_now()->modify('+1 day')->format('Y-m-d')) {
            $when = 'tomorrow at ' . $deadline->format('H:i');
        }
        $items[] = [
            'exam' => (string) $round['title'],
            'text' => $round['title'] . ' round ' . $round['round_index'] . ' closes ' . $when,
            'label' => 'Open round',
            'href' => app_url('admin/exam-rounds.php?exam=' . rawurlencode((string) $round['exam_id'])),
        ];
    }
    $sealed = db_all(
        'SELECT e.exam_id, e.title,
                (SELECT COUNT(*)
                 FROM project_group g
                 LEFT JOIN assignment a ON a.group_id = g.group_id
                 WHERE g.exam_id = e.exam_id AND a.assignment_id IS NULL) AS waiting_count
         FROM exam e
         JOIN exam_admin ea ON ea.exam_id = e.exam_id
         WHERE ea.admin_username = ? AND e.acceptance_mode = "sealed" AND e.status = "active"
         ORDER BY waiting_count DESC',
        [$username]
    );
    foreach ($sealed as $row) {
        $count = (int) $row['waiting_count'];
        if ($count < 1) {
            continue;
        }
        $acceptRound = sealed_accept_round((string) $row['exam_id']);
        if ($acceptRound === null) {
            continue;
        }
        $items[] = [
            'exam' => (string) $row['title'],
            'text' => $count . ' groups are waiting for later acceptance',
            'label' => 'Accept now',
            'href' => app_url('admin/exam-rounds.php?exam=' . rawurlencode((string) $row['exam_id']) . '&accept=' . rawurlencode((string) $acceptRound['round_id'])),
        ];
    }
    $mail = db_all(
        'SELECT e.exam_id, e.title, COUNT(*) AS failed_count
         FROM credential_delivery d
         JOIN exam e ON e.exam_id = d.exam_id
         JOIN exam_admin ea ON ea.exam_id = e.exam_id
         WHERE ea.admin_username = ? AND d.status = "failed"
         GROUP BY e.exam_id, e.title',
        [$username]
    );
    foreach ($mail as $row) {
        $items[] = [
            'exam' => (string) $row['title'],
            'text' => (int) $row['failed_count'] . ' invitation emails failed',
            'label' => 'Review email',
            'href' => app_url('admin/exam-deliveries.php?exam=' . rawurlencode((string) $row['exam_id']) . '&filter=failed'),
        ];
    }
    $drafts = db_all(
        'SELECT e.exam_id, e.title
         FROM exam e
         JOIN exam_admin ea ON ea.exam_id = e.exam_id
         WHERE ea.admin_username = ? AND e.status = "draft"
           AND NOT EXISTS (SELECT 1 FROM exam_timeslot t WHERE t.exam_id = e.exam_id)',
        [$username]
    );
    foreach ($drafts as $row) {
        $items[] = [
            'exam' => (string) $row['title'],
            'text' => 'Draft has no timeslots',
            'label' => 'Continue setup',
            'href' => app_url(continue_setup_href((string) $row['exam_id'])),
        ];
    }
    $unsent = db_all(
        'SELECT e.exam_id, e.title, COUNT(*) AS missing_count
         FROM student_account s
         JOIN exam e ON e.exam_id = s.exam_id
         JOIN exam_admin ea ON ea.exam_id = e.exam_id
         WHERE ea.admin_username = ?
           AND NOT EXISTS (
                SELECT 1 FROM credential_delivery d
                WHERE d.exam_id = s.exam_id AND d.student_id = s.student_id AND d.status IN ("sent", "downloaded")
           )
         GROUP BY e.exam_id, e.title',
        [$username]
    );
    foreach ($unsent as $row) {
        $items[] = [
            'exam' => (string) $row['title'],
            'text' => (int) $row['missing_count'] . ' students have not been sent their entry code',
            'label' => 'Review email',
            'href' => app_url('admin/exam-deliveries.php?exam=' . rawurlencode((string) $row['exam_id'])),
        ];
    }
    $items = array_slice($items, 0, 8);

    $activity = db_all(
        'SELECT a.event_at, a.detail, a.exam_id, e.title, u.display_name AS actor_name
         FROM audit_event a
         JOIN exam e ON e.exam_id = a.exam_id
         JOIN exam_admin ea ON ea.exam_id = a.exam_id
         LEFT JOIN admin_user u ON u.username = a.actor_admin_username
         WHERE ea.admin_username = ?
         ORDER BY a.event_at DESC
         LIMIT 10',
        [$username]
    );

    $body .= '<div class="dashboard-grid"><section class="panel"><h2 class="h5">Needs attention</h2>';
    if ($items === []) {
        $body .= '<p>Nothing needs you right now.</p>';
    } else {
        $body .= '<ul class="attention-list">';
        foreach ($items as $item) {
            $body .= '<li><div><p class="attention-exam">' . e((string) $item['exam']) . '</p><p>' . e($item['text']) . '</p></div><a class="btn btn-outline-secondary btn-sm" href="' . e($item['href']) . '">' . e($item['label']) . '</a></li>';
        }
        $body .= '</ul>';
    }
    $body .= '</section><section class="panel"><h2 class="h5">Recent activity</h2>';
    if ($activity === []) {
        $body .= '<p>Nothing has been recorded yet.</p>';
    } else {
        $body .= '<table id="activity-table" data-client-table data-ignore-filter="true" data-search="false" data-pagination="false" data-show-columns="false"><thead><tr><th data-sortable="true">Time</th><th data-sortable="true">Exam</th><th data-sortable="true">Event</th><th data-sortable="true">User</th><th>Action</th></tr></thead><tbody>';
        foreach ($activity as $row) {
            $body .= '<tr><td>' . e(hk_full((string) $row['event_at'])) . '</td><td>' . e($row['title']) . '</td><td>' . e(first_sentence((string) $row['detail'])) . '</td><td>' . e($row['actor_name'] ?: 'System') . '</td><td><a href="' . e(app_url('admin/exam-audit.php?exam=' . rawurlencode((string) $row['exam_id']))) . '">Open activity</a></td></tr>';
        }
        $body .= '</tbody></table>';
    }
    $body .= '</section></div>';

    $actions = '<a class="btn btn-poly" href="' . e(app_url('admin/exam-new.php')) . '">New exam</a>';
    render_admin($admin, [
        'title' => 'Dashboard',
        'subtitle' => 'What needs you across the exams you manage.',
        'nav' => 'dashboard',
        'actions' => $actions,
    ], $body);
} catch (Throwable $e) {
    error_log($e->__toString());
    render_error_page('The service is not available right now. Try again later.');
}
