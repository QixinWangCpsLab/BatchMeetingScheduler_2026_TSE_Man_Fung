<?php
declare(strict_types=1);

require_once __DIR__ . '/../components/bootstrap.php';

try {
    $admin = require_admin_page();
    $exam = require_exam($admin, (string) ($_GET['exam'] ?? $_POST['exam_id'] ?? ''));
    $examId = (string) $exam['exam_id'];
    $back = exam_href('exam-assignments.php', $examId);
    $detailUrl = request_path() . '?exam=' . rawurlencode($examId);
    $completed = $exam['status'] === 'completed';
    $fcfsOpen = $exam['acceptance_mode'] === 'fcfs'
        && (int) db_value('SELECT COUNT(*) FROM exam_round WHERE exam_id = ? AND status = "open"', [$examId]) > 0;

    if (($_GET['ajax'] ?? '') === 'detail') {
        $group = db_one('SELECT * FROM project_group WHERE group_id = ? AND exam_id = ?', [(string) ($_GET['id'] ?? ''), $examId]);
        if ($group === null) {
            json_out(['ok' => false, 'message' => 'That group was not found.'], 404);
        }
        json_out(['ok' => true, 'title' => (string) $group['display_name'], 'html' => assignment_detail_html($exam, $group, $completed)]);
    }

    if (($_GET['ajax'] ?? '') === 'slots') {
        $group = db_one('SELECT * FROM project_group WHERE group_id = ? AND exam_id = ?', [(string) ($_GET['group'] ?? ''), $examId]);
        if ($group === null) {
            json_out(['ok' => false, 'message' => 'That group was not found.'], 404);
        }
        json_out(slot_choices_payload($exam, $group));
    }

    if (($_GET['ajax'] ?? '') === 'assigned') {
        json_out(assigned_payload($exam, $completed, $detailUrl));
    }
    if (($_GET['ajax'] ?? '') === 'waiting') {
        json_out(waiting_payload($exam, $completed, $detailUrl));
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_post_csrf();
        $action = (string) ($_POST['action'] ?? '');
        $groupId = post_str('group_id');
        $owned = db_one('SELECT group_id FROM project_group WHERE group_id = ? AND exam_id = ?', [$groupId, $examId]);
        if ($owned === null) {
            respond_back(['ok' => false, 'message' => 'That group was not found.'], $back);
        }
        if ($action === 'move_group') {
            $result = move_group_action($admin, $groupId, post_str('timeslot_id'));
        } elseif ($action === 'clear_group') {
            $result = clear_group_action($admin, $groupId);
        } else {
            $result = ['ok' => false, 'message' => 'That action is not available.'];
        }
        respond_back($result, $back);
    }

    $body = assignment_intro_html($exam);
    $body .= '<h2 class="h5">Assigned</h2>';
    $body .= filter_bar('Results', [
        'all' => 'All',
        'first' => '1st choice',
        'later' => 'Later choice',
        'absent' => 'Not on their list',
        'manual' => 'Moved by admin',
    ], one_of((string) ($_GET['filter'] ?? 'all'), ['all', 'first', 'later', 'absent', 'manual'], 'all'));
    $body .= '<div id="assigned-empty" hidden></div>' . list_error_html() . '<table id="assigned-table"></table>';
    $body .= '<h2 class="h5 mt-4">Waiting</h2>';
    $body .= '<div id="waiting-empty" hidden></div><table id="waiting-table"></table>';
    $body .= modal_start('move-modal', 'Choose a time slot', 'move_group', ['exam_id' => $examId]);
    $body .= '<input type="hidden" name="group_id" id="move-group" value="">';
    $body .= '<div id="move-slots" class="slot-choices"></div>';
    $body .= modal_end('Choose a time slot');
    $body .= modal_start('clear-modal', 'Clear this time slot', 'clear_group', ['exam_id' => $examId]);
    $body .= '<input type="hidden" name="group_id" id="clear-group" value="">';
    $body .= '<p id="clear-text"></p>';
    $body .= modal_end('Clear time slot', true);
    $body .= detail_canvas('Result');
    $clearId = (string) ($_GET['clear'] ?? '');
    $clearName = '';
    if ($clearId !== '') {
        $clearGroup = db_one(
            'SELECT display_name FROM project_group WHERE group_id = ? AND exam_id = ?',
            [$clearId, $examId]
        );
        if ($clearGroup === null) {
            $clearId = '';
        } else {
            $clearName = (string) $clearGroup['display_name'];
        }
    }
    $boot = json_encode([
        'url' => $detailUrl,
        'detailUrl' => $detailUrl,
        'fcfsOpen' => $fcfsOpen,
        'move' => (string) ($_GET['move'] ?? ''),
        'assign' => (string) ($_GET['assign'] ?? ''),
        'clear' => $clearId,
        'clearName' => $clearName,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $body .= '<script>
    document.addEventListener("DOMContentLoaded", function () {
        const boot = ' . $boot . ';
        const columns = [
            { field: "group", title: "Group", sortable: true, escape: true },
            { field: "members", title: "Members", escape: true },
            { field: "seat", title: "Time slot", sortable: true, escape: true },
            { field: "choice", title: "Their choice", escape: true },
            { field: "how", title: "Source", escape: false },
            { field: "when", title: "Time", sortable: true, escape: true },
            { field: "by", title: "User", escape: true },
            { field: "actions", title: "Action", escape: false }
        ];
        mountServerTable("#assigned-table", {
            url: boot.url,
            ajax: "assigned",
            detailUrl: boot.detailUrl,
            emptyHost: "#assigned-empty",
            sortName: "when",
            sortOrder: "desc",
            columns: columns
        });
        mountServerTable("#waiting-table", {
            url: boot.url,
            ajax: "waiting",
            detailUrl: boot.detailUrl,
            emptyHost: "#waiting-empty",
            useUrl: false,
            filters: false,
            remember: false,
            openFromQuery: false,
            sortName: "group",
            sortOrder: "asc",
            columns: [
                { field: "group", title: "Group", sortable: true, escape: true },
                { field: "list", title: "Latest list", escape: false },
                { field: "why", title: "Reason", escape: true },
                { field: "actions", title: "Action", escape: false }
            ]
        });
        const moveButton = document.querySelector("#move-modal button[type=submit]");
        function fillSlots(data) {
            const box = document.getElementById("move-slots");
            box.replaceChildren();
            const group = data.group || "This group";
            const verb = data.verb || "Move";
            document.getElementById("move-modal-title").textContent = verb + " " + group;
            if (!data.slots || data.slots.length === 0) {
                const note = document.createElement("p");
                note.textContent = "No time slot is free.";
                box.appendChild(note);
                moveButton.disabled = true;
                moveButton.textContent = "Choose a time slot";
                return;
            }
            data.slots.forEach(function (slot) {
                const label = document.createElement("label");
                label.className = "choice-line";
                const input = document.createElement("input");
                input.type = "radio";
                input.name = "timeslot_id";
                input.value = slot.id;
                input.addEventListener("change", function () {
                    moveButton.disabled = false;
                    moveButton.textContent = verb + " " + group + " to " + slot.confirm + ".";
                });
                label.appendChild(input);
                let text = " " + slot.label;
                if (slot.choice) { text += " " + slot.choice; }
                if (slot.current) { text += " Current time slot"; }
                label.appendChild(document.createTextNode(text));
                box.appendChild(label);
            });
            moveButton.disabled = true;
            moveButton.textContent = "Choose a time slot";
        }
        function openMove(groupId) {
            document.getElementById("move-group").value = groupId;
            const url = new URL(boot.url, location.href);
            url.searchParams.set("ajax", "slots");
            url.searchParams.set("group", groupId);
            fetch(url, { headers: { Accept: "application/json", "X-Requested-With": "fetch" } })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        toast(data.message || "Could not load this list. Try again", "danger");
                        return;
                    }
                    fillSlots(data);
                    bootstrap.Modal.getOrCreateInstance(document.getElementById("move-modal")).show();
                })
                .catch(function () {
                    toast("Not saved. Check the connection and try again.", "danger");
                });
        }
        function openClear(groupId, name) {
            document.getElementById("clear-group").value = groupId;
            const who = name || "This group";
            document.getElementById("clear-modal-title").textContent = "Clear the time slot for " + who;
            document.getElementById("clear-text").textContent = boot.fcfsOpen
                ? "Clearing this seat puts the group back in the waiting list. This time slot can be taken immediately by another group."
                : "Clear the time slot for " + who + ". The group returns to the waiting list.";
            bootstrap.Modal.getOrCreateInstance(document.getElementById("clear-modal")).show();
        }
        document.addEventListener("click", function (event) {
            const move = event.target.closest(".move-open");
            if (move) { openMove(move.dataset.group || ""); return; }
            const clear = event.target.closest(".clear-open");
            if (clear) { openClear(clear.dataset.group || "", clear.dataset.name || ""); }
        });
        if (boot.move) { openMove(boot.move); }
        if (boot.assign) { openMove(boot.assign); }
        if (boot.clear) { openClear(boot.clear, boot.clearName || ""); }
    });
    </script>';

    render_admin($admin, [
        'title' => 'Results',
        'subtitle' => 'Check who sits where, and move a group when a chair should change.',
        'nav' => 'exams',
        'exam' => $exam,
        'exam_nav' => 'results',
        'crumbs' => exam_crumbs($exam, 'Results'),
    ], $body);
} catch (Throwable $e) {
    page_fail($e);
}

