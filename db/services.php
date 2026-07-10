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
 * External web service definitions for local_vbs_myoverview.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_vbs_myoverview_enrich_courses' => [
        'classname'   => 'local_vbs_myoverview\external\enrich_courses',
        'methodname'  => 'execute',
        'description' => 'Return VBS presentation enrichment (state badges, class date range, register URL) '
            . 'for the given course ids. Read-only; computes from existing course/enrolment/completion data.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => '',
        'services'    => [],
    ],
    'local_vbs_myoverview_open_registration_summary' => [
        'classname'   => 'local_vbs_myoverview\external\open_registration_summary',
        'methodname'  => 'execute',
        'description' => 'Return whether the current user has classes open for self registration '
            . '(count + catalog URL + per-course register URLs). Read-only; powers Empty State A '
            . 'and the catalog "Đăng ký ngay" CTA (VBS-134).',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => '',
        'services'    => [],
    ],
    'local_vbs_myoverview_get_learning_progress' => [
        'classname'   => 'local_vbs_myoverview\external\get_learning_progress',
        'methodname'  => 'execute',
        'description' => 'Return the personal learning-progress payload (active/completed courses, '
            . 'derived training plan, certificates) for a learner. Read-only; a learner may only read '
            . 'their own data unless they hold moodle/user:viewdetails on the target user.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => '',
        'services'    => [],
    ],
    'local_vbs_myoverview_get_schedule' => [
        'classname'   => 'local_vbs_myoverview\external\get_schedule',
        'methodname'  => 'execute',
        'description' => 'Return all scheduled sessions (face-to-face, quiz, VBS exam) for the current '
            . 'learner in a unified format, with optional date range and filter params.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => '',
        'services'    => [],
    ],
    'local_vbs_myoverview_export_schedule_ics' => [
        'classname'   => 'local_vbs_myoverview\external\export_schedule_ics',
        'methodname'  => 'execute',
        'description' => 'Export the current learner\'s schedule as a base64-encoded iCalendar (ICS) feed '
            . 'for calendar import.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => '',
        'services'    => [],
    ],
];
