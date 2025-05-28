<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;

/**
 * Logout
 *
 * @OA\Post (
 *     path="/api/auth/logout",
 *     tags={"Core/Auth"},
 *     security={{"bearerAuth": {}}},
 *
 *      @OA\Response(
 *          response=200,
 *          description="Success",
 *
 *          @OA\JsonContent(
 *
 *              @OA\Property(property="message", type="string", example="Successfully logged out"),
 *
 *          )
 *      ),
 *
 *      @OA\Response(
 *          response=401,
 *          description="Invalid credentials",
 *
 *          @OA\JsonContent(
 *
 *              @OA\Property(property="error", type="string", example="Unauthorized")
 *          )
 *      ),
 * )
 */
class LogoutController extends Controller
{
    public function __invoke(AuthService $authService): JsonResponse
    {
        return $authService->logout();
    }
}
