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

/**
 * Small helpers for the forms of the plugin.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class form_helper {

    /**
     * Add a field where a colour can be picked.
     *
     * @param \MoodleQuickForm $mform
     * @param string $name Name of the field.
     * @param string $label
     * @param string $default A colour such as #000000.
     * @return \HTML_QuickForm_element
     */
    public static function add_colour(\MoodleQuickForm $mform, string $name, string $label, string $default = '#000000') {
        $element = $mform->addElement('text', $name, $label, ['size' => 8, 'maxlength' => 7]);
        $element->updateAttributes(['type' => 'color']);
        $mform->setType($name, PARAM_RAW_TRIMMED);
        $mform->setDefault($name, $default);
        return $element;
    }

    /**
     * Check the colour fields of a submitted form.
     *
     * @param array $data The submitted data.
     * @param string[] $names The names of the colour fields to check.
     * @return array Errors per field.
     */
    public static function validate_colours(array $data, array $names): array {
        $errors = [];
        foreach ($names as $name) {
            if (isset($data[$name]) && !helper::is_valid_colour($data[$name])) {
                $errors[$name] = get_string('err_colour', 'local_transcript');
            }
        }
        return $errors;
    }
}
