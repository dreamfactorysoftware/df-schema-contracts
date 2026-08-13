<?php

namespace DreamFactory\Core\SchemaContracts\Tests\Integration;

use DreamFactory\Core\SchemaContracts\Models\SchemaContractService;
use DreamFactory\Core\Testing\TestCase;

/**
 * Guards the product rule that schema-contract enforcement is NON-BLOCKING by
 * default. A service with no contract config must never reject or reshape
 * traffic. Blocking (strict) is opt-in only.
 *
 * This asserts the actual resolution, not just the constant, so that changing
 * the `?? ENFORCE_OFF` fallback to a blocking level fails here.
 */
class DefaultEnforcementTest extends TestCase
{
    public function testUnconfiguredServiceEnforcementDefaultsToOff(): void
    {
        $resolved = SchemaContractService::enforcementForName('no-such-service-' . __FUNCTION__);

        $this->assertSame(SchemaContractService::ENFORCE_OFF, $resolved);
        $this->assertNotSame(SchemaContractService::ENFORCE_STRICT, $resolved,
            'An unconfigured service must not default to the blocking (strict) level.');
    }

    public function testUnconfiguredServiceModeDefaultsToNone(): void
    {
        $this->assertSame(SchemaContractService::MODE_NONE, SchemaContractService::modeFor(987654321));
    }

    public function testOffIsTheDefaultAndStrictIsTheOnlyBlockingLevel(): void
    {
        // The sentinel that the DB-null fallback returns.
        $this->assertSame('off', SchemaContractService::ENFORCE_OFF);
        $this->assertSame('strict', SchemaContractService::ENFORCE_STRICT);

        // Enforcement levels exist and off is the safe/default one. Only strict
        // rejects writes (see EnforcementEventHandler::handlePreProcess, which
        // returns early unless the level is ENFORCE_STRICT).
        $this->assertContains(SchemaContractService::ENFORCE_OFF, SchemaContractService::ENFORCEMENT_LEVELS);
        $this->assertContains(SchemaContractService::ENFORCE_SHAPE_RESPONSE, SchemaContractService::ENFORCEMENT_LEVELS);
        $this->assertContains(SchemaContractService::ENFORCE_STRICT, SchemaContractService::ENFORCEMENT_LEVELS);
    }
}
