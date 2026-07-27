<?php

namespace Freemius\SDK;

use Composer\CaBundle\CaBundle;
use Freemius\SDK\Exception\Exception as FreemiusException;
use Freemius\SDK\Utilities\Validation;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;

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

class Product
{
    private static $INSTANCES = array();
    const VERSION = '2.0.0';
    /**
     * Default options for product-scope Guzzle requests.
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

    private int $_id;
    private ?string $_key;
    private bool $_sandbox;
    protected ?ClientInterface $_http_client = null;

    /**
     * Initialize a product scope.
     *
     * @param int $pID Product identifier.
     * @param string|null $pBearerToken Product bearer token.
     * @param bool $pSandbox Whether to use the sandbox API endpoint.
     */
    private function __construct(int $pID, ?string $pBearerToken = null, bool $pSandbox = false)
    {
        $this->_id = $pID;
        $this->_key = $pBearerToken;
        $this->_sandbox = $pSandbox;
    }

    /**
     * Get or create the singleton product scope.
     *
     * @param int $pID Product identifier.
     * @param string|null $pBearerToken Product bearer token.
     * @param bool $pSandbox Whether to use the sandbox API endpoint.
     *
     * @return self Product scope instance.
     */
    public static function Instance(int $pID, ?string $pBearerToken = null, bool $pSandbox = false): self
    {
        Validation::ValidateProductIdValue($pID);

        if (isset(self::$INSTANCES[$pID])) {
            return self::$INSTANCES[$pID];
        }

        Validation::ValidateBearerTokenValue($pBearerToken);

        if (!isset(self::$INSTANCES[$pID])) {
            self::$INSTANCES[$pID] = new self($pID, $pBearerToken, $pSandbox);
        }

        return self::$INSTANCES[$pID];
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
     * Build a product-scoped API path using the product ID stored by this instance.
     *
     * Endpoint methods pass only the path below the product resource. This keeps
     * callers from replacing the product ID in a request path.
     *
     * @param string $pPath Product endpoint path.
     *
     * @return string Product-scoped API path.
     */
    protected function BuildProductPath(string $pPath): string
    {
        $path = ltrim($pPath, '/');

        return '/v' . FS_API__VERSION . '/products/' . $this->_id .
            ('' === $path ? '/' : ('.' === $path[0] ? '' : '/') . $path);
    }

    /**
     * Make an authenticated product-scope request.
     *
     * @param string $pPath Product-relative endpoint path.
     * @param string $pMethod HTTP method.
     * @param array<string, mixed> $pQuery Query parameters.
     * @param array<string, mixed> $pBody JSON request body.
     * @param string $pResponseType `json` or `binary`.
     *
     * @return mixed Decoded JSON or raw response bytes.
     * @throws FreemiusException For API and transport failures.
     */
    protected function Request(
        string $pPath,
        string $pMethod = 'GET',
        array  $pQuery = array(),
        array  $pBody = array(),
        string $pResponseType = 'json'
    )
    {
        $method = strtoupper($pMethod);
        if (!in_array($method, array('GET', 'POST', 'PUT', 'DELETE'), true)) {
            Validation::ThrowInvalidArgument('method', 'Only GET, POST, PUT, and DELETE requests are supported.');
        }

        if (!in_array($pResponseType, array('json', 'binary'), true)) {
            Validation::ThrowInvalidArgument('response_type', 'The response type must be json or binary.');
        }

        Validation::ValidateQuery($pQuery);
        $path = $this->BuildProductPath($pPath);
        if (!empty($pQuery)) {
            $path .= (false === strpos($path, '?') ? '?' : '&') . http_build_query($pQuery);
        }

        $headers = self::$HTTP_OPTIONS['headers'];
        $headers['Authorization'] = 'Bearer ' . $this->_key;
        $headers['Accept'] = ('binary' === $pResponseType ? '*/*' : 'application/json');
        $headers['Content-Type'] = 'application/json';

        $options = self::$HTTP_OPTIONS;
        $options['verify'] = CaBundle::getSystemCaRootBundlePath();
        $options['headers'] = $headers;

        if ('POST' === $method || 'PUT' === $method) {
            $encoded_body = json_encode($pBody);
            if (false === $encoded_body) {
                Validation::ThrowInvalidArgument('body', 'The request body could not be JSON encoded.');
            }
            $options['body'] = $encoded_body;
        }

        try {
            $response = $this->GetHttpClient()->request($method, $this->GetUrl($path), $options);
        } catch (TransferException $exception) {
            throw new FreemiusException(array(
                'error' => array(
                    'code' => $exception->getCode(),
                    'message' => $exception->getMessage(),
                    'type' => 'TransportException',
                ),
            ));
        }

        $status = $response->getStatusCode();
        $content = (string)$response->getBody();
        if ($status < 200 || $status >= 300) {
            throw new FreemiusException($this->DecodeErrorResult($content, $status));
        }

        return ('binary' === $pResponseType ? $content : json_decode($content));
    }

    /**
     * Create the internal HTTP client.
     *
     * @return ClientInterface
     */
    protected function CreateHttpClient(): ClientInterface
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
    protected function GetHttpClient(): ClientInterface
    {
        if (null === $this->_http_client) {
            $this->_http_client = $this->CreateHttpClient();
        }

        return $this->_http_client;
    }

    /**
     * Convert a non-JSON API response into an SDK error result.
     *
     * @param string $pContent Response content.
     * @param int $pStatus HTTP status code.
     *
     * @return array<string, mixed> Structured error result.
     */
    private function DecodeErrorResult(string $pContent, int $pStatus): array
    {
        $result = json_decode($pContent, true);
        if (is_array($result)) {
            return $result;
        }

        return array(
            'error' => array(
                'code' => $pStatus,
                'message' => '' === $pContent ? 'The API returned an empty error response.' : $pContent,
                'type' => 'ApiException',
            ),
        );
    }

    /**
     * List product addons.
     *
     * @param array<string, mixed> $pQuery Optional list filters and pagination values.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/addons.json (products/list-addons)
     */
    public function ListAddons(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('count', 'offset', 'fields', 'show_pending', 'enriched'));
        foreach (array('show_pending', 'enriched') as $name) {
            if (array_key_exists($name, $pQuery)) {
                Validation::ValidateBoolean($pQuery[$name], $name);
            }
        }

        return $this->Request('addons.json', 'GET', $pQuery);
    }

    /**
     * List product email addresses.
     *
     * @param array<string, mixed> $pQuery Optional response field selection.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/emails/addresses.json (products/list-email-addresses)
     */
    public function ListEmailAddresses(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('emails/addresses.json', 'GET', $pQuery);
    }

    /**
     * Delete product email addresses.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: DELETE /v1/products/{product_id}/emails/addresses.json (products/delete-email-addresses)
     */
    public function DeleteEmailAddresses()
    {
        return $this->Request('emails/addresses.json', 'DELETE');
    }

    /**
     * Retrieve a product event.
     *
     * @param int $pEventId Event identifier.
     * @param array<string, mixed> $pQuery Optional response field selection.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/events/{event_id}.json (events/retrieve)
     */
    public function RetrieveEvent(int $pEventId, array $pQuery = array())
    {
        $eventId = Validation::ValidatePathId($pEventId, 'event_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('events/' . $eventId . '.json', 'GET', $pQuery);
    }

    /**
     * List product events.
     *
     * @param array<string, mixed> $pQuery Event filters and pagination values.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/events.json (events/list)
     */
    public function ListEvents(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('type', 'state', 'count', 'offset', 'fields'));
        if (array_key_exists('type', $pQuery)) {
            Validation::ValidateNonEmptyString($pQuery['type'], 'type');
        }
        if (array_key_exists('state', $pQuery)) {
            Validation::ValidateEnum($pQuery['state'], array('processed', 'pending', 'canceled', 'error'), 'state');
        }

        return $this->Request('events.json', 'GET', $pQuery);
    }

    /**
     * Retrieve a product feature.
     *
     * @param int $pFeatureId Feature identifier.
     * @param array<string, mixed> $pQuery Optional response field selection.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/features/{feature_id}.json (products/retrieve-feature)
     */
    public function RetrieveFeature(int $pFeatureId, array $pQuery = array())
    {
        $featureId = Validation::ValidatePathId($pFeatureId, 'feature_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('features/' . $featureId . '.json', 'GET', $pQuery);
    }

    /**
     * Update a product feature.
     *
     * @param int $pFeatureId Feature identifier.
     * @param array<string, mixed> $pData Feature fields to update.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: PUT /v1/products/{product_id}/features/{feature_id}.json (products/update-feature)
     */
    public function UpdateFeature(int $pFeatureId, array $pData)
    {
        $featureId = Validation::ValidatePathId($pFeatureId, 'feature_id');
        Validation::ValidateRequestBody(
            $pData,
            array(),
            array(
                'title' => 'string',
                'description' => 'string',
                'is_featured' => 'bool',
            )
        );

        return $this->Request('features/' . $featureId . '.json', 'PUT', array(), $pData);
    }

    /**
     * Delete a product feature.
     *
     * @param int $pFeatureId Feature identifier.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: DELETE /v1/products/{product_id}/features/{feature_id}.json (products/delete-feature)
     */
    public function DeleteFeature(int $pFeatureId)
    {
        $featureId = Validation::ValidatePathId($pFeatureId, 'feature_id');

        return $this->Request('features/' . $featureId . '.json', 'DELETE');
    }

    /**
     * List product features.
     *
     * @param array<string, mixed> $pQuery Optional list filters and pagination values.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/features.json (products/list-features)
     */
    public function ListFeatures(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('count', 'offset', 'fields'));

        return $this->Request('features.json', 'GET', $pQuery);
    }

