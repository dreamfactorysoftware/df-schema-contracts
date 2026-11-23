<?php

namespace DreamFactory\Core\SchemaContracts\Tests\Unit;

use DreamFactory\Core\SchemaContracts\Enforcement\ContractShape;
use PHPUnit\Framework\TestCase;

class ContractShapeTest extends TestCase
{
    private static function allowed(): array
    {
        return ContractShape::allowedKeys([
            'fields' => [['name' => 'id'], ['name' => 'status'], ['name' => 'total_amount'], ['name' => 'code_fk', 'alias' => 'code']],
            'relationships' => [['name' => 'customers_by_customer_id']],
        ]);
    }

    public function testAllowedKeysCoverNamesAliasesAndRelationships(): void
    {
        $this->assertSame(
            ['id', 'status', 'total_amount', 'code', 'code_fk', 'customers_by_customer_id'],
            array_keys(self::allowed())
        );
    }

    public function testAggregatesOverAllowedColumnsSurviveShaping(): void
    {
        $a = self::allowed();
        $row = ['status' => 'pending', 'SUM_total_amount' => 629.98, 'COUNT_ALL' => 1, 'AVG_code' => 2, 'MAX_secret' => 'x', 'secret' => 'hidden'];
        $this->assertSame(
            ['status' => 'pending', 'SUM_total_amount' => 629.98, 'COUNT_ALL' => 1, 'AVG_code' => 2],
            ContractShape::filterTopLevel($row, $a),
            'aggregates over contract columns stay; an aggregate over a hidden column and the hidden column go'
        );
        $this->assertFalse(ContractShape::isAggregateOfAllowed('SUMMARY_total_amount', $a));
    }

    public function testModelTableIsTrimmedToTheContract(): void
    {
        $entry = [
            'columns' => [['name' => 'id'], ['name' => 'status'], ['name' => 'secret'], ['name' => 'code', 'column' => 'code_fk']],
            'row_count' => 2,
            'sample_data' => [['id' => 1, 'status' => 'pending', 'secret' => 'SHOULD-BE-HIDDEN']],
            'enum_values' => ['status' => ['pending'], 'secret' => ['x']],
        ];
        $shaped = ContractShape::shapeModelTable($entry, self::allowed());
        $this->assertSame(['id', 'status', 'code'], array_column($shaped['columns'], 'name'));
        $this->assertSame([['id' => 1, 'status' => 'pending']], $shaped['sample_data']);
        $this->assertSame(['status' => ['pending']], $shaped['enum_values']);
        $this->assertSame(2, $shaped['row_count']);
    }

    public function testAliasedModelColumnsKeepTheirSamples(): void
    {
        $allowed = ContractShape::allowedKeys(['fields' => [['name' => 'id'], ['name' => 'status']]]);
        $entry = [
            'columns' => [['name' => 'id'], ['name' => 'order_status', 'column' => 'status']],
            'sample_data' => [['id' => 1, 'order_status' => 'shipped']],
            'enum_values' => ['order_status' => ['shipped']],
        ];
        $shaped = ContractShape::shapeModelTable($entry, $allowed);
        $this->assertSame([['id' => 1, 'order_status' => 'shipped']], $shaped['sample_data']);
        $this->assertSame(['order_status' => ['shipped']], $shaped['enum_values']);
    }
}
