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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * The master control of a layout: everything that is not placed on the page itself. Page size, margins, the heights
 * of the three sections, the background graphic and watermark, which courses are included, the look of the course
 * table and the PDF protection.
 *
 * Custom data: 'transcriptheight' (the current height of the transcript section, shown to the person who edits).
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_form extends \moodleform {

    private const COLOURS = ['stripecolour', 'linecolour', 'groupbg'];

    /**
     * Options of the file manager for the watermark.
     *
     * @return array
     */
    public static function get_watermark_options(): array {
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'accepted_types' => ['.png', '.jpg', '.jpeg', '.gif'],
            'maxbytes' => 5 * 1024 * 1024,
        ];
    }

    /**
     * Options of the editor for the message of the email. Pictures can be added to the message.
     *
     * @return array
     */
    public static function get_email_editor_options(): array {
        return [
            'maxfiles' => 10,
            'maxbytes' => 2 * 1024 * 1024,
            'subdirs' => 0,
            'accepted_types' => ['web_image'],
            'context' => \context_system::instance(),
            'noclean' => false,
            'trusttext' => false,
        ];
    }

    /**
     * Options of the file manager for the background graphic. They are the same as for the watermark.
     *
     * @return array
     */
    public static function get_background_options(): array {
        return self::get_watermark_options();
    }

    /**
     * The form.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        // General.
        $mform->addElement('header', 'general', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('templatename', 'local_transcript'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('advcheckbox', 'visible', get_string('templatevisible', 'local_transcript'));
        $mform->addHelpButton('visible', 'templatevisible', 'local_transcript');

        // The page.
        $mform->addElement('header', 'pagesetup', get_string('pagesetup', 'local_transcript'));
        $sizes = [];
        foreach (helper::get_page_sizes() as $key => $dimensions) {
            $sizes[$key] = get_string('pagesize_' . strtolower($key), 'local_transcript');
        }
        $sizes['CUSTOM'] = get_string('pagesize_custom', 'local_transcript');
        $mform->addElement('select', 'pagesize', get_string('pagesize', 'local_transcript'), $sizes);
        $mform->addElement('select', 'orientation', get_string('orientation', 'local_transcript'), [
            'P' => get_string('portrait', 'local_transcript'),
            'L' => get_string('landscape', 'local_transcript'),
        ]);
        $mform->hideIf('orientation', 'pagesize', 'eq', 'CUSTOM');
        $mform->addElement('float', 'customwidth', get_string('customwidth', 'local_transcript'), ['size' => 6]);
        $mform->addElement('float', 'customheight', get_string('customheight', 'local_transcript'), ['size' => 6]);
        $mform->hideIf('customwidth', 'pagesize', 'neq', 'CUSTOM');
        $mform->hideIf('customheight', 'pagesize', 'neq', 'CUSTOM');
        foreach (['topmargin', 'bottommargin', 'leftmargin', 'rightmargin'] as $field) {
            $mform->addElement('float', $field, get_string($field, 'local_transcript'), ['size' => 6]);
            $mform->addHelpButton($field, $field, 'local_transcript');
        }

        // The sections.
        $mform->addElement('header', 'sectionshdr', get_string('sections', 'local_transcript'));
        $mform->addElement('static', 'sectionsinfo', '', get_string('sections_desc', 'local_transcript'));
        $mform->addElement('float', 'headerheight', get_string('headerheight', 'local_transcript'), ['size' => 6]);
        $mform->addHelpButton('headerheight', 'headerheight', 'local_transcript');
        $mform->addElement('float', 'footerheight', get_string('footerheight', 'local_transcript'), ['size' => 6]);
        $mform->addHelpButton('footerheight', 'footerheight', 'local_transcript');
        $mform->addElement('static', 'transcriptheightinfo', get_string('transcriptheight', 'local_transcript'),
            get_string('transcriptheight_value', 'local_transcript', format_float((float) ($this->_customdata['transcriptheight'] ?? 0), 1)));
        $mform->addHelpButton('transcriptheightinfo', 'transcriptheight', 'local_transcript');
        $mform->addElement('float', 'headingheight', get_string('headingheight', 'local_transcript'), ['size' => 6]);
        $mform->addHelpButton('headingheight', 'headingheight', 'local_transcript');
        $mform->addElement('float', 'rowheight', get_string('rowheight', 'local_transcript'), ['size' => 6]);
        $mform->addHelpButton('rowheight', 'rowheight', 'local_transcript');
        $mform->addElement('float', 'groupheight', get_string('groupheight', 'local_transcript'), ['size' => 6]);
        $mform->addHelpButton('groupheight', 'groupheight', 'local_transcript');

        // Which courses.
        $mform->addElement('header', 'courseshdr', get_string('whichcourses', 'local_transcript'));
        $mform->addElement('advcheckbox', 'includeinprogress', '', get_string('includeinprogress', 'local_transcript'));
        $mform->addElement('advcheckbox', 'includeended', '', get_string('includeended', 'local_transcript'));
        $mform->addElement('advcheckbox', 'includehidden', '', get_string('includehidden', 'local_transcript'));
        $mform->addElement('text', 'lastyears', get_string('lastyears', 'local_transcript'), ['size' => 3]);
        $mform->setType('lastyears', PARAM_INT);
        $mform->addHelpButton('lastyears', 'lastyears', 'local_transcript');
        $mform->addElement('select', 'sortby', get_string('sortby', 'local_transcript'), helper::get_sort_options());
        $mform->addElement('select', 'groupby', get_string('groupby', 'local_transcript'), helper::get_groupby_options());
        $mform->addHelpButton('groupby', 'groupby', 'local_transcript');
        $mform->addElement('select', 'dateformat', get_string('dateformat', 'local_transcript'),
            helper::get_date_format_options());
        $mform->addHelpButton('dateformat', 'dateformat', 'local_transcript');

        // The look of the course table.
        $mform->addElement('header', 'stylehdr', get_string('tablestyle', 'local_transcript'));
        $mform->addElement('advcheckbox', 'stripe', get_string('stripe', 'local_transcript'));
        form_helper::add_colour($mform, 'stripecolour', get_string('stripecolour', 'local_transcript'), '#F5F6F8');
        $mform->hideIf('stripecolour', 'stripe', 'notchecked');
        $mform->addElement('advcheckbox', 'rowlines', get_string('rowlines', 'local_transcript'));
        form_helper::add_colour($mform, 'linecolour', get_string('linecolour', 'local_transcript'), '#C8CCD2');
        $mform->hideIf('linecolour', 'rowlines', 'notchecked');
        form_helper::add_colour($mform, 'groupbg', get_string('groupbg', 'local_transcript'), '#F0F2F5');
        $mform->hideIf('groupbg', 'groupby', 'eq', 'none');

        // The email.
        $mform->addElement('header', 'emailhdr', get_string('emailsettingshdr', 'local_transcript'));
        $mform->addElement('static', 'emailinfo', '', get_string('emailsettings_desc', 'local_transcript'));
        $mform->addElement('text', 'emailsubject', get_string('emailsubjecttemplate', 'local_transcript'), ['size' => 70]);
        $mform->setType('emailsubject', PARAM_TEXT);
        $mform->addRule('emailsubject', null, 'required', null, 'client');
        $mform->addRule('emailsubject', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addElement('editor', 'emailmessage_editor', get_string('emailmessagetemplate', 'local_transcript'),
            ['rows' => 12], self::get_email_editor_options());
        $mform->setType('emailmessage_editor', PARAM_RAW);
        $mform->addRule('emailmessage_editor', null, 'required', null, 'client');
        $mform->addElement('static', 'emailplaceholders', get_string('emailplaceholders', 'local_transcript'),
            \local_transcript\placeholders::get_help_html());

        // Pictures behind the content.
        $mform->addElement('header', 'backgroundhdr', get_string('backgroundgraphic', 'local_transcript'));
        $mform->addElement('static', 'backgroundinfo', '', get_string('backgroundgraphic_desc', 'local_transcript'));
        $mform->addElement('filemanager', 'background_filemanager', get_string('backgroundimage', 'local_transcript'), null,
            self::get_background_options());

        $mform->addElement('header', 'watermarkhdr', get_string('watermark', 'local_transcript'));
        $mform->addElement('static', 'watermarkinfo', '', get_string('watermark_desc', 'local_transcript'));
        $mform->addElement('filemanager', 'watermark_filemanager', get_string('watermarkimage', 'local_transcript'), null,
            self::get_watermark_options());
        $mform->addElement('text', 'watermarkopacity', get_string('watermarkopacity', 'local_transcript'), ['size' => 4]);
        $mform->setType('watermarkopacity', PARAM_INT);
        $mform->addHelpButton('watermarkopacity', 'watermarkopacity', 'local_transcript');
        $mform->addElement('text', 'watermarkwidth', get_string('watermarkwidth', 'local_transcript'), ['size' => 4]);
        $mform->setType('watermarkwidth', PARAM_INT);
        $mform->addHelpButton('watermarkwidth', 'watermarkwidth', 'local_transcript');

        // PDF protection.
        $mform->addElement('header', 'protectionhdr', get_string('pdfprotection', 'local_transcript'));
        $mform->addElement('static', 'protectioninfo', '', get_string('pdfprotection_desc', 'local_transcript'));
        foreach (helper::get_protection_options() as $key => $label) {
            $mform->addElement('advcheckbox', 'protect_' . str_replace('-', '_', $key), '', $label);
        }

        $this->add_action_buttons();
    }

    /**
     * Turn the stored list of blocked PDF permissions into check boxes.
     *
     * @param \stdClass|array $defaults
     */
    public function set_data($defaults) {
        $defaults = (object) $defaults;
        $blocked = array_filter(array_map('trim', explode(',', (string) ($defaults->protection ?? ''))));
        foreach (array_keys(helper::get_protection_options()) as $key) {
            $field = 'protect_' . str_replace('-', '_', $key);
            $defaults->$field = in_array($key, $blocked) ? 1 : 0;
        }
        parent::set_data($defaults);
    }

    /**
     * Turn the check boxes of the PDF protection into one stored list.
     *
     * @return \stdClass|null
     */
    public function get_data() {
        $data = parent::get_data();
        if (!$data) {
            return $data;
        }
        $blocked = [];
        foreach (array_keys(helper::get_protection_options()) as $key) {
            $field = 'protect_' . str_replace('-', '_', $key);
            if (!empty($data->$field)) {
                $blocked[] = $key;
            }
            unset($data->$field);
        }
        $data->protection = implode(',', $blocked);
        return $data;
    }

    /**
     * Check the numbers.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $errors += form_helper::validate_colours($data, self::COLOURS);

        $ranges = [
            'leftmargin' => [0, 100], 'rightmargin' => [0, 100], 'topmargin' => [0, 200], 'bottommargin' => [0, 200], 'headerheight' => [0, 400], 'footerheight' => [0, 400],
            'headingheight' => [0, 50], 'rowheight' => [3, 150], 'groupheight' => [3, 50],
        ];
        if ($data['pagesize'] === 'CUSTOM') {
            $ranges['customwidth'] = [50, 1000];
            $ranges['customheight'] = [50, 1000];
        }
        foreach ($ranges as $field => [$min, $max]) {
            if (!isset($data[$field]) || $data[$field] < $min || $data[$field] > $max) {
                $errors[$field] = get_string('err_numberrange', 'local_transcript', "$min - $max");
            }
        }
        if ($data['lastyears'] === '' || $data['lastyears'] < 0 || $data['lastyears'] > 100) {
            $errors['lastyears'] = get_string('err_numberrange', 'local_transcript', '0 - 100');
        }
        if ($data['watermarkopacity'] < 1 || $data['watermarkopacity'] > 100) {
            $errors['watermarkopacity'] = get_string('err_numberrange', 'local_transcript', '1 - 100');
        }
        if ($data['watermarkwidth'] < 5 || $data['watermarkwidth'] > 100) {
            $errors['watermarkwidth'] = get_string('err_numberrange', 'local_transcript', '5 - 100');
        }

        if (!$errors) {
            [$width, $height] = helper::get_template_dimensions((object) $data);
            if ($data['leftmargin'] + $data['rightmargin'] > $width - 40) {
                $errors['rightmargin'] = get_string('err_marginstoolarge', 'local_transcript');
            }
            if ($data['topmargin'] > $data['headerheight'] || $data['bottommargin'] > $data['footerheight']) {
                $errors[$data['topmargin'] > $data['headerheight'] ? 'topmargin' : 'bottommargin'] =
                    get_string('err_verticalmargin', 'local_transcript');
            }
            $transcriptheight = $height - $data['headerheight'] - $data['footerheight'];
            $needed = $data['headingheight'] + $data['groupheight'] + $data['rowheight'];
            if ($transcriptheight < $needed) {
                $errors['footerheight'] = get_string('err_heightstoolarge', 'local_transcript',
                    format_float($transcriptheight, 1));
            }
        }
        return $errors;
    }
}
