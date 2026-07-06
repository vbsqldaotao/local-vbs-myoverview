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

defined('MOODLE_INTERNAL') || die();

// The base class lives in tests/externallib/ (namespace local_vbs_myoverview\external),
// a path Moodle's test class autoloader does not map, so require it explicitly.
require_once(__DIR__ . '/ws_testcase.php');

/**
 * WS-context tests for local_vbs_myoverview_enrich_courses.
 *
 * Every case dispatches through {@see external_api::call_external_function()} so the
 * external parameter/return schema and the customfield export path run exactly as
 * they do in production — the layer a plain execute() call skips.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_vbs_myoverview\external\enrich_courses
 * @covers     \local_vbs_myoverview\local\customfield_installer
 */
final class enrich_courses_ws_test extends ws_testcase {

    /** @var string The registered web-service function under test. */
    const WS_FUNCTION = 'local_vbs_myoverview_enrich_courses';

    /**
     * Create a course with a start/end window plus an actively enrolled user and
     * log that user in.
     *
     * @return array{0: \stdClass, 1: \stdClass} [$course, $user]
     */
    protected function create_enrolled_course_and_login(): array {
        $now = time();
        $gen = self::getDataGenerator();
        $course = $gen->create_course(['startdate' => $now - DAYSECS, 'enddate' => $now + DAYSECS]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);
        $this->setUser($user);
        return [$course, $user];
    }

    /**
     * TC-WS-01: courseids passed as strings must coerce to ints via the WS
     * parameter layer and yield an identical result to the int form.
     *
     * @return void
     */
    public function test_ws_parameter_coercion_int(): void {
        $this->resetAfterTest();
        [$course] = $this->create_enrolled_course_and_login();

        $asint = $this->call_ws(self::WS_FUNCTION, ['courseids' => [(int)$course->id]]);
        $asstring = $this->call_ws(self::WS_FUNCTION, ['courseids' => [(string)$course->id]]);

        $this->assertEquals($asint, $asstring);
        $this->assertCount(1, $asstring);
        $this->assertSame((int)$course->id, $asstring[0]['courseid']);
    }

    /**
     * TC-WS-02: an enrolled user asking for a valid course gets one record back
     * carrying the four contract slots.
     *
     * @return void
     */
    public function test_ws_enrolled_course_returns_result(): void {
        $this->resetAfterTest();
        [$course] = $this->create_enrolled_course_and_login();

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
     * TC-WS-03: a delivery_mode of 'online' set through the customfield API
     * surfaces as the leading (delivery) badge with the localised label.
     *
     * @return void
     */
    public function test_ws_delivery_mode_online_returns_badge(): void {
        $this->resetAfterTest();
        [$course] = $this->create_enrolled_course_and_login();

        $fieldid = $this->ensure_delivery_mode_field();
        $this->set_delivery_mode((int)$course->id, $fieldid, 'online');

        $result = $this->call_ws(self::WS_FUNCTION, ['courseids' => [$course->id]]);

        $this->assertCount(1, $result);
        $badges = $result[0]['vbsbadges'];
        $this->assertNotEmpty($badges);
        // Card badge order is delivery → lifecycle → enrollment, so delivery is first.
        $this->assertSame(
            get_string('delivery_online', 'local_vbs_myoverview'),
            $badges[0]['label']
        );
    }

    /**
     * TC-WS-04 (primary regression guard): a delivery_mode data row with a NULL
     * intvalue — the malformed state that broke the F01 pilot before fa14c6a —
     * must NOT throw. The card degrades gracefully: no delivery badge, just the
     * lifecycle + enrollment pair.
     *
     * @return void
     */
    public function test_ws_delivery_mode_null_intvalue_does_not_throw(): void {
        $this->resetAfterTest();
        [$course] = $this->create_enrolled_course_and_login();

        $fieldid = $this->ensure_delivery_mode_field();
        $coursecontext = \context_course::instance((int)$course->id);
        $this->set_delivery_mode_null_intvalue((int)$course->id, $fieldid, $coursecontext);

        $result = $this->call_ws(self::WS_FUNCTION, ['courseids' => [$course->id]]);

        $this->assertCount(1, $result);
        $badges = $result[0]['vbsbadges'];
        // Delivery chip omitted (null value degrades gracefully); lifecycle + enrollment remain.
        $this->assertCount(2, $badges);
        $labels = array_column($badges, 'label');
        $deliverylabels = [
            get_string('delivery_online', 'local_vbs_myoverview'),
            get_string('delivery_offline', 'local_vbs_myoverview'),
            get_string('delivery_blended', 'local_vbs_myoverview'),
        ];
        foreach ($deliverylabels as $deliverylabel) {
            $this->assertNotContains($deliverylabel, $labels);
        }
    }

    /**
     * TC-WS-05: an empty courseids list yields an empty result.
     *
     * @return void
     */
    public function test_ws_empty_courseids_returns_empty(): void {
        $this->resetAfterTest();
        $this->create_enrolled_course_and_login();

        $result = $this->call_ws(self::WS_FUNCTION, ['courseids' => []]);

        $this->assertSame([], $result);
    }

    /**
     * TC-WS-06: invalid ids (zero, negative, non-existent) are silently skipped;
     * only the valid enrolled course comes back.
     *
     * @return void
     */
    public function test_ws_skips_invalid_courseids(): void {
        $this->resetAfterTest();
        [$course] = $this->create_enrolled_course_and_login();

        $result = $this->call_ws(self::WS_FUNCTION, [
            'courseids' => [(int)$course->id, 0, -1, 99999],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame((int)$course->id, $result[0]['courseid']);
    }

    /**
     * TC-WS-07: validate_context on the caller's own user context passes for an
     * enrolled, logged-in user — the dispatch completes without throwing.
     *
     * @return void
     */
    public function test_ws_validate_context_passes_for_enrolled_user(): void {
        $this->resetAfterTest();
        [$course] = $this->create_enrolled_course_and_login();

        $result = $this->call_ws(self::WS_FUNCTION, ['courseids' => [$course->id]]);

        // Reaching here means validate_context() did not raise require_login/permission errors.
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }
}
