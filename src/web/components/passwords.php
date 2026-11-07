<?php
declare(strict_types=1);

if (!defined('BMS_APP')) {
    http_response_code(404);
    exit;
}

const STUDENT_PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';

function password_rule_ok(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Za-z]/', $password) === 1
        && preg_match('/\d/', $password) === 1;
}

function password_rule_message(): string
{
    return 'Use at least 8 characters with a letter and a number.';
}

function generate_student_password(): string
{
    $alphabet = STUDENT_PASSWORD_ALPHABET;
    $max = strlen($alphabet) - 1;
    do {
        $chars = [];
        for ($i = 0; $i < 12; $i++) {
            $chars[] = $alphabet[random_int(0, $max)];
        }
        $password = implode('', $chars);
    } while (!password_rule_ok($password));
    return $password;
}

function unknown_password_hash(): string
{
    return password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
}

function credential_stamp(string $passwordHash): string
{
    return hash('sha256', $passwordHash);
}

function normalize_teacher_username(string $value): string
{
    $value = preg_replace('/\s+/u', '', $value) ?? $value;
    return strtolower($value);
}

function username_is_valid(string $username): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $username);
}

function username_rule_message(): string
{
    return 'Use lowercase letters, digits, dots, underscores, or hyphens, with no spaces.';
}
