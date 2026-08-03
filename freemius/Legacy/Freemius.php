<?php

namespace Freemius\SDK\Legacy;

use Composer\CaBundle\CaBundle;
use Freemius\SDK\Exceptions\Exception as FreemiusException;
use Freemius\SDK\Exceptions\UnknownFileTypeException;

/**
 * Copyright 2014 Freemius, Inc.
 *
 * Licensed under the GPL v2 (the "License"); you may
 * not use this file except in compliance with the License. You may obtain
 * a copy of the License at:
 *
 *     http://choosealicense.com/licenses/gpl-v2/
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations
 * under the License.
 */

if (!defined('FS_API__VERSION')) {
    define('FS_API__VERSION', '1');
}

if (!function_exists('json_decode'))
    throw new \Exception('Freemius needs the JSON PHP extension.');

if (!function_exists('curl_init'))
    throw new \Exception('Freemius needs the CURL PHP extension.');

if (!defined('FS_SDK__USER_AGENT'))
    define('FS_SDK__USER_AGENT', 'fs-php-' . Freemius::VERSION);

$curl_version = curl_version();

if (!defined('FS_API__PROTOCOL'))
    define('FS_API__PROTOCOL', version_compare($curl_version['version'], '7.37', '>=') ? 'https' : 'http');

if (!defined('FS_API__ADDRESS'))
    define('FS_API__ADDRESS', FS_API__PROTOCOL . '://api.freemius.com');
if (!defined('FS_API__SANDBOX_ADDRESS'))
    define('FS_API__SANDBOX_ADDRESS', FS_API__PROTOCOL . '://sandbox-api.freemius.com');

class Freemius
{
    const VERSION = '2.0.0';
    const FORMAT = 'json';

    /** @var int */
    protected int $_id;

    /** @var string */
    protected string $_public;

    /** @var string */
    protected string $_secret;

    /** @var string */
    protected string $_scope;

    /** @var bool */
    protected bool $_sandbox;

    /**
     * Default options for curl.
     *
     * @var array<int, mixed>
     */
    public static array $CURL_OPTS = array(
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => FS_SDK__USER_AGENT,
        CURLOPT_HTTPHEADER => array()
    );

    /**
     * @var int Clock diff in seconds between current server to API server.
     */
    private static int $_clock_diff = 0;

    /**
     * @param string $pScope 'app', 'developer', 'user' or 'install'.
     * @param number $pID Element's id.
     * @param string $pPublic Public key.
     * @param string $pSecret Element's secret key.
     * @param bool $pSandbox Whether or not to run API in sandbox mode.
     *
     * @return void
     */
    public function Init(string $pScope, int $pID, string $pPublic, string $pSecret, bool $pSandbox = false): void
    {
        $this->_id = $pID;
        $this->_public = $pPublic;
        $this->_secret = $pSecret;
        $this->_scope = $pScope;
        $this->_sandbox = $pSandbox;
    }

    /**
     * Determine whether the SDK is using the sandbox API endpoint.
     *
     * @return bool True when sandbox mode is enabled.
     */
    public function IsSandbox(): bool
    {
        return $this->_sandbox;
    }

    /**
     * Convert a relative API path to a canonical SDK API path.
     *
     * @param string $pPath Relative API path.
     *
     * @return string Canonical API path.
     * @throws FreemiusException When the configured scope is not supported.
     */
    public function CanonizePath(string $pPath): string
    {
        $pPath = trim($pPath, '/');
        $query_pos = strpos($pPath, '?');
        $query = '';

        if (false !== $query_pos) {
            $query = substr($pPath, $query_pos);
            $pPath = substr($pPath, 0, $query_pos);
        }

        // Trim '.json' suffix.
        $format_length = strlen('.' . self::FORMAT);
        $start = $format_length * (-1); //negative
        if (substr(strtolower($pPath), $start) === ('.' . self::FORMAT))
            $pPath = substr($pPath, 0, strlen($pPath) - $format_length);

        switch ($this->_scope) {
            case 'app':
                $base = '/apps/' . $this->_id;
                break;
            case 'developer':
                $base = '/developers/' . $this->_id;
                break;
            case 'store':
                $base = '/stores/' . $this->_id;
                break;
            case 'user':
                $base = '/users/' . $this->_id;
                break;
            case 'plugin':
                $base = '/plugins/' . $this->_id;
                break;
            case 'install':
                $base = '/installs/' . $this->_id;
                break;
            default:
                throw new FreemiusException(array(
                    'error' => array(
                        'message' => 'Scope not implemented.',
                        'type' => 'InvalidScope',
                    ),
                ));
        }

        return '/v' . FS_API__VERSION . $base .
            (!empty($pPath) ? '/' : '') . $pPath .
            ((false === strpos($pPath, '.')) ? '.' . self::FORMAT : '') . $query;
    }

