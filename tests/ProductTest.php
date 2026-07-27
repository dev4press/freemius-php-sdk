<?php

use Freemius\SDK\Exception\Exception as FreemiusException;
use Freemius\SDK\Exception\EmptyArgumentException;
use Freemius\SDK\Exception\InvalidArgumentException;
use Freemius\SDK\Product;
use Freemius\SDK\Utilities\Validation;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ProductTestDouble extends Product
{
    public function __construct(int $pID, string $pBearerToken, bool $pSandbox, $pClient)
    {
        $reflection = new ReflectionClass(Product::class);
        foreach (array('_id' => $pID, '_key' => $pBearerToken, '_sandbox' => $pSandbox) as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue($this, $value);
        }

        $this->_http_client = $pClient;
    }

    public function RequestForTest(
        string $pPath,
        string $pMethod = 'GET',
        array $pQuery = array(),
        array $pBody = array(),
        string $pResponseType = 'json'
    )
    {
        return $this->Request($pPath, $pMethod, $pQuery, $pBody, $pResponseType);
    }

    public function BuildProductPathForTest(string $pPath): string
    {
        return $this->BuildProductPath($pPath);
    }

    public function ValidateQueryForTest(array $pQuery): void
    {
        Validation::ValidateQuery($pQuery);
    }
}

class ProductTest extends TestCase
{
    private function CreateProduct(array $pResponses, array &$pHistory, bool $pSandbox = false): ProductTestDouble
    {
        $handler = new MockHandler($pResponses);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($pHistory));

