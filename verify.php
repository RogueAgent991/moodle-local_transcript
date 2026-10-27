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
 * Makes the PDF of a transcript again from its verification code.
 *
 * Who may use this is set by the administrator: course teachers and managers, the learner as well, any logged in
 * user, or anybody.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_transcript\helper;
use local_transcript\issue_manager;
use local_transcript\permissions;
use local_transcript\ui;

$code = optional_param('code', '', PARAM_RAW_TRIMMED);
$PAGE->set_context(context_system::instance());

// Only ask for a login when the administrator did not open the page to everybody.
if (get_config('local_transcript', 'verifyaccess') !== helper::VERIFY_PUBLIC) {
    require_login(null, false);
}

ui::setup_page('/local/transcript/verify.php', ['code' => $code], get_string('verifytranscript', 'local_transcript'));

if (!permissions::can_verify()) {
    throw new \moodle_exception('verifynotallowed', 'local_transcript');
}

$message = '';
if ($code !== '') {
    $issue = issue_manager::get_by_code(clean_param($code, PARAM_RAW_TRIMMED));
    if ($issue && !permissions::can_verify_issue($issue)) {
        // Do not tell people that a code exists when they may not use it.
        $issue = null;
    }
    if ($issue && !$issue->revoked) {
        \local_transcript\event\transcript_verified::create([
            'context' => \local_transcript\template::get_context(),
            'objectid' => $issue->id,
            'relateduserid' => $issue->userid,
            'other' => ['code' => $issue->code],
        ])->trigger();

        core_php_time_limit::raise(120);
        raise_memory_limit(MEMORY_EXTRA);
        \core\session\manager::write_close();
        $pdf = issue_manager::render_issue($issue);
        send_file($pdf, issue_manager::get_filename($issue), 0, 0, true, false, 'application/pdf');
    }

    // Slow down people who keep guessing.
    $SESSION->local_transcript_failures = ($SESSION->local_transcript_failures ?? 0) + 1;
    sleep(min(5, $SESSION->local_transcript_failures));
    $message = $issue ? get_string('verifyrevoked', 'local_transcript') : get_string('verifynotfound', 'local_transcript');
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('verifytranscript', 'local_transcript'), 3);
echo html_writer::div(get_string('verify_desc', 'local_transcript'), 'mb-3');
if ($message !== '') {
    echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_ERROR);
}
echo html_writer::tag('form', html_writer::div(
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'code', 'value' => s($code), 'size' => 22,
        'class' => 'form-control w-auto mr-2 me-2', 'maxlength' => 40, 'autocomplete' => 'off',
        'aria-label' => get_string('verificationcode', 'local_transcript'), 'placeholder' => 'XXXX-XXXX-XXXX']) .
    html_writer::tag('button', get_string('verifyandopen', 'local_transcript'), ['type' => 'submit', 'class' => 'btn btn-primary']),
    'd-flex align-items-center'), ['method' => 'get', 'action' => (new moodle_url('/local/transcript/verify.php'))->out(false)]);
echo $OUTPUT->footer();
