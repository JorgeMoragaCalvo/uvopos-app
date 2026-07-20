<?php

namespace App\Rules;

use App\Support\Rut;
use Illuminate\Contracts\Validation\Rule;

class ValidRut implements Rule
{
    public function passes($attribute, $value): bool
    {
        return is_string($value) && Rut::isValid($value);
    }

    public function message(): string
    {
        return 'El RUT ingresado no es válido.';
    }
}
