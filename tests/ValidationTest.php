<?php

use Freemius\SDK\Exception\EmptyArgumentException;
use Freemius\SDK\Exception\InvalidArgumentException;
use Freemius\SDK\Utilities\Validation;
use PHPUnit\Framework\TestCase;

class ValidationTest extends TestCase
{
    public function testValidateProductIdValueAcceptsPositiveIntegers(): void
    {
        $this->assertNull(Validation::ValidateProductIdValue(1));
        $this->assertNull(Validation::ValidateProductIdValue(PHP_INT_MAX));
    }

    /**
     * @dataProvider invalidProductIdProvider
     */
    public function testValidateProductIdValueRejectsInvalidValues($value): void
    {
        $this->assertValidationException(
            static function () use ($value): void {
                Validation::ValidateProductIdValue($value);
            },
            InvalidArgumentException::class,
            'product_id',
            'InvalidArgument',
            'A positive integer product ID is required.'
        );
    }

    public function invalidProductIdProvider(): array
    {
        return array(
            array(0),
            array(-1),
            array('1'),
            array(null),
        );
    }

    public function testValidateBearerTokenValueAcceptsToken(): void
    {
        $this->assertNull(Validation::ValidateBearerTokenValue(' bearer-token '));
    }

    /**
     * @dataProvider emptyBearerTokenProvider
     */
    public function testValidateBearerTokenValueRejectsEmptyTokens($value): void
    {
        $this->assertValidationException(
            static function () use ($value): void {
                Validation::ValidateBearerTokenValue($value);
            },
            EmptyArgumentException::class,
            'bearer_token',
            'InvalidBearerToken',
            'Bearer token is required and can\'t be empty or null.'
        );
    }

    public function emptyBearerTokenProvider(): array
    {
        return array(
            array(null),
            array(''),
            array('   '),
        );
    }

    public function testValidatePathIdReturnsValueAndUsesCustomName(): void
    {
        $this->assertSame(42, Validation::ValidatePathId(42));
        $this->assertSame(7, Validation::ValidatePathId(7, 'user_id'));
    }

    public function testValidatePathIdRejectsNonPositiveValues(): void
    {
        $this->assertValidationException(
            static function (): void {
                Validation::ValidatePathId(0, 'user_id');
            },
            InvalidArgumentException::class,
            'user_id',
            'InvalidArgument',
            'A positive integer is required.'
        );
    }

    public function testValidateNonEmptyStringReturnsOriginalValue(): void
    {
        $this->assertSame(' value ', Validation::ValidateNonEmptyString(' value ', 'name'));
    }

    public function testValidateNonEmptyStringRejectsWrongTypeAndEmptyValue(): void
    {
        $this->assertValidationException(
            static function (): void {
                Validation::ValidateNonEmptyString(12, 'name');
            },
            InvalidArgumentException::class,
            'name',
            'InvalidArgument',
            'A string is required.'
        );

        $this->assertValidationException(
            static function (): void {
                Validation::ValidateNonEmptyString("\t", 'name');
            },
            EmptyArgumentException::class,
            'name',
            'EmptyArgument',
            'A non-empty string is required.'
        );
    }

    public function testValidateEnumIsStrictAndReturnsAllowedValue(): void
    {
        $this->assertSame('active', Validation::ValidateEnum('active', array('active', 'inactive'), 'status'));
        $this->assertSame(1, Validation::ValidateEnum(1, array(0, 1), 'enabled'));

        $this->assertValidationException(
            static function (): void {
                Validation::ValidateEnum('1', array(0, 1), 'enabled');
            },
            InvalidArgumentException::class,
            'enabled',
            'InvalidArgument',
            'The value is not one of the allowed values.'
        );
    }

    public function testValidateIntegerRangeAcceptsInclusiveBounds(): void
    {
        $this->assertSame(1, Validation::ValidateIntegerRange(1, 'count', 1, 50));
        $this->assertSame(50, Validation::ValidateIntegerRange(50, 'count', 1, 50));
        $this->assertSame(-2, Validation::ValidateIntegerRange(-2, 'value'));
    }

