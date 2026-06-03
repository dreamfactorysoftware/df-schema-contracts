<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the runtime-enforcement dimension to per-service contract config.
 *
 * This is independent of `mode` (none/auto/strict), which governs
 * promotion + OpenAPI source. `runtime_enforcement` governs whether locked
 * contracts shape live API traffic:
 *
 *   - off            : contracts are docs/drift only (default; current behavior)
 *   - shape_response : strip response fields not in the active contract
 *   - strict         : shape responses AND reject incompatible writes (future)
 */
class AddRuntimeEnforcementToSchemaContractService extends Migration
{
    public function up()
    {
        Schema::table('schema_contract_service', function (Blueprint $t) {
            $t->string('runtime_enforcement', 20)
                ->default('off')
                ->after('mode');
        });
    }

    public function down()
    {
        Schema::table('schema_contract_service', function (Blueprint $t) {
            $t->dropColumn('runtime_enforcement');
        });
    }
}
