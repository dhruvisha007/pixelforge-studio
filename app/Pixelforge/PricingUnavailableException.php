<?php

namespace App\Pixelforge;

use RuntimeException;

final class PricingUnavailableException extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Pixelforge pricing is not available right now.', 0, $previous);
    }
}
