-- Loaded into the database named by DB_NAME from run.bat.
-- Docker creates that database, and 01-charset.sh sets DB_CHARSET and DB_COLLATION
-- before this file runs. This file does not choose the database name or timezone.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `audit_event`;
DROP TABLE IF EXISTS `credential_delivery`;
DROP TABLE IF EXISTS `assignment`;
DROP TABLE IF EXISTS `match_run`;
DROP TABLE IF EXISTS `preference_choice`;
DROP TABLE IF EXISTS `preference_submission`;
DROP TABLE IF EXISTS `group_member`;
DROP TABLE IF EXISTS `project_group`;
DROP TABLE IF EXISTS `exam_timeslot`;
DROP TABLE IF EXISTS `exam_round`;
DROP TABLE IF EXISTS `exam_admin`;
DROP TABLE IF EXISTS `student_account`;
DROP TABLE IF EXISTS `exam`;
DROP TABLE IF EXISTS `admin_user`;
DROP TABLE IF EXISTS `preference`;
DROP TABLE IF EXISTS `StudentExamMatch`;
DROP TABLE IF EXISTS `studentexammatch`;
DROP TABLE IF EXISTS `MeetingTimeslots`;
DROP TABLE IF EXISTS `meetingtimeslots`;
DROP TABLE IF EXISTS `MeetingDate`;
DROP TABLE IF EXISTS `meetingdate`;
DROP TABLE IF EXISTS `result`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `admin_user` (
  `username` VARCHAR(64) PRIMARY KEY COMMENT 'Admin login username; lowercase and without whitespace.',
  `email` VARCHAR(248) UNIQUE NOT NULL COMMENT 'Admin email address used for password reset and notifications.',
  `password_hash` VARCHAR(248) NOT NULL,
  `display_name` VARCHAR(248) NOT NULL COMMENT 'Name displayed in the administration dashboard.',
  `role` ENUM ('ta', 'teacher', 'prof') NOT NULL DEFAULT 'teacher' COMMENT 'Admin role: TA, teacher, or professor.',
  `is_active` TINYINT NOT NULL DEFAULT 1 COMMENT '1 means the account is active; 0 means it is disabled.',
  `created_at` DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP) COMMENT 'Timestamp when the admin account was created.',
  `last_login_at` DATETIME COMMENT 'Timestamp of the most recent successful login.',
  `updated_at` DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP) COMMENT 'Timestamp when the admin account was last updated.',
  `created_by_admin_username` VARCHAR(64) COMMENT 'Username of the administrator who created this account.',
  CONSTRAINT `chk_admin_active` CHECK (is_active IN (0, 1))
);

CREATE TABLE `student_account` (
  `student_id` VARCHAR(32) COMMENT 'Student login ID imported from the first Excel column.',
  `exam_id` CHAR(36) NOT NULL,
  `display_name` VARCHAR(248) NOT NULL COMMENT 'Name displayed to the student; imported from Excel.',
  `email` VARCHAR(248) NOT NULL COMMENT 'Student email address for exam codes and credentials; imported from Excel.',
  `password_hash` VARCHAR(248) NOT NULL,
  `is_active` TINYINT NOT NULL DEFAULT 1 COMMENT '1 means the account is active; 0 means it is disabled.',
  `last_login_at` DATETIME COMMENT 'Timestamp of the most recent successful login.',
  `created_at` DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP) COMMENT 'Timestamp when the student account was created.',
  `updated_at` DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP) COMMENT 'Timestamp when the student account was last updated.',
  `created_by_admin_username` VARCHAR(64) COMMENT 'Username of the administrator who created this account.',
  CONSTRAINT `chk_student_active` CHECK (is_active IN (0, 1)),
  PRIMARY KEY (`student_id`, `exam_id`)
);

