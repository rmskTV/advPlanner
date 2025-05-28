<?php

namespace Modules\AdvBlocks\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\AdvBlocks\Repositories\AdvBlockBroadcastingRepository as Repository;
use Modules\AdvBlocks\Services\AdvBlockBroadcastingService as Service;

class AdvBlockBroadcastingController extends Controller
{
    /**
     * Получение списка выходов рекламных блоков
     *
     * @param  Service  $service  Сервис для работы со словарем
     * @param  Repository  $repository  Репозиторий для доступа к данным
     * @return JsonResponse JSON-ответ с данными
     *
     * @OA\Get(
     *     path="/api/advBlocksBroadcasting",
     *     tags={"Core/Broadcasting/AdvBlocksBroadcasting"},
     *     summary="Получение списка выходов рекламных блоков.",
     *     description="Метод для получения списка типов рекламного блока.",
     *
     *     @OA\Response(
     *         response=200,
     *         description="Успешный запрос. Возвращает список типов рекламного блока.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="current_page", type="integer"),
     *             @OA\Property(property="data", type="array",
     *
     *                 @OA\Items(
     *                     ref="#/components/schemas/AdvBlockBroadcasting",
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
     * @throws ValidationException
     */
    public function index(Service $service, Repository $repository): JsonResponse
    {
        $filters = [
            'channel_id' => 'nullable|integer|min:1',
            'adv_block_id' => 'nullable|integer|min:1',
            'media_product_id' => 'nullable|integer|min:1',
            'broadcast_at_from' => 'nullable|date', // Фильтр "от"
            'broadcast_at_to' => 'nullable|date',   // Фильтр "до"

        ];
        $validator = Validator::make(request()->all(), $filters);
        $filters = $validator->validated();

        return $service->getAll($repository, $filters);
    }

    /**
     * Добавление типа рекламного блока
     *
     * @param  Service  $service  Сервис для работы со словарем
     * @param  Repository  $repository  Репозиторий для доступа к данным
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Put(
     *     path="/api/advBlocksBroadcasting",
     *     tags={"Core/Broadcasting/AdvBlocksBroadcasting"},
     *     summary="Создание нового выхода рекламного блока",
     *     description="Метод для создания выхода рекламного блока.",
     *
     *     @OA\RequestBody(
     *         required=true,
     *         description="Данные для создания новой записи",
     *
     *         @OA\JsonContent(
     *             required={"adv_block_id", "broadcast_at"},
     *
     *             ref="#/components/schemas/AdvBlockBroadcastingRequest",
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

        $request = request()->post();

        $validator = Validator::make($request, [
            'broadcast_at' => 'required|date_format:Y-m-d H:i:s',
            'adv_block_id' => 'required|exists:adv_blocks,id',
            'size' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toJson(), 200);
        }

        return $service->create($request, $repository);
    }

    /**
     * Получение типа рекламного блока по идентификатору
     *
     * @OA\Get (
     *     path="/api/advBlocksBroadcasting/{id}",
     *     tags={"Core/Broadcasting/AdvBlocksBroadcasting"},
     *     summary="Получение информации о выходе  рекламного блока",
     *     description="Метод для получения информации о выходе рекламного блока по его идентификатору",
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Идентификатор записи",
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
     *                 ref="#/components/schemas/AdvBlockBroadcasting",
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
     * Обновление данных типа рекламного блока
     *
     * @param  Service  $service  Сервис для работы со словарем
     * @param  Repository  $repository  Репозиторий для доступа к данным
     * @param  int  $id  Идентификатор типа рекламного блока
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Patch(
     *     path="/api/advBlocksBroadcasting/{id}",
     *     tags={"Core/Broadcasting/AdvBlocksBroadcasting"},
     *     summary="Обновление данных типа рекламного блока",
     *     description="Метод для обновления данных типа рекламного блока.",
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
     *             ref="#/components/schemas/AdvBlockBroadcastingRequest",
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
     *
     * @throws ValidationException
     */
    public function update(Service $service, Repository $repository, int $id): JsonResponse
    {
        // TODO: Авторизация: Обновлять могут только администраторы
        $request = request()->post();

        $validator = Validator::make($request, [
            'broadcast_at' => 'required|date_format:Y-m-d H:i:s',
            'adv_block_id' => 'required|exists:adv_blocks,id',
            'size' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toJson(), 200);
        }

        if (count($validator->validated()) == 0) {
            return response()->json(true, 200);
        }

        return $service->update($validator->validated(), $repository, $id);
    }

    /**
     * Удаление типа рекламного блока
     *
     * @param  Service  $service  Сервис для работы со словарем
     * @param  Repository  $repository  Репозиторий для доступа к данным
     * @param  int  $id  Идентификатор канала
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Delete(
     *     path="/api/advBlocksBroadcasting/{id}",
     *     tags={"Core/Broadcasting/AdvBlocksBroadcasting"},
     *     summary="Удаление выхода реклмного блока",
     *     description="Метод для удаления выхода рекламного блока.",
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Идентификатор типа рекламного блока",
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
