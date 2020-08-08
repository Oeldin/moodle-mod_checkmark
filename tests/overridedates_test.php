<?php
// This file is part of mod_checkmark for Moodle - http://moodle.org/
//
// It is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// It is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
use core\event\calendar_event_created;
use core\event\calendar_event_updated;
use mod_checkmark\event\group_override_updated;
use mod_checkmark\event\user_override_created;
use mod_checkmark\event\group_override_created;
use mod_checkmark\event\user_override_updated;

/**
 * Unit tests for (some of) mod_checkmark's methods.
 *
 * @package   mod_checkmark
 * @author    Daniel Binder
 * @copyright 2020 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.'); // It must be included from a Moodle page!
}

// Make sure the code being tested is accessible.
global $CFG;
require_once($CFG->dirroot . '/mod/checkmark/locallib.php'); // Include the code to test!

/**
 * This class contains the test cases for the override_dates method.
 * @group mod_checkmark
 *
 * @package   mod_checkmark
 * @author    Daniel Binder
 * @copyright 2020 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checkmark_overridedates_test extends advanced_testcase {

    /**
     * @var checkmark Checkmark object used for testing
     */
    private $checkmark;
    /**
     * @var stdClass User object used for testing
     */
    private $testuser;
    /**
     * @var stdClass Group object used for testing
     */
    private $testgroup;

    /**
     * Set up a checkmark instance, a user and a group
     *
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function setUp() {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->testuser = $this->getDataGenerator()->create_user(['email' => 'test@example.com', 'username' => 'test']);
        $course1 = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($this->testuser->id, $course1->id);
        $checkmark = $this->getDataGenerator()->create_module('checkmark', array('course' => $course1->id));
        $this->testgroup = $this->getDataGenerator()->create_group(['courseid' => $course1->id]);
        $this->getDataGenerator()->create_group_member(['userid' => $this->testuser->id, 'groupid' => $this->testgroup->id]);
        $this->checkmark = new checkmark($checkmark->cmid);
    }

    /**
     * Test the creation of a single user override
     *
     * @throws dml_exception
     */
    public function test_add_user_override() {
        global $DB;
        $timedueoverride = time() + 1209600; // 2 weeks after now.
        $sink = $this->redirectEvents();
        $this->checkmark->override_dates([$this->testuser->id], $this->checkmark->checkmark->timeavailable,
                $timedueoverride, $this->checkmark->checkmark->cutoffdate);
        $this->assertEquals(1, $DB->count_records('checkmark_overrides'));
        $expect = ['timeavailable' => null, 'timedue' => $timedueoverride, 'cutoffdate' => null, 'groupid' => null,
                'grouppriority' => null];
        $result = $DB->get_record('checkmark_overrides', ['userid' => $this->testuser->id],
                'timeavailable,timedue,cutoffdate,groupid,grouppriority');
        $result = ['timeavailable' => $result->timeavailable, 'timedue' => (int)($result->timedue),
                'cutoffdate' => $result->cutoffdate, 'groupid' => $result->groupid, 'grouppriority' => $result->grouppriority];
        $this->assertTrue(self::arrays_are_similar($expect, $result));

        // Assert calendar and log event
        $events = $sink->get_events();
        $this->check_events($events, user_override_created::class, calendar_event_created::class);
        $sink->clear();

        $timedueoverride = time() + 2419200; // 4 weeks after now
        $timeavaliableoverride = time() + 1209600;
        $cutoffoverride = time() + 2419200;
        $this->checkmark->override_dates([$this->testuser->id], $timeavaliableoverride,
                $timedueoverride, $cutoffoverride);
        $expect = ['timeavailable' => $timeavaliableoverride, 'timedue' => $timedueoverride, 'cutoffdate' => $cutoffoverride,
                'groupid' => null, 'grouppriority' => null];
        $result = $DB->get_record('checkmark_overrides', ['userid' => $this->testuser->id],
                'timeavailable,timedue,cutoffdate,groupid,grouppriority');
        $result = ['timeavailable' => (int)$result->timeavailable, 'timedue' => (int)($result->timedue),
                'cutoffdate' => (int)$result->cutoffdate, 'groupid' => $result->groupid, 'grouppriority' => $result->grouppriority];
        $this->assertTrue(self::arrays_are_similar($expect, $result));

        // Assert calendar and log event
        $events = $sink->get_events();
        $this->check_events($events, user_override_updated::class, calendar_event_updated::class);
        $sink->close();
    }

    /**
     * Test the creation of a single group override
     *
     * @throws dml_exception
     */
    public function test_add_group_override() {
        global $DB;
        $timedueoverride = time() + 1209600; // 2 weeks after now.
        $sink = $this->redirectEvents();
        $this->checkmark->override_dates([$this->testgroup->id], $this->checkmark->checkmark->timeavailable,
                $timedueoverride, $this->checkmark->checkmark->cutoffdate, \mod_checkmark\overrideform::GROUP);
        $this->assertEquals(1, $DB->count_records('checkmark_overrides'));
        $expect = ['timeavailable' => null, 'timedue' => $timedueoverride, 'cutoffdate' => null,
                'userid' => null, 'grouppriority' => 1];
        $result = $DB->get_record('checkmark_overrides', ['groupid' => $this->testgroup->id],
                'timeavailable,timedue,cutoffdate,userid,grouppriority');
        $result = ['timeavailable' => $result->timeavailable, 'timedue' => (int)($result->timedue),
                'cutoffdate' => $result->cutoffdate, 'userid' => $result->userid, 'grouppriority' => (int)($result->grouppriority)];
        $this->assertTrue(self::arrays_are_similar($expect, $result));

        // Assert calendar and log event
        $this->check_events($sink->get_events(), group_override_created::class, calendar_event_created::class);
        $sink->clear();

        $timedueoverride = time() + 2419200; // 4 weeks after now
        $timeavaliableoverride = time() + 1209600;
        $cutoffoverride = time() + 2419200;
        $this->checkmark->override_dates([$this->testgroup->id], $timeavaliableoverride,
                $timedueoverride, $cutoffoverride, \mod_checkmark\overrideform::GROUP);
        $expect = ['timeavailable' => $timeavaliableoverride, 'timedue' => $timedueoverride, 'cutoffdate' => $cutoffoverride,
                'userid' => null, 'grouppriority' => 1];
        $result = $DB->get_record('checkmark_overrides', ['groupid' => $this->testgroup->id],
                'timeavailable,timedue,cutoffdate,userid,grouppriority');
        $result = ['timeavailable' => (int)$result->timeavailable, 'timedue' => (int)($result->timedue),
                'cutoffdate' => (int)$result->cutoffdate, 'userid' => $result->userid, 'grouppriority' => (int)($result->grouppriority)];
        $this->assertTrue(self::arrays_are_similar($expect, $result));

        // Assert calendar and log event
        $this->check_events($sink->get_events(), group_override_updated::class,
                calendar_event_updated::class);
        $sink->close();
    }

    /**
     * Helper function to check if all log and calendar events for an overwrite dates action have taken place
     *
     * @param $events array Events caught by $sink->getEvents()
     * @param $logkind string Class the log event should be an instance of
     * @param $calendarkind string Class the calendar event should be an instance of
     */
    public function check_events($events, $logkind, $calendarkind) {
        $this->assertCount(2, $events);
        $calendareventreceived = false;
        $logeventreceived = false;
        foreach ($events as $event) {
            if ($event instanceof $logkind && !$logeventreceived) {
                $this->assertEquals($this->checkmark->context, $event->get_context());
                if (isset($event->other['groupid'])) {
                    $this->assertEquals($this->testgroup->id, $event->other['groupid']);
                } else if (isset($event->relateduserid)) {
                    $this->assertEquals($this->testuser->id, $event->relateduserid);
                } else {
                    // Let test fail if no id or either a user or a group override is contained in event
                    $this->assertTrue(false);
                }
                $logeventreceived = true;
            } else if ($event instanceof $calendarkind && !$calendareventreceived) {
                $calendareventreceived = true;
            } else {
                // Let test fail if events contains not exactly one log and one calendar event
                var_dump($events);
                var_dump($calendareventreceived);
                var_dump($logeventreceived);
                $this->assertTrue(false);
            }
        }
    }



    /**
     * Test if no overwrite is created if dates identical to the checkmark's dates are passed.
     *
     * @throws dml_exception
     */
    public function test_add_identical_overwrite() {
        global $DB;
        $sink = $this->redirectEvents();
        $this->checkmark->override_dates([$this->testgroup->id], $this->checkmark->checkmark->timeavailable,
                $this->checkmark->checkmark->timedue, $this->checkmark->checkmark->cutoffdate,
                \mod_checkmark\overrideform::GROUP);
        $this->assertEquals(0, $DB->count_records('checkmark_overrides'));
        $events = $sink->get_events();
        $this->assertCount(0, $events);
        $sink->close();
    }

    /**
     * Determine if two associative arrays are similar
     *
     * Both arrays must have the same indexes with identical values
     * without respect to key ordering
     *
     * @param array $a
     * @param array $b
     * @return bool
     */
    private static function arrays_are_similar($a, $b) {
        // If the indexes don't match, return immediately.
        if (count(array_diff_assoc($a, $b))) {
            return false;
        }
        // We know that the indexes, but maybe not values, match.
        // Compare the values between the two arrays.
        foreach ($a as $k => $v) {
            if ($v !== $b[$k]) {
                return false;
            }
        }
        // We have identical indexes, and no unequal values.
        return true;
    }
}