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
 * English language strings for local_vbs_myoverview.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'VBS course overview enrichment';
$string['privacy:metadata'] = 'The VBS course overview enrichment plugin does not store any personal data. '
    . 'It only computes presentation state (badges, date range) on the fly from existing course, enrolment '
    . 'and completion data.';

// Custom field.
$string['customfieldcategory'] = 'VBS';
$string['deliverymode'] = 'Delivery mode';

// Delivery mode (hình thức) badge labels.
$string['delivery_online'] = 'Online';
$string['delivery_offline'] = 'Offline';
$string['delivery_blended'] = 'Blended';

// Lifecycle state badge labels.
$string['lifecycle_not_started'] = 'Not started';
$string['lifecycle_in_progress'] = 'In progress';
$string['lifecycle_ended'] = 'Ended';
$string['lifecycle_completed'] = 'Completed';

// Enrollment state badge labels.
$string['enrollment_assigned'] = 'Assigned';
$string['enrollment_pending_approval'] = 'Pending approval';
$string['enrollment_open_for_registration'] = 'Open for registration';

// F02 — Learning progress page (VBS-160).
$string['progress:pagetitle'] = 'My learning progress';
$string['progress:heading'] = 'My learning progress';

// Section titles.
$string['progress:section_plan'] = 'Training plan {$a}';
$string['progress:section_plan_noyear'] = 'Training plan';
$string['progress:section_active'] = 'Courses in progress';
$string['progress:section_completed'] = 'Completed courses';
$string['progress:section_certificates'] = 'Issued certificates';

// Plan section.
$string['progress:plan_progress'] = '{$a->completed}/{$a->total} items';
$string['progress:plan_status_completed'] = 'Completed';
$string['progress:plan_status_in_progress'] = 'In progress';
$string['progress:plan_status_not_started'] = 'Not started';
$string['progress:plan_noitems'] = 'The training plan has no courses yet.';

// Active courses section.
$string['progress:deadline'] = 'Due: {$a}';
$string['progress:nodeadline'] = 'Due: —';
$string['progress:percent'] = '{$a}%';
$string['progress:delivery_elearning'] = 'E-learning';
$string['progress:delivery_classroom'] = 'Classroom';
$string['progress:delivery_blended'] = 'Blended';
$string['progress:progressbar_label'] = 'Completion {$a}%';

// Completed courses section.
$string['progress:completedon'] = 'Completed: {$a}';
$string['progress:viewcertificate'] = 'View certificate';

// Certificates section.
$string['progress:downloadpdf'] = 'Download PDF';
$string['progress:downloadpdf_label'] = 'Download PDF of certificate {$a}';

// Empty states (TC-20).
$string['progress:empty_plan'] = 'No training plan for {$a}.';
$string['progress:empty_plan_noyear'] = 'No training plan yet.';
$string['progress:empty_active'] = 'You have no courses in progress.';
$string['progress:empty_completed'] = 'You have not completed any courses yet.';
$string['progress:empty_certificates'] = 'No certificates have been issued yet.';

// Loading / error.
$string['progress:loading'] = 'Loading…';
$string['progress:loaderror'] = 'Could not load this section. Please try again later.';

// Schedule tracking (F04).
$string['schedule_type_facetoface'] = 'Face-to-face';
$string['schedule_type_quiz'] = 'Exam / Assessment';
$string['schedule_type_vbs_exam'] = 'VBS Exam session';
