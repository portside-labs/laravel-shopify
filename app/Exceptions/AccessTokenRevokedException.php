<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when Shopify no longer honors the credentials held for a shop.
 */
class AccessTokenRevokedException extends RuntimeException
{
    //
}
