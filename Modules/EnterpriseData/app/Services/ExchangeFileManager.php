<?php

namespace Modules\EnterpriseData\app\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;
use Modules\EnterpriseData\app\Exceptions\ExchangeFileException;
use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;
use Modules\EnterpriseData\app\ValueObjects\FileLock;

class ExchangeFileManager
{
    private const LOCK_TIMEOUT = 300; // 5 минут

    private const MAX_FILE_SIZE = 50 * 1024 * 1024; // 50MB

    private const ALLOWED_EXTENSIONS = ['xml'];

    public function __construct(
        private readonly ExchangeFtpConnectorService $ftpService
    ) {}

    /**
     * Сканирование входящих файлов
     */
    public function scanIncomingFiles(ExchangeFtpConnector $connector): array
    {
        try {
            $filesystem = $this->ftpService->getConnection($connector);
            $exchangePath = $this->getExchangePath($connector);

            Log::info('Scanning for incoming files', [
                'connector' => $connector->id,
                'exchange_path' => $exchangePath,
                'ftp_host' => parse_url($connector->ftp_path, PHP_URL_HOST),
                'ftp_port' => $connector->ftp_port,
            ]);

            // Получаем список всех файлов в папке обмена
            $allFiles = [];
            try {
                $listing = $filesystem->listContents($exchangePath, false);

                foreach ($listing as $item) {
                    if ($item->isFile()) {
                        $fileName = basename($item->path());

                        Log::debug('Found file', [
                            'connector' => $connector->id,
                            'file' => $fileName,
                            'is_incoming' => $this->isIncomingFileForUs($fileName, $connector),
                            'is_locked' => $this->isFileLocked($fileName),
                            'valid_extension' => $this->isValidFileExtension($fileName),
                        ]);

                        // Проверяем, является ли файл входящим для нас
                        if ($this->isIncomingFileForUs($fileName, $connector)) {
                            // Проверяем блокировку
                            if (! $this->isFileLocked($fileName)) {
                                $allFiles[] = $fileName;
                            }
                        }
                    }
                }

            } catch (\Exception $e) {
                // Если не можем получить листинг директории, возможно её не существует
                Log::warning('Cannot list directory contents', [
                    'connector' => $connector->id,
                    'path' => $exchangePath,
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                ]);

                return [];
            }

            Log::info('Scanned incoming files', [
                'connector' => $connector->id,
                'path' => $exchangePath,
                'files_found' => count($allFiles),
                'files' => $allFiles,
            ]);

            return $allFiles;

        } catch (\Exception $e) {
            throw new ExchangeFileException('Failed to scan incoming files: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Загрузка файла с FTP с улучшенной диагностикой
     */
    public function downloadFile(ExchangeFtpConnector $connector, string $fileName): string
    {
        try {
            $filesystem = $this->ftpService->getConnection($connector);
            $exchangePath = $this->getExchangePath($connector);
            $filePath = empty($exchangePath) ? $fileName : $exchangePath.'/'.$fileName;

            Log::info('Downloading file', [
                'connector' => $connector->id,
                'file' => $fileName,
                'full_path' => $filePath,
                'exchange_path' => $exchangePath,
            ]);

            // Проверка существования файла
            if (! $filesystem->fileExists($filePath)) {
                throw new ExchangeFileException("File {$fileName} does not exist at path {$filePath}");
            }

            // Проверка размера файла
            $fileSize = $filesystem->fileSize($filePath);
            Log::info('File size check', [
                'connector' => $connector->id,
                'file' => $fileName,
                'size' => $fileSize,
                'max_allowed' => self::MAX_FILE_SIZE,
            ]);

            if ($fileSize > self::MAX_FILE_SIZE) {
                throw new ExchangeFileException("File {$fileName} exceeds maximum size limit ({$fileSize} bytes)");
            }

            // Проверка расширения
            if (! $this->isValidFileExtension($fileName)) {
                throw new ExchangeFileException("File {$fileName} has invalid extension");
            }

            $content = $filesystem->read($filePath);

            Log::info('File content preview', [
                'connector' => $connector->id,
                'file' => $fileName,
                'content_length' => strlen($content),
                'content_preview' => substr($content, 0, 200), // Первые 200 символов для диагностики
                'starts_with_xml' => str_starts_with(trim($content), '<?xml'),
                'encoding' => mb_detect_encoding($content),
            ]);

            // Проверка на наличие вредоносного содержимого
            $this->validateFileContent($content, $fileName);

            Log::info('Downloaded file successfully', [
                'connector' => $connector->id,
                'file' => $fileName,
                'size' => strlen($content),
            ]);

            return $content;

        } catch (\Exception $e) {
            Log::error('Failed to download file', [
                'connector' => $connector->id,
                'file' => $fileName,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            throw new ExchangeFileException("Failed to download file {$fileName}: ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * Загрузка файла на FTP
     */
    public function uploadFile(ExchangeFtpConnector $connector, string $content, string $fileName): bool
    {
        try {
            // Валидация содержимого перед загрузкой
            $this->validateFileContent($content, $fileName);

            $filesystem = $this->ftpService->getConnection($connector);
            $exchangePath = $this->getExchangePath($connector);
            $filePath = empty($exchangePath) ? $fileName : $exchangePath.'/'.$fileName;

            Log::info('Uploading file', [
                'connector' => $connector->id,
                'file' => $fileName,
                'full_path' => $filePath,
                'size' => strlen($content),
            ]);

            // Создание временного файла с уникальным именем
            $tempFileName = $fileName.'.tmp.'.Str::random(8);
            $tempFilePath = empty($exchangePath) ? $tempFileName : $exchangePath.'/'.$tempFileName;

            // Загрузка во временный файл
            $filesystem->write($tempFilePath, $content);

            // Атомарное переименование
            $filesystem->move($tempFilePath, $filePath);

            Log::info('Uploaded file successfully', [
                'connector' => $connector->id,
                'file' => $fileName,
                'size' => strlen($content),
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to upload file', [
                'connector' => $connector->id,
                'file' => $fileName,
                'error' => $e->getMessage(),
            ]);

            throw new ExchangeFileException("Failed to upload file {$fileName}: ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * Блокировка файла для обработки
     */
    public function lockFile(string $fileName): FileLock
    {
        $lockId = Str::uuid()->toString();
        $cacheKey = "exchange_file_lock:{$fileName}";

        Log::debug('Attempting to lock file', [
            'file' => $fileName,
            'lock_id' => $lockId,
        ]);

        // Попытка получения блокировки
        if (! Cache::add($cacheKey, $lockId, self::LOCK_TIMEOUT)) {
            throw new ExchangeFileException("File {$fileName} is already locked");
        }

        // Сохраняем время создания блокировки
        Cache::put($cacheKey.'_created_at', Carbon::now()->toISOString(), self::LOCK_TIMEOUT);

        Log::info('File locked successfully', [
            'file' => $fileName,
            'lock_id' => $lockId,
        ]);

        return new FileLock($fileName, $lockId, Carbon::now());
    }

    /**
     * Разблокировка файла
     */
    public function unlockFile(FileLock $lock): void
    {
        $cacheKey = "exchange_file_lock:{$lock->fileName}";

        // Проверяем, что блокировка принадлежит нам
        if (Cache::get($cacheKey) === $lock->lockId) {
            Cache::forget($cacheKey);
            Cache::forget($cacheKey.'_created_at');

            Log::info('File unlocked successfully', [
                'file' => $lock->fileName,
                'lock_id' => $lock->lockId,
            ]);
        } else {
            Log::warning('Attempted to unlock file with wrong lock ID', [
                'file' => $lock->fileName,
                'attempted_lock_id' => $lock->lockId,
                'actual_lock_id' => Cache::get($cacheKey),
            ]);
        }
    }

    /**
     * Архивирование обработанного файла
     */
    public function archiveProcessedFile(ExchangeFtpConnector $connector, string $fileName): bool
    {
        try {
            $filesystem = $this->ftpService->getConnection($connector);
            $exchangePath = $this->getExchangePath($connector);
            $sourcePath = empty($exchangePath) ? $fileName : $exchangePath.'/'.$fileName;
            $archivePath = $this->getArchivePath($connector).'/incoming/'.date('Y/m/d/H/i').'/'.$fileName;

            Log::info('Archiving processed file', [
                'connector' => $connector->id,
                'file' => $fileName,
                'source_path' => $sourcePath,
                'archive_path' => $archivePath,
            ]);

            // Создание директории архива если не существует
            $archiveDir = dirname($archivePath);
            if (! $filesystem->directoryExists($archiveDir)) {
                $this->createDirectoryRecursively($filesystem, $archiveDir);
            }

            // Проверяем существование исходного файла
            if (! $filesystem->fileExists($sourcePath)) {
                Log::warning('Source file does not exist for archiving', [
                    'connector' => $connector->id,
                    'file' => $fileName,
                    'source_path' => $sourcePath,
                ]);

                return false;
            }

            // Копирование файла в архив - или move() для перемещения
            $filesystem->copy($sourcePath, $archivePath);

            Log::info('Archived processed file successfully', [
                'connector' => $connector->id,
                'file' => $fileName,
                'archive_path' => $archivePath,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to archive file', [
                'connector' => $connector->id,
                'file' => $fileName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Генерация имени файла для исходящего сообщения
     * Формат: Message_{senderPrefix}_{receiverPrefix}.xml
     */
    public function generateFileName(ExchangeFtpConnector $connector, int $messageNo): string
    {
        $ourPrefix = $connector->ftp_transliterate
            ? $this->transliterate($connector->own_base_prefix)
            : $connector->own_base_prefix;

        $foreignPrefix = $connector->ftp_transliterate
            ? $this->transliterate($connector->foreign_base_prefix)
            : $connector->foreign_base_prefix;

        // Используем простой формат с префиксами
        return "Message_{$ourPrefix}_{$foreignPrefix}.xml";
    }

    /**
     * Альтернативная генерация имени файла с UUID
     * Формат: Message_{senderPrefix}_{senderUUID}_{receiverUUID}.xml
     */
    public function generateFileNameWithUUID(ExchangeFtpConnector $connector): string
    {
        $ourPrefix = $connector->ftp_transliterate
            ? $this->transliterate($connector->own_base_prefix)
            : $connector->own_base_prefix;

        $ourUUID = config('enterprisedata.own_base_guid');
        $foreignUUID = $connector->foreign_base_guid;

        if (! $ourUUID || ! $foreignUUID) {
            throw new ExchangeFileException('UUIDs are required for UUID-based file naming');
        }

        return "Message_{$ourPrefix}_{$ourUUID}_{$foreignUUID}.xml";
    }

    /**
     * Проверка содержимого файла перед загрузкой
     */
    public function inspectFile(ExchangeFtpConnector $connector, string $fileName): array
    {
        try {
            $filesystem = $this->ftpService->getConnection($connector);
            $exchangePath = $this->getExchangePath($connector);
            $filePath = empty($exchangePath) ? $fileName : $exchangePath.'/'.$fileName;

            if (! $filesystem->fileExists($filePath)) {
                return ['error' => "File {$fileName} does not exist"];
            }

            $fileSize = $filesystem->fileSize($filePath);
            $lastModified = $filesystem->lastModified($filePath);

            // Читаем первые 1000 символов для анализа
            $content = $filesystem->read($filePath);
            $preview = substr($content, 0, 1000);

            return [
                'file_name' => $fileName,
                'file_path' => $filePath,
                'size' => $fileSize,
                'last_modified' => date('Y-m-d H:i:s', $lastModified),
                'content_length' => strlen($content),
                'content_preview' => $preview,
                'starts_with_xml' => str_starts_with(trim($content), '<?xml'),
                'encoding' => mb_detect_encoding($content),
                'is_incoming' => $this->isIncomingFileForUs($fileName, $connector),
                'is_outgoing' => $this->isOutgoingFileFromUs($fileName, $connector),
                'is_locked' => $this->isFileLocked($fileName),
                'is_valid_xml' => $this->isValidXmlContent($content),
            ];

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage(),
                'file_name' => $fileName,
            ];
        }
    }

    /**
     * Получение пути для обмена из ftp_path
     */
    private function getExchangePath(ExchangeFtpConnector $connector): string
    {
        $parsedUrl = parse_url($connector->ftp_path);

        // Если есть path в URL, используем его
        $path = $parsedUrl['path'] ?? '';

        // Убираем начальные и конечные слеши
        $path = trim($path, '/');

        // Если путь пустой, работаем в корневой директории
        return $path;
    }

    /**
     * Путь для архива
     */
    private function getArchivePath(ExchangeFtpConnector $connector): string
    {
        $exchangePath = $this->getExchangePath($connector);

        if (empty($exchangePath)) {
            return 'archive';
        }

        return $exchangePath.'/archive';
    }

    /**
     * Проверка, является ли файл входящим для нас
     * Формат: Message_{senderPrefix}_{receiverPrefix}.xml или Message_{senderPrefix}_{senderUUID}_{receiverUUID}.xml
     */
    private function isIncomingFileForUs(string $fileName, ExchangeFtpConnector $connector): bool
    {
        // Проверка расширения
        if (! $this->isValidFileExtension($fileName)) {
            return false;
        }

        // Получаем префиксы с учетом транслитерации
        $ourPrefix = $connector->ftp_transliterate
            ? $this->transliterate($connector->own_base_prefix)
            : $connector->own_base_prefix;

        $foreignPrefix = $connector->ftp_transliterate
            ? $this->transliterate($connector->foreign_base_prefix)
            : $connector->foreign_base_prefix;

        Log::debug('Checking if file is incoming for us', [
            'file' => $fileName,
            'our_prefix' => $ourPrefix,
            'foreign_prefix' => $foreignPrefix,
            'transliterate' => $connector->ftp_transliterate,
        ]);

        // Проверяем паттерн 1: Message_{foreignPrefix}_{ourPrefix}.xml
        $pattern1 = "/^Message_{$foreignPrefix}_{$ourPrefix}\.xml$/i";
        if (preg_match($pattern1, $fileName)) {
            Log::debug('File matches pattern 1 (prefix-based)', [
                'file' => $fileName,
                'pattern' => $pattern1,
            ]);

            return true;
        }

        // Проверяем паттерн 2: Message_{foreignPrefix}_{foreignUUID}_{ourUUID}.xml
        if ($connector->foreign_base_guid) {
            $ourUUID = config('enterprisedata.own_base_guid');
            $foreignUUID = $connector->foreign_base_guid;

            if ($ourUUID && $foreignUUID) {
                $pattern2 = "/^Message_{$foreignPrefix}_{$foreignUUID}_{$ourUUID}\.xml$/i";
                if (preg_match($pattern2, $fileName)) {
                    Log::debug('File matches pattern 2 (UUID-based)', [
                        'file' => $fileName,
                        'pattern' => $pattern2,
                    ]);

                    return true;
                }
            }
        }

        Log::debug('File does not match any incoming patterns', [
            'file' => $fileName,
        ]);

        return false;
    }

    /**
     * Проверка, является ли файл исходящим от нас
     */
    private function isOutgoingFileFromUs(string $fileName, ExchangeFtpConnector $connector): bool
    {
        // Проверка расширения
        if (! $this->isValidFileExtension($fileName)) {
            return false;
        }

        // Получаем префиксы с учетом транслитерации
        $ourPrefix = $connector->ftp_transliterate
            ? $this->transliterate($connector->own_base_prefix)
            : $connector->own_base_prefix;

        $foreignPrefix = $connector->ftp_transliterate
            ? $this->transliterate($connector->foreign_base_prefix)
            : $connector->foreign_base_prefix;

        // Проверяем паттерн 1: Message_{ourPrefix}_{foreignPrefix}.xml
        $pattern1 = "/^Message_{$ourPrefix}_{$foreignPrefix}\.xml$/i";
        if (preg_match($pattern1, $fileName)) {
            return true;
        }

        // Проверяем паттерн 2: Message_{ourPrefix}_{ourUUID}_{foreignUUID}.xml
        if ($connector->foreign_base_guid) {
            $ourUUID = config('enterprisedata.own_base_guid');
            $foreignUUID = $connector->foreign_base_guid;

            if ($ourUUID && $foreignUUID) {
                $pattern2 = "/^Message_{$ourPrefix}_{$ourUUID}_{$foreignUUID}\.xml$/i";
                if (preg_match($pattern2, $fileName)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Проверка валидности расширения файла
     */
    private function isValidFileExtension(string $fileName): bool
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return in_array($extension, self::ALLOWED_EXTENSIONS, true);
    }

    /**
     * Проверка блокировки файла
     */
    private function isFileLocked(string $fileName): bool
    {
        $cacheKey = "exchange_file_lock:{$fileName}";

        return Cache::has($cacheKey);
    }

    /**
     * Валидация содержимого файла с поддержкой BOM
     */
    private function validateFileContent(string $content, string $fileName): void
    {
        // Проверка на пустой контент
        if (empty(trim($content))) {
            throw new ExchangeFileException("File {$fileName} is empty");
        }

        // Удаляем BOM если есть
        $content = $this->removeBOM($content);
        $trimmedContent = trim($content);

        // Более гибкая проверка XML
        $isValidXml = str_starts_with($trimmedContent, '<?xml') ||
            str_starts_with($trimmedContent, '<Message') ||
            str_starts_with($trimmedContent, '<msg:Message');

        if (! $isValidXml) {
            // Пытаемся найти XML контент в файле
            if (preg_match('/<\?xml.*?\?>/i', $content) ||
                preg_match('/<Message[^>]*>/i', $content)) {
                Log::warning('XML declaration found but not at the beginning', [
                    'file' => $fileName,
                ]);
            } else {
                throw new ExchangeFileException("File {$fileName} does not contain valid XML content. Content preview: ".substr($trimmedContent, 0, 200));
            }
        }

        // Дополнительная проверка XML парсингом
        if (! $this->isValidXmlContent($content)) {
            throw new ExchangeFileException("File {$fileName} contains malformed XML");
        }

        // Проверка на наличие потенциально опасного содержимого
        $dangerousPatterns = [
            '<!DOCTYPE',           // DTD declarations
            '<!ENTITY',           // Entity declarations
            'SYSTEM',             // External system references
            'PUBLIC',             // External public references
            '<script',            // Script tags
            'javascript:',        // JavaScript protocols
            'vbscript:',          // VBScript protocols
        ];

        $lowerContent = strtolower($content);
        foreach ($dangerousPatterns as $pattern) {
            if (str_contains($lowerContent, strtolower($pattern))) {
                throw new ExchangeFileException("File {$fileName} contains potentially dangerous content: {$pattern}");
            }
        }

        // Проверка размера после декодирования
        if (strlen($content) > self::MAX_FILE_SIZE) {
            throw new ExchangeFileException("File {$fileName} content exceeds size limit");
        }
    }

    private function removeBOM(string $content): string
    {
        // UTF-8 BOM
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        // UTF-16 BE BOM
        if (str_starts_with($content, "\xFE\xFF")) {
            return substr($content, 2);
        }

        // UTF-16 LE BOM
        if (str_starts_with($content, "\xFF\xFE")) {
            return substr($content, 2);
        }

        // UTF-32 BE BOM
        if (str_starts_with($content, "\x00\x00\xFE\xFF")) {
            return substr($content, 4);
        }

        // UTF-32 LE BOM
        if (str_starts_with($content, "\xFF\xFE\x00\x00")) {
            return substr($content, 4);
        }

        return $content;
    }

    /**
     * Проверка валидности XML контента
     */
    private function isValidXmlContent(string $content): bool
    {
        try {
            // Удаляем BOM
            $content = $this->removeBOM($content);

            // Отключаем внешние сущности для безопасности
            $previousSetting = libxml_disable_entity_loader(true);

            // Отключаем вывод ошибок
            $previousErrorSetting = libxml_use_internal_errors(true);

            $dom = new \DOMDocument;
            $result = $dom->loadXML($content, LIBXML_NOCDATA | LIBXML_NONET);

            // Логируем ошибки XML если есть
            if (! $result) {
                $xmlErrors = libxml_get_errors();
                if (! empty($xmlErrors)) {
                    Log::debug('XML parsing errors', [
                        'errors' => array_map(fn ($error) => $error->message, $xmlErrors),
                    ]);
                }
                libxml_clear_errors();
            }

            // Восстанавливаем настройки
            libxml_disable_entity_loader($previousSetting);
            libxml_use_internal_errors($previousErrorSetting);

            return $result !== false;

        } catch (\Exception $e) {
            Log::debug('XML validation exception', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Рекурсивное создание директории
     */
    private function createDirectoryRecursively(Filesystem $filesystem, string $directory): void
    {
        $parts = explode('/', $directory);
        $currentPath = '';

        foreach ($parts as $part) {
            if (empty($part)) {
                continue;
            }

            $currentPath .= ($currentPath ? '/' : '').$part;

            if (! $filesystem->directoryExists($currentPath)) {
                $filesystem->createDirectory($currentPath);

                Log::debug('Created directory part', [
                    'directory' => $currentPath,
                ]);
            }
        }
    }

    /**
     * Транслитерация кириллицы в латиницу
     */
    private function transliterate(string $text): string
    {
        $transliterationMap = [
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D',
            'Е' => 'E', 'Ё' => 'E', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I',
            'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
            'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
            'У' => 'U', 'Ф' => 'F', 'Х' => 'Kh', 'Ц' => 'Ts', 'Ч' => 'Ch',
            'Ш' => 'Sh', 'Щ' => 'Sch', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '',
            'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];

        return strtr($text, $transliterationMap);
    }

    /**
     * Получение информации о файле
     */
    public function getFileInfo(ExchangeFtpConnector $connector, string $fileName): array
    {
        try {
            $filesystem = $this->ftpService->getConnection($connector);
            $exchangePath = $this->getExchangePath($connector);
            $filePath = empty($exchangePath) ? $fileName : $exchangePath.'/'.$fileName;

            if (! $filesystem->fileExists($filePath)) {
                throw new ExchangeFileException("File {$fileName} does not exist");
            }

            return [
                'name' => $fileName,
                'path' => $filePath,
                'size' => $filesystem->fileSize($filePath),
                'last_modified' => $filesystem->lastModified($filePath),
                'mime_type' => $filesystem->mimeType($filePath),
                'is_incoming' => $this->isIncomingFileForUs($fileName, $connector),
                'is_outgoing' => $this->isOutgoingFileFromUs($fileName, $connector),
                'is_locked' => $this->isFileLocked($fileName),
            ];

        } catch (\Exception $e) {
            throw new ExchangeFileException("Failed to get file info for {$fileName}: ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * Очистка старых заблокированных файлов
     */
    public function cleanupExpiredLocks(): int
    {
        $pattern = 'exchange_file_lock:*';
        $keys = Cache::getRedis()->keys($pattern);
        $cleaned = 0;

        foreach ($keys as $key) {
            $lockData = Cache::get($key);
            if ($lockData) {
                // Проверяем время создания блокировки
                $lockCreatedAt = Cache::get($key.'_created_at');
                if ($lockCreatedAt && Carbon::parse($lockCreatedAt)->addSeconds(self::LOCK_TIMEOUT)->isPast()) {
                    Cache::forget($key);
                    Cache::forget($key.'_created_at');
                    $cleaned++;
                }
            }
        }

        if ($cleaned > 0) {
            Log::info('Cleaned up expired file locks', ['count' => $cleaned]);
        }

        return $cleaned;
    }

    /**
     * Получение статистики файлов
     */
    public function getFileStatistics(ExchangeFtpConnector $connector): array
    {
        try {
            $filesystem = $this->ftpService->getConnection($connector);
            $exchangePath = $this->getExchangePath($connector);

            $stats = [
                'total_files' => 0,
                'incoming_files' => 0,
                'outgoing_files' => 0,
                'other_files' => 0,
                'locked_files' => 0,
                'total_size' => 0,
                'xml_files' => 0,
                'non_xml_files' => 0,
            ];

            $listing = $filesystem->listContents($exchangePath ?: '/', false);

            foreach ($listing as $item) {
                if ($item->isFile()) {
                    $fileName = basename($item->path());
                    $stats['total_files']++;

                    if (method_exists($item, 'fileSize')) {
                        $stats['total_size'] += $item->fileSize();
                    }

                    if ($this->isValidFileExtension($fileName)) {
                        $stats['xml_files']++;
                    } else {
                        $stats['non_xml_files']++;
                    }

                    if ($this->isIncomingFileForUs($fileName, $connector)) {
                        $stats['incoming_files']++;
                    } elseif ($this->isOutgoingFileFromUs($fileName, $connector)) {
                        $stats['outgoing_files']++;
                    } else {
                        $stats['other_files']++;
                    }

                    if ($this->isFileLocked($fileName)) {
                        $stats['locked_files']++;
                    }
                }
            }

            return $stats;

        } catch (\Exception $e) {
            Log::error('Failed to get file statistics', [
                'connector' => $connector->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'error' => $e->getMessage(),
                'total_files' => 0,
                'incoming_files' => 0,
                'outgoing_files' => 0,
                'other_files' => 0,
                'locked_files' => 0,
                'total_size' => 0,
                'xml_files' => 0,
                'non_xml_files' => 0,
            ];
        }
    }

    /**
     * Тест подключения к FTP
     */
    public function testConnection(ExchangeFtpConnector $connector): bool
    {
        try {
            Log::info('Testing FTP connection', [
                'connector' => $connector->id,
                'host' => parse_url($connector->ftp_path, PHP_URL_HOST),
                'port' => $connector->ftp_port,
                'login' => $connector->ftp_login,
                'passive_mode' => $connector->ftp_passive_mode,
            ]);

            $filesystem = $this->ftpService->getConnection($connector);
            $exchangePath = $this->getExchangePath($connector);

            // Простая проверка - пытаемся получить список файлов
            $listing = $filesystem->listContents($exchangePath ?: '/', false);

            // Проверяем, что можем итерироваться по результатам
            $count = 0;
            foreach ($listing as $item) {
                $count++;
                if ($count > 100) {
                    break;
                } // Ограничиваем для безопасности
            }

            Log::info('FTP connection test successful', [
                'connector' => $connector->id,
                'items_found' => $count,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('FTP connection test failed', [
                'connector' => $connector->id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            return false;
        }
    }
}
