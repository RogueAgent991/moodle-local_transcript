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
 * Slows down people who keep trying verification codes.
 *
 * Wrong codes are counted in the application cache, once for the user (when logged in) and once for the
 * address of the computer. The cache is shared by all sessions, so a new session does not reset the count.
 * Nothing waits: when there were too many wrong codes, the code is not even looked up.
 *
 * @package    local_transcript
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class verify_throttle {

    /** @var int The number of wrong codes after which no more codes are tried. */
    public const MAX_FAILURES = 10;

    /**
     * The cache that holds the counts. They are forgotten after the time set in db/caches.php.
     *
     * @return \cache
     */
    private static function get_cache(): \cache {
        return \cache::make('local_transcript', 'verifyattempts');
    }

    /**
     * The keys under which wrong codes are counted: the user and the address of the computer.
     * The address is not kept as it is, only a hash of it.
     *
     * @return string[]
     */
    private static function get_keys(): array {
        global $USER;
        $keys = ['ip' . sha1((string) getremoteaddr())];
        if (isloggedin() && !isguestuser()) {
            $keys[] = 'user' . (int) $USER->id;
        }
        return $keys;
    }

    /**
     * Have there been too many wrong codes from this user or this computer?
     *
     * @return bool
     */
    public static function is_blocked(): bool {
        $cache = self::get_cache();
        foreach (self::get_keys() as $key) {
            if ((int) $cache->get($key) >= self::MAX_FAILURES) {
                return true;
            }
        }
        return false;
    }

    /**
     * Count one wrong code.
     */
    public static function record_failure(): void {
        $cache = self::get_cache();
        foreach (self::get_keys() as $key) {
            $cache->set($key, (int) $cache->get($key) + 1);
        }
    }
}
