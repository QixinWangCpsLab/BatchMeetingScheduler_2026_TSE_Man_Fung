-- Demo data for the admin and student screens.
-- Safe to run again: it replaces exam codes COMP4431, COMP4432, and COMP4433,
-- and the demo logins prof.demo, teacher.demo, ta.demo, and old.demo.
-- If one of those logins is already used by a different exam, this file stops
-- and leaves the database unchanged.
--
-- Login as Teacher. Every demo password is Test1234.
--   prof.demo      Alice. Professor. Sees all three exams.
--   teacher.demo   Bob. Teacher. Created COMP4431 and can open COMP4432.
--   ta.demo        Carol. TA. Created the COMP4433 draft.
--   old.demo       Dave. Disabled teacher. Sign-in is rejected.
--
-- Login as Student. The same password.
--   22000001d Eve is in both COMP4431 and COMP4432, so sign-in asks which exam.
--   22000011d Mallory is disabled on COMP4431.
--
-- COMP4431 Oral (Computer Networks) is first come, first served and still open.
--   Round 1 opened 3 days ago and closes in 20 hours, so Needs attention says
--   the round closes today or tomorrow. The oral day is 3 days from now.
--   Two chairs share 09:00 and both are taken: Group 1 (Frank, Grace) sits on
--   its 1st choice, and Group 2 (Heidi, Ivan) sits on its 1st choice. An older
--   Group 1 list is superseded. 09:15 and 09:30 are empty. Eve (22000001d)
--   listed those two, then was moved by hand to 09:45, so the assignment says
--   "Not on their list". 10:00 is still empty. Group 4 (Judy, Karl) listed only
--   the two taken 09:00 chairs, so the waiting reason is "Every choice was
--   taken". Leo (22000007d) downloaded an entry code and has no list. Mallory
--   (22000011d) is disabled, has no list, and was never sent a code. Ivan's
--   invitation failed. Judy and Karl have no delivery row. The dashboard
--   therefore shows 1 invitation email failed, and 4 students (Ivan, Judy,
--   Karl, Mallory) have not been sent their entry code. A failed row counts
--   as unsent.
--
-- COMP4432 Oral (Software Engineering) is later acceptance, past the deadline.
--   Round 1 started 10 days ago. The deadline was yesterday. The round is
--   closed and not matched, and there is no assignment and no match run, so
--   Accept now is available. The oral day is 5 days from now: two empty chairs
--   at 14:00, then 14:15 and 14:30. Group A (Nina, Oscar) listed 3 slots.
--   Group B (Peggy, Quinn) listed 2. Eve (22000001d) listed 2, which is why
--   student sign-in asks which exam. Ruby (23000003d) has no list, so her
--   waiting reason is "No choice yet". The other three groups are waiting for
--   later acceptance. All 4 groups are unassigned, so the dashboard says
--   "4 groups are waiting for later acceptance". Quinn's invitation failed.
--   Ruby was never sent a code, so 2 students have not been sent their entry
--   code. Accept can seat the three groups that have a list and leave Ruby
--   unassigned.
--
-- COMP4433 Draft (Database Systems) is a draft. Carol created it. Round 1 is
--   pending, starts in 7 days, and ends in 14 days. There are no time slots
--   and no students, so Needs attention says "Draft has no timeslots" and
--   Continue setup opens step 3 (time slots). Alice can open it. Bob cannot.

SET NAMES utf8mb4;
SET time_zone = '+08:00';
SET @now = NOW();
SET @demo_hash = '$2y$10$hL2lLk70n26I7v5XdD.Sye7Wq84xkMpFOQe4jh5XpKSuKG40PVVJC';

SET @exam_fcfs = '01900001-0000-7000-8000-000000000001';
SET @exam_sealed = '01900002-0000-7000-8000-000000000002';
SET @exam_draft = '01900003-0000-7000-8000-000000000003';
SET @round_fcfs = '01900001-0000-7000-8000-000000000011';
SET @round_sealed = '01900002-0000-7000-8000-000000000021';
SET @round_draft = '01900003-0000-7000-8000-000000000031';

