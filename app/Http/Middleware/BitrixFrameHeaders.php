<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BitrixFrameHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Разрешаем встраивание в iframe Битрикс24
        $response->headers->set('X-Frame-Options', 'ALLOW-FROM https://bratsk.bitrix24.ru');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self' https://*.bitrix24.ru https://bratsk.bitrix24.ru");
        $response->headers->remove('X-Frame-Options'); // Удаляем, т.к. CSP важнее

        return $response;
    }
}
