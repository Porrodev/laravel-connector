<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the connector has to keep for good: the site key made by "Connect
 * to Porro" in the control panel, encrypted with the app key. Not in
 * porro_cache: that table is a cache store, which a site can point at
 * Redis or clear, and losing the key would disconnect the site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('porro_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('porro_settings');
    }
};
