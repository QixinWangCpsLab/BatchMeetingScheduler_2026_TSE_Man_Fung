<?php
declare(strict_types=1);

if (!defined('BMS_APP')) {
    http_response_code(404);
    exit;
}

function excel_string(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_int($value)) {
        return (string) $value;
    }
    if (is_float($value)) {
        if (floor($value) === $value && abs($value) < 1.0e15) {
            return sprintf('%.0f', $value);
        }
        return trim((string) $value);
    }
    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }
    return trim((string) $value);
}

function header_key(string $header): string
{
    $header = strtolower(trim(preg_replace('/\s+/u', ' ', $header) ?? $header));
    return $header;
}

function is_import_note(array $cells): bool
{
    $blob = strtolower(implode(' ', array_map(static fn ($cell) => excel_string($cell), $cells)));
    return str_contains($blob, 'same id will be a group') || str_contains($blob, 'if empty, will be');
}

function read_xlsx_matrix(string $path): array
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(true);
    $sheet = $reader->load($path)->getActiveSheet();
    $rows = [];
    foreach ($sheet->getRowIterator() as $row) {
        $cells = [];
        $iterator = $row->getCellIterator();
        $iterator->setIterateOnlyExistingCells(false);
        foreach ($iterator as $cell) {
            $cells[] = $cell->getValue();
        }
        $rows[] = $cells;
    }
    return $rows;
}

/**
 * @param list<list<mixed>> $matrix
 * @param list<string> $existingStudentIds
 * @param list<array{group_id: string, display_name: string, member_count: int, student_id: string, student_name: string}> $existingGroups
 */
