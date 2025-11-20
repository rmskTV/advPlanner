<?php

namespace Modules\MediaHills\app\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\MediaHills\app\Http\Requests\UploadAudienceFileRequest;
use Modules\MediaHills\Services\AudienceDataImportService;

class AudienceDataController extends Controller
{
    public function __construct(
        private AudienceDataImportService $importService
    ) {}

    /**
     * Показать форму загрузки
     */
    public function index()
    {
        return view('mediahills::upload');
    }

    /**
     * Загрузка и обработка файла
     */
    public function upload(UploadAudienceFileRequest $request): JsonResponse
    {
        try {
            $file = $request->file('file');
            $path = $file->getRealPath();

            $stats = $this->importService->import($path);

            return response()->json([
                'success' => true,
                'message' => 'Файл успешно обработан',
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при обработке файла: '.$e->getMessage(),
            ], 500);
        }
    }
}
