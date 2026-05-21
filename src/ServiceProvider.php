<?php

namespace DreamFactory\Core\SchemaContracts;

use DreamFactory\Core\SchemaContracts\Adapters\AdapterRegistry;
use DreamFactory\Core\SchemaContracts\Adapters\DefaultSqlAdapter;
use DreamFactory\Core\SchemaContracts\Console\DescribeCommand;
use DreamFactory\Core\SchemaContracts\Console\PruneCommand;
use DreamFactory\Core\SchemaContracts\Resources\SchemaContractResource;
use DreamFactory\Core\System\Components\SystemResourceManager;
use DreamFactory\Core\System\Components\SystemResourceType;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AdapterRegistry::class, function () {
            $registry = new AdapterRegistry();
            $registry->register(new DefaultSqlAdapter());

            return $registry;
        });

        // Public binding for use by other DF packages that want to register
        // their own adapter without depending on this package's concrete class.
        $this->app->alias(AdapterRegistry::class, 'df.schema_contracts.adapters');

        // Register the schema_contract system resource under
        // /api/v2/system/schema_contract/...
        $this->app->resolving('df.system.resource', function (SystemResourceManager $rm) {
            $rm->addType(new SystemResourceType([
                'name'        => 'schema_contract',
                'label'       => 'Schema Contracts',
                'description' => 'Lock, diff, and promote SQL schema contracts.',
                'class_name'  => SchemaContractResource::class,
            ]));
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DescribeCommand::class,
                PruneCommand::class,
            ]);
        }
    }
}
