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

namespace local_vbs_myoverview\local;

/**
 * Maps computed states to the {label, classes} badge model consumed by the
 * frozen coursecard presentation contract (VBS-132, theme_vbs override).
 *
 * Card badge order (spec §5.4 / wireframe §5.1): delivery (hình thức, outlined,
 * optional) → lifecycle (colored) → enrollment (outlined). Colours come from
 * Bootstrap variant classes themed via $primary — no hex is emitted here.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class badge_mapper {

    /** @var string Outlined chip used for the optional delivery-mode badge. */
    const OUTLINE_DELIVERY = 'border border-secondary text-body bg-white';

    /** @var string[] Canonical delivery-mode values that yield a badge. */
    const DELIVERY_MODES = ['online', 'offline', 'blended'];

    /**
     * Build the ordered badge list for one course card.
     *
     * @param string|null $deliverymode canonical delivery mode or null/unknown (badge omitted)
     * @param string $lifecyclestate one of state_computer::LIFECYCLE_*
     * @param string $enrollmentstate one of state_computer::ENROL_*
     * @return array[] list of ['label' => string, 'classes' => string] in card order
     */
    public static function build_badges(?string $deliverymode, string $lifecyclestate, string $enrollmentstate): array {
        $badges = [];
        $delivery = self::delivery_badge($deliverymode);
        if ($delivery !== null) {
            $badges[] = $delivery;
        }
        $badges[] = self::lifecycle_badge($lifecyclestate);
        $badges[] = self::enrollment_badge($enrollmentstate);
        return $badges;
    }

    /**
     * Optional delivery-mode (hình thức) badge — outlined, omitted when unknown.
     *
     * @param string|null $mode raw delivery mode value
     * @return array|null ['label' => string, 'classes' => string] or null
     */
    public static function delivery_badge(?string $mode): ?array {
        if ($mode === null || $mode === '') {
            return null;
        }
        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::DELIVERY_MODES, true)) {
            return null;
        }
        return [
            'label' => get_string('delivery_' . $mode, 'local_vbs_myoverview'),
            'classes' => self::OUTLINE_DELIVERY,
            'type' => 'delivery',
        ];
    }

    /**
     * Lifecycle badge — colored chip.
     *
     * @param string $state one of state_computer::LIFECYCLE_*
     * @return array ['label' => string, 'classes' => string]
     */
    public static function lifecycle_badge(string $state): array {
        return [
            'label' => get_string('lifecycle_' . $state, 'local_vbs_myoverview'),
            'classes' => self::lifecycle_classes($state),
            'type' => 'lifecycle',
        ];
    }

    /**
     * Enrollment badge — outlined chip.
     *
     * @param string $state one of state_computer::ENROL_*
     * @return array ['label' => string, 'classes' => string]
     */
    public static function enrollment_badge(string $state): array {
        return [
            'label' => get_string('enrollment_' . $state, 'local_vbs_myoverview'),
            'classes' => self::enrollment_classes($state),
            'type' => 'enrollment',
        ];
    }

    /**
     * Bootstrap variant classes for a lifecycle state (colored).
     *
     * @param string $state lifecycle state
     * @return string
     */
    protected static function lifecycle_classes(string $state): string {
        switch ($state) {
            case state_computer::LIFECYCLE_IN_PROGRESS:
                return 'bg-primary text-white';
            case state_computer::LIFECYCLE_COMPLETED:
                return 'bg-success text-white';
            case state_computer::LIFECYCLE_ENDED:
                return 'bg-secondary text-white';
            case state_computer::LIFECYCLE_NOT_STARTED:
            default:
                return 'bg-info text-white';
        }
    }

    /**
     * Bootstrap variant classes for an enrollment state (outlined).
     *
     * @param string $state enrollment state
     * @return string
     */
    protected static function enrollment_classes(string $state): string {
        switch ($state) {
            case state_computer::ENROL_PENDING:
                return 'border border-warning text-warning bg-white';
            case state_computer::ENROL_OPEN:
                return 'border border-success text-success bg-white';
            case state_computer::ENROL_ASSIGNED:
            default:
                return 'border border-primary text-primary bg-white';
        }
    }
}
