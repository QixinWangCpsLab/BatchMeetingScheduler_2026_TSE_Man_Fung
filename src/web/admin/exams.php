<?php
declare(strict_types=1);

require_once __DIR__ . '/../components/bootstrap.php';

try {
    $admin = require_admin_page();
    $status = (string) ($_GET['status'] ?? 'all');
    if (!in_array($status, ['all', 'draft', 'active', 'completed'], true)) {
        $status = 'all';
    }
    $view = (string) ($_GET['view'] ?? '');
    $rows = db_all(
        'SELECT e.*,
            (SELECT COUNT(*) FROM project_group g WHERE g.exam_id = e.exam_id) AS group_count,
            (SELECT COUNT(*) FROM assignment a JOIN project_group g ON g.group_id = a.group_id WHERE g.exam_id = e.exam_id) AS assigned_count,
            (SELECT COUNT(*) FROM exam_timeslot t WHERE t.exam_id = e.exam_id) AS slot_count,
            (SELECT COUNT(*) FROM credential_delivery d WHERE d.exam_id = e.exam_id AND d.status = "failed") AS failed_count,
            (SELECT COUNT(*) FROM exam_round r WHERE r.exam_id = e.exam_id AND r.status = "open") AS open_count
         FROM exam e
         JOIN exam_admin ea ON ea.exam_id = e.exam_id
         WHERE ea.admin_username = ?
         ORDER BY e.updated_at DESC',
        [$admin['username']]
    );
    $visible = [];
    foreach ($rows as $row) {
        if ($status !== 'all' && $row['status'] !== $status) {
            continue;
        }
        if ($view === 'open' && (int) $row['open_count'] < 1) {
            continue;
        }
        if ($view === 'waiting' && (int) $row['assigned_count'] >= (int) $row['group_count']) {
            continue;
        }
        if ($view === 'email' && (int) $row['failed_count'] < 1) {
            continue;
        }
        $visible[] = $row;
    }
    $note = match ($view) {
        'open' => 'Showing exams with an open round.',
        'waiting' => 'Showing exams that still have a group without a time slot.',
        'email' => 'Showing exams with a failed email.',
        default => '',
    };
    $body = '';
    if ($note !== '') {
        $body .= '<p>' . e($note) . ' <a href="' . e(app_url('admin/exams.php')) . '">Show all exams</a></p>';
    }
    $body .= filter_bar('Status', ['all' => 'All', 'draft' => 'Draft', 'active' => 'Active', 'completed' => 'Completed'], $status);
    if ($rows === [] ) {
        $body .= empty_state('You do not manage any exam yet.', 'New exam', app_url('admin/exam-new.php'));
    } elseif ($visible === []) {
        $body .= empty_state('No exam matches this filter.', 'Show all exams', app_url('admin/exams.php'));
    } else {
        $body .= '<table id="exam-table" data-client-table data-detail-url="' . e(request_path()) . '" data-sort-name="updated" data-sort-order="desc"><thead><tr>'
            . '<th data-sortable="true">Exam</th><th data-sortable="true">Code</th><th data-sortable="true">Mode</th><th data-sortable="true">Status</th><th data-sortable="true">Current round</th><th data-sortable="true">Groups</th><th data-sortable="true">Assigned</th><th data-sortable="true">Closes</th><th data-sortable="true">Updated</th><th>Action</th>'
            . '</tr></thead><tbody>';
        foreach ($visible as $row) {
            $round = db_one('SELECT round_index, status, deadline FROM exam_round WHERE exam_id = ? ORDER BY round_index DESC LIMIT 1', [$row['exam_id']]);
            $closing = db_one('SELECT deadline FROM exam_round WHERE exam_id = ? AND status IN ("open", "pending") ORDER BY status = "open" DESC, deadline ASC LIMIT 1', [$row['exam_id']]);
            $current = $round === null ? 'Not started' : ('Round ' . $round['round_index'] . ' · ' . round_label((string) $round['status']));
            $body .= '<tr data-id="' . e($row['exam_id']) . '" data-filter="' . e($row['status']) . '">';
            $body .= '<td><div>' . e($row['title']) . '</div><div class="text-muted">' . e($row['subject']) . '</div></td>';
            $body .= '<td>' . e($row['exam_code']) . '</td>';
            $body .= '<td>' . e(mode_label((string) $row['acceptance_mode'])) . '</td>';
            $body .= '<td>' . exam_badge((string) $row['status']) . '</td>';
            $body .= '<td>' . e($current) . '</td>';
            $body .= '<td>' . e((string) $row['group_count']) . '</td>';
            $body .= '<td>' . e((string) $row['assigned_count']) . ' / ' . e((string) $row['group_count']) . '</td>';
            $body .= '<td>' . e($closing ? hk_full((string) $closing['deadline']) : '—') . '</td>';
            $body .= '<td>' . e(hk_full((string) $row['updated_at'])) . '</td>';
            $body .= '<td><a class="btn btn-link" href="' . e(app_url('admin/exam.php?exam=' . rawurlencode((string) $row['exam_id']))) . '">Open</a></td>';
            $body .= '</tr>';
        }
        $body .= '</tbody></table>';
    }
    if (($_GET['ajax'] ?? '') === 'detail') {
        $exam = managed_exam($admin, (string) ($_GET['id'] ?? ''));
        if ($exam === null) {
            json_out(['ok' => false, 'message' => 'That exam is not available.'], 404);
        }
        $html = '<p>' . e($exam['subject']) . '</p><p>' . exam_badge((string) $exam['status']) . ' ' . mode_badge((string) $exam['acceptance_mode']) . '</p>'
            . '<p>' . e(mode_sentence((string) $exam['acceptance_mode'])) . '</p>'
            . '<p class="reference-line"><span>Reference</span> <code>' . e($exam['exam_id']) . '</code> <button type="button" class="btn btn-link" data-copy="' . e($exam['exam_id']) . '">Copy</button></p>'
            . '<a class="btn btn-poly" href="' . e(app_url('admin/exam.php?exam=' . rawurlencode((string) $exam['exam_id']))) . '">Open</a>';
        json_out(['ok' => true, 'title' => (string) $exam['title'], 'html' => $html]);
    }
    $body .= '<div class="offcanvas offcanvas-end" tabindex="-1" id="detail-canvas"><div class="offcanvas-header"><h2 class="offcanvas-title h5">Exam</h2><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button></div><div class="offcanvas-body"></div></div>';
    $body .= '<script>document.querySelectorAll(".filter-bar [data-filter]").forEach(function (button) { button.addEventListener("click", function () { const url = new URL(location.href); if (button.dataset.filter === "all") { url.searchParams.delete("status"); } else { url.searchParams.set("status", button.dataset.filter); } url.searchParams.delete("page"); location.href = url.toString(); }); });</script>';
    render_admin($admin, [
        'title' => 'Exams',
        'subtitle' => 'Exams you can manage.',
        'nav' => 'exams',
        'actions' => '<a class="btn btn-poly" href="' . e(app_url('admin/exam-new.php')) . '">New exam</a>',
    ], $body);
} catch (Throwable $e) {
    error_log($e->__toString());
    if (wants_json()) {
        json_out(['ok' => false, 'message' => friendly_exception($e)], 500);
    }
    render_error_page('The service is not available right now. Try again later.');
}
