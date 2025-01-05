<?php

namespace Modules\SalesModels\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Modules\SalesModels\Repositories\SalesModelRepository as Repository;
use Modules\SalesModels\Services\SalesModelService as Service;

class SalesModelsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Service $service, Repository $repository): JsonResponse
    {
        return $service->getAll($repository);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Service $service, Repository $repository): JsonResponse
    {
        $request = request()->post();

        $validator = Validator::make($request, [
            'name' => 'required',
            'organisation_id' => 'required|integer|exists:organisations,id',
            'contragent_id' => 'integer|exists:contragents,id',
            'guarantee' => 'float',
            'percent' => 'float',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors()->toJson(), 200);
        }

        // TODO: Авторизация: Сверять с организацией текущего пользователя
        if ($request['organisation_id'] != 1) {
            return response()->json(['organisation_id' => 'NOT_YOUR_ORGANISATION'], 200);
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
        $request = request()->post();

        if (! $request || count($request) == 0) {
            return response()->json(true, 200);
        }

        // TODO: Авторизация: Сверять с организацией текущего пользователя
        if (isset($request['organisation_id']) && $request['organisation_id'] != 1) {
            return response()->json(['organisation_id' => 'NOT_YOUR_ORGANISATION'], 200);
        }

        return $service->update($request, $repository, $id);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Service $service, Repository $repository, int $id): JsonResponse
    {
        // TODO: Авторизация: Удалять могут работники организации
        return $service->delete($repository, $id);
    }
}