SET @oral_day = DATE_ADD(DATE(@now), INTERVAL 3 DAY);
SET @t0900 = DATE_ADD(@oral_day, INTERVAL 9 HOUR);
SET @t0915 = DATE_ADD(@t0900, INTERVAL 15 MINUTE);
SET @t0930 = DATE_ADD(@t0900, INTERVAL 30 MINUTE);
SET @t0945 = DATE_ADD(@t0900, INTERVAL 45 MINUTE);
SET @t1000 = DATE_ADD(@t0900, INTERVAL 60 MINUTE);
SET @t1015 = DATE_ADD(@t0900, INTERVAL 75 MINUTE);
SET @sealed_day = DATE_ADD(DATE(@now), INTERVAL 5 DAY);
SET @t1400 = DATE_ADD(@sealed_day, INTERVAL 14 HOUR);
SET @t1415 = DATE_ADD(@t1400, INTERVAL 15 MINUTE);
SET @t1430 = DATE_ADD(@t1400, INTERVAL 30 MINUTE);
SET @t1445 = DATE_ADD(@t1400, INTERVAL 45 MINUTE);

SET @fcfs_start = DATE_SUB(@now, INTERVAL 3 DAY);
SET @fcfs_deadline = DATE_ADD(@now, INTERVAL 20 HOUR);
SET @sealed_start = DATE_SUB(@now, INTERVAL 10 DAY);
SET @sealed_deadline = DATE_SUB(@now, INTERVAL 1 DAY);
SET @draft_start = DATE_ADD(@now, INTERVAL 7 DAY);
SET @draft_deadline = DATE_ADD(@now, INTERVAL 14 DAY);
SET @sub_old = DATE_SUB(@now, INTERVAL 2 DAY);
SET @sub_new = DATE_SUB(@now, INTERVAL 1 DAY);
SET @sub_wait = DATE_SUB(@now, INTERVAL 12 HOUR);
SET @sub_sealed = DATE_SUB(@now, INTERVAL 3 DAY);
SET @manual_at = DATE_SUB(@now, INTERVAL 6 HOUR);
SET @imported_at = DATE_SUB(@now, INTERVAL 4 DAY);
SET @mail_at = DATE_SUB(@now, INTERVAL 2 DAY);
SET @mail_done = DATE_ADD(@mail_at, INTERVAL 1 MINUTE);

SET autocommit = 0;
START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_demo_exam;
CREATE TEMPORARY TABLE tmp_demo_exam (exam_id CHAR(36) PRIMARY KEY);
INSERT INTO tmp_demo_exam (exam_id) VALUES (@exam_fcfs), (@exam_sealed), (@exam_draft);
INSERT IGNORE INTO tmp_demo_exam (exam_id)
SELECT exam_id FROM exam WHERE exam_code IN ('COMP4431', 'COMP4432', 'COMP4433');

