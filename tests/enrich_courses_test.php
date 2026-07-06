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

namespace local_vbs_myoverview;

use core_external\external_api;
use local_vbs_myoverview\external\enrich_courses;
use local_vbs_myoverview\local\badge_mapper;

/**
 * Tests for the enrich_courses external function.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_vbs_myoverview\external\enrich_courses
 */
final class enrich_courses_test extends \advanced_testcase {

    /**
     * Call the external function and clean the return value like the WS layer does.
     *
     * @param int[] $courseids
     * @return array
     */
    protected function call(array $courseids): array {
        $raw = enrich_courses::execute($courseids);
        return external_api::clean_returnvalue(enrich_courses::execute_returns(), $raw);
    }

    /**
     * An enrolled course is enriched with badges, a date range and an empty CTA.
     *
     * @return void
     */
    public function test_enriches_enrolled_course(): void {
        $this->resetAfterTest();
        $now = time();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['startdate' => $now - DAYSECS, 'enddate' => $now + DAYSECS]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $result = $this->call([$course->id]);

        $this->assertCount(1, $result);
        $this->assertEquals($course->id, $result[0]['courseid']);
        $this->assertNotEmpty($result[0]['vbsbadges']);
        $this->assertNotEmpty($result[0]['vbsdaterange']);
        // Pilot: register CTA is always empty (open_for_registration deferred).
        $this->assertSame('', $result[0]['vbsregisterurl']);

        // Round-trip the semantic data-badge-type anchor through the WS return
        // structure: because $result came through clean_returnvalue(execute_returns()),
        // a present 'type' key proves execute_returns declares it AND badge_mapper
        // emits it — a mismatch between the two would fail here at the PHP layer,
        // before Behat. This course has no delivery_mode, so only lifecycle +
        // enrollment badges are present.
        $this->assertArrayHasKey('type', $result[0]['vbsbadges'][0]);
        $types = array_column($result[0]['vbsbadges'], 'type');
        $this->assertContains(badge_mapper::TYPE_LIFECYCLE, $types);
        $this->assertContains(badge_mapper::TYPE_ENROLLMENT, $types);
    }

    /**
     * A course the user is not enrolled in is silently skipped.
     *
     * @return void
     */
    public function test_skips_unenrolled_course(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $user = $gen->create_user();
        $this->setUser($user);

        $result = $this->call([$course->id]);

        $this->assertSame([], $result);
    }

    /**
     * With no delivery_mode value the card still gets lifecycle + enrollment
     * badges (delivery chip omitted — graceful degrade per pilot constraint).
     *
     * @return void
     */
    public function test_enrichment_without_delivery_mode(): void {
        $this->resetAfterTest();
        $now = time();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['startdate' => $now - DAYSECS, 'enddate' => $now + DAYSECS]);
        $user = $gen->create_user();
        $gen->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $result = $this->call([$course->id]);

        $this->assertCount(1, $result);
        // Lifecycle + enrollment always present; delivery omitted without data.
        $this->assertCount(2, $result[0]['vbsbadges']);
        $labels = array_column($result[0]['vbsbadges'], 'label');
        $this->assertContains(get_string('lifecycle_in_progress', 'local_vbs_myoverview'), $labels);
        $this->assertContains(get_string('enrollment_assigned', 'local_vbs_myoverview'), $labels);
    }

    /**
     * The delivery_mode custom field can be provisioned idempotently.
     *
     * @return void
     */
    public function test_customfield_installer_is_idempotent(): void {
        global $DB;
        $this->resetAfterTest();

        \local_vbs_myoverview\local\customfield_installer::ensure_delivery_mode_field();
        \local_vbs_myoverview\local\customfield_installer::ensure_delivery_mode_field();

        $count = $DB->count_records('customfield_field', [
            'shortname' => \local_vbs_myoverview\local\customfield_installer::FIELD_SHORTNAME,
        ]);
        $this->assertEquals(1, $count);
    }
}
