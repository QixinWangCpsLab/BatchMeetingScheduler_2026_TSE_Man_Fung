<?php
declare(strict_types=1);

if (!defined('BMS_APP')) {
    http_response_code(404);
    exit;
}

function uuid_v7(): string
{
    $ms = (int) floor(microtime(true) * 1000);
    $time = str_pad(dechex($ms), 12, '0', STR_PAD_LEFT);
    $bytes = hex2bin($time) . random_bytes(10);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x70);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function is_uuid(string $value): bool
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
}
