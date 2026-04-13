<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mood_content_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('mood'); // Happy, Neutral, Low, Angry
            $table->string('tag');  // hashtag/content tag
            $table->float('weight', 3, 2)->default(0.5); // 0.0-1.0
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['mood', 'tag']);
            $table->index('mood');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mood_content_mappings');
    }
};
