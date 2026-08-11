<?php

namespace DreamFactory\Core\SchemaContracts\Tests\Unit;

use DreamFactory\Core\Database\Schema\ColumnSchema as DfColumnSchema;
use DreamFactory\Core\Enums\DbSimpleTypes;
use DreamFactory\Core\SchemaContracts\Canonical\FieldSchema;
use DreamFactory\Core\SchemaContracts\Normalization\Normalizer;
use PHPUnit\Framework\TestCase;

/**
 * Normalizer maps DreamFactory connector schema objects into the canonical
 * shape. It needs df-core value objects but no database, so it stays a unit
 * test. These lock the canonical mapping rules: type collapse, the length-null
 * rule for numeric types, read-only derivation, and foreign-key refs.
 */
class NormalizerTest extends TestCase
{
    private Normalizer $norm;

    protected function setUp(): void
    {
        $this->norm = new Normalizer();
    }

    private function column(string $name, string $dfType, array $props = []): DfColumnSchema
    {
        $c = new DfColumnSchema(['name' => $name]);
        $c->type = $dfType;
        foreach ($props as $k => $v) {
            $c->{$k} = $v;
        }
        return $c;
    }

    public function testTypeCollapse(): void
    {
        $cases = [
            [DbSimpleTypes::TYPE_ID, FieldSchema::TYPE_ID],
            [DbSimpleTypes::TYPE_BIG_ID, FieldSchema::TYPE_ID],
            [DbSimpleTypes::TYPE_BIG_INT, FieldSchema::TYPE_INTEGER],
            [DbSimpleTypes::TYPE_TINY_INT, FieldSchema::TYPE_INTEGER],
            [DbSimpleTypes::TYPE_DECIMAL, FieldSchema::TYPE_NUMBER],
            [DbSimpleTypes::TYPE_DOUBLE, FieldSchema::TYPE_NUMBER],
            [DbSimpleTypes::TYPE_STRING, FieldSchema::TYPE_STRING],
            [DbSimpleTypes::TYPE_LONG_TEXT, FieldSchema::TYPE_TEXT],
            [DbSimpleTypes::TYPE_BOOLEAN, FieldSchema::TYPE_BOOLEAN],
            [DbSimpleTypes::TYPE_TIMESTAMP, FieldSchema::TYPE_DATETIME],
            [DbSimpleTypes::TYPE_DATE, FieldSchema::TYPE_DATE],
        ];

        foreach ($cases as [$dfType, $expected]) {
            $field = $this->norm->normalizeField($this->column('c', $dfType));
            $this->assertSame($expected, $field->type, "DF type '{$dfType}' should map to '{$expected}'");
        }
    }

    public function testLengthKeptForStringNulledForIntegerAndId(): void
    {
        $string = $this->norm->normalizeField($this->column('name', DbSimpleTypes::TYPE_STRING, ['size' => 255]));
        $this->assertSame(255, $string->length);

        // size on integer/id is a display-width hint, not a storage length,
        // and must be dropped so consumers are not misled.
        $int = $this->norm->normalizeField($this->column('n', DbSimpleTypes::TYPE_INTEGER, ['size' => 11]));
        $this->assertNull($int->length);

        $id = $this->norm->normalizeField($this->column('id', DbSimpleTypes::TYPE_ID, ['size' => 20]));
        $this->assertNull($id->length);
    }

    public function testPrecisionAndScalePassThrough(): void
    {
        $field = $this->norm->normalizeField($this->column('amount', DbSimpleTypes::TYPE_DECIMAL, ['precision' => 12, 'scale' => 2]));
        $this->assertSame(12, $field->precision);
        $this->assertSame(2, $field->scale);
    }

    public function testVirtualColumnIsReadOnlyAndGenerated(): void
    {
        $field = $this->norm->normalizeField($this->column('full_name', DbSimpleTypes::TYPE_STRING, [
            'isVirtual'  => true,
            'dbFunction' => [['function' => "first || ' ' || last"]],
        ]));

        $this->assertTrue($field->readOnly);
        $this->assertIsArray($field->generated);
        $this->assertSame('virtual', $field->generated['kind']);
        $this->assertSame("first || ' ' || last", $field->generated['expression']);
    }

    public function testAggregateColumnIsReadOnly(): void
    {
        $field = $this->norm->normalizeField($this->column('total', DbSimpleTypes::TYPE_INTEGER, ['isAggregate' => true]));
        $this->assertTrue($field->readOnly);
        // Not virtual, so no generated block.
        $this->assertNull($field->generated);
    }

    public function testForeignKeyProducesRef(): void
    {
        $field = $this->norm->normalizeField($this->column('customer_id', DbSimpleTypes::TYPE_INTEGER, [
            'isForeignKey' => true,
            'refTable'     => 'customers',
            'refField'     => 'id',
        ]));

        $this->assertTrue($field->isForeignKey);
        $this->assertSame([
            'service' => null,
            'schema'  => null,
            'table'   => 'customers',
            'field'   => 'id',
        ], $field->ref);
    }

    public function testNonForeignKeyHasNoRef(): void
    {
        $field = $this->norm->normalizeField($this->column('plain', DbSimpleTypes::TYPE_STRING));
        $this->assertNull($field->ref);
    }

    public function testDescriptionFallsBackToComment(): void
    {
        // No admin-UI description -> use the DB-native comment.
        $withComment = $this->norm->normalizeField($this->column('c', DbSimpleTypes::TYPE_STRING, ['comment' => 'from comment']));
        $this->assertSame('from comment', $withComment->description);

        // An explicit description wins over the comment.
        $withBoth = $this->norm->normalizeField($this->column('c', DbSimpleTypes::TYPE_STRING, ['description' => 'explicit', 'comment' => 'from comment']));
        $this->assertSame('explicit', $withBoth->description);
    }

    public function testFlagsPropagate(): void
    {
        $field = $this->norm->normalizeField($this->column('sku', DbSimpleTypes::TYPE_STRING, [
            'isUnique'      => true,
            'isPrimaryKey'  => false,
            'autoIncrement' => false,
            'allowNull'     => false,
        ]));

        $this->assertTrue($field->isUnique);
        $this->assertFalse($field->allowNull);
        $this->assertSame('sku', $field->name);
    }
}
