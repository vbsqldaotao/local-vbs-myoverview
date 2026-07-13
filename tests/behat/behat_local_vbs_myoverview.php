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
 * Behat step definitions for local_vbs_myoverview.
 *
 * Shared by:
 *   - theme_vbs/tests/behat/vbs_f01_course_badges.feature (D6/D7 badges)
 *   - theme_vbs/tests/behat/vbs_f01_catalog_register.feature (F01 catalog CTA + Empty State A)
 *   - theme_vbs/tests/behat/vbs_f01_search_noresult.feature (§5.3 search no-result)
 *
 * Moodle Behat autoloads this file whenever local_vbs_myoverview is installed
 * in the same Moodle tree as theme_vbs (arch-reviewer VBS-131 note 3).
 *
 * @package    local_vbs_myoverview
 * @category   test
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use Behat\Mink\Exception\ExpectationException;

// phpcs:disable moodle.NamingConventions.ValidFunctionName.LowercaseMethod

/**
 * @package local_vbs_myoverview
 */
class behat_local_vbs_myoverview extends behat_base {

    /** @var int ms to wait for the VBS enrichment overlay to finish injecting badges. */
    const ENRICHMENT_TIMEOUT_MS = 4000;

    // ─────────────────────────────────────────────────────────────────
    // Navigation
    // ─────────────────────────────────────────────────────────────────

