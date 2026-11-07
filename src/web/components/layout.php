<?php
declare(strict_types=1);

if (!defined('BMS_APP')) {
    http_response_code(404);
    exit;
}

function asset_tags(): string
{
    return '<link rel="icon" href="' . e(app_url('images/favicon.ico')) . '">'
        . '<link rel="stylesheet" href="https://use.fontawesome.com/releases/v6.5.2/css/all.css" crossorigin="anonymous">'
        . '<link rel="stylesheet" href="' . e(app_url('styles/bootstrap.min.css')) . '">'
        . '<link rel="stylesheet" href="' . e(app_url('styles/bootstrap-table.min.css')) . '">'
        . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/chiron-sans-hk-webfont@2.46.1/css/vf.min.css">'
        . '<link rel="stylesheet" href="' . e(app_url('styles/main.css')) . '">'
        . '<link rel="stylesheet" href="' . e(app_url('styles/admin.css')) . '">';
}

function script_tags(): string
{
    return '<script src="' . e(app_url('scripts/jquery-3.6.0.min.js')) . '"></script>'
        . '<script src="' . e(app_url('scripts/bootstrap.bundle.min.js')) . '"></script>'
        . '<script src="' . e(app_url('scripts/bootstrap-table.min.js')) . '"></script>'
        . '<script src="' . e(app_url('scripts/admin.js')) . '"></script>';
}

function flash_seeds(): string
{
    $html = '';
    foreach (take_flashes() as $flash) {
        $html .= '<div class="toast-seed" hidden data-type="' . e($flash['type']) . '" data-message="' . e($flash['message']) . '"></div>';
    }
    return $html;
}

function render_public_page(string $title, string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . '</title>'
        . asset_tags()
        . '</head><body class="public-page">'
        . $body
        . '<div id="toasts" class="toast-stack" aria-live="polite"></div>'
        . flash_seeds()
        . script_tags()
        . '</body></html>';
}

function render_error_page(string $message): void
{
    render_public_page(
        'Batch Meeting Scheduler',
        '<main class="login-wrap"><section class="card login-card"><div class="card-body">'
        . '<h1 class="h4">Batch Meeting Scheduler</h1>'
        . '<p>' . e($message) . '</p>'
        . '<a class="btn btn-poly" href="' . e(app_url('index.php')) . '">Try again</a>'
        . '</div></section></main>'
    );
}

function exam_nav_html(array $exam, string $active): string
{
    $items = [
        'overview' => ['Overview', 'admin/exam.php'],
        'rounds' => ['Rounds', 'admin/exam-rounds.php'],
        'timeslots' => ['Timeslots', 'admin/exam-timeslots.php'],
        'groups' => ['Groups', 'admin/exam-groups.php'],
        'students' => ['Students', 'admin/exam-students.php'],
        'results' => ['Results', 'admin/exam-assignments.php'],
        'email' => ['Email', 'admin/exam-deliveries.php'],
        'access' => ['Access', 'admin/exam-access.php'],
        'activity' => ['Activity', 'admin/exam-audit.php'],
    ];
    $html = '<nav class="exam-nav" aria-label="Exam">';
    foreach ($items as $key => [$label, $path]) {
        $href = app_url($path . '?exam=' . rawurlencode((string) $exam['exam_id']));
        $current = $key === $active ? ' aria-current="page"' : '';
        $class = $key === $active ? ' class="is-current"' : '';
        $html .= '<a href="' . e($href) . '"' . $class . $current . '>' . e($label) . '</a>';
    }
    $html .= '</nav>';
    return $html;
}

function breadcrumb_html(array $crumbs): string
{
    if ($crumbs === []) {
        return '';
    }
    $html = '<nav class="crumbs" aria-label="Breadcrumb">';
    $last = count($crumbs) - 1;
    foreach ($crumbs as $index => $crumb) {
        if ($index > 0) {
            $html .= '<span aria-hidden="true"> / </span>';
        }
        $note = '';
        if (!empty($crumb['note'])) {
            $note = ' <span class="crumb-code">' . e((string) $crumb['note']) . '</span>';
        }
        if ($index === $last || empty($crumb['href'])) {
            $html .= '<span>' . e($crumb['label']) . $note . '</span>';
        } else {
            $html .= '<a href="' . e($crumb['href']) . '">' . e($crumb['label']) . '</a>' . $note;
        }
    }
    $html .= '</nav>';
    return $html;
}

