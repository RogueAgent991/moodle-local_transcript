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
 * Collects the information about a student and the courses of the student.
 *
 * Everything that is returned is made of plain text, numbers and arrays, so that it can be stored as JSON
 * in the snapshot of an issued transcript.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class data_collector {

    /** @var string The course is finished. */
    public const STATUS_COMPLETED = 'completed';
    /** @var string The student is working on the course. */
    public const STATUS_INPROGRESS = 'inprogress';
    /** @var string The enrolment has been suspended or has expired and the course was not completed. */
    public const STATUS_ENDED = 'ended';

    /**
     * Details of the student.
     *
     * @param int $userid
     * @return array
     */
    public static function get_user_info(int $userid): array {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        $countries = get_string_manager()->get_list_of_countries(true);

        $info = [
            'id' => (int) $user->id,
            'firstname' => (string) $user->firstname,
            'lastname' => (string) $user->lastname,
            'middlename' => (string) $user->middlename,
            'alternatename' => (string) $user->alternatename,
            'fullname' => helper::plain_text(fullname($user)),
            'username' => (string) $user->username,
            'email' => (string) $user->email,
            'idnumber' => (string) $user->idnumber,
            'institution' => (string) $user->institution,
            'department' => (string) $user->department,
            'city' => (string) $user->city,
            'country' => $countries[$user->country] ?? (string) $user->country,
            'phone1' => (string) $user->phone1,
            'phone2' => (string) $user->phone2,
            'address' => (string) $user->address,
            'profile' => [],
        ];

        foreach (profile_get_user_fields_with_data($userid) as $formfield) {
            $info['profile'][$formfield->field->shortname] = helper::plain_text($formfield->display_data());
        }
        return $info;
    }

    /**
     * The courses a student is enrolled in or has completed, with grade and status.
     *
     * @param int $userid
     * @param \stdClass|array $settings Template settings: includeinprogress, includeended, includehidden,
     *        lastyears, groupby and sortby.
     * @return array[] One array per course, sorted the way the template wants.
     */
    public static function get_courses(int $userid, $settings): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/querylib.php');

        $settings = (object) $settings;
        $now = time();

        // Enrolments, including the ones that are suspended or have expired.
        $enrolments = [];
        $sql = "SELECT e.courseid,
                       MIN(CASE WHEN ue.timestart > 0 THEN ue.timestart ELSE ue.timecreated END) AS enroldate,
                       MAX(CASE WHEN ue.status = :active AND e.status = :enabled AND ue.timestart < :now1
                                     AND (ue.timeend = 0 OR ue.timeend > :now2) THEN 1 ELSE 0 END) AS isactive
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = :userid
              GROUP BY e.courseid";
        $params = [
            'active' => ENROL_USER_ACTIVE, 'enabled' => ENROL_INSTANCE_ENABLED,
            'now1' => $now, 'now2' => $now, 'userid' => $userid,
        ];
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $enrolments[$row->courseid] = $row;
        }

        // Completed courses are kept in the transcript even when the student was unenrolled later.
        $completions = [];
        $records = $DB->get_records_select('course_completions', 'userid = ? AND timecompleted IS NOT NULL AND timecompleted > 0',
            [$userid], '', 'course, timecompleted');
        foreach ($records as $record) {
            $completions[$record->course] = (int) $record->timecompleted;
        }

        $courseids = array_unique(array_merge(array_keys($enrolments), array_keys($completions)));
        $courseids = array_diff($courseids, [SITEID]);
        if (!$courseids) {
            return [];
        }
        $courses = $DB->get_records_list('course', 'id', $courseids, '',
            'id, fullname, shortname, idnumber, category, startdate, enddate, visible, summary, summaryformat');

        $categories = [];
        $rows = [];
        foreach ($courses as $course) {
            $completed = $completions[$course->id] ?? 0;
            $enrolment = $enrolments[$course->id] ?? null;

            if ($completed) {
                $status = self::STATUS_COMPLETED;
            } else if ($enrolment && $enrolment->isactive) {
                $status = self::STATUS_INPROGRESS;
            } else {
                $status = self::STATUS_ENDED;
            }

            if (!$course->visible && empty($settings->includehidden)) {
                continue;
            }
            if ($status === self::STATUS_INPROGRESS && empty($settings->includeinprogress)) {
                continue;
            }
            if ($status === self::STATUS_ENDED && empty($settings->includeended)) {
                continue;
            }

            // Only the courses of the last years. A course that is still going on always counts.
            $lastyears = (int) ($settings->lastyears ?? 0);
            if ($lastyears > 0 && $status !== self::STATUS_INPROGRESS) {
                $reference = $completed ?: max((int) $course->enddate, (int) ($enrolment->enroldate ?? 0),
                    (int) $course->startdate);
                if ($reference && $reference < strtotime('-' . $lastyears . ' years')) {
                    continue;
                }
            }

            if (!array_key_exists($course->category, $categories)) {
                $category = \core_course_category::get($course->category, IGNORE_MISSING, true);
                $categories[$course->category] = $category ? helper::plain_text($category->get_formatted_name()) : '';
            }

            $context = \context_course::instance($course->id);
            $enroldate = $enrolment ? (int) $enrolment->enroldate : 0;
            $yearsource = $course->startdate ?: $enroldate;

            $row = [
                'id' => (int) $course->id,
                'fullname' => helper::plain_text(format_string($course->fullname, true, ['context' => $context])),
                'shortname' => helper::plain_text(format_string($course->shortname, true, ['context' => $context])),
                'idnumber' => (string) $course->idnumber,
                'summary' => self::get_summary($course, $context),
                'category' => $categories[$course->category],
                'year' => $yearsource ? (int) userdate($yearsource, '%Y', \core_date::get_server_timezone()) : 0,
                'startdate' => (int) $course->startdate,
                'enddate' => (int) $course->enddate,
                'enroldate' => $enroldate,
                'completiondate' => $completed,
                'status' => $status,
            ];
            $row += self::get_grade_info($userid, (int) $course->id, $completed > 0);
            $row['cf'] = self::get_course_custom_field_values((int) $course->id);
            $rows[] = $row;
        }

        return self::sort_courses($rows, $settings->groupby ?? 'none', $settings->sortby ?? 'startdate_asc');
    }

    /**
     * The description of a course as plain text.
     *
     * @param \stdClass $course Needs summary and summaryformat.
     * @param \context $context The context of the course.
     * @return string At most 1500 characters.
     */
    public static function get_summary(\stdClass $course, \context $context): string {
        if (trim((string) $course->summary) === '') {
            return '';
        }
        $text = format_text($course->summary, $course->summaryformat, ['context' => $context, 'noclean' => false]);
        $text = preg_replace('~</(p|div|li|h[1-6])>~i', '$0 ', $text);
        $text = preg_replace('/\s+/u', ' ', helper::plain_text($text));
        return \core_text::substr(trim($text), 0, 1500);
    }

    /**
     * The grade of a student in a course, in the ways a transcript can show it.
     *
     * @param int $userid
     * @param int $courseid
     * @param bool $completed Whether the student completed the course.
     * @return array grade, gradepercent, gradeletter and passfail
     */
    public static function get_grade_info(int $userid, int $courseid, bool $completed): array {
        $none = ['grade' => '', 'gradepercent' => '', 'gradeletter' => '', 'passfail' => 'none'];

        $grade = grade_get_course_grade($userid, $courseid);
        $item = \grade_item::fetch_course_item($courseid);
        if (!$grade || !$item || $item->gradetype == GRADE_TYPE_NONE) {
            // No grade is used in this course, but a completed course still counts as a pass.
            $none['passfail'] = $completed ? 'pass' : 'none';
            return $none;
        }
        if ($grade->hidden || $grade->item->hidden) {
            // A grade that the teachers have hidden is never shown.
            $na = get_string('notavailable', 'local_transcript');
            return ['grade' => $na, 'gradepercent' => $na, 'gradeletter' => $na, 'passfail' => 'none'];
        }
        if ($grade->grade === null || $grade->grade === false) {
            $none['passfail'] = $completed ? 'pass' : 'none';
            return $none;
        }

        $result = [];
        $result['grade'] = helper::plain_text($grade->str_grade);
        if ($item->gradetype == GRADE_TYPE_VALUE) {
            $result['gradepercent'] = helper::plain_text(
                grade_format_gradevalue($grade->grade, $item, true, GRADE_DISPLAY_TYPE_PERCENTAGE));
            $result['gradeletter'] = helper::plain_text(
                grade_format_gradevalue($grade->grade, $item, true, GRADE_DISPLAY_TYPE_LETTER));
        } else {
            // Scales (and text) cannot be turned into a percentage or a letter.
            $result['gradepercent'] = $result['grade'];
            $result['gradeletter'] = $result['grade'];
        }

        if ((float) $item->gradepass > 0) {
            $result['passfail'] = $grade->grade >= (float) $item->gradepass ? 'pass' : 'fail';
        } else {
            $result['passfail'] = $completed ? 'pass' : 'none';
        }
        return $result;
    }

    /**
     * The values of the custom course fields of a course.
     *
     * @param int $courseid
     * @return array shortname => text
     */
    public static function get_course_custom_field_values(int $courseid): array {
        $values = [];
        try {
            $handler = \core_course\customfield\course_handler::create();
            foreach ($handler->get_instance_data($courseid, true) as $data) {
                $shortname = $data->get_field()->get('shortname');
                $values[$shortname] = helper::plain_text((string) $data->export_value());
            }
        } catch (\Throwable $e) {
            debugging($e->getMessage(), DEBUG_DEVELOPER);
        }
        return $values;
    }

    /**
     * Sort courses, keeping the courses of a group together.
     *
     * @param array[] $rows
     * @param string $groupby none, year or category
     * @param string $sortby One of the keys of helper::get_sort_options()
     * @return array[]
     */
    public static function sort_courses(array $rows, string $groupby, string $sortby): array {
        $descending = $sortby === 'startdate_desc';
        $groupkey = function (array $row) use ($groupby) {
            if ($groupby === 'year') {
                return $row['year'];
            }
            if ($groupby === 'category') {
                return \core_text::strtolower($row['category']);
            }
            return 0;
        };
        $datekey = function (array $row): int {
            return (int) ($row['startdate'] ?: $row['enroldate']);
        };

        usort($rows, function (array $a, array $b) use ($groupby, $sortby, $descending, $groupkey, $datekey): int {
            if ($groupby !== 'none') {
                $ga = $groupkey($a);
                $gb = $groupkey($b);
                if ($ga !== $gb) {
                    // The years run the same direction as the courses. A year without a date goes last.
                    if ($groupby === 'year') {
                        if (!$ga || !$gb) {
                            return $ga ? -1 : 1;
                        }
                        return $descending ? $gb <=> $ga : $ga <=> $gb;
                    }
                    return strnatcmp((string) $ga, (string) $gb);
                }
            }
            switch ($sortby) {
                case 'name_asc':
                    $result = strnatcasecmp($a['fullname'], $b['fullname']);
                    break;
                case 'completion_asc':
                    $ca = $a['completiondate'] ?: PHP_INT_MAX;
                    $cb = $b['completiondate'] ?: PHP_INT_MAX;
                    $result = $ca <=> $cb;
                    break;
                case 'startdate_desc':
                    $result = $datekey($b) <=> $datekey($a);
                    break;
                default:
                    $result = $datekey($a) <=> $datekey($b);
            }
            return $result ?: strnatcasecmp($a['fullname'], $b['fullname']);
        });
        return array_values($rows);
    }

    /**
     * Made up information for the preview in the layout designer, so that a layout can be judged
     * before any real student exists. It is long enough to run over more than one page.
     *
     * @return array user, courses
     */
    public static function get_sample_data(): array {
        $user = [
            'id' => 0, 'firstname' => 'Alex', 'lastname' => 'Morgan', 'middlename' => '', 'alternatename' => '',
            'fullname' => 'Alex Morgan', 'username' => 'amorgan', 'email' => 'alex.morgan@example.com',
            'idnumber' => 'S1234567', 'institution' => 'Example College', 'department' => 'Science',
            'city' => 'Toronto', 'country' => 'Canada', 'phone1' => '', 'phone2' => '', 'address' => '',
            'profile' => [],
        ];

        $titles = [
            ['Introduction to Biology', 'BIO101', 'Science'], ['Calculus I', 'MAT110', 'Mathematics'],
            ['Academic Writing', 'ENG100', 'Humanities'], ['General Chemistry', 'CHM120', 'Science'],
            ['Statistics for Research', 'STA201', 'Mathematics'], ['World History', 'HIS150', 'Humanities'],
            ['Organic Chemistry', 'CHM220', 'Science'], ['Linear Algebra', 'MAT210', 'Mathematics'],
            ['Introduction to Psychology', 'PSY101', 'Social Sciences'], ['Ethics and Society', 'PHI130', 'Humanities'],
            ['Genetics', 'BIO230', 'Science'], ['Data Analysis with Python', 'CSC240', 'Computing'],
            ['Microeconomics', 'ECO101', 'Social Sciences'], ['Research Methods', 'RES300', 'Social Sciences'],
            ['Environmental Science', 'ENV250', 'Science'], ['Capstone Project', 'CAP400', 'Science'],
            ['Public Speaking', 'COM105', 'Humanities'], ['Cell Biology', 'BIO310', 'Science'],
        ];
        $grades = [
            ['92.00 %', 'A+', 'pass'], ['85.50 %', 'A', 'pass'], ['78.00 %', 'B+', 'pass'], ['Pass', 'Pass', 'pass'],
            ['66.00 %', 'C+', 'pass'], ['88.00 %', 'A', 'pass'], ['59.00 %', 'F', 'fail'], ['81.00 %', 'A-', 'pass'],
        ];

        $courses = [];
        $start = mktime(9, 0, 0, 9, 1, 2023);
        foreach ($titles as $i => [$name, $code, $category]) {
            $startdate = strtotime('+' . ($i * 4) . ' months', $start);
            $isactive = $i >= count($titles) - 2;
            [$gradetext, $letter, $passfail] = $grades[$i % count($grades)];
            $courses[] = [
                'id' => 1000 + $i,
                'fullname' => $name,
                'shortname' => $code,
                'idnumber' => $code,
                'summary' => 'An introduction to ' . strtolower($name) . ', covering the main ideas, methods and vocabulary '
                    . 'of the subject, with practical exercises and a final assessment.',
                'category' => $category,
                'year' => (int) date('Y', $startdate),
                'startdate' => $startdate,
                'enddate' => strtotime('+4 months', $startdate),
                'enroldate' => $startdate,
                'completiondate' => $isactive ? 0 : strtotime('+4 months -3 days', $startdate),
                'status' => $isactive ? self::STATUS_INPROGRESS : self::STATUS_COMPLETED,
                'grade' => $isactive ? '' : $gradetext,
                'gradepercent' => $isactive ? '' : ($gradetext === 'Pass' ? 'Pass' : $gradetext),
                'gradeletter' => $isactive ? '' : $letter,
                'passfail' => $isactive ? 'none' : $passfail,
                'cf' => [],
            ];
        }
        return ['user' => $user, 'courses' => $courses];
    }
}
