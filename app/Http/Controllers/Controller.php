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
 */

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;
}

