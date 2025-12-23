<?php

// Modules/Bitrix24/app/Traits/HasBitrix24Operations.php

namespace Modules\Bitrix24\app\Traits;

use Illuminate\Support\Facades\Cache;
use Modules\Bitrix24\app\Enums\Bitrix24FieldType;

trait HasBitrix24Operations
{
    /**
     * Очистка строки от HTML entities
     */
    protected function cleanString(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Поиск реквизита по GUID с кэшированием
     */
    protected function findRequisiteByGuid(string $guid): ?array
    {
        $cacheKey = "b24:requisite:{$guid}";

        return Cache::remember($cacheKey, 600, function () use ($guid) {
            $response = $this->b24Service->call('crm.requisite.list', [
                'filter' => [Bitrix24FieldType::REQUISITE_GUID->value() => $guid],
                'select' => ['ID', 'ENTITY_ID', 'ENTITY_TYPE_ID'],
            ]);

            return $response['result'][0] ?? null;
        });
    }

    /**
     * Поиск компании по ID реквизита
     */
    protected function findCompanyIdByRequisiteGuid(string $guid): ?int
    {
        $requisite = $this->findRequisiteByGuid($guid);

        return $requisite ? (int) $requisite['ENTITY_ID'] : null;
    }

    /**
     * Поиск контакта по GUID
     */
    protected function findContactByGuid(string $guid): ?int
    {
        $cacheKey = "b24:contact:{$guid}";

        return Cache::remember($cacheKey, 600, function () use ($guid) {
            $response = $this->b24Service->call('crm.contact.list', [
                'filter' => [Bitrix24FieldType::CONTACT_GUID->value() => $guid],
                'select' => ['ID'],
                'limit' => 1,
            ]);

            return isset($response['result'][0]['ID'])
                ? (int) $response['result'][0]['ID']
                : null;
        });
    }

    /**
     * Получение карты пользователей GUID -> ID
     */
    protected function getUsersMap(): array
    {
        return Cache::remember('b24:users_map', 3600, function () {
            $response = $this->b24Service->call('user.get', [
                'select' => ['ID', Bitrix24FieldType::USER_GUID->value()],
                'filter' => ['ACTIVE' => 'Y'],
            ]);

            $map = [];
            foreach ($response['result'] ?? [] as $user) {
                if (! empty($user[Bitrix24FieldType::USER_GUID->value()])) {
                    $map[$user[Bitrix24FieldType::USER_GUID->value()]] = (int) $user['ID'];
                }
            }

            return $map;
        });
    }

    /**
     * Поиск пользователя по GUID
     */
    protected function findUserIdByGuid(?string $guid): ?int
    {
        if (empty($guid)) {
            return null;
        }

        return $this->getUsersMap()[$guid] ?? null;
    }

    /**
     * Парсинг ФИО из полного имени
     */
    protected function parseFioFromFullName(?string $fullName): array
    {
        $fullName = $this->cleanString($fullName);

        if (empty($fullName)) {
            return ['last' => null, 'first' => null, 'second' => null];
        }

        $cleanedName = str_ireplace(['Индивидуальный предприниматель', 'ИП'], '', $fullName);
        $cleanedName = trim(preg_replace('/\s+/', ' ', $cleanedName));
        $parts = explode(' ', $cleanedName);

        return [
            'last' => $parts[0] ?? null,
            'first' => $parts[1] ?? null,
            'second' => $parts[2] ?? null,
        ];
    }

    /**
     * Определение типа реквизита по ИНН
     */
    protected function determinePresetId(?string $inn): int
    {
        if (empty($inn)) {
            return 1; // Организация по умолчанию
        }

        return (strlen($inn) === 12) ? 3 : 1; // 3 = ИП, 1 = Организация
    }

    /**
     * Сброс кэша при обновлении сущности
     */
    protected function invalidateCache(string $type, string $guid): void
    {
        Cache::forget("b24:{$type}:{$guid}");
    }
}
