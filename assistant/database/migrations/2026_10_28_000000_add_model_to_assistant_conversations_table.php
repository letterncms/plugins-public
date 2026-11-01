<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_conversations', function (Blueprint $table) {
            // The model picked in the chat; null follows the default in Settings → Assistant.
            $table->string('model', 64)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_conversations', function (Blueprint $table) {
            $table->dropColumn('model');
        });
    }
};