CREATE TABLE `exam` (
  `exam_id` CHAR(36) PRIMARY KEY NOT NULL COMMENT 'Unique exam UUID v7.',
  `exam_code` VARCHAR(64) NOT NULL COMMENT 'Short code students use to enter the exam; shared by all rounds.',
  `title` VARCHAR(248) NOT NULL COMMENT 'Exam title.',
  `subject` VARCHAR(248) NOT NULL COMMENT 'Subject name.',
  `slot_length_min` INT NOT NULL COMMENT 'Length of each bookable slot in minutes.',
  `preference_limit` INT NOT NULL DEFAULT 3 COMMENT 'Maximum number of preferences a group may submit per round.',
  `acceptance_mode` ENUM ('fcfs', 'sealed') NOT NULL DEFAULT 'fcfs' COMMENT 'fcfs is real-time acceptance: a group takes a still-available timeslot at submission time. sealed is later acceptance: preferences are held and assigned together after the round deadline.',
  `status` ENUM ('draft', 'active', 'completed') NOT NULL DEFAULT 'draft' COMMENT 'Overall lifecycle status of the exam.',
  `created_by_admin_username` VARCHAR(64) NOT NULL COMMENT 'Username of the admin who created the exam.',
  `updated_at` DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP) COMMENT 'Timestamp when the exam was last updated.'
);

CREATE TABLE `exam_admin` (
  `exam_id` CHAR(36) NOT NULL COMMENT 'Exam for which access is granted.',
  `admin_username` VARCHAR(64) NOT NULL COMMENT 'Username of the admin who can manage the exam.',
  `granted_by_admin_username` VARCHAR(64) COMMENT 'Username of the admin who granted access; may be the exam creator.',
  `granted_at` DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP) COMMENT 'Timestamp when management access was granted.',
  PRIMARY KEY (`exam_id`, `admin_username`)
);

CREATE TABLE `exam_round` (
  `round_id` CHAR(36) PRIMARY KEY NOT NULL COMMENT 'UUID v7.',
  `exam_id` CHAR(36) NOT NULL COMMENT 'Exam to which the round belongs.',
  `round_index` INT NOT NULL COMMENT 'Round number starting at 1 within the exam.',
  `start_at` DATETIME NOT NULL COMMENT 'Timestamp when the round opens for preference submission.',
  `deadline` DATETIME NOT NULL COMMENT 'End of the submission window. Real-time FCFS has already accepted timeslots before this instant. Sealed matching accepts only after this instant.',
  `status` ENUM ('pending', 'open', 'closed', 'matched') NOT NULL DEFAULT 'closed' COMMENT 'Round status; pending -> open -> closed. Matched means every student group has an assignment. Under FCFS that can happen while the round is still open. Under sealed matching it happens at later acceptance.',
  `closed_at` DATETIME COMMENT 'Timestamp when the round was closed and preferences were archived.',
  `created_by_admin_username` VARCHAR(64) NOT NULL COMMENT 'Username of the admin who opened the round.',
  CONSTRAINT `chk_round_time_range` CHECK (start_at < deadline),
  CONSTRAINT `chk_round_index` CHECK (round_index >= 1),
  CONSTRAINT `chk_round_closed_at` CHECK (closed_at IS NULL OR closed_at >= start_at)
);

CREATE TABLE `exam_timeslot` (
  `timeslot_id` CHAR(36) PRIMARY KEY NOT NULL COMMENT 'UUID v7.',
  `exam_id` CHAR(36) NOT NULL COMMENT 'Exam to which this timeslot belongs.',
  `start_at` DATETIME NOT NULL COMMENT 'Slot start time. Other timeslots in the same exam may use the same start time.',
  `end_at` DATETIME NOT NULL COMMENT 'Slot end time. The interval may overlap other timeslots of the same exam.',
  `status` ENUM ('available', 'occupied') NOT NULL DEFAULT 'available' COMMENT 'Available or occupied applies only to this timeslot row. An overlapping timeslot stays independently available until its own assignment.',
  CONSTRAINT `chk_slot_range` CHECK (end_at > start_at)
);

