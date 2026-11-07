<?php
declare(strict_types=1);

if (!defined('BMS_APP')) {
    http_response_code(404);
    exit;
}

function latest_round(string $examId): ?array
{
    return db_one(
        'SELECT * FROM exam_round WHERE exam_id = ? ORDER BY round_index DESC LIMIT 1',
        [$examId]
    );
}

function sealed_accept_round(string $examId): ?array
{
    return db_one(
        'SELECT * FROM exam_round
         WHERE exam_id = ? AND deadline <= ? AND status <> "matched"
         ORDER BY round_index DESC
         LIMIT 1',
        [$examId, hk_now()->format('Y-m-d H:i:s')]
    );
}

function continue_setup_step(string $examId): int
{
    if ((int) db_value('SELECT COUNT(*) FROM exam_round WHERE exam_id = ?', [$examId]) < 1) {
        return 2;
    }
    if ((int) db_value('SELECT COUNT(*) FROM exam_timeslot WHERE exam_id = ?', [$examId]) < 1) {
        return 3;
    }
    if ((int) db_value('SELECT COUNT(*) FROM student_account WHERE exam_id = ?', [$examId]) < 1) {
        return 4;
    }
    return 5;
}

function continue_setup_href(string $examId): string
{
    return 'admin/exam-new.php?exam=' . rawurlencode($examId) . '&step=' . continue_setup_step($examId);
}

function unassigned_groups(string $examId): array
{
    return db_all(
        'SELECT g.group_id, g.display_name
         FROM project_group g
         LEFT JOIN assignment a ON a.group_id = g.group_id
         WHERE g.exam_id = ? AND a.assignment_id IS NULL
         ORDER BY g.display_name ASC',
        [$examId]
    );
}

function open_round_action(array $admin, string $roundId): array
{
    $round = db_one('SELECT * FROM exam_round WHERE round_id = ?', [$roundId]);
    if ($round === null) {
        return ['ok' => false, 'message' => 'That round was not found.'];
    }
    $exam = managed_exam($admin, (string) $round['exam_id']);
    if ($exam === null) {
        return ['ok' => false, 'message' => 'That exam is not available.'];
    }
    if ($exam['status'] === 'completed') {
        return ['ok' => false, 'message' => 'This exam is completed.'];
    }
    if (!in_array($round['status'], ['pending', 'open'], true)) {
        return ['ok' => false, 'message' => 'This round cannot be opened.'];
    }
    if ($round['status'] === 'open') {
        return ['ok' => true, 'message' => 'Round ' . $round['round_index'] . ' is already open.', 'exam_id' => $exam['exam_id']];
    }
    $now = hk_now()->format('Y-m-d H:i:s');
    db_tx(static function () use ($admin, $round, $exam, $now): void {
        $others = db_all(
            'SELECT * FROM exam_round WHERE exam_id = ? AND status = "open" AND round_id <> ?',
            [$exam['exam_id'], $round['round_id']]
        );
        foreach ($others as $other) {
            db_exec(
                'UPDATE exam_round SET status = "closed", closed_at = ? WHERE round_id = ?',
                [$now, $other['round_id']]
            );
            audit_write(
                (string) $exam['exam_id'],
                (string) $other['round_id'],
                null,
                (string) $admin['username'],
                'round_closed',
                'Round ' . $other['round_index'] . ' closed because Round ' . $round['round_index'] . ' opened. Time slots already taken stay with their groups.'
            );
        }
        db_exec('UPDATE exam_round SET status = "open" WHERE round_id = ?', [$round['round_id']]);
        if ($exam['status'] === 'draft') {
            db_exec('UPDATE exam SET status = "active", updated_at = ? WHERE exam_id = ?', [$now, $exam['exam_id']]);
        } else {
            touch_exam((string) $exam['exam_id']);
        }
        audit_write(
            (string) $exam['exam_id'],
            (string) $round['round_id'],
            null,
            (string) $admin['username'],
            'round_opened',
            'Round ' . $round['round_index'] . ' opened.'
        );
    });
    return ['ok' => true, 'message' => 'Round ' . $round['round_index'] . ' is open.', 'exam_id' => $exam['exam_id'], 'row' => $round['round_id']];
}

function close_round_action(array $admin, string $roundId): array
{
    $round = db_one('SELECT * FROM exam_round WHERE round_id = ?', [$roundId]);
    if ($round === null) {
        return ['ok' => false, 'message' => 'That round was not found.'];
    }
    $exam = managed_exam($admin, (string) $round['exam_id']);
    if ($exam === null) {
        return ['ok' => false, 'message' => 'That exam is not available.'];
    }
    if ($round['status'] !== 'open') {
        return ['ok' => false, 'message' => 'Only an open round can be closed.'];
    }
    $now = hk_now()->format('Y-m-d H:i:s');
    db_exec('UPDATE exam_round SET status = "closed", closed_at = ? WHERE round_id = ?', [$now, $roundId]);
    touch_exam((string) $exam['exam_id']);
    audit_write(
        (string) $exam['exam_id'],
        $roundId,
        null,
        (string) $admin['username'],
        'round_closed',
        'Round ' . $round['round_index'] . ' closed. Time slots already taken stay with their groups.'
    );
    return ['ok' => true, 'message' => 'Round ' . $round['round_index'] . ' is closed.', 'exam_id' => $exam['exam_id'], 'row' => $roundId];
}

