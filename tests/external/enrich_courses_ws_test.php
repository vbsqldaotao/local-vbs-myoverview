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

namespace local_vbs_myoverview\external;

use local_vbs_myoverview\tests\external\ws_testcase;

/**
 * WS-context tests for local_vbs_myoverview_enrich_courses.
 *
 * These drive the external function through the real web-service dispatch path
 * ({@see ws_testcase::call_ws()}) so WS parameter coercion and the customfield
 * read path are exercised — catching the class of autoload/customfield bug that
 * escaped to Behat during the F01 pilot (VBS-143). TC-WS-04 is the regression
 * guard for the null-intvalue delivery_mode bug (commit fa14c6a).
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_vbs_myoverview\external\enrich_courses
 * @covers     \local_vbs_myoverview\local\customfield_installer
 */
final class enrich_courses_ws_test extends ws_testcase {

    /** @var string The external function under test. */
    private const WS_FUNCTION = 'local_vbs_myoverview_enrich_courses';

    /**
     * Create a running-now course, enrol a fresh user in it and log that user in.
     *
     * @param array $courseopts overrides merged over the running-now defaults
     * @return array{0: \stdClass, 1: \stdClass} [$course, $user]
     */
    private function enrolled_course(array $courseopts = []): array {
        $gen = self::getDataGenerator();
        $now = time();
        $course = $gen->create_course($courseopts + [
            'startdate' => $now - DAYSECS,
            'enddate'   => $now + DAYSECS,
        ]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);
        $this->setUser($user);
        return [$course, $user];
    }

    /**
     * TC-WS-01: string course ids coerce to ints and yield the same result.
     *
     * @return void
     */
    public function test_ws_parameter_coercion_int(): void {
        $this->resetAfterTest();
        [$course] = $this->enrolled_course();

        $asint = $this->call_ws(self::WS_FUNCTION, ['courseids' => [(int)$course->id]]);
        $asstring = $this->call_ws(self::WS_FUNCTION, ['courseids' => [(string)$course->id]]);

        $this->assertEquals($asint, $asstring);
        $this->assertCount(1, $asstring);
        $this->assertSame((int)$course->id, $asstring[0]['courseid']);
    }

    /**
     * TC-WS-02: an enrolled course returns one record with the four contract fields.
     *
     * @return void
     */
    public function test_ws_enrolled_course_returns_result(): void {
        $this->resetAfterTest();
        [$course] = $this->enrolled_course();

        $result = $this->call_ws(self::WS_FUNCTION, ['courseids' => [$course->id]]);

        $this->assertCount(1, $result);
        $record = $result[0];
        $this->assertArrayHasKey('courseid', $record);
        $this->assertArrayHasKey('vbsbadges', $record);
        $this->assertArrayHasKey('vbsdaterange', $record);
        $this->assertArrayHasKey('vbsregisterurl', $record);
        $this->assertSame((int)$course->id, $record['courseid']);
    }

    /**
     * TC-WS-03: delivery_mode = online surfaces the delivery badge first.
     *
     * @return void
     */
    public function test_ws_delivery_mode_online_returns_badge(): void {
        $this->resetAfterTest();
        [$course] = $this->enrolled_course();
        $fieldid = $this->ensure_delivery_mode_field();
        $this->set_delivery_mode($course->id, $fieldid, 'online');

        $result = $this->call_ws(self::WS_FUNCTION, ['courseids' => [$course->id]]);

        $this->assertCount(1, $result);
        $badges = $result[0]['vbsbadges'];
        $this->assertNotEmpty($badges);
        // Card order is delivery → lifecycle → enrollment, so delivery is index 0.
        $this->assertSame(
            get_string('delivery_online', 'local_vbs_myoverview'),
            $badges[0]['label']
        );
    }

    /**
     * TC-WS-04 (regression guard): a delivery_mode row with NULL intvalue must
     * not throw; the delivery badge is simply omitted (F01, commit fa14c6a).
     *
     * @return void
     */
    public function test_ws_delivery_mode_null_intvalue_does_not_throw(): void {
        $this->resetAfterTest();
        [$course] = $this->enrolled_course();
        $fieldid = $this->ensure_delivery_mode_field();
        $ctx = \context_course::instance($course->id);
        $this->set_delivery_mode_null_intvalue($course->id, $fieldid, $ctx);

        // The WS read path must degrade gracefully rather than fatal on NULL intvalue.
        $result = $this->call_ws(self::WS_FUNCTION, ['courseids' => [$course->id]]);

        $this->assertCount(1, $result);
        $badges = $result[0]['vbsbadges'];
        // Delivery badge omitted → only lifecycle + enrollment remain.
        $this->assertCount(2, $badges);
        $labels = array_column($badges, 'label');
        $this->assertNotContains(get_string('delivery_online', 'local_vbs_myoverview'), $labels);
        $this->assertNotContains(get_string('delivery_offline', 'local_vbs_myoverview'), $labels);
        $this->assertNotContains(get_string('delivery_blended', 'local_vbs_myoverview'), $labels);
    }

    /**
     * TC-WS-05: an empty courseids list returns an empty result.
     *
     * @return void
     */
    public function test_ws_empty_courseids_returns_empty(): void {
        $this->resetAfterTest();
        $user = self::getDataGenerator()->create_user();
        $this->setUser($user);

        $result = $this->call_ws(self::WS_FUNCTION, ['courseids' => []]);

        $this->assertSame([], $result);
    }

    /**
     * TC-WS-06: invalid / inaccessible ids are silently skipped.
     *
     * @return void
     */
    public function test_ws_skips_invalid_courseids(): void {
        $this->resetAfterTest();
        [$course] = $this->enrolled_course();

        // Mix the valid enrolled id with zero, a negative id and a non-existent id.
        $result = $this->call_ws(self::WS_FUNCTION, [
            'courseids' => [$course->id, 0, -1, 99999],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame((int)$course->id, $result[0]['courseid']);
    }

    /**
     * TC-WS-07: validate_context passes for the enrolled user's own context.
     *
     * @return void
     */
    public function test_ws_validate_context_passes_for_enrolled_user(): void {
        $this->resetAfterTest();
        [$course, $user] = $this->enrolled_course();
        $this->setUser($user);

        // Completes without throwing a require_login / access exception.
        $result = $this->call_ws(self::WS_FUNCTION, ['courseids' => [$course->id]]);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }
}
