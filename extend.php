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

/**
 * view.php Prints the main view of a particular instance of checkmark
 *
 * @package   mod_checkmark
 * @author    Philipp Hager
 * @copyright 2014 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/checkmark/locallib.php');

// We check that in detail afterwards!
require_login();

$id = required_param('id', PARAM_INT);
$type = required_param('type', PARAM_INT);
$mode = optional_param('mode', \mod_checkmark\overrideform::ADD, PARAM_TEXT);
$confirm = optional_param('confirm', 0, PARAM_INT);
$return = optional_param('return', false, PARAM_RAW);
$return = !empty($return) ? urldecode($return) : (new moodle_url('/mod/checkmark/overrides.php',
        ['id' => $id, 'mode' => $type === \mod_checkmark\overrideform::USER ? 'user' : 'group']))->out();
$users = optional_param('users', false, PARAM_RAW);

try {
    if (!in_array($type, [\mod_checkmark\overrideform::USER, \mod_checkmark\overrideform::GROUP])) {
        throw new coding_exception('invalidformdata');
    }

    // Sets url with params and performs require_login!
    $url = new moodle_url('/mod/checkmark/extend.php');
    $url->param('id', $id);
    $url->param('type', $type);
    list($cm, $checkmark, $course) = \checkmark::init_checks($id, 0, $url);
    $context = context_module::instance($id);
    require_capability('mod/checkmark:manageoverrides', $context);

    $customdata = [
        'cm' => $cm,
        'context' => $context,
        'checkmark' => $checkmark,
        'return' => $return,
        'mode' => $mode
    ];

    $stroverride = get_string('override', 'checkmark');
    if ($mode === \mod_checkmark\overrideform::DELETE) {
        $title = get_string('deletecheck', null, $stroverride);
    } else {
        $title = get_string('editoverride', 'checkmark');
    }

    $PAGE->set_url($url);
    $PAGE->set_pagelayout('admin');
    $PAGE->navbar->add($title);
    $PAGE->set_title($title);
    $PAGE->set_heading($course->fullname);


    $form = new \mod_checkmark\overrideform($type, $url, $customdata);

    if ($form->is_cancelled()) {
        redirect($return);
    } else if ($mode === \mod_checkmark\overrideform::DELETE && $confirm) {
        $instance = new checkmark($cm->id, $checkmark, $cm, $course);
        $instance->delete_override($users);
        redirect($return, "Entry deleted", null, \core\output\notification::NOTIFY_SUCCESS);
    } else if ($data = $form->get_data()) {
        $instance = new checkmark($cm->id, $checkmark, $cm, $course);
        if ($type === \mod_checkmark\overrideform::GROUP) {
            // Get all group(s) users to extend for!
            $data->userids = [];
            if (!empty($data->groups)) {
                $instance->override_dates($data->groups, $data->timeavailable, $data->timedue, $data->cutoffdate,\mod_checkmark\overrideform::GROUP);
                /*
                foreach ($data->groups as $cur) {
                    if ($userids = groups_get_members($cur)) {
                        $data->userids = array_merge($data->userids, array_keys($userids));
                    }
                }
                */
            }
        } else if (!empty($data->userids)) {
            $instance->override_dates($data->userids, $data->timeavailable, $data->timedue, $data->cutoffdate);
        }

        if (!empty($data->override)) {
            redirect($return, get_string('dates_overwritten', 'checkmark'), null, \core\output\notification::NOTIFY_SUCCESS);
        }

        \core\notification::add(get_string('dates_overwritten', 'checkmark'), \core\output\notification::NOTIFY_SUCCESS);
    } else {
        if (!empty($users) && $type === \mod_checkmark\overrideform::USER) {
            $users = json_decode(urldecode(required_param('users', PARAM_RAW)));
            $data = array();
            if ($mode == \mod_checkmark\overrideform::EDIT || $mode == \mod_checkmark\overrideform::COPY) {
                $dates = checkmark_get_overridden_dates($checkmark->id,
                        is_int($users) ? $users : $users[0]);
                if ($dates) {
                    $data = array('timeavailable' => $dates->timeavailable, 'timedue' => $dates->timedue, 'cutoffdate' => $dates->cutoffdate);
                }
            }
            if ($mode != \mod_checkmark\overrideform::COPY) {
                $data['userids'] = $users;
            }
            $form->set_data($data);
        }
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(format_string($checkmark->name, true, array('context' => $context)));

    if ($mode != \mod_checkmark\overrideform::DELETE) {
        $form->display();
    } else {
        $confirmurl = new moodle_url($url, array('id' => $id, 'type' => $type, 'users' => $users,
                'mode' => \mod_checkmark\overrideform::DELETE, 'confirm' => 1));
        $cancelurl = new moodle_url('/mod/checkmark/overrides.php', array('id' => $id));
        $namefields = get_all_user_name_fields(true);
        $user = $DB->get_record('user', array('id' => $users),
                'id, ' . $namefields);
        $confirmstr = get_string("overridedeleteusersure", "checkmark", fullname($user));
        echo $OUTPUT->confirm($confirmstr, $confirmurl, $cancelurl);
    }

    echo $OUTPUT->footer();
} catch (dml_exception $d) {
    throw $d;
} catch (Throwable $t) {
    redirect($return, $t->getFile().'#'.$t->getLine().': '.$t->getMessage().html_writer::empty_tag('br').
                      nl2br($t->getTraceAsString()), null, \core\output\notification::NOTIFY_ERROR);
} catch (\Exception $e) {
    redirect($return, $e->getFile().'#'.$e->getLine().': '.$e->getMessage().html_writer::empty_tag('br').
                      nl2br($e->getTraceAsString()), null, \core\output\notification::NOTIFY_ERROR);
}

