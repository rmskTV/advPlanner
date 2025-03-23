<?php

namespace Modules\BroadcastingDayTemplates\Services;

use Illuminate\Http\JsonResponse;
use Modules\BroadcastingDayTemplates\Repositories\BroadcastingDayTemplateSlotRepository as Repository;

class BroadcastingDayTemplateSlotService
{
    public function getAll(Repository $repository, array $filters): JsonResponse
    {
        return response()->json($repository->getAll([], $filters), 200);
    }

    public function create(array $request, Repository $repository): JsonResponse
    {
        $params = ['name', 'comment', 'broadcasting_day_template_id', 'start', 'end'];
        $data = [];
        foreach ($params as $param) {
            if (isset($request[$param])) {
                $data[$param] = $request[$param];
            }
        }

        return response()->json($repository->create($data), 201);
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

    public function delete(Repository $repository, int $id): JsonResponse
    {
        return response()->json($repository->delete($id), 200);
    }
}
