<?php

namespace Corbpie\BunnyCdn;

use Exception;

class BunnyAPIException extends exception
{
    public function errorMessage()
    {//Error message
        return "Error on line {$this->getLine()} in {$this->getFile()}. {$this->getMessage()}.";
    }
}
