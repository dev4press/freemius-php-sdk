# Freemius PHP SDK
This SDK is a wrapper for accessing the API. It handles the endpoint's path and authorization signature generation.

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
  define( 'FS_API__ENTITY_ID', 1234 );
  define( 'FS_API__PUBLIC_KEY', 'pk_YOUR_PUBLIC_KEY' );
  define( 'FS_API__SECRET_KEY', 'sk_YOUR_SECRET_KEY' );
  
  // Init SDK.
  $api = new Freemius\SDK\Freemius(FS_API__SCOPE, FS_API__ENTITY_ID, FS_API__PUBLIC_KEY, FS_API__SECRET_KEY);
  
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
