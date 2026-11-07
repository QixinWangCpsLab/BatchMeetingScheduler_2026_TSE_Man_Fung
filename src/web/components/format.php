<?php
declare(strict_types=1);

if (!defined('BMS_APP')) {
    http_response_code(404);
    exit;
}

function hk_zone(): DateTimeZone
{
    return new DateTimeZone('Asia/Hong_Kong');
}

function hk_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', hk_zone());
}

function hk_parse(?string $value): ?DateTimeImmutable
{
    if ($value === null || trim($value) === '') {
        return null;
    }
    $value = trim($value);
    $formats = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d'];
    foreach ($formats as $format) {
        $parsed = DateTimeImmutable::createFromFormat($format, $value, hk_zone());
        if ($parsed instanceof DateTimeImmutable) {
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                return $parsed;
            }
        }
    }
    return null;
}

function hk_store(?string $value): ?string
{
    $parsed = hk_parse($value);
    return $parsed?->format('Y-m-d H:i:s');
}

function hk_input(?string $value): string
{
    $parsed = hk_parse($value);
    return $parsed ? $parsed->format('Y-m-d\TH:i') : '';
}

function hk_full(?string $value): string
{
    $parsed = hk_parse($value);
    return $parsed ? $parsed->format('j M Y, H:i') : '—';
}

function hk_short(?string $value): string
{
    $parsed = hk_parse($value);
    return $parsed ? $parsed->format('j M, H:i') : '—';
}

function hk_day(?string $value): string
{
    $parsed = hk_parse($value);
    return $parsed ? $parsed->format('j M Y') : '—';
}

function hk_time(?string $value): string
{
    $parsed = hk_parse($value);
    return $parsed ? $parsed->format('H:i') : '—';
}

function hk_clock(?string $start, ?string $end): string
{
    $from = hk_parse($start);
    $to = hk_parse($end);
    if (!$from || !$to) {
        return '—';
    }
    return $from->format('H:i') . '–' . $to->format('H:i');
}

function slot_phrase(?string $start, ?int $number, string $empty = 'Waiting'): string
{
    if ($start === null || $start === '') {
        return $empty;
    }
    $text = hk_short($start);
    if ($number !== null && $number > 0) {
        $text .= ', time slot ' . $number;
    }
    return $text;
}

function hk_range(?string $start, ?string $end): string
{
    $from = hk_parse($start);
    $to = hk_parse($end);
    if (!$from || !$to) {
        return '—';
    }
    if ($from->format('Y-m-d') === $to->format('Y-m-d')) {
        return $from->format('j M Y, H:i') . '–' . $to->format('H:i');
    }
    return hk_full($start) . '–' . hk_full($end);
}

function first_sentence(string $detail): string
{
    $detail = trim(preg_replace('/\s+/u', ' ', $detail) ?? $detail);
    if (preg_match('/^(.+?[.!?])(?:\s|$)/u', $detail, $matches) === 1) {
        return $matches[1];
    }
    return $detail;
}

function role_label(string $role): string
{
    return match ($role) {
        'ta' => 'TA',
        'teacher' => 'Teacher',
        'prof' => 'Professor',
        default => $role,
    };
}

function mode_label(string $mode): string
{
    return match ($mode) {
        'fcfs' => 'First come, first served',
        'sealed' => 'Later acceptance',
        default => $mode,
    };
}

function mode_sentence(string $mode): string
{
    return $mode === 'sealed'
        ? 'No time slot is given until you accept the lists after the deadline.'
        : 'Time slots are taken as soon as a group submits.';
}

function round_label(string $status): string
{
    return match ($status) {
        'pending' => 'Not open',
        'open' => 'Open',
        'closed' => 'Closed',
        'matched' => 'All groups assigned',
        default => $status,
    };
}

function exam_label(string $status): string
{
    return match ($status) {
        'draft' => 'Draft',
        'active' => 'Active',
        'completed' => 'Completed',
        default => $status,
    };
}

function slot_label(string $status): string
{
    return $status === 'occupied' ? 'Occupied' : 'Available';
}

function source_label(string $source): string
{
    return match ($source) {
        'fcfs' => 'Accepted when submitted',
        'sealed' => 'Accepted after deadline',
        'manual' => 'Moved by admin',
        default => $source,
    };
}

function delivery_label(string $status): string
{
    return match ($status) {
        'pending' => 'Sending',
        'sent' => 'Sent',
        'failed' => 'Failed',
        'downloaded' => 'Downloaded',
        default => $status,
    };
}

function submission_label(string $status): string
{
    return $status === 'current' ? 'Latest' : 'Replaced';
}

function account_label(mixed $active): string
{
    return (int) $active === 1 ? 'Active' : 'Disabled';
}

