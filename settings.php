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
 * Settings and admin pages of the Student Transcript plugin.
 *
 * The plugin gets its own entry in the site administration menu, next to Plugins, with the settings, the transcript
 * layouts and the issued transcripts.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('root', new admin_category('local_transcript_category',
        new lang_string('transcripts', 'local_transcript')), 'modules');

    $settings = new admin_settingpage('local_transcript', new lang_string('generalsettings', 'local_transcript'),
        'moodle/site:config');
    // The settings page is part of Local plugins, which is where Moodle looks for the settings of a local plugin.
    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('local_transcript_category', new admin_externalpage('local_transcript_templates',
        new lang_string('templates', 'local_transcript'), new moodle_url('/local/transcript/templates.php'),
        'local/transcript:managetemplates'));
    $ADMIN->add('local_transcript_category', new admin_externalpage('local_transcript_issues',
        new lang_string('issuedtranscripts', 'local_transcript'), new moodle_url('/local/transcript/issues.php'),
        'local/transcript:viewissues'));

    // A link in the Transcripts menu to the settings.
    $ADMIN->add('local_transcript_category', new admin_externalpage('local_transcript_settingslink',
        new lang_string('generalsettings', 'local_transcript'),
        new moodle_url('/admin/settings.php', ['section' => 'local_transcript']), 'moodle/site:config'));

    if ($ADMIN->fulltree) {
        $links = html_writer::alist([
            html_writer::link(new moodle_url('/local/transcript/templates.php'), get_string('templates', 'local_transcript')) .
                ' - ' . get_string('templates_desc', 'local_transcript'),
            html_writer::link(new moodle_url('/local/transcript/issues.php'), get_string('issuedtranscripts', 'local_transcript')),
            html_writer::link(new moodle_url('/local/transcript/index.php'), get_string('generatetranscripts', 'local_transcript')),
        ]);
        $settings->add(new admin_setting_heading('local_transcript/links', get_string('settingslinks', 'local_transcript'),
            $links));

        $settings->add(new admin_setting_configselect('local_transcript/verifyaccess',
            new lang_string('verifyaccess', 'local_transcript'),
            new lang_string('verifyaccess_desc', 'local_transcript'),
            \local_transcript\helper::VERIFY_CAPABILITY, [
                \local_transcript\helper::VERIFY_CAPABILITY => new lang_string('verifyaccess_capability', 'local_transcript'),
                \local_transcript\helper::VERIFY_LEARNER => new lang_string('verifyaccess_learner', 'local_transcript'),
                \local_transcript\helper::VERIFY_LOGGEDIN => new lang_string('verifyaccess_loggedin', 'local_transcript'),
                \local_transcript\helper::VERIFY_PUBLIC => new lang_string('verifyaccess_public', 'local_transcript'),
            ]));

        $settings->add(new admin_setting_configcheckbox('local_transcript/emailenabled',
            new lang_string('emailenabled', 'local_transcript'),
            new lang_string('emailenabled_desc', 'local_transcript'), 1));

        $settings->add(new admin_setting_configtext('local_transcript/maxrecipients',
            new lang_string('maxrecipients', 'local_transcript'),
            new lang_string('maxrecipients_desc', 'local_transcript'), 5, PARAM_INT, 3));
    }
}
