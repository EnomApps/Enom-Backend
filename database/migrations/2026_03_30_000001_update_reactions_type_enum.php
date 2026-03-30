<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE reactions MODIFY COLUMN type ENUM('like', 'love', 'haha', 'wow', 'sad', 'angry') NOT NULL DEFAULT 'like'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE reactions MODIFY COLUMN type ENUM('like', 'love', 'haha', 'wow') NOT NULL");
    }
};
