<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendar engine storage. Every tenant-owned table carries tenant_key
 * ('' = global) as part of its unique keys; NULL is avoided so the unique
 * indexes behave the same on SQLite, MySQL and PostgreSQL.
 *
 * Original terms (names, labels) are TEXT columns stored byte-for-byte; no
 * collation-based uniqueness is placed on them, because a case- or
 * accent-insensitive collation would treat distinct spellings as equal.
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
        Schema::create('nmc_calendars', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_key', 64)->default('');
            $table->string('identifier', 100);
            $table->timestamps();

            $table->unique(['tenant_key', 'identifier']);
        });

        Schema::create('nmc_calendar_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calendar_id')->constrained('nmc_calendars')->cascadeOnDelete();
            $table->string('version', 32);
            $table->string('status', 16)->default('draft');
            $table->string('driver', 100);
            $table->text('name_original');
            $table->string('name_language', 35)->nullable();
            $table->string('name_direction', 3)->default('ltr');
            $table->date('epoch_date')->nullable();
            $table->text('epoch_label')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->string('region')->nullable();
            $table->string('verification_status', 16)->default('draft');
            $table->json('metadata')->nullable();
            $table->char('content_hash', 64);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique(['calendar_id', 'version']);
            $table->index(['calendar_id', 'status']);
        });

        Schema::create('nmc_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('calendar_version_id')->constrained('nmc_calendar_versions')->cascadeOnDelete();
            $table->string('identifier', 100);
            $table->unsignedSmallInteger('sort');
            $table->text('name_original');
            $table->string('name_language', 35)->nullable();
            $table->string('continuity', 16)->default('assumed');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestamps();

            $table->unique(['calendar_version_id', 'identifier']);
        });

        Schema::create('nmc_cycle_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained('nmc_cycles')->cascadeOnDelete();
            $table->string('identifier', 100);
            $table->unsignedInteger('ordinal');
            $table->text('label_original');
            $table->string('label_language', 35)->nullable();
            $table->string('label_direction', 3)->default('ltr');
            $table->timestamps();

            $table->unique(['cycle_id', 'identifier']);
            $table->unique(['cycle_id', 'ordinal']);
        });

        Schema::create('nmc_calendar_anchors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained('nmc_cycles')->cascadeOnDelete();
            $table->foreignId('cycle_position_id')->constrained('nmc_cycle_positions')->cascadeOnDelete();
            $table->string('identifier', 100);
            $table->date('anchor_date');
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->json('source_ids')->nullable();
            $table->timestamps();

            $table->unique(['cycle_id', 'identifier']);
        });

        Schema::create('nmc_calendar_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained('nmc_cycles')->cascadeOnDelete();
            $table->foreignId('cycle_position_id')->constrained('nmc_cycle_positions')->cascadeOnDelete();
            $table->string('identifier', 100);
            $table->date('exception_date');
            $table->text('reason_original')->nullable();
            $table->string('reason_language', 35)->nullable();
            $table->json('source_ids')->nullable();
            $table->timestamps();

            $table->unique(['cycle_id', 'identifier']);
            $table->unique(['cycle_id', 'exception_date']);
        });

        Schema::create('nmc_observances', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_key', 64)->default('');
            $table->string('calendar_identifier', 100);
            $table->string('identifier', 100);
            $table->string('category', 100);
            $table->text('name_original');
            $table->string('name_language', 35)->nullable();
            $table->text('description_original')->nullable();
            $table->string('description_language', 35)->nullable();
            $table->string('visibility', 16)->default('public');
            $table->string('verification_status', 16)->default('draft');
            $table->integer('priority')->default(0);
            $table->json('source_ids')->nullable();
            $table->timestamps();

            $table->unique(['tenant_key', 'calendar_identifier', 'identifier']);
        });

        Schema::create('nmc_observance_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('observance_id')->constrained('nmc_observances')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort');
            $table->string('type', 100);
            $table->json('parameters');
            $table->integer('priority')->default(0);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestamps();

            $table->index(['observance_id', 'sort']);
        });

        // Alternate spellings, translations, pronunciation and audio for any
        // term column above. The original spelling stays on the owning row.
        Schema::create('nmc_localizations', function (Blueprint $table): void {
            $table->id();
            $table->string('owner_type', 32);
            $table->unsignedBigInteger('owner_id');
            $table->string('field', 32);
            $table->string('kind', 16);
            $table->string('locale', 35)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->text('text');
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });

        Schema::create('nmc_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_key', 64)->default('');
            $table->string('identifier', 100);
            $table->string('kind', 32);
            $table->text('title');
            $table->text('citation')->nullable();
            $table->string('custodian')->nullable();
            $table->string('url', 2048)->nullable();
            $table->date('recorded_on')->nullable();
            $table->string('status', 16)->default('submitted');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_key', 'identifier']);
        });

        Schema::create('nmc_verifications', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_key', 64)->default('');
            $table->string('subject_type', 32);
            $table->string('subject_key', 255);
            $table->string('status', 16);
            $table->string('actor')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at');

            $table->index(['tenant_key', 'subject_type', 'subject_key']);
        });

        Schema::create('nmc_revision_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_key', 64)->default('');
            $table->string('subject_type', 32);
            $table->string('subject_key', 255);
            $table->string('action', 32);
            $table->string('actor')->nullable();
            $table->json('changes')->nullable();
            $table->timestamp('created_at');

            $table->index(['tenant_key', 'subject_type', 'subject_key']);
        });
    }

    public function down(): void
    {
        foreach ([
            'nmc_revision_logs', 'nmc_verifications', 'nmc_sources', 'nmc_localizations',
            'nmc_observance_rules', 'nmc_observances', 'nmc_calendar_exceptions',
            'nmc_calendar_anchors', 'nmc_cycle_positions', 'nmc_cycles',
            'nmc_calendar_versions', 'nmc_calendars',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
