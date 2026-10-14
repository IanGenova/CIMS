<?php

namespace App\utils;

/** Something Xendit refused or could not do, in words for the cashier. */
class XenditException extends \RuntimeException
{
    public $errorCode;

    public function __construct($message, $errorCode = null)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }
}
