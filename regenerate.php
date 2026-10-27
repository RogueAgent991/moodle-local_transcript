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
 * Makes a transcript again with the choices of the person (courses in progress, last years), and then shows it,
 * downloads it or goes on to send it by email.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_transcript\issue_manager;
use local_transcript\permissions;

require_login(null, false);
$PAGE->set_context(context_system::instance());
require_sesskey();

$id = required_param('id', PARAM_INT);
$action = optional_param('action', 'regenerate', PARAM_ALPHA);
$includeinprogress = optional_param('includeinprogress', 0, PARAM_BOOL);
$lastyears = max(0, min(100, optional_param('lastyears', 0, PARAM_INT)));

$old = $DB->get_record('local_transcript_issue', ['id' => $id], 'id, userid, templateid, revoked', MUST_EXIST);
permissions::require_access_issue($old);
if ($action === 'email' && !permissions::can_email_for((int) $old->userid)) {
    throw new \moodle_exception('emaildisabled', 'local_transcript');
}

core_php_time_limit::raise(120);
raise_memory_limit(MEMORY_EXTRA);

// The layout can be changed to another one that people may choose.
$templateid = optional_param('templateid', (int) $old->templateid, PARAM_INT);
if ($templateid !== (int) $old->templateid) {
    $template = \local_transcript\template::get($templateid);
    if (!$template->visible && !permissions::can_manage_templates()) {
        throw new \moodle_exception('templatenotavailable', 'local_transcript');
    }
}

$issue = issue_manager::create_issue($templateid, (int) $old->userid, (int) $USER->id,
    ['includeinprogress' => $includeinprogress, 'lastyears' => $lastyears]);

if ($action === 'email') {
    redirect(new moodle_url('/local/transcript/email.php', ['id' => $issue->id]));
}
redirect(new moodle_url('/local/transcript/transcript.php', ['id' => $issue->id, 'download' => $action === 'download' ? 1 : 0]));
