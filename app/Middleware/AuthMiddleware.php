<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use Closure;

/**
 * AuthMiddleware
 *
 * Enforces active authenticated session.
 * Returns 401 Unauthorized for API/JSON calls or redirects to /login.php for browser visits.
 */
class AuthMiddleware
{
    private AuthService $authService;

    public function __construct(?AuthService $authService = null)
    {
        $this->authService = $authService ?? new AuthService();
    }

    /**
     * Handle incoming request before passing to controller action.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->authService->getCurrentUser();

        if ($user === null) {
            $path = $request->getPath();
            $isApi = str_starts_with($path, '/api/') || $request->expectsJson();

            if ($isApi) {
                return Response::error('Unauthorized. You must be logged in to access this endpoint.', 401);
            }

            return Response::redirect('/login.php');
        }

        return $next($request);
    }
}
