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
use local_vbs_myoverview\local\customfield_installer;

/**
 * External function local_vbs_myoverview_get_learning_progress.
 *
 * Aggregates the "Trang tiến độ học tập cá nhân" (F02) payload for a single
 * learner: active (enrolled, not-yet-complete) courses, completed courses with
 * their certificate, the org training plan filtered to the learner's cohorts,
 * and the learner's issued certificates.
 *
 * Security (AC-08): a learner may only read their own progress. Reading another
 * user requires `moodle/user:viewdetails` on that user's context, otherwise a
 * required_capability_exception is thrown before any data is fetched.
 *
 * Schema note (reported to BA/arch-reviewer): the `local_vbs_plan` tables are
 * org-level, not per-user — `vbs_plan_year` has no `userid`, and
 * `vbs_plan_item` keys on `planid` (not `yearid`) and stores neither a per-item
 * status nor a duedate. The per-user `training_plan` is therefore *derived*: the
 * active year plan is filtered to the learner's cohorts, each item's
 * `plan_status` is computed from the learner's own enrolment/completion, and
 * `duedate` is always null (no column exists to source it from).
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_learning_progress extends external_api {

    /** @var string plan item: learner has completed the course. */
    const PLAN_COMPLETED = 'completed';
    /** @var string plan item: learner is actively enrolled but not complete. */
    const PLAN_IN_PROGRESS = 'in_progress';
    /** @var string plan item: learner is in the plan but not enrolled yet. */
    const PLAN_NOT_STARTED = 'not_started';

    /**
     * Parameters for {@see execute()}.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Learner user id to read progress for'),
        ]);
    }

    /**
     * Return the aggregated learning progress for a learner.
     *
     * @param int $userid learner id
     * @return array progress payload matching {@see execute_returns()}
     */
    public static function execute(int $userid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['userid' => $userid]);
        $userid = $params['userid'];

        // Resolve the target user (must exist even when they have no learning data — AC-09).
        $targetuser = \core_user::get_user($userid, '*', MUST_EXIST);

        // AC-08 security: run in the target user's context; only allow cross-user
        // reads to holders of moodle/user:viewdetails.
        $usercontext = \context_user::instance($userid);
        self::validate_context($usercontext);
        if ((int)$userid !== (int)$USER->id) {
            require_capability('moodle/user:viewdetails', $usercontext);
        }

        $warnings = [];

        // Build the plan first so any deleted-course warnings are collected before
        // the warnings key is assembled.
        $trainingplan = self::build_training_plan($userid, $warnings);

        $result = [
            'userid' => (int)$userid,
            'fullname' => fullname($targetuser),
            'generated_at' => time(),
            'active_courses' => self::build_active_courses($userid),
            'completed_courses' => self::build_completed_courses($userid),
            'certificates' => self::build_certificates($userid),
            'warnings' => $warnings,
        ];

        // training_plan is a VALUE_OPTIONAL single_structure: Moodle's
        // clean_returnvalue rejects a null value ("Only arrays/objects accepted"),
        // so the key must be OMITTED entirely when the learner has no plan — not
        // set to null.
        if ($trainingplan !== null) {
            $result['training_plan'] = $trainingplan;
        }

        return $result;
    }

    /**
     * Enrolled courses the learner has not yet completed.
     *
     * Uses {@see enrol_get_users_courses()} which already returns each course at
     * most once regardless of how many enrol instances the learner has (AC —
     * DISTINCT enrolment), and only for active enrolments.
     *
     * @param int $userid learner id
     * @return array[] list of active course rows
     */
    protected static function build_active_courses(int $userid): array {
        $courses = enrol_get_users_courses($userid, true, ['id', 'fullname', 'shortname', 'visible']);
        $handler = \core_course\customfield\course_handler::create();
        $rows = [];

        foreach ($courses as $course) {
            $courseid = (int)$course->id;
            if (self::is_course_complete($courseid, $userid)) {
                continue;
            }
            $rows[] = [
                'courseid' => $courseid,
                'coursename' => self::course_name($course),
                'shortname' => format_string($course->shortname, true, ['context' => \context_system::instance()]),
                'courseurl' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                'completion_percent' => self::completion_percent($courseid, $userid),
                'deadline' => self::course_deadline($courseid, $userid),
                'delivery_mode' => (string)(self::get_delivery_mode($handler, $courseid) ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Completed courses, newest completion first, with their certificate (if any).
     *
     * @param int $userid learner id
     * @return array[] list of completed course rows
     */
    protected static function build_completed_courses(int $userid): array {
        global $DB;

        $records = $DB->get_records_select(
            'course_completions',
            'userid = :userid AND timecompleted > 0',
            ['userid' => $userid],
            'timecompleted DESC'
        );

        $rows = [];
        foreach ($records as $cc) {
            $course = $DB->get_record('course', ['id' => $cc->course]);
            if (!$course) {
                // Completed course was deleted — nothing meaningful to show.
                continue;
            }
            [$certurl, $certcode] = self::course_certificate($course->id, $userid);
            $rows[] = [
                'courseid' => (int)$course->id,
                'coursename' => self::course_name($course),
                'shortname' => format_string($course->shortname, true, ['context' => \context_system::instance()]),
                'courseurl' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
                'timecompleted' => (int)$cc->timecompleted,
                'cert_url' => $certurl,
                'cert_code' => $certcode,
            ];
        }

        return $rows;
    }

    /**
     * The learner's derived training plan for the current year, or null.
     *
     * The plan is the active `vbs_plan_year` for the current year; its items are
     * filtered to the learner's cohorts (plus any item with cohortid = 0, i.e.
     * everyone). Per-item status is derived from the learner's own completion /
     * enrolment. Items pointing at a deleted course are skipped and reported in
     * $warnings (AC — deleted course must not crash).
     *
     * @param int $userid learner id
     * @param array $warnings passed by reference; deleted-course notices are appended
     * @return array|null the plan structure, or null when no plan applies
     */
    protected static function build_training_plan(int $userid, array &$warnings): ?array {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('vbs_plan_year') || !$dbman->table_exists('vbs_plan_item')) {
            // local_vbs_plan is not installed on this site.
            return null;
        }

        $year = (int)date('Y');
        // Prefer an 'active' plan, fall back to an 'approved' one for the year.
        $plan = $DB->get_record_select(
            'vbs_plan_year',
            "year = :year AND status IN ('active', 'approved')",
            ['year' => $year],
            '*',
            IGNORE_MULTIPLE
        );
        if (!$plan) {
            return null;
        }

        // Cohorts the learner belongs to; items target a cohort (or 0 = everyone).
        $cohortids = $DB->get_fieldset_select('cohort_members', 'cohortid', 'userid = :userid', ['userid' => $userid]);
        $cohortids[] = 0;
        [$insql, $inparams] = $DB->get_in_or_equal(array_unique($cohortids), SQL_PARAMS_NAMED, 'coh');
        $inparams['planid'] = $plan->id;

        $items = $DB->get_records_select(
            'vbs_plan_item',
            "planid = :planid AND cohortid $insql",
            $inparams
        );

        $rows = [];
        $counts = [self::PLAN_COMPLETED => 0, self::PLAN_IN_PROGRESS => 0, self::PLAN_NOT_STARTED => 0];
        foreach ($items as $item) {
            $course = $DB->get_record('course', ['id' => $item->courseid]);
            if (!$course) {
                $warnings[] = [
                    'item' => 'training_plan',
                    'itemid' => (int)$item->id,
                    'warningcode' => 'coursedeleted',
                    'message' => 'Plan item ' . (int)$item->id . ' references deleted course '
                        . (int)$item->courseid . '; skipped.',
                ];
                continue;
            }

            $status = self::plan_item_status((int)$course->id, $userid);
            $counts[$status]++;
            $rows[] = [
                'itemid' => (int)$item->id,
                'courseid' => (int)$course->id,
                'coursename' => self::course_name($course),
                // Field name is `status` to match the F02 frontend contract (VBS-160).
                'status' => $status,
                // No per-item duedate exists in vbs_plan_item — always null.
                'duedate' => null,
            ];
        }

        return [
            'year' => (int)$plan->year,
            'total_items' => count($rows),
            'completed_items' => $counts[self::PLAN_COMPLETED],
            'in_progress_items' => $counts[self::PLAN_IN_PROGRESS],
            'not_started_items' => $counts[self::PLAN_NOT_STARTED],
            'items' => $rows,
        ];
    }

    /**
     * The learner's issued certificates, newest first.
     *
     * @param int $userid learner id
     * @return array[] certificate rows (empty when customcert is absent)
     */
    protected static function build_certificates(int $userid): array {
        global $DB;

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('customcert_issues') || !$dbman->table_exists('customcert')) {
            return [];
        }

        $sql = "SELECT cert.id AS certid, cert.name AS certname, cert.course AS courseid,
                       ci.timecreated, ci.code
                  FROM {customcert_issues} ci
                  JOIN {customcert} cert ON cert.id = ci.customcertid
                 WHERE ci.userid = :userid
              ORDER BY ci.timecreated DESC, ci.id DESC";
        $records = $DB->get_records_sql($sql, ['userid' => $userid]);

        $rows = [];
        foreach ($records as $rec) {
            $course = $DB->get_record('course', ['id' => $rec->courseid]);
            if (!$course) {
                continue;
            }
            $cm = self::customcert_cm((int)$rec->courseid, (int)$rec->certid);
            $downloadurl = '';
            if ($cm) {
                $downloadurl = (new \moodle_url('/mod/customcert/view.php',
                    ['id' => $cm, 'downloadown' => 1]))->out(false);
            }
            $rows[] = [
                'certid' => (int)$rec->certid,
                'certname' => format_string($rec->certname, true, ['context' => \context_course::instance($course->id)]),
                'courseid' => (int)$course->id,
                'coursename' => self::course_name($course),
                'timecreated' => (int)$rec->timecreated,
                'code' => (string)($rec->code ?? ''),
                'download_url' => $downloadurl,
            ];
        }

        return $rows;
    }

    /**
     * Derive a plan item's status from the learner's completion/enrolment.
     *
     * @param int $courseid course id
     * @param int $userid learner id
     * @return string one of the PLAN_* constants
     */
    protected static function plan_item_status(int $courseid, int $userid): string {
        if (self::is_course_complete($courseid, $userid)) {
            return self::PLAN_COMPLETED;
        }
        $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
        if ($coursecontext && is_enrolled($coursecontext, $userid, '', true)) {
            return self::PLAN_IN_PROGRESS;
        }
        return self::PLAN_NOT_STARTED;
    }

    /**
     * Whether the learner has a completion record for the course.
     *
     * @param int $courseid course id
     * @param int $userid learner id
     * @return bool
     */
    protected static function is_course_complete(int $courseid, int $userid): bool {
        global $DB;
        $timecompleted = $DB->get_field('course_completions', 'timecompleted',
            ['course' => $courseid, 'userid' => $userid]);
        return $timecompleted !== false && (int)$timecompleted > 0;
    }

    /**
     * Grade-based completion percentage for a course, guarded against grademax = 0.
     *
     * @param int $courseid course id
     * @param int $userid learner id
     * @return int 0-100 (0 when there is no grade or grademax is 0)
     */
    protected static function completion_percent(int $courseid, int $userid): int {
        global $DB;
        $sql = "SELECT gg.finalgrade, gi.grademax
                  FROM {grade_items} gi
             LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :userid
                 WHERE gi.courseid = :courseid AND gi.itemtype = 'course'";
        $rec = $DB->get_record_sql($sql, ['courseid' => $courseid, 'userid' => $userid]);
        if (!$rec || $rec->finalgrade === null || (float)$rec->grademax <= 0) {
            return 0;
        }
        $percent = (int)round((float)$rec->finalgrade / (float)$rec->grademax * 100);
        return max(0, min(100, $percent));
    }

    /**
     * The learner's enrolment deadline for a course (latest active timeend), or null.
     *
     * @param int $courseid course id
     * @param int $userid learner id
     * @return int|null unix timestamp, or null when no bounded deadline
     */
    protected static function course_deadline(int $courseid, int $userid): ?int {
        global $DB;
        $sql = "SELECT MAX(ue.timeend) AS deadline
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid
                   AND ue.userid = :userid
                   AND ue.status = :uestatus
                   AND e.status = :estatus";
        $rec = $DB->get_record_sql($sql, [
            'courseid' => $courseid,
            'userid' => $userid,
            'uestatus' => ENROL_USER_ACTIVE,
            'estatus' => ENROL_INSTANCE_ENABLED,
        ]);
        $deadline = $rec ? (int)$rec->deadline : 0;
        return $deadline > 0 ? $deadline : null;
    }

    /**
     * Resolve the customcert certificate (url, code) issued to the learner for a course.
     *
     * @param int $courseid course id
     * @param int $userid learner id
     * @return array{0: ?string, 1: ?string} [cert_url, cert_code] — both null when none
     */
    protected static function course_certificate(int $courseid, int $userid): array {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('customcert') || !$dbman->table_exists('customcert_issues')) {
            return [null, null];
        }
        $certs = $DB->get_records('customcert', ['course' => $courseid], 'id ASC');
        foreach ($certs as $cert) {
            $issue = $DB->get_record('customcert_issues', ['customcertid' => $cert->id, 'userid' => $userid]);
            if (!$issue) {
                continue;
            }
            $cm = self::customcert_cm($courseid, (int)$cert->id);
            $url = $cm ? (new \moodle_url('/mod/customcert/view.php', ['id' => $cm]))->out(false) : null;
            return [$url, $issue->code !== null ? (string)$issue->code : null];
        }
        return [null, null];
    }

    /**
     * Course module id for a customcert instance in a course, or null.
     *
     * @param int $courseid course id
     * @param int $instanceid customcert instance id
     * @return int|null course module id
     */
    protected static function customcert_cm(int $courseid, int $instanceid): ?int {
        $cm = get_coursemodule_from_instance('customcert', $instanceid, $courseid, false, IGNORE_MISSING);
        return $cm ? (int)$cm->id : null;
    }

    /**
     * Read the delivery_mode custom field for a course, normalised (reuses the F01 convention).
     *
     * @param \core_course\customfield\course_handler $handler
     * @param int $courseid course id
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
     * Format a course full name for output in the system context.
     *
     * @param \stdClass $course course record (needs id, fullname)
     * @return string
     */
    protected static function course_name(\stdClass $course): string {
        return format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]);
    }

    /**
     * Return description for {@see execute()}.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'Learner user id'),
            'fullname' => new external_value(PARAM_TEXT, 'Learner full name'),
            'generated_at' => new external_value(PARAM_INT, 'Unix timestamp the payload was generated'),
            'active_courses' => new external_multiple_structure(
                new external_single_structure([
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'coursename' => new external_value(PARAM_TEXT, 'Course full name'),
                    'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
                    'courseurl' => new external_value(PARAM_URL, 'Course view URL'),
                    'completion_percent' => new external_value(PARAM_INT, 'Grade-based progress 0-100'),
                    'deadline' => new external_value(PARAM_INT, 'Enrolment deadline (unix ts) or null',
                        VALUE_OPTIONAL, null, NULL_ALLOWED),
                    'delivery_mode' => new external_value(PARAM_TEXT, 'Delivery mode custom field', VALUE_DEFAULT, ''),
                ]),
                'Enrolled courses not yet completed'
            ),
            'completed_courses' => new external_multiple_structure(
                new external_single_structure([
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'coursename' => new external_value(PARAM_TEXT, 'Course full name'),
                    'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
                    'courseurl' => new external_value(PARAM_URL, 'Course view URL'),
                    'timecompleted' => new external_value(PARAM_INT, 'Completion unix timestamp'),
                    'cert_url' => new external_value(PARAM_URL, 'Certificate view URL or null',
                        VALUE_OPTIONAL, null, NULL_ALLOWED),
                    'cert_code' => new external_value(PARAM_RAW, 'Certificate verification code or null',
                        VALUE_OPTIONAL, null, NULL_ALLOWED),
                ]),
                'Completed courses newest-first'
            ),
            'training_plan' => new external_single_structure([
                'year' => new external_value(PARAM_INT, 'Plan year'),
                'total_items' => new external_value(PARAM_INT, 'Total plan items for the learner'),
                'completed_items' => new external_value(PARAM_INT, 'Items the learner has completed'),
                'in_progress_items' => new external_value(PARAM_INT, 'Items in progress'),
                'not_started_items' => new external_value(PARAM_INT, 'Items not started'),
                'items' => new external_multiple_structure(
                    new external_single_structure([
                        'itemid' => new external_value(PARAM_INT, 'Plan item id'),
                        'courseid' => new external_value(PARAM_INT, 'Course id'),
                        'coursename' => new external_value(PARAM_TEXT, 'Course full name'),
                        'status' => new external_value(PARAM_ALPHAEXT, 'completed|in_progress|not_started'),
                        'duedate' => new external_value(PARAM_INT, 'Item due date (always null — no source column)',
                            VALUE_OPTIONAL, null, NULL_ALLOWED),
                    ])
                ),
            ], 'Derived training plan for the current year, or null', VALUE_OPTIONAL),
            'certificates' => new external_multiple_structure(
                new external_single_structure([
                    'certid' => new external_value(PARAM_INT, 'Certificate id (customcert instance id, used to build download URL)'),
                    'certname' => new external_value(PARAM_TEXT, 'Certificate name'),
                    'courseid' => new external_value(PARAM_INT, 'Course id'),
                    'coursename' => new external_value(PARAM_TEXT, 'Course full name'),
                    'timecreated' => new external_value(PARAM_INT, 'Issue unix timestamp'),
                    'code' => new external_value(PARAM_RAW, 'Certificate verification code'),
                    'download_url' => new external_value(PARAM_URL, 'Certificate download URL', VALUE_DEFAULT, ''),
                ]),
                'Issued certificates newest-first'
            ),
            'warnings' => new external_multiple_structure(
                new external_single_structure([
                    'item' => new external_value(PARAM_TEXT, 'Payload section the warning relates to'),
                    'itemid' => new external_value(PARAM_INT, 'Related record id'),
                    'warningcode' => new external_value(PARAM_ALPHANUMEXT, 'Machine warning code'),
                    'message' => new external_value(PARAM_TEXT, 'Human-readable warning'),
                ]),
                'Non-fatal warnings encountered while building the payload'
            ),
        ]);
    }
}
