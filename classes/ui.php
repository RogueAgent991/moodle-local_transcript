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
 * Small pieces of screen shared by several pages.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ui {

    /**
     * Set up the page. All pages of the plugin live in the system context.
     *
     * @param string $path Path starting with /local/transcript/
     * @param array $params
     * @param string $title
     * @param string $layout The page layout, admin for the pages of the people who design layouts.
     */
    public static function setup_page(string $path, array $params, string $title, string $layout = 'standard'): void {
        global $PAGE;
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url(new \moodle_url($path, $params));
        $PAGE->set_pagelayout($layout);
        $PAGE->set_title($title);
        $PAGE->set_heading($title);
    }

    /**
     * The tabs shown while a layout is being designed: the master control, the three sections and the preview.
     *
     * @param int $templateid
     * @param string $active One of master, header, transcript, footer or preview.
     * @return string
     */
    public static function template_tabs(int $templateid, string $active): string {
        global $OUTPUT;
        $tabs = [];
        $tabs[] = new \tabobject('master', new \moodle_url('/local/transcript/template.php', ['id' => $templateid]),
            get_string('tab_master', 'local_transcript'));
        foreach (['header', 'transcript', 'footer'] as $section) {
            $tabs[] = new \tabobject($section, new \moodle_url('/local/transcript/designer.php',
                ['id' => $templateid, 'section' => $section]), get_string('tab_' . $section, 'local_transcript'));
        }
        $tabs[] = new \tabobject('preview', new \moodle_url('/local/transcript/preview.php', ['id' => $templateid]),
            get_string('tab_preview', 'local_transcript'));
        return $OUTPUT->tabtree($tabs, $active);
    }

    /**
     * The regions that are shown on the page of a section.
     *
     * @param string $section header, transcript or footer.
     * @return string[]
     */
    public static function get_regions_of_section(string $section): array {
        if ($section === 'transcript') {
            return [helper::REGION_HEADING, helper::REGION_ROW];
        }
        return [$section];
    }

    /**
     * The section (tab) that a region belongs to.
     *
     * @param string $region
     * @return string header, transcript or footer.
     */
    public static function get_section_of_region(string $region): string {
        if ($region === helper::REGION_HEADER || $region === helper::REGION_FOOTER) {
            return $region;
        }
        return 'transcript';
    }

    /**
     * The pictures of the parts of a section that has more than one part, one below the other without a gap, so
     * that they look like the top of the transcript section.
     *
     * @param \stdClass $template
     * @param string[] $regions
     * @return string HTML
     */
    public static function render_combined_canvas(\stdClass $template, array $regions): string {
        $out = \html_writer::start_div('lt-combined');
        foreach ($regions as $region) {
            if (helper::get_section_height($template, $region) <= 0) {
                continue;
            }
            $elements = template::get_elements((int) $template->id, $region);
            if ($region !== helper::REGION_ROW) {
                $out .= self::render_canvas($template, $region, $elements);
                continue;
            }
            // Four courses, so that the spacing and the colours of alternating rows can be seen. Only the first
            // course can be dragged, the others follow it.
            $samples = array_slice(data_collector::get_sample_data()['courses'], 0, 4);
            foreach ($samples as $index => $sample) {
                $out .= self::render_canvas($template, $region, $elements, $sample, $index + 1, $index > 0);
            }
        }
        $out .= \html_writer::end_div();
        return $out;
    }

    /**
     * A picture of one part of the page, with its elements. The elements can be dragged (see amd/src/designer.js).
     *
     * @param \stdClass $template
     * @param string $region One of the helper::REGION_ constants.
     * @param \stdClass[] $elements The elements of this region.
     * @param array|null $sample A made up course for the course fields. Null takes the first one.
     * @param int $number The number of the course, when the part is one of several rows.
     * @param bool $follower True for a copy that cannot be dragged and shows what the real element does.
     * @return string HTML
     */
    public static function render_canvas(\stdClass $template, string $region, array $elements, ?array $sample = null,
            int $number = 0, bool $follower = false): string {
        [$pw] = helper::get_template_dimensions($template);
        $sectionheight = max(helper::get_section_height($template, $region), 6.0);
        $pct = function (float $value, float $total): string {
            return round($value / $total * 100, 3) . '%';
        };

        $style = 'aspect-ratio: ' . $pw . ' / ' . $sectionheight . ';';
        if ($number && !empty($template->stripe) && $number % 2 === 0) {
            // The same rows as in the PDF are coloured.
            $style .= 'background-color:' . $template->stripecolour . ';background-image:none;';
        }
        $out = \html_writer::start_div('lt-canvas lt-canvas-' . $region . ($number ? ' lt-canvas-row' : ''), [
            'data-pagewidth' => $pw,
            'data-sectionheight' => $sectionheight,
            'style' => $style,
        ]);
        if ($number && !empty($template->rowlines)) {
            $out .= \html_writer::div('', 'lt-rowline', ['style' => 'border-bottom-color:' . $template->linecolour]);
        }
        // The margins.
        foreach ([(float) $template->leftmargin, $pw - (float) $template->rightmargin] as $position) {
            $out .= \html_writer::div('', 'lt-margin', ['style' => 'left:' . $pct($position, $pw)]);
        }
        // The top boundary of the header and the bottom boundary of the footer, drawn like the side margins.
        if ($region === helper::REGION_HEADER) {
            $inset = min((float) $template->topmargin, $sectionheight);
            $out .= \html_writer::div('', 'lt-margin lt-margin-top', ['style' => 'top:' . $pct($inset, $sectionheight)]);
        } else if ($region === helper::REGION_FOOTER) {
            $inset = min((float) $template->bottommargin, $sectionheight);
            $out .= \html_writer::div('', 'lt-margin lt-margin-bottom',
                ['style' => 'top:' . $pct($sectionheight - $inset, $sectionheight)]);
        }
        $sample = $sample ?? data_collector::get_sample_data()['courses'][0];
        foreach ($elements as $element) {
            $out .= self::render_canvas_element($element, $template, $pw, $sectionheight, $pct, $sample, $number ?: 1,
                $follower);
        }
        $out .= \html_writer::end_div();
        return $out;
    }

    /**
     * One element on the picture of a part of the page.
     *
     * @param \stdClass $element
     * @param \stdClass $template
     * @param float $pw Page width.
     * @param float $sh Height of the part of the page.
     * @param callable $pct Turns millimetres into a percentage.
     * @param array $sample A made up course, to show what course fields look like.
     * @param int $number The number of the course.
     * @param bool $follower A copy that cannot be dragged.
     * @return string
     */
    private static function render_canvas_element(\stdClass $element, \stdClass $template, float $pw, float $sh,
            callable $pct, array $sample, int $number = 1, bool $follower = false): string {
        $fixed = in_array($element->region, [helper::REGION_ROW, helper::REGION_HEADING]);
        $width = (float) $element->width;
        if ($width <= 0) {
            $width = max(10.0, $pw - (float) $element->posx - (float) $template->rightmargin);
        }
        $data = $element->data + template::get_element_default_data($element->type);

        $style = 'left:' . $pct((float) $element->posx, $pw) . ';top:' . $pct((float) $element->posy, $sh) .
            ';width:' . $pct($width, $pw) . ';';
        $attributes = [
            'data-id' => $element->id,
            'data-regiontop' => 0,
            'data-regionheight' => $sh,
            'data-posx' => $element->posx,
            'data-posy' => $element->posy,
            'data-width' => $width,
            'data-editurl' => (new \moodle_url('/local/transcript/element.php', ['id' => $element->id]))->out(false),
            'title' => $element->name,
        ];

        switch ($element->type) {
            case 'text':
            case 'coursefield':
                $attributes['data-fontsize'] = $data['fontsize'];
                $style .= 'color:' . $data['colour'] . ';text-align:' . ($data['align'] === 'C' ? 'center' :
                    ($data['align'] === 'R' ? 'right' : 'left')) . ';';
                if (strpos($data['fontstyle'], 'B') !== false) {
                    $style .= 'font-weight:bold;';
                }
                if (strpos($data['fontstyle'], 'I') !== false) {
                    $style .= 'font-style:italic;';
                }
                if ($element->type === 'coursefield') {
                    $content = s($data['prefix'] . helper::get_course_field_text($sample, $data['field'], $number,
                        $template->dateformat, isset($data['decimals']) ? (int) $data['decimals'] : null));
                } else if (helper::has_rich_text((string) $data['text'])) {
                    // Links and simple formatting, as in the PDF. Links are shown but cannot be clicked here.
                    $content = placeholders::replace(helper::sanitise_rich_text((string) $data['text']),
                        ['user' => ['fullname' => 'Alex Morgan'], 'code' => 'XXXX-XXXX-XXXX', 'courses' => [],
                        'sitename' => get_site()->fullname, 'issuedate' => time(), 'template' => (array) $template],
                        1, 1, true);
                    $style .= 'white-space:normal;';
                } else {
                    $content = s($data['text']);
                }
                if ($fixed) {
                    $height = (float) $element->height;
                    $style .= $height > 0 ? 'height:' . $pct($height, $sh) . ';' : 'white-space:nowrap;text-overflow:ellipsis;';
                }
                break;
            case 'image':
            case 'userpicture':
                $height = (float) $element->height;
                $style .= 'height:' . $pct($height > 0 ? $height : ($element->type === 'image' ? 15.0 : 30.0), $sh) . ';';
                $file = $element->type === 'image' ? template::get_first_file(template::FILEAREA_IMAGE, (int) $element->id) : null;
                if ($file) {
                    $url = \moodle_url::make_pluginfile_url($file->get_contextid(), 'local_transcript',
                        template::FILEAREA_IMAGE, $file->get_itemid(), $file->get_filepath(), $file->get_filename());
                    $content = \html_writer::empty_tag('img', ['src' => $url->out(false), 'alt' => '',
                        'draggable' => 'false']);
                } else {
                    $content = s($element->type === 'image' ? $element->name : get_string('element_userpicture', 'local_transcript'));
                    $style .= 'background:#dee2e6;color:#495057;font-size:0.75rem;text-align:center;';
                }
                break;
            case 'line':
                $style .= 'height:' . $pct(max(0.3, (float) $data['thickness']), $sh) . ';background:' . $data['colour'] . ';';
                $content = '';
                break;
            case 'rectangle':
                $style .= 'height:' . $pct((float) $element->height, $sh) . ';border:1px solid ' . $data['colour'] . ';';
                if (!empty($data['fill'])) {
                    $style .= 'background:' . $data['fillcolour'] . ';';
                }
                $content = '';
                break;
            default:
                // The QR code is square.
                $style .= 'aspect-ratio:1;';
                $content = 'QR';
        }
        if ($follower) {
            // Not draggable: moves when the element of the first course is moved.
            unset($attributes['data-id'], $attributes['data-editurl']);
            $attributes['data-followid'] = $element->id;
        }
        $attributes['style'] = $style;
        return \html_writer::div($content, 'lt-el lt-el-' . $element->type, $attributes);
    }
}
