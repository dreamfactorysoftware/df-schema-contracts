<?php

namespace DreamFactory\Core\SchemaContracts\Tests\Unit;

use DreamFactory\Core\SchemaContracts\Canonical\FieldSchema;
use DreamFactory\Core\SchemaContracts\OpenApi\OpenApiSchemaGenerator;
use PHPUnit\Framework\TestCase;

/**
 * OpenApiSchemaGenerator is pure: canonical Field/Table arrays in, OpenAPI 3.0
 * Schema Objects out. These tests lock the type mapping and the request-body
 * `required` rules that customers' generated clients depend on.
 */
class OpenApiSchemaGeneratorTest extends TestCase
{
    private OpenApiSchemaGenerator $gen;

    protected function setUp(): void
    {
        $this->gen = new OpenApiSchemaGenerator();
    }

    private function field(string $type, array $attrs = []): array
    {
        return array_merge(['name' => 'f', 'type' => $type], $attrs);
    }

    public function testScalarTypeMappings(): void
    {
        $this->assertSame(['type' => 'integer', 'format' => 'int64'], $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_ID)));
        $this->assertSame(['type' => 'integer', 'format' => 'int64'], $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_INTEGER)));
        $this->assertSame(['type' => 'number'], $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_NUMBER)));
        $this->assertSame(['type' => 'boolean'], $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_BOOLEAN)));
        $this->assertSame(['type' => 'string'], $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_TEXT)));
    }

    public function testStringLengthBecomesMaxLength(): void
    {
        $this->assertSame(
            ['type' => 'string', 'maxLength' => 120],
            $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_STRING, ['length' => 120]))
        );
        // No length -> no maxLength constraint.
        $this->assertSame(['type' => 'string'], $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_STRING)));
    }

    public function testDateTimeFormats(): void
    {
        $this->assertSame(['type' => 'string', 'format' => 'date'], $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_DATE)));
        $this->assertSame(['type' => 'string', 'format' => 'date-time'], $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_DATETIME)));
        $this->assertSame(['type' => 'string', 'format' => 'binary'], $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_BINARY)));
    }

    public function testUnknownTypeIsUnconstrained(): void
    {
        $this->assertSame([], $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_UNKNOWN)));
    }

    public function testArrayUsesElementItems(): void
    {
        $schema = $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_ARRAY, ['element_type' => FieldSchema::TYPE_INTEGER]));
        $this->assertSame('array', $schema['type']);
        $this->assertSame(['type' => 'integer', 'format' => 'int64'], $schema['items']);
    }

    public function testNullableAndReadOnlyFlags(): void
    {
        $nullable = $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_STRING, ['allow_null' => true]));
        $this->assertTrue($nullable['nullable']);

        $auto = $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_ID, ['auto_increment' => true]));
        $this->assertTrue($auto['readOnly']);

        $ro = $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_STRING, ['read_only' => true]));
        $this->assertTrue($ro['readOnly']);
    }

    public function testEnumAndDefault(): void
    {
        $enum = $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_STRING, ['enum' => ['values' => ['a', 'b']]]));
        $this->assertSame(['a', 'b'], $enum['enum']);

        $withDefault = $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_STRING, ['default' => 'x']));
        $this->assertSame('x', $withDefault['default']);

        // A null default must NOT emit a `default` key.
        $nullDefault = $this->gen->fromCanonicalField($this->field(FieldSchema::TYPE_STRING, ['default' => null]));
        $this->assertArrayNotHasKey('default', $nullDefault);
    }

    public function testTableRequiredExcludesAutoAndReadOnly(): void
    {
        $table = [
            'name'   => 'users',
            'fields' => [
                ['name' => 'id', 'type' => FieldSchema::TYPE_ID, 'required' => true, 'auto_increment' => true],
                ['name' => 'email', 'type' => FieldSchema::TYPE_STRING, 'required' => true],
                ['name' => 'created', 'type' => FieldSchema::TYPE_DATETIME, 'required' => true, 'read_only' => true],
                ['name' => 'nickname', 'type' => FieldSchema::TYPE_STRING, 'required' => false],
            ],
        ];

        $schema = $this->gen->fromCanonicalTable($table);

        $this->assertSame('object', $schema['type']);
        // Only a client-supplied NOT NULL field is required. The auto PK and the
        // server-populated read-only timestamp are excluded.
        $this->assertSame(['email'], $schema['required']);
        $this->assertArrayHasKey('id', (array) $schema['properties']);
    }

    public function testTableWithNoRequiredFieldsOmitsRequiredKey(): void
    {
        $table = ['name' => 't', 'fields' => [['name' => 'a', 'type' => FieldSchema::TYPE_STRING, 'required' => false]]];
        $schema = $this->gen->fromCanonicalTable($table);
        $this->assertArrayNotHasKey('required', $schema);
    }
}