SELECT COALESCE(SUM(n), 0) INTO @demo_block FROM (
    SELECT COUNT(*) AS n FROM exam e
    WHERE e.created_by_admin_username IN ('prof.demo', 'teacher.demo', 'ta.demo', 'old.demo')
      AND NOT EXISTS (SELECT 1 FROM tmp_demo_exam t WHERE t.exam_id = e.exam_id)
    UNION ALL
    SELECT COUNT(*) FROM exam_admin ea
    WHERE ea.admin_username IN ('prof.demo', 'teacher.demo', 'ta.demo', 'old.demo')
      AND NOT EXISTS (SELECT 1 FROM tmp_demo_exam t WHERE t.exam_id = ea.exam_id)
    UNION ALL
    SELECT COUNT(*) FROM exam_round r
    WHERE r.created_by_admin_username IN ('prof.demo', 'teacher.demo', 'ta.demo', 'old.demo')
      AND NOT EXISTS (SELECT 1 FROM tmp_demo_exam t WHERE t.exam_id = r.exam_id)
    UNION ALL
    SELECT COUNT(*) FROM student_account s
    WHERE s.created_by_admin_username IN ('prof.demo', 'teacher.demo', 'ta.demo', 'old.demo')
      AND NOT EXISTS (SELECT 1 FROM tmp_demo_exam t WHERE t.exam_id = s.exam_id)
    UNION ALL
    SELECT COUNT(*) FROM credential_delivery d
    WHERE d.requested_by_admin_username IN ('prof.demo', 'teacher.demo', 'ta.demo', 'old.demo')
      AND NOT EXISTS (SELECT 1 FROM tmp_demo_exam t WHERE t.exam_id = d.exam_id)
    UNION ALL
    SELECT COUNT(*) FROM assignment a
    WHERE a.assigned_by_admin_username IN ('prof.demo', 'teacher.demo', 'ta.demo', 'old.demo')
      AND NOT EXISTS (
          SELECT 1 FROM exam_round r
          INNER JOIN tmp_demo_exam t ON t.exam_id = r.exam_id
          WHERE r.round_id = a.round_id
      )
    UNION ALL
    SELECT COUNT(*) FROM audit_event a
    WHERE a.actor_admin_username IN ('prof.demo', 'teacher.demo', 'ta.demo', 'old.demo')
      AND (a.exam_id IS NULL OR NOT EXISTS (SELECT 1 FROM tmp_demo_exam t WHERE t.exam_id = a.exam_id))
    UNION ALL
    SELECT COUNT(*) FROM admin_user u
    WHERE u.created_by_admin_username IN ('prof.demo', 'teacher.demo', 'ta.demo', 'old.demo')
      AND u.username NOT IN ('prof.demo', 'teacher.demo', 'ta.demo', 'old.demo')
) blockers;

DELIMITER $$
IF @demo_block > 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A demo login is already used outside COMP4431, COMP4432, and COMP4433. Nothing was changed.';
END IF$$
DELIMITER ;

DELETE a FROM audit_event a INNER JOIN tmp_demo_exam t ON t.exam_id = a.exam_id;
DELETE d FROM credential_delivery d INNER JOIN tmp_demo_exam t ON t.exam_id = d.exam_id;
DELETE a FROM assignment a
    INNER JOIN exam_round r ON r.round_id = a.round_id
    INNER JOIN tmp_demo_exam t ON t.exam_id = r.exam_id;
DELETE c FROM preference_choice c
    INNER JOIN preference_submission s ON s.submission_id = c.submission_id
    INNER JOIN exam_round r ON r.round_id = s.round_id
    INNER JOIN tmp_demo_exam t ON t.exam_id = r.exam_id;
DELETE s FROM preference_submission s
    INNER JOIN exam_round r ON r.round_id = s.round_id
    INNER JOIN tmp_demo_exam t ON t.exam_id = r.exam_id;
DELETE m FROM match_run m
    INNER JOIN exam_round r ON r.round_id = m.round_id
    INNER JOIN tmp_demo_exam t ON t.exam_id = r.exam_id;
DELETE g FROM group_member g INNER JOIN tmp_demo_exam t ON t.exam_id = g.exam_id;
DELETE p FROM project_group p INNER JOIN tmp_demo_exam t ON t.exam_id = p.exam_id;
DELETE s FROM exam_timeslot s INNER JOIN tmp_demo_exam t ON t.exam_id = s.exam_id;
DELETE r FROM exam_round r INNER JOIN tmp_demo_exam t ON t.exam_id = r.exam_id;
DELETE a FROM exam_admin a INNER JOIN tmp_demo_exam t ON t.exam_id = a.exam_id;
DELETE s FROM student_account s INNER JOIN tmp_demo_exam t ON t.exam_id = s.exam_id;
DELETE e FROM exam e INNER JOIN tmp_demo_exam t ON t.exam_id = e.exam_id;

UPDATE admin_user
SET created_by_admin_username = NULL
WHERE username IN ('prof.demo', 'teacher.demo', 'ta.demo', 'old.demo');
DELETE FROM admin_user
WHERE username IN ('prof.demo', 'teacher.demo', 'ta.demo', 'old.demo');