function save_round_time(array $admin, string $roundId, string $startRaw, string $deadlineRaw): array
{
    $round = db_one('SELECT * FROM exam_round WHERE round_id = ?', [$roundId]);
    if ($round === null) {
        return ['ok' => false, 'message' => 'That round was not found.'];
    }
    $exam = managed_exam($admin, (string) $round['exam_id']);
    if ($exam === null) {
        return ['ok' => false, 'message' => 'That exam is not available.'];
    }
    if (!in_array($round['status'], ['pending', 'open'], true)) {
        return ['ok' => false, 'message' => 'Closed rounds keep their original times.'];
    }
    $start = hk_store($startRaw);
    $deadline = hk_store($deadlineRaw);
    $errors = [];
    if ($start === null) {
        $errors['start_at'] = 'Choose when the round opens.';
    }
    if ($deadline === null) {
        $errors['deadline'] = 'Choose a deadline.';
    }
    if ($start !== null && $deadline !== null && $start >= $deadline) {
        $errors['deadline'] = 'The deadline must be after the opening time.';
    }
    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors, 'message' => 'Check the times and try again.'];
    }
    db_exec('UPDATE exam_round SET start_at = ?, deadline = ? WHERE round_id = ?', [$start, $deadline, $roundId]);
    touch_exam((string) $exam['exam_id']);
    audit_write(
        (string) $exam['exam_id'],
        $roundId,
        null,
        (string) $admin['username'],
        'round_time_changed',
        'Round ' . $round['round_index'] . ' now opens ' . hk_full($start) . ' and closes ' . hk_full($deadline) . '.'
    );
    return ['ok' => true, 'message' => 'Round time updated.', 'exam_id' => $exam['exam_id'], 'row' => $roundId];
}

function add_round_action(array $admin, string $examId, string $startRaw, string $deadlineRaw): array
{
    $exam = managed_exam($admin, $examId);
    if ($exam === null) {
        return ['ok' => false, 'message' => 'That exam is not available.'];
    }
    if ($exam['status'] === 'completed') {
        return ['ok' => false, 'message' => 'This exam is completed.'];
    }
    $start = hk_store($startRaw);
    $deadline = hk_store($deadlineRaw);
    $errors = [];
    if ($start === null) {
        $errors['start_at'] = 'Choose when the round opens.';
    }
    if ($deadline === null) {
        $errors['deadline'] = 'Choose a deadline.';
    }
    if ($start !== null && $deadline !== null && $start >= $deadline) {
        $errors['deadline'] = 'The deadline must be after the opening time.';
    }
    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors, 'message' => 'Check the times and try again.'];
    }
    $max = (int) db_value('SELECT COALESCE(MAX(round_index), 0) FROM exam_round WHERE exam_id = ?', [$examId]);
    $index = $max + 1;
    $roundId = uuid_v7();
    $waiting = count(unassigned_groups($examId));
    db_exec(
        'INSERT INTO exam_round
            (round_id, exam_id, round_index, start_at, deadline, status, created_by_admin_username)
         VALUES (?, ?, ?, ?, ?, "pending", ?)',
        [$roundId, $examId, $index, $start, $deadline, $admin['username']]
    );
    touch_exam($examId);
    audit_write(
        $examId,
        $roundId,
        null,
        (string) $admin['username'],
        'round_created',
        'Round ' . $index . ' added. ' . $waiting . ' groups are still waiting and can choose again. Groups that already have a time slot stay where they are.'
    );
    return ['ok' => true, 'message' => 'Round ' . $index . ' added.', 'exam_id' => $examId, 'row' => $roundId];
}

function sealed_preview(string $roundId, int $seed): array
{
    $round = db_one('SELECT * FROM exam_round WHERE round_id = ?', [$roundId]);
    if ($round === null) {
        return ['ok' => false, 'message' => 'That round was not found.'];
    }
    $built = sealed_inputs((string) $round['exam_id'], $roundId);
    $plan = sealed_plan($built['group_ids'], $built['choices'], $built['free'], $seed);
    return [
        'ok' => true,
        'seed' => $seed,
        'assigned' => count($plan['assigned']),
        'unsatisfied' => count($plan['unsatisfied']),
        'no_choice' => count($plan['no_choice']),
        'round_index' => (int) $round['round_index'],
        'will_close' => $round['status'] === 'open',
    ];
}

function sealed_inputs(string $examId, string $roundId): array
{
    $groups = unassigned_groups($examId);
    $groupIds = array_map(static fn (array $row): string => (string) $row['group_id'], $groups);
    $choices = [];
    if ($groupIds !== []) {
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $choiceRows = db_all(
            'SELECT ps.group_id, pc.choice_id, pc.timeslot_id, pc.priority
             FROM preference_submission ps
             JOIN preference_choice pc ON pc.submission_id = ps.submission_id
             WHERE ps.round_id = ? AND ps.status = "current" AND ps.group_id IN (' . $placeholders . ')
             ORDER BY pc.priority ASC',
            array_merge([$roundId], $groupIds)
        );
        foreach ($choiceRows as $row) {
            $choices[(string) $row['group_id']][] = [
                'choice_id' => (string) $row['choice_id'],
                'timeslot_id' => (string) $row['timeslot_id'],
                'priority' => (int) $row['priority'],
            ];
        }
    }
    $free = [];
    foreach (db_all('SELECT timeslot_id FROM exam_timeslot WHERE exam_id = ? AND status = "available"', [$examId]) as $slot) {
        $free[(string) $slot['timeslot_id']] = true;
    }
    return ['group_ids' => $groupIds, 'choices' => $choices, 'free' => $free];
}

