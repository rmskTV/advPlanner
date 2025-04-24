<?php

namespace Modules\AdvBlocks\Services;

use Illuminate\Http\JsonResponse;
use Modules\AdvBlocks\Repositories\AdvBlockRepository as Repository;
use Modules\MediaProducts\app\Models\MediaProduct;

class AdvBlockService
{
    public function create(array $request, Repository $repository): JsonResponse
    {
        $params = ['name', 'comment', 'adv_block_type_id', 'media_product_id', 'is_only_for_package', 'size', 'sales_model_id'];
        $data = [];
        foreach ($params as $param) {
            if (isset($request[$param])) {
                $data[$param] = $request[$param];
            }
        }
        if (isset($data['media_product_id'])) {
            $data['channel_id'] = MediaProduct::query()->find($data['media_product_id'])->channel_id;
        }

        return response()->json($repository->create($data), 201);
    }

    public function getAll(Repository $repository, array $filters): JsonResponse
    {
        return response()->json($repository->getAll(['advBlockType', 'mediaProduct', 'channel', 'salesModel'], $filters), 200);
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
        $resource = $repository->getById($id);

        foreach ($request as $key => $value) {
            if (isset($resource->$key)) {
                $data[$key] = $value;
            }
        }
        if (isset($data['media_product_id'])) {
            $data['channel_id'] = MediaProduct::query()->find($data['media_product_id'])->channel_id;
        }

        $updated = $repository->update($id, $data);

        return response()->json($updated, ($updated) ? 200 : 201);
    }
}
