<?php

namespace DreamFactory\Core\SchemaContracts\Console;

use DreamFactory\Core\SchemaContracts\Adapters\AdapterRegistry;
use DreamFactory\Core\Services\ServiceManager;
use Illuminate\Console\Command;

class DescribeCommand extends Command
{
    protected $signature = 'schema-contracts:describe
        {service : The DreamFactory service name (e.g. dvdstore)}
        {--table= : Describe a single table instead of the whole service}
        {--pretty : Pretty-print the JSON output}';

    protected $description = 'Emit the canonical JSON schema for a SQL service or a single table.';

    public function handle(ServiceManager $services, AdapterRegistry $adapters): int
    {
        $serviceName = $this->argument('service');
        $service = $services->getService($serviceName);

        if ($service === null) {
            $this->error("Service '{$serviceName}' was not found.");
            return self::FAILURE;
        }

        try {
            $adapter = $adapters->for($service);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        try {
            $result = $this->option('table')
                ? $adapter->describeTable($service, $this->option('table'))
                : $adapter->describeService($service);
        } catch (\Throwable $e) {
            $this->error('Adapter failed: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
            return self::FAILURE;
        }

        $flags = JSON_UNESCAPED_SLASHES;
        if ($this->option('pretty')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $this->line(json_encode($result, $flags));
        return self::SUCCESS;
    }
}
