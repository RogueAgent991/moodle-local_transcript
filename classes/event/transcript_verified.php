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

namespace local_transcript\event;

/**
 * A transcript was regenerated with its verification code.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class transcript_verified extends \core\event\base {

    /**
     * Set the basic properties of the event.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_transcript_issue';
    }

    /**
     * The name of the event.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_transcript_verified', 'local_transcript');
    }

    /**
     * What happened, for the logs.
     *
     * @return string
     */
    public function get_description() {
        return "The transcript of the user with id '{$this->relateduserid}' (issue id '{$this->objectid}') was " .
            "regenerated with its verification code by the user with id '{$this->userid}'.";
    }

    /**
     * The page to see more.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/transcript/verify.php');
    }

    /**
     * The mapping of the object id for restores. This plugin is not part of backups.
     *
     * @return false
     */
    public static function get_objectid_mapping() {
        return false;
    }
}