    /**
     * Retrieve a product.
     *
     * @param array<string, mixed> $pQuery Optional response field selection.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}.json (products/retrieve)
     */
    public function RetrieveProduct(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('.json', 'GET', $pQuery);
    }

    /**
     * Update a product.
     *
     * @param array<string, mixed> $pData Product fields to update.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: PUT /v1/products/{product_id}.json (products/update)
     */
    public function UpdateProduct(array $pData)
    {
        Validation::ValidateRequestBody(
            $pData,
            array(),
            array(
                'slug' => 'string',
                'title' => 'string',
                'type' => 'string',
                'plans' => 'array',
                'features' => 'array',
                'money_back_period' => 'int',
                'refund_policy' => 'string',
                'annual_renewals_discount' => 'number',
                'renewals_discount_type' => 'string',
                'lifetime_license_proration_days' => 'int',
                'is_pricing_visible' => 'bool',
                'default_plan_id' => 'int',
                'accepted_payments' => 'int',
                'expose_license_key' => 'bool',
                'enable_after_purchase_email_login_link' => 'bool',
            )
        );
        if (array_key_exists('accepted_payments', $pData)) {
            Validation::ValidateEnum($pData['accepted_payments'], array(0, 1), 'accepted_payments');
        }

        return $this->Request('.json', 'PUT', array(), $pData);
    }

    /**
     * Get product information.
     *
     * @param array<string, mixed> $pQuery Optional response field selection.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/info.json (products/get-info)
     */
    public function GetProductInfo(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('info.json', 'GET', $pQuery);
    }

    /**
     * Check product status.
     *
     * @param array<string, mixed> $pQuery Optional status query values.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/is_active.json (products/check-status)
     */
    public function CheckProductStatus(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('is_update'));
        if (array_key_exists('is_update', $pQuery)) {
            Validation::ValidateBoolean($pQuery['is_update'], 'is_update');
        }

