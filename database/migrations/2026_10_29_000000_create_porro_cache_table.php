<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Porro's own cache store: suspicious activity, the Cloudflare connection
 * and nginx check-ins. Separate from the app's cache so a deploy's
 * cache:clear doesn't wipe them. Same shape as Laravel's cache tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('porro_cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });

        Schema::create('porro_cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('porro_cache');
        Schema::dropIfExists('porro_cache_locks');
    }
};
