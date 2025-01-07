<?php

namespace Modules\MediaProducts\Services;

use Illuminate\Http\JsonResponse;
use Modules\Channels\app\Models\Channel;
use Modules\MediaProducts\Repositories\MediaProductRepository as Repository;

class MediaProductService
{
    public function create(array $request, Repository $repository): JsonResponse
    {
        $params = ['name', 'channel_id', 'start_time', 'duration'];
        $data = [];
        foreach ($params as $param) {
            if (isset($request[$param])) {
                $data[$param] = $request[$param];
            }
        }

        $data['organisation_id'] = Channel::query()->find($data['channel_id'])->organisation_id;

        return response()->json($repository->create($data), 201);
    }

    public function getAll(Repository $repository, int $channel_id): JsonResponse
    {
        return response()->json($repository->getAllBy('channel_id', $channel_id), 200);
    }

    public function delete(Repository $repository, int $id): JsonResponse
    {
        return response()->json($repository->delete($id), 200);
    }

    public function getById(Repository $repository, int $id): JsonResponse
    {
        $object = $repository->getById($id);
        if (! isset($object)) {
            return response()->json(['error' => 'Object not found'], 200);
        }

        return response()->json($repository->getById($id), 200);
    }

    public function update(array $request, Repository $repository, int $id): JsonResponse
    {
        $resource = $repository->getById($id);

        $data = [];
        foreach ($request as $key => $value) {
            if (isset($resource->$key)) {
                $data[$key] = $value;
            }
        }
        if (isset($data['channel_id'])) {
            $data['organisation_id'] = Channel::query()->find($data['channel_id'])->organisation_id;
        }

        $updated = $repository->update($id, $data);

        return response()->json($updated, ($updated) ? 200 : 201);
    }
}
