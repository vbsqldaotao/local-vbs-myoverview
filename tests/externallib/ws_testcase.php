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

namespace local_vbs_myoverview\external;

use local_vbs_myoverview\local\customfield_installer;

defined('MOODLE_INTERNAL') || die();

/**
 * Base test case that exercises external functions through the real web-service
 * dispatcher instead of calling {@see enrich_courses::execute()} directly.
 *
 * The point of this fixture (VBS-143) is to catch bugs that only surface on the
 * WS execution path — parameter coercion via {@see external_api::call_external_function()},
 * return-value cleaning against the declared schema, and lazy customfield export —
 * at the PHPUnit tier, ~10x faster than waiting for Behat. The F01 pilot delivery_mode
 * `intvalue = NULL` regression (fix commit fa14c6a) is the motivating example.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class ws_testcase extends \advanced_testcase {

    /**
     * Invoke an external function the way the WS server does: dispatch through
     * {@see external_api::call_external_function()} so parameter coercion and
     * return-value cleaning run exactly as they would over REST/AJAX.
     *
     * @param string $function the registered WS function name (e.g. local_vbs_myoverview_enrich_courses)
     * @param array $params associative array keyed by the declared parameter names
     * @return array the cleaned response payload (result['data'])
     */
    protected function call_ws(string $function, array $params): array {
        // The AJAX web-service dispatch path guards ajax-enabled functions with a
        // sesskey (required_param + confirm_sesskey), exactly as a browser XHR would
        // carry one. Seed a valid sesskey for the current user so the simulation runs
        // the real guarded path instead of erroring out on a missing parameter.
        $_POST['sesskey'] = sesskey();
        $_GET['sesskey'] = $_POST['sesskey'];

        $result = \core_external\external_api::call_external_function($function, $params, false);
        if (!empty($result['error'])) {
            $message = isset($result['exception']->message) ? $result['exception']->message : 'WS call failed';
            // 'generalexceptionmessage' is a core string ("Error: {$a}"); avoids a bogus
            // get_string() lookup while still surfacing the real WS failure in the test output.
            throw new \moodle_exception('generalexceptionmessage', 'error', '', $message);
        }
        return $result['data'];
    }

    /**
     * Provision the delivery_mode course custom field and return its id.
     *
     * @return int the customfield_field id
     */
    protected function ensure_delivery_mode_field(): int {
        global $DB;
        customfield_installer::ensure_delivery_mode_field();
        return (int)$DB->get_field('customfield_field', 'id',
            ['shortname' => customfield_installer::FIELD_SHORTNAME], MUST_EXIST);
    }

    /**
     * Set the delivery_mode value for a course through the customfield API
     * (a data_controller save) — never a direct table insert — so the stored
     * intvalue matches what a real save would write.
     *
     * @param int $courseid course instance id
     * @param int $fieldid customfield_field id for delivery_mode
     * @param string $value canonical option value (online|offline|blended)
     * @return void
     */
    protected function set_delivery_mode(int $courseid, int $fieldid, string $value): void {
        $field = \core_customfield\field_controller::create($fieldid);

        // customfield_select stores the 1-based option index in intvalue (0 = unset),
        // so resolve $value → index before saving through the data controller.
        $options = preg_split('/\r?\n/', trim((string)$field->get_configdata_property('options')));
        $options = array_map('trim', $options ?: []);
        $index = array_search($value, $options, true);
        if ($index === false) {
            throw new \coding_exception("Unknown delivery_mode option: {$value}");
        }
        $optionindex = $index + 1;

        $data = \core_customfield\data_controller::create(0, null, $field);
        $data->set('instanceid', $courseid);
        $data->set('contextid', \context_course::instance($courseid)->id);
        // For select the datafield is intvalue; keep the text `value` column in sync
        // (it is NOT NULL) so the row mirrors what the customfield API persists.
        // Set intvalue last so it wins if the controller re-derives it from `value`.
        $data->set('value', (string)$optionindex);
        $data->set('intvalue', $optionindex);
        $data->save();
    }

    /**
     * Graceful-degradation helper: write a delivery_mode data row with a NULL intvalue
     * straight into customfield_data, reproducing the malformed state a save path could
     * leave behind (e.g. the pre-fix Behat step that set 'value' without 'intvalue',
     * commit 7e4984c). Direct insert is intentional here — the whole point is to smuggle
     * in a value the customfield API would never produce, so the WS read path's null
     * handling is exercised.
     *
     * @param int $courseid course instance id
     * @param int $fieldid customfield_field id for delivery_mode
     * @param \context $ctx the course context
     * @return void
     */
    protected function set_delivery_mode_null_intvalue(int $courseid, int $fieldid, \context $ctx): void {
        global $DB;
        $DB->insert_record('customfield_data', (object)[
            'fieldid'        => $fieldid,
            'instanceid'     => $courseid,
            'intvalue'       => null,
            'decvalue'       => null,
            'shortcharvalue' => null,
            'charvalue'      => null,
            // The malformed state under test is the NULL intvalue; the `value` text
            // column is NOT NULL, so it carries an empty string (as a select would).
            'value'          => '',
            'valueformat'    => 0,
            'timecreated'    => time(),
            'timemodified'   => time(),
            'contextid'      => $ctx->id,
        ]);
    }
}
