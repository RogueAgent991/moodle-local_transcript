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
 * The list of generated transcripts, for managers.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_transcript\issue_manager;
use local_transcript\ui;

require_login(null, false);
$context = context_system::instance();
require_capability('local/transcript:viewissues', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$search = optional_param('search', '', PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 30;

$pageurl = new moodle_url('/local/transcript/issues.php', ['search' => $search, 'page' => $page]);
ui::setup_page('/local/transcript/issues.php', ['search' => $search, 'page' => $page],
    get_string('issuedtranscripts', 'local_transcript'), 'admin');

if ($action !== '' && $id) {
    require_sesskey();
    $DB->get_record('local_transcript_issue', ['id' => $id], 'id', MUST_EXIST);
    switch ($action) {
        case 'revoke':
            $DB->set_field('local_transcript_issue', 'revoked', 1, ['id' => $id]);
            redirect($pageurl);
        case 'restore':
            $DB->set_field('local_transcript_issue', 'revoked', 0, ['id' => $id]);
            redirect($pageurl);
        case 'delete':
            if ($confirm) {
                issue_manager::delete_issue($id);
                redirect($pageurl, get_string('issuedeleted', 'local_transcript'), null,
                    \core\output\notification::NOTIFY_SUCCESS);
            }
            echo $OUTPUT->header();
            echo $OUTPUT->confirm(get_string('deleteissue_confirm', 'local_transcript'),
                new moodle_url($pageurl, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]), $pageurl);
            echo $OUTPUT->footer();
            exit;
    }
}

$studentfields = \core_user\fields::for_name()->get_sql('u', false, 'stu', '', true);
$issuerfields = \core_user\fields::for_name()->get_sql('b', false, 'iss', '', true);
$where = '1 = 1';
$params = $studentfields->params + $issuerfields->params;
if (trim($search) !== '') {
    $like = '%' . $DB->sql_like_escape(trim($search)) . '%';
    $where = '(' . $DB->sql_like('i.code', ':s1', false, false) . ' OR ' . $DB->sql_like('u.lastname', ':s2', false, false) .
        ' OR ' . $DB->sql_like('u.firstname', ':s3', false, false) . ' OR ' . $DB->sql_like('u.username', ':s4', false, false) . ')';
    $params += ['s1' => $like, 's2' => $like, 's3' => $like, 's4' => $like];
}
$from = "FROM {local_transcript_issue} i
         JOIN {user} u ON u.id = i.userid
    LEFT JOIN {user} b ON b.id = i.issuedby
        WHERE $where";
$total = $DB->count_records_sql("SELECT COUNT(1) $from", $params);
$records = $DB->get_records_sql("SELECT i.id, i.userid, i.issuedby, i.templatename, i.code, i.revoked, i.timecreated
                                        {$studentfields->selects} {$issuerfields->selects}
                                   $from ORDER BY i.timecreated DESC, i.id DESC", $params, $page * $perpage, $perpage);

echo $OUTPUT->header();
echo html_writer::tag('form', html_writer::div(
    html_writer::empty_tag('input', ['type' => 'text', 'name' => 'search', 'value' => s($search),
        'class' => 'form-control w-auto mr-2 me-2', 'size' => 30, 'aria-label' => get_string('search'),
        'placeholder' => get_string('searchissues', 'local_transcript')]) .
    html_writer::tag('button', get_string('search'), ['type' => 'submit', 'class' => 'btn btn-secondary']),
    'd-flex align-items-center mb-3'), ['method' => 'get', 'action' => (new moodle_url('/local/transcript/issues.php'))->out(false)]);

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = [get_string('date'), get_string('student', 'local_transcript'), get_string('issuedby', 'local_transcript'),
    get_string('layout', 'local_transcript'), get_string('verificationcode', 'local_transcript'), get_string('status'),
    get_string('actions')];
foreach ($records as $record) {
    $student = new stdClass();
    username_load_fields_from_object($student, $record, 'stu');
    $student->id = $record->userid;
    $issuer = new stdClass();
    username_load_fields_from_object($issuer, $record, 'iss');
    $link = function (string $action, string $text) use ($record, $search, $page): string {
        return html_writer::link(new moodle_url('/local/transcript/issues.php', ['action' => $action, 'id' => $record->id,
            'search' => $search, 'page' => $page, 'sesskey' => sesskey()]), $text);
    };
    $table->data[] = [
        userdate($record->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
        fullname($student),
        $record->issuedby ? fullname($issuer) : '-',
        s($record->templatename),
        html_writer::tag('code', s($record->code)),
        $record->revoked ? html_writer::span(get_string('revoked', 'local_transcript'), 'badge bg-danger text-white') :
            get_string('valid', 'local_transcript'),
        implode(' | ', [
            html_writer::link(new moodle_url('/local/transcript/transcript.php', ['id' => $record->id]),
                get_string('open', 'local_transcript')),
            $record->revoked ? $link('restore', get_string('restore', 'local_transcript')) :
                $link('revoke', get_string('revoke', 'local_transcript')),
            $link('delete', get_string('delete')),
        ]),
    ];
}
if ($table->data) {
    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($total, $page, $perpage, new moodle_url('/local/transcript/issues.php', ['search' => $search]));
} else {
    echo $OUTPUT->notification(get_string('noissues', 'local_transcript'), \core\output\notification::NOTIFY_INFO);
}
echo $OUTPUT->footer();
