<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_vbs_myoverview;

use core_external\external_api;
use local_vbs_myoverview\external\get_learning_progress;

/**
 * Tests for YCTK #10 fields (grade_items, attendance_percent) added to active_courses
 * in get_learning_progress.
 *
 * Edge cases covered per BE contract rule:
 * - Empty result set: no quizzes → grade_items = []
 * - Optional field absent: attendance_percent = null when mod_attendance not installed
 * - New user with no records → empty grade_items, null attendance_percent
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_vbs_myoverview\external\get_learning_progress
 */
final class get_learning_progress_scores_attendance_test extends \advanced_testcase {

    /**
     * Call the external function and clean the return value like the WS layer does.
     *
     * @param int $userid
     * @return array
     */
    protected function call(int $userid): array {
        $raw = get_learning_progress::execute($userid);
        return external_api::clean_returnvalue(get_learning_progress::execute_returns(), $raw);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // grade_items edge cases
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * New user with no enrolled courses → active_courses empty → no grade_items to check.
     * Validates the new fields are present in the schema (clean_returnvalue passes).
     *
     * @return void
     */
    public function test_new_user_active_courses_empty(): void {
        $this->resetAfterTest();
        $user = self::getDataGenerator()->create_user();
        $this->setUser($user);

        $result = $this->call($user->id);

        $this->assertSame([], $result['active_courses']);
    }

    /**
     * Active course with no quizzes or assignments returns an empty grade_items list.
     *
     * @return void
     */
    public function test_active_course_no_quizzes_returns_empty_grade_items(): void {
        $this->resetAfterTest();
        $now = time();
        $gen = self::getDataGenerator();
        $course = $gen->create_course(['startdate' => $now - DAYSECS]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $result = $this->call($user->id);

        $this->assertCount(1, $result['active_courses']);
        $row = $result['active_courses'][0];
        $this->assertArrayHasKey('grade_items', $row);
        $this->assertSame([], $row['grade_items']);
    }

    /**
     * Active course with a quiz that has been graded returns grade_items with
     * the correct percent and module type.
     *
     * @return void
     */
    public function test_active_course_with_graded_quiz_returns_grade_item(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $gen = self::getDataGenerator();
        $course = $gen->create_course(['startdate' => $now - DAYSECS]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        // Insert a quiz grade item directly (no quiz module install required).
        $giid = $DB->insert_record('grade_items', (object)[
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => 1,
            'itemname' => 'Quiz cuối kỳ',
            'grademax' => 10.0,
            'grademin' => 0.0,
            'gradepass' => 0.0,
            'scaleid' => null,
            'outcomeid' => null,
            'gradetype' => 1,
            'display' => 0,
            'decimals' => null,
            'hidden' => 0,
            'locked' => 0,
            'locktime' => 0,
            'needsupdate' => 0,
            'weightoverride' => 0,
            'sortorder' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'itemnumber' => 0,
            'calculation' => null,
            'idnumber' => null,
            'aggregationcoef' => 0,
            'aggregationcoef2' => 0,
            'plusfactor' => 0,
            'multfactor' => 1.0,
        ]);
        $DB->insert_record('grade_grades', (object)[
            'itemid' => $giid,
            'userid' => $user->id,
            'rawgrade' => 8.0,
            'finalgrade' => 8.0,
            'rawgrademax' => 10.0,
            'rawgrademin' => 0.0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $this->setUser($user);
        $result = $this->call($user->id);

        $this->assertCount(1, $result['active_courses']);
        $row = $result['active_courses'][0];
        $this->assertCount(1, $row['grade_items']);
        $gi = $row['grade_items'][0];
        $this->assertSame('Quiz cuối kỳ', $gi['itemname']);
        $this->assertSame('quiz', $gi['itemmodule']);
        $this->assertSame(80, $gi['percent']);
    }

    /**
     * A quiz grade item with null finalgrade (not yet graded) yields percent = 0.
     *
     * @return void
     */
    public function test_ungraded_quiz_yields_zero_percent(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $gen = self::getDataGenerator();
        $course = $gen->create_course(['startdate' => $now - DAYSECS]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $giid = $DB->insert_record('grade_items', (object)[
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => 1,
            'itemname' => 'Quiz chưa chấm',
            'grademax' => 10.0,
            'grademin' => 0.0,
            'gradepass' => 0.0,
            'scaleid' => null,
            'outcomeid' => null,
            'gradetype' => 1,
            'display' => 0,
            'decimals' => null,
            'hidden' => 0,
            'locked' => 0,
            'locktime' => 0,
            'needsupdate' => 0,
            'weightoverride' => 0,
            'sortorder' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'itemnumber' => 0,
            'calculation' => null,
            'idnumber' => null,
            'aggregationcoef' => 0,
            'aggregationcoef2' => 0,
            'plusfactor' => 0,
            'multfactor' => 1.0,
        ]);
        // Grade record exists but finalgrade is null (not yet graded).
        $DB->insert_record('grade_grades', (object)[
            'itemid' => $giid,
            'userid' => $user->id,
            'rawgrade' => null,
            'finalgrade' => null,
            'rawgrademax' => 10.0,
            'rawgrademin' => 0.0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $this->setUser($user);
        $result = $this->call($user->id);

        $this->assertCount(1, $result['active_courses']);
        $row = $result['active_courses'][0];
        $this->assertCount(1, $row['grade_items']);
        $this->assertSame(0, $row['grade_items'][0]['percent']);
    }

    /**
     * Hidden grade items (hidden = 1) must not appear in grade_items.
     *
     * @return void
     */
    public function test_hidden_grade_item_excluded(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $gen = self::getDataGenerator();
        $course = $gen->create_course(['startdate' => $now - DAYSECS]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $DB->insert_record('grade_items', (object)[
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => 1,
            'itemname' => 'Quiz ẩn',
            'grademax' => 10.0,
            'grademin' => 0.0,
            'gradepass' => 0.0,
            'scaleid' => null,
            'outcomeid' => null,
            'gradetype' => 1,
            'display' => 0,
            'decimals' => null,
            'hidden' => 1,
            'locked' => 0,
            'locktime' => 0,
            'needsupdate' => 0,
            'weightoverride' => 0,
            'sortorder' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'itemnumber' => 0,
            'calculation' => null,
            'idnumber' => null,
            'aggregationcoef' => 0,
            'aggregationcoef2' => 0,
            'plusfactor' => 0,
            'multfactor' => 1.0,
        ]);

        $this->setUser($user);
        $result = $this->call($user->id);

        $this->assertCount(1, $result['active_courses']);
        $this->assertSame([], $result['active_courses'][0]['grade_items']);
    }

    /**
     * A scale-graded item (gradetype = 2) must not appear in grade_items — its
     * finalgrade is a scale option id, not a numeric score, so a percent would
     * be meaningless.
     *
     * @return void
     */
    public function test_scale_graded_item_excluded(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $gen = self::getDataGenerator();
        $course = $gen->create_course(['startdate' => $now - DAYSECS]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        // gradetype = 2 (SCALE): finalgrade stores scale option id, not a numeric score.
        $DB->insert_record('grade_items', (object)[
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => 1,
            'itemname' => 'Bài tập thang điểm',
            'grademax' => 5.0,
            'grademin' => 0.0,
            'gradepass' => 0.0,
            'scaleid' => 1,
            'outcomeid' => null,
            'gradetype' => 2,
            'display' => 0,
            'decimals' => null,
            'hidden' => 0,
            'locked' => 0,
            'locktime' => 0,
            'needsupdate' => 0,
            'weightoverride' => 0,
            'sortorder' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'itemnumber' => 0,
            'calculation' => null,
            'idnumber' => null,
            'aggregationcoef' => 0,
            'aggregationcoef2' => 0,
            'plusfactor' => 0,
            'multfactor' => 1.0,
        ]);

        $this->setUser($user);
        $result = $this->call($user->id);

        $this->assertCount(1, $result['active_courses']);
        $this->assertSame([], $result['active_courses'][0]['grade_items'],
            'Scale-graded items (gradetype=2) must be excluded from grade_items.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // attendance_percent edge cases
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * attendance_percent is null when mod_attendance tables are not installed.
     *
     * On a plain Moodle without mod_attendance, get_attendance_percent() must
     * short-circuit to null — it must never throw.
     *
     * @return void
     */
    public function test_attendance_percent_null_when_tables_absent(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $gen = self::getDataGenerator();
        $course = $gen->create_course(['startdate' => $now - DAYSECS]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $dbman = $DB->get_manager();
        $hasAttendance = $dbman->table_exists('attendance')
            && $dbman->table_exists('attendance_sessions')
            && $dbman->table_exists('attendance_log')
            && $dbman->table_exists('attendance_statuses');

        $result = $this->call($user->id);

        $this->assertCount(1, $result['active_courses']);
        $row = $result['active_courses'][0];
        $this->assertArrayHasKey('attendance_percent', $row);

        if (!$hasAttendance) {
            $this->assertNull($row['attendance_percent']);
        }
        // When tables exist the value may be null (no sessions) or 0-100.
    }

    /**
     * attendance_percent is null when the learner has no logged attendance sessions.
     *
     * Requires mod_attendance tables; skipped on plain Moodle.
     *
     * @return void
     */
    public function test_attendance_percent_null_when_no_sessions(): void {
        global $DB;
        $this->resetAfterTest();

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('attendance') || !$dbman->table_exists('attendance_sessions')
            || !$dbman->table_exists('attendance_log') || !$dbman->table_exists('attendance_statuses')) {
            $this->markTestSkipped('mod_attendance not installed; attendance null-session path not exercisable.');
        }

        $now = time();
        $gen = self::getDataGenerator();
        $course = $gen->create_course(['startdate' => $now - DAYSECS]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $result = $this->call($user->id);

        $this->assertCount(1, $result['active_courses']);
        $this->assertNull($result['active_courses'][0]['attendance_percent']);
    }

    /**
     * Logs pointing to a soft-deleted attendance status (deleted = 1) must not
     * be counted. The session total drops to zero → result is null.
     *
     * Requires mod_attendance tables; skipped on plain Moodle.
     *
     * @return void
     */
    public function test_attendance_percent_excludes_deleted_statuses(): void {
        global $DB;
        $this->resetAfterTest();

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('attendance') || !$dbman->table_exists('attendance_sessions')
            || !$dbman->table_exists('attendance_log') || !$dbman->table_exists('attendance_statuses')) {
            $this->markTestSkipped('mod_attendance not installed; deleted-status path not exercisable.');
        }

        $now = time();
        $gen = self::getDataGenerator();
        $course = $gen->create_course(['startdate' => $now - DAYSECS]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        // Create attendance instance.
        $attid = $DB->insert_record('attendance', (object)[
            'course' => $course->id,
            'name' => 'Test Attendance',
            'intro' => '',
            'introformat' => 0,
            'grade' => 100,
            'timemodified' => $now,
        ]);

        // Create a soft-deleted status (deleted = 1).
        $statusid = $DB->insert_record('attendance_statuses', (object)[
            'attendanceid' => $attid,
            'acronym' => 'P',
            'description' => 'Present',
            'grade' => 2,
            'deleted' => 1,
            'setnumber' => 0,
            'visible' => 1,
            'availablebeforesession' => 0,
            'setunmarked' => 0,
        ]);

        // Create a session and log the learner against the deleted status.
        $sessionid = $DB->insert_record('attendance_sessions', (object)[
            'attendanceid' => $attid,
            'groupid' => 0,
            'sessdate' => $now - HOURSECS,
            'duration' => 3600,
            'timemodified' => $now,
            'description' => '',
            'descriptionformat' => 0,
            'studentscanmark' => 0,
            'autoassignstatus' => 0,
            'preventsharedip' => 0,
            'preventsharediptime' => 0,
            'statusset' => 0,
        ]);
        $DB->insert_record('attendance_log', (object)[
            'sessionid' => $sessionid,
            'studentid' => $user->id,
            'statusid' => $statusid,
            'timetaken' => $now,
            'takenby' => 0,
            'statusset' => 0,
            'remarks' => '',
        ]);

        $this->setUser($user);
        $result = $this->call($user->id);

        $this->assertCount(1, $result['active_courses']);
        // All logs point to a deleted status → the JOIN filters them out → total = 0 → null.
        $this->assertNull($result['active_courses'][0]['attendance_percent'],
            'Logs pointing to deleted attendance statuses must not inflate the total.');
    }
}
