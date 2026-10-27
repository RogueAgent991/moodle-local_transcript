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
 * Preview of a layout with made up students and courses, or with a real student.
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
use local_transcript\ui;

require_login(null, false);
$PAGE->set_context(context_system::instance());
$context = context_system::instance();
require_capability('local/transcript:managetemplates', $context);

$id = required_param('id', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);
$raw = optional_param('raw', 0, PARAM_BOOL);

$template = template::get($id);
if ($userid) {
    permissions::require_generate_for($userid);
}

if ($raw) {
    core_php_time_limit::raise(120);
    raise_memory_limit(MEMORY_EXTRA);
    \core\session\manager::write_close();
    $pdf = issue_manager::render_preview($id, $userid ?: null);
    send_file($pdf, 'preview.pdf', 0, 0, true, false, 'application/pdf');
}

ui::setup_page('/local/transcript/preview.php', ['id' => $id, 'userid' => $userid], get_string('tab_preview', 'local_transcript'),
    'admin');
$pdfurl = new moodle_url('/local/transcript/preview.php', ['id' => $id, 'userid' => $userid, 'raw' => 1]);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($template->name), 3);
echo ui::template_tabs($id, 'preview');
echo html_writer::div(get_string('preview_help', 'local_transcript'), 'alert alert-info');
echo html_writer::tag('object', html_writer::link($pdfurl, get_string('openpdf', 'local_transcript')), [
    'data' => $pdfurl->out(false), 'type' => 'application/pdf', 'style' => 'width:100%;height:85vh;border:1px solid #ccc',
]);
echo $OUTPUT->footer();