        return $this->Request('is_active.json', 'GET', $pQuery);
    }

    /**
     * Check GDPR compliance for a product.
     *
     * @param array<string, mixed> $pQuery Required UID and optional compliance flags.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/ping.json (products/gdpr-compliance-check)
     */
    public function GdprComplianceCheck(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('uid', 'is_update', 'is_gdpr_test'));
        if (!array_key_exists('uid', $pQuery) || null === $pQuery['uid']) {
            Validation::ThrowEmptyArgument('uid', 'A required query parameter is missing.');
        }
        Validation::ValidateUid($pQuery['uid']);
        foreach (array('is_update', 'is_gdpr_test') as $name) {
            if (array_key_exists($name, $pQuery)) {
                Validation::ValidateBoolean($pQuery[$name], $name);
            }
        }

        return $this->Request('ping.json', 'GET', $pQuery);
    }

    /**
     * Generate a customer portal login link.
     *
     * @param array<string, mixed> $pData Portal login identifier or email data.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: POST /v1/products/{product_id}/portal/login.json (products/generate-portal-login-link)
     */
    public function GeneratePortalLoginLink(array $pData)
    {
        Validation::ValidateRequestBody($pData, array(), array('id' => 'string', 'email' => 'string'));
        if (!array_key_exists('id', $pData) && !array_key_exists('email', $pData)) {
            Validation::ThrowEmptyArgument('id', 'Either id or email is required.');
        }
        foreach (array('id', 'email') as $name) {
            if (array_key_exists($name, $pData)) {
                Validation::ValidateNonEmptyString($pData[$name], $name);
            }
        }
        if (array_key_exists('email', $pData) && false === filter_var($pData['email'], FILTER_VALIDATE_EMAIL)) {
            Validation::ThrowInvalidArgument('email', 'A valid email address is required.');
        }

        return $this->Request('portal/login.json', 'POST', array(), $pData);
    }

    /**
     * Retrieve product pricing table data.
     *
     * @param array<string, mixed> $pQuery Pricing table filters and bundle values.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/pricing.json (products/retrieve-pricing-table-data)
     */
    public function RetrievePricingTableData(array $pQuery = array())
    {
        Validation::ValidateQuery(
            $pQuery,
            array(
                'currency',
                'show_pending',
                'type',
                'is_enriched',
                'bundle_product_id',
                'bundle_product_public_key',
            )
        );
        if (array_key_exists('currency', $pQuery)) {
            Validation::ValidateNonEmptyString($pQuery['currency'], 'currency');
        }
        if (array_key_exists('type', $pQuery)) {
            Validation::ValidateEnum($pQuery['type'], array('all', 'visible'), 'type');
        }
        foreach (array('show_pending', 'is_enriched') as $name) {
            if (array_key_exists($name, $pQuery)) {
                Validation::ValidateBoolean($pQuery[$name], $name);
            }
        }
        foreach (array('bundle_product_id', 'bundle_product_public_key') as $name) {
            if (array_key_exists($name, $pQuery)) {
                Validation::ValidateNonEmptyString($pQuery[$name], $name);
            }
        }

        return $this->Request('pricing.json', 'GET', $pQuery);
    }

    /**
     * Retrieve a product setting.
     *
     * @param int $pSettingId Setting identifier.
     * @param array<string, mixed> $pQuery Optional response field selection.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/settings/{setting_id}.json (products/retrieve-setting)
     */
    public function RetrieveSetting(int $pSettingId, array $pQuery = array())
    {
        $settingId = Validation::ValidatePathId($pSettingId, 'setting_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('settings/' . $settingId . '.json', 'GET', $pQuery);
    }

    /**
     * Update a product setting.
     *
     * @param int $pSettingId Setting identifier.
     * @param array<string, mixed> $pData Setting fields to update.
     * @param array<string, mixed> $pQuery Optional response field selection.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: PUT /v1/products/{product_id}/settings/{setting_id}.json (products/update-setting)
     */
    public function UpdateSetting(int $pSettingId, array $pData, array $pQuery = array())
    {
        $settingId = Validation::ValidatePathId($pSettingId, 'setting_id');
        Validation::ValidateQuery($pQuery, array('fields'));
        Validation::ValidateRequestBody($pData, array(), array('data' => 'string'));

        return $this->Request('settings/' . $settingId . '.json', 'PUT', $pQuery, $pData);
    }

    /**
     * Delete a product setting.
     *
     * @param int $pSettingId Setting identifier.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: DELETE /v1/products/{product_id}/settings/{setting_id}.json (products/delete-setting)
     */
    public function DeleteSetting(int $pSettingId)
    {
        $settingId = Validation::ValidatePathId($pSettingId, 'setting_id');

        return $this->Request('settings/' . $settingId . '.json', 'DELETE');
    }

    /**
     * Skip product account connection.
     *
     * @param array<string, mixed> $pData UID or UID-list data.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: PUT /v1/products/{product_id}/skip.json (products/skip-account-connection)
     */
    public function SkipAccountConnection(array $pData)
    {
        Validation::ValidateRequestBody($pData, array(), array('uids' => 'array', 'uid' => 'string'));
        foreach (array('uid') as $name) {
            if (array_key_exists($name, $pData)) {
                Validation::ValidateUid($pData[$name], $name);
            }
        }
        if (array_key_exists('uids', $pData)) {
            foreach ($pData['uids'] as $uid) {
                Validation::ValidateUid($uid, 'uids');
            }
        }

        return $this->Request('skip.json', 'PUT', array(), $pData);
    }

    /**
     * List plans for a product addon.
     *
     * @param int $pAddonId Addon identifier.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/addons/{addon_id}/plans.json (addons/list-plans)
     */
    public function ListAddonPlans(int $pAddonId)
    {
        $addonId = Validation::ValidatePathId($pAddonId, 'addon_id');

        return $this->Request('addons/' . $addonId . '/plans.json', 'GET');
    }

    /**
     * List features for an addon plan.
     *
     * @param int $pAddonId Addon identifier.
     * @param int $pPlanId Plan identifier.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/addons/{addon_id}/plans/{plan_id}/features.json (addons/list-plans-features)
     */
    public function ListAddonPlanFeatures(int $pAddonId, int $pPlanId)
    {
        $addonId = Validation::ValidatePathId($pAddonId, 'addon_id');
        $planId = Validation::ValidatePathId($pPlanId, 'plan_id');

        return $this->Request('addons/' . $addonId . '/plans/' . $planId . '/features.json', 'GET');
    }

    /**
     * List pricing entries for an addon plan.
     *
     * @param int $pAddonId Addon identifier.
     * @param int $pPlanId Plan identifier.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/addons/{addon_id}/plans/{plan_id}/pricing.json (addons/list-pricings)
     */
    public function ListAddonPlanPricings(int $pAddonId, int $pPlanId)
    {
        $addonId = Validation::ValidatePathId($pAddonId, 'addon_id');
        $planId = Validation::ValidatePathId($pPlanId, 'plan_id');

        return $this->Request('addons/' . $addonId . '/plans/' . $planId . '/pricing.json', 'GET');
    }

    /**
     * List product carts.
     *
     * @param array<string, mixed> $pQuery Cart filters and pagination values.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/carts.json (carts/list)
     */
    public function ListCarts(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('filter', 'offset', 'fields', 'enriched', 'email', 'count'));
        if (array_key_exists('filter', $pQuery)) {
            Validation::ValidateEnum(
                $pQuery['filter'],
                array('all', 'abandoned', 'completed', 'recovered', 'recovery', 'active'),
                'filter'
            );
        }
        foreach (array('enriched') as $name) {
            if (array_key_exists($name, $pQuery)) {
                Validation::ValidateBoolean($pQuery[$name], $name);
            }
        }
        if (array_key_exists('email', $pQuery)) {
            Validation::ValidateNonEmptyString($pQuery['email'], 'email');
        }

        return $this->Request('carts.json', 'GET', $pQuery);
    }

    /**
     * Retrieve a product cart.
     *
     * @param int $pCartId Cart identifier.
     * @param array<string, mixed> $pQuery Optional response fields and enrichment flag.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/carts/{cart_id}.json (carts/retrieve)
     */
    public function RetrieveCart(int $pCartId, array $pQuery = array())
    {
        $cartId = Validation::ValidatePathId($pCartId, 'cart_id');
        Validation::ValidateQuery($pQuery, array('fields', 'enriched'));
        if (array_key_exists('enriched', $pQuery)) {
            Validation::ValidateBoolean($pQuery['enriched'], 'enriched');
        }

        return $this->Request('carts/' . $cartId . '.json', 'GET', $pQuery);
    }

    /**
     * Update a product cart.
     *
     * @param int $pCartId Cart identifier.
     * @param array<string, mixed> $pData Cart fields to update.
     * @param array<string, mixed> $pQuery Optional response field selection.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: PUT /v1/products/{product_id}/carts/{cart_id}.json (carts/update)
     */
    public function UpdateCart(int $pCartId, array $pData, array $pQuery = array())
    {
        $cartId = Validation::ValidatePathId($pCartId, 'cart_id');
        Validation::ValidateQuery($pQuery, array('fields'));
        Validation::ValidateRequestBody(
            $pData,
            array(),
            array(
                'plan_id' => 'int',
                'pricing_id' => 'int',
                'payment_method' => 'string',
                'billing_cycle' => 'int',
                'coupon_id' => 'int',
                'coupon_code' => 'string',
                'country_code' => 'string',
                'vat_id' => 'string',
                'email' => 'string',
                'first' => 'string',
                'last' => 'string',
                'ip' => 'string',
            )
        );

        return $this->Request('carts/' . $cartId . '.json', 'PUT', $pQuery, $pData);
    }

    /**
     * Delete a product cart.
     *
     * @param int $pCartId Cart identifier.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: DELETE /v1/products/{product_id}/carts/{cart_id}.json (carts/delete)
     */
    public function DeleteCart(int $pCartId)
    {
        $cartId = Validation::ValidatePathId($pCartId, 'cart_id');

        return $this->Request('carts/' . $cartId . '.json', 'DELETE');
    }

    /**
     * Retrieve events for a product cart.
     *
     * @param int $pCartId Cart identifier.
     * @param array<string, mixed> $pQuery Event pagination values.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/carts/{cart_id}/events.json (carts/retrieve-events)
     */
    public function RetrieveCartEvents(int $pCartId, array $pQuery = array())
    {
        $cartId = Validation::ValidatePathId($pCartId, 'cart_id');
        Validation::ValidateQuery($pQuery, array('offset', 'count'));

        return $this->Request('carts/' . $cartId . '/events.json', 'GET', $pQuery);
    }

    /**
     * List product coupons.
     *
     * @param array<string, mixed> $pQuery Coupon filters and pagination values.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/coupons.json (coupons/list)
     */
    public function ListCoupons(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('code', 'is_enriched', 'count', 'offset', 'prefix', 'search'));
        foreach (array('code', 'prefix', 'search') as $name) {
            if (array_key_exists($name, $pQuery)) {
                Validation::ValidateNonEmptyString($pQuery[$name], $name);
            }
        }
        if (array_key_exists('is_enriched', $pQuery)) {
            Validation::ValidateBoolean($pQuery['is_enriched'], 'is_enriched');
        }

        return $this->Request('coupons.json', 'GET', $pQuery);
    }

    /**
     * Create a product coupon.
     *
     * @param array<string, mixed> $pData Coupon fields to create.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: POST /v1/products/{product_id}/coupons.json (coupons/create)
     */
    public function CreateCoupon(array $pData)
    {
        Validation::ValidateCouponData($pData);

        return $this->Request('coupons.json', 'POST', array(), $pData);
    }

    /**
     * Retrieve a product coupon.
     *
     * @param int $pCouponId Coupon identifier.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/coupons/{coupon_id}.json (coupons/retrieve)
     */
    public function RetrieveCoupon(int $pCouponId)
    {
        $couponId = Validation::ValidatePathId($pCouponId, 'coupon_id');

        return $this->Request('coupons/' . $couponId . '.json', 'GET');
    }

    /**
     * Update a product coupon.
     *
     * @param int $pCouponId Coupon identifier.
     * @param array<string, mixed> $pData Coupon fields to update.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: PUT /v1/products/{product_id}/coupons/{coupon_id}.json (coupons/update)
     */
    public function UpdateCoupon(int $pCouponId, array $pData)
    {
        $couponId = Validation::ValidatePathId($pCouponId, 'coupon_id');
        Validation::ValidateCouponData($pData);

        return $this->Request('coupons/' . $couponId . '.json', 'PUT', array(), $pData);
    }

    /**
     * Delete a product coupon.
     *
     * @param int $pCouponId Coupon identifier.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: DELETE /v1/products/{product_id}/coupons/{coupon_id}.json (coupons/delete)
     */
    public function DeleteCoupon(int $pCouponId)
    {
        $couponId = Validation::ValidatePathId($pCouponId, 'coupon_id');

        return $this->Request('coupons/' . $couponId . '.json', 'DELETE');
    }

    /**
     * Retrieve a coupon note.
     *
     * @param int $pCouponId Coupon identifier.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/coupons/{coupon_id}/note.json (coupons/retrieve-note)
     */
    public function RetrieveCouponNote(int $pCouponId)
    {
        $couponId = Validation::ValidatePathId($pCouponId, 'coupon_id');

        return $this->Request('coupons/' . $couponId . '/note.json', 'GET');
    }

    /**
     * Update a coupon note.
     *
     * @param int $pCouponId Coupon identifier.
     * @param array<string, mixed> $pData Note fields to update.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: PUT /v1/products/{product_id}/coupons/{coupon_id}/note.json (coupons/update-note)
     */
    public function UpdateCouponNote(int $pCouponId, array $pData)
    {
        $couponId = Validation::ValidatePathId($pCouponId, 'coupon_id');
        Validation::ValidateCouponNoteData($pData);

        return $this->Request('coupons/' . $couponId . '/note.json', 'PUT', array(), $pData);
    }

    /**
     * Create a coupon note.
     *
     * @param int $pCouponId Coupon identifier.
     * @param array<string, mixed> $pData Note fields to create.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: POST /v1/products/{product_id}/coupons/{coupon_id}/note.json (coupons/create-note)
     */
    public function CreateCouponNote(int $pCouponId, array $pData)
    {
        $couponId = Validation::ValidatePathId($pCouponId, 'coupon_id');
        Validation::ValidateCouponNoteData($pData);

        return $this->Request('coupons/' . $couponId . '/note.json', 'POST', array(), $pData);
    }

    /**
     * Delete a coupon note.
     *
     * @param int $pCouponId Coupon identifier.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: DELETE /v1/products/{product_id}/coupons/{coupon_id}/note.json (coupons/delete-note)
     */
    public function DeleteCouponNote(int $pCouponId)
    {
        $couponId = Validation::ValidatePathId($pCouponId, 'coupon_id');

        return $this->Request('coupons/' . $couponId . '/note.json', 'DELETE');
    }

    /**
     * Retrieve special coupons.
     *
     * @param array<string, mixed> $pQuery Optional list filters and pagination values.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/coupons/special.json (coupons/retrieve-special)
     */
    public function RetrieveSpecialCoupons(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('count', 'offset', 'fields'));

        return $this->Request('coupons/special.json', 'GET', $pQuery);
    }

    /**
     * Create a special coupon entry.
     *
     * @param int $pCouponId Coupon identifier.
     * @param int $pSpecialId Special coupon identifier.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: PUT /v1/products/{product_id}/coupons/{coupon_id}/special/{special_id}.json (coupons/create-special)
     */
    public function CreateSpecialCoupon(int $pCouponId, int $pSpecialId)
    {
        $couponId = Validation::ValidatePathId($pCouponId, 'coupon_id');
        $specialId = Validation::ValidatePathId($pSpecialId, 'special_id');

        return $this->Request('coupons/' . $couponId . '/special/' . $specialId . '.json', 'PUT');
    }

    /**
     * Delete a special coupon entry.
     *
     * @param int $pCouponId Coupon identifier.
     * @param int $pSpecialId Special coupon identifier.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: DELETE /v1/products/{product_id}/coupons/{coupon_id}/special/{special_id}.json (coupons/delete-special)
     */
    public function DeleteSpecialCoupon(int $pCouponId, int $pSpecialId)
    {
        $couponId = Validation::ValidatePathId($pCouponId, 'coupon_id');
        $specialId = Validation::ValidatePathId($pSpecialId, 'special_id');

        return $this->Request('coupons/' . $couponId . '/special/' . $specialId . '.json', 'DELETE');
    }

    /**
     * List product deployments.
     *
     * @param array<string, mixed> $pQuery Optional list filters and pagination values.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/deployments.json (deployments/list)
     */
    public function ListDeployments(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('count', 'offset', 'fields'));

        return $this->Request('deployments.json', 'GET', $pQuery);
    }

    /**
     * Retrieve a product deployment.
     *
     * @param int $pDeploymentId Deployment identifier.
     * @param array<string, mixed> $pQuery Optional response field selection.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/deployments/{deployment_id}.json (deployments/retrieve)
     */
    public function RetrieveDeployment(int $pDeploymentId, array $pQuery = array())
    {
        $deploymentId = Validation::ValidatePathId($pDeploymentId, 'deployment_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('deployments/' . $deploymentId . '.json', 'GET', $pQuery);
    }

    /**
     * Update a product deployment.
     *
     * @param int $pDeploymentId Deployment identifier.
     * @param array<string, mixed> $pData Deployment fields to update.
     * @param array<string, mixed> $pQuery Optional response field selection.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: PUT /v1/products/{product_id}/deployments/{deployment_id}.json (deployments/update)
     */
    public function UpdateDeployment(int $pDeploymentId, array $pData, array $pQuery = array())
    {
        $deploymentId = Validation::ValidatePathId($pDeploymentId, 'deployment_id');
        Validation::ValidateQuery($pQuery, array('fields'));
        Validation::ValidateRequestBody(
            $pData,
            array(),
            array(
                'version' => 'string',
                'slug' => 'string',
                'title' => 'string',
                'changelog' => 'string',
                'release' => 'string',
                'download_url' => 'string',
                'is_active' => 'bool',
                'is_premium' => 'bool',
            )
        );

        return $this->Request('deployments/' . $deploymentId . '.json', 'PUT', $pQuery, $pData);
    }

    /**
     * Delete a product deployment.
     *
     * @param int $pDeploymentId Deployment identifier.
     *
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: DELETE /v1/products/{product_id}/deployments/{deployment_id}.json (deployments/delete)
     */
    public function DeleteDeployment(int $pDeploymentId)
    {
        $deploymentId = Validation::ValidatePathId($pDeploymentId, 'deployment_id');

        return $this->Request('deployments/' . $deploymentId . '.json', 'DELETE');
    }

    /**
     * Download a product deployment archive.
     *
     * @param int $pDeploymentId Deployment identifier.
     * @param array<string, mixed> $pQuery Optional archive filters.
     *
     * @return string Raw ZIP archive bytes.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/deployments/{deployment_id}.zip (deployments/download)
     */
    public function DownloadDeployment(int $pDeploymentId, array $pQuery = array())
    {
        $deploymentId = Validation::ValidatePathId($pDeploymentId, 'deployment_id');
        Validation::ValidateDeploymentDownloadQuery($pQuery);

        return $this->Request('deployments/' . $deploymentId . '.zip', 'GET', $pQuery, array(), 'binary');
    }

    /**
     * Download the latest product deployment archive.
     *
     * @param array<string, mixed> $pQuery Optional archive filters.
     *
     * @return string Raw ZIP archive bytes.
     * @throws FreemiusException If the API or transport returns an error.
     *
     * OpenAPI: GET /v1/products/{product_id}/deployments/latest.zip (deployments/download-latest)
     */
    public function DownloadLatestDeployment(array $pQuery = array())
    {
        Validation::ValidateDeploymentDownloadQuery($pQuery);

        return $this->Request('deployments/latest.zip', 'GET', $pQuery, array(), 'binary');
    }

    /**
     * List an installation add-on plan's features.
     * @param int $pInstallId Installation identifier.
     * @param int $pAddonId Add-on identifier.
     * @param int $pPlanId Plan identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/installs/{install_id}/addons/{addon_id}/plans/{plan_id}/features.json (installations/list-plans-features-for-addon)
     */
    public function ListPlansFeaturesForAddon(int $pInstallId, int $pAddonId, int $pPlanId, array $pQuery = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        $addonId = Validation::ValidatePathId($pAddonId, 'addon_id');
        $planId = Validation::ValidatePathId($pPlanId, 'plan_id');
        Validation::ValidateQuery($pQuery, array('count', 'offset', 'fields'));

        return $this->Request('installs/' . $installId . '/addons/' . $addonId . '/plans/' . $planId . '/features.json', 'GET', $pQuery);
    }

    /**
     * Resolve an installation clone.
     * @param int $pInstallId Installation identifier.
     * @param int $pCloneId Clone identifier.
     * @param array<string, mixed> $pData Clone resolution data.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: PUT /v1/products/{product_id}/installs/{install_id}/clones/{clone_id}.json (installations/resolve-clone)
     */
    public function ResolveClone(int $pInstallId, int $pCloneId, array $pData, array $pQuery = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        $cloneId = Validation::ValidatePathId($pCloneId, 'clone_id');
        Validation::ValidateQuery($pQuery, array('fields'));
        Validation::ValidateRequestBody($pData, array('resolution'), array('resolution' => 'string', 'new_install_id' => 'int'));
        Validation::ValidateEnum($pData['resolution'], array('keep', 'delete'), 'resolution');

        return $this->Request('installs/' . $installId . '/clones/' . $cloneId . '.json', 'PUT', $pQuery, $pData);
    }

    /**
     * Create an installation clone.
     * @param int $pInstallId Installation identifier.
     * @param array<string, mixed> $pData Clone data.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: POST /v1/products/{product_id}/installs/{install_id}/clones.json (installations/create-clone)
     */
    public function CreateClone(int $pInstallId, array $pData, array $pQuery = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        Validation::ValidateQuery($pQuery, array('fields'));
        Validation::ValidateRequestBody($pData, array('site_url'), array('site_url' => 'string'));
        Validation::ValidateNonEmptyString($pData['site_url'], 'site_url');

        return $this->Request('installs/' . $installId . '/clones.json', 'POST', $pQuery, $pData);
    }

    /**
     * Retrieve the product installation count.
     * @param array<string, mixed> $pQuery Count filters.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/installs/count.json (installations/retrieve-installs-count)
     */
    public function RetrieveInstallsCount(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('plan_id', 'is_active'));
        if (array_key_exists('plan_id', $pQuery)) {
            Validation::ValidatePathId($pQuery['plan_id'], 'plan_id');
        }
        if (array_key_exists('is_active', $pQuery)) {
            Validation::ValidateBoolean($pQuery['is_active'], 'is_active');
        }

        return $this->Request('installs/count.json', 'GET', $pQuery);
    }

    /**
     * Downgrade an installation to its default plan.
     * @param int $pInstallId Installation identifier.
     * @param array<string, mixed> $pData Downgrade data.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: PUT /v1/products/{product_id}/installs/{install_id}/downgrade.json (installations/downgrade-default-plan)
     */
    public function DowngradeDefaultPlan(int $pInstallId, array $pData = array(), array $pQuery = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        Validation::ValidateQuery($pQuery, array('fields'));
        Validation::ValidateRequestBody($pData, array(), array('deactivate_license' => 'bool', 'reason_ids' => 'array', 'reason' => 'string'));

        return $this->Request('installs/' . $installId . '/downgrade.json', 'PUT', $pQuery, $pData);
    }

    /**
     * List events for an installation.
     * @param int $pInstallId Installation identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/installs/{install_id}/events.json (installations/list-events)
     */
    public function ListInstallationEvents(int $pInstallId, array $pQuery = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        Validation::ValidateQuery($pQuery, array('count', 'offset', 'fields'));

        return $this->Request('installs/' . $installId . '/events.json', 'GET', $pQuery);
    }

    /**
     * Retrieve an installation.
     * @param int $pInstallId Installation identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/installs/{install_id}.json (installations/retrieve-install)
     */
    public function RetrieveInstall(int $pInstallId, array $pQuery = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('installs/' . $installId . '.json', 'GET', $pQuery);
    }

    /**
     * Update an installation.
     * @param int $pInstallId Installation identifier.
     * @param array<string, mixed> $pData Installation data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: PUT /v1/products/{product_id}/installs/{install_id}.json (installations/update-install)
     */
    public function UpdateInstall(int $pInstallId, array $pData)
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        Validation::ValidateRequestBody($pData, array(), array('items' => 'array'));

        return $this->Request('installs/' . $installId . '.json', 'PUT', array(), $pData);
    }

    /**
     * Delete an installation.
     * @param int $pInstallId Installation identifier.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: DELETE /v1/products/{product_id}/installs/{install_id}.json (installations/delete-install)
     */
    public function DeleteInstall(int $pInstallId)
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');

        return $this->Request('installs/' . $installId . '.json', 'DELETE');
    }

    /**
     * List product installations.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/installs.json (installations/list-installs)
     */
    public function ListInstalls(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('count', 'offset', 'fields'));

        return $this->Request('installs.json', 'GET', $pQuery);
    }

    /**
     * Retrieve an active installation license by UID.
     * @param int $pInstallId Installation identifier.
     * @param array<string, mixed> $pQuery License query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/installs/{install_id}/license.json (installations/retrieve-active-license-by-uid)
     */
    public function RetrieveActiveLicenseByUid(int $pInstallId, array $pQuery)
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        Validation::ValidateQuery($pQuery, array('uid', 'fields'));
        if (!array_key_exists('uid', $pQuery)) {
            Validation::ThrowEmptyArgument('uid', 'A required query parameter is missing.');
        }
        Validation::ValidateUid($pQuery['uid']);

        return $this->Request('installs/' . $installId . '/license.json', 'GET', $pQuery);
    }

    /**
     * Retrieve an active license by ID.
     * @param int $pInstallId Installation identifier.
     * @param int $pLicenseId License identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/installs/{install_id}/licenses/{license_id}.json (installations/retrieve-active-license-by-id)
     */
    public function RetrieveActiveLicenseById(int $pInstallId, int $pLicenseId, array $pQuery = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        $licenseId = Validation::ValidatePathId($pLicenseId, 'license_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('installs/' . $installId . '/licenses/' . $licenseId . '.json', 'GET', $pQuery);
    }

    /**
     * Activate a license for an installation.
     * @param int $pInstallId Installation identifier.
     * @param int $pLicenseId License identifier.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: PUT /v1/products/{product_id}/installs/{install_id}/licenses/{license_id}.json (installations/activate-license)
     */
    public function ActivateInstallationLicense(int $pInstallId, int $pLicenseId)
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        $licenseId = Validation::ValidatePathId($pLicenseId, 'license_id');

        return $this->Request('installs/' . $installId . '/licenses/' . $licenseId . '.json', 'PUT');
    }

    /**
     * Deactivate a license for an installation.
     * @param int $pInstallId Installation identifier.
     * @param int $pLicenseId License identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: DELETE /v1/products/{product_id}/installs/{install_id}/licenses/{license_id}.json (installations/deactivate-license)
     */
    public function DeactivateInstallationLicense(int $pInstallId, int $pLicenseId, array $pQuery = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        $licenseId = Validation::ValidatePathId($pLicenseId, 'license_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('installs/' . $installId . '/licenses/' . $licenseId . '.json', 'DELETE', $pQuery);
    }

    /**
     * List active installation licenses.
     * @param int $pInstallId Installation identifier.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/installs/{install_id}/licenses.json (installations/list-active-licenses)
     */
    public function ListActiveLicenses(int $pInstallId)
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');

        return $this->Request('installs/' . $installId . '/licenses.json', 'GET');
    }

    /**
     * List market items for an installation.
     * @param int $pInstallId Installation identifier.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/installs/{install_id}/market_items.json (installations/list-market-items)
     */
    public function ListMarketItems(int $pInstallId)
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');

        return $this->Request('installs/' . $installId . '/market_items.json', 'GET');
    }

    /**
     * List payments for an installation.
     * @param int $pInstallId Installation identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/installs/{install_id}/payments.json (installations/list-payments)
     */
    public function ListInstallationPayments(int $pInstallId, array $pQuery = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        Validation::ValidateQuery($pQuery, array('count', 'offset', 'fields'));

        return $this->Request('installs/' . $installId . '/payments.json', 'GET', $pQuery);
    }

    /**
     * Update installation permissions.
     * @param int $pInstallId Installation identifier.
     * @param array<string, mixed> $pData Permission data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: PUT /v1/products/{product_id}/installs/{install_id}/permissions.json (installations/update-permissions)
     */
    public function UpdatePermissions(int $pInstallId, array $pData)
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        Validation::ValidateRequestBody($pData, array(), array('is_enabled' => 'bool', 'permissions' => 'array'));

        return $this->Request('installs/' . $installId . '/permissions.json', 'PUT', array(), $pData);
    }

    /**
     * Retrieve an installation plan.
     * @param int $pInstallId Installation identifier.
     * @param int $pPlanId Plan identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/installs/{install_id}/plans/{plan_id}.json (installations/retrieve-plan)
     */
    public function RetrieveInstallationPlan(int $pInstallId, int $pPlanId, array $pQuery = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        $planId = Validation::ValidatePathId($pPlanId, 'plan_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('installs/' . $installId . '/plans/' . $planId . '.json', 'GET', $pQuery);
    }

    /**
     * List plans available to an installation.
     * @param int $pInstallId Installation identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/installs/{install_id}/plans.json (installations/list-plans)
     */
    public function ListInstallationPlans(int $pInstallId, array $pQuery = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('installs/' . $installId . '/plans.json', 'GET', $pQuery);
    }

    /**
     * Create a new installation license.
     * @param int $pInstallId Installation identifier.
     * @param int $pPlanId Plan identifier.
     * @param int $pPricingId Pricing identifier.
     * @param array<string, mixed> $pData License data.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: POST /v1/products/{product_id}/installs/{install_id}/plans/{plan_id}/pricing/{pricing_id}/licenses.json (installations/create-new-license)
     */
    public function CreateNewLicense(int $pInstallId, int $pPlanId, int $pPricingId, array $pData = array(), array $pQuery = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        $planId = Validation::ValidatePathId($pPlanId, 'plan_id');
        $pricingId = Validation::ValidatePathId($pPricingId, 'pricing_id');
        Validation::ValidateQuery($pQuery, array('fields'));
        Validation::ValidateRequestBody($pData, array(), array('is_block_features' => 'bool', 'period' => 'int', 'expires_at' => 'string'));

        return $this->Request('installs/' . $installId . '/plans/' . $planId . '/pricing/' . $pricingId . '/licenses.json', 'POST', $pQuery, $pData);
    }

    /**
     * Start an installation trial.
     * @param int $pInstallId Installation identifier.
     * @param int $pPlanId Plan identifier.
     * @param array<string, mixed> $pData Trial data.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: POST /v1/products/{product_id}/installs/{install_id}/plans/{plan_id}/trials.json (installations/start-trial)
     */
    public function StartTrial(int $pInstallId, int $pPlanId, array $pData = array(), array $pQuery = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        $planId = Validation::ValidatePathId($pPlanId, 'plan_id');
        Validation::ValidateQuery($pQuery, array('fields'));
        Validation::ValidateRequestBody($pData, array(), array('trial_ends' => 'string', 'phone' => 'string', 'is_migration' => 'bool', 'trial_token' => 'string'));

        return $this->Request('installs/' . $installId . '/plans/' . $planId . '/trials.json', 'POST', $pQuery, $pData);
    }

    /**
     * Cancel an installation trial.
     * @param int $pInstallId Installation identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: DELETE /v1/products/{product_id}/installs/{install_id}/trials.json (installations/cancel-trial)
     */
    public function CancelTrial(int $pInstallId, array $pQuery = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('installs/' . $installId . '/trials.json', 'DELETE', $pQuery);
    }

    /**
     * Retrieve installation uninstall details.
     * @param int $pInstallId Installation identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/installs/{install_id}/uninstall.json (installations/retrieve-uninstall-details)
     */
    public function RetrieveUninstallDetails(int $pInstallId, array $pQuery = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('installs/' . $installId . '/uninstall.json', 'GET', $pQuery);
    }

    /**
     * List installation updates.
     * @param int $pInstallId Installation identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/installs/{install_id}/updates.json (installations/list-updates)
     */
    public function ListUpdates(int $pInstallId, array $pQuery = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        Validation::ValidateQuery($pQuery, array('count', 'offset', 'fields'));

        return $this->Request('installs/' . $installId . '/updates.json', 'GET', $pQuery);
    }

    /**
     * Retrieve the latest installation update.
     * @param int $pInstallId Installation identifier.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/installs/{install_id}/updates/latest.json (installations/retrieve-latest-update)
     */
    public function RetrieveLatestUpdate(int $pInstallId)
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');

        return $this->Request('installs/' . $installId . '/updates/latest.json', 'GET');
    }

    /**
     * Change installation ownership.
     * @param int $pInstallId Installation identifier.
     * @param int $pUserId User identifier.
     * @param array<string, mixed> $pData Ownership data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: PUT /v1/products/{product_id}/installs/{install_id}/users/{user_id}/ownership-change.json (installations/change-ownership)
     */
    public function ChangeOwnership(int $pInstallId, int $pUserId, array $pData)
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        $userId = Validation::ValidatePathId($pUserId, 'user_id');
        Validation::ValidateRequestBody($pData, array(), array('email' => 'string', 'transfer_type' => 'string', 'owner_token' => 'string'));

        return $this->Request('installs/' . $installId . '/users/' . $userId . '/ownership-change.json', 'PUT', array(), $pData);
    }

    /**
     * Send an installation verification email.
     * @param int $pInstallId Installation identifier.
     * @param int $pUserId User identifier.
     * @param array<string, mixed> $pData Verification data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: PUT /v1/products/{product_id}/installs/{install_id}/users/{user_id}/verify.json (installations/send-verification-email)
     */
    public function SendVerificationEmail(int $pInstallId, int $pUserId, array $pData = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        $userId = Validation::ValidatePathId($pUserId, 'user_id');
        Validation::ValidateRequestBody($pData, array(), array('after_email_confirm_url' => 'string'));

        return $this->Request('installs/' . $installId . '/users/' . $userId . '/verify.json', 'PUT', array(), $pData);
    }

    /**
     * Uninstall an anonymous site.
     * @param array<string, mixed> $pData Uninstall data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: PUT /v1/products/{product_id}/uninstall.json (installations/uninstall-from-anonymous-site)
     */
    public function UninstallFromAnonymousSite(array $pData)
    {
        Validation::ValidateRequestBody($pData, array('uid'), array('uid' => 'string', 'reason_id' => 'int', 'reason' => 'string'));
        Validation::ValidateUid($pData['uid']);

        return $this->Request('uninstall.json', 'PUT', array(), $pData);
    }

    /**
     * Activate a product license.
     * @param array<string, mixed> $pData License activation data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: POST /v1/products/{product_id}/licenses/activate.json (licenses/activate)
     */
    public function ActivateLicense(array $pData)
    {
        Validation::ValidateRequestBody($pData, array('uid', 'license_key'), array('uid' => 'string', 'license_key' => 'string', 'url' => 'string', 'title' => 'string', 'version' => 'string', 'is_marketing_allowed' => 'bool', 'install_id' => 'int', 'first_name' => 'string', 'last_name' => 'string', 'user_email' => 'string', 'allow_unreleased_plan_activation' => 'bool'));

        return $this->Request('licenses/activate.json', 'POST', array(), $pData);
    }

    /**
     * Generate a license upgrade checkout link.
     * @param int $pLicenseId License identifier.
     * @param array<string, mixed> $pData Upgrade-link request data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: POST /v1/products/{product_id}/licenses/{license_id}/checkout/link.json (licenses/generate-upgrade-link)
     */
    public function GenerateLicenseUpgradeLink(int $pLicenseId, array $pData)
    {
        $licenseId = Validation::ValidatePathId($pLicenseId, 'license_id');
        Validation::ValidateRequestBody($pData, array(), array('plan_id' => 'int', 'billing_cycle' => 'string', 'quota' => 'int', 'is_payment_method_update' => 'bool'));
        if (array_key_exists('billing_cycle', $pData)) {
            Validation::ValidateEnum($pData['billing_cycle'], array('monthly', 'annual', 'lifetime'), 'billing_cycle');
        }

        return $this->Request('licenses/' . $licenseId . '/checkout/link.json', 'POST', array(), $pData);
    }

    /**
     * Deactivate a product license.
     * @param array<string, mixed> $pData License deactivation data.
     * @param array<string, mixed> $pQuery Optional response field selection.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: POST /v1/products/{product_id}/licenses/deactivate.json (licenses/deactivate)
     */
    public function DeactivateLicense(array $pData, array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('fields'));
        Validation::ValidateRequestBody($pData, array('uid', 'install_id', 'license_key'), array('uid' => 'string', 'install_id' => 'int', 'license_key' => 'string'));

        return $this->Request('licenses/deactivate.json', 'POST', $pQuery, $pData);
    }

    /**
     * Retrieve a product license.
     * @param int $pLicenseId License identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/licenses/{license_id}.json (licenses/retrieve)
     */
    public function RetrieveLicense(int $pLicenseId, array $pQuery = array())
    {
        $licenseId = Validation::ValidatePathId($pLicenseId, 'license_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('licenses/' . $licenseId . '.json', 'GET', $pQuery);
    }

    /**
     * Update a product license.
     * @param int $pLicenseId License identifier.
     * @param array<string, mixed> $pData License data.
     * @param array<string, mixed> $pQuery Optional response field selection.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: PUT /v1/products/{product_id}/licenses/{license_id}.json (licenses/update)
     */
    public function UpdateLicense(int $pLicenseId, array $pData, array $pQuery = array())
    {
        $licenseId = Validation::ValidatePathId($pLicenseId, 'license_id');
        Validation::ValidateQuery($pQuery, array('fields'));
        Validation::ValidateRequestBody($pData, array(), array('quota' => 'int', 'expiration' => 'string', 'is_block_features' => 'bool', 'is_whitelabeled' => 'bool', 'is_free_localhost' => 'bool', 'new_user_id' => 'int', 'cancel_subscription' => 'bool', 'extend_bundle' => 'bool', 'update_subscription_renewal_date' => 'bool'));

        return $this->Request('licenses/' . $licenseId . '.json', 'PUT', $pQuery, $pData);
    }

    /**
     * Cancel a product license.
     * @param int $pLicenseId License identifier.
     * @param array<string, mixed> $pQuery Cancellation query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: DELETE /v1/products/{product_id}/licenses/{license_id}.json (licenses/cancel)
     */
    public function CancelLicense(int $pLicenseId, array $pQuery = array())
    {
        $licenseId = Validation::ValidatePathId($pLicenseId, 'license_id');
        Validation::ValidateQuery($pQuery, array('delete', 'include_bundle', 'fields'));
        foreach (array('delete', 'include_bundle') as $name) {
            if (array_key_exists($name, $pQuery)) {
                Validation::ValidateBoolean($pQuery[$name], $name);
            }
        }

        return $this->Request('licenses/' . $licenseId . '.json', 'DELETE', $pQuery);
    }

    /**
     * List product licenses.
     * @param array<string, mixed> $pQuery License filters.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/licenses.json (licenses/list)
     */
    public function ListLicenses(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('count', 'offset', 'fields', 'search', 'user_id', 'plan_id', 'status', 'extended'));

        return $this->Request('licenses.json', 'GET', $pQuery);
    }

    /**
     * Assign a license to a product user or installation.
     * @param array<string, mixed> $pData License assignment data.
     * @param array<string, mixed> $pQuery Optional response field selection.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: PUT /v1/products/{product_id}/licenses.json (licenses/assign)
     */
    public function AssignLicense(array $pData, array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('fields'));
        Validation::ValidateRequestBody($pData, array('email', 'license_key'), array('email' => 'string', 'license_key' => 'string', 'name' => 'string', 'is_marketing_allowed' => 'bool'));

        return $this->Request('licenses.json', 'PUT', $pQuery, $pData);
    }

    /**
     * Deactivate all installs for a license.
     * @param int $pLicenseId License identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: DELETE /v1/products/{product_id}/licenses/{license_id}/installs.json (licenses/deactivate-installs)
     */
    public function DeactivateLicenseInstalls(int $pLicenseId, array $pQuery = array())
    {
        $licenseId = Validation::ValidatePathId($pLicenseId, 'license_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('licenses/' . $licenseId . '/installs.json', 'DELETE', $pQuery);
    }

    /**
     * Send a license renewal email.
     * @param int $pLicenseId License identifier.
     * @param array<string, mixed> $pData Renewal data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: POST /v1/products/{product_id}/licenses/{license_id}/renewals.json (licenses/send-renewal-email)
     */
    public function SendRenewalEmail(int $pLicenseId, array $pData = array())
    {
        $licenseId = Validation::ValidatePathId($pLicenseId, 'license_id');
        Validation::ValidateRequestBody($pData, array(), array('email' => 'string'));

        return $this->Request('licenses/' . $licenseId . '/renewals.json', 'POST', array(), $pData);
    }

    /**
     * Resend license keys.
     * @param array<string, mixed> $pData Resend data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: POST /v1/products/{product_id}/licenses/resend.json (licenses/resend-keys)
     */
    public function ResendLicenseKeys(array $pData = array())
    {
        Validation::ValidateRequestBody($pData, array(), array('email' => 'string', 'license_id' => 'int'));

        return $this->Request('licenses/resend.json', 'POST', array(), $pData);
    }

    /**
     * Resend a license upgrade email.
     * @param int $pLicenseId License identifier.
     * @param array<string, mixed> $pData Email data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: POST /v1/products/{product_id}/licenses/{license_id}/resend.json (licenses/resend-upgrade-email)
     */
    public function ResendUpgradeEmail(int $pLicenseId, array $pData = array())
    {
        $licenseId = Validation::ValidatePathId($pLicenseId, 'license_id');
        Validation::ValidateRequestBody($pData, array(), array('email' => 'string'));

        return $this->Request('licenses/' . $licenseId . '/resend.json', 'POST', array(), $pData);
    }

    /**
     * Retrieve the latest subscription for a license.
     * @param int $pLicenseId License identifier.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/licenses/{license_id}/subscription.json (licenses/retrieve-latest-subscription)
     */
    public function RetrieveLatestLicenseSubscription(int $pLicenseId)
    {
        $licenseId = Validation::ValidatePathId($pLicenseId, 'license_id');

        return $this->Request('licenses/' . $licenseId . '/subscription.json', 'GET');
    }

    /**
     * Cancel the current subscription for a license.
     * @param int $pLicenseId License identifier.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: DELETE /v1/products/{product_id}/licenses/{license_id}/subscription.json (licenses/cancel-current-subscription)
     */
    public function CancelCurrentLicenseSubscription(int $pLicenseId)
    {
        $licenseId = Validation::ValidatePathId($pLicenseId, 'license_id');

        return $this->Request('licenses/' . $licenseId . '/subscription.json', 'DELETE');
    }

    /**
     * List subscriptions for an installation license.
     * @param int $pInstallId Installation identifier.
     * @param int $pLicenseId License identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/installs/{install_id}/licenses/{license_id}/subscriptions.json (licenses/list-subscriptions)
     */
    public function ListLicenseSubscriptions(int $pInstallId, int $pLicenseId, array $pQuery = array())
    {
        $installId = Validation::ValidatePathId($pInstallId, 'install_id');
        $licenseId = Validation::ValidatePathId($pLicenseId, 'license_id');
        Validation::ValidateQuery($pQuery, array('count', 'offset', 'fields'));

        return $this->Request('installs/' . $installId . '/licenses/' . $licenseId . '/subscriptions.json', 'GET', $pQuery);
    }

    /**
     * Retrieve a license review URL.
     * @param int $pUserId User identifier.
     * @param int $pLicenseId License identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/users/{user_id}/licenses/{license_id}/review_url.json (licenses/get-review-url)
     */
    public function GetLicenseReviewUrl(int $pUserId, int $pLicenseId, array $pQuery = array())
    {
        $userId = Validation::ValidatePathId($pUserId, 'user_id');
        $licenseId = Validation::ValidatePathId($pLicenseId, 'license_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('users/' . $userId . '/licenses/' . $licenseId . '/review_url.json', 'GET', $pQuery);
    }

    /**
     * Create a review for a user license.
     * @param int $pUserId User identifier.
     * @param int $pLicenseId License identifier.
     * @param array<string, mixed> $pData Review data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: POST /v1/products/{product_id}/users/{user_id}/licenses/{license_id}/reviews.json (licenses/create-review)
     */
    public function CreateLicenseReview(int $pUserId, int $pLicenseId, array $pData)
    {
        $userId = Validation::ValidatePathId($pUserId, 'user_id');
        $licenseId = Validation::ValidatePathId($pLicenseId, 'license_id');
        Validation::ValidateRequestBody($pData, array(), array('title' => 'string', 'text' => 'string', 'rate' => 'int', 'name' => 'string'));

        return $this->Request('users/' . $userId . '/licenses/' . $licenseId . '/reviews.json', 'POST', array(), $pData);
    }

    /**
     * List product payments.
     * @param array<string, mixed> $pQuery Payment filters.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/payments.json (payments/list)
     */
    public function ListPayments(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('count', 'offset', 'search', 'search_user_id', 'billing_cycle', 'currency', 'coupon_id', 'filter', 'extended', 'from', 'to', 'fields'));

        return $this->Request('payments.json', 'GET', $pQuery);
    }

    /**
     * Retrieve a product payment.
     * @param int $pPaymentId Payment identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/payments/{payment_id}.json (payments/retrieve)
     */
    public function RetrievePayment(int $pPaymentId, array $pQuery = array())
    {
        $paymentId = Validation::ValidatePathId($pPaymentId, 'payment_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('payments/' . $paymentId . '.json', 'GET', $pQuery);
    }

    /**
     * Download a payment invoice.
     * @param int $pPaymentId Payment identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return string Raw PDF bytes.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/payments/{payment_id}/invoice.pdf (payments/download-invoice)
     */
    public function DownloadPaymentInvoice(int $pPaymentId, array $pQuery = array())
    {
        $paymentId = Validation::ValidatePathId($pPaymentId, 'payment_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('payments/' . $paymentId . '/invoice.pdf', 'GET', $pQuery, array(), 'binary');
    }

    /**
     * List product plans.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/plans.json (plans/list)
     */
    public function ListProductPlans(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('plans.json', 'GET', $pQuery);
    }

    /**
     * Retrieve a product plan.
     * @param int $pPlanId Plan identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/plans/{plan_id}.json (plans/retrieve)
     */
    public function RetrieveProductPlan(int $pPlanId, array $pQuery = array())
    {
        $planId = Validation::ValidatePathId($pPlanId, 'plan_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('plans/' . $planId . '.json', 'GET', $pQuery);
    }

    /**
     * List plan currencies.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/plans/currencies.json (plans/list-currencies)
     */
    public function ListPlanCurrencies(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('plans/currencies.json', 'GET', $pQuery);
    }

    /**
     * List features for a product plan.
     * @param int $pPlanId Plan identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/plans/{plan_id}/features.json (plans/list-features)
     */
    public function ListProductPlanFeatures(int $pPlanId, array $pQuery = array())
    {
        $planId = Validation::ValidatePathId($pPlanId, 'plan_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('plans/' . $planId . '/features.json', 'GET', $pQuery);
    }

    /**
     * Clone plan pricing to another currency.
     * @param int $pPlanId Plan identifier.
     * @param array<string, mixed> $pData Pricing clone data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: POST /v1/products/{product_id}/plans/{plan_id}/pricing/clone.json (plans/clone-pricing-other-currency)
     */
    public function ClonePlanPricing(int $pPlanId, array $pData)
    {
        $planId = Validation::ValidatePathId($pPlanId, 'plan_id');
        Validation::ValidateRequestBody($pData, array('to_currency'), array('to_currency' => 'string', 'from_currency' => 'string'));

        return $this->Request('plans/' . $planId . '/pricing/clone.json', 'POST', array(), $pData);
    }

    /**
     * Retrieve plan pricing.
     * @param int $pPlanId Plan identifier.
     * @param int $pPricingId Pricing identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/plans/{plan_id}/pricing/{pricing_id}.json (plans/retrieve-pricing)
     */
    public function RetrievePlanPricing(int $pPlanId, int $pPricingId, array $pQuery = array())
    {
        $planId = Validation::ValidatePathId($pPlanId, 'plan_id');
        $pricingId = Validation::ValidatePathId($pPricingId, 'pricing_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('plans/' . $planId . '/pricing/' . $pricingId . '.json', 'GET', $pQuery);
    }

    /**
     * List pricing for a product plan.
     * @param int $pPlanId Plan identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/plans/{plan_id}/pricing.json (plans/list-s-pricing)
     */
    public function ListPlanPricing(int $pPlanId, array $pQuery = array())
    {
        $planId = Validation::ValidatePathId($pPlanId, 'plan_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('plans/' . $planId . '/pricing.json', 'GET', $pQuery);
    }

    /**
     * Create a license from plan pricing.
     * @param int $pPlanId Plan identifier.
     * @param int $pPricingId Pricing identifier.
     * @param array<string, mixed> $pData License data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: POST /v1/products/{product_id}/plans/{plan_id}/pricing/{pricing_id}/licenses.json (plans/create-license)
     */
    public function CreatePlanLicense(int $pPlanId, int $pPricingId, array $pData = array())
    {
        $planId = Validation::ValidatePathId($pPlanId, 'plan_id');
        $pricingId = Validation::ValidatePathId($pPricingId, 'pricing_id');
        Validation::ValidateRequestBody($pData, array(), array('is_whitelabeled' => 'bool', 'period' => 'int', 'is_block_features' => 'bool', 'expires_at' => 'string', 'email' => 'string', 'existing_install_id' => 'int', 'send_email' => 'bool', 'source' => 'string', 'license_key' => 'string'));

        return $this->Request('plans/' . $planId . '/pricing/' . $pricingId . '/licenses.json', 'POST', array(), $pData);
    }

    /**
     * List product reviews.
     * @param array<string, mixed> $pQuery Review filters.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/reviews.json (reviews/list)
     */
    public function ListReviews(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('count', 'offset', 'fields'));

        return $this->Request('reviews.json', 'GET', $pQuery);
    }

    /**
     * Retrieve a product review.
     * @param int $pReviewId Review identifier.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/reviews/{review_id}.json (reviews/retrieve)
     */
    public function RetrieveReview(int $pReviewId)
    {
        $reviewId = Validation::ValidatePathId($pReviewId, 'review_id');

        return $this->Request('reviews/' . $reviewId . '.json', 'GET');
    }

    /**
     * Create a product review.
     * @param array<string, mixed> $pData Review data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: POST /v1/products/{product_id}/reviews.json (reviews/create)
     */
    public function CreateReview(array $pData)
    {
        Validation::ValidateRequestBody($pData, array('title', 'text', 'name', 'rate'), array('title' => 'string', 'text' => 'string', 'name' => 'string', 'rate' => 'int', 'job_title' => 'string', 'company' => 'string', 'company_url' => 'string', 'profile_url' => 'string', 'is_featured' => 'bool', 'is_active' => 'bool'));

        return $this->Request('reviews.json', 'POST', array(), $pData);
    }

    /**
     * Update a product review.
     * @param int $pReviewId Review identifier.
     * @param array<string, mixed> $pData Review data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: PUT /v1/products/{product_id}/reviews/{review_id}.json (reviews/update)
     */
    public function UpdateReview(int $pReviewId, array $pData)
    {
        $reviewId = Validation::ValidatePathId($pReviewId, 'review_id');
        Validation::ValidateRequestBody($pData, array(), array('title' => 'string', 'text' => 'string', 'name' => 'string', 'rate' => 'int', 'job_title' => 'string', 'company' => 'string', 'company_url' => 'string', 'profile_url' => 'string', 'is_featured' => 'bool'));

        return $this->Request('reviews/' . $reviewId . '.json', 'PUT', array(), $pData);
    }

    /**
     * Delete a product review.
     * @param int $pReviewId Review identifier.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: DELETE /v1/products/{product_id}/reviews/{review_id}.json (reviews/delete)
     */
    public function DeleteReview(int $pReviewId)
    {
        $reviewId = Validation::ValidatePathId($pReviewId, 'review_id');

        return $this->Request('reviews/' . $reviewId . '.json', 'DELETE');
    }

    /**
     * Retrieve the product review summary.
     * @param array<string, mixed> $pQuery Summary filters.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/reviews/summary.json (reviews/retrieve-summary)
     */
    public function RetrieveReviewsSummary(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('type'));
        if (array_key_exists('type', $pQuery)) {
            Validation::ValidateEnum($pQuery['type'], array('verified', 'all'), 'type');
        }

        return $this->Request('reviews/summary.json', 'GET', $pQuery);
    }

    /**
     * List product subscriptions.
     * @param array<string, mixed> $pQuery Subscription filters.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/subscriptions.json (subscriptions/list)
     */
    public function ListSubscriptions(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('search', 'billing_cycle', 'gateway', 'plan_id', 'status', 'extended', 'enrich_with_cancellation_discounts', 'count', 'offset', 'fields', 'sort'));

        return $this->Request('subscriptions.json', 'GET', $pQuery);
    }

    /**
     * Retrieve a product subscription.
     * @param int $pSubscriptionId Subscription identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/subscriptions/{subscription_id}.json (subscriptions/retrieve)
     */
    public function RetrieveSubscription(int $pSubscriptionId, array $pQuery = array())
    {
        $subscriptionId = Validation::ValidatePathId($pSubscriptionId, 'subscription_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('subscriptions/' . $subscriptionId . '.json', 'GET', $pQuery);
    }

    /**
     * Update a product subscription.
     * @param int $pSubscriptionId Subscription identifier.
     * @param array<string, mixed> $pData Subscription data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: PUT /v1/products/{product_id}/subscriptions/{subscription_id}.json (subscriptions/update)
     */
    public function UpdateSubscription(int $pSubscriptionId, array $pData)
    {
        $subscriptionId = Validation::ValidatePathId($pSubscriptionId, 'subscription_id');
        Validation::ValidateRequestBody($pData, array('coupon_id'), array('coupon_id' => 'int'));

        return $this->Request('subscriptions/' . $subscriptionId . '.json', 'PUT', array(), $pData);
    }

    /**
     * Cancel a product subscription.
     * @param int $pSubscriptionId Subscription identifier.
     * @param array<string, mixed> $pQuery Cancellation values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: DELETE /v1/products/{product_id}/subscriptions/{subscription_id}.json (subscriptions/cancel)
     */
    public function CancelSubscription(int $pSubscriptionId, array $pQuery = array())
    {
        $subscriptionId = Validation::ValidatePathId($pSubscriptionId, 'subscription_id');
        Validation::ValidateQuery($pQuery, array('reason_ids', 'reason'));

        return $this->Request('subscriptions/' . $subscriptionId . '.json', 'DELETE', $pQuery);
    }

    /**
     * List payments for a subscription.
     * @param int $pSubscriptionId Subscription identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/subscriptions/{subscription_id}/payments.json (subscriptions/list-payments)
     */
    public function ListSubscriptionPayments(int $pSubscriptionId, array $pQuery = array())
    {
        $subscriptionId = Validation::ValidatePathId($pSubscriptionId, 'subscription_id');
        Validation::ValidateQuery($pQuery, array('count', 'offset', 'fields'));

        return $this->Request('subscriptions/' . $subscriptionId . '/payments.json', 'GET', $pQuery);
    }

    /**
     * Create a migrated subscription payment.
     * @param int $pSubscriptionId Subscription identifier.
     * @param array<string, mixed> $pData Payment data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: POST /v1/products/{product_id}/subscriptions/{subscription_id}/payments.json (subscriptions/create-new-migrated-payment)
     */
    public function CreateSubscriptionMigratedPayment(int $pSubscriptionId, array $pData)
    {
        $subscriptionId = Validation::ValidatePathId($pSubscriptionId, 'subscription_id');
        Validation::ValidateRequestBody($pData, array('gross', 'payment_external_id', 'source', 'processed_at'), array('gross' => 'number', 'payment_external_id' => 'string', 'source' => 'string', 'processed_at' => 'string', 'vat' => 'number', 'gateway_fee' => 'number', 'is_extend_license' => 'bool', 'next_payment' => 'string'));

        return $this->Request('subscriptions/' . $subscriptionId . '/payments.json', 'POST', array(), $pData);
    }

    /**
     * List product trials.
     * @param array<string, mixed> $pQuery Trial filters.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/trials.json (trials/list)
     */
    public function ListTrials(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('fields', 'offset', 'count', 'from', 'to'));

        return $this->Request('trials.json', 'GET', $pQuery);
    }

    /**
     * List product users.
     * @param array<string, mixed> $pQuery User filters.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/users.json (users/list)
     */
    public function ListUsers(array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('email', 'filter', 'search', 'count', 'offset', 'fields'));

        return $this->Request('users.json', 'GET', $pQuery);
    }

    /**
     * Create a product user.
     * @param array<string, mixed> $pData User data.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: POST /v1/products/{product_id}/users.json (users/create)
     */
    public function CreateUser(array $pData, array $pQuery = array())
    {
        Validation::ValidateQuery($pQuery, array('fields'));
        Validation::ValidateRequestBody($pData, array('email', 'password'), array('email' => 'string', 'password' => 'string', 'ip' => 'string', 'name' => 'string', 'first' => 'string', 'last' => 'string', 'picture' => 'string', 'is_verified' => 'bool', 'after_email_confirm_url' => 'string', 'send_verification_email' => 'bool', 'is_marketing_allowed' => 'bool', 'is_migration' => 'bool'));

        return $this->Request('users.json', 'POST', $pQuery, $pData);
    }

    /**
     * Retrieve a product user.
     * @param int $pUserId User identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/users/{user_id}.json (users/retrieve)
     */
    public function RetrieveUser(int $pUserId, array $pQuery = array())
    {
        $userId = Validation::ValidatePathId($pUserId, 'user_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('users/' . $userId . '.json', 'GET', $pQuery);
    }

    /**
     * Update a product user.
     * @param int $pUserId User identifier.
     * @param array<string, mixed> $pData User data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: PUT /v1/products/{product_id}/users/{user_id}.json (users/update)
     */
    public function UpdateUser(int $pUserId, array $pData)
    {
        $userId = Validation::ValidatePathId($pUserId, 'user_id');
        Validation::ValidateRequestBody($pData, array(), array('email' => 'string', 'password' => 'string', 'name' => 'string', 'first' => 'string', 'last' => 'string', 'picture' => 'string', 'is_marketing_allowed' => 'bool'));

        return $this->Request('users/' . $userId . '.json', 'PUT', array(), $pData);
    }

    /**
     * Delete a product user.
     * @param int $pUserId User identifier.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: DELETE /v1/products/{product_id}/users/{user_id}.json (users/delete)
     */
    public function DeleteUser(int $pUserId)
    {
        $userId = Validation::ValidatePathId($pUserId, 'user_id');

        return $this->Request('users/' . $userId . '.json', 'DELETE');
    }

    /**
     * List events for a user.
     * @param int $pUserId User identifier.
     * @param array<string, mixed> $pQuery Event filters.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/users/{user_id}/events.json (users/list-events)
     */
    public function ListUserEvents(int $pUserId, array $pQuery = array())
    {
        $userId = Validation::ValidatePathId($pUserId, 'user_id');
        Validation::ValidateQuery($pQuery, array('type', 'state', 'count', 'offset', 'fields'));

        return $this->Request('users/' . $userId . '/events.json', 'GET', $pQuery);
    }

    /**
     * List installs for a user.
     * @param int $pUserId User identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/users/{user_id}/installs.json (users/list-installs)
     */
    public function ListUserInstalls(int $pUserId, array $pQuery = array())
    {
        $userId = Validation::ValidatePathId($pUserId, 'user_id');
        Validation::ValidateQuery($pQuery, array('fields', 'offset', 'count', 'sort'));

        return $this->Request('users/' . $userId . '/installs.json', 'GET', $pQuery);
    }

    /**
     * List licenses for a user.
     * @param int $pUserId User identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/users/{user_id}/licenses.json (users/list-licenses)
     */
    public function ListUserLicenses(int $pUserId, array $pQuery = array())
    {
        $userId = Validation::ValidatePathId($pUserId, 'user_id');
        Validation::ValidateQuery($pQuery, array('fields', 'offset', 'count'));

        return $this->Request('users/' . $userId . '/licenses.json', 'GET', $pQuery);
    }

    /**
     * List payments for a user.
     * @param int $pUserId User identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/users/{user_id}/payments.json (users/list-payments)
     */
    public function ListUserPayments(int $pUserId, array $pQuery = array())
    {
        $userId = Validation::ValidatePathId($pUserId, 'user_id');
        Validation::ValidateQuery($pQuery, array('fields', 'offset', 'count'));

        return $this->Request('users/' . $userId . '/payments.json', 'GET', $pQuery);
    }

    /**
     * Download a user's payment invoice.
     * @param int $pUserId User identifier.
     * @param int $pPaymentId Payment identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return string Raw PDF bytes.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/users/{user_id}/payments/{payment_id}/invoice.pdf (users/download-invoice)
     */
    public function DownloadUserPaymentInvoice(int $pUserId, int $pPaymentId, array $pQuery = array())
    {
        $userId = Validation::ValidatePathId($pUserId, 'user_id');
        $paymentId = Validation::ValidatePathId($pPaymentId, 'payment_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('users/' . $userId . '/payments/' . $paymentId . '/invoice.pdf', 'GET', $pQuery, array(), 'binary');
    }

    /**
     * List subscriptions for a user.
     * @param int $pUserId User identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/users/{user_id}/subscriptions.json (users/list-subscriptions)
     */
    public function ListUserSubscriptions(int $pUserId, array $pQuery = array())
    {
        $userId = Validation::ValidatePathId($pUserId, 'user_id');
        Validation::ValidateQuery($pQuery, array('fields', 'offset', 'count'));

        return $this->Request('users/' . $userId . '/subscriptions.json', 'GET', $pQuery);
    }

    /**
     * Retrieve user billing details.
     * @param int $pUserId User identifier.
     * @param array<string, mixed> $pQuery Query values.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: GET /v1/products/{product_id}/users/{user_id}/billing.json (users/retrieve-billing)
     */
    public function RetrieveUserBilling(int $pUserId, array $pQuery = array())
    {
        $userId = Validation::ValidatePathId($pUserId, 'user_id');
        Validation::ValidateQuery($pQuery, array('fields'));

        return $this->Request('users/' . $userId . '/billing.json', 'GET', $pQuery);
    }

    /**
     * Create or update user billing details.
     * @param int $pUserId User identifier.
     * @param array<string, mixed> $pData Billing data.
     * @return mixed Decoded API response.
     * @throws FreemiusException If the API or transport returns an error.
     * OpenAPI: PUT /v1/products/{product_id}/users/{user_id}/billing.json (users/update-or-create-billing)
     */
    public function UpdateUserBilling(int $pUserId, array $pData)
    {
        $userId = Validation::ValidatePathId($pUserId, 'user_id');
        Validation::ValidateRequestBody($pData, array(), array('address' => 'string', 'city' => 'string', 'zip' => 'string', 'country' => 'string', 'state' => 'string', 'company' => 'string', 'vat_id' => 'string'));

        return $this->Request('users/' . $userId . '/billing.json', 'PUT', array(), $pData);
    }
}