    /**
     * Execute an API request and decode a JSON response when possible.
     *
     * @param string $pPath Canonical API path.
     * @param string $pMethod HTTP method.
     * @param array $pParams Request parameters.
     * @param array $pFileParams File parameters.
     *
     * @return string|object Decoded API response or the raw response body.
     */
    private function ApiRequest(string $pPath, string $pMethod = 'GET', array $pParams = array(), array $pFileParams = array())
    {
        $pMethod = strtoupper($pMethod);

        try {
            $result = $this->MakeRequest($pPath, $pMethod, $pParams, $pFileParams);
        } catch (FreemiusException $e) {
            // Map to error object.
            $result = json_encode($e->GetResult());
        } catch (\Exception $e) {
            // Map to error object.
            $result = json_encode(array(
                'error' => array(
                    'type' => 'Unknown',
                    'message' => $e->getMessage() . ' (' . $e->getFile() . ': ' . $e->getLine() . ')',
                    'code' => 'unknown',
                    'http' => 402
                )
            ));
        }

        $decoded = json_decode($result);

        return (null === $decoded) ? $result : $decoded;
    }

    /**
     * @return bool True if successful connectivity to the API endpoint using ping.json endpoint.
     */
    public function Test(): bool
    {
        $pong = $this->ApiRequest('/v' . FS_API__VERSION . '/ping.json');

        return (is_object($pong) && isset($pong->api) && 'pong' === $pong->api);
    }

    /**
     * Find clock diff between current server to API server.
     *
     * @return int Clock diff in seconds.
     * @since 1.0.2
     */
    public function FindClockDiff(): int
    {
        $time = time();
        $pong = $this->ApiRequest('/v' . FS_API__VERSION . '/ping.json');
        return ($time - strtotime($pong->timestamp));
    }

    /**
     * Add query parameters to the path.
     *
     * @param string $pPath Path.
     * @param array $pParams Query parameters.
     *
     * @return string Path with query parameters.
     */
    private function AddQueryParams(string $pPath, array $pParams): string
    {
        $query_string = http_build_query($pParams);

        return $pPath . (strpos($pPath, '?') === false ? '?' : '&') . $query_string;
    }

    /**
     * Make an API request using a path relative to the configured scope.
     *
     * @param string $pPath Relative API path.
     * @param string $pMethod HTTP method.
     * @param array $pParams Request parameters.
     * @param array $pFileParams File parameters.
     *
     * @return string|object Decoded API response or the raw response body.
     */
    public function Api(string $pPath, string $pMethod = 'GET', array $pParams = array(), array $pFileParams = array())
    {
        return $this->ApiRequest($this->CanonizePath($pPath), $pMethod, $pParams, $pFileParams);
    }

    /**
     * Base64 encoding that does not need to be urlencode()ed.
     * Exactly the same as base64_encode except it uses
     *   - instead of +
     *   _ instead of /
     *   No padded =
     *
     * @param string $input base64UrlEncoded string
     * @return string
     */
    private static function Base64UrlDecode(string $input): string
    {
        return base64_decode(strtr($input, '-_', '+/'));
    }

    /**
     * Base64 encoding that does not need to be urlencode()ed.
     * Exactly the same as base64_encode except it uses
     *   - instead of +
     *   _ instead of /
     *
     * @param string $input string
     * @return string base64Url encoded string
     */
    private static function Base64UrlEncode(string $input): string
    {
        $str = strtr(base64_encode($input), '+/', '-_');
        $str = str_replace('=', '', $str);

        return $str;
    }


    /**
     * @param string $pScope 'app', 'developer', 'user' or 'install'.
     * @param number $pID Element's id.
     * @param string $pPublic Public key.
     * @param string|bool $pSecret Element's secret key.
     * @param bool $pSandbox Whether or not to run API in sandbox mode.
     */
    public function __construct(string $pScope, int $pID, string $pPublic, $pSecret = false, bool $pSandbox = false)
    {
        // If secret key not provided, user public key encryption.
        if (is_bool($pSecret))
            $pSecret = $pPublic;

        $this->Init($pScope, $pID, $pPublic, $pSecret, $pSandbox);
    }

    /**
     * Build the full URL for a canonical API path.
     *
     * @param string $pCanonizedPath Canonical API path.
     *
     * @return string Full API URL.
     */
    public function GetUrl(string $pCanonizedPath = ''): string
    {
        return ($this->_sandbox ? FS_API__SANDBOX_ADDRESS : FS_API__ADDRESS) . $pCanonizedPath;
    }


    /**
     * Set clock diff for all API calls.
     *
     * @param int $pSeconds Clock difference in seconds.
     * @since 1.0.3
     */
    public static function SetClockDiff(int $pSeconds): void
    {
        self::$_clock_diff = $pSeconds;
    }

