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

namespace local_vbs_myoverview\tests\external;

use core_customfield\data_controller;
use core_customfield\field_controller;
use core_external\external_api;
use local_vbs_myoverview\local\customfield_installer;

/**
 * Base test case that exercises external functions through the real web-service
 * dispatch path (`external_api::call_external_function`) rather than calling
 * `execute()` directly.
 *
 * F01 pilot surfaced an autoload/customfield bug (commit fa14c6a) that PHPUnit
 * missed because the plain unit tests bypassed WS parameter parsing and the
 * customfield read path. Routing through `call_external_function` reproduces the
 * WS execution context — parameter coercion, return-value cleaning and the
 * exception envelope — so such regressions get caught at the PHPUnit tier
 * (~10x faster than Behat). See VBS-131 retrospective and VBS-143.
 *
 * Placed under `tests/classes/` (namespace `\tests\external`) so Moodle's test
 * class autoloader can resolve it as a shared helper.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class ws_testcase extends \advanced_testcase {

    /**
     * Invoke an external function the same way the WS server does.
     *
     * Params are passed as a named array and dispatched through
     * {@see external_api::call_external_function()} (skipclean = false), so the
     * declared parameter types coerce the input (e.g. string course ids become
     * ints) and the declared return structure cleans the output. A WS-level
     * failure is re-thrown as a moodle_exception carrying the original message.
     *
     * @param string $function external function name (as registered in db/services.php)
     * @param array $params named arguments for the function
     * @return array the cleaned return payload
     */
    protected function call_ws(string $function, array $params): array {
        // The WS dispatcher requires a valid sesskey for a logged-in caller
        // (require_sesskey inside call_external_function); the browser AJAX layer
        // sends it, so inject it here to reproduce that context.
        $_POST['sesskey'] = sesskey();

        $result = external_api::call_external_function($function, $params, false);
        if (!empty($result['error'])) {
            $exception = $result['exception'] ?? null;
            $message = 'WS call failed';
            if (is_object($exception) && !empty($exception->message)) {
                $message = (string)$exception->message;
            } else if ($exception instanceof \Throwable) {
                $message = $exception->getMessage();
            }
            throw new \moodle_exception('error', 'moodle', '', null, $message);
        }
        return $result['data'];
    }

    /**
     * Provision the delivery_mode course custom field (idempotent) and return its id.
     *
     * @return int the customfield_field id of the delivery_mode select field
     */
    protected function ensure_delivery_mode_field(): int {
        global $DB;
        customfield_installer::ensure_delivery_mode_field();
        return (int)$DB->get_field('customfield_field', 'id',
            ['shortname' => customfield_installer::FIELD_SHORTNAME], MUST_EXIST);
    }

    /**
     * Set the delivery_mode value for a course through the customfield API.
     *
     * The select field stores the 1-based option index in `intvalue`; this
     * resolves the canonical text (online/offline/blended) to that index and
     * persists it via the data controller — never a raw DB insert — so the
     * stored shape matches what a real form save produces.
     *
     * @param int $courseid target course id
     * @param int $fieldid delivery_mode customfield_field id
     * @param string $value canonical delivery mode (online|offline|blended)
     * @return void
     */
    protected function set_delivery_mode(int $courseid, int $fieldid, string $value): void {
        $field = field_controller::create($fieldid);
        $options = $this->delivery_mode_options($field);
        $index = array_search($value, $options, true);
        if ($index === false) {
            throw new \coding_exception("Unknown delivery_mode option '{$value}'");
        }

        $data = data_controller::create(0, null, $field);
        $data->set('instanceid', $courseid);
        $data->set('contextid', \context_course::instance($courseid)->id);
        // customfield_select keeps the option index in both intvalue and value.
        $data->set($data->datafield(), $index + 1);
        $data->set('value', $index + 1);
        $data->save();
    }

    /**
     * Insert a delivery_mode data row with a NULL intvalue directly into the DB.
     *
     * Regression guard for the F01 pilot bug (commit fa14c6a): a customfield_data
     * row can legitimately exist with `intvalue = NULL` (e.g. a value that was
     * cleared), and reading it through the WS path must degrade gracefully
     * instead of throwing. Only this fixture needs the raw insert — production
     * code never writes a null-intvalue select row on purpose.
     *
     * @param int $courseid target course id
     * @param int $fieldid delivery_mode customfield_field id
     * @param \context $ctx the course context the data row belongs to
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
            // `value` is NOT NULL in the schema; the regression is specifically a
            // NULL `intvalue` on a select field, so keep value as the empty string.
            'value'          => '',
            'valueformat'    => 0,
            'timecreated'    => time(),
            'timemodified'   => time(),
            'contextid'      => $ctx->id,
        ]);
    }

    /**
     * Canonical (0-based, trimmed, non-empty) delivery_mode option list.
     *
     * @param field_controller $field the delivery_mode field controller
     * @return string[] option texts in declaration order
     */
    private function delivery_mode_options(field_controller $field): array {
        $raw = (string)$field->get_configdata_property('options');
        $options = preg_split('/\r\n|\r|\n/', $raw);
        $options = array_map('trim', $options);
        return array_values(array_filter($options, static function (string $o): bool {
            return $o !== '';
        }));
    }
}