        return new ProductTestDouble(
            42,
            'bearer-token',
            $pSandbox,
            new Client(array('handler' => $stack))
        );
    }

    public function testAuthenticatedJsonRequestUsesProductScopeAndDecodesObjects(): void
    {
        $history = array();
        $product = $this->CreateProduct(array(new Response(200, array(), '{"ok":true,"items":[1,2]}')), $history);

        $result = $product->RequestForTest(
            '/users.json',
            'GET',
            array('count' => 2, 'offset' => 0)
        );

        $this->assertInstanceOf(stdClass::class, $result);
        $this->assertTrue($result->ok);
        $this->assertSame(array(1, 2), $result->items);
        $this->assertCount(1, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertSame(
            'https://api.freemius.com/v1/products/42/users.json?count=2&offset=0',
            (string)$history[0]['request']->getUri()
        );
        $this->assertSame('Bearer bearer-token', $history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $history[0]['request']->getHeaderLine('Accept'));
        $this->assertSame('fs-php-' . Product::VERSION, $history[0]['request']->getHeaderLine('User-Agent'));
    }

    public function testSandboxPathAndBinaryResponsesArePreserved(): void
    {
        $history = array();
        $product = $this->CreateProduct(array(new Response(200, array(), "PK\x03\x04binary")), $history, true);

        $this->assertSame("PK\x03\x04binary", $product->RequestForTest('/deployments/latest.zip', 'GET', array(), array(), 'binary'));
        $this->assertSame(
            'https://sandbox-api.freemius.com/v1/products/42/deployments/latest.zip',
            (string)$history[0]['request']->getUri()
        );
    }

    public function testPostRequestEncodesJsonBody(): void
    {
        $history = array();
        $product = $this->CreateProduct(array(new Response(200, array(), '{"created":true}')), $history);

        $product->RequestForTest('/users.json', 'POST', array(), array('email' => 'user@example.com'));

        $this->assertSame('POST', $history[0]['request']->getMethod());
        $this->assertSame('{"email":"user@example.com"}', (string)$history[0]['request']->getBody());
        $this->assertSame('application/json', $history[0]['request']->getHeaderLine('Content-Type'));
    }

    public function testInvalidCommonQueryValuesFailBeforeHttpRequest(): void
    {
        $history = array();
        $product = $this->CreateProduct(array(new Response(200, array(), '{}')), $history);

        try {
            $product->RequestForTest('/users.json', 'GET', array('count' => 51));
            $this->fail('Expected an invalid-argument exception.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('InvalidArgument', $exception->GetType());
        }

        $this->assertCount(0, $history);
    }

    public function testValidationHelpersAreStaticUtilities(): void
    {
        foreach (array(
            'ValidateProductIdValue',
            'ValidateBearerTokenValue',
            'ValidatePathId',
            'ValidateNonEmptyString',
            'ValidateEnum',
            'ValidateIntegerRange',
            'ValidateNumericRange',
            'ValidateBoolean',
            'ValidateUid',
            'ValidateDateTime',
            'ValidateQuery',
            'ValidateRequestBody',
            'ThrowInvalidArgument',
            'ThrowEmptyArgument',
        ) as $method) {
            $reflection = new ReflectionMethod(Validation::class, $method);
            $this->assertTrue($reflection->isStatic(), $method . ' should be static.');
        }
    }

    public function testInvalidProductIdAndBearerTokenAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Product::Instance(0, 'bearer-token');
    }

    public function testEmptyBearerTokenIsRejected(): void
    {
        $this->expectException(EmptyArgumentException::class);
        Product::Instance(43, '');
    }

    public function testProductPathAlwaysUsesStoredProductId(): void
    {
        $history = array();
        $product = $this->CreateProduct(array(new Response(200, array(), '{}')), $history);

        $this->assertSame('/v1/products/42/users.json', $product->BuildProductPathForTest('/users.json'));
    }

    public function testInvalidDateTimeIsRejectedBeforeHttpRequest(): void
    {
        $history = array();
        $product = $this->CreateProduct(array(new Response(200, array(), '{}')), $history);

        try {
            $product->ValidateQueryForTest(array('from' => 'not-a-date'));
            $this->fail('Expected an invalid-argument exception.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('InvalidArgument', $exception->GetType());
        }

        $this->assertCount(0, $history);
    }

    public function testApiErrorPreservesDecodedErrorResult(): void
    {
        $history = array();
        $product = $this->CreateProduct(array(new Response(422, array(), '{"error":{"type":"Invalid","message":"Bad request"}}')), $history);

        try {
            $product->RequestForTest('/users.json');
            $this->fail('Expected an SDK exception.');
        } catch (FreemiusException $exception) {
            $this->assertSame('Invalid', $exception->GetType());
            $this->assertSame('Bad request', $exception->getMessage());
            $this->assertSame('Bad request', $exception->GetResult()['error']['message']);
        }
    }

    public function testTransportFailureIsWrappedAsSdkException(): void
    {
        $history = array();
        $product = $this->CreateProduct(
            array(new ConnectException('Connection failed', new Request('GET', '/'))),
            $history
        );

        $this->expectException(FreemiusException::class);
        $product->RequestForTest('/users.json');
    }

    public function testProductsOperationsBuildExpectedRequests(): void
    {
        $history = array();
        $responses = array();
        for ($index = 0; $index < 18; $index++) {
            $responses[] = new Response(200, array(), '{"ok":true}');
        }
        $responses[2] = new Response(204);
        $responses[5] = new Response(204);
        $responses[16] = new Response(204);
        $responses[17] = new Response(204);
        $product = $this->CreateProduct($responses, $history);
        $uid = str_repeat('a', 32);

        $product->ListAddons(array('count' => 2, 'offset' => 1, 'fields' => 'id,name', 'show_pending' => true, 'enriched' => false));
        $product->ListEmailAddresses(array('fields' => 'id,email'));
        $product->DeleteEmailAddresses();
        $product->RetrieveFeature(7, array('fields' => 'id,title'));
        $product->UpdateFeature(8, array('title' => 'Feature', 'description' => 'Description', 'is_featured' => true));
        $product->DeleteFeature(8);
        $product->ListFeatures(array('count' => 3, 'offset' => 0, 'fields' => 'id'));
        $product->RetrieveProduct(array('fields' => 'id,title'));
        $product->UpdateProduct(array('title' => 'Updated', 'accepted_payments' => 0, 'is_pricing_visible' => true));
        $product->GetProductInfo(array('fields' => 'id'));
        $product->CheckProductStatus(array('is_update' => true));
        $product->GdprComplianceCheck(array('uid' => $uid, 'is_update' => false, 'is_gdpr_test' => true));
        $product->GeneratePortalLoginLink(array('email' => 'user@example.com'));
        $product->RetrievePricingTableData(array('currency' => 'USD', 'show_pending' => false, 'type' => 'visible', 'is_enriched' => true));
        $product->RetrieveSetting(3, array('fields' => 'id,data'));
        $product->UpdateSetting(3, array('data' => '{"enabled":true}'), array('fields' => 'id'));
        $product->DeleteSetting(3);
        $product->SkipAccountConnection(array('uid' => $uid));

        $expected = array(
            'GET /v1/products/42/addons.json?count=2&offset=1&fields=id%2Cname&show_pending=1&enriched=0',
            'GET /v1/products/42/emails/addresses.json?fields=id%2Cemail',
            'DELETE /v1/products/42/emails/addresses.json',
            'GET /v1/products/42/features/7.json?fields=id%2Ctitle',
            'PUT /v1/products/42/features/8.json',
            'DELETE /v1/products/42/features/8.json',
            'GET /v1/products/42/features.json?count=3&offset=0&fields=id',
            'GET /v1/products/42.json?fields=id%2Ctitle',
            'PUT /v1/products/42.json',
            'GET /v1/products/42/info.json?fields=id',
            'GET /v1/products/42/is_active.json?is_update=1',
            'GET /v1/products/42/ping.json?uid=' . $uid . '&is_update=0&is_gdpr_test=1',
            'POST /v1/products/42/portal/login.json',
            'GET /v1/products/42/pricing.json?currency=USD&show_pending=0&type=visible&is_enriched=1',
            'GET /v1/products/42/settings/3.json?fields=id%2Cdata',
            'PUT /v1/products/42/settings/3.json?fields=id',
            'DELETE /v1/products/42/settings/3.json',
            'PUT /v1/products/42/skip.json',
        );

        $this->assertCount(18, $history);
        foreach ($expected as $index => $request) {
            $this->assertSame($request, $history[$index]['request']->getMethod() . ' ' . $history[$index]['request']->getRequestTarget());
            $this->assertSame('Bearer bearer-token', $history[$index]['request']->getHeaderLine('Authorization'));
        }
        $this->assertSame('{"title":"Feature","description":"Description","is_featured":true}', (string)$history[4]['request']->getBody());
        $this->assertSame('{"data":"{\\"enabled\\":true}"}', (string)$history[15]['request']->getBody());
        $this->assertSame('{"uid":"' . $uid . '"}', (string)$history[17]['request']->getBody());
    }

    public function testProductsOperationInventoryHasOnePublicMethodPerOperation(): void
    {
        $reflection = new ReflectionClass(Product::class);
        $operations = array();
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $docComment = $method->getDocComment();
            if (false !== $docComment && false !== strpos($docComment, 'OpenAPI:') && false !== strpos($docComment, '(products/')) {
                $operations[] = $method->getName();
            }
        }

        $this->assertSame(
            array(
                'ListAddons',
                'ListEmailAddresses',
                'DeleteEmailAddresses',
                'RetrieveFeature',
                'UpdateFeature',
                'DeleteFeature',
                'ListFeatures',
                'RetrieveProduct',
                'UpdateProduct',
                'GetProductInfo',
                'CheckProductStatus',
                'GdprComplianceCheck',
                'GeneratePortalLoginLink',
                'RetrievePricingTableData',
                'RetrieveSetting',
                'UpdateSetting',
                'DeleteSetting',
                'SkipAccountConnection',
            ),
            $operations
        );
    }

    public function testProductsOperationsRejectInvalidArgumentsBeforeHttpRequest(): void
    {
        $history = array();
        $product = $this->CreateProduct(array(new Response(200, array(), '{}')), $history);

        $invalidCalls = array(
            static function () use ($product): void {
                $product->RetrieveFeature(0);
            },
            static function () use ($product): void {
                $product->ListAddons(array('unknown' => true));
            },
            static function () use ($product): void {
                $product->UpdateFeature(1, array('is_featured' => 'yes'));
            },
            static function () use ($product): void {
                $product->GdprComplianceCheck();
            },
            static function () use ($product): void {
                $product->GeneratePortalLoginLink(array());
            },
            static function () use ($product): void {
                $product->RetrievePricingTableData(array('type' => 'hidden'));
            },
            static function () use ($product): void {
                $product->UpdateSetting(1, array('data' => array()));
            },
        );

        foreach ($invalidCalls as $invalidCall) {
            try {
                $invalidCall();
                $this->fail('Expected a structured validation exception.');
            } catch (EmptyArgumentException $exception) {
                $this->assertSame('EmptyArgument', $exception->GetType());
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('InvalidArgument', $exception->GetType());
            }
        }

        $this->assertCount(0, $history);
    }

    public function testEventOperationsBuildExpectedRequestsAndDecodeResponses(): void
    {
        $history = array();
        $product = $this->CreateProduct(
            array(
                new Response(200, array(), '{"id":"9","type":"license.created"}'),
                new Response(200, array(), '{"events":[]}'),
            ),
            $history
        );

        $this->assertInstanceOf(stdClass::class, $product->RetrieveEvent(9, array('fields' => 'id,type')));
        $this->assertInstanceOf(stdClass::class, $product->ListEvents(array(
            'type' => 'license.created',
            'state' => 'pending',
            'count' => 2,
            'offset' => 0,
            'fields' => 'id,type',
        )));

        $this->assertSame(
            array(
                'GET /v1/products/42/events/9.json?fields=id%2Ctype',
                'GET /v1/products/42/events.json?type=license.created&state=pending&count=2&offset=0&fields=id%2Ctype',
            ),
            array_map(
                static function (array $entry): string {
                    return $entry['request']->getMethod() . ' ' . $entry['request']->getRequestTarget();
                },
                $history
            )
        );
        foreach ($history as $entry) {
            $this->assertSame('Bearer bearer-token', $entry['request']->getHeaderLine('Authorization'));
        }
    }

    public function testEventArgumentsAreValidatedBeforeHttpRequest(): void
    {
        $history = array();
        $product = $this->CreateProduct(array(new Response(200, array(), '{}')), $history);

        foreach (array(
            static function () use ($product): void {
                $product->RetrieveEvent(0);
            },
            static function () use ($product): void {
                $product->ListEvents(array('type' => ''));
            },
            static function () use ($product): void {
                $product->ListEvents(array('state' => 'unknown'));
            },
            static function () use ($product): void {
                $product->ListEvents(array('count' => 51));
            },
        ) as $invalidCall) {
            try {
                $invalidCall();
                $this->fail('Expected a structured validation exception.');
            } catch (EmptyArgumentException $exception) {
                $this->assertSame('EmptyArgument', $exception->GetType());
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('InvalidArgument', $exception->GetType());
            }
        }

        $this->assertCount(0, $history);
    }

    public function testFeatureOperationsBuildExpectedRequestsAndDecodeResponses(): void
    {
        $history = array();
        $product = $this->CreateProduct(
            array(
                new Response(200, array(), '{"id":"7"}'),
                new Response(200, array(), '{"id":"7","title":"Updated"}'),
                new Response(204),
                new Response(200, array(), '{"features":[]}'),
            ),
            $history
        );

        $this->assertInstanceOf(stdClass::class, $product->RetrieveFeature(7, array('fields' => 'id')));
        $this->assertInstanceOf(stdClass::class, $product->UpdateFeature(7, array(
            'title' => 'Updated',
            'description' => 'Feature description',
            'is_featured' => true,
        )));
        $this->assertNull($product->DeleteFeature(7));
        $this->assertInstanceOf(stdClass::class, $product->ListFeatures(array('count' => 2, 'offset' => 0, 'fields' => 'id')));

        $this->assertSame(
            array(
                'GET /v1/products/42/features/7.json?fields=id',
                'PUT /v1/products/42/features/7.json',
                'DELETE /v1/products/42/features/7.json',
                'GET /v1/products/42/features.json?count=2&offset=0&fields=id',
            ),
            array_map(
                static function (array $entry): string {
                    return $entry['request']->getMethod() . ' ' . $entry['request']->getRequestTarget();
                },
                $history
            )
        );
        $this->assertSame(
            '{"title":"Updated","description":"Feature description","is_featured":true}',
            (string)$history[1]['request']->getBody()
        );
        foreach ($history as $entry) {
            $this->assertSame('Bearer bearer-token', $entry['request']->getHeaderLine('Authorization'));
        }
    }

    public function testFeatureArgumentsAreValidatedBeforeHttpRequest(): void
    {
        $history = array();
        $product = $this->CreateProduct(array(new Response(200, array(), '{}')), $history);

        foreach (array(
            static function () use ($product): void {
                $product->RetrieveFeature(0);
            },
            static function () use ($product): void {
                $product->UpdateFeature(7, array('is_featured' => 'yes'));
            },
            static function () use ($product): void {
                $product->ListFeatures(array('count' => 51));
            },
        ) as $invalidCall) {
            try {
                $invalidCall();
                $this->fail('Expected a structured validation exception.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('InvalidArgument', $exception->GetType());
            }
        }

        $this->assertCount(0, $history);
    }

    public function testEventOperationInventoryHasTwoPublicMethods(): void
    {
        $reflection = new ReflectionClass(Product::class);
        $operations = array();
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $docComment = $method->getDocComment();
            if (false !== $docComment && false !== strpos($docComment, 'OpenAPI:') && false !== strpos($docComment, '(events/')) {
                $operations[] = $method->getName();
            }
        }

        $this->assertSame(array('RetrieveEvent', 'ListEvents'), $operations);
    }

    public function testFeatureOperationInventoryHasFourPublicMethods(): void
    {
        $reflection = new ReflectionClass(Product::class);
        $operations = array();
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $docComment = $method->getDocComment();
            if (false !== $docComment && false !== strpos($docComment, 'OpenAPI:') && false !== strpos($docComment, '(products/') && false !== strpos($docComment, 'feature')) {
                $operations[] = $method->getName();
            }
        }

        $this->assertSame(array('RetrieveFeature', 'UpdateFeature', 'DeleteFeature', 'ListFeatures'), $operations);
    }

    public function testPathIdParametersAreDeclaredAsIntegers(): void
    {
        $pathIdParameters = array(
            'RetrieveEvent' => array('pEventId'),
            'RetrieveFeature' => array('pFeatureId'),
            'UpdateFeature' => array('pFeatureId'),
            'DeleteFeature' => array('pFeatureId'),
            'RetrieveSetting' => array('pSettingId'),
            'UpdateSetting' => array('pSettingId'),
            'DeleteSetting' => array('pSettingId'),
            'ListAddonPlans' => array('pAddonId'),
            'ListAddonPlanFeatures' => array('pAddonId', 'pPlanId'),
            'ListAddonPlanPricings' => array('pAddonId', 'pPlanId'),
            'RetrieveCart' => array('pCartId'),
            'UpdateCart' => array('pCartId'),
            'DeleteCart' => array('pCartId'),
            'RetrieveCartEvents' => array('pCartId'),
            'RetrieveCoupon' => array('pCouponId'),
            'UpdateCoupon' => array('pCouponId'),
            'DeleteCoupon' => array('pCouponId'),
            'RetrieveCouponNote' => array('pCouponId'),
            'UpdateCouponNote' => array('pCouponId'),
            'CreateCouponNote' => array('pCouponId'),
            'DeleteCouponNote' => array('pCouponId'),
            'CreateSpecialCoupon' => array('pCouponId', 'pSpecialId'),
            'DeleteSpecialCoupon' => array('pCouponId', 'pSpecialId'),
            'RetrieveDeployment' => array('pDeploymentId'),
            'UpdateDeployment' => array('pDeploymentId'),
            'DeleteDeployment' => array('pDeploymentId'),
            'DownloadDeployment' => array('pDeploymentId'),
        );

        foreach ($pathIdParameters as $methodName => $parameterNames) {
            $parameters = array();
            foreach ((new ReflectionMethod(Product::class, $methodName))->getParameters() as $parameter) {
                $parameters[$parameter->getName()] = $parameter;
            }

            foreach ($parameterNames as $parameterName) {
                $this->assertArrayHasKey($parameterName, $parameters, $methodName);
                $this->assertNotNull($parameters[$parameterName]->getType(), $methodName . ' parameter type');
                $this->assertSame('int', $parameters[$parameterName]->getType()->getName(), $methodName . ' parameter type');
            }
        }
    }

    public function testAddonOperationsBuildExpectedRequests(): void
    {
        $history = array();
        $product = $this->CreateProduct(
            array(
                new Response(200, array(), '{"plans":[1]}'),
                new Response(200, array(), '{"features":[2]}'),
                new Response(200, array(), '{"pricing":[3]}'),
            ),
            $history
        );

        $this->assertInstanceOf(stdClass::class, $product->ListAddonPlans(5));
        $this->assertInstanceOf(stdClass::class, $product->ListAddonPlanFeatures(5, 7));
        $this->assertInstanceOf(stdClass::class, $product->ListAddonPlanPricings(5, 7));

        $this->assertSame(
            array(
                'GET /v1/products/42/addons/5/plans.json',
                'GET /v1/products/42/addons/5/plans/7/features.json',
                'GET /v1/products/42/addons/5/plans/7/pricing.json',
            ),
            array_map(
                static function (array $entry): string {
                    return $entry['request']->getMethod() . ' ' . $entry['request']->getRequestTarget();
                },
                $history
            )
        );
        foreach ($history as $entry) {
            $this->assertSame('Bearer bearer-token', $entry['request']->getHeaderLine('Authorization'));
        }
    }

    public function testAddonPathIdsAreValidatedBeforeHttpRequest(): void
    {
        $history = array();
        $product = $this->CreateProduct(array(new Response(200, array(), '{}')), $history);

        foreach (array(
            static function () use ($product): void {
                $product->ListAddonPlans(0);
            },
            static function () use ($product): void {
                $product->ListAddonPlanFeatures(5, 0);
            },
            static function () use ($product): void {
                $product->ListAddonPlanPricings(-1, 7);
            },
        ) as $invalidCall) {
            try {
                $invalidCall();
                $this->fail('Expected a structured validation exception.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('InvalidArgument', $exception->GetType());
            }
        }

        $this->assertCount(0, $history);
    }

    public function testCartOperationsBuildExpectedRequests(): void
    {
        $history = array();
        $product = $this->CreateProduct(
            array(
                new Response(200, array(), '{"carts":[]}'),
                new Response(200, array(), '{"id":5}'),
                new Response(200, array(), '{"id":5,"email":"user@example.com"}'),
                new Response(204),
                new Response(200, array(), '{"events":[]}'),
            ),
            $history
        );

        $product->ListCarts(array(
            'filter' => 'active',
            'offset' => 1,
            'fields' => 'id,email',
            'enriched' => true,
            'email' => 'user@example.com',
            'count' => 2,
        ));
        $product->RetrieveCart(5, array('fields' => 'id', 'enriched' => false));
        $product->UpdateCart(5, array('plan_id' => 7, 'billing_cycle' => 12, 'email' => 'new@example.com'), array('fields' => 'id'));
        $product->DeleteCart(5);
        $product->RetrieveCartEvents(5, array('offset' => 0, 'count' => 10));

        $this->assertSame(
            array(
                'GET /v1/products/42/carts.json?filter=active&offset=1&fields=id%2Cemail&enriched=1&email=user%40example.com&count=2',
                'GET /v1/products/42/carts/5.json?fields=id&enriched=0',
                'PUT /v1/products/42/carts/5.json?fields=id',
                'DELETE /v1/products/42/carts/5.json',
                'GET /v1/products/42/carts/5/events.json?offset=0&count=10',
            ),
            array_map(
                static function (array $entry): string {
                    return $entry['request']->getMethod() . ' ' . $entry['request']->getRequestTarget();
                },
                $history
            )
        );
        $this->assertSame(
            '{"plan_id":7,"billing_cycle":12,"email":"new@example.com"}',
            (string)$history[2]['request']->getBody()
        );
    }

    public function testCartPathAndQueryValuesAreValidatedBeforeHttpRequest(): void
    {
        $history = array();
        $product = $this->CreateProduct(array(new Response(200, array(), '{}')), $history);

        foreach (array(
            static function () use ($product): void {
                $product->ListCarts(array('filter' => 'unknown'));
            },
            static function () use ($product): void {
                $product->RetrieveCart(0);
            },
            static function () use ($product): void {
                $product->UpdateCart(5, array('plan_id' => '7'));
            },
            static function () use ($product): void {
                $product->DeleteCart(0);
            },
            static function () use ($product): void {
                $product->RetrieveCartEvents(5, array('count' => 51));
            },
        ) as $invalidCall) {
            try {
                $invalidCall();
                $this->fail('Expected a structured validation exception.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('InvalidArgument', $exception->GetType());
            }
        }

        $this->assertCount(0, $history);
    }

    public function testCouponOperationsBuildExpectedRequests(): void
    {
        $history = array();
        $responses = array();
        for ($index = 0; $index < 12; $index++) {
            $responses[] = new Response(200, array(), '{"ok":true}');
        }
        $responses[4] = new Response(204);
        $responses[8] = new Response(204);
        $responses[10] = new Response(201, array(), '{"created":true}');
        $responses[11] = new Response(204);
        $product = $this->CreateProduct($responses, $history);

        $product->ListCoupons(array('code' => 'SAVE', 'is_enriched' => true, 'count' => 2, 'offset' => 0));
        $product->CreateCoupon(array('code' => 'SAVE', 'discount' => 20, 'discount_type' => 'percentage'));
        $product->RetrieveCoupon(3);
        $product->UpdateCoupon(3, array('is_active' => false));
        $product->DeleteCoupon(3);
        $product->RetrieveCouponNote(3);
        $product->UpdateCouponNote(3, array('note' => 'Updated'));
        $product->CreateCouponNote(3, array('note' => 'Created'));
        $product->DeleteCouponNote(3);
        $product->RetrieveSpecialCoupons(array('count' => 2, 'offset' => 0));
        $product->CreateSpecialCoupon(3, 4);
        $product->DeleteSpecialCoupon(3, 4);

        $this->assertSame(
            array(
                'GET /v1/products/42/coupons.json?code=SAVE&is_enriched=1&count=2&offset=0',
                'POST /v1/products/42/coupons.json',
                'GET /v1/products/42/coupons/3.json',
                'PUT /v1/products/42/coupons/3.json',
                'DELETE /v1/products/42/coupons/3.json',
                'GET /v1/products/42/coupons/3/note.json',
                'PUT /v1/products/42/coupons/3/note.json',
                'POST /v1/products/42/coupons/3/note.json',
                'DELETE /v1/products/42/coupons/3/note.json',
                'GET /v1/products/42/coupons/special.json?count=2&offset=0',
                'PUT /v1/products/42/coupons/3/special/4.json',
                'DELETE /v1/products/42/coupons/3/special/4.json',
            ),
            array_map(
                static function (array $entry): string {
                    return $entry['request']->getMethod() . ' ' . $entry['request']->getRequestTarget();
                },
                $history
            )
        );
        $this->assertSame('{"note":"Updated"}', (string)$history[6]['request']->getBody());
        $this->assertSame('{"note":"Created"}', (string)$history[7]['request']->getBody());
    }

    public function testCouponArgumentsAreValidatedBeforeHttpRequest(): void
    {
        $history = array();
        $product = $this->CreateProduct(array(new Response(200, array(), '{}')), $history);

        foreach (array(
            static function () use ($product): void {
                $product->ListCoupons(array('is_enriched' => 'yes'));
            },
            static function () use ($product): void {
                $product->CreateCoupon(array('discount' => '20'));
            },
            static function () use ($product): void {
                $product->RetrieveCoupon(0);
            },
            static function () use ($product): void {
                $product->UpdateCouponNote(1, array());
            },
            static function () use ($product): void {
                $product->DeleteSpecialCoupon(1, 0);
            },
        ) as $invalidCall) {
            try {
                $invalidCall();
                $this->fail('Expected a structured validation exception.');
            } catch (EmptyArgumentException $exception) {
                $this->assertSame('EmptyArgument', $exception->GetType());
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('InvalidArgument', $exception->GetType());
            }
        }

        $this->assertCount(0, $history);
    }

    public function testDeploymentOperationsBuildExpectedRequestsAndDecodeResponses(): void
    {
        $history = array();
        $product = $this->CreateProduct(
            array(
                new Response(200, array(), '{"deployments":[]}'),
                new Response(200, array(), '{"id":5}'),
                new Response(200, array(), '{"id":5,"version":"1.2.3"}'),
                new Response(204),
                new Response(200, array(), "PK\x03\x04deployment"),
                new Response(200, array(), "PK\x03\x04latest"),
            ),
            $history
        );

        $this->assertInstanceOf(stdClass::class, $product->ListDeployments(array('count' => 2, 'offset' => 0, 'fields' => 'id,version')));
        $this->assertInstanceOf(stdClass::class, $product->RetrieveDeployment(5, array('fields' => 'id')));
        $this->assertInstanceOf(stdClass::class, $product->UpdateDeployment(5, array('version' => '1.2.3', 'is_active' => true), array('fields' => 'id')));
        $this->assertNull($product->DeleteDeployment(5));
        $this->assertSame("PK\x03\x04deployment", $product->DownloadDeployment(5, array('is_premium' => true)));
        $this->assertSame("PK\x03\x04latest", $product->DownloadLatestDeployment(array('is_premium' => false)));

        $this->assertSame(
            array(
                'GET /v1/products/42/deployments.json?count=2&offset=0&fields=id%2Cversion',
                'GET /v1/products/42/deployments/5.json?fields=id',
                'PUT /v1/products/42/deployments/5.json?fields=id',
                'DELETE /v1/products/42/deployments/5.json',
                'GET /v1/products/42/deployments/5.zip?is_premium=1',
                'GET /v1/products/42/deployments/latest.zip?is_premium=0',
            ),
            array_map(
                static function (array $entry): string {
                    return $entry['request']->getMethod() . ' ' . $entry['request']->getRequestTarget();
                },
                $history
            )
        );
        foreach ($history as $entry) {
            $this->assertSame('Bearer bearer-token', $entry['request']->getHeaderLine('Authorization'));
        }
        $this->assertSame('{"version":"1.2.3","is_active":true}', (string)$history[2]['request']->getBody());
        $this->assertSame('*/*', $history[4]['request']->getHeaderLine('Accept'));
        $this->assertSame('*/*', $history[5]['request']->getHeaderLine('Accept'));
    }

    public function testDeploymentOperationInventoryHasSixPublicMethods(): void
    {
        $reflection = new ReflectionClass(Product::class);
        $operations = array();
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $docComment = $method->getDocComment();
            if (false !== $docComment && false !== strpos($docComment, 'OpenAPI:') && false !== strpos($docComment, '(deployments/')) {
                $operations[] = $method->getName();
            }
        }

        $this->assertSame(
            array(
                'ListDeployments',
                'RetrieveDeployment',
                'UpdateDeployment',
                'DeleteDeployment',
                'DownloadDeployment',
                'DownloadLatestDeployment',
            ),
            $operations
        );
    }

    public function testDeploymentArgumentsAreValidatedBeforeHttpRequest(): void
    {
        $history = array();
        $product = $this->CreateProduct(array(new Response(200, array(), '{}')), $history);

        foreach (array(
            static function () use ($product): void {
                $product->RetrieveDeployment(0);
            },
            static function () use ($product): void {
                $product->UpdateDeployment(5, array('is_active' => 'yes'));
            },
            static function () use ($product): void {
                $product->DeleteDeployment(0);
            },
            static function () use ($product): void {
                $product->DownloadDeployment(-1);
            },
            static function () use ($product): void {
                $product->DownloadLatestDeployment(array('is_premium' => 'yes'));
            },
        ) as $invalidCall) {
            try {
                $invalidCall();
                $this->fail('Expected a structured validation exception.');
            } catch (EmptyArgumentException $exception) {
                $this->assertSame('EmptyArgument', $exception->GetType());
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('InvalidArgument', $exception->GetType());
            }
        }

        $this->assertCount(0, $history);
    }

    public function testDeploymentApiErrorIsConvertedToSdkException(): void
    {
        $history = array();
        $product = $this->CreateProduct(
            array(new Response(422, array(), '{"error":{"type":"InvalidDeployment","message":"Bad deployment"}}')),
            $history
        );

        try {
            $product->RetrieveDeployment(5);
            $this->fail('Expected an SDK exception.');
        } catch (FreemiusException $exception) {
            $this->assertSame('InvalidDeployment', $exception->GetType());
            $this->assertSame('Bad deployment', $exception->getMessage());
        }
    }

    public function testRemainingEndpointFamiliesBuildBearerRequestsAndDecodeResponses(): void
    {
        $history = array();
        $product = $this->CreateProduct(
            array(
                new Response(200, array(), '{"features":[]}'),
                new Response(200, array(), '{"license":1}'),
                new Response(200, array(), "PDF-BYTES"),
                new Response(200, array(), '{"plans":[]}'),
                new Response(201, array(), '{"review":1}'),
                new Response(200, array(), '{"subscriptions":[]}'),
                new Response(200, array(), '{"trials":[]}'),
                new Response(200, array(), '{"billing":true}'),
            ),
            $history
        );

        $this->assertInstanceOf(stdClass::class, $product->ListPlansFeaturesForAddon(3, 4, 5, array('count' => 2)));
        $this->assertInstanceOf(stdClass::class, $product->ActivateLicense(array('uid' => str_repeat('a', 32), 'license_key' => 'key')));
        $this->assertSame('PDF-BYTES', $product->DownloadPaymentInvoice(6));
        $this->assertInstanceOf(stdClass::class, $product->ListProductPlans(array('fields' => 'id')));
        $this->assertInstanceOf(stdClass::class, $product->CreateReview(array('title' => 'Title', 'text' => 'Text', 'name' => 'Name', 'rate' => 5)));
        $this->assertInstanceOf(stdClass::class, $product->ListSubscriptions(array('count' => 2)));
        $this->assertInstanceOf(stdClass::class, $product->ListTrials(array('count' => 2)));
        $this->assertInstanceOf(stdClass::class, $product->UpdateUserBilling(8, array('country' => 'US')));

        $this->assertSame(
            array(
                'GET /v1/products/42/installs/3/addons/4/plans/5/features.json?count=2',
                'POST /v1/products/42/licenses/activate.json',
                'GET /v1/products/42/payments/6/invoice.pdf',
                'GET /v1/products/42/plans.json?fields=id',
                'POST /v1/products/42/reviews.json',
                'GET /v1/products/42/subscriptions.json?count=2',
                'GET /v1/products/42/trials.json?count=2',
                'PUT /v1/products/42/users/8/billing.json',
            ),
            array_map(
                static function (array $entry): string {
                    return $entry['request']->getMethod() . ' ' . $entry['request']->getRequestTarget();
                },
                $history
            )
        );
        foreach ($history as $entry) {
            $this->assertSame('Bearer bearer-token', $entry['request']->getHeaderLine('Authorization'));
        }
        $this->assertSame('{"uid":"' . str_repeat('a', 32) . '","license_key":"key"}', (string)$history[1]['request']->getBody());
        $this->assertSame('{"country":"US"}', (string)$history[7]['request']->getBody());
    }

    public function testRemainingEndpointArgumentsAreValidatedBeforeHttpRequest(): void
    {
        $history = array();
        $product = $this->CreateProduct(array(new Response(200, array(), '{}')), $history);

        foreach (array(
            static function () use ($product): void {
                $product->RetrieveInstall(0);
            },
            static function () use ($product): void {
                $product->ActivateLicense(array('license_key' => 'key'));
            },
            static function () use ($product): void {
                $product->GenerateLicenseUpgradeLink(0, array());
            },
            static function () use ($product): void {
                $product->UpdateSubscription(2, array());
            },
            static function () use ($product): void {
                $product->CreateUser(array('email' => 'a@example.com'));
            },
            static function () use ($product): void {
                $product->ListReviews(array('unknown' => true));
            },
        ) as $invalidCall) {
            try {
                $invalidCall();
                $this->fail('Expected a structured validation exception.');
            } catch (EmptyArgumentException $exception) {
                $this->assertSame('EmptyArgument', $exception->GetType());
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('InvalidArgument', $exception->GetType());
            }
        }

        $this->assertCount(0, $history);
    }

    public function testCompleteProductOperationInventoryHas129UniquePublicMethods(): void
    {
        $expectedRemaining = array(
            'ListPlansFeaturesForAddon', 'ResolveClone', 'CreateClone', 'RetrieveInstallsCount', 'DowngradeDefaultPlan',
            'ListInstallationEvents', 'RetrieveInstall', 'UpdateInstall', 'DeleteInstall', 'ListInstalls',
            'RetrieveActiveLicenseByUid', 'RetrieveActiveLicenseById', 'ActivateInstallationLicense',
            'DeactivateInstallationLicense', 'ListActiveLicenses', 'ListMarketItems', 'ListInstallationPayments',
            'UpdatePermissions', 'RetrieveInstallationPlan', 'ListInstallationPlans', 'CreateNewLicense', 'StartTrial',
            'CancelTrial', 'RetrieveUninstallDetails', 'ListUpdates', 'RetrieveLatestUpdate', 'ChangeOwnership',
            'SendVerificationEmail', 'UninstallFromAnonymousSite', 'ActivateLicense', 'GenerateLicenseUpgradeLink',
            'DeactivateLicense', 'RetrieveLicense', 'UpdateLicense', 'CancelLicense', 'ListLicenses', 'AssignLicense',
            'DeactivateLicenseInstalls', 'SendRenewalEmail', 'ResendLicenseKeys', 'ResendUpgradeEmail',
            'RetrieveLatestLicenseSubscription', 'CancelCurrentLicenseSubscription', 'ListLicenseSubscriptions',
            'GetLicenseReviewUrl', 'CreateLicenseReview', 'ListPayments', 'RetrievePayment', 'DownloadPaymentInvoice',
            'ListProductPlans', 'RetrieveProductPlan', 'ListPlanCurrencies', 'ListProductPlanFeatures', 'ClonePlanPricing',
            'RetrievePlanPricing', 'ListPlanPricing', 'CreatePlanLicense', 'ListReviews', 'RetrieveReview', 'CreateReview',
            'UpdateReview', 'DeleteReview', 'RetrieveReviewsSummary', 'ListSubscriptions', 'RetrieveSubscription',
            'UpdateSubscription', 'CancelSubscription', 'ListSubscriptionPayments', 'CreateSubscriptionMigratedPayment',
            'ListTrials', 'ListUsers', 'CreateUser', 'RetrieveUser', 'UpdateUser', 'DeleteUser', 'ListUserEvents',
            'ListUserInstalls', 'ListUserLicenses', 'ListUserPayments', 'DownloadUserPaymentInvoice',
            'ListUserSubscriptions', 'RetrieveUserBilling', 'UpdateUserBilling',
        );
        $reflection = new ReflectionClass(Product::class);
        $operations = array();
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $docComment = $method->getDocComment();
            if (false !== $docComment && false !== strpos($docComment, 'OpenAPI:')) {
                $operations[] = $method->getName();
            }
        }

        $this->assertCount(129, $operations);
        $this->assertCount(129, array_unique($operations));
        foreach ($expectedRemaining as $methodName) {
            $this->assertContains($methodName, $operations);
        }
        $this->assertCount(83, $expectedRemaining);
    }

    public function testProductMethodsHaveCompleteDocumentation(): void
    {
        foreach ((new ReflectionClass(Product::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $docComment = $method->getDocComment();
            $this->assertNotFalse($docComment, $method->getName() . ' must have a PHPDoc block.');
            $this->assertStringContainsString('@return', $docComment, $method->getName() . ' must document its return value.');

            foreach ($method->getParameters() as $parameter) {
                $this->assertMatchesRegularExpression(
                    '/@param\s+.*\s+\$' . preg_quote($parameter->getName(), '/') . '\b/',
                    $docComment,
                    $method->getName() . ' must document $' . $parameter->getName() . '.'
                );
            }
        }
    }

    public function testProductSpecificValidationHelpersAreOwnedByValidation(): void
    {
        foreach (array('ValidateCouponData', 'ValidateCouponNoteData', 'ValidateDeploymentDownloadQuery') as $methodName) {
            $this->assertTrue(method_exists(Validation::class, $methodName));
            $this->assertFalse(method_exists(Product::class, $methodName));
        }
    }
}