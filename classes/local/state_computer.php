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
 * Computes the two independent state dimensions used by the VBS course cards.
 *
 * Lifecycle (theo ngày + Completion API) and Enrollment (theo enrol status) are
 * computed independently — BR-F01-02, spec §5.1. Progress % is NEVER used to
 * derive lifecycle. See VBS-128 / wireframe README §5.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class state_computer {

    /** @var string Lifecycle: course start date is in the future. */
    const LIFECYCLE_NOT_STARTED = 'not_started';
    /** @var string Lifecycle: now is between start and end date. */
    const LIFECYCLE_IN_PROGRESS = 'in_progress';
    /** @var string Lifecycle: end date has passed and the learner is not yet complete. */
    const LIFECYCLE_ENDED = 'ended';
    /** @var string Lifecycle: the learner has completed the course (Completion API). */
    const LIFECYCLE_COMPLETED = 'completed';

    /** @var string Enrollment: learner has an active enrolment (assigned by admin/teacher). */
    const ENROL_ASSIGNED = 'assigned';
    /** @var string Enrollment: learner is enrolled but the enrolment is not yet active. */
    const ENROL_PENDING = 'pending_approval';
    /** @var string Enrollment: class is open for self registration (deferred at pilot). */
    const ENROL_OPEN = 'open_for_registration';

    /**
     * Compute the lifecycle state of a course for a learner.
     *
     * Completion wins over dates: a completed course is `completed` regardless of
     * the end date (Function #47, spec §5.1). Otherwise the state is derived from
     * the course start/end dates relative to $now.
     *
     * @param \stdClass $course course record (needs id, startdate, enddate, enablecompletion)
     * @param int $userid learner id
     * @param int|null $now timestamp to evaluate against (defaults to time(), injectable for tests)
     * @return string one of the LIFECYCLE_* constants
     */
    public function compute_lifecycle_state(\stdClass $course, int $userid, ?int $now = null): string {
        $now = $now ?? time();

        if ($userid > 0 && $this->is_course_complete($course, $userid)) {
            return self::LIFECYCLE_COMPLETED;
        }

        $start = (int)($course->startdate ?? 0);
        $end = (int)($course->enddate ?? 0);

        if ($start > 0 && $now < $start) {
            return self::LIFECYCLE_NOT_STARTED;
        }
        if ($end > 0 && $now > $end) {
            return self::LIFECYCLE_ENDED;
        }
        return self::LIFECYCLE_IN_PROGRESS;
    }

    /**
     * Whether the learner is considered complete for the course.
     *
     * Short-circuits to false when completion tracking is disabled so that
     * date-based lifecycle logic applies.
     *
     * @param \stdClass $course course record
     * @param int $userid learner id
     * @return bool
     */
    protected function is_course_complete(\stdClass $course, int $userid): bool {
        $info = new \completion_info($course);
        if (!$info->is_enabled()) {
            return false;
        }
        return $info->is_course_complete($userid);
    }

    /**
     * Compute the enrollment state of a course for a learner.
     *
     * Pilot only surfaces half (a) — courses the learner is already enrolled in
     * (block_myoverview scope) — so the result is `assigned` (active enrolment)
     * or `pending_approval` (enrolled but suspended / not yet active). The
     * `open_for_registration` half is deferred (spec §2, half (b)).
     *
     * @param int $courseid course id
     * @param int $userid learner id
     * @param int|null $now timestamp to evaluate against (defaults to time())
     * @return string one of the ENROL_* constants
     */
    public function compute_enrollment_state(int $courseid, int $userid, ?int $now = null): string {
        global $DB;
        $now = $now ?? time();

        $sql = "SELECT ue.id, ue.status, ue.timestart, ue.timeend
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.courseid = :courseid
                   AND ue.userid = :userid
                   AND e.status = :enrolstatus";
        $records = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'userid' => $userid,
            'enrolstatus' => ENROL_INSTANCE_ENABLED,
        ]);

        $hasactive = false;
        $hasany = false;
        foreach ($records as $ue) {
            $hasany = true;
            $withinwindow = ((int)$ue->timestart <= $now)
                && ((int)$ue->timeend === 0 || $now <= (int)$ue->timeend);
            if ((int)$ue->status === ENROL_USER_ACTIVE && $withinwindow) {
                $hasactive = true;
                break;
            }
        }

        if ($hasactive) {
            return self::ENROL_ASSIGNED;
        }
        if ($hasany) {
            // Enrolled but suspended or outside the enrolment window → pending (pilot half (a)).
            return self::ENROL_PENDING;
        }
        // block_myoverview only lists enrolled courses, so this is a defensive default.
        return self::ENROL_ASSIGNED;
    }
}
