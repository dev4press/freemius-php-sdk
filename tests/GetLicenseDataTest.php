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
use Freemius\SDK\Legacy\Freemius as LegacyFreemius;
use PHPUnit\Framework\TestCase;

/**
 * Verifies license data retrieval with both SDK implementations.
 */
class GetLicenseDataTest extends TestCase
{
    /**
     * Verifies license data retrieval via Product scope through the legacy cURL implementation.
     *
     * @test
     * @return void
     */
    public function TestProductScopeLicenseDataLegacy()
    {
        $legacyApi = new LegacyFreemius(
            'plugin',
            FS_API__PRODUCT_ID,
            FS_API__PRODUCT_PUBLIC_KEY,
            FS_API__PRODUCT_SECRET_KEY
        );

        $license_key = FS_TEST__VALID_LICENSE_KEY;

        $result = $legacyApi->Api("/licenses.json", "GET", array(
            'search' => ($license_key),
        ));

        $this->assertIsArray($result->licenses, 'Licenses should be an array');
        $this->assertCount(1, $result->licenses, 'There should be exactly one license');
        $this->assertEquals($license_key, $result->licenses[0]->secret_key, 'The secret key should match the provided license key');
    }

    /**
     * Verifies license data retrieval via Product scope through the active Guzzle implementation.
     *
     * @test
     * @return void
     */
    public function TestProductScopeLicenseData()
    {
        $api = Freemius::Product(FS_API__PRODUCT_ID, FS_API__PRODUCT_PUBLIC_KEY, FS_API__PRODUCT_SECRET_KEY);

        $license_key = FS_TEST__VALID_LICENSE_KEY;

        $result = $api->Api("/licenses.json", "GET", array(
            'search' => ($license_key),
        ));

        $this->assertIsArray($result->licenses, 'Licenses should be an array');
        $this->assertCount(1, $result->licenses, 'There should be exactly one license');
        $this->assertEquals($license_key, $result->licenses[0]->secret_key, 'The secret key should match the provided license key');
    }
}