    public function testValidateIntegerRangeRejectsWrongTypeAndOutOfRangeValues(): void
    {
        foreach (array('1', 0, 51) as $value) {
            $this->assertValidationException(
                static function () use ($value): void {
                    Validation::ValidateIntegerRange($value, 'count', 1, 50);
                },
                InvalidArgumentException::class,
                'count',
                'InvalidArgument',
                'An integer within the allowed range is required.'
            );
        }
    }

    public function testValidateNumericRangeAcceptsIntegersFloatsAndInclusiveBounds(): void
    {
        $this->assertSame(1, Validation::ValidateNumericRange(1, 'price', 1.0, 5.5));
        $this->assertSame(5.5, Validation::ValidateNumericRange(5.5, 'price', 1.0, 5.5));
        $this->assertSame(-3.25, Validation::ValidateNumericRange(-3.25, 'price'));
    }

    public function testValidateNumericRangeRejectsNonNumericAndOutOfRangeValues(): void
    {
        foreach (array('1', 0, 6.0) as $value) {
            $this->assertValidationException(
                static function () use ($value): void {
                    Validation::ValidateNumericRange($value, 'price', 1.0, 5.5);
                },
                InvalidArgumentException::class,
                'price',
                'InvalidArgument',
                'A number within the allowed range is required.'
            );
        }
    }

    public function testValidateBooleanAcceptsBothBooleanValuesAndReturnsThem(): void
    {
        $this->assertTrue(Validation::ValidateBoolean(true, 'enabled'));
        $this->assertFalse(Validation::ValidateBoolean(false, 'enabled'));
    }

    public function testValidateBooleanRejectsCoercibleValues(): void
    {
        $this->assertValidationException(
            static function (): void {
                Validation::ValidateBoolean(1, 'enabled');
            },
            InvalidArgumentException::class,
            'enabled',
            'InvalidArgument',
            'A boolean is required.'
        );
    }

    public function testValidateUidAcceptsExactly32Characters(): void
    {
        $uid = str_repeat('a', 32);

        $this->assertSame($uid, Validation::ValidateUid($uid));
        $this->assertSame($uid, Validation::ValidateUid($uid, 'user_uid'));
    }

    public function testValidateUidRejectsValuesWithWrongLengthOrType(): void
    {
        foreach (array(str_repeat('a', 31), str_repeat('a', 33), 123) as $value) {
            $this->assertValidationException(
                static function () use ($value): void {
                    Validation::ValidateUid($value, 'user_uid');
                },
                InvalidArgumentException::class,
                'user_uid',
                'InvalidArgument',
                'A 32-character UID is required.'
            );
        }
    }

    public function testValidateDateTimeAcceptsIso8601Values(): void
    {
        $this->assertSame('2024-01-31T23:59:59Z', Validation::ValidateDateTime('2024-01-31T23:59:59Z', 'from'));
        $this->assertSame(
            '2024-02-29T12:30:45.123+02:00',
            Validation::ValidateDateTime('2024-02-29T12:30:45.123+02:00', 'to')
        );
    }

    public function testValidateDateTimeRejectsMalformedAndInvalidCalendarValues(): void
    {
        foreach (array('2024-01-31', '2024-02-30T12:30:45Z', 'not-a-date') as $value) {
            $this->assertValidationException(
                static function () use ($value): void {
                    Validation::ValidateDateTime($value, 'from');
                },
                InvalidArgumentException::class,
                'from',
                'InvalidArgument',
                'An ISO 8601 date-time is required.'
            );
        }
    }

    public function testValidateQueryAcceptsSupportedValuesAndAllowlist(): void
    {
        Validation::ValidateQuery(
            array(
                'fields' => 'id,name',
                'count' => 50,
                'offset' => 0,
                'gateway' => 'stripe',
                'from' => '2024-01-01T00:00:00Z',
                'to' => '2024-01-31T23:59:59Z',
                'billing_cycle' => '12',
                'uid' => str_repeat('b', 32),
            ),
            array('fields', 'count', 'offset', 'gateway', 'from', 'to', 'billing_cycle', 'uid')
        );

        $this->assertTrue(true);
    }

    public function testValidateQueryRejectsUnsupportedAllowedParameter(): void
    {
        $this->assertValidationException(
            static function (): void {
                Validation::ValidateQuery(array('unknown' => 'value'), array('fields'));
            },
            InvalidArgumentException::class,
            'unknown',
            'InvalidArgument',
            'This query parameter is not supported by the operation.'
        );
    }

