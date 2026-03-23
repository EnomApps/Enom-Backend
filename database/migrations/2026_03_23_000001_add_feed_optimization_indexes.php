<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Posts: faster feed queries filtering by visibility
        Schema::table('posts', function (Blueprint $table) {
            $table->index(['visibility', 'created_at']);
        });

        // Post media: faster eager loading by post_id
        Schema::table('post_media', function (Blueprint $table) {
            $table->index('post_id');
        });

        // Reactions: faster count queries
        Schema::table('reactions', function (Blueprint $table) {
            $table->index('post_id');
        });

        // Post views: faster count queries
        Schema::table('post_views', function (Blueprint $table) {
            $table->index('post_id');
        });

        // Saved posts: faster lookups
        Schema::table('saved_posts', function (Blueprint $table) {
            $table->index('post_id');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['visibility', 'created_at']);
        });
        Schema::table('post_media', function (Blueprint $table) {
            $table->dropIndex(['post_id']);
        });
        Schema::table('reactions', function (Blueprint $table) {
            $table->dropIndex(['post_id']);
        });
        Schema::table('post_views', function (Blueprint $table) {
            $table->dropIndex(['post_id']);
        });
        Schema::table('saved_posts', function (Blueprint $table) {
            $table->dropIndex(['post_id']);
        });
    }
};
