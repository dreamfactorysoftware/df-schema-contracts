<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSchemaContractSnapshotTable extends Migration
{
    public function up()
    {
        Schema::create('schema_contract_snapshot', function (Blueprint $t) {
            $t->increments('id');

            // Owning service. Cascade delete: orphan snapshots have no value.
            $t->integer('service_id')->unsigned();
            $t->foreign('service_id')->references('id')->on('service')->onDelete('cascade');

            // Denormalised for export portability and faster lookups without
            // joining service for every drift check.
            $t->string('service_name', 128);

            // Table identity: (catalog, schema, name) per the canonical
            // identity rules. catalog and schema may be null for connectors
            // that do not use them (SQLite always null; MySQL puts the
            // database name in schema; Snowflake uses all three).
            //
            // Length capped at 128 — covers MySQL (64), Postgres (63), SQL
            // Server (128), Oracle (128). Snowflake (255-char identifiers)
            // is a known follow-up; very long identifiers would need a
            // table_identity_hash column added to the composite indexes.
            $t->string('table_catalog', 128)->nullable();
            $t->string('table_schema', 128)->nullable();
            $t->string('table_name', 128);

            $t->string('object_type', 32); // 'table' | 'view' | 'materialized_view' | 'foreign_table'

            // Per-table contract version. Starts at 1 on first lock; promote
            // bumps. Versions are immutable once written.
            $t->integer('contract_version')->unsigned();

            // SHA-256 hex of schema_json. Lets equality checks across
            // snapshots avoid parsing and comparing the JSON.
            $t->string('schema_hash', 64);

            // Canonical Table JSON as produced by the normalizer. MEDIUMTEXT
            // is 16MB-max; a single canonical table is typically <100KB even
            // for wide schemas, so we have ~3 orders of magnitude of headroom.
            $t->mediumText('schema_json');

            // Lifecycle. Candidates are computed-not-persisted, so this column
            // only ever holds 'active' or 'archived'. Exactly one 'active' row
            // is allowed per table identity; enforced at the application
            // layer (MySQL has no partial unique index).
            $t->string('status', 16);

            $t->timestamp('created_date')->nullable();
            $t->timestamp('last_modified_date')->useCurrent();

            $t->integer('created_by_id')->unsigned()->nullable();
            $t->foreign('created_by_id')->references('id')->on('user')->onDelete('set null');

            $t->integer('last_modified_by_id')->unsigned()->nullable();
            $t->foreign('last_modified_by_id')->references('id')->on('user')->onDelete('set null');

            // Unique: every (service, table identity, version) appears once.
            $t->unique(
                ['service_id', 'table_catalog', 'table_schema', 'table_name', 'contract_version'],
                'sc_snapshot_version_uq'
            );

            // Fast lookup: active snapshot for a service / table.
            $t->index(
                ['service_id', 'table_catalog', 'table_schema', 'table_name', 'status'],
                'sc_snapshot_lookup_idx'
            );

            // Fast service-wide drift sweeps.
            $t->index(['service_id', 'status'], 'sc_snapshot_service_status_idx');

            // Cross-table equality (drift sentinel; same hash = identical canonical JSON).
            $t->index('schema_hash', 'sc_snapshot_hash_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('schema_contract_snapshot');
    }
}
