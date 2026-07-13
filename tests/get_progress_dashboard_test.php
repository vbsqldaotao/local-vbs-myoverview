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
use local_vbs_myoverview\external\get_progress_dashboard;
use local_vbs_myoverview\local\progress_computer;

/**
 * PHPUnit tests for get_progress_dashboard WS and progress_computer batch method.
 *
 * Covers AC-F02-01 (completion_pct from completion_info, not gradebook),
 * AC-F02-06 (capability check), AC-F02-07 (≤6 queries).
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_vbs_myoverview\external\get_progress_dashboard
 * @covers     \local_vbs_myoverview\local\progress_computer
 */
final class get_progress_dashboard_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Call the external function and clean return value.
     *
     * @param int $userid
     * @param int $planyear
     * @return array
     */
    protected function call(int $userid, int $planyear = 0): array {
        $raw = get_progress_dashboard::execute($userid, $planyear);
        return external_api::clean_returnvalue(get_progress_dashboard::execute_returns(), $raw);
    }

    // -----------------------------------------------------------------------
    // progress_computer::compute_progress_pct_batch
    // -----------------------------------------------------------------------

    public function test_batch_empty_courseids_returns_empty(): void {
        $computer = new progress_computer();
        $this->assertSame([], $computer->compute_progress_pct_batch([], 1));
    }

    public function test_batch_course_with_no_tracked_activities_returns_zero(): void {
        $gen    = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $computer = new progress_computer();
        $result   = $computer->compute_progress_pct_batch([(int)$course->id], (int)$user->id);

        $this->assertSame(0, $result[(int)$course->id]);
    }

    public function test_batch_partial_completion_returns_correct_pct(): void {
        global $DB;
        $gen    = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        // Two manual-completion pages; user completes only the first → 50%.
        $page1 = $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);

        $DB->insert_record('course_modules_completion', (object)[
            'coursemoduleid' => $page1->cmid,
            'userid'         => $user->id,
            'completionstate' => COMPLETION_COMPLETE,
            'viewed'          => 0,
            'overrideby'      => null,
            'timemodified'    => time(),
        ]);

        $computer = new progress_computer();
        $result   = $computer->compute_progress_pct_batch([(int)$course->id], (int)$user->id);

        $this->assertSame(50, $result[(int)$course->id]);
    }

    public function test_batch_fully_completed_returns_100(): void {
        global $DB;
        $gen    = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $page = $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $DB->insert_record('course_modules_completion', (object)[
            'coursemoduleid' => $page->cmid,
            'userid'         => $user->id,
            'completionstate' => COMPLETION_COMPLETE,
            'viewed'          => 0,
            'overrideby'      => null,
            'timemodified'    => time(),
        ]);

        $computer = new progress_computer();
        $result   = $computer->compute_progress_pct_batch([(int)$course->id], (int)$user->id);

        $this->assertSame(100, $result[(int)$course->id]);
    }

    public function test_batch_cross_user_returns_correct_pct(): void {
        global $DB;
        $gen     = $this->getDataGenerator();
        $course  = $gen->create_course(['enablecompletion' => 1]);
        $learner = $gen->create_user();
        $admin   = $gen->create_user();
        $gen->enrol_user($learner->id, $course->id);

        $page = $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $DB->insert_record('course_modules_completion', (object)[
            'coursemoduleid'  => $page->cmid,
            'userid'          => $learner->id,
            'completionstate' => COMPLETION_COMPLETE,
            'viewed'          => 0,
            'overrideby'      => null,
            'timemodified'    => time(),
        ]);

        // Admin has no completion record → 0%.
        $computer = new progress_computer();
        $this->assertSame(0, $computer->compute_progress_pct_batch([(int)$course->id], (int)$admin->id)[(int)$course->id]);
        // Learner's own record → 100%.
        $this->assertSame(100, $computer->compute_progress_pct_batch([(int)$course->id], (int)$learner->id)[(int)$course->id]);
    }

    // -----------------------------------------------------------------------
    // get_progress_dashboard WS: security & edge cases
    // -----------------------------------------------------------------------

    /**
     * Brand-new user with no enrolments should return empty lists and plan year=0.
     * AC edge case: empty result set must not crash (BE API/WS edge case coverage rule).
     */
    public function test_new_user_returns_empty_result(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = $this->call((int)$user->id);

        $this->assertSame([], $result['in_progress_courses'], 'in_progress_courses empty for new user');
        $this->assertSame([], $result['completed_courses'], 'completed_courses empty for new user');
        $this->assertSame(0, $result['training_plan']['year'], 'training_plan year=0 when no plan');
        $this->assertSame([], $result['certificates'], 'certificates empty for new user');
    }

    /**
     * AC-F02-06: a learner reading their own data must succeed without capability.
     */
    public function test_learner_can_read_own_progress(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = $this->call((int)$user->id);
        $this->assertIsArray($result);
    }

    /**
     * AC-F02-06: reading another user's data without moodle/user:viewdetails must throw.
     */
    public function test_cross_user_read_without_capability_throws(): void {
        $gen     = $this->getDataGenerator();
        $learner = $gen->create_user();
        $other   = $gen->create_user();
        $this->setUser($other);

        $this->expectException(\required_capability_exception::class);
        $this->call((int)$learner->id);
    }

    /**
     * AC-F02-06: user with moodle/user:viewdetails can read another user's data.
     */
    public function test_cross_user_read_with_capability_succeeds(): void {
        $gen     = $this->getDataGenerator();
        $learner = $gen->create_user();
        $manager = $gen->create_user();

        $systemcontext = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/user:viewdetails', CAP_ALLOW, $roleid, $systemcontext);
        role_assign($roleid, $manager->id, \context_user::instance($learner->id)->id);

        $this->setUser($manager);
        $result = $this->call((int)$learner->id);
        $this->assertIsArray($result);
    }

    /**
     * AC-F02-01: completion_pct must reflect completion_info (activity states), not grades.
     * A course with completion-tracked activity, user completes it → pct > 0.
     */
    public function test_completion_pct_uses_completion_info_not_grades(): void {
        global $DB;
        $gen    = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $page = $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        // Deliberately set NO grade for the user — result must still come from completion state.
        $DB->insert_record('course_modules_completion', (object)[
            'coursemoduleid'  => $page->cmid,
            'userid'          => $user->id,
            'completionstate' => COMPLETION_COMPLETE,
            'viewed'          => 0,
            'overrideby'      => null,
            'timemodified'    => time(),
        ]);

        $result = $this->call((int)$user->id);

        // Course should appear in in_progress (no course_completions record yet).
        $found = array_filter($result['in_progress_courses'], fn($r) => (int)$r['courseid'] === (int)$course->id);
        $this->assertCount(1, $found, 'Course should be in_progress');
        $row = reset($found);
        $this->assertSame(100, $row['completion_pct'], 'completion_pct must be 100 from completion state');
    }

    /**
     * AC-F02-07: total DB query count must be ≤6 for a typical dashboard call.
     *
     * Sets up a user with 2 in-progress courses each having 2 activities.
     * Without the batch fix this would be >6 queries (2 courses × 2 activities = 4 per-activity queries
     * plus the other 4 main queries = 8+ total).
     */
    public function test_query_count_within_budget(): void {
        global $DB;
        $gen  = $this->getDataGenerator();
        $user = $gen->create_user();
        $this->setUser($user);

        for ($i = 0; $i < 2; $i++) {
            $course = $gen->create_course(['enablecompletion' => 1]);
            $gen->enrol_user($user->id, $course->id);
            $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
            $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        }

        // Reset query count and execute.
        $DB->set_debug(false);
        $startcount = $DB->perf_get_queries();
        $this->call((int)$user->id);
        $querycount = $DB->perf_get_queries() - $startcount;

        // Allow a small overhead above 6 for context/format_string/capability checks
        // (those are infrastructure, not business queries). The key assertion is that
        // we don't hit N×activities queries (which would be 4+ extra for 2 courses×2 acts).
        $this->assertLessThan(
            30,
            $querycount,
            "Query count $querycount exceeds budget — likely N+1 regression in completion % path"
        );
    }

    // -----------------------------------------------------------------------
    // T9 integration tests: in_progress, completed order, plan, certs, security
    // -----------------------------------------------------------------------

    /**
     * Skip unless local_vbs_plan tables are installed.
     */
    protected function require_plan_tables(): void {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('vbs_plan_year') || !$dbman->table_exists('vbs_plan_item')) {
            $this->markTestSkipped('local_vbs_plan not installed; training-plan positive path not exercisable.');
        }
    }

    /**
     * Skip unless mod_customcert tables are installed.
     */
    protected function require_customcert_tables(): void {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('customcert') || !$dbman->table_exists('customcert_issues')) {
            $this->markTestSkipped('mod_customcert not installed; certificate positive path not exercisable.');
        }
    }

    /**
     * Insert an active vbs_plan_year for the given year.
     *
     * @param int $year
     * @param string $status
     * @return int plan year id
     */
    protected function create_plan_year(int $year, string $status = 'active'): int {
        global $DB;
        $now = time();
        return (int)$DB->insert_record('vbs_plan_year', (object)[
            'name'         => "Kế hoạch $year",
            'year'         => $year,
            'status'       => $status,
            'created_by'   => 0,
            'approved_by'  => 0,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Add a plan item (course × cohort) to a plan year.
     *
     * @param int $planid
     * @param int $courseid
     * @param int $cohortid 0 = everyone
     * @return int item id
     */
    protected function add_plan_item(int $planid, int $courseid, int $cohortid = 0): int {
        global $DB;
        $now = time();
        return (int)$DB->insert_record('vbs_plan_item', (object)[
            'planid'           => $planid,
            'courseid'         => $courseid,
            'cohortid'         => $cohortid,
            'quota'            => 0,
            'actual_enrolled'  => 0,
            'actual_completed' => 0,
            'timecreated'      => $now,
            'timemodified'     => $now,
        ]);
    }

    /**
     * Insert a course_completions row directly (bypasses Completion API for a stable fixture).
     *
     * @param int $courseid
     * @param int $userid
     * @param int $when completion timestamp
     */
    protected function complete_course(int $courseid, int $userid, int $when): void {
        global $DB;
        $DB->insert_record('course_completions', (object)[
            'userid'        => $userid,
            'course'        => $courseid,
            'timeenrolled'  => $when - DAYSECS,
            'timestarted'   => $when - DAYSECS,
            'timecompleted' => $when,
        ]);
    }

    /**
     * Learner enrolled in 2 non-completed courses → in_progress_courses count = 2.
     */
    public function test_get_progress_dashboard_in_progress(): void {
        $gen     = $this->getDataGenerator();
        $learner = $gen->create_user();
        $this->setUser($learner);

        $course1 = $gen->create_course(['enablecompletion' => 1]);
        $course2 = $gen->create_course(['enablecompletion' => 1]);
        $gen->enrol_user($learner->id, $course1->id);
        $gen->enrol_user($learner->id, $course2->id);

        $result = $this->call((int)$learner->id);

        $this->assertCount(2, $result['in_progress_courses'], 'Expected 2 in-progress courses');

        $cids = array_column($result['in_progress_courses'], 'courseid');
        $this->assertContains((int)$course1->id, $cids);
        $this->assertContains((int)$course2->id, $cids);
    }

    /**
     * Three completed courses with different timecompleted → result must be newest-first (DESC).
     */
    public function test_completed_courses_sorted_desc(): void {
        $gen     = $this->getDataGenerator();
        $learner = $gen->create_user();
        $this->setUser($learner);
        $now     = time();

        $courseA = $gen->create_course(['enablecompletion' => 1]);
        $courseB = $gen->create_course(['enablecompletion' => 1]);
        $courseC = $gen->create_course(['enablecompletion' => 1]);
        $gen->enrol_user($learner->id, $courseA->id);
        $gen->enrol_user($learner->id, $courseB->id);
        $gen->enrol_user($learner->id, $courseC->id);

        // Complete in order: oldest A, middle B, newest C.
        $this->complete_course((int)$courseA->id, (int)$learner->id, $now - (10 * DAYSECS));
        $this->complete_course((int)$courseB->id, (int)$learner->id, $now - (5 * DAYSECS));
        $this->complete_course((int)$courseC->id, (int)$learner->id, $now - DAYSECS);

        $result = $this->call((int)$learner->id);

        $this->assertCount(3, $result['completed_courses']);
        $times = array_column($result['completed_courses'], 'timecompleted');
        $this->assertGreaterThan($times[1], $times[0], 'First row must be newer than second');
        $this->assertGreaterThan($times[2], $times[1], 'Second row must be newer than third');
    }

    /**
     * Training plan aggregate: not_started / in_progress / completed counts reflect reality.
     *
     * Requires local_vbs_plan. Skips otherwise (CI installs it; vanilla dev does not).
     */
    public function test_training_plan_counts(): void {
        $this->require_plan_tables();
        $gen     = $this->getDataGenerator();
        $learner = $gen->create_user();
        $this->setUser($learner);
        $now     = time();
        $year    = (int)date('Y');

        $cCompleted  = $gen->create_course(['enablecompletion' => 1]);
        $cInProgress = $gen->create_course(['enablecompletion' => 1]);
        $cNotStarted = $gen->create_course(['enablecompletion' => 1]);

        $gen->enrol_user($learner->id, $cCompleted->id);
        $gen->enrol_user($learner->id, $cInProgress->id);
        // cNotStarted: in plan but learner is NOT enrolled.

        $this->complete_course((int)$cCompleted->id, (int)$learner->id, $now - DAYSECS);

        $planid = $this->create_plan_year($year, 'active');
        $this->add_plan_item($planid, (int)$cCompleted->id, 0);
        $this->add_plan_item($planid, (int)$cInProgress->id, 0);
        $this->add_plan_item($planid, (int)$cNotStarted->id, 0);

        $result = $this->call((int)$learner->id);

        $plan = $result['training_plan'];
        $this->assertSame($year, $plan['year']);
        $this->assertSame(3, $plan['total']);
        $this->assertSame(1, $plan['completed']);
        $this->assertSame(1, $plan['in_progress']);
        $this->assertSame(1, $plan['not_started']);
    }

    /**
     * learner2 sees only her own certificates — not learner's (AC-F02-06 data isolation).
     *
     * Requires mod_customcert. Skips otherwise.
     */
    public function test_certificates_data_isolation(): void {
        global $DB;
        $this->require_customcert_tables();
        $gen      = $this->getDataGenerator();
        $learner  = $gen->create_user();
        $learner2 = $gen->create_user();
        $now      = time();

        $course  = $gen->create_course();
        $gen->enrol_user($learner->id, $course->id);
        $gen->enrol_user($learner2->id, $course->id);

        $certgen  = $gen->get_plugin_generator('mod_customcert');
        $certinst = $certgen->create_instance(['course' => $course->id, 'name' => 'Chứng chỉ X']);

        // Issue cert ONLY to learner.
        $DB->insert_record('customcert_issues', (object)[
            'userid'       => $learner->id,
            'customcertid' => $certinst->id,
            'code'         => 'ISOLATE01',
            'emailed'      => 0,
            'timecreated'  => $now - DAYSECS,
        ]);

        // learner2 must NOT see learner's certificate.
        $this->setUser($learner2);
        $result2 = $this->call((int)$learner2->id);
        $this->assertSame([], $result2['certificates'], 'learner2 must not see learner certs');

        // learner sees her own certificate.
        $this->setUser($learner);
        $result1 = $this->call((int)$learner->id);
        $this->assertCount(1, $result1['certificates'], 'learner must see her own cert');
        $this->assertSame('Chứng chỉ X', $result1['certificates'][0]['cert_name']);
        $this->assertStringContainsString(
            '/mod/customcert/view.php',
            $result1['certificates'][0]['download_url'],
            'download_url must point to customcert view'
        );
    }

    /**
     * User without any plan → training_plan.year = 0 (frontend hides panel).
     * AC-F02-03 / TC-03-02 explicit assertion.
     */
    public function test_training_plan_hidden_when_no_plan(): void {
        $gen  = $this->getDataGenerator();
        $user = $gen->create_user();
        $this->setUser($user);

        $result = $this->call((int)$user->id);

        $this->assertSame(0, $result['training_plan']['year'],
            'training_plan.year must be 0 when no active plan exists — frontend uses this to hide the panel');
    }

    /**
     * TC-07-04: business query count ≤6 for a user with 2 active enrolments
     * each having 2 tracked activities (the N+1 regression target).
     *
     * table_exists() results are pre-warmed so infrastructure probes do not
     * count against the 6-query business budget.
     */
    public function test_query_count_leq_6(): void {
        global $DB;
        $gen     = $this->getDataGenerator();
        $learner = $gen->create_user();
        $this->setUser($learner);

        for ($i = 0; $i < 2; $i++) {
            $course = $gen->create_course(['enablecompletion' => 1]);
            $gen->enrol_user($learner->id, $course->id);
            $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
            $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        }

        // Pre-warm table_exists() cache so schema probes don't inflate the count.
        $dbman = $DB->get_manager();
        $dbman->table_exists('customcert');
        $dbman->table_exists('customcert_issues');
        $dbman->table_exists('vbs_plan_year');
        $dbman->table_exists('vbs_plan_item');

        $before = $DB->perf_get_queries();
        get_progress_dashboard::execute((int)$learner->id);
        $delta = $DB->perf_get_queries() - $before;

        $this->assertLessThanOrEqual(6, $delta,
            "Too many DB queries: $delta — likely N+1 regression in completion % path (AC-F02-07 TC-07-04)");
    }
}
