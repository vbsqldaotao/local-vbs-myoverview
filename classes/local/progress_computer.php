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
     * Compute the completion percentage for a learner in a course.
     *
     * Returns 0 when completion tracking is disabled or the course has no
     * trackable activities. Uses activity completion states only (not grades).
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
}
