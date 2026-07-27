<?php

namespace App\Support\Cartola;

use RuntimeException;

/**
 * A cartola that could not be read. The message is shown to staff as-is,
 * so it is written in Spanish and says what to do about it.
 */
class CartolaException extends RuntimeException
{
}
