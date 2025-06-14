<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeVideo;
use App\Models\VideoFile;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoFilesController extends Controller
{
    /**
     * Загрузка видеофайла
     *
     * @return JsonResponse JSON-ответ с результатом операции
     *
     * @OA\Post(
     *     path="/api/video",
     *     tags={"Core/Files"},
     *     summary="Загрузка видеоФайла (с последующей конвертацией в MP4)",
     *     description="Метод для загрузки видеоФайла (с последующей конвертацией в MP4)",
     *     security={{"bearerAuth": {}}},
     *
     *     @OA\RequestBody(
     *          required=true,
     *          description="тело запроса",
     *
     *          @OA\MediaType(
     *              mediaType="multipart/form-data",
     *
     *              @OA\Schema(ref="#/components/schemas/VideoFileRequest")
     *          )
     *      ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Успешное создание",
     *
     *         @OA\JsonContent(
     *             type="boolean",
     *             example=true
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=400,
     *         description="Неверный запрос",
     *
     *         @OA\JsonContent(
     *             example={"message": "Неверный запрос"}
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Внутренняя ошибка сервера",
     *
     *         @OA\JsonContent(
     *             example={"message": "Внутренняя ошибка сервера"}
     *         ),
     *     )
     * )
     */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'video' => 'required|file|mimetypes:video/*,application/mxf|mimes:mp4,mov,avi,mkv,mxf'
        ]);

        try {
            $file = $validated['video'];
            $hash = hash_file('sha256', $file->path());

            $video = VideoFile::firstOrNew(['hash' => $hash]);
            if ($video->exists && $video->status == 'done') {
                return $this->videoResponse($video);
            }

            $originalFilePath = $file->store('videos/'.Carbon::now()->format('Y/m'), 'public');

            $safeName = $this->sanitizeFileName($file->getClientOriginalName());

            $video->fill([
                'original_name' => $safeName,
                'status' => 'analyzing',
                'original_file_location' => $originalFilePath
            ])->save();

            AnalyzeVideo::dispatch($video);

            return response()->json([
                'id' => $video->id,
                'status' => $video->status
            ]);

        } catch (\Exception $e) {
            Log::error('Video upload failed: '.$e->getMessage());
            return response()->json(['error' => 'Upload failed'], 500);
        }
    }


    /**
     * Получение информации о видеофайле по его ID
     *
     * @OA\Get (
     *     path="/api/video/{id}",
     *     tags={"Core/Files"},
     *     security={{"bearerAuth": {}}},
     *     summary="Получение информации о видеофайле",
     *     description="Метод для получения информации о видеофайле",
     *
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Идентификатор записи",
     *         required=true,
     *
     *         @OA\Schema(
     *             type="integer"
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Успешный запрос. Возвращает информацию о медиапродукте.",
     *
     *         @OA\JsonContent(
     *                 ref="#/components/schemas/VideoFile",
     *         ),
     *     ),
     *
     *     @OA\Response(
     *         response=404,
     *         description="Запись не найдена",
     *
     *         @OA\JsonContent(
     *             example={"message": "Запись не найдена"}
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=500,
     *         description="Внутренняя ошибка сервера",
     *
     *         @OA\JsonContent(
     *             example={"message": "Внутренняя ошибка сервера"}
     *         )
     *     )
     * )
     */
    public function getVideoInfo($id): JsonResponse
    {
        $video = VideoFile::findOrFail($id);


        return $this->videoResponse($video);
    }

    private function videoResponse(VideoFile $video)
    {
        return response()->json([
            'id' => $video->id,
            'status' => $video->status,
            'width' => $video->width,
            'height' => $video->height,
            'duration' => $video->duration,
            'original_file' => $video->original_file_location,
            'preview_url' => $video->status === 'done'
                ? Storage::disk('public')->url($video->preview_file_location)
                : null
        ]);
    }

    protected function sanitizeFileName(string $name): string
    {
        $name = preg_replace('/[^\w\-\. ]+/u', '_', $name);
        $name = str_replace(['../', '..\\', '%00'], '', $name);
        $name = basename($name);
        $name = mb_substr($name, 0, 255);

        return $name ?: 'video_'.Str::random(10);
    }
}
