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
 * The page of a generated transcript: look at it, download it or send it by email.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_transcript\helper;
use local_transcript\permissions;
use local_transcript\ui;

require_login(null, false);

$id = required_param('id', PARAM_INT);
$download = optional_param('download', 0, PARAM_BOOL);
$issue = $DB->get_record('local_transcript_issue', ['id' => $id],
    'id, userid, issuedby, templateid, templatename, code, revoked, timecreated, snapshot', MUST_EXIST);
permissions::require_access_issue($issue);

ui::setup_page('/local/transcript/transcript.php', ['id' => $id], get_string('transcript', 'local_transcript'));

$student = core_user::get_user($issue->userid, '*', MUST_EXIST);
$downloadurl = new moodle_url('/local/transcript/download.php', ['id' => $id]);
$viewurl = new moodle_url('/local/transcript/download.php', ['id' => $id, 'inline' => 1]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('transcriptfor', 'local_transcript', fullname($student)), 3);

if ($issue->revoked) {
    echo $OUTPUT->notification(get_string('revokednotice', 'local_transcript'), \core\output\notification::NOTIFY_WARNING);
}

$details = [
    get_string('layout', 'local_transcript') => s($issue->templatename),
    get_string('generatedon', 'local_transcript') => userdate($issue->timecreated, get_string('strftimedatetime', 'langconfig')),
    get_string('verificationcode', 'local_transcript') => html_writer::tag('code', s($issue->code)),
];
echo html_writer::start_tag('dl', ['class' => 'row']);
foreach ($details as $label => $value) {
    echo html_writer::tag('dt', $label, ['class' => 'col-sm-3']) . html_writer::tag('dd', $value, ['class' => 'col-sm-9']);
}
echo html_writer::end_tag('dl');

// What is included. The values are the ones that this transcript was made with.
$model = json_decode($issue->snapshot, true) ?: [];
$includeinprogress = !isset($model['template']['includeinprogress']) || !empty($model['template']['includeinprogress']);
$lastyears = (int) ($model['template']['lastyears'] ?? 0);

$checkbox = html_writer::div(
    html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'includeinprogress', 'value' => 1, 'id' => 'lt-inprogress',
        'class' => 'form-check-input'] + ($includeinprogress ? ['checked' => 'checked'] : [])) .
    html_writer::label(get_string('optincludeinprogress', 'local_transcript'), 'lt-inprogress',
        true, ['class' => 'form-check-label ml-2 ms-2']), 'form-check mb-2');
$years = html_writer::div(
    html_writer::label(get_string('optlastyears', 'local_transcript'), 'lt-lastyears', true, ['class' => 'mr-2 me-2 mb-0']) .
    html_writer::empty_tag('input', ['type' => 'number', 'name' => 'lastyears', 'id' => 'lt-lastyears', 'min' => 0, 'max' => 100,
        'value' => $lastyears, 'class' => 'form-control', 'style' => 'width:6rem']) .
    $OUTPUT->help_icon('optlastyears', 'local_transcript'), 'd-flex align-items-center mb-3');

$submit = function (string $action, string $label, string $class) {
    return html_writer::tag('button', $label, ['type' => 'submit', 'name' => 'action', 'value' => $action,
        'class' => 'btn ' . $class . ' mr-2 me-2']);
};
$buttons = $submit('regenerate', get_string('regeneratetranscript', 'local_transcript'), 'btn-secondary') .
    $submit('download', get_string('downloadpdf', 'local_transcript'), 'btn-primary');
if (permissions::can_email_for((int) $issue->userid)) {
    $buttons .= $submit('email', get_string('sendbyemail', 'local_transcript'), 'btn-secondary');
}
// The layout. Only when there is more than one to choose from.
$layouts = [];
foreach (\local_transcript\template::get_all(true) as $layout) {
    $layouts[$layout->id] = format_string($layout->name);
}
$layouts[(int) $issue->templateid] = $layouts[(int) $issue->templateid] ?? s($issue->templatename);
$choice = '';
if (count($layouts) > 1) {
    $choice = html_writer::tag('h4', get_string('selecttranscript', 'local_transcript'), ['class' => 'h5']);
    foreach ($layouts as $layoutid => $name) {
        $inputid = 'lt-layout-' . $layoutid;
        $choice .= html_writer::div(
            html_writer::empty_tag('input', ['type' => 'radio', 'name' => 'templateid', 'value' => $layoutid,
                'id' => $inputid, 'class' => 'form-check-input'] +
                ($layoutid == $issue->templateid ? ['checked' => 'checked'] : [])) .
            html_writer::label($name, $inputid, true, ['class' => 'form-check-label ml-2 ms-2']), 'form-check');
    }
    $choice = html_writer::div($choice, 'mb-3');
}
echo $OUTPUT->heading(get_string('transcriptoptions', 'local_transcript'), 4);
echo html_writer::tag('form', html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]) .
    $choice . $checkbox . $years . html_writer::div($buttons, 'd-flex mb-3'),
    ['method' => 'post', 'action' => (new moodle_url('/local/transcript/regenerate.php'))->out(false)]);
if ($download) {
    // The PDF of the transcript that was just made is downloaded while this page is shown.
    echo html_writer::tag('iframe', '', ['src' => $downloadurl->out(false), 'style' => 'display:none', 'title' => '']);
}

echo html_writer::tag('object', html_writer::link($viewurl, get_string('openpdf', 'local_transcript')), [
    'data' => $viewurl->out(false), 'type' => 'application/pdf', 'style' => 'width:100%;height:80vh;border:1px solid #ccc',
]);

echo html_writer::div(html_writer::link(new moodle_url('/local/transcript/index.php'),
    get_string('backtostart', 'local_transcript')), 'mt-3');
echo $OUTPUT->footer();
