<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique()->nullable()->after('name');
            $table->string('profession')->nullable()->after('location');
            $table->string('country')->nullable()->after('profession');
            $table->string('city')->nullable()->after('country');
            $table->string('region')->nullable()->after('city');
            $table->json('content_preferences')->nullable()->after('region');
            $table->string('social_personality')->nullable()->after('content_preferences');
            $table->json('languages')->nullable()->after('social_personality');
            $table->enum('privacy_setting', ['public', 'private', 'friends_only'])->default('public')->after('languages');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'username', 'profession', 'country', 'city', 'region',
                'content_preferences', 'social_personality', 'languages', 'privacy_setting',
            ]);
        });
    }
};
