<?php
declare(strict_types=1);

require_once __DIR__ . '/../components/bootstrap.php';

try {
    $admin = require_admin_page();
    $exam = require_exam($admin, (string) ($_GET['exam'] ?? $_POST['exam_id'] ?? ''));
    $examId = (string) $exam['exam_id'];
    $detailUrl = request_path() . '?exam=' . rawurlencode($examId);

    if (($_GET['ajax'] ?? '') === 'rows') {
        $query = table_query('DESC');
        $types = audit_types($examId);
        $filter = one_of($query['filter'], array_merge(['all'], $types), 'all');
        $params = [$examId];
        $where = 'WHERE a.exam_id = ?';
        if ($filter !== 'all') {
            $where .= ' AND a.event_type = ?';
            $params[] = $filter;
        }
        if ($query['search'] !== '') {
            $like = search_like($query['search']);
            $where .= ' AND (a.detail ' . sql_like() . ' OR IFNULL(u.display_name, "") ' . sql_like() . ' OR IFNULL(a.actor_admin_username, "") ' . sql_like() . ')';
            array_push($params, $like, $like, $like);
        }
        $sort = sort_sql($query['sort'], [
            'when' => 'a.event_at',
            'what' => 'a.detail',
            'round' => 'r.round_index',
            'who' => 'u.display_name',
            'kind' => 'a.event_type',
        ], 'a.event_at');
        $from = 'FROM audit_event a
             LEFT JOIN exam_round r ON r.round_id = a.round_id
             LEFT JOIN admin_user u ON u.username = a.actor_admin_username
             LEFT JOIN match_run m ON m.run_id = a.run_id';
        $total = (int) db_value('SELECT COUNT(*) ' . $from . ' ' . $where, $params);
        $rows = db_all(
            'SELECT a.audit_id, a.detail, a.event_type, a.event_at, a.run_id, a.round_id,
                    r.round_index, u.display_name AS actor_name, m.round_id AS run_round_id
             ' . $from . '
             ' . $where . '
             ORDER BY ' . $sort . ' ' . $query['order'] . ', a.audit_id DESC' . sql_page($query['limit'], $query['offset']),
            $params
        );
        $any = (int) db_value('SELECT COUNT(*) FROM audit_event WHERE exam_id = ?', [$examId]);
        $mapped = [];
        foreach ($rows as $row) {
            $roundId = (string) ($row['round_id'] ?: $row['run_round_id']);
            $mapped[] = [
                'id' => (string) $row['audit_id'],
                'when' => hk_full((string) $row['event_at']),
                'what' => (string) $row['detail'],
                'round' => $row['round_index'] !== null ? 'Round ' . $row['round_index'] : 'Exam',
                'who' => $row['actor_name'] ?: 'System',
                'kind' => event_label((string) $row['event_type']),
                'detail_html' => audit_detail_html($row, $examId, $roundId),
            ];
        }
        json_out([
            'total' => $total,
            'rows' => $mapped,
            'emptyHtml' => $any === 0 ? empty_state('Nothing has been recorded for this exam yet.') : '',
        ]);
    }

    $types = audit_types($examId);
    $filter = one_of((string) ($_GET['filter'] ?? 'all'), array_merge(['all'], $types), 'all');
    $options = ['all' => 'All'];
    foreach ($types as $type) {
        $options[$type] = event_label($type);
    }
    $body = '<p>This list is a record. It cannot be edited or deleted. Select a row to read the full entry.</p>';
    if (count($options) > 1) {
        $body .= filter_bar('Kind', $options, $filter);
    }
    $body .= '<div id="list-empty" hidden></div>' . list_error_html() . '<table id="audit-table"></table>';
    $boot = json_encode(['url' => $detailUrl], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $body .= '<script>
    document.addEventListener("DOMContentLoaded", function () {
        const boot = ' . $boot . ';
        mountServerTable("#audit-table", {
            url: boot.url,
            expandDetail: true,
            sortName: "when",
            sortOrder: "desc",
            columns: [
                { field: "when", title: "Time", sortable: true, escape: true },
                { field: "what", title: "Event", sortable: true, escape: true },
                { field: "round", title: "Round", sortable: true, escape: true },
                { field: "who", title: "User", sortable: true, escape: true },
                { field: "kind", title: "Kind", sortable: true, escape: true }
            ],
            extra: {
                detailView: true,
                detailViewIcon: false,
                detailFormatter: function (index, row) { return row.detail_html || ""; }
            }
        });
    });
    </script>';

    render_admin($admin, [
        'title' => 'Activity',
        'subtitle' => 'What changed on this exam, newest first.',
        'nav' => 'exams',
        'exam' => $exam,
        'exam_nav' => 'activity',
        'crumbs' => exam_crumbs($exam, 'Activity'),
    ], $body);
} catch (Throwable $e) {
    page_fail($e);
}

function audit_types(string $examId): array
{
    return array_map(
        static fn (array $row): string => (string) $row['event_type'],
        db_all('SELECT DISTINCT event_type FROM audit_event WHERE exam_id = ? ORDER BY event_type ASC', [$examId])
    );
}

function audit_detail_html(array $row, string $examId, string $roundId): string
{
    $html = '<p>' . nl2br(e((string) $row['detail']), false) . '</p>';
    $html .= reference_line((string) $row['audit_id']);
    if (!empty($row['run_id']) && $roundId !== '') {
        $href = app_url('admin/exam-rounds.php?exam=' . rawurlencode($examId) . '&detail=' . rawurlencode($roundId));
        $html .= '<p><a href="' . e($href) . '">View that acceptance</a></p>';
    }
    return $html;
}
