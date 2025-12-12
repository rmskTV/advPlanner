<?php

namespace Modules\Accounting\app\Console\Commands;

use Illuminate\Console\Command;
use GuzzleHttp\Client;
use Carbon\Carbon;

class VkDeletePostsByOffset extends Command
{
    protected $signature = 'vk:delete-by-offset
                           {--owner_domain=foo : имя владельца стены}
                           {--start_offset=1000000: Стартовый offset для начала проверки}
                           {--count=100 : Количество постов для обработки (0 = без лимита)}
                           {--stop-date= : Остановиться при достижении даты (Y-m-d)}
                           {--token= : VK API токен}
                           {--dry-run : Только показать что будет удалено, не удалять}
                           {--force : Удалить без подтверждения каждого поста}';

    protected $description = 'Удаление записей с фото/альбомами по offset с учетом смещения после удаления';

    private $client;
    private $token;
    private $ownerDomain;
    private $ownerId;
    private $isDryRun;
    private $isForce;
    private $stopTimestamp;
    private $batchSize;
    private $maxBatches;

    public function handle()
    {
        $this->ownerDomain = 'keytobaikal';
        $this->ownerId = -137776330;
        $startOffset = (int)$this->option('start_offset');
        $count = (int)$this->option('count');
        $stopDate = $this->option('stop-date');
        $this->token = 'tokrn';
        $this->isDryRun = $this->option('dry-run');
        $this->isForce = $this->option('force');

        $batchSize = 100;
        $maxBatches = 0;

        if (!$this->token) {
            $this->error('VK API токен не найден. Укажите через --token или настройте в config/services.php');
            return 1;
        }

        if ($stopDate) {
            $this->stopTimestamp = Carbon::parse($stopDate)->endOfDay()->timestamp;
        }

        $this->client = new Client();
        try {
            $this->info("=== НАСТРОЙКИ ===");
            $this->info("Владелец стены: {$this->ownerId}");
            $this->info("Стартовый offset: {$startOffset}");
            $this->info("Размер батча: {$batchSize}");
            $this->info("Максимум батчей: " . ($maxBatches > 0 ? $maxBatches : 'без лимита'));
            $this->info("Режим: " . ($this->isDryRun ? 'ТЕСТ' : 'УДАЛЕНИЕ'));
            if ($stopDate) {
                $this->info("Остановка при: {$stopDate}");
            }
            $this->newLine();

            if (!$this->isDryRun && !$this->isForce) {
                if (!$this->confirm('Продолжить?')) {
                    return 0;
                }
            }

            $currentOffset = $startOffset;
            $batchCount = 0;
            $totalProcessed = 0;
            $totalDeleted = 0;
            $totalSkipped = 0;
            $totalErrors = 0;

            while (true) {
                // Проверяем лимит батчей
                if ($maxBatches > 0 && $batchCount >= $maxBatches) {
                    $this->info("Достигнут лимит батчей: {$maxBatches}");
                    break;
                }

                $batchCount++;
                $this->info("\n=== БАТЧ #{$batchCount} (offset: {$currentOffset}) ===");

                // Получаем батч постов
                $posts = $this->getPostsBatch($currentOffset, $batchSize);

                if (empty($posts)) {
                    $this->info("Посты не найдены. Конец стены.");
                    break;
                }

                $this->info("Получено постов в батче: " . count($posts));

                // Обрабатываем посты в батче
                $batchStats = $this->processBatch($posts);

                $totalProcessed += $batchStats['processed'];
                $totalDeleted += $batchStats['deleted'];
                $totalSkipped += $batchStats['skipped'];
                $totalErrors += $batchStats['errors'];

                // Показываем статистику батча
                $this->showBatchStats($batchCount, $batchStats);

                // Рассчитываем новый offset с учетом удалений
                if ($this->isDryRun) {
                    // В тестовом режиме симулируем смещение
                    $currentOffset += $batchSize - $batchStats['deleted'];
                } else {
                    // После реального удаления offset сдвигается
                    $currentOffset += $batchSize - $batchStats['deleted'];
                }

                $this->line("Новый offset для следующего батча: {$currentOffset}");

                // Проверяем дату остановки (по последнему посту в батче)
                if ($this->stopTimestamp && !empty($posts)) {
                    $lastPost = end($posts);
                    if ($lastPost['date'] < $this->stopTimestamp) {
                        $lastDate = Carbon::createFromTimestamp($lastPost['date'])->format('Y-m-d H:i:s');
                        $this->info("Достигнута дата остановки: {$lastDate}");
                        break;
                    }
                }

                // Пауза между батчами
                usleep(100 *1000);
            }

            $this->showFinalStats($batchCount, $totalProcessed, $totalDeleted, $totalSkipped, $totalErrors);

            return 0;

        } catch (\Exception $e) {
            $this->error('Ошибка: ' . $e->getMessage());
            return 1;
        }
    }

