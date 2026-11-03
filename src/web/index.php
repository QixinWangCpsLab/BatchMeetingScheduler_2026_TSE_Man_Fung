<?php
declare(strict_types=1);

require_once __DIR__ . '/components/bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'logout') {
        if (!csrf_ok($_POST['csrf'] ?? null)) {
            flash('This page has expired. Reload and try again.', 'danger');
            redirect_to('index.php');
        }
        logout_and_restart();
        $signedOut = true;
    } else {
        $signedOut = false;
    }

    $actor = (string) ($_SESSION['actor'] ?? '');
    if ($actor === 'teacher' && current_admin() !== null) {
        redirect_to('admin/index.php');
    }
    if ($actor === 'student' && current_student() !== null) {
        redirect_to('student/index.php');
    }

    $errors = [];
    $loginName = '';
    $loginAs = '';
    $focus = '';
    $formError = '';
    $lockedUntil = null;
    $whichExam = null;

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'choose_exam') {
        if (!csrf_ok($_POST['csrf'] ?? null)) {
            $formError = 'This page has expired. Reload and try again.';
        } else {
            $pending = $_SESSION['pending_student'] ?? null;
            $choice = (string) ($_POST['exam_id'] ?? '');
            $match = null;
            if (is_array($pending) && (int) ($pending['expires'] ?? 0) >= time()) {
                foreach ($pending['choices'] as $item) {
                    if (($item['exam_id'] ?? '') === $choice) {
                        $match = $item;
                        break;
                    }
                }
            }
            if ($match === null) {
                unset($_SESSION['pending_student']);
                $formError = 'Sign in again to choose the exam.';
            } else {
                $student = db_one(
                    'SELECT * FROM student_account WHERE student_id = ? AND exam_id = ? AND is_active = 1',
                    [$match['student_id'], $match['exam_id']]
                );
                if ($student === null) {
                    unset($_SESSION['pending_student']);
                    $formError = 'Login name or password is not correct. Check Login as as well.';
                } else {
                    db_exec(
                        'UPDATE student_account SET last_login_at = ? WHERE student_id = ? AND exam_id = ?',
                        [hk_now()->format('Y-m-d H:i:s'), $student['student_id'], $student['exam_id']]
                    );
                    $student['password_hash'] = (string) $student['password_hash'];
                    establish_student($student);
                    redirect_to('student/index.php');
                }
            }
        }
    }

    $pending = $_SESSION['pending_student'] ?? null;
    if (is_array($pending) && (int) ($pending['expires'] ?? 0) >= time() && $formError === '') {
        $whichExam = $pending['choices'];
    } elseif (is_array($pending)) {
        unset($_SESSION['pending_student']);
    }

    $adminCount = (int) db_value('SELECT COUNT(*) FROM admin_user');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'create_professor' && $adminCount === 0) {
        if (!csrf_ok($_POST['csrf'] ?? null)) {
            $formError = 'This page has expired. Reload and try again.';
        } else {
            $display = post_str('display_name');
            $username = post_str('username');
            $email = post_str('email');
            $password = (string) ($_POST['password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');
            if ($display === '') {
                $errors['display_name'] = 'Enter a name.';
            }
            if ($username === '' || $username !== normalize_teacher_username($username) || !username_is_valid($username)) {
                $errors['username'] = username_rule_message();
            }
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'Enter a valid email address.';
            }
            if (!password_rule_ok($password)) {
                $errors['password'] = password_rule_message();
            } elseif ($password !== $confirm) {
                $errors['confirm_password'] = 'Type the new password again.';
            }
            if ($errors === []) {
                $now = hk_now()->format('Y-m-d H:i:s');
                $hash = password_hash($password, PASSWORD_DEFAULT);
                db_exec(
                    'INSERT INTO admin_user
                        (username, email, password_hash, display_name, role, is_active, created_at, updated_at, last_login_at)
                     VALUES (?, ?, ?, ?, "prof", 1, ?, ?, ?)',
                    [$username, $email, $hash, $display, $now, $now, $now]
                );
                $admin = db_one('SELECT * FROM admin_user WHERE username = ?', [$username]);
                establish_teacher($admin);
                flash('Professor account created.', 'success');
                redirect_to('admin/index.php');
            }
        }
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'login') {
        if (!csrf_ok($_POST['csrf'] ?? null)) {
            $formError = 'This page has expired. Reload and try again.';
        } else {
            $loginName = post_str('login_name');
            $loginAs = (string) ($_POST['login_as'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            if ($loginName === '') {
                $errors['login_name'] = 'Enter your login name.';
            }
            if ($password === '') {
                $errors['password'] = 'Enter your password.';
            }
            if (!in_array($loginAs, ['teacher', 'student'], true)) {
                $errors['login_as'] = 'Select teacher or student.';
            }
            if ($errors !== []) {
                $focus = isset($errors['login_name']) ? 'login_name' : (isset($errors['password']) ? 'password' : 'login_as');
            } else {
                $keyName = $loginAs === 'teacher' ? normalize_teacher_username($loginName) : trim($loginName);
                $lockedUntil = lockout_status($loginAs, $keyName);
                if ($lockedUntil === null) {
                    $matches = [];
                    if ($loginAs === 'teacher') {
                        $admin = db_one('SELECT * FROM admin_user WHERE username = ?', [$keyName]);
                        if ($admin !== null && (int) $admin['is_active'] === 1 && password_verify($password, (string) $admin['password_hash'])) {
                            $matches[] = $admin;
                        }
                    } else {
                        $rows = db_all(
                            'SELECT s.*, e.title, e.exam_code
                             FROM student_account s
                             JOIN exam e ON e.exam_id = s.exam_id
                             WHERE s.student_id = ? AND s.is_active = 1',
                            [$keyName]
                        );
                        foreach ($rows as $row) {
                            if (password_verify($password, (string) $row['password_hash'])) {
                                $matches[] = $row;
                            }
                        }
                    }
                    if ($matches === []) {
                        $lockedUntil = lockout_fail($loginAs, $keyName);
                        if ($lockedUntil === null) {
                            $formError = 'Login name or password is not correct. Check Login as as well.';
                            $password = '';
                            $focus = 'password';
                        }
                    } else {
                        lockout_clear($loginAs, $keyName);
                        $now = hk_now()->format('Y-m-d H:i:s');
                        if ($loginAs === 'teacher') {
                            db_exec('UPDATE admin_user SET last_login_at = ? WHERE username = ?', [$now, $matches[0]['username']]);
                            $matches[0]['last_login_at'] = $now;
                            establish_teacher($matches[0]);
                            redirect_to('admin/index.php');
                        }
                        if (count($matches) === 1) {
                            db_exec(
                                'UPDATE student_account SET last_login_at = ? WHERE student_id = ? AND exam_id = ?',
                                [$now, $matches[0]['student_id'], $matches[0]['exam_id']]
                            );
                            establish_student($matches[0]);
                            redirect_to('student/index.php');
                        }
                        session_regenerate_id(true);
                        $_SESSION['pending_student'] = [
                            'expires' => time() + 600,
                            'choices' => array_map(static function (array $row): array {
                                return [
                                    'student_id' => (string) $row['student_id'],
                                    'exam_id' => (string) $row['exam_id'],
                                    'title' => (string) $row['title'],
                                    'exam_code' => (string) $row['exam_code'],
                                ];
                            }, $matches),
                        ];
                        $_SESSION['csrf'] = bin2hex(random_bytes(16));
                        $whichExam = $_SESSION['pending_student']['choices'];
                    }
                }
            }
        }
    }

    $body = '<main class="login-wrap"><section class="card login-card"><div class="card-body">';
    $body .= '<h1 class="h4 mb-3">Batch Meeting Scheduler</h1>';
    if ($signedOut) {
        $body .= '<p class="login-error">You have signed out.</p>';
    }
    if ($formError !== '') {
        $body .= '<p class="login-error text-danger" role="alert">' . e($formError) . '</p>';
    }
    if ($lockedUntil !== null) {
        $body .= '<p class="login-error" role="alert">' . e(lockout_message($lockedUntil)) . '</p>';
    }
    if ($adminCount === 0 && $whichExam === null) {
        $body .= '<h2 class="h5">Create the first professor</h2>';
        $body .= '<p class="form-text">The first account is a professor. After this, professors create the other admin accounts.</p>';
        $body .= '<form method="post" action="' . e(app_url('index.php')) . '" class="login-form">';
        $body .= csrf_input();
        $body .= '<input type="hidden" name="action" value="create_professor">';
        $body .= text_field('display_name', 'Name', post_str('display_name'), ['lg' => true, 'error' => $errors['display_name'] ?? '', 'required' => true]);
        $body .= text_field('username', 'Username', post_str('username'), ['lg' => true, 'error' => $errors['username'] ?? '', 'required' => true, 'help' => username_rule_message()]);
        $body .= text_field('email', 'Email', post_str('email'), ['lg' => true, 'type' => 'email', 'error' => $errors['email'] ?? '', 'required' => true]);
        $body .= password_field('password', 'Password', ['lg' => true, 'beam' => true, 'error' => $errors['password'] ?? '', 'help' => password_rule_message()]);
        $body .= password_field('confirm_password', 'Confirm password', ['id' => 'confirm_password', 'lg' => true, 'beam' => true, 'error' => $errors['confirm_password'] ?? '']);
        $body .= '<button class="btn btn-poly w-100" type="submit">Create professor account</button>';
        $body .= '</form>';
    } elseif (is_array($whichExam)) {
        $body .= '<h2 class="h5">Which exam?</h2>';
        $body .= '<form method="post" action="' . e(app_url('index.php')) . '">';
        $body .= csrf_input();
        $body .= '<input type="hidden" name="action" value="choose_exam">';
        foreach ($whichExam as $choice) {
            $body .= '<button class="btn btn-outline-secondary w-100 text-start mb-2" type="submit" name="exam_id" value="' . e($choice['exam_id']) . '">'
                . '<span class="d-block fw-semibold">' . e($choice['title']) . '</span>'
                . '<span class="text-muted">' . e($choice['exam_code']) . '</span></button>';
        }
        $body .= '</form>';
    } else {
        $body .= '<h2 class="h5">Sign in</h2>';
        $disabled = $lockedUntil !== null;
        $body .= '<form method="post" action="' . e(app_url('index.php')) . '" class="login-form"' . ($disabled ? '' : '') . '>';
        $body .= csrf_input();
        $body .= '<input type="hidden" name="action" value="login">';
        $nameOpts = ['id' => 'login_name', 'lg' => true, 'required' => true, 'autocomplete' => 'username', 'error' => $errors['login_name'] ?? ''];
        if ($disabled) {
            $nameOpts['disabled'] = true;
        }
        $body .= text_field('login_name', 'Login name', $loginName, $nameOpts);
        $body .= '<div class="mb-3"><label class="form-label" for="password">Password</label>'
            . password_control('password', 'password', [
                'lg' => true,
                'beam' => true,
                'autocomplete' => 'current-password',
                'error' => $errors['password'] ?? '',
                'autofocus' => $focus === 'password',
                'disabled' => $disabled,
            ]);
        if (isset($errors['password'])) {
            $body .= '<div class="invalid-feedback d-block">' . e($errors['password']) . '</div>';
        }
        $body .= '</div>';
        $body .= '<div class="mb-3"><label class="form-label" for="login_as">Login as</label>'
            . '<select class="form-select form-select-lg' . (isset($errors['login_as']) ? ' is-invalid' : '') . '" name="login_as" id="login_as"' . ($disabled ? ' disabled' : '') . '>'
            . '<option value="" disabled' . ($loginAs === '' ? ' selected' : '') . '>Select teacher or student</option>'
            . '<option value="teacher"' . ($loginAs === 'teacher' ? ' selected' : '') . '>Teacher</option>'
            . '<option value="student"' . ($loginAs === 'student' ? ' selected' : '') . '>Student</option>'
            . '</select>';
        if (isset($errors['login_as'])) {
            $body .= '<div class="invalid-feedback d-block">' . e($errors['login_as']) . '</div>';
        }
        $body .= '</div>';
        $body .= '<button class="btn btn-poly w-100" type="submit"' . ($disabled ? ' disabled' : '') . '>Sign in</button>';
        $body .= '</form>';
    }
    $body .= '</div></section></main>';
    render_public_page('Sign in · Batch Meeting Scheduler', $body);
} catch (Throwable $e) {
    error_log($e->__toString());
    render_error_page('The service is not available right now. Try again later.');
}