    /**
     * Sign request with the following HTTP headers:
     *      Content-MD5: MD5(HTTP Request body)
     *      Date: Current date (i.e Sat, 14 Feb 2015 20:24:46 +0000)
     *      Authorization: FS {scope_entity_id}:{scope_entity_public_key}:base64encode(sha256(string_to_sign, {scope_entity_secret_key}))
     *
     * @param string $pResourceUrl Resource URL to sign.
     * @param string $pMethod HTTP method.
     * @param array $opts cURL options, passed by reference.
     * @param string $pJsonEncodedParams JSON-encoded request parameters.
     * @param string $pContentType Request content type.
     */
    private function SignRequest(
        string $pResourceUrl,
        string $pMethod,
        array  &$opts,
        string $pJsonEncodedParams,
        string $pContentType
    ): void
    {
        $auth = $this->GenerateAuthorizationParams(
            $pResourceUrl,
            $pMethod,
            $pJsonEncodedParams,
            $pContentType
        );

        $opts[CURLOPT_HTTPHEADER][] = ('Date: ' . $auth['date']);

        // Add authorization header.
        $opts[CURLOPT_HTTPHEADER][] = ('Authorization: ' . $auth['authorization']);

        if (!empty($auth['content_md5']))
            $opts[CURLOPT_HTTPHEADER][] = ('Content-MD5: ' . $auth['content_md5']);
    }

    /**
     * @param string $pResourceUrl
     * @param string $pMethod
     * @param string $pJsonEncodedParams
     * @param string $pContentType
     *
     * @return array
     */
    private function GenerateAuthorizationParams(
        string $pResourceUrl,
        string $pMethod = 'GET',
        string $pJsonEncodedParams = '',
        string $pContentType = ''
    ): array
    {
        $pMethod = strtoupper($pMethod);

        $eol = "\n";
        $content_md5 = '';
        $now = (time() - self::$_clock_diff);
        $date = date('r', $now);

        if (in_array($pMethod, array('POST', 'PUT')) && !empty($pJsonEncodedParams))
            $content_md5 = md5($pJsonEncodedParams);

        $string_to_sign = implode($eol, array(
            $pMethod,
            $content_md5,
            $pContentType,
            $date,
            $pResourceUrl
        ));

        // If secret and public keys are identical, it means that
        // the signature uses public key hash encoding.
        $auth_type = ($this->_secret !== $this->_public) ? 'FS' : 'FSP';

        $auth = array(
            'date' => $date,
            'authorization' => $auth_type . ' ' . $this->_id . ':' .
                $this->_public . ':' .
                self::Base64UrlEncode(hash_hmac(
                    'sha256', $string_to_sign, $this->_secret
                ))
        );

        if (!empty($content_md5))
            $auth['content_md5'] = $content_md5;

        return $auth;
    }

    /**
     * Generate a signed URL for an API path.
     *
     * @param string $pPath Relative API path.
     *
     * @return string Signed API URL.
     */
    public function GetSignedUrl(string $pPath): string
    {
        $resource = explode('?', $this->CanonizePath($pPath));
        $pResourceUrl = $resource[0];

        $auth = $this->GenerateAuthorizationParams($pResourceUrl);

        return $this->GetUrl(
            $pResourceUrl . '?' .
            (1 < count($resource) && !empty($resource[1]) ? $resource[1] . '&' : '') .
            http_build_query(array(
                'auth_date' => $auth['date'],
                'authorization' => $auth['authorization']
            )));
    }

