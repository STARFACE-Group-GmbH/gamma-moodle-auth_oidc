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
 * Test cases for the ID token validation admin settings.
 *
 * @package auth_oidc
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2026 onwards Gamma Communications
 */

namespace auth_oidc;

use auth_oidc\adminsetting\auth_oidc_admin_setting_idtokenvalidation_uri;
use auth_oidc\adminsetting\auth_oidc_admin_setting_verifyidtokensignature;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Tests that the ID token validation settings cannot be saved into a lockout.
 *
 * @group auth_oidc
 * @group office365
 */
final class adminsetting_test extends \advanced_testcase {
    /**
     * Reset test state.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        unset($_POST['s_auth_oidc_verifyidtokensignature']);
        unset($_POST['s_auth_oidc_jwksuri']);
        unset($_POST['s_auth_oidc_idtokenissuer']);
    }

    /**
     * Store a working ID token validation configuration.
     */
    protected function set_verified_config(): void {
        set_config('verifyidtokensignature', 1, 'auth_oidc');
        set_config('jwksuri', 'https://idp.example.test/keys', 'auth_oidc');
        set_config('idtokenissuer', 'https://idp.example.test/realm', 'auth_oidc');
    }

    /**
     * Build the setting object for one of the two URIs.
     *
     * @param string $name Setting name, jwksuri or idtokenissuer.
     * @return auth_oidc_admin_setting_idtokenvalidation_uri
     */
    protected function uri_setting(string $name): auth_oidc_admin_setting_idtokenvalidation_uri {
        return new auth_oidc_admin_setting_idtokenvalidation_uri(
            'auth_oidc/' . $name,
            $name,
            '',
            '',
            PARAM_URL,
            'error_' . $name . '_required'
        );
    }

    /**
     * Names of the two URI settings guarded against being cleared.
     *
     * @return array
     */
    public static function uri_setting_provider(): array {
        return [
            'JWKS endpoint' => ['jwksuri'],
            'Expected issuer' => ['idtokenissuer'],
        ];
    }

    /**
     * Clearing a required URI while verification stays enabled must be refused.
     *
     * @dataProvider uri_setting_provider
     * @param string $name Setting name.
     */
    public function test_uri_cannot_be_cleared_while_verification_enabled(string $name): void {
        $this->set_verified_config();
        $stored = get_config('auth_oidc', $name);
        $_POST['s_auth_oidc_verifyidtokensignature'] = '1';

        $result = $this->uri_setting($name)->write_setting('');

        $this->assertNotEmpty($result);
        $this->assertEquals($stored, get_config('auth_oidc', $name));
    }

    /**
     * The stored value counts when the checkbox is not part of the submission.
     *
     * @dataProvider uri_setting_provider
     * @param string $name Setting name.
     */
    public function test_uri_cannot_be_cleared_without_submitted_checkbox(string $name): void {
        $this->set_verified_config();
        $stored = get_config('auth_oidc', $name);

        $result = $this->uri_setting($name)->write_setting('');

        $this->assertNotEmpty($result);
        $this->assertEquals($stored, get_config('auth_oidc', $name));
    }

    /**
     * Clearing a required URI is allowed when verification is switched off in the same save.
     *
     * @dataProvider uri_setting_provider
     * @param string $name Setting name.
     */
    public function test_uri_can_be_cleared_when_verification_is_switched_off(string $name): void {
        $this->set_verified_config();
        $_POST['s_auth_oidc_verifyidtokensignature'] = '0';

        $result = $this->uri_setting($name)->write_setting('');

        $this->assertSame('', $result);
        $this->assertEmpty(get_config('auth_oidc', $name));
    }

    /**
     * Replacing a required URI with another one stays possible.
     *
     * @dataProvider uri_setting_provider
     * @param string $name Setting name.
     */
    public function test_uri_can_be_replaced_while_verification_enabled(string $name): void {
        $this->set_verified_config();
        $_POST['s_auth_oidc_verifyidtokensignature'] = '1';

        $result = $this->uri_setting($name)->write_setting('https://other.example.test/keys');

        $this->assertSame('', $result);
        $this->assertEquals('https://other.example.test/keys', get_config('auth_oidc', $name));
    }

    /**
     * Enabling verification without the required URIs must be refused.
     */
    public function test_verification_cannot_be_enabled_without_uris(): void {
        set_config('verifyidtokensignature', 0, 'auth_oidc');
        set_config('jwksuri', '', 'auth_oidc');
        set_config('idtokenissuer', '', 'auth_oidc');
        $setting = new auth_oidc_admin_setting_verifyidtokensignature(
            'auth_oidc/verifyidtokensignature',
            'verify',
            '',
            0
        );

        $this->assertNotEmpty($setting->write_setting('1'));
        $this->assertEmpty(get_config('auth_oidc', 'verifyidtokensignature'));

        $_POST['s_auth_oidc_jwksuri'] = 'https://idp.example.test/keys';
        $this->assertNotEmpty($setting->write_setting('1'));
        $this->assertEmpty(get_config('auth_oidc', 'verifyidtokensignature'));

        $_POST['s_auth_oidc_idtokenissuer'] = 'https://idp.example.test/realm';
        $this->assertSame('', $setting->write_setting('1'));
        $this->assertEquals('1', get_config('auth_oidc', 'verifyidtokensignature'));
    }
}
