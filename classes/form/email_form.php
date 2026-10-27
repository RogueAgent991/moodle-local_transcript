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

use local_transcript\emailer;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * The form for sending a transcript by email.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class email_form extends \moodleform {

    /**
     * Form definition.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);
        $mform->addElement('hidden', 'templateid');
        $mform->setType('templateid', PARAM_INT);

        $mform->addElement('textarea', 'recipients', get_string('emailrecipients', 'local_transcript'),
            ['rows' => 3, 'cols' => 50, 'placeholder' => get_string('emailrecipients_placeholder', 'local_transcript')]);
        $mform->setType('recipients', PARAM_RAW);
        $mform->addHelpButton('recipients', 'emailrecipients', 'local_transcript');

        $this->add_action_buttons(true, get_string('emailsend', 'local_transcript'));
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

        [$valid, $invalid] = emailer::parse_addresses($data['recipients']);
        if ($invalid) {
            $errors['recipients'] = get_string('err_emailinvalid', 'local_transcript', s(implode(', ', $invalid)));
        } else if (count($valid) > emailer::get_max_recipients()) {
            $errors['recipients'] = get_string('err_emailtoomany', 'local_transcript', emailer::get_max_recipients());
        }
        return $errors;
    }
}