    /**
     * Makes an HTTP request. This method can be overridden by subclasses if
     * developers want to do fancier things or use something other than curl
     * to make the request.
     *
     * @param string $pCanonizedPath The URL to make the request to.
     * @param string $pMethod HTTP method.
     * @param array $pParams The parameters to use for the POST body.
     * @param array $pFileParams File parameters.
     * @param resource|\CurlHandle|null $ch Initialized cURL handle.
     *
     * @return string The response body.
     * @throws FreemiusException
     */
    public function MakeRequest(
        string $pCanonizedPath,
        string $pMethod = 'GET',
        array  $pParams = array(),
        array  $pFileParams = array(),
               $ch = null
    ): string
    {
        if (!$ch)
            $ch = curl_init();

        $opts = self::$CURL_OPTS;
        $opts[CURLOPT_CAINFO] = CaBundle::getSystemCaRootBundlePath();

        if (!is_array($opts[CURLOPT_HTTPHEADER]))
            $opts[CURLOPT_HTTPHEADER] = array();

        $content_type = 'application/json';
        $json_encoded_params = empty($pParams) ?
            '' :
            json_encode($pParams);

        $overidden_method = $pMethod;

        if ('POST' === $pMethod || 'PUT' === $pMethod) {
            if (!empty($pFileParams)) {
                $data = empty($json_encoded_params) ?
                    '' :
                    array('data' => $json_encoded_params);

                $json_encoded_params = '';

                $boundary = ('----' . uniqid());
                $post_fields = $this->GenerateMultipartBody($data, $pFileParams, $boundary);
                $content_type = "multipart/form-data; boundary={$boundary}";

                if ('PUT' === $pMethod) {
                    $query = parse_url($pCanonizedPath, PHP_URL_QUERY);
                    $pCanonizedPath .= (is_string($query) ? '&' : '?') . 'method=PUT';

                    $overidden_method = $pMethod;
                    $pMethod = 'POST';
                }
            } else {
                $post_fields = $json_encoded_params;
            }

            if (is_array($pParams) && 0 < count($pParams)) {
                $opts[CURLOPT_POST] = count($pParams);
                $opts[CURLOPT_POSTFIELDS] = $post_fields;
            }

            $opts[CURLOPT_RETURNTRANSFER] = true;
        } else if (('GET' === $pMethod || 'DELETE' === $pMethod) && !empty($pParams)) {
            $pCanonizedPath = $this->AddQueryParams($pCanonizedPath, $pParams);
        }

        $opts[CURLOPT_HTTPHEADER][] = "Content-Type: $content_type";

        $request_url = $this->GetUrl($pCanonizedPath);

        $opts[CURLOPT_URL] = $request_url;
        $opts[CURLOPT_CUSTOMREQUEST] = $pMethod;

        $resource = explode('?', $pCanonizedPath);
        $this->SignRequest($resource[0], $overidden_method, $opts, $json_encoded_params, $content_type);

        // disable the 'Expect: 100-continue' behaviour. This causes CURL to wait
        // for 2 seconds if the server does not support this header.
        $opts[CURLOPT_HTTPHEADER][] = 'Expect:';

        curl_setopt_array($ch, $opts);
        $result = curl_exec($ch);

        // With dual stacked DNS responses, it's possible for a server to
        // have IPv6 enabled but not have IPv6 connectivity.  If this is
        // the case, curl will try IPv4 first and if that fails, then it will
        // fall back to IPv6 and the error EHOSTUNREACH is returned by the
        // operating system.
        if (false === $result && empty($opts[CURLOPT_IPRESOLVE])) {
            $matches = array();
            $regex = '/Failed to connect to ([^:].*): Network is unreachable/';
            if (preg_match($regex, curl_error($ch), $matches)) {
                if (strlen(@inet_pton($matches[1])) === 16) {
                    self::errorLog('Invalid IPv6 configuration on server, Please disable or get native IPv6 on your server.');
                    self::$CURL_OPTS[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
                    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
                    $result = curl_exec($ch);
                }
            }
        }

        if ($result === false) {
            $e = new FreemiusException(array(
                'error' => array(
                    'code' => curl_errno($ch),
                    'message' => curl_error($ch),
                    'type' => 'CurlException',
                ),
            ));

            curl_close($ch);
            throw $e;
        }

        curl_close($ch);

        return $result;
    }

    /**
     * @param array $pParams
     * @param array $pFileParams
     * @param string $pBoundary
     *
     * @return string
     */
    private function GenerateMultipartBody(array $pParams, array $pFileParams, string $pBoundary): string
    {
        $body = '';

        if (!empty($pParams)) {
            foreach ($pParams as $name => $value) {
                $body = ('--' . $pBoundary . PHP_EOL) .
                    ("Content-Disposition: form-data; name=\"{$name}\"" . PHP_EOL) .
                    PHP_EOL .
                    ($value . PHP_EOL);
            }
        }

        foreach ($pFileParams as $name => $file_path) {
            $filename = basename($file_path);

            $body .=
                ('--' . $pBoundary . PHP_EOL) .
                ("Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"" . PHP_EOL) .
                ('Content-Type: ' . $this->GetMimeContentType($file_path) . PHP_EOL) .
                PHP_EOL .
                (file_get_contents($file_path) . PHP_EOL);
        }

        $body .= ('--' . $pBoundary . '--');

        return $body;
    }

    /**
     * @param string $pFilename
     *
     * @return string
     *
     * @throws UnknownFileTypeException
     */
    private function GetMimeContentType(string $pFilename): string
    {
        if (function_exists('mime_content_type'))
            return mime_content_type($pFilename);

        $mime_types = array(
            'zip' => 'application/zip',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
        );

        $ext = explode('.', $pFilename)[1];

        if (!isset($mime_types[$ext]))
            throw new UnknownFileTypeException(array(
                'error' => array(
                    'message' => 'Unknown file type',
                    'type' => 'UnknownFileType',
                ),
            ));

        return $mime_types[$ext];
    }
}