function event_label(string $type): string
{
    return match ($type) {
        'exam_created' => 'Exam created',
        'exam_updated' => 'Exam details updated',
        'exam_completed' => 'Exam marked completed',
        'exam_deleted' => 'Draft deleted',
        'round_opened' => 'Round opened',
        'round_closed' => 'Round closed',
        'round_time_changed' => 'Round time changed',
        'round_created' => 'Round added',
        'sealed_accepted' => 'Preferences accepted',
        'assignment_changed' => 'Time slot moved',
        'assignment_cleared' => 'Time slot cleared',
        'student_imported' => 'Students imported',
        'credential_sent' => 'Entry email sent',
        'credential_resent' => 'Entry email resent',
        'credential_downloaded' => 'Credentials downloaded',
        'admin_granted' => 'Admin access granted',
        'admin_revoked' => 'Admin access removed',
        'account_disabled' => 'Account disabled',
        'account_enabled' => 'Account enabled',
        'account_created' => 'Admin account created',
        'profile_updated' => 'Profile updated',
        'password_changed' => 'Password updated',
        'timeslots_added' => 'Time slots added',
        'timeslot_removed' => 'Time slot removed',
        'group_updated' => 'Group updated',
        default => ucfirst(str_replace('_', ' ', $type)),
    };
}

