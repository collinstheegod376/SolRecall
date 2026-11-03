<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Base Application Controller
 *
 * Provides shared helpers for view rendering, JSON formatting,
 * validation routines, and redirects.
 */
abstract class Controller
{
    /**
     * Return formatted JSON response.
     */
    protected function jsonResponse(mixed $data, int $status = 200, array $headers = []): Response
    {
        return Response::json($data, $status, $headers);
    }

    /**
     * Standard success JSON response.
     */
    protected function jsonSuccess(mixed $data = null, string $message = 'Success', int $status = 200): Response
    {
        return Response::success($data, $message, $status);
    }

    /**
     * Standard error JSON response.
     */
    protected function jsonError(string $message, int $status = 400, array $errors = []): Response
    {
        return Response::error($message, $status, $errors);
    }

    /**
     * Render a PHP view template file into an HTML response.
     */
    protected function render(string $viewPath, array $data = []): Response
    {
        // Support paths with or without .php extension
        $cleanPath = str_replace('.', '/', ltrim($viewPath, '/\\'));
        $file = base_path("views/{$cleanPath}.php");

        if (!file_exists($file)) {
            $file = base_path("app/Views/{$cleanPath}.php");
        }

        if (!file_exists($file)) {
            throw new RuntimeException("View template not found: {$cleanPath}");
        }

        extract($data, EXTR_SKIP);

        ob_start();
        include $file;
        $content = (string) ob_get_clean();

        return Response::html($content);
    }

    /**
     * Create an HTTP redirect response.
     */
    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    /**
     * Validate incoming request data against defined rules.
     *
     * Supported rules:
     * - required
     * - string
     * - numeric
     * - email
     * - min:X
     * - max:X
     * - in:val1,val2
     * - regex:/pattern/
     * - solana_address
     *
     * @throws ValidationException
     */
    protected function validate(Request $request, array $rules): array
    {
        $input = $request->all();
        $errors = [];
        $validated = [];

        foreach ($rules as $field => $fieldRules) {
            if (is_string($fieldRules)) {
                $fieldRules = explode('|', $fieldRules);
            }

            $value = $input[$field] ?? null;
            $hasValue = $value !== null && $value !== '';

            foreach ($fieldRules as $rule) {
                $params = [];
                if (str_contains($rule, ':')) {
                    [$rule, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }

                $rule = trim(strtolower($rule));

                if ($rule === 'required') {
                    if (!$hasValue) {
                        $errors[$field][] = "The {$field} field is required.";
                        break;
                    }
                }

                // If not required and empty, skip remaining validations
                if (!$hasValue) {
                    continue;
                }

                if ($rule === 'string') {
                    if (!is_string($value)) {
                        $errors[$field][] = "The {$field} must be a string.";
                    }
                } elseif ($rule === 'numeric') {
                    if (!is_numeric($value)) {
                        $errors[$field][] = "The {$field} must be a number.";
                    }
                } elseif ($rule === 'email') {
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[$field][] = "The {$field} must be a valid email address.";
                    }
                } elseif ($rule === 'min') {
                    $min = (float) ($params[0] ?? 0);
                    if (is_numeric($value)) {
                        if ((float) $value < $min) {
                            $errors[$field][] = "The {$field} must be at least {$min}.";
                        }
                    } else {
                        if (mb_strlen((string) $value) < $min) {
                            $errors[$field][] = "The {$field} must be at least {$min} characters.";
                        }
                    }
                } elseif ($rule === 'max') {
                    $max = (float) ($params[0] ?? 0);
                    if (is_numeric($value)) {
                        if ((float) $value > $max) {
                            $errors[$field][] = "The {$field} must not exceed {$max}.";
                        }
                    } else {
                        if (mb_strlen((string) $value) > $max) {
                            $errors[$field][] = "The {$field} must not exceed {$max} characters.";
                        }
                    }
                } elseif ($rule === 'in') {
                    if (!in_array((string) $value, $params, true)) {
                        $errors[$field][] = "The selected {$field} is invalid. Allowed: " . implode(', ', $params);
                    }
                } elseif ($rule === 'solana_address') {
                    // Solana base58 public keys are 32-44 characters of base58 charset (no 0, O, I, l)
                    if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', (string) $value)) {
                        $errors[$field][] = "The {$field} must be a valid Solana address.";
                    }
                }
            }

            if (empty($errors[$field])) {
                $validated[$field] = $value;
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $validated;
    }
}
