<?php

namespace Freemius\SDK\Exceptions;

/**
 * Thrown when an API call returns an exception.
 */
class Exception extends \Exception
{
    /** @var array */
    protected array $_result;

    /** @var string */
    protected string $_type;

    /** @var string */
    protected string $_code;

    /**
     * Make a new API exception with the given result.
     *
     * @param array $result The result from the API server.
     */
    public function __construct(array $result)
    {
        $this->_result = $result;

        $code = 0;
        $message = 'Unknown error, please check GetResult().';
        $type = '';

        if (isset($result['error']) && is_array($result['error'])) {
            if (isset($result['error']['code']))
                $code = $result['error']['code'];
            if (isset($result['error']['message']))
                $message = $result['error']['message'];
            if (isset($result['error']['type']))
                $type = $result['error']['type'];
        }

        $this->_type = $type;
        $this->_code = $code;

        parent::__construct($message, is_numeric($code) ? $code : 0);
    }

    /**
     * Return the associated result object returned by the API server.
     *
     * @return array The result from the API server.
     */
    public function GetResult(): array
    {
        return $this->_result;
    }

    /**
     * Return the API error code as a string.
     *
     * @return string The API error code.
     */
    public function GetStringCode(): string
    {
        return $this->_code;
    }

    /**
     * Return the API error type.
     *
     * @return string The API error type.
     */
    public function GetType(): string
    {
        return $this->_type;
    }

    /**
     * To make debugging easier.
     *
     * @return string The string representation of the error.
     */
    public function __toString(): string
    {
        $str = $this->GetType() . ': ';

        if ($this->code != 0)
            $str .= $this->GetStringCode() . ': ';

        return $str . $this->getMessage();
    }
}