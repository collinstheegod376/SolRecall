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

    public function __construct(string|array $messageOrErrors, array|string $errorsOrMessage = [], int $code = 422)
    {
        if (is_array($messageOrErrors)) {
            $message = is_string($errorsOrMessage) ? $errorsOrMessage : 'The given data was invalid.';
            $errors = $messageOrErrors;
        } else {
            $message = $messageOrErrors;
            $errors = is_array($errorsOrMessage) ? $errorsOrMessage : ['error' => $errorsOrMessage];
        }
        parent::__construct($message, $code);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
