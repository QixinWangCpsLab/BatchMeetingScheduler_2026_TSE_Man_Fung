<?php
declare(strict_types=1);

if (!defined('BMS_APP')) {
    http_response_code(404);
    exit;
}

function plan_timeslots(
    string $startDate,
    string $endDate,
    string $dailyStart,
    string $dailyEnd,
    int $slotMinutes,
    int $chairs
): array {
    $errors = [];
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, hk_zone());
    $end = DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, hk_zone());
    $dayStart = DateTimeImmutable::createFromFormat('!H:i', $dailyStart, hk_zone());
    $dayEnd = DateTimeImmutable::createFromFormat('!H:i', $dailyEnd, hk_zone());
    if (!$start || !$end) {
        $errors[] = 'Choose a start date and an end date.';
    } elseif ($end < $start) {
        $errors[] = 'The end date must be on or after the start date.';
    }
    if (!$dayStart || !$dayEnd) {
        $errors[] = 'Choose the daily start and daily end.';
    } elseif ($dayEnd <= $dayStart) {
        $errors[] = 'The daily end must be after the daily start.';
    }
    if ($slotMinutes < 1) {
        $errors[] = 'Enter the length of one time slot.';
    }
    if ($chairs < 1) {
        $errors[] = 'Enter at least one chair.';
    }
    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors, 'clocks' => 0, 'total' => 0, 'rows' => []];
    }

    $rows = [];
    $clocks = 0;
    for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
        $cursor = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $day->format('Y-m-d') . ' ' . $dayStart->format('H:i'),
            hk_zone()
        );
        $limit = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $day->format('Y-m-d') . ' ' . $dayEnd->format('H:i'),
            hk_zone()
        );
        if (!$cursor || !$limit) {
            continue;
        }
        while (true) {
            $slotEnd = $cursor->modify('+' . $slotMinutes . ' minutes');
            if ($slotEnd > $limit) {
                break;
            }
            $clocks++;
            for ($chair = 0; $chair < $chairs; $chair++) {
                $rows[] = [
                    'start_at' => $cursor->format('Y-m-d H:i:s'),
                    'end_at' => $slotEnd->format('Y-m-d H:i:s'),
                ];
            }
            $cursor = $slotEnd;
        }
    }

    return [
        'ok' => true,
        'errors' => [],
        'clocks' => $clocks,
        'chairs' => $chairs,
        'total' => count($rows),
        'rows' => $rows,
        'message' => timeslot_plan_message($clocks, $chairs, count($rows)),
    ];
}

function timeslot_plan_message(int $clocks, int $chairs, int $total): string
{
    if ($total < 1) {
        return 'No time slots fall in that range. Check the daily start and end.';
    }
    return 'This will add ' . $total . ' time slots. ' . $clocks . ' clock times, ' . $chairs . ' time slots each.';
}

function slot_number_map(string $examId): array
{
    $rows = db_all(
        'SELECT timeslot_id, start_at FROM exam_timeslot WHERE exam_id = ? ORDER BY start_at ASC, timeslot_id ASC',
        [$examId]
    );
    $seen = [];
    $map = [];
    foreach ($rows as $row) {
        $key = (string) $row['start_at'];
        $seen[$key] = ($seen[$key] ?? 0) + 1;
        $map[(string) $row['timeslot_id']] = $seen[$key];
    }
    return $map;
}

function timeslot_is_used(string $timeslotId): bool
{
    $choices = (int) db_value('SELECT COUNT(*) FROM preference_choice WHERE timeslot_id = ?', [$timeslotId]);
    $assigned = (int) db_value('SELECT COUNT(*) FROM assignment WHERE timeslot_id = ?', [$timeslotId]);
    return $choices > 0 || $assigned > 0;
}
