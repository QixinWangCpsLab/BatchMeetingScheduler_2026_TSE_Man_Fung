<?php
declare(strict_types=1);

require_once __DIR__ . '/../components/bootstrap.php';

try {
    $admin = require_admin_page();
    $exam = require_exam($admin, (string) ($_GET['exam'] ?? $_POST['exam_id'] ?? ''));
    $examId = (string) $exam['exam_id'];
    $back = exam_href('exam-access.php', $examId);
    $detailUrl = request_path() . '?exam=' . rawurlencode($examId);
    $canGrant = can_grant_access($admin, $exam);

    if (($_GET['ajax'] ?? '') === 'admins') {
        if (!$canGrant) {
            json_out(['ok' => false, 'message' => 'Only the person who created this exam, or a professor, can add another admin.'], 403);
        }
        $term = search_like((string) ($_GET['q'] ?? ''));
        $rows = $term === '%%' ? [] : db_all(
            'SELECT username, display_name, role FROM admin_user
             WHERE is_active = 1
               AND username NOT IN (SELECT admin_username FROM exam_admin WHERE exam_id = ?)
               AND (username ' . sql_like() . ' OR display_name ' . sql_like() . ')
             ORDER BY display_name ASC
             LIMIT 8',
            [$examId, $term, $term]
        );
        json_out([
            'ok' => true,
            'rows' => $rows,
            'empty' => 'No other active admin has that name.',
            'canCreate' => ($admin['role'] ?? '') === 'prof',
            'adminsUrl' => app_url('admin/admins.php'),
        ]);
    }

    if (($_GET['ajax'] ?? '') === 'detail') {
        $row = access_row($examId, (string) ($_GET['id'] ?? ''));
        if ($row === null) {
            json_out(['ok' => false, 'message' => 'That admin is not on this exam.'], 404);
        }
        $html = '<p>' . e($row['display_name']) . '</p>';
        $html .= '<p>' . e($row['username']) . ' · ' . e(role_label((string) $row['role'])) . '</p>';
        $html .= '<p>' . e((string) $row['email']) . '</p>';
        $html .= '<p>Access given ' . e(hk_full((string) $row['granted_at'])) . '</p>';
        $html .= '<p>Given by ' . e($row['given_by'] ?: 'Unknown') . '</p>';
        if ($row['username'] === $exam['created_by_admin_username']) {
            $html .= '<p>The person who created this exam must keep access.</p>';
        } elseif ($canGrant) {
            $html .= '<button type="button" class="btn btn-outline-secondary text-danger remove-access" data-username="' . e($row['username']) . '" data-name="' . e($row['display_name']) . '">Remove</button>';
        }
        json_out(['ok' => true, 'title' => (string) $row['display_name'], 'html' => $html]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_post_csrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'grant') {
            $result = grant_admin_action($admin, $examId, post_str('username'));
        } elseif ($action === 'revoke') {
            $result = revoke_admin_action($admin, $examId, post_str('username'));
        } else {
            $result = ['ok' => false, 'message' => 'That action is not available.'];
        }
        respond_back($result, $back);
    }

    $people = db_all(
        'SELECT ea.admin_username, ea.granted_at, u.display_name, u.username, u.role, u.email, g.display_name AS given_by
         FROM exam_admin ea
         JOIN admin_user u ON u.username = ea.admin_username
         LEFT JOIN admin_user g ON g.username = ea.granted_by_admin_username
         WHERE ea.exam_id = ?
         ORDER BY u.display_name ASC, u.username ASC',
        [$examId]
    );
    $body = '';
    if (!$canGrant) {
        $body .= '<p>Only the person who created this exam, or a professor, can change who manages this exam.</p>';
    } else {
        $body .= '<form class="mb-3" id="admin-search-form"><label class="form-label" for="admin-q">Add an admin</label><input class="form-control" id="admin-q" name="q" autocomplete="off"><div id="admin-results"></div></form>';
    }
    if ($people === []) {
        $body .= empty_state('Nobody can manage this exam yet.');
    } else {
        $body .= '<table id="access-table" data-client-table data-detail-url="' . e($detailUrl) . '" data-sort-name="name" data-sort-order="asc"><thead><tr>';
        $body .= '<th data-field="name" data-sortable="true">Name</th><th data-field="id" data-sortable="true">Username</th><th data-field="role">Role</th><th data-field="given" data-sortable="true">Access given</th><th data-field="by">Given by</th><th>Action</th>';
        $body .= '</tr></thead><tbody>';
        foreach ($people as $row) {
            $body .= '<tr data-id="' . e($row['username']) . '">';
            $body .= '<td>' . e($row['display_name']) . '</td>';
            $body .= '<td>' . e($row['username']) . '</td>';
            $body .= '<td>' . role_badge((string) $row['role']) . '</td>';
            $body .= '<td>' . e(hk_full((string) $row['granted_at'])) . '</td>';
            $body .= '<td>' . e($row['given_by'] ?: 'Unknown') . '</td><td>';
            if ($row['username'] === $exam['created_by_admin_username']) {
                $body .= '<span class="form-text">The person who created this exam must keep access.</span>';
            } elseif ($canGrant) {
                $body .= '<button type="button" class="btn btn-link text-danger remove-access" data-username="' . e($row['username']) . '" data-name="' . e($row['display_name']) . '">Remove</button>';
            }
            $body .= '</td></tr>';
        }
        $body .= '</tbody></table>';
    }
    if ($canGrant) {
        $body .= modal_start('remove-modal', 'Remove access', 'revoke', ['exam_id' => $examId]);
        $body .= '<input type="hidden" name="username" id="remove-username" value="">';
        $body .= '<p id="remove-text"></p>';
        $body .= modal_end('Remove access', true);
    }
    $body .= detail_canvas('Access');
    $boot = json_encode([
        'csrf' => csrf_input() . '<input type="hidden" name="action" value="grant"><input type="hidden" name="exam_id" value="' . e($examId) . '">',
        'title' => (string) $exam['title'],
        'canCreate' => ($admin['role'] ?? '') === 'prof',
        'adminsUrl' => app_url('admin/admins.php'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $body .= '<script>
    document.addEventListener("DOMContentLoaded", function () {
        const boot = ' . $boot . ';
        const searchForm = document.getElementById("admin-search-form");
        const input = document.getElementById("admin-q");
        const box = document.getElementById("admin-results");
        if (searchForm && input && box) {
            let timer = 0;
            function runSearch() {
                const url = new URL(location.href);
                url.searchParams.set("ajax", "admins");
                url.searchParams.set("q", input.value);
                fetch(url, { headers: { Accept: "application/json", "X-Requested-With": "fetch" } })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        box.replaceChildren();
                        if (!data.rows || data.rows.length === 0) {
                            if (input.value.trim() === "") { return; }
                            const note = document.createElement("p");
                            note.textContent = data.empty || "No other active admin has that name.";
                            if (data.canCreate) {
                                note.appendChild(document.createTextNode(" Create one on the "));
                                const link = document.createElement("a");
                                link.href = data.adminsUrl || boot.adminsUrl;
                                link.textContent = "Admins";
                                note.appendChild(link);
                                note.appendChild(document.createTextNode(" page."));
                            }
                            box.appendChild(note);
                            return;
                        }
                        data.rows.forEach(function (row) {
                            const form = document.createElement("form");
                            form.method = "post";
                            form.className = "js-form d-inline me-2";
                            form.innerHTML = boot.csrf;
                            const hidden = document.createElement("input");
                            hidden.type = "hidden";
                            hidden.name = "username";
                            hidden.value = row.username;
                            form.appendChild(hidden);
                            const button = document.createElement("button");
                            button.className = "btn btn-outline-secondary btn-sm mb-2";
                            button.type = "submit";
                            button.textContent = "Add " + row.display_name;
                            form.appendChild(button);
                            box.appendChild(form);
                        });
                    })
                    .catch(function () {
                        if (window.toast) {
                            window.toast("Could not load this list. Try again", "danger");
                        }
                    });
            }
            searchForm.addEventListener("submit", function (event) {
                event.preventDefault();
                clearTimeout(timer);
                runSearch();
            });
            input.addEventListener("input", function () {
                clearTimeout(timer);
                timer = setTimeout(runSearch, 300);
            });
        }
        document.addEventListener("click", function (event) {
            const button = event.target.closest(".remove-access");
            if (!button) { return; }
            const form = document.querySelector("#remove-modal form");
            if (!form) { return; }
            form.querySelector("#remove-username").value = button.dataset.username || "";
            document.getElementById("remove-text").textContent = (button.dataset.name || "This admin") + " will no longer see " + boot.title + ".";
            bootstrap.Modal.getOrCreateInstance(document.getElementById("remove-modal")).show();
        });
    });
    </script>';

    render_admin($admin, [
        'title' => 'Access',
        'subtitle' => 'People listed here can open this exam.',
        'nav' => 'exams',
        'exam' => $exam,
        'exam_nav' => 'access',
        'crumbs' => exam_crumbs($exam, 'Access'),
    ], $body);
} catch (Throwable $e) {
    page_fail($e);
}

function access_row(string $examId, string $username): ?array
{
    return db_one(
        'SELECT ea.granted_at, u.display_name, u.username, u.role, u.email, g.display_name AS given_by
         FROM exam_admin ea
         JOIN admin_user u ON u.username = ea.admin_username
         LEFT JOIN admin_user g ON g.username = ea.granted_by_admin_username
         WHERE ea.exam_id = ? AND ea.admin_username = ?',
        [$examId, $username]
    );
}
