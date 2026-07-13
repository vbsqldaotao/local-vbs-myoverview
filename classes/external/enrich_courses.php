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
use local_vbs_myoverview\local\badge_mapper;
use local_vbs_myoverview\local\customfield_installer;
use local_vbs_myoverview\local\state_computer;

/**
 * External function local_vbs_myoverview_enrich_courses.
 *
 * Batched "enrichment overlay" for the Course overview block: given a list of
 * course ids the learner is enrolled in, returns the VBS presentation slots the
 * frozen coursecard contract expects (VBS-132): vbsbadges, vbsdaterange,
 * vbsregisterurl. Core `core_course_get_enrolled_courses_by_timeline_...` is
 * left untouched — this only adds the enrichment (arch-reviewer approved).
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrich_courses extends external_api {

    /** @var string strftime format for the class date range (spec §5.4: dd/mm/YYYY). */
    const DATE_FORMAT = '%d/%m/%Y';

    /**
     * Parameters for {@see execute()}.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course id'),
                'Course ids to enrich (learner-enrolled scope)',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Return enrichment records for the requested courses.
     *
     * Only courses the current user can access (enrolled or viewing) are
     * returned; unknown/inaccessible ids are silently skipped so the caller
     * degrades to stock core cards.
     *
     * @param int[] $courseids
     * @return array[] list of enrichment records keyed by courseid
     */
    public static function execute(array $courseids): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['courseids' => $courseids]);

        // The overlay runs in the caller's own user context.
        $context = \context_user::instance($USER->id);
        self::validate_context($context);

        $computer = new state_computer();
        $handler = \core_course\customfield\course_handler::create();
        $result = [];

        foreach (array_unique($params['courseids']) as $courseid) {
            $courseid = (int)$courseid;
            if ($courseid <= 0) {
                continue;
            }

            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                continue;
            }

            $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
            if (!$coursecontext) {
                continue;
            }

            // Restrict to the block_myoverview scope: courses the user is enrolled in / may view.
            if (!is_enrolled($coursecontext, $USER->id, '', true) && !is_viewing($coursecontext, $USER->id)) {
                continue;
            }

            $lifecycle = $computer->compute_lifecycle_state($course, (int)$USER->id);
            $enrollment = $computer->compute_enrollment_state($courseid, (int)$USER->id);
            $delivery = self::get_delivery_mode($handler, $courseid);

            $badges = badge_mapper::build_badges($delivery, $lifecycle, $enrollment);

            $result[] = [
                'courseid' => $courseid,
                'vbsbadges' => array_values($badges),
                'vbsdaterange' => self::format_date_range($course),
                // Pilot: the open_for_registration half is deferred → CTA is always empty.
                'vbsregisterurl' => '',
            ];
        }

        return $result;
    }

    /**
     * Read the delivery_mode custom field for a course, normalised to a canonical value.
     *
     * @param \core_course\customfield\course_handler $handler
     * @param int $courseid
     * @return string|null canonical delivery mode, or null when unset/unknown
     */
    protected static function get_delivery_mode(\core_course\customfield\course_handler $handler, int $courseid): ?string {
        $datas = $handler->get_instance_data($courseid, true);
        foreach ($datas as $data) {
            if ($data->get_field()->get('shortname') !== customfield_installer::FIELD_SHORTNAME) {
                continue;
            }
            $export = $data->export_value();
            if ($export === null || $export === '') {
                return null;
            }
            return strtolower(trim((string)$export));
        }
        return null;
    }

    /**
     * Format the "start – end" class date range required by BR-F01-05.
     *
     * @param \stdClass $course course record
     * @return string formatted range, or '' when no dates are set
     */
    protected static function format_date_range(\stdClass $course): string {
        $start = (int)($course->startdate ?? 0);
        $end = (int)($course->enddate ?? 0);
        if ($start <= 0 && $end <= 0) {
            return '';
        }
        $startstr = $start > 0 ? userdate($start, self::DATE_FORMAT) : '';
        $endstr = $end > 0 ? userdate($end, self::DATE_FORMAT) : '';
        if ($startstr !== '' && $endstr !== '') {
            return $startstr . ' – ' . $endstr;
        }
        return $startstr !== '' ? $startstr : $endstr;
    }

    /**
     * Return description for {@see execute()}.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'courseid' => new external_value(PARAM_INT, 'Course id'),
                'vbsbadges' => new external_multiple_structure(
                    new external_single_structure([
                        'label' => new external_value(PARAM_TEXT, 'Badge label'),
                        'classes' => new external_value(PARAM_TEXT, 'Bootstrap classes for the badge chip'),
                        'type' => new external_value(
                            PARAM_TEXT,
                            'Badge category: delivery | lifecycle | enrollment — emitted as data-badge-type for QA anchors (VBS-141)',
                            VALUE_DEFAULT,
                            ''
                        ),
                    ]),
                    'Ordered badges: delivery → lifecycle → enrollment'
                ),
                'vbsdaterange' => new external_value(PARAM_TEXT, 'Formatted class date range', VALUE_DEFAULT, ''),
                'vbsregisterurl' => new external_value(PARAM_URL, 'Register CTA URL (empty at pilot)', VALUE_DEFAULT, ''),
            ])
        );
    }
}
