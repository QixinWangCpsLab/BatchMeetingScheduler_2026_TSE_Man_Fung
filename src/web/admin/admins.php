<?php
declare(strict_types=1);

require_once __DIR__ . '/../components/bootstrap.php';

try {
    $admin = require_admin_page();
    if (($admin['role'] ?? '') !== 'prof') {
        if (wants_json()) {
            json_out(['ok' => false, 'message' => 'Only a professor can manage admin accounts.'], 403);
        }
        flash('Only a professor can manage admin accounts.', 'danger');
        redirect_to('admin/index.php');
    }

    if (($_GET['ajax'] ?? '') === 'check') {
        $field = (string) ($_GET['field'] ?? 'username');
        $value = trim((string) ($_GET['value'] ?? ''));
        if ($field === 'email') {
            if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                json_out(['ok' => false, 'message' => 'Enter a valid email address.']);
            }
            $clash = db_one('SELECT username FROM admin_user WHERE email = ?', [$value]);
            json_out(['ok' => $clash === null, 'message' => $clash === null ? 'This email is available.' : 'That email is already used.']);
        }
        if ($value === '' || $value !== normalize_teacher_username($value) || !username_is_valid($value)) {
            json_out(['ok' => false, 'message' => username_rule_message()]);
        }
        $clash = db_one('SELECT username FROM admin_user WHERE username = ?', [$value]);
        json_out(['ok' => $clash === null, 'message' => $clash === null ? 'This username is available.' : 'That username is already used.']);
    }

    if (($_GET['ajax'] ?? '') === 'detail') {
        $row = db_one(
            'SELECT u.*, c.display_name AS creator_name
             FROM admin_user u
             LEFT JOIN admin_user c ON c.username = u.created_by_admin_username
             WHERE u.username = ?',
            [(string) ($_GET['id'] ?? '')]
        );
        if ($row === null) {
            json_out(['ok' => false, 'message' => 'That account was not found.'], 404);
        }
        $html = '<dl>'
            . '<dt>Name</dt><dd>' . e($row['display_name']) . '</dd>'
            . '<dt>Username</dt><dd>' . e($row['username']) . '</dd>'
            . '<dt>Email</dt><dd>' . e($row['email']) . '</dd>'
            . '<dt>Role</dt><dd>' . e(role_label((string) $row['role'])) . '</dd>'
            . '<dt>Status</dt><dd>' . account_badge($row['is_active']) . '</dd>'
            . '<dt>Last sign-in</dt><dd>' . e($row['last_login_at'] ? hk_full((string) $row['last_login_at']) : 'Has not signed in') . '</dd>'
            . '<dt>Created</dt><dd>' . e(hk_full((string) $row['created_at'])) . '</dd>'
            . '<dt>Created by</dt><dd>' . e($row['creator_name'] ?: '—') . '</dd>'
            . '</dl>';
        json_out(['ok' => true, 'title' => (string) $row['display_name'], 'html' => $html]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_post_csrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create') {
            $name = post_str('display_name');
            $username = post_str('username');
            $email = post_str('email');
            $role = post_str('role');
            $password = (string) ($_POST['temporary_password'] ?? '');
            $errors = [];
            if ($name === '') {
                $errors['display_name'] = 'Enter a name.';
            }
            if ($username === '' || $username !== normalize_teacher_username($username) || !username_is_valid($username)) {
                $errors['username'] = username_rule_message();
            } elseif (db_one('SELECT username FROM admin_user WHERE username = ?', [$username])) {
                $errors['username'] = 'That username is already used.';
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'Enter a valid email address.';
            } elseif (db_one('SELECT username FROM admin_user WHERE email = ?', [$email])) {
                $errors['email'] = 'That email is already used.';
            }
            if (!in_array($role, ['ta', 'teacher', 'prof'], true)) {
                $errors['role'] = 'Choose a role.';
            }
            if (!password_rule_ok($password)) {
                $errors['temporary_password'] = password_rule_message();
            }
            if ($errors !== []) {
                respond(['ok' => false, 'errors' => $errors, 'message' => 'Check the highlighted fields.']);
            }
            $now = hk_now()->format('Y-m-d H:i:s');
            db_exec(
                'INSERT INTO admin_user
                    (username, email, password_hash, display_name, role, is_active, created_at, updated_at, created_by_admin_username)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?)',
                [$username, $email, password_hash($password, PASSWORD_DEFAULT), $name, $role, $now, $now, $admin['username']]
            );
            audit_write(null, null, null, (string) $admin['username'], 'account_created', 'Created admin account ' . $username . '.');
            respond([
                'ok' => true,
                'message' => 'Admin account created.',
                'secret' => [
                    'password' => $password,
                    'message' => 'Copy this temporary password now. It will not be shown again.',
                ],
                'redirect' => 'admin/admins.php',
            ]);
        }
        if ($action === 'update') {
            $username = post_str('username');
            $row = db_one('SELECT * FROM admin_user WHERE username = ?', [$username]);
            if ($row === null) {
                respond(['ok' => false, 'message' => 'That account was not found.']);
            }
            $name = post_str('display_name');
            $email = post_str('email');
            $role = post_str('role');
            $errors = [];
            if ($name === '') {
                $errors['display_name'] = 'Enter a name.';
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'Enter a valid email address.';
            } elseif (db_one('SELECT username FROM admin_user WHERE email = ? AND username <> ?', [$email, $username])) {
                $errors['email'] = 'That email is already used.';
            }
            if (!in_array($role, ['ta', 'teacher', 'prof'], true)) {
                $errors['role'] = 'Choose a role.';
            }
            if ($username === (string) $admin['username'] && $role !== (string) $admin['role']) {
                $errors['role'] = 'A professor cannot change their own role.';
            }
            if ($errors !== []) {
                respond(['ok' => false, 'errors' => $errors, 'message' => 'Check the highlighted fields.']);
            }
            db_exec(
                'UPDATE admin_user SET display_name = ?, email = ?, role = ?, updated_at = ? WHERE username = ?',
                [$name, $email, $role, hk_now()->format('Y-m-d H:i:s'), $username]
            );
            audit_write(null, null, null, (string) $admin['username'], 'profile_updated', 'Updated admin account ' . $username . '.');
            respond(['ok' => true, 'message' => 'Account updated.', 'redirect' => 'admin/admins.php?flash_row=' . rawurlencode($username)]);
        }
        if ($action === 'disable' || $action === 'enable') {
            $username = post_str('username');
            $row = db_one('SELECT * FROM admin_user WHERE username = ?', [$username]);
            if ($row === null) {
                respond(['ok' => false, 'message' => 'That account was not found.']);
            }
            if ($action === 'disable') {
                if ($username === (string) $admin['username']) {
                    respond(['ok' => false, 'message' => 'You cannot disable your own account.']);
                }
                if (post_str('confirm_code') !== $username) {
                    respond(['ok' => false, 'errors' => ['confirm_code' => 'Type the username to confirm.'], 'message' => 'Type the username to confirm.']);
                }
                $titles = sole_creator_titles($username);
                if ($titles !== []) {
                    respond(['ok' => false, 'message' => $row['display_name'] . ' is the only active admin on ' . implode(', ', $titles) . '. Add another admin before disabling this account.']);
                }
                db_exec('UPDATE admin_user SET is_active = 0, updated_at = ? WHERE username = ?', [hk_now()->format('Y-m-d H:i:s'), $username]);
                audit_write(null, null, null, (string) $admin['username'], 'account_disabled', 'Disabled admin account ' . $username . '.');
                respond(['ok' => true, 'message' => $row['display_name'] . ' is disabled.', 'redirect' => 'admin/admins.php?flash_row=' . rawurlencode($username)]);
            }
            db_exec('UPDATE admin_user SET is_active = 1, updated_at = ? WHERE username = ?', [hk_now()->format('Y-m-d H:i:s'), $username]);
            audit_write(null, null, null, (string) $admin['username'], 'account_enabled', 'Enabled admin account ' . $username . '.');
            respond(['ok' => true, 'message' => $row['display_name'] . ' is active.', 'redirect' => 'admin/admins.php?flash_row=' . rawurlencode($username)]);
        }
        respond(['ok' => false, 'message' => 'That action is not available.']);
    }

    $rows = db_all(
        'SELECT u.*, c.display_name AS creator_name
         FROM admin_user u
         LEFT JOIN admin_user c ON c.username = u.created_by_admin_username
         ORDER BY u.display_name ASC'
    );
    $body = '<p class="form-text">Passwords are never shown in this list.</p>';
    $body .= '<table id="admin-table" data-client-table data-detail-url="' . e(request_path()) . '" data-sort-name="name" data-sort-order="asc">';
    $body .= '<thead><tr><th data-field="name" data-sortable="true">Name</th><th data-field="username" data-sortable="true">Username</th><th data-field="email" data-sortable="true">Email</th><th data-field="role" data-sortable="true">Role</th><th data-field="status" data-sortable="true">Status</th><th data-field="last" data-sortable="true">Last sign-in</th><th data-field="created" data-sortable="true">Created</th><th data-field="creator" data-sortable="true">Created by</th><th>Actions</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $self = $row['username'] === $admin['username'];
        $body .= '<tr data-id="' . e($row['username']) . '">';
        $body .= '<td>' . e($row['display_name']) . '</td>';
        $body .= '<td>' . e($row['username']) . '</td>';
        $body .= '<td>' . e($row['email']) . '</td>';
        $body .= '<td>' . e(role_label((string) $row['role'])) . '</td>';
        $body .= '<td>' . account_badge($row['is_active']) . '</td>';
        $body .= '<td>' . e($row['last_login_at'] ? hk_full((string) $row['last_login_at']) : 'Has not signed in') . '</td>';
        $body .= '<td>' . e(hk_full((string) $row['created_at'])) . '</td>';
        $body .= '<td>' . e($row['creator_name'] ?: '—') . '</td>';
        $body .= '<td><div class="table-actions">';
        $body .= '<button type="button" class="btn btn-link edit-admin" data-username="' . e($row['username']) . '" data-name="' . e($row['display_name']) . '" data-email="' . e($row['email']) . '" data-role="' . e($row['role']) . '" data-self="' . ($self ? '1' : '0') . '">Edit</button>';
        if ((int) $row['is_active'] === 1) {
            if (!$self) {
                $body .= '<button type="button" class="btn btn-link text-danger disable-admin" data-username="' . e($row['username']) . '" data-name="' . e($row['display_name']) . '">Disable</button>';
            }
        } else {
            $body .= '<form class="js-form d-inline" method="post"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="enable"><input type="hidden" name="username" value="' . e($row['username']) . '"><button class="btn btn-link" type="submit" data-busy-label="Saving">Enable</button></form>';
        }
        $body .= '</div></td></tr>';
    }
    $body .= '</tbody></table>';
    $body .= '<div class="offcanvas offcanvas-end" tabindex="-1" id="detail-canvas" aria-labelledby="detail-title"><div class="offcanvas-header"><h2 class="offcanvas-title h5" id="detail-title">Details</h2><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button></div><div class="offcanvas-body"></div></div>';

    $body .= modal_start('create-admin', 'New admin', 'create');
    $body .= text_field('display_name', 'Name', '', ['required' => true]);
    $body .= text_field('username', 'Username', '', ['required' => true, 'help' => username_rule_message()]);
    $body .= text_field('email', 'Email', '', ['type' => 'email', 'required' => true]);
    $body .= select_field('role', 'Role', 'teacher', ['ta' => 'TA', 'teacher' => 'Teacher', 'prof' => 'Professor']);
    $body .= password_field('temporary_password', 'Temporary password', ['help' => password_rule_message()]);
    $body .= modal_end('Create admin');

    $body .= modal_start('edit-admin', 'Edit admin', 'update');
    $body .= '<input type="hidden" name="username" id="edit-username" value="">';
    $body .= text_field('display_name', 'Name', '', ['id' => 'edit-name', 'required' => true]);
    $body .= text_field('username_display', 'Username', '', ['id' => 'edit-username-display', 'disabled' => true, 'help' => 'The login name cannot change.']);
    $body .= text_field('email', 'Email', '', ['id' => 'edit-email', 'type' => 'email', 'required' => true]);
    $body .= select_field('role', 'Role', 'teacher', ['ta' => 'TA', 'teacher' => 'Teacher', 'prof' => 'Professor'], ['id' => 'edit-role']);
    $body .= '<p class="form-text" id="edit-role-help" hidden>A professor cannot change their own role.</p>';
    $body .= modal_end('Save admin');

    $body .= modal_start('disable-admin', 'Disable admin', 'disable', [], '');
    $body .= '<input type="hidden" name="username" id="disable-username" value="">';
    $body .= '<p id="disable-copy"></p>';
    $body .= text_field('confirm_code', 'Username', '', ['id' => 'disable-code', 'match' => '']);
    $body .= modal_end('Disable account', true, 'Saving', true);

    $body .= '<script>
    document.querySelector("#username")?.setAttribute("data-live-check", location.pathname);
    document.querySelector("#username")?.setAttribute("data-live-ajax", "check");
    document.querySelector("#email")?.setAttribute("data-live-check", location.pathname + "?field=email");
    document.querySelector("#email")?.setAttribute("data-live-ajax", "check");
    document.querySelectorAll(".edit-admin").forEach(function (button) {
        button.addEventListener("click", function () {
            const form = document.querySelector("#edit-admin form");
            form.elements.username.value = button.dataset.username;
            form.querySelector("#edit-username-display").value = button.dataset.username;
            form.elements.display_name.value = button.dataset.name;
            form.elements.display_name.dataset.initial = button.dataset.name;
            form.elements.email.value = button.dataset.email;
            form.elements.email.dataset.initial = button.dataset.email;
            form.elements.role.value = button.dataset.role;
            form.elements.role.dataset.initial = button.dataset.role;
            form.elements.role.disabled = button.dataset.self === "1";
            document.getElementById("edit-role-help").hidden = button.dataset.self !== "1";
            bootstrap.Modal.getOrCreateInstance(document.getElementById("edit-admin")).show();
        });
    });
    document.querySelectorAll(".disable-admin").forEach(function (button) {
        button.addEventListener("click", function () {
            const form = document.querySelector("#disable-admin form");
            form.elements.username.value = button.dataset.username;
            const input = form.querySelector("[data-match-input]");
            input.dataset.match = button.dataset.username;
            input.value = "";
            document.getElementById("disable-copy").textContent = "Type " + button.dataset.username + " to disable " + button.dataset.name + ".";
            form.querySelector("[type=submit]").disabled = true;
            bootstrap.Modal.getOrCreateInstance(document.getElementById("disable-admin")).show();
        });
    });
    </script>';

    render_admin($admin, [
        'title' => 'Admins',
        'subtitle' => 'Professor accounts can create and disable other admins.',
        'nav' => 'admins',
        'actions' => '<button class="btn btn-poly" type="button" data-bs-toggle="modal" data-bs-target="#create-admin">New admin</button>',
    ], $body);
} catch (Throwable $e) {
    error_log($e->__toString());
    if (wants_json()) {
        json_out(['ok' => false, 'message' => friendly_exception($e)], 500);
    }
    render_error_page('The service is not available right now. Try again later.');
}
