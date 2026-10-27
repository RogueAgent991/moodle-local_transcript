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
 * The list of transcript layouts.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_transcript\template;
use local_transcript\ui;

require_login(null, false);
$context = context_system::instance();
require_capability('local/transcript:managetemplates', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$listurl = new moodle_url('/local/transcript/templates.php');
ui::setup_page('/local/transcript/templates.php', [], get_string('templates', 'local_transcript'), 'admin');

if ($action !== '' && $id) {
    require_sesskey();
    $record = $DB->get_record('local_transcript_template', ['id' => $id], '*', MUST_EXIST);
    switch ($action) {
        case 'duplicate':
            template::duplicate($id);
            redirect($listurl, get_string('templateduplicated', 'local_transcript'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        case 'togglevisible':
            $DB->set_field('local_transcript_template', 'visible', $record->visible ? 0 : 1, ['id' => $id]);
            redirect($listurl);
        case 'setdefault':
            set_config('defaulttemplate', $id, 'local_transcript');
            redirect($listurl, get_string('defaultset', 'local_transcript'), null, \core\output\notification::NOTIFY_SUCCESS);
        case 'delete':
            if ($confirm) {
                template::delete($id);
                redirect($listurl, get_string('templatedeleted', 'local_transcript'), null,
                    \core\output\notification::NOTIFY_SUCCESS);
            }
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(get_string('deletetemplate_confirm', 'local_transcript', format_string($record->name)),
                new moodle_url($listurl, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]), $listurl);
            echo $OUTPUT->footer();
            exit;
    }
}

$default = (int) get_config('local_transcript', 'defaulttemplate');
$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = [get_string('templatename', 'local_transcript'), get_string('pagesetup', 'local_transcript'),
    get_string('elements', 'local_transcript'), get_string('status'), get_string('actions')];

foreach (template::get_all() as $template) {
    $count = $DB->count_records('local_transcript_element', ['templateid' => $template->id]);
    $link = function (string $action, string $text) use ($template): string {
        return html_writer::link(new moodle_url('/local/transcript/templates.php',
            ['action' => $action, 'id' => $template->id, 'sesskey' => sesskey()]), $text);
    };

    $status = [];
    $status[] = $template->visible ? get_string('visible') : get_string('hidden', 'local_transcript');
    if ((int) $template->id === $default) {
        $status[] = html_writer::span(get_string('default'), 'badge bg-primary text-white');
    }

    $designurl = function (string $section) use ($template): moodle_url {
        return new moodle_url('/local/transcript/designer.php', ['id' => $template->id, 'section' => $section]);
    };
    $actions = [
        html_writer::link(new moodle_url('/local/transcript/template.php', ['id' => $template->id]),
            get_string('tab_master', 'local_transcript')),
        html_writer::link($designurl('header'), get_string('tab_header', 'local_transcript')),
        html_writer::link($designurl('transcript'), get_string('tab_transcript', 'local_transcript')),
        html_writer::link($designurl('footer'), get_string('tab_footer', 'local_transcript')),
        html_writer::link(new moodle_url('/local/transcript/preview.php', ['id' => $template->id]),
            get_string('tab_preview', 'local_transcript')),
        $link('togglevisible', $template->visible ? get_string('hide') : get_string('show')),
        $link('duplicate', get_string('duplicate')),
    ];
    if ((int) $template->id !== $default) {
        $actions[] = $link('setdefault', get_string('makedefault', 'local_transcript'));
    }
    $actions[] = $link('delete', get_string('delete'));

    $table->data[] = [
        format_string($template->name),
        $template->pagesize === 'CUSTOM' ? format_float((float) $template->customwidth, 0) . ' x ' .
            format_float((float) $template->customheight, 0) . ' mm' :
            $template->pagesize . ' ' . ($template->orientation === 'L' ? get_string('landscape', 'local_transcript') :
                get_string('portrait', 'local_transcript')),
        $count,
        implode(' ', $status),
        implode(' | ', $actions),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->single_button(new moodle_url('/local/transcript/template.php'), get_string('createtemplate', 'local_transcript'), 'get');
if ($table->data) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('notemplates', 'local_transcript'), \core\output\notification::NOTIFY_INFO);
}
echo $OUTPUT->footer();
