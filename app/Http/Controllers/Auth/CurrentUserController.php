<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use OpenApi\Annotations as OA;

class CurrentUserController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/auth/me",
     *     summary="Get authenticated user information",
     *     tags={"Core/Auth"},
     *     security={{"bearerAuth": {}}},
     *     description="Returns information about the currently authenticated user. Requires valid JWT token obtained via /api/auth/login",
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *
     *         @OA\JsonContent(
     *              ref="#/components/schemas/User",
     *         )
     *     ),
     *
     *     @OA\Response(
     *          response=401,
     *          description="Unauthorized",
     *          @OA\JsonContent(
     *              @OA\Examples(
     *                  example="unauthorized",
     *                  value={
     *                      "message": "Unauthenticated."
     *                  },
     *                  summary="Invalid or missing token"
     *              )
     *          )
     *      ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Unprocessable Entity",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Validation error")
     *         )
     *     )
     * )
     */
    public function __invoke(AuthService $authService): JsonResponse
    {
        return $authService->me();
    }
}
