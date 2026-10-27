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


/**
 * Library functions of the Student Transcript plugin.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add a link to the transcript to the profile page of a user. It shows for the user's own profile and
 * for the profile of students that the viewer may generate a transcript for.
 *
 * @param \core_user\output\myprofile\tree $tree
 * @param stdClass $user The user whose profile is shown.
 * @param bool $iscurrentuser
 * @param stdClass|null $course
 */
function local_transcript_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    if (!\local_transcript\permissions::can_generate_for((int) $user->id)) {
        return;
    }
    $url = new moodle_url('/local/transcript/index.php', ['userid' => $user->id]);
    $node = new \core_user\output\myprofile\node('reports', 'local_transcript', get_string('transcript', 'local_transcript'),
        null, $url);
    $tree->add_node($node);
}

/**
 * Serve the pictures of the layouts to the people who design them.
 *
 * @param stdClass $course
 * @param stdClass|null $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool False when the file cannot be sent.
 */
function local_transcript_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }
    if ($filearea === \local_transcript\template::FILEAREA_EMAIL) {
        // The pictures in the email message are seen by people who are not logged in, in their email program.
        $itemid = (int) array_shift($args);
        $filename = array_pop($args);
        $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
        $file = get_file_storage()->get_file($context->id, 'local_transcript', $filearea, $itemid, $filepath, $filename);
        if (!$file || $file->is_directory() || strpos((string) $file->get_mimetype(), 'image/') !== 0) {
            return false;
        }
        send_stored_file($file, 86400, 0, false, $options);
    }
    if (!in_array($filearea, [\local_transcript\template::FILEAREA_IMAGE, \local_transcript\template::FILEAREA_WATERMARK,
            \local_transcript\template::FILEAREA_BACKGROUND])) {
        return false;
    }
    require_login(null, false);
    require_capability('local/transcript:managetemplates', $context);

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, 'local_transcript', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}
