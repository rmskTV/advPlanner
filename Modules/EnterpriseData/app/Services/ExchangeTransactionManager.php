<?php

namespace Modules\EnterpriseData\app\Services;

use Illuminate\Support\Facades\DB;
use Modules\EnterpriseData\app\Exceptions\ExchangeTransactionException;

class ExchangeTransactionManager
{
    private array $savepoints = [];

    public function executeInTransaction(callable $operation): mixed
    {
        return DB::transaction(function () use ($operation) {
            try {
                return $operation();
            } catch (\Exception $e) {
                throw new ExchangeTransactionException(
                    'Transaction failed: '.$e->getMessage(),
                    0,
                    $e
                );
            }
        });
    }

    public function executeWithRetry(callable $operation, int $maxRetries = 3): mixed
    {
        $attempts = 0;
        $lastException = null;

        while ($attempts < $maxRetries) {
            try {
                return $this->executeInTransaction($operation);
            } catch (\Exception $e) {
                $attempts++;
                $lastException = $e;

                if ($attempts >= $maxRetries) {
                    break;
                }

                // Экспоненциальная задержка
                usleep(pow(2, $attempts) * 100000); // 0.1, 0.2, 0.4 секунды
            }
        }

        throw new ExchangeTransactionException(
            "Operation failed after {$maxRetries} attempts: ".$lastException->getMessage(),
            0,
            $lastException
        );
    }

    public function createSavepoint(string $name): void
    {
        if (in_array($name, $this->savepoints)) {
            throw new ExchangeTransactionException("Savepoint {$name} already exists");
        }

        DB::statement("SAVEPOINT {$name}");
        $this->savepoints[] = $name;
    }

    public function rollbackToSavepoint(string $name): void
    {
        if (! in_array($name, $this->savepoints)) {
            throw new ExchangeTransactionException("Savepoint {$name} does not exist");
        }

        DB::statement("ROLLBACK TO SAVEPOINT {$name}");

        // Удаляем все savepoint-ы после текущего
        $index = array_search($name, $this->savepoints);
        $this->savepoints = array_slice($this->savepoints, 0, $index + 1);
    }

    public function releaseSavepoint(string $name): void
    {
        if (! in_array($name, $this->savepoints)) {
            throw new ExchangeTransactionException("Savepoint {$name} does not exist");
        }

        DB::statement("RELEASE SAVEPOINT {$name}");

        $index = array_search($name, $this->savepoints);
        unset($this->savepoints[$index]);
        $this->savepoints = array_values($this->savepoints);
    }
}
