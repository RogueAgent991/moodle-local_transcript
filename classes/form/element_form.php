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

namespace local_transcript\form;

use local_transcript\helper;
use local_transcript\placeholders;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * The form for one element of the header or footer. The fields depend on the type of the element.
 *
 * Custom data: 'type' (text, image, line, rectangle or qrcode).
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class element_form extends \moodleform {

    /**
     * The options of the file picker used for pictures.
     *
     * @return array
     */
    public static function get_image_options(): array {
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'accepted_types' => ['.png', '.jpg', '.jpeg', '.gif'],
            'maxbytes' => 5 * 1024 * 1024,
        ];
    }

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;
        $type = $this->_customdata['type'];
        $region = $this->_customdata['region'];
        $fixedbox = in_array($region, [helper::REGION_ROW, helper::REGION_HEADING]);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'templateid');
        $mform->setType('templateid', PARAM_INT);
        $mform->addElement('hidden', 'region');
        $mform->setType('region', PARAM_ALPHANUMEXT);
        $mform->addElement('hidden', 'type');
        $mform->setType('type', PARAM_ALPHA);

        $mform->addElement('header', 'general', helper::get_element_types()[$type] ?? $type);
        $mform->addElement('text', 'name', get_string('elementname', 'local_transcript'), ['size' => 40]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addHelpButton('name', 'elementname', 'local_transcript');

        switch ($type) {
            case 'text':
                $mform->addElement('textarea', 'text', get_string('text', 'local_transcript'), ['rows' => 4, 'cols' => 50]);
                $mform->setType('text', PARAM_RAW);
                $mform->addElement('static', 'textlinkhelp', '', get_string('textlinkhelp', 'local_transcript'));
                $mform->addElement('static', 'placeholders', get_string('placeholders', 'local_transcript'),
                    $this->get_placeholder_help());
                $this->add_font_fields($mform);
                $mform->addElement('select', 'align', get_string('textalign', 'local_transcript'), helper::get_alignments());
                break;

            case 'coursefield':
                $mform->addElement('select', 'field', get_string('coursefield', 'local_transcript'),
                    helper::get_column_fields());
                $mform->addHelpButton('field', 'coursefield', 'local_transcript');
                $mform->addElement('text', 'prefix', get_string('prefix', 'local_transcript'), ['size' => 30]);
                $mform->setType('prefix', PARAM_TEXT);
                $mform->addHelpButton('prefix', 'prefix', 'local_transcript');
                $mform->addElement('text', 'decimals', get_string('decimals', 'local_transcript'), ['size' => 3]);
                $mform->setType('decimals', PARAM_INT);
                $mform->setDefault('decimals', 2);
                $mform->addHelpButton('decimals', 'decimals', 'local_transcript');
                // Only for the grades: hide it when any of the other fields is chosen.
                foreach (array_keys(helper::get_column_fields()) as $otherfield) {
                    if (!in_array($otherfield, ['grade', 'gradepercent'])) {
                        $mform->hideIf('decimals', 'field', 'eq', $otherfield);
                    }
                }
                $this->add_font_fields($mform);
                $mform->addElement('select', 'align', get_string('textalign', 'local_transcript'), helper::get_alignments());
                break;

            case 'userpicture':
                $mform->addElement('static', 'userpictureinfo', '', get_string('userpicture_desc', 'local_transcript'));
                break;

            case 'image':
                $mform->addElement('filemanager', 'image_filemanager', get_string('element_image', 'local_transcript'), null,
                    self::get_image_options());
                break;

            case 'line':
                form_helper::add_colour($mform, 'colour', get_string('linecolour', 'local_transcript'));
                $mform->addElement('float', 'thickness', get_string('thickness', 'local_transcript'), ['size' => 5]);
                $mform->setDefault('thickness', 0.3);
                break;

            case 'rectangle':
                form_helper::add_colour($mform, 'colour', get_string('bordercolour', 'local_transcript'));
                $mform->addElement('float', 'thickness', get_string('thickness', 'local_transcript'), ['size' => 5]);
                $mform->setDefault('thickness', 0.3);
                $mform->addElement('advcheckbox', 'fill', get_string('fill', 'local_transcript'));
                form_helper::add_colour($mform, 'fillcolour', get_string('fillcolour', 'local_transcript'), '#EEEEEE');
                $mform->hideIf('fillcolour', 'fill', 'notchecked');
                break;

            case 'qrcode':
                form_helper::add_colour($mform, 'colour', get_string('qrcolour', 'local_transcript'));
                $mform->addElement('static', 'qrinfo', '', get_string('qrcode_desc', 'local_transcript'));
                break;
        }

        // Position and size.
        $mform->addElement('header', 'positionhdr', get_string('positionandsize', 'local_transcript'));
        $mform->addElement('static', 'positioninfo', '', get_string('position_desc_' . $region, 'local_transcript'));
        $mform->addElement('float', 'posx', get_string('posx', 'local_transcript'), ['size' => 6]);
        $mform->addElement('float', 'posy', get_string('posy', 'local_transcript'), ['size' => 6]);
        $widthlabel = [
            'text' => 'width_text', 'coursefield' => 'width_text', 'image' => 'width_image', 'userpicture' => 'width_image',
            'line' => 'width_line', 'rectangle' => 'width_rectangle', 'qrcode' => 'width_qrcode',
        ][$type];
        $mform->addElement('float', 'width', get_string($widthlabel, 'local_transcript'), ['size' => 6]);
        $mform->addHelpButton('width', $widthlabel, 'local_transcript');
        if (in_array($type, ['text', 'coursefield']) && $fixedbox) {
            $mform->addElement('float', 'height', get_string('height_textbox', 'local_transcript'), ['size' => 6]);
            $mform->addHelpButton('height', 'height_textbox', 'local_transcript');
        } else if (in_array($type, ['image', 'userpicture', 'rectangle'])) {
            $heightlabel = 'height_' . ($type === 'userpicture' ? 'image' : $type);
            $mform->addElement('float', 'height', get_string($heightlabel, 'local_transcript'), ['size' => 6]);
            $mform->addHelpButton('height', $heightlabel, 'local_transcript');
        } else {
            $mform->addElement('hidden', 'height');
            $mform->setType('height', PARAM_FLOAT);
            $mform->setDefault('height', 0);
        }

        if ($fixedbox) {
            $mform->addElement('hidden', 'showon', helper::SHOWON_ALL);
            $mform->setType('showon', PARAM_INT);
        } else {
            $mform->addElement('select', 'showon', get_string('showon', 'local_transcript'), helper::get_showon_options());
            $mform->addHelpButton('showon', 'showon', 'local_transcript');
        }

        $this->add_action_buttons();
    }

    /**
     * The fields for font, size, style and colour.
     *
     * @param \MoodleQuickForm $mform
     */
    private function add_font_fields(\MoodleQuickForm $mform): void {
        $mform->addElement('select', 'font', get_string('font', 'local_transcript'), helper::get_fonts());
        $mform->addElement('text', 'fontsize', get_string('fontsize', 'local_transcript'), ['size' => 4]);
        $mform->setType('fontsize', PARAM_INT);
        $mform->addElement('select', 'fontstyle', get_string('fontstyle', 'local_transcript'), helper::get_font_styles());
        form_helper::add_colour($mform, 'colour', get_string('textcolour', 'local_transcript'));
    }

    /**
     * The explanation of the placeholders, shown under the text field.
     *
     * @return string HTML
     */
    private function get_placeholder_help(): string {
        $items = '';
        foreach (placeholders::get_list() as $placeholder => $description) {
            $items .= \html_writer::tag('li', \html_writer::tag('code', '{{' . $placeholder . '}}') . ' - ' .
                s($description));
        }
        $summary = \html_writer::tag('summary', get_string('placeholders_show', 'local_transcript'));
        return \html_writer::tag('details', $summary . \html_writer::tag('ul', $items, ['class' => 'mt-2']));
    }

    /**
     * Check the form.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $errors += form_helper::validate_colours($data, ['colour', 'fillcolour']);
        $type = $data['type'];

        foreach (['posx', 'posy'] as $field) {
            if ($data[$field] < 0 || $data[$field] > 1000) {
                $errors[$field] = get_string('err_numberrange', 'local_transcript', '0 - 1000');
            }
        }
        if ($data['width'] < 0 || $data['width'] > 1000) {
            $errors['width'] = get_string('err_numberrange', 'local_transcript', '0 - 1000');
        }
        if (isset($data['height']) && ($data['height'] < 0 || $data['height'] > 1000)) {
            $errors['height'] = get_string('err_numberrange', 'local_transcript', '0 - 1000');
        }
        if (in_array($type, ['text', 'coursefield'])) {
            if ($data['fontsize'] < 4 || $data['fontsize'] > 96) {
                $errors['fontsize'] = get_string('err_numberrange', 'local_transcript', '4 - 96');
            }
        }
        if ($type === 'coursefield' && in_array($data['field'], ['grade', 'gradepercent'])
                && (($data['decimals'] ?? '') === '' || $data['decimals'] < 0 || $data['decimals'] > 6)) {
            $errors['decimals'] = get_string('err_numberrange', 'local_transcript', '0 - 6');
        }
        if (in_array($type, ['line', 'rectangle']) && ($data['thickness'] < 0.1 || $data['thickness'] > 5)) {
            $errors['thickness'] = get_string('err_numberrange', 'local_transcript', '0.1 - 5');
        }
        if ($type === 'rectangle' && empty($errors['width']) && empty($errors['height'])
                && ($data['width'] <= 0 || $data['height'] <= 0)) {
            $errors['width'] = get_string('err_sizerequired', 'local_transcript');
        }
        return $errors;
    }
}
