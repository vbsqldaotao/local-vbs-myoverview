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
 * Covers:
 * - Data setup: set delivery_mode custom field on a course.
 * - Navigation: visit the My Courses page.
 * - Interaction: search, timeline filter.
 * - Assertions: VBS badge presence/absence, badge order, date range.
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
     * The field stores a 1-based option index (customfield_select convention).
     * Options in configdata: "online\noffline\nblended" → indices 1, 2, 3.
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

        // Determine the 1-based option index from the field's configdata.
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
        $instdata    = $handler->get_instance_data($course->id, true);
        $controller  = null;
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
        $this->wait_for_pending_js();
    }

    // ─────────────────────────────────────────────────────────────
    // Interaction steps — search and filter
    // ─────────────────────────────────────────────────────────────

    /**
     * Type a keyword into the block_myoverview search box and wait for results.
     *
     * The block_myoverview search input has [data-action="filter"] and listens
     * for 'keyup' events to filter the course list dynamically.
     *
     * @When I search courses for :keyword
     *
     * @param string $keyword search term
     */
    public function i_search_courses_for(string $keyword): void {
        $input = $this->find('css', 'input[data-action="filter"], input[placeholder="Search"], input[aria-label="Search courses"]');
        $input->setValue($keyword);
        // Trigger the input event so AMD handlers fire.
        $this->execute_script("
            var el = document.querySelector('input[data-action=\"filter\"], input[placeholder=\"Search\"]');
            if (el) {
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('keyup', { bubbles: true }));
            }
        ");
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
     * Click the block_myoverview timeline filter button that corresponds to the
     * given classification key.
     *
     * Accepted values match the core `classification` parameter of
     * core_course_get_enrolled_courses_by_timeline_classification:
     *   allincludinghidden | inprogress | future | past | hidden | favourites
     *
     * @When I filter courses by :classification
     *
     * @param string $classification core timeline classification key
     */
    public function i_filter_courses_by(string $classification): void {
        // block_myoverview renders filter buttons with data-filter-type or data-value attributes.
        // Try data-value first (Moodle 4.4+), fall back to data-filter-type.
        $script = <<<JS
        (function() {
            var btn = document.querySelector('[data-value="{$classification}"]')
                   || document.querySelector('[data-filter-type="{$classification}"]')
                   || document.querySelector('[data-filtername="{$classification}"]');
            if (btn) { btn.click(); return true; }
            return false;
        })();
        JS;

        $found = $this->evaluate_script($script);
        if (!$found) {
            // Fallback: look for a button/tab whose text matches the display label.
            $labels = [
                'allincludinghidden' => ['All', 'Tất cả'],
                'inprogress'         => ['In progress', 'Đang diễn ra'],
                'future'             => ['Future', 'Sắp diễn ra', 'Chưa bắt đầu'],
                'past'               => ['Past', 'Đã kết thúc'],
                'favourites'         => ['Starred', 'Yêu thích'],
            ];
            $textcandidates = $labels[$classification] ?? [$classification];
            foreach ($textcandidates as $label) {
                try {
                    $btn = $this->find('xpath',
                        "//button[normalize-space(text())='$label'] | //a[normalize-space(text())='$label']");
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
     * Navigate to the next page in the course list (load-more or pagination link).
     *
     * @When I follow the next page in the course list
     */
    public function i_follow_the_next_page_in_the_course_list(): void {
        $btn = $this->find('css', '[data-action="load-more"], .course-list-next-page, a[aria-label="Next page"]');
        $btn->click();
        $this->wait_for_pending_js();
    }

    // ─────────────────────────────────────────────────────────────
    // Assertion steps — VBS presentation contract
    // ─────────────────────────────────────────────────────────────

    /**
     * Assert that a specific VBS badge label is present on the course card for
     * the named course.
     *
     * The VBS badges are injected by myoverview_enricher.js via a WS call after
     * the initial page render; behat_base::spin() retries the assertion until the
     * JS overlay has completed and the badge appears in the DOM.
     *
     * @Then the course card for :coursename shows the vbs badge :label
     *
     * @param string $coursename visible course name (fullname)
     * @param string $label      expected badge text (e.g. "Online", "In progress", "Assigned")
     */
    public function the_course_card_shows_vbs_badge(string $coursename, string $label): void {
        $this->spin(function() use ($coursename, $label) {
            $card = $this->find_course_card($coursename);
            $badgecontainer = $card->find('css', '.vbs-card-badges');
            if (!$badgecontainer) {
                throw new ExpectationException(
                    "Course card for '$coursename' does not have a .vbs-card-badges element yet.",
                    $this->getSession()
                );
            }
            $text = $badgecontainer->getText();
            if (strpos($text, $label) === false) {
                throw new ExpectationException(
                    "Course card for '$coursename' has badges '$text' — expected to contain '$label'.",
                    $this->getSession()
                );
            }
            return true;
        });
    }

    /**
     * Assert that the course card for the named course does NOT show any delivery
     * mode chip (the optional first badge is absent when delivery_mode is unset).
     *
     * @Then the course card for :coursename does not show a delivery badge
     *
     * @param string $coursename visible course name
     */
    public function the_course_card_does_not_show_delivery_badge(string $coursename): void {
        $this->spin(function() use ($coursename) {
            $card = $this->find_course_card($coursename);
            $badgecontainer = $card->find('css', '.vbs-card-badges');
            if (!$badgecontainer) {
                // No badge container at all is acceptable — delivery badge absent.
                return true;
            }
            // Delivery chip must NOT carry the OUTLINE_DELIVERY class combo.
            $outlinecss = 'border border-secondary text-body bg-white';
            $spans = $badgecontainer->findAll('css', 'span.badge');
            foreach ($spans as $span) {
                $classes = $span->getAttribute('class') ?? '';
                // Check for all three classes that form the delivery outline style.
                if (str_contains($classes, 'border-secondary') && str_contains($classes, 'bg-white')) {
                    throw new ExpectationException(
                        "Course card for '$coursename' should NOT have a delivery badge, but one was found.",
                        $this->getSession()
                    );
                }
            }
            return true;
        });
    }

    /**
     * Assert that the course card for the named course shows a date range element
     * (.vbs-card-dates) with non-empty text.
     *
     * @Then the course card for :coursename shows a date range
     *
     * @param string $coursename visible course name
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
     * Assert that the course card for the named course does NOT show a date range.
     *
     * @Then the course card for :coursename does not show a date range
     *
     * @param string $coursename visible course name
     */
    public function the_course_card_does_not_show_date_range(string $coursename): void {
        $this->spin(function() use ($coursename) {
            $card      = $this->find_course_card($coursename);
            $daterange = $card->find('css', '.vbs-card-dates');
            if ($daterange && trim($daterange->getText()) !== '') {
                throw new ExpectationException(
                    "Course card for '$coursename' should NOT show a date range, but '.vbs-card-dates' contains: "
                    . $daterange->getText(),
                    $this->getSession()
                );
            }
            return true;
        });
    }

    /**
     * Assert that the VBS badges on the course card appear in the exact CSV order
     * given: "label1, label2, label3".
     *
     * @Then the course card for :coursename has badge order :csvlabels
     *
     * @param string $coursename visible course name
     * @param string $csvlabels  comma-separated expected badge labels in order
     */
    public function the_course_card_has_badge_order(string $coursename, string $csvlabels): void {
        $expected = array_map('trim', explode(',', $csvlabels));

        $this->spin(function() use ($coursename, $expected) {
            $card            = $this->find_course_card($coursename);
            $badgecontainer  = $card->find('css', '.vbs-card-badges');
            if (!$badgecontainer) {
                throw new ExpectationException(
                    "Course card for '$coursename' has no .vbs-card-badges yet.",
                    $this->getSession()
                );
            }
            $spans  = $badgecontainer->findAll('css', 'span.badge');
            $actual = array_map(fn($s) => trim($s->getText()), $spans);

            if ($actual !== $expected) {
                throw new ExpectationException(
                    "Badge order for '$coursename' expected [" . implode(', ', $expected)
                    . "] but got [" . implode(', ', $actual) . "].",
                    $this->getSession()
                );
            }
            return true;
        });
    }

    /**
     * Assert that the active timeline filter is the given classification key.
     *
     * block_myoverview marks the active filter button with an `active` CSS class.
     *
     * @Then the active filter is still :classification
     *
     * @param string $classification timeline classification key
     */
    public function the_active_filter_is_still(string $classification): void {
        $this->spin(function() use ($classification) {
            $active = $this->find('css',
                "[data-value=\"{$classification}\"].active, "
                . "[data-filter-type=\"{$classification}\"].active, "
                . ".active[data-value=\"{$classification}\"]");
            if (!$active) {
                throw new ExpectationException(
                    "Expected active filter '$classification' not found.",
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
     * Find the course card DOM element for a course by its fullname.
     *
     * block_myoverview renders cards inside [data-region="course-content"]; each
     * card contains the course name as an .aalink.coursename link.
     *
     * @param string $coursename visible course fullname
     * @return \Behat\Mink\Element\NodeElement
     * @throws ExpectationException if no matching card is found
     */
    protected function find_course_card(string $coursename): \Behat\Mink\Element\NodeElement {
        // XPath: find [data-region="course-content"] whose descendant anchor text = $coursename.
        $xpath = "//*[@data-region='course-content'][.//*[contains(@class,'coursename') "
               . "and normalize-space(.)='" . addslashes($coursename) . "']]";
        try {
            return $this->find('xpath', $xpath);
        } catch (ElementNotFoundException $e) {
            throw new ExpectationException(
                "No course card found for '$coursename'. Is the course visible on this page?",
                $this->getSession()
            );
        }
    }

    /**
     * Wait for all pending JavaScript (AMD modules, fetch calls) to complete.
     *
     * Delegates to the standard Moodle/Behat JS idle check.
     */
    protected function wait_for_pending_js(): void {
        if ($this->running_javascript()) {
            $this->getSession()->wait(8000, '(typeof M === "undefined" || typeof M.util === "undefined" || M.util.pending_js && M.util.pending_js.length === 0)');
        }
    }
}