function accept_sealed_action(array $admin, string $roundId, int $seed): array
{
    $round = db_one('SELECT * FROM exam_round WHERE round_id = ?', [$roundId]);
    if ($round === null) {
        return ['ok' => false, 'message' => 'That round was not found.'];
    }
    $exam = managed_exam($admin, (string) $round['exam_id']);
    if ($exam === null) {
        return ['ok' => false, 'message' => 'That exam is not available.'];
    }
    if ($exam['acceptance_mode'] !== 'sealed') {
        return ['ok' => false, 'message' => 'This exam gives out time slots when a group submits.'];
    }
    if ($exam['status'] === 'completed') {
        return ['ok' => false, 'message' => 'This exam is completed.'];
    }
    $deadline = hk_parse((string) $round['deadline']);
    if ($deadline === null || hk_now() < $deadline) {
        return ['ok' => false, 'message' => 'Acceptance opens after ' . hk_full((string) $round['deadline']) . '.'];
    }
    $now = hk_now()->format('Y-m-d H:i:s');
    $result = db_tx(static function () use ($admin, $round, $exam, $seed, $now): array {
        $built = sealed_inputs((string) $exam['exam_id'], (string) $round['round_id']);
        $lockedFree = [];
        $slotRows = db_all(
            'SELECT timeslot_id, status FROM exam_timeslot WHERE exam_id = ? FOR UPDATE',
            [$exam['exam_id']]
        );
        foreach ($slotRows as $slot) {
            if ($slot['status'] === 'available') {
                $lockedFree[(string) $slot['timeslot_id']] = true;
            }
        }
        $plan = sealed_plan($built['group_ids'], $built['choices'], $lockedFree, $seed);
        $runId = uuid_v7();
        db_exec(
            'INSERT INTO match_run (run_id, round_id, algorithm, random_seed, started_at, completed_at)
             VALUES (?, ?, "sealed", ?, ?, ?)',
            [$runId, $round['round_id'], $seed, $now, $now]
        );
        foreach ($plan['assigned'] as $item) {
            db_exec(
                'INSERT INTO assignment
                    (assignment_id, round_id, group_id, timeslot_id, selected_choice_id, run_id, source, accepted_at, assigned_by_admin_username)
                 VALUES (?, ?, ?, ?, ?, ?, "sealed", ?, NULL)',
                [uuid_v7(), $round['round_id'], $item['group_id'], $item['timeslot_id'], $item['choice_id'], $runId, $now]
            );
            db_exec('UPDATE exam_timeslot SET status = "occupied" WHERE timeslot_id = ?', [$item['timeslot_id']]);
        }
        $stillWaiting = (int) db_value(
            'SELECT COUNT(*) FROM project_group g
             LEFT JOIN assignment a ON a.group_id = g.group_id
             WHERE g.exam_id = ? AND a.assignment_id IS NULL',
            [$exam['exam_id']]
        );
        if ($stillWaiting === 0 && (int) db_value('SELECT COUNT(*) FROM project_group WHERE exam_id = ?', [$exam['exam_id']]) > 0) {
            db_exec('UPDATE exam_round SET status = "matched", closed_at = COALESCE(closed_at, ?) WHERE round_id = ?', [$now, $round['round_id']]);
        } elseif ($round['status'] === 'open') {
            db_exec('UPDATE exam_round SET status = "closed", closed_at = ? WHERE round_id = ?', [$now, $round['round_id']]);
        }
        touch_exam((string) $exam['exam_id']);
        audit_write(
            (string) $exam['exam_id'],
            (string) $round['round_id'],
            $runId,
            (string) $admin['username'],
            'sealed_accepted',
            'Accepted ' . count($plan['assigned']) . ' groups after the deadline. '
                . count($plan['unsatisfied']) . ' groups could not be given a time slot on their list.'
        );
        return [
            'assigned' => count($plan['assigned']),
            'unsatisfied' => count($plan['unsatisfied']),
            'no_choice' => count($plan['no_choice']),
            'run_id' => $runId,
        ];
    });
    $message = 'Accepted ' . $result['assigned'] . ' groups. ' . $result['unsatisfied'] . ' groups could not be given a time slot.';
    return ['ok' => true, 'message' => $message, 'exam_id' => $exam['exam_id'], 'row' => $roundId] + $result;
}

function complete_exam_action(array $admin, string $examId, string $typedCode): array
{
    $exam = managed_exam($admin, $examId);
    if ($exam === null) {
        return ['ok' => false, 'message' => 'That exam is not available.'];
    }
    if ($exam['status'] === 'completed') {
        return ['ok' => false, 'message' => 'This exam is already completed.'];
    }
    if ($typedCode !== (string) $exam['exam_code']) {
        return ['ok' => false, 'errors' => ['confirm_code' => 'Type the exam code to confirm.'], 'message' => 'Type the exam code to confirm.'];
    }
    $now = hk_now()->format('Y-m-d H:i:s');
    db_tx(static function () use ($admin, $exam, $now): void {
        $open = db_all('SELECT * FROM exam_round WHERE exam_id = ? AND status = "open"', [$exam['exam_id']]);
        foreach ($open as $round) {
            db_exec('UPDATE exam_round SET status = "closed", closed_at = ? WHERE round_id = ?', [$now, $round['round_id']]);
            audit_write(
                (string) $exam['exam_id'],
                (string) $round['round_id'],
                null,
                (string) $admin['username'],
                'round_closed',
                'Round ' . $round['round_index'] . ' closed because the exam was marked completed.'
            );
        }
        db_exec('UPDATE exam SET status = "completed", updated_at = ? WHERE exam_id = ?', [$now, $exam['exam_id']]);
        audit_write(
            (string) $exam['exam_id'],
            null,
            null,
            (string) $admin['username'],
            'exam_completed',
            $exam['title'] . ' was marked completed. Students can no longer submit preferences.'
        );
    });
    return ['ok' => true, 'message' => 'Exam marked completed.', 'exam_id' => $examId];
}

