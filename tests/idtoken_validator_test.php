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
 * ID token validator test cases.
 *
 * @package auth_oidc
 * @copyright (C) 2014 onwards Microsoft, Inc. (http://microsoft.com/)
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_oidc;

use auth_oidc\tests\mockhttpclient;
use Firebase\JWT\JWT;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests idtoken_validator.
 *
 * @group auth_oidc
 * @group office365
 */
final class idtoken_validator_test extends \advanced_testcase {
    /** @var string Test key ID. */
    protected const KID = 'testkey';

    /**
     * Reset test state.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        \cache::make('auth_oidc', 'idtokenjwks')->purge();
    }

    /**
     * Build default validator config.
     *
     * @param array $overrides Config overrides.
     * @return \stdClass
     */
    protected function config(array $overrides = []): \stdClass {
        return (object)array_merge([
            'verifyidtokensignature' => 1,
            'jwksuri' => 'https://idp.example.test/keys',
            'clientid' => 'moodle-client',
            'idtokenissuer' => 'https://idp.example.test/realm',
        ], $overrides);
    }

    /**
     * Generate a signed token and matching JWKS.
     *
     * @param array $claimoverrides Claim overrides.
     * @return array Token string and JWKS JSON.
     */
    protected function signed_token(array $claimoverrides = []): array {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        openssl_pkey_export($key, $privatekey);
        $details = openssl_pkey_get_details($key);

        $claims = array_merge([
            'sub' => 'user-subject',
            'aud' => 'moodle-client',
            'iss' => 'https://idp.example.test/realm',
            'exp' => time() + 3600,
            'iat' => time() - 60,
        ], $claimoverrides);

        $jwks = [
            'keys' => [[
                'kty' => 'RSA',
                'kid' => self::KID,
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => $this->base64url($details['rsa']['n']),
                'e' => $this->base64url($details['rsa']['e']),
            ]],
        ];

        return [
            JWT::encode($claims, $privatekey, 'RS256', self::KID),
            json_encode($jwks),
        ];
    }

    /**
     * Base64url encode bytes.
     *
     * @param string $data Bytes to encode.
     * @return string
     */
    protected function base64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Create a mock HTTP client.
     *
     * @param string $jwks JWKS response.
     * @return mockhttpclient
     */
    protected function httpclient(string $jwks = ''): mockhttpclient {
        $httpclient = new mockhttpclient();
        if ($jwks !== '') {
            $httpclient->set_response($jwks);
        }
        return $httpclient;
    }

    /**
     * Test disabled verification keeps the existing decode-only behavior.
     *
     * @covers \auth_oidc\idtoken_validator::validate
     */
    public function test_disabled_verification_decodes_without_jwks(): void {
        [$token] = $this->signed_token();

        $result = idtoken_validator::validate(
            $token,
            $this->config(['verifyidtokensignature' => 0, 'jwksuri' => '']),
            $this->httpclient()
        );

        $this->assertSame('user-subject', $result->claim('sub'));
    }

    /**
     * Test a valid signature and claims pass.
     *
     * @covers \auth_oidc\idtoken_validator::validate
     */
    public function test_valid_signature_passes(): void {
        [$token, $jwks] = $this->signed_token();

        $result = idtoken_validator::validate($token, $this->config(), $this->httpclient($jwks));

        $this->assertSame('user-subject', $result->claim('sub'));
    }

    /**
     * Test audience mismatch fails.
     *
     * @covers \auth_oidc\idtoken_validator::validate
     */
    public function test_audience_mismatch_fails(): void {
        [$token, $jwks] = $this->signed_token(['aud' => 'other-client']);

        $this->expectExceptionMessage('ID token audience validation failed.');
        idtoken_validator::validate($token, $this->config(), $this->httpclient($jwks));
    }

    /**
     * Test tampered signature fails.
     *
     * @covers \auth_oidc\idtoken_validator::validate
     */
    public function test_invalid_signature_fails(): void {
        [$token, $jwks] = $this->signed_token();
        $parts = explode('.', $token);
        $parts[1] = $this->base64url(json_encode([
            'sub' => 'attacker',
            'aud' => 'moodle-client',
            'iss' => 'https://idp.example.test/realm',
            'exp' => time() + 3600,
        ]));
        $token = implode('.', $parts);

        $this->expectExceptionMessage('ID token signature validation failed.');
        idtoken_validator::validate($token, $this->config(), $this->httpclient($jwks));
    }
}