    public function testValidateQueryRejectsInvalidKnownParameter(): void
    {
        $this->assertValidationException(
            static function (): void {
                Validation::ValidateQuery(array('count' => 51));
            },
            InvalidArgumentException::class,
            'count',
            'InvalidArgument',
            'An integer within the allowed range is required.'
        );
    }

    public function testValidateRequestBodyAcceptsRequiredFieldsAndDeclaredTypes(): void
    {
        Validation::ValidateRequestBody(
            array(
                'name' => 'Product',
                'enabled' => true,
                'count' => 2,
                'price' => 1.5,
                'tags' => array('sdk'),
            ),
            array('name', 'enabled'),
            array(
                'name' => 'string',
                'enabled' => 'bool',
                'count' => 'int',
                'price' => 'number',
                'tags' => 'array',
            )
        );

        $this->assertTrue(true);
    }

    public function testValidateRequestBodyRejectsMissingAndNullRequiredFields(): void
    {
        foreach (array(array(), array('name' => null)) as $data) {
            $this->assertValidationException(
                static function () use ($data): void {
                    Validation::ValidateRequestBody($data, array('name'));
                },
                EmptyArgumentException::class,
                'name',
                'EmptyArgument',
                'A required request-body field is missing.'
            );
        }
    }

    public function testValidateRequestBodyRejectsInvalidDeclaredTypes(): void
    {
        $invalidValues = array(
            'string' => 1,
            'int' => '1',
            'bool' => 1,
            'array' => 'tags',
            'number' => '1.5',
        );

        foreach ($invalidValues as $name => $value) {
            $this->assertValidationException(
                static function () use ($name, $value): void {
                    Validation::ValidateRequestBody(array($name => $value), array(), array($name => $name));
                },
                InvalidArgumentException::class,
                $name,
                'InvalidArgument',
                'The request-body field has an invalid type.'
            );
        }
    }

    public function testProductSpecificValidatorsAcceptValidValues(): void
    {
        Validation::ValidateCouponData(array('code' => 'WELCOME', 'discount' => 10));
        Validation::ValidateCouponNoteData(array('note' => 'Internal note'));
        Validation::ValidateDeploymentDownloadQuery(array('is_premium' => true));

        $this->assertTrue(true);
    }

    public function testProductSpecificValidatorsRejectInvalidValues(): void
    {
        $this->assertValidationException(
            static function (): void {
                Validation::ValidateCouponData(array('code' => '   '));
            },
            EmptyArgumentException::class,
            'code',
            'EmptyArgument',
            'A non-empty string is required.'
        );

        $this->assertValidationException(
            static function (): void {
                Validation::ValidateCouponNoteData(array());
            },
            EmptyArgumentException::class,
            'note',
            'EmptyArgument',
            'A required request-body field is missing.'
        );

        $this->assertValidationException(
            static function (): void {
                Validation::ValidateDeploymentDownloadQuery(array('is_premium' => 'yes'));
            },
            InvalidArgumentException::class,
            'is_premium',
            'InvalidArgument',
            'A boolean is required.'
        );
    }

    public function testThrowInvalidArgumentCreatesStructuredException(): void
    {
        $this->assertValidationException(
            static function (): void {
                Validation::ThrowInvalidArgument('field', 'message');
            },
            InvalidArgumentException::class,
            'field',
            'InvalidArgument',
            'message'
        );
    }

    public function testThrowEmptyArgumentCreatesStructuredExceptionAndSupportsCustomType(): void
    {
        $this->assertValidationException(
            static function (): void {
                Validation::ThrowEmptyArgument('field', 'message', 'CustomType');
            },
            EmptyArgumentException::class,
            'field',
            'CustomType',
            'message'
        );
    }

    private function assertValidationException(
        callable $callback,
        string $expectedClass,
        string $expectedField,
        string $expectedType,
        string $expectedMessage
    ): void {
        $exception = null;

        try {
            $callback();
        } catch (\Throwable $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf($expectedClass, $exception);
        $this->assertSame($expectedMessage, $exception->getMessage());
        $this->assertSame($expectedType, $exception->GetType());
        $this->assertSame(
            array(
                'error' => array(
                    'message' => $expectedMessage,
                    'type' => $expectedType,
                    'field' => $expectedField,
                ),
            ),
            $exception->GetResult()
        );
    }
}