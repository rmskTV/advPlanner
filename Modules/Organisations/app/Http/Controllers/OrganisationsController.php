<?php

namespace Modules\Organisations\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Modules\Organisations\Repositories\OrganisationRepository;
use Modules\Organisations\Repositories\OrganisationRepository as Repository;
use Modules\Organisations\Services\OrganisationService;
use Modules\Organisations\Services\OrganisationService as Service;

class OrganisationsController extends Controller
{
    /**
     * Получение списка ВебКреативов по ID ВебРесурса
     *
     * @param  OrganisationService  $service  Сервис для работы со словарем организаций
     * @param  OrganisationRepository  $repository  Репозиторий для доступа к данным о городских порталах
     * @return JsonResponse JSON-ответ с данными о ВебКреативах
     *
     * @OA\Get(
     *     path="/api/organisations/{id}",
     *     tags={"Organisations"},
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
     * Store a newly created resource in storage.
     */
    public function create(Service $service, Repository $repository): JsonResponse
    {
        $request = request()->post();

        $validator = Validator::make($request, [
            'name' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toJson(), 200);
        }

        return $service->create($request, $repository);
    }

    /**
     * Show the specified resource.
     */
    public function show(Service $service, Repository $repository, int $id): JsonResponse
    {
        return $service->getById($repository, $id);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Service $service, Repository $repository, int $id): JsonResponse
    {
        // TODO: Только администраторы
        $request = request()->post();

        if (! $request || count($request) == 0) {
            return response()->json(true, 200);
        }

        return $service->update($request, $repository, $id);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Service $service, Repository $repository, int $id): JsonResponse
    {
        // TODO: Авторизация: Удалять могут только администраторы
        return $service->delete($repository, $id);
    }
}
