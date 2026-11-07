<?php
declare(strict_types=1);

require_once __DIR__ . '/../components/bootstrap.php';

try {
    $admin = require_admin_page();
    $exam = require_exam($admin, (string) ($_GET['exam'] ?? $_POST['exam_id'] ?? ''));
    $examId = (string) $exam['exam_id'];
    $back = exam_href('exam-deliveries.php', $examId);
    $detailUrl = request_path() . '?exam=' . rawurlencode($examId);
    $mailReady = mail_is_configured();
    $pendingStudents = students_without_delivery($examId);
    $pendingCount = count($pendingStudents);

    if (($_GET['ajax'] ?? '') === 'detail') {
        $row = db_one(delivery_sql('WHERE d.exam_id = ? AND d.delivery_id = ?', 'd.requested_at', 'DESC'), [$examId, (string) ($_GET['id'] ?? '')]);
        if ($row === null) {
            json_out(['ok' => false, 'message' => 'That email record was not found.'], 404);
        }
        $what = delivery_what($row);
        $html = '<p>' . e($row['display_name']) . ' (' . e($row['student_id']) . ')</p>';
        $html .= '<p>' . e((string) $row['email']) . '</p>';
        $html .= '<p>' . e($what) . '</p>';
        $html .= '<p>' . delivery_badge((string) $row['status']) . '</p>';
        $html .= '<p>Requested ' . e(hk_full((string) $row['requested_at'])) . '</p>';
        $html .= '<p>Finished ' . e($row['completed_at'] ? hk_full((string) $row['completed_at']) : '—') . '</p>';
        $html .= '<p>Requested by ' . e($row['admin_name'] ?: 'System') . '</p>';
        if (($row['status'] ?? '') === 'failed' && ($row['error_message'] ?? '') !== '') {
            $html .= '<p>' . e((string) $row['error_message']) . '</p>';
        }
        if ($row['status'] === 'failed' && $mailReady) {
            $html .= '<button type="button" class="btn btn-outline-secondary retry-mail" data-id="' . e($row['delivery_id']) . '" data-name="' . e($row['display_name']) . '">Retry</button>';
        }
        $html .= reference_line((string) $row['delivery_id']);
        json_out(['ok' => true, 'title' => (string) $row['display_name'], 'html' => $html]);
    }

    if (($_GET['ajax'] ?? '') === 'rows') {
        $query = table_query('DESC');
        $filter = one_of($query['filter'], ['all', 'failed'], 'all');
        $params = [$examId];
        $where = 'WHERE d.exam_id = ?';
        if ($filter === 'failed') {
            $where .= ' AND d.status = "failed"';
        }
        if ($query['search'] !== '') {
            $like = search_like($query['search']);
            $where .= ' AND (s.display_name ' . sql_like() . ' OR s.student_id ' . sql_like() . ' OR s.email ' . sql_like() . ' OR IFNULL(u.display_name, "") ' . sql_like() . ')';
            array_push($params, $like, $like, $like, $like);
        }
        $sort = sort_sql($query['sort'], [
            'when' => 'd.requested_at',
            'student' => 's.display_name',
            'email' => 's.email',
            'status' => 'd.status',
            'finished' => 'd.completed_at',
        ], 'd.requested_at');
        $total = (int) db_value('SELECT COUNT(*) ' . delivery_from() . ' ' . $where, $params);
        $rows = db_all(delivery_sql($where, $sort, $query['order']) . sql_page($query['limit'], $query['offset']), $params);
        $any = (int) db_value('SELECT COUNT(*) FROM credential_delivery WHERE exam_id = ?', [$examId]);
        $mapped = [];
        foreach ($rows as $row) {
            $actions = '';
            if ($row['status'] === 'failed' && $mailReady) {
                $actions = '<button type="button" class="btn btn-link retry-mail" data-id="' . e($row['delivery_id']) . '" data-name="' . e($row['display_name']) . '">Retry</button>';
            }
            $mapped[] = [
                'id' => (string) $row['delivery_id'],
                'when' => hk_full((string) $row['requested_at']),
                'student' => $row['display_name'] . ' (' . $row['student_id'] . ')',
                'email' => (string) $row['email'],
                'what' => delivery_what($row),
                'status' => delivery_badge((string) $row['status']),
                'finished' => $row['completed_at'] ? hk_full((string) $row['completed_at']) : '—',
                'by' => $row['admin_name'] ?: 'System',
                'actions' => $actions,
            ];
        }
        $emptyHtml = '';
        if ($any === 0 && $query['search'] === '') {
            $emptyHtml = '<div class="empty-state"><p>No email has been sent for this exam yet.</p>';
            if ($mailReady && $pendingCount > 0) {
                $emptyHtml .= '<button type="button" class="btn btn-poly" data-bs-toggle="modal" data-bs-target="#send-modal">Send to everyone who has not received mail</button>';
            } elseif (!$mailReady) {
                $emptyHtml .= '<a class="btn btn-poly" href="' . e(app_url(exam_href('exam-students.php', $examId))) . '">Download the spreadsheet</a>';
            }
            $emptyHtml .= '</div>';
        }
        json_out(['total' => $total, 'rows' => $mapped, 'emptyHtml' => $emptyHtml]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_post_csrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'send_pending') {
            if (!$mailReady) {
                respond_back(['ok' => false, 'message' => 'Email is not configured. You can still download the spreadsheet.'], $back);
            }
            $students = students_without_delivery($examId);
            if ($students === []) {
                respond_back(['ok' => true, 'message' => 'Every student has already received an entry code.'], $back);
            }
            $batch = send_entry_batch($exam, $students, (string) $admin['username'], false);
            if (!empty($batch['message']) && (int) ($batch['sent'] ?? 0) === 0 && empty($batch['ok'])) {
                respond_back(['ok' => false, 'message' => (string) $batch['message']], $back);
            }
            $message = 'Sent ' . (int) $batch['sent'] . ' entry codes.';
            if ((int) $batch['failed'] > 0) {
                $message .= ' ' . (int) $batch['failed'] . ' could not be sent.';
            }
            respond_back(['ok' => true, 'message' => $message], $back);
        }
        if ($action === 'retry') {
            $delivery = db_one(
                'SELECT d.delivery_id, d.status, d.student_id, s.display_name, s.email
                 FROM credential_delivery d
                 JOIN student_account s ON s.student_id = d.student_id AND s.exam_id = d.exam_id
                 WHERE d.delivery_id = ? AND d.exam_id = ?',
                [post_str('delivery_id'), $examId]
            );
            if ($delivery === null || $delivery['status'] !== 'failed') {
                respond_back(['ok' => false, 'message' => 'Only a failed email can be retried.'], $back);
            }
            if (!$mailReady) {
                respond_back(['ok' => false, 'message' => 'Email is not configured. You can still download the spreadsheet.'], $back);
            }
            $result = issue_email_credential($exam, $delivery, (string) $admin['username'], true);
            if (!empty($result['ok'])) {
                $result['message'] = 'A new entry password was sent to ' . $delivery['display_name'] . '.';
            }
            respond_back($result, $back);
        }
        if ($action === 'download') {
            $result = download_credentials($exam, (string) $admin['username']);
            if (!$result['ok']) {
                respond_back($result, $back);
            }
            flash('Downloaded credentials for ' . (int) $result['count'] . ' students.');
            session_write_close();
            send_download((string) $result['path'], (string) $result['filename']);
        }
        respond_back(['ok' => false, 'message' => 'That action is not available.'], $back);
    }

    $body = '';
    if (!$mailReady) {
        $body .= '<div class="alert alert-warning" role="status">Email is not configured. You can still download the spreadsheet.</div>';
    }
    if ($pendingCount === 0) {
        $body .= '<p>Every student has already received an entry code. To replace a password that was already sent, use Email again on the student page.</p>';
    } else {
        $noun = $pendingCount === 1 ? 'student has' : 'students have';
        $body .= '<p>' . e((string) $pendingCount) . ' ' . $noun . ' not been sent an entry code. Students who were already sent a code, or whose spreadsheet was downloaded, are not included.</p>';
    }
    $body .= filter_bar('Email', ['all' => 'All', 'failed' => 'Failed'], one_of((string) ($_GET['filter'] ?? 'all'), ['all', 'failed'], 'all'));
    $body .= '<div id="list-empty" hidden></div>' . list_error_html() . '<table id="delivery-table"></table>';
    if ($mailReady && $pendingCount > 0) {
        $sentence = 'Send entry codes to ' . $pendingCount . ' ' . ($pendingCount === 1 ? 'student' : 'students') . '.';
        $body .= modal_start('send-modal', 'Send entry codes', 'send_pending', ['exam_id' => $examId]);
        $body .= '<p>' . e($sentence) . '</p>';
        $body .= '<p>To replace a password that was already sent, use Email again on the student page.</p>';
        $body .= modal_end($sentence, false, 'Sending');
    }
    $body .= modal_start('retry-modal', 'Retry this email', 'retry', ['exam_id' => $examId]);
    $body .= '<input type="hidden" name="delivery_id" id="retry-id" value="">';
    $body .= '<p id="retry-text"></p>';
    $body .= modal_end('Send a new password', false, 'Sending');
    $body .= modal_start('download-modal', 'Download credentials', 'download', ['exam_id' => $examId], 'js-download');
    $body .= '<p>This file is the only copy of these passwords. Downloading replaces any password already sent.</p>';
    $body .= modal_end('Download credentials');
    $body .= detail_canvas('Email');
    $boot = json_encode(['url' => $detailUrl, 'detailUrl' => $detailUrl], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $body .= '<script>
    document.addEventListener("DOMContentLoaded", function () {
        const boot = ' . $boot . ';
        mountServerTable("#delivery-table", {
            url: boot.url,
            detailUrl: boot.detailUrl,
            sortName: "when",
            sortOrder: "desc",
            columns: [
                { field: "when", title: "Time", sortable: true, escape: true },
                { field: "student", title: "Student", sortable: true, escape: true },
                { field: "email", title: "Email", sortable: true, escape: true },
                { field: "what", title: "Event", escape: true },
                { field: "status", title: "Status", sortable: true, escape: false },
                { field: "finished", title: "Finished", sortable: true, escape: true },
                { field: "by", title: "Requested by", escape: true },
                { field: "actions", title: "Action", escape: false }
            ]
        });
        document.addEventListener("click", function (event) {
            const button = event.target.closest(".retry-mail");
            if (!button) { return; }
            document.getElementById("retry-id").value = button.dataset.id || "";
            document.getElementById("retry-text").textContent = (button.dataset.name || "This student") + " will receive a new entry password. The old password will stop working. The failed row stays in the list.";
            bootstrap.Modal.getOrCreateInstance(document.getElementById("retry-modal")).show();
        });
    });
    </script>';

    $actions = '';
    if ($mailReady && $pendingCount > 0) {
        $actions .= '<button class="btn btn-poly" type="button" data-bs-toggle="modal" data-bs-target="#send-modal">Send to everyone who has not received mail</button>';
    }
    $actions .= '<button class="btn btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#download-modal">Download the spreadsheet</button>';
    render_admin($admin, [
        'title' => 'Email',
        'subtitle' => 'Entry codes are emailed from here. A failed send stays in the list.',
        'nav' => 'exams',
        'exam' => $exam,
        'exam_nav' => 'email',
        'crumbs' => exam_crumbs($exam, 'Email'),
        'actions' => $actions,
    ], $body);
} catch (Throwable $e) {
    page_fail($e);
}

function delivery_from(): string
{
    return 'FROM credential_delivery d
         JOIN student_account s ON s.student_id = d.student_id AND s.exam_id = d.exam_id
         LEFT JOIN admin_user u ON u.username = d.requested_by_admin_username';
}

function delivery_sql(string $where, string $sort, string $order): string
{
    return 'SELECT d.delivery_id, d.student_id, d.status, d.requested_at, d.completed_at, d.error_message,
                s.display_name, s.email, u.display_name AS admin_name,
                (SELECT COUNT(*) FROM credential_delivery earlier
                  WHERE earlier.exam_id = d.exam_id AND earlier.student_id = d.student_id
                    AND (earlier.requested_at < d.requested_at
                         OR (earlier.requested_at = d.requested_at AND earlier.delivery_id < d.delivery_id))
                ) AS earlier_count
         ' . delivery_from() . '
         ' . $where . '
         ORDER BY ' . $sort . ' ' . $order . ', d.delivery_id DESC';
}

function delivery_what(array $row): string
{
    if (($row['status'] ?? '') === 'downloaded') {
        return 'Downloaded by admin';
    }
    return (int) ($row['earlier_count'] ?? 0) === 0 ? 'Entry code' : 'New password';
}
