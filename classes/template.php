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
 * Loading, saving, copying and deleting transcript layouts and their elements.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template {

    /** @var string File area holding the pictures placed in elements. The item id is the element id. */
    public const FILEAREA_IMAGE = 'image';
    /** @var string File area holding the watermark of a template. The item id is the template id. */
    public const FILEAREA_WATERMARK = 'watermark';
    /** @var string File area holding the background graphic (the whole page) of a template. The item id is the template id. */
    public const FILEAREA_BACKGROUND = 'background';
    /** @var string File area holding the pictures in the message of the email. The item id is the template id. */
    public const FILEAREA_EMAIL = 'emailmessage';
    /** @var string File area holding the pictures of an issued transcript. The item id is the issue id. */
    public const FILEAREA_ISSUE = 'issuefiles';

    /** @var string[] Columns of the template table that are plain settings (everything but the id and times). */
    private const SETTINGS = [
        'name', 'visible', 'pagesize', 'orientation', 'customwidth', 'customheight', 'leftmargin', 'rightmargin',
        'topmargin', 'bottommargin', 'lastyears', 'emailsubject', 'emailmessage', 'emailmessageformat',
        'headerheight', 'footerheight', 'headingheight', 'rowheight', 'groupheight', 'bodyfont', 'bodyfontsize',
        'bodycolour', 'stripe', 'stripecolour', 'rowlines', 'linecolour', 'groupby', 'groupbg', 'sortby',
        'includeinprogress', 'includeended', 'includehidden', 'dateformat', 'watermarkopacity',
        'watermarkwidth', 'protection',
    ];

    /**
     * The file storage context. All files of the plugin live in the system context.
     *
     * @return \context_system
     */
    public static function get_context(): \context_system {
        return \context_system::instance();
    }

    /**
     * The settings a new template starts with.
     *
     * @return \stdClass
     */
    public static function get_defaults(): \stdClass {
        $data = new \stdClass();
        $data->name = '';
        $data->visible = 1;
        $data->pagesize = 'A4';
        $data->orientation = 'P';
        $data->leftmargin = 15;
        $data->rightmargin = 15;
        $data->topmargin = 10;
        $data->bottommargin = 10;
        $data->lastyears = 0;
        $data->emailsubject = get_string('email_defaultsubject', 'local_transcript');
        $data->emailmessage = get_string('email_defaultmessage', 'local_transcript');
        $data->emailmessageformat = FORMAT_HTML;
        $data->customwidth = 210;
        $data->customheight = 297;
        $data->headerheight = 38;
        $data->footerheight = 22;
        $data->headingheight = 8;
        $data->rowheight = 10;
        $data->groupheight = 7;
        $data->bodygap = 0;
        $data->bodyfont = helper::get_default_font();
        $data->bodyfontsize = 9;
        $data->bodycolour = '#000000';
        $data->showheaderrow = 1;
        $data->headerrowbg = '#E6E9EE';
        $data->headerrowcolour = '#000000';
        $data->columns = null;
        $data->stripe = 0;
        $data->stripecolour = '#F5F6F8';
        $data->rowlines = 1;
        $data->linecolour = '#C8CCD2';
        $data->groupby = 'none';
        $data->groupbg = '#F0F2F5';
        $data->sortby = 'startdate_asc';
        $data->includeinprogress = 1;
        $data->includeended = 1;
        $data->includehidden = 0;
        $data->dateformat = 'long';
        $data->watermarkopacity = 15;
        $data->watermarkwidth = 60;
        $data->protection = 'copy,modify,annot-forms,assemble';
        return $data;
    }

    /**
     * Get one template. Throws an exception if it does not exist.
     *
     * @param int $id
     * @return \stdClass
     */
    public static function get(int $id): \stdClass {
        global $DB;
        return $DB->get_record('local_transcript_template', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Get all templates.
     *
     * @param bool $onlyvisible Only the templates that users may choose.
     * @return \stdClass[] Keyed by id.
     */
    public static function get_all(bool $onlyvisible = false): array {
        global $DB;
        $conditions = $onlyvisible ? ['visible' => 1] : [];
        return $DB->get_records('local_transcript_template', $conditions, 'name ASC, id ASC');
    }

    /**
     * Create a template with a simple starting layout, so that the designer never starts from a blank page.
     *
     * @param \stdClass $data Settings, anything missing is taken from the defaults.
     * @return int The id of the new template.
     */
    public static function create(\stdClass $data): int {
        global $DB;
        $record = self::get_defaults();
        foreach (self::SETTINGS as $field) {
            if (isset($data->$field)) {
                $record->$field = $data->$field;
            }
        }
        $record->timecreated = $record->timemodified = time();
        $id = $DB->insert_record('local_transcript_template', $record);

        if (empty($data->nostarterelements)) {
            self::add_starter_elements($id, $record);
        }
        return $id;
    }

    /**
     * Save the settings of a template.
     *
     * @param \stdClass $data Must have the id.
     */
    public static function update(\stdClass $data): void {
        global $DB;
        $record = new \stdClass();
        $record->id = $data->id;
        foreach (self::SETTINGS as $field) {
            if (isset($data->$field)) {
                $record->$field = $data->$field;
            }
        }
        $record->timemodified = time();
        $DB->update_record('local_transcript_template', $record);
    }

    /**
     * Delete a template, its elements and its files. Transcripts that were issued earlier keep working,
     * because they carry their own copy of the layout and the pictures.
     *
     * @param int $id
     */
    public static function delete(int $id): void {
        global $DB;
        $fs = get_file_storage();
        $context = self::get_context();
        foreach ($DB->get_fieldset_select('local_transcript_element', 'id', 'templateid = ?', [$id]) as $elementid) {
            $fs->delete_area_files($context->id, 'local_transcript', self::FILEAREA_IMAGE, $elementid);
        }
        $fs->delete_area_files($context->id, 'local_transcript', self::FILEAREA_WATERMARK, $id);
        $fs->delete_area_files($context->id, 'local_transcript', self::FILEAREA_BACKGROUND, $id);
        $fs->delete_area_files($context->id, 'local_transcript', self::FILEAREA_EMAIL, $id);
        $DB->delete_records('local_transcript_element', ['templateid' => $id]);
        $DB->delete_records('local_transcript_template', ['id' => $id]);
        if ((int) get_config('local_transcript', 'defaulttemplate') === $id) {
            unset_config('defaulttemplate', 'local_transcript');
        }
    }

    /**
     * Make a copy of a template, including elements and pictures.
     *
     * @param int $id The template to copy.
     * @return int The id of the copy.
     */
    public static function duplicate(int $id): int {
        global $DB;
        $source = self::get($id);
        $copy = clone $source;
        unset($copy->id);
        $copy->name = get_string('copyof', 'local_transcript', $source->name);
        $copy->nostarterelements = true;
        $newid = self::create($copy);

        $fs = get_file_storage();
        $context = self::get_context();
        foreach ([self::FILEAREA_WATERMARK, self::FILEAREA_BACKGROUND, self::FILEAREA_EMAIL] as $area) {
            foreach ($fs->get_area_files($context->id, 'local_transcript', $area, $id, 'id', false) as $file) {
                $fs->create_file_from_storedfile(['itemid' => $newid], $file);
            }
        }
        foreach (self::get_elements($id) as $element) {
            $oldid = $element->id;
            unset($element->id);
            $element->templateid = $newid;
            $element->timecreated = $element->timemodified = time();
            $element->data = json_encode($element->data);
            $newelementid = $DB->insert_record('local_transcript_element', $element);
            foreach ($fs->get_area_files($context->id, 'local_transcript', self::FILEAREA_IMAGE, $oldid, 'id', false) as $file) {
                $fs->create_file_from_storedfile(['itemid' => $newelementid], $file);
            }
        }
        return $newid;
    }

    /**
     * Elements of a template with the data decoded.
     *
     * @param int $templateid
     * @param string|null $region Only the elements of this region.
     * @return \stdClass[] Keyed by id, in drawing order.
     */
    public static function get_elements(int $templateid, ?string $region = null): array {
        global $DB;
        $conditions = ['templateid' => $templateid];
        if ($region !== null) {
            $conditions['region'] = $region;
        }
        $records = $DB->get_records('local_transcript_element', $conditions, 'sortorder ASC, id ASC');
        foreach ($records as $record) {
            $record->data = self::decode_element_data($record->data);
        }
        return $records;
    }

    /**
     * One element, with the data decoded.
     *
     * @param int $id
     * @return \stdClass
     */
    public static function get_element(int $id): \stdClass {
        global $DB;
        $record = $DB->get_record('local_transcript_element', ['id' => $id], '*', MUST_EXIST);
        $record->data = self::decode_element_data($record->data);
        return $record;
    }

    /**
     * What an element looks like when it is first added.
     *
     * @param string $type
     * @return array
     */
    public static function get_element_default_data(string $type): array {
        $data = [
            'text' => '',
            'font' => helper::get_default_font(),
            'fontsize' => 10,
            'fontstyle' => '',
            'colour' => '#000000',
            'align' => 'L',
            'thickness' => 0.3,
            'fill' => 0,
            'fillcolour' => '#EEEEEE',
            'field' => '',
            'prefix' => '',
        ];
        if ($type === 'coursefield') {
            $data['decimals'] = 2;
        }
        if ($type === 'text') {
            $data['text'] = get_string('element_text_default', 'local_transcript');
        }
        return $data;
    }

    /**
     * Save an element. Creates it when there is no id.
     *
     * @param \stdClass $element Fields of the element table, with data as an array.
     * @return int The element id.
     */
    public static function save_element(\stdClass $element): int {
        global $DB;
        $record = clone $element;
        $record->data = json_encode(self::sanitise_element_data((array) ($element->data ?? [])));
        $record->timemodified = time();
        if (!empty($record->id)) {
            $DB->update_record('local_transcript_element', $record);
            return (int) $record->id;
        }
        $record->timecreated = $record->timemodified;
        $record->sortorder = 1 + (int) $DB->get_field_sql(
            'SELECT MAX(sortorder) FROM {local_transcript_element} WHERE templateid = ?',
            [$record->templateid]
        );
        return $DB->insert_record('local_transcript_element', $record);
    }

    /**
     * Delete an element and its picture.
     *
     * @param int $id
     */
    public static function delete_element(int $id): void {
        global $DB;
        get_file_storage()->delete_area_files(self::get_context()->id, 'local_transcript', self::FILEAREA_IMAGE, $id);
        $DB->delete_records('local_transcript_element', ['id' => $id]);
    }

    /**
     * Copy an element in the same template, a little lower and to the right so that it can be seen.
     *
     * @param int $id
     * @return int The id of the copy.
     */
    public static function duplicate_element(int $id): int {
        global $DB;
        $element = self::get_element($id);
        $oldid = $element->id;
        unset($element->id);
        $element->name = get_string('copyof', 'local_transcript', $element->name);
        $element->posx += 3;
        $element->posy += 3;
        $newid = self::save_element($element);
        $fs = get_file_storage();
        $context = self::get_context();
        foreach ($fs->get_area_files($context->id, 'local_transcript', self::FILEAREA_IMAGE, $oldid, 'id', false) as $file) {
            $fs->create_file_from_storedfile(['itemid' => $newid], $file);
        }
        return $newid;
    }

    /**
     * Move an element up or down in the drawing order (later elements are drawn on top).
     *
     * @param int $id
     * @param int $direction -1 for earlier, 1 for later.
     */
    public static function move_element(int $id, int $direction): void {
        global $DB;
        $element = $DB->get_record('local_transcript_element', ['id' => $id], '*', MUST_EXIST);
        // Only the elements of the same part of the page count, because that is the list the person sees.
        $all = array_values($DB->get_records('local_transcript_element',
            ['templateid' => $element->templateid, 'region' => $element->region], 'sortorder ASC, id ASC'));
        $index = null;
        foreach ($all as $i => $candidate) {
            if ((int) $candidate->id === (int) $id) {
                $index = $i;
            }
        }
        $swapwith = $index === null ? null : $index + ($direction < 0 ? -1 : 1);
        if ($swapwith === null || !isset($all[$swapwith])) {
            return;
        }
        [$all[$index], $all[$swapwith]] = [$all[$swapwith], $all[$index]];
        // Number the list again, so that elements that share a number cannot get in the way.
        foreach ($all as $order => $candidate) {
            if ((int) $candidate->sortorder !== $order) {
                $DB->set_field('local_transcript_element', 'sortorder', $order, ['id' => $candidate->id]);
            }
        }
    }

    /**
     * Everything the PDF builder needs to know about a layout: the settings and the elements of all sections.
     *
     * @param int $templateid
     * @return array
     */
    public static function get_layout(int $templateid): array {
        $template = self::get($templateid);
        $settings = (array) $template;
        $columns = self::decode_columns($settings['columns'] ?? null);
        unset($settings['columns']);
        $elements = [];
        foreach (self::get_elements($templateid) as $element) {
            $elements[] = (array) $element;
        }
        return ['template' => $settings, 'elements' => $elements, 'columns' => $columns];
    }

    /**
     * Fill the file areas of a form's picture field from an element or template.
     *
     * @param string $filearea
     * @param int $itemid
     * @return \stored_file|null The first file, if any.
     */
    public static function get_first_file(string $filearea, int $itemid): ?\stored_file {
        $files = get_file_storage()->get_area_files(self::get_context()->id, 'local_transcript', $filearea, $itemid,
            'sortorder, id', false);
        return $files ? reset($files) : null;
    }

    /**
     * Turn the stored JSON of the columns of a layout made before the three sections were introduced into an array.
     *
     * @param string|null $json
     * @return array[]
     */
    public static function decode_columns(?string $json): array {
        $columns = $json ? json_decode($json, true) : null;
        if (!is_array($columns)) {
            return [];
        }
        return $columns;
    }

    /**
     * Turn the stored JSON of an element into an array, with all the keys present.
     *
     * @param string|null $json
     * @return array
     */
    public static function decode_element_data(?string $json): array {
        $data = $json ? json_decode($json, true) : null;
        return is_array($data) ? $data : [];
    }

    /**
     * Keep only what an element is allowed to store, with values of the right kind.
     *
     * @param array $data
     * @return array
     */
    public static function sanitise_element_data(array $data): array {
        $fonts = array_keys(helper::get_fonts());
        $clean = [];
        $clean['text'] = (string) ($data['text'] ?? '');
        $clean['font'] = in_array($data['font'] ?? '', $fonts) ? $data['font'] : 'helvetica';
        $clean['fontsize'] = max(4, min(96, (int) ($data['fontsize'] ?? 10)));
        $clean['fontstyle'] = in_array($data['fontstyle'] ?? '', ['', 'B', 'I', 'BI']) ? $data['fontstyle'] : '';
        $clean['colour'] = helper::is_valid_colour((string) ($data['colour'] ?? '')) ? $data['colour'] : '#000000';
        $clean['align'] = in_array($data['align'] ?? 'L', ['L', 'C', 'R']) ? $data['align'] : 'L';
        $clean['thickness'] = max(0.1, min(5.0, (float) ($data['thickness'] ?? 0.3)));
        $clean['fill'] = empty($data['fill']) ? 0 : 1;
        $clean['fillcolour'] = helper::is_valid_colour((string) ($data['fillcolour'] ?? '')) ? $data['fillcolour'] : '#EEEEEE';
        $field = (string) ($data['field'] ?? '');
        $clean['field'] = (array_key_exists($field, helper::get_column_fields()) || strpos($field, 'cf:') === 0) ? $field : '';
        $clean['prefix'] = \core_text::substr((string) ($data['prefix'] ?? ''), 0, 100);
        // The number of decimal places of a grade. Elements made before this setting existed keep the grade as it is.
        if (isset($data['decimals']) && $data['decimals'] !== '') {
            $clean['decimals'] = max(0, min(6, (int) $data['decimals']));
        }
        return $clean;
    }

    /**
     * Elements made from the columns of a layout that was made before the three sections were introduced. Also used
     * to give a new layout a course row and a row with column headings.
     *
     * @param array[] $columns Each with heading, field, width and align. Empty means the default columns.
     * @param \stdClass|array $template The settings of the layout.
     * @return array The elements of the heading band and of the course row and the height of the heading band.
     */
    public static function columns_to_elements(array $columns, $template): array {
        $t = (object) $template;
        [$pagewidth] = helper::get_template_dimensions($t);
        $left = (float) $t->leftmargin;
        $usable = max(20.0, $pagewidth - $left - (float) $t->rightmargin);
        if (!$columns) {
            $columns = helper::get_default_columns();
        }
        $total = 0.0;
        foreach ($columns as $column) {
            $total += (float) $column['width'];
        }
        $factor = ($total > $usable && $total > 0) ? $usable / $total : 1.0;

        $fontsize = (int) ($t->bodyfontsize ?? 9);
        $pitch = $fontsize * 0.352778 * 1.25;
        $rowheight = (float) $t->rowheight;
        $showheading = !isset($t->showheaderrow) || !empty($t->showheaderrow);
        $headingheight = $showheading ? round(max(6.0, $pitch + 3.0), 1) : 0.0;
        $font = !empty($t->bodyfont) ? $t->bodyfont : helper::get_default_font();
        $colour = !empty($t->bodycolour) ? $t->bodycolour : '#000000';

        $heading = [];
        $row = [];
        if ($showheading) {
            $background = !empty($t->headerrowbg) ? $t->headerrowbg : '#E6E9EE';
            $heading[] = [
                'region' => helper::REGION_HEADING, 'type' => 'rectangle', 'name' => get_string('headingband', 'local_transcript'),
                'posx' => $left, 'posy' => 0, 'width' => $usable, 'height' => $headingheight, 'showon' => 0,
                'data' => ['colour' => $background, 'thickness' => 0.1, 'fill' => 1, 'fillcolour' => $background],
            ];
        }
        $x = $left;
        foreach ($columns as $column) {
            $width = round((float) $column['width'] * $factor, 1);
            $align = in_array($column['align'] ?? 'L', ['L', 'C', 'R']) ? $column['align'] : 'L';
            $field = (string) $column['field'];
            if ($showheading) {
                $heading[] = [
                    'region' => helper::REGION_HEADING, 'type' => 'text', 'name' => (string) $column['heading'],
                    'posx' => $x, 'posy' => round(($headingheight - $pitch) / 2, 1), 'width' => $width, 'height' => 0,
                    'showon' => 0,
                    'data' => ['text' => (string) $column['heading'], 'font' => $font, 'fontsize' => $fontsize,
                        'fontstyle' => 'B', 'colour' => !empty($t->headerrowcolour) ? $t->headerrowcolour : '#000000',
                        'align' => $align],
                ];
            }
            $multiline = in_array($field, ['coursefullname', 'coursedescription']) && floor(($rowheight - 1) / $pitch) >= 2;
            $row[] = [
                'region' => helper::REGION_ROW, 'type' => 'coursefield',
                'name' => helper::get_column_fields()[$field] ?? $field,
                'posx' => $x, 'posy' => $multiline ? 0.5 : round(max(0.0, ($rowheight - $pitch) / 2), 1), 'width' => $width,
                'height' => $multiline ? $rowheight - 1 : 0, 'showon' => 0,
                'data' => ['field' => $field, 'font' => $font, 'fontsize' => $fontsize, 'fontstyle' => '', 'colour' => $colour,
                    'align' => $align],
            ];
            $x += $width;
        }
        return ['heading' => $heading, 'row' => $row, 'headingheight' => $headingheight];
    }

    /**
     * A simple layout for a new template: title, student details, verification code and page numbers in the header and
     * footer, and a course row with column headings, so that the designer never starts from a blank page.
     *
     * @param int $templateid
     * @param \stdClass $template The template settings.
     */
    private static function add_starter_elements(int $templateid, \stdClass $template): void {
        [$pagewidth] = helper::get_template_dimensions($template);
        $usable = $pagewidth - $template->leftmargin - $template->rightmargin;
        $left = (float) $template->leftmargin;
        $headerheight = (float) $template->headerheight;

        $items = [
            [helper::REGION_HEADER, 'text', get_string('starter_sitename', 'local_transcript'),
                $left, 8, $usable, 0, helper::SHOWON_ALL,
                ['text' => '{{sitename}}', 'fontsize' => 18, 'fontstyle' => 'B', 'colour' => '#1F3A5F']],
            [helper::REGION_HEADER, 'text', get_string('starter_title', 'local_transcript'),
                $left, 17, $usable, 0, helper::SHOWON_ALL,
                ['text' => get_string('starter_title_text', 'local_transcript'), 'fontsize' => 12, 'colour' => '#444444']],
            [helper::REGION_HEADER, 'line', get_string('starter_line', 'local_transcript'),
                $left, 25, $usable, 0, helper::SHOWON_ALL,
                ['colour' => '#1F3A5F', 'thickness' => 0.5]],
            [helper::REGION_HEADER, 'text', get_string('starter_student', 'local_transcript'),
                $left, min(28, max(0, $headerheight - 8)), $usable, 0, helper::SHOWON_ALL,
                ['text' => get_string('starter_student_text', 'local_transcript'), 'fontsize' => 10]],
            [helper::REGION_FOOTER, 'line', get_string('starter_line', 'local_transcript'),
                $left, 2, $usable, 0, helper::SHOWON_ALL,
                ['colour' => '#C8CCD2', 'thickness' => 0.3]],
            [helper::REGION_FOOTER, 'text', get_string('starter_issued', 'local_transcript'),
                $left, 5, $usable / 2, 0, helper::SHOWON_ALL,
                ['text' => get_string('starter_issued_text', 'local_transcript'), 'fontsize' => 8, 'colour' => '#555555']],
            [helper::REGION_FOOTER, 'text', get_string('starter_code', 'local_transcript'),
                $left, 10, $usable / 2, 0, helper::SHOWON_ALL,
                ['text' => get_string('starter_code_text', 'local_transcript'), 'fontsize' => 8, 'colour' => '#555555']],
            [helper::REGION_FOOTER, 'text', get_string('starter_page', 'local_transcript'),
                $left + $usable / 2, 5, $usable / 2, 0, helper::SHOWON_ALL,
                ['text' => get_string('starter_page_text', 'local_transcript'), 'fontsize' => 8,
                    'colour' => '#555555', 'align' => 'R']],
        ];
        foreach ($items as [$region, $type, $name, $x, $y, $w, $h, $showon, $data]) {
            self::insert_starter_element($templateid, $region, $type, $name, $x, $y, $w, $h, $showon, $data);
        }

        $converted = self::columns_to_elements(helper::get_default_columns(), $template);
        foreach (array_merge($converted['heading'], $converted['row']) as $element) {
            self::insert_starter_element($templateid, $element['region'], $element['type'], $element['name'],
                $element['posx'], $element['posy'], $element['width'], $element['height'], 0, $element['data']);
        }
    }

    /**
     * Add one element to a layout.
     *
     * @param int $templateid
     * @param string $region
     * @param string $type
     * @param string $name
     * @param float $x
     * @param float $y
     * @param float $w
     * @param float $h
     * @param int $showon
     * @param array $data
     */
    public static function insert_starter_element(int $templateid, string $region, string $type, string $name, float $x,
            float $y, float $w, float $h, int $showon, array $data): void {
        $element = new \stdClass();
        $element->templateid = $templateid;
        $element->region = $region;
        $element->type = $type;
        $element->name = $name;
        $element->posx = $x;
        $element->posy = $y;
        $element->width = $w;
        $element->height = $h;
        $element->showon = $showon;
        $element->data = array_merge(self::get_element_default_data($type), $data);
        self::save_element($element);
    }
}