INSERT INTO admin_user
    (username, email, password_hash, display_name, role, is_active, created_at, last_login_at, updated_at, created_by_admin_username)
VALUES
    ('prof.demo', 'prof.demo@example.com', @demo_hash, 'Alice', 'prof', 1, @imported_at, DATE_SUB(@now, INTERVAL 1 DAY), @now, NULL),
    ('teacher.demo', 'teacher.demo@example.com', @demo_hash, 'Bob', 'teacher', 1, @imported_at, DATE_SUB(@now, INTERVAL 2 DAY), @now, 'prof.demo'),
    ('ta.demo', 'ta.demo@example.com', @demo_hash, 'Carol', 'ta', 1, @imported_at, NULL, @now, 'prof.demo'),
    ('old.demo', 'old.demo@example.com', @demo_hash, 'Dave', 'teacher', 0, @imported_at, NULL, @now, 'prof.demo');

INSERT INTO exam
    (exam_id, exam_code, title, subject, slot_length_min, preference_limit, acceptance_mode, status, created_by_admin_username, updated_at)
VALUES
    (@exam_fcfs, 'COMP4431', 'COMP4431 Oral', 'Computer Networks', 15, 3, 'fcfs', 'active', 'teacher.demo', @now),
    (@exam_sealed, 'COMP4432', 'COMP4432 Oral', 'Software Engineering', 15, 3, 'sealed', 'active', 'prof.demo', @now),
    (@exam_draft, 'COMP4433', 'COMP4433 Draft', 'Database Systems', 15, 3, 'fcfs', 'draft', 'ta.demo', @now);

INSERT INTO exam_admin (exam_id, admin_username, granted_by_admin_username, granted_at) VALUES
    (@exam_fcfs, 'teacher.demo', 'teacher.demo', @imported_at),
    (@exam_fcfs, 'prof.demo', 'teacher.demo', @imported_at),
    (@exam_sealed, 'prof.demo', 'prof.demo', @sealed_start),
    (@exam_sealed, 'teacher.demo', 'prof.demo', @sealed_start),
    (@exam_draft, 'ta.demo', 'ta.demo', @now),
    (@exam_draft, 'prof.demo', 'ta.demo', @now);

INSERT INTO exam_round
    (round_id, exam_id, round_index, start_at, deadline, status, closed_at, created_by_admin_username)
VALUES
    (@round_fcfs, @exam_fcfs, 1, @fcfs_start, @fcfs_deadline, 'open', NULL, 'teacher.demo'),
    (@round_sealed, @exam_sealed, 1, @sealed_start, @sealed_deadline, 'closed', @sealed_deadline, 'prof.demo'),
    (@round_draft, @exam_draft, 1, @draft_start, @draft_deadline, 'pending', NULL, 'ta.demo');

-- Two chairs share 09:00. Slot numbers follow timeslot_id order.
INSERT INTO exam_timeslot (timeslot_id, exam_id, start_at, end_at, status) VALUES
    ('01900001-0000-7000-8000-000000000101', @exam_fcfs, @t0900, @t0915, 'occupied'),
    ('01900001-0000-7000-8000-000000000102', @exam_fcfs, @t0900, @t0915, 'occupied'),
    ('01900001-0000-7000-8000-000000000103', @exam_fcfs, @t0915, @t0930, 'available'),
    ('01900001-0000-7000-8000-000000000104', @exam_fcfs, @t0930, @t0945, 'available'),
    ('01900001-0000-7000-8000-000000000105', @exam_fcfs, @t0945, @t1000, 'occupied'),
    ('01900001-0000-7000-8000-000000000106', @exam_fcfs, @t1000, @t1015, 'available'),
    ('01900002-0000-7000-8000-000000000201', @exam_sealed, @t1400, @t1415, 'available'),
    ('01900002-0000-7000-8000-000000000202', @exam_sealed, @t1400, @t1415, 'available'),
    ('01900002-0000-7000-8000-000000000203', @exam_sealed, @t1415, @t1430, 'available'),
    ('01900002-0000-7000-8000-000000000204', @exam_sealed, @t1430, @t1445, 'available');

