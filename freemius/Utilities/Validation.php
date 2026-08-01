<?php

namespace Freemius\SDK\Utilities;

use Freemius\SDK\Exception\EmptyArgumentException;
use Freemius\SDK\Exception\InvalidArgumentException;

/**
 * Shared validation helpers for SDK arguments and request data.
 */
class Validation
{
    /**
     * Validate a positive product identifier.
     *
     * @param mixed $pID Product identifier.
     *
     * @return void
     */
    public static function ValidateProductIdValue($pID): void
    {
        if (!is_int($pID) || $pID <= 0) {
            self::ThrowInvalidArgument('product_id', 'A positive integer product ID is required.');
        }
    }

    /**
     * Validate a bearer token.
     *
     * @param string|null $pBearerToken Bearer token.
     *
     * @return void
     */
    public static function ValidateBearerTokenValue(?string $pBearerToken): void
    {
        if (null === $pBearerToken || '' === trim($pBearerToken)) {
            self::ThrowEmptyArgument('bearer_token', 'Bearer token is required and can\'t be empty or null.', 'InvalidBearerToken');
        }
    }

    /**
     * Validate a positive path identifier.
     *
     * @param mixed $pValue Identifier value.
     * @param string $pName Argument name.
     *
     * @return int The validated identifier.
     */
    public static function ValidatePathId($pValue, string $pName = 'id'): int
    {
        if (!is_int($pValue) || $pValue <= 0) {
            self::ThrowInvalidArgument($pName, 'A positive integer is required.');
        }

        return $pValue;
    }

    /**
     * Validate a non-empty string value.
     *
     * @param mixed $pValue Value to validate.
     * @param string $pName Argument name.
     *
     * @return string The validated string.
     */
    public static function ValidateNonEmptyString($pValue, string $pName): string
    {
        if (!is_string($pValue)) {
            self::ThrowInvalidArgument($pName, 'A string is required.');
        }

        if ('' === trim($pValue)) {
            self::ThrowEmptyArgument($pName, 'A non-empty string is required.');
        }

        return $pValue;
    }

    /**
     * Validate that a value belongs to an allowed enum.
     *
     * @param mixed $pValue Value to validate.
     * @param array<int, mixed> $pValues Allowed values.
     * @param string $pName Argument name.
     *
     * @return mixed The validated value.
     */
    public static function ValidateEnum($pValue, array $pValues, string $pName)
    {
        if (!in_array($pValue, $pValues, true)) {
            self::ThrowInvalidArgument($pName, 'The value is not one of the allowed values.');
        }

        return $pValue;
    }

    /**
     * Validate an integer range.
     *
     * @param mixed $pValue Value to validate.
     * @param string $pName Argument name.
     * @param int|null $pMinimum Inclusive minimum.
     * @param int|null $pMaximum Inclusive maximum.
     *
     * @return int The validated integer.
     */
    public static function ValidateIntegerRange($pValue, string $pName, ?int $pMinimum = null, ?int $pMaximum = null): int
    {
        if (!is_int($pValue) || (null !== $pMinimum && $pValue < $pMinimum) || (null !== $pMaximum && $pValue > $pMaximum)) {
            self::ThrowInvalidArgument($pName, 'An integer within the allowed range is required.');
        }

        return $pValue;
    }

    /**
     * Validate a numeric range.
     *
     * @param mixed $pValue Value to validate.
     * @param string $pName Argument name.
     * @param float|null $pMinimum Inclusive minimum.
     * @param float|null $pMaximum Inclusive maximum.
     *
     * @return int|float The validated number.
     */
    public static function ValidateNumericRange($pValue, string $pName, ?float $pMinimum = null, ?float $pMaximum = null)
    {
        if ((!is_int($pValue) && !is_float($pValue)) || (null !== $pMinimum && $pValue < $pMinimum) || (null !== $pMaximum && $pValue > $pMaximum)) {
            self::ThrowInvalidArgument($pName, 'A number within the allowed range is required.');
        }

        return $pValue;
    }

    /**
     * Validate a boolean value.
     *
     * @param mixed $pValue Value to validate.
     * @param string $pName Argument name.
     *
     * @return bool The validated boolean.
     */
    public static function ValidateBoolean($pValue, string $pName): bool
    {
        if (!is_bool($pValue)) {
            self::ThrowInvalidArgument($pName, 'A boolean is required.');
        }

        return $pValue;
    }

    /**
     * Validate a 32-character UID.
     *
     * @param mixed $pValue Value to validate.
     * @param string $pName Argument name.
     *
     * @return string The validated UID.
     */
    public static function ValidateUid($pValue, string $pName = 'uid'): string
    {
        if (!is_string($pValue) || 32 !== strlen($pValue)) {
            self::ThrowInvalidArgument($pName, 'A 32-character UID is required.');
        }

        return $pValue;
    }

    /**
     * Validate an ISO 8601 date-time value.
     *
     * @param mixed $pValue Value to validate.
     * @param string $pName Argument name.
     *
     * @return string The validated date-time.
     */
    public static function ValidateDateTime($pValue, string $pName): string
    {
        if (!is_string($pValue) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $pValue)) {
            self::ThrowInvalidArgument($pName, 'An ISO 8601 date-time is required.');
        }

