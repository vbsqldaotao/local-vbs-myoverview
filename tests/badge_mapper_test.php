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

use local_vbs_myoverview\local\badge_mapper;
use local_vbs_myoverview\local\state_computer;

/**
 * Unit tests for {@see badge_mapper}.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_vbs_myoverview\local\badge_mapper
 */
final class badge_mapper_test extends \advanced_testcase {

    /**
     * All three badges appear in card order (delivery → lifecycle → enrollment).
     *
     * @return void
     */
    public function test_full_badge_set_order_and_classes(): void {
        $badges = badge_mapper::build_badges(
            'online',
            state_computer::LIFECYCLE_IN_PROGRESS,
            state_computer::ENROL_ASSIGNED
        );

        $this->assertCount(3, $badges);
        $this->assertEquals(get_string('delivery_online', 'local_vbs_myoverview'), $badges[0]['label']);
        $this->assertEquals(badge_mapper::OUTLINE_DELIVERY, $badges[0]['classes']);
        // Lifecycle is a colored chip.
        $this->assertStringContainsString('bg-primary', $badges[1]['classes']);
        // Enrollment is an outlined chip.
        $this->assertStringContainsString('border-primary', $badges[2]['classes']);
    }

    /**
     * When delivery mode is absent the badge list has only lifecycle + enrollment.
     *
     * @return void
     */
    public function test_delivery_absent_is_omitted(): void {
        $badges = badge_mapper::build_badges(
            null,
            state_computer::LIFECYCLE_ENDED,
            state_computer::ENROL_ASSIGNED
        );
        $this->assertCount(2, $badges);
        $this->assertEquals(get_string('lifecycle_ended', 'local_vbs_myoverview'), $badges[0]['label']);
    }

    /**
     * An unrecognised delivery mode is dropped (degrade), not rendered.
     *
     * @return void
     */
    public function test_unknown_delivery_is_omitted(): void {
        $badges = badge_mapper::build_badges(
            'hybrid',
            state_computer::LIFECYCLE_COMPLETED,
            state_computer::ENROL_PENDING
        );
        $this->assertCount(2, $badges);
        $this->assertStringContainsString('bg-success', $badges[0]['classes']);
        $this->assertStringContainsString('border-warning', $badges[1]['classes']);
    }

    /**
     * Delivery mode matching is case-insensitive and whitespace-tolerant.
     *
     * @return void
     */
    public function test_delivery_badge_normalisation(): void {
        $badge = badge_mapper::delivery_badge('  BLENDED ');
        $this->assertNotNull($badge);
        $this->assertEquals(get_string('delivery_blended', 'local_vbs_myoverview'), $badge['label']);
    }
}
