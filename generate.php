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
 * Generates a transcript: records it with its verification code and goes to the page where it can be used.
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
use local_transcript\template;

require_login(null, false);
$PAGE->set_context(context_system::instance());
require_sesskey();

$userid = required_param('userid', PARAM_INT);
$templateid = required_param('templateid', PARAM_INT);

permissions::require_generate_for($userid);

$template = template::get($templateid);
if (!$template->visible && !permissions::can_manage_templates()) {
    throw new \moodle_exception('templatenotavailable', 'local_transcript');
}

core_php_time_limit::raise(120);
raise_memory_limit(MEMORY_EXTRA);

$issue = issue_manager::create_issue($templateid, $userid, (int) $USER->id);
redirect(new moodle_url('/local/transcript/transcript.php', ['id' => $issue->id]));
