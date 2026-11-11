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
 * Tests of the limit on wrong verification codes.
 *
 * @package    local_transcript
 * @category   test
 * @copyright  2026 Roy Jensen (created and copyrighted by Roy Jensen; development by Claude AI, Anthropic)
 * @copyright  The layout designer is modelled on mod_customcert (Custom Certificate), copyright 2013 Mark Nelson
 *             <markn@moodle.com> and contributors, https://moodle.org/plugins/mod_customcert (GNU GPL v3 or later)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_transcript\verify_throttle
 */
final class verify_throttle_test extends \advanced_testcase {

    /**
     * After too many wrong codes nothing more is tried, whatever the session.
     */
    public function test_too_many_wrong_codes_block(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertFalse(verify_throttle::is_blocked());
        for ($i = 0; $i < verify_throttle::MAX_FAILURES - 1; $i++) {
            verify_throttle::record_failure();
        }
        $this->assertFalse(verify_throttle::is_blocked());
        verify_throttle::record_failure();
        $this->assertTrue(verify_throttle::is_blocked());

        // A new session (the same computer, not logged in) is blocked too.
        $this->setUser(0);
        $this->assertTrue(verify_throttle::is_blocked());
    }
}
