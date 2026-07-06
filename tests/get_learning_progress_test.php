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
 * Tests for the get_learning_progress external function.
 *
 * Covers the QA plan's high-priority cases: AC-08 security (self-only vs
 * viewdetails), AC-09 brand-new user, grademax = 0 division guard, and
 * duplicate-enrolment de-duplication.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_vbs_myoverview\external\get_learning_progress
 */
final class get_learning_progress_test extends \advanced_testcase {

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

    /**
     * Mark a user complete for a course (Completion API side-stepped for a stable fixture).
     *
     * @param int $courseid
     * @param int $userid
     * @param int $when completion timestamp
     * @return void
     */
    protected function complete_course(int $courseid, int $userid, int $when): void {
        global $DB;
        $DB->insert_record('course_completions', (object)[
            'userid' => $userid,
            'course' => $courseid,
            'timeenrolled' => $when - DAYSECS,
            'timestarted' => $when - DAYSECS,
            'timecompleted' => $when,
        ]);
    }

    /**
     * TC-11 / AC-09: a brand-new learner reading their own progress gets a valid,
     * fully-empty payload and no exception.
     *
     * @return void
     */
    public function test_new_user_gets_empty_payload(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = $this->call($user->id);

        $this->assertSame((int)$user->id, $result['userid']);
        $this->assertNotEmpty($result['fullname']);
        $this->assertGreaterThan(0, $result['generated_at']);
        $this->assertSame([], $result['active_courses']);
        $this->assertSame([], $result['completed_courses']);
        $this->assertNull($result['training_plan']);
        $this->assertSame([], $result['certificates']);
        $this->assertSame([], $result['warnings']);
    }

    /**
     * TC-09 / AC-08 (P0 security): a plain learner cannot read another user's data.
     *
     * @return void
     */
    public function test_learner_cannot_read_other_user(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $viewer = $gen->create_user();
        $target = $gen->create_user();
        $this->setUser($viewer);

        $this->expectException(\required_capability_exception::class);
        get_learning_progress::execute($target->id);
    }

    /**
     * TC-10 / AC-08: a user holding moodle/user:viewdetails on the target may read it.
     *
     * @return void
     */
    public function test_viewdetails_can_read_other_user(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $viewer = $gen->create_user();
        $target = $gen->create_user();

        $targetcontext = \context_user::instance($target->id);
        $roleid = $gen->create_role();
        assign_capability('moodle/user:viewdetails', CAP_ALLOW, $roleid, $targetcontext->id);
        role_assign($roleid, $viewer->id, $targetcontext->id);

        $this->setUser($viewer);
        $result = $this->call($target->id);

        $this->assertSame((int)$target->id, $result['userid']);
    }

    /**
     * TC-12: grademax = 0 must not divide-by-zero; completion_percent is 0.
     *
     * @return void
     */
    public function test_grademax_zero_yields_zero_percent(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['startdate' => $now - DAYSECS]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $gi = \grade_item::fetch_course_item($course->id);
        $DB->set_field('grade_items', 'grademax', 0, ['id' => $gi->id]);
        $DB->insert_record('grade_grades', (object)[
            'itemid' => $gi->id,
            'userid' => $user->id,
            'finalgrade' => 50,
        ]);

        $this->setUser($user);
        $result = $this->call($user->id);

        $this->assertCount(1, $result['active_courses']);
        $this->assertSame(0, $result['active_courses'][0]['completion_percent']);
    }

    /**
     * A graded, deadlined active course reports the computed percent and deadline.
     *
     * @return void
     */
    public function test_active_course_reports_percent_and_deadline(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $deadline = $now + (30 * DAYSECS);
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['startdate' => $now - DAYSECS]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id, null, 'manual', 0, $deadline);

        $gi = \grade_item::fetch_course_item($course->id);
        $DB->set_field('grade_items', 'grademax', 100, ['id' => $gi->id]);
        $DB->insert_record('grade_grades', (object)[
            'itemid' => $gi->id,
            'userid' => $user->id,
            'finalgrade' => 65,
        ]);

        $this->setUser($user);
        $result = $this->call($user->id);

        $this->assertCount(1, $result['active_courses']);
        $row = $result['active_courses'][0];
        $this->assertSame(65, $row['completion_percent']);
        $this->assertSame($deadline, $row['deadline']);
    }

    /**
     * TC-13: a course reached through two enrol instances appears exactly once.
     *
     * @return void
     */
    public function test_duplicate_enrolment_deduplicated(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['startdate' => $now - DAYSECS]);
        $user = $gen->create_user();

        // First enrolment: manual (default instance).
        $gen->enrol_user($user->id, $course->id, null, 'manual');

        // Second enrolment for the same course through a self-enrol instance.
        $selfplugin = enrol_get_plugin('self');
        $instanceid = $selfplugin->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED]);
        $instance = $DB->get_record('enrol', ['id' => $instanceid]);
        $selfplugin->enrol_user($instance, $user->id, null, 0, 0, ENROL_USER_ACTIVE);

        $this->setUser($user);
        $result = $this->call($user->id);

        $courseids = array_column($result['active_courses'], 'courseid');
        $this->assertSame([(int)$course->id], $courseids);
    }

    /**
     * A completed course lands in completed_courses (newest-first) and not in active.
     *
     * @return void
     */
    public function test_completed_course_is_separated_and_sorted(): void {
        $this->resetAfterTest();
        $now = time();
        $gen = $this->getDataGenerator();
        $user = $gen->create_user();

        $older = $gen->create_course(['startdate' => $now - (10 * DAYSECS)]);
        $newer = $gen->create_course(['startdate' => $now - (10 * DAYSECS)]);
        $gen->enrol_user($user->id, $older->id);
        $gen->enrol_user($user->id, $newer->id);
        $this->complete_course($older->id, $user->id, $now - (5 * DAYSECS));
        $this->complete_course($newer->id, $user->id, $now - DAYSECS);

        $this->setUser($user);
        $result = $this->call($user->id);

        $this->assertSame([], $result['active_courses']);
        $ids = array_column($result['completed_courses'], 'courseid');
        // Newest completion first.
        $this->assertSame([(int)$newer->id, (int)$older->id], $ids);
        $this->assertNull($result['completed_courses'][0]['cert_url']);
        $this->assertNull($result['completed_courses'][0]['cert_code']);
    }
}
