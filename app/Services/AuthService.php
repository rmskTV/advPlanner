<?php

namespace App\Services;

use App\Repository\UserRepository;
use Illuminate\Http\JsonResponse;

class AuthService
{
    public function registration(UserRepository $userRepository): JsonResponse
    {
        $data = [
            'name' => request()->name,
            'email' => request()->email,
            'password' => bcrypt(request()->password),
        ];

        return response()->json($userRepository->create($data), 201);
    }

    /**
     * Авторизация пользователя
     */
    public function login(): JsonResponse
    {
        $credentials = request(['email', 'password']);
        if (! $token = auth()->attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $this->respondWithToken($token);
    }

    /**
     * Выход пользователя из системы
     */
    public function logout(): JsonResponse
    {
        auth()->logout();

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Обновить токен пользователю
     */
    public function refreshToken(): JsonResponse
    {
        return $this->respondWithToken(auth()->refresh());
    }

    /**
     * Данные о текущем пользователе
     */
    public function me(): JsonResponse
    {
        return response()->json(auth()->user());
    }

    /**
     * Get the token array structure.
     */
    private function respondWithToken(string $token): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => env('SESSION_LIFETIME', auth()->factory()->getTTL()) * 60,
            'user' => auth()->user()
        ]);
    }

}
