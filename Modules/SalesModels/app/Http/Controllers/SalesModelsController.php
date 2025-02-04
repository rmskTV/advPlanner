<?php

namespace Modules\SalesModels\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\SalesModels\Repositories\SalesModelRepository as Repository;
use Modules\SalesModels\Services\SalesModelService as Service;

class SalesModelsController extends Controller
{

    /**
     * Получение списка моделей продаж
     *
     * @param  Service  $service  Сервис для работы со словарем
     * @param  Repository  $repository  Репозиторий для доступа к данным
     * @return JsonResponse JSON-ответ с данными
     *
     * @OA\Get(
     *     path="/api/salesModels",
     *     tags={"Core/Dictionary/SalesModels"},
     *     summary="Получение списка моделей продаж.",
     *     description="Метод для получения списка списка моделей продаж.",
     *
     *     @OA\Response(
     *         response=200,
     *         description="Успешный запрос. Возвращает список  моделей продаж.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="current_page", type="integer"),
     *             @OA\Property(property="data", type="array",
     *
     *                 @OA\Items(
     *                     ref="#/components/schemas/SalesModel",
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
    public function index(Service $service, Repository $repository): JsonResponse
    {
        return $service->getAll($repository);
    }



    /**
     * Добавление модели продаж
     *
     * @param  Service  $service  Сервис для работы со словарем
     * @param  Repository  $repository  Репозиторий для доступа к данным
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Put(
     *     path="/api/salesModels",
     *     tags={"Core/Dictionary/SalesModels"},
     *     summary="Создание новой модели продаж",
     *     description="Метод для создания новой модели продаж.",
     *
     *     @OA\RequestBody(
     *         required=true,
     *         description="Данные для создания новой записи",
     *
     *         @OA\JsonContent(
     *             required={"name", "organisation_id", "guarantee", "percent"},
     *
     *             ref="#/components/schemas/SalesModelRequest",
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
            'name' => 'required',
            'organisation_id' => 'required|integer|exists:organisations,id',
            'guarantee' => 'required|numeric|min:0',
            'percent' => 'required|numeric|max:100|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toJson(), 200);
        }

        // TODO: Авторизация: Сверять с организацией текущего пользователя
//        if ($request['organisation_id'] != 1) {
//            return response()->json(['organisation_id' => 'NOT_YOUR_ORGANISATION'], 200);
//        }

        return $service->create($request, $repository);
    }

    /**
     * Получение модели продаж по идентификатору
     *
     * @OA\Get (
     *     path="/api/salesModels/{id}",
     *     tags={"Core/Dictionary/SalesModels"},
     *     summary="Получение информации о модели продаж",
     *     description="Метод для получения информации о модели по его идентификатору",
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Идентификатор модели продаж",
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
     *                 ref="#/components/schemas/SalesModel",
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
     * Обновление данных модели продаж
     *
     * @param Service $service Сервис для работы со словарем
     * @param Repository $repository Репозиторий для доступа к данным
     * @param int $id Идентификатор  модели продаж
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Patch(
     *     path="/api/salesModels/{id}",
     *     tags={"Core/Dictionary/SalesModels"},
     *     summary="Обновление данных модели продаж",
     *     description="Метод для обновления данных модели продаж.",
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
     *         description="Данные для обновления записи",
     *
     *         @OA\JsonContent(
     *             ref="#/components/schemas/SalesModelRequest",
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
     * @throws ValidationException
     */
    public function update(Service $service, Repository $repository, int $id): JsonResponse
    {
        $request = request()->post();

        $validator = Validator::make($request, [
            'name' => 'string',
            'organisation_id' => 'integer|exists:organisations,id',
            'guarantee' => 'numeric|min:0',
            'percent' => 'numeric|max:100|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toJson(), 200);
        }

        if (! $request || count($request) == 0) {
            return response()->json(true, 200);
        }

        // TODO: Авторизация: Сверять с организацией текущего пользователя
//        if (isset($request['organisation_id']) && $request['organisation_id'] != 1) {
//            return response()->json(['organisation_id' => 'NOT_YOUR_ORGANISATION'], 200);
//        }

        return $service->update($validator->validated(), $repository, $id);
    }

    /**
     * Удаление модели продаж
     *
     * @param  Service  $service  Сервис для работы со словарем
     * @param  Repository  $repository  Репозиторий для доступа к данным
     * @param  int  $id  Идентификатор канала
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Delete(
     *     path="/api/salesModels/{id}",
     *     tags={"Core/Dictionary/SalesModels"},
     *     summary="Удаление модели продаж",
     *     description="Метод для удаления модели продж.",
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Идентификатор удаляемой записи",
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
        // TODO: Авторизация: Удалять могут работники организации
        return $service->delete($repository, $id);
    }
}
