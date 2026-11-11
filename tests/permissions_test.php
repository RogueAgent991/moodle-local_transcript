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

namespace local_transcript;

/**
 * Tests of who may create transcripts.
 *
 * @package    local_transcript
 * @category   test
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_transcript\permissions
 * @covers     \local_transcript\data_collector
 */
final class permissions_test extends \advanced_testcase {
    /**
     * A student in two courses, a teacher in the first one and a manager of the whole site.
     *
     * @return \stdClass[] student, teacher, manager, other, coursea and courseb
     */
    private function create_people(): array {
        $generator = $this->getDataGenerator();
        $coursea = $generator->create_course(['fullname' => 'Course A']);
        $courseb = $generator->create_course(['fullname' => 'Course B']);
        $student = $generator->create_user();
        $teacher = $generator->create_user();
        $manager = $generator->create_user();
        $other = $generator->create_user();
        $generator->enrol_user($student->id, $coursea->id, 'student');
        $generator->enrol_user($student->id, $courseb->id, 'student');
        $generator->enrol_user($teacher->id, $coursea->id, 'editingteacher');
        $generator->role_assign('manager', $manager->id, \context_system::instance()->id);
        return compact('student', 'teacher', 'manager', 'other', 'coursea', 'courseb');
    }

    /**
     * Who may create the transcript of the student, with every group allowed (the default).
     */
    public function test_everybody_is_allowed_by_default(): void {
        $this->resetAfterTest();
        $people = $this->create_people();
        $student = (int) $people['student']->id;

        $this->setUser($people['teacher']);
        $this->assertTrue(permissions::can_generate_for($student));
        $this->setUser($people['manager']);
        $this->assertTrue(permissions::can_generate_for($student));
        $this->setUser($people['student']);
        $this->assertTrue(permissions::can_generate_for($student));
        $this->setUser($people['other']);
        $this->assertFalse(permissions::can_generate_for($student));
    }

    /**
     * Teachers can be stopped, and then the managers and learners can still create transcripts.
     */
    public function test_teachers_can_be_stopped(): void {
        $this->resetAfterTest();
        $people = $this->create_people();
        $student = (int) $people['student']->id;
        set_config('whocancreate', 'manager,learner', 'local_transcript');

        $this->setUser($people['teacher']);
        $this->assertFalse(permissions::can_generate_for($student));
        $this->assertFalse(permissions::can_search_students());
        $this->setUser($people['manager']);
        $this->assertTrue(permissions::can_generate_for($student));
        $this->setUser($people['student']);
        $this->assertTrue(permissions::can_generate_for($student));
    }

    /**
     * Managers can be stopped, and then the teachers and learners can still create transcripts.
     */
    public function test_managers_can_be_stopped(): void {
        $this->resetAfterTest();
        $people = $this->create_people();
        $student = (int) $people['student']->id;
        set_config('whocancreate', 'teacher,learner', 'local_transcript');

        $this->setUser($people['manager']);
        $this->assertFalse(permissions::can_generate_for($student));
        $this->setUser($people['teacher']);
        $this->assertTrue(permissions::can_generate_for($student));
    }

    /**
     * Learners can be stopped. Site administrators can always create transcripts.
     */
    public function test_learners_can_be_stopped(): void {
        $this->resetAfterTest();
        $people = $this->create_people();
        $student = (int) $people['student']->id;
        set_config('whocancreate', 'manager,teacher', 'local_transcript');

        $this->setUser($people['student']);
        $this->assertFalse(permissions::can_generate_for($student));
        $this->setAdminUser();
        $this->assertTrue(permissions::can_generate_for($student));
        set_config('whocancreate', '', 'local_transcript');
        $this->assertTrue(permissions::can_generate_for($student));
    }

    /**
     * A teacher who is allowed gets the full transcript: every course of the student.
     */
    public function test_allowed_teacher_gets_every_course(): void {
        $this->resetAfterTest();
        $people = $this->create_people();

        $this->setUser($people['teacher']);
        $settings = ['includeinprogress' => 1, 'includeended' => 1, 'includehidden' => 1];
        $courses = data_collector::get_courses((int) $people['student']->id, $settings);
        $this->assertCount(2, $courses);
    }
}
