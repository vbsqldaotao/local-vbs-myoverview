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
use local_vbs_myoverview\external\export_schedule_ics;
use local_vbs_myoverview\external\get_schedule;
use local_vbs_myoverview\local\schedule_aggregator;

/**
 * Tests for the get_schedule and export_schedule_ics external functions.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_vbs_myoverview\external\get_schedule
 * @covers     \local_vbs_myoverview\external\export_schedule_ics
 * @covers     \local_vbs_myoverview\local\schedule_aggregator
 */
final class get_schedule_test extends \advanced_testcase {

    /**
     * Call get_schedule and clean the return value through the WS layer.
     *
     * @param array $args
     * @return array[]
     */
    protected function call_get_schedule(array $args = []): array {
        $defaults = [
            'date_from'    => 0,
            'date_to'      => 0,
            'course_id'    => 0,
            'session_type' => '',
            'instructor'   => '',
            'location'     => '',
        ];
        $p = array_merge($defaults, $args);
        $raw = get_schedule::execute(
            $p['date_from'], $p['date_to'], $p['course_id'],
            $p['session_type'], $p['instructor'], $p['location']
        );
        return external_api::clean_returnvalue(get_schedule::execute_returns(), $raw);
    }

    /**
     * Call export_schedule_ics and clean through the WS layer.
     *
     * @param array $args
     * @return array
     */
    protected function call_export_ics(array $args = []): array {
        $defaults = ['date_from' => 0, 'date_to' => 0, 'course_id' => 0, 'session_type' => ''];
        $p = array_merge($defaults, $args);
        $raw = export_schedule_ics::execute(
            $p['date_from'], $p['date_to'], $p['course_id'], $p['session_type']
        );
        return external_api::clean_returnvalue(export_schedule_ics::execute_returns(), $raw);
    }

    // -----------------------------------------------------------------------
    // Empty-result edge cases (BE API/WS edge case coverage requirement)
    // -----------------------------------------------------------------------

    /**
     * A brand-new user with no enrolments or signups gets an empty schedule.
     */
    public function test_new_user_returns_empty_schedule(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = $this->call_get_schedule();

        $this->assertSame([], $result, 'New user with no sessions must get empty array');
    }

    /**
     * ICS export for a user with no sessions returns a valid but event-free calendar.
     */
    public function test_new_user_ics_export_is_valid_empty_calendar(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $result = $this->call_export_ics();

        $this->assertNotEmpty($result['ics_base64']);
        $ics = base64_decode($result['ics_base64']);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
        $this->assertStringNotContainsString('BEGIN:VEVENT', $ics,
            'Empty schedule must produce no VEVENT blocks');
        $this->assertSame('vbs-schedule.ics', $result['filename']);
    }

    /**
     * Date range filter with no matching sessions returns empty array.
     */
    public function test_date_range_filter_no_match_returns_empty(): void {
        $this->resetAfterTest();
        $gen  = $this->getDataGenerator();
        $user = $gen->create_user();
        $this->setUser($user);

        // Request far-future range: should return nothing even for enrolled courses.
        $far_future = mktime(0, 0, 0, 1, 1, 2099);
        $result = $this->call_get_schedule([
            'date_from' => $far_future,
            'date_to'   => $far_future + DAYSECS,
        ]);

        $this->assertSame([], $result);
    }

    // -----------------------------------------------------------------------
    // Quiz sessions
    // -----------------------------------------------------------------------

    /**
     * A quiz with timeopen set is returned for an enrolled learner.
     */
    public function test_quiz_session_returned_for_enrolled_user(): void {
        global $DB;
        $this->resetAfterTest();

        $gen    = $this->getDataGenerator();
        $course = $gen->create_course();
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $now = time();

        // Create a quiz with a scheduled window.
        $quiz = $gen->create_module('quiz', [
            'course'    => $course->id,
            'timeopen'  => $now + HOURSECS,
            'timeclose' => $now + 2 * HOURSECS,
        ]);

        $this->setUser($user);

        $result = $this->call_get_schedule([
            'session_type' => 'quiz',
            'date_from'    => $now,
            'date_to'      => $now + DAYSECS,
        ]);

        $this->assertNotEmpty($result, 'Enrolled user must see quiz with timeopen');
        $types = array_column($result, 'session_type');
        $this->assertContains('quiz', $types);
        $ids = array_column($result, 'id');
        $this->assertContains('quiz:' . $quiz->id, $ids);
    }

