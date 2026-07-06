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
 * F02 — Personal learning progress page controller.
 *
 * Fetches `local_vbs_myoverview_get_learning_progress` (or bundled mock data
 * while the backend WS — VBS-159 — is being built, enabled with ?vbsmock=1) and
 * hydrates each of the four sections independently: a failure or empty result in
 * one section never blocks the others (TC-19 / TC-20).
 *
 * @module     local_vbs_myoverview/progress
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import Notification from 'core/notification';
import Pending from 'core/pending';
import {get_string as getString} from 'core/str';

const COMPONENT = 'local_vbs_myoverview';

const SELECTORS = {
    ROOT: '[data-region="learning-progress"]',
    SECTION: '[data-region="section"]',
    BODY: '[data-region="body"]',
    DOWNLOAD: '[data-action="download-cert"]',
};

/** delivery_mode value → lang string key. */
const DELIVERY_KEY = {
    elearning: 'progress:delivery_elearning',
    classroom: 'progress:delivery_classroom',
    blended: 'progress:delivery_blended',
};

/** plan item status → lang string key. */
const PLAN_STATUS_KEY = {
    completed: 'progress:plan_status_completed',
    in_progress: 'progress:plan_status_in_progress',
    not_started: 'progress:plan_status_not_started',
};

/** plan item status → Bootstrap badge class. */
const PLAN_STATUS_CLASS = {
    completed: 'badge-success',
    in_progress: 'badge-warning',
    not_started: 'badge-secondary',
};

/**
 * Shorthand for a component string.
 *
 * @param {string} key
 * @param {(string|number|object)} [param]
 * @return {Promise<string>}
 */
const str = (key, param) => getString(key, COMPONENT, param);

/**
 * Format a UNIX timestamp as dd/mm/yyyy in the user's timezone (AC-01).
 *
 * @param {number} timestamp seconds since epoch (0/null → null)
 * @param {string} timezone IANA timezone id
 * @return {?string}
 */
const formatDate = (timestamp, timezone) => {
    if (!timestamp) {
        return null;
    }
    const date = new Date(timestamp * 1000);
    const opts = {day: '2-digit', month: '2-digit', year: 'numeric'};
    try {
        return new Intl.DateTimeFormat('en-GB', {...opts, timeZone: timezone}).format(date);
    } catch (e) {
        // Unknown timezone id — fall back to the browser locale timezone.
        return new Intl.DateTimeFormat('en-GB', opts).format(date);
    }
};

/**
 * Build the template context for the training-plan section.
 *
 * @param {?object} plan training_plan payload (null when the user has no plan)
 * @param {object} config init config
 * @return {Promise<object>}
 */
const buildPlanContext = async(plan, config) => {
    if (!plan) {
        return {hasplan: false, emptytext: await str('progress:empty_plan', config.year)};
    }

    const total = parseInt(plan.total_items, 10) || 0;
    const completed = parseInt(plan.completed_items, 10) || 0;
    // Clamp to 0..100: BE PARAM_INT sanitizes but does not cap, and BR-F01-02
    // allows completed > total on edge cases — an over-100 width overflows the
    // bar and yields an invalid aria-valuenow against aria-valuemax="100".
    const percent = total > 0 ? Math.max(0, Math.min(100, Math.round((completed / total) * 100))) : 0;

    // Defensive sort: duedate ASC, nulls last (BE already sorts, but never trust it).
    const rawItems = (plan.items || []).slice().sort((a, b) => {
        const da = a.duedate || null;
        const db = b.duedate || null;
        if (da === db) {
            return 0;
        }
        if (da === null) {
            return 1;
        }
        if (db === null) {
            return -1;
        }
        return da - db;
    });

    const [countlabel, barlabel, noDeadline, noItems] = await Promise.all([
        str('progress:plan_progress', {completed, total}),
        str('progress:progressbar_label', percent),
        str('progress:nodeadline'),
        str('progress:plan_noitems'),
    ]);

    const items = await Promise.all(rawItems.map(async(item) => {
        const key = PLAN_STATUS_KEY[item.status] || PLAN_STATUS_KEY.not_started;
        const due = formatDate(item.duedate, config.timezone);
        return {
            coursename: item.coursename,
            statuslabel: await str(key),
            statusclass: PLAN_STATUS_CLASS[item.status] || PLAN_STATUS_CLASS.not_started,
            duetext: due ? await str('progress:deadline', due) : noDeadline,
        };
    }));

    return {
        hasplan: true,
        percent,
        countlabel,
        barlabel,
        hasitems: items.length > 0,
        items,
        // Shown by the template only when hasitems is false.
        emptytext: noItems,
    };
};