CREATE TABLE `project_group` (
  `group_id` CHAR(36) PRIMARY KEY COMMENT 'UUID v7; an individual project is also represented as a one-person group.',
  `exam_id` CHAR(36) NOT NULL COMMENT 'Exam to which the group belongs.',
  `display_name` VARCHAR(248) NOT NULL COMMENT 'Group name displayed to users; imported from Excel. For one-person groups, this is the student''s name. For multi-person groups, this is the group name.'
);

CREATE TABLE `group_member` (
  `group_id` CHAR(36) NOT NULL COMMENT 'Project group to which the student belongs.',
  `exam_id` CHAR(36) NOT NULL COMMENT 'exam used for membership and authorization checks; must match the group exam.',
  `student_id` VARCHAR(32) NOT NULL COMMENT 'Student account that can log in and represent the group.',
  `joined_at` DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP) COMMENT 'Timestamp when the student joined the group.',
  PRIMARY KEY (`group_id`, `student_id`)
);

CREATE TABLE `preference_submission` (
  `submission_id` CHAR(36) PRIMARY KEY NOT NULL COMMENT 'UUID v7 for one complete preference submission.',
  `round_id` CHAR(36) NOT NULL COMMENT 'Round in which the submission was made.',
  `group_id` CHAR(36) NOT NULL COMMENT 'Project group represented by the submission.',
  `submitted_by_student_id` VARCHAR(32) NOT NULL COMMENT 'Student account that submitted the preferences.',
  `status` ENUM ('current', 'superseded') NOT NULL DEFAULT 'current' COMMENT 'Current is the latest submission; superseded means replaced by a later submission.',
  `submitted_at` DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP) COMMENT 'Submission timestamp. Under real-time FCFS this is the acceptance order: the earliest current submission takes the first of its choices that is still available. Under sealed matching this is only the preference time; acceptance happens later.'
);

CREATE TABLE `preference_choice` (
  `choice_id` CHAR(36) PRIMARY KEY NOT NULL COMMENT 'UUID v7',
  `submission_id` CHAR(36) NOT NULL COMMENT 'Preference submission containing this choice.',
  `timeslot_id` CHAR(36) NOT NULL COMMENT 'Timeslot selected by the student group.',
  `priority` INT NOT NULL COMMENT 'Preference priority; 1 is the highest priority. Real-time FCFS walks this order at submission and accepts the first available timeslot. Sealed matching keeps the order for later acceptance.'
);

CREATE TABLE `match_run` (
  `run_id` CHAR(36) PRIMARY KEY NOT NULL COMMENT 'UUID v7.',
  `round_id` CHAR(36) NOT NULL COMMENT 'Round processed by the matching execution.',
  `algorithm` ENUM ('fcfs', 'sealed') NOT NULL DEFAULT 'fcfs' COMMENT 'fcfs audits a real-time acceptance. sealed is the later-acceptance batch and runs only at or after the round deadline.',
  `random_seed` INT COMMENT 'Seed for reproducible sealed later-acceptance; NULL for real-time FCFS.',
  `started_at` DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP) COMMENT 'Timestamp when matching started.',
  `completed_at` DATETIME COMMENT 'Timestamp when matching completed.',
  CONSTRAINT `chk_run_time` CHECK (completed_at IS NULL OR completed_at >= started_at)
);