function assignment_intro_html(array $exam): string
{
    if ($exam['acceptance_mode'] !== 'sealed') {
        return '<p>These time slots were taken when the groups submitted. You can still move a group.</p>';
    }
    $accepted = db_one(
        'SELECT m.completed_at
         FROM match_run m
         JOIN exam_round r ON r.round_id = m.round_id
         WHERE r.exam_id = ? AND m.algorithm = "sealed" AND m.completed_at IS NOT NULL
         ORDER BY m.completed_at DESC
         LIMIT 1',
        [$exam['exam_id']]
    );
    if ($accepted !== null) {
        return '<p>Accepted on ' . e(hk_short((string) $accepted['completed_at'])) . '.</p>';
    }
    $round = db_one(
        'SELECT round_id, deadline, status FROM exam_round
         WHERE exam_id = ? AND deadline <= ? AND status <> "matched"
         ORDER BY round_index DESC LIMIT 1',
        [$exam['exam_id'], hk_now()->format('Y-m-d H:i:s')]
    );
    $html = '<p>Lists are saved. Time slots appear here after you accept them.</p>';
    if ($round !== null) {
        $href = app_url('admin/exam-rounds.php?exam=' . rawurlencode((string) $exam['exam_id']) . '&accept=' . rawurlencode((string) $round['round_id']));
        $html .= '<p><a class="btn btn-poly" href="' . e($href) . '">Accept now</a></p>';
    }
    return $html;
}

