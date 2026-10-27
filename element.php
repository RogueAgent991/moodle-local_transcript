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
 * Add an element to a section of a layout, or change one.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_transcript\form\element_form;
use local_transcript\helper;
use local_transcript\template;
use local_transcript\ui;

require_login(null, false);
$context = context_system::instance();
require_capability('local/transcript:managetemplates', $context);

$id = optional_param('id', 0, PARAM_INT);
$preset = '';
if ($id) {
    $element = template::get_element($id);
    $templateid = (int) $element->templateid;
    $region = $element->region;
    $type = $element->type;
} else {
    $element = null;
    $templateid = required_param('templateid', PARAM_INT);
    $region = required_param('region', PARAM_ALPHANUMEXT);
    // The choice is the type, and after a colon the name of a ready made element.
    $choice = optional_param('choice', 'text', PARAM_RAW_TRIMMED);
    [$type, $preset] = array_pad(explode(':', $choice, 2), 2, '');
    $type = clean_param($type, PARAM_ALPHA);
    $preset = clean_param($preset, PARAM_RAW_TRIMMED);
}
$allowed = [
    helper::REGION_HEADER => ['text', 'image', 'userpicture', 'line', 'rectangle', 'qrcode'],
    helper::REGION_FOOTER => ['text', 'image', 'userpicture', 'line', 'rectangle', 'qrcode'],
    helper::REGION_HEADING => ['text', 'image', 'line', 'rectangle'],
    helper::REGION_ROW => ['coursefield', 'text', 'image', 'line', 'rectangle'],
];
if (!isset($allowed[$region]) || !in_array($type, $allowed[$region])) {
    throw new \moodle_exception('invalidparameter', 'debug');
}
$template = template::get($templateid);

$params = $id ? ['id' => $id] : ['templateid' => $templateid, 'region' => $region, 'choice' => $type . ($preset !== '' ? ':' . $preset : '')];
ui::setup_page('/local/transcript/element.php', $params, get_string('element', 'local_transcript'), 'admin');
$designerurl = new moodle_url('/local/transcript/designer.php', ['id' => $templateid,
    'section' => ui::get_section_of_region($region)]);

$form = new element_form(new moodle_url('/local/transcript/element.php', $params), ['type' => $type, 'region' => $region]);

if ($form->is_cancelled()) {
    redirect($designerurl);
}

if ($data = $form->get_data()) {
    $record = new stdClass();
    $record->id = $id ?: null;
    $record->templateid = $templateid;
    $record->region = $region;
    $record->type = $type;
    $record->name = $data->name;
    $record->posx = $data->posx;
    $record->posy = $data->posy;
    $record->width = $data->width;
    // A line is always horizontal. Its thickness is set separately.
    $record->height = $type === 'line' ? 0 : ($data->height ?? 0);
    $record->showon = $data->showon ?? helper::SHOWON_ALL;

    // Start with what is stored, so that settings this type does not show are kept.
    $stored = $element ? $element->data : template::get_element_default_data($type);
    foreach (['text', 'font', 'fontsize', 'fontstyle', 'colour', 'align', 'thickness', 'fill', 'fillcolour', 'field',
            'prefix', 'decimals'] as $key) {
        if (isset($data->$key)) {
            $stored[$key] = $data->$key;
        }
    }
    if ($type === 'rectangle' && !isset($data->fill)) {
        $stored['fill'] = 0;
    }
    $record->data = $stored;
    $savedid = template::save_element($record);

    if ($type === 'image') {
        file_save_draft_area_files($data->image_filemanager, $context->id, 'local_transcript', template::FILEAREA_IMAGE,
            $savedid, element_form::get_image_options());
    }
    redirect($designerurl, get_string('elementsaved', 'local_transcript'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Values for the form.
if ($element) {
    $defaults = (object) ($element->data + (array) $element);
} else {
    [$pagewidth] = helper::get_template_dimensions($template);
    $usable = $pagewidth - $template->leftmargin - $template->rightmargin;
    $defaults = (object) template::get_element_default_data($type);
    $defaults->name = helper::get_element_types()[$type];
    $defaults->showon = helper::SHOWON_ALL;
    $defaults->posx = (float) $template->leftmargin;
    $defaults->posy = 5;
    $defaults->width = in_array($type, ['text', 'line', 'rectangle']) ? $usable : ($type === 'qrcode' ? 22 : 0);
    $defaults->height = $type === 'image' ? 20 : ($type === 'userpicture' ? 30 : ($type === 'rectangle' ? 10 : 0));
    if ($type === 'userpicture') {
        $defaults->width = 25;
    }

    $presets = helper::get_text_presets();
    if ($type === 'text' && isset($presets[$preset])) {
        $defaults->name = $presets[$preset]['label'];
        $defaults->text = $presets[$preset]['text'];
    }

    if ($region === helper::REGION_ROW || $region === helper::REGION_HEADING) {
        // Place the new element to the right of the ones that are there, in the middle of the height of the row.
        $pitch = $defaults->fontsize * 0.352778 * 1.25;
        $sectionheight = helper::get_section_height($template, $region);
        $defaults->posy = round(max(0.0, ($sectionheight - $pitch) / 2), 1);
        $right = (float) $template->leftmargin;
        foreach (template::get_elements($templateid, $region) as $existing) {
            if ($existing->type !== 'rectangle') {
                $right = max($right, (float) $existing->posx + (float) $existing->width);
            }
        }
        $defaults->posx = min($right, max(0.0, $pagewidth - $template->rightmargin - 20));
        $defaults->width = min($usable - ($defaults->posx - $template->leftmargin), 60);
        $defaults->fontsize = (int) $template->bodyfontsize;
        $defaults->font = $template->bodyfont;
        $defaults->colour = $template->bodycolour;
    }
    if ($type === 'coursefield') {
        $fields = helper::get_column_fields();
        $field = isset($fields[$preset]) || strpos($preset, 'cf:') === 0 ? $preset : 'coursefullname';
        $defaults->field = $field;
        $defaults->name = $fields[$field] ?? $field;
        $defaults->width = min(helper::get_default_field_width($field), max(10.0, $defaults->width));
        if (in_array($field, ['startdate', 'enddate', 'enroldate', 'completiondate', 'grade', 'gradepercent',
                'gradeletter', 'passfail', 'status', 'year'])) {
            $defaults->align = 'C';
        }
        if (in_array($field, ['coursefullname', 'coursedescription']) && $template->rowheight >= 2 * $pitch + 1) {
            $defaults->posy = 0.5;
            $defaults->height = round($template->rowheight - 1, 1);
        }
    }
}
$defaults->templateid = $templateid;
$defaults->region = $region;
$defaults->type = $type;
$defaults->id = $id;
if ($type === 'image') {
    $draftitemid = file_get_submitted_draft_itemid('image_filemanager');
    file_prepare_draft_area($draftitemid, $context->id, 'local_transcript', template::FILEAREA_IMAGE, $id ?: null,
        element_form::get_image_options());
    $defaults->image_filemanager = $draftitemid;
}
$form->set_data($defaults);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($template->name), 3);
echo ui::template_tabs($templateid, ui::get_section_of_region($region));
$form->display();
echo $OUTPUT->footer();