CREATE TABLE `assignment` (
  `assignment_id` CHAR(36) PRIMARY KEY NOT NULL COMMENT 'UUID v7.',
  `round_id` CHAR(36) NOT NULL COMMENT 'Round that produced the assignment.',
  `group_id` CHAR(36) NOT NULL COMMENT 'Project group assigned.',
  `timeslot_id` CHAR(36) NOT NULL COMMENT 'Timeslot occupied by the assignment. Overlap with other timeslots does not occupy those other rows.',
  `selected_choice_id` CHAR(36) COMMENT 'Preference choice selected by acceptance. NULL for manual assignments.',
  `run_id` CHAR(36) COMMENT 'NULL for real-time FCFS and for manual assignments. Required when source is sealed, because that acceptance happens later in a match run.',
  `source` ENUM ('fcfs', 'sealed', 'manual') NOT NULL DEFAULT 'fcfs' COMMENT 'fcfs is real-time acceptance at submission. sealed is later acceptance by a match run. manual is an admin override.',
  `accepted_at` DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP) COMMENT 'When the timeslot was accepted. For FCFS this is the submission instant. For sealed matching this is the later batch-acceptance time.',
  `assigned_by_admin_username` VARCHAR(64) COMMENT 'Username of the admin who made a manual assignment; NULL for automatic matching.'
);

CREATE TABLE `credential_delivery` (
  `delivery_id` CHAR(36) PRIMARY KEY COMMENT 'UUID v7',
  `student_id` VARCHAR(32) NOT NULL COMMENT 'Student account receiving the credentials.',
  `exam_id` CHAR(36) NOT NULL COMMENT 'exam associated with the credentials.',
  `requested_by_admin_username` VARCHAR(64) COMMENT 'Username of the admin who requested the resend or download.',
  `status` ENUM ('pending', 'sent', 'failed', 'downloaded') NOT NULL DEFAULT 'pending' COMMENT 'Delivery or download result.',
  `requested_at` DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP) COMMENT 'Timestamp when delivery was requested.',
  `completed_at` DATETIME COMMENT 'Timestamp when delivery, failure handling, or download completed.',
  `error_message` VARCHAR(1024) COMMENT 'Delivery failure reason; NULL when successful.',
  CONSTRAINT `chk_delivery_time` CHECK (completed_at IS NULL OR completed_at >= requested_at)
);

CREATE TABLE `audit_event` (
  `audit_id` CHAR(36) PRIMARY KEY COMMENT 'UUID v7',
  `exam_id` CHAR(36) COMMENT 'Exam associated with the event.',
  `round_id` CHAR(36) COMMENT 'Round associated with the event; NULL for exam-level events.',
  `run_id` CHAR(36) COMMENT 'Matching execution associated with the event; NULL for non-matching events.',
  `actor_admin_username` VARCHAR(64) COMMENT 'Username of the admin who performed the action; NULL for automatic matching.',
  `event_type` VARCHAR(64) NOT NULL COMMENT 'Event type, such as round_closed, assignment_changed, or credential_resent.',
  `detail` LONGTEXT NOT NULL COMMENT 'Event details',
  `event_at` DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP) COMMENT 'Timestamp when the event occurred.'
);

CREATE UNIQUE INDEX `uq_admin_email` ON `admin_user` (`email`);

CREATE INDEX `idx_admin_active` ON `admin_user` (`is_active`);

CREATE INDEX `idx_student_email` ON `student_account` (`email`);

CREATE INDEX `idx_student_active` ON `student_account` (`is_active`);

CREATE UNIQUE INDEX `uq_exam_code` ON `exam` (`exam_code`);

CREATE INDEX `idx_exam_creator` ON `exam` (`created_by_admin_username`);

CREATE INDEX `idx_exam_status` ON `exam` (`status`);

CREATE INDEX `idx_exam_admin_admin` ON `exam_admin` (`admin_username`);

CREATE UNIQUE INDEX `uq_exam_round` ON `exam_round` (`exam_id`, `round_index`);

CREATE INDEX `idx_round_deadline` ON `exam_round` (`deadline`);

CREATE INDEX `idx_round_status` ON `exam_round` (`status`);

CREATE INDEX `idx_slot_exam` ON `exam_timeslot` (`exam_id`);

CREATE INDEX `idx_slot_interval` ON `exam_timeslot` (`exam_id`, `start_at`, `end_at`);

CREATE INDEX `idx_slot_status` ON `exam_timeslot` (`status`);

CREATE INDEX `idx_group_exam` ON `project_group` (`exam_id`);