/**
 * Build the template context for the "courses in progress" section.
 *
 * @param {object[]} courses active_courses payload
 * @param {object} config init config
 * @return {Promise<object>}
 */
const buildActiveContext = async(courses, config) => {
    const list = courses || [];
    const noDeadline = await str('progress:nodeadline');

    const rendered = await Promise.all(list.map(async(course) => {
        const percent = Math.max(0, Math.min(100, parseInt(course.completion_percent, 10) || 0));
        const due = formatDate(course.deadline, config.timezone);
        const deliverykey = DELIVERY_KEY[course.delivery_mode];
        return {
            coursename: course.coursename,
            courseurl: course.courseurl,
            percent,
            percentlabel: await str('progress:percent', percent),
            barlabel: await str('progress:progressbar_label', percent),
            deadlinetext: due ? await str('progress:deadline', due) : noDeadline,
            deliverylabel: deliverykey ? await str(deliverykey) : (course.delivery_mode || ''),
        };
    }));

    return {
        hasany: rendered.length > 0,
        courses: rendered,
        emptytext: await str('progress:empty_active'),
    };
};

/**
 * Build the template context for the "completed courses" section.
 *
 * @param {object[]} courses completed_courses payload
 * @param {object} config init config
 * @return {Promise<object>}
 */
const buildCompletedContext = async(courses, config) => {
    const list = courses || [];
    const viewcertlabel = await str('progress:viewcertificate');

    const rendered = await Promise.all(list.map(async(course) => {
        const done = formatDate(course.timecompleted, config.timezone);
        return {
            coursename: course.coursename,
            courseurl: course.courseurl,
            completedtext: done ? await str('progress:completedon', done) : '',
            hascert: !!course.cert_url,
            cert_url: course.cert_url,
            viewcertlabel,
        };
    }));

    return {
        hasany: rendered.length > 0,
        courses: rendered,
        emptytext: await str('progress:empty_completed'),
    };
};

/**
 * Build the template context for the "issued certificates" section.
 *
 * @param {object[]} certificates certificates payload
 * @return {Promise<object>}
 */
const buildCertificatesContext = async(certificates) => {
    // Defensive sort: newest first.
    const list = (certificates || []).slice().sort((a, b) => (b.timecreated || 0) - (a.timecreated || 0));
    const downloadlabel = await str('progress:downloadpdf');

    const certs = await Promise.all(list.map(async(cert) => ({
        certname: cert.certname,
        download_url: cert.download_url,
        downloadlabel,
        downloadaria: await str('progress:downloadpdf_label', cert.certname),
    })));

    return {
        hasany: certs.length > 0,
        certs,
        emptytext: await str('progress:empty_certificates'),
    };
};

/**
 * Render one section's body from its template + context, then clear its busy state.
 *
 * Wrapped so a failure in one section surfaces an inline error without aborting
 * the others (TC-19: sections load independently).
 *
 * @param {Element} section the section element
 * @param {string} template template name (without component prefix)
 * @param {Promise<object>} contextPromise resolves to the template context
 * @return {Promise<void>}
 */
const renderSection = async(section, template, contextPromise) => {
    const body = section.querySelector(SELECTORS.BODY);
    if (!body) {
        return;
    }
    try {
        const context = await contextPromise;
        const {html, js} = await Templates.renderForPromise(`${COMPONENT}/${template}`, context);
        Templates.replaceNodeContents(body, html, js);
    } catch (error) {
        const message = await str('progress:loaderror');
        body.innerHTML = `<p class="vbs-lp-error text-danger" role="alert"></p>`;
        body.querySelector('.vbs-lp-error').textContent = message;
        Notification.exception(error);
    } finally {
        section.setAttribute('aria-busy', 'false');
    }
};

/**
 * Stream a certificate PDF via a hidden iframe so it downloads in place —
 * no navigation, no new tab (AC-06 / TC-18). The BE download_url already
 * carries downloadown=1, which makes customcert send an attachment disposition.
 *
 * @param {string} url
 */
const streamDownload = (url) => {
    // Defensively guarantee downloadown=1 — the iframe only downloads (rather
    // than silently rendering the customcert view page) when the response
    // carries Content-Disposition: attachment, which customcert gates on this
    // param. If a proxy or hand-built URL drops it, the click would look broken.
    const src = url.includes('downloadown=') ? url : url + (url.includes('?') ? '&' : '?') + 'downloadown=1';
    let iframe = document.querySelector('iframe[data-region="vbs-lp-download"]');
    if (!iframe) {
        iframe = document.createElement('iframe');
        iframe.setAttribute('data-region', 'vbs-lp-download');
        iframe.style.display = 'none';
        document.body.appendChild(iframe);
    }
    iframe.src = src;
};

