<?php

namespace Modules\Organisations\Services;

use Illuminate\Http\JsonResponse;
use Modules\Organisations\Repositories\OrganisationRepository as Repository;

class OrganisationService
{
    public function create(array $request, Repository $repository): JsonResponse
    {
        $params = ['name'];
        $data = [];
        foreach ($params as $param) {
            if (isset($request[$param])) {
                $data[$param] = $request[$param];
            }
        }

        return response()->json($repository->create($data), 201);
    }

    public function getAll(Repository $repository): JsonResponse
    {
        return response()->json($repository->getAll(), 200);
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
        $data = [];
        foreach ($request as $key => $value) {
            if ( $key == 'name') {
                $data[$key] = $value;
            }
        }
        $updated = $repository->update($id, $data);

        return response()->json($updated, ($updated) ? 200 : 201);
    }
}
