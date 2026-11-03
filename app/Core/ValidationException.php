<?php

declare(strict_types=1);

namespace App\Core;

use Exception;

/**
 * Validation Exception
 *
 * Thrown when request data fails input validation rules.
 */
class ValidationException extends Exception
{
    private array $errors;

    public function __construct(array $errors, string $message = 'The given data was invalid.', int $code = 422)
    {
        parent::__construct($message, $code);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
