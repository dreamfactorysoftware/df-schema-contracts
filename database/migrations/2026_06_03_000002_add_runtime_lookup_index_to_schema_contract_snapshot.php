<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index for the runtime-enforcement hot path.
 *
 * The enforcement handler looks up active snapshots by SERVICE NAME (the
 * identifier carried on the API event) + table name + status:
 *
 *   WHERE service_name = ? AND table_name = ? AND status = ?
 *
 * The original indexes all lead with service_id (for the admin/drift paths),
 * so this predicate could not use them and fell back to a full table scan on
 * every uncached enforced request. This index covers the runtime lookup.
 */
class AddRuntimeLookupIndexToSchemaContractSnapshot extends Migration
{
    public function up()
    {
        Schema::table('schema_contract_snapshot', function (Blueprint $t) {
            $t->index(
                ['service_name', 'table_name', 'status'],
                'sc_snapshot_runtime_lookup_idx'
            );
        });
    }

    public function down()
    {
        Schema::table('schema_contract_snapshot', function (Blueprint $t) {
            $t->dropIndex('sc_snapshot_runtime_lookup_idx');
        });
    }
}
