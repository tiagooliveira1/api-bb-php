<?php

namespace Troliveira\ApiBbPhp\Exceptions;

use Troliveira\ApiBbPhp\Exceptions\HttpClientException;

class ServiceUnavailableException extends HttpClientException
{
    const HTTP_STATUS_CODE = 503;

    public function getStatusCode()
    {
        return self::HTTP_STATUS_CODE;
    }
}
