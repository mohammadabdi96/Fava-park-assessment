<?php

namespace Fava\Core;

/** Thrown to stop a request with a specific HTTP status and a message that is safe to show. */
class HttpException extends \RuntimeException
{
    /** @var int */
    public $status;

    public function __construct(int $status, string $message = '')
    {
        parent::__construct($message, $status);
        $this->status = $status;
    }
}
