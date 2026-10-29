<?php

namespace yamaguchi\regionsync\services;

class ProductSnapshotException extends \RuntimeException
{
    public $statusCode;

    public function __construct($message, $statusCode = null)
    {
        $this->statusCode = $statusCode;
        parent::__construct($message);
    }
}