function choice_map(string $examId): array
{
    $round = latest_round($examId);
    $byGroup = [];
    if ($round !== null) {
        foreach (db_all(
            'SELECT ps.group_id, pc.priority, pc.timeslot_id, t.start_at
             FROM preference_submission ps
             JOIN preference_choice pc ON pc.submission_id = ps.submission_id
             JOIN exam_timeslot t ON t.timeslot_id = pc.timeslot_id
             WHERE ps.round_id = ? AND ps.status = "current"
             ORDER BY pc.priority ASC',
            [$round['round_id']]
        ) as $row) {
            $byGroup[(string) $row['group_id']][] = $row;
        }
    }
    $pending = false;
    if ($round !== null) {
        $done = (int) db_value(
            'SELECT COUNT(*) FROM match_run WHERE round_id = ? AND algorithm = "sealed" AND completed_at IS NOT NULL',
            [$round['round_id']]
        );
        $pending = $done === 0;
    }
    return ['round' => $round, 'by_group' => $byGroup, 'pending' => $pending];
}

function member_map(string $examId): array
{
    $map = [];
    foreach (db_all(
        'SELECT gm.group_id, sa.display_name
         FROM group_member gm
         JOIN student_account sa ON sa.student_id = gm.student_id AND sa.exam_id = gm.exam_id
         WHERE gm.exam_id = ?
         ORDER BY sa.display_name ASC',
        [$examId]
    ) as $row) {
        $map[(string) $row['group_id']][] = (string) $row['display_name'];
    }
    return $map;
}