function classify_import(array $matrix, array $existingStudentIds, array $existingGroups): array
{
    if ($matrix === []) {
        return ['ok' => false, 'message' => 'The file is empty. Use one of the example files.', 'rows' => [], 'renames' => [], 'kind' => ''];
    }
    $header = array_map(static fn ($cell) => header_key(excel_string($cell)), $matrix[0]);
    $find = static function (string $name) use ($header): int {
        $index = array_search($name, $header, true);
        return $index === false ? -1 : (int) $index;
    };
    $idCol = $find('student id');
    $nameCol = $find('student name');
    $emailCol = $find('student email');
    $groupCol = $find('group id');
    $groupNameCol = $find('group name');
    if ($idCol < 0 || $nameCol < 0 || $emailCol < 0) {
        return [
            'ok' => false,
            'message' => 'Use the group example or the individual example. The first row must be the column titles.',
            'rows' => [],
            'renames' => [],
            'kind' => '',
        ];
    }
    $kind = $groupCol >= 0 ? 'group' : 'individual';
    $existing = [];
    foreach ($existingStudentIds as $studentId) {
        $existing[(string) $studentId] = true;
    }
    $seen = [];
    $rows = [];
    $line = 1;
    foreach (array_slice($matrix, 1) as $cells) {
        $line++;
        $values = array_map(static fn ($cell) => excel_string($cell), $cells);
        $joined = trim(implode('', $values));
        if ($joined === '' || is_import_note($cells)) {
            continue;
        }
        $studentId = $values[$idCol] ?? '';
        $name = $values[$nameCol] ?? '';
        $email = $values[$emailCol] ?? '';
        $groupKey = $kind === 'group' ? ($values[$groupCol] ?? '') : $studentId;
        $groupName = $kind === 'group' && $groupNameCol >= 0 ? ($values[$groupNameCol] ?? '') : '';
        $result = '';
        if ($studentId === '') {
            $result = 'Missing student ID';
        } elseif ($name === '') {
            $result = 'Missing name';
        } elseif ($email === '') {
            $result = 'Missing email';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $result = 'Enter a valid email address.';
        } elseif ($kind === 'group' && $groupKey === '') {
            $result = 'Missing Group ID';
        } elseif (isset($existing[$studentId]) || isset($seen[$studentId])) {
            $result = 'Duplicate student in this exam';
        }
        if ($studentId !== '') {
            $seen[$studentId] = true;
        }
        $rows[] = [
            'line' => $line,
            'student_id' => $studentId,
            'name' => $name,
            'email' => $email,
            'group_key' => $groupKey,
            'group_name' => $groupName,
            'ready' => $result === '',
            'result' => $result === '' ? 'Ready' : $result,
        ];
    }

    if ($kind === 'group') {
        $namesByKey = [];
        foreach ($rows as $row) {
            if ($row['group_key'] === '' || $row['group_name'] === '') {
                continue;
            }
            if (!isset($namesByKey[$row['group_key']])) {
                $namesByKey[$row['group_key']] = $row['group_name'];
            }
        }
        foreach ($rows as &$row) {
            if (!$row['ready']) {
                continue;
            }
            $row['group_name'] = $namesByKey[$row['group_key']] ?? ('Group ' . $row['group_key']);
        }
        unset($row);
        return ['ok' => true, 'message' => '', 'rows' => $rows, 'renames' => [], 'kind' => $kind];
    }

    $readyNames = [];
    foreach ($rows as $row) {
        if ($row['ready']) {
            $readyNames[$row['name']] = ($readyNames[$row['name']] ?? 0) + 1;
        }
    }
    $existingByName = [];
    foreach ($existingGroups as $group) {
        $existingByName[$group['display_name']][] = $group;
    }
    $renames = [];
    foreach ($rows as &$row) {
        if (!$row['ready']) {
            $row['group_name'] = $row['name'];
            continue;
        }
        $collides = ($readyNames[$row['name']] ?? 0) > 1 || isset($existingByName[$row['name']]);
        if ($collides) {
            $row['group_name'] = $row['name'] . ' (' . $row['student_id'] . ')';
            $row['result'] = 'Ready. Same name, so the group name includes the student ID.';
        } else {
            $row['group_name'] = $row['name'];
        }
    }
    unset($row);
    foreach ($readyNames as $name => $count) {
        if ($count < 1 || !isset($existingByName[$name])) {
            if ($count < 2) {
                continue;
            }
        }
        if ($count < 2 && !isset($existingByName[$name])) {
            continue;
        }
        foreach ($existingByName[$name] ?? [] as $group) {
            if ((int) $group['member_count'] !== 1) {
                continue;
            }
            if ($group['student_name'] !== $name || $group['student_id'] === '') {
                continue;
            }
            $renames[] = [
                'group_id' => $group['group_id'],
                'display_name' => $name . ' (' . $group['student_id'] . ')',
            ];
        }
    }

    return ['ok' => true, 'message' => '', 'rows' => $rows, 'renames' => $renames, 'kind' => $kind];
}

function import_ready_count(array $rows): int
{
    $count = 0;
    foreach ($rows as $row) {
        if (!empty($row['ready'])) {
            $count++;
        }
    }
    return $count;
}

function store_import_preview(string $examId, array $preview): void
{
    if (!isset($_SESSION['import']) || !is_array($_SESSION['import'])) {
        $_SESSION['import'] = [];
    }
    $_SESSION['import'][$examId] = $preview;
}

function take_import_preview(string $examId): ?array
{
    $preview = $_SESSION['import'][$examId] ?? null;
    return is_array($preview) ? $preview : null;
}

function clear_import_preview(string $examId): void
{
    unset($_SESSION['import'][$examId]);
}

