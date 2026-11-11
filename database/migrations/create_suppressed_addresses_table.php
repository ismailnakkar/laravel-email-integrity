<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppressed_addresses', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 254)->unique();
            $table->string('reason', 20);
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressed_addresses');
    }
};