function delete_draft_action(array $admin, string $examId, string $typedCode): array
{
    $exam = managed_exam($admin, $examId);
    if ($exam === null) {
        return ['ok' => false, 'message' => 'That exam is not available.'];
    }
    if ($exam['status'] !== 'draft') {
        return ['ok' => false, 'message' => 'Only a draft with no students can be deleted.'];
    }
    if ($typedCode !== (string) $exam['exam_code']) {
        return ['ok' => false, 'errors' => ['confirm_code' => 'Type the exam code to confirm.'], 'message' => 'Type the exam code to confirm.'];
    }
    $students = (int) db_value('SELECT COUNT(*) FROM student_account WHERE exam_id = ?', [$examId]);
    $assignments = (int) db_value(
        'SELECT COUNT(*) FROM assignment a JOIN exam_round r ON r.round_id = a.round_id WHERE r.exam_id = ?',
        [$examId]
    );
    $deliveries = (int) db_value('SELECT COUNT(*) FROM credential_delivery WHERE exam_id = ?', [$examId]);
    if ($students > 0 || $assignments > 0 || $deliveries > 0) {
        return ['ok' => false, 'message' => 'This draft already has students, time slots that were used, or email records.'];
    }
    audit_write(
        null,
        null,
        null,
        (string) $admin['username'],
        'exam_deleted',
        'Deleted draft ' . $exam['title'] . ' (' . $exam['exam_code'] . ').'
    );
    db_exec('DELETE FROM exam WHERE exam_id = ?', [$examId]);
    return ['ok' => true, 'message' => 'Draft deleted.', 'redirect' => 'admin/exams.php'];
}

function draft_can_delete(string $examId): bool
{
    $students = (int) db_value('SELECT COUNT(*) FROM student_account WHERE exam_id = ?', [$examId]);
    $assignments = (int) db_value(
        'SELECT COUNT(*) FROM assignment a JOIN exam_round r ON r.round_id = a.round_id WHERE r.exam_id = ?',
        [$examId]
    );
    $deliveries = (int) db_value('SELECT COUNT(*) FROM credential_delivery WHERE exam_id = ?', [$examId]);
    return $students === 0 && $assignments === 0 && $deliveries === 0;
}

function add_timeslots_action(array $admin, string $examId, array $input): array
{
    $exam = managed_exam($admin, $examId);
    if ($exam === null) {
        return ['ok' => false, 'message' => 'That exam is not available.'];
    }
    if ($exam['status'] === 'completed') {
        return ['ok' => false, 'message' => 'This exam is completed.'];
    }
    $plan = plan_timeslots(
        (string) ($input['start_date'] ?? ''),
        (string) ($input['end_date'] ?? ''),
        (string) ($input['daily_start'] ?? ''),
        (string) ($input['daily_end'] ?? ''),
        (int) $exam['slot_length_min'],
        (int) ($input['chairs'] ?? 0)
    );
    if (!$plan['ok']) {
        return ['ok' => false, 'message' => $plan['errors'][0] ?? 'Check the time slot range.', 'errors' => ['plan' => $plan['errors'][0] ?? '']];
    }
    if ($plan['total'] < 1) {
        return ['ok' => false, 'message' => $plan['message']];
    }
    db_tx(static function () use ($examId, $plan): void {
        foreach ($plan['rows'] as $row) {
            db_exec(
                'INSERT INTO exam_timeslot (timeslot_id, exam_id, start_at, end_at, status) VALUES (?, ?, ?, ?, "available")',
                [uuid_v7(), $examId, $row['start_at'], $row['end_at']]
            );
        }
    });
    touch_exam($examId);
    audit_write(
        $examId,
        null,
        null,
        (string) $admin['username'],
        'timeslots_added',
        'Added ' . $plan['total'] . ' time slots. ' . $plan['clocks'] . ' clock times, ' . $plan['chairs'] . ' time slots each.'
    );
    return ['ok' => true, 'message' => 'Added ' . $plan['total'] . ' time slots.', 'exam_id' => $examId];
}

function remove_timeslot_action(array $admin, string $timeslotId, bool $draftOnly): array
{
    $slot = db_one('SELECT * FROM exam_timeslot WHERE timeslot_id = ?', [$timeslotId]);
    if ($slot === null) {
        return ['ok' => false, 'message' => 'That time slot was not found.'];
    }
    $exam = managed_exam($admin, (string) $slot['exam_id']);
    if ($exam === null) {
        return ['ok' => false, 'message' => 'That exam is not available.'];
    }
    if ($draftOnly && $exam['status'] !== 'draft') {
        return ['ok' => false, 'message' => 'Time slots can only be removed from the draft before the exam opens.'];
    }
    if (timeslot_is_used($timeslotId)) {
        return ['ok' => false, 'message' => 'A group has already used this time slot.'];
    }
    db_exec('DELETE FROM exam_timeslot WHERE timeslot_id = ?', [$timeslotId]);
    touch_exam((string) $exam['exam_id']);
    audit_write(
        (string) $exam['exam_id'],
        null,
        null,
        (string) $admin['username'],
        'timeslot_removed',
        'Removed the time slot at ' . hk_range((string) $slot['start_at'], (string) $slot['end_at']) . '.'
    );
    return ['ok' => true, 'message' => 'Time slot removed.', 'exam_id' => $exam['exam_id']];
}

