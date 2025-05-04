<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;

/**
 * Login
 *
 * @OA\Post (
 *     path="/api/auth/login",
 *     tags={"Core/Auth"},
 *
 *     @OA\RequestBody(
 *
 *         @OA\MediaType(
 *             mediaType="application/json",
 *
 *             @OA\Schema(
 *
 *                 @OA\Property(
 *                      type="object",
 *                      @OA\Property(
 *                          property="email",
 *                          type="string"
 *                      ),
 *                      @OA\Property(
 *                          property="password",
 *                          type="string"
 *                      )
 *                 ),
 *                 example={
 *                     "email":"john@test.com",
 *                     "password":"johnjohn1"
 *                }
 *             )
 *         )
 *      ),
 *
 *      @OA\Response(
 *          response=200,
 *          description="Success",
 *
 *          @OA\JsonContent(
 *
 *              @OA\Property(property="access_token", type="string", example="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwOi8vbG9jYWxob3N0L2FwaS9hdXRoL2xvZ2luIiwiaWF0IjoxNzE1MDY5MTcyLCJleHAiOjE3MTUwNzI3NzIsIm5iZiI6MTcxNTA2OTE3MiwianRpIjoiR0RqbmZ5dGhjUVJ1YUVpTyIsInN1YiI6IjMiLCJwcnYiOiIyM2JkNWM4OTQ5ZjYwMGFkYjM5ZTcwMWM0MDA4NzJkYjdhNTk3NmY3In0.2UjHloJNkPvzgKK8g0_5f8o0k_RlZkjy6Jaxzprj9wg"),
 *              @OA\Property(property="token_type", type="string", example="bearer"),
 *              @OA\Property(property="expires_in", type="string", example=3600),
 *              @OA\Property(property="user", type="type",   ref="#/components/schemas/User"),
 *
 *          )
 *      ),
 *
 *     @OA\Response(
 *           response=422,
 *           description="Validation error",
 *
 *           @OA\JsonContent(
 *
 *               @OA\Property(property="meta", type="object",
 *                   @OA\Property(property="code", type="number", example=422),
 *                   @OA\Property(property="status", type="string", example="error"),
 *                   @OA\Property(property="message", type="object",
 *                       @OA\Property(property="error", type="array", collectionFormat="multi",
 *
 *                         @OA\Items(
 *                           type="string",
 *                           example="Invalid user.",
 *                           )
 *                       ),
 *                   ),
 *               ),
 *
 *               @OA\Property(property="data", type="object", example={}),
 *           )
 *       )
 * )
 */
class LoginController extends Controller
{
    public function __invoke(AuthService $authService): JsonResponse
    {
        return $authService->login();
    }
}
