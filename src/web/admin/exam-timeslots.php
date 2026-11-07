<?php
declare(strict_types=1);

require_once __DIR__ . '/../components/bootstrap.php';

try {
    $admin = require_admin_page();
    $exam = require_exam($admin, (string) ($_GET['exam'] ?? $_POST['exam_id'] ?? ''));
    $examId = (string) $exam['exam_id'];
    $back = exam_href('exam-timeslots.php', $examId);
    $detailUrl = request_path() . '?exam=' . rawurlencode($examId);
    $assignmentDetailUrl = app_url('admin/exam-assignments.php?exam=' . rawurlencode($examId));
    $completed = $exam['status'] === 'completed';

    if (($_GET['ajax'] ?? '') === 'detail') {
        $slot = db_one(
            'SELECT t.*, a.assignment_id, a.group_id, a.source, a.accepted_at, a.assigned_by_admin_username,
                    g.display_name AS group_name, u.display_name AS admin_name
             FROM exam_timeslot t
             LEFT JOIN assignment a ON a.timeslot_id = t.timeslot_id
             LEFT JOIN project_group g ON g.group_id = a.group_id
             LEFT JOIN admin_user u ON u.username = a.assigned_by_admin_username
             WHERE t.timeslot_id = ? AND t.exam_id = ?',
            [(string) ($_GET['id'] ?? ''), $examId]
        );
        if ($slot === null) {
            json_out(['ok' => false, 'message' => 'That time slot was not found.'], 404);
        }
        $numbers = slot_number_map($examId);
        $number = (int) ($numbers[$slot['timeslot_id']] ?? 0);
        $used = timeslot_is_used((string) $slot['timeslot_id']);
        $html = '<p>' . e(hk_range((string) $slot['start_at'], (string) $slot['end_at'])) . ', time slot ' . e((string) $number) . '</p>';
        $html .= '<p>' . slot_badge((string) $slot['status']) . '</p>';
        if ($slot['group_id'] !== null) {
            $names = array_map(
                static fn (array $row): string => (string) $row['display_name'],
                db_all(
                    'SELECT sa.display_name
                     FROM group_member gm
                     JOIN student_account sa ON sa.student_id = gm.student_id AND sa.exam_id = gm.exam_id
                     WHERE gm.group_id = ?
                     ORDER BY sa.display_name',
                    [$slot['group_id']]
                )
            );
            $html .= '<h3 class="h6">Group</h3><p>' . e((string) $slot['group_name']) . '</p>';
            $html .= '<p>' . e($names === [] ? 'No members' : implode(', ', $names)) . '</p>';
            $html .= '<p>' . source_badge((string) $slot['source']) . ' · ' . e(hk_full((string) $slot['accepted_at'])) . '</p>';
            $html .= '<p>By ' . e($slot['admin_name'] ?: 'System') . '</p>';
            if (!$completed) {
                $href = app_url('admin/exam-assignments.php?exam=' . rawurlencode($examId) . '&move=' . rawurlencode((string) $slot['group_id']));
                $html .= '<p><a class="btn btn-outline-secondary" href="' . e($href) . '">Change time slot</a></p>';
            }
        } else {
            $html .= '<p>Empty</p>';
            if ($used) {
                $html .= '<p>A group has already used this time slot.</p>';
            } elseif (!$completed) {
                $href = app_url('admin/exam-assignments.php?exam=' . rawurlencode($examId));
                $html .= '<p><a class="btn btn-outline-secondary" href="' . e($href) . '">Assign a waiting group</a></p>';
            }
        }
        $html .= reference_line((string) $slot['timeslot_id']);
        json_out(['ok' => true, 'title' => slot_phrase((string) $slot['start_at'], $number, 'Time slot'), 'html' => $html]);
    }

    if (($_GET['ajax'] ?? '') === 'rows') {
        $query = table_query('ASC');
        $filter = one_of($query['filter'], ['all', 'empty', 'occupied'], 'all');
        $params = [$examId];
        $where = 'WHERE t.exam_id = ?';
        if ($filter === 'empty') {
            $where .= ' AND t.status = "available"';
        } elseif ($filter === 'occupied') {
            $where .= ' AND t.status = "occupied"';
        }
        if ($query['search'] !== '') {
            $like = search_like($query['search']);
            $where .= ' AND (IFNULL(g.display_name, "") ' . sql_like()
                . ' OR DATE_FORMAT(t.start_at, "%e %b %Y, %H:%i") ' . sql_like()
                . ' OR DATE_FORMAT(t.start_at, "%H:%i") ' . sql_like()
                . ' OR DATE_FORMAT(t.end_at, "%H:%i") ' . sql_like()
                . ' OR t.start_at ' . sql_like() . ')';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $sort = sort_sql($query['sort'], [
            'date' => 't.start_at',
            'time' => 't.start_at',
            'slot' => 't.start_at',
            'status' => 't.status',
            'group' => 'g.display_name',
            'accepted' => 'a.accepted_at',
            'how' => 'a.source',
        ], 't.start_at');
        $rows = db_all(
            'SELECT t.timeslot_id, t.start_at, t.end_at, t.status,
                    a.accepted_at, a.source, a.group_id, g.display_name AS group_name,
                    (SELECT COUNT(*) FROM preference_choice pc WHERE pc.timeslot_id = t.timeslot_id) AS choice_count
             FROM exam_timeslot t
             LEFT JOIN assignment a ON a.timeslot_id = t.timeslot_id
             LEFT JOIN project_group g ON g.group_id = a.group_id
             ' . $where . '
             ORDER BY ' . $sort . ' ' . $query['order'] . ', t.start_at ASC, t.timeslot_id ASC',
            $params
        );
        $numbers = slot_number_map($examId);
        $scheduleSource = $rows;
        usort($scheduleSource, static function (array $left, array $right): int {
            return [$left['start_at'], $left['timeslot_id']] <=> [$right['start_at'], $right['timeslot_id']];
        });
        $any = (int) db_value('SELECT COUNT(*) FROM exam_timeslot WHERE exam_id = ?', [$examId]);
        $page = page_slice($rows, $query['offset'], $query['limit']);
        $mapped = [];
        foreach ($page['rows'] as $slot) {
            $number = (int) ($numbers[$slot['timeslot_id']] ?? 0);
            $used = (int) $slot['choice_count'] > 0 || $slot['group_id'] !== null;
            $label = hk_range((string) $slot['start_at'], (string) $slot['end_at']) . ', time slot ' . $number;
            $actions = '';
            if (!$completed && !$used && $slot['status'] === 'available') {
                $actions = '<button type="button" class="btn btn-link text-danger remove-slot" data-id="'
                    . e($slot['timeslot_id']) . '" data-label="' . e($label) . '">Remove</button>';
            }
            $mapped[] = [
                'id' => (string) $slot['timeslot_id'],
                'date' => hk_day((string) $slot['start_at']),
                'time' => hk_clock((string) $slot['start_at'], (string) $slot['end_at']),
                'slot' => (string) $number,
                'status' => slot_badge((string) $slot['status']),
                'group' => $slot['group_name'] !== null && $slot['group_name'] !== '' ? (string) $slot['group_name'] : 'Empty',
                'accepted' => $slot['accepted_at'] ? hk_full((string) $slot['accepted_at']) : '—',
                'how' => $slot['source'] ? source_badge((string) $slot['source']) : '—',
                'actions' => $actions,
            ];
        }
        $emptyHtml = '';
        if ($any === 0) {
            $emptyHtml = '<div class="empty-state"><p>This exam has no timeslots yet.</p>';
            if (!$completed) {
                $emptyHtml .= '<button type="button" class="btn btn-poly" data-bs-toggle="modal" data-bs-target="#add-slots">Add timeslots</button>';
            }
            $emptyHtml .= '</div>';
        }
        $scheduleHtml = '';
        if ($any > 0) {
            $scheduleHtml = $scheduleSource === []
                ? '<p>No time slots match this filter.</p>'
                : timeslot_schedule_html($scheduleSource, $numbers, $detailUrl, $assignmentDetailUrl);
        }
        json_out([
            'total' => $page['total'],
            'rows' => $mapped,
            'emptyHtml' => $emptyHtml,
            'scheduleHtml' => $scheduleHtml,
        ]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_post_csrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add_slots') {
            $result = add_timeslots_action($admin, $examId, $_POST);
        } elseif ($action === 'remove_slot') {
            if ($completed) {
                $result = ['ok' => false, 'message' => 'This exam is completed.'];
            } else {
                $result = remove_timeslot_action($admin, post_str('timeslot_id'), false);
            }
        } else {
            $result = ['ok' => false, 'message' => 'That action is not available.'];
        }
        respond_back($result, $back);
    }

    $filter = one_of((string) ($_GET['filter'] ?? 'all'), ['all', 'empty', 'occupied'], 'all');
    $body = '<p>Overlapping chairs stay as separate time slots. The schedule and the table use the same filter.</p>';
    $body .= filter_bar('Time slots', [
        'all' => 'All time slots',
        'empty' => 'Empty only',
        'occupied' => 'Occupied only',
    ], $filter);
    $body .= '<div id="schedule"></div><div id="list-empty" hidden></div>' . list_error_html();
    $body .= '<table id="slot-table"></table>';
    if (!$completed) {
        $body .= '<div data-slot-minutes="' . e((string) $exam['slot_length_min']) . '">';
        $body .= modal_start('add-slots', 'Add time slots', 'add_slots', ['exam_id' => $examId]);
        $body .= '<p class="form-text">Each group sits for ' . e((string) $exam['slot_length_min']) . ' minutes.</p>';
        $body .= '<div class="generator-grid">';
        $body .= text_field('start_date', 'Start date', '', ['id' => 'start_date', 'type' => 'date']);
        $body .= text_field('end_date', 'End date', '', ['id' => 'end_date', 'type' => 'date']);
        $body .= text_field('daily_start', 'Daily start', '09:00', ['id' => 'daily_start', 'type' => 'time']);
        $body .= text_field('daily_end', 'Daily end', '12:00', ['id' => 'daily_end', 'type' => 'time']);
        $body .= text_field('chairs', 'Parallel chairs', '1', ['id' => 'chairs', 'type' => 'number', 'min' => '1']);
        $body .= '</div><p id="plan-note" class="plan-note"></p>';
        $body .= modal_end('Add time slots');
        $body .= '</div>';
        $body .= modal_start('remove-slot', 'Remove this time slot', 'remove_slot', ['exam_id' => $examId]);
        $body .= '<input type="hidden" name="timeslot_id" id="remove-id" value="">';
        $body .= '<p id="remove-text">Remove this time slot.</p>';
        $body .= modal_end('Remove this time slot', true);
    }
    $body .= detail_canvas('Time slot');
    $boot = json_encode([
        'url' => $detailUrl,
        'detailUrl' => $detailUrl,
        'assignmentsUrl' => $assignmentDetailUrl,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $body .= '<script>
    document.addEventListener("DOMContentLoaded", function () {
        const boot = ' . $boot . ';
        mountServerTable("#slot-table", {
            url: boot.url,
            detailUrl: boot.detailUrl,
            sortName: "date",
            sortOrder: "asc",
            columns: [
                { field: "date", title: "Date", sortable: true, escape: true },
                { field: "time", title: "Time", sortable: true, escape: true },
                { field: "slot", title: "Time slot", sortable: true, escape: true },
                { field: "status", title: "Status", sortable: true, escape: false },
                { field: "group", title: "Group", sortable: true, escape: true },
                { field: "accepted", title: "Accepted", sortable: true, escape: true },
                { field: "how", title: "Source", sortable: true, escape: false },
                { field: "actions", title: "Action", escape: false }
            ]
        });
        document.addEventListener("click", function (event) {
            const move = event.target.closest(".move-open");
            const clear = event.target.closest(".clear-open");
            if (move || clear) {
                const url = new URL(boot.assignmentsUrl, location.href);
                url.searchParams.set(move ? "move" : "clear", (move || clear).dataset.group || "");
                location.href = url.toString();
                return;
            }
            const button = event.target.closest(".remove-slot");
            if (!button) { return; }
            const form = document.querySelector("#remove-slot form");
            if (!form) { return; }
            form.querySelector("#remove-id").value = button.dataset.id || "";
            document.getElementById("remove-text").textContent = "Remove the time slot at " + (button.dataset.label || "this time") + ".";
            bootstrap.Modal.getOrCreateInstance(document.getElementById("remove-slot")).show();
        });
    });
    </script>';

    $actions = '';
    if (!$completed) {
        $actions = '<button class="btn btn-poly" type="button" data-bs-toggle="modal" data-bs-target="#add-slots">Add timeslots</button>';
    }
    render_admin($admin, [
        'title' => 'Timeslots',
        'subtitle' => 'Each chair is its own time slot, even when the clock time is the same.',
        'nav' => 'exams',
        'exam' => $exam,
        'exam_nav' => 'timeslots',
        'crumbs' => exam_crumbs($exam, 'Timeslots'),
        'actions' => $actions,
    ], $body);
} catch (Throwable $e) {
    page_fail($e);
}

function timeslot_schedule_html(array $slots, array $numbers, string $detailUrl, string $assignmentDetailUrl): string
{
    $days = [];
    foreach ($slots as $slot) {
        $day = hk_day((string) $slot['start_at']);
        $clock = hk_time((string) $slot['start_at']);
        $days[$day][$clock][] = $slot;
    }
    $html = '';
    foreach ($days as $day => $clocks) {
        $html .= '<section class="day-block"><h2>' . e((string) $day) . '</h2>';
        foreach ($clocks as $clock => $chairs) {
            $html .= '<div class="clock-row"><div>' . e((string) $clock) . '</div><div class="chairs">';
            foreach ($chairs as $slot) {
                $occupied = $slot['status'] === 'occupied';
                $groupId = (string) ($slot['group_id'] ?? '');
                $openAssignment = $occupied && $groupId !== '';
                $name = $slot['group_name'] !== null && $slot['group_name'] !== '' ? (string) $slot['group_name'] : 'Empty';
                $html .= '<button type="button" class="chair ' . ($occupied ? 'occupied' : 'available') . '" data-detail-id="'
                    . e($openAssignment ? $groupId : (string) $slot['timeslot_id']) . '" data-detail-url="'
                    . e($openAssignment ? $assignmentDetailUrl : $detailUrl) . '">';
                $html .= '<span class="chair-end">' . e(hk_time((string) $slot['end_at'])) . '</span>';
                $html .= '<span class="chair-status">' . e($occupied ? 'Occupied' : 'Available') . '</span>';
                $html .= '<span class="chair-group">' . e($name) . '</span>';
                $number = (int) ($numbers[$slot['timeslot_id']] ?? 0);
                if ($number > 0) {
                    $html .= '<span class="chair-index">Time slot ' . e((string) $number) . '</span>';
                }
                $html .= '</button>';
            }
            $html .= '</div></div>';
        }
        $html .= '</section>';
    }
    return $html;
}