INSERT INTO student_account
    (student_id, exam_id, display_name, email, password_hash, is_active, last_login_at, created_at, updated_at, created_by_admin_username)
VALUES
    ('22000001d', @exam_fcfs, 'Eve', '22000001d@example.com', @demo_hash, 1, @sub_new, @imported_at, @now, 'teacher.demo'),
    ('22000002d', @exam_fcfs, 'Frank', '22000002d@example.com', @demo_hash, 1, @sub_old, @imported_at, @now, 'teacher.demo'),
    ('22000003d', @exam_fcfs, 'Grace', '22000003d@example.com', @demo_hash, 1, NULL, @imported_at, @now, 'teacher.demo'),
    ('22000004d', @exam_fcfs, 'Heidi', '22000004d@example.com', @demo_hash, 1, NULL, @imported_at, @now, 'teacher.demo'),
    ('22000005d', @exam_fcfs, 'Ivan', '22000005d@example.com', @demo_hash, 1, NULL, @imported_at, @now, 'teacher.demo'),
    ('22000006d', @exam_fcfs, 'Judy', '22000006d@example.com', @demo_hash, 1, NULL, @imported_at, @now, 'teacher.demo'),
    ('22000010d', @exam_fcfs, 'Karl', '22000010d@example.com', @demo_hash, 1, NULL, @imported_at, @now, 'teacher.demo'),
    ('22000007d', @exam_fcfs, 'Leo', '22000007d@example.com', @demo_hash, 1, NULL, @imported_at, @now, 'teacher.demo'),
    ('22000011d', @exam_fcfs, 'Mallory', '22000011d@example.com', @demo_hash, 0, NULL, @imported_at, @now, 'teacher.demo'),
    ('22000001d', @exam_sealed, 'Eve', '22000001d@example.com', @demo_hash, 1, @sub_new, @sealed_start, @now, 'prof.demo'),
    ('23000001d', @exam_sealed, 'Nina', '23000001d@example.com', @demo_hash, 1, NULL, @sealed_start, @now, 'prof.demo'),
    ('23000002d', @exam_sealed, 'Oscar', '23000002d@example.com', @demo_hash, 1, NULL, @sealed_start, @now, 'prof.demo'),
    ('23000004d', @exam_sealed, 'Peggy', '23000004d@example.com', @demo_hash, 1, NULL, @sealed_start, @now, 'prof.demo'),
    ('23000005d', @exam_sealed, 'Quinn', '23000005d@example.com', @demo_hash, 1, NULL, @sealed_start, @now, 'prof.demo'),
    ('23000003d', @exam_sealed, 'Ruby', '23000003d@example.com', @demo_hash, 1, NULL, @sealed_start, @now, 'prof.demo');

INSERT INTO project_group (group_id, exam_id, display_name) VALUES
    ('01900001-0000-7000-8000-000000000301', @exam_fcfs, 'Group 1'),
    ('01900001-0000-7000-8000-000000000302', @exam_fcfs, 'Group 2'),
    ('01900001-0000-7000-8000-000000000303', @exam_fcfs, 'Eve'),
    ('01900001-0000-7000-8000-000000000304', @exam_fcfs, 'Group 4'),
    ('01900001-0000-7000-8000-000000000305', @exam_fcfs, 'Leo'),
    ('01900001-0000-7000-8000-000000000306', @exam_fcfs, 'Mallory'),
    ('01900002-0000-7000-8000-000000000401', @exam_sealed, 'Group A'),
    ('01900002-0000-7000-8000-000000000402', @exam_sealed, 'Group B'),
    ('01900002-0000-7000-8000-000000000403', @exam_sealed, 'Eve'),
    ('01900002-0000-7000-8000-000000000404', @exam_sealed, 'Ruby');

