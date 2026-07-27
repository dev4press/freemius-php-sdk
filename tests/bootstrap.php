<?php

require_once __DIR__ . '/../vendor/autoload.php';

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
    'FS_API__DEVELOPER_ID',
    'FS_API__DEVELOPER_PUBLIC_KEY',
    'FS_API__DEVELOPER_SECRET_KEY',
    'FS_API__STORE_ID',
    'FS_API__STORE_PUBLIC_KEY',
    'FS_API__STORE_SECRET_KEY',
    'FS_API__PRODUCT_ID',
    'FS_API__PRODUCT_PUBLIC_KEY',
    'FS_API__PRODUCT_SECRET_KEY',
    'FS_TEST__VALID_LICENSE_KEY',
);

$missing_values = array();
foreach ($required_values as $name) {
    if (null === $get_environment_value($name) || '' === $get_environment_value($name)) {
        $missing_values[] = $name;
    }
}

if (!empty($missing_values)) {
    throw new RuntimeException(
        'Missing test credentials: ' . implode(', ', $missing_values) .
        '. Copy .env.example to .env and fill in real values, or provide these environment variables.'
    );
}

$api_address = $get_environment_value('FS_API__ADDRESS');
if (null !== $api_address && '' !== $api_address) {
    define('FS_API__ADDRESS', $api_address);
}

define('FS_API__DEVELOPER_ID', (int)$get_environment_value('FS_API__DEVELOPER_ID'));
define('FS_API__DEVELOPER_PUBLIC_KEY', $get_environment_value('FS_API__DEVELOPER_PUBLIC_KEY'));
define('FS_API__DEVELOPER_SECRET_KEY', $get_environment_value('FS_API__DEVELOPER_SECRET_KEY'));
define('FS_API__STORE_ID', (int)$get_environment_value('FS_API__STORE_ID'));
define('FS_API__STORE_PUBLIC_KEY', $get_environment_value('FS_API__STORE_PUBLIC_KEY'));
define('FS_API__STORE_SECRET_KEY', $get_environment_value('FS_API__STORE_SECRET_KEY'));
define('FS_API__PRODUCT_ID', (int)$get_environment_value('FS_API__PRODUCT_ID'));
define('FS_API__PRODUCT_PUBLIC_KEY', $get_environment_value('FS_API__PRODUCT_PUBLIC_KEY'));
define('FS_API__PRODUCT_SECRET_KEY', $get_environment_value('FS_API__PRODUCT_SECRET_KEY'));
define('FS_TEST__VALID_LICENSE_KEY', $get_environment_value('FS_TEST__VALID_LICENSE_KEY'));
