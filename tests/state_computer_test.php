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
}
