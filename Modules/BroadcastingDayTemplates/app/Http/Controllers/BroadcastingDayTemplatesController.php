<?php

namespace Modules\BroadcastingDayTemplates\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\BroadcastingDayTemplates\Repositories\BroadcastingDayTemplateRepository  as Repository;
use Modules\BroadcastingDayTemplates\Services\BroadcastingDayTemplateService as Service;

class BroadcastingDayTemplatesController extends Controller
{
    /**
     * Получение шаблонов вещания
     *
     * @param Service $service Сервис для работы со словарем
     * @param Repository $repository Репозиторий для доступа к данным
     * @return JsonResponse JSON-ответ с данными
     *
     * @OA\Get(
     *     path="/api/broadcastingDayTemplates",
     *     tags={"Core/Broadcasting/DayTemplates"},
     *     summary="Получение списка шаблонов вещнания.",
     *     description="Метод для получения списка шаблонов вещнания.",
     *
     *     @OA\Response(
     *         response=200,
     *         description="Успешный запрос. Возвращает список.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="current_page", type="integer"),
     *             @OA\Property(property="data", type="array",
     *
     *                 @OA\Items(
     *                     ref="#/components/schemas/BroadcastingDayTemplate",
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
     * @throws ValidationException
     */
    public function index(Service $service, Repository $repository): JsonResponse
    {
        $filters = [
            'channel_id' => 'nullable|integer|min:1',
        ];
        $validator = Validator::make(request()->all(), $filters);
        $filters = $validator->validated();

        return $service->getAll($repository, $filters);
    }



    /**
     * Добавление шаблона вещания
     *
     * @param Service $service  Сервис для работы со словарем
     * @param Repository $repository  Репозиторий для доступа к данным
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Put(
     *     path="/api/broadcastingDayTemplates",
     *     tags={"Core/Broadcasting/DayTemplates"},
     *     summary="Создание нового шаблолна вещания",
     *     description="Метод для создания нового шаблона вещания.",
     *
     *     @OA\RequestBody(
     *         required=true,
     *         description="Данные для создания новой записи",
     *
     *         @OA\JsonContent(
     *             required={"name", "channel_id", "start_hour", "comment"},
     *
     *             ref="#/components/schemas/BroadcastingDayTemplateRequest",
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
            'name' => 'required|string|max:255',
            'comment' => 'string|nullable|max:255',
            'channel_id' => 'required|integer|exists:channels,id',
            'start_hour' => 'required|integer|min:0|max:23',
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
     *     path="/api/broadcastingDayTemplates/{id}",
     *     tags={"Core/Broadcasting/DayTemplates"},
     *     summary="Получение информации о шаблоне вещания",
     *     description="Метод для получения информации о шаблоне вещания",
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
     *                 ref="#/components/schemas/BroadcastingDayTemplate",
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
     * Обновление данных шаблона вещания
     *
     * @param Service $service  Сервис для работы со словарем
     * @param Repository $repository  Репозиторий для доступа к данным
     * @param  int  $id  Идентификатор рекламного блока
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Patch(
     *     path="/api/broadcastingDayTemplates/{id}",
     *     tags={"Core/Broadcasting/DayTemplates"},
     *     summary="Обновление данных шаблона вещания",
     *     description="Метод для обновления данных шаблона вещания.",
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
     *             ref="#/components/schemas/BroadcastingDayTemplateRequest",
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

        $request = request()->post();

        $validator = Validator::make($request, [
            'name' => 'required|string|max:255',
            'comment' => 'string|nullable|max:255',
            'channel_id' => 'required|integer|exists:channels,id',
            'start_hour' => 'required|integer|min:0|max:23',
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
     * Удаление рекламного блока
     *
     * @param Service $service  Сервис для работы со словарем
     * @param Repository $repository  Репозиторий для доступа к данным
     * @param  int  $id  Идентификатор шаблона
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Delete(
     *     path="/api/broadcastingDayTemplates/{id}",
     *     tags={"Core/Broadcasting/DayTemplates"},
     *     summary="Удаление шаблона вещания",
     *     description="Метод для удаления шаблона вещания.",
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
        return $service->delete($repository, $id);
    }

}
