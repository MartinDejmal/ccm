<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (json_decode(file_get_contents(database_path('data/catalogs.json')), true, 512, JSON_THROW_ON_ERROR) as $table => $rows) {
            foreach ($rows as $row) {
                DB::table($table)->updateOrInsert(isset($row['code']) ? ['code' => $row['code']] : ['id' => $row['id']], $row);
            }
        }
    }

    public function down(): void {} // Catalogs belong to schema; do not delete referenced values.
};