        $parsed = date_parse($pValue);
        if ($parsed['error_count'] > 0 || $parsed['warning_count'] > 0) {
            self::ThrowInvalidArgument($pName, 'An ISO 8601 date-time is required.');
        }

        return $pValue;
    }

    /**
     * Validate common query parameters shared by product operations.
     *
     * @param array<string, mixed> $pQuery Query values.
     * @param array<int, string> $pAllowed Optional operation-specific allowlist.
     *
     * @return void
     */
    public static function ValidateQuery(array $pQuery, array $pAllowed = array()): void
    {
        foreach ($pQuery as $name => $value) {
            if (!empty($pAllowed) && !in_array($name, $pAllowed, true)) {
                self::ThrowInvalidArgument($name, 'This query parameter is not supported by the operation.');
            }

            switch ($name) {
                case 'fields':
                    self::ValidateNonEmptyString($value, $name);
                    break;
                case 'count':
                    self::ValidateIntegerRange($value, $name, 1, 50);
                    break;
                case 'offset':
                    self::ValidateIntegerRange($value, $name, 0);
                    break;
                case 'gateway':
                    self::ValidateEnum($value, array('paypal', 'stripe'), $name);
                    break;
                case 'from':
                case 'to':
                    self::ValidateDateTime($value, $name);
                    break;
                case 'billing_cycle':
                    self::ValidateEnum($value, array(0, 1, 12, '0', '1', '12'), $name);
                    break;
                case 'uid':
                    self::ValidateUid($value, $name);
                    break;
            }
        }
    }

    /**
     * Validate required body fields and their basic scalar types.
     *
     * @param array<string, mixed> $pData Request body.
     * @param array<int, string> $pRequired Required field names.
     * @param array<string, string> $pTypes Field type map.
     *
     * @return void
     */
    public static function ValidateRequestBody(array $pData, array $pRequired = array(), array $pTypes = array()): void
    {
        foreach ($pRequired as $name) {
            if (!array_key_exists($name, $pData) || null === $pData[$name]) {
                self::ThrowEmptyArgument($name, 'A required request-body field is missing.');
            }
        }

        foreach ($pTypes as $name => $type) {
            if (!array_key_exists($name, $pData)) {
                continue;
            }

            $valid = ('string' === $type && is_string($pData[$name])) ||
                ('int' === $type && is_int($pData[$name])) ||
                ('bool' === $type && is_bool($pData[$name])) ||
                ('array' === $type && is_array($pData[$name])) ||
                ('number' === $type && (is_int($pData[$name]) || is_float($pData[$name])));

            if (!$valid) {
                self::ThrowInvalidArgument($name, 'The request-body field has an invalid type.');
            }
        }
    }

    /**
     * Validate product deployment archive query parameters.
     *
     * @param array<string, mixed> $pQuery Download query values.
     *
     * @return void
     */
    public static function ValidateDeploymentDownloadQuery(array $pQuery): void
    {
        self::ValidateQuery($pQuery, array('is_premium'));
        if (array_key_exists('is_premium', $pQuery)) {
            self::ValidateBoolean($pQuery['is_premium'], 'is_premium');
        }
    }

    /**
     * Validate product coupon request fields.
     *
     * @param array<string, mixed> $pData Coupon data.
     *
     * @return void
     */
    public static function ValidateCouponData(array $pData): void
    {
        self::ValidateRequestBody(
            $pData,
            array(),
            array(
                'plans' => 'array',
                'licenses' => 'array',
                'billing_cycles' => 'array',
                'user_type' => 'string',
                'code' => 'string',
                'discount' => 'number',
                'discount_type' => 'string',
                'start_date' => 'string',
                'end_date' => 'string',
                'redemptions_limit' => 'int',
                'has_renewals_discount' => 'bool',
                'has_addons_discount' => 'bool',
                'is_one_per_user' => 'bool',
                'is_active' => 'bool',
            )
        );
        if (array_key_exists('code', $pData)) {
            self::ValidateNonEmptyString($pData['code'], 'code');
        }
    }

    /**
     * Validate a product coupon note request body.
     *
     * @param array<string, mixed> $pData Note data.
     *
     * @return void
     */
    public static function ValidateCouponNoteData(array $pData): void
    {
        self::ValidateRequestBody($pData, array('note'), array('note' => 'string'));
        self::ValidateNonEmptyString($pData['note'], 'note');
    }

    /**
     * Throw a structured invalid-argument exception.
     *
     * @param string $pName Argument name.
     * @param string $pMessage Error message.
     *
     * @return void
     */
    public static function ThrowInvalidArgument(string $pName, string $pMessage): void
    {
        throw new InvalidArgumentException(array(
            'error' => array(
                'message' => $pMessage,
                'type' => 'InvalidArgument',
                'field' => $pName,
            ),
        ));
    }

    /**
     * Throw a structured empty-argument exception.
     *
     * @param string $pName Argument name.
     * @param string $pMessage Error message.
     * @param string $pType Error type.
     *
     * @return void
     */
    public static function ThrowEmptyArgument(string $pName, string $pMessage, string $pType = 'EmptyArgument'): void
    {
        throw new EmptyArgumentException(array(
            'error' => array(
                'message' => $pMessage,
                'type' => $pType,
                'field' => $pName,
            ),
        ));
    }
}