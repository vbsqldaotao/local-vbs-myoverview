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
 * Behat step definitions for local_vbs_myoverview (F01 – Course list).
 *
 * These steps are discovered by Moodle's Behat runner from all installed
 * plugins, so they are also available to tests in vbs-theme repo when both
 * plugins are installed (see tests/behat/vbs_f01_course_badges.feature there).
 *
 * @package    local_vbs_myoverview
 * @category   test
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use Behat\Mink\Exception\ElementNotFoundException;
use Behat\Mink\Exception\ExpectationException;

// phpcs:disable moodle.NamingConventions.ValidFunctionName.LowercaseMethod

// Pull in enrol step definitions (registration open/closed, pending/active status,
// slot caps) so they are all available inside the theme_vbs Behat suite via the
// behat_theme_vbs extends behat_local_vbs_myoverview chain.
// Use __DIR__ (absolute path of this file's directory) rather than $GLOBALS['CFG']->dirroot
// because Behat's ClassResolver loads context files before Moodle bootstrap completes,
// so $CFG is not yet available at this point.
require_once(__DIR__ . '/../../../vbs_enrol/tests/behat/behat_local_vbs_enrol.php');

/**
 * Step definitions for local_vbs_myoverview and the theme_vbs Behat suite.
 *
 * Extends behat_local_vbs_enrol so that enrol setup steps (registration open,
 * pending/active registrations, slot caps) are all usable from theme_vbs
 * feature files without duplication.
 */
class behat_local_vbs_myoverview extends behat_local_vbs_enrol {

    // ─────────────────────────────────────────────────────────────
    // Data setup steps
    // ─────────────────────────────────────────────────────────────

    /**
     * Set the delivery_mode custom field on a course identified by its shortname.
     *
     * Ensures the delivery_mode field is provisioned (calls the installer) before
     * writing data, so this step works even on a freshly-reset test DB.
     *
     * customfield_select stores a 1-based option index. Options in configdata:
     * "online\noffline\nblended" → stored values 1, 2, 3. export_value() returns
     * the option text ("online", "offline", "blended") which badge_mapper receives
     * after strtolower() in enrich_courses::get_delivery_mode().
     *
     * @Given the course :shortname has delivery mode :mode
     *
     * @param string $shortname course shortname
     * @param string $mode      canonical delivery mode: online | offline | blended
     */
    public function the_course_has_delivery_mode(string $shortname, string $mode): void {
        global $DB;

        \local_vbs_myoverview\local\customfield_installer::ensure_delivery_mode_field();

        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
        $handler = \core_course\customfield\course_handler::create();

        // Locate the delivery_mode field.
        $fieldobj = null;
        foreach ($handler->get_fields() as $field) {
            if ($field->get('shortname') === \local_vbs_myoverview\local\customfield_installer::FIELD_SHORTNAME) {
                $fieldobj = $field;
                break;
            }
        }

        if ($fieldobj === null) {
            throw new \moodle_exception('Field delivery_mode not found after ensure_delivery_mode_field().');
        }

        // Determine the 1-based option index from the field's stored configdata JSON.
        $fieldrecord = $DB->get_record('customfield_field',
            ['shortname' => \local_vbs_myoverview\local\customfield_installer::FIELD_SHORTNAME], '*', MUST_EXIST);
        $configdata  = json_decode($fieldrecord->configdata, true);
        $optionsraw  = $configdata['options'] ?? '';
        $options     = array_values(array_filter(preg_split('/[\r\n]+/', $optionsraw), fn($o) => trim($o) !== ''));

        $normalised = strtolower(trim($mode));
        $idx        = array_search($normalised, array_map(fn($o) => strtolower(trim($o)), $options), true);

        if ($idx === false) {
            throw new \moodle_exception("Unknown delivery mode '$mode'. Valid options: " . implode(', ', $options));
        }

        $optionvalue = (string)($idx + 1); // customfield_select uses 1-based index.

        // Get or create the data controller for this course/field pair.
        $instdata   = $handler->get_instance_data($course->id, true);
        $controller = null;
        foreach ($instdata as $d) {
            if ($d->get_field()->get('id') === (int)$fieldobj->get('id')) {
                $controller = $d;
                break;
            }
        }

        if ($controller === null) {
            $controller = \core_customfield\data_controller::create(0, null, $fieldobj);
            $controller->set('instanceid', $course->id);
            $controller->set('contextid', \context_course::instance($course->id)->id);
        }

        // contextid is required (NULL_NOT_ALLOWED) in Moodle 4.4 data persistent.
        // get_instance_data(true) returns controllers without contextid set, so set it unconditionally.
        $controller->set('contextid', \context_course::instance($course->id)->id);
        // customfield_select stores the 1-based option index in intvalue; export_value() reads
        // from intvalue. set('value', ...) only writes the TEXT column — also set intvalue explicitly.
        $controller->set('intvalue', (int)$optionvalue);
        $controller->set('value', $optionvalue);
        $controller->save();
    }

