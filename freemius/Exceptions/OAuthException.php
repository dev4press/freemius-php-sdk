<?php

namespace Freemius\SDK\Exception;

/**
 * Thrown when an OAuth API call returns an exception.
 */
class OAuthException extends Exception
{
    /**
     * Make a new OAuth exception with the given result.
     *
     * @param array $pResult The result from the API server.
     */
    public function __construct(array $pResult)
    {
        parent::__construct($pResult);
    }
}