function assigned_payload(array $exam, bool $completed, string $detailUrl): array
{
    $examId = (string) $exam['exam_id'];
    $query = table_query('DESC');
    $filter = one_of($query['filter'], ['all', 'first', 'later', 'absent', 'manual'], 'all');
    $choices = choice_map($examId);
    $members = member_map($examId);
    $numbers = slot_number_map($examId);
    $rows = db_all(
        'SELECT a.assignment_id, a.group_id, a.timeslot_id, a.source, a.accepted_at, g.display_name,
                t.start_at, u.display_name AS admin_name, pc.priority AS selected_priority
         FROM assignment a
         JOIN project_group g ON g.group_id = a.group_id
         JOIN exam_timeslot t ON t.timeslot_id = a.timeslot_id
         LEFT JOIN admin_user u ON u.username = a.assigned_by_admin_username
         LEFT JOIN preference_choice pc ON pc.choice_id = a.selected_choice_id
         WHERE g.exam_id = ?',
        [$examId]
    );
    $built = [];
    foreach ($rows as $row) {
        $groupId = (string) $row['group_id'];
        $names = $members[$groupId] ?? [];
        $phrase = their_choice_label(
            $row['selected_priority'] === null ? null : (int) $row['selected_priority'],
            (string) $row['timeslot_id'],
            $choices['by_group'][$groupId] ?? []
        );
        $kind = choice_kind($phrase);
        if ($filter === 'manual' && $row['source'] !== 'manual') {
            continue;
        }
        if (in_array($filter, ['first', 'later', 'absent'], true) && $kind !== $filter) {
            continue;
        }
        $blob = mb_strtolower($row['display_name'] . ' ' . implode(' ', $names));
        if ($query['search'] !== '' && !str_contains($blob, mb_strtolower($query['search']))) {
            continue;
        }
        $actions = '';
        if (!$completed) {
            $actions = '<button type="button" class="btn btn-link move-open" data-group="' . e($groupId) . '" data-name="' . e($row['display_name']) . '">Move</button>'
                . '<button type="button" class="btn btn-link text-danger clear-open" data-group="' . e($groupId) . '" data-name="' . e($row['display_name']) . '">Clear</button>';
        }
        $built[] = [
            'id' => $groupId,
            'group' => (string) $row['display_name'],
            'members' => member_brief($names),
            'seat' => slot_phrase((string) $row['start_at'], (int) ($numbers[$row['timeslot_id']] ?? 0), '—'),
            'choice' => $phrase,
            'how' => source_badge((string) $row['source']),
            'when' => hk_full((string) $row['accepted_at']),
            'by' => $row['admin_name'] ?: 'System',
            'actions' => $actions,
            'sort_group' => (string) $row['display_name'],
            'sort_seat' => (string) $row['start_at'],
            'sort_when' => (string) $row['accepted_at'],
        ];
    }
    $sortKey = ['group' => 'sort_group', 'seat' => 'sort_seat', 'when' => 'sort_when'][$query['sort']] ?? 'sort_when';
    usort($built, static function (array $left, array $right) use ($sortKey, $query): int {
        $cmp = $left[$sortKey] <=> $right[$sortKey];
        return $query['order'] === 'ASC' ? $cmp : -$cmp;
    });
    $page = page_slice($built, $query['offset'], $query['limit']);
    foreach ($page['rows'] as &$row) {
        unset($row['sort_group'], $row['sort_seat'], $row['sort_when']);
    }
    unset($row);
    $untouched = $query['search'] === '' && $filter === 'all';
    return [
        'total' => $page['total'],
        'rows' => $page['rows'],
        'emptyHtml' => $untouched && $built === [] ? empty_state('No group has a time slot yet.') : '',
    ];
}

