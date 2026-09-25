<?php

namespace DreamFactory\Core\SchemaContracts\Enrichers;

use DreamFactory\Core\Contracts\DataModelEnricherInterface;
use DreamFactory\Core\SchemaContracts\Enforcement\ContractShape;
use DreamFactory\Core\SchemaContracts\Models\SchemaContractService;
use DreamFactory\Core\SchemaContracts\Models\SchemaContractSnapshot;
use DreamFactory\Core\Services\BaseRestService;

/**
 * Keeps the condensed data model (GET {service}/_spec?model=true, which MCP
 * get_data_model serves) consistent with runtime enforcement: when a service
 * shapes responses to its contracts, the model lists only the columns the
 * API will return, and its sample rows and enum values carry nothing else.
 * Without this the model described (and sampled) contract-hidden columns.
 */
class ContractModelEnricher implements DataModelEnricherInterface
{
    public function enrich(array $model, BaseRestService $service): array
    {
        if (SchemaContractService::enforcementForName($service->getName()) === SchemaContractService::ENFORCE_OFF) {
            return $model;
        }
        $tables = array_keys($model['tables'] ?? []);
        if ($tables === []) {
            return $model;
        }
        $snapshots = SchemaContractSnapshot::query()
            ->where('service_name', $service->getName())
            ->whereIn('table_name', $tables)
            ->where('status', SchemaContractSnapshot::STATUS_ACTIVE)
            ->get(['table_name', 'contract_version', 'schema_json']);

        $allowedByTable = [];
        foreach ($snapshots as $s) {
            $canonical = json_decode((string) $s->schema_json, true);
            if (is_array($canonical)) {
                $allowedByTable[$s->table_name] = ContractShape::allowedKeys($canonical);
            }
        }
        foreach ($allowedByTable as $table => $allowed) {
            $model['tables'][$table] = ContractShape::shapeModelTable($model['tables'][$table], $allowed);
        }
        if (isset($model['relationships']) && is_array($model['relationships'])) {
            foreach ($model['relationships'] as $table => $rels) {
                if (isset($allowedByTable[$table]) && is_array($rels)) {
                    $model['relationships'][$table] = array_values(array_filter(
                        $rels,
                        static fn ($r) => isset($allowedByTable[$table][(string) ($r['name'] ?? '')])
                    ));
                }
            }
        }

        return $model;
    }
}