INSERT INTO group_member (group_id, exam_id, student_id, joined_at) VALUES
    ('01900001-0000-7000-8000-000000000301', @exam_fcfs, '22000002d', @imported_at),
    ('01900001-0000-7000-8000-000000000301', @exam_fcfs, '22000003d', @imported_at),
    ('01900001-0000-7000-8000-000000000302', @exam_fcfs, '22000004d', @imported_at),
    ('01900001-0000-7000-8000-000000000302', @exam_fcfs, '22000005d', @imported_at),
    ('01900001-0000-7000-8000-000000000303', @exam_fcfs, '22000001d', @imported_at),
    ('01900001-0000-7000-8000-000000000304', @exam_fcfs, '22000006d', @imported_at),
    ('01900001-0000-7000-8000-000000000304', @exam_fcfs, '22000010d', @imported_at),
    ('01900001-0000-7000-8000-000000000305', @exam_fcfs, '22000007d', @imported_at),
    ('01900001-0000-7000-8000-000000000306', @exam_fcfs, '22000011d', @imported_at),
    ('01900002-0000-7000-8000-000000000401', @exam_sealed, '23000001d', @sealed_start),
    ('01900002-0000-7000-8000-000000000401', @exam_sealed, '23000002d', @sealed_start),
    ('01900002-0000-7000-8000-000000000402', @exam_sealed, '23000004d', @sealed_start),
    ('01900002-0000-7000-8000-000000000402', @exam_sealed, '23000005d', @sealed_start),
    ('01900002-0000-7000-8000-000000000403', @exam_sealed, '22000001d', @sealed_start),
    ('01900002-0000-7000-8000-000000000404', @exam_sealed, '23000003d', @sealed_start);

INSERT INTO preference_submission
    (submission_id, round_id, group_id, submitted_by_student_id, status, submitted_at)
VALUES
    ('01900001-0000-7000-8000-000000000511', @round_fcfs, '01900001-0000-7000-8000-000000000301', '22000003d', 'superseded', @sub_old),
    ('01900001-0000-7000-8000-000000000501', @round_fcfs, '01900001-0000-7000-8000-000000000301', '22000002d', 'current', @sub_new),
    ('01900001-0000-7000-8000-000000000502', @round_fcfs, '01900001-0000-7000-8000-000000000302', '22000004d', 'current', @sub_new),
    ('01900001-0000-7000-8000-000000000503', @round_fcfs, '01900001-0000-7000-8000-000000000303', '22000001d', 'current', @sub_new),
    ('01900001-0000-7000-8000-000000000504', @round_fcfs, '01900001-0000-7000-8000-000000000304', '22000006d', 'current', @sub_wait),
    ('01900002-0000-7000-8000-000000000601', @round_sealed, '01900002-0000-7000-8000-000000000401', '23000001d', 'current', @sub_sealed),
    ('01900002-0000-7000-8000-000000000602', @round_sealed, '01900002-0000-7000-8000-000000000402', '23000004d', 'current', @sub_sealed),
    ('01900002-0000-7000-8000-000000000603', @round_sealed, '01900002-0000-7000-8000-000000000403', '22000001d', 'current', @sub_sealed);

