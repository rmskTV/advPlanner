<?php

namespace Modules\BroadcastingDayTemplates\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\BroadcastingDayTemplates\Repositories\BroadcastingDayTemplateSlotRepository as Repository;
use Modules\BroadcastingDayTemplates\Services\BroadcastingDayTemplateSlotService as Service;

class BroadcastingDayTemplateSlotsController extends Controller
{

    /**
     * Добавление слота в шаблон вещания
     *
     * @param Service $service  Сервис для работы со словарем
     * @param Repository $repository  Репозиторий для доступа к данным
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Put(
     *     path="/api/broadcastingDayTemplateSlots",
     *     tags={"Core/Broadcasting/DayTemplates"},
     *     summary="Добавление слота в шаблон вещания",
     *     description="Метод для Добавления слота в шаблон вещания",
     *
     *     @OA\RequestBody(
     *         required=true,
     *         description="Данные для создания новой записи",
     *
     *         @OA\JsonContent(
     *             required={"name", "comment", "broadcasting_day_template_id", "start", "end"},
     *
     *             ref="#/components/schemas/BroadcastingDayTemplateSlotRequest",
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
            'comment' => 'string|nullable|max:1024',
            'broadcasting_day_template_id' => 'required|integer|exists:broadcasting_day_templates,id',
            'start' => 'required|integer|min:0|max:1440',
            'end' => 'required|integer|min:0|max:1440',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toJson(), 200);
        }

        return $service->create($request, $repository);
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
     *     path="/api/broadcastingDayTemplateSlots/{id}",
     *     tags={"Core/Broadcasting/DayTemplates"},
     *     summary="Обновление данных слота шаблона вещания",
     *     description="Метод для обновления данных слота шаблона вещания.",
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
     *             ref="#/components/schemas/BroadcastingDayTemplateSlotRequest",
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
            'comment' => 'string|nullable|max:1024',
            'broadcasting_day_template_id' => 'required|integer|exists:broadcasting_day_templates,id',
            'start' => 'required|integer|min:0|max:1440',
            'end' => 'required|integer|min:0|max:1440',
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
     * Удаление слота шаблона вещания
     *
     * @param Service $service  Сервис для работы со словарем
     * @param Repository $repository  Репозиторий для доступа к данным
     * @param  int  $id  Идентификатор шаблона
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Delete(
     *     path="/api/broadcastingDayTemplateSlots/{id}",
     *     tags={"Core/Broadcasting/DayTemplates"},
     *     summary="Удаление слота шаблона вещания",
     *     description="Метод для удаления слота шаблона вещания.",
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


    /**
     * Получение списка слотов по ID шаблона
     *
     * @OA\Get (
     *     path="/api/broadcastingDayTemplates/{id}/slots",
     *     tags={"Core/Broadcasting/DayTemplates"},
     *     summary="Получение списка слотов шаблона вещания",
     *     description="Метод для получения списка слотов шаблона вещания",
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
     *          response=200,
     *          description="Успешный запрос. Возвращает список слотов.",
     *
     *          @OA\JsonContent(
     *
     *              @OA\Property(property="current_page", type="integer"),
     *              @OA\Property(property="data", type="array",
     *
     *                  @OA\Items(
     *                      ref="#/components/schemas/BroadcastingDayTemplateSlot",
     *                  )
     *              ),
     *
     *              @OA\Property(property="first_page_url", type="string"),
     *              @OA\Property(property="from", type="integer"),
     *              @OA\Property(property="last_page", type="integer"),
     *              @OA\Property(property="last_page_url", type="string"),
     *              @OA\Property(property="links", type="array",
     *
     *                  @OA\Items(
     *
     *                      @OA\Property(property="url", type="string", nullable=true),
     *                      @OA\Property(property="label", type="string"),
     *                      @OA\Property(property="active", type="boolean"),
     *                  )
     *              ),
     *              @OA\Property(property="next_page_url", type="string", nullable=true),
     *              @OA\Property(property="path", type="string"),
     *              @OA\Property(property="per_page", type="integer"),
     *              @OA\Property(property="prev_page_url", type="string", nullable=true),
     *              @OA\Property(property="to", type="integer"),
     *              @OA\Property(property="total", type="integer"),
     *          ),
     *      ),
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
    public function index(Service $service, Repository $repository, int $id): JsonResponse
    {
        return $service->getSlots($repository, $id);
    }


}
