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
 * Positive-path tests for the training-plan and certificate sections of
 * get_learning_progress.
 *
 * These branches only exercise when the optional org plugins are installed on
 * the test site: `local_vbs_plan` supplies `vbs_plan_year` / `vbs_plan_item`,
 * and `mod_customcert` supplies `customcert` / `customcert_issues`. On a vanilla
 * Moodle those tables do not exist and {@see get_learning_progress::build_training_plan()}
 * / {@see get_learning_progress::build_certificates()} short-circuit to the null
 * branch — already covered by {@see get_learning_progress_test}. VBS-165 extends
 * the CI harness to install both plugins so these positive branches (cohort
 * filtering, per-item status derivation, deleted-course warnings, issued-cert
 * rows) are finally locked by automated tests.
 *
 * Each test self-skips when its dependency plugin is absent, so the suite stays
 * green on a plain Moodle checkout and only asserts the real path in the
 * plugin-enabled CI harness.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_vbs_myoverview\external\get_learning_progress
 */
final class get_learning_progress_plan_cert_test extends \advanced_testcase {

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
     * Skip the current test unless local_vbs_plan's tables exist on this site.
     *
     * @return void
     */
    protected function require_plan_tables(): void {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('vbs_plan_year') || !$dbman->table_exists('vbs_plan_item')) {
            $this->markTestSkipped('local_vbs_plan not installed; training-plan positive path not exercisable.');
        }
    }

    /**
     * Skip the current test unless mod_customcert's tables exist on this site.
     *
     * @return void
     */
    protected function require_customcert_tables(): void {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('customcert') || !$dbman->table_exists('customcert_issues')) {
            $this->markTestSkipped('mod_customcert not installed; certificate positive path not exercisable.');
        }
    }

    /**
     * Insert an active plan year for the current calendar year (the year
     * build_training_plan() looks up), returning its id.
     *
     * @param string $status one of the vbs_plan_year statuses (active|approved|...)
     * @return int the vbs_plan_year id
     */
    protected function create_plan_year(string $status = 'active'): int {
        global $DB;
        $now = time();
        return (int)$DB->insert_record('vbs_plan_year', (object)[
            'name' => 'Kế hoạch ' . date('Y'),
            'year' => (int)date('Y'),
            'status' => $status,
            'created_by' => 0,
            'approved_by' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Add a plan item (course × cohort) to a plan year, returning its id.
     *
     * @param int $planid vbs_plan_year id
     * @param int $courseid course id (may point at a deleted course for the warning path)
     * @param int $cohortid target cohort id, or 0 for "everyone"
     * @return int the vbs_plan_item id
     */
    protected function add_plan_item(int $planid, int $courseid, int $cohortid = 0): int {
        global $DB;
        $now = time();
        return (int)$DB->insert_record('vbs_plan_item', (object)[
            'planid' => $planid,
            'courseid' => $courseid,
            'cohortid' => $cohortid,
            'quota' => 0,
            'actual_enrolled' => 0,
            'actual_completed' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Mark a user complete for a course (Completion API side-stepped for a stable
     * fixture, matching get_learning_progress_test::complete_course()).
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
     * Positive training-plan path: an active plan whose items span all three
     * derived statuses reports the correct per-status counts and item rows.
     *
     * @return void
     */
    public function test_training_plan_reports_items_and_status_counts(): void {
        $this->resetAfterTest();
        $this->require_plan_tables();
        $now = time();
        $gen = self::getDataGenerator();
        $user = $gen->create_user();

        // Three courses → one completed, one enrolled-not-complete, one plan-only.
        $completedcourse = $gen->create_course(['startdate' => $now - (10 * DAYSECS)]);
        $inprogresscourse = $gen->create_course(['startdate' => $now - (10 * DAYSECS)]);
        $notstartedcourse = $gen->create_course(['startdate' => $now - (10 * DAYSECS)]);

        $gen->enrol_user($user->id, $completedcourse->id);
        $gen->enrol_user($user->id, $inprogresscourse->id);
        $this->complete_course($completedcourse->id, $user->id, $now - DAYSECS);
        // notstartedcourse: in the plan (cohort 0) but the learner is not enrolled.

        $planid = $this->create_plan_year('active');
        $this->add_plan_item($planid, $completedcourse->id, 0);
        $this->add_plan_item($planid, $inprogresscourse->id, 0);
        $this->add_plan_item($planid, $notstartedcourse->id, 0);

        $this->setUser($user);
        $result = $this->call($user->id);

        $this->assertArrayHasKey('training_plan', $result);
        $plan = $result['training_plan'];
        $this->assertSame((int)date('Y'), $plan['year']);
        $this->assertSame(3, $plan['total_items']);
        $this->assertSame(1, $plan['completed_items']);
        $this->assertSame(1, $plan['in_progress_items']);
        $this->assertSame(1, $plan['not_started_items']);

        // Map courseid → status for order-independent assertions.
        $status = [];
        foreach ($plan['items'] as $item) {
            $status[$item['courseid']] = $item['status'];
            // duedate has no source column and is always null.
            $this->assertNull($item['duedate']);
        }
        $this->assertSame(get_learning_progress::PLAN_COMPLETED, $status[(int)$completedcourse->id]);
        $this->assertSame(get_learning_progress::PLAN_IN_PROGRESS, $status[(int)$inprogresscourse->id]);
        $this->assertSame(get_learning_progress::PLAN_NOT_STARTED, $status[(int)$notstartedcourse->id]);
        // No deleted courses → no warnings.
        $this->assertSame([], $result['warnings']);
    }

    /**
     * Cohort filter: an item targeting a cohort the learner is NOT in is excluded;
     * items targeting a cohort the learner IS in and items with cohortid = 0
     * (everyone) are included.
     *
     * @return void
     */
    public function test_training_plan_filters_by_cohort(): void {
        $this->resetAfterTest();
        $this->require_plan_tables();
        $now = time();
        $gen = self::getDataGenerator();
        $user = $gen->create_user();

        $mycohort = $gen->create_cohort();
        $othercohort = $gen->create_cohort();
        cohort_add_member($mycohort->id, $user->id);

        $everyonecourse = $gen->create_course(['startdate' => $now - (10 * DAYSECS)]);
        $mycohortcourse = $gen->create_course(['startdate' => $now - (10 * DAYSECS)]);
        $othercohortcourse = $gen->create_course(['startdate' => $now - (10 * DAYSECS)]);

        $planid = $this->create_plan_year('active');
        $this->add_plan_item($planid, $everyonecourse->id, 0);
        $this->add_plan_item($planid, $mycohortcourse->id, (int)$mycohort->id);
        $this->add_plan_item($planid, $othercohortcourse->id, (int)$othercohort->id);

        $this->setUser($user);
        $result = $this->call($user->id);

        $this->assertArrayHasKey('training_plan', $result);
        $courseids = array_column($result['training_plan']['items'], 'courseid');
        sort($courseids);
        $expected = [(int)$everyonecourse->id, (int)$mycohortcourse->id];
        sort($expected);
        $this->assertSame($expected, $courseids);
        $this->assertSame(2, $result['training_plan']['total_items']);
    }

    /**
     * Deleted-course-in-plan: a plan item pointing at a course that no longer
     * exists is skipped and reported in warnings[] (warningcode 'coursedeleted')
     * rather than crashing the payload.
     *
     * @return void
     */
    public function test_training_plan_deleted_course_emits_warning(): void {
        $this->resetAfterTest();
        $this->require_plan_tables();
        $now = time();
        $gen = self::getDataGenerator();
        $user = $gen->create_user();

        $livecourse = $gen->create_course(['startdate' => $now - (10 * DAYSECS)]);
        $ghostcourse = $gen->create_course(['startdate' => $now - (10 * DAYSECS)]);
        $ghostid = (int)$ghostcourse->id;

        $planid = $this->create_plan_year('active');
        $this->add_plan_item($planid, $livecourse->id, 0);
        $deleteditemid = $this->add_plan_item($planid, $ghostid, 0);

        // Delete the course after the plan item references it.
        delete_course($ghostcourse, false);

        $this->setUser($user);
        $result = $this->call($user->id);

        $this->assertArrayHasKey('training_plan', $result);
        // The live course still shows; the deleted one is skipped from items.
        $courseids = array_column($result['training_plan']['items'], 'courseid');
        $this->assertSame([(int)$livecourse->id], $courseids);
        $this->assertSame(1, $result['training_plan']['total_items']);

        // Exactly one coursedeleted warning, pointing at the offending item.
        $this->assertCount(1, $result['warnings']);
        $warning = $result['warnings'][0];
        $this->assertSame('training_plan', $warning['item']);
        $this->assertSame($deleteditemid, $warning['itemid']);
        $this->assertSame('coursedeleted', $warning['warningcode']);
        $this->assertStringContainsString((string)$ghostid, $warning['message']);
    }

    /**
     * No active/approved plan for the current year → training_plan key omitted,
     * even though the plan tables exist (guards against a false-positive when the
     * table check passes but no row matches).
     *
     * @return void
     */
    public function test_training_plan_absent_when_no_active_plan(): void {
        $this->resetAfterTest();
        $this->require_plan_tables();
        $now = time();
        $gen = self::getDataGenerator();
        $user = $gen->create_user();
        $course = $gen->create_course(['startdate' => $now - (10 * DAYSECS)]);

        // A draft plan is neither 'active' nor 'approved' → not selected.
        $planid = $this->create_plan_year('draft');
        $this->add_plan_item($planid, $course->id, 0);

        $this->setUser($user);
        $result = $this->call($user->id);

        $this->assertArrayNotHasKey('training_plan', $result);
    }

    /**
     * Positive certificate path: an issued customcert appears in certificates[]
     * with its name, code, course, and a download URL, newest-first.
     *
     * @return void
     */
    public function test_certificates_reports_issued_certs_newest_first(): void {
        global $DB;
        $this->resetAfterTest();
        $this->require_customcert_tables();
        $now = time();
        $gen = self::getDataGenerator();
        $user = $gen->create_user();
        $course = $gen->create_course(['startdate' => $now - (10 * DAYSECS)]);
        $gen->enrol_user($user->id, $course->id);

        $certgen = $gen->get_plugin_generator('mod_customcert');
        $oldercert = $certgen->create_instance(['course' => $course->id, 'name' => 'Chứng chỉ A']);
        $newercert = $certgen->create_instance(['course' => $course->id, 'name' => 'Chứng chỉ B']);

        $DB->insert_record('customcert_issues', (object)[
            'userid' => $user->id,
            'customcertid' => $oldercert->id,
            'code' => 'OLDCODE01',
            'emailed' => 0,
            'timecreated' => $now - (5 * DAYSECS),
        ]);
        $DB->insert_record('customcert_issues', (object)[
            'userid' => $user->id,
            'customcertid' => $newercert->id,
            'code' => 'NEWCODE02',
            'emailed' => 0,
            'timecreated' => $now - DAYSECS,
        ]);

        $this->setUser($user);
        $result = $this->call($user->id);

        $this->assertCount(2, $result['certificates']);
        // Newest issue first.
        $codes = array_column($result['certificates'], 'code');
        $this->assertSame(['NEWCODE02', 'OLDCODE01'], $codes);

        $first = $result['certificates'][0];
        $this->assertSame('Chứng chỉ B', $first['certname']);
        $this->assertSame((int)$course->id, $first['courseid']);
        $this->assertNotEmpty($first['coursename']);
        $this->assertStringContainsString('/mod/customcert/view.php', $first['download_url']);
        $this->assertStringContainsString('downloadown=1', $first['download_url']);
    }

    /**
     * A certificate issued for a course that was later deleted is skipped (no
     * course row to resolve) rather than emitting a broken certificate entry.
     *
     * @return void
     */
    public function test_certificates_skip_deleted_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->require_customcert_tables();
        $now = time();
        $gen = self::getDataGenerator();
        $user = $gen->create_user();

        $livecourse = $gen->create_course(['startdate' => $now - (10 * DAYSECS)]);
        $ghostcourse = $gen->create_course(['startdate' => $now - (10 * DAYSECS)]);

        $certgen = $gen->get_plugin_generator('mod_customcert');
        $livecert = $certgen->create_instance(['course' => $livecourse->id, 'name' => 'Sống']);
        $ghostcert = $certgen->create_instance(['course' => $ghostcourse->id, 'name' => 'Ma']);

        $DB->insert_record('customcert_issues', (object)[
            'userid' => $user->id,
            'customcertid' => $livecert->id,
            'code' => 'LIVE0001',
            'emailed' => 0,
            'timecreated' => $now - (2 * DAYSECS),
        ]);
        $DB->insert_record('customcert_issues', (object)[
            'userid' => $user->id,
            'customcertid' => $ghostcert->id,
            'code' => 'GHOST001',
            'emailed' => 0,
            'timecreated' => $now - DAYSECS,
        ]);

        delete_course($ghostcourse, false);

        $this->setUser($user);
        $result = $this->call($user->id);

        // Only the live-course certificate survives.
        $this->assertCount(1, $result['certificates']);
        $this->assertSame('LIVE0001', $result['certificates'][0]['code']);
        $this->assertSame((int)$livecourse->id, $result['certificates'][0]['courseid']);
    }

    /**
     * Certificate cross-linking on completed_courses: a completed course whose
     * customcert has been issued to the learner exposes cert_url + cert_code on
     * the completed_courses row.
     *
     * @return void
     */
    public function test_completed_course_exposes_certificate(): void {
        global $DB;
        $this->resetAfterTest();
        $this->require_customcert_tables();
        $now = time();
        $gen = self::getDataGenerator();
        $user = $gen->create_user();
        $course = $gen->create_course(['startdate' => $now - (10 * DAYSECS)]);
        $gen->enrol_user($user->id, $course->id);
        $this->complete_course($course->id, $user->id, $now - DAYSECS);

        $certgen = $gen->get_plugin_generator('mod_customcert');
        $cert = $certgen->create_instance(['course' => $course->id, 'name' => 'Hoàn thành']);
        $DB->insert_record('customcert_issues', (object)[
            'userid' => $user->id,
            'customcertid' => $cert->id,
            'code' => 'DONE0001',
            'emailed' => 0,
            'timecreated' => $now - DAYSECS,
        ]);

        $this->setUser($user);
        $result = $this->call($user->id);

        $this->assertCount(1, $result['completed_courses']);
        $row = $result['completed_courses'][0];
        $this->assertSame((int)$course->id, $row['courseid']);
        $this->assertStringContainsString('/mod/customcert/view.php', $row['cert_url']);
        $this->assertSame('DONE0001', $row['cert_code']);
    }
}
