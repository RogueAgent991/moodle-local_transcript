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


/**
 * The start page: choose a layout and generate a transcript for yourself or for a student.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_transcript\permissions;
use local_transcript\template;
use local_transcript\ui;

require_login(null, false);
if (!permissions::is_real_user()) {
    throw new \moodle_exception('noguest');
}

$userid = optional_param('userid', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$templateid = optional_param('templateid', 0, PARAM_INT);

ui::setup_page('/local/transcript/index.php', ['userid' => $userid, 'search' => $search, 'templateid' => $templateid],
    get_string('transcript', 'local_transcript'));

// The heading of the page is written in the page itself, because some themes do not show the page heading.
$PAGE->set_heading(format_string($SITE->fullname));

$templates = template::get_all(true);
$options = [];
foreach ($templates as $template) {
    $options[$template->id] = format_string($template->name);
}
if (!isset($options[$templateid])) {
    $default = (int) get_config('local_transcript', 'defaulttemplate');
    $templateid = isset($options[$default]) ? $default : (int) key($options);
}

/**
 * The little form with the button that generates a transcript.
 *
 * @param int $studentid
 * @param int $selected The layout that is chosen.
 * @param string $label The text on the button.
 * @return string
 */
$generateform = function (int $studentid, int $selected, string $label) use ($options): string {
    $hidden = \html_writer::input_hidden_params(new moodle_url('/local/transcript/generate.php', [
        'userid' => $studentid, 'sesskey' => sesskey(),
    ]));
    $select = '';
    if (count($options) > 1) {
        // Several transcripts to choose from: list them.
        $radios = \html_writer::tag('h4', get_string('selecttranscript', 'local_transcript'), ['class' => 'h5']);
        foreach ($options as $optionid => $name) {
            $inputid = 'lt-template-' . $studentid . '-' . $optionid;
            $radios .= \html_writer::div(
                \html_writer::empty_tag('input', ['type' => 'radio', 'name' => 'templateid', 'value' => $optionid,
                    'id' => $inputid, 'class' => 'form-check-input'] + ($optionid == $selected ? ['checked' => 'checked'] : [])) .
                \html_writer::label($name, $inputid, true, ['class' => 'form-check-label ml-2 ms-2']), 'form-check');
        }
        $select = \html_writer::div($radios, 'mb-3');
    } else {
        $hidden .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'templateid', 'value' => $selected]);
    }
    $button = \html_writer::tag('button', $label, ['type' => 'submit', 'class' => 'btn btn-primary']);
    return \html_writer::tag('form', $hidden . $select . \html_writer::div($button),
        ['method' => 'post', 'action' => (new moodle_url('/local/transcript/generate.php'))->out(false)]);
};

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('transcript', 'local_transcript'), 2);

if (!$templates) {
    echo $OUTPUT->notification(get_string('notemplates', 'local_transcript'), \core\output\notification::NOTIFY_WARNING);
    if (permissions::can_manage_templates()) {
        echo $OUTPUT->single_button(new moodle_url('/local/transcript/template.php'),
            get_string('createtemplate', 'local_transcript'), 'get');
    }
}

if ($templates) {
    // A particular student, for example from the profile page.
    if ($userid && $userid != $USER->id) {
        permissions::require_generate_for($userid);
        $student = core_user::get_user($userid, '*', MUST_EXIST);
        echo $OUTPUT->heading(get_string('transcriptfor', 'local_transcript', fullname($student)), 3);
        echo $generateform($userid, $templateid, get_string('generatetranscript', 'local_transcript'));
        echo html_writer::empty_tag('hr');
    }

    // The transcript of the user.
    if (permissions::can_generate_for((int) $USER->id)) {
        echo $generateform((int) $USER->id, $templateid, get_string('generatemytranscript', 'local_transcript'));
    }

    // Find students.
    if (permissions::can_search_students()) {
        echo html_writer::empty_tag('hr');
        echo $OUTPUT->heading(get_string('findstudent', 'local_transcript'), 3);
        echo html_writer::tag('form', html_writer::div(
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'templateid', 'value' => $templateid]) .
            html_writer::empty_tag('input', ['type' => 'text', 'name' => 'search', 'value' => $search,
                'class' => 'form-control w-auto mr-2 me-2', 'size' => 40, 'aria-label' => get_string('search'),
                'placeholder' => get_string('findstudent_placeholder', 'local_transcript')]) .
            html_writer::tag('button', get_string('search'), ['type' => 'submit', 'class' => 'btn btn-secondary']),
            'd-flex align-items-center'), ['method' => 'get', 'action' => (new moodle_url('/local/transcript/index.php'))->out(false)]);

        if (trim($search) !== '') {
            $students = permissions::find_students($search);
            echo html_writer::div('', 'mb-3');
            if (!$students) {
                echo $OUTPUT->notification(get_string('nostudentsfound', 'local_transcript'),
                    \core\output\notification::NOTIFY_INFO);
            } else {
                if (count($students) >= permissions::SEARCH_LIMIT) {
                    echo $OUTPUT->notification(get_string('toomanystudents', 'local_transcript', permissions::SEARCH_LIMIT),
                        \core\output\notification::NOTIFY_INFO);
                }
                $table = new html_table();
                $table->attributes['class'] = 'generaltable';
                $table->head = [get_string('name'), get_string('idnumber'), get_string('email'), ''];
                foreach ($students as $student) {
                    $table->data[] = [
                        fullname($student),
                        s($student->idnumber ?? ''),
                        s($student->email ?? ''),
                        $generateform((int) $student->id, $templateid, get_string('generate', 'local_transcript')),
                    ];
                }
                echo html_writer::table($table);
            }
        }
    }
}

// Links for the people who do more than generating.
$links = [];
if (permissions::can_manage_templates()) {
    $links[] = html_writer::link(new moodle_url('/local/transcript/templates.php'),
        get_string('managetemplates', 'local_transcript'));
}
if (permissions::can_view_issues()) {
    $links[] = html_writer::link(new moodle_url('/local/transcript/issues.php'),
        get_string('issuedtranscripts', 'local_transcript'));
}
if (permissions::can_verify()) {
    $links[] = html_writer::link(new moodle_url('/local/transcript/verify.php'),
        get_string('verifytranscript', 'local_transcript'));
}
if ($links) {
    echo html_writer::empty_tag('hr');
    echo html_writer::alist($links, ['class' => 'list-inline'], 'ul');
}

echo $OUTPUT->footer();