    private function getPostsBatch($offset, $count)
    {
        try {
            $response = $this->client->get('https://api.vk.com/method/wall.get', [
                'query' => [
                    'owner_id' => $this->ownerId,
                    'count' => $count,
                    'offset' => $offset,
                    'access_token' => $this->token,
                    'v' => '5.131'
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            if (isset($data['error'])) {
                $this->error("Ошибка получения батча (offset {$offset}): " . $data['error']['error_msg']);
                return [];
            }

            return $data['response']['items'] ?? [];

        } catch (\Exception $e) {
            $this->error("Ошибка запроса батча: " . $e->getMessage());
            return [];
        }
    }

    private function processBatch($posts)
    {
        $stats = [
            'processed' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'errors' => 0
        ];

        foreach ($posts as $post) {
            $stats['processed']++;

            $postDate = Carbon::createFromTimestamp($post['date'])->format('Y-m-d H:i:s');
            $text = mb_substr(str_replace("\n", " ", $post['text']), 0, 40);

            $this->line("  ID {$post['id']} | {$postDate} | {$text}...");

            if ($this->hasMediaAttachments($post) || 1) {
                if ($this->isDryRun) {
                    $this->line("    → [ТЕСТ] Будет удален");
                    $stats['deleted']++;
                } else {
                    if ($this->deletePost($post['id'])) {
                        $this->line("    → ✓ Удален");
                        $stats['deleted']++;
                        // Небольшая пауза после удаления
                        usleep(100 * 1000); // 100мс
                    } else {
                        $this->line("    → ✗ Ошибка удаления");
                        $stats['errors']++;
                    }
                }
            } else {
                $this->line("    → Без медиа");
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    private function hasMediaAttachments($post)
    {
        if (empty($post['attachments'])) {
            return false;
        }

        $mediaTypes = ['photo', 'album'];
        foreach ($post['attachments'] as $attachment) {
            if (in_array($attachment['type'], $mediaTypes)) {
                return true;
            }
        }

        return false;
    }

    private function deletePost($postId)
    {
        try {
            $response = $this->client->get('https://api.vk.com/method/wall.delete', [
                'query' => [
                    'owner_id' => $this->ownerId,
                    'post_id' => $postId,
                    'access_token' => $this->token,
                    'v' => '5.131'
                ]
            ]);

            $data = json_decode($response->getBody(), true);

            if (isset($data['error'])) {
                $this->line("      Ошибка: " . $data['error']['error_msg']);
                return false;
            }

            return $data['response'] == 1;

        } catch (\Exception $e) {
            $this->line("      Исключение: " . $e->getMessage());
            return false;
        }
    }

    private function showBatchStats($batchNumber, $stats)
    {
        $this->info("Статистика батча #{$batchNumber}:");
        $this->info("  Обработано: {$stats['processed']}");
        $this->info("  Удалено: {$stats['deleted']}");
        $this->info("  Пропущено: {$stats['skipped']}");
        $this->info("  Ошибок: {$stats['errors']}");
    }

    private function showFinalStats($batches, $processed, $deleted, $skipped, $errors)
    {
        $this->newLine(2);
        $this->info("=== ИТОГОВАЯ СТАТИСТИКА ===");
        $this->info("Обработано батчей: {$batches}");
        $this->info("Всего постов обработано: {$processed}");
        $this->info("Всего удалено: {$deleted}");
        $this->info("Всего пропущено: {$skipped}");
        $this->info("Всего ошибок: {$errors}");

        if ($processed > 0) {
            $deletePercent = round(($deleted / $processed) * 100, 1);
            $this->info("Процент удаленных: {$deletePercent}%");
        }

        if ($this->isDryRun) {
            $this->warn("Это был тестовый запуск!");
        }
    }
}
