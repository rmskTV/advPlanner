<?php

namespace Modules\MediaProducts\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Modules\MediaProducts\Repositories\MediaProductRepository as Repository;
use Modules\MediaProducts\Services\MediaProductService as Service;

class MediaProductsController extends Controller
{
    /**
     * Получение списка медиапродуктов по ID канала
     *
     * @param  Service  $service  Сервис для работы со словарем
     * @param  Repository  $repository  Репозиторий для доступа к данным
     * @return JsonResponse JSON-ответ с данными
     *
     * @OA\Get(
     *     path="/api/mediaProducts/byChannel/{channel_id}",
     *     tags={"Core/Dictionary/MediaProducts"},
     *     summary="Получение списка медиапродуктов по ID канала",
     *     description="Метод для получения списка списка медиапродуктов по ID канала.",
     *
     *     @OA\Parameter(
     *          name="channel_id",
     *          in="path",
     *          description="Идентификатор канала",
     *          required=true,
     *
     *          @OA\Schema(
     *              type="integer"
     *          )
     *      ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Успешный запрос. Возвращает список медиапродуктов.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="current_page", type="integer"),
     *             @OA\Property(property="data", type="array",
     *
     *                 @OA\Items(
     *                     ref="#/components/schemas/MediaProduct",
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
     */
    public function index(Service $service, Repository $repository, int $channel_id): JsonResponse
    {
        return $service->getAll($repository, $channel_id);
    }

    /**
     * Добавление медиапродукта
     *
     * @param  Service  $service  Сервис для работы со словарем
     * @param  Repository  $repository  Репозиторий для доступа к данным
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Put(
     *     path="/api/mediaProducts",
     *     tags={"Core/Dictionary/MediaProducts"},
     *     summary="Создание нового медиапродукта",
     *     description="Метод для создания нового медиапродукта.",
     *
     *     @OA\RequestBody(
     *         required=true,
     *         description="Данные для создания новой записи",
     *
     *         @OA\JsonContent(
     *             required={"name", "channel_id"},
     *
     *             ref="#/components/schemas/MediaProductRequest",
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Успешное создание",
     *
     *         @OA\JsonContent(
     *             type="boolean",
     *             example=true
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=400,
     *         description="Неверный запрос",
     *
     *         @OA\JsonContent(
     *             example={"message": "Неверный запрос"}
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Внутренняя ошибка сервера",
     *
     *         @OA\JsonContent(
     *             example={"message": "Внутренняя ошибка сервера"}
     *         ),
     *     )
     * )
     */
    public function create(Service $service, Repository $repository): JsonResponse
    {
        // TODO: Авторизация: Добавлять могут только администраторы
        $request = request()->post();

        $validator = Validator::make($request, [
            'start_time' => 'date_format:H:i:s',
            'duration' => 'integer|between:0,1440',
            'name' => 'required',
            'channel_id' => 'required|integer|exists:channels,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toJson(), 200);
        }

        return $service->create($request, $repository);
    }

    /**
     * Получение медиапродукта по идентификатору
     *
     * @OA\Get (
     *     path="/api/mediaProducts/{id}",
     *     tags={"Core/Dictionary/MediaProducts"},
     *     summary="Получение информации о медиапродукте",
     *     description="Метод для получения информации о медиапродукте по его идентификатору",
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Идентификатор медиапродукта",
     *         required=true,
     *
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Успешный запрос. Возвращает информацию о медиапродукте.",
     *
     *         @OA\JsonContent(
     *                 ref="#/components/schemas/MediaProduct",
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Запись не найдена",
     *
     *         @OA\JsonContent(
     *             example={"message": "Запись не найдена"}
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
     */
    public function show(Service $service, Repository $repository, int $id): JsonResponse
    {
        return $service->getById($repository, $id);
    }

    /**
     * Обновление данных медиапродукта
     *
     * @param  Service  $service  Сервис для работы со словарем
     * @param  Repository  $repository  Репозиторий для доступа к данным
     * @param  int  $id  Идентификатор медиапродукта
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Patch(
     *     path="/api/mediaProducts/{id}",
     *     tags={"Core/Dictionary/MediaProducts"},
     *     summary="Обновление данных медиапродукта",
     *     description="Метод для обновления данных медиапродукта.",
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Идентификатор обновляемой записи",
     *         required=true,
     *
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *
     *     @OA\RequestBody(
     *         required=false,
     *         description="Данные для обновления канала",
     *
     *         @OA\JsonContent(

     *             ref="#/components/schemas/MediaProductRequest",
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Успешное обновление данных",
     *
     *         @OA\JsonContent(
     *             type="boolean",
     *             example=true
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Запрос выполнен, но ничего не обновлено",
     *
     *         @OA\JsonContent(
     *             example={"message": "Запрос выполнен, но ничего не обновлено"}
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Внутренняя ошибка сервера",
     *
     *         @OA\JsonContent(
     *             example={"message": "Внутренняя ошибка сервера"}
     *         ),
     *     )
     * )
     */
    public function update(Service $service, Repository $repository, int $id): JsonResponse
    {
        // TODO: Авторизация: Обновлять могут только администраторы
        $request = request()->post();

        $validator = Validator::make($request, [
            'start_time' => 'date_format:H:i:s',
            'duration' => 'integer|between:0,1440',
            'name' => 'string',
            'channel_id' => 'integer|exists:channels,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toJson(), 200);
        }

        if (! $request || count($request) == 0) {
            return response()->json(true, 200);
        }

        return $service->update($request, $repository, $id);
    }

    /**
     * Удаление медиапродукта
     *
     * @param  Service  $service  Сервис для работы со словарем
     * @param  Repository  $repository  Репозиторий для доступа к данным
     * @param  int  $id  Идентификатор канала
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Delete(
     *     path="/api/mediaProducts/{id}",
     *     tags={"Core/Dictionary/MediaProducts"},
     *     summary="Удаление медиапродукта",
     *     description="Метод для удаления медиапродукта.",
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Идентификатор медаипродукта",
     *         required=true,
     *
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Успешное удаление канала",
     *
     *         @OA\JsonContent(
     *             type="boolean",
     *             example=true
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Внутренняя ошибка сервера",
     *
     *         @OA\JsonContent(
     *             example={"message": "Внутренняя ошибка сервера"}
     *         ),
     *     )
     * )
     */
    public function destroy(Service $service, Repository $repository, int $id): JsonResponse
    {
        // TODO: Авторизация: Удалять могут только администраторы
        return $service->delete($repository, $id);
    }
}
