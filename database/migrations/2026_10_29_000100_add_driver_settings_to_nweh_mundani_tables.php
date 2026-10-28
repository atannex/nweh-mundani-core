<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: driver settings on versions (e.g. {"rule": "julian"}) and the
 * names of unit values (month names), stored like positions: identifier,
 * original label, and further renderings in nmc_localizations.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('nweh-mundani.persistence.connection');

        return is_string($connection) ? $connection : null;
    }

    public function up(): void
    {
        Schema::table('nmc_calendar_versions', function (Blueprint $table): void {
            $table->json('parameters')->nullable();
        });

        Schema::create('nmc_calendar_names', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calendar_version_id')->constrained('nmc_calendar_versions')->cascadeOnDelete();
            $table->string('unit', 100);
            $table->string('identifier', 100);
            $table->unsignedSmallInteger('sort');
            $table->text('label_original');
            $table->string('label_language', 35)->nullable();
            $table->string('label_direction', 3)->default('ltr');
            $table->timestamps();

            $table->unique(['calendar_version_id', 'unit', 'identifier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nmc_calendar_names');
        Schema::table('nmc_calendar_versions', fn (Blueprint $table) => $table->dropColumn('parameters'));
    }
};
