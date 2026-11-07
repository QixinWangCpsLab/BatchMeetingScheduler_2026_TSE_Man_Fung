<?php
declare(strict_types=1);

require_once __DIR__ . '/../components/bootstrap.php';

try {
    $admin = require_admin_page();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_post_csrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_profile') {
            $name = post_str('display_name');
            $email = post_str('email');
            $errors = [];
            if ($name === '') {
                $errors['display_name'] = 'Enter a name.';
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'Enter a valid email address.';
            } else {
                $clash = db_one('SELECT username FROM admin_user WHERE email = ? AND username <> ?', [$email, $admin['username']]);
                if ($clash !== null) {
                    $errors['email'] = 'That email is already used.';
                }
            }
            if ($errors !== []) {
                respond(['ok' => false, 'errors' => $errors, 'message' => 'Check the highlighted fields.']);
            }
            $now = hk_now()->format('Y-m-d H:i:s');
            db_exec(
                'UPDATE admin_user SET display_name = ?, email = ?, updated_at = ? WHERE username = ?',
                [$name, $email, $now, $admin['username']]
            );
            audit_write(null, null, null, (string) $admin['username'], 'profile_updated', 'Updated name and email.');
            respond(['ok' => true, 'message' => 'Profile updated.', 'redirect' => 'admin/profile.php']);
        }
        if ($action === 'change_password') {
            $current = (string) ($_POST['current_password'] ?? '');
            $next = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');
            $errors = [];
            if (!password_verify($current, (string) $admin['password_hash'])) {
                $errors['current_password'] = 'The current password is not correct.';
            }
            if (!password_rule_ok($next)) {
                $errors['new_password'] = password_rule_message();
            }
            if ($next !== $confirm) {
                $errors['confirm_password'] = 'Type the new password again.';
            }
            if ($errors !== []) {
                respond(['ok' => false, 'errors' => $errors, 'message' => 'Check the highlighted fields.']);
            }
            $hash = password_hash($next, PASSWORD_DEFAULT);
            $now = hk_now()->format('Y-m-d H:i:s');
            db_exec('UPDATE admin_user SET password_hash = ?, updated_at = ? WHERE username = ?', [$hash, $now, $admin['username']]);
            $admin['password_hash'] = $hash;
            establish_teacher($admin);
            audit_write(null, null, null, (string) $admin['username'], 'password_changed', 'Password updated.');
            respond(['ok' => true, 'message' => 'Password updated', 'redirect' => 'admin/profile.php']);
        }
        respond(['ok' => false, 'message' => 'That action is not available.']);
    }

    $creator = null;
    if (!empty($admin['created_by_admin_username'])) {
        $creator = db_one('SELECT display_name FROM admin_user WHERE username = ?', [$admin['created_by_admin_username']]);
    }
    $roleHelp = 'Ask a professor to change a role. A professor cannot change their own role to TA or Teacher.';
    $body = '<div class="row g-4"><div class="col-lg-7"><section class="panel">';
    $body .= '<form class="js-form" method="post" action="' . e(request_path()) . '">';
    $body .= csrf_input();
    $body .= '<input type="hidden" name="action" value="save_profile">';
    $body .= text_field('display_name', 'Name', (string) $admin['display_name'], ['required' => true]);
    $body .= text_field('username', 'Username', (string) $admin['username'], ['disabled' => true, 'help' => 'This is the login name.']);
    $body .= text_field('email', 'Email', (string) $admin['email'], ['type' => 'email', 'required' => true]);
    $body .= text_field('role', 'Role', role_label((string) $admin['role']), ['disabled' => true, 'help' => $roleHelp]);
    $body .= text_field('status', 'Status', account_label($admin['is_active']), ['disabled' => true]);
    $body .= text_field('created', 'Created', hk_full((string) $admin['created_at']) . ($creator ? ' by ' . $creator['display_name'] : ''), ['disabled' => true]);
    $body .= text_field('last_login', 'Last sign-in', $admin['last_login_at'] ? hk_full((string) $admin['last_login_at']) : 'Has not signed in', ['disabled' => true]);
    $body .= text_field('updated', 'Updated', hk_full((string) $admin['updated_at']), ['disabled' => true]);
    $body .= '<button class="btn btn-poly" type="submit">Save profile</button>';
    $body .= '</form></section></div></div>';
    $body .= modal_start('password-modal', 'Change password', 'change_password');
    $body .= password_field('current_password', 'Current password', ['autocomplete' => 'current-password']);
    $body .= password_field('new_password', 'New password', ['help' => password_rule_message()]);
    $body .= password_field('confirm_password', 'Type the new password again', ['id' => 'confirm_password']);
    $body .= modal_end('Change password');
    $actions = '<button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#password-modal">Change password</button>';
    render_admin($admin, [
        'title' => 'Your account',
        'subtitle' => 'Only you can see this page.',
        'nav' => '',
        'actions' => $actions,
    ], $body);
} catch (Throwable $e) {
    error_log($e->__toString());
    if (wants_json()) {
        json_out(['ok' => false, 'message' => friendly_exception($e)], 500);
    }
    render_error_page('The service is not available right now. Try again later.');
}
