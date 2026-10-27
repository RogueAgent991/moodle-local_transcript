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
 * The editor of one section of a layout: the header, the transcript or the footer.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_transcript\helper;
use local_transcript\template;
use local_transcript\ui;

require_login(null, false);
$context = context_system::instance();
require_capability('local/transcript:managetemplates', $context);

$id = required_param('id', PARAM_INT);
$section = optional_param('section', 'header', PARAM_ALPHA);
if (!in_array($section, ['header', 'transcript', 'footer'])) {
    $section = 'header';
}
$action = optional_param('action', '', PARAM_ALPHA);
$elementid = optional_param('elementid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$template = template::get($id);
$pageurl = new moodle_url('/local/transcript/designer.php', ['id' => $id, 'section' => $section]);
ui::setup_page('/local/transcript/designer.php', ['id' => $id, 'section' => $section],
    get_string('tab_' . $section, 'local_transcript'), 'admin');

if ($action !== '' && $elementid) {
    require_sesskey();
    // The element must belong to this layout.
    $DB->get_record('local_transcript_element', ['id' => $elementid, 'templateid' => $id], 'id', MUST_EXIST);
    switch ($action) {
        case 'duplicate':
            template::duplicate_element($elementid);
            redirect($pageurl);
        case 'up':
            template::move_element($elementid, -1);
            redirect($pageurl);
        case 'down':
            template::move_element($elementid, 1);
            redirect($pageurl);
        case 'delete':
            if ($confirm) {
                template::delete_element($elementid);
                redirect($pageurl, get_string('elementdeleted', 'local_transcript'), null,
                    \core\output\notification::NOTIFY_SUCCESS);
            }
            $element = template::get_element($elementid);
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(get_string('deleteelement_confirm', 'local_transcript', s($element->name)),
                new moodle_url($pageurl, ['action' => 'delete', 'elementid' => $elementid, 'confirm' => 1,
                    'sesskey' => sesskey()]), $pageurl);
            echo $OUTPUT->footer();
            exit;
    }
}

$PAGE->requires->js_call_amd('local_transcript/designer', 'init');

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($template->name), 3);
echo ui::template_tabs($id, $section);
echo html_writer::div(get_string('designer_help_' . $section, 'local_transcript'), 'alert alert-info');

[$pagewidth, $pageheight] = helper::get_template_dimensions($template);
$types = helper::get_element_types();
$showon = helper::get_showon_options();

$regions = ui::get_regions_of_section($section);
if (count($regions) > 1) {
    // One picture of the whole section: the column headings on top and one course below, above the lists.
    echo ui::render_combined_canvas($template, $regions);
}

foreach ($regions as $region) {
    $height = helper::get_section_height($template, $region);
    echo $OUTPUT->heading(get_string('region_' . $region, 'local_transcript') . ' (' . format_float($pagewidth, 0) . ' x ' .
        format_float($height, 1) . ' mm)', 4);
    echo html_writer::div(get_string('region_' . $region . '_desc', 'local_transcript'), 'text-muted mb-2');

    if ($height <= 0) {
        echo $OUTPUT->notification(get_string('regionnoheight', 'local_transcript',
            html_writer::link(new moodle_url('/local/transcript/template.php', ['id' => $id]),
                get_string('tab_master', 'local_transcript'))), \core\output\notification::NOTIFY_WARNING);
        continue;
    }

    $elements = template::get_elements($id, $region);
    if (count($regions) === 1) {
        echo ui::render_canvas($template, $region, $elements);
    }

    // The menu to add an element, like in Custom Certificate.
    echo html_writer::tag('form', html_writer::div(
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'templateid', 'value' => $id]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'region', 'value' => $region]) .
        html_writer::select(helper::get_add_options($region), 'choice', 'text', false,
            ['class' => 'custom-select form-select w-auto mr-2 me-2', 'aria-label' => get_string('addelement', 'local_transcript')]) .
        html_writer::tag('button', get_string('addelement', 'local_transcript'), ['type' => 'submit', 'class' => 'btn btn-primary']),
        'd-flex align-items-center mb-3'), ['method' => 'get', 'action' => (new moodle_url('/local/transcript/element.php'))->out(false)]);

    // The list of the elements of this part.
    $isfixed = in_array($region, [helper::REGION_ROW, helper::REGION_HEADING]);
    $table = new html_table();
    $table->attributes['class'] = 'generaltable';
    $table->head = [get_string('elementname', 'local_transcript'), get_string('type', 'local_transcript'),
        get_string('position', 'local_transcript')];
    if (!$isfixed) {
        $table->head[] = get_string('showon', 'local_transcript');
    }
    $table->head[] = get_string('actions');
    foreach ($elements as $element) {
        $link = function (string $action, string $text) use ($element, $id, $section): string {
            return html_writer::link(new moodle_url('/local/transcript/designer.php', ['id' => $id, 'section' => $section,
                'action' => $action, 'elementid' => $element->id, 'sesskey' => sesskey()]), $text);
        };
        $row = [
            html_writer::link(new moodle_url('/local/transcript/element.php', ['id' => $element->id]), s($element->name)),
            $types[$element->type] ?? s($element->type),
            html_writer::span(($element->posx + 0) . ' / ' . ($element->posy + 0), '', ['data-position-for' => $element->id]),
        ];
        if (!$isfixed) {
            $row[] = $showon[$element->showon] ?? '';
        }
        $row[] = implode(' | ', [
            html_writer::link(new moodle_url('/local/transcript/element.php', ['id' => $element->id]), get_string('edit')),
            $link('duplicate', get_string('duplicate')),
            $link('up', get_string('moveup')),
            $link('down', get_string('movedown')),
            $link('delete', get_string('delete')),
        ]);
        $table->data[] = $row;
    }
    if ($table->data) {
        echo html_writer::table($table);
    }
    echo html_writer::empty_tag('hr');
}

echo $OUTPUT->footer();
