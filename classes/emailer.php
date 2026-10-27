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
 * Sends a transcript as an email attachment to any email address.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class emailer {

    /** @var string[] What the mail system of the site said when an email could not be sent (filled by the observer). */
    public static $errors = [];

    /**
     * Split what a person typed into email addresses.
     *
     * Addresses can be separated by commas, semicolons, spaces or new lines.
     *
     * @param string $input
     * @return array[] A list of the valid addresses and a list of the words that are not valid addresses.
     */
    public static function parse_addresses(string $input): array {
        $valid = [];
        $invalid = [];
        foreach (preg_split('/[\s,;]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if (validate_email($word)) {
                $valid[\core_text::strtolower($word)] = $word;
            } else {
                $invalid[] = $word;
            }
        }
        return [array_values($valid), $invalid];
    }

    /**
     * The most addresses that may be used in one email.
     *
     * @return int
     */
    public static function get_max_recipients(): int {
        $max = (int) get_config('local_transcript', 'maxrecipients');
        return $max > 0 ? $max : 5;
    }

    /**
     * The model (layout and data) that was saved with an issued transcript.
     *
     * @param \stdClass $issue
     * @return array
     */
    private static function get_model(\stdClass $issue): array {
        $model = json_decode($issue->snapshot, true);
        return is_array($model) ? $model : [];
    }

    /**
     * The subject of the email. It is set in the master control of the layout, with placeholders filled in.
     *
     * @param \stdClass $issue
     * @return string
     */
    public static function get_subject(\stdClass $issue): string {
        $model = self::get_model($issue);
        $subject = trim((string) ($model['template']['emailsubject'] ?? ''));
        if ($subject === '') {
            $subject = get_string('email_defaultsubject', 'local_transcript');
        }
        $subject = placeholders::replace($subject, $model);
        return \core_text::substr(trim(preg_replace('/\s+/u', ' ', helper::plain_text($subject))), 0, 255);
    }

    /**
     * The message of the email as HTML. It is set in the master control of the layout, with placeholders filled in.
     *
     * @param \stdClass $issue
     * @return string HTML
     */
    public static function get_message_html(\stdClass $issue): string {
        $model = self::get_model($issue);
        $message = (string) ($model['template']['emailmessage'] ?? '');
        if (trim($message) === '') {
            $message = get_string('email_defaultmessage', 'local_transcript');
        }
        // Pictures of the message are files of the plugin. The address is complete, so email programs can load them.
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $message = \file_rewrite_pluginfile_urls($message, 'pluginfile.php', template::get_context()->id, 'local_transcript',
            template::FILEAREA_EMAIL, (int) ($model['template']['id'] ?? 0));
        // An editor can write the braces of a placeholder in an address as %7B and %7D.
        $message = str_ireplace(['%7B%7B', '%7D%7D'], ['{{', '}}'], $message);
        $message = placeholders::replace($message, $model, 1, 1, true);
        return clean_text($message, (int) ($model['template']['emailmessageformat'] ?? FORMAT_HTML));
    }

    /**
     * Send the PDF of a transcript with the email routine of Moodle (email_to_user). Each receiver gets an email of
     * their own. The sender then gets one email, which says to whom the others went. Without any receivers the
     * transcript is only sent to the sender.
     *
     * @param \stdClass $issue The issue record.
     * @param string[] $addresses Valid email addresses. May be empty.
     * @return array[] The addresses that were sent to and the addresses that failed. When there are no receivers, the
     *         address of the sender is the one that was sent to.
     */
    public static function send(\stdClass $issue, array $addresses): array {
        global $USER;

        $pdf = issue_manager::render_issue($issue);
        $filename = issue_manager::get_filename($issue);
        $directory = make_request_directory();
        $path = $directory . '/' . $filename;
        file_put_contents($path, $pdf);

        $subject = self::get_subject($issue);
        $html = self::get_message_html($issue);
        $from = \core_user::get_noreply_user();

        $sent = [];
        $failed = [];
        $addresses = array_values(array_unique($addresses));
        foreach ($addresses as $address) {
            if (self::send_one(self::make_recipient($address), $from, $subject, $html, $path, $filename)) {
                $sent[] = $address;
            } else {
                $failed[] = $address;
            }
        }

        if (!$addresses) {
            // Only for the sender.
            if (self::send_one($USER, $from, $subject, $html, $path, $filename)) {
                $sent[] = (string) $USER->email;
            } else {
                $failed[] = (string) $USER->email;
            }
        } else if ($sent) {
            // One email for the sender, which lists everybody who got the transcript.
            $note = \html_writer::tag('p', get_string('email_copynote', 'local_transcript',
                s(self::make_list($sent))));
            if (!self::send_one($USER, $from, $subject, $note . $html, $path, $filename)) {
                $failed[] = (string) $USER->email;
            }
        }
        @unlink($path);

        if ($sent) {
            \local_transcript\event\transcript_emailed::create([
                'context' => template::get_context(),
                'objectid' => $issue->id,
                'relateduserid' => $issue->userid,
                'other' => ['recipients' => count($sent)],
            ])->trigger();
        }
        return [$sent, $failed];
    }

    /**
     * Write a list of addresses in a sentence: "a", "a and b", "a, b, and c".
     *
     * @param string[] $items
     * @return string
     */
    public static function make_list(array $items): string {
        $items = array_values($items);
        $count = count($items);
        if ($count <= 1) {
            return (string) ($items[0] ?? '');
        }
        $last = array_pop($items);
        if ($count === 2) {
            return get_string('email_list_two', 'local_transcript', ['first' => $items[0], 'last' => $last]);
        }
        return get_string('email_list_many', 'local_transcript', ['most' => implode(', ', $items), 'last' => $last]);
    }

    /**
     * Send one email to one person with email_to_user().
     *
     * @param \stdClass $to
     * @param \stdClass $from
     * @param string $subject
     * @param string $html The message.
     * @param string $path The PDF.
     * @param string $filename The name of the PDF in the email.
     * @return bool
     */
    private static function send_one(\stdClass $to, \stdClass $from, string $subject, string $html, string $path,
            string $filename): bool {
        global $USER;
        $text = html_to_text($html, 0, false);
        return (bool) email_to_user($to, $from, $subject, $text, $html, $path, $filename, true,
            $USER->email ?? '', fullname($USER));
    }

    /**
     * A receiver for email_to_user(): somebody who does not need to have an account.
     *
     * @param string $address
     * @return \stdClass
     */
    private static function make_recipient(string $address): \stdClass {
        $to = clone \core_user::get_noreply_user();
        $to->email = $address;
        $to->firstname = '';
        $to->lastname = $address;
        $to->emailstop = 0;
        $to->mailformat = 1;
        return $to;
    }
}
