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
 * WS-context tests for local_vbs_myoverview_open_registration_summary.
 *
 * Dispatches through {@see external_api::call_external_function()} so the
 * single-structure return schema (count + catalogurl + courses[]) is validated
 * exactly as it is over AJAX — the layer a plain execute() call skips.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_vbs_myoverview\external\open_registration_summary
 */
final class open_registration_summary_ws_test extends ws_testcase {

    /** @var string The registered web-service function under test. */
    const WS_FUNCTION = 'local_vbs_myoverview_open_registration_summary';

    /**
     * Enable an open `self` enrolment instance (new enrolments allowed) on a course.
     *
     * @param \stdClass $course course record
     * @return void
     */
    protected function make_self_enrollable(\stdClass $course): void {
        global $DB, $CFG;
        // Ensure the self-enrolment plugin is enabled site-wide.
        $enabled = array_filter(explode(',', (string)($CFG->enrol_plugins_enabled ?? '')));
        if (!in_array('self', $enabled, true)) {
            $enabled[] = 'self';
            set_config('enrol_plugins_enabled', implode(',', $enabled));
        }
        $selfplugin = enrol_get_plugin('self');
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'self']);
        if (!$instance) {
            $instanceid = $selfplugin->add_instance($course, [
                'status' => ENROL_INSTANCE_ENABLED,
                'customint6' => 1,
            ]);
            $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
        }
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['id' => $instance->id]);
        $DB->set_field('enrol', 'customint6', 1, ['id' => $instance->id]);
    }

    /**
     * The summary is shaped {count:int, catalogurl:string, courses:[{courseid,enrolurl}]}
     * and reports an open course for a non-enrolled, eligible learner.
     *
     * @return void
     */
    public function test_ws_reports_open_course(): void {
        $this->resetAfterTest();
        $gen = self::getDataGenerator();
        $course = $gen->create_course();
        $this->make_self_enrollable($course);
        $user = $gen->create_user();
        $this->setUser($user);

        $result = $this->call_ws(self::WS_FUNCTION, []);

        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('catalogurl', $result);
        $this->assertArrayHasKey('courses', $result);
        $this->assertSame(1, $result['count']);
        $this->assertStringContainsString('/course/index.php', $result['catalogurl']);
        $this->assertCount(1, $result['courses']);
        $this->assertSame((int)$course->id, $result['courses'][0]['courseid']);
        $this->assertStringContainsString('/enrol/index.php', $result['courses'][0]['enrolurl']);
        $this->assertStringContainsString('id=' . (int)$course->id, $result['courses'][0]['enrolurl']);
    }

    /**
     * With no open class the count is zero, the courses list is empty, and the
     * catalog URL is still returned (so Empty State A can decide to stay silent).
     *
     * @return void
     */
    public function test_ws_reports_zero_when_no_open_course(): void {
        $this->resetAfterTest();
        $gen = self::getDataGenerator();
        // A course the user is enrolled in — half (a), not open.
        $course = $gen->create_course();
        $this->make_self_enrollable($course);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id, null, 'manual', 0, 0, ENROL_USER_ACTIVE);
        $this->setUser($user);

        $result = $this->call_ws(self::WS_FUNCTION, []);

        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['courses']);
        $this->assertStringContainsString('/course/index.php', $result['catalogurl']);
    }
}