/**
 * Bind the certificate download interception on the page root.
 *
 * @param {Element} root
 */
const bindDownloads = (root) => {
    root.addEventListener('click', (event) => {
        const link = event.target.closest(SELECTORS.DOWNLOAD);
        if (!link) {
            return;
        }
        event.preventDefault();
        streamDownload(link.getAttribute('href'));
    });
};

/**
 * Fetch the learning-progress payload, from mock data or the real WS.
 *
 * @param {object} config
 * @return {Promise<object>}
 */
const fetchProgress = (config) => {
    if (config.mock) {
        return Promise.resolve(mockPayload());
    }
    const request = Ajax.call([{
        methodname: 'local_vbs_myoverview_get_learning_progress',
        args: {userid: config.userid},
    }]);
    return request[0];
};

/**
 * Entry point.
 *
 * @param {object} config {userid, mock, year, timezone}
 */
export const init = (config) => {
    const root = document.querySelector(SELECTORS.ROOT);
    if (!root) {
        return;
    }
    bindDownloads(root);

    const pending = new Pending('local_vbs_myoverview/progress:load');
    const sections = {};
    root.querySelectorAll(SELECTORS.SECTION).forEach((section) => {
        sections[section.dataset.section] = section;
    });

    fetchProgress(config).then((data) => {
        return Promise.all([
            renderSection(sections.plan, 'progress_section_plan', buildPlanContext(data.training_plan, config)),
            renderSection(sections.active, 'progress_section_active', buildActiveContext(data.active_courses, config)),
            renderSection(sections.completed, 'progress_section_completed',
                buildCompletedContext(data.completed_courses, config)),
            renderSection(sections.certificates, 'progress_section_certificates',
                buildCertificatesContext(data.certificates)),
        ]);
    }).catch(Notification.exception).finally(() => pending.resolve());
};

/**
 * Bundled mock payload mirroring the VBS-159 API contract. Used with ?vbsmock=1
 * so FE can be exercised end-to-end before the backend WS is available. Exercises
 * every branch: null deadline, 0% progress, plan with a null-due item, a completed
 * course without a certificate, and non-empty certificates.
 *
 * @return {object}
 */
const mockPayload = () => ({
    userid: 123,
    fullname: 'Nguyễn Văn A',
    generated_at: 1720000000,
    active_courses: [
        {
            courseid: 10,
            coursename: 'Kế toán cơ bản',
            shortname: 'KTCB',
            courseurl: 'https://lms.vbs.vn/course/view.php?id=10',
            completion_percent: 65,
            deadline: 1727654400,
            delivery_mode: 'elearning',
        },
        {
            courseid: 11,
            coursename: 'Kỹ năng thuyết trình',
            shortname: 'KNTT',
            courseurl: 'https://lms.vbs.vn/course/view.php?id=11',
            completion_percent: 0,
            deadline: null,
            delivery_mode: 'classroom',
        },
    ],
    completed_courses: [
        {
            courseid: 5,
            coursename: 'An toàn lao động',
            shortname: 'ATLĐ',
            courseurl: 'https://lms.vbs.vn/course/view.php?id=5',
            timecompleted: 1710460800,
            cert_url: 'https://lms.vbs.vn/mod/customcert/view.php?id=3',
            cert_code: 'VBS-2024-001',
        },
        {
            courseid: 6,
            coursename: 'Định hướng hội nhập',
            shortname: 'DHHN',
            courseurl: 'https://lms.vbs.vn/course/view.php?id=6',
            timecompleted: 1701388800,
            cert_url: null,
            cert_code: null,
        },
    ],
    training_plan: {
        year: 2026,
        total_items: 8,
        completed_items: 3,
        in_progress_items: 2,
        not_started_items: 3,
        items: [
            {itemid: 1, courseid: 10, coursename: 'Kế toán cơ bản', status: 'in_progress', duedate: 1727654400},
            {itemid: 2, courseid: 5, coursename: 'An toàn lao động', status: 'completed', duedate: 1710460800},
            {itemid: 3, courseid: 12, coursename: 'Quản trị rủi ro', status: 'not_started', duedate: null},
        ],
    },
    certificates: [
        {
            certid: 1,
            certname: 'Chứng chỉ An toàn lao động',
            courseid: 5,
            coursename: 'An toàn lao động',
            timecreated: 1710460800,
            code: 'VBS-2024-001',
            download_url: 'https://lms.vbs.vn/mod/customcert/view.php?id=3&downloadown=1',
        },
    ],
    warnings: [],
});
