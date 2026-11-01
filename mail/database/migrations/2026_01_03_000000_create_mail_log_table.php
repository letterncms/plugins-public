<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_log', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('to', 1000);
            $table->string('subject', 500);
            $table->string('tag', 64)->nullable();
            $table->string('transport', 32);
            $table->string('status', 16);
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_log');
    }
};