function ordinal_choice(int $priority): string
{
    $suffix = 'th';
    if ($priority % 100 < 11 || $priority % 100 > 13) {
        $suffix = match ($priority % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }
    return $priority . $suffix . ' choice';
}

function badge(string $tone, string $label): string
{
    return '<span class="status-badge tone-' . e($tone) . '">' . e($label) . '</span>';
}

function exam_badge(string $status): string
{
    return badge($status, exam_label($status));
}

function round_badge(string $status): string
{
    return badge('round-' . $status, round_label($status));
}

function mode_badge(string $mode): string
{
    return badge('mode-' . $mode, mode_label($mode));
}

function slot_badge(string $status): string
{
    return badge('slot-' . $status, slot_label($status));
}

function source_badge(string $source): string
{
    return badge('source-' . $source, source_label($source));
}

function delivery_badge(string $status): string
{
    return badge('delivery-' . $status, delivery_label($status));
}

function account_badge(mixed $active): string
{
    $on = (int) $active === 1;
    return badge($on ? 'account-on' : 'account-off', account_label($active));
}

function submission_badge(string $status): string
{
    return badge('submission-' . $status, submission_label($status));
}

function role_badge(string $role): string
{
    return '<span class="role-badge">' . e(role_label($role)) . '</span>';
}

function member_brief(array $names): string
{
    $names = array_values(array_filter($names, static fn ($name) => $name !== ''));
    $count = count($names);
    if ($count === 0) {
        return 'No members';
    }
    if ($count <= 2) {
        return implode(', ', $names);
    }
    return $names[0] . ', ' . $names[1] . ' +' . ($count - 2);
}

function group_members_label(int $count, string $onlyName): string
{
    if ($count <= 0) {
        return 'No members';
    }
    if ($count === 1) {
        return $onlyName !== '' ? $onlyName : '1 member';
    }
    return $count . ' members';
}

function empty_state(string $message, ?string $label = null, ?string $href = null): string
{
    $html = '<div class="empty-state"><p>' . e($message) . '</p>';
    if ($label !== null && $href !== null) {
        $html .= '<a class="btn btn-poly" href="' . e($href) . '">' . e($label) . '</a>';
    }
    $html .= '</div>';
    return $html;
}

function field_wrap(string $label, string $id, string $control, string $help = '', string $error = ''): string
{
    $html = '<div class="mb-3">';
    $html .= '<label class="form-label" for="' . e($id) . '">' . e($label) . '</label>';
    $html .= $control;
    if ($help !== '') {
        $html .= '<div class="form-text" id="' . e($id) . '-help">' . e($help) . '</div>';
    }
    if ($error !== '') {
        $html .= '<div class="invalid-feedback d-block" id="' . e($id) . '-error">' . e($error) . '</div>';
    }
    $html .= '</div>';
    return $html;
}

function text_field(string $name, string $label, string $value, array $opts = []): string
{
    $id = (string) ($opts['id'] ?? $name);
    $type = (string) ($opts['type'] ?? 'text');
    $class = 'form-control' . (!empty($opts['lg']) ? ' form-control-lg' : '') . (!empty($opts['error']) ? ' is-invalid' : '');
    $attrs = '';
    foreach (['required', 'disabled', 'readonly'] as $flag) {
        if (!empty($opts[$flag])) {
            $attrs .= ' ' . $flag;
        }
    }
    if (!empty($opts['help'])) {
        $attrs .= ' aria-describedby="' . e($id) . '-help"';
    }
    if (!empty($opts['autocomplete'])) {
        $attrs .= ' autocomplete="' . e((string) $opts['autocomplete']) . '"';
    }
    if (!empty($opts['inputmode'])) {
        $attrs .= ' inputmode="' . e((string) $opts['inputmode']) . '"';
    }
    if (isset($opts['min'])) {
        $attrs .= ' min="' . e((string) $opts['min']) . '"';
    }
    if (isset($opts['max'])) {
        $attrs .= ' max="' . e((string) $opts['max']) . '"';
    }
    if (!empty($opts['match'])) {
        $attrs .= ' data-match-input data-match="' . e((string) $opts['match']) . '" autocomplete="off"';
    }
    $control = '<input class="' . $class . '" type="' . e($type) . '" name="' . e($name) . '" id="' . e($id) . '" value="' . e($value) . '" data-initial="' . e($value) . '"' . $attrs . '>';
    return field_wrap($label, $id, $control, (string) ($opts['help'] ?? ''), (string) ($opts['error'] ?? ''));
}

function select_field(string $name, string $label, string $value, array $options, array $opts = []): string
{
    $id = (string) ($opts['id'] ?? $name);
    $class = 'form-select' . (!empty($opts['lg']) ? ' form-select-lg' : '') . (!empty($opts['error']) ? ' is-invalid' : '');
    $disabled = !empty($opts['disabled']) ? ' disabled' : '';
    $html = '<select class="' . $class . '" name="' . e($name) . '" id="' . e($id) . '" data-initial="' . e($value) . '"' . $disabled . '>';
    foreach ($options as $optionValue => $optionLabel) {
        $selected = (string) $optionValue === $value ? ' selected' : '';
        $html .= '<option value="' . e((string) $optionValue) . '"' . $selected . '>' . e((string) $optionLabel) . '</option>';
    }
    $html .= '</select>';
    return field_wrap($label, $id, $html, (string) ($opts['help'] ?? ''), (string) ($opts['error'] ?? ''));
}

function their_choice_label(?int $selectedPriority, string $timeslotId, array $choices): string
{
    if ($selectedPriority !== null && $selectedPriority > 0) {
        return ordinal_choice($selectedPriority);
    }
    if ($choices === []) {
        return '—';
    }
    foreach ($choices as $choice) {
        $priority = (int) ($choice['priority'] ?? 0);
        if ($priority > 0 && (string) ($choice['timeslot_id'] ?? '') === $timeslotId) {
            return ordinal_choice($priority);
        }
    }
    return 'Not on their list';
}

function waiting_reason(bool $hasChoice, bool $waitingForAcceptance): string
{
    if (!$hasChoice) {
        return 'No choice yet';
    }
    if ($waitingForAcceptance) {
        return 'Waiting for later acceptance';
    }
    return 'Every choice was taken';
}

function reference_line(string $id): string
{
    return '<p class="reference-line"><span>Reference</span> <code>' . e($id) . '</code> '
        . '<button type="button" class="btn btn-link" data-copy="' . e($id) . '">Copy</button></p>';
}

function password_control(string $name, string $id, array $opts = []): string
{
    $error = (string) ($opts['error'] ?? '');
    $class = 'form-control' . (!empty($opts['lg']) ? ' form-control-lg' : '') . ($error !== '' ? ' is-invalid' : '');
    $autocomplete = (string) ($opts['autocomplete'] ?? 'new-password');
    $attrs = '';
    foreach (['required', 'disabled', 'autofocus'] as $flag) {
        if (!empty($opts[$flag])) {
            $attrs .= ' ' . $flag;
        }
    }
    $eyeDisabled = !empty($opts['disabled']) ? ' disabled' : '';
    $html = '<div class="password-box">'
        . '<input class="' . $class . '" type="password" name="' . e($name) . '" id="' . e($id) . '" value="" data-initial="" autocomplete="' . e($autocomplete) . '"' . $attrs . '>'
        . '<button type="button" class="password-eye" data-toggle-password="' . e($id) . '" aria-label="Show password"' . $eyeDisabled . '>'
        . '<i class="fa-regular fa-eye-slash" aria-hidden="true"></i></button>';
    if (!empty($opts['beam'])) {
        $html .= '<div class="beam" hidden></div>';
    }
    return $html . '</div>';
}

function password_field(string $name, string $label, array $opts = []): string
{
    $id = (string) ($opts['id'] ?? $name);
    $error = (string) ($opts['error'] ?? '');
    return field_wrap($label, $id, password_control($name, $id, $opts), (string) ($opts['help'] ?? ''), $error);
}
