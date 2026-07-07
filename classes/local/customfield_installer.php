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

use core_customfield\api;
use core_customfield\category_controller;
use core_customfield\field_controller;

/**
 * Idempotently provisions the `delivery_mode` course custom field (menu:
 * online/offline/blended) under a VBS category. Called from install/upgrade.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class customfield_installer {

    /** @var string Shortname of the course custom field. */
    const FIELD_SHORTNAME = 'delivery_mode';

    /**
     * Create the delivery_mode course custom field if it does not exist.
     *
     * Safe to call repeatedly — returns early when the field is already present.
     *
     * @return void
     */
    public static function ensure_delivery_mode_field(): void {
        $handler = \core_course\customfield\course_handler::create();

        // Already provisioned? Custom-field shortnames are unique within the course area.
        foreach ($handler->get_fields() as $field) {
            if ($field->get('shortname') === self::FIELD_SHORTNAME) {
                return;
            }
        }

        // Find or create the VBS category.
        $categoryid = null;
        $categoryname = get_string('customfieldcategory', 'local_vbs_myoverview');
        foreach ($handler->get_categories_with_fields() as $category) {
            if ($category->get('name') === $categoryname) {
                $categoryid = $category->get('id');
                break;
            }
        }
        if ($categoryid === null) {
            $categoryid = $handler->create_category($categoryname);
        }

        $category = category_controller::create($categoryid);
        $field = field_controller::create(0, (object)['type' => 'select'], $category);

        $record = (object)[
            'name' => get_string('deliverymode', 'local_vbs_myoverview'),
            'shortname' => self::FIELD_SHORTNAME,
            'type' => 'select',
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'configdata' => [
                'options' => "\nonline\noffline\nblended",
                'defaultvalue' => '',
                'required' => 0,
                'uniquevalues' => 0,
                'locked' => 0,
                'visibility' => \core_course\customfield\course_handler::VISIBLETOALL,
            ],
        ];

        api::save_field_configuration($field, $record);
    }
}
