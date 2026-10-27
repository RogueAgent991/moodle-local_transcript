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
 * Upgrade steps of the Student Transcript plugin.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion The version that is installed.
 * @return bool
 */
function xmldb_local_transcript_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026102600) {
        // The layout has three sections: header, transcript and footer. The transcript section holds a row with
        // column headings and a course row, both made of elements, instead of a list of columns.
        $table = new xmldb_table('local_transcript_template');
        $fields = [
            new xmldb_field('customwidth', XMLDB_TYPE_NUMBER, '6, 1', null, XMLDB_NOTNULL, null, '210', 'orientation'),
            new xmldb_field('customheight', XMLDB_TYPE_NUMBER, '6, 1', null, XMLDB_NOTNULL, null, '297', 'customwidth'),
            new xmldb_field('headingheight', XMLDB_TYPE_NUMBER, '6, 1', null, XMLDB_NOTNULL, null, '8', 'footerheight'),
            new xmldb_field('groupheight', XMLDB_TYPE_NUMBER, '6, 1', null, XMLDB_NOTNULL, null, '7', 'headingheight'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // The names of the parts of the page are longer now.
        $elementtable = new xmldb_table('local_transcript_element');
        $regionfield = new xmldb_field('region', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'header', 'templateid');
        $dbman->change_field_precision($elementtable, $regionfield);

        foreach ($DB->get_records('local_transcript_template') as $record) {
            if ($DB->record_exists_select('local_transcript_element', "templateid = ? AND region IN ('transcript', 'transcript_heading')",
                    [$record->id])) {
                continue;
            }
            // The space that used to be left between the sections is added to the header and the footer.
            $gap = (float) $record->bodygap;
            $record->headerheight = (float) $record->headerheight + $gap;
            $record->footerheight = (float) $record->footerheight + $gap;
            $record->bodygap = 0;
            $record->groupheight = (float) $record->rowheight;
            $columns = $record->columns ? json_decode($record->columns, true) : [];
            $converted = \local_transcript\template::columns_to_elements(is_array($columns) ? $columns : [], $record);
            $record->headingheight = $converted['headingheight'];
            $DB->update_record('local_transcript_template', $record);
            foreach (array_merge($converted['heading'], $converted['row']) as $element) {
                \local_transcript\template::insert_starter_element((int) $record->id, $element['region'], $element['type'],
                    $element['name'], $element['posx'], $element['posy'], $element['width'], $element['height'], 0,
                    $element['data']);
            }
        }
        upgrade_plugin_savepoint(true, 2026102600, 'local', 'transcript');
    }

    if ($oldversion < 2026102700) {
        // The choices for who can use a verification code were reworded. The saved value stays valid.
        upgrade_plugin_savepoint(true, 2026102700, 'local', 'transcript');
    }

    if ($oldversion < 2026102800) {
        $table = new xmldb_table('local_transcript_template');
        $new = [
            new xmldb_field('topmargin', XMLDB_TYPE_NUMBER, '6, 1', null, XMLDB_NOTNULL, null, '10', 'rightmargin'),
            new xmldb_field('bottommargin', XMLDB_TYPE_NUMBER, '6, 1', null, XMLDB_NOTNULL, null, '10', 'topmargin'),
            new xmldb_field('lastyears', XMLDB_TYPE_INTEGER, '3', null, XMLDB_NOTNULL, null, '0', 'includeended'),
        ];
        foreach ($new as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026102800, 'local', 'transcript');
    }

    if ($oldversion < 2026102900) {
        $table = new xmldb_table('local_transcript_template');
        $new = [
            new xmldb_field('emailsubject', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'protection'),
            new xmldb_field('emailmessage', XMLDB_TYPE_TEXT, null, null, null, null, null, 'emailsubject'),
            new xmldb_field('emailmessageformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'emailmessage'),
        ];
        foreach ($new as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        // Layouts that exist already get the standard subject and message.
        foreach ($DB->get_records('local_transcript_template', null, '', 'id') as $record) {
            $record->emailsubject = get_string('email_defaultsubject', 'local_transcript');
            $record->emailmessage = get_string('email_defaultmessage', 'local_transcript');
            $record->emailmessageformat = FORMAT_HTML;
            $DB->update_record('local_transcript_template', $record);
        }
        upgrade_plugin_savepoint(true, 2026102900, 'local', 'transcript');
    }

    if ($oldversion < 2026103000) {
        // Nothing to change in the database: the settings page moved and the wording of some texts changed.
        upgrade_plugin_savepoint(true, 2026103000, 'local', 'transcript');
    }

    if ($oldversion < 2026103100) {
        // Nothing to change in the database: emails are sent with the email routine of Moodle again.
        upgrade_plugin_savepoint(true, 2026103100, 'local', 'transcript');
    }

    if ($oldversion < 2026103200) {
        // Nothing to change in the database: the email to the sender is one email now.
        upgrade_plugin_savepoint(true, 2026103200, 'local', 'transcript');
    }

    if ($oldversion < 2026103300) {
        // Nothing to change in the database. The steps above are now in the order of their versions.
        upgrade_plugin_savepoint(true, 2026103300, 'local', 'transcript');
    }

    if ($oldversion < 2026103400) {
        // Nothing to change in the database. Pictures can be added to the message of the email now.
        upgrade_plugin_savepoint(true, 2026103400, 'local', 'transcript');
    }

    if ($oldversion < 2026103500) {
        // Nothing to change in the database. Grades that teachers have hidden are always shown as n/a.
        upgrade_plugin_savepoint(true, 2026103500, 'local', 'transcript');
    }

    if ($oldversion < 2026103600) {
        // Nothing to change in the database. An observer now reports why an email could not be sent.
        upgrade_plugin_savepoint(true, 2026103600, 'local', 'transcript');
    }

    return true;
}
