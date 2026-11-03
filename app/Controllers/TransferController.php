<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Services\TransferService;
use Throwable;

/**
 * Transfer & Escrow REST Controller
 *
 * Exposes API endpoints for transfer initiation, cancellation grace window,
 * balances, pending holds, and external withdrawals.
 */
class TransferController
{
    private TransferService $transferService;

    public function __construct(?TransferService $transferService = null)
    {
        $this->transferService = $transferService ?? new TransferService();
    }

    /**
     * Resolve the active authenticated user ID.
     * Requires a valid PHP session or verified Bearer token from the sessions table.
     * Strictly rejects untrusted client headers and never defaults to a mock user.
     */
    protected function getAuthenticatedUserId(Request $request): ?int
    {
        ensure_session_started();
        if (!empty($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
            return (int) $_SESSION['user_id'];
        }

        // Bearer token authentication via database sessions table
        $authHeader = $request->header('authorization');
        if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $token = $matches[1];
            try {
                $sessionRow = Database::getInstance()->fetch(
                    'SELECT user_id FROM sessions WHERE id = ? AND expires_at > NOW()',
                    [$token]
                );
                if ($sessionRow && !empty($sessionRow['user_id'])) {
                    return (int) $sessionRow['user_id'];
                }
            } catch (Throwable) {
                // Database query error
            }
        }

        return null;
    }

