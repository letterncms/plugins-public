<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->string('name');
            $table->json('redirect_uris');
            $table->timestamps();
        });

        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->string('token_hash', 64)->primary();
            $table->ulid('api_token_id')->index();
            $table->string('client_id', 512);
            $table->ulid('user_id');
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_refresh_tokens');
        Schema::dropIfExists('oauth_clients');
    }
};
