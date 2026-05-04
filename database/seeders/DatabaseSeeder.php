<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            \Modules\Core\Database\Seeders\CoreDatabaseSeeder::class,
        ]);
    }
}
