<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Cache\DefinitionCache;
use Atannex\NwehMundani\Core\Persistence\StorageConnection;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 2.0.0 renames the bundled calendar "mundae-wabane" to "mundani-wabane".
 * Stored rows, observances, translations and audit entries that point at it
 * follow, tenant by tenant.
 *
 * A tenant's own "mundae-wabane" calendar is left alone when the tenant
 * already has a "mundani-wabane", or when renaming it would put it behind
 * the global calendar (tenancy.allow_shadowing off). Its observances and
 * translations stay with it. Published versions keep the name they were
 * published with; publish a new version to change it.
 */
return new class extends Migration
{
    private const string FROM = 'mundae-wabane';

    private const string TO = 'mundani-wabane';

    public function getConnection(): ?string
    {
        return StorageConnection::name();
    }

    public function up(): void
    {
        $this->rename(self::FROM, self::TO);
    }

    public function down(): void
    {
        $this->rename(self::TO, self::FROM);
    }

    private function rename(string $from, string $to): void
    {
        $db = DB::connection($this->getConnection());

        if (! $db->getSchemaBuilder()->hasTable('nmc_calendars')) {
            return;
        }

        $owners = $db->table('nmc_calendars')->where('identifier', $from)->pluck('tenant_key')->map(fn ($t) => (string) $t)->all();
        $globalTaken = $db->table('nmc_calendars')->where('tenant_key', '')->where('identifier', $to)->exists();
        $shadowing = (bool) config('nweh-mundani.tenancy.allow_shadowing', false);

        foreach ($this->tenantsReferencing($db, $from) as $tenant) {
            $keep = in_array($tenant, $owners, true)
                ? $db->table('nmc_calendars')->where('tenant_key', $tenant)->where('identifier', $to)->exists() || ($tenant !== '' && ! $shadowing)
                : $globalTaken;

            if ($keep) {
                continue;
            }

            $db->transaction(fn () => $this->renameFor($db, $tenant, $from, $to));
            $this->forgetCache($tenant, $from, $to);
        }
    }

    /** @return list<string> */
    private function tenantsReferencing(Connection $db, string $from): array
    {
        $tenants = [];

        foreach (['nmc_calendars' => 'identifier', 'nmc_observances' => 'calendar_identifier', 'nmc_translations' => 'calendar_identifier'] as $table => $column) {
            $tenants = [...$tenants, ...$db->table($table)->where($column, $from)->distinct()->pluck('tenant_key')->all()];
        }

        foreach (['nmc_verifications', 'nmc_revision_logs'] as $table) {
            $tenants = [...$tenants, ...$this->auditRows($db, $table, $from)->pluck('tenant_key')->all()];
        }

        return array_values(array_unique(array_map(fn ($t) => (string) $t, $tenants)));
    }

    /**
     * Audit entries about a calendar are keyed "id", "id@version", "id/observance" or "id/term key".
     *
     * @return Collection<int, stdClass>
     */
    private function auditRows(Connection $db, string $table, string $from, ?string $tenant = null): Collection
    {
        return $db->table($table)
            ->when($tenant !== null, fn ($q) => $q->where('tenant_key', $tenant))
            ->whereIn('subject_type', ['calendar', 'observance', 'translation', 'anchor'])
            ->where(fn ($q) => $q->where('subject_key', $from)->orWhere('subject_key', 'like', "{$from}@%")->orWhere('subject_key', 'like', "{$from}/%"))
            ->orderBy('id')
            ->get(['id', 'tenant_key', 'subject_key']);
    }

    private function renameFor(Connection $db, string $tenant, string $from, string $to): void
    {
        $db->table('nmc_calendars')->where('tenant_key', $tenant)->where('identifier', $from)->update(['identifier' => $to]);
        $db->table('nmc_observances')->where('tenant_key', $tenant)->where('calendar_identifier', $from)->update(['calendar_identifier' => $to]);

        $db->table('nmc_translations')->where('tenant_key', $tenant)->where('calendar_identifier', $from)->orderBy('id')
            ->get(['id', 'term_key'])
            ->each(fn (stdClass $row) => $db->table('nmc_translations')->where('id', $row->id)->update([
                'calendar_identifier' => $to,
                'term_key' => $this->swapPrefix((string) $row->term_key, "calendar.{$from}.", "calendar.{$to}."),
            ]));

        foreach (['nmc_verifications', 'nmc_revision_logs'] as $table) {
            $this->auditRows($db, $table, $from, $tenant)
                ->each(fn (stdClass $row) => $db->table($table)->where('id', $row->id)->update([
                    'subject_key' => str_replace("/calendar.{$from}.", "/calendar.{$to}.", $to.substr((string) $row->subject_key, strlen($from))),
                ]));
        }
    }

    private function swapPrefix(string $value, string $from, string $to): string
    {
        return str_starts_with($value, $from) ? $to.substr($value, strlen($from)) : $value;
    }

    private function forgetCache(string $tenant, string ...$ids): void
    {
        if (! app()->bound(DefinitionCache::class)) {
            return;
        }

        $cache = app(DefinitionCache::class);

        foreach ($ids as $id) {
            $cache->forgetCalendar($tenant, $id);
            $cache->forgetObservances($tenant, $id);
            $cache->forgetTranslations($tenant, $id);
        }
    }
};
