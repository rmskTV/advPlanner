<?php

namespace App\Http\Controllers;

use App\Services\AccountingUnitsService as Service;
use Illuminate\Http\JsonResponse;

class AccountingUnitsController extends Controller
{
    /**
     * Получение списка единиц учета
     *
     * @return JsonResponse JSON-ответ с данными о ВебКреативах
     *
     * @OA\Get(
     *     path="/api/accountingUnits",
     *     tags={"Core/Enum/AccountingUnits"},
     *     summary="Получение списка единиц учета",
     *     description="Метод для получения списка единиц учета.",
     *
     *     @OA\Response(
     *         response=200,
     *         description="Успешный запрос. Возвращает список единиц учета.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="current_page", type="integer"),
     *             @OA\Property(property="data", type="array",
     *
     *                 @OA\Items(
     *                     ref="#/components/schemas/AccountingUnit",
     *                 )
     *             ),
     *
     *             @OA\Property(property="first_page_url", type="string"),
     *             @OA\Property(property="from", type="integer"),
     *             @OA\Property(property="last_page", type="integer"),
     *             @OA\Property(property="last_page_url", type="string"),
     *             @OA\Property(property="links", type="array",
     *
     *                 @OA\Items(
     *
     *                     @OA\Property(property="url", type="string", nullable=true),
     *                     @OA\Property(property="label", type="string"),
     *                     @OA\Property(property="active", type="boolean"),
     *                 )
     *             ),
     *             @OA\Property(property="next_page_url", type="string", nullable=true),
     *             @OA\Property(property="path", type="string"),
     *             @OA\Property(property="per_page", type="integer"),
     *             @OA\Property(property="prev_page_url", type="string", nullable=true),
     *             @OA\Property(property="to", type="integer"),
     *             @OA\Property(property="total", type="integer"),
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Записи не найдены",
     *
     *         @OA\JsonContent(
     *             example={"message": "Записи не найдены"}
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Внутренняя ошибка сервера",
     *
     *         @OA\JsonContent(
     *             example={"message": "Внутренняя ошибка сервера"}
     *         )
     *     )
     * )
     *
     * @OA\Schema(
     *       schema="AccountingUnit",
     *
     *       @OA\Property(property="id", type="integer", example="14", description="ID единицы измерения"),
     *       @OA\Property(property="name", type="string", example="site.ru", description="Название единицы измерения"),
     *  )
     */
    public function index(Service $service): JsonResponse
    {
        return $service->getAll();
    }
}
