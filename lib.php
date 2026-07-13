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

/**
 * Library functions for local_vbs_myoverview.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Extend the user navigation to include a "Tiến độ học tập" link.
 *
 * @param \navigation_node $navigation
 * @param \stdClass $user
 * @param \context_user $usercontext
 * @param \stdClass $course
 * @param \context_course $coursecontext
 */
function local_vbs_myoverview_extend_navigation_user(
    \navigation_node $navigation,
    \stdClass $user,
    \context_user $usercontext,
    \stdClass $course,
    \context_course $coursecontext
): void {
    global $USER;

    if ($user->id !== (int)$USER->id && !has_capability('moodle/user:viewdetails', $usercontext)) {
        return;
    }

    $url = new \moodle_url('/local/vbs_myoverview/progress.php', ['userid' => $user->id]);
    $navigation->add(
        get_string('progress_dashboard', 'local_vbs_myoverview'),
        $url,
        \navigation_node::TYPE_SETTING,
        null,
        'vbs_progress_dashboard',
        new \pix_icon('i/completion-auto-enabled', '')
    );
}
