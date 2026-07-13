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
 * F02 — Personal learning progress page.
 *
 * Renders the page shell (four sections with per-section skeleton loaders) and
 * hands off to the `local_vbs_myoverview/progress` AMD module, which fetches
 * `local_vbs_myoverview_get_learning_progress` (or mock data with ?vbsmock=1
 * while the backend WS — VBS-159 — is still being built) and hydrates each
 * section independently.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

// Learner scope: the page always renders for the current user. A teacher/admin
// can inspect another learner via ?userid=; the WS itself enforces the
// capability check (AC-08), so the page just forwards the id.
$userid = optional_param('userid', $USER->id, PARAM_INT);
// Front-end mock switch: lets FE be exercised before the BE WS lands (VBS-159).
$mock = optional_param('vbsmock', 0, PARAM_BOOL);

// AC-F02-06 / TC-06-02: deny page access when viewing another user without the
// required capability. The WS has its own check; this guards the page shell too.
if ($userid != $USER->id) {
    $usercontext = context_user::instance($userid);
    require_capability('moodle/user:viewdetails', $usercontext);
}

$context = context_user::instance($USER->id);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/vbs_myoverview/progress.php', $userid == $USER->id ? [] : ['userid' => $userid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('progress:pagetitle', 'local_vbs_myoverview'));
$PAGE->set_heading(get_string('progress:heading', 'local_vbs_myoverview'));

echo $OUTPUT->header();

echo $OUTPUT->render_from_template('local_vbs_myoverview/progress_page', [
    'year' => (int)userdate(time(), '%Y'),
]);

$PAGE->requires->js_call_amd('local_vbs_myoverview/progress', 'init', [[
    'userid' => $userid,
    'mock' => (bool)$mock,
    // Current year for the empty-plan message when the WS returns training_plan = null.
    'year' => (int)userdate(time(), '%Y'),
    // User timezone so JS can render deadlines in the user's tz, not UTC/UNIX (AC-01).
    'timezone' => core_date::get_user_timezone($USER),
]]);

echo $OUTPUT->footer();
