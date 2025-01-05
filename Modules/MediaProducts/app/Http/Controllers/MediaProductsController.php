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
     * Display a listing of the resource.
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
        // TODO: Авторизация: Добавлять могут только администраторы
        $request = request()->post();

        $validator = Validator::make($request, [
            'name' => 'required',
            'channel_id' => 'required|integer|exists:channels,id',
            'start_time' => 'required|time|date_format:H:i:s',
            'duration' => 'required|integer|between:0,1440',
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
        // TODO: Авторизация: Обновлять могут только администраторы
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
