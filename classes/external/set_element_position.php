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


namespace local_transcript\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Saves the new position of an element after it was dragged in the layout designer.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_element_position extends external_api {

    /**
     * Parameters of execute().
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'The element id'),
            'posx' => new external_value(PARAM_FLOAT, 'Distance from the left edge of the page in millimetres'),
            'posy' => new external_value(PARAM_FLOAT, 'Distance from the top of the header or footer in millimetres'),
        ]);
    }

    /**
     * Move an element.
     *
     * @param int $id
     * @param float $posx
     * @param float $posy
     * @return array The position that was saved.
     */
    public static function execute(int $id, float $posx, float $posy): array {
        global $DB;

        ['id' => $id, 'posx' => $posx, 'posy' => $posy] = self::validate_parameters(
            self::execute_parameters(), ['id' => $id, 'posx' => $posx, 'posy' => $posy]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/transcript:managetemplates', $context);

        $element = $DB->get_record('local_transcript_element', ['id' => $id], '*', MUST_EXIST);
        $posx = round(max(0.0, min(1000.0, $posx)), 1);
        $posy = round(max(0.0, min(1000.0, $posy)), 1);

        $DB->update_record('local_transcript_element', (object) [
            'id' => $element->id, 'posx' => $posx, 'posy' => $posy, 'timemodified' => time(),
        ]);
        return ['posx' => $posx, 'posy' => $posy];
    }

    /**
     * Description of what execute() returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'posx' => new external_value(PARAM_FLOAT, 'The saved horizontal position'),
            'posy' => new external_value(PARAM_FLOAT, 'The saved vertical position'),
        ]);
    }
}
