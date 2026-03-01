<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            
            $table->enum('gender', ['male','female','other'])->after('password');
            $table->date('dob')->after('gender');
            $table->text('bio')->nullable()->after('dob');
            $table->string('location')->nullable()->after('bio');
            $table->string('profile_image')->nullable()->after('location');
            $table->boolean('is_verified')->default(false)->after('profile_image');
            $table->enum('status', ['active','inactive','blocked'])->default('active')->after('is_verified');
            $table->timestamp('last_login_at')->nullable()->after('status');

        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'gender',
                'dob',
                'bio',
                'location',
                'profile_image',
                'is_verified',
                'status',
                'last_login_at'
            ]);
        });
    }
};