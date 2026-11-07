<?php
declare(strict_types=1);

require_once __DIR__ . '/../components/bootstrap.php';

function wizard_url(string $examId, int $step): string
{
    $path = 'admin/exam-new.php?step=' . $step;
    if ($examId !== '') {
        $path .= '&exam=' . rawurlencode($examId);
    }
    return $path;
}

try {
    $admin = require_admin_page();
    $step = (int) ($_GET['step'] ?? $_POST['step'] ?? 1);
    if ($step < 1 || $step > 5) {
        $step = 1;
    }
    $examId = (string) ($_GET['exam'] ?? $_POST['exam_id'] ?? '');
    $exam = null;
    if ($examId !== '') {
        $exam = require_exam($admin, $examId);
        if ($exam['status'] !== 'draft') {
            flash('This exam is already set up.', 'success');
            redirect_to('admin/exam.php?exam=' . rawurlencode($examId));
        }
    } elseif ($step !== 1) {
        redirect_to('admin/exam-new.php?step=1');
    }

    if (($_GET['ajax'] ?? '') === 'check') {
        $code = trim((string) ($_GET['value'] ?? ''));
        if ($code === '') {
            json_out(['ok' => false, 'message' => 'Enter an entry code.']);
        }
        if (preg_match('/\s/u', $code) === 1) {
            json_out(['ok' => false, 'message' => 'The entry code cannot contain spaces.']);
        }
        $clash = db_one('SELECT exam_id FROM exam WHERE exam_code = ? AND exam_id <> ?', [$code, $examId]);
        json_out(['ok' => $clash === null, 'message' => $clash === null ? 'Students can use this code.' : 'That entry code is already used.']);
    }
    if (($_GET['ajax'] ?? '') === 'admins') {
        if ($exam === null) {
            json_out(['ok' => true, 'rows' => []]);
        }
        $raw = trim((string) ($_GET['q'] ?? ''));
        $rows = [];
        if ($raw !== '') {
            $term = search_like($raw);
            $rows = db_all(
                'SELECT username, display_name, role FROM admin_user
                 WHERE is_active = 1
                   AND username NOT IN (SELECT admin_username FROM exam_admin WHERE exam_id = ?)
                   AND (username ' . sql_like() . ' OR display_name ' . sql_like() . ')
                 ORDER BY display_name ASC LIMIT 8',
                [$examId, $term, $term]
            );
        }
        json_out([
            'ok' => true,
            'rows' => $rows,
            'empty' => 'No other active admin has that name.',
            'canCreate' => ($admin['role'] ?? '') === 'prof',
            'adminsUrl' => app_url('admin/admins.php'),
        ]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_post_csrf();
        $action = (string) ($_POST['action'] ?? '');
        $go = post_str('go');
        if ($action === 'step1') {
            $result = save_exam_basics($admin, $examId !== '' ? $examId : null, $_POST);
            if (!$result['ok']) {
                respond($result + ['redirect' => wizard_url($examId, 1)]);
            }
            $next = $go === 'stay' ? 1 : 2;
            respond($result + ['redirect' => wizard_url((string) $result['exam_id'], $next)]);
        }
        if ($exam === null) {
            respond(['ok' => false, 'message' => 'Save the exam before this step.', 'redirect' => wizard_url('', 1)]);
        }
        if ($action === 'step2') {
            $existing = db_one('SELECT * FROM exam_round WHERE exam_id = ? AND round_index = 1', [$examId]);
            if ($existing === null) {
                $result = add_round_action($admin, $examId, post_str('start_at'), post_str('deadline'));
            } else {
                $result = save_round_time($admin, (string) $existing['round_id'], post_str('start_at'), post_str('deadline'));
            }
            $result['redirect'] = wizard_url($examId, $go === 'stay' ? 2 : 3);
            respond($result);
        }
        if ($action === 'add_slots') {
            $result = add_timeslots_action($admin, $examId, $_POST);
            $result['redirect'] = wizard_url($examId, 3);
            respond($result);
        }
        if ($action === 'remove_slot') {
            $result = remove_timeslot_action($admin, post_str('timeslot_id'), true);
            $result['redirect'] = wizard_url($examId, 3);
            respond($result);
        }
        if ($action === 'remove_slots') {
            $rawIds = $_POST['timeslot_id'] ?? [];
            if (is_string($rawIds)) {
                $rawIds = [$rawIds];
            }
            $result = remove_timeslots_action($admin, $examId, is_array($rawIds) ? $rawIds : [], true);
            $result['redirect'] = wizard_url($examId, 3);
            respond($result);
        }
        if ($action === 'preview_import') {
            $file = $_FILES['import_file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                respond(['ok' => false, 'message' => 'Choose a spreadsheet first.', 'redirect' => wizard_url($examId, 4)]);
            }
            if (($file['size'] ?? 0) > 2_000_000) {
                respond(['ok' => false, 'message' => 'That file is too large.', 'redirect' => wizard_url($examId, 4)]);
            }
            try {
                $matrix = read_xlsx_matrix((string) $file['tmp_name']);
            } catch (Throwable $e) {
                error_log($e->__toString());
                respond(['ok' => false, 'message' => 'That file could not be read. Use one of the example spreadsheets.', 'redirect' => wizard_url($examId, 4)]);
            }
            [$ids, $groups] = existing_import_context($examId);
            $preview = classify_import($matrix, $ids, $groups);
            if (!$preview['ok']) {
                respond(['ok' => false, 'message' => $preview['message'], 'redirect' => wizard_url($examId, 4)]);
            }
            store_import_preview($examId, $preview);
            $ready = import_ready_count($preview['rows']);
            if (post_str('go') === 'next_import') {
                if ($ready < 1) {
                    respond(['ok' => true, 'message' => 'No rows can be imported. Check the results.', 'redirect' => wizard_url($examId, 4)]);
                }
                $result = commit_import($examId, (string) $admin['username'], $preview);
                clear_import_preview($examId);
                $message = 'Imported ' . $result['imported'] . ' students.';
                if ($result['skipped'] > 0) {
                    $message .= ' ' . $result['skipped'] . ' rows were skipped.';
                }
                respond(['ok' => true, 'message' => $message, 'redirect' => wizard_url($examId, 5)]);
            }
            respond(['ok' => true, 'message' => 'Preview ready. ' . $ready . ' rows can be imported.', 'redirect' => wizard_url($examId, 4)]);
        }
        if ($action === 'commit_import') {
            $preview = take_import_preview($examId);
            if ($preview === null) {
                respond(['ok' => false, 'message' => 'Upload the spreadsheet again.', 'redirect' => wizard_url($examId, 4)]);
            }
            $result = commit_import($examId, (string) $admin['username'], $preview);
            clear_import_preview($examId);
            $message = 'Imported ' . $result['imported'] . ' students.';
            if ($result['skipped'] > 0) {
                $message .= ' ' . $result['skipped'] . ' rows were skipped.';
            }
            $nextStep = post_str('go') === 'next' ? 5 : 4;
            respond(['ok' => true, 'message' => $message, 'redirect' => wizard_url($examId, $nextStep)]);
        }
        if ($action === 'discard_import') {
            clear_import_preview($examId);
            respond(['ok' => true, 'message' => 'No students were imported.', 'redirect' => wizard_url($examId, 5)]);
        }
        if ($action === 'grant') {
            $result = grant_admin_action($admin, $examId, post_str('username'));
            $result['redirect'] = wizard_url($examId, 5);
            respond($result);
        }
        if ($action === 'revoke') {
            $result = revoke_admin_action($admin, $examId, post_str('username'));
            $result['redirect'] = wizard_url($examId, 5);
            respond($result);
        }
        if ($action === 'finish') {
            $send = post_str('send_now') === '1';
            $open = post_str('open_now') === '1';
            if ($send && !mail_is_configured()) {
                $send = false;
                flash('Email is not configured, so no messages were sent.', 'danger');
            }
            $round = db_one('SELECT * FROM exam_round WHERE exam_id = ? AND round_index = 1', [$examId]);
            if ($open && $round === null) {
                respond(['ok' => false, 'message' => 'Add the first round before opening it.', 'redirect' => wizard_url($examId, 5)]);
            }
            if ($open && $round !== null) {
                $opened = open_round_action($admin, (string) $round['round_id']);
                if (!$opened['ok']) {
                    $opened['redirect'] = wizard_url($examId, 5);
                    respond($opened);
                }
            }
            $sent = 0;
            $failed = 0;
            if ($send) {
                $batch = send_entry_batch($exam, students_without_delivery($examId), (string) $admin['username'], false);
                $sent = (int) ($batch['sent'] ?? 0);
                $failed = (int) ($batch['failed'] ?? 0);
            }
            $message = 'Exam saved.';
            if ($open) {
                $message = 'Round 1 is open.';
            }
            if ($send) {
                $message .= ' Sent ' . $sent . ' entry codes.';
                if ($failed > 0) {
                    $message .= ' ' . $failed . ' could not be sent.';
                }
            }
            respond(['ok' => true, 'message' => $message, 'redirect' => 'admin/exam.php?exam=' . rawurlencode($examId)]);
        }
        respond(['ok' => false, 'message' => 'That action is not available.', 'redirect' => wizard_url($examId, $step)]);
    }

    $round = $exam ? db_one('SELECT * FROM exam_round WHERE exam_id = ? AND round_index = 1', [$examId]) : null;
    $slotCount = $exam ? (int) db_value('SELECT COUNT(*) FROM exam_timeslot WHERE exam_id = ?', [$examId]) : 0;
    $studentCount = $exam ? (int) db_value('SELECT COUNT(*) FROM student_account WHERE exam_id = ?', [$examId]) : 0;
    $importPreview = null;
    $pendingReady = 0;
    if ($exam !== null && $step === 4) {
        $importPreview = take_import_preview($examId);
        if (is_array($importPreview) && is_array($importPreview['rows'] ?? null) && $importPreview['rows'] !== []) {
            $pendingReady = import_ready_count($importPreview['rows']);
        }
    }
    $steps = [
        1 => 'What is this exam',
        2 => 'First round',
        3 => 'Time slots',
        4 => 'Students and groups',
        5 => 'Access and finish',
    ];
    $done = [
        1 => $exam !== null,
        2 => $round !== null,
        3 => $slotCount > 0,
        4 => $studentCount > 0,
        5 => false,
    ];
    $body = '<ol class="wizard-steps">';
    foreach ($steps as $number => $label) {
        $classes = [];
        if ($number === $step) {
            $classes[] = 'is-current';
        } elseif (!empty($done[$number])) {
            $classes[] = 'is-done';
        }
        $class = $classes === [] ? '' : ' class="' . implode(' ', $classes) . '"';
        $body .= '<li' . $class . '>';
        if ($exam !== null || $number === 1) {
            $leave = ($step === 4 && $number === 5) ? ' data-import-next="1"' : '';
            $body .= '<a href="' . e(app_url(wizard_url($examId, $number))) . '"' . $leave . '>' . e($number . '. ' . $label) . '</a>';
        } else {
            $body .= '<span>' . e($number . '. ' . $label) . '</span>';
        }
        $body .= '</li>';
    }
    $body .= '</ol>';

    if ($step === 1) {
        $lockedMode = false;
        if ($exam !== null) {
            $lockedMode = (int) db_value('SELECT COUNT(*) FROM preference_submission ps JOIN exam_round r ON r.round_id = ps.round_id WHERE r.exam_id = ?', [$examId]) > 0;
        }
        $mode = (string) ($exam['acceptance_mode'] ?? 'fcfs');
        $body .= '<form class="js-form" method="post" action="' . e(request_path()) . '">';
        $body .= csrf_input() . '<input type="hidden" name="action" value="step1"><input type="hidden" name="exam_id" value="' . e($examId) . '">';
        $body .= text_field('title', 'Title', (string) ($exam['title'] ?? ''), ['required' => true]);
        $body .= text_field('subject', 'Subject', (string) ($exam['subject'] ?? ''), ['required' => true]);
        $body .= text_field('exam_code', 'Student entry code', (string) ($exam['exam_code'] ?? ''), ['required' => true, 'help' => 'Students use this code to enter. Every round shares it.']);
        $body .= text_field('slot_length_min', 'Length of one time slot', (string) ($exam['slot_length_min'] ?? '15'), ['type' => 'number', 'min' => '1', 'required' => true, 'help' => 'Minutes.']);
        $body .= '<p id="slot-length-note" class="plan-note">' . e(((int) ($exam['slot_length_min'] ?? 15)) > 0 ? 'Each group sits for ' . (int) ($exam['slot_length_min'] ?? 15) . ' minutes' : '') . '</p>';
        $body .= text_field('preference_limit', 'Choices a group can list', (string) ($exam['preference_limit'] ?? '3'), ['type' => 'number', 'min' => '1', 'required' => true]);
        $body .= '<fieldset class="mb-3"><legend class="form-label">How a time slot is taken</legend><div class="choice-row">';
        foreach (['fcfs' => ['First come, first served', 'A group takes the first free time slot on its list at the moment it submits.'], 'sealed' => ['Later acceptance', 'Groups submit a list. Nobody is given a time slot until you accept them after the deadline.']] as $value => [$label, $copy]) {
            $checked = $mode === $value ? ' checked' : '';
            $disabled = $lockedMode ? ' disabled' : '';
            $body .= '<label class="choice-card"><input type="radio" name="acceptance_mode" value="' . e($value) . '"' . $checked . $disabled . ' data-initial="' . ($mode === $value ? '1' : '0') . '"> <span class="choice-title">' . e($label) . '</span><p>' . e($copy) . '</p></label>';
        }
        $body .= '</div>';
        if ($lockedMode) {
            $body .= '<input type="hidden" name="acceptance_mode" value="' . e($mode) . '">';
            $body .= '<p class="form-text">Groups have already submitted choices, so this cannot change.</p>';
        }
        $body .= '</fieldset>';
        $body .= '<div class="d-flex gap-2"><button class="btn btn-outline-secondary" name="go" value="stay" type="submit">Save draft</button><button class="btn btn-poly" name="go" value="next" type="submit">Next</button></div></form>';
        $body .= '<script>document.getElementById("exam_code")?.setAttribute("data-live-check", location.pathname + location.search);</script>';
    }

    if ($step === 2 && $exam !== null) {
        $body .= '<form class="js-form" method="post"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="step2"><input type="hidden" name="exam_id" value="' . e($examId) . '">';
        $body .= text_field('start_at', 'Opens', hk_input($round['start_at'] ?? null), ['type' => 'datetime-local', 'required' => true, 'help' => 'Hong Kong time.']);
        $body .= text_field('deadline', 'Deadline', hk_input($round['deadline'] ?? null), ['type' => 'datetime-local', 'required' => true, 'help' => 'Hong Kong time. The deadline must be after the opening time.']);
        $body .= '<div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="' . e(app_url(wizard_url($examId, 1))) . '">Back</a><button class="btn btn-outline-secondary" name="go" value="stay" type="submit">Save draft</button><button class="btn btn-poly" name="go" value="next" type="submit">Next</button></div></form>';
    }

    if ($step === 3 && $exam !== null) {
        $body .= '<form class="js-form" method="post" data-plan-form><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="add_slots"><input type="hidden" name="exam_id" value="' . e($examId) . '">';
        $body .= '<div class="generator-grid">';
        $body .= text_field('start_date', 'Start date', '', ['id' => 'start_date', 'type' => 'date']);
        $body .= text_field('end_date', 'End date', '', ['id' => 'end_date', 'type' => 'date']);
        $body .= text_field('daily_start', 'Daily start', '09:00', ['id' => 'daily_start', 'type' => 'time']);
        $body .= text_field('daily_end', 'Daily end', '12:00', ['id' => 'daily_end', 'type' => 'time']);
        $body .= text_field('chairs', 'Parallel chairs', '1', ['id' => 'chairs', 'type' => 'number', 'min' => '1']);
        $body .= '</div><p id="plan-note" class="plan-note"></p>';
        $body .= '<button class="btn btn-poly" type="submit">Add time slots</button></form><br>';
        $slots = db_all('SELECT * FROM exam_timeslot WHERE exam_id = ? ORDER BY start_at ASC, timeslot_id ASC', [$examId]);
        $numbers = slot_number_map($examId);
        if ($slots === []) {
            $body .= empty_state('This exam has no timeslots yet.');
        } else {
            $slotFilter = (string) ($_GET['filter'] ?? 'all');
            if (!in_array($slotFilter, ['all', 'available', 'occupied'], true)) {
                $slotFilter = 'all';
            }
            $body .= '<div class="slot-tools">';
            $body .= filter_bar('Status', ['all' => 'All', 'available' => 'Available', 'occupied' => 'Occupied'], $slotFilter);
            $body .= '<button type="button" class="btn btn-outline-danger" id="remove-selected" disabled>Remove selected</button>';
            $body .= '</div>';
            $body .= '<form id="remove-slots" class="js-form" method="post" hidden>' . csrf_input()
                . '<input type="hidden" name="action" value="remove_slots"><input type="hidden" name="exam_id" value="' . e($examId) . '"></form>';
            $body .= '<table id="slot-table" data-client-table data-maintain-meta-data="true" data-sort-name="time" data-sort-order="asc"><thead><tr>'
                . '<th data-checkbox="true" data-formatter="slotRemoveCheckbox"></th>'
                . '<th data-field="time" data-sortable="true" data-sorter="slotTimeSorter">Time</th>'
                . '<th data-field="number" data-sortable="true" data-sorter="slotNumberSorter">Time slot</th>'
                . '<th data-field="status" data-sortable="true">Status</th>'
                . '<th>Action</th>'
                . '</tr></thead><tbody>';
            foreach ($slots as $slot) {
                $used = timeslot_is_used((string) $slot['timeslot_id']);
                $removable = !$used && $slot['status'] === 'available';
                $body .= '<tr data-id="' . e((string) $slot['timeslot_id']) . '" data-filter="' . e((string) $slot['status']) . '" data-removable="' . ($removable ? '1' : '0') . '" data-start="' . e((string) $slot['start_at']) . '" data-number="' . e((string) ($numbers[$slot['timeslot_id']] ?? '')) . '">';
                $body .= '<td></td>';
                $body .= '<td>' . e(hk_range((string) $slot['start_at'], (string) $slot['end_at'])) . '</td>';
                $body .= '<td>' . e((string) ($numbers[$slot['timeslot_id']] ?? '')) . '</td>';
                $body .= '<td>' . slot_badge((string) $slot['status']) . '</td><td>';
                if ($removable) {
                    $body .= '<form class="js-form" method="post"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="remove_slot"><input type="hidden" name="exam_id" value="' . e($examId) . '"><input type="hidden" name="timeslot_id" value="' . e($slot['timeslot_id']) . '"><button class="btn btn-link text-danger" type="submit">Remove</button></form>';
                }
                $body .= '</td></tr>';
            }
            $body .= '</tbody></table>';
        }
        $body .= '<div class="d-flex gap-2 mt-3"><a class="btn btn-outline-secondary" href="' . e(app_url(wizard_url($examId, 2))) . '">Back</a><a class="btn btn-poly" href="' . e(app_url(wizard_url($examId, 4))) . '">Next</a></div>';
        $body = '<div data-slot-minutes="' . e((string) $exam['slot_length_min']) . '">' . $body . '</div>';
    }

    if ($step === 4 && $exam !== null) {
        $body .= '<div class="example-actions">';
        $body .= '<div><a class="btn btn-outline-secondary" href="' . e(app_url('excel_example/import_grp.xlsx')) . '" download>Download group example</a><p class="form-text">Same Group ID means the same group. Empty Group Name becomes "Group" plus that ID.</p></div>';
        $body .= '<div><a class="btn btn-outline-secondary" href="' . e(app_url('excel_example/import_indv.xlsx')) . '" download>Download individual example</a><p class="form-text">Each row is a group of one. The group name is the student name. If two students share a name, the student ID is added.</p></div>';
        $body .= '</div>';
        $body .= '<form method="post" enctype="multipart/form-data" class="mt-3" id="preview-form">' . csrf_input() . '<input type="hidden" name="action" value="preview_import"><input type="hidden" name="exam_id" value="' . e($examId) . '">';
        $body .= '<div class="file-drop"><label class="form-label" for="import_file">Spreadsheet</label><input class="form-control" type="file" id="import_file" name="import_file" accept=".xlsx" required><p class="file-drop-note" aria-live="polite">Drop an .xlsx file here, or choose a file.</p></div>';
        $body .= '<button class="btn btn-outline-secondary mt-3" type="submit" id="preview-submit">Preview</button></form>';
        $preview = $importPreview;
        if (is_array($preview) && !empty($preview['rows'])) {
            $ready = import_ready_count($preview['rows']);
            if (!empty($preview['renames'])) {
                $body .= '<p>Some existing one-person groups will be renamed so the same name is not shared.</p><ul>';
                foreach ($preview['renames'] as $rename) {
                    $body .= '<li>' . e($rename['display_name']) . '</li>';
                }
                $body .= '</ul>';
            }
            $body .= '<div class="mt-3"><table id="import-preview-table" data-client-table data-ignore-filter="true"><thead><tr><th data-sortable="true">Student ID</th><th data-sortable="true">Name</th><th data-sortable="true">Email</th><th data-sortable="true">Group</th><th data-sortable="true">Result of check</th></tr></thead><tbody>';
            foreach ($preview['rows'] as $row) {
                $body .= '<tr><td>' . e($row['student_id']) . '</td><td>' . e($row['name']) . '</td><td>' . e($row['email']) . '</td><td>' . e($row['group_name']) . '</td><td>' . e($row['result']) . '</td></tr>';
            }
            $body .= '</tbody></table></div>';
            if ($ready > 0) {
                $body .= '<form method="post" class="js-form">' . csrf_input() . '<input type="hidden" name="action" value="commit_import"><input type="hidden" name="exam_id" value="' . e($examId) . '"><button class="btn btn-poly" type="submit">Import ' . e((string) $ready) . ' ready rows</button></form>';
            }
        }
        $nextUrl = app_url(wizard_url($examId, 5));
        $body .= '<div class="d-flex gap-2 mt-3"><a class="btn btn-outline-secondary" href="' . e(app_url(wizard_url($examId, 3))) . '">Back</a>';
        $body .= '<button type="button" class="btn btn-poly" id="step4-next" data-next="' . e($nextUrl) . '" data-pending="' . e((string) $pendingReady) . '">Next</button></div>';
        $body .= '<div class="modal fade" id="import-next-modal" tabindex="-1" aria-labelledby="import-next-title" aria-hidden="true">';
        $body .= '<div class="modal-dialog"><div class="modal-content">';
        $body .= '<div class="modal-header"><h2 class="modal-title h5" id="import-next-title">Students are not imported yet</h2>';
        $body .= '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button></div>';
        $body .= '<div class="modal-body">';
        $body .= '<div id="import-next-file" hidden><p>You chose a spreadsheet, but it has not been checked. Nothing in that file is in the exam yet.</p><p>Continue without importing and this file is left out.</p></div>';
        if ($pendingReady > 0) {
            $skipped = count($importPreview['rows']) - $pendingReady;
            $studentWord = $pendingReady === 1 ? 'student' : 'students';
            $verb = $pendingReady === 1 ? 'is' : 'are';
            $body .= '<div id="import-next-preview" hidden><p>' . e((string) $pendingReady) . ' ' . $studentWord . ' ' . $verb . ' ready in this preview. They are not in the exam until you import them.</p>';
            if ($skipped > 0) {
                $body .= '<p>' . e((string) $skipped) . ' ' . ($skipped === 1 ? 'row' : 'rows') . ' failed the check and will not be imported.</p>';
            }
            $body .= '<p>Continue without importing and this preview is deleted.</p></div>';
        }
        $body .= '</div><div class="modal-footer">';
        $body .= '<button type="button" class="btn btn-poly" id="import-check-next" hidden>Check and import, then continue</button>';
        if ($pendingReady > 0) {
            $studentWord = $pendingReady === 1 ? 'student' : 'students';
            $body .= '<form class="js-form" method="post" action="' . e(request_path()) . '" id="import-commit-next" hidden>' . csrf_input();
            $body .= '<input type="hidden" name="action" value="commit_import"><input type="hidden" name="go" value="next"><input type="hidden" name="exam_id" value="' . e($examId) . '">';
            $body .= '<button class="btn btn-poly" type="submit" data-busy-label="Importing">Import ' . e((string) $pendingReady) . ' ' . $studentWord . ' and continue</button></form>';
        }
        $body .= '<form class="js-form" method="post" action="' . e(request_path()) . '">' . csrf_input();
        $body .= '<input type="hidden" name="action" value="discard_import"><input type="hidden" name="exam_id" value="' . e($examId) . '">';
        $body .= '<button class="btn btn-outline-secondary" type="submit" data-busy-label="Continuing">Continue without importing</button></form>';
        $body .= '</div></div></div></div>';
        $body .= '<script>
        (function () {
            const button = document.getElementById("step4-next");
            const modal = document.getElementById("import-next-modal");
            const file = document.getElementById("import_file");
            const previewForm = document.getElementById("preview-form");
            if (!button || !modal) { return; }
            const pending = parseInt(button.getAttribute("data-pending") || "0", 10);
            function fileChosen() { return !!(file && file.files && file.files.length > 0); }
            function shouldAsk() { return fileChosen() || pending > 0; }
            function showPane() {
                const chosen = fileChosen();
                const filePane = document.getElementById("import-next-file");
                const fileAction = document.getElementById("import-check-next");
                const previewPane = document.getElementById("import-next-preview");
                const commit = document.getElementById("import-commit-next");
                if (filePane) { filePane.hidden = !chosen; }
                if (fileAction) { fileAction.hidden = !chosen; }
                if (previewPane) { previewPane.hidden = chosen; }
                if (commit) { commit.hidden = chosen; }
            }
            function openModal() {
                showPane();
                function open() {
                    if (!window.bootstrap) { window.setTimeout(open, 30); return; }
                    window.bootstrap.Modal.getOrCreateInstance(modal).show();
                }
                open();
            }
            button.addEventListener("click", function () {
                if (!shouldAsk()) { window.location.href = button.getAttribute("data-next") || window.location.href; return; }
                openModal();
            });
            document.querySelectorAll("[data-import-next]").forEach(function (link) {
                link.addEventListener("click", function (event) {
                    if (!shouldAsk()) { return; }
                    event.preventDefault();
                    openModal();
                });
            });
            const check = document.getElementById("import-check-next");
            if (check && previewForm) {
                check.addEventListener("click", function () {
                    let go = previewForm.querySelector("[data-go-next]");
                    if (!go) {
                        go = document.createElement("input");
                        go.type = "hidden";
                        go.name = "go";
                        go.value = "next_import";
                        go.setAttribute("data-go-next", "1");
                        previewForm.appendChild(go);
                    }
                    previewForm.requestSubmit();
                });
            }
            const previewButton = document.getElementById("preview-submit");
            if (previewButton && previewForm) {
                previewButton.addEventListener("click", function () {
                    const go = previewForm.querySelector("[data-go-next]");
                    if (go) { go.remove(); }
                });
            }
        })();
        </script>';
    }

    if ($step === 5 && $exam !== null) {
        $access = db_all(
            'SELECT u.username, u.display_name, u.role FROM exam_admin ea JOIN admin_user u ON u.username = ea.admin_username WHERE ea.exam_id = ? ORDER BY u.display_name',
            [$examId]
        );
        $body .= '<form class="admin-suggest mb-3" id="admin-search-form" autocomplete="off">';
        $body .= '<label class="form-label" for="admin-q">Add an admin</label>';
        $body .= '<input class="form-control" id="admin-q" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="admin-results" placeholder="Name or username" autocomplete="off">';
        $body .= '<div id="admin-results" class="admin-suggest-list" role="listbox" hidden></div></form>';
        $body .= '<ul class="attention-list">';
        foreach ($access as $row) {
            $body .= '<li><span>' . e($row['display_name']) . ' · ' . e($row['username']) . ' · ' . e(role_label((string) $row['role'])) . '</span>';
            if ($row['username'] !== $exam['created_by_admin_username']) {
                $body .= '<form class="js-form" method="post">' . csrf_input() . '<input type="hidden" name="action" value="revoke"><input type="hidden" name="exam_id" value="' . e($examId) . '"><input type="hidden" name="username" value="' . e($row['username']) . '"><button class="btn btn-link text-danger" type="submit">Remove</button></form>';
            } else {
                $body .= '<span class="form-text">The person who created this exam must keep access.</span>';
            }
            $body .= '</li>';
        }
        $body .= '</ul>';
        $mailReady = mail_is_configured();
        $body .= '<form class="js-form" method="post">' . csrf_input() . '<input type="hidden" name="action" value="finish"><input type="hidden" name="exam_id" value="' . e($examId) . '">';
        $body .= '<div class="form-check"><input class="form-check-input" type="checkbox" name="send_now" id="send_now" value="1"' . ($mailReady ? '' : ' disabled') . '><label class="form-check-label" for="send_now">Send each student their entry code now</label></div>';
        if (!$mailReady) {
            $body .= '<p class="form-text">Email is not configured. Finish without sending, then download the spreadsheet.</p>';
        }
        $body .= '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="open_now" id="open_now" value="1"><label class="form-check-label" for="open_now">Open round 1 now</label></div>';
        $body .= '<div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="' . e(app_url(wizard_url($examId, 4))) . '">Back</a><button class="btn btn-poly" type="submit">Finish</button></div></form>';
        $searchBoot = json_encode([
            'csrf' => csrf_input() . '<input type="hidden" name="action" value="grant"><input type="hidden" name="exam_id" value="' . e($examId) . '">',
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $body .= '<script>document.addEventListener("DOMContentLoaded", function () { if (window.bindAdminSuggest) { window.bindAdminSuggest(' . $searchBoot . '); } });</script>';
    }

    $slotMinutes = (int) ($exam['slot_length_min'] ?? 0);
    $crumbs = [
        ['label' => 'Exams', 'href' => app_url('admin/exams.php')],
        ['label' => $exam['title'] ?? 'New exam', 'href' => ''],
    ];
    if ($exam !== null && (string) ($exam['exam_code'] ?? '') !== '') {
        $crumbs[1]['note'] = (string) $exam['exam_code'];
    }
    render_admin($admin, [
        'title' => 'New exam',
        'subtitle' => 'Set up one oral exam. You can save a draft at any step.',
        'nav' => 'exams',
        'crumbs' => $crumbs,
    ], $body);
} catch (Throwable $e) {
    error_log($e->__toString());
    if (wants_json()) {
        json_out(['ok' => false, 'message' => friendly_exception($e)], 500);
    }
    render_error_page('The service is not available right now. Try again later.');
}
