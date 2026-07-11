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
use local_vbs_myoverview\external\get_schedule;

/**
 * Performance test for get_schedule with 1000+ sessions (AC7 of VBS-255 F04).
 *
 * Seeds the test DB with 1000+ sessions (quiz, and facetoface when available),
 * calls local_vbs_myoverview_get_schedule, and asserts that all sessions are
 * returned within an acceptable response-time threshold.
 *
 * @package    local_vbs_myoverview
 * @copyright  2026 VBS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_vbs_myoverview\external\get_schedule
 * @covers     \local_vbs_myoverview\local\schedule_aggregator
 */
final class get_schedule_performance_test extends \advanced_testcase {

    /** Acceptable ceiling for get_schedule response time in seconds (AC7). */
    const MAX_RESPONSE_TIME_SECONDS = 2.0;

    /**
     * Bulk-insert quiz rows directly into the quiz table.
     *
     * Using direct DB inserts (instead of create_module) avoids the per-module
     * overhead of course_module, context, and grade records so that data seeding
     * stays fast and only the WS query time is being measured.
     *
     * @param int[] $courseids Course ids to spread quizzes across.
     * @param int   $per_course Number of quizzes to create per course.
     * @param int   $base_time  Unix timestamp for timeopen of the first quiz.
     * @return int  Total number of quiz records inserted.
     */
    private function bulk_insert_quizzes(array $courseids, int $per_course, int $base_time): int {
        global $DB;

        $records = [];
        $offset  = 0;

        foreach ($courseids as $courseid) {
            for ($i = 0; $i < $per_course; $i++) {
                $records[] = [
                    'course'                 => $courseid,
                    'name'                   => 'Perf Quiz ' . $offset,
                    'intro'                  => '',
                    'introformat'            => 0,
                    'timeopen'               => $base_time + $offset * 60,
                    'timeclose'              => $base_time + $offset * 60 + 3600,
                    'timelimit'              => 0,
                    'overduehandling'        => 'autoabandon',
                    'graceperiod'            => 0,
                    'preferredbehaviour'     => 'deferredfeedback',
                    'canredoquestions'       => 0,
                    'attempts'               => 0,
                    'attemptonlast'          => 0,
                    'grademethod'            => 1,
                    'decimalpoints'          => 2,
                    'questiondecimalpoints'  => -1,
                    'reviewattempt'          => 69904,
                    'reviewcorrectness'      => 4352,
                    'reviewmarks'            => 4352,
                    'reviewspecificfeedback' => 4352,
                    'reviewgeneralfeedback'  => 4352,
                    'reviewrightanswer'      => 4352,
                    'reviewoverallfeedback'  => 4352,
                    'questionsperpage'       => 0,
                    'navmethod'              => 'free',
                    'shuffleanswers'         => 0,
                    'sumgrades'              => 0,
                    'grade'                  => 10,
                    'timecreated'            => $base_time,
                    'timemodified'           => $base_time,
                    'password'               => '',
                    'subnet'                 => '',
                    'browsersecurity'        => '-',
                    'delay1'                 => 0,
                    'delay2'                 => 0,
                    'showuserpicture'        => 0,
                    'showblocks'             => 0,
                ];
                $offset++;
            }
        }

        $DB->insert_records('quiz', $records);
        return count($records);
    }