function render_admin(array $admin, array $page, string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    $active = (string) ($page['nav'] ?? '');
    $exam = $page['exam'] ?? null;
    $title = (string) ($page['title'] ?? 'Dashboard');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="csrf" content="' . e(csrf_token()) . '">'
        . '<title>' . e($title) . ' · Batch Meeting Scheduler</title>'
        . asset_tags()
        . '</head><body class="admin-app">'
        . '<a class="skip-link" href="#content">Skip to content</a>'
        . '<header class="topbar"><div class="topbar-inner">'
        . '<div class="topbar-brand">'
        . '<button type="button" class="menu-button" id="menu-button" aria-label="Open menu"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>'
        . '<a class="brand" href="' . e(app_url('admin/index.php')) . '">Batch Meeting Scheduler</a>'
        . '</div>'
        . '<div class="topbar-user">'
        . '<span class="topbar-name">' . e($admin['display_name']) . '</span>'
        . role_badge((string) $admin['role'])
        . sign_out_form()
        . '</div></div></header>'
        . '<div class="sidebar-backdrop" id="sidebar-backdrop" hidden></div>'
        . '<div class="admin-shell"><nav class="sidebar" id="sidebar" aria-label="Admin">'
        . '<div class="sidebar-links">';
    $links = [
        'dashboard' => ['Dashboard', 'admin/index.php', 'fa-solid fa-gauge'],
        'exams' => ['Exams', 'admin/exams.php', 'fa-solid fa-calendar-days'],
    ];
    if (($admin['role'] ?? '') === 'prof') {
        $links['admins'] = ['Admins', 'admin/admins.php', 'fa-solid fa-user-gear'];
    }
    foreach ($links as $key => [$label, $path, $icon]) {
        $class = $key === $active ? ' class="is-current"' : '';
        $current = $key === $active ? ' aria-current="page"' : '';
        echo '<a href="' . e(app_url($path)) . '"' . $class . $current . '>'
            . '<i class="' . e($icon) . '" aria-hidden="true"></i><span>' . e($label) . '</span></a>';
    }
    echo '</div><button type="button" class="sidebar-collapse" id="collapse-nav" aria-expanded="true">'
        . '<span class="when-open">&lt;&lt; Collapse</span><span class="when-collapsed">&gt;&gt;</span></button></nav>';
    echo '<main class="admin-main page-enter" id="content">'
        . '<div class="page-head"><div>';
    if (is_array($exam)) {
        echo exam_nav_html($exam, (string) ($page['exam_nav'] ?? ''));
    }
    echo '<h1>' . e($title) . '</h1>';
    if (!empty($page['crumbs']) && is_array($page['crumbs'])) {
        echo breadcrumb_html($page['crumbs']);
    }
    if (!empty($page['subtitle'])) {
        echo '<p class="page-subtitle">' . e((string) $page['subtitle']) . '</p>';
    }
    echo '</div>';
    if (!empty($page['actions'])) {
        echo '<div class="page-actions">' . $page['actions'] . '</div>';
    }
    echo '</div>' . $body . '</main></div>';
    echo '<div id="toasts" class="toast-stack" aria-live="polite"></div>';
    echo flash_seeds();
    echo script_tags();
    echo '</body></html>';
}

function modal_start(string $id, string $title, string $actionName, array $hidden = [], string $extraClass = ''): string
{
    $html = '<div class="modal fade" id="' . e($id) . '" tabindex="-1" aria-labelledby="' . e($id) . '-title" aria-hidden="true">'
        . '<div class="modal-dialog"><div class="modal-content">'
        . '<form class="js-form ' . e($extraClass) . '" method="post" action="' . e(request_path()) . '">'
        . csrf_input()
        . '<input type="hidden" name="action" value="' . e($actionName) . '">';
    foreach ($hidden as $name => $value) {
        $html .= '<input type="hidden" name="' . e((string) $name) . '" value="' . e((string) $value) . '">';
    }
    $html .= '<div class="modal-header"><h2 class="modal-title h5" id="' . e($id) . '-title">' . e($title) . '</h2>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel"></button></div>'
        . '<div class="modal-body">';
    return $html;
}

function modal_end(string $confirm, bool $danger = false, string $busy = 'Saving', bool $locked = false): string
{
    $class = $danger ? 'btn btn-danger' : 'btn btn-poly';
    $disabled = $locked ? ' disabled' : '';
    return '</div><div class="modal-footer">'
        . '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>'
        . '<button type="button" class="btn btn-outline-secondary" data-reset>Reset</button>'
        . '<button type="submit" class="' . $class . ' ms-auto" data-busy-label="' . e($busy) . '"' . $disabled . '>' . e($confirm) . '</button>'
        . '</div></form></div></div></div>';
}

function exam_crumbs(array $exam, string $leaf): array
{
    return [
        ['label' => 'Exams', 'href' => app_url('admin/exams.php')],
        ['label' => (string) $exam['title'], 'href' => app_url('admin/exam.php?exam=' . rawurlencode((string) $exam['exam_id'])), 'note' => (string) ($exam['exam_code'] ?? '')],
        ['label' => $leaf, 'href' => ''],
    ];
}

function list_error_html(): string
{
    return '<div class="list-error" id="list-error" hidden><p>Could not load this list. Try again.</p>'
        . '<button type="button" class="btn btn-poly retry-list">Retry</button></div>';
}

function detail_canvas(string $title = 'Details'): string
{
    return '<div class="offcanvas offcanvas-end" tabindex="-1" id="detail-canvas" aria-labelledby="detail-canvas-title">'
        . '<div class="offcanvas-header"><h2 class="offcanvas-title h5" id="detail-canvas-title">' . e($title) . '</h2>'
        . '<button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button></div>'
        . '<div class="offcanvas-body"></div></div>';
}

function count_strip(array $items): string
{
    $html = '<section class="count-strip">';
    foreach ($items as $item) {
        $html .= '<a href="' . e($item['href']) . '"><span class="count-num">' . e((string) $item['count']) . '</span><span class="count-label">' . e($item['label']) . '</span></a>';
    }
    $html .= '</section>';
    return $html;
}

function filter_bar(string $name, array $options, string $current): string
{
    $html = '<div class="filter-bar" role="group" aria-label="' . e($name) . '">';
    foreach ($options as $value => $label) {
        $pressed = $value === $current ? 'true' : 'false';
        $class = $value === $current ? 'btn btn-outline-secondary active' : 'btn btn-outline-secondary';
        $html .= '<button type="button" class="' . $class . '" data-filter="' . e((string) $value) . '" aria-pressed="' . $pressed . '">' . e((string) $label) . '</button>';
    }
    $html .= '</div>';
    return $html;
}
