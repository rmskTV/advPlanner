<?php

namespace Modules\Organisations\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Organisations\Repositories\OrganisationRepository as Repository;
use Modules\Organisations\Services\OrganisationService as Service;

class OrganisationsController extends Controller
{
    /**
     * Получение списка организаций
     *
     * @param  Service  $service  Сервис для работы со словарем организаций
     * @param  Repository  $repository  Репозиторий для доступа к данным об организациях
     * @return JsonResponse JSON-ответ с данными о ВебКреативах
     *
     * @OA\Get(
     *     path="/api/organisations",
     *     tags={"Core/Dictionary/Organisations"},
     *     summary="Получение списка Организаций",
     *     description="Метод для получения списка всех Организаций.",
     *
     *     @OA\Response(
     *         response=200,
     *         description="Успешный запрос. Возвращает список ВебКреативов.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="current_page", type="integer"),
     *             @OA\Property(property="data", type="array",
     *
     *                 @OA\Items(
     *                     ref="#/components/schemas/Organisation",
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
     * Создание новой организации
     *
     * @param Service $service Сервис для работы со словарем организаций
     * @param Repository $repository Репозиторий для доступа к данным об организациях
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Put(
     *     path="/api/organisations",
     *     tags={"Core/Dictionary/Organisations"},
     *     summary="Создание новой организации",
     *     description="Метод для создания новой организации.",
     *
     *     @OA\RequestBody(
     *         required=true,
     *         description="Данные для создания новой организации",
     *
     *         @OA\JsonContent(
     *             required={"name"},
     *
     *             ref="#/components/schemas/OrganisationRequest",
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Успешное создание организации",
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
     * @throws ValidationException
     */
    public function create(Service $service, Repository $repository): JsonResponse
    {
        $request = request()->post();

        $validator = Validator::make($request, [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toJson(), 200);
        }

        return $service->create($validator->validated(), $repository);
    }

    /**
     * Получение городского портала по идентификатору
     *
     * @OA\Get (
     *     path="/api/organisations/{id}",
     *     tags={"Core/Dictionary/Organisations"},
     *     summary="Получение информации об организации",
     *     description="Метод для получения информации об организации по его идентификатору",
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Идентификатор организации",
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
     *                 ref="#/components/schemas/Organisation",
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
     * Обновление данных городского портала
     *
     * @param Service $service Сервис для работы со словарем Организаций
     * @param Repository $repository Репозиторий для доступа к данным об организациях
     * @param int $id Идентификатор городского портала
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Patch(
     *     path="/api/organisations/{id}",
     *     tags={"Core/Dictionary/Organisations"},
     *     summary="Обновление данных организации",
     *     description="Метод для обновления данных организации.",
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Идентификатор организации",
     *         required=true,
     *
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *
     *     @OA\RequestBody(
     *         required=false,
     *         description="Данные для обновления организации",
     *
     *         @OA\JsonContent(
     *             ref="#/components/schemas/OrganisationRequest",
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Успешное обновление данных организации",
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
        // TODO: Только администраторы
        $request = request()->post();

        $validator = Validator::make($request, [
            'name' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toJson(), 200);
        }

        if (! $request || count($request) == 0) {
            return response()->json(true, 200);
        }

        return $service->update($validator->validated(), $repository, $id);
    }

    /**
     * Удаление городского портала
     *
     * @param  Service  $service  Сервис для работы со словарем организаций
     * @param  Repository  $repository  Репозиторий для доступа к данным об организациях
     * @param  int  $id  Идентификатор городского портала
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Delete(
     *     path="/api/organisations/{id}",
     *     tags={"Core/Dictionary/Organisations"},
     *     summary="Удаление организации",
     *     description="Метод для удаления организации.",
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Идентификатор организации",
     *         required=true,
     *
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Успешное удаление городского портала",
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