    /**
     * POST /api/transfers/initiate
     *
     * Initiates an outgoing transfer. If amount <= per-token threshold,
     * balance is placed in escrow hold for the configured duration.
     */
    public function initiate(Request $request): Response
    {
        try {
            $userId = $this->getAuthenticatedUserId($request);
            if ($userId === null) {
                return Response::error('Authentication required. Please log in.', 401);
            }

            $token = (string) $request->input('token', '');
            $amount = (float) $request->input('amount', 0.0);
            $destination = (string) $request->input('destination', '');
            $note = $request->input('note') !== null ? (string) $request->input('note') : null;
            
            // Support both direct minutes or flexible value + unit (hours, days, months, years)
            $customMinutes = null;
            if ($request->input('duration_value') !== null && $request->input('duration_unit') !== null) {
                $val = (float) $request->input('duration_value');
                $unit = strtolower(trim((string) $request->input('duration_unit')));
                $customMinutes = (int) match ($unit) {
                    'year', 'years', 'y' => round($val * 525600),
                    'month', 'months', 'mo' => round($val * 43200),
                    'week', 'weeks', 'w' => round($val * 10080),
                    'day', 'days', 'd' => round($val * 1440),
                    'hour', 'hours', 'h' => round($val * 60),
                    default => round($val)
                };
            } elseif ($request->input('window_minutes') !== null) {
                $customMinutes = (int) $request->input('window_minutes');
            }

            if ($token === '' || $destination === '') {
                return Response::error('Missing required fields: token and destination are required.', 422);
            }

            if ($amount <= 0) {
                return Response::error('Amount must be greater than zero.', 422);
            }

            $result = $this->transferService->initiateTransfer(
                $userId,
                $token,
                $amount,
                $destination,
                $note,
                $customMinutes
            );

            return Response::success($result, $result['message'] ?? 'Transfer initiated successfully.');
        } catch (ValidationException $e) {
            return Response::error($e->getMessage(), 422, $e->getErrors());
        } catch (Throwable $e) {
            return Response::error('Failed to initiate transfer: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/transfers/cancel
     *
     * Cancels an active pending transfer during the undo hold window.
     */
    public function cancel(Request $request): Response
    {
        try {
            $userId = $this->getAuthenticatedUserId($request);
            if ($userId === null) {
                return Response::error('Authentication required. Please log in.', 401);
            }

            $transferId = (int) $request->input('transfer_id', 0);

            if ($transferId <= 0) {
                return Response::error('Valid transfer_id is required.', 422);
            }

            $result = $this->transferService->cancelTransfer($userId, $transferId);

            return Response::success($result, $result['message'] ?? 'Transfer cancelled.');
        } catch (ValidationException $e) {
            return Response::error($e->getMessage(), 422, $e->getErrors());
        } catch (Throwable $e) {
            return Response::error('Failed to cancel transfer: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/transfers/pending
     *
     * Returns list of currently active pending transfers with remaining countdown seconds.
     */
    public function pending(Request $request): Response
    {
        try {
            $userId = $this->getAuthenticatedUserId($request);
            if ($userId === null) {
                return Response::error('Authentication required. Please log in.', 401);
            }

            $isAdmin = (bool) $request->input('admin', false);

            $pending = $this->transferService->getPendingTransfers($userId, $isAdmin);

            return Response::success([
                'transfers' => $pending,
                'count' => count($pending),
            ], 'Active pending transfers retrieved.');
        } catch (Throwable $e) {
            return Response::error('Failed to fetch pending transfers: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/transfers/history
     *
     * Returns past transfer activity (completed, cancelled, failed).
     */
    public function history(Request $request): Response
    {
        try {
            $userId = $this->getAuthenticatedUserId($request);
            if ($userId === null) {
                return Response::error('Authentication required. Please log in.', 401);
            }

            $limit = max(1, min(100, (int) $request->input('limit', 50)));
            $offset = max(0, (int) $request->input('offset', 0));

            $history = $this->transferService->getTransferHistory($userId, $limit, $offset);

            return Response::success([
                'transfers' => $history,
                'count' => count($history),
                'limit' => $limit,
                'offset' => $offset,
            ], 'Transfer history retrieved.');
        } catch (Throwable $e) {
            return Response::error('Failed to fetch transfer history: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/transfers/balances
     *
     * Returns multi-token available and escrow balances for the user.
     */
    public function balances(Request $request): Response
    {
        try {
            $userId = $this->getAuthenticatedUserId($request);
            if ($userId === null) {
                return Response::error('Authentication required. Please log in.', 401);
            }

            // Automatically sync on-chain incoming deposits for this user
            try {
                $this->transferService->syncUserDeposits($userId);
            } catch (Throwable) {
                // Non-fatal if network/RPC is throttled
            }

            $balances = $this->transferService->getUserBalances($userId);
            $thresholds = $this->transferService->getThresholds();

            return Response::success([
                'user_id' => $userId,
                'balances' => $balances,
                'thresholds' => $thresholds,
            ], 'User balances retrieved.');
        } catch (Throwable $e) {
            return Response::error('Failed to fetch balances: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/transfers/sync
     *
     * Explicitly syncs confirmed on-chain deposits and returns updated balances.
     */
    public function sync(Request $request): Response
    {
        try {
            $userId = $this->getAuthenticatedUserId($request);
            if ($userId === null) {
                return Response::error('Authentication required. Please log in.', 401);
            }

            $credited = $this->transferService->syncUserDeposits($userId);
            $balances = $this->transferService->getUserBalances($userId);

            return Response::success([
                'credited' => $credited,
                'balances' => $balances,
            ], "Synced with Solana Devnet. {$credited} new deposit(s) credited.");
        } catch (Throwable $e) {
            return Response::error('Failed to sync deposits: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/transfers/withdraw
     *
     * Withdraws funds to an external Solana address, verifying 2FA if configured.
     */
    public function withdraw(Request $request): Response
    {
        try {
            $userId = $this->getAuthenticatedUserId($request);
            if ($userId === null) {
                return Response::error('Authentication required. Please log in.', 401);
            }

            $token = (string) $request->input('token', '');
            $amount = (float) $request->input('amount', 0.0);
            $destination = (string) $request->input('destination', '');
            $totpCode = $request->input('totp_code') !== null ? (string) $request->input('totp_code') : null;

            if ($token === '' || $destination === '') {
                return Response::error('Missing required fields: token and destination.', 422);
            }

            if ($amount <= 0) {
                return Response::error('Withdrawal amount must be greater than zero.', 422);
            }

            $result = $this->transferService->withdraw($userId, $token, $amount, $destination, $totpCode);

            return Response::success($result, 'Withdrawal broadcast successfully.');
        } catch (ValidationException $e) {
            return Response::error($e->getMessage(), 422, $e->getErrors());
        } catch (Throwable $e) {
            return Response::error('Withdrawal failed: ' . $e->getMessage(), 500);
        }
    }
}
