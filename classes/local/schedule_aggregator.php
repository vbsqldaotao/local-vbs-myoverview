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

namespace local_vbs_myoverview\local;

/**
 * Aggregates schedule data for a learner from multiple Moodle sources.
 *
 * Sources:
 *  - mod_facetoface: offline/face-to-face training sessions
 *  - mod_quiz: scheduled exam/assessment windows
 *  - local_vbs_exam: VBS backend exam sittings (vbs_exam_session)
 *
 * Each source returns the same unified session shape (see SESSION_TYPE_* constants).
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule_aggregator {

    /** Session type constants. */
    const TYPE_FACETOFACE = 'facetoface';
    const TYPE_QUIZ       = 'quiz';
    const TYPE_VBS_EXAM   = 'vbs_exam';

    /**
     * Return all scheduled sessions for a learner, sorted by timestart ascending.
     *
     * @param int    $userid    Moodle user id.
     * @param int    $datefrom  Unix timestamp range start (inclusive). 0 = no lower bound.
     * @param int    $dateto    Unix timestamp range end (inclusive). 0 = no upper bound.
     * @param int    $courseid  Filter to a single course (0 = all courses).
     * @param string $type      Filter to a single session type ('' = all types).
     * @param string $instructor Substring filter on instructor name ('' = no filter).
     * @param string $location  Substring filter on location ('' = no filter).
     * @return array[] Unified session records.
     */
    public function get_sessions(
        int $userid,
        int $datefrom = 0,
        int $dateto = 0,
        int $courseid = 0,
        string $type = '',
        string $instructor = '',
        string $location = ''
    ): array {
        $sessions = [];

        if ($type === '' || $type === self::TYPE_FACETOFACE) {
            $sessions = array_merge($sessions, $this->get_facetoface_sessions(
                $userid, $datefrom, $dateto, $courseid, $instructor, $location
            ));
        }

        if ($type === '' || $type === self::TYPE_QUIZ) {
            $sessions = array_merge($sessions, $this->get_quiz_sessions(
                $userid, $datefrom, $dateto, $courseid, $location
            ));
        }

        if ($type === '' || $type === self::TYPE_VBS_EXAM) {
            $sessions = array_merge($sessions, $this->get_vbs_exam_sessions(
                $userid, $datefrom, $dateto, $location
            ));
        }

        // Sort by start time ascending (stable on PHP 8+).
        usort($sessions, fn($a, $b) => $a['timestart'] <=> $b['timestart']);

        return $sessions;
    }

    /**
     * Face-to-face sessions the user has an active (non-cancelled/declined) signup for.
     *
     * Only signups with statuscode >= 60 (APPROVED / WAITLISTED / BOOKED / attended)
     * are included.  Cancelled (30) and Declined (40) signups are excluded so a user
     * who cancelled still does not see the session on their schedule or ICS feed.
     *
     * @param int    $userid
     * @param int    $datefrom
     * @param int    $dateto
     * @param int    $courseid
     * @param string $instructor
     * @param string $location
     * @return array[]
     */
    protected function get_facetoface_sessions(
        int $userid,
        int $datefrom,
        int $dateto,
        int $courseid,
        string $instructor,
        string $location
    ): array {
        global $DB;

        // facetoface tables may not exist if mod_facetoface is not installed.
        if (!$DB->get_manager()->table_exists('facetoface_signups')) {
            return [];
        }

        // facetoface_sessions has no location field in the core schema; location
        // is a custom field. We fall back to session details for description.
        // Join facetoface_signups_status to filter out cancelled/declined signups.
        $sql = "SELECT fsd.id AS dateid,
                       fs.id AS sessionid,
                       f.id  AS facetofaceid,
                       f.course AS courseid,
                       f.name AS activity_name,
                       fs.details,
                       fsd.timestart,
                       fsd.timefinish
                  FROM {facetoface_signups} sig
                  JOIN {facetoface_signups_status} fss
                       ON fss.signupid = sig.id AND fss.superceded = 0 AND fss.statuscode >= 60
                  JOIN {facetoface_sessions} fs ON fs.id = sig.sessionid
                  JOIN {facetoface} f            ON f.id = fs.facetoface
                  JOIN {facetoface_sessions_dates} fsd ON fsd.sessionid = fs.id
                 WHERE sig.userid = :userid
                   AND fs.visible = 1";

        $params = ['userid' => $userid];

        if ($datefrom > 0) {
            $sql .= ' AND fsd.timestart >= :datefrom';
            $params['datefrom'] = $datefrom;
        }
        if ($dateto > 0) {
            $sql .= ' AND fsd.timestart <= :dateto';
            $params['dateto'] = $dateto;
        }
        if ($courseid > 0) {
            $sql .= ' AND f.course = :courseid';
            $params['courseid'] = $courseid;
        }

        $rows = $DB->get_records_sql($sql, $params);

        if (!$rows) {
            return [];
        }

        // Batch-fetch course names — one query instead of N.
        $courseids = array_unique(array_column((array)$rows, 'courseid'));
        $courses = $DB->get_records_list('course', 'id', $courseids, '', 'id, fullname');

        // Fetch trainer names for these sessions in bulk.
        $sessionids = array_unique(array_column((array)$rows, 'sessionid'));
        $instructors_map = $this->get_facetoface_instructors($sessionids);

        $result = [];
        foreach ($rows as $row) {
            $instructor_str = $instructors_map[$row->sessionid] ?? '';

            // Apply instructor filter.
            if ($instructor !== '' && stripos($instructor_str, $instructor) === false) {
                continue;
            }

            // facetoface core schema has no location column; location filter skips if no data.
            if ($location !== '') {
                continue;
            }

            $course = $courses[$row->courseid] ?? null;
            $coursecontext = $course
                ? \context_course::instance((int)$row->courseid, IGNORE_MISSING)
                : null;

            $result[] = [
                'id'           => self::TYPE_FACETOFACE . ':' . $row->dateid,
                'session_type' => self::TYPE_FACETOFACE,
                'title'        => format_string($row->activity_name, true,
                    ['context' => $coursecontext ?? \context_system::instance()]),
                'courseid'     => (int)$row->courseid,
                'course_name'  => $course
                    ? format_string($course->fullname, true,
                        ['context' => $coursecontext ?? \context_system::instance()])
                    : '',
                'timestart'    => (int)$row->timestart,
                'timefinish'   => (int)$row->timefinish,
                'location'     => '',
                'instructor'   => $instructor_str,
                'description'  => format_text((string)($row->details ?? ''), FORMAT_HTML,
                    ['noclean' => false, 'filter' => false]),
            ];
        }

        return $result;
    }

    /**
     * Return a map [sessionid => comma-separated trainer full names] for the given session ids.
     *
     * @param int[] $sessionids
     * @return string[]
     */
    protected function get_facetoface_instructors(array $sessionids): array {
        global $DB;
        if (empty($sessionids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'sid');
        $sql = "SELECT fsr.sessionid, u.firstname, u.lastname
                  FROM {facetoface_session_roles} fsr
                  JOIN {user} u ON u.id = fsr.userid
                 WHERE fsr.sessionid $insql";
        $rows = $DB->get_records_sql($sql, $inparams);

        $map = [];
        foreach ($rows as $row) {
            $name = trim($row->firstname . ' ' . $row->lastname);
            if (isset($map[$row->sessionid])) {
                $map[$row->sessionid] .= ', ' . $name;
            } else {
                $map[$row->sessionid] = $name;
            }
        }
        return $map;
    }

    /**
     * Quiz modules with a scheduled open/close window the user is enrolled in.
     *
     * @param int    $userid
     * @param int    $datefrom
     * @param int    $dateto
     * @param int    $courseid
     * @param string $location  (quizzes have no location; skip if filter provided)
     * @return array[]
     */
    protected function get_quiz_sessions(
        int $userid,
        int $datefrom,
        int $dateto,
        int $courseid,
        string $location
    ): array {
        global $DB;

        // Skip quizzes when a location filter is active — they carry no location.
        if ($location !== '') {
            return [];
        }

        // Only return quizzes with a defined open time.
        $sql = "SELECT q.id,
                       q.course AS courseid,
                       q.name,
                       q.intro,
                       q.timeopen,
                       q.timeclose,
                       q.timelimit
                  FROM {quiz} q
                  JOIN {enrol} e   ON e.courseid = q.course AND e.status = 0
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = :userid AND ue.status = 0
                 WHERE q.timeopen > 0";

        $params = ['userid' => $userid];

        if ($datefrom > 0) {
            $sql .= ' AND q.timeopen >= :datefrom';
            $params['datefrom'] = $datefrom;
        }
        if ($dateto > 0) {
            $sql .= ' AND q.timeopen <= :dateto';
            $params['dateto'] = $dateto;
        }
        if ($courseid > 0) {
            $sql .= ' AND q.course = :courseid';
            $params['courseid'] = $courseid;
        }

        // Deduplicate: a learner may have multiple enrolments in the same course.
        $rows = $DB->get_records_sql($sql, $params);

        if (!$rows) {
            return [];
        }

        // Deduplicate quiz ids (learner may have multiple enrolments in the same course).
        $unique_rows = [];
        foreach ($rows as $row) {
            if (!isset($unique_rows[$row->id])) {
                $unique_rows[$row->id] = $row;
            }
        }

        // Batch-fetch course names — one query instead of N.
        $courseids = array_unique(array_column($unique_rows, 'courseid'));
        $courses = $DB->get_records_list('course', 'id', $courseids, '', 'id, fullname');

        $result = [];
        foreach ($unique_rows as $row) {
            $timeclose = (int)$row->timeclose;
            if ($timeclose <= 0 && (int)$row->timelimit > 0) {
                $timeclose = (int)$row->timeopen + (int)$row->timelimit;
            }

            $course = $courses[$row->courseid] ?? null;
            $coursecontext = $course
                ? \context_course::instance((int)$row->courseid, IGNORE_MISSING)
                : null;
            $ctx = $coursecontext ?? \context_system::instance();

            $result[] = [
                'id'           => self::TYPE_QUIZ . ':' . $row->id,
                'session_type' => self::TYPE_QUIZ,
                'title'        => format_string($row->name, true, ['context' => $ctx]),
                'courseid'     => (int)$row->courseid,
                'course_name'  => $course
                    ? format_string($course->fullname, true, ['context' => $ctx])
                    : '',
                'timestart'    => (int)$row->timeopen,
                'timefinish'   => $timeclose,
                'location'     => '',
                'instructor'   => '',
                'description'  => format_text((string)($row->intro ?? ''), FORMAT_HTML,
                    ['noclean' => false, 'filter' => false]),
            ];
        }

        return $result;
    }

    /**
     * VBS exam sittings the user is enrolled in.
     *
     * @param int    $userid
     * @param int    $datefrom
     * @param int    $dateto
     * @param string $location
     * @return array[]
     */
    protected function get_vbs_exam_sessions(
        int $userid,
        int $datefrom,
        int $dateto,
        string $location
    ): array {
        global $DB;

        // vbs_exam tables may not exist if local_vbs_exam is not installed.
        if (!$DB->get_manager()->table_exists('vbs_exam_session')) {
            return [];
        }

        $sql = "SELECT es.id, es.name, es.starttime, es.endtime, es.location,
                       et.name AS topic_name
                  FROM {vbs_exam_enrolment} ee
                  JOIN {vbs_exam_session} es ON es.id = ee.sessionid
                  JOIN {vbs_exam_topic} et   ON et.id = es.topicid
                 WHERE ee.userid = :userid
                   AND es.status IN ('planned','open')";

        $params = ['userid' => $userid];

        if ($datefrom > 0) {
            $sql .= ' AND es.starttime >= :datefrom';
            $params['datefrom'] = $datefrom;
        }
        if ($dateto > 0) {
            $sql .= ' AND es.starttime <= :dateto';
            $params['dateto'] = $dateto;
        }
        if ($location !== '') {
            $sql .= ' AND ' . $DB->sql_like('es.location', ':location', false);
            $params['location'] = '%' . $DB->sql_like_escape($location) . '%';
        }

        $rows = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            $sysctx = \context_system::instance();
            $result[] = [
                'id'           => self::TYPE_VBS_EXAM . ':' . $row->id,
                'session_type' => self::TYPE_VBS_EXAM,
                'title'        => format_string($row->name, true, ['context' => $sysctx]),
                'courseid'     => 0,
                'course_name'  => format_string($row->topic_name, true, ['context' => $sysctx]),
                'timestart'    => (int)$row->starttime,
                'timefinish'   => (int)$row->endtime,
                'location'     => format_string((string)($row->location ?? ''), true, ['context' => $sysctx]),
                'instructor'   => '',
                'description'  => '',
            ];
        }

        return $result;
    }
}
