<?php

namespace Modules\EnterpriseData\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\EnterpriseData\app\Http\Requests\StoreExchangeConnectorRequest;
use Modules\EnterpriseData\app\Repositories\ExchangeConnectorRepository as Repository;
use Modules\EnterpriseData\app\Services\ExchangeFtpConnectorService;
use Modules\EnterpriseData\app\Services\ExchangeFtpConnectorService as Service;

class ExchangeFtpConnectorController extends Controller
{
    public function __construct(
        private readonly ExchangeFtpConnectorService $service
    ) {}

    /**
     * @throws ValidationException
     */
    public function index(Service $service, Repository $repository): JsonResponse
    {

        $filters = [];
        $validator = Validator::make(request()->all(), $filters);
        $filters = $validator->validated();

        return $service->getAll($repository, $filters);
    }

    public function store(Repository $repository, StoreExchangeConnectorRequest $request): \Illuminate\Http\JsonResponse
    {
        try {
            $connector = $this->service->createFromXml($repository, $request->file('config')->get());

            return response()->json($connector, 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
