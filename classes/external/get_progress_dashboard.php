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
use local_vbs_myoverview\local\progress_computer;

/**
 * External function local_vbs_myoverview_get_progress_dashboard.
 *
 * Returns learning progress dashboard data for a user: in-progress courses,
 * completed courses, training plan summary, and issued certificates.
 * Uses ≤6 batch DB queries (AC-F02-07).
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_progress_dashboard extends external_api {

    /**
     * Parameters for {@see execute()}.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User id (defaults to current user)', VALUE_DEFAULT, 0),
            'plan_year' => new external_value(PARAM_INT, 'Training plan year (defaults to current year)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Return progress dashboard data for the given user and plan year.
     *
     * @param int $userid 0 → current user
     * @param int $plan_year 0 → current year
     * @return array
     */
    public static function execute(int $userid = 0, int $plan_year = 0): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'plan_year' => $plan_year,
        ]);

        $userid = (int)$params['userid'] ?: (int)$USER->id;
        $year = (int)$params['plan_year'] ?: (int)date('Y');

        // AC-F02-06: capability check when viewing another user's data.
        if ($userid !== (int)$USER->id) {
            $usercontext = \context_user::instance($userid);
            require_capability('moodle/user:viewdetails', $usercontext);
        }

        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);

        // --- Query 1: In-progress courses (active enrolment, not yet completed) ---
        $sql1 = "SELECT c.id AS courseid, c.fullname, c.enddate
                   FROM {course} c
                   JOIN {enrol} e ON e.courseid = c.id AND e.status = 0
                   JOIN {user_enrolments} ue
                        ON ue.enrolid = e.id AND ue.userid = :userid AND ue.status = 0
                        AND (ue.timestart = 0 OR ue.timestart <= UNIX_TIMESTAMP())
                        AND (ue.timeend   = 0 OR ue.timeend   >= UNIX_TIMESTAMP())
                   LEFT JOIN {course_completions} cc
                        ON cc.course = c.id AND cc.userid = :userid2 AND cc.timecompleted > 0
                  WHERE cc.id IS NULL AND c.id <> 1";
        $inprogressrows = $DB->get_records_sql($sql1, ['userid' => $userid, 'userid2' => $userid]);

        // Compute completion % in PHP loop after the batch query (not in SQL) per spec.
        $computer = new progress_computer();
        $inprogresscourses = [];
        foreach ($inprogressrows as $row) {
            $courseobj = (object)['id' => $row->courseid, 'enablecompletion' => 1];
            $pct = $computer->compute_progress_pct($courseobj, $userid);

            $inprogresscourses[] = [
                'courseid' => (int)$row->courseid,
                'fullname' => $row->fullname,
                'courseurl' => (string)(new \moodle_url('/course/view.php', ['id' => $row->courseid])),
                'completion_pct' => $pct,
                'enddate' => (int)$row->enddate,
                'delivery_mode' => '',
            ];
        }

        // --- Query 2: Completed courses with optional cert info ---
        $sql2 = "SELECT c.id AS courseid, c.fullname, cc.timecompleted,
                        ci.id AS issueid, cu.name AS cert_name, cm.id AS cmid
                   FROM {course_completions} cc
                   JOIN {course} c ON c.id = cc.course
                   LEFT JOIN {customcert} cu ON cu.course = c.id
                   LEFT JOIN {customcert_issues} ci ON ci.customcertid = cu.id AND ci.userid = cc.userid
                   LEFT JOIN {course_modules} cm ON cm.course = c.id
                        AND cm.module = (SELECT id FROM {modules} WHERE name = 'customcert' LIMIT 1)
                        AND cm.instance = cu.id
                  WHERE cc.userid = :userid AND cc.timecompleted > 0
                  ORDER BY cc.timecompleted DESC";
        $completedrows = $DB->get_records_sql($sql2, ['userid' => $userid]);

        $completedcourses = [];
        foreach ($completedrows as $row) {
            $certurl = '';
            if (!empty($row->cmid)) {
                $certurl = (string)(new \moodle_url('/mod/customcert/view.php', [
                    'id' => $row->cmid,
                    'downloadown' => 1,
                ]));
            }
            $completedcourses[] = [
                'courseid' => (int)$row->courseid,
                'fullname' => $row->fullname,
                'timecompleted' => (int)$row->timecompleted,
                'cert_url' => $certurl,
                'cert_name' => (string)($row->cert_name ?? ''),
            ];
        }

        // --- Query 3: Training plan aggregate ---
        $sql3 = "SELECT vpi.courseid, cc.timecompleted, ue.id AS enrol_id
                   FROM {vbs_plan_year} vpy
                   JOIN {vbs_plan_item} vpi ON vpi.plan_year_id = vpy.id
                   LEFT JOIN {course_completions} cc
                        ON cc.course = vpi.courseid AND cc.userid = vpy.userid AND cc.timecompleted > 0
                   LEFT JOIN {enrol} e ON e.courseid = vpi.courseid AND e.status = 0
                   LEFT JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = vpy.userid AND ue.status = 0
                  WHERE vpy.userid = :userid AND vpy.year = :year";
        $planrows = $DB->get_records_sql($sql3, ['userid' => $userid, 'year' => $year]);

        if (empty($planrows)) {
            // No plan for this user+year → frontend hides the panel.
            $trainingplan = ['year' => 0, 'total' => 0, 'completed' => 0, 'in_progress' => 0, 'not_started' => 0];
        } else {
            $total = count($planrows);
            $completed = 0;
            $inprogress = 0;
            foreach ($planrows as $row) {
                if (!empty($row->timecompleted)) {
                    $completed++;
                } else if (!empty($row->enrol_id)) {
                    $inprogress++;
                }
            }
            $trainingplan = [
                'year' => $year,
                'total' => $total,
                'completed' => $completed,
                'in_progress' => $inprogress,
                'not_started' => $total - $completed - $inprogress,
            ];
        }

        // --- Query 4: Issued certificates ---
        $sql4 = "SELECT ci.id AS issueid, cu.name AS cert_name,
                        c.fullname AS course_fullname, ci.timecreated, cm.id AS cmid
                   FROM {customcert_issues} ci
                   JOIN {customcert} cu ON cu.id = ci.customcertid
                   JOIN {course} c ON c.id = cu.course
                   JOIN {course_modules} cm ON cm.course = c.id
                        AND cm.module = (SELECT id FROM {modules} WHERE name = 'customcert' LIMIT 1)
                        AND cm.instance = cu.id
                  WHERE ci.userid = :userid
                  ORDER BY ci.timecreated DESC";
        $certrows = $DB->get_records_sql($sql4, ['userid' => $userid]);

        $certificates = [];
        foreach ($certrows as $row) {
            $downloadurl = (string)(new \moodle_url('/mod/customcert/view.php', [
                'id' => $row->cmid,
                'downloadown' => 1,
            ]));
            $certificates[] = [
                'issueid' => (int)$row->issueid,
                'cert_name' => $row->cert_name,
                'course_fullname' => $row->course_fullname,
                'timecreated' => (int)$row->timecreated,
                'download_url' => $downloadurl,
            ];
        }

        return [
            'in_progress_courses' => $inprogresscourses,
            'completed_courses' => $completedcourses,
            'training_plan' => $trainingplan,
            'certificates' => $certificates,
        ];
    }

    /**
     * Return description for {@see execute()}.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'in_progress_courses' => new external_multiple_structure(
                new external_single_structure([
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
                    'courseurl' => new external_value(PARAM_URL, 'Course URL'),
                    'completion_pct' => new external_value(PARAM_INT, 'Completion percentage 0–100'),
                    'enddate' => new external_value(PARAM_INT, 'Course end date timestamp (0 if none)'),
                    'delivery_mode' => new external_value(PARAM_TEXT, 'Delivery mode (online/offline/blended or empty)'),
                ]),
                'Courses the user is enrolled in but has not yet completed'
            ),
            'completed_courses' => new external_multiple_structure(
                new external_single_structure([
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
                    'timecompleted' => new external_value(PARAM_INT, 'Completion timestamp'),
                    'cert_url' => new external_value(PARAM_TEXT, 'Certificate download URL (empty if none)', VALUE_DEFAULT, ''),
                    'cert_name' => new external_value(PARAM_TEXT, 'Certificate name (empty if none)', VALUE_DEFAULT, ''),
                ]),
                'Courses the user has completed'
            ),
            'training_plan' => new external_single_structure([
                'year' => new external_value(PARAM_INT, 'Plan year (0 = no plan for this user/year)'),
                'total' => new external_value(PARAM_INT, 'Total courses in plan'),
                'completed' => new external_value(PARAM_INT, 'Completed courses'),
                'in_progress' => new external_value(PARAM_INT, 'In-progress courses'),
                'not_started' => new external_value(PARAM_INT, 'Not-started courses'),
            ], 'Training plan summary (year=0 means no plan; frontend should hide panel)'),
            'certificates' => new external_multiple_structure(
                new external_single_structure([
                    'issueid' => new external_value(PARAM_INT, 'Certificate issue id'),
                    'cert_name' => new external_value(PARAM_TEXT, 'Certificate name'),
                    'course_fullname' => new external_value(PARAM_TEXT, 'Course full name'),
                    'timecreated' => new external_value(PARAM_INT, 'Issue timestamp'),
                    'download_url' => new external_value(PARAM_URL, 'Certificate download URL'),
                ]),
                'Certificates issued to the user'
            ),
        ]);
    }
}
