<?php
declare(strict_types=1);

require_once __DIR__ . '/../components/bootstrap.php';

try {
    $admin = require_admin_page();
    $exam = require_exam($admin, (string) ($_GET['exam'] ?? $_POST['exam_id'] ?? ''));
    $examId = (string) $exam['exam_id'];
    $back = exam_href('exam-groups.php', $examId);
    $detailUrl = request_path() . '?exam=' . rawurlencode($examId);
    $completed = $exam['status'] === 'completed';

    if (($_GET['ajax'] ?? '') === 'detail') {
        $group = db_one('SELECT * FROM project_group WHERE group_id = ? AND exam_id = ?', [(string) ($_GET['id'] ?? ''), $examId]);
        if ($group === null) {
            json_out(['ok' => false, 'message' => 'That group was not found.'], 404);
        }
        json_out([
            'ok' => true,
            'title' => (string) $group['display_name'],
            'html' => group_detail_html($admin, $exam, $group),
        ]);
    }

    if (($_GET['ajax'] ?? '') === 'rows') {
        $query = table_query('ASC');
        $catalog = group_catalog($examId);
        if ($query['search'] !== '') {
            $needle = mb_strtolower($query['search']);
            $catalog = array_values(array_filter(
                $catalog,
                static fn (array $row): bool => str_contains($row['search'], $needle)
            ));
        }
        $sortMap = [
            'group' => 'name',
            'members' => 'member_count',
            'submitted' => 'submitted_sort',
            'seat' => 'seat_sort',
        ];
        $sortKey = $sortMap[$query['sort']] ?? 'name';
        usort($catalog, static function (array $left, array $right) use ($sortKey, $query): int {
            $cmp = $left[$sortKey] <=> $right[$sortKey];
            return $query['order'] === 'ASC' ? $cmp : -$cmp;
        });
        $page = page_slice($catalog, $query['offset'], $query['limit']);
        $rows = [];
        foreach ($page['rows'] as $row) {
            $rows[] = [
                'id' => $row['id'],
                'group' => $row['name'],
                'members' => $row['members'],
                'choice' => $row['choice'],
                'submitted' => $row['submitted'],
                'seat' => $row['seat'],
                'actions' => '<button type="button" class="btn btn-link" data-detail-id="' . e($row['id']) . '" data-detail-url="' . e($detailUrl) . '">View</button>',
            ];
        }
        $any = (int) db_value('SELECT COUNT(*) FROM project_group WHERE exam_id = ?', [$examId]);
        json_out([
            'total' => $page['total'],
            'rows' => $rows,
            'emptyHtml' => $any === 0
                ? empty_state('No groups yet. Import students and they will become groups.', 'Import students', app_url(exam_href('exam-students.php', $examId)))
                : '',
        ]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_post_csrf();
        $action = (string) ($_POST['action'] ?? '');
        $groupId = post_str('group_id');
        if ($action === 'rename') {
            $result = rename_group_action($admin, $groupId, post_str('display_name'));
        } elseif ($action === 'add_member') {
            $result = add_member_action($admin, $groupId, post_str('student_id'));
        } elseif ($action === 'remove_member') {
            $result = remove_member_action($admin, $groupId, post_str('student_id'));
        } else {
            $result = ['ok' => false, 'message' => 'That action is not available.'];
        }
        $detail = !empty($result['ok']) ? $groupId : null;
        respond_back($result, $back, $detail);
    }

    $body = '<div id="list-empty" hidden></div>' . list_error_html() . '<table id="group-table"></table>';
    $body .= detail_canvas('Group');
    $boot = json_encode(['url' => $detailUrl, 'detailUrl' => $detailUrl], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $body .= '<script>
    document.addEventListener("DOMContentLoaded", function () {
        const boot = ' . $boot . ';
        mountServerTable("#group-table", {
            url: boot.url,
            detailUrl: boot.detailUrl,
            sortName: "group",
            sortOrder: "asc",
            columns: [
                { field: "group", title: "Group", sortable: true, escape: true },
                { field: "members", title: "Members", sortable: true, escape: true },
                { field: "choice", title: "Latest choice", sortable: false, escape: true },
                { field: "submitted", title: "Submitted", sortable: true, escape: true },
                { field: "seat", title: "Time slot", sortable: true, escape: true },
                { field: "actions", title: "Action", escape: false }
            ]
        });
    });
    </script>';

    render_admin($admin, [
        'title' => 'Groups',
        'subtitle' => 'An individual student is a group of one.',
        'nav' => 'exams',
        'exam' => $exam,
        'exam_nav' => 'groups',
        'crumbs' => exam_crumbs($exam, 'Groups'),
        'actions' => '<a class="btn btn-outline-secondary" href="' . e(app_url(exam_href('exam-students.php', $examId))) . '">Import students</a>',
    ], $body);
} catch (Throwable $e) {
    page_fail($e);
}

function group_catalog(string $examId): array
{
    $groups = db_all('SELECT group_id, display_name FROM project_group WHERE exam_id = ?', [$examId]);
    $memberRows = db_all(
        'SELECT gm.group_id, sa.student_id, sa.display_name
         FROM group_member gm
         JOIN student_account sa ON sa.student_id = gm.student_id AND sa.exam_id = gm.exam_id
         WHERE gm.exam_id = ?
         ORDER BY sa.display_name ASC, sa.student_id ASC',
        [$examId]
    );
    $members = [];
    foreach ($memberRows as $row) {
        $members[(string) $row['group_id']][] = $row;
    }
    $round = latest_round($examId);
    $choices = [];
    if ($round !== null) {
        foreach (db_all(
            'SELECT ps.group_id, ps.submitted_at, t.start_at
             FROM preference_submission ps
             JOIN preference_choice pc ON pc.submission_id = ps.submission_id AND pc.priority = 1
             JOIN exam_timeslot t ON t.timeslot_id = pc.timeslot_id
             WHERE ps.round_id = ? AND ps.status = "current"',
            [$round['round_id']]
        ) as $row) {
            $choices[(string) $row['group_id']] = $row;
        }
    }
    $assigned = [];
    foreach (db_all(
        'SELECT a.group_id, a.timeslot_id, t.start_at
         FROM assignment a
         JOIN exam_timeslot t ON t.timeslot_id = a.timeslot_id
         JOIN project_group g ON g.group_id = a.group_id
         WHERE g.exam_id = ?',
        [$examId]
    ) as $row) {
        $assigned[(string) $row['group_id']] = $row;
    }
    $numbers = slot_number_map($examId);
    $out = [];
    foreach ($groups as $group) {
        $id = (string) $group['group_id'];
        $people = $members[$id] ?? [];
        $names = array_map(static fn (array $person): string => (string) $person['display_name'], $people);
        $ids = array_map(static fn (array $person): string => (string) $person['student_id'], $people);
        $seat = $assigned[$id] ?? null;
        $choice = $choices[$id] ?? null;
        $out[] = [
            'id' => $id,
            'name' => (string) $group['display_name'],
            'members' => group_members_label(count($people), $names[0] ?? ''),
            'member_count' => count($people),
            'choice' => $choice ? hk_short((string) $choice['start_at']) : 'No choice yet',
            'submitted' => $choice ? hk_full((string) $choice['submitted_at']) : '—',
            'submitted_sort' => (string) ($choice['submitted_at'] ?? ''),
            'seat' => $seat ? slot_phrase((string) $seat['start_at'], (int) ($numbers[$seat['timeslot_id']] ?? 0)) : 'Waiting',
            'seat_sort' => (string) ($seat['start_at'] ?? ''),
            'search' => mb_strtolower((string) $group['display_name'] . ' ' . implode(' ', $names) . ' ' . implode(' ', $ids)),
        ];
    }
    return $out;
}

function group_detail_html(array $admin, array $exam, array $group): string
{
    $examId = (string) $exam['exam_id'];
    $groupId = (string) $group['group_id'];
    $completed = $exam['status'] === 'completed';
    $members = db_all(
        'SELECT sa.display_name, sa.student_id, sa.email, gm.joined_at
         FROM group_member gm
         JOIN student_account sa ON sa.student_id = gm.student_id AND sa.exam_id = gm.exam_id
         WHERE gm.group_id = ?
         ORDER BY sa.display_name ASC, sa.student_id ASC',
        [$groupId]
    );
    $html = '';
    if ($completed) {
        $html .= '<p>This exam is completed. You can still review it.</p>';
        $html .= '<p>' . e((string) $group['display_name']) . '</p>';
    } else {
        $html .= '<form class="js-form" method="post" action="' . e(request_path()) . '">' . csrf_input();
        $html .= '<input type="hidden" name="action" value="rename"><input type="hidden" name="exam_id" value="' . e($examId) . '">';
        $html .= '<input type="hidden" name="group_id" value="' . e($groupId) . '">';
        $html .= text_field('display_name', 'Group name', (string) $group['display_name']);
        $html .= '<button class="btn btn-poly" type="submit">Save group name</button></form>';
    }
    $html .= '<h3 class="h6 mt-3">Members</h3>';
    if ($members === []) {
        $html .= '<p>No members</p>';
    } else {
        $html .= '<table id="member-table" data-client-table data-ignore-filter="true"><thead><tr><th data-sortable="true">Name</th><th data-sortable="true">Student ID</th><th data-sortable="true">Email</th><th data-sortable="true">Joined</th><th>Action</th></tr></thead><tbody>';
        foreach ($members as $member) {
            $html .= '<tr><td>' . e($member['display_name']) . '</td><td>' . e($member['student_id']) . '</td><td>' . e($member['email']) . '</td><td>' . e(hk_full((string) $member['joined_at'])) . '</td><td>';
            if (!$completed && count($members) > 1) {
                $html .= '<form class="js-form" method="post" action="' . e(request_path()) . '">' . csrf_input();
                $html .= '<input type="hidden" name="action" value="remove_member"><input type="hidden" name="exam_id" value="' . e($examId) . '">';
                $html .= '<input type="hidden" name="group_id" value="' . e($groupId) . '"><input type="hidden" name="student_id" value="' . e($member['student_id']) . '">';
                $html .= '<button class="btn btn-link text-danger" type="submit">Remove</button></form>';
            }
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table>';
    }
    if (count($members) <= 1) {
        $html .= '<p>This group needs at least one member.</p>';
    }
    if (!$completed) {
        $free = db_all(
            'SELECT sa.student_id, sa.display_name
             FROM student_account sa
             WHERE sa.exam_id = ?
               AND NOT EXISTS (
                    SELECT 1 FROM group_member gm
                    WHERE gm.exam_id = sa.exam_id AND gm.student_id = sa.student_id
               )
             ORDER BY sa.display_name ASC, sa.student_id ASC',
            [$examId]
        );
        if ($free === []) {
            $html .= '<p>Every student in this exam is already in a group.</p>';
        } else {
            $options = ['' => 'Choose a student'];
            foreach ($free as $student) {
                $options[(string) $student['student_id']] = $student['display_name'] . ' (' . $student['student_id'] . ')';
            }
            $html .= '<form class="js-form" method="post" action="' . e(request_path()) . '">' . csrf_input();
            $html .= '<input type="hidden" name="action" value="add_member"><input type="hidden" name="exam_id" value="' . e($examId) . '">';
            $html .= '<input type="hidden" name="group_id" value="' . e($groupId) . '">';
            $html .= select_field('student_id', 'Add a student from this exam', '', $options);
            $html .= '<button class="btn btn-outline-secondary" type="submit">Add to this group</button></form>';
        }
    }
    $html .= '<h3 class="h6 mt-3">Preferences</h3>';
    $subs = db_all(
        'SELECT ps.submission_id, ps.status, ps.submitted_at, sa.display_name, r.round_index
         FROM preference_submission ps
         JOIN exam_round r ON r.round_id = ps.round_id
         JOIN student_account sa ON sa.student_id = ps.submitted_by_student_id AND sa.exam_id = ?
         WHERE ps.group_id = ?
         ORDER BY ps.submitted_at DESC',
        [$examId, $groupId]
    );
    if ($subs === []) {
        $html .= '<p>No choice yet</p>';
    }
    $numbers = slot_number_map($examId);
    foreach ($subs as $sub) {
        $open = $sub['status'] === 'current' ? ' open' : '';
        $html .= '<details' . $open . '><summary>' . submission_badge((string) $sub['status']) . ' Round ' . e((string) $sub['round_index'])
            . ' · ' . e(hk_full((string) $sub['submitted_at'])) . ' · ' . e((string) $sub['display_name']) . '</summary><ol>';
        $choices = db_all(
            'SELECT pc.priority, t.start_at, t.timeslot_id
             FROM preference_choice pc
             JOIN exam_timeslot t ON t.timeslot_id = pc.timeslot_id
             WHERE pc.submission_id = ?
             ORDER BY pc.priority ASC',
            [$sub['submission_id']]
        );
        foreach ($choices as $choice) {
            $html .= '<li>' . e(ordinal_choice((int) $choice['priority'])) . ': '
                . e(slot_phrase((string) $choice['start_at'], (int) ($numbers[$choice['timeslot_id']] ?? 0), '—')) . '</li>';
        }
        $html .= '</ol></details>';
    }
    $seat = db_one(
        'SELECT a.source, a.accepted_at, t.start_at, t.timeslot_id, u.display_name AS admin_name
         FROM assignment a
         JOIN exam_timeslot t ON t.timeslot_id = a.timeslot_id
         LEFT JOIN admin_user u ON u.username = a.assigned_by_admin_username
         WHERE a.group_id = ?',
        [$groupId]
    );
    $html .= '<h3 class="h6 mt-3">Time slot</h3>';
    if ($seat === null) {
        $html .= '<p>Waiting</p>';
    } else {
        $html .= '<p>' . e(slot_phrase((string) $seat['start_at'], (int) ($numbers[$seat['timeslot_id']] ?? 0), '—')) . '</p>';
        $html .= '<p>' . source_badge((string) $seat['source']) . ' · ' . e(hk_full((string) $seat['accepted_at'])) . '</p>';
        $html .= '<p>By ' . e($seat['admin_name'] ?: 'System') . '</p>';
        if (!$completed) {
            $href = app_url('admin/exam-assignments.php?exam=' . rawurlencode($examId) . '&move=' . rawurlencode($groupId));
            $html .= '<p><a class="btn btn-outline-secondary" href="' . e($href) . '">Change time slot</a></p>';
        }
    }
    $html .= reference_line($groupId);
    return $html;
}
