<?php
declare(strict_types=1);

if (!defined('BMS_APP')) {
    http_response_code(404);
    exit;
}

function seeded_rng(int $seed): Closure
{
    $state = $seed & 0x7fffffff;
    if ($state === 0) {
        $state = 1;
    }
    return static function () use (&$state): int {
        $state ^= ($state << 13) & 0x7fffffff;
        $state ^= ($state >> 17);
        $state ^= ($state << 5) & 0x7fffffff;
        $state &= 0x7fffffff;
        return $state;
    };
}

function seeded_shuffle(array $items, int $seed): array
{
    $rng = seeded_rng($seed);
    $count = count($items);
    for ($i = $count - 1; $i > 0; $i--) {
        $j = $rng() % ($i + 1);
        $swap = $items[$i];
        $items[$i] = $items[$j];
        $items[$j] = $swap;
    }
    return $items;
}

/**
 * @param list<string> $groupIds
 * @param array<string, list<array{choice_id: string, timeslot_id: string, priority: int}>> $choicesByGroup
 * @param array<string, true> $freeSlots
 * @return array{assigned: list<array{group_id: string, timeslot_id: string, choice_id: string}>, unsatisfied: list<string>, no_choice: list<string>}
 */
function sealed_plan(array $groupIds, array $choicesByGroup, array $freeSlots, int $seed): array
{
    $order = seeded_shuffle(array_values($groupIds), $seed);
    $taken = [];
    $assigned = [];
    $unsatisfied = [];
    $noChoice = [];
    foreach ($order as $groupId) {
        $choices = $choicesByGroup[$groupId] ?? [];
        if ($choices === []) {
            $noChoice[] = $groupId;
            continue;
        }
        usort($choices, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);
        $placed = false;
        foreach ($choices as $choice) {
            $slotId = $choice['timeslot_id'];
            if (!isset($freeSlots[$slotId]) || isset($taken[$slotId])) {
                continue;
            }
            $taken[$slotId] = true;
            $assigned[] = [
                'group_id' => $groupId,
                'timeslot_id' => $slotId,
                'choice_id' => $choice['choice_id'],
            ];
            $placed = true;
            break;
        }
        if (!$placed) {
            $unsatisfied[] = $groupId;
        }
    }
    return ['assigned' => $assigned, 'unsatisfied' => $unsatisfied, 'no_choice' => $noChoice];
}

function random_seed(): int
{
    return random_int(1, 2147483646);
}

function parse_seed(string $value): ?int
{
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^\d+$/', $value)) {
        return null;
    }
    $seed = (int) $value;
    if ($seed < 1 || $seed > 2147483646) {
        return null;
    }
    return $seed;
}