    /**
     * A quiz with no timeopen (timeopen=0) is excluded from the schedule.
     */
    public function test_quiz_without_timeopen_excluded(): void {
        $this->resetAfterTest();
        $gen    = $this->getDataGenerator();
        $course = $gen->create_course();
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        // Quiz with no scheduled window.
        $gen->create_module('quiz', [
            'course'   => $course->id,
            'timeopen' => 0,
        ]);

        $this->setUser($user);

        $result = $this->call_get_schedule(['session_type' => 'quiz']);

        $this->assertSame([], $result, 'Quiz without timeopen must be excluded');
    }

    /**
     * A user not enrolled in a course does not see its quiz sessions.
     */
    public function test_quiz_not_returned_for_unenrolled_user(): void {
        $this->resetAfterTest();
        $gen    = $this->getDataGenerator();
        $course = $gen->create_course();
        $user   = $gen->create_user();
        // Deliberately NOT enrolling the user.

        $now = time();
        $gen->create_module('quiz', [
            'course'    => $course->id,
            'timeopen'  => $now + HOURSECS,
            'timeclose' => $now + 2 * HOURSECS,
        ]);

        $this->setUser($user);

        $result = $this->call_get_schedule(['session_type' => 'quiz']);

        $this->assertSame([], $result, 'Unenrolled user must not see quiz sessions');
    }

    /**
     * course_id filter correctly narrows results to a single course.
     */
    public function test_course_id_filter_narrows_quiz_results(): void {
        $this->resetAfterTest();
        $gen     = $this->getDataGenerator();
        $course1 = $gen->create_course();
        $course2 = $gen->create_course();
        $user    = $gen->create_user();
        $gen->enrol_user($user->id, $course1->id);
        $gen->enrol_user($user->id, $course2->id);

        $now = time();
        $q1 = $gen->create_module('quiz', ['course' => $course1->id, 'timeopen' => $now + HOURSECS,
            'timeclose' => $now + 2 * HOURSECS]);
        $q2 = $gen->create_module('quiz', ['course' => $course2->id, 'timeopen' => $now + HOURSECS,
            'timeclose' => $now + 2 * HOURSECS]);

        $this->setUser($user);

        $result = $this->call_get_schedule(['session_type' => 'quiz', 'course_id' => $course1->id]);

        $ids = array_column($result, 'id');
        $this->assertContains('quiz:' . $q1->id, $ids);
        $this->assertNotContains('quiz:' . $q2->id, $ids,
            'course_id filter must exclude sessions from other courses');
    }

    // -----------------------------------------------------------------------
    // Result shape and ordering
    // -----------------------------------------------------------------------

    /**
     * Results are sorted by timestart ascending.
     */
    public function test_results_sorted_by_timestart_ascending(): void {
        $this->resetAfterTest();
        $gen    = $this->getDataGenerator();
        $course = $gen->create_course();
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $now = time();
        // Create quizzes out of order.
        $q_later  = $gen->create_module('quiz', ['course' => $course->id,
            'timeopen' => $now + 3 * HOURSECS, 'timeclose' => $now + 4 * HOURSECS]);
        $q_sooner = $gen->create_module('quiz', ['course' => $course->id,
            'timeopen' => $now + HOURSECS,     'timeclose' => $now + 2 * HOURSECS]);

        $this->setUser($user);

        $result = $this->call_get_schedule(['session_type' => 'quiz']);

        $this->assertCount(2, $result);
        $this->assertLessThanOrEqual($result[1]['timestart'], $result[0]['timestart'],
            'Sessions must be ordered by timestart ascending');
        $this->assertSame($now + HOURSECS, $result[0]['timestart']);
        $this->assertSame($now + 3 * HOURSECS, $result[1]['timestart']);
    }