INSERT INTO preference_choice (choice_id, submission_id, timeslot_id, priority) VALUES
    ('01900001-0000-7000-8000-000000000711', '01900001-0000-7000-8000-000000000511', '01900001-0000-7000-8000-000000000104', 1),
    ('01900001-0000-7000-8000-000000000712', '01900001-0000-7000-8000-000000000511', '01900001-0000-7000-8000-000000000103', 2),
    ('01900001-0000-7000-8000-000000000701', '01900001-0000-7000-8000-000000000501', '01900001-0000-7000-8000-000000000101', 1),
    ('01900001-0000-7000-8000-000000000702', '01900001-0000-7000-8000-000000000501', '01900001-0000-7000-8000-000000000103', 2),
    ('01900001-0000-7000-8000-000000000703', '01900001-0000-7000-8000-000000000501', '01900001-0000-7000-8000-000000000104', 3),
    ('01900001-0000-7000-8000-000000000721', '01900001-0000-7000-8000-000000000502', '01900001-0000-7000-8000-000000000102', 1),
    ('01900001-0000-7000-8000-000000000722', '01900001-0000-7000-8000-000000000502', '01900001-0000-7000-8000-000000000101', 2),
    ('01900001-0000-7000-8000-000000000731', '01900001-0000-7000-8000-000000000503', '01900001-0000-7000-8000-000000000103', 1),
    ('01900001-0000-7000-8000-000000000732', '01900001-0000-7000-8000-000000000503', '01900001-0000-7000-8000-000000000104', 2),
    ('01900001-0000-7000-8000-000000000741', '01900001-0000-7000-8000-000000000504', '01900001-0000-7000-8000-000000000101', 1),
    ('01900001-0000-7000-8000-000000000742', '01900001-0000-7000-8000-000000000504', '01900001-0000-7000-8000-000000000102', 2),
    ('01900002-0000-7000-8000-000000000751', '01900002-0000-7000-8000-000000000601', '01900002-0000-7000-8000-000000000201', 1),
    ('01900002-0000-7000-8000-000000000752', '01900002-0000-7000-8000-000000000601', '01900002-0000-7000-8000-000000000203', 2),
    ('01900002-0000-7000-8000-000000000753', '01900002-0000-7000-8000-000000000601', '01900002-0000-7000-8000-000000000204', 3),
    ('01900002-0000-7000-8000-000000000761', '01900002-0000-7000-8000-000000000602', '01900002-0000-7000-8000-000000000202', 1),
    ('01900002-0000-7000-8000-000000000762', '01900002-0000-7000-8000-000000000602', '01900002-0000-7000-8000-000000000201', 2),
    ('01900002-0000-7000-8000-000000000771', '01900002-0000-7000-8000-000000000603', '01900002-0000-7000-8000-000000000203', 1),
    ('01900002-0000-7000-8000-000000000772', '01900002-0000-7000-8000-000000000603', '01900002-0000-7000-8000-000000000204', 2);

INSERT INTO assignment
    (assignment_id, round_id, group_id, timeslot_id, selected_choice_id, run_id, source, accepted_at, assigned_by_admin_username)
VALUES
    ('01900001-0000-7000-8000-000000000801', @round_fcfs, '01900001-0000-7000-8000-000000000301', '01900001-0000-7000-8000-000000000101', '01900001-0000-7000-8000-000000000701', NULL, 'fcfs', @sub_new, NULL),
    ('01900001-0000-7000-8000-000000000802', @round_fcfs, '01900001-0000-7000-8000-000000000302', '01900001-0000-7000-8000-000000000102', '01900001-0000-7000-8000-000000000721', NULL, 'fcfs', @sub_new, NULL),
    ('01900001-0000-7000-8000-000000000803', @round_fcfs, '01900001-0000-7000-8000-000000000303', '01900001-0000-7000-8000-000000000105', NULL, NULL, 'manual', @manual_at, 'teacher.demo');

INSERT INTO credential_delivery
    (delivery_id, student_id, exam_id, requested_by_admin_username, status, requested_at, completed_at, error_message)