CREATE UNIQUE INDEX `uq_exam_student_group` ON `group_member` (`exam_id`, `student_id`);

CREATE INDEX `idx_member_student` ON `group_member` (`student_id`);

CREATE INDEX `idx_submission_current` ON `preference_submission` (`round_id`, `group_id`, `status`);

CREATE INDEX `idx_submission_time` ON `preference_submission` (`submitted_at`);

CREATE UNIQUE INDEX `uq_submission_slot` ON `preference_choice` (`submission_id`, `timeslot_id`);

CREATE UNIQUE INDEX `uq_submission_priority` ON `preference_choice` (`submission_id`, `priority`);

CREATE INDEX `idx_choice_slot` ON `preference_choice` (`timeslot_id`);

CREATE INDEX `idx_run_round` ON `match_run` (`round_id`);

CREATE UNIQUE INDEX `uq_assignment_group` ON `assignment` (`group_id`);

CREATE UNIQUE INDEX `uq_assignment_slot` ON `assignment` (`timeslot_id`);

CREATE INDEX `idx_assignment_round` ON `assignment` (`round_id`);

CREATE INDEX `idx_assignment_choice` ON `assignment` (`selected_choice_id`);

CREATE INDEX `idx_assignment_run` ON `assignment` (`run_id`);

CREATE INDEX `idx_delivery_student_exam` ON `credential_delivery` (`student_id`, `exam_id`, `requested_at`);

CREATE INDEX `idx_delivery_status` ON `credential_delivery` (`status`);

CREATE INDEX `idx_audit_exam` ON `audit_event` (`exam_id`, `event_at`);

CREATE INDEX `idx_audit_round` ON `audit_event` (`round_id`, `event_at`);

CREATE INDEX `idx_audit_run` ON `audit_event` (`run_id`);

ALTER TABLE `admin_user` COMMENT = 'Administrator accounts can manage exams, rounds, and student accounts.';

ALTER TABLE `student_account` COMMENT = 'Student account for a specific exam. Accounts are created when student data is imported.';

ALTER TABLE `exam_round` COMMENT = 'An exam can have multiple rounds for preference submission.';

ALTER TABLE `exam_timeslot` COMMENT = 'Bookable timeslot for one exam. Several timeslots may share the same clock time or overlap; each row is an independent seat. There is no unique key on (exam_id, start_at, end_at).';

ALTER TABLE `assignment` COMMENT = 'One project group accepted onto one timeslot. Real-time FCFS inserts this row at submission. Sealed matching inserts it later, when the match run accepts the held preferences. A timeslot row has at most one assignment; another timeslot at the same clock time is a separate seat.';

ALTER TABLE `credential_delivery` COMMENT = 'Record whether the student''s login information or exam credentials have been sent or downloaded.';

ALTER TABLE `audit_event` COMMENT = 'Log of all administrative and matching events.';

