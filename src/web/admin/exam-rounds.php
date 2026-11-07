<?php
declare(strict_types=1);

require_once __DIR__ . '/../components/bootstrap.php';

try {
    $admin = require_admin_page();
    $exam = require_exam($admin, (string) ($_GET['exam'] ?? $_POST['exam_id'] ?? ''));
    $examId = (string) $exam['exam_id'];
    $acceptId = (string) ($_GET['accept'] ?? '');

    if (($_GET['ajax'] ?? '') === 'detail') {
        $round = db_one('SELECT r.*, u.display_name AS creator_name FROM exam_round r JOIN admin_user u ON u.username = r.created_by_admin_username WHERE r.round_id = ? AND r.exam_id = ?', [(string) ($_GET['id'] ?? ''), $examId]);
        if ($round === null) {
            json_out(['ok' => false, 'message' => 'That round was not found.'], 404);
        }
        $runs = db_all('SELECT m.*, (SELECT COUNT(*) FROM assignment a WHERE a.run_id = m.run_id) AS assignment_count FROM match_run m WHERE m.round_id = ? ORDER BY m.started_at DESC', [$round['round_id']]);
        $html = '<p>Opens ' . e(hk_full((string) $round['start_at'])) . '. Deadline ' . e(hk_full((string) $round['deadline'])) . '.</p>';
        $html .= '<p>Created by ' . e($round['creator_name']) . '.</p>';
        $html .= '<h3 class="h6">Acceptance runs</h3>';
        if ($runs === []) {
            $html .= '<p>No acceptance run yet.</p>';
        } else {
            $html .= '<table id="run-table" data-client-table data-ignore-filter="true"><thead><tr><th data-sortable="true">Started</th><th data-sortable="true">Finished</th><th data-sortable="true">Method</th><th data-sortable="true">Seed</th><th data-sortable="true">Assignments</th></tr></thead><tbody>';
            foreach ($runs as $run) {
                $how = $run['algorithm'] === 'sealed' ? 'Accepted after deadline' : 'Accepted when submitted';
                $html .= '<tr><td>' . e(hk_full((string) $run['started_at'])) . '</td><td>' . e($run['completed_at'] ? hk_full((string) $run['completed_at']) : '—') . '</td><td>' . e($how) . '</td><td>' . e($run['random_seed'] === null ? '—' : (string) $run['random_seed']) . '</td><td>' . e((string) $run['assignment_count']) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        json_out(['ok' => true, 'title' => 'Round ' . $round['round_index'], 'html' => $html]);
    }
    if (($_GET['ajax'] ?? '') === 'preview') {
        $seed = parse_seed((string) ($_GET['seed'] ?? '')) ?? random_seed();
        $preview = sealed_preview((string) ($_GET['round_id'] ?? ''), $seed);
        if (!$preview['ok']) {
            json_out($preview, 422);
        }
        $round = db_one('SELECT exam_id FROM exam_round WHERE round_id = ?', [(string) ($_GET['round_id'] ?? '')]);
        if ($round === null || $round['exam_id'] !== $examId) {
            json_out(['ok' => false, 'message' => 'That round was not found.'], 404);
        }
        json_out($preview);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        require_post_csrf();
        $action = (string) ($_POST['action'] ?? '');
        $back = 'admin/exam-rounds.php?exam=' . rawurlencode($examId);
        if ($action === 'open_round') {
            $result = open_round_action($admin, post_str('round_id'));
        } elseif ($action === 'close_round') {
            $result = close_round_action($admin, post_str('round_id'));
        } elseif ($action === 'save_time') {
            $result = save_round_time($admin, post_str('round_id'), post_str('start_at'), post_str('deadline'));
        } elseif ($action === 'add_round') {
            $result = add_round_action($admin, $examId, post_str('start_at'), post_str('deadline'));
        } elseif ($action === 'accept') {
            $seed = parse_seed(post_str('random_seed'));
            if ($seed === null) {
                $result = ['ok' => false, 'errors' => ['random_seed' => 'Enter a seed, or leave it blank and update the preview.'], 'message' => 'Enter a seed.'];
            } else {
                $result = accept_sealed_action($admin, post_str('round_id'), $seed);
            }
        } else {
            $result = ['ok' => false, 'message' => 'That action is not available.'];
        }
        $result['redirect'] = $result['redirect'] ?? $back;
        respond($result);
    }

    $rounds = db_all(
        'SELECT r.*, u.display_name AS creator_name,
            (SELECT COUNT(*) FROM preference_submission ps WHERE ps.round_id = r.round_id AND ps.status = "current") AS submitted_count,
            (SELECT COUNT(*) FROM assignment a WHERE a.round_id = r.round_id) AS assigned_count
         FROM exam_round r
         JOIN admin_user u ON u.username = r.created_by_admin_username
         WHERE r.exam_id = ?
         ORDER BY r.round_index DESC',
        [$examId]
    );
    $now = hk_now();
    $body = '';
    if ($exam['acceptance_mode'] === 'sealed') {
        $pendingAccept = null;
        foreach ($rounds as $round) {
            $deadline = hk_parse((string) $round['deadline']);
            if ($deadline !== null && $now < $deadline && in_array($round['status'], ['pending', 'open'], true)) {
                $pendingAccept = $round;
                break;
            }
        }
        if ($pendingAccept !== null) {
            $body .= '<p>Acceptance opens after ' . e(hk_full((string) $pendingAccept['deadline'])) . '.</p>';
        }
    }
    if ($rounds === []) {
        $body .= empty_state('This exam has no rounds yet.', 'Add round', '#add-round');
    } else {
        $body .= '<table id="round-table" data-client-table data-detail-url="' . e(request_path() . '?exam=' . rawurlencode($examId)) . '" data-sort-name="round" data-sort-order="desc"><thead><tr><th data-sortable="true">Round</th><th data-sortable="true">Opens</th><th data-sortable="true">Deadline</th><th>Status</th><th data-sortable="true">Submitted</th><th data-sortable="true">Assigned</th><th>Closed</th><th>Action</th></tr></thead><tbody>';
        foreach ($rounds as $round) {
            $deadline = hk_parse((string) $round['deadline']);
            $canAccept = $exam['acceptance_mode'] === 'sealed' && $deadline !== null && $now >= $deadline && $round['status'] !== 'matched';
            $body .= '<tr data-id="' . e($round['round_id']) . '"><td>Round ' . e((string) $round['round_index']) . '</td><td>' . e(hk_full((string) $round['start_at'])) . '</td><td>' . e(hk_full((string) $round['deadline'])) . '</td><td>' . round_badge((string) $round['status']) . '</td><td>' . e((string) $round['submitted_count']) . '</td><td>' . e((string) $round['assigned_count']) . '</td><td>' . e($round['closed_at'] ? hk_full((string) $round['closed_at']) : '—') . '</td><td><div class="table-actions">';
            if (in_array($round['status'], ['pending', 'open'], true) && $round['status'] !== 'open') {
                $body .= '<button type="button" class="btn btn-link" data-bs-toggle="modal" data-bs-target="#open-' . e($round['round_id']) . '">Open</button>';
            }
            if ($round['status'] === 'open') {
                $body .= '<button type="button" class="btn btn-link" data-bs-toggle="modal" data-bs-target="#close-' . e($round['round_id']) . '">Close</button>';
            }
            if ($canAccept) {
                $body .= '<button type="button" class="btn btn-link accept-open" data-round="' . e($round['round_id']) . '" data-index="' . e((string) $round['round_index']) . '">Accept</button>';
            }
            if (in_array($round['status'], ['pending', 'open'], true)) {
                $body .= '<button type="button" class="btn btn-link edit-time" data-round="' . e($round['round_id']) . '" data-index="' . e((string) $round['round_index']) . '" data-start="' . e(hk_input((string) $round['start_at'])) . '" data-deadline="' . e(hk_input((string) $round['deadline'])) . '">Edit time</button>';
            }
            $body .= '</div></td></tr>';
        }
        $body .= '</tbody></table>';
    }
    foreach ($rounds as $round) {
        if ($round['status'] === 'pending') {
            $openOthers = array_filter($rounds, static fn (array $item): bool => $item['status'] === 'open');
            $body .= modal_start('open-' . $round['round_id'], 'Open Round ' . $round['round_index'], 'open_round', ['exam_id' => $examId, 'round_id' => $round['round_id']]);
            if ($openOthers !== []) {
                $other = array_values($openOthers)[0];
                $body .= '<p>Round ' . e((string) $other['round_index']) . ' is still open. It will be closed before Round ' . e((string) $round['round_index']) . ' opens.</p>';
            } else {
                $body .= '<p>Students can submit preferences while this round is open.</p>';
            }
            $body .= modal_end('Open Round ' . $round['round_index']);
        }
        if ($round['status'] === 'open') {
            $body .= modal_start('close-' . $round['round_id'], 'Close Round ' . $round['round_index'], 'close_round', ['exam_id' => $examId, 'round_id' => $round['round_id']]);
            $body .= '<p>Time slots already taken stay with their groups.</p>';
            $body .= modal_end('Close Round ' . $round['round_index']);
        }
    }
    $body .= modal_start('edit-time', 'Change the time of this round', 'save_time', ['exam_id' => $examId]);
    $body .= '<input type="hidden" name="round_id" id="edit-round-id" value="">';
    $body .= text_field('start_at', 'Opens', '', ['id' => 'edit-start', 'type' => 'datetime-local', 'help' => 'Hong Kong time.']);
    $body .= text_field('deadline', 'Deadline', '', ['id' => 'edit-deadline', 'type' => 'datetime-local', 'help' => 'Hong Kong time.']);
    $body .= modal_end('Save round time');
    $nextIndex = $rounds === [] ? 1 : ((int) $rounds[0]['round_index'] + 1);
    $body .= modal_start('add-round', 'Add Round ' . $nextIndex, 'add_round', ['exam_id' => $examId]);
    $body .= '<p>Only groups still waiting will choose again. Groups that already have a time slot stay where they are.</p>';
    $body .= text_field('start_at', 'Opens', '', ['id' => 'add-start', 'type' => 'datetime-local', 'help' => 'Hong Kong time.']);
    $body .= text_field('deadline', 'Deadline', '', ['id' => 'add-deadline', 'type' => 'datetime-local', 'help' => 'Hong Kong time.']);
    $body .= modal_end('Add Round ' . $nextIndex);
    $body .= modal_start('accept-modal', 'Accept preferences', 'accept', ['exam_id' => $examId]);
    $body .= '<input type="hidden" name="round_id" id="accept-round" value="">';
    $body .= '<p id="accept-summary">Update the preview to see how many groups will receive a time slot.</p>';
    $body .= text_field('random_seed', 'Random seed', '', ['id' => 'accept-seed', 'help' => 'Leave it as generated, or replace it and update the numbers. The same seed gives the same order.']);
    $body .= '<button class="btn btn-outline-secondary" type="button" id="update-preview">Update numbers</button>';
    $body .= modal_end('Accept preferences');
    $body .= '<div class="offcanvas offcanvas-end" tabindex="-1" id="detail-canvas"><div class="offcanvas-header"><h2 class="offcanvas-title h5">Round</h2><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button></div><div class="offcanvas-body"></div></div>';
    $body .= '<script>
    document.querySelectorAll(".edit-time").forEach(function (button) {
        button.addEventListener("click", function () {
            const form = document.querySelector("#edit-time form");
            form.querySelector("#edit-round-id").value = button.dataset.round;
            form.querySelector("#edit-start").value = button.dataset.start;
            form.querySelector("#edit-start").dataset.initial = button.dataset.start;
            form.querySelector("#edit-deadline").value = button.dataset.deadline;
            form.querySelector("#edit-deadline").dataset.initial = button.dataset.deadline;
            document.getElementById("edit-time-title").textContent = "Change the time of Round " + button.dataset.index;
            bootstrap.Modal.getOrCreateInstance(document.getElementById("edit-time")).show();
        });
    });
    function loadPreview(roundId) {
        const seed = document.getElementById("accept-seed").value;
        const url = new URL(location.href);
        url.searchParams.set("ajax", "preview");
        url.searchParams.set("round_id", roundId);
        url.searchParams.set("seed", seed);
        fetch(url, { headers: { Accept: "application/json" } }).then(r => r.json()).then(function (data) {
            if (!data.ok) { return; }
            document.getElementById("accept-seed").value = data.seed;
            document.getElementById("accept-seed").dataset.initial = String(data.seed);
            const closeNote = data.will_close ? " The round will close." : "";
            document.getElementById("accept-summary").textContent = data.assigned + " groups will receive a time slot. " + data.unsatisfied + " groups cannot be given any time slot on their list. " + data.no_choice + " groups have not submitted a list." + closeNote;
        });
    }
    document.querySelectorAll(".accept-open").forEach(function (button) {
        button.addEventListener("click", function () {
            document.getElementById("accept-round").value = button.dataset.round;
            document.getElementById("accept-modal-title").textContent = "Accept preferences for Round " + button.dataset.index;
            document.getElementById("accept-seed").value = "";
            loadPreview(button.dataset.round);
            bootstrap.Modal.getOrCreateInstance(document.getElementById("accept-modal")).show();
        });
    });
    document.getElementById("update-preview")?.addEventListener("click", function () {
        loadPreview(document.getElementById("accept-round").value);
    });
    document.addEventListener("DOMContentLoaded", function () {
        const acceptNow = ' . json_encode($acceptId) . ';
        if (acceptNow) {
            const button = document.querySelector(".accept-open[data-round=\'" + acceptNow + "\']");
            if (button) { button.click(); }
        }
        const detailId = new URL(location.href).searchParams.get("detail");
        if (detailId && window.openDetail) {
            window.openDetail(location.pathname + location.search, detailId);
        }
    });
    </script>';

    $actions = '';
    if ($exam['status'] !== 'completed') {
        $actions = '<button class="btn btn-poly" type="button" data-bs-toggle="modal" data-bs-target="#add-round">Add next round</button>';
    }
    render_admin($admin, [
        'title' => 'Rounds',
        'subtitle' => 'Open a round, close it, or accept lists after the deadline.',
        'nav' => 'exams',
        'exam' => $exam,
        'exam_nav' => 'rounds',
        'crumbs' => exam_crumbs($exam, 'Rounds'),
        'actions' => $actions,
    ], $body);
} catch (Throwable $e) {
    error_log($e->__toString());
    if (wants_json()) {
        json_out(['ok' => false, 'message' => friendly_exception($e)], 500);
    }
    render_error_page('The service is not available right now. Try again later.');
}
