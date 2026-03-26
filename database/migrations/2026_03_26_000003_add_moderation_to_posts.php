<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->enum('moderation_status', ['approved', 'pending_review', 'rejected'])->default('approved')->after('longitude');
            $table->string('moderation_reason')->nullable()->after('moderation_status');

            $table->index('moderation_status');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['moderation_status']);
            $table->dropColumn(['moderation_status', 'moderation_reason']);
        });
    }
};