function existing_import_context(string $examId): array
{
    $ids = array_map(
        static fn (array $row): string => (string) $row['student_id'],
        db_all('SELECT student_id FROM student_account WHERE exam_id = ?', [$examId])
    );
    $groups = db_all(
        'SELECT g.group_id, g.display_name,
                (SELECT COUNT(*) FROM group_member gm WHERE gm.group_id = g.group_id) AS member_count,
                (SELECT gm.student_id FROM group_member gm WHERE gm.group_id = g.group_id LIMIT 1) AS student_id,
                (SELECT sa.display_name FROM group_member gm
                    JOIN student_account sa ON sa.student_id = gm.student_id AND sa.exam_id = gm.exam_id
                    WHERE gm.group_id = g.group_id LIMIT 1) AS student_name
         FROM project_group g
         WHERE g.exam_id = ?',
        [$examId]
    );
    foreach ($groups as &$group) {
        $group['member_count'] = (int) $group['member_count'];
        $group['student_id'] = (string) ($group['student_id'] ?? '');
        $group['student_name'] = (string) ($group['student_name'] ?? '');
    }
    unset($group);
    return [$ids, $groups];
}

function commit_import(string $examId, string $adminUsername, array $preview): array
{
    $rows = $preview['rows'] ?? [];
    $renames = $preview['renames'] ?? [];
    $kind = (string) ($preview['kind'] ?? 'individual');
    if (!is_array($rows)) {
        return ['imported' => 0, 'skipped' => 0, 'groups' => 0];
    }
    $now = hk_now()->format('Y-m-d H:i:s');
    $imported = 0;
    $skipped = 0;
    $groupIds = [];
    db_tx(static function () use ($examId, $adminUsername, $rows, $renames, $kind, $now, &$imported, &$skipped, &$groupIds): void {
        foreach ($renames as $rename) {
            if (!is_uuid((string) ($rename['group_id'] ?? ''))) {
                continue;
            }
            db_exec(
                'UPDATE project_group SET display_name = ? WHERE group_id = ? AND exam_id = ?',
                [(string) $rename['display_name'], (string) $rename['group_id'], $examId]
            );
        }
        $ready = array_values(array_filter($rows, static fn ($row) => !empty($row['ready'])));
        $buckets = [];
        foreach ($ready as $row) {
            $key = $kind === 'group' ? (string) $row['group_key'] : (string) $row['student_id'];
            $buckets[$key][] = $row;
        }
        foreach ($buckets as $bucket) {
            $groupId = uuid_v7();
            $groupName = (string) ($bucket[0]['group_name'] ?? 'Group');
            $members = [];
            foreach ($bucket as $row) {
                $studentId = (string) $row['student_id'];
                $exists = db_value(
                    'SELECT COUNT(*) FROM student_account WHERE exam_id = ? AND student_id = ?',
                    [$examId, $studentId]
                );
                if ((int) $exists > 0) {
                    $skipped++;
                    continue;
                }
                db_exec(
                    'INSERT INTO student_account
                        (student_id, exam_id, display_name, email, password_hash, is_active, created_at, updated_at, created_by_admin_username)
                     VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)',
                    [
                        $studentId,
                        $examId,
                        (string) $row['name'],
                        (string) $row['email'],
                        unknown_password_hash(),
                        $now,
                        $now,
                        $adminUsername,
                    ]
                );
                $members[] = $studentId;
                $imported++;
            }
            if ($members === []) {
                continue;
            }
            db_exec(
                'INSERT INTO project_group (group_id, exam_id, display_name) VALUES (?, ?, ?)',
                [$groupId, $examId, $groupName]
            );
            foreach ($members as $studentId) {
                db_exec(
                    'INSERT INTO group_member (group_id, exam_id, student_id, joined_at) VALUES (?, ?, ?, ?)',
                    [$groupId, $examId, $studentId, $now]
                );
            }
            $groupIds[] = $groupId;
        }
    });
    if ($imported > 0) {
        touch_exam($examId);
        audit_write(
            $examId,
            null,
            null,
            $adminUsername,
            'student_imported',
            'Imported ' . $imported . ' students into ' . count($groupIds) . ' groups.'
        );
    }
    return ['imported' => $imported, 'skipped' => $skipped, 'groups' => count($groupIds)];
}
