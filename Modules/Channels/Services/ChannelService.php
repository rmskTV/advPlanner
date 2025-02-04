<?php

namespace Modules\Channels\Services;

use Illuminate\Http\JsonResponse;
use Modules\Channels\Repositories\ChannelRepository as Repository;

class ChannelService
{
    public function create(array $request, Repository $repository): JsonResponse
    {
        $params = ['name', 'organisation_id'];
        $data = [];
        foreach ($params as $param) {
            if (isset($request[$param])) {
                $data[$param] = $request[$param];
            }
        }

        return response()->json($repository->create($data), 201);
    }

    public function getAll(Repository $repository, array $filters): JsonResponse
    {
        return response()->json($repository->getAll(['organisation'], $filters), 200);
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
        $updated = $repository->update($id, $data);

        return response()->json($updated, ($updated) ? 200 : 201);
    }
}
