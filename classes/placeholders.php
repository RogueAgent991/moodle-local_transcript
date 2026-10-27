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
 * The {{placeholders}} that can be typed in text elements.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class placeholders {

    /** @var string[] Placeholders that read a field of the student. */
    private const USER_FIELDS = [
        'firstname', 'lastname', 'middlename', 'username', 'email', 'idnumber', 'institution', 'department', 'city',
        'country', 'phone1', 'phone2', 'address',
    ];

    /**
     * The placeholders, for the help in the layout designer.
     *
     * @return array placeholder => description
     */
    public static function get_list(): array {
        $list = [
            'studentname' => get_string('placeholder_studentname', 'local_transcript'),
            'firstname' => get_string('placeholder_firstname', 'local_transcript'),
            'lastname' => get_string('placeholder_lastname', 'local_transcript'),
            'username' => get_string('placeholder_username', 'local_transcript'),
            'email' => get_string('placeholder_email', 'local_transcript'),
            'idnumber' => get_string('placeholder_idnumber', 'local_transcript'),
            'institution' => get_string('placeholder_institution', 'local_transcript'),
            'department' => get_string('placeholder_department', 'local_transcript'),
            'city' => get_string('placeholder_city', 'local_transcript'),
            'country' => get_string('placeholder_country', 'local_transcript'),
            'profile:shortname' => get_string('placeholder_profile', 'local_transcript'),
            'issuedate' => get_string('placeholder_issuedate', 'local_transcript'),
            'verificationcode' => get_string('placeholder_verificationcode', 'local_transcript'),
            'verificationurl' => get_string('placeholder_verificationurl', 'local_transcript'),
            'pagenumber' => get_string('placeholder_pagenumber', 'local_transcript'),
            'totalpages' => get_string('placeholder_totalpages', 'local_transcript'),
            'sitename' => get_string('placeholder_sitename', 'local_transcript'),
            'coursecount' => get_string('placeholder_coursecount', 'local_transcript'),
            'completedcount' => get_string('placeholder_completedcount', 'local_transcript'),
            'inprogresscount' => get_string('placeholder_inprogresscount', 'local_transcript'),
            'transcriptduration' => get_string('placeholder_transcriptduration', 'local_transcript'),
        ];
        return $list;
    }

    /**
     * The list of the placeholders as HTML, for the help under a field.
     *
     * @return string
     */
    public static function get_help_html(): string {
        $items = '';
        foreach (self::get_list() as $placeholder => $description) {
            $items .= \html_writer::tag('li', \html_writer::tag('code', '{{' . $placeholder . '}}') . ' - ' .
                s($description));
        }
        $summary = \html_writer::tag('summary', get_string('placeholders_show', 'local_transcript'));
        return \html_writer::tag('details', $summary . \html_writer::tag('ul', $items, ['class' => 'mt-2']));
    }

    /**
     * Replace the placeholders in a text.
     *
     * Placeholders that are not known are left alone, so that a typing mistake is easy to see in the preview.
     *
     * @param string $text
     * @param array $model The data of the transcript: user, courses, code, issuedate, sitename and template.
     * @param int $page The number of the page that is being drawn.
     * @param int $totalpages The number of pages of the transcript.
     * @return string
     */
    public static function replace(string $text, array $model, int $page = 1, int $totalpages = 1,
            bool $escapehtml = false): string {
        if (strpos($text, '{{') === false) {
            return $text;
        }
        $dateformat = $model['template']['dateformat'] ?? 'long';

        return preg_replace_callback('/\{\{\s*([A-Za-z0-9_:\-]+)\s*\}\}/', function (array $match) use (
            $model, $page, $totalpages, $dateformat, $escapehtml
        ) {
            $value = self::get_value($match, $model, $page, $totalpages, $dateformat);
            return $escapehtml ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
        }, $text);
    }

    /**
     * The text for one placeholder.
     *
     * @param array $match The match of the regular expression: the whole placeholder and its name.
     * @param array $model
     * @param int $page
     * @param int $totalpages
     * @param string $dateformat
     * @return string
     */
    private static function get_value(array $match, array $model, int $page, int $totalpages, string $dateformat): string {
        {
            $key = strtolower($match[1]);
            $user = $model['user'] ?? [];

            if ($key === 'studentname') {
                return (string) ($user['fullname'] ?? '');
            }
            if (in_array($key, self::USER_FIELDS)) {
                return (string) ($user[$key] ?? '');
            }
            if (strpos($key, 'profile:') === 0) {
                // Short names of profile fields are case sensitive, so look at the original spelling.
                $shortname = substr(trim($match[1]), 8);
                foreach (($user['profile'] ?? []) as $name => $value) {
                    if (strcasecmp($name, $shortname) === 0) {
                        return (string) $value;
                    }
                }
                return '';
            }

            switch ($key) {
                case 'issuedate':
                    return helper::format_date($model['issuedate'] ?? time(), $dateformat);
                case 'verificationcode':
                    return (string) ($model['code'] ?? '');
                case 'verificationurl':
                    return helper::get_verify_url((string) ($model['code'] ?? ''));
                case 'pagenumber':
                    return (string) $page;
                case 'totalpages':
                    return (string) $totalpages;
                case 'sitename':
                    return (string) ($model['sitename'] ?? '');
                case 'coursecount':
                    return (string) count($model['courses'] ?? []);
                case 'completedcount':
                    return (string) self::count_status($model, data_collector::STATUS_COMPLETED);
                case 'transcriptduration':
                    $years = (int) ($model['template']['lastyears'] ?? 0);
                    return $years <= 0 ? get_string('allcourses', 'local_transcript')
                        : get_string($years === 1 ? 'oneyear' : 'numyears', 'local_transcript', $years);
                case 'inprogresscount':
                    return (string) self::count_status($model, data_collector::STATUS_INPROGRESS);
            }
            return $match[0];
        }
    }

    /**
     * Count the courses with a status.
     *
     * @param array $model
     * @param string $status
     * @return int
     */
    private static function count_status(array $model, string $status): int {
        $count = 0;
        foreach (($model['courses'] ?? []) as $course) {
            if (($course['status'] ?? '') === $status) {
                $count++;
            }
        }
        return $count;
    }
}
