<?php

namespace Modules\AdvBlocks\Services;

use Illuminate\Http\JsonResponse;
use Modules\AdvBlocks\Repositories\AdvBlockTypeRepository as Repository;

class AdvBlockTypeService
{

    public function create(array $request, Repository $repository): JsonResponse
    {
        $params = ['name','is_with_exact_time','accounting_unit'];
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
        //Предустановленные типы удалить нельзя
        if ($id <= 3){
            return response()->json(-1, 404);
        }
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
        $resource = $repository->getById($id);

        foreach ($request as $key => $value) {
            if (isset($resource->$key)) {
                $data[$key] = $value;
            }
        }

        $updated = $repository->update($id, $data);

        return response()->json($updated, ($updated) ? 200 : 201);
    }

}
