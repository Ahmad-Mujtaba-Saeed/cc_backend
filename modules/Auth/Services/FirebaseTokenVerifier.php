<?php

namespace Modules\Auth\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;

class FirebaseTokenVerifier
{
    protected $publicKeysUrl = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';
    protected $cacheKey = 'AIzaSyAuu2ed5WspBJo13KZVRL6BNBdoooZQiP8';
    protected $cacheTtl = 3600; // 1 hour

    /**
     * Verify a Firebase ID token
     *
     * @param string $idToken
     * @return array
     * @throws \Exception
     */
    public function verifyToken($idToken)
    {
        try {
            // Get Firebase public keys (cached for performance)
            $publicKeys = $this->getPublicKeys();

            // Decode the token header to get the key ID
            $tks = explode('.', $idToken);
            if (count($tks) !== 3) {
                throw new \Exception('Invalid token: Wrong number of segments');
            }

            list($headb64) = $tks;
            $header = json_decode($this->urlSafeB64Decode($headb64), true);
            
            if (empty($header['kid'])) {
                throw new \Exception('Invalid token: No key ID in token header');
            }

            $kid = $header['kid'];

            if (!isset($publicKeys[$kid])) {
                throw new \Exception('Invalid token: Key not found');
            }

            // Get the public key
            $publicKey = openssl_pkey_get_public($publicKeys[$kid]);
            if ($publicKey === false) {
                throw new \Exception('Invalid public key');
            }

            // Verify the token
            $payload = JWT::decode($idToken, new Key($publicKeys[$kid], 'RS256'));
            
            // Additional Firebase specific validations
            $this->validatePayload($payload);

            return (array) $payload;
        } catch (ExpiredException $e) {
            throw new \Exception('Token has expired');
        } catch (SignatureInvalidException $e) {
            throw new \Exception('Invalid token signature');
        } catch (BeforeValidException $e) {
            throw new \Exception('Token not yet valid');
        } catch (\Exception $e) {
            throw new \Exception('Token verification failed: ' . $e->getMessage());
        }
    }

    /**
     * Get Firebase public keys with caching
     *
     * @return array
     */
    protected function getPublicKeys()
    {
        return Cache::remember($this->cacheKey, $this->cacheTtl, function () {
            $client = new Client();
            $response = $client->get($this->publicKeysUrl);
            
            if ($response->getStatusCode() !== 200) {
                throw new \Exception('Failed to fetch public keys');
            }

            $keys = json_decode((string) $response->getBody(), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to decode public keys');
            }

            return $keys;
        });
    }

    /**
     * Validate the JWT payload against Firebase's requirements
     *
     * @param object $payload
     * @throws \Exception
     */
    protected function validatePayload($payload)
    {
        $projectId = config('services.firebase.project_id');
        $now = time();

        // Check required claims
        $requiredClaims = ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time'];
        foreach ($requiredClaims as $claim) {
            if (!isset($payload->$claim)) {
                throw new \Exception("Missing required claim: {$claim}");
            }
        }

        // Validate audience
        if ($payload->aud !== $projectId) {
            throw new \Exception('Invalid token: Audience mismatch');
        }

        // Validate issuer
        $expectedIssuer = "https://securetoken.google.com/{$projectId}";
        if ($payload->iss !== $expectedIssuer) {
            throw new \Exception('Invalid token: Issuer mismatch');
        }

        // Check token expiration
        if ($payload->exp < $now) {
            throw new \Exception('Token has expired');
        }

        // Check issued at time
        if ($payload->iat > ($now + 300)) { // Allow 5 minute clock skew
            throw new \Exception('Token issued in the future');
        }

        // Check authentication time
        if (isset($payload->auth_time) && $payload->auth_time > $now) {
            throw new \Exception('Authentication time is in the future');
        }
    }

    /**
     * URL-safe base64 decode
     *
     * @param string $input
     * @return string
     */
    protected function urlSafeB64Decode($input)
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $padlen = 4 - $remainder;
            $input .= str_repeat('=', $padlen);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }
}
