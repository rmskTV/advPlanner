<?php

namespace App\Services;

use App\Enum\AccountingUnitsEnum;
use Illuminate\Http\JsonResponse;

class AccountingUnitsService
{
    public function getAll(): JsonResponse
    {
        $data = [];
        foreach (AccountingUnitsEnum::cases() as $enumCase) {
            $data[] = [
                'id' => $enumCase->value,
                'name' => $enumCase->label(),
            ];
        }

        return response()->json(['data' => $data], 200);

    }

    public function getById(string $id): JsonResponse
    {
        return response()->json([], 200);
    }

}
