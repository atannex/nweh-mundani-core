<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive upgrade: review workflow, calendar status, observance
 * publication and per-date overrides, source visibility, and translations
 * that live outside versions. Nothing is dropped or renamed; every new
 * column has a default that keeps existing rows behaving as before
 * (calendars active, observances published, sources public).
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
        Schema::table('nmc_calendars', function (Blueprint $table): void {
            $table->string('status', 16)->default('active');
            $table->text('status_reason')->nullable();
            $table->timestamp('status_changed_at')->nullable();
        });

        Schema::table('nmc_calendar_versions', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable();
            $table->string('submitted_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->text('review_notes')->nullable();
            $table->string('published_by')->nullable();
        });

        Schema::table('nmc_observances', function (Blueprint $table): void {
            $table->string('status', 16)->default('published');
            $table->timestamp('published_at')->nullable();
            $table->index(['tenant_key', 'calendar_identifier', 'status']);
        });

        Schema::table('nmc_observance_rules', function (Blueprint $table): void {
            $table->index(['effective_from', 'effective_until']);
        });

        Schema::create('nmc_observance_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('observance_id')->constrained('nmc_observances')->cascadeOnDelete();
            $table->date('occurrence_date');
            $table->string('action', 16);
            $table->date('rescheduled_to')->nullable();
            $table->text('reason')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique(['observance_id', 'occurrence_date']);
            $table->index('rescheduled_to');
        });

        Schema::table('nmc_sources', function (Blueprint $table): void {
            $table->string('visibility', 16)->default('public');
        });

        // Renderings registered after publication, keyed by the stable term
        // path (calendar.nweh.cycle.traditional-days.position.ankoah), never
        // by the translated text. The authoritative original stays on the
        // version's own rows.
        Schema::create('nmc_translations', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_key', 64)->default('');
            $table->string('calendar_identifier', 100);
            $table->string('term_key', 255);
            $table->string('locale', 35);
            $table->text('text');
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_key', 'calendar_identifier', 'term_key', 'locale'], 'nmc_translations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nmc_translations');
        Schema::dropIfExists('nmc_observance_overrides');

        Schema::table('nmc_sources', fn (Blueprint $table) => $table->dropColumn('visibility'));
        Schema::table('nmc_observance_rules', fn (Blueprint $table) => $table->dropIndex(['effective_from', 'effective_until']));
        Schema::table('nmc_observances', function (Blueprint $table): void {
            $table->dropIndex(['tenant_key', 'calendar_identifier', 'status']);
            $table->dropColumn(['status', 'published_at']);
        });
        Schema::table('nmc_calendar_versions', fn (Blueprint $table) => $table->dropColumn(['submitted_at', 'submitted_by', 'reviewed_at', 'reviewed_by', 'review_notes', 'published_by']));
        Schema::table('nmc_calendars', fn (Blueprint $table) => $table->dropColumn(['status', 'status_reason', 'status_changed_at']));
    }
};
