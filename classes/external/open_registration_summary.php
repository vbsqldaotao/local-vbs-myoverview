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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_vbs_myoverview\local\state_computer;

/**
 * External function local_vbs_myoverview_open_registration_summary.
 *
 * Answers the one question the "mở đăng ký" half (b) surfaces need (VBS-134):
 * does the current learner have any class open for self registration, and where
 * to register? It powers two client surfaces:
 *
 *   - Empty State A (spec §5.2 / wireframe §7): when block_myoverview has no
 *     enrolled cards but `count > 0`, the overlay shows a banner linking to the
 *     course catalog instead of the bare "Bạn chưa có lớp nào" message.
 *   - The catalog CTA (Function #2): /course/index.php lists every visible course,
 *     so `courses[]` tells the overlay which boxes are actually open for this
 *     learner and gives each a safe, server-built `enrolurl`.
 *
 * Read-only; computes from existing course/enrolment data. No parameters — the
 * scope is implicitly the current $USER.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class open_registration_summary extends external_api {

    /**
     * Parameters for {@see execute()} — none; scope is the current user.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Return the open-for-registration summary for the current user.
     *
     * @return array{count: int, catalogurl: string, courses: array<array{courseid: int, enrolurl: string}>}
     */
    public static function execute(): array {
        global $USER;

        self::validate_parameters(self::execute_parameters(), []);

        // Runs in the caller's own user context, like enrich_courses.
        $context = \context_user::instance($USER->id);
        self::validate_context($context);

        // The register CTA and Empty State A both point here (spec §5.2, Function #2).
        // Built with ->out(false) so the URL is validated, never string-concatenated
        // (arch-review W2 — avoids a `javascript:` scheme reaching the template).
        $catalogurl = (new \moodle_url('/course/index.php'))->out(false);

        if (!isloggedin() || isguestuser()) {
            return ['count' => 0, 'catalogurl' => $catalogurl, 'courses' => []];
        }

        $computer = new state_computer();
        $courseids = $computer->get_open_registration_courseids((int)$USER->id);

        $courses = [];
        foreach ($courseids as $courseid) {
            $courses[] = [
                'courseid' => (int)$courseid,
                'enrolurl' => (new \moodle_url('/enrol/index.php', ['id' => (int)$courseid]))->out(false),
            ];
        }

        return [
            'count' => count($courses),
            'catalogurl' => $catalogurl,
            'courses' => $courses,
        ];
    }

    /**
     * Return description for {@see execute()}.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'count' => new external_value(PARAM_INT, 'Number of classes open for self registration for this user'),
            'catalogurl' => new external_value(PARAM_URL, 'URL of the course catalog (/course/index.php)'),
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'courseid' => new external_value(PARAM_INT, 'Course id open for registration'),
                    'enrolurl' => new external_value(PARAM_URL, 'Register CTA URL (/enrol/index.php) for this course'),
                ]),
                'Courses open for self registration for this user'
            ),
        ]);
    }
}
