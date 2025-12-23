<?php
// Modules/Bitrix24/app/Services/Processors/AbstractBitrix24Processor.php

namespace Modules\Bitrix24\app\Services\Processors;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Accounting\app\Models\ObjectChangeLog;
use Modules\Bitrix24\app\Exceptions\Bitrix24Exception;
use Modules\Bitrix24\app\Exceptions\DependencyNotReadyException;
use Modules\Bitrix24\app\Exceptions\ValidationException;
use Modules\Bitrix24\app\Services\Bitrix24Service;
use Modules\Bitrix24\app\Traits\HasBitrix24Operations;

abstract class AbstractBitrix24Processor
{
    use HasBitrix24Operations;

    protected Bitrix24Service $b24Service;

    public function __construct(Bitrix24Service $b24Service)
    {
        $this->b24Service = $b24Service;
    }

    /**
     * Главный метод обработки
     */
    public function process(ObjectChangeLog $change): void
    {
        // Блокируем запись
        if (!$change->lock()) {
            Log::warning('Failed to lock change', ['id' => $change->id]);
            return;
        }

        try {
            DB::beginTransaction();

            // Основная логика синхронизации (переопределяется в наследниках)
            $this->syncEntity($change);

            // Фиксируем успех
            $change->markProcessed();

            DB::commit();

            Log::info('Entity synced successfully', [
                'change_id' => $change->id,
                'entity_type' => $change->entity_type,
                'b24_id' => $change->b24_id
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();
            $change->markSkipped($e->getMessage());

            Log::warning('Entity skipped due to validation', [
                'change_id' => $change->id,
                'error' => $e->getMessage()
            ]);

        } catch (DependencyNotReadyException $e) {
            DB::rollBack();

            if ($change->retry_count < ObjectChangeLog::MAX_RETRIES) {
                $change->increment('retry_count');
                $change->markRetry($e->getMessage());

                Log::info('Dependency not ready, will retry', [
                    'change_id' => $change->id,
                    'retry_count' => $change->retry_count,
                    'error' => $e->getMessage()
                ]);
            } else {
                $change->markError("Max retries exceeded: " . $e->getMessage());

                Log::error('Max retries exceeded', [
                    'change_id' => $change->id,
                    'error' => $e->getMessage()
                ]);
            }

        } catch (Bitrix24Exception $e) {
            DB::rollBack();

            if ($e->isRetryable() && $change->retry_count < ObjectChangeLog::MAX_RETRIES) {
                $change->increment('retry_count');
                $change->markRetry($e->getMessage());

                Log::warning('Retryable error occurred', [
                    'change_id' => $change->id,
                    'retry_count' => $change->retry_count,
                    'error' => $e->getMessage()
                ]);
            } else {
                $change->markError($e->getMessage());

                Log::error('Non-retryable error', [
                    'change_id' => $change->id,
                    'error' => $e->getMessage()
                ]);
            }

        } catch (\Exception $e) {
            DB::rollBack();

            // Неизвестная ошибка - пробуем retry
            if ($change->retry_count < ObjectChangeLog::MAX_RETRIES) {
                $change->increment('retry_count');
                $change->markRetry($e->getMessage());

                Log::error('Unexpected error, will retry', [
                    'change_id' => $change->id,
                    'retry_count' => $change->retry_count,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            } else {
                $change->markError("Max retries exceeded: " . $e->getMessage());
            }
        } finally {
            // Всегда снимаем блокировку
            $change->unlock();
        }
    }

    /**
     * Основная логика синхронизации (переопределяется в наследниках)
     */
    abstract protected function syncEntity(ObjectChangeLog $change): void;

    /**
     * Валидация перед синхронизацией (опционально переопределяется)
     */
    protected function validate(ObjectChangeLog $change): void
    {
        // По умолчанию ничего не проверяем
    }
}