    /**
     * Open a course for self registration (the `open_for_registration` half (b)).
     *
     * Makes the named course qualify for state_computer::get_open_registration_courseids():
     * the site-wide `self` enrol plugin is enabled, and the course carries an enabled
     * `self` enrolment instance with new enrolments allowed, no capacity cap, no cohort
     * restriction and an open (unbounded) enrol window. That is exactly the gate
     * enrol_self_plugin::can_self_enrol() checks, so a learner who is not yet enrolled
     * shows up as "open" on the catalog CTA / Empty State A overlays.
     *
     * Self-contained (no reliance on the course's auto-created instance state) so it
     * works on a freshly-reset Behat DB, mirroring the_course_has_delivery_mode().
     *
     * @Given the course :shortname is open for self enrolment
     *
     * @param string $shortname course shortname
     */
    public function the_course_is_open_for_self_enrolment(string $shortname): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/enrol/self/lib.php');

        // Ensure the self enrolment plugin is enabled site-wide.
        if (!enrol_is_enabled('self')) {
            \core\plugininfo\enrol::enable_plugin('self', 1);
        }

        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
        $plugin = enrol_get_plugin('self');

        // Reuse the course's self instance if one exists (courses get a disabled one by
        // default); otherwise add a fresh enabled instance.
        $instance = null;
        foreach (enrol_get_instances($course->id, false) as $inst) {
            if ($inst->enrol === 'self') {
                $instance = $inst;
                break;
            }
        }
        if ($instance === null) {
            $instanceid = $plugin->add_instance($course, [
                'status'     => ENROL_INSTANCE_ENABLED,
                'customint6' => 1, // Allow new enrolments.
            ]);
            $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
        }