function remove_timeslots_action(array $admin, string $examId, array $timeslotIds, bool $draftOnly): array
{
    $exam = managed_exam($admin, $examId);
    if ($exam === null) {
        return ['ok' => false, 'message' => 'That exam is not available.'];
    }
    if ($draftOnly && $exam['status'] !== 'draft') {
        return ['ok' => false, 'message' => 'Time slots can only be removed from the draft before the exam opens.'];
    }
    $ids = [];
    foreach ($timeslotIds as $timeslotId) {
        if (!is_string($timeslotId)) {
            continue;
        }
        $timeslotId = trim($timeslotId);
        if ($timeslotId !== '') {
            $ids[$timeslotId] = true;
        }
    }
    $ids = array_keys($ids);
    if ($ids === []) {
        return ['ok' => false, 'message' => 'Select at least one time slot.'];
    }
    $removed = 0;
    $blocked = 0;
    db_tx(static function () use ($examId, $ids, &$removed, &$blocked): void {
        foreach ($ids as $timeslotId) {
            $slot = db_one(
                'SELECT timeslot_id FROM exam_timeslot WHERE timeslot_id = ? AND exam_id = ?',
                [$timeslotId, $examId]
            );
            if ($slot === null) {
                continue;
            }
            if (timeslot_is_used($timeslotId)) {
                $blocked++;
                continue;
            }
            db_exec('DELETE FROM exam_timeslot WHERE timeslot_id = ? AND exam_id = ?', [$timeslotId, $examId]);
            $removed++;
        }
    });
    if ($removed < 1) {
        return ['ok' => false, 'message' => $blocked > 0
            ? 'A group has already used the selected time slots.'
            : 'Those time slots were not found.'];
    }
    touch_exam($examId);
    audit_write(
        $examId,
        null,
        null,
        (string) $admin['username'],
        'timeslots_removed',
        'Removed ' . $removed . ' time slots.'
    );
    $message = $removed === 1 ? 'Removed 1 time slot.' : 'Removed ' . $removed . ' time slots.';
    if ($blocked > 0) {
        $message .= ' ' . $blocked . ' stayed because a group has already used them.';
    }
    return ['ok' => true, 'message' => $message, 'exam_id' => $examId];
}

function move_group_action(array $admin, string $groupId, string $timeslotId): array
{
    $group = db_one('SELECT * FROM project_group WHERE group_id = ?', [$groupId]);
    if ($group === null) {
        return ['ok' => false, 'message' => 'That group was not found.'];
    }
    $exam = managed_exam($admin, (string) $group['exam_id']);
    if ($exam === null) {
        return ['ok' => false, 'message' => 'That exam is not available.'];
    }
    if ($exam['status'] === 'completed') {
        return ['ok' => false, 'message' => 'This exam is completed.'];
    }
    $round = latest_round((string) $exam['exam_id']);
    if ($round === null) {
        return ['ok' => false, 'message' => 'Add a round before assigning a time slot.'];
    }
    try {
        $message = db_tx(static function () use ($admin, $group, $exam, $round, $timeslotId): string {
            $slot = db_one('SELECT * FROM exam_timeslot WHERE timeslot_id = ? AND exam_id = ? FOR UPDATE', [$timeslotId, $exam['exam_id']]);
            if ($slot === null) {
                throw new RuntimeException('slot-missing');
            }
            $occupant = db_one(
                'SELECT a.group_id, g.display_name
                 FROM assignment a
                 JOIN project_group g ON g.group_id = a.group_id
                 WHERE a.timeslot_id = ? FOR UPDATE',
                [$timeslotId]
            );
            if ($occupant !== null && (string) $occupant['group_id'] !== (string) $group['group_id']) {
                throw new RuntimeException('taken:' . $occupant['display_name']);
            }
            $current = db_one('SELECT * FROM assignment WHERE group_id = ? FOR UPDATE', [$group['group_id']]);
            if ($current !== null && (string) $current['timeslot_id'] === $timeslotId) {
                return 'That group is already in this time slot.';
            }
            $now = hk_now()->format('Y-m-d H:i:s');
            if ($current !== null) {
                db_exec('UPDATE exam_timeslot SET status = "available" WHERE timeslot_id = ?', [$current['timeslot_id']]);
                db_exec(
                    'UPDATE assignment
                     SET round_id = ?, timeslot_id = ?, selected_choice_id = NULL, run_id = NULL, source = "manual",
                         accepted_at = ?, assigned_by_admin_username = ?
                     WHERE assignment_id = ?',
                    [$round['round_id'], $timeslotId, $now, $admin['username'], $current['assignment_id']]
                );
            } else {
                db_exec(
                    'INSERT INTO assignment
                        (assignment_id, round_id, group_id, timeslot_id, selected_choice_id, run_id, source, accepted_at, assigned_by_admin_username)
                     VALUES (?, ?, ?, ?, NULL, NULL, "manual", ?, ?)',
                    [uuid_v7(), $round['round_id'], $group['group_id'], $timeslotId, $now, $admin['username']]
                );
            }
            db_exec('UPDATE exam_timeslot SET status = "occupied" WHERE timeslot_id = ?', [$timeslotId]);
            $numbers = slot_number_map((string) $exam['exam_id']);
            $number = $numbers[$timeslotId] ?? 1;
            $verb = $current === null ? 'Assigned' : 'Moved';
            audit_write(
                (string) $exam['exam_id'],
                (string) $round['round_id'],
                null,
                (string) $admin['username'],
                'assignment_changed',
                $verb . ' ' . $group['display_name'] . ' to ' . hk_short((string) $slot['start_at']) . ', time slot ' . $number . '.'
            );
            touch_exam((string) $exam['exam_id']);
            return $verb . ' ' . $group['display_name'] . ' to ' . hk_short((string) $slot['start_at']) . ', time slot ' . $number . '.';
        });
    } catch (Throwable $e) {
        $text = $e->getMessage();
        if (str_starts_with($text, 'taken:')) {
            $name = substr($text, 6);
            return ['ok' => false, 'message' => $name . ' took this time slot a moment ago. Choose another time slot.', 'refresh' => true];
        }
        if (str_contains($text, 'uq_assignment_slot') || str_contains($text, 'uq_assignment_group')) {
            return ['ok' => false, 'message' => 'That time slot was just taken. Choose another time slot.', 'refresh' => true];
        }
        if ($text === 'slot-missing') {
            return ['ok' => false, 'message' => 'That time slot was not found.'];
        }
        throw $e;
    }
    return ['ok' => true, 'message' => $message, 'exam_id' => $exam['exam_id'], 'row' => $groupId];
}

