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
 * Compact summary payload for widget/mobile consumers (AC-F02-01, AC-F02-06, AC-F02-07).
 *
 * Architectural note (Blocker #3 resolution): this WS is architecturally distinct from
 * local_vbs_myoverview_get_learning_progress (full dashboard, grade-based %, item-level
 * plan detail, attendance, cert codes) in the following ways:
 *
 * - completion_pct derived from {course_modules_completion} via progress_computer
 *   (completion_info semantics) NOT from gradebook — per AC-F02-01.
 * - Training plan returned as aggregate counts only (no item listing, no duedate).
 * - No grade_items, no attendance_percent, no cert codes, no warnings array.
 * - Designed for compact widget / future mobile consumers. progress.php uses
 *   get_learning_progress (grade-based %, item-level plan detail).
 *
 * Query budget: 5 queries (≤6 per AC-F02-07):
 *   Q1 batch pct  — {course_modules} + {course_modules_completion}
 *   Q2 in-progress — {course} + {enrol} + {user_enrolments} + {course_completions}
 *   Q3 completed  — {course_completions} + {course} + {customcert} + {customcert_issues}
 *   Q4 plan       — {vbs_plan_year} + {vbs_plan_item} + {cohort_members} + enrol/completion
 *   Q5 certs      — {customcert_issues} + {customcert} + {course} + {course_modules}
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
        $year   = (int)$params['plan_year'] ?: (int)date('Y');

        // W2 fix: validate_context first, then user-exists check, then capability.
        $systemcontext = \context_system::instance();
        self::validate_context($systemcontext);

        // Ensure the target user exists before any capability/data access.
        \core_user::get_user($userid, 'id', MUST_EXIST);

        // AC-F02-06: capability check when viewing another user's data.
        if ($userid !== (int)$USER->id) {
            $usercontext = \context_user::instance($userid);
            require_capability('moodle/user:viewdetails', $usercontext);
        }

        // --- Query 1: in-progress courses (active enrolment, not yet completed) ---
        // W3 fix: SELECT c.enablecompletion to pass real value to progress_computer.
        $sql1 = "SELECT c.id AS courseid, c.fullname, c.enddate, c.enablecompletion
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

        // Blocker 2 fix: batch-compute all completion %s in ONE query before the loop.
        $courseids = array_map(fn($r) => (int)$r->courseid, $inprogressrows);
        $computer  = new progress_computer();
        $pctmap    = $computer->compute_progress_pct_batch($courseids, $userid);

        $inprogresscourses = [];
        foreach ($inprogressrows as $row) {
            $inprogresscourses[] = [
                'courseid'       => (int)$row->courseid,
                'fullname'       => $row->fullname,
                'courseurl'      => (string)(new \moodle_url('/course/view.php', ['id' => $row->courseid])),
                'completion_pct' => $pctmap[(int)$row->courseid] ?? 0,
                'enddate'        => (int)$row->enddate,
                'delivery_mode'  => '',
            ];
        }

        // --- Query 2: completed courses with optional primary cert ---
        // W1 fix: first column is cc.id (unique per user+course) so get_records_sql
        // never loses rows when a course has multiple customcert instances.
        // MIN(cu.id) picks the first cert in the course to avoid row duplication.
        $customcertmodule = $DB->get_manager()->table_exists('customcert') ? 1 : 0;
        $sql2 = "SELECT cc.id AS cc_id, c.id AS courseid, c.fullname, cc.timecompleted,
                        ci.id AS issueid, cu.name AS cert_name, cm.id AS cmid
                   FROM {course_completions} cc
                   JOIN {course} c ON c.id = cc.course
                   LEFT JOIN {customcert} cu ON cu.course = c.id
                        AND cu.id = (SELECT MIN(cu2.id) FROM {customcert} cu2 WHERE cu2.course = c.id)
                   LEFT JOIN {customcert_issues} ci ON ci.customcertid = cu.id AND ci.userid = cc.userid
                   LEFT JOIN {course_modules} cm ON cm.course = c.id
                        AND cm.module = (SELECT id FROM {modules} WHERE name = 'customcert' LIMIT 1)
                        AND cm.instance = cu.id
                  WHERE cc.userid = :userid AND cc.timecompleted > 0
                  ORDER BY cc.timecompleted DESC";

        if (!$DB->get_manager()->table_exists('customcert') || !$DB->get_manager()->table_exists('customcert_issues')) {
            // Simplified query when customcert plugin is absent.
            $sql2 = "SELECT cc.id AS cc_id, c.id AS courseid, c.fullname, cc.timecompleted,
                            NULL AS issueid, NULL AS cert_name, NULL AS cmid
                       FROM {course_completions} cc
                       JOIN {course} c ON c.id = cc.course
                      WHERE cc.userid = :userid AND cc.timecompleted > 0
                      ORDER BY cc.timecompleted DESC";
        }

        $completedrows = $DB->get_records_sql($sql2, ['userid' => $userid]);

        $completedcourses = [];
        foreach ($completedrows as $row) {
            $certurl = '';
            if (!empty($row->cmid)) {
                $certurl = (string)(new \moodle_url('/mod/customcert/view.php', [
                    'id'          => $row->cmid,
                    'downloadown' => 1,
                ]));
            }
            $completedcourses[] = [
                'courseid'      => (int)$row->courseid,
                'fullname'      => $row->fullname,
                'timecompleted' => (int)$row->timecompleted,
                'cert_url'      => $certurl,
                'cert_name'     => (string)($row->cert_name ?? ''),
            ];
        }

        // --- Query 3: Training plan aggregate (Blocker 1 fix) ---
        // vbs_plan_year is org-level (no userid column). vbs_plan_item keys on planid.
        // User's plan is derived via cohort membership (same pattern as get_learning_progress).
        $trainingplan = self::build_training_plan_summary($userid, $year, $DB);

        // --- Query 4: Issued certificates ---
        $certificates = self::build_certificates($userid, $DB);

        return [
            'in_progress_courses' => $inprogresscourses,
            'completed_courses'   => $completedcourses,
            'training_plan'       => $trainingplan,
            'certificates'        => $certificates,
        ];
    }

    /**
     * Derive the training plan aggregate for a user from the org-level plan.
     *
     * Blocker 1 fix: vbs_plan_year has NO userid column. vbs_plan_item uses planid
     * (not plan_year_id). Per-user filtering is done via cohort_members, matching
     * the pattern established in get_learning_progress::build_training_plan().
     *
     * Returns year=0 when local_vbs_plan is not installed or no active plan exists
     * for the requested year — the frontend must hide the panel in that case (spec).
     *
     * W4 fix: table_exists() guard prevents 500 on sites without local_vbs_plan.
     *
     * @param int $userid learner id
     * @param int $year plan year to look up
     * @param \moodle_database $DB
     * @return array training_plan aggregate
     */
    protected static function build_training_plan_summary(int $userid, int $year, \moodle_database $DB): array {
        $empty = ['year' => 0, 'total' => 0, 'completed' => 0, 'in_progress' => 0, 'not_started' => 0];

        // W4 fix: guard against missing local_vbs_plan.
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('vbs_plan_year') || !$dbman->table_exists('vbs_plan_item')) {
            return $empty;
        }

        // Single SQL joining plan → items (filtered by user cohorts) → completion + enrolment.
        // Correlated subquery on cohort_members avoids a separate PHP query.
        $sql = "SELECT vpi.courseid, cc.timecompleted, ue.id AS enrol_id
                  FROM {vbs_plan_year} vpy
                  JOIN {vbs_plan_item} vpi ON vpi.planid = vpy.id
                       AND (vpi.cohortid = 0 OR vpi.cohortid IN (
                           SELECT cm.cohortid FROM {cohort_members} cm WHERE cm.userid = :cohort_userid
                       ))
                  LEFT JOIN {course_completions} cc
                       ON cc.course = vpi.courseid AND cc.userid = :userid AND cc.timecompleted > 0
                  LEFT JOIN {enrol} e ON e.courseid = vpi.courseid AND e.status = 0
                  LEFT JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = :userid2 AND ue.status = 0
                 WHERE vpy.year = :year AND vpy.status IN ('active', 'approved')";

        $rows = $DB->get_records_sql($sql, [
            'cohort_userid' => $userid,
            'userid'        => $userid,
            'userid2'       => $userid,
            'year'          => $year,
        ]);

        if (empty($rows)) {
            return $empty;
        }

        $total      = count($rows);
        $completed  = 0;
        $inprogress = 0;
        foreach ($rows as $row) {
            if (!empty($row->timecompleted)) {
                $completed++;
            } else if (!empty($row->enrol_id)) {
                $inprogress++;
            }
        }

        return [
            'year'        => $year,
            'total'       => $total,
            'completed'   => $completed,
            'in_progress' => $inprogress,
            'not_started' => $total - $completed - $inprogress,
        ];
    }

    /**
     * Issued certificates for the user, newest first.
     *
     * @param int $userid learner id
     * @param \moodle_database $DB
     * @return array[]
     */
    protected static function build_certificates(int $userid, \moodle_database $DB): array {
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('customcert_issues') || !$dbman->table_exists('customcert')) {
            return [];
        }

        $sql = "SELECT ci.id AS issueid, cu.name AS cert_name,
                       c.fullname AS course_fullname, ci.timecreated, cm.id AS cmid
                  FROM {customcert_issues} ci
                  JOIN {customcert} cu ON cu.id = ci.customcertid
                  JOIN {course} c ON c.id = cu.course
                  JOIN {course_modules} cm ON cm.course = c.id
                       AND cm.module = (SELECT id FROM {modules} WHERE name = 'customcert' LIMIT 1)
                       AND cm.instance = cu.id
                 WHERE ci.userid = :userid
                 ORDER BY ci.timecreated DESC";

        $certrows = $DB->get_records_sql($sql, ['userid' => $userid]);

        $certificates = [];
        foreach ($certrows as $row) {
            $downloadurl = (string)(new \moodle_url('/mod/customcert/view.php', [
                'id'          => $row->cmid,
                'downloadown' => 1,
            ]));
            $certificates[] = [
                'issueid'        => (int)$row->issueid,
                'cert_name'      => $row->cert_name,
                'course_fullname' => $row->course_fullname,
                'timecreated'    => (int)$row->timecreated,
                'download_url'   => $downloadurl,
            ];
        }

        return $certificates;
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
                    'courseid'       => new external_value(PARAM_INT, 'Course id'),
                    'fullname'       => new external_value(PARAM_TEXT, 'Course full name'),
                    'courseurl'      => new external_value(PARAM_URL, 'Course URL'),
                    'completion_pct' => new external_value(PARAM_INT, 'Completion % from completion_info (0–100)'),
                    'enddate'        => new external_value(PARAM_INT, 'Course end date timestamp (0 if none)'),
                    'delivery_mode'  => new external_value(PARAM_TEXT, 'Delivery mode or empty', VALUE_DEFAULT, ''),
                ]),
                'Courses the user is actively enrolled in but has not yet completed'
            ),
            'completed_courses' => new external_multiple_structure(
                new external_single_structure([
                    'courseid'      => new external_value(PARAM_INT, 'Course id'),
                    'fullname'      => new external_value(PARAM_TEXT, 'Course full name'),
                    'timecompleted' => new external_value(PARAM_INT, 'Completion timestamp'),
                    'cert_url'      => new external_value(PARAM_TEXT, 'Primary certificate download URL (empty if none)', VALUE_DEFAULT, ''),
                    'cert_name'     => new external_value(PARAM_TEXT, 'Primary certificate name (empty if none)', VALUE_DEFAULT, ''),
                ]),
                'Courses the user has completed, newest first'
            ),
            'training_plan' => new external_single_structure([
                'year'        => new external_value(PARAM_INT, 'Plan year (0 = no plan — frontend must hide panel)'),
                'total'       => new external_value(PARAM_INT, 'Total courses in plan for this user\'s cohorts'),
                'completed'   => new external_value(PARAM_INT, 'Completed courses'),
                'in_progress' => new external_value(PARAM_INT, 'In-progress courses (enrolled, not complete)'),
                'not_started' => new external_value(PARAM_INT, 'Not-started courses (in plan, not enrolled)'),
            ], 'Training plan aggregate (year=0 → frontend hides panel)'),
            'certificates' => new external_multiple_structure(
                new external_single_structure([
                    'issueid'         => new external_value(PARAM_INT, 'Certificate issue id'),
                    'cert_name'       => new external_value(PARAM_TEXT, 'Certificate name'),
                    'course_fullname' => new external_value(PARAM_TEXT, 'Course full name'),
                    'timecreated'     => new external_value(PARAM_INT, 'Issue timestamp'),
                    'download_url'    => new external_value(PARAM_URL, 'Certificate download URL'),
                ]),
                'All certificates issued to the user, newest first'
            ),
        ]);
    }
}
