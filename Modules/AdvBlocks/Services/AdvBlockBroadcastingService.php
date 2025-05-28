<?php

namespace Modules\AdvBlocks\Services;

use Illuminate\Http\JsonResponse;
use Modules\AdvBlocks\app\Models\AdvBlock;
use Modules\AdvBlocks\Repositories\AdvBlockBroadcastingRepository as Repository;

class AdvBlockBroadcastingService
{
    public function create(array $request, Repository $repository): JsonResponse
    {
        $params = ['broadcast_at', 'adv_block_id', 'size'];
        $data = [];
        foreach ($params as $param) {
            if (isset($request[$param])) {
                $data[$param] = $request[$param];
            }
        }
        $advBlock = AdvBlock::query()->find($data['adv_block_id']);
        $data['channel_id'] = $advBlock->channel_id;
        $data['media_product_id'] = $advBlock->media_product_id;

        return response()->json($repository->create($data), 201);
    }

    public function getAll(Repository $repository, array $filters): JsonResponse
    {
        return response()->json($repository->getAll(['channel', 'advBlock'], $filters), 200);
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

        if (isset($data['adv_block_id'])) {
            $data['channel_id'] = AdvBlock::query()->find($data['adv_block_id'])->channel_id;
        }

        $updated = $repository->update($id, $data);

        return response()->json($updated, ($updated) ? 200 : 201);
    }
}
