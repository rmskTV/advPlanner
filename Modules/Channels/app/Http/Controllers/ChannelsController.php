<?php

namespace Modules\Channels\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Channels\Repositories\ChannelRepository as Repository;
use Modules\Channels\Services\ChannelService as Service;

class ChannelsController extends Controller
{
    /**
     * Получение списка каналов
     *
     * @param  Service  $service  Сервис для работы со словарем
     * @param  Repository  $repository  Репозиторий для доступа к данным
     * @return JsonResponse JSON-ответ с данными
     *
     * @OA\Get(
     *     path="/api/channels",
     *     tags={"Core/Dictionary/Channels"},
     *     summary="Получение списка каналов",
     *     description="Метод для получения списка всех Каналов.",
     *
     *     @OA\Parameter(
     *           name="organisation_id",
     *           in="query",
     *           description="Идентификатор Организации (для фильтрации по организации)",
     *           required=false,
     *
     *           @OA\Schema(
     *               type="integer"
     *           )
     *       ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Успешный запрос. Возвращает список Каналов.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="current_page", type="integer"),
     *             @OA\Property(property="data", type="array",
     *
     *                 @OA\Items(
     *                     ref="#/components/schemas/Channel",
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
            'organisation_id' => 'nullable|integer|min:1',
            // Добавьте другие правила валидации для ваших параметров.
        ];
        $validator = Validator::make(request()->all(), $filters);
        $filters = $validator->validated();

        return $service->getAll($repository, $filters);
    }

    /**
     * Создание новой организации
     *
     * @param  Service  $service  Сервис для работы со словарем
     * @param  Repository  $repository  Репозиторий для доступа к данным
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Put(
     *     path="/api/channels",
     *     tags={"Core/Dictionary/Channels"},
     *     summary="Создание нового канала",
     *     description="Метод для создания нового канала.",
     *
     *     @OA\RequestBody(
     *         required=true,
     *         description="Данные для создания новой организации",
     *
     *         @OA\JsonContent(
     *             required={"name", "oarganisation_id"},
     *
     *             ref="#/components/schemas/ChannelRequest",
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
     *
     * @throws ValidationException
     */
    public function create(Service $service, Repository $repository): JsonResponse
    {
        $request = request()->post();

        $validator = Validator::make($request, [
            'name' => 'required|string|max:255',
            'organisation_id' => 'required|integer|exists:organisations,id',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toJson(), 200);
        }

        return $service->create($validator->validated(), $repository);
    }

    /**
     * Получение канала по идентификатору
     *
     * @OA\Get (
     *     path="/api/channels/{id}",
     *     tags={"Core/Dictionary/Channels"},
     *     summary="Получение информации о канале",
     *     description="Метод для получения информации о канале по его идентификатору",
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Идентификатор канала",
     *         required=true,
     *
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Успешный запрос. Возвращает информацию об организации.",
     *
     *         @OA\JsonContent(
     *                 ref="#/components/schemas/Channel",
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
     * Обновление данных канала
     *
     * @param  Service  $service  Сервис для работы со словарем
     * @param  Repository  $repository  Репозиторий для доступа к данным
     * @param  int  $id  Идентификатор канала
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Patch(
     *     path="/api/channels/{id}",
     *     tags={"Core/Dictionary/Channels"},
     *     summary="Обновление данных канала",
     *     description="Метод для обновления данных канала.",
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Идентификатор канала",
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
     *             ref="#/components/schemas/ChannelRequest",
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
        // TODO: Только администраторы
        $request = request()->post();

        $validator = Validator::make($request, [
            'name' => 'required|string|max:255',
            'organisation_id' => 'required|integer|exists:organisations,id',
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
     * Удаление канала
     *
     * @param  Service  $service  Сервис для работы со словарем
     * @param  Repository  $repository  Репозиторий для доступа к данным
     * @param  int  $id  Идентификатор канала
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Delete(
     *     path="/api/channels/{id}",
     *     tags={"Core/Dictionary/Channels"},
     *     summary="Удаление канала",
     *     description="Метод для удаления канала.",
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Идентификатор канала",
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
