<?php

namespace DreamFactory\Core\SchemaContracts\Console;

use DreamFactory\Core\Models\Service;
use DreamFactory\Core\SchemaContracts\Models\SchemaContractService;
use DreamFactory\Core\SchemaContracts\Models\SchemaContractSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Apply the `archive_retention_count` policy from `schema_contract_service`
 * to actually delete surplus archived snapshots. Active snapshots are
 * never touched; services with NULL retention count are skipped (keep
 * forever is the default).
 *
 * See docs/SYSTEM_API.md "Resolved decisions §4" for the design rationale —
 * pruning is intentionally manual rather than automatic-on-write so it
 * can't surprise customers at 2am.
 */
class PruneCommand extends Command
{
    protected $signature = 'schema-contracts:prune
        {--service= : Restrict to one service by name (default: every service with a retention policy)}
        {--dry-run : Report what would be deleted without applying}';

    protected $description = 'Delete surplus archived snapshots per service-level archive_retention_count.';

    public function handle(): int
    {
        $serviceFilter = $this->option('service');
        $dryRun        = (bool) $this->option('dry-run');

        $configRows = SchemaContractService::query()
            ->whereNotNull('archive_retention_count')
            ->when($serviceFilter, fn ($q) => $q->where('service_name', $serviceFilter))
            ->get();

        if ($configRows->isEmpty()) {
            $msg = $serviceFilter
                ? "No retention policy configured for service '{$serviceFilter}'."
                : 'No services have archive_retention_count set; nothing to prune.';
            $this->info($msg);
            return self::SUCCESS;
        }

        $totalDeleted = 0;
        $servicesTouched = 0;

        foreach ($configRows as $config) {
            $retention = (int) $config->archive_retention_count;
            if ($retention < 0) {
                $this->warn("  {$config->service_name}: invalid retention_count={$retention}, skipping");
                continue;
            }

            $deletedForService = 0;

            // Group archived snapshots by table identity and trim per group.
            // We can't use a single DELETE since SQL retention is per-table,
            // not per-service.
            $identities = SchemaContractSnapshot::query()
                ->where('service_id', $config->service_id)
                ->where('status', SchemaContractSnapshot::STATUS_ARCHIVED)
                ->select('table_catalog', 'table_schema', 'table_name')
                ->distinct()
                ->get();

            foreach ($identities as $identity) {
                $archived = SchemaContractSnapshot::query()
                    ->where('service_id', $config->service_id)
                    ->where('table_name', $identity->table_name)
                    ->when($identity->table_catalog === null,
                        fn ($q) => $q->whereNull('table_catalog'),
                        fn ($q) => $q->where('table_catalog', $identity->table_catalog))
                    ->when($identity->table_schema === null,
                        fn ($q) => $q->whereNull('table_schema'),
                        fn ($q) => $q->where('table_schema', $identity->table_schema))
                    ->where('status', SchemaContractSnapshot::STATUS_ARCHIVED)
                    ->orderByDesc('contract_version')
                    ->get();

                if ($archived->count() <= $retention) {
                    continue;
                }

                $toDelete = $archived->slice($retention);
                $versions = $toDelete->pluck('contract_version')->all();

                $tableLabel = $config->service_name . ' / ' . ($identity->table_schema
                    ? "{$identity->table_schema}.{$identity->table_name}"
                    : $identity->table_name);

                $this->line(sprintf(
                    '  %s: keeping %d, %s %d archived %s (v%s)',
                    $tableLabel,
                    $retention,
                    $dryRun ? 'would delete' : 'deleting',
                    $toDelete->count(),
                    $toDelete->count() === 1 ? 'version' : 'versions',
                    implode(', v', $versions)
                ));

                if (!$dryRun) {
                    SchemaContractSnapshot::query()
                        ->whereIn('id', $toDelete->pluck('id')->all())
                        ->delete();
                }

                $deletedForService += $toDelete->count();
            }

            if ($deletedForService > 0) {
                $servicesTouched++;
                $totalDeleted += $deletedForService;
            } else {
                $this->line("  {$config->service_name}: nothing to prune (within retention)");
            }
        }

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        $this->info(sprintf(
            '%s %d archived snapshot%s across %d service%s.',
            $verb,
            $totalDeleted,
            $totalDeleted === 1 ? '' : 's',
            $servicesTouched,
            $servicesTouched === 1 ? '' : 's'
        ));

        if ($dryRun && $totalDeleted > 0) {
            $this->comment('Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
