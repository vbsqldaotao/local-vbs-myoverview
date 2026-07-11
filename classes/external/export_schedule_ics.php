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
use core_external\external_single_structure;
use core_external\external_value;
use local_vbs_myoverview\local\ics_builder;
use local_vbs_myoverview\local\schedule_aggregator;

/**
 * External function local_vbs_myoverview_export_schedule_ics.
 *
 * Returns the learner's schedule as a base64-encoded iCalendar (ICS) string so
 * the frontend can offer a calendar download without a separate HTTP endpoint.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export_schedule_ics extends external_api {

    /**
     * Parameters for {@see execute()}.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'date_from'    => new external_value(PARAM_INT,  'Unix timestamp range start (0 = no lower bound)',
                VALUE_DEFAULT, 0),
            'date_to'      => new external_value(PARAM_INT,  'Unix timestamp range end (0 = no upper bound)',
                VALUE_DEFAULT, 0),
            'course_id'    => new external_value(PARAM_INT,  'Filter by course id (0 = all)',
                VALUE_DEFAULT, 0),
            'session_type' => new external_value(PARAM_ALPHANUMEXT, 'Filter: facetoface | quiz | vbs_exam | "" = all',
                VALUE_DEFAULT, ''),
            'year'         => new external_value(PARAM_INT,
                'Calendar year (e.g. 2026). When combined with month, sets the date range to that calendar month. '
                . 'Ignored when date_from / date_to are provided explicitly.',
                VALUE_DEFAULT, 0),
            'month'        => new external_value(PARAM_INT,
                'Calendar month 1–12. Used together with year to scope the export to one calendar month.',
                VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Generate and return a base64-encoded ICS calendar feed.
     *
     * @param int    $date_from
     * @param int    $date_to
     * @param int    $course_id
     * @param string $session_type
     * @param int    $year
     * @param int    $month
     * @return array{ics_base64: string, filename: string}
     */
    public static function execute(
        int    $date_from    = 0,
        int    $date_to      = 0,
        int    $course_id    = 0,
        string $session_type = '',
        int    $year         = 0,
        int    $month        = 0
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'date_from'    => $date_from,
            'date_to'      => $date_to,
            'course_id'    => $course_id,
            'session_type' => $session_type,
            'year'         => $year,
            'month'        => $month,
        ]);

        // When the frontend sends {year, month} for a calendar-month export, compute the
        // Unix timestamp range for that month. Explicit date_from/date_to take precedence.
        if ($params['year'] > 0 && $params['month'] >= 1 && $params['month'] <= 12
                && $params['date_from'] === 0 && $params['date_to'] === 0) {
            $params['date_from'] = mktime(0, 0, 0, $params['month'], 1, $params['year']);
            $params['date_to']   = mktime(23, 59, 59, $params['month'],
                (int)date('t', $params['date_from']), $params['year']);
        }

        $context = \context_user::instance($USER->id);
        self::validate_context($context);

        $aggregator = new schedule_aggregator();
        $sessions = $aggregator->get_sessions(
            (int)$USER->id,
            $params['date_from'],
            $params['date_to'],
            $params['course_id'],
            $params['session_type']
        );

        $builder = new ics_builder();
        $ics = $builder->build($sessions);

        return [
            'ics_base64' => base64_encode($ics),
            'filename'   => 'vbs-schedule.ics',
        ];
    }

    /**
     * Return schema for {@see execute()}.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ics_base64' => new external_value(PARAM_RAW,  'Base64-encoded ICS calendar content'),
            'filename'   => new external_value(PARAM_FILE, 'Suggested download filename'),
        ]);
    }
}
