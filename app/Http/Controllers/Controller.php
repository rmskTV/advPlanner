<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * @OA\Info(
 *     title="Сервис планирования и размещения рекламы",
 *     version="0.1",
 *
 *      @OA\Contact(
 *          email="ruslanmoskvitin@gmail.com"
 *      ),
 * ),
 *
 *  @OA\SecurityScheme(
 *       type="http",
 *       scheme="bearer",
 *       securityScheme="bearerAuth",
 *       bearerFormat="JWT",
 *       description="Authenticate using JWT token obtained from /api/auth/login endpoint"
 *   )
 * @OA\Server(
 *         url="/",
 *         description="localhost"
 *    )
 * @OA\Server(
 *       url="https://advider.bst.bratsk.ru",
 *       description="Product server"
 *   )
 * @OA\Server(
 *        url="https://advider-test.bst.bratsk.ru",
 *        description="Test server"
 *   )
 */
class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}