        // Enable the instance and clear every gate can_self_enrol() evaluates.
        $plugin->update_status($instance, ENROL_INSTANCE_ENABLED);
        $DB->update_record('enrol', (object)[
            'id'             => $instance->id,
            'customint6'     => 1, // New enrolments allowed.
            'customint3'     => 0, // Max enrolled: unlimited.
            'customint5'     => 0, // No cohort restriction.
            'enrolstartdate' => 0,
            'enrolenddate'   => 0,
            'password'       => '', // Password does not block eligibility, but keep it open.
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Navigation steps
    // ─────────────────────────────────────────────────────────────

    /**
     * Navigate to the VBS "My courses" page (/my/courses.php).
     *
     * @When I am on the VBS course list page
     */
    public function i_am_on_the_vbs_course_list_page(): void {
        $url = new \moodle_url('/my/courses.php');
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
        // W1 fix: use parent's wait_for_pending_js() — do not override.
        $this->wait_for_pending_js();
    }

    /**
     * Navigate to the course catalog category page that lists the named course.
     *
     * Goes straight to /course/index.php?categoryid=<the course's category> so the
     * course boxes render server-side under the `coursecategory` layout (where the
     * theme_vbs/catalog_register overlay is loaded), avoiding the lazy category-tree
     * AJAX expansion of the catalog root.
     *
     * @When I am on the course catalog page listing :shortname
     *
     * @param string $shortname course shortname
     */
    public function i_am_on_the_course_catalog_page_listing(string $shortname): void {
        global $DB;
        $course = $DB->get_record('course', ['shortname' => $shortname], 'id, category', MUST_EXIST);
        $url = new \moodle_url('/course/index.php', ['categoryid' => $course->category]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
        $this->wait_for_pending_js();
    }

    // ─────────────────────────────────────────────────────────────
    // Interaction steps — search and filter
    // ─────────────────────────────────────────────────────────────

    /**
     * Type a keyword into the block_myoverview search box.
     *
     * W2 fix: scope the selector to [data-region="myoverview"] to avoid
     * matching nav-bar or global search inputs on the same page.
     *
     * @When I search courses for :keyword
     *
     * @param string $keyword search term
     */
    public function i_search_courses_for(string $keyword): void {
        // W2 fix: narrow to the myoverview block's own search input.
        // Moodle 4.4 uses data-action="search" (core/search_input_auto template).
        $input = $this->find('css',
            '[data-region="myoverview"] input[data-action="search"]');
        $input->setValue($keyword);
        // Trigger the input event so AMD view.js re-fetches courses.
        $this->execute_script(
            'var el = document.querySelector(\'[data-region="myoverview"] input[data-action="search"]\');'
            . 'if (el) {'
            . '  el.dispatchEvent(new Event("input", {bubbles: true}));'
            . '  el.dispatchEvent(new Event("keyup", {bubbles: true}));'
            . '}'
        );
        $this->wait_for_pending_js();
    }

    /**
     * Clear the block_myoverview search box and restore the full course list.
     *
     * @When I clear the course search
     */
    public function i_clear_the_course_search(): void {
        $this->i_search_courses_for('');
    }

    /**
     * Click the block_myoverview timeline filter button for the given classification key.
     *
     * Accepted keys match the core classification parameter:
     *   allincludinghidden | inprogress | future | past | hidden | favourites
     *
     * W4 fix: use json_encode() to safely embed the classification value in JS
     * so that any special characters in the value cannot break the querySelector.
     *
     * @When I filter courses by :classification
     *
     * @param string $classification core timeline classification key
     */
    public function i_filter_courses_by(string $classification): void {
        // W4 fix: json_encode() safely quotes the value for the JS string literal.
        $safevalue = json_encode($classification);
        $script = <<<JS
        (function() {
            var v = {$safevalue};
            var btn = document.querySelector('[data-value="' + v + '"]')
                   || document.querySelector('[data-filter-type="' + v + '"]')
                   || document.querySelector('[data-filtername="' + v + '"]');
            if (btn) { btn.click(); return true; }
            return false;
        })();
        JS;

        $found = $this->evaluate_script($script);

        if (!$found) {
            // Fallback: locate by visible label text for each known classification key.
            $labels = [
                'all'                => ['All', 'Tất cả'],
                'allincludinghidden' => ['All (including hidden)', 'Tất cả (bao gồm ẩn)'],
                'inprogress'         => ['In progress', 'Đang diễn ra'],
                'future'             => ['Future', 'Sắp diễn ra', 'Chưa bắt đầu'],
                'past'               => ['Past', 'Đã kết thúc'],
                'favourites'         => ['Starred', 'Yêu thích'],
            ];
            $textcandidates = $labels[$classification] ?? [$classification];
            foreach ($textcandidates as $label) {
                try {
                    $btn = $this->find('xpath',
                        '//button[normalize-space(text())=' . \behat_context_helper::escape($label) . ']'
                        . '|//a[normalize-space(text())=' . \behat_context_helper::escape($label) . ']');
                    $btn->click();
                    break;
                } catch (ElementNotFoundException $e) {
                    continue;
                }
            }
        }

        $this->wait_for_pending_js();
    }

    /**
     * Click the "load more" button or next-page link in the course list.
     *
     * @When I follow the next page in the course list
     */
    public function i_follow_the_next_page_in_the_course_list(): void {
        // Moodle 4.4 core/paged-content uses data-control="next" on the next-page link.
        // Older selector variants are retained as fallbacks.
        $btn = $this->find('css',
            '[data-control="next"], [data-action="load-more"], .paging-bar-next, a[aria-label="Next page"]');
        $btn->click();
        $this->wait_for_pending_js();
    }

    // ─────────────────────────────────────────────────────────────
    // Assertion steps — VBS presentation contract (delta D6/D7)
    // Used by both this plugin's tests and vbs-theme/tests/behat/.
    // ─────────────────────────────────────────────────────────────

    /**
     * Assert a VBS badge label appears on the named course's card.
     *
     * Uses spin() because the VBS JS overlay (myoverview_enricher.js) injects
     * the badge HTML asynchronously after the initial page render.
     *
     * W5 fix (VBS-141): anchor on the semantic `data-badge-type` attribute
     * rather than a substring of the whole container text. Each VBS badge is
     * rendered by coursecard.mustache as
     *   <span class="badge ..." data-badge-type="delivery|lifecycle|enrollment">Label</span>
     * so the label is matched against the text of an actual typed badge element,
     * which no longer silently passes if the label leaks in from unrelated markup.
     *
     * @Then the course card for :coursename shows the vbs badge :label
     *
     * @param string $coursename visible course fullname
     * @param string $label      expected badge text (locale-dependent; force en in Background)
     */
    public function the_course_card_shows_vbs_badge(string $coursename, string $label): void {
        $this->spin(function() use ($coursename, $label) {
            $card   = $this->find_course_card($coursename);
            $badges = $card->findAll('css', '.vbs-card-badges [data-badge-type]');
            if (!$badges) {
                throw new ExpectationException(
                    "Course card for '$coursename' has no [data-badge-type] badge element yet.",
                    $this->getSession()
                );
            }
            foreach ($badges as $badge) {
                if (str_contains(trim($badge->getText()), trim($label))) {
                    return true;
                }
            }
            $found = implode(', ', array_map(fn($b) => trim($b->getText()), $badges));
            throw new ExpectationException(
                "Course card for '$coursename' badges = '$found'"
                . " — expected a data-badge-type badge with label '$label'.",
                $this->getSession()
            );
        });
    }

    /**
     * Assert that the delivery-mode chip is absent on the named course's card.
     *
     * When delivery_mode is unset, badge_mapper omits the delivery badge and
     * only lifecycle + enrollment badges are rendered.
     *
     * W5 fix (VBS-141): assert on the semantic `data-badge-type="delivery"`
     * anchor instead of matching badge_mapper::OUTLINE_DELIVERY outline classes
     * ('border-secondary' + 'bg-white'), which would silently pass if the theme
     * changed the outline styling.
     *
     * @Then the course card for :coursename does not show a delivery badge
     *
     * @param string $coursename visible course fullname
     */
    public function the_course_card_does_not_show_delivery_badge(string $coursename): void {
        $this->spin(function() use ($coursename) {
            $card     = $this->find_course_card($coursename);
            $delivery = $card->find('css', '.vbs-card-badges [data-badge-type="delivery"]');
            if ($delivery !== null) {
                throw new ExpectationException(
                    "Course card for '$coursename' should NOT have a delivery badge but one was found.",
                    $this->getSession()
                );
            }
            return true;
        });
    }

    /**
     * Assert the course card shows a non-empty .vbs-card-dates element.
     *
     * @Then the course card for :coursename shows a date range
     *
     * @param string $coursename visible course fullname
     */
    public function the_course_card_shows_a_date_range(string $coursename): void {
        $this->spin(function() use ($coursename) {
            $card      = $this->find_course_card($coursename);
            $daterange = $card->find('css', '.vbs-card-dates');
            if (!$daterange || trim($daterange->getText()) === '') {
                throw new ExpectationException(
                    "Course card for '$coursename' does not show a .vbs-card-dates element.",
                    $this->getSession()
                );
            }
            return true;
        });
    }

    /**
     * Assert the course card has NO .vbs-card-dates element (or an empty one).
     *
     * @Then the course card for :coursename does not show a date range
     *
     * @param string $coursename visible course fullname
     */
    public function the_course_card_does_not_show_date_range(string $coursename): void {
        $this->spin(function() use ($coursename) {
            $card      = $this->find_course_card($coursename);
            $daterange = $card->find('css', '.vbs-card-dates');
            if ($daterange && trim($daterange->getText()) !== '') {
                throw new ExpectationException(
                    "Course card for '$coursename' should NOT show a date range; got: "
                    . $daterange->getText(),
                    $this->getSession()
                );
            }
            return true;
        });
    }

    /**
     * Assert that the VBS badges on the card appear in the exact CSV order given.
     *
     * @Then the course card for :coursename has badge order :csvlabels
     *
     * @param string $coursename visible course fullname
     * @param string $csvlabels  comma-separated expected badge labels in order
     */
    public function the_course_card_has_badge_order(string $coursename, string $csvlabels): void {
        $expected = array_map('trim', explode(',', $csvlabels));

        $this->spin(function() use ($coursename, $expected) {
            $card           = $this->find_course_card($coursename);
            $badgecontainer = $card->find('css', '.vbs-card-badges');
            if (!$badgecontainer) {
                throw new ExpectationException(
                    "Course card for '$coursename' has no .vbs-card-badges yet.",
                    $this->getSession()
                );
            }
            // W5 fix (VBS-141): iterate typed badge elements in DOM order via the
            // data-badge-type anchor instead of the generic span.badge selector.
            $actual = array_map(fn($s) => trim($s->getText()),
                $badgecontainer->findAll('css', '[data-badge-type]'));
            if ($actual !== $expected) {
                throw new ExpectationException(
                    "Badge order for '$coursename': expected ["
                    . implode(', ', $expected) . "] — got ["
                    . implode(', ', $actual) . "].",
                    $this->getSession()
                );
            }
            return true;
        });
    }

    /**
     * Assert that the active timeline filter button shows the given classification key.
     *
     * block_myoverview marks the active filter with aria-current="true" or an
     * `active` CSS class.
     *
     * @Then the active filter is still :classification
     *
     * @param string $classification timeline classification key
     */
    public function the_active_filter_is_still(string $classification): void {
        $safevalue = json_encode($classification);
        $this->spin(function() use ($safevalue) {
            $found = $this->evaluate_script(
                '(function() {'
                . '  var v = ' . $safevalue . ';'
                . '  return !!(document.querySelector(\'[data-value="\' + v + \'"].active\')'
                . '         || document.querySelector(\'[data-filter-type="\' + v + \'"].active\')'
                . '         || document.querySelector(\'[data-value="\' + v + \'"]\')?.getAttribute("aria-current") === "true"'
                . '         || document.querySelector(\'[data-region="courses-view"]\')?.getAttribute("data-grouping") === v);'
                . '})()'
            );
            if (!$found) {
                throw new ExpectationException(
                    "Expected active filter $safevalue not found.",
                    $this->getSession()
                );
            }
            return true;
        });
    }

    // ─────────────────────────────────────────────────────────────
    // Assertion steps — F01 half (b): catalog CTA + Empty State A
    // (theme_vbs overlays; require theme_vbs active + this plugin installed)
    // ─────────────────────────────────────────────────────────────

    /**
     * Assert the "Đăng ký ngay" register CTA appears on the named catalog course box.
     *
     * The CTA is injected asynchronously by theme_vbs/catalog_register.js after the
     * open_registration_summary web service resolves, so spin() until it arrives. The
     * button's href must be a server-built /enrol/index.php URL (arch-review W2 — no
     * client-side URL construction, no javascript: scheme).
     *
     * @Then the catalog course box for :coursename shows a register button
     *
     * @param string $coursename visible course fullname
     */
    public function the_catalog_course_box_shows_a_register_button(string $coursename): void {
        $this->spin(function() use ($coursename) {
            $box = $this->find_catalog_course_box($coursename);
            $cta = $box->find('css', '[data-region="vbs-catalog-cta"] a');
            if ($cta === null) {
                throw new ExpectationException(
                    "Catalog course box for '$coursename' has no register CTA yet.",
                    $this->getSession()
                );
            }
            $href = (string)$cta->getAttribute('href');
            if (strpos($href, '/enrol/index.php') === false) {
                throw new ExpectationException(
                    "Register CTA for '$coursename' should link to /enrol/index.php; got '$href'.",
                    $this->getSession()
                );
            }
            return true;
        });
    }

    /**
     * Assert NO register CTA appears on the named catalog course box.
     *
     * Used for a course that is not open for the learner (e.g. already enrolled or no
     * self enrolment): the catalog must stay a plain listing for it. Waits for pending
     * JS first so the overlay has had its chance to (not) inject.
     *
     * @Then the catalog course box for :coursename does not show a register button
     *
     * @param string $coursename visible course fullname
     */
    public function the_catalog_course_box_does_not_show_a_register_button(string $coursename): void {
        $this->wait_for_pending_js();
        $box = $this->find_catalog_course_box($coursename);
        $cta = $box->find('css', '[data-region="vbs-catalog-cta"]');
        if ($cta !== null) {
            throw new ExpectationException(
                "Catalog course box for '$coursename' should NOT show a register CTA but one was found.",
                $this->getSession()
            );
        }
    }

    /**
     * Assert the Empty State A banner (catalog link) replaces the core "no courses" state.
     *
     * theme_vbs/myoverview_emptystate.js injects the banner asynchronously once the
     * Course overview block settles on its empty placeholder and a class is open for
     * registration; spin() until it appears and check its link targets the catalog.
     *
     * @Then I should see the empty-state catalog banner
     */
    public function i_should_see_the_empty_state_catalog_banner(): void {
        $this->spin(function() {
            $banner = $this->getSession()->getPage()->find('css', '[data-region="vbs-emptystate-a"]');
            if ($banner === null) {
                throw new ExpectationException(
                    'Empty State A banner [data-region="vbs-emptystate-a"] not found yet.',
                    $this->getSession()
                );
            }
            $link = $banner->find('css', 'a[href]');
            if ($link === null || trim((string)$link->getAttribute('href')) === '') {
                throw new ExpectationException(
                    'Empty State A banner is present but has no catalog link.',
                    $this->getSession()
                );
            }
            $href = (string)$link->getAttribute('href');
            if (strpos($href, '/course/index.php') === false) {
                throw new ExpectationException(
                    "Empty State A link should target the course catalog; got '$href'.",
                    $this->getSession()
                );
            }
            return true;
        });
    }

    /**
     * Assert the Empty State A banner is NOT shown (core empty state left untouched).
     *
     * Used when no class is open for registration: the block must keep its stock
     * "no courses" placeholder. Waits for pending JS so the overlay has had its chance.
     *
     * @Then I should not see the empty-state catalog banner
     */
    public function i_should_not_see_the_empty_state_catalog_banner(): void {
        $this->wait_for_pending_js();
        $banner = $this->getSession()->getPage()->find('css', '[data-region="vbs-emptystate-a"]');
        if ($banner !== null) {
            throw new ExpectationException(
                'Empty State A banner should NOT be shown when no class is open for registration.',
                $this->getSession()
            );
        }
    }

    /**
     * Assert the block's empty state shows the §5.3 search no-result wording (VBS-147).
     *
     * When a search term matches no course, block_myoverview renders the SAME core
     * "no courses" placeholder it uses for a genuinely zero-course learner. The
     * theme_vbs/myoverview_emptystate overlay reads the search input at settle time
     * and swaps that placeholder's paragraph text for `f01_search_noresult`. The swap
     * is asynchronous (getString promise), so spin() until the localised string
     * appears in an [data-region="empty-message"] region. The expected text is
     * resolved via get_string() so the step stays language-agnostic regardless of the
     * lang forced in the feature Background.
     *
     * @Then I should see the course search no-result message
     */
    public function i_should_see_the_course_search_no_result_message(): void {
        $expected = get_string('f01_search_noresult', 'theme_vbs');
        $this->spin(function() use ($expected) {
            $regions = $this->getSession()->getPage()->findAll('css',
                '[data-region="courses-view"] [data-region="empty-message"]');
            foreach ($regions as $region) {
                if (str_contains($region->getText(), $expected)) {
                    return true;
                }
            }
            throw new ExpectationException(
                "Search no-result message '$expected' not shown in the empty-message region yet.",
                $this->getSession()
            );
        });
    }

    /**
     * Assert the §5.3 search no-result wording is NOT shown (VBS-147).
     *
     * Used when the search matches at least one course (cards render, no empty
     * state) or when the zero-course state must keep its stock wording rather than
     * the no-result message. Waits for pending JS so the overlay has had its chance
     * to swap, then asserts no empty-message region carries `f01_search_noresult`.
     *
     * @Then I should not see the course search no-result message
     */
    public function i_should_not_see_the_course_search_no_result_message(): void {
        $this->wait_for_pending_js();
        $expected = get_string('f01_search_noresult', 'theme_vbs');
        $regions = $this->getSession()->getPage()->findAll('css',
            '[data-region="courses-view"] [data-region="empty-message"]');
        foreach ($regions as $region) {
            if (str_contains($region->getText(), $expected)) {
                throw new ExpectationException(
                    "Search no-result message '$expected' should NOT be shown here but it was.",
                    $this->getSession()
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Find the per-card DOM element for a course by its fullname.
     *
     * Moodle 4.4 renders each course card as:
     *   <div data-region="course-content" data-course-id="{id}">
     * The `data-course-id` attribute confirms this is a per-card element, not a
     * list container (W3 fix).
     *
     * B4 fix: use behat_context_helper::escape() instead of addslashes() for
     * XPath 1.0 — handles apostrophes and double quotes correctly.
     *
     * @param string $coursename visible course fullname
     * @return \Behat\Mink\Element\NodeElement
     * @throws ExpectationException
     */
    protected function find_course_card(string $coursename): \Behat\Mink\Element\NodeElement {
        // behat_context_helper::escape() produces a valid XPath string literal
        // even when $coursename contains single quotes or double quotes.
        $escaped = \behat_context_helper::escape($coursename);
        // Moodle 4.4 block_myoverview/view-cards.mustache fills the coursename block with
        // a <span class="multiline" title="{{fullname}}"> (plus inner sr-only spans and a
        // potentially-shortened visible span). Using normalize-space(.) on .coursename would
        // include sr-only "Course name" text and fail an exact match. Match via @title instead,
        // which always holds the full (unshortened) course fullname.
        $xpath = "//*[@data-region='course-content' and @data-course-id]"
               . "[.//*[normalize-space(@title)={$escaped}]]";
        try {
            return $this->find('xpath', $xpath);
        } catch (ElementNotFoundException $e) {
            throw new ExpectationException(
                "No course card found for '$coursename'. Is the course visible on the current page?",
                $this->getSession()
            );
        }
    }

    /**
     * Find the catalog course box (/course/index.php) for a course by its fullname.
     *
     * core_course_renderer::coursecat_coursebox() renders each course inline as
     *   <div class="coursebox ..." data-courseid="{id}">
     * with the course fullname in a `.coursename` link. Matches the box whose
     * `.coursename` descendant text contains the fullname.
     *
     * @param string $coursename visible course fullname
     * @return \Behat\Mink\Element\NodeElement
     * @throws ExpectationException
     */
    protected function find_catalog_course_box(string $coursename): \Behat\Mink\Element\NodeElement {
        $escaped = \behat_context_helper::escape($coursename);
        $xpath = "//div[@data-courseid and contains(concat(' ', normalize-space(@class), ' '), ' coursebox ')]"
               . "[.//*[contains(concat(' ', normalize-space(@class), ' '), ' coursename ')]"
               . "[contains(normalize-space(.), {$escaped})]]";
        try {
            return $this->find('xpath', $xpath);
        } catch (ElementNotFoundException $e) {
            throw new ExpectationException(
                "No catalog course box found for '$coursename'. Is the course listed on this category page?",
                $this->getSession()
            );
        }
    }

    // -----------------------------------------------------------------------
    // F03 catalog registration steps — re-declared here so behat_theme_vbs
    // can reliably discover them via PHP reflection. The parent class
    // behat_local_vbs_enrol defines the same methods; overriding them in
    // this class guarantees they are found when only behat_theme_vbs is
    // registered as a Behat context (i.e. when enrol is not a top-level suite).
    // -----------------------------------------------------------------------

    public function user_has_pending_registration_for_course(string $username, string $shortname): void {
        parent::user_has_pending_registration_for_course($username, $shortname);
    }

    /**
     * Pre-stub window.confirm before an action that will trigger it.
     * Must be called BEFORE the action that opens the native confirm dialog.
     */
    public function i_will_confirm_any_dialogs(): void {
        parent::i_will_confirm_any_dialogs();
    }

    /**
     * Stub local_vbs_exam_enrol_course WS to return a mock pending success.
     * Uses M.util.js_pending/js_complete + setTimeout(0) so wait_for_pending_js()
     * blocks until the entire handleRegister async chain completes (TC-05).
     */
    public function i_stub_vbs_exam_registration_ws(): void {
        parent::i_stub_vbs_exam_registration_ws();
    }

}
