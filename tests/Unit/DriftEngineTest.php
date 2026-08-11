<?php

namespace DreamFactory\Core\SchemaContracts\Tests\Unit;

use DreamFactory\Core\SchemaContracts\Drift\DriftEngine;
use DreamFactory\Core\SchemaContracts\Drift\Kind;
use DreamFactory\Core\SchemaContracts\Drift\Severity;
use PHPUnit\Framework\TestCase;

/**
 * DriftEngine is pure: two canonical Table arrays in, a drift report out.
 * These tests lock the severity contract that external CI gates match on
 * (the Kind:: strings), so a change in classification is caught here rather
 * than by a customer's release gate.
 */
class DriftEngineTest extends TestCase
{
    private DriftEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new DriftEngine();
    }

    // --- helpers ---------------------------------------------------------

    private function field(string $name, array $attrs = []): array
    {
        return array_merge(['name' => $name, 'type' => 'string'], $attrs);
    }

    private function table(array $fields = [], array $attrs = []): array
    {
        return array_merge([
            'name'          => 't',
            'type'          => 'table',
            'fields'        => $fields,
            'primary_key'   => ['id'],
            'relationships' => [],
        ], $attrs);
    }

    /** Find the single change of a given kind, asserting exactly one exists. */
    private function changeOfKind(array $report, string $kind): array
    {
        $matches = array_values(array_filter($report['changes'], fn ($c) => $c['kind'] === $kind));
        $this->assertCount(1, $matches, "expected exactly one '{$kind}' change, got: " . json_encode(array_column($report['changes'], 'kind')));
        return $matches[0];
    }

    // --- no drift --------------------------------------------------------

    public function testIdenticalTablesHaveNoDrift(): void
    {
        $t = $this->table([$this->field('id', ['type' => 'id']), $this->field('name')]);
        $report = $this->engine->compareTable($t, $t);

        $this->assertFalse($report['has_drift']);
        $this->assertFalse($report['has_breaking']);
        $this->assertSame(0, $report['summary']['total_changes']);
        $this->assertSame([], $report['changes']);
    }

    // --- field add / remove ---------------------------------------------

    public function testFieldAddedIsAdditive(): void
    {
        $active = $this->table([$this->field('id')]);
        $live   = $this->table([$this->field('id'), $this->field('email')]);

        $report = $this->engine->compareTable($active, $live);
        $c = $this->changeOfKind($report, Kind::FIELD_ADDED);

        $this->assertSame(Severity::ADDITIVE, $c['severity']);
        $this->assertSame('t.email', $c['path']);
        $this->assertTrue($report['has_drift']);
        $this->assertFalse($report['has_breaking']);
    }

    public function testFieldRemovedIsBreaking(): void
    {
        $active = $this->table([$this->field('id'), $this->field('email')]);
        $live   = $this->table([$this->field('id')]);

        $report = $this->engine->compareTable($active, $live);
        $c = $this->changeOfKind($report, Kind::FIELD_REMOVED);

        $this->assertSame(Severity::BREAKING, $c['severity']);
        $this->assertTrue($report['has_breaking']);
    }

    public function testRenameSurfacesAsRemovePlusAdd(): void
    {
        // Documented: rename detection is NOT performed.
        $active = $this->table([$this->field('id'), $this->field('email')]);
        $live   = $this->table([$this->field('id'), $this->field('email_address')]);

        $report = $this->engine->compareTable($active, $live);

        $this->changeOfKind($report, Kind::FIELD_REMOVED);
        $this->changeOfKind($report, Kind::FIELD_ADDED);
        $this->assertSame(2, $report['summary']['total_changes']);
        $this->assertTrue($report['has_breaking']); // the removal is breaking
    }

    // --- type / nullability ---------------------------------------------

    public function testTypeChangeIsBreaking(): void
    {
        $active = $this->table([$this->field('age', ['type' => 'integer'])]);
        $live   = $this->table([$this->field('age', ['type' => 'string'])]);

        $c = $this->changeOfKind($this->engine->compareTable($active, $live), Kind::FIELD_TYPE_CHANGED);
        $this->assertSame(Severity::BREAKING, $c['severity']);
        $this->assertSame('integer', $c['detail']['from']);
        $this->assertSame('string', $c['detail']['to']);
    }

    public function testNullableRelaxedIsAdditiveTightenedIsBreaking(): void
    {
        $notNull = $this->table([$this->field('email', ['allow_null' => false])]);
        $nullable = $this->table([$this->field('email', ['allow_null' => true])]);

        $relaxed = $this->changeOfKind($this->engine->compareTable($notNull, $nullable), Kind::FIELD_NULLABLE_RELAXED);
        $this->assertSame(Severity::ADDITIVE, $relaxed['severity']);

        $tightened = $this->changeOfKind($this->engine->compareTable($nullable, $notNull), Kind::FIELD_NULLABLE_TIGHTENED);
        $this->assertSame(Severity::BREAKING, $tightened['severity']);
    }

    // --- length (string-shaped only) ------------------------------------

    public function testLengthIncreaseAdditiveDecreasePotentiallyBreaking(): void
    {
        $short = $this->table([$this->field('name', ['type' => 'string', 'length' => 50])]);
        $long  = $this->table([$this->field('name', ['type' => 'string', 'length' => 255])]);

        $inc = $this->changeOfKind($this->engine->compareTable($short, $long), Kind::FIELD_LENGTH_INCREASED);
        $this->assertSame(Severity::ADDITIVE, $inc['severity']);

        $dec = $this->changeOfKind($this->engine->compareTable($long, $short), Kind::FIELD_LENGTH_REDUCED);
        $this->assertSame(Severity::POTENTIALLY_BREAKING, $dec['severity']);
    }

    public function testLengthChangeIgnoredForNonStringType(): void
    {
        // Length is meaningless for integer-shaped types and must not register.
        $a = $this->table([$this->field('n', ['type' => 'integer', 'length' => 4])]);
        $b = $this->table([$this->field('n', ['type' => 'integer', 'length' => 8])]);

        $report = $this->engine->compareTable($a, $b);
        $this->assertFalse($report['has_drift'], 'integer length change should not be reported');
    }

    // --- default / required / unique ------------------------------------

    public function testDefaultRemovedIsPotentiallyBreakingChangedIsAdditive(): void
    {
        $withDefault = $this->table([$this->field('status', ['default' => 'active'])]);
        $noDefault   = $this->table([$this->field('status', ['default' => null])]);
        $otherDefault = $this->table([$this->field('status', ['default' => 'pending'])]);

        $removed = $this->changeOfKind($this->engine->compareTable($withDefault, $noDefault), Kind::FIELD_DEFAULT_REMOVED);
        $this->assertSame(Severity::POTENTIALLY_BREAKING, $removed['severity']);

        $changed = $this->changeOfKind($this->engine->compareTable($withDefault, $otherDefault), Kind::FIELD_DEFAULT_CHANGED);
        $this->assertSame(Severity::ADDITIVE, $changed['severity']);
    }

    public function testRequiredAddedIsBreakingRemovedIsAdditive(): void
    {
        $optional = $this->table([$this->field('email', ['required' => false])]);
        $required = $this->table([$this->field('email', ['required' => true])]);

        $added = $this->changeOfKind($this->engine->compareTable($optional, $required), Kind::FIELD_REQUIRED_ADDED);
        $this->assertSame(Severity::BREAKING, $added['severity']);

        $removed = $this->changeOfKind($this->engine->compareTable($required, $optional), Kind::FIELD_REQUIRED_REMOVED);
        $this->assertSame(Severity::ADDITIVE, $removed['severity']);
    }

    public function testUniqueAddedIsPotentiallyBreaking(): void
    {
        $plain  = $this->table([$this->field('sku', ['is_unique' => false])]);
        $unique = $this->table([$this->field('sku', ['is_unique' => true])]);

        $c = $this->changeOfKind($this->engine->compareTable($plain, $unique), Kind::FIELD_UNIQUE_ADDED);
        $this->assertSame(Severity::POTENTIALLY_BREAKING, $c['severity']);
    }

    // --- enum ------------------------------------------------------------

    public function testEnumValueAddedPotentiallyBreakingRemovedBreaking(): void
    {
        $ab  = $this->table([$this->field('s', ['enum' => ['values' => ['a', 'b']]])]);
        $abc = $this->table([$this->field('s', ['enum' => ['values' => ['a', 'b', 'c']]])]);

        $added = $this->changeOfKind($this->engine->compareTable($ab, $abc), Kind::FIELD_ENUM_VALUE_ADDED);
        $this->assertSame(Severity::POTENTIALLY_BREAKING, $added['severity']);
        $this->assertSame(['c'], $added['detail']['added']);

        $removed = $this->changeOfKind($this->engine->compareTable($abc, $ab), Kind::FIELD_ENUM_VALUE_REMOVED);
        $this->assertSame(Severity::BREAKING, $removed['severity']);
        $this->assertSame(['c'], $removed['detail']['removed']);
    }

    public function testEnumConstraintAddedFromNullIsAdditive(): void
    {
        $none = $this->table([$this->field('s')]);
        $enum = $this->table([$this->field('s', ['enum' => ['values' => ['a', 'b']]])]);

        $c = $this->changeOfKind($this->engine->compareTable($none, $enum), Kind::FIELD_ENUM_VALUE_ADDED);
        $this->assertSame(Severity::ADDITIVE, $c['severity']);
    }

    // --- primary key / table / relationships ----------------------------

    public function testPrimaryKeyChangeIsBreaking(): void
    {
        $a = $this->table([$this->field('id')], ['primary_key' => ['id']]);
        $b = $this->table([$this->field('id')], ['primary_key' => ['id', 'tenant_id']]);

        $c = $this->changeOfKind($this->engine->compareTable($a, $b), Kind::PRIMARY_KEY_CHANGED);
        $this->assertSame(Severity::BREAKING, $c['severity']);
        $this->assertSame(['id', 'tenant_id'], $c['detail']['to']);
    }

    public function testTableTypeChangeBreakingLabelChangeCosmetic(): void
    {
        $tbl  = $this->table([], ['type' => 'table', 'label' => 'Orders']);
        $view = $this->table([], ['type' => 'view', 'label' => 'Orders']);
        $relabeled = $this->table([], ['type' => 'table', 'label' => 'Sales Orders']);

        $typeChange = $this->changeOfKind($this->engine->compareTable($tbl, $view), Kind::TABLE_TYPE_CHANGED);
        $this->assertSame(Severity::BREAKING, $typeChange['severity']);

        $labelChange = $this->changeOfKind($this->engine->compareTable($tbl, $relabeled), Kind::TABLE_LABEL_CHANGED);
        $this->assertSame(Severity::COSMETIC, $labelChange['severity']);
    }

    public function testRelationshipAddedAdditiveRemovedBreakingTargetChangeBreaking(): void
    {
        $rel = ['name' => 'customer', 'type' => 'belongs_to', 'ref_table' => 'customers', 'ref_field' => 'id'];
        $none = $this->table();
        $withRel = $this->table([], ['relationships' => [$rel]]);
        $retargeted = $this->table([], ['relationships' => [array_merge($rel, ['ref_table' => 'clients'])]]);

        $added = $this->changeOfKind($this->engine->compareTable($none, $withRel), Kind::RELATIONSHIP_ADDED);
        $this->assertSame(Severity::ADDITIVE, $added['severity']);

        $removed = $this->changeOfKind($this->engine->compareTable($withRel, $none), Kind::RELATIONSHIP_REMOVED);
        $this->assertSame(Severity::BREAKING, $removed['severity']);

        $target = $this->changeOfKind($this->engine->compareTable($withRel, $retargeted), Kind::RELATIONSHIP_TARGET_CHANGED);
        $this->assertSame(Severity::BREAKING, $target['severity']);
    }

    // --- report aggregation ---------------------------------------------

    public function testReportAggregatesCountsAcrossSeverities(): void
    {
        $active = $this->table([
            $this->field('id', ['type' => 'id']),
            $this->field('email', ['required' => true]), // will be removed -> breaking
            $this->field('name', ['label' => 'Name']),   // label change -> cosmetic
        ]);
        $live = $this->table([
            $this->field('id', ['type' => 'id']),
            $this->field('name', ['label' => 'Full Name']), // cosmetic
            $this->field('phone'),                            // added -> additive
        ]);

        $report = $this->engine->compareTable($active, $live);

        $this->assertTrue($report['has_drift']);
        $this->assertTrue($report['has_breaking']);
        $this->assertSame(1, $report['summary']['breaking_count']);   // email removed
        $this->assertSame(1, $report['summary']['additive_count']);   // phone added
        $this->assertSame(1, $report['summary']['cosmetic_count']);   // name label
        $this->assertSame(3, $report['summary']['total_changes']);
    }

    public function testBuildReportOnEmptyChangeListIsCleanEnvelope(): void
    {
        $report = $this->engine->buildReport([]);
        $this->assertFalse($report['has_drift']);
        $this->assertFalse($report['has_breaking']);
        $this->assertSame(0, $report['summary']['total_changes']);
    }
}
