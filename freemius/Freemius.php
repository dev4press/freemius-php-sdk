<?php

namespace Freemius\SDK;

use Composer\CaBundle\CaBundle;
use Freemius\SDK\Exception\EmptyArgumentException;
use Freemius\SDK\Exception\Exception as FreemiusException;
use Freemius\SDK\Exception\UnknownFileTypeException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\MultipartStream;
use Psr\Http\Message\StreamInterface;

/**
 * Copyright 2014 Freemius, Inc.
 *
 * Licensed under the GPL v2 (the "License"); you may
 * not use this file except in compliance with the License. You may obtain
 * a copy of the License at
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

if (!defined('FS_SDK__USER_AGENT'))
    define('FS_SDK__USER_AGENT', 'fs-php-' . Freemius::VERSION);

if (!defined('FS_API__PROTOCOL'))
    define('FS_API__PROTOCOL', 'https');

if (!defined('FS_API__ADDRESS'))
    define('FS_API__ADDRESS', FS_API__PROTOCOL . '://api.freemius.com');
if (!defined('FS_API__SANDBOX_ADDRESS'))
    define('FS_API__SANDBOX_ADDRESS', FS_API__PROTOCOL . '://sandbox-api.freemius.com');

class Freemius
{
    private static $INSTANCES = array();
    const VERSION = '2.0.0';
    const FORMAT = 'json';
    const SCOPE_DEVELOPER = 'developer';
    const SCOPE_STORE = 'store';
    const SCOPE_PRODUCT = 'plugin';
    const SCOPE_APP = 'app';
    const SCOPE_USER = 'user';
    const SCOPE_INSTALL = 'install';

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
     * Default options for Guzzle requests.
     *
     * @var array<string, mixed>
     */
    protected static array $HTTP_OPTIONS = array(
        'connect_timeout' => 10,
        'timeout' => 60,
        'http_errors' => false,
        'allow_redirects' => false,
        'expect' => false,
        'headers' => array(
            'User-Agent' => FS_SDK__USER_AGENT,
        ),
    );

    /**
     * @var int Clock diff in seconds between current server to API server.
     */
    private static int $_clock_diff = 0;

    /**
     * @var ClientInterface|null
     */
    protected ?ClientInterface $_http_client = null;

    /**
     * @param string $pScope 'app', 'developer', 'user' or 'install'.
     * @param number $pID Element's id.
     * @param string $pPublic Public key.
     * @param string|null $pSecret Element's secret key. If secret key not provided, user public key encryption.
     * @param bool $pSandbox Whether or not to run API in sandbox mode.
     */
    public function __construct(string $pScope, int $pID, string $pPublic, string $pSecret = null, bool $pSandbox = false)
    {
        $this->_id = $pID;
        $this->_public = $pPublic;
        $this->_secret = $pSecret ?? $pPublic;
        $this->_scope = $pScope;
        $this->_sandbox = $pSandbox;
    }

    public static function Instance(string $pScope, int $pID, string $pPublic = null, string $pSecret = null, bool $pSandbox = false): self
    {
        if (isset(self::$INSTANCES[$pScope][$pID])) {
            return self::$INSTANCES[$pScope][$pID];
        }

        if (is_null($pPublic)) {
            throw new EmptyArgumentException(array(
                'error' => array(
                    'message' => 'Public key is required and can\'t be empty or null.',
                    'type' => 'InvalidPublicKey',
                ),
            ));
        }

        if (is_null($pSecret) && $pScope !== self::SCOPE_USER) {
            throw new EmptyArgumentException(array(
                'error' => array(
                    'message' => 'Secret key is required and can\'t be empty or null.',
                    'type' => 'InvalidSecretKey',
                ),
            ));
        }

        if (!isset(self::$INSTANCES[$pScope][$pID])) {
            self::$INSTANCES[$pScope][$pID] = new self($pScope, $pID, $pPublic, $pSecret, $pSandbox);
        }

        return self::$INSTANCES[$pScope][$pID];
    }

    public static function Developer(int $pID, string $pPublic = null, string $pSecret = null, bool $pSandbox = false): self
    {
        return self::Instance(self::SCOPE_DEVELOPER, $pID, $pPublic, $pSecret, $pSandbox);
    }

    public static function Store(int $pID, string $pPublic = null, string $pSecret = null, bool $pSandbox = false): self
    {
        return self::Instance(self::SCOPE_STORE, $pID, $pPublic, $pSecret, $pSandbox);
    }

    public static function Product(int $pID, string $pPublic = null, string $pSecret = null, bool $pSandbox = false): self
    {
        return self::Instance(self::SCOPE_PRODUCT, $pID, $pPublic, $pSecret, $pSandbox);
    }

    public static function User(int $pID, string $pPublic = null, bool $pSandbox = false): self
    {
        return self::Instance(self::SCOPE_USER, $pID, $pPublic, null, $pSandbox);
    }

    public static function Install(int $pID, string $pPublic = null, string $pSecret = null, bool $pSandbox = false): self
    {
        return self::Instance(self::SCOPE_INSTALL, $pID, $pPublic, $pSecret, $pSandbox);
    }

    public static function App(int $pID, string $pPublic = null, string $pSecret = null, bool $pSandbox = false): self
    {
        return self::Instance(self::SCOPE_APP, $pID, $pPublic, $pSecret, $pSandbox);
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
            case self::SCOPE_APP:
                $base = '/apps/' . $this->_id;
                break;
            case self::SCOPE_DEVELOPER:
                $base = '/developers/' . $this->_id;
                break;
            case self::SCOPE_STORE:
                $base = '/stores/' . $this->_id;
                break;
            case self::SCOPE_USER:
                $base = '/users/' . $this->_id;
                break;
            case self::SCOPE_PRODUCT:
                $base = '/plugins/' . $this->_id;
                break;
            case self::SCOPE_INSTALL:
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
     * @param array $headers HTTP headers, passed by reference.
     * @param string $pJsonEncodedParams JSON-encoded request parameters.
     * @param string $pContentType Request content type.
     */
    private function SignRequest(
        string $pResourceUrl,
        string $pMethod,
        array  &$headers,
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

        $headers['Date'] = $auth['date'];
        $headers['Authorization'] = $auth['authorization'];

        if (!empty($auth['content_md5']))
            $headers['Content-MD5'] = $auth['content_md5'];
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
     * developers want to do fancier things or use something other than Guzzle
     * to make the request.
     *
     * @param string $pCanonizedPath The URL to make the request to.
     * @param string $pMethod HTTP method.
     * @param array $pParams The parameters to use for the POST body.
     * @param array $pFileParams File parameters.
     *
     * @return string The response body.
     * @throws FreemiusException
     */
    public function MakeRequest(
        string $pCanonizedPath,
        string $pMethod = 'GET',
        array  $pParams = array(),
        array  $pFileParams = array()
    ): string
    {
        $headers = self::$HTTP_OPTIONS['headers'];
        $content_type = 'application/json';
        $json_encoded_params = empty($pParams) ?
            '' :
            json_encode($pParams);
        $body = null;
        $overridden_method = $pMethod;

        if ('POST' === $pMethod || 'PUT' === $pMethod) {
            if (!empty($pFileParams)) {
                $boundary = ('----' . uniqid());
                $multipart_elements = array();

                if (!empty($json_encoded_params)) {
                    $multipart_elements[] = array(
                        'name' => 'data',
                        'contents' => $json_encoded_params,
                    );
                }

                foreach ($pFileParams as $name => $file_path) {
                    $file = fopen($file_path, 'rb');
                    if (false === $file)
                        throw new \RuntimeException('Unable to read upload file: ' . $file_path);

                    $multipart_elements[] = array(
                        'name' => $name,
                        'contents' => $file,
                        'filename' => basename($file_path),
                        'headers' => array(
                            'Content-Type' => $this->GetMimeContentType($file_path),
                        ),
                    );
                }

                $body = new MultipartStream($multipart_elements, $boundary);
                $content_type = "multipart/form-data; boundary={$boundary}";
                $json_encoded_params = '';

                if ('PUT' === $pMethod) {
                    $query = parse_url($pCanonizedPath, PHP_URL_QUERY);
                    $pCanonizedPath .= (is_string($query) ? '&' : '?') . 'method=PUT';

                    $overridden_method = $pMethod;
                    $pMethod = 'POST';
                }
            } else {
                $body = $json_encoded_params;
            }
        } else if (('GET' === $pMethod || 'DELETE' === $pMethod) && !empty($pParams)) {
            $pCanonizedPath = $this->AddQueryParams($pCanonizedPath, $pParams);
        }

        $headers['Content-Type'] = $content_type;

        $request_url = $this->GetUrl($pCanonizedPath);
        $resource = explode('?', $pCanonizedPath);
        $this->SignRequest($resource[0], $overridden_method, $headers, $json_encoded_params, $content_type);

        $options = self::$HTTP_OPTIONS;
        $options['headers'] = $headers;
        if (null !== $body)
            $options['body'] = $body;

        try {
            $response = $this->GetHttpClient()->request($pMethod, $request_url, $options);
        } catch (ConnectException $e) {
            $options['force_ip_resolve'] = 'v4';

            if (isset($options['body']) && $options['body'] instanceof StreamInterface && $options['body']->isSeekable())
                $options['body']->rewind();

            try {
                $response = $this->GetHttpClient()->request($pMethod, $request_url, $options);
            } catch (TransferException $retry_exception) {
                $e = $retry_exception;
            }
        } catch (TransferException $e) {
        }

        if (isset($response))
            return (string)$response->getBody();

        throw new FreemiusException(array(
            'error' => array(
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'type' => 'TransportException',
            ),
        ));
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

    /**
     * Create the internal HTTP client.
     *
     * Creates the configured Guzzle client without changing the public
     * constructor.
     *
     * @return ClientInterface
     */
    private function CreateHttpClient(): ClientInterface
    {
        return new Client(array(
            'verify' => CaBundle::getSystemCaRootBundlePath(),
        ));
    }

    /**
     * Get the lazily-created internal HTTP client.
     *
     * @return ClientInterface
     */
    private function GetHttpClient(): ClientInterface
    {
        if (null === $this->_http_client)
            $this->_http_client = $this->CreateHttpClient();

        return $this->_http_client;
    }
}
