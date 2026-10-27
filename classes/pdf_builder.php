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
 * Draws a transcript as a protected PDF.
 *
 * The page is made of three sections that always add up to the height of the page:
 *  - the header, the same on every page;
 *  - the transcript, which holds the courses of the student. A row with column headings is repeated at the top of the
 *    section on every page and a course row is repeated for every course. The courses continue on more pages when
 *    they do not fit;
 *  - the footer, the same on every page.
 *
 * The builder works in two steps: first it works out which rows go on which page (so that the total number of pages is
 * known, for "Page 2 of 3"), then it draws the pages.
 *
 * The model is an array with these keys:
 *  - template: the settings of the layout.
 *  - elements: the elements of all sections. The region of an element tells the section.
 *  - columns: only in layouts made before the three sections were introduced.
 *  - user: information about the student (see data_collector::get_user_info()).
 *  - courses: the courses (see data_collector::get_courses()).
 *  - issuedate, code, sitename and lang.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdf_builder {

    /** @var array The layout and the data. */
    private $model;

    /** @var callable Gives the stored file for 'watermark', 'background', 'userpicture' or an element id such as 'e12'. */
    private $imageprovider;

    /** @var \stdClass Template settings. */
    private $t;

    /** @var float Page width in millimetres. */
    private $pagewidth;

    /** @var float Page height in millimetres. */
    private $pageheight;

    /** @var array[] The elements, grouped by region. */
    private $elements = [];

    /** @var string[] Temporary files that are removed when the PDF is finished. */
    private $tempfiles = [];

    /** @var array Paths of pictures that were already copied to a temporary file, by key. */
    private $imagepaths = [];

    /** @var array[] The pages, each holding the lines of the transcript section. Filled by paginate(). */
    private $pages = [];

    /** @var float Left side of the course table. */
    private $left = 0;

    /** @var float Width of the course table. */
    private $tablewidth = 0;

    /** @var float Top of the transcript section. */
    private $bodytop = 0;

    /** @var float Bottom of the transcript section. */
    private $bodybottom = 0;

    /**
     * Constructor.
     *
     * @param array $model The layout and the data.
     * @param callable $imageprovider Function that gets a picture key and returns a stored_file or null.
     */
    public function __construct(array $model, callable $imageprovider) {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');

        $model = self::upgrade_old_model($model);
        $this->model = $model;
        $this->imageprovider = $imageprovider;
        $this->t = (object) $model['template'];
        [$this->pagewidth, $this->pageheight] = helper::get_template_dimensions($this->t);

        foreach (helper::get_regions() as $region) {
            $this->elements[$region] = [];
        }
        foreach ($model['elements'] as $element) {
            if (isset($this->elements[$element['region']])) {
                $this->elements[$element['region']][] = $element;
            }
        }
    }

    /**
     * A copy of a transcript that was made before the three sections were introduced is drawn with the same look:
     * the table columns become elements of the course row.
     *
     * @param array $model
     * @return array
     */
    private static function upgrade_old_model(array $model): array {
        if (isset($model['template']['headingheight'])) {
            return $model;
        }
        $t = $model['template'];
        $gap = (float) ($t['bodygap'] ?? 0);
        $t['headerheight'] = (float) $t['headerheight'] + $gap;
        $t['footerheight'] = (float) $t['footerheight'] + $gap;
        $t['bodygap'] = 0;
        $t['groupheight'] = (float) $t['rowheight'];
        $converted = template::columns_to_elements($model['columns'] ?? [], $t);
        $t['headingheight'] = $converted['headingheight'];
        $model['template'] = $t;
        foreach (array_merge($converted['heading'], $converted['row']) as $element) {
            $model['elements'][] = $element + ['id' => 0];
        }
        return $model;
    }

    /**
     * Build the PDF.
     *
     * @return string The content of the PDF file.
     */
    public function build(): string {
        $originallang = current_language();
        $lang = $this->model['lang'] ?? '';
        $switched = false;
        if ($lang && $lang !== $originallang && get_string_manager()->translation_exists($lang, false)) {
            force_current_language($lang);
            $switched = true;
        }

        try {
            $this->paginate();
            return $this->draw();
        } finally {
            if ($switched) {
                force_current_language($originallang);
            }
            foreach ($this->tempfiles as $path) {
                @unlink($path);
            }
            $this->tempfiles = [];
            $this->imagepaths = [];
        }
    }

    /**
     * The number of pages of the last PDF that was built.
     *
     * @return int
     */
    public function get_page_count(): int {
        return count($this->pages);
    }

    /**
     * A PDF object for measuring text or for drawing.
     *
     * @return \pdf
     */
    private function create_pdf(): \pdf {
        $pdf = new \pdf($this->pagewidth > $this->pageheight ? 'L' : 'P', 'mm', [$this->pagewidth, $this->pageheight]);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetMargins(0, 0, 0);
        $pdf->setCellPaddings(0, 0, 0, 0);
        return $pdf;
    }

    /**
     * The height of one line of text of a font size, in millimetres.
     *
     * @param int $fontsize
     * @return float
     */
    private function line_pitch(int $fontsize): float {
        return $fontsize * 0.352778 * 1.25;
    }

    /**
     * The label of a group of courses.
     *
     * @param array $course
     * @return string
     */
    private function get_group_label(array $course): string {
        if ($this->t->groupby === 'year') {
            return $course['year'] ? (string) $course['year'] : get_string('group_other', 'local_transcript');
        }
        if ($this->t->groupby === 'category') {
            return $course['category'] !== '' ? $course['category'] : get_string('group_other', 'local_transcript');
        }
        return '';
    }

    /**
     * Work out which lines of the transcript section go on which page.
     */
    private function paginate(): void {
        $t = $this->t;
        $this->left = (float) $t->leftmargin;
        $this->tablewidth = max(20.0, $this->pagewidth - $this->left - (float) $t->rightmargin);
        $this->bodytop = (float) $t->headerheight;
        $this->bodybottom = $this->pageheight - (float) $t->footerheight;
        $bodyheight = max(10.0, $this->bodybottom - $this->bodytop);

        $rowheight = max(3.0, min((float) $t->rowheight, $bodyheight / 2));
        $groupheight = max(3.0, min((float) $t->groupheight, $bodyheight / 4));
        $headingheight = max(0.0, min((float) $t->headingheight, $bodyheight / 4));
        $headingline = $headingheight > 0 ? ['type' => 'heading', 'height' => $headingheight] : null;

        $lines = [];
        $currentgroup = null;
        foreach ($this->model['courses'] as $index => $course) {
            $number = $index + 1;
            if ($t->groupby !== 'none') {
                $label = $this->get_group_label($course);
                if ($label !== $currentgroup) {
                    $currentgroup = $label;
                    $lines[] = ['type' => 'group', 'height' => $groupheight, 'label' => $label];
                }
            }
            $lines[] = ['type' => 'row', 'height' => $rowheight, 'course' => $course, 'number' => $number,
                'group' => $currentgroup];
        }
        if (!$lines) {
            $lines[] = ['type' => 'empty', 'height' => $rowheight * 2, 'label' => get_string('nocourses', 'local_transcript')];
        }

        $pages = [];
        $page = [];
        $used = 0.0;
        $startpage = function () use (&$page, &$used, $headingline) {
            $page = [];
            $used = 0.0;
            if ($headingline) {
                $page[] = $headingline;
                $used += $headingline['height'];
            }
        };
        $startpage();

        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            $need = $line['height'];
            // A group heading is never left alone at the bottom of a page.
            if ($line['type'] === 'group' && isset($lines[$i + 1])) {
                $need += $lines[$i + 1]['height'];
            }
            $hascontent = count($page) > ($headingline ? 1 : 0);
            if ($hascontent && $used + $need > $bodyheight + 0.001) {
                $pages[] = $page;
                $startpage();
                // The group goes on after the page break, so say so with a heading.
                if ($line['type'] === 'row' && $t->groupby !== 'none' && $line['group'] !== null) {
                    $page[] = ['type' => 'group', 'height' => $groupheight,
                        'label' => get_string('group_continued', 'local_transcript', $line['group'])];
                    $used += $groupheight;
                }
            }
            $page[] = $line;
            $used += $line['height'];
        }
        $pages[] = $page;
        $this->pages = $pages;
    }

    /**
     * Draw all pages.
     *
     * @return string The PDF.
     */
    private function draw(): string {
        $pdf = $this->create_pdf();

        $permissions = helper::get_blocked_permissions($this->t->protection ?? '');
        $mode = function_exists('openssl_encrypt') ? 2 : 1;
        $pdf->SetProtection($permissions, '', null, $mode);

        $title = get_string('pdftitle', 'local_transcript', $this->model['user']['fullname'] ?? '');
        $pdf->SetTitle($title);
        $pdf->SetSubject($title);
        $pdf->SetCreator('Moodle - local_transcript');
        $pdf->SetAuthor($this->model['sitename'] ?? '');

        $total = count($this->pages);
        foreach ($this->pages as $index => $lines) {
            $pagenumber = $index + 1;
            $pdf->AddPage();
            $this->draw_background($pdf);
            $this->draw_watermark($pdf);
            $this->draw_transcript($pdf, $lines, $pagenumber, $total);
            foreach ([helper::REGION_HEADER, helper::REGION_FOOTER] as $region) {
                $top = $region === helper::REGION_FOOTER ? $this->pageheight - (float) $this->t->footerheight : 0.0;
                foreach ($this->elements[$region] as $element) {
                    $this->draw_element($pdf, $element, $top, $pagenumber, $total, null, 0);
                }
            }
        }
        return $pdf->Output('transcript.pdf', 'S');
    }

    /**
     * The background graphic covers the whole page, behind everything else.
     *
     * @param \pdf $pdf
     */
    private function draw_background(\pdf $pdf): void {
        $path = $this->get_image_path('background');
        if ($path === null) {
            return;
        }
        $pdf->Image($path, 0, 0, $this->pagewidth, $this->pageheight, '', '', '', false, 300);
    }

    /**
     * Put the watermark picture in the middle of the transcript section, faded so that the text stays readable.
     *
     * @param \pdf $pdf
     */
    private function draw_watermark(\pdf $pdf): void {
        $path = $this->get_image_path('watermark');
        if ($path === null) {
            return;
        }
        $size = @getimagesize($path);
        if (!$size || empty($size[0]) || empty($size[1])) {
            return;
        }
        $bodyheight = $this->bodybottom - $this->bodytop;
        $width = $this->tablewidth * max(5, min(100, (int) $this->t->watermarkwidth)) / 100;
        $height = $width * $size[1] / $size[0];
        if ($height > $bodyheight) {
            $height = $bodyheight;
            $width = $height * $size[0] / $size[1];
        }
        $x = ($this->pagewidth - $width) / 2;
        $y = $this->bodytop + ($bodyheight - $height) / 2;

        $pdf->SetAlpha(max(1, min(100, (int) $this->t->watermarkopacity)) / 100);
        $pdf->Image($path, $x, $y, $width, $height, '', '', '', false, 300);
        $pdf->SetAlpha(1);
    }

    /**
     * Draw the lines of the transcript section of one page.
     *
     * @param \pdf $pdf
     * @param array[] $lines
     * @param int $page
     * @param int $total
     */
    private function draw_transcript(\pdf $pdf, array $lines, int $page, int $total): void {
        $t = $this->t;
        $y = $this->bodytop;
        $linecolour = helper::colour_to_rgb($t->linecolour);
        $fontsize = (int) $t->bodyfontsize;

        foreach ($lines as $line) {
            $height = $line['height'];
            switch ($line['type']) {
                case 'heading':
                    foreach ($this->elements[helper::REGION_HEADING] as $element) {
                        $this->draw_element($pdf, $element, $y, $page, $total, null, 0);
                    }
                    break;

                case 'group':
                    $pdf->SetFillColorArray(helper::colour_to_rgb($t->groupbg));
                    $pdf->Rect($this->left, $y, $this->tablewidth, $height, 'F');
                    $pdf->SetTextColorArray(helper::colour_to_rgb($t->bodycolour));
                    $pdf->setFont($t->bodyfont, 'B', $fontsize);
                    $pdf->setCellPaddings(1.2, 0, 1.2, 0);
                    $pdf->MultiCell($this->tablewidth, $height, $line['label'], 0, 'L', false, 0, $this->left, $y, true, 0,
                        false, true, $height, 'M');
                    $pdf->setCellPaddings(0, 0, 0, 0);
                    break;

                case 'empty':
                    $pdf->SetTextColorArray(helper::colour_to_rgb($t->bodycolour));
                    $pdf->setFont($t->bodyfont, 'I', $fontsize);
                    $pdf->MultiCell($this->tablewidth, $height, $line['label'], 0, 'C', false, 0, $this->left, $y, true, 0,
                        false, true, $height, 'M');
                    break;

                default:
                    if (!empty($t->stripe) && $line['number'] % 2 === 0) {
                        $pdf->SetFillColorArray(helper::colour_to_rgb($t->stripecolour));
                        $pdf->Rect($this->left, $y, $this->tablewidth, $height, 'F');
                    }
                    foreach ($this->elements[helper::REGION_ROW] as $element) {
                        $this->draw_element($pdf, $element, $y, $page, $total, $line['course'], $line['number']);
                    }
            }

            if (!empty($t->rowlines) && $line['type'] !== 'empty' && $line['type'] !== 'heading') {
                $pdf->SetLineStyle(['width' => 0.2, 'color' => $linecolour, 'cap' => 'butt']);
                $pdf->Line($this->left, $y + $height, $this->left + $this->tablewidth, $y + $height);
            }
            $y += $height;
        }
    }

    /**
     * Draw one element.
     *
     * @param \pdf $pdf
     * @param array $element
     * @param float $top The top of the section the element is in.
     * @param int $page The number of this page.
     * @param int $total The number of pages.
     * @param array|null $course The course, for elements of the course row.
     * @param int $number The number of the course row.
     */
    private function draw_element(\pdf $pdf, array $element, float $top, int $page, int $total, ?array $course,
            int $number): void {
        $region = $element['region'];
        $inheaderorfooter = $region === helper::REGION_HEADER || $region === helper::REGION_FOOTER;
        if ($inheaderorfooter && !$this->is_shown_on((int) $element['showon'], $page, $total)) {
            return;
        }
        $pdf->setCellPaddings(0, 0, 0, 0);
        $x = (float) $element['posx'];
        $y = $top + (float) $element['posy'];
        $width = (float) $element['width'];
        $height = (float) $element['height'];
        $data = $element['data'] + template::get_element_default_data($element['type']);

        switch ($element['type']) {
            case 'text':
                if (helper::has_rich_text((string) $data['text'])) {
                    // Links and simple formatting. The values of the placeholders are made safe first.
                    $html = placeholders::replace(helper::sanitise_rich_text((string) $data['text']), $this->model, $page,
                        $total, true);
                    $this->draw_text($pdf, $data, $html, $x, $y, $width, $height, !$inheaderorfooter, true);
                    break;
                }
                $text = placeholders::replace((string) $data['text'], $this->model, $page, $total);
                $this->draw_text($pdf, $data, $text, $x, $y, $width, $height, !$inheaderorfooter);
                break;
            case 'coursefield':
                if ($course === null || $data['field'] === '') {
                    break;
                }
                $value = helper::get_course_field_text($course, $data['field'], $number, $this->t->dateformat,
                    isset($data['decimals']) ? (int) $data['decimals'] : null);
                $this->draw_text($pdf, $data, $data['prefix'] . $value, $x, $y, $width, $height, true);
                break;
            case 'image':
                $this->draw_image($pdf, 'e' . $element['id'], $x, $y, $width, $height, 30.0);
                break;
            case 'userpicture':
                $this->draw_image($pdf, 'userpicture', $x, $y, $width, $height, 25.0);
                break;
            case 'line':
                $pdf->SetLineStyle(['width' => (float) $data['thickness'],
                    'color' => helper::colour_to_rgb($data['colour']), 'cap' => 'butt']);
                $pdf->Line($x, $y, $x + $width, $y);
                break;
            case 'rectangle':
                $pdf->SetLineStyle(['width' => (float) $data['thickness'],
                    'color' => helper::colour_to_rgb($data['colour'])]);
                $pdf->SetFillColorArray(helper::colour_to_rgb($data['fillcolour']));
                $pdf->Rect($x, $y, $width, $height, !empty($data['fill']) ? 'DF' : 'D');
                break;
            case 'qrcode':
                $this->draw_qrcode($pdf, $data, $x, $y, $width);
                break;
        }
    }

    /**
     * Does an element belong on this page?
     *
     * @param int $showon One of the helper::SHOWON_ constants.
     * @param int $page
     * @param int $total
     * @return bool
     */
    private function is_shown_on(int $showon, int $page, int $total): bool {
        switch ($showon) {
            case helper::SHOWON_FIRST:
                return $page === 1;
            case helper::SHOWON_LAST:
                return $page === $total;
            case helper::SHOWON_NOTFIRST:
                return $page > 1;
        }
        return true;
    }

    /**
     * Draw a piece of text.
     *
     * In the header and the footer a text box grows with its text. In the course row it has a fixed size: one line
     * when the height is zero, otherwise as many lines as fit in the height. Text that is too long ends in "...".
     *
     * @param \pdf $pdf
     * @param array $data Style of the text.
     * @param string $text
     * @param float $x
     * @param float $y
     * @param float $width Zero means as wide as the room that is left on the page.
     * @param float $height
     * @param bool $fixed Keep the text inside a box of a fixed size.
     */
    private function draw_text(\pdf $pdf, array $data, string $text, float $x, float $y, float $width, float $height,
            bool $fixed, bool $ishtml = false): void {
        if (trim(strip_tags($text)) === '') {
            return;
        }
        if ($width <= 0) {
            $width = max(10.0, $this->pagewidth - $x - (float) $this->t->rightmargin);
        }
        $fontsize = (int) $data['fontsize'];
        $pdf->setFont($data['font'], $data['fontstyle'], $fontsize);
        $pdf->SetTextColorArray(helper::colour_to_rgb($data['colour']));

        if ($ishtml) {
            // Links are drawn in the colour of the text, underlined.
            $pdf->setHtmlLinksStyle(helper::colour_to_rgb($data['colour']), 'U');
        }
        if (!$fixed) {
            $pdf->MultiCell($width, 0, $text, 0, $data['align'], false, 1, $x, $y, true, 0, $ishtml, true, 0, 'T', false);
            return;
        }
        $pitch = $this->line_pitch($fontsize);
        $boxheight = $height > 0 ? $height : $pitch;
        if ($ishtml) {
            $pdf->MultiCell($width, $boxheight, $text, 0, $data['align'], false, 1, $x, $y, true, 0, true, true, $boxheight,
                'T', false);
            return;
        }
        $maxlines = max(1, (int) floor(($boxheight + 0.05) / $pitch));
        $text = $this->fit_text($pdf, $text, $width, $maxlines);
        $pdf->MultiCell($width, $boxheight, $text, 0, $data['align'], false, 1, $x, $y, true, 0, false, true, $boxheight, 'T',
            false);
    }

    /**
     * Shorten a text, ending it with "...", until it fits in a number of lines.
     *
     * @param \pdf $pdf A PDF with the right font set.
     * @param string $text
     * @param float $width
     * @param int $maxlines
     * @return string
     */
    private function fit_text(\pdf $pdf, string $text, float $width, int $maxlines): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ((int) $pdf->getNumLines($text, $width) <= $maxlines) {
            return $text;
        }
        $low = 0;
        $high = \core_text::strlen($text);
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            $candidate = rtrim(\core_text::substr($text, 0, $middle)) . '...';
            if ((int) $pdf->getNumLines($candidate, $width) <= $maxlines) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }
        return rtrim(\core_text::substr($text, 0, $low)) . '...';
    }

    /**
     * Draw a picture.
     *
     * @param \pdf $pdf
     * @param string $key The key of the picture.
     * @param float $x
     * @param float $y
     * @param float $width Zero keeps the shape of the picture, using the height.
     * @param float $height Zero keeps the shape of the picture, using the width.
     * @param float $defaultwidth The width to use when both width and height are zero.
     */
    private function draw_image(\pdf $pdf, string $key, float $x, float $y, float $width, float $height,
            float $defaultwidth): void {
        $path = $this->get_image_path($key);
        if ($path === null) {
            return;
        }
        if ($width <= 0 && $height <= 0) {
            $width = $defaultwidth;
        }
        $pdf->Image($path, $x, $y, $width, $height, '', '', '', false, 300);
    }

    /**
     * Draw a QR code that opens the verification page.
     *
     * @param \pdf $pdf
     * @param array $data
     * @param float $x
     * @param float $y
     * @param float $size Width and height. Zero gives 22 mm.
     */
    private function draw_qrcode(\pdf $pdf, array $data, float $x, float $y, float $size): void {
        $size = $size > 0 ? $size : 22.0;
        $code = (string) ($this->model['code'] ?? '');
        $url = helper::get_verify_url($code);
        $style = [
            'border' => 0, 'vpadding' => 0, 'hpadding' => 0,
            'fgcolor' => helper::colour_to_rgb($data['colour']), 'bgcolor' => false,
            'module_width' => 1, 'module_height' => 1,
        ];
        $pdf->write2DBarcode($url, 'QRCODE,M', $x, $y, $size, $size, $style, 'N');
    }

    /**
     * Copy a picture to a temporary file, so that TCPDF can read it. The file is removed after the build, and
     * a picture that is used many times (in every course row) is only copied once.
     *
     * @param string $key 'watermark', 'background', 'userpicture' or 'e' followed by the element id.
     * @return string|null The path or null if there is no picture.
     */
    private function get_image_path(string $key): ?string {
        if (array_key_exists($key, $this->imagepaths)) {
            return $this->imagepaths[$key];
        }
        $path = null;
        $file = call_user_func($this->imageprovider, $key);
        if ($file) {
            $copy = $file->copy_content_to_temp('local_transcript');
            if ($copy) {
                $this->tempfiles[] = $copy;
                $path = $copy;
            }
        }
        $this->imagepaths[$key] = $path;
        return $path;
    }
}
