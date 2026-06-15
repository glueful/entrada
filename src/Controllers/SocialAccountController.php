<?php

declare(strict_types=1);

namespace Glueful\Extensions\Entrada\Controllers;

use Glueful\Http\Response;
use Glueful\Database\Connection;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
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
     * Get connected social accounts for authenticated user.
     */
    #[ApiOperation(
        summary: 'Get Connected Social Accounts',
        description: 'Retrieve all social accounts connected to the authenticated user.',
        tags: ['Social Account Management'],
    )]
    #[ApiResponse(200, description: 'Successfully retrieved social accounts')]
    #[ApiResponse(401, description: 'Unauthorized - User is not authenticated')]
    #[ApiResponse(500, description: 'Server error retrieving social accounts')]
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
     * Unlink a social account.
     */
    #[ApiOperation(
        summary: 'Unlink Social Account',
        description: 'Remove a social provider connection from the authenticated user.',
        tags: ['Social Account Management'],
    )]
    #[ApiResponse(200, description: 'Successfully unlinked social account')]
    #[ApiResponse(401, description: 'Unauthorized - User is not authenticated')]
    #[ApiResponse(404, description: 'Social account not found or not owned by user')]
    #[ApiResponse(500, description: 'Server error unlinking social account')]
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
