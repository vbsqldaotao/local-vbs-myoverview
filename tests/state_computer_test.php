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

use local_vbs_myoverview\local\state_computer;

/**
 * Unit tests for {@see state_computer}.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_vbs_myoverview\local\state_computer
 */
final class state_computer_test extends \advanced_testcase {

    /**
     * Lifecycle is derived from course dates when completion is not tracked.
     *
     * @return void
     */
    public function test_lifecycle_from_dates(): void {
        $this->resetAfterTest();
        $now = 1751000000; // Fixed reference timestamp.
        $gen = $this->getDataGenerator();
        $computer = new state_computer();

        $future = $gen->create_course(['startdate' => $now + DAYSECS]);
        $this->assertEquals(
            state_computer::LIFECYCLE_NOT_STARTED,
            $computer->compute_lifecycle_state($future, 0, $now)
        );

        $current = $gen->create_course(['startdate' => $now - DAYSECS, 'enddate' => $now + DAYSECS]);
        $this->assertEquals(
            state_computer::LIFECYCLE_IN_PROGRESS,
            $computer->compute_lifecycle_state($current, 0, $now)
        );

        $past = $gen->create_course(['startdate' => $now - (2 * DAYSECS), 'enddate' => $now - DAYSECS]);
        $this->assertEquals(
            state_computer::LIFECYCLE_ENDED,
            $computer->compute_lifecycle_state($past, 0, $now)
        );
    }

    /**
     * A course with a start date but no end date stays in progress once started.
     *
     * @return void
     */
    public function test_lifecycle_open_ended_course(): void {
        $this->resetAfterTest();
        $now = 1751000000;
        $course = $this->getDataGenerator()->create_course(['startdate' => $now - DAYSECS, 'enddate' => 0]);
        $computer = new state_computer();
        $this->assertEquals(
            state_computer::LIFECYCLE_IN_PROGRESS,
            $computer->compute_lifecycle_state($course, 0, $now)
        );
    }

    /**
     * An active enrolment yields the `assigned` state.
     *
     * @return void
     */
    public function test_enrollment_assigned(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id, null, 'manual', 0, 0, ENROL_USER_ACTIVE);

        $computer = new state_computer();
        $this->assertEquals(
            state_computer::ENROL_ASSIGNED,
            $computer->compute_enrollment_state($course->id, $user->id)
        );
    }

    /**
     * A suspended enrolment yields the `pending_approval` state.
     *
     * @return void
     */
    public function test_enrollment_pending_when_suspended(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id, null, 'manual', 0, 0, ENROL_USER_SUSPENDED);

        $computer = new state_computer();
        $this->assertEquals(
            state_computer::ENROL_PENDING,
            $computer->compute_enrollment_state($course->id, $user->id)
        );
    }

    /**
     * Enable (or create) an active `self` enrolment instance that allows new
     * enrolments on a course, so it qualifies as "open for registration".
     *
     * @param \stdClass $course course record
     * @param array $overrides field overrides on the enrol row (e.g. enrolstartdate)
     * @return void
     */
    private function make_self_enrollable(\stdClass $course, array $overrides = []): void {
        global $DB, $CFG;
        // Ensure the self-enrolment plugin is enabled site-wide (state_computer
        // short-circuits when it is not).
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
        $update = array_merge([
            'status' => ENROL_INSTANCE_ENABLED,
            'customint6' => 1, // Allow new enrolments.
        ], $overrides);
        foreach ($update as $field => $value) {
            $DB->set_field('enrol', $field, $value, ['id' => $instance->id]);
        }
    }

    /**
     * A visible course with an open self-enrolment the learner is not in is
     * reported as open for registration.
     *
     * @return void
     */
    public function test_open_registration_lists_eligible_course(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $this->make_self_enrollable($course);
        $user = $gen->create_user();
        $this->setUser($user); // can_self_enrol is evaluated against $USER.

        $computer = new state_computer();
        $this->assertEquals(
            [(int)$course->id],
            $computer->get_open_registration_courseids((int)$user->id)
        );
    }

    /**
     * A course the learner is already enrolled in is half (a), not "open".
     *
     * @return void
     */
    public function test_open_registration_excludes_enrolled_course(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $this->make_self_enrollable($course);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id, null, 'manual', 0, 0, ENROL_USER_ACTIVE);
        $this->setUser($user);

        $computer = new state_computer();
        $this->assertSame([], $computer->get_open_registration_courseids((int)$user->id));
    }

    /**
     * A course whose self-enrolment is disabled / not allowing new enrolments is
     * not reported as open.
     *
     * @return void
     */
    public function test_open_registration_excludes_closed_self_enrol(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();

        // Self instance disabled.
        $disabled = $gen->create_course();
        $this->make_self_enrollable($disabled, ['status' => ENROL_INSTANCE_DISABLED]);

        // Self instance enabled but new enrolments blocked (customint6 = 0).
        $noNewEnrols = $gen->create_course();
        $this->make_self_enrollable($noNewEnrols, ['customint6' => 0]);

        // No self instance at all.
        $manualonly = $gen->create_course();

        $user = $gen->create_user();
        $this->setUser($user);

        $computer = new state_computer();
        $this->assertSame([], $computer->get_open_registration_courseids((int)$user->id));
    }

    /**
     * A hidden course is never reported as open even with self-enrolment.
     *
     * @return void
     */
    public function test_open_registration_excludes_hidden_course(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['visible' => 0]);
        $this->make_self_enrollable($course);
        $user = $gen->create_user();
        $this->setUser($user);

        $computer = new state_computer();
        $this->assertSame([], $computer->get_open_registration_courseids((int)$user->id));
    }

    /**
     * A non-positive user id yields an empty result (defensive guard).
     *
     * @return void
     */
    public function test_open_registration_empty_for_invalid_user(): void {
        $this->resetAfterTest();
        $computer = new state_computer();
        $this->assertSame([], $computer->get_open_registration_courseids(0));
    }
}
