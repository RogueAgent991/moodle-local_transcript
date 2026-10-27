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
 * Sends a generated transcript to email addresses that the user types.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_transcript\emailer;
use local_transcript\form\email_form;
use local_transcript\permissions;
use local_transcript\ui;

require_login(null, false);

$id = required_param('id', PARAM_INT);
$issue = $DB->get_record('local_transcript_issue', ['id' => $id], '*', MUST_EXIST);
permissions::require_access_issue($issue);
if (!permissions::can_email_for((int) $issue->userid)) {
    throw new \moodle_exception('emaildisabled', 'local_transcript');
}

$returnurl = new moodle_url('/local/transcript/transcript.php', ['id' => $id]);
ui::setup_page('/local/transcript/email.php', ['id' => $id], get_string('sendbyemail', 'local_transcript'));

$form = new email_form(new moodle_url('/local/transcript/email.php', ['id' => $id]));
$form->set_data([
    'userid' => $issue->userid,
    'templateid' => $issue->templateid,
]);

if ($form->is_cancelled()) {
    redirect($returnurl);
}

if ($data = $form->get_data()) {
    core_php_time_limit::raise(120);
    raise_memory_limit(MEMORY_EXTRA);
    [$valid] = emailer::parse_addresses((string) $data->recipients);
    [$sent, $failed] = emailer::send($issue, $valid);

    if ($failed) {
        $failmessage = get_string('emailpartlyfailed', 'local_transcript', s(implode(', ', $failed)));
        $reasons = array_unique(array_filter(emailer::$errors));
        if ($reasons) {
            $failmessage .= \html_writer::empty_tag('br') .
                get_string('emailfailreason', 'local_transcript', s(implode(' | ', $reasons)));
        }
        redirect($returnurl, $failmessage, null, \core\output\notification::NOTIFY_ERROR);
    }
    $message = $valid ? get_string('emailsent', 'local_transcript', count($sent)) : get_string('emailsentself', 'local_transcript');
    redirect($returnurl, $message, null,
        \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('sendbyemail', 'local_transcript'), 3);
echo html_writer::div(get_string('emailattachmentnote', 'local_transcript'), 'mb-3');

// The subject and the message are set by the administrator. They are shown here and cannot be changed.
echo html_writer::div(get_string('emailreadonlynote', 'local_transcript'), 'text-muted mb-2');
echo html_writer::div(
    html_writer::div(html_writer::tag('strong', get_string('emailsubject', 'local_transcript') . ': ') .
        s(emailer::get_subject($issue)), 'mb-2') .
    html_writer::div(emailer::get_message_html($issue), 'lt-email-preview'), 'border rounded p-3 mb-4 bg-light');
$form->display();
echo $OUTPUT->footer();