VALUES
    ('01900001-0000-7000-8000-000000000901', '22000001d', @exam_fcfs, 'teacher.demo', 'sent', @mail_at, @mail_done, NULL),
    ('01900001-0000-7000-8000-000000000902', '22000002d', @exam_fcfs, 'teacher.demo', 'sent', @mail_at, @mail_done, NULL),
    ('01900001-0000-7000-8000-000000000903', '22000003d', @exam_fcfs, 'teacher.demo', 'sent', @mail_at, @mail_done, NULL),
    ('01900001-0000-7000-8000-000000000904', '22000004d', @exam_fcfs, 'teacher.demo', 'sent', @mail_at, @mail_done, NULL),
    ('01900001-0000-7000-8000-000000000905', '22000005d', @exam_fcfs, 'teacher.demo', 'failed', @mail_at, @mail_done, 'The mail server rejected the address.'),
    ('01900001-0000-7000-8000-000000000906', '22000007d', @exam_fcfs, 'teacher.demo', 'downloaded', @mail_at, @mail_done, NULL),
    ('01900002-0000-7000-8000-000000000911', '22000001d', @exam_sealed, 'prof.demo', 'sent', @sub_sealed, DATE_ADD(@sub_sealed, INTERVAL 1 MINUTE), NULL),
    ('01900002-0000-7000-8000-000000000912', '23000001d', @exam_sealed, 'prof.demo', 'sent', @sub_sealed, DATE_ADD(@sub_sealed, INTERVAL 1 MINUTE), NULL),
    ('01900002-0000-7000-8000-000000000913', '23000002d', @exam_sealed, 'prof.demo', 'sent', @sub_sealed, DATE_ADD(@sub_sealed, INTERVAL 1 MINUTE), NULL),
    ('01900002-0000-7000-8000-000000000914', '23000004d', @exam_sealed, 'prof.demo', 'sent', @sub_sealed, DATE_ADD(@sub_sealed, INTERVAL 1 MINUTE), NULL),
    ('01900002-0000-7000-8000-000000000915', '23000005d', @exam_sealed, 'prof.demo', 'failed', @sub_sealed, DATE_ADD(@sub_sealed, INTERVAL 1 MINUTE), 'The mail server rejected the address.');

INSERT INTO audit_event
    (audit_id, exam_id, round_id, run_id, actor_admin_username, event_type, detail, event_at)
VALUES
    ('01900009-0000-7000-8000-000000000001', @exam_fcfs, NULL, NULL, 'teacher.demo', 'exam_created', 'COMP4431 Oral was created.', @imported_at),
    ('01900009-0000-7000-8000-000000000002', @exam_fcfs, NULL, NULL, 'teacher.demo', 'timeslots_added', 'Six time slots were added, including two chairs at 09:00.', @imported_at),
    ('01900009-0000-7000-8000-000000000003', @exam_fcfs, NULL, NULL, 'teacher.demo', 'student_imported', 'Nine students were imported.', @imported_at),
    ('01900009-0000-7000-8000-000000000004', @exam_fcfs, @round_fcfs, NULL, 'teacher.demo', 'round_opened', 'Round 1 is open.', @fcfs_start),
    ('01900009-0000-7000-8000-000000000005', @exam_fcfs, NULL, NULL, 'teacher.demo', 'credential_sent', 'Entry codes were sent to students whose email succeeded.', @mail_done),
    ('01900009-0000-7000-8000-000000000006', @exam_fcfs, @round_fcfs, NULL, 'teacher.demo', 'assignment_changed', 'Eve was moved to a time slot that was not on the list.', @manual_at),
    ('01900009-0000-7000-8000-000000000007', @exam_fcfs, NULL, NULL, 'teacher.demo', 'account_disabled', 'Mallory was disabled for this exam.', DATE_SUB(@now, INTERVAL 5 HOUR)),
    ('01900009-0000-7000-8000-000000000008', @exam_sealed, NULL, NULL, 'prof.demo', 'exam_created', 'COMP4432 Oral was created.', @sealed_start),
    ('01900009-0000-7000-8000-000000000009', @exam_sealed, NULL, NULL, 'prof.demo', 'student_imported', 'Six students were imported.', @sealed_start),
    ('01900009-0000-7000-8000-000000000010', @exam_sealed, @round_sealed, NULL, 'prof.demo', 'round_closed', 'Round 1 was closed after the deadline. Preferences are waiting.', @sealed_deadline),
    ('01900009-0000-7000-8000-000000000011', @exam_draft, @round_draft, NULL, 'ta.demo', 'round_created', 'Round 1 was added. No time slots have been added yet.', DATE_SUB(@now, INTERVAL 1 HOUR)),
    ('01900009-0000-7000-8000-000000000012', @exam_draft, NULL, NULL, 'ta.demo', 'exam_created', 'COMP4433 Draft was saved without time slots.', DATE_SUB(@now, INTERVAL 1 HOUR));

COMMIT;
DROP TEMPORARY TABLE IF EXISTS tmp_demo_exam;
SELECT 'Demo data is ready.' AS result;