    /**
     * Seed facetoface sessions when mod_facetoface is installed.
     *
     * Creates facetoface activities with multiple session-date rows so the
     * aggregator's facetoface path is also exercised at scale. Returns the
     * number of facetoface_sessions_dates rows inserted (= schedule entries).
     *
     * Skips silently and returns 0 when facetoface tables are absent, so the
     * test remains runnable in CI environments without the module.
     *
     * @param int   $courseid   Course to attach facetoface activities to.
     * @param int   $userid     User who signs up for every session.
     * @param int   $activities Number of facetoface activities to create.
     * @param int   $dates_each Number of session-date rows per activity.
     * @param int   $base_time  Unix timestamp for the first session date.
     * @return int  Number of facetoface schedule entries (session-date rows) created.
     */
    private function seed_facetoface_sessions(
        int $courseid,
        int $userid,
        int $activities,
        int $dates_each,
        int $base_time
    ): int {
        global $DB;

        if (!$DB->get_manager()->table_exists('facetoface')) {
            return 0;
        }

        $gen       = self::getDataGenerator();
        $date_rows = 0;

        for ($fi = 0; $fi < $activities; $fi++) {
            $f2f = $gen->create_module('facetoface', ['course' => $courseid]);

            $sessid = (int) $DB->insert_record('facetoface_sessions', [
                'facetoface'    => $f2f->id,
                'capacity'      => 200,
                'allowoverbook' => 0,
                'datetimeknown' => 1,
                'visible'       => 1,
                'timecreated'   => $base_time,
                'timemodified'  => $base_time,
            ]);

            for ($di = 0; $di < $dates_each; $di++) {
                $offset = ($fi * $dates_each + $di) * 3600;
                $DB->insert_record('facetoface_sessions_dates', [
                    'sessionid'  => $sessid,
                    'timestart'  => $base_time + $offset,
                    'timefinish' => $base_time + $offset + 1800,
                ]);
                $date_rows++;
            }

            // BOOKED signup (statuscode=80, superceded=0) — this is what the
            // aggregator's JOIN on facetoface_signups_status requires (statuscode >= 60).
            $signupid = (int) $DB->insert_record('facetoface_signups', [
                'sessionid'        => $sessid,
                'userid'           => $userid,
                'mailedreminder'   => 0,
                'notificationtype' => 3,
            ]);
            $DB->insert_record('facetoface_signups_status', [
                'signupid'    => $signupid,
                'statuscode'  => 80,
                'superceded'  => 0,
                'createdby'   => $userid,
                'timecreated' => $base_time,
            ]);
        }

        return $date_rows;
    }

    /**
     * Performance: get_schedule with 1000+ sessions must return all sessions
     * within the 2-second response-time threshold (AC7 of VBS-255).
     *
     * Data mix:
     *   - 1010 quiz sessions (10 courses × 101 quizzes, direct DB insert)
     *   - 50 facetoface session-date rows (5 activities × 10 dates) when
     *     mod_facetoface is installed; skipped otherwise.
     */
    public function test_get_schedule_performance_with_1000_plus_sessions(): void {
        global $DB;
        $this->resetAfterTest();

        $gen  = self::getDataGenerator();
        $user = $gen->create_user();
        $now  = time();

        // --- Quiz sessions (always seeded) ---
        $num_courses       = 10;
        $quizzes_per_course = 101; // 10 × 101 = 1010 quizzes

        $courseids = [];
        for ($c = 0; $c < $num_courses; $c++) {
            $course     = $gen->create_course();
            $courseids[] = (int) $course->id;
            $gen->enrol_user($user->id, $course->id);
        }

        $quiz_count = $this->bulk_insert_quizzes($courseids, $quizzes_per_course, $now + HOURSECS);

        // --- Facetoface sessions (seeded only when mod_facetoface is installed) ---
        $f2f_count = $this->seed_facetoface_sessions(
            $courseids[0],
            (int) $user->id,
            5,   // activities
            10,  // dates each → 50 session-date rows
            $now + DAYSECS
        );

        $expected_total = $quiz_count + $f2f_count;

        $this->assertGreaterThanOrEqual(1000, $expected_total,
            'Test fixture must seed at least 1000 sessions before benchmarking.');

        // --- Benchmark get_schedule ---
        $this->setUser($user);

        $start  = microtime(true);
        $raw    = get_schedule::execute(0, 0, 0, '', '', '');
        $result = external_api::clean_returnvalue(get_schedule::execute_returns(), $raw);
        $elapsed = microtime(true) - $start;

        // All seeded sessions must be returned.
        $this->assertCount(
            $expected_total,
            $result,
            sprintf(
                'Expected %d sessions (%d quiz%s); got %d.',
                $expected_total,
                $quiz_count,
                $f2f_count > 0 ? " + {$f2f_count} facetoface" : '',
                count($result)
            )
        );

        // Response time must be within the AC7 threshold.
        $this->assertLessThan(
            self::MAX_RESPONSE_TIME_SECONDS,
            $elapsed,
            sprintf(
                'get_schedule must respond in < %.1f s with %d sessions; took %.3f s.',
                self::MAX_RESPONSE_TIME_SECONDS,
                $expected_total,
                $elapsed
            )
        );
    }
}
