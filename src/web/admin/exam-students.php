<?php
declare(strict_types=1);

require_once __DIR__ . '/../components/bootstrap.php';

try {
    $admin = require_admin_page();
    $exam = require_exam($admin, (string) ($_GET['exam'] ?? $_POST['exam_id'] ?? ''));
    $examId = (string) $exam['exam_id'];
    $back = exam_href('exam-students.php', $examId);
    $detailUrl = request_path() . '?exam=' . rawurlencode($examId);
    $completed = $exam['status'] === 'completed';
    $mailReady = mail_is_configured();

    if (($_GET['ajax'] ?? '') === 'detail') {
        $student = student_row($examId, (string) ($_GET['id'] ?? ''));
        if ($student === null) {
            json_out(['ok' => false, 'message' => 'That student is not in this exam.'], 404);
        }
        $html = '<p>' . e((string) $student['display_name']) . '</p>';
        $html .= '<p>Student ID ' . e((string) $student['student_id']) . '</p>';
        $html .= '<p>' . e((string) $student['email']) . '</p>';
        $html .= '<p>Group ' . e($student['group_name'] ?: 'No group') . '</p>';
        $html .= '<p>' . account_badge($student['is_active']) . '</p>';
        $html .= '<p>' . e($student['last_login_at'] ? hk_full((string) $student['last_login_at']) : 'Has not signed in') . '</p>';
        $html .= '<p>Last email ' . last_email_html($student['delivery_status'] !== null ? (string) $student['delivery_status'] : null) . '</p>';
        if (($student['delivery_status'] ?? '') === 'failed' && ($student['error_message'] ?? '') !== '') {
            $html .= '<p>' . e((string) $student['error_message']) . '</p>';
        }
        $html .= '<div class="table-actions">';
        if ($mailReady) {
            $html .= '<button type="button" class="btn btn-link email-again" data-id="' . e($student['student_id']) . '" data-name="' . e($student['display_name']) . '">Email again</button>';
        }
        if ((int) $student['is_active'] === 1) {
            $html .= '<button type="button" class="btn btn-link text-danger disable-student" data-id="' . e($student['student_id']) . '" data-name="' . e($student['display_name']) . '">Disable</button>';
        } else {
            $html .= '<button type="button" class="btn btn-link enable-student" data-id="' . e($student['student_id']) . '" data-name="' . e($student['display_name']) . '">Enable</button>';
        }
        $html .= '</div>';
        json_out(['ok' => true, 'title' => (string) $student['display_name'], 'html' => $html]);
    }

    if (($_GET['ajax'] ?? '') === 'rows') {
        $query = table_query('ASC');
        $filter = one_of($query['filter'], ['all', 'never', 'failed', 'disabled'], 'all');
        $params = [$examId];
        $where = 'WHERE s.exam_id = ?';
        if ($filter === 'never') {
            $where .= ' AND s.last_login_at IS NULL';
        } elseif ($filter === 'failed') {
            $where .= ' AND d.status = "failed"';
        } elseif ($filter === 'disabled') {
            $where .= ' AND s.is_active = 0';
        }
        if ($query['search'] !== '') {
            $like = search_like($query['search']);
            $where .= ' AND (s.display_name ' . sql_like() . ' OR s.student_id ' . sql_like() . ' OR s.email ' . sql_like() . ')';
            array_push($params, $like, $like, $like);
        }
        $sort = sort_sql($query['sort'], [
            'name' => 's.display_name',
            'student_id' => 's.student_id',
            'email' => 's.email',
            'group' => 'g.display_name',
            'signin' => 's.last_login_at',
        ], 's.display_name');
        $total = (int) db_value(student_count_sql($where), $params);
        $rows = db_all(student_sql($where, $sort, $query['order']) . sql_page($query['limit'], $query['offset']), $params);
        $any = (int) db_value('SELECT COUNT(*) FROM student_account WHERE exam_id = ?', [$examId]);
        $mapped = [];
        foreach ($rows as $student) {
            $actions = '';
            if ($mailReady) {
                $actions .= '<button type="button" class="btn btn-link email-again" data-id="' . e($student['student_id']) . '" data-name="' . e($student['display_name']) . '">Email again</button>';
            }
            if ((int) $student['is_active'] === 1) {
                $actions .= '<button type="button" class="btn btn-link text-danger disable-student" data-id="' . e($student['student_id']) . '" data-name="' . e($student['display_name']) . '">Disable</button>';
            } else {
                $actions .= '<button type="button" class="btn btn-link enable-student" data-id="' . e($student['student_id']) . '" data-name="' . e($student['display_name']) . '">Enable</button>';
            }
            $mapped[] = [
                'id' => (string) $student['student_id'],
                'name' => (string) $student['display_name'],
                'student_id' => (string) $student['student_id'],
                'email' => (string) $student['email'],
                'group' => $student['group_name'] ?: 'No group',
                'account' => account_badge($student['is_active']),
                'signin' => $student['last_login_at'] ? hk_full((string) $student['last_login_at']) : 'Has not signed in',
                'last_email' => last_email_html($student['delivery_status'] !== null ? (string) $student['delivery_status'] : null),
                'actions' => $actions,
            ];
        }
        $emptyHtml = '';
        if ($any === 0) {
            $emptyHtml = '<div class="empty-state"><p>This exam has no students yet.</p>';
            if (!$completed) {
                $emptyHtml .= '<button type="button" class="btn btn-poly" data-bs-toggle="collapse" data-bs-target="#import-panel">Import students</button>';
            }
            $emptyHtml .= '</div>';
        }
        json_out(['total' => $total, 'rows' => $mapped, 'emptyHtml' => $emptyHtml]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_post_csrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'download') {
            $result = download_credentials($exam, (string) $admin['username']);
            if (!$result['ok']) {
                respond_back($result, $back);
            }
            flash('Downloaded credentials for ' . (int) $result['count'] . ' students.');
            session_write_close();
            send_download((string) $result['path'], (string) $result['filename']);
        }
        if ($completed && in_array($action, ['preview_import', 'commit_import'], true)) {
            respond_back(['ok' => false, 'message' => 'This exam is completed.'], $back);
        }
        if ($action === 'preview_import') {
            $file = $_FILES['import_file'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
                respond_back(['ok' => false, 'message' => 'Choose a spreadsheet first.'], $back);
            }
            if (($file['size'] ?? 0) > 2_000_000) {
                respond_back(['ok' => false, 'message' => 'That file is too large.'], $back);
            }
            try {
                $matrix = read_xlsx_matrix((string) $file['tmp_name']);
            } catch (Throwable $e) {
                error_log($e->__toString());
                respond_back(['ok' => false, 'message' => 'That file could not be read. Use one of the example spreadsheets.'], $back);
            }
            [$ids, $groups] = existing_import_context($examId);
            $preview = classify_import($matrix, $ids, $groups);
            if (!$preview['ok']) {
                respond_back(['ok' => false, 'message' => $preview['message']], $back);
            }
            store_import_preview($examId, $preview);
            $ready = import_ready_count($preview['rows']);
            respond_back(['ok' => true, 'message' => 'Preview ready. ' . $ready . ' rows can be imported.'], $back);
        }
        if ($action === 'commit_import') {
            $preview = take_import_preview($examId);
            if ($preview === null) {
                respond_back(['ok' => false, 'message' => 'Upload the spreadsheet again.'], $back);
            }
            $result = commit_import($examId, (string) $admin['username'], $preview);
            clear_import_preview($examId);
            $message = 'Imported ' . $result['imported'] . ' students.';
            if ($result['skipped'] > 0) {
                $message .= ' ' . $result['skipped'] . ' rows were skipped.';
            }
            respond_back(['ok' => true, 'message' => $message], $back);
        }
        if ($action === 'email_again') {
            $student = db_one(
                'SELECT student_id, display_name, email FROM student_account WHERE student_id = ? AND exam_id = ?',
                [post_str('student_id'), $examId]
            );
            if ($student === null) {
                $result = ['ok' => false, 'message' => 'That student is not in this exam.'];
            } elseif (!$mailReady) {
                $result = ['ok' => false, 'message' => 'Email is not configured. Download the spreadsheet instead.'];
            } else {
                $again = student_has_delivery($examId, (string) $student['student_id']);
                $result = issue_email_credential($exam, $student, (string) $admin['username'], $again);
                if (!empty($result['ok'])) {
                    $result['message'] = 'A new entry password was sent to ' . $student['display_name'] . '.';
                    $result['row'] = (string) $student['student_id'];
                }
            }
            $reopen = is_array($student) ? (string) $student['student_id'] : null;
            respond_back($result, $back, $reopen);
        }
        if ($action === 'disable') {
            $result = set_student_active_action($admin, $examId, post_str('student_id'), false, post_str('confirm_code'));
            respond_back($result, $back, post_str('student_id'));
        }
        if ($action === 'enable') {
            $result = set_student_active_action($admin, $examId, post_str('student_id'), true, '');
            respond_back($result, $back, post_str('student_id'));
        }
        respond_back(['ok' => false, 'message' => 'That action is not available.'], $back);
    }

    $filter = one_of((string) ($_GET['filter'] ?? 'all'), ['all', 'never', 'failed', 'disabled'], 'all');
    $body = '';
    if (!$mailReady) {
        $body .= '<div class="alert alert-warning" role="status">Email is not configured. Download the spreadsheet instead.</div>';
    }
    $body .= '<p>Accounts on this page belong to this exam only. The same student ID can exist in another exam.</p>';
    $body .= filter_bar('Students', [
        'all' => 'All',
        'never' => 'Has not signed in',
        'failed' => 'Email failed',
        'disabled' => 'Disabled',
    ], $filter);
    if (!$completed) {
        $preview = take_import_preview($examId);
        $open = is_array($preview) && !empty($preview['rows']) ? ' show' : '';
        $body .= '<div class="collapse' . $open . '" id="import-panel"><div class="card card-body mb-3">';
        $body .= '<div class="example-actions">';
        $body .= '<div><a class="btn btn-outline-secondary" href="' . e(app_url('excel_example/import_grp.xlsx')) . '" download>Download group example</a><p class="form-text">Same Group ID means the same group. Empty Group Name becomes "Group" plus that ID.</p></div>';
        $body .= '<div><a class="btn btn-outline-secondary" href="' . e(app_url('excel_example/import_indv.xlsx')) . '" download>Download individual example</a><p class="form-text">Each row is a group of one. The group name is the student name. If two students share a name, the student ID is added.</p></div>';
        $body .= '</div>';
        $body .= '<form method="post" enctype="multipart/form-data" class="mt-3">' . csrf_input() . '<input type="hidden" name="action" value="preview_import"><input type="hidden" name="exam_id" value="' . e($examId) . '">';
        $body .= '<div class="file-drop"><label class="form-label" for="import_file">Spreadsheet</label><input class="form-control" type="file" id="import_file" name="import_file" accept=".xlsx" required><p class="file-drop-note" aria-live="polite">Drop an .xlsx file here, or choose a file.</p></div>';
        $body .= '<button class="btn btn-outline-secondary mt-3" type="submit">Preview</button></form>';
        if (is_array($preview) && !empty($preview['rows'])) {
            $ready = import_ready_count($preview['rows']);
            $body .= '<div class="mt-3"><table id="import-preview-table" data-client-table data-ignore-filter="true"><thead><tr><th data-sortable="true">Student ID</th><th data-sortable="true">Name</th><th data-sortable="true">Email</th><th data-sortable="true">Group</th><th data-sortable="true">Result of check</th></tr></thead><tbody>';
            foreach ($preview['rows'] as $row) {
                $body .= '<tr><td>' . e($row['student_id']) . '</td><td>' . e($row['name']) . '</td><td>' . e($row['email']) . '</td><td>' . e($row['group_name']) . '</td><td>' . e($row['result']) . '</td></tr>';
            }
            $body .= '</tbody></table></div>';
            if ($ready > 0) {
                $body .= '<form method="post" class="js-form">' . csrf_input() . '<input type="hidden" name="action" value="commit_import"><input type="hidden" name="exam_id" value="' . e($examId) . '"><button class="btn btn-poly" type="submit">Import ' . e((string) $ready) . ' ready rows</button></form>';
            }
        }
        $body .= '</div></div>';
    }
    $body .= '<div id="list-empty" hidden></div>' . list_error_html() . '<table id="student-table"></table>';
    $body .= modal_start('email-modal', 'Email again', 'email_again', ['exam_id' => $examId]);
    $body .= '<input type="hidden" name="student_id" id="email-id" value="">';
    $body .= '<p id="email-text"></p>';
    $body .= modal_end('Send a new password', false, 'Sending');
    $body .= modal_start('disable-modal', 'Disable this student', 'disable', ['exam_id' => $examId]);
    $body .= '<input type="hidden" name="student_id" id="disable-id" value="">';
    $body .= '<p id="disable-text"></p>';
    $body .= text_field('confirm_code', 'Student ID', '', ['id' => 'disable-code', 'match' => '']);
    $body .= modal_end('Disable for this exam', true, 'Saving', true);
    $body .= modal_start('enable-modal', 'Enable this student', 'enable', ['exam_id' => $examId]);
    $body .= '<input type="hidden" name="student_id" id="enable-id" value="">';
    $body .= '<p id="enable-text"></p>';
    $body .= modal_end('Enable for this exam');
    $body .= modal_start('download-modal', 'Download credentials', 'download', ['exam_id' => $examId], 'js-download');
    $body .= '<p>This file is the only copy of these passwords. Downloading replaces any password already sent.</p>';
    $body .= modal_end('Download credentials');
    $body .= detail_canvas('Student');
    $boot = json_encode(['url' => $detailUrl, 'detailUrl' => $detailUrl], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $body .= '<script>
    document.addEventListener("DOMContentLoaded", function () {
        const boot = ' . $boot . ';
        mountServerTable("#student-table", {
            url: boot.url,
            detailUrl: boot.detailUrl,
            sortName: "name",
            sortOrder: "asc",
            columns: [
                { field: "name", title: "Name", sortable: true, escape: true },
                { field: "student_id", title: "Student ID", sortable: true, escape: true },
                { field: "email", title: "Email", sortable: true, escape: true },
                { field: "group", title: "Group", sortable: true, escape: true },
                { field: "account", title: "Account", escape: false },
                { field: "signin", title: "Last sign-in", sortable: true, escape: true },
                { field: "last_email", title: "Last email", escape: false },
                { field: "actions", title: "Action", escape: false }
            ]
        });
        document.addEventListener("click", function (event) {
            const email = event.target.closest(".email-again");
            if (email) {
                document.getElementById("email-id").value = email.dataset.id || "";
                document.getElementById("email-text").textContent = (email.dataset.name || "This student") + " will receive a new entry password. The old password will stop working.";
                bootstrap.Modal.getOrCreateInstance(document.getElementById("email-modal")).show();
                return;
            }
            const disable = event.target.closest(".disable-student");
            if (disable) {
                document.getElementById("disable-id").value = disable.dataset.id || "";
                const code = document.getElementById("disable-code");
                code.dataset.match = disable.dataset.id || "";
                code.value = "";
                const submit = document.querySelector("#disable-modal button[type=submit]");
                if (submit) { submit.disabled = true; }
                document.getElementById("disable-text").textContent = "Disable " + (disable.dataset.name || "this student") + " for this exam only. The group and any time slot stay. Type the student ID to confirm.";
                bootstrap.Modal.getOrCreateInstance(document.getElementById("disable-modal")).show();
                return;
            }
            const enable = event.target.closest(".enable-student");
            if (enable) {
                document.getElementById("enable-id").value = enable.dataset.id || "";
                document.getElementById("enable-text").textContent = (enable.dataset.name || "This student") + " can sign in to this exam again.";
                bootstrap.Modal.getOrCreateInstance(document.getElementById("enable-modal")).show();
            }
        });
    });
    </script>';

    $actions = '';
    if (!$completed) {
        $actions .= '<button class="btn btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#import-panel">Import more students</button>';
    }
    $actions .= '<button class="btn btn-poly" type="button" data-bs-toggle="modal" data-bs-target="#download-modal">Download credentials</button>';
    render_admin($admin, [
        'title' => 'Students',
        'subtitle' => 'Each student signs in to this exam with the student ID from the spreadsheet.',
        'nav' => 'exams',
        'exam' => $exam,
        'exam_nav' => 'students',
        'crumbs' => exam_crumbs($exam, 'Students'),
        'actions' => $actions,
    ], $body);
} catch (Throwable $e) {
    page_fail($e);
}

function student_from(): string
{
    return 'FROM student_account s
         LEFT JOIN group_member gm ON gm.student_id = s.student_id AND gm.exam_id = s.exam_id
         LEFT JOIN project_group g ON g.group_id = gm.group_id
         LEFT JOIN credential_delivery d ON d.delivery_id = (
            SELECT d2.delivery_id FROM credential_delivery d2
            WHERE d2.exam_id = s.exam_id AND d2.student_id = s.student_id
            ORDER BY d2.requested_at DESC, d2.delivery_id DESC
            LIMIT 1
         )';
}

function student_count_sql(string $where): string
{
    return 'SELECT COUNT(*) ' . student_from() . ' ' . $where;
}

function student_sql(string $where, string $sort, string $order): string
{
    return 'SELECT s.student_id, s.display_name, s.email, s.is_active, s.last_login_at,
                g.display_name AS group_name, d.status AS delivery_status, d.error_message
         ' . student_from() . '
         ' . $where . '
         ORDER BY ' . $sort . ' ' . $order . ', s.student_id ASC';
}

function student_row(string $examId, string $studentId): ?array
{
    return db_one(student_sql('WHERE s.exam_id = ? AND s.student_id = ?', 's.display_name', 'ASC'), [$examId, $studentId]);
}

function last_email_html(?string $status): string
{
    if ($status === null || $status === '') {
        return 'Not sent';
    }
    if ($status === 'failed') {
        return '<span class="text-danger">Failed</span>';
    }
    return delivery_badge($status);
}
