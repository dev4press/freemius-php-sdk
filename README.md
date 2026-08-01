# Freemius PHP SDK
This SDK is a wrapper for accessing the API. It handles the endpoint's path and authorization signature generation.

Requests from `Freemius` use an internal Guzzle client with explicit timeout,
redirect, TLS, error-response, and multipart options. The legacy cURL
implementation is available as `Freemius\SDK\Legacy\Freemius` for
compatibility comparisons, and both implementations use Composer's CA bundle
for HTTPS certificate verification. Existing integrations
that customized the public `$CURL_OPTS` property or passed a cURL handle to
`MakeRequest()` must migrate to the Guzzle request configuration; the cURL
handle argument is no longer supported.
The SDK keeps the cURL extension requirement because Guzzle 6 uses its cURL
handler by default.

Install the SDK with Composer and include Composer's autoloader:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

To run the integration tests, copy `.env.example` to `.env` and fill in the real Freemius credentials. The `.env` file is ignored by Git. In CI, provide the same variables as secret environment variables instead of creating a file.

As a plugin or theme developer using Freemius, you can access your data via the `developer` scope or `plugin` scope. 
If you only need to access one product, we recommend using the `plugin` scope. You can get the product's credentials in *SETTINGS -> Keys*.
If you need to access multiple products, use the `developer` scope. To get your credentials, click on *My Profile* at the top right menu and you'll find it in the *Keys* section.

```php
  define( 'FS_API__SCOPE', 'developer' );
  define( 'FS_API__PRODUCT_ID', 1234 );
  define( 'FS_API__PRODUCT_PUBLIC_KEY', 'pk_YOUR_PUBLIC_KEY' );
  define( 'FS_API__PRODUCT_SECRET_KEY', 'sk_YOUR_SECRET_KEY' );
  
  // Init SDK.
  $api = new Freemius\SDK\Freemius(FS_API__SCOPE, FS_API__PRODUCT_ID, FS_API__PRODUCT_PUBLIC_KEY, FS_API__PRODUCT_SECRET_KEY);
  
  // Get all products.
  $result = $api->Api('/plugins.json');
  
  // Load 1st product data.
  $first_plugin_id = $result->plugins[0]->id;
  $first_plugin = $api->Api("/plugins/{$first_plugin_id}.json");
  
  // Update title.
  $api->Api("/plugins/{$first_plugin_id}.json", 'PUT', array(
    'title' => 'My New Title',
  ));
```