function waiting_payload(array $exam, bool $completed, string $detailUrl): array
{
    $examId = (string) $exam['exam_id'];
    $query = table_query('ASC');
    $choices = choice_map($examId);
    $members = member_map($examId);
    $numbers = slot_number_map($examId);
    $pending = $exam['acceptance_mode'] === 'sealed' && $choices['pending'];
    $groups = db_all(
        'SELECT g.group_id, g.display_name
         FROM project_group g
         WHERE g.exam_id = ?
           AND NOT EXISTS (SELECT 1 FROM assignment a WHERE a.group_id = g.group_id)
         ORDER BY g.display_name ASC',
        [$examId]
    );
    $built = [];
    foreach ($groups as $group) {
        $groupId = (string) $group['group_id'];
        $list = $choices['by_group'][$groupId] ?? [];
        $names = $members[$groupId] ?? [];
        $blob = mb_strtolower($group['display_name'] . ' ' . implode(' ', $names));
        if ($query['search'] !== '' && !str_contains($blob, mb_strtolower($query['search']))) {
            continue;
        }
        $lines = '';
        foreach ($list as $choice) {
            $lines .= '<span class="choice-line">' . e(ordinal_choice((int) $choice['priority'])) . ': '
                . e(slot_phrase((string) $choice['start_at'], (int) ($numbers[$choice['timeslot_id']] ?? 0), '—')) . '</span>';
        }
        $actions = '';
        if (!$completed) {
            $actions = '<button type="button" class="btn btn-link move-open" data-group="' . e($groupId) . '" data-name="' . e($group['display_name']) . '">Assign</button>';
        }
        $built[] = [
            'id' => $groupId,
            'group' => (string) $group['display_name'],
            'list' => $lines === '' ? 'No choice yet' : $lines,
            'why' => waiting_reason($list !== [], $pending),
            'actions' => $actions,
            'sort_group' => (string) $group['display_name'],
        ];
    }
    usort($built, static function (array $left, array $right) use ($query): int {
        $cmp = strcasecmp($left['sort_group'], $right['sort_group']);
        return $query['order'] === 'ASC' ? $cmp : -$cmp;
    });
    $page = page_slice($built, $query['offset'], $query['limit']);
    foreach ($page['rows'] as &$row) {
        unset($row['sort_group']);
    }
    unset($row);
    $groupCount = (int) db_value('SELECT COUNT(*) FROM project_group WHERE exam_id = ?', [$examId]);
    $emptyHtml = '';
    if ($query['search'] === '' && $built === []) {
        $emptyHtml = $groupCount === 0
            ? empty_state('No groups yet. Import students and they will become groups.', 'Import students', app_url(exam_href('exam-students.php', $examId)))
            : empty_state('Every group has a time slot.');
    }
    return ['total' => $page['total'], 'rows' => $page['rows'], 'emptyHtml' => $emptyHtml];
}

function choice_kind(string $phrase): string
{
    if ($phrase === '1st choice') {
        return 'first';
    }
    if ($phrase === 'Not on their list') {
        return 'absent';
    }
    if ($phrase === '—') {
        return 'none';
    }
    if (str_ends_with($phrase, ' choice')) {
        return 'later';
    }
    return 'other';
}

