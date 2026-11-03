<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use Closure;

/**
 * Require2FAMiddleware
 *
 * Ensures that the authenticated user has enabled Two-Factor Authentication (2FA)
 * before accessing sensitive operations such as withdrawals or custodial key export.
 */
class Require2FAMiddleware
{
    private AuthService $authService;

    public function __construct(?AuthService $authService = null)
    {
        $this->authService = $authService ?? new AuthService();
    }

    /**
     * Handle incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->authService->getCurrentUser();

        // If not logged in, delegate to 401
        if ($user === null) {
            $path = $request->getPath();
            $isApi = str_starts_with($path, '/api/') || $request->expectsJson();
            if ($isApi) {
                return Response::error('Unauthorized. Authentication is required.', 401);
            }
            return Response::redirect('/login.php');
        }

        // Check if 2FA is active
        if (!$user->hasTwoFactor()) {
            $isApi = str_starts_with($request->getPath(), '/api/') || $request->expectsJson();
            if ($isApi) {
                return Response::error(
                    'Two-Factor Authentication (2FA) is required for this operation. Please configure 2FA in account settings.',
                    403,
                    ['two_factor_required' => true]
                );
            }
            return Response::redirect('/dashboard.php#settings');
        }

        return $next($request);
    }
}