    /**
     * Navigate to the student Dashboard (block_myoverview) and wait for
     * the VBS badge enricher overlay to finish injecting badges.
     *
     * @When /^I am on the VBS course list page$/
     */
    public function i_am_on_the_vbs_course_list_page(): void {
        $this->getSession()->visit($this->locate_path('/my/'));
        // Standard page-ready wait.
        $this->getSession()->wait(
            behat_base::get_extended_timeout() * 1000,
            '(document.readyState === "complete") && (typeof M !== "undefined")'
        );
        // Wait until at least one card carries data-vbs-enriched="done" (the
        // enricher's completion flag) or the block has no cards at all.
        $this->getSession()->wait(
            self::ENRICHMENT_TIMEOUT_MS,
            '(function() {
                var view = document.querySelector("[data-region=\"courses-view\"]");
                if (!view) { return false; }
                var cards = view.querySelectorAll("[data-region=\"course-content\"]");
                if (!cards.length) { return true; }
                for (var i = 0; i < cards.length; i++) {
                    if (cards[i].dataset.vbsEnriched === "done") { return true; }
                }
                return false;
            })()'
        );
    }

    /**
     * Navigate to /course/index.php and wait for the catalog_register overlay.
     *
     * Uses the root catalog so all courses are visible regardless of category
     * (the VBS course_renderer forces EXPANDED mode — VBS-333/VBS-368).
     *
     * @When /^I am on the course catalog page listing "([^"]*)"$/
     * @param string $shortname Course shortname (used only to confirm the box exists).
     */
    public function i_am_on_the_course_catalog_page_listing(string $shortname): void {
        $this->getSession()->visit($this->locate_path('/course/index.php'));
        $this->getSession()->wait(
            behat_base::get_extended_timeout() * 1000,
            '(document.readyState === "complete") && (typeof M !== "undefined")'
        );
        // Wait for course boxes to appear.
        $this->getSession()->wait(
            3000,
            'document.querySelector(".coursebox[data-courseid]") !== null'
        );
        // Give the catalog_register AJAX overlay time to decorate boxes.
        // The overlay calls local_vbs_enrol_get_courses; 3 s is enough for local Moodle.
        $this->getSession()->wait(
            3000,
            'document.querySelector("[data-region=\"vbs-catalog-cta\"]") !== null ||
             document.querySelector(".coursebox[data-courseid]") !== null'
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // VBS delta D6 — 2-D state badge assertions (vbs_f01_course_badges.feature)
    // ─────────────────────────────────────────────────────────────────

    /**
     * @Then /^the course card for "([^"]*)" shows the vbs badge "([^"]*)"$/
     */
    public function the_course_card_shows_the_vbs_badge(string $coursename, string $badgelabel): void {
        $card = $this->find_course_card($coursename);
        $badgerow = $card->find('css', '.vbs-card-badges');
        if (!$badgerow) {
            throw new ExpectationException(
                "No .vbs-card-badges found on card for '$coursename' — enrichment may not have run",
                $this->getSession()
            );
        }
        foreach ($badgerow->findAll('css', '.badge') as $badge) {
            if (trim($badge->getText()) === $badgelabel) {
                return;
            }
        }
        $found = implode(', ', array_map(
            static fn($b) => trim($b->getText()),
            $badgerow->findAll('css', '.badge')
        ));
        throw new ExpectationException(
            "Badge '$badgelabel' not found on card for '$coursename'. Found: [$found]",
            $this->getSession()
        );
    }

    /**
     * Assert no delivery badge (data-badge-type="delivery") on the card.
     *
     * Relies on the data-badge-type attribute set by the enricher when the backend
     * supplies a type value (fixed in VBS-378 — badge_mapper now includes type).
     *
     * @Then /^the course card for "([^"]*)" does not show a delivery badge$/
     */
    public function the_course_card_does_not_show_delivery_badge(string $coursename): void {
        $card = $this->find_course_card($coursename);
        $badge = $card->find('css', '.vbs-card-badges .badge[data-badge-type="delivery"]');
        if ($badge) {
            throw new ExpectationException(
                "Delivery badge unexpectedly found on card for '$coursename'",
                $this->getSession()
            );
        }
    }

    /**
     * @Then /^the course card for "([^"]*)" shows a date range$/
     */
    public function the_course_card_shows_a_date_range(string $coursename): void {
        $card = $this->find_course_card($coursename);
        $dates = $card->find('css', '.vbs-card-dates');
        if (!$dates || empty(trim($dates->getText()))) {
            throw new ExpectationException(
                "Date range not found on card for '$coursename'",
                $this->getSession()
            );
        }
    }

    /**
     * @Then /^the course card for "([^"]*)" does not show a date range$/
     */
    public function the_course_card_does_not_show_date_range(string $coursename): void {
        $card = $this->find_course_card($coursename);
        $dates = $card->find('css', '.vbs-card-dates');
        if ($dates) {
            throw new ExpectationException(
                "Date range unexpectedly found on card for '$coursename'",
                $this->getSession()
            );
        }
    }

    /**
     * Assert that .vbs-card-badges contains exactly the given comma-separated labels
     * in the given order.
     *
     * @Then /^the course card for "([^"]*)" has badge order "([^"]*)"$/
     */
    public function the_course_card_has_badge_order(string $coursename, string $expectedorder): void {
        $card = $this->find_course_card($coursename);
        $badges = $card->findAll('css', '.vbs-card-badges .badge');
        $actual = array_map(static fn($b) => trim($b->getText()), $badges);
        $expected = array_map('trim', explode(',', $expectedorder));
        if ($actual !== $expected) {
            throw new ExpectationException(
                sprintf(
                    "Badge order mismatch on card for '%s': expected [%s] got [%s]",
                    $coursename,
                    implode(', ', $expected),
                    implode(', ', $actual)
                ),
                $this->getSession()
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // F01 half (b) — catalog CTA + Empty State A (vbs_f01_catalog_register.feature)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Enable Moodle's self-enrolment method on a course so catalog_register.js
     * surfaces it as "open for registration".
     *
     * @Given /^the course "([^"]*)" is open for self enrolment$/
     */
    public function the_course_is_open_for_self_enrolment(string $shortname): void {
        global $DB;
        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
        $existing = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'self']);
        if ($existing) {
            $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['id' => $existing->id]);
        } else {
            $plugin = enrol_get_plugin('self');
            $plugin->add_instance($course, ['status' => ENROL_INSTANCE_ENABLED]);
        }
    }

    /**
     * @Then /^the catalog course box for "([^"]*)" shows a register button$/
     */
    public function the_catalog_course_box_shows_a_register_button(string $coursename): void {
        $box = $this->find_catalog_course_box($coursename);
        $btn = $box->find('css', '[data-testid="register-btn"]');
        if (!$btn) {
            throw new ExpectationException(
                "Register button not found for catalog course '$coursename'",
                $this->getSession()
            );
        }
    }

    /**
     * @Then /^the catalog course box for "([^"]*)" does not show a register button$/
     */
    public function the_catalog_course_box_does_not_show_a_register_button(string $coursename): void {
        $box = $this->find_catalog_course_box($coursename);
        $btn = $box->find('css', '[data-testid="register-btn"]');
        if ($btn) {
            throw new ExpectationException(
                "Register button unexpectedly found for catalog course '$coursename'",
                $this->getSession()
            );
        }
    }

    /**
     * @Then /^I should see the empty-state catalog banner$/
     */
    public function i_should_see_the_empty_state_catalog_banner(): void {
        // The emptystate JS injects [data-region="vbs-emptystate-a"] into the DOM.
        $this->getSession()->wait(3000, 'document.querySelector("[data-region=\"vbs-emptystate-a\"]") !== null');
        $banner = $this->getSession()->getPage()->find('css', '[data-region="vbs-emptystate-a"]');
        if (!$banner || !$banner->isVisible()) {
            throw new ExpectationException(
                'Empty-state catalog banner not visible',
                $this->getSession()
            );
        }
    }

    /**
     * @Then /^I should not see the empty-state catalog banner$/
     */
    public function i_should_not_see_the_empty_state_catalog_banner(): void {
        $banner = $this->getSession()->getPage()->find('css', '[data-region="vbs-emptystate-a"]');
        if ($banner && $banner->isVisible()) {
            throw new ExpectationException(
                'Empty-state catalog banner unexpectedly visible',
                $this->getSession()
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // §5.3 — search no-result overlay (vbs_f01_search_noresult.feature)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Type into block_myoverview's search input and trigger the input event so
     * the block re-fetches and the emptystate overlay evaluates.
     *
     * @When /^I search courses for "([^"]*)"$/
     */
    public function i_search_courses_for(string $query): void {
        $input = $this->getSession()->getPage()->find('css', '[data-action="search"]');
        if (!$input) {
            throw new ExpectationException(
                'block_myoverview search input [data-action="search"] not found',
                $this->getSession()
            );
        }
        $input->setValue($query);
        // Fire the input event — block_myoverview listens with a debouncer.
        $this->getSession()->executeScript(
            'var el = document.querySelector(\'[data-action="search"]\');
             if (el) { el.dispatchEvent(new Event("input", {bubbles: true})); }'
        );
        // Wait for block to re-render and emptystate overlay to evaluate.
        $this->getSession()->wait(3000, 'typeof M !== "undefined"');
    }

    /**
     * The emptystate overlay sets data-vbs-search-empty="done" on the empty-message
     * wrapper when it rewrites the no-result text (§5.3).
     *
     * @Then /^I should see the course search no-result message$/
     */
    public function i_should_see_the_course_search_no_result_message(): void {
        $this->getSession()->wait(
            3000,
            'document.querySelector("[data-vbs-search-empty=\"done\"]") !== null'
        );
        $el = $this->getSession()->getPage()->find('css', '[data-vbs-search-empty="done"]');
        if (!$el) {
            throw new ExpectationException(
                'Search no-result message (data-vbs-search-empty="done") not found',
                $this->getSession()
            );
        }
    }

    /**
     * @Then /^I should not see the course search no-result message$/
     */
    public function i_should_not_see_the_course_search_no_result_message(): void {
        $el = $this->getSession()->getPage()->find('css', '[data-vbs-search-empty="done"]');
        if ($el) {
            throw new ExpectationException(
                'Search no-result message unexpectedly present',
                $this->getSession()
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Find a block_myoverview course card by course name.
     *
     * @param string $coursename Full course name as shown in the card.
     * @return \Behat\Mink\Element\NodeElement
     * @throws ExpectationException when the card is not found.
     */
    private function find_course_card(string $coursename): \Behat\Mink\Element\NodeElement {
        $page = $this->getSession()->getPage();
        foreach ($page->findAll('css', '[data-region="course-content"]') as $card) {
            $link = $card->find('css', '.coursename');
            if ($link && str_contains($link->getText(), $coursename)) {
                return $card;
            }
        }
        throw new ExpectationException(
            "Course card for '$coursename' not found in block_myoverview",
            $this->getSession()
        );
    }

    /**
     * Find a /course/index.php course box by course name.
     *
     * @param string $coursename
     * @return \Behat\Mink\Element\NodeElement
     * @throws ExpectationException
     */
    private function find_catalog_course_box(string $coursename): \Behat\Mink\Element\NodeElement {
        $page = $this->getSession()->getPage();
        foreach ($page->findAll('css', '.coursebox[data-courseid]') as $box) {
            $nameel = $box->find('css', '.coursename a, h3.coursename');
            if ($nameel && str_contains($nameel->getText(), $coursename)) {
                return $box;
            }
        }
        throw new ExpectationException(
            "Catalog course box for '$coursename' not found on /course/index.php",
            $this->getSession()
        );
    }
}
