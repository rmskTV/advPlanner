<?php

namespace Modules\Channels\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Modules\Channels\Repositories\ChannelRepository as Repository;
use Modules\Channels\Services\ChannelService as Service;

class ChannelsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Service $service, Repository $repository): JsonResponse
    {
        // TODO Возвращать только каналы организации пользователя
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
            'organisation_id' => 'required|integer|exists:organisations,id',
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
