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
 * ID token validator.
 *
 * @package auth_oidc
 * @copyright (C) 2014 onwards Microsoft, Inc. (http://microsoft.com/)
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace auth_oidc;

use moodle_exception;

/**
 * Validates OpenID Connect ID tokens.
 */
class idtoken_validator {
    /**
     * Decode an ID token and optionally verify its signature and standard claims.
     *
     * @param string $encodedtoken Encoded ID token.
     * @param \stdClass $config Plugin configuration.
     * @param httpclientinterface $httpclient HTTP client used to fetch JWKS.
     * @return jwt Decoded and validated token.
     * @throws moodle_exception
     */
    public static function validate(string $encodedtoken, \stdClass $config, httpclientinterface $httpclient): jwt {
        if (empty($config->verifyidtokensignature)) {
            return jwt::instance_from_encoded($encodedtoken);
        }

        $jwksuri = trim($config->jwksuri ?? '');
        $clientid = trim($config->clientid ?? '');
        $issuer = trim($config->idtokenissuer ?? '');
        if ($jwksuri === '' || $clientid === '' || $issuer === '') {
            throw new moodle_exception('errorjwtverificationnotconfigured', 'auth_oidc');
        }

        [$payload, $header] = self::decode_with_jwks($encodedtoken, $jwksuri, $httpclient);
        self::validate_audience($payload, $clientid);
        self::validate_issuer($payload, $issuer);

        $token = new jwt();
        $token->set_header(self::object_to_array($header));
        $token->set_claims(self::object_to_array($payload));
        return $token;
    }

    /**
     * Decode a JWT using cached JWKS, refreshing once on miss or validation failure.
     *
     * @param string $encodedtoken Encoded ID token.
     * @param string $jwksuri JWKS endpoint URI.
     * @param httpclientinterface $httpclient HTTP client.
     * @return array Verified token payload and header.
     * @throws moodle_exception
     */
    protected static function decode_with_jwks(
        string $encodedtoken,
        string $jwksuri,
        httpclientinterface $httpclient
    ): array {
        $cache = \cache::make('auth_oidc', 'idtokenjwks');
        $cachekey = sha1($jwksuri);
        $keyset = $cache->get($cachekey);

        if (!empty($keyset)) {
            try {
                return self::decode_with_keyset($encodedtoken, $keyset);
            } catch (\Exception $e) {
                self::debug_validation_failure(
                    'Cached JWKS failed ID token validation, refreshing keys.',
                    $encodedtoken,
                    $keyset,
                    $jwksuri,
                    $e
                );
            }
        }

        $keyset = $httpclient->get($jwksuri);
        if (empty($keyset) || !is_string($keyset)) {
            self::debug_validation_message('Could not fetch JWKS for ID token signature verification.', [
                'jwksuri' => $jwksuri,
                'response_type' => gettype($keyset),
                'response_empty' => empty($keyset),
            ]);
            throw new moodle_exception('errorjwtjwksfetchfailed', 'auth_oidc');
        }

        try {
            $payload = self::decode_with_keyset($encodedtoken, $keyset);
        } catch (\Exception $e) {
            self::debug_validation_failure('ID token signature validation failed.', $encodedtoken, $keyset, $jwksuri, $e);
            throw new moodle_exception('errorjwtvalidationfailed', 'auth_oidc');
        }

        $cache->set($cachekey, $keyset);
        return $payload;
    }

    /**
     * Decode a JWT using a raw JWKS document.
     *
     * ES256/PS256 keys are only recognised correctly when the IdP's JWKS entry declares its
     * own "alg"; a JWK published without one is assumed to be RS256, so an ES256 or PS256
     * token signed by such a key fails verification. Keycloak always declares "alg".
     *
     * @param string $encodedtoken Encoded ID token.
     * @param string $keyset Raw JWKS JSON.
     * @return array Verified token payload and header.
     */
    protected static function decode_with_keyset(string $encodedtoken, string $keyset): array {
        $keysetarr = json_decode($keyset, true);
        if (empty($keysetarr) || !is_array($keysetarr) || !isset($keysetarr['keys'])) {
            throw new \UnexpectedValueException('Invalid JWKS');
        }

        $keys = \Firebase\JWT\JWK::parseKeySet($keysetarr, 'RS256');
        $header = new \stdClass();
        $payload = \Firebase\JWT\JWT::decode($encodedtoken, $keys, $header);
        return [$payload, $header];
    }

    /**
     * Debug a validation failure without logging token or key material.
     *
     * @param string $message Debug message.
     * @param string $encodedtoken Encoded token.
     * @param string $keyset Raw JWKS JSON.
     * @param string $jwksuri JWKS endpoint URI.
     * @param \Exception $exception Validation exception.
     */
    protected static function debug_validation_failure(
        string $message,
        string $encodedtoken,
        string $keyset,
        string $jwksuri,
        \Exception $exception
    ): void {
        $context = [
            'jwksuri' => $jwksuri,
            'token_header' => self::safe_token_header($encodedtoken),
            'jwks' => self::safe_jwks_summary($keyset),
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
        ];
        self::debug_validation_message($message, $context);
    }

    /**
     * Write validation debug details to Moodle debug logging.
     *
     * @param string $message Debug message.
     * @param array $context Safe debug context.
     */
    protected static function debug_validation_message(string $message, array $context): void {
        utils::debug($message, __METHOD__, $context);
    }

    /**
     * Decode safe token header details.
     *
     * @param string $encodedtoken Encoded token.
     * @return array
     */
    protected static function safe_token_header(string $encodedtoken): array {
        try {
            [$header] = jwt::decode($encodedtoken);
            return [
                'alg' => $header['alg'] ?? null,
                'kid' => $header['kid'] ?? null,
                'typ' => $header['typ'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'decode_error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Summarise JWKS without logging key material.
     *
     * @param string $keyset Raw JWKS JSON.
     * @return array
     */
    protected static function safe_jwks_summary(string $keyset): array {
        $keysetarr = json_decode($keyset, true);
        if (empty($keysetarr) || !is_array($keysetarr) || !isset($keysetarr['keys']) || !is_array($keysetarr['keys'])) {
            return [
                'valid_json' => is_array($keysetarr),
                'has_keys' => isset($keysetarr['keys']),
            ];
        }

        $keys = [];
        foreach ($keysetarr['keys'] as $key) {
            if (!is_array($key)) {
                continue;
            }
            $keys[] = [
                'kid' => $key['kid'] ?? null,
                'alg' => $key['alg'] ?? null,
                'kty' => $key['kty'] ?? null,
                'use' => $key['use'] ?? null,
            ];
        }
        return [
            'key_count' => count($keysetarr['keys']),
            'keys' => $keys,
        ];
    }

    /**
     * Validate token audience against the configured client ID.
     *
     * @param \stdClass $payload Verified payload.
     * @param string $clientid Expected audience.
     * @throws moodle_exception
     */
    protected static function validate_audience(\stdClass $payload, string $clientid): void {
        $audiences = $payload->aud ?? null;
        if (is_string($audiences)) {
            $audiences = [$audiences];
        }
        if (!is_array($audiences) || !in_array($clientid, $audiences, true)) {
            throw new moodle_exception('errorjwtinvalidaudience', 'auth_oidc');
        }

        // OIDC Core 3.1.3.7 (10): a multi-valued aud requires an azp claim matching the client ID.
        if (count($audiences) > 1 && ($payload->azp ?? null) !== $clientid) {
            throw new moodle_exception('errorjwtinvalidaudience', 'auth_oidc');
        }
    }

    /**
     * Validate the token issuer against the expected issuer.
     *
     * @param \stdClass $payload Verified payload.
     * @param string $issuer Expected issuer.
     * @throws moodle_exception
     */
    protected static function validate_issuer(\stdClass $payload, string $issuer): void {
        if (($payload->iss ?? '') !== $issuer) {
            throw new moodle_exception('errorjwtinvalidissuer', 'auth_oidc');
        }
    }

    /**
     * Convert a stdClass payload to an array of claims.
     *
     * @param \stdClass $object Object to convert.
     * @return array
     */
    protected static function object_to_array(\stdClass $object): array {
        return json_decode(json_encode($object), true);
    }
}
