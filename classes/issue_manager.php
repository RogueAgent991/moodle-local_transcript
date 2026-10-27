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
 * Creates the transcripts that are handed out, keeps a record of them and makes the PDF again from that record.
 *
 * Each time a transcript is generated, a record is made with a verification code and a complete copy
 * (a snapshot) of the layout and the data. The PDF of an old transcript is always made from its own snapshot,
 * so it looks the same, even after the layout was changed or the grades were updated.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class issue_manager {

    /**
     * The layout and the data of a transcript, as they are right now.
     *
     * @param int $templateid
     * @param int|null $userid The student. When empty, made up sample data is used.
     * @param array $options What the person who generates the transcript chose: includeinprogress (0 or 1) and
     *        lastyears (the number of years, 0 for all). What is not given is taken from the layout.
     * @return array The model for the pdf_builder.
     */
    public static function build_model(int $templateid, ?int $userid = null, array $options = []): array {
        global $SITE;

        $model = template::get_layout($templateid);
        if (isset($options['includeinprogress'])) {
            $model['template']['includeinprogress'] = empty($options['includeinprogress']) ? 0 : 1;
        }
        if (isset($options['lastyears'])) {
            $model['template']['lastyears'] = max(0, min(100, (int) $options['lastyears']));
        }
        if ($userid) {
            $model['user'] = data_collector::get_user_info($userid);
            $model['courses'] = data_collector::get_courses($userid, $model['template']);
        } else {
            $sample = data_collector::get_sample_data();
            $model['user'] = $sample['user'];
            $model['courses'] = data_collector::sort_courses($sample['courses'], $model['template']['groupby'],
                $model['template']['sortby']);
        }
        $model['issuedate'] = time();
        $model['code'] = '';
        $model['sitename'] = helper::plain_text(format_string($SITE->fullname, true, ['context' => \context_system::instance()]));
        $model['lang'] = current_language();
        return $model;
    }

    /**
     * Where the pictures of the layout come from while it is being edited.
     *
     * @param int $templateid
     * @param int|null $userid The student, for the picture of the student.
     * @return \Closure
     */
    public static function get_live_image_provider(int $templateid, ?int $userid = null): \Closure {
        return function (string $key) use ($templateid, $userid) {
            if ($key === 'watermark') {
                return template::get_first_file(template::FILEAREA_WATERMARK, $templateid);
            }
            if ($key === 'background') {
                return template::get_first_file(template::FILEAREA_BACKGROUND, $templateid);
            }
            if ($key === 'userpicture') {
                return $userid ? self::get_user_picture($userid) : null;
            }
            if (preg_match('/^e(\d+)$/', $key, $matches)) {
                return template::get_first_file(template::FILEAREA_IMAGE, (int) $matches[1]);
            }
            return null;
        };
    }

    /**
     * Where the pictures of an issued transcript come from.
     *
     * @param int $issueid
     * @return \Closure
     */
    public static function get_issue_image_provider(int $issueid): \Closure {
        return function (string $key) use ($issueid) {
            $file = get_file_storage()->get_file(template::get_context()->id, 'local_transcript',
                template::FILEAREA_ISSUE, $issueid, '/', $key);
            return $file ?: null;
        };
    }

    /**
     * Make a PDF of a layout with sample data, or with the data of a student, without recording anything.
     *
     * @param int $templateid
     * @param int|null $userid
     * @return string The PDF.
     */
    public static function render_preview(int $templateid, ?int $userid = null): string {
        $model = self::build_model($templateid, $userid);
        $model['code'] = 'XXXX-XXXX-XXXX';
        $builder = new pdf_builder($model, self::get_live_image_provider($templateid, $userid));
        return $builder->build();
    }

    /**
     * The profile picture of a user, in the largest size that Moodle keeps.
     *
     * @param int $userid
     * @return \stored_file|null
     */
    public static function get_user_picture(int $userid): ?\stored_file {
        $user = \core_user::get_user($userid, 'id, picture', IGNORE_MISSING);
        if (!$user || !$user->picture) {
            return null;
        }
        $context = \context_user::instance($userid, IGNORE_MISSING);
        if (!$context) {
            return null;
        }
        foreach (['f3', 'f1'] as $name) {
            foreach (['.png', '.jpg'] as $extension) {
                $file = get_file_storage()->get_file($context->id, 'user', 'icon', 0, '/', $name . $extension);
                if ($file && !$file->is_directory()) {
                    return $file;
                }
            }
        }
        return null;
    }

    /**
     * Generate a transcript: record it, with its verification code and snapshot.
     *
     * @param int $templateid
     * @param int $userid The student.
     * @param int $issuedby The user who is generating it.
     * @param array $options What to include, see build_model().
     * @return \stdClass The issue record, with the PDF available through render().
     */
    public static function create_issue(int $templateid, int $userid, int $issuedby, array $options = []): \stdClass {
        global $DB;

        $model = self::build_model($templateid, $userid, $options);
        $template = template::get($templateid);

        $transaction = $DB->start_delegated_transaction();
        $issue = new \stdClass();
        try {
            $model['code'] = self::create_unique_code();
            $issue->userid = $userid;
            $issue->issuedby = $issuedby;
            $issue->templateid = $templateid;
            $issue->templatename = \core_text::substr($template->name, 0, 255);
            $issue->code = $model['code'];
            $issue->revoked = 0;
            $issue->snapshot = json_encode($model);
            $issue->timecreated = $model['issuedate'];
            $issue->id = $DB->insert_record('local_transcript_issue', $issue);

            self::copy_images_to_issue($model, $templateid, (int) $issue->id, $userid);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (!empty($issue->id)) {
                get_file_storage()->delete_area_files(template::get_context()->id, 'local_transcript',
                    template::FILEAREA_ISSUE, $issue->id);
            }
            $transaction->rollback($e);
        }

        \local_transcript\event\transcript_issued::create([
            'context' => template::get_context(),
            'objectid' => $issue->id,
            'relateduserid' => $userid,
            'other' => ['templateid' => $templateid, 'code' => $issue->code],
        ])->trigger();

        return $issue;
    }

    /**
     * A verification code that has not been used.
     *
     * @return string
     */
    private static function create_unique_code(): string {
        global $DB;
        do {
            $code = helper::generate_code();
        } while ($DB->record_exists('local_transcript_issue', ['code' => $code]));
        return $code;
    }

    /**
     * Keep a copy of the pictures of the layout with the issued transcript. The file storage of Moodle stores
     * identical files only once, so this takes almost no extra room.
     *
     * @param array $model
     * @param int $templateid
     * @param int $issueid
     * @param int $userid The student.
     */
    private static function copy_images_to_issue(array $model, int $templateid, int $issueid, int $userid): void {
        $fs = get_file_storage();
        $contextid = template::get_context()->id;
        $copy = function (?\stored_file $file, string $name) use ($fs, $contextid, $issueid) {
            if (!$file) {
                return;
            }
            $fs->create_file_from_storedfile([
                'contextid' => $contextid, 'component' => 'local_transcript', 'filearea' => template::FILEAREA_ISSUE,
                'itemid' => $issueid, 'filepath' => '/', 'filename' => $name,
            ], $file);
        };
        $copy(template::get_first_file(template::FILEAREA_WATERMARK, $templateid), 'watermark');
        $copy(template::get_first_file(template::FILEAREA_BACKGROUND, $templateid), 'background');
        $copiedpicture = false;
        foreach ($model['elements'] as $element) {
            if ($element['type'] === 'image') {
                $copy(template::get_first_file(template::FILEAREA_IMAGE, (int) $element['id']), 'e' . $element['id']);
            } else if ($element['type'] === 'userpicture' && !$copiedpicture) {
                $copiedpicture = true;
                $copy(self::get_user_picture($userid), 'userpicture');
            }
        }
    }

    /**
     * Make the PDF of an issued transcript from its snapshot.
     *
     * @param \stdClass $issue A record of local_transcript_issue.
     * @return string The PDF.
     */
    public static function render_issue(\stdClass $issue): string {
        $model = json_decode($issue->snapshot, true);
        if (!is_array($model)) {
            throw new \moodle_exception('snapshotbroken', 'local_transcript');
        }
        $builder = new pdf_builder($model, self::get_issue_image_provider((int) $issue->id));
        return $builder->build();
    }

    /**
     * A file name for the PDF of an issued transcript.
     *
     * @param \stdClass $issue
     * @return string
     */
    public static function get_filename(\stdClass $issue): string {
        $model = json_decode($issue->snapshot, true);
        $name = $model['user']['fullname'] ?? 'student';
        $date = userdate($issue->timecreated, '%Y-%m-%d', \core_date::get_server_timezone());
        return clean_filename('Transcript_' . $name . '_' . $date . '.pdf');
    }

    /**
     * Find a transcript by its verification code.
     *
     * @param string $code What a person typed or scanned.
     * @return \stdClass|null
     */
    public static function get_by_code(string $code): ?\stdClass {
        global $DB;
        $code = helper::normalise_code($code);
        if ($code === '') {
            return null;
        }
        $issue = $DB->get_record('local_transcript_issue', ['code' => $code]);
        return $issue ?: null;
    }

    /**
     * Remove the record of an issued transcript, including its pictures.
     *
     * @param int $issueid
     */
    public static function delete_issue(int $issueid): void {
        global $DB;
        get_file_storage()->delete_area_files(template::get_context()->id, 'local_transcript',
            template::FILEAREA_ISSUE, $issueid);
        $DB->delete_records('local_transcript_issue', ['id' => $issueid]);
    }
}
