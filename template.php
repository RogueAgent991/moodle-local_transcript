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
 * The master control of a layout: page size, margins, the heights of the three sections, background graphic,
 * watermark, which courses are included and the PDF protection. Also creates a new layout.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_transcript\form\template_form;
use local_transcript\helper;
use local_transcript\template;
use local_transcript\ui;

require_login(null, false);
$context = context_system::instance();
require_capability('local/transcript:managetemplates', $context);

$id = optional_param('id', 0, PARAM_INT);
$title = get_string($id ? 'tab_master' : 'createtemplate', 'local_transcript');
ui::setup_page('/local/transcript/template.php', ['id' => $id], $title, 'admin');

$listurl = new moodle_url('/local/transcript/templates.php');
$defaults = $id ? template::get($id) : template::get_defaults();
$form = new template_form(new moodle_url('/local/transcript/template.php', ['id' => $id]),
    ['transcriptheight' => helper::get_transcript_height($defaults)]);

if ($form->is_cancelled()) {
    redirect($listurl);
}

if ($data = $form->get_data()) {
    $editordata = (object) ['emailmessage_editor' => $data->emailmessage_editor];
    unset($data->emailmessage_editor);
    if ($id) {
        $data->id = $id;
        template::update($data);
        $newid = $id;
    } else {
        $newid = template::create($data);
    }
    // The message of the email, with its pictures.
    $editordata = file_postupdate_standard_editor($editordata, 'emailmessage', template_form::get_email_editor_options(),
        $context, 'local_transcript', template::FILEAREA_EMAIL, $newid);
    $DB->update_record('local_transcript_template', (object) ['id' => $newid, 'emailmessage' => $editordata->emailmessage,
        'emailmessageformat' => $editordata->emailmessageformat]);
    file_save_draft_area_files($data->watermark_filemanager, $context->id, 'local_transcript', template::FILEAREA_WATERMARK,
        $newid, template_form::get_watermark_options());
    file_save_draft_area_files($data->background_filemanager, $context->id, 'local_transcript', template::FILEAREA_BACKGROUND,
        $newid, template_form::get_background_options());
    if ($id) {
        redirect(new moodle_url('/local/transcript/template.php', ['id' => $id]), get_string('changessaved'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect(new moodle_url('/local/transcript/designer.php', ['id' => $newid, 'section' => 'header']),
        get_string('templatecreated', 'local_transcript'), null, \core\output\notification::NOTIFY_SUCCESS);
}

foreach ([template::FILEAREA_WATERMARK => 'watermark_filemanager', template::FILEAREA_BACKGROUND => 'background_filemanager']
        as $area => $field) {
    $draftitemid = file_get_submitted_draft_itemid($field);
    file_prepare_draft_area($draftitemid, $context->id, 'local_transcript', $area, $id ?: null,
        template_form::get_watermark_options());
    $defaults->$field = $draftitemid;
}
$defaults = file_prepare_standard_editor($defaults, 'emailmessage', template_form::get_email_editor_options(), $context,
    'local_transcript', template::FILEAREA_EMAIL, $id ?: null);
$form->set_data($defaults);

echo $OUTPUT->header();
if ($id) {
    echo $OUTPUT->heading(format_string($defaults->name), 3);
    echo ui::template_tabs($id, 'master');
}
$form->display();
echo $OUTPUT->footer();