    /**
     * Each result record contains all required fields.
     */
    public function test_result_record_has_all_required_fields(): void {
        $this->resetAfterTest();
        $gen    = $this->getDataGenerator();
        $course = $gen->create_course();
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $now = time();
        $gen->create_module('quiz', ['course' => $course->id,
            'timeopen' => $now + HOURSECS, 'timeclose' => $now + 2 * HOURSECS]);

        $this->setUser($user);
        $result = $this->call_get_schedule(['session_type' => 'quiz']);

        $this->assertCount(1, $result);
        $record = $result[0];
        foreach (['id', 'session_type', 'title', 'courseid', 'course_name',
                  'timestart', 'timefinish', 'location', 'instructor', 'description'] as $field) {
            $this->assertArrayHasKey($field, $record, "Missing field: $field");
        }
        $this->assertStringStartsWith('quiz:', $record['id']);
    }

    // -----------------------------------------------------------------------
    // ICS builder unit tests
    // -----------------------------------------------------------------------

    /**
     * ICS output with one quiz session contains a VEVENT with the right fields.
     */
    public function test_ics_export_contains_vevent_for_quiz(): void {
        $this->resetAfterTest();
        $gen    = $this->getDataGenerator();
        $course = $gen->create_course(['fullname' => 'Test Course']);
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $now = time();
        $gen->create_module('quiz', [
            'course'   => $course->id,
            'name'     => 'Mid-term exam',
            'timeopen' => $now + HOURSECS,
            'timeclose' => $now + 2 * HOURSECS,
        ]);

        $this->setUser($user);

        $result = $this->call_export_ics(['session_type' => 'quiz']);

        $ics = base64_decode($result['ics_base64']);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('SUMMARY:Mid-term exam', $ics);
        $this->assertStringContainsString('END:VEVENT', $ics);
    }

    /**
     * ICS export via year/month params (frontend calendar style) scopes the date range.
     *
     * Regression for VBS-279: the frontend sends {year, month} but the original backend
     * schema only accepted {date_from, date_to}, causing validate_parameters() to throw
     * and the JS catch block to show "Không thể xuất lịch".
     */
    public function test_ics_export_year_month_params_compute_date_range(): void {
        $this->resetAfterTest();
        $gen    = $this->getDataGenerator();
        $course = $gen->create_course();
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        // Create a quiz inside the target month (July 2026).
        $july_start = mktime(0, 0, 0, 7, 1, 2026);
        $gen->create_module('quiz', [
            'course'    => $course->id,
            'name'      => 'July quiz',
            'timeopen'  => $july_start + HOURSECS,
            'timeclose' => $july_start + 2 * HOURSECS,
        ]);

        // Create a quiz outside the target month (June 2026).
        $june_start = mktime(0, 0, 0, 6, 1, 2026);
        $gen->create_module('quiz', [
            'course'    => $course->id,
            'name'      => 'June quiz',
            'timeopen'  => $june_start + HOURSECS,
            'timeclose' => $june_start + 2 * HOURSECS,
        ]);

        $this->setUser($user);

        // Call with year/month params (the way the frontend calls it).
        $raw = export_schedule_ics::execute(0, 0, 0, '', 2026, 7);
        $result = external_api::clean_returnvalue(export_schedule_ics::execute_returns(), $raw);

        $this->assertNotEmpty($result['ics_base64']);
        $ics = base64_decode($result['ics_base64']);
        $this->assertStringContainsString('July quiz', $ics, 'July quiz must appear in July export');
        $this->assertStringNotContainsString('June quiz', $ics, 'June quiz must be excluded from July export');
    }

    /**
     * ICS export with year/month and no sessions returns a valid empty calendar.
     */
    public function test_ics_export_year_month_no_sessions_returns_empty_calendar(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $raw = export_schedule_ics::execute(0, 0, 0, '', 2026, 7);
        $result = external_api::clean_returnvalue(export_schedule_ics::execute_returns(), $raw);

        $ics = base64_decode($result['ics_base64']);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringNotContainsString('BEGIN:VEVENT', $ics);
    }

    // -----------------------------------------------------------------------
    // Optional field absent in request/response
    // -----------------------------------------------------------------------

