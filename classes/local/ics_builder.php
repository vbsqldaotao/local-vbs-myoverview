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
 * Builds an iCalendar (RFC 5545) feed from a list of unified schedule sessions.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ics_builder {

    /**
     * Build and return an ICS string for the given sessions.
     *
     * Timestamps are emitted as UTC (Z suffix) regardless of the server timezone,
     * which is the safest interoperable choice for multi-TZ learners.
     *
     * @param array[] $sessions Unified session records from schedule_aggregator::get_sessions().
     * @param string  $prodid   PRODID value (default: VBS schedule).
     * @return string RFC 5545 iCalendar content.
     */
    public function build(array $sessions, string $prodid = '-//VBS//Schedule Tracking//EN'): string {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:' . $this->escape_text($prodid),
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        foreach ($sessions as $session) {
            $lines = array_merge($lines, $this->build_vevent($session));
        }

        $lines[] = 'END:VCALENDAR';

        // RFC 5545 §3.1: lines MUST end with CRLF.
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Build VEVENT lines for a single session.
     *
     * @param array $session
     * @return string[]
     */
    protected function build_vevent(array $session): array {
        $dtstart = $session['timestart'] > 0
            ? gmdate('Ymd\THis\Z', (int)$session['timestart'])
            : gmdate('Ymd\THis\Z');

        $dtend = $session['timefinish'] > 0
            ? gmdate('Ymd\THis\Z', (int)$session['timefinish'])
            : gmdate('Ymd\THis\Z', (int)$session['timestart'] + 3600);

        $uid = $this->escape_text($session['id']) . '@vbs.local';

        $lines = [
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $dtstart,
            'DTEND:' . $dtend,
            'SUMMARY:' . $this->fold($this->escape_text(strip_tags($session['title']))),
        ];

        if (!empty($session['location'])) {
            $lines[] = 'LOCATION:' . $this->fold($this->escape_text(strip_tags($session['location'])));
        }
        if (!empty($session['description'])) {
            $lines[] = 'DESCRIPTION:' . $this->fold($this->escape_text(strip_tags($session['description'])));
        }
        if (!empty($session['course_name'])) {
            $lines[] = 'CATEGORIES:' . $this->escape_text(strip_tags($session['course_name']));
        }
        if (!empty($session['instructor'])) {
            $lines[] = 'ORGANIZER;CN=' . $this->escape_text(strip_tags($session['instructor'])) . ':MAILTO:noreply@vbs.local';
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * Escape text values per RFC 5545 §3.3.11.
     *
     * @param string $text
     * @return string
     */
    protected function escape_text(string $text): string {
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace(';', '\\;', $text);
        $text = str_replace(',', '\\,', $text);
        $text = str_replace("\n", '\\n', $text);
        $text = str_replace("\r", '', $text);
        return $text;
    }

    /**
     * Fold long content lines at ~75 octets per RFC 5545 §3.1, respecting UTF-8 boundaries.
     *
     * str_split() counts bytes, which would split multi-byte sequences (e.g. Vietnamese
     * characters are 2-3 bytes each) producing invalid UTF-8. We retreat from byte 74
     * until we are no longer inside a UTF-8 continuation byte (0x80–0xBF).
     *
     * @param string $value Already-escaped property value (UTF-8).
     * @return string Value with embedded CRLF+space folds if needed.
     */
    protected function fold(string $value): string {
        if (strlen($value) <= 75) {
            return $value;
        }
        $out = [];
        $len = strlen($value);
        $i = 0;
        while ($i < $len) {
            $take = min(74, $len - $i);
            // Retreat until we are not pointing at a UTF-8 continuation byte (10xxxxxx).
            while ($take > 0 && (ord($value[$i + $take]) & 0xC0) === 0x80) {
                $take--;
            }
            if ($take === 0) {
                $take = 1; // Prevent infinite loop on malformed input.
            }
            $out[] = substr($value, $i, $take);
            $i += $take;
        }
        return implode("\r\n ", $out);
    }
}
