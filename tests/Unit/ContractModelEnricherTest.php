<?php

namespace DreamFactory\Core\SchemaContracts\Tests\Unit;

use DreamFactory\Core\SchemaContracts\Enforcement\ContractShape;
use DreamFactory\Core\SchemaContracts\Enrichers\ContractModelEnricher;
use PHPUnit\Framework\TestCase;

class ContractModelEnricherTest extends TestCase
{
    public function testRunsBeforeOtherEnrichers(): void
    {
        $this->assertSame(-100, (new ContractModelEnricher())->priority());
    }

    public function testSemanticsEntriesOnHiddenColumnsAreDropped(): void
    {
        $allowed = ['orders' => ContractShape::allowedKeys(['fields' => [['name' => 'id'], ['name' => 'status'], ['name' => 'total_amount']]])];
        $semantics = [
            'guidance' => 'g',
            'warnings' => ['zero totals (orders.secret): text'],
            'glossary' => [
                ['term' => 'shipped', 'table' => 'orders', 'field' => 'status', 'value' => 'shipped'],
                ['term' => 'vip', 'table' => 'orders', 'field' => 'secret', 'value' => 'y'],
                ['term' => 'customer', 'table' => 'customers', 'field' => 'anything'],
                ['term' => 'loose', 'meaning' => 'no table'],
            ],
            'metrics' => [
                ['name' => 'revenue', 'run_with' => ['arguments' => ['table_name' => 'orders', 'aggregates' => [['function' => 'SUM', 'field' => 'total_amount']]]]],
                ['name' => 'margin', 'run_with' => ['arguments' => ['table_name' => 'orders', 'aggregates' => [['function' => 'SUM', 'field' => 'secret']]]]],
                ['name' => 'count by secret', 'run_with' => ['arguments' => ['table_name' => 'orders', 'aggregates' => [['function' => 'COUNT', 'field' => '*']], 'group_by' => ['secret']]]],
            ],
            'verified_queries' => [
                ['name' => 'all', 'run_with' => ['arguments' => ['table_name' => 'orders']]],
                ['name' => 'secret rows', 'run_with' => ['arguments' => ['table_name' => 'orders', 'fields' => ['id', 'secret']]]],
            ],
            'omitted' => 1,
        ];
        $out = ContractShape::shapeSemantics($semantics, $allowed);
        $this->assertSame(['shipped', 'customer', 'loose'], array_column($out['glossary'], 'term'));
        $this->assertSame(['revenue'], array_column($out['metrics'], 'name'));
        $this->assertSame(['all'], array_column($out['verified_queries'], 'name'));
        $this->assertSame(5, $out['omitted'], 'dropped entries are counted, not named');
        $this->assertSame($semantics['warnings'], $out['warnings'], 'notes are sentences and are left alone');
        $this->assertSame($semantics, ContractShape::shapeSemantics($semantics, []), 'no contract, no change');
    }
}
