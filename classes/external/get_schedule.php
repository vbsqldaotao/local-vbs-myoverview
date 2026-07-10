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
use local_vbs_myoverview\local\schedule_aggregator;

/**
 * External function local_vbs_myoverview_get_schedule.
 *
 * Returns a learner's upcoming scheduled sessions across all VBS sources
 * (face-to-face, quiz, VBS exam sittings) in a single unified response.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_schedule extends external_api {

    /**
     * Parameters for {@see execute()}.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'date_from'  => new external_value(PARAM_INT,  'Unix timestamp range start (0 = no lower bound)',
                VALUE_DEFAULT, 0),
            'date_to'    => new external_value(PARAM_INT,  'Unix timestamp range end (0 = no upper bound)',
                VALUE_DEFAULT, 0),
            'course_id'  => new external_value(PARAM_INT,  'Filter by course id (0 = all enrolled courses)',
                VALUE_DEFAULT, 0),
            'session_type' => new external_value(PARAM_ALPHA, 'Filter: facetoface | quiz | vbs_exam | "" = all',
                VALUE_DEFAULT, ''),
            'instructor' => new external_value(PARAM_TEXT,  'Substring filter on instructor name (empty = all)',
                VALUE_DEFAULT, ''),
            'location'   => new external_value(PARAM_TEXT,  'Substring filter on location (empty = all)',
                VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Return scheduled sessions for the current user.
     *
     * Each session exposes a composite id (<type>:<source_id>), so callers can
     * distinguish sessions from different sources even if their numeric ids collide.
     *
     * @param int    $date_from
     * @param int    $date_to
     * @param int    $course_id
     * @param string $session_type
     * @param string $instructor
     * @param string $location
     * @return array[]
     */
    public static function execute(
        int    $date_from    = 0,
        int    $date_to      = 0,
        int    $course_id    = 0,
        string $session_type = '',
        string $instructor   = '',
        string $location     = ''
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'date_from'    => $date_from,
            'date_to'      => $date_to,
            'course_id'    => $course_id,
            'session_type' => $session_type,
            'instructor'   => $instructor,
            'location'     => $location,
        ]);

        $context = \context_user::instance($USER->id);
        self::validate_context($context);

        $aggregator = new schedule_aggregator();
        return $aggregator->get_sessions(
            (int)$USER->id,
            $params['date_from'],
            $params['date_to'],
            $params['course_id'],
            $params['session_type'],
            $params['instructor'],
            $params['location']
        );
    }

    /**
     * Return schema for {@see execute()}.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id'           => new external_value(PARAM_TEXT, 'Composite id: <type>:<source_id>'),
                'session_type' => new external_value(PARAM_ALPHA, 'Session source type'),
                'title'        => new external_value(PARAM_TEXT, 'Session or activity title'),
                'courseid'     => new external_value(PARAM_INT,  'Course id (0 for standalone exam sessions)'),
                'course_name'  => new external_value(PARAM_TEXT, 'Course or exam topic name'),
                'timestart'    => new external_value(PARAM_INT,  'Session start (unix timestamp)'),
                'timefinish'   => new external_value(PARAM_INT,  'Session end (unix timestamp, 0 if unknown)'),
                'location'     => new external_value(PARAM_TEXT, 'Venue / room (empty when not applicable)'),
                'instructor'   => new external_value(PARAM_TEXT, 'Trainer name(s), comma-separated'),
                'description'  => new external_value(PARAM_RAW,  'Session description (HTML safe)'),
            ]),
            'Scheduled sessions sorted by start time ascending'
        );
    }
}
