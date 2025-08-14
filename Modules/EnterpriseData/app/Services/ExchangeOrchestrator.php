<?php

namespace Modules\EnterpriseData\app\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\EnterpriseData\app\Exceptions\ExchangeException;
use Modules\EnterpriseData\app\Models\ExchangeFtpConnector;
use Modules\EnterpriseData\app\Models\ExchangeLog;
use Modules\EnterpriseData\app\ValueObjects\ExchangeResult;
use Modules\EnterpriseData\app\ValueObjects\ParsedExchangeMessage;
use Modules\EnterpriseData\app\ValueObjects\ProcessingResult;

readonly class ExchangeOrchestrator
{
    public function __construct(
        private ExchangeMessageProcessor $messageProcessor,
        private ExchangeFileManager $fileManager,
        private ExchangeTransactionManager $transactionManager,
        private ExchangeDataMapper $dataMapper,
        private ExchangeLogger $logger,
        private ExchangeConfigValidator $configValidator
    ) {}

    public function processIncomingExchange(ExchangeFtpConnector $connector): ExchangeResult
    {
        $startTime = Carbon::now();
        $this->logger->logExchangeStart($connector, 'incoming');

        try {
            // Валидация конфигурации
            $validationResult = $this->configValidator->validateConnector($connector);
            if (! $validationResult->isValid()) {
                throw new ExchangeException('Invalid connector configuration: '.implode(', ', $validationResult->getErrors()));
            }

            // Сканирование входящих файлов
            $incomingFiles = $this->fileManager->scanIncomingFiles($connector);
            if (empty($incomingFiles)) {
                return new ExchangeResult(true, 0, 0, [], [], $startTime, Carbon::now());
            }

            $totalProcessed = 0;
            $totalObjects = 0;
            $allErrors = [];
            $allWarnings = [];

            foreach ($incomingFiles as $fileName) {
                try {
                    $result = $this->processIncomingFile($connector, $fileName);
                    $totalProcessed += $result->processedMessages;
                    $totalObjects += $result->processedObjects;
                    $allErrors = array_merge($allErrors, $result->errors);
                    $allWarnings = array_merge($allWarnings, $result->warnings);
                } catch (\Exception $e) {
                    $allErrors[] = "File {$fileName}: ".$e->getMessage();
                    $this->logger->logError("Failed to process file {$fileName}", ['error' => $e->getMessage()]);
                }
            }

            $result = new ExchangeResult(
                empty($allErrors),
                $totalProcessed,
                $totalObjects, // Исправляем: передаем реальное количество объектов
                $allErrors,
                $allWarnings,
                $startTime,
                Carbon::now()
            );

            $this->logger->logExchangeEnd($connector, 'incoming', $result);

            return $result;

        } catch (\Exception $e) {
            $this->logger->logError('Exchange failed', ['connector' => $connector->id, 'error' => $e->getMessage()]);

            return new ExchangeResult(false, 0, 0, [$e->getMessage()], [], $startTime, Carbon::now());
        }
    }

    private function processIncomingFile(ExchangeFtpConnector $connector, string $fileName): ExchangeResult
    {
        return $this->transactionManager->executeInTransaction(function () use ($connector, $fileName) {
            // Блокировка файла
            $lock = $this->fileManager->lockFile($fileName);

            try {
                // Загрузка файла
                $xmlContent = $this->fileManager->downloadFile($connector, $fileName);

                // Парсинг сообщения
                $parsedMessage = $this->messageProcessor->parseIncomingMessage($xmlContent);

                $objectsCount = $parsedMessage->body->getObjectsCount();

                Log::info('Parsed message details', [
                    'connector_id' => $connector->id,
                    'file_name' => $fileName,
                    'message_no' => $parsedMessage->header->messageNo,
                    'objects_count' => $objectsCount,
                    'unique_types' => $parsedMessage->body->getUniqueObjectTypes(),
                ]);

                // Обработка объектов
                $processingResult = $this->dataMapper->processIncomingObjects(
                    $parsedMessage->body->objects,
                    $connector
                );

                Log::info('Processing result', [
                    'connector_id' => $connector->id,
                    'file_name' => $fileName,
                    'processing_success' => $processingResult->success,
                    'processed_count' => $processingResult->processedCount,
                    'total_objects_in_message' => $objectsCount,
                    'errors_count' => count($processingResult->errors),
                ]);

                // В режиме dry-run НЕ создаем запись в журнале и НЕ помечаем сообщение как обработанное
                $isDryRun = app()->runningInConsole() &&
                    in_array('--dry-run', $_SERVER['argv'] ?? []);

                if (! $isDryRun) {
                    // Создание записи в журнале
                    $exchangeLog = $this->createExchangeLogEntry($connector, $parsedMessage, $processingResult, 'incoming');

                    // Помечаем сообщение как успешно обработанное только если обработка прошла без ошибок
                    if ($processingResult->success) {
                        $this->markIncomingMessageAsProcessed($connector, $parsedMessage->header->messageNo, $exchangeLog->id);
                    }

                    // Архивирование файла
                    $this->fileManager->archiveProcessedFile($connector, $fileName);
                } else {
                    Log::info('DRY RUN: Skipping database operations', [
                        'file' => $fileName,
                        'total_objects' => $objectsCount,
                        'objects_processed' => $processingResult->processedCount,
                    ]);
                }

                // ИСПРАВЛЕНИЕ: Возвращаем общее количество объектов в сообщении, а не только обработанных
                return new ExchangeResult(
                    $processingResult->success,
                    1, // 1 сообщение обработано
                    $objectsCount, // Общее количество объектов в сообщении
                    $processingResult->errors,
                    []
                );

            } finally {
                $this->fileManager->unlockFile($lock);
            }
        });
    }

    public function processOutgoingExchange(ExchangeFtpConnector $connector): ExchangeResult
    {
        $startTime = Carbon::now();
        $this->logger->logExchangeStart($connector, 'outgoing');

        try {
            // Определение объектов для отправки
            $objectsToSend = $this->dataMapper->getObjectsForSending($connector);

            // Получение номера последнего успешно обработанного входящего сообщения
            $lastProcessedIncomingMessageNo = $this->getLastProcessedIncomingMessageNo($connector);

            // Если нет объектов для отправки, но есть подтверждение - отправляем пустое сообщение с подтверждением
            if ($objectsToSend->isEmpty() && $lastProcessedIncomingMessageNo === null) {
                return new ExchangeResult(true, 0, 0, [], [], $startTime, Carbon::now());
            }

            // Группировка объектов по типам
            $groupedObjects = $objectsToSend->groupBy('object_type');
            $messageNo = $this->getNextOutgoingMessageNumber($connector);

            $totalObjects = 0;
            $allErrors = [];
            $allWarnings = [];

            // Если есть объекты для отправки, группируем их
            if (! $objectsToSend->isEmpty()) {
                foreach ($groupedObjects as $objectType => $objects) {
                    try {
                        $result = $this->processOutgoingObjects(
                            $connector,
                            $objectType,
                            $objects,
                            $messageNo++,
                            $lastProcessedIncomingMessageNo
                        );

                        $totalObjects += $objects->count();
                        $allErrors = array_merge($allErrors, $result->errors);
                        $allWarnings = array_merge($allWarnings, $result->warnings);
                    } catch (\Exception $e) {
                        $allErrors[] = "Object type {$objectType}: ".$e->getMessage();
                    }
                }
            } else {
                // Отправляем пустое сообщение только с подтверждением
                try {
                    $this->sendConfirmationOnlyMessage($connector, $messageNo, $lastProcessedIncomingMessageNo);
                } catch (\Exception $e) {
                    $allErrors[] = 'Confirmation message: '.$e->getMessage();
                }
            }

            // Обновляем информацию о последнем подтвержденном сообщении
            if ($lastProcessedIncomingMessageNo !== null) {
                $this->updateLastConfirmedIncomingMessage($connector, $lastProcessedIncomingMessageNo);
            }

            $result = new ExchangeResult(
                empty($allErrors),
                $groupedObjects->count(),
                $totalObjects,
                $allErrors,
                $allWarnings,
                $startTime,
                Carbon::now()
            );

            $this->logger->logExchangeEnd($connector, 'outgoing', $result);

            return $result;

        } catch (\Exception $e) {
            $this->logger->logError('Outgoing exchange failed', ['connector' => $connector->id, 'error' => $e->getMessage()]);

            return new ExchangeResult(false, 0, 0, [$e->getMessage()], [], $startTime, Carbon::now());
        }
    }

    private function processOutgoingObjects(
        ExchangeFtpConnector $connector,
        string $objectType,
        Collection $objects,
        int $messageNo,
        ?int $lastProcessedIncomingMessageNo
    ): ProcessingResult {
        return $this->transactionManager->executeInTransaction(function () use (
            $connector, $objectType, $objects, $messageNo, $lastProcessedIncomingMessageNo
        ) {

            // Преобразование объектов в формат 1С
            $objects1C = $this->dataMapper->mapFromLaravelTo1C($objects, $objectType);

            // Генерация XML сообщения с подтверждением
            $xmlContent = $this->messageProcessor->generateOutgoingMessage(
                $objects1C,
                $connector,
                $messageNo,
                $lastProcessedIncomingMessageNo // Передаем номер последнего обработанного входящего сообщения
            );

            // Генерация имени файла
            $fileName = $this->fileManager->generateFileName($connector, $messageNo);

            // Загрузка файла на FTP
            $uploadResult = $this->fileManager->uploadFile($connector, $xmlContent, $fileName);

            if (! $uploadResult) {
                throw new ExchangeException("Failed to upload file {$fileName}");
            }

            // Отметка объектов как отправленных
            $this->markObjectsAsSent($objects, $messageNo);

            return new ProcessingResult(true, $objects->count(), [], [], []);
        });
    }

    /**
     * Отправка сообщения только с подтверждением (без данных)
     */
    private function sendConfirmationOnlyMessage(
        ExchangeFtpConnector $connector,
        int $messageNo,
        int $lastProcessedIncomingMessageNo
    ): void {
        // Генерация пустого XML сообщения только с подтверждением
        $xmlContent = $this->messageProcessor->generateConfirmationOnlyMessage(
            $connector,
            $messageNo,
            $lastProcessedIncomingMessageNo
        );

        // Генерация имени файла
        $fileName = $this->fileManager->generateFileName($connector, $messageNo);

        // Загрузка файла на FTP
        $uploadResult = $this->fileManager->uploadFile($connector, $xmlContent, $fileName);

        if (! $uploadResult) {
            throw new ExchangeException("Failed to upload confirmation file {$fileName}");
        }

        $this->logger->logMessageProcessing($fileName, 0, $connector);
    }

    /**
     * Получение номера последнего успешно обработанного входящего сообщения
     */
    private function getLastProcessedIncomingMessageNo(ExchangeFtpConnector $connector): ?int
    {
        return DB::table('exchange_incoming_confirmations')
            ->where('connector_id', $connector->id)
            ->where('confirmed', false) // Еще не подтверждено в исходящем сообщении
            ->orderBy('message_no', 'desc')
            ->value('message_no');
    }

    /**
     * Получение следующего номера исходящего сообщения
     */
    private function getNextOutgoingMessageNumber(ExchangeFtpConnector $connector): int
    {
        return ExchangeLog::where('connector_id', $connector->id)
            ->where('direction', 'outgoing')
            ->max('message_no') + 1 ?? 1;
    }

    /**
     * Пометка входящего сообщения как успешно обработанного
     */
    private function markIncomingMessageAsProcessed(
        ExchangeFtpConnector $connector,
        int $messageNo,
        int $exchangeLogId
    ): void {
        DB::table('exchange_incoming_confirmations')->insert([
            'connector_id' => $connector->id,
            'message_no' => $messageNo,
            'exchange_log_id' => $exchangeLogId,
            'processed_at' => now(),
            'confirmed' => false, // Будет установлено в true после отправки подтверждения
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Обновление информации о последнем подтвержденном входящем сообщении
     */
    private function updateLastConfirmedIncomingMessage(
        ExchangeFtpConnector $connector,
        int $messageNo
    ): void {
        DB::table('exchange_incoming_confirmations')
            ->where('connector_id', $connector->id)
            ->where('message_no', '<=', $messageNo)
            ->update(['confirmed' => true, 'confirmed_at' => now()]);
    }

    private function createExchangeLogEntry(
        ExchangeFtpConnector $connector,
        ParsedExchangeMessage $message,
        ProcessingResult $result,
        string $direction
    ): ExchangeLog {
        return ExchangeLog::create([
            'connector_id' => $connector->id,
            'direction' => $direction,
            'message_no' => $message->header->messageNo,
            'file_name' => $message->fileName ?? null,
            'objects_count' => $result->processedCount,
            'status' => $result->success ? 'completed' : 'failed',
            'started_at' => Carbon::now(),
            'completed_at' => Carbon::now(),
            'errors' => $result->errors,
            'warnings' => [],
        ]);
    }

    private function markObjectsAsSent(Collection $objects, int $messageNo): void
    {
        // Реализация зависит от структуры ваших моделей
        // Например, обновление поля last_exchange_message_no
    }
}
