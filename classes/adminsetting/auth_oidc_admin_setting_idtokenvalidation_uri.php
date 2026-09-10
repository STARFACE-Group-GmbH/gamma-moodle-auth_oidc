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
 * Admin setting class for the URIs required by ID token signature verification.
 *
 * @package auth_oidc
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @copyright (C) 2026 onwards Gamma Communications
 */

namespace auth_oidc\adminsetting;

/**
 * Refuses to clear a URI that ID token signature verification depends on while that
 * verification stays enabled.
 *
 * This is the counterpart to auth_oidc_admin_setting_verifyidtokensignature: without it
 * the lockout the checkbox guard prevents can still be reached from the other side,
 * because admin_write_settings() writes each setting on its own and keeps the settings
 * that validated even when a sibling setting reported an error.
 */
class auth_oidc_admin_setting_idtokenvalidation_uri extends \admin_setting_configtext {
    /** @var string Identifier of the error string shown when the value is cleared. */
    protected $emptyerror;

    /**
     * Constructor.
     *
     * @param string $name Unique ascii name of the setting.
     * @param string $visiblename Localised name of the setting.
     * @param string $description Localised description of the setting.
     * @param string $defaultsetting Default value of the setting.
     * @param mixed $paramtype Parameter type used to clean the submitted value.
     * @param string $emptyerror Identifier of the auth_oidc error string shown when the value is cleared.
     */
    public function __construct($name, $visiblename, $description, $defaultsetting, $paramtype, $emptyerror) {
        $this->emptyerror = $emptyerror;
        parent::__construct($name, $visiblename, $description, $defaultsetting, $paramtype);
    }

    /**
     * Reject an empty value while ID token signature verification remains enabled.
     *
     * @param mixed $data Submitted value.
     * @return string Empty string on success, translated error string on failure.
     */
    public function write_setting($data) {
        if (trim((string)$data) === '' && self::verification_remains_enabled()) {
            return get_string($this->emptyerror, 'auth_oidc');
        }

        return parent::write_setting($data);
    }

    /**
     * Whether ID token signature verification is enabled once the current save is through.
     *
     * The submitted checkbox value wins over the stored one, so that disabling verification
     * and clearing the URIs in the same save is still allowed.
     *
     * @return bool
     */
    protected static function verification_remains_enabled(): bool {
        $verify = optional_param(
            's_auth_oidc_verifyidtokensignature',
            get_config('auth_oidc', 'verifyidtokensignature'),
            PARAM_RAW
        );

        return !empty($verify);
    }
}