function assignment_detail_html(array $exam, array $group, bool $completed): string
{
    $examId = (string) $exam['exam_id'];
    $groupId = (string) $group['group_id'];
    $names = member_map($examId)[$groupId] ?? [];
    $choices = choice_map($examId);
    $numbers = slot_number_map($examId);
    $seat = db_one(
        'SELECT a.assignment_id, a.timeslot_id, a.source, a.accepted_at, a.selected_choice_id, t.start_at, u.display_name AS admin_name, pc.priority AS selected_priority
         FROM assignment a
         JOIN exam_timeslot t ON t.timeslot_id = a.timeslot_id
         LEFT JOIN admin_user u ON u.username = a.assigned_by_admin_username
         LEFT JOIN preference_choice pc ON pc.choice_id = a.selected_choice_id
         WHERE a.group_id = ?',
        [$groupId]
    );
    $html = '<p>' . e(member_brief($names)) . '</p>';
    if ($seat === null) {
        $html .= '<p>Waiting</p>';
        $html .= '<p>' . e(waiting_reason(($choices['by_group'][$groupId] ?? []) !== [], $exam['acceptance_mode'] === 'sealed' && $choices['pending'])) . '</p>';
        if (!$completed) {
            $html .= '<button type="button" class="btn btn-outline-secondary move-open" data-group="' . e($groupId) . '" data-name="' . e($group['display_name']) . '">Assign</button>';
        }
    } else {
        $phrase = their_choice_label(
            $seat['selected_priority'] === null ? null : (int) $seat['selected_priority'],
            (string) $seat['timeslot_id'],
            $choices['by_group'][$groupId] ?? []
        );
        $html .= '<p>' . e(slot_phrase((string) $seat['start_at'], (int) ($numbers[$seat['timeslot_id']] ?? 0), '—')) . '</p>';
        $html .= '<p>' . e($phrase) . '</p>';
        $html .= '<p>' . source_badge((string) $seat['source']) . ' · ' . e(hk_full((string) $seat['accepted_at'])) . '</p>';
        $html .= '<p>By ' . e($seat['admin_name'] ?: 'System') . '</p>';
        if (!$completed) {
            $html .= '<button type="button" class="btn btn-outline-secondary move-open" data-group="' . e($groupId) . '" data-name="' . e($group['display_name']) . '">Move</button> ';
            $html .= '<button type="button" class="btn btn-outline-secondary text-danger clear-open" data-group="' . e($groupId) . '" data-name="' . e($group['display_name']) . '">Clear</button>';
        }
        $html .= reference_line((string) $seat['assignment_id']);
    }
    return $html;
}

function slot_choices_payload(array $exam, array $group): array
{
    $examId = (string) $exam['exam_id'];
    $groupId = (string) $group['group_id'];
    $current = db_one('SELECT timeslot_id FROM assignment WHERE group_id = ?', [$groupId]);
    $currentId = (string) ($current['timeslot_id'] ?? '');
    $slots = db_all(
        'SELECT timeslot_id, start_at, end_at, status
         FROM exam_timeslot
         WHERE exam_id = ? AND (status = "available" OR timeslot_id = ?)
         ORDER BY start_at ASC, timeslot_id ASC',
        [$examId, $currentId]
    );
    $numbers = slot_number_map($examId);
    $choiceBySlot = [];
    foreach ((choice_map($examId)['by_group'][$groupId] ?? []) as $choice) {
        $choiceBySlot[(string) $choice['timeslot_id']] = (int) $choice['priority'];
    }
    $out = [];
    foreach ($slots as $slot) {
        $number = (int) ($numbers[$slot['timeslot_id']] ?? 0);
        $priority = $choiceBySlot[(string) $slot['timeslot_id']] ?? 0;
        $out[] = [
            'id' => (string) $slot['timeslot_id'],
            'label' => hk_range((string) $slot['start_at'], (string) $slot['end_at']) . ', time slot ' . $number,
            'confirm' => slot_phrase((string) $slot['start_at'], $number, 'this time slot'),
            'choice' => $priority > 0 ? 'Their ' . ordinal_choice($priority) : '',
            'current' => (string) $slot['timeslot_id'] === $currentId,
        ];
    }
    return [
        'ok' => true,
        'group' => (string) $group['display_name'],
        'verb' => $currentId === '' ? 'Assign' : 'Move',
        'slots' => $out,
    ];
}
