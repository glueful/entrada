<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Controllers;

use Glueful\Http\Response;
use Glueful\Database\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Social Account Controller
 *
 * Handles user social account management:
 * - List connected social accounts
 * - Unlink social accounts
 */
class SocialAccountController
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Get connected social accounts for authenticated user
     *
     * @route GET /user/social-accounts
     */
    public function index(Request $request): Response
    {
        try {
            $userData = $request->attributes->get('user');

            if (!$userData || !isset($userData['uuid'])) {
                return Response::unauthorized('Unauthorized');
            }

            $userUuid = $userData['uuid'];

            $accounts = $this->db->table('social_accounts')
                ->select(['uuid', 'provider', 'created_at', 'updated_at'])
                ->where(['user_uuid' => $userUuid])
                ->get();

            return Response::success($accounts, 'Social accounts retrieved successfully');
        } catch (\Exception $e) {
            error_log('[entrada] Failed to retrieve social accounts: ' . $e->getMessage());
            return Response::serverError('Failed to retrieve social accounts');
        }
    }

    /**
     * Unlink a social account
     *
     * @route DELETE /user/social-accounts/{uuid}
     */
    public function destroy(Request $request, string $uuid): Response
    {
        try {
            $userData = $request->attributes->get('user');

            if (!$userData || !isset($userData['uuid'])) {
                return Response::unauthorized('Unauthorized');
            }

            $userUuid = $userData['uuid'];

            $account = $this->db->table('social_accounts')
                ->select(['uuid'])
                ->where([
                    'uuid' => $uuid,
                    'user_uuid' => $userUuid
                ])
                ->limit(1)
                ->get();

            if (empty($account)) {
                return Response::notFound('Social account not found or not owned by user');
            }

            $deleted = $this->db->table('social_accounts')
                ->where([
                    'uuid' => $uuid,
                    'user_uuid' => $userUuid
                ])
                ->delete();

            if (!$deleted) {
                return Response::serverError('Failed to unlink social account');
            }

            return Response::success(null, 'Social account unlinked successfully');
        } catch (\Exception $e) {
            error_log('[entrada] Failed to unlink social account: ' . $e->getMessage());
            return Response::serverError('Failed to unlink social account');
        }
    }
}
