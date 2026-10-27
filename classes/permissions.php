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
 * Who may do what. All the rules about permissions are in this one class.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class permissions {

    /** @var int The most students that are shown when searching. */
    public const SEARCH_LIMIT = 50;

    /**
     * Is somebody logged in with a real account (not the guest)?
     *
     * @return bool
     */
    public static function is_real_user(): bool {
        return isloggedin() && !isguestuser();
    }

    /**
     * May the current user generate the transcript of a student?
     *
     * - Everyone with the "generate own" permission can generate their own.
     * - For other students, the "generate for others" permission is needed. It can be given site wide (managers),
     *   in the context of the student (for example to a parent or mentor) or in a course the student is in
     *   (for instructors).
     *
     * @param int $studentid
     * @return bool
     */
    public static function can_generate_for(int $studentid): bool {
        global $USER;

        if (!self::is_real_user()) {
            return false;
        }
        $student = \core_user::get_user($studentid, 'id, deleted');
        if (!$student || $student->deleted) {
            return false;
        }
        $system = \context_system::instance();
        if ((int) $USER->id === $studentid) {
            return has_capability('local/transcript:generateown', $system);
        }

        $capability = 'local/transcript:generateforothers';
        if (has_capability($capability, $system) || has_capability($capability, \context_user::instance($studentid))) {
            return true;
        }
        foreach (self::get_enrolled_course_ids($studentid) as $courseid) {
            if (has_capability($capability, \context_course::instance($courseid, IGNORE_MISSING))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Stop with an error unless the current user may generate the transcript of the student.
     *
     * @param int $studentid
     */
    public static function require_generate_for(int $studentid): void {
        if (!self::can_generate_for($studentid)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/transcript:generateforothers',
                'nopermissions', '');
        }
    }

    /**
     * May the current user send the transcript of a student by email?
     *
     * @param int $studentid
     * @return bool
     */
    public static function can_email_for(int $studentid): bool {
        $enabled = get_config('local_transcript', 'emailenabled');
        if ($enabled !== false && !$enabled) {
            return false;
        }
        return self::can_generate_for($studentid)
            && has_capability('local/transcript:emailtranscript', \context_system::instance());
    }

    /**
     * Can the current user look for other students at all?
     *
     * @return bool
     */
    public static function can_search_students(): bool {
        global $USER;
        if (!self::is_real_user()) {
            return false;
        }
        $capability = 'local/transcript:generateforothers';
        if (has_capability($capability, \context_system::instance())) {
            return true;
        }
        return (bool) get_user_capability_course($capability, $USER->id, false, '', '', 1)
            || (bool) self::get_user_context_students();
    }

    /**
     * May the current user manage the layouts?
     *
     * @return bool
     */
    public static function can_manage_templates(): bool {
        return self::is_real_user() && has_capability('local/transcript:managetemplates', \context_system::instance());
    }

    /**
     * May the current user see the list of generated transcripts?
     *
     * @return bool
     */
    public static function can_view_issues(): bool {
        return self::is_real_user() && has_capability('local/transcript:viewissues', \context_system::instance());
    }

    /**
     * May the current user (who might not be logged in) use a verification code to get the transcript back?
     * The administrator decides who that is.
     *
     * @return bool
     */
    public static function can_verify(): bool {
        $mode = get_config('local_transcript', 'verifyaccess');
        switch ($mode) {
            case helper::VERIFY_PUBLIC:
                return true;
            case helper::VERIFY_LOGGEDIN:
            case helper::VERIFY_LEARNER:
                // Learners are checked against the transcript itself, see can_verify_issue().
                return self::is_real_user();
        }
        return self::has_verify_capability();
    }

    /**
     * Does the current user have the verify capability: in the whole site (managers) or in a course (teachers)?
     *
     * @return bool
     */
    private static function has_verify_capability(): bool {
        if (!self::is_real_user()) {
            return false;
        }
        return is_siteadmin()
            || has_capability('local/transcript:verify', \context_system::instance())
            || (bool) get_user_capability_course('local/transcript:verify', null, false, '', '', 1);
    }

    /**
     * May the current user open this particular transcript with its verification code?
     *
     * @param \stdClass $issue A record of local_transcript_issue.
     * @return bool
     */
    public static function can_verify_issue(\stdClass $issue): bool {
        global $USER;
        if (!self::can_verify()) {
            return false;
        }
        if (get_config('local_transcript', 'verifyaccess') === helper::VERIFY_LEARNER) {
            return (int) $issue->userid === (int) $USER->id || self::has_verify_capability();
        }
        return true;
    }

    /**
     * May the current user open, download or send a transcript that was generated earlier?
     *
     * Managers who may see the list of issued transcripts can always open them. Everybody else needs to be
     * allowed to generate the transcript of that student, and cannot use a transcript that was revoked.
     *
     * @param \stdClass $issue A record of local_transcript_issue.
     * @return bool
     */
    public static function can_access_issue(\stdClass $issue): bool {
        if (!self::is_real_user()) {
            return false;
        }
        if (self::can_view_issues()) {
            return true;
        }
        return empty($issue->revoked) && self::can_generate_for((int) $issue->userid);
    }

    /**
     * Stop with an error unless the current user may use an issued transcript.
     *
     * @param \stdClass $issue
     */
    public static function require_access_issue(\stdClass $issue): void {
        if (!self::can_access_issue($issue)) {
            throw new \required_capability_exception(\context_system::instance(), 'local/transcript:generateforothers',
                'nopermissions', '');
        }
    }

    /**
     * The ids of the courses in which a user is enrolled, including suspended enrolments.
     *
     * @param int $userid
     * @return int[]
     */
    private static function get_enrolled_course_ids(int $userid): array {
        global $DB;
        $sql = "SELECT DISTINCT e.courseid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.userid = ? AND e.courseid <> ?";
        return array_map('intval', $DB->get_fieldset_sql($sql, [$userid, SITEID]));
    }

    /**
     * The students for whom the current user has the permission in the context of the student.
     *
     * @return int[]
     */
    private static function get_user_context_students(): array {
        global $DB, $USER;
        $sql = "SELECT DISTINCT c.instanceid
                  FROM {role_assignments} ra
                  JOIN {context} c ON c.id = ra.contextid AND c.contextlevel = :level
                 WHERE ra.userid = :userid";
        $found = [];
        foreach ($DB->get_fieldset_sql($sql, ['level' => CONTEXT_USER, 'userid' => $USER->id]) as $studentid) {
            if (has_capability('local/transcript:generateforothers', \context_user::instance($studentid))) {
                $found[] = (int) $studentid;
            }
        }
        return $found;
    }

    /**
     * Look for students whose transcript the current user may generate.
     *
     * @param string $search Part of a name, user name, id number or email address.
     * @return \stdClass[] Users with the fields needed to show their name, at most SEARCH_LIMIT.
     */
    public static function find_students(string $search): array {
        global $DB, $USER;

        $search = trim($search);
        if ($search === '' || !self::can_search_students()) {
            return [];
        }

        $capability = 'local/transcript:generateforothers';
        $userfields = \core_user\fields::for_name()->with_identity(\context_system::instance());
        $fieldsql = $userfields->get_sql('u', false, '', '', true);

        $wheres = ['u.deleted = 0', 'u.id <> :guestid', 'u.id <> :me'];
        $params = ['guestid' => guest_user()->id, 'me' => $USER->id] + $fieldsql->params;

        // Every word has to be found in the name, user name, id number or email address.
        $index = 0;
        foreach (preg_split('/\s+/', $search) as $word) {
            $index++;
            $like = '%' . $DB->sql_like_escape($word) . '%';
            $parts = [];
            foreach (['u.firstname', 'u.lastname', 'u.username', 'u.idnumber', 'u.email'] as $field) {
                $param = 'w' . $index . str_replace('.', '', $field);
                $parts[] = $DB->sql_like($field, ':' . $param, false, false);
                $params[$param] = $like;
            }
            $wheres[] = '(' . implode(' OR ', $parts) . ')';
        }

        $joins = '';
        if (!has_capability($capability, \context_system::instance())) {
            $courseids = array_map(function ($course) {
                return (int) $course->id;
            }, get_user_capability_course($capability, $USER->id, false) ?: []);
            $studentids = self::get_user_context_students();
            $access = [];
            if ($courseids) {
                [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'crs');
                $access[] = "u.id IN (SELECT ue.userid
                                        FROM {user_enrolments} ue
                                        JOIN {enrol} e ON e.id = ue.enrolid
                                       WHERE e.courseid $insql)";
                $params += $inparams;
            }
            if ($studentids) {
                [$insql, $inparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'stu');
                $access[] = "u.id $insql";
                $params += $inparams;
            }
            if (!$access) {
                return [];
            }
            $wheres[] = '(' . implode(' OR ', $access) . ')';
        }

        $sql = "SELECT u.id {$fieldsql->selects}
                  FROM {user} u
                  $joins
                 WHERE " . implode(' AND ', $wheres) . "
              ORDER BY u.lastname, u.firstname, u.id";
        return $DB->get_records_sql($sql, $params, 0, self::SEARCH_LIMIT);
    }
}
