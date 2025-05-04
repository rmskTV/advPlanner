<?php

namespace App\Repository;

use Illuminate\Support\Facades\DB;

class UserRepository
{
    /**
     * @param  array<string, string>  $data
     */
    public function create(array $data): bool
    {
        return DB::table('users')->insert([$data]);
    }

    /**
     * @param  array<string, string>  $data
     */
    public function update(array $data): int
    {
        return DB::table('users')->update([$data]);
    }
}

