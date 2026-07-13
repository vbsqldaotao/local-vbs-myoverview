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
 * F02 — Progress dashboard AMD controller.
 *
 * Calls local_vbs_myoverview_get_progress_dashboard and hydrates the
 * progress_dashboard Mustache template with the prepared context.
 *
 * Date processing, has_training_plan flag, and display fields are all computed
 * here so the Mustache template stays logic-free (AC-F02-01..05).
 *
 * @module     local_vbs_myoverview/progress_dashboard
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/templates', 'core/str'], function(Ajax, Templates, Str) {

    /**
     * Convert a Unix timestamp (seconds) to dd/mm/YYYY string.
     * Returns empty string when timestamp is 0 or falsy (AC-F02-01 TC-01-04).
     *
     * @param {number} timestamp
     * @return {string}
     */
    var formatDate = function(timestamp) {
        if (!timestamp) {
            return '';
        }
        var d = new Date(timestamp * 1000);
        var dd = String(d.getDate()).padStart(2, '0');
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        var yyyy = d.getFullYear();
        return dd + '/' + mm + '/' + yyyy;
    };

    /**
     * Build the context object for the Mustache template from raw WS data.
     *
     * @param {object} data Raw response from local_vbs_myoverview_get_progress_dashboard
     * @return {object} Template context
     */
    var buildContext = function(data) {
        // Panel 1: In-progress courses.
        var inProgressCourses = (data.in_progress_courses || []).map(function(course) {
            var deadlineDisplay = formatDate(course.enddate);
            return {
                courseid: course.courseid,
                fullname: course.fullname,
                courseurl: course.courseurl,
                completion_pct: course.completion_pct,
                // deadline_display is present only when enddate is non-zero (TC-01-04).
                deadline_display: deadlineDisplay || '',
                has_deadline: !!deadlineDisplay,
                delivery_mode: course.delivery_mode || ''
            };
        });

        // Panel 2: Completed courses.
        var completedCourses = (data.completed_courses || []).map(function(course) {
            return {
                courseid: course.courseid,
                fullname: course.fullname,
                completed_date_display: formatDate(course.timecompleted),
                // cert_url is only set when a certificate exists (AC-F02-02 TC-02-03).
                cert_url: course.cert_url || '',
                cert_name: course.cert_name || ''
            };
        });

        // Panel 3: Training plan — hidden entirely when year === 0 (AC-F02-03 TC-03-02).
        var trainingPlan = data.training_plan || {};
        var hasTrainingPlan = trainingPlan.year !== 0 && !!trainingPlan.year;

        // Panel 4: Issued certificates.
        var certificates = (data.certificates || []).map(function(cert) {
            return {
                issueid: cert.issueid,
                cert_name: cert.cert_name,
                course_fullname: cert.course_fullname,
                issued_date_display: formatDate(cert.timecreated),
                download_url: cert.download_url
            };
        });

        return {
            in_progress_courses: inProgressCourses,
            completed_courses: completedCourses,
            has_training_plan: hasTrainingPlan,
            training_plan: hasTrainingPlan ? trainingPlan : null,
            certificates: certificates
        };
    };

    return {
        /**
         * Initialise the progress dashboard.
         *
         * @param {object} params {userid: int}
         */
        init: function(params) {
            Ajax.call([{
                methodname: 'local_vbs_myoverview_get_progress_dashboard',
                args: {
                    userid: params.userid,
                    plan_year: new Date().getFullYear()
                }
            }])[0].then(function(data) {
                var context = buildContext(data);
                return Templates.render('local_vbs_myoverview/progress_dashboard', context);
            }).then(function(html, js) {
                Templates.replaceNodeContents('#progress-dashboard-container', html, js);
                return;
            }).catch(function(error) {
                // eslint-disable-next-line no-console
                console.error('Progress dashboard error:', error);
                Str.get_string('error').then(function(errorStr) {
                    var container = document.querySelector('#progress-dashboard-container');
                    if (container) {
                        container.innerHTML = '<p class="alert alert-danger">' + errorStr + '</p>';
                    }
                    return;
                }).catch(function() {
                    // Silently ignore string fetch failure.
                });
            });
        }
    };
});