ALTER TABLE `exam` ADD CONSTRAINT `fk_exam_creator` FOREIGN KEY (`created_by_admin_username`) REFERENCES `admin_user` (`username`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `admin_user` ADD CONSTRAINT `fk_admin_creator` FOREIGN KEY (`created_by_admin_username`) REFERENCES `admin_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `exam_admin` ADD CONSTRAINT `fk_exam_admin_exam` FOREIGN KEY (`exam_id`) REFERENCES `exam` (`exam_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `exam_admin` ADD CONSTRAINT `fk_exam_admin_admin` FOREIGN KEY (`admin_username`) REFERENCES `admin_user` (`username`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `exam_admin` ADD CONSTRAINT `fk_exam_admin_granter` FOREIGN KEY (`granted_by_admin_username`) REFERENCES `admin_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `exam_round` ADD CONSTRAINT `fk_round_exam` FOREIGN KEY (`exam_id`) REFERENCES `exam` (`exam_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `exam_round` ADD CONSTRAINT `fk_round_creator` FOREIGN KEY (`created_by_admin_username`) REFERENCES `admin_user` (`username`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `student_account` ADD CONSTRAINT `fk_student_creator` FOREIGN KEY (`created_by_admin_username`) REFERENCES `admin_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `exam_timeslot` ADD CONSTRAINT `fk_slot_exam` FOREIGN KEY (`exam_id`) REFERENCES `exam` (`exam_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `project_group` ADD CONSTRAINT `fk_group_exam` FOREIGN KEY (`exam_id`) REFERENCES `exam` (`exam_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `group_member` ADD CONSTRAINT `fk_member_group` FOREIGN KEY (`group_id`) REFERENCES `project_group` (`group_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `group_member` ADD CONSTRAINT `fk_member_exam` FOREIGN KEY (`exam_id`) REFERENCES `exam` (`exam_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `group_member` ADD CONSTRAINT `fk_member_student_exam` FOREIGN KEY (`student_id`, `exam_id`) REFERENCES `student_account` (`student_id`, `exam_id`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `preference_submission` ADD CONSTRAINT `fk_submission_round` FOREIGN KEY (`round_id`) REFERENCES `exam_round` (`round_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `preference_submission` ADD CONSTRAINT `fk_submission_group` FOREIGN KEY (`group_id`) REFERENCES `project_group` (`group_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `preference_choice` ADD CONSTRAINT `fk_choice_submission` FOREIGN KEY (`submission_id`) REFERENCES `preference_submission` (`submission_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `preference_choice` ADD CONSTRAINT `fk_choice_slot` FOREIGN KEY (`timeslot_id`) REFERENCES `exam_timeslot` (`timeslot_id`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `match_run` ADD CONSTRAINT `fk_run_round` FOREIGN KEY (`round_id`) REFERENCES `exam_round` (`round_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `assignment` ADD CONSTRAINT `fk_assignment_round` FOREIGN KEY (`round_id`) REFERENCES `exam_round` (`round_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `assignment` ADD CONSTRAINT `fk_assignment_group` FOREIGN KEY (`group_id`) REFERENCES `project_group` (`group_id`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `assignment` ADD CONSTRAINT `fk_assignment_slot` FOREIGN KEY (`timeslot_id`) REFERENCES `exam_timeslot` (`timeslot_id`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `assignment` ADD CONSTRAINT `fk_assignment_choice` FOREIGN KEY (`selected_choice_id`) REFERENCES `preference_choice` (`choice_id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `assignment` ADD CONSTRAINT `fk_assignment_run` FOREIGN KEY (`run_id`) REFERENCES `match_run` (`run_id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `assignment` ADD CONSTRAINT `fk_assignment_admin` FOREIGN KEY (`assigned_by_admin_username`) REFERENCES `admin_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `credential_delivery` ADD CONSTRAINT `fk_delivery_student_exam` FOREIGN KEY (`student_id`, `exam_id`) REFERENCES `student_account` (`student_id`, `exam_id`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `credential_delivery` ADD CONSTRAINT `fk_delivery_exam` FOREIGN KEY (`exam_id`) REFERENCES `exam` (`exam_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `credential_delivery` ADD CONSTRAINT `fk_delivery_admin` FOREIGN KEY (`requested_by_admin_username`) REFERENCES `admin_user` (`username`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `audit_event` ADD CONSTRAINT `fk_audit_exam` FOREIGN KEY (`exam_id`) REFERENCES `exam` (`exam_id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `audit_event` ADD CONSTRAINT `fk_audit_round` FOREIGN KEY (`round_id`) REFERENCES `exam_round` (`round_id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `audit_event` ADD CONSTRAINT `fk_audit_run` FOREIGN KEY (`run_id`) REFERENCES `match_run` (`run_id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `audit_event` ADD CONSTRAINT `fk_audit_admin` FOREIGN KEY (`actor_admin_username`) REFERENCES `admin_user` (`username`) ON DELETE SET NULL ON UPDATE CASCADE;



/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
