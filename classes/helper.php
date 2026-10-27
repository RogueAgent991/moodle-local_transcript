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
 * Lists of options and small utility functions shared by the whole plugin.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {

    /** @var string Region names. */
    public const REGION_HEADER = 'header';
    /** @var string Region names. */
    public const REGION_FOOTER = 'footer';
    /** @var string The band with the column headings at the top of the transcript section of every page. */
    public const REGION_HEADING = 'transcript_heading';
    /** @var string The row that is repeated for every course in the transcript section. */
    public const REGION_ROW = 'transcript';

    /** @var int The element is shown on every page. */
    public const SHOWON_ALL = 0;
    /** @var int The element is shown on the first page only. */
    public const SHOWON_FIRST = 1;
    /** @var int The element is shown on the last page only. */
    public const SHOWON_LAST = 2;
    /** @var int The element is shown on every page except the first. */
    public const SHOWON_NOTFIRST = 3;

    /** @var string Verification access: anyone, even people who are not logged in. */
    public const VERIFY_PUBLIC = 'public';
    /** @var string Verification access: the learner of the transcript, course teachers and managers. */
    public const VERIFY_LEARNER = 'learner';
    /** @var string Verification access: any logged in user. */
    public const VERIFY_LOGGEDIN = 'loggedin';
    /** @var string Verification access: users with the verify capability. */
    public const VERIFY_CAPABILITY = 'capability';

    /** @var int The number of characters in a verification code (without the dashes). */
    public const CODE_LENGTH = 12;

    /** @var string Letters used in verification codes. No 0, O, 1, I or L so they are easy to read out. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /**
     * Page sizes that can be chosen, with the width and height in millimetres (portrait).
     *
     * @return array
     */
    public static function get_page_sizes(): array {
        return [
            'A4' => [210.0, 297.0],
            'LETTER' => [215.9, 279.4],
            'LEGAL' => [215.9, 355.6],
        ];
    }

    /**
     * Width and height of a page in millimetres, taking the orientation into account.
     *
     * @param string $pagesize One of the keys of get_page_sizes().
     * @param string $orientation P or L.
     * @return float[] Width and height.
     */
    public static function get_page_dimensions(string $pagesize, string $orientation): array {
        $sizes = self::get_page_sizes();
        [$w, $h] = $sizes[$pagesize] ?? $sizes['A4'];
        return $orientation === 'L' ? [$h, $w] : [$w, $h];
    }

    /**
     * Width and height of the page of a layout in millimetres. A layout either has one of the standard paper sizes
     * or its own width and height.
     *
     * @param \stdClass|array $template The settings of the layout.
     * @return float[] Width and height.
     */
    public static function get_template_dimensions($template): array {
        $t = (object) $template;
        if (($t->pagesize ?? '') === 'CUSTOM') {
            return [max(50.0, (float) ($t->customwidth ?? 210)), max(50.0, (float) ($t->customheight ?? 297))];
        }
        return self::get_page_dimensions($t->pagesize ?? 'A4', $t->orientation ?? 'P');
    }

    /**
     * The height in millimetres of the part of the page that an element is placed in. Elements are positioned from the
     * top of their part.
     *
     * - header and footer: as set in the master control.
     * - transcript_heading: the band with the column headings.
     * - transcript: one course.
     *
     * @param \stdClass|array $template
     * @param string $region
     * @return float
     */
    public static function get_section_height($template, string $region): float {
        $t = (object) $template;
        switch ($region) {
            case self::REGION_HEADER:
                return (float) $t->headerheight;
            case self::REGION_FOOTER:
                return (float) $t->footerheight;
            case self::REGION_HEADING:
                return (float) ($t->headingheight ?? 0);
        }
        return (float) $t->rowheight;
    }

    /**
     * The height of the transcript section: what is left of the page after the header and the footer.
     *
     * @param \stdClass|array $template
     * @return float
     */
    public static function get_transcript_height($template): float {
        $t = (object) $template;
        [, $height] = self::get_template_dimensions($t);
        return $height - (float) $t->headerheight - (float) $t->footerheight;
    }

    /**
     * The fonts that can be used. Only fonts that are really present are returned.
     *
     * @return array font key => display name
     */
    public static function get_fonts(): array {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');
        $path = \TCPDF_FONTS::_getfontpath();
        $candidates = [
            'helvetica' => 'Helvetica',
            'times' => 'Times',
            'courier' => 'Courier',
            'freesans' => 'FreeSans (Unicode)',
            'freeserif' => 'FreeSerif (Unicode)',
            'freemono' => 'FreeMono (Unicode)',
        ];
        $fonts = [];
        foreach ($candidates as $key => $name) {
            if (file_exists($path . $key . '.php')) {
                $fonts[$key] = $name;
            }
        }
        if (!$fonts) {
            $fonts['helvetica'] = 'Helvetica';
        }
        return $fonts;
    }

    /**
     * The font that new layouts start with. FreeSans is used when it is installed because it shows the accented
     * and non-Latin letters of names (for example Polish, Turkish or Cyrillic names). The basic PDF fonts, such
     * as Helvetica, cannot show those and print a question mark instead.
     *
     * @return string
     */
    public static function get_default_font(): string {
        return array_key_exists('freesans', self::get_fonts()) ? 'freesans' : 'helvetica';
    }

    /**
     * Font styles, as understood by TCPDF.
     *
     * @return array
     */
    public static function get_font_styles(): array {
        return [
            '' => get_string('fontstyle_regular', 'local_transcript'),
            'B' => get_string('fontstyle_bold', 'local_transcript'),
            'I' => get_string('fontstyle_italic', 'local_transcript'),
            'BI' => get_string('fontstyle_bolditalic', 'local_transcript'),
        ];
    }

    /**
     * Text alignments.
     *
     * @return array
     */
    public static function get_alignments(): array {
        return [
            'L' => get_string('alignleft', 'local_transcript'),
            'C' => get_string('aligncenter', 'local_transcript'),
            'R' => get_string('alignright', 'local_transcript'),
        ];
    }

    /**
     * The ways a date can be written. The value is a strftime style format.
     *
     * @return array key => strftime format
     */
    public static function get_date_formats(): array {
        return [
            'long' => '%d %B %Y',
            'us_long' => '%B %d, %Y',
            'iso' => '%Y-%m-%d',
            'uk' => '%d/%m/%Y',
            'us' => '%m/%d/%Y',
        ];
    }

    /**
     * Human readable list of date formats, with an example for each.
     *
     * @return array key => text
     */
    public static function get_date_format_options(): array {
        $example = mktime(12, 0, 0, 9, 5, 2026);
        $options = [];
        foreach (self::get_date_formats() as $key => $format) {
            $options[$key] = self::format_date($example, $key);
        }
        return $options;
    }

    /**
     * Format a timestamp the way a template wants it. The server time zone is used, so that the result
     * does not depend on who generates the transcript.
     *
     * @param int $timestamp The timestamp. Empty values give an empty string.
     * @param string $formatkey One of the keys of get_date_formats().
     * @return string
     */
    public static function format_date($timestamp, string $formatkey = 'long'): string {
        if (empty($timestamp)) {
            return '';
        }
        $formats = self::get_date_formats();
        $format = $formats[$formatkey] ?? $formats['long'];
        return userdate((int) $timestamp, $format, \core_date::get_server_timezone());
    }

    /**
     * The PDF permissions an administrator can switch off.
     *
     * @return array permission => text
     */
    public static function get_protection_options(): array {
        return [
            'print' => get_string('protect_print', 'local_transcript'),
            'copy' => get_string('protect_copy', 'local_transcript'),
            'modify' => get_string('protect_modify', 'local_transcript'),
            'annot-forms' => get_string('protect_annot', 'local_transcript'),
            'assemble' => get_string('protect_assemble', 'local_transcript'),
        ];
    }

    /**
     * Turn the choices stored in a template into the list that TCPDF needs.
     *
     * @param string|null $stored Comma separated permissions that are blocked.
     * @return string[]
     */
    public static function get_blocked_permissions(?string $stored): array {
        $chosen = array_filter(array_map('trim', explode(',', (string) $stored)));
        $chosen = array_intersect($chosen, array_keys(self::get_protection_options()));
        $blocked = $chosen;
        if (in_array('annot-forms', $chosen)) {
            $blocked[] = 'fill-forms';
        }
        if (in_array('print', $chosen)) {
            $blocked[] = 'print-high';
        }
        return array_values(array_unique($blocked));
    }

    /**
     * The options for how a group of courses is shown.
     *
     * @return array
     */
    public static function get_groupby_options(): array {
        return [
            'none' => get_string('groupby_none', 'local_transcript'),
            'year' => get_string('groupby_year', 'local_transcript'),
            'category' => get_string('groupby_category', 'local_transcript'),
        ];
    }

    /**
     * The ways the courses can be sorted.
     *
     * @return array
     */
    public static function get_sort_options(): array {
        return [
            'startdate_asc' => get_string('sort_startdate_asc', 'local_transcript'),
            'startdate_desc' => get_string('sort_startdate_desc', 'local_transcript'),
            'name_asc' => get_string('sort_name_asc', 'local_transcript'),
            'completion_asc' => get_string('sort_completion_asc', 'local_transcript'),
        ];
    }

    /**
     * Where an element is shown.
     *
     * @return array
     */
    public static function get_showon_options(): array {
        return [
            self::SHOWON_ALL => get_string('showon_all', 'local_transcript'),
            self::SHOWON_FIRST => get_string('showon_first', 'local_transcript'),
            self::SHOWON_LAST => get_string('showon_last', 'local_transcript'),
            self::SHOWON_NOTFIRST => get_string('showon_notfirst', 'local_transcript'),
        ];
    }

    /**
     * All kinds of element.
     *
     * @return array type => text
     */
    public static function get_element_types(): array {
        return [
            'text' => get_string('element_text', 'local_transcript'),
            'coursefield' => get_string('element_coursefield', 'local_transcript'),
            'image' => get_string('element_image', 'local_transcript'),
            'userpicture' => get_string('element_userpicture', 'local_transcript'),
            'line' => get_string('element_line', 'local_transcript'),
            'rectangle' => get_string('element_rectangle', 'local_transcript'),
            'qrcode' => get_string('element_qrcode', 'local_transcript'),
        ];
    }

    /**
     * The regions (parts of the layout) that can hold elements.
     *
     * @return string[]
     */
    public static function get_regions(): array {
        return [self::REGION_HEADER, self::REGION_HEADING, self::REGION_ROW, self::REGION_FOOTER];
    }

    /**
     * The choices in the "add an element" menu of a region. The key is the type, followed by a colon and the name
     * of a ready made element when there is one, for example text:issuedate or coursefield:grade.
     *
     * @param string $region
     * @return array A list of groups, each a one item array of group name => [choice => text], as html_writer::select()
     *         wants it.
     */
    public static function get_add_options(string $region): array {
        $types = self::get_element_types();
        $basic = [
            'text' => $types['text'],
            'image' => $types['image'],
            'line' => $types['line'],
            'rectangle' => $types['rectangle'],
        ];
        if ($region === self::REGION_ROW) {
            $fields = [];
            foreach (self::get_column_fields() as $field => $label) {
                $fields['coursefield:' . $field] = $label;
            }
            return [[get_string('addgroup_course', 'local_transcript') => $fields],
                [get_string('addgroup_other', 'local_transcript') => $basic]];
        }
        if ($region === self::REGION_HEADING) {
            return [[get_string('addgroup_other', 'local_transcript') => $basic]];
        }
        $student = [];
        foreach (self::get_text_presets() as $key => $preset) {
            $student['text:' . $key] = $preset['label'];
        }
        $student['userpicture'] = $types['userpicture'];
        $basic['qrcode'] = $types['qrcode'];
        return [[get_string('addgroup_student', 'local_transcript') => $student],
            [get_string('addgroup_other', 'local_transcript') => $basic]];
    }

    /**
     * Ready made text elements for the header and the footer.
     *
     * @return array key => [label, text]
     */
    public static function get_text_presets(): array {
        return [
            'studentname' => ['label' => get_string('preset_studentname', 'local_transcript'), 'text' => '{{studentname}}'],
            'idnumber' => ['label' => get_string('preset_idnumber', 'local_transcript'), 'text' => '{{idnumber}}'],
            'email' => ['label' => get_string('preset_email', 'local_transcript'), 'text' => '{{email}}'],
            'issuedate' => ['label' => get_string('preset_issuedate', 'local_transcript'),
                'text' => get_string('starter_issued_text', 'local_transcript')],
            'code' => ['label' => get_string('preset_code', 'local_transcript'),
                'text' => get_string('starter_code_text', 'local_transcript')],
            'pageof' => ['label' => get_string('preset_pageof', 'local_transcript'),
                'text' => get_string('starter_page_text', 'local_transcript')],
            'sitename' => ['label' => get_string('preset_sitename', 'local_transcript'), 'text' => '{{sitename}}'],
        ];
    }

    /**
     * A sensible width in millimetres for a course field, used when the field is first added.
     *
     * @param string $field
     * @return float
     */
    public static function get_default_field_width(string $field): float {
        switch ($field) {
            case 'coursefullname':
                return 70.0;
            case 'coursedescription':
                return 90.0;
            case 'courseshortname':
            case 'courseidnumber':
                return 26.0;
            case 'startdate':
            case 'enddate':
            case 'enroldate':
            case 'completiondate':
                return 38.0;
            case 'rownumber':
            case 'year':
                return 14.0;
        }
        return 26.0;
    }

    /**
     * The text a course gives for a field of the course table.
     *
     * @param array $course A course as made by data_collector::get_courses().
     * @param string $field
     * @param int $number The number of the row, starting at 1.
     * @param string $dateformat One of the keys of get_date_formats().
     * @param int|null $decimals The number of decimal places of grades. Null leaves the grade as it is.
     * @return string
     */
    public static function get_course_field_text(array $course, string $field, int $number, string $dateformat,
            ?int $decimals = null): string {
        switch ($field) {
            case 'rownumber':
                return (string) $number;
            case 'coursefullname':
                return (string) ($course['fullname'] ?? '');
            case 'courseshortname':
                return (string) ($course['shortname'] ?? '');
            case 'courseidnumber':
                return (string) ($course['idnumber'] ?? '');
            case 'coursedescription':
                return (string) ($course['summary'] ?? '');
            case 'coursecategory':
                return (string) ($course['category'] ?? '');
            case 'year':
                return !empty($course['year']) ? (string) $course['year'] : '';
            case 'completiondate':
                if (empty($course['completiondate'])) {
                    // No date: say why. A course that is still going on is in progress, any other was not completed.
                    return get_string(($course['status'] ?? '') === 'inprogress' ? 'status_inprogress' : 'status_ended',
                        'local_transcript');
                }
                return self::format_date($course['completiondate'], $dateformat);
            case 'startdate':
            case 'enddate':
            case 'enroldate':
                return self::format_date($course[$field] ?? 0, $dateformat);
            case 'status':
                return get_string('status_' . ($course['status'] ?? 'inprogress'), 'local_transcript');
            case 'grade':
            case 'gradepercent':
                $text = (string) ($course[$field] ?? '');
                return $text === '' ? '-' : ($decimals === null ? $text : self::round_numbers($text, $decimals));
            case 'gradeletter':
                return ($course[$field] ?? '') !== '' ? (string) $course[$field] : '-';
            case 'passfail':
                $passfail = $course['passfail'] ?? 'none';
                return $passfail === 'none' ? '-' : get_string('passfail_' . $passfail, 'local_transcript');
        }
        if (strpos($field, 'cf:') === 0) {
            return (string) ($course['cf'][substr($field, 3)] ?? '');
        }
        return '';
    }

    /**
     * Does a text written in a text element use links or simple formatting (a, b, i, u, br)?
     *
     * @param string $text
     * @return bool
     */
    public static function has_rich_text(string $text): bool {
        return (bool) preg_match('~<\s*/?\s*(a|b|i|u|br)\b[^>]*>~i', $text);
    }

    /**
     * Make the HTML of a text element safe. Only links (with an address that starts with http, https or mailto, or
     * the placeholder {{verificationurl}}), bold, italic, underline and line breaks are kept. Everything else that
     * looks like a tag is shown as plain text. Placeholders are left for placeholders::replace().
     *
     * @param string $text What the administrator typed.
     * @return string HTML
     */
    public static function sanitise_rich_text(string $text): string {
        $parts = preg_split('~(<[^<>]*>)~', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $out = '';
        $openlinks = 0;
        foreach ($parts as $part) {
            if ($part[0] !== '<' || substr($part, -1) !== '>') {
                $out .= htmlspecialchars($part, ENT_QUOTES, 'UTF-8', false);
                continue;
            }
            if (preg_match('~^<\s*(b|i|u)\s*>$~i', $part, $m)) {
                $out .= '<' . strtolower($m[1]) . '>';
            } else if (preg_match('~^<\s*/\s*(b|i|u)\s*>$~i', $part, $m)) {
                $out .= '</' . strtolower($m[1]) . '>';
            } else if (preg_match('~^<\s*br\s*/?\s*>$~i', $part)) {
                $out .= '<br>';
            } else if (preg_match('~^<\s*a\s+[^>]*href\s*=\s*(?:"([^"]*)"|\'([^\']*)\')[^>]*>$~i', $part, $m)) {
                $href = trim(html_entity_decode($m[1] !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES, 'UTF-8'));
                if (preg_match('~^(\{\{\s*verificationurl\s*\}\}|https?://[^\s"<>]+|mailto:[^\s"<>]+)$~i', $href)) {
                    $out .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">';
                    $openlinks++;
                } else {
                    $out .= htmlspecialchars($part, ENT_QUOTES, 'UTF-8', false);
                }
            } else if (preg_match('~^<\s*/\s*a\s*>$~i', $part)) {
                if ($openlinks > 0) {
                    $out .= '</a>';
                    $openlinks--;
                }
            } else {
                $out .= htmlspecialchars($part, ENT_QUOTES, 'UTF-8', false);
            }
        }
        $out .= str_repeat('</a>', $openlinks);
        return nl2br($out, false);
    }

    /**
     * Round the numbers in a text, for example "85.456 %" becomes "85.46 %".
     *
     * @param string $text
     * @param int $decimals The number of decimal places.
     * @return string
     */
    public static function round_numbers(string $text, int $decimals): string {
        $decimals = max(0, min(6, $decimals));
        return preg_replace_callback('/\d+(?:[.,]\d+)?/u', function (array $match) use ($decimals): string {
            $usecomma = strpos($match[0], ',') !== false;
            $value = round((float) str_replace(',', '.', $match[0]), $decimals);
            $out = number_format($value, $decimals, $usecomma ? ',' : '.', '');
            return $out;
        }, $text);
    }

    /**
     * The information about a course that can be placed in the transcript section.
     *
     * Custom course fields are added at the end, they are written as cf:shortname.
     *
     * @return array field => text
     */
    public static function get_column_fields(): array {
        $fields = [
            'rownumber' => get_string('field_rownumber', 'local_transcript'),
            'coursefullname' => get_string('field_coursefullname', 'local_transcript'),
            'courseshortname' => get_string('field_courseshortname', 'local_transcript'),
            'courseidnumber' => get_string('field_courseidnumber', 'local_transcript'),
            'coursedescription' => get_string('field_coursedescription', 'local_transcript'),
            'coursecategory' => get_string('field_coursecategory', 'local_transcript'),
            'year' => get_string('field_year', 'local_transcript'),
            'startdate' => get_string('field_startdate', 'local_transcript'),
            'enddate' => get_string('field_enddate', 'local_transcript'),
            'enroldate' => get_string('field_enroldate', 'local_transcript'),
            'completiondate' => get_string('field_completiondate', 'local_transcript'),
            'status' => get_string('field_status', 'local_transcript'),
            'grade' => get_string('field_grade', 'local_transcript'),
            'gradepercent' => get_string('field_gradepercent', 'local_transcript'),
            'gradeletter' => get_string('field_gradeletter', 'local_transcript'),
            'passfail' => get_string('field_passfail', 'local_transcript'),
        ];
        foreach (self::get_course_custom_fields() as $shortname => $name) {
            $fields['cf:' . $shortname] = get_string('field_customfield', 'local_transcript', $name);
        }
        return $fields;
    }

    /**
     * The custom course fields that exist on this site.
     *
     * @return array shortname => name
     */
    public static function get_course_custom_fields(): array {
        $result = [];
        try {
            $handler = \core_course\customfield\course_handler::create();
            foreach ($handler->get_fields() as $field) {
                $result[$field->get('shortname')] = format_string($field->get('name'), true, ['context' => \context_system::instance()]);
            }
        } catch (\Throwable $e) {
            // Custom fields are optional. Anything going wrong here must never break the transcript.
            debugging($e->getMessage(), DEBUG_DEVELOPER);
        }
        return $result;
    }

    /**
     * The columns a new template starts with.
     *
     * @return array[]
     */
    public static function get_default_columns(): array {
        return [
            ['heading' => get_string('column_default_course', 'local_transcript'), 'field' => 'coursefullname',
                'width' => 68, 'align' => 'L'],
            ['heading' => get_string('column_default_code', 'local_transcript'), 'field' => 'courseshortname',
                'width' => 26, 'align' => 'L'],
            ['heading' => get_string('column_default_completed', 'local_transcript'), 'field' => 'completiondate',
                'width' => 40, 'align' => 'C'],
            ['heading' => get_string('column_default_grade', 'local_transcript'), 'field' => 'grade',
                'width' => 20, 'align' => 'C'],
            ['heading' => get_string('column_default_status', 'local_transcript'), 'field' => 'status',
                'width' => 26, 'align' => 'C'],
        ];
    }

    /**
     * Make a verification code, like K7Q2-M9XD-4TRB.
     *
     * @return string
     */
    public static function generate_code(): string {
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        return self::format_code($code);
    }

    /**
     * Put dashes in a code so that it reads XXXX-XXXX-XXXX.
     *
     * @param string $code
     * @return string
     */
    public static function format_code(string $code): string {
        return implode('-', str_split($code, 4));
    }

    /**
     * Clean up what a person typed as a code: no spaces, upper case and dashes in the right places.
     *
     * @param string $input
     * @return string
     */
    public static function normalise_code(string $input): string {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input));
        if (strlen($clean) !== self::CODE_LENGTH) {
            return $clean;
        }
        return self::format_code($clean);
    }

    /**
     * Turn text that may contain HTML (names run through filters, for example) into plain text for the PDF.
     *
     * @param string|null $text
     * @return string
     */
    public static function plain_text(?string $text): string {
        $text = (string) $text;
        $text = preg_replace('~<br\s*/?>~i', ' ', $text);
        $text = strip_tags($text);
        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Check a colour written as #rrggbb.
     *
     * @param string $colour
     * @return bool
     */
    public static function is_valid_colour(string $colour): bool {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $colour);
    }

    /**
     * Turn #rrggbb into three numbers for TCPDF.
     *
     * @param string $colour
     * @return int[] red, green and blue
     */
    public static function colour_to_rgb(string $colour): array {
        if (!self::is_valid_colour($colour)) {
            return [0, 0, 0];
        }
        return [hexdec(substr($colour, 1, 2)), hexdec(substr($colour, 3, 2)), hexdec(substr($colour, 5, 2))];
    }

    /**
     * The address of the verification page for a code.
     *
     * @param string $code
     * @return string
     */
    public static function get_verify_url(string $code): string {
        return (new \moodle_url('/local/transcript/verify.php', ['code' => $code]))->out(false);
    }
}