function clear_group_action(array $admin, string $groupId): array
{
    $group = db_one('SELECT * FROM project_group WHERE group_id = ?', [$groupId]);
    if ($group === null) {
        return ['ok' => false, 'message' => 'That group was not found.'];
    }
    $exam = managed_exam($admin, (string) $group['exam_id']);
    if ($exam === null) {
        return ['ok' => false, 'message' => 'That exam is not available.'];
    }
    if ($exam['status'] === 'completed') {
        return ['ok' => false, 'message' => 'This exam is completed.'];
    }
    $assignment = db_one('SELECT * FROM assignment WHERE group_id = ?', [$groupId]);
    if ($assignment === null) {
        return ['ok' => false, 'message' => 'That group is already waiting.'];
    }
    db_tx(static function () use ($admin, $group, $exam, $assignment): void {
        db_exec('DELETE FROM assignment WHERE assignment_id = ?', [$assignment['assignment_id']]);
        db_exec('UPDATE exam_timeslot SET status = "available" WHERE timeslot_id = ?', [$assignment['timeslot_id']]);
        touch_exam((string) $exam['exam_id']);
        audit_write(
            (string) $exam['exam_id'],
            (string) $assignment['round_id'],
            null,
            (string) $admin['username'],
            'assignment_cleared',
            'Cleared the time slot for ' . $group['display_name'] . '.'
        );
    });
    return ['ok' => true, 'message' => 'Time slot cleared for ' . $group['display_name'] . '.', 'exam_id' => $exam['exam_id'], 'row' => $groupId];
}

function sole_creator_titles(string $username): array
{
    return array_map(
        static fn (array $row): string => (string) $row['title'],
        db_all(
            'SELECT e.title
             FROM exam e
             WHERE e.created_by_admin_username = ?
               AND EXISTS (
                    SELECT 1 FROM exam_admin ea
                    JOIN admin_user u ON u.username = ea.admin_username
                    WHERE ea.exam_id = e.exam_id AND ea.admin_username = ? AND u.is_active = 1
               )
               AND NOT EXISTS (
                    SELECT 1 FROM exam_admin ea
                    JOIN admin_user u ON u.username = ea.admin_username
                    WHERE ea.exam_id = e.exam_id AND ea.admin_username <> ? AND u.is_active = 1
               )',
            [$username, $username, $username]
        )
    );
}

function save_exam_basics(array $admin, ?string $examId, array $input): array
{
    $title = trim((string) ($input['title'] ?? ''));
    $subject = trim((string) ($input['subject'] ?? ''));
    $code = trim((string) ($input['exam_code'] ?? ''));
    $slotLength = (int) ($input['slot_length_min'] ?? 0);
    $limit = (int) ($input['preference_limit'] ?? 0);
    $mode = (string) ($input['acceptance_mode'] ?? '');
    $errors = [];
    if ($title === '') {
        $errors['title'] = 'Enter a title.';
    }
    if ($subject === '') {
        $errors['subject'] = 'Enter a subject.';
    }
    if ($code === '') {
        $errors['exam_code'] = 'Enter an entry code.';
    } elseif (preg_match('/\s/u', $code) === 1) {
        $errors['exam_code'] = 'The entry code cannot contain spaces.';
    } elseif (mb_strlen($code) > 64) {
        $errors['exam_code'] = 'Use an entry code of 64 characters or fewer.';
    }
    if ($slotLength < 1) {
        $errors['slot_length_min'] = 'Enter how many minutes one time slot lasts.';
    }
    if ($limit < 1) {
        $errors['preference_limit'] = 'A group must be able to list at least one choice.';
    }
    if (!in_array($mode, ['fcfs', 'sealed'], true)) {
        $errors['acceptance_mode'] = 'Choose how a time slot is taken.';
    }
    if ($code !== '' && !isset($errors['exam_code'])) {
        $clash = db_one('SELECT exam_id FROM exam WHERE exam_code = ? AND exam_id <> ?', [$code, $examId ?? '']);
        if ($clash !== null) {
            $errors['exam_code'] = 'That entry code is already used.';
        }
    }
    $existing = null;
    if ($examId !== null) {
        $existing = managed_exam($admin, $examId);
        if ($existing === null) {
            return ['ok' => false, 'message' => 'That exam is not available.'];
        }
        if ($existing['status'] !== 'draft') {
            return ['ok' => false, 'message' => 'This exam is already set up.'];
        }
        $submissions = (int) db_value(
            'SELECT COUNT(*) FROM preference_submission ps JOIN exam_round r ON r.round_id = ps.round_id WHERE r.exam_id = ?',
            [$examId]
        );
        if ($submissions > 0 && $mode !== (string) $existing['acceptance_mode']) {
            $errors['acceptance_mode'] = 'Groups have already submitted choices, so this cannot change.';
        }
    }
    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors, 'message' => 'Check the highlighted fields.'];
    }
    $now = hk_now()->format('Y-m-d H:i:s');
    if ($existing === null) {
        $examId = uuid_v7();
        db_tx(static function () use ($admin, $examId, $code, $title, $subject, $slotLength, $limit, $mode, $now): void {
            db_exec(
                'INSERT INTO exam
                    (exam_id, exam_code, title, subject, slot_length_min, preference_limit, acceptance_mode, status, created_by_admin_username, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, "draft", ?, ?)',
                [$examId, $code, $title, $subject, $slotLength, $limit, $mode, $admin['username'], $now]
            );
            db_exec(
                'INSERT INTO exam_admin (exam_id, admin_username, granted_by_admin_username, granted_at) VALUES (?, ?, ?, ?)',
                [$examId, $admin['username'], $admin['username'], $now]
            );
        });
        audit_write($examId, null, null, (string) $admin['username'], 'exam_created', 'Created ' . $title . ' (' . $code . ').');
        return ['ok' => true, 'message' => 'Draft saved.', 'exam_id' => $examId];
    }
    db_exec(
        'UPDATE exam
         SET exam_code = ?, title = ?, subject = ?, slot_length_min = ?, preference_limit = ?, acceptance_mode = ?, updated_at = ?
         WHERE exam_id = ?',
        [$code, $title, $subject, $slotLength, $limit, $mode, $now, $examId]
    );
    audit_write((string) $examId, null, null, (string) $admin['username'], 'exam_updated', 'Updated the details for ' . $title . '.');
    return ['ok' => true, 'message' => 'Draft saved.', 'exam_id' => $examId];
}

