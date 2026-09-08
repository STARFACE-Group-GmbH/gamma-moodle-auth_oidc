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
 * Admin setting class for the ID token signature verification checkbox.
 *
 * @package auth_oidc
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2026 onwards Gamma Communications
 */

namespace auth_oidc\adminsetting;

/**
 * Refuses to enable ID token signature verification while the JWKS endpoint or expected
 * issuer are not configured, so an admin cannot lock every user (including themselves)
 * out via the settings page.
 */
class auth_oidc_admin_setting_verifyidtokensignature extends \admin_setting_configcheckbox {
    /**
     * Reject enabling verification when the JWKS endpoint or expected issuer are not
     * (yet) configured.
     *
     * @param mixed $data Submitted checkbox value.
     * @return string Empty string on success, translated error string on failure.
     */
    public function write_setting($data) {
        if ((string)$data === $this->yes) {
            $jwksuri = optional_param('s_auth_oidc_jwksuri', get_config('auth_oidc', 'jwksuri'), PARAM_URL);
            if (empty($jwksuri)) {
                return get_string('error_verifyidtokensignature_no_jwksuri', 'auth_oidc');
            }

            $idtokenissuer = optional_param('s_auth_oidc_idtokenissuer', get_config('auth_oidc', 'idtokenissuer'), PARAM_URL);
            if (empty($idtokenissuer)) {
                return get_string('error_verifyidtokensignature_no_issuer', 'auth_oidc');
            }
        }

        return parent::write_setting($data);
    }
}
