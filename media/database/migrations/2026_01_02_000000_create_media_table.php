<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('path', 512);
            $table->string('name');
            $table->string('filename');
            $table->string('mime', 128);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('alt', 1000)->nullable();
            $table->string('caption', 1000)->nullable();
            $table->unsignedTinyInteger('focal_x')->default(50);
            $table->unsignedTinyInteger('focal_y')->default(50);
            $table->string('folder', 255)->default('');
            $table->string('hash', 64)->index();
            $table->json('meta')->nullable();
            $table->ulid('user_id')->nullable();
            $table->timestamps();

            $table->index(['folder', 'created_at']);
            $table->index('mime');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