    /**
     * Calling get_schedule with no optional params uses defaults and does not error.
     */
    public function test_get_schedule_with_no_optional_params(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // Call with only required defaults — should not throw.
        $raw = get_schedule::execute();
        $result = external_api::clean_returnvalue(get_schedule::execute_returns(), $raw);
        $this->assertIsArray($result);
    }

    /**
     * timefinish is 0 when quiz has no timeclose and no timelimit (optional field absent).
     */
    public function test_timefinish_zero_when_quiz_has_no_close_or_timelimit(): void {
        $this->resetAfterTest();
        $gen    = $this->getDataGenerator();
        $course = $gen->create_course();
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $now = time();
        $gen->create_module('quiz', [
            'course'    => $course->id,
            'timeopen'  => $now + HOURSECS,
            'timeclose' => 0,
            'timelimit' => 0,
        ]);

        $this->setUser($user);
        $result = $this->call_get_schedule(['session_type' => 'quiz']);

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['timefinish'],
            'timefinish must be 0 when no timeclose/timelimit provided');
    }

    // -----------------------------------------------------------------------
    // Blocker regressions (arch-review fixes)
    // -----------------------------------------------------------------------

    /**
     * Blocker 1: session_type=vbs_exam passes PARAM_ALPHANUMEXT and reaches the aggregator.
     *
     * We test that the WS layer does not strip the underscore, so the filter string
     * survives validate_parameters unchanged, and the returns schema also preserves it.
     */
    public function test_vbs_exam_session_type_filter_survives_param_cleaning(): void {
        global $DB;
        $this->resetAfterTest();

        // Skip if local_vbs_exam tables are not installed (unit environment may lack them).
        if (!$DB->get_manager()->table_exists('vbs_exam_session')) {
            $this->markTestSkipped('vbs_exam_session table not available in this environment.');
        }

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        // With no exam enrolments the result should be [] — but the call must not throw
        // (previously PARAM_ALPHA stripped '_' making the type 'vbsexam', which then
        // silently returned [] for the wrong reason).  After the fix the aggregator
        // correctly dispatches to get_vbs_exam_sessions() and returns [] because there
        // are no enrolments — the test asserts only that the call succeeds without error.
        $result = $this->call_get_schedule(['session_type' => 'vbs_exam']);
        $this->assertSame([], $result);

        // Verify the schema does not mangle session_type='vbs_exam' on the way back.
        // We inject a fake row through clean_returnvalue to avoid needing DB fixtures.
        $fake = [
            'id'           => 'vbs_exam:1',
            'session_type' => 'vbs_exam',
            'title'        => 'Test',
            'courseid'     => 0,
            'course_name'  => 'Topic',
            'timestart'    => time(),
            'timefinish'   => 0,
            'location'     => '',
            'instructor'   => '',
            'description'  => '',
        ];
        $cleaned = \core_external\external_api::clean_returnvalue(
            get_schedule::execute_returns(), [$fake]
        );
        $this->assertSame('vbs_exam', $cleaned[0]['session_type'],
            'PARAM_ALPHANUMEXT must preserve underscore in vbs_exam');
    }

    /**
     * Blocker 3: A facetoface signup with cancelled status must not appear in schedule.
     *
     * Creates a signup with statuscode=30 (USER_CANCELLED) and asserts it is excluded.
     * Also verifies an active signup (statuscode=80 BOOKED) IS included.
     */
    public function test_cancelled_facetoface_signup_excluded(): void {
        global $DB;
        $this->resetAfterTest();

        if (!$DB->get_manager()->table_exists('facetoface')) {
            $this->markTestSkipped('mod_facetoface not available in this environment.');
        }

        $gen    = $this->getDataGenerator();
        $course = $gen->create_course();
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $now = time();

        // Create two facetoface activities.
        $f2f_cancelled = $gen->create_module('facetoface', ['course' => $course->id]);
        $f2f_booked    = $gen->create_module('facetoface', ['course' => $course->id]);

        // Create sessions.
        $sess_cancelled = (int)$DB->insert_record('facetoface_sessions', [
            'facetoface' => $f2f_cancelled->id, 'capacity' => 10, 'allowoverbook' => 0,
            'datetimeknown' => 1, 'visible' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $sess_booked = (int)$DB->insert_record('facetoface_sessions', [
            'facetoface' => $f2f_booked->id, 'capacity' => 10, 'allowoverbook' => 0,
            'datetimeknown' => 1, 'visible' => 1, 'timecreated' => $now, 'timemodified' => $now,
        ]);

        // Session dates.
        $DB->insert_record('facetoface_sessions_dates', [
            'sessionid' => $sess_cancelled, 'timestart' => $now + HOURSECS,
            'timefinish' => $now + 2 * HOURSECS,
        ]);
        $DB->insert_record('facetoface_sessions_dates', [
            'sessionid' => $sess_booked, 'timestart' => $now + 2 * HOURSECS,
            'timefinish' => $now + 3 * HOURSECS,
        ]);

        // Signups.
        $signup_cancelled = (int)$DB->insert_record('facetoface_signups', [
            'sessionid' => $sess_cancelled, 'userid' => $user->id,
            'mailedreminder' => 0, 'notificationtype' => 3,
        ]);
        $signup_booked = (int)$DB->insert_record('facetoface_signups', [
            'sessionid' => $sess_booked, 'userid' => $user->id,
            'mailedreminder' => 0, 'notificationtype' => 3,
        ]);

        // Signup statuses: cancelled (30) and booked (80).
        $DB->insert_record('facetoface_signups_status', [
            'signupid' => $signup_cancelled, 'statuscode' => 30,
            'superceded' => 0, 'createdby' => $user->id, 'timecreated' => $now,
        ]);
        $DB->insert_record('facetoface_signups_status', [
            'signupid' => $signup_booked, 'statuscode' => 80,
            'superceded' => 0, 'createdby' => $user->id, 'timecreated' => $now,
        ]);

        $this->setUser($user);
        $result = $this->call_get_schedule([
            'session_type' => 'facetoface',
            'date_from'    => $now,
            'date_to'      => $now + DAYSECS,
        ]);

        $ids = array_column($result, 'id');
        // The BOOKED session must appear; the CANCELLED one must not.
        $has_booked    = count(array_filter($ids, fn($id) => str_contains($id, 'facetoface:'))) > 0;
        $has_cancelled = false;
        foreach ($result as $r) {
            // The cancelled session's date id maps to the cancelled facetoface date row.
            // We verify no session from f2f_cancelled activity appears.
            if ($r['session_type'] === 'facetoface'
                && $r['timestart'] === $now + HOURSECS) {
                $has_cancelled = true;
            }
        }
        $this->assertTrue($has_booked, 'BOOKED signup must appear in schedule');
        $this->assertFalse($has_cancelled, 'CANCELLED signup must be excluded from schedule');
    }

    /**
     * Blocker 4: ICS export with Vietnamese title produces valid UTF-8.
     *
     * A title longer than 75 bytes when encoded in UTF-8 would previously be split
     * at a byte boundary that could land inside a multi-byte sequence.
     */
    public function test_ics_export_vietnamese_title_is_valid_utf8(): void {
        global $DB;
        $this->resetAfterTest();

        $gen    = $this->getDataGenerator();
        $course = $gen->create_course();
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        // Title with Vietnamese characters that exceeds 75 bytes when UTF-8 encoded.
        // Each Vietnamese character is 3 bytes; 26 chars × 3 = 78 bytes > 75 threshold.
        $long_vi_title = 'Kỳ thi cuối kỳ môn học Toán ứng dụng và thực hành';
        $now = time();
        $gen->create_module('quiz', [
            'course'    => $course->id,
            'name'      => $long_vi_title,
            'timeopen'  => $now + HOURSECS,
            'timeclose' => $now + 2 * HOURSECS,
        ]);

        $this->setUser($user);
        $result = $this->call_export_ics(['session_type' => 'quiz']);

        $ics = base64_decode($result['ics_base64']);
        $this->assertTrue(
            mb_check_encoding($ics, 'UTF-8'),
            'ICS output must be valid UTF-8 even when title contains multi-byte Vietnamese characters'
        );
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
    }
}
