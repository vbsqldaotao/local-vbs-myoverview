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

/**
 * Step definitions for local_vbs_myoverview.
 */
class behat_local_vbs_myoverview extends behat_base {

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
        $controller->set('value', $optionvalue);
        $controller->save();
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
     * @Then the course card for :coursename shows the vbs badge :label
     *
     * @param string $coursename visible course fullname
     * @param string $label      expected badge text (locale-dependent; force en in Background)
     */
    public function the_course_card_shows_vbs_badge(string $coursename, string $label): void {
        $this->spin(function() use ($coursename, $label) {
            $card            = $this->find_course_card($coursename);
            $badgecontainer  = $card->find('css', '.vbs-card-badges');
            if (!$badgecontainer) {
                throw new ExpectationException(
                    "Course card for '$coursename' has no .vbs-card-badges element yet.",
                    $this->getSession()
                );
            }
            if (strpos($badgecontainer->getText(), $label) === false) {
                throw new ExpectationException(
                    "Course card for '$coursename' badges = '{$badgecontainer->getText()}'"
                    . " — expected to contain '$label'.",
                    $this->getSession()
                );
            }
            return true;
        });
    }

    /**
     * Assert that the delivery-mode chip is absent on the named course's card.
     *
     * The delivery chip uses badge_mapper::OUTLINE_DELIVERY classes
     * ('border border-secondary text-body bg-white'). When delivery_mode is
     * unset, only lifecycle + enrollment badges are rendered (W5 note: a semantic
     * data-badge-type="delivery" attribute on the chip would make this more
     * robust; tracked as tech debt F01).
     *
     * @Then the course card for :coursename does not show a delivery badge
     *
     * @param string $coursename visible course fullname
     */
    public function the_course_card_does_not_show_delivery_badge(string $coursename): void {
        $this->spin(function() use ($coursename) {
            $card           = $this->find_course_card($coursename);
            $badgecontainer = $card->find('css', '.vbs-card-badges');
            if (!$badgecontainer) {
                return true; // No badge container → no delivery badge either.
            }
            foreach ($badgecontainer->findAll('css', 'span.badge') as $span) {
                $classes = $span->getAttribute('class') ?? '';
                if (str_contains($classes, 'border-secondary') && str_contains($classes, 'bg-white')) {
                    throw new ExpectationException(
                        "Course card for '$coursename' should NOT have a delivery badge but one was found.",
                        $this->getSession()
                    );
                }
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
            $actual = array_map(fn($s) => trim($s->getText()),
                $badgecontainer->findAll('css', 'span.badge'));
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
}
