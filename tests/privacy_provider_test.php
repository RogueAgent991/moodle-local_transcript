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


namespace local_transcript\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;

/**
 * Tests of the privacy export.
 *
 * @package    local_transcript
 * @category   test
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_transcript\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {

    /**
     * Somebody who generated the transcript of a student gets no data of the student in the export.
     */
    public function test_issuer_does_not_get_transcript_of_student(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $student = $generator->create_user();
        $teacher = $generator->create_user();

        $snapshot = json_encode([
            'user' => ['fullname' => 'Secret Student'],
            'courses' => [['fullname' => 'Secret Course', 'shortname' => 'SC', 'status' => 'completed', 'grade' => '97']],
        ]);
        $DB->insert_record('local_transcript_issue', (object) [
            'userid' => $student->id, 'issuedby' => $teacher->id, 'templateid' => 1, 'templatename' => 'Layout',
            'code' => 'AAAA-BBBB-CCCC', 'revoked' => 0, 'snapshot' => $snapshot, 'timecreated' => time(),
        ]);

        $context = \context_system::instance();

        // The teacher: only that a transcript was generated, and when.
        $this->export_context_data_for_user($teacher->id, $context, 'local_transcript');
        $teacherdata = json_encode(writer::with_context($context)->get_data([get_string('pluginname', 'local_transcript')]));
        $this->assertStringContainsString('Layout', $teacherdata);
        $this->assertStringNotContainsString('Secret', $teacherdata);
        $this->assertStringNotContainsString('AAAA-BBBB-CCCC', $teacherdata);

        // The student: the full transcript.
        writer::reset();
        $this->export_context_data_for_user($student->id, $context, 'local_transcript');
        $studentdata = json_encode(writer::with_context($context)->get_data([get_string('pluginname', 'local_transcript')]));
        $this->assertStringContainsString('Secret Course', $studentdata);
        $this->assertStringContainsString('AAAA-BBBB-CCCC', $studentdata);
    }
}
