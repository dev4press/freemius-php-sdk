<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Freemius\SDK\Freemius;

$env = array();
$env_file = __DIR__ . '/../.env';

if (is_readable($env_file)) {
    $env = parse_ini_file($env_file, false, INI_SCANNER_RAW);
    if (false === $env) {
        throw new RuntimeException('Unable to parse .env. Copy .env.example and use KEY=value entries.');
    }
}

$get_environment_value = static function ($name) use ($env) {
    $value = getenv($name);

    return false !== $value ? $value : (isset($env[$name]) ? $env[$name] : null);
};

$required_values = array(
    'FS_API__SCOPE',
    'FS_API__ENTITY_ID',
    'FS_API__PUBLIC_KEY',
    'FS_API__SECRET_KEY',
    'FS_DEPLOYMENT__PLUGIN_ID',
    'FS_DEPLOYMENT__PLUGIN_ZIP',
    'FS_DEPLOYMENT__DOWNLOAD_PATH',
);

$missing_values = array();
foreach ($required_values as $name) {
    if (null === $get_environment_value($name) || '' === $get_environment_value($name)) {
        $missing_values[] = $name;
    }
}

if (!empty($missing_values)) {
    throw new RuntimeException(
        'Missing deployment configuration: ' . implode(', ', $missing_values) .
        '. Copy .env.example to .env and fill in real values, or provide these environment variables.'
    );
}

$api_address = $get_environment_value('FS_API__ADDRESS');
if (null !== $api_address && '' !== $api_address) {
    define('FS_API__ADDRESS', $api_address);
}

define('FS_API__SCOPE', $get_environment_value('FS_API__SCOPE'));
define('FS_API__ENTITY_ID', (int) $get_environment_value('FS_API__ENTITY_ID'));
define('FS_API__PUBLIC_KEY', $get_environment_value('FS_API__PUBLIC_KEY'));
define('FS_API__SECRET_KEY', $get_environment_value('FS_API__SECRET_KEY'));

// Init SDK.
$api = new Freemius(FS_API__SCOPE, FS_API__ENTITY_ID, FS_API__PUBLIC_KEY, FS_API__SECRET_KEY);

// Deploy new version.
$tag = $api->Api(
    'plugins/' . $get_environment_value('FS_DEPLOYMENT__PLUGIN_ID') . '/tags.json',
    'POST',
    array('add_contributor' => true),
    array('file' => $get_environment_value('FS_DEPLOYMENT__PLUGIN_ZIP'))
);

// Generate secure download URLs.
$free_version_download_url = $api->GetSignedUrl("/plugins/{$tag->plugin_id}/tags/{$tag->id}.zip?is_premium=false");
$paid_version_download_url = $api->GetSignedUrl("/plugins/{$tag->plugin_id}/tags/{$tag->id}.zip?is_premium=true");

// Download the paid version.
if (file_put_contents($get_environment_value('FS_DEPLOYMENT__DOWNLOAD_PATH'), file_get_contents($paid_version_download_url))) {
    // Successfully downloaded and stored.
}

print_r($tag);
