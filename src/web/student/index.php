<?php
declare(strict_types=1);

require_once __DIR__ . '/../components/bootstrap.php';

try {
    if (($_SESSION['actor'] ?? '') === 'teacher' && current_admin() !== null) {
        redirect_to('admin/index.php');
    }
    $student = current_student();
    if ($student === null) {
        redirect_to('index.php');
    }
    $body = '<header class="topbar"><div class="topbar-inner">'
        . '<a class="brand" href="' . e(app_url('student/index.php')) . '">Batch Meeting Scheduler</a>'
        . '<div class="topbar-user"><span class="topbar-name">' . e($student['display_name']) . '</span>'
        . sign_out_form()
        . '</div></div></header>'
        . '<main class="admin-main" id="content" style="margin-left:0;padding-top:80px">'
        . '<h1>You are signed in</h1>'
        . '<p class="page-subtitle">' . e($student['exam_title']) . '</p>'
        . '<p>Entry code <strong>' . e($student['exam_code']) . '</strong></p>'
        . '<p>Login name <strong>' . e($student['student_id']) . '</strong></p>'
        . '</main>';
    render_public_page('Your exam · Batch Meeting Scheduler', $body);
} catch (Throwable $e) {
    error_log($e->__toString());
    render_error_page('The service is not available right now. Try again later.');
}
