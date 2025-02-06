<?php

namespace Modules\AdvBlocks\Database\Seeders;

use App\Enum\AccountingUnitsEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

class AdvBlocksDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('adv_block_types')->insert([
            'uuid' => Uuid::uuid1()->toString(),
            'name' => 'Ролики',
            'is_with_exact_time' => true,
            'accounting_unit' => AccountingUnitsEnum::SECOND,
        ]);
        DB::table('adv_block_types')->insert([
            'uuid' => Uuid::uuid1()->toString(),
            'name' => 'Баннер',
            'is_with_exact_time' => false,
            'accounting_unit' => AccountingUnitsEnum::RELEASE,
        ]);
        DB::table('adv_block_types')->insert([
            'uuid' => Uuid::uuid1()->toString(),
            'name' => 'Объявления',
            'is_with_exact_time' => false,
            'accounting_unit' => AccountingUnitsEnum::WORD,
        ]);
    }
}
