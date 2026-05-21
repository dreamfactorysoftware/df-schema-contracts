<?php

namespace DreamFactory\Core\SchemaContracts\Adapters;

use DreamFactory\Core\Contracts\ServiceInterface;
use DreamFactory\Core\SchemaContracts\Contracts\CanonicalSchemaAdapter;
use RuntimeException;

class AdapterRegistry
{
    /** @var CanonicalSchemaAdapter[] */
    protected array $adapters = [];

    public function register(CanonicalSchemaAdapter $adapter): void
    {
        $this->adapters[$adapter->name()] = $adapter;
    }

    /**
     * Resolve the adapter that should describe this service. Highest priority
     * wins among adapters that report `supports() === true`.
     */
    public function for(ServiceInterface $service): CanonicalSchemaAdapter
    {
        $candidates = array_filter(
            $this->adapters,
            fn (CanonicalSchemaAdapter $a) => $a->supports($service)
        );

        if (empty($candidates)) {
            throw new RuntimeException(
                "No canonical schema adapter is registered for service '{$service->getName()}'."
            );
        }

        usort(
            $candidates,
            fn (CanonicalSchemaAdapter $a, CanonicalSchemaAdapter $b) => $b->priority() <=> $a->priority()
        );

        return array_values($candidates)[0];
    }

    /** @return CanonicalSchemaAdapter[] */
    public function all(): array
    {
        return array_values($this->adapters);
    }
}
