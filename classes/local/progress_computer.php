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
 * Computes course completion percentage from core_completion API.
 *
 * Progress % is derived solely from activity completion states — NOT from
 * gradebook — per AC-F02-01.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progress_computer {

    /**
     * Compute the completion percentage for a single course via the completion_info API.
     *
     * Returns 0 when completion tracking is disabled or the course has no
     * trackable activities. Uses activity completion states only (not grades).
     *
     * NOTE: this method fires one query per tracked activity when $userid differs from
     * $USER->id (completion cache is bypassed). For batch use across multiple courses,
     * prefer {@see compute_progress_pct_batch()} — one SQL query for all courses.
     *
     * @param \stdClass $course course record (needs id, enablecompletion)
     * @param int $userid learner id
     * @return int integer percentage 0–100
     */
    public function compute_progress_pct(\stdClass $course, int $userid): int {
        $info = new \completion_info($course);
        if (!$info->is_enabled()) {
            return 0;
        }
        $activities = $info->get_activities();
        if (empty($activities)) {
            return 0;
        }
        $done = 0;
        foreach ($activities as $cm) {
            $data = $info->get_data($cm, false, $userid);
            if ($data->completionstate > COMPLETION_INCOMPLETE) {
                $done++;
            }
        }
        return (int)(($done / count($activities)) * 100);
    }

    /**
     * Batch-compute activity-completion percentages for multiple courses in one SQL query.
     *
     * Reads directly from {course_modules_completion} — same data source as
     * completion_info::get_data() — so AC-F02-01 is satisfied without using gradebook.
     * Works correctly for cross-user views (no $USER->id dependency, no request cache).
     *
     * @param int[] $courseids list of course ids
     * @param int $userid learner id
     * @return array<int,int> map of courseid → percentage 0–100; courses with no tracked
     *                        activities are included with value 0
     */
    public function compute_progress_pct_batch(array $courseids, int $userid): array {
        global $DB;

        $courseids = array_map('intval', $courseids);
        $courseids = array_values(array_unique($courseids));

        if (empty($courseids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
        $params['userid'] = $userid;

        $sql = "SELECT cm.course AS courseid,
                       COUNT(cm.id) AS total,
                       SUM(CASE WHEN cmc.completionstate > 0 THEN 1 ELSE 0 END) AS done
                  FROM {course_modules} cm
                  LEFT JOIN {course_modules_completion} cmc
                            ON cmc.coursemoduleid = cm.id AND cmc.userid = :userid
                  JOIN {modules} m ON m.id = cm.module AND m.visible = 1
                 WHERE cm.course $insql
                   AND cm.completion > 0
                   AND cm.deletioninprogress = 0
                 GROUP BY cm.course";

        $records = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($records as $row) {
            $total = (int)$row->total;
            $done = (int)$row->done;
            $result[(int)$row->courseid] = $total > 0 ? (int)(($done / $total) * 100) : 0;
        }

        // Courses with no completion-tracked activities default to 0.
        foreach ($courseids as $id) {
            if (!isset($result[$id])) {
                $result[$id] = 0;
            }
        }

        return $result;
    }
}
