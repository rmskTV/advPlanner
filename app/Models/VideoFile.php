<?php

namespace App\Models;

/**
 * Класс ВидеоФайла
 *
 * @OA\Schema(
 *      schema="VideoFile",
 *
 *              @OA\Property(property="uuid", type="uuid"),
 *              @OA\Property(property="id", type="integer"),
 *              @OA\Property(property="original_name", type="string", example="FooBar.mp4"),
 *              @OA\Property(property="hash", type="string"),
 *              @OA\Property(property="status", type="string", example="in_queue"),
 *              @OA\Property(property="converted_file_location", type="string", example="/var/file/video.mp4"),
 *              @OA\Property(property="width", type="integer", example="1920"),
 *              @OA\Property(property="height", type="integer", example="1080"),
 *              @OA\Property(property="length", type="integer", example="30"),
 *              @OA\Property(property="media_info", type="string", example="json"),
 *              @OA\Property(property="created_at", type="string", format="date-time", example="2024-05-06T18:23:37.000000Z"),
 *              @OA\Property(property="updated_at", type="string", format="date-time", example="2024-05-06T18:23:37.000000Z")
 * )
 *
 * @OA\Schema(
 *              schema="VideoFileRequest",
 *              type="object",
 *              required={"video"},
 *
 *              @OA\Property(
 *                  property="video",
 *                  type="string",
 *                  format="binary",
 *                  description="Видео файл для загрузки"
 *              )
 * )
 */
class VideoFile extends CatalogObject
{
    protected $fillable = [
        'original_name',
        'hash',
        'status',
        'preview_file_location',
        'original_file_location',
        'media_info',
        'height',
        'width',
        'duration',
        'size'
    ];
}
