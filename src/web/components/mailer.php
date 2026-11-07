<?php
declare(strict_types=1);

if (!defined('BMS_APP')) {
    http_response_code(404);
    exit;
}

function mail_raw(string $name): string
{
    $value = getenv($name);
    if (($value === false || $value === '') && isset($_SERVER[$name])) {
        $value = $_SERVER[$name];
    }
    return (string) $value;
}

function mail_value(string $name): string
{
    return trim(mail_raw($name));
}

function mail_from_address(): string
{
    $from = mail_value('MAIL_FROM');
    if (filter_var($from, FILTER_VALIDATE_EMAIL) !== false) {
        return $from;
    }
    $user = mail_value('MAIL_USER');
    if (filter_var($user, FILTER_VALIDATE_EMAIL) !== false) {
        return $user;
    }
    return '';
}

function mail_is_configured(): bool
{
    return mail_value('MAIL_HOST') !== ''
        && mail_value('MAIL_USER') !== ''
        && mail_value('MAIL_PASS') !== ''
        && mail_from_address() !== '';
}

function send_entry_mail(string $to, string $toName, string $subject, string $body): ?string
{
    if (!mail_is_configured()) {
        return 'Email is not configured.';
    }
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = mail_value('MAIL_HOST');
        $mail->SMTPAuth = true;
        $mail->Username = mail_value('MAIL_USER');
        $mail->Password = mail_raw('MAIL_PASS');
        $secure = strtolower(mail_value('MAIL_SECURE') !== '' ? mail_value('MAIL_SECURE') : 'ssl');
        $mail->SMTPSecure = $secure === 'tls'
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $port = mail_value('MAIL_PORT');
        $mail->Port = $port !== '' ? (int) $port : 465;
        $mail->Timeout = 20;
        $mail->CharSet = 'UTF-8';
        $fromName = mail_value('MAIL_FROM_NAME');
        $mail->setFrom(mail_from_address(), $fromName !== '' ? $fromName : 'do-not-reply');
        $mail->addAddress($to, $toName);
        $mail->Subject = $subject;
        $mail->isHTML(false);
        $mail->Body = $body;
        $mail->send();
        return null;
    } catch (Throwable $e) {
        $reason = trim($mail->ErrorInfo) !== '' ? trim($mail->ErrorInfo) : $e->getMessage();
        error_log('Mail send failed: ' . $reason);
        return mb_substr($reason, 0, 1000);
    }
}

function entry_mail_body(array $exam, string $studentId, string $password): string
{
    return $exam['title'] . "\n\n"
        . 'Entry code: ' . $exam['exam_code'] . "\n"
        . 'Login name: ' . $studentId . "\n"
        . 'Password: ' . $password . "\n\n"
        . 'Open ' . root_url() . " and sign in.\n"
        . "In Login as, choose Student.\n";
}