function grant_admin_action(array $admin, string $examId, string $username): array
{
    $exam = managed_exam($admin, $examId);
    if ($exam === null) {
        return ['ok' => false, 'message' => 'That exam is not available.'];
    }
    if (!can_grant_access($admin, $exam)) {
        return ['ok' => false, 'message' => 'Only the person who created this exam, or a professor, can add another admin.'];
    }
    $username = normalize_teacher_username($username);
    $target = db_one('SELECT * FROM admin_user WHERE username = ? AND is_active = 1', [$username]);
    if ($target === null) {
        return ['ok' => false, 'message' => 'No other active admin has that name.'];
    }
    $exists = db_one('SELECT admin_username FROM exam_admin WHERE exam_id = ? AND admin_username = ?', [$examId, $username]);
    if ($exists !== null) {
        return ['ok' => true, 'message' => $target['display_name'] . ' can already manage this exam.', 'exam_id' => $examId];
    }
    $now = hk_now()->format('Y-m-d H:i:s');
    db_exec(
        'INSERT INTO exam_admin (exam_id, admin_username, granted_by_admin_username, granted_at) VALUES (?, ?, ?, ?)',
        [$examId, $username, $admin['username'], $now]
    );
    audit_write(
        $examId,
        null,
        null,
        (string) $admin['username'],
        'admin_granted',
        $target['display_name'] . ' can manage this exam.'
    );
    return ['ok' => true, 'message' => $target['display_name'] . ' can manage this exam.', 'exam_id' => $examId, 'row' => $username];
}

function revoke_admin_action(array $admin, string $examId, string $username): array
{
    $exam = managed_exam($admin, $examId);
    if ($exam === null) {
        return ['ok' => false, 'message' => 'That exam is not available.'];
    }
    if (!can_grant_access($admin, $exam)) {
        return ['ok' => false, 'message' => 'Only the person who created this exam, or a professor, can remove an admin.'];
    }
    if ($username === (string) $exam['created_by_admin_username']) {
        return ['ok' => false, 'message' => 'The person who created this exam must keep access.'];
    }
    $target = db_one('SELECT display_name FROM admin_user WHERE username = ?', [$username]);
    db_exec('DELETE FROM exam_admin WHERE exam_id = ? AND admin_username = ?', [$examId, $username]);
    audit_write(
        $examId,
        null,
        null,
        (string) $admin['username'],
        'admin_revoked',
        ($target['display_name'] ?? $username) . ' can no longer manage this exam.'
    );
    $redirect = $username === (string) $admin['username'] ? 'admin/exams.php' : null;
    return [
        'ok' => true,
        'message' => ($target['display_name'] ?? $username) . ' will no longer see ' . $exam['title'] . '.',
        'exam_id' => $examId,
        'redirect' => $redirect,
    ];
}

function editable_exam_group(array $admin, string $groupId): array
{
    if (!is_uuid($groupId)) {
        return ['ok' => false, 'message' => 'That group was not found.'];
    }
    $group = db_one('SELECT * FROM project_group WHERE group_id = ?', [$groupId]);
    if ($group === null) {
        return ['ok' => false, 'message' => 'That group was not found.'];
    }
    $exam = managed_exam($admin, (string) $group['exam_id']);
    if ($exam === null) {
        return ['ok' => false, 'message' => 'That exam is not available.'];
    }
    if ($exam['status'] === 'completed') {
        return ['ok' => false, 'message' => 'This exam is completed.'];
    }
    return ['ok' => true, 'group' => $group, 'exam' => $exam];
}

