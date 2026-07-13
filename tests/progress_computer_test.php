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

use local_vbs_myoverview\local\progress_computer;

/**
 * Unit tests for {@see progress_computer::compute_progress_pct()}.
 *
 * Covers AC-F02-01: completion_pct must come from core_completion (activity
 * completion states), NOT from gradebook.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_vbs_myoverview\local\progress_computer
 */
final class progress_computer_test extends \advanced_testcase {

    /**
     * Course with enablecompletion=0 returns 0 immediately (AC-F02-01 TC-01-03).
     */
    public function test_compute_progress_pct_no_completion(): void {
        $this->resetAfterTest();
        $gen    = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 0]);
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        // Add a page so the result isn't 0 "by accident" due to missing modules.
        $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);

        $computer = new progress_computer();
        $this->assertSame(0, $computer->compute_progress_pct($course, (int)$user->id));
    }

    /**
     * 4 activities with completion tracking, 2 completed → 50% (AC-F02-01 TC-01-02).
     */
    public function test_compute_progress_pct_partial(): void {
        global $DB;
        $this->resetAfterTest();
        $gen    = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $pages = [];
        for ($i = 0; $i < 4; $i++) {
            $pages[] = $gen->create_module('page', [
                'course'     => $course->id,
                'completion' => COMPLETION_TRACKING_MANUAL,
            ]);
        }

        // Mark only the first two pages as complete.
        for ($i = 0; $i < 2; $i++) {
            $DB->insert_record('course_modules_completion', (object)[
                'coursemoduleid'  => $pages[$i]->cmid,
                'userid'          => $user->id,
                'completionstate' => COMPLETION_COMPLETE,
                'viewed'          => 0,
                'overrideby'      => null,
                'timemodified'    => time(),
            ]);
        }

        $computer = new progress_computer();
        $this->assertSame(50, $computer->compute_progress_pct($course, (int)$user->id));
    }

    /**
     * Course has enablecompletion=1 but zero completion-tracked activities → 0%.
     */
    public function test_compute_progress_pct_no_activities(): void {
        $this->resetAfterTest();
        $gen    = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        // A page with COMPLETION_TRACKING_NONE (0) is not a "tracked activity".
        $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_NONE]);

        $computer = new progress_computer();
        $this->assertSame(0, $computer->compute_progress_pct($course, (int)$user->id));
    }

    /**
     * All completion-tracked activities done → 100%.
     */
    public function test_compute_progress_pct_all_done(): void {
        global $DB;
        $this->resetAfterTest();
        $gen    = $this->getDataGenerator();
        $course = $gen->create_course(['enablecompletion' => 1]);
        $user   = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);

        $page1 = $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $page2 = $gen->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);

        foreach ([$page1, $page2] as $page) {
            $DB->insert_record('course_modules_completion', (object)[
                'coursemoduleid'  => $page->cmid,
                'userid'          => $user->id,
                'completionstate' => COMPLETION_COMPLETE,
                'viewed'          => 0,
                'overrideby'      => null,
                'timemodified'    => time(),
            ]);
        }

        $computer = new progress_computer();
        $this->assertSame(100, $computer->compute_progress_pct($course, (int)$user->id));
    }
}
