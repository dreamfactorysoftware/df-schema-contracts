<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSchemaContractServiceTable extends Migration
{
    public function up()
    {
        Schema::create('schema_contract_service', function (Blueprint $t) {
            $t->increments('id');

            // Service ownership. A service is "configured" only when a row
            // exists here; absent rows are read as mode = 'none'.
            $t->integer('service_id')->unsigned()->unique();
            $t->foreign('service_id')->references('id')->on('service')->onDelete('cascade');

            // Denormalised for export portability. service.id is
            // environment-specific; service.name round-trips through
            // backup/restore.
            $t->string('service_name', 128);

            // Mode column stores only 'auto' or 'strict'. The 'none' state is
            // represented by the absence of a row, so PATCH to mode='none'
            // deletes; PATCH to mode='auto'/'strict' upserts.
            $t->string('mode', 32);

            // Retention policy for archived snapshots. NULL = keep all
            // versions forever; integer N = keep last N per table. Pruning
            // is only run via `schema-contracts:prune`, never automatic.
            $t->integer('archive_retention_count')->unsigned()->nullable();

            $t->boolean('enabled')->default(true);

            $t->timestamp('created_date')->nullable();
            $t->timestamp('last_modified_date')->useCurrent();

            $t->integer('created_by_id')->unsigned()->nullable();
            $t->foreign('created_by_id')->references('id')->on('user')->onDelete('set null');

            $t->integer('last_modified_by_id')->unsigned()->nullable();
            $t->foreign('last_modified_by_id')->references('id')->on('user')->onDelete('set null');

            $t->index(['enabled', 'mode'], 'sc_service_enabled_mode_idx');
        });
    }

    public function down()
    {
        Schema::dropIfExists('schema_contract_service');
    }
}
