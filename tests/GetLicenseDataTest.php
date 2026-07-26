<?php
/**
 * How to set up and run tests:
 *
 * 1. Install dependencies via Composer:
 *    composer install
 *
 * 2. Copy `.env.example` to `.env` and fill in your actual credentials:
 *    cp .env.example .env
 *    // Alternatively, provide the same variables through the environment (for example in CI).
 *
 * 3. Run the tests using PHPUnit:
 *    vendor/bin/phpunit
 */

use Freemius\SDK\Freemius;
use PHPUnit\Framework\TestCase;

class GetLicenseDataTest extends TestCase
{
    public function testLicenseData()
    {
        $api = new Freemius(
            FS_API__SCOPE,
            FS_API__ENTITY_ID,
            FS_API__PUBLIC_KEY,
            FS_API__SECRET_KEY
        );

        $license_key = FS_TEST__VALID_LICENSE_KEY;

        $result = $api->Api("/licenses.json", "GET", array(
            'search' => ($license_key),
        ));

        $this->assertIsArray($result->licenses, 'Licenses should be an array');
        $this->assertCount(1, $result->licenses, 'There should be exactly one license');
        $this->assertEquals($license_key, $result->licenses[0]->secret_key, 'The secret key should match the provided license key');
    }
}
