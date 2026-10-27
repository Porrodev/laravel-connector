<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['porro_blocklist', 'porro_safelist'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                // Normalised: "203.0.113.0/24" or "2001:db8::1".
                $table->string('cidr', 64)->unique();
                $table->string('note')->default('');
                $table->string('source', 16)->default('manual');
                $table->unsignedBigInteger('added_at');
                $table->unsignedBigInteger('expires_at')->nullable()->index();
                $table->string('added_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('porro_blocklist');
        Schema::dropIfExists('porro_safelist');
    }
};