function rename_group_action(array $admin, string $groupId, string $name): array
{
    $context = editable_exam_group($admin, $groupId);
    if (!$context['ok']) {
        return $context;
    }
    $name = trim($name);
    if ($name === '') {
        return ['ok' => false, 'errors' => ['display_name' => 'Enter a group name.'], 'message' => 'Enter a group name.'];
    }
    if (mb_strlen($name) > 248) {
        return ['ok' => false, 'errors' => ['display_name' => 'Use a group name of 248 characters or fewer.'], 'message' => 'Use a shorter group name.'];
    }
    db_exec('UPDATE project_group SET display_name = ? WHERE group_id = ?', [$name, $groupId]);
    touch_exam((string) $context['exam']['exam_id']);
    audit_write(
        (string) $context['exam']['exam_id'],
        null,
        null,
        (string) $admin['username'],
        'group_updated',
        'Renamed the group to ' . $name . '.'
    );
    return ['ok' => true, 'message' => 'Group name saved.', 'row' => $groupId];
}

function add_member_action(array $admin, string $groupId, string $studentId): array
{
    $context = editable_exam_group($admin, $groupId);
    if (!$context['ok']) {
        return $context;
    }
    $studentId = trim($studentId);
    if ($studentId === '') {
        return ['ok' => false, 'errors' => ['student_id' => 'Choose a student.'], 'message' => 'Choose a student.'];
    }
    $examId = (string) $context['exam']['exam_id'];
    $student = db_one(
        'SELECT student_id, display_name FROM student_account WHERE student_id = ? AND exam_id = ?',
        [$studentId, $examId]
    );
    if ($student === null) {
        return ['ok' => false, 'message' => 'That student is not in this exam.'];
    }
    $other = db_one(
        'SELECT g.group_id, g.display_name
         FROM group_member gm
         JOIN project_group g ON g.group_id = gm.group_id
         WHERE gm.exam_id = ? AND gm.student_id = ?',
        [$examId, $studentId]
    );
    if ($other !== null) {
        $where = (string) $other['group_id'] === $groupId ? (string) $context['group']['display_name'] : (string) $other['display_name'];
        return ['ok' => false, 'message' => $student['display_name'] . ' is already in ' . $where . '.'];
    }
    try {
        db_exec(
            'INSERT INTO group_member (group_id, exam_id, student_id, joined_at) VALUES (?, ?, ?, ?)',
            [$groupId, $examId, $studentId, hk_now()->format('Y-m-d H:i:s')]
        );
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'uq_exam_student_group')) {
            return ['ok' => false, 'message' => $student['display_name'] . ' is already in another group.'];
        }
        throw $e;
    }
    touch_exam($examId);
    audit_write(
        $examId,
        null,
        null,
        (string) $admin['username'],
        'group_updated',
        'Added ' . $student['display_name'] . ' to ' . $context['group']['display_name'] . '.'
    );
    return ['ok' => true, 'message' => $student['display_name'] . ' is now in ' . $context['group']['display_name'] . '.', 'row' => $groupId];
}

function remove_member_action(array $admin, string $groupId, string $studentId): array
{
    $context = editable_exam_group($admin, $groupId);
    if (!$context['ok']) {
        return $context;
    }
    $count = (int) db_value('SELECT COUNT(*) FROM group_member WHERE group_id = ?', [$groupId]);
    if ($count <= 1) {
        return ['ok' => false, 'message' => 'This group needs at least one member.'];
    }
    $student = db_one(
        'SELECT sa.display_name
         FROM group_member gm
         JOIN student_account sa ON sa.student_id = gm.student_id AND sa.exam_id = gm.exam_id
         WHERE gm.group_id = ? AND gm.student_id = ?',
        [$groupId, $studentId]
    );
    if ($student === null) {
        return ['ok' => false, 'message' => 'That student is not in this group.'];
    }
    db_exec('DELETE FROM group_member WHERE group_id = ? AND student_id = ?', [$groupId, $studentId]);
    touch_exam((string) $context['exam']['exam_id']);
    audit_write(
        (string) $context['exam']['exam_id'],
        null,
        null,
        (string) $admin['username'],
        'group_updated',
        'Removed ' . $student['display_name'] . ' from ' . $context['group']['display_name'] . '.'
    );
    return ['ok' => true, 'message' => $student['display_name'] . ' was removed from ' . $context['group']['display_name'] . '.', 'row' => $groupId];
}

function set_student_active_action(array $admin, string $examId, string $studentId, bool $active, string $typed): array
{
    $exam = managed_exam($admin, $examId);
    if ($exam === null) {
        return ['ok' => false, 'message' => 'That exam is not available.'];
    }
    $student = db_one(
        'SELECT student_id, display_name, is_active FROM student_account WHERE student_id = ? AND exam_id = ?',
        [$studentId, $examId]
    );
    if ($student === null) {
        return ['ok' => false, 'message' => 'That student is not in this exam.'];
    }
    if (!$active && $typed !== $studentId) {
        return [
            'ok' => false,
            'errors' => ['confirm_code' => 'Type the student ID to continue.'],
            'message' => 'Type the student ID to continue.',
        ];
    }
    $now = hk_now()->format('Y-m-d H:i:s');
    db_exec(
        'UPDATE student_account SET is_active = ?, updated_at = ? WHERE student_id = ? AND exam_id = ?',
        [$active ? 1 : 0, $now, $studentId, $examId]
    );
    touch_exam($examId);
    audit_write(
        $examId,
        null,
        null,
        (string) $admin['username'],
        $active ? 'account_enabled' : 'account_disabled',
        ($active ? 'Enabled ' : 'Disabled ') . $student['display_name'] . ' (' . $studentId . ') for this exam.'
    );
    $message = $active
        ? $student['display_name'] . ' can sign in to this exam again.'
        : $student['display_name'] . ' can no longer sign in to this exam.';
    return ['ok' => true, 'message' => $message, 'row' => $studentId];
}
