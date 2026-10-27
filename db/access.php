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
 * Capability definitions for the Student Transcript plugin.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Generate your own transcript (every logged in user by default).
    'local/transcript:generateown' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'user' => CAP_ALLOW,
        ],
    ],

    // Generate the transcript of another user. Checked in the system context, in the user context and in the
    // courses the other user is enrolled in, so it works for managers and for course instructors.
    'local/transcript:generateforothers' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'riskbitmask' => RISK_PERSONAL,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],

    // Send a generated transcript by email to other people.
    'local/transcript:emailtranscript' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'riskbitmask' => RISK_PERSONAL,
        'archetypes' => [
            'user' => CAP_ALLOW,
        ],
    ],

    // Create and change transcript layouts.
    'local/transcript:managetemplates' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'riskbitmask' => RISK_CONFIG,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // See, revoke and delete the transcripts that were generated.
    'local/transcript:viewissues' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'riskbitmask' => RISK_PERSONAL,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Use a verification code to regenerate a transcript. Only used when the administrator chose
    // "Users with the verify capability" as the verification access. It can be given in a course (to teachers)
    // or for the whole site.
    'local/transcript:verify' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'riskbitmask' => RISK_PERSONAL,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
        ],
    ],
];
