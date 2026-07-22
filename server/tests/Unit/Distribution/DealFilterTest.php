<?php

declare(strict_types=1);

namespace DealDist\Tests\Unit\Distribution;

use DealDist\Distribution\DealFilter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DealDist\Distribution\DealFilter
 */
class DealFilterTest extends TestCase
{
    private DealFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new DealFilter();
    }

    // ── Empty filters ─────────────────────────────────────────────────────────

    public function testEmptyFiltersAlwaysMatch(): void
    {
        $this->assertTrue($this->filter->matches($this->lead(), []));
    }

    public function testMissingFiltersKeyAlwaysMatch(): void
    {
        $this->assertTrue($this->filter->matches($this->lead(), []));
    }

    // ── Budget ────────────────────────────────────────────────────────────────

    public function testBudgetMinPass(): void
    {
        $this->assertTrue($this->filter->matches(
            $this->lead(price: 10_000),
            ['budget_min' => 5_000]
        ));
    }

    public function testBudgetMinExactBoundaryPass(): void
    {
        $this->assertTrue($this->filter->matches(
            $this->lead(price: 5_000),
            ['budget_min' => 5_000]
        ));
    }

    public function testBudgetMinFail(): void
    {
        $this->assertFalse($this->filter->matches(
            $this->lead(price: 4_999),
            ['budget_min' => 5_000]
        ));
    }

    public function testBudgetMaxPass(): void
    {
        $this->assertTrue($this->filter->matches(
            $this->lead(price: 99_000),
            ['budget_max' => 100_000]
        ));
    }

    public function testBudgetMaxExactBoundaryPass(): void
    {
        $this->assertTrue($this->filter->matches(
            $this->lead(price: 100_000),
            ['budget_max' => 100_000]
        ));
    }

    public function testBudgetMaxFail(): void
    {
        $this->assertFalse($this->filter->matches(
            $this->lead(price: 100_001),
            ['budget_max' => 100_000]
        ));
    }

    public function testBudgetRangePass(): void
    {
        $this->assertTrue($this->filter->matches(
            $this->lead(price: 50_000),
            ['budget_min' => 10_000, 'budget_max' => 100_000]
        ));
    }

    public function testBudgetRangeFailLow(): void
    {
        $this->assertFalse($this->filter->matches(
            $this->lead(price: 9_999),
            ['budget_min' => 10_000, 'budget_max' => 100_000]
        ));
    }

    public function testBudgetRangeFailHigh(): void
    {
        $this->assertFalse($this->filter->matches(
            $this->lead(price: 100_001),
            ['budget_min' => 10_000, 'budget_max' => 100_000]
        ));
    }

    // ── Name contains ─────────────────────────────────────────────────────────

    public function testNameContainsPass(): void
    {
        $this->assertTrue($this->filter->matches(
            $this->lead(name: 'Доставка мебели'),
            ['name_contains' => 'мебели']
        ));
    }

    public function testNameContainsCaseInsensitivePass(): void
    {
        $this->assertTrue($this->filter->matches(
            $this->lead(name: 'Доставка МЕБЕЛИ'),
            ['name_contains' => 'мебели']
        ));
    }

    public function testNameContainsFail(): void
    {
        $this->assertFalse($this->filter->matches(
            $this->lead(name: 'Установка оборудования'),
            ['name_contains' => 'мебели']
        ));
    }

    // ── Tags ──────────────────────────────────────────────────────────────────

    public function testSingleTagPass(): void
    {
        $this->assertTrue($this->filter->matches(
            $this->lead(tags: ['vip', 'wholesale']),
            ['tags' => ['vip']]
        ));
    }

    public function testAllTagsRequiredPass(): void
    {
        $this->assertTrue($this->filter->matches(
            $this->lead(tags: ['vip', 'wholesale', 'premium']),
            ['tags' => ['vip', 'wholesale']]
        ));
    }

    public function testTagCaseInsensitivePass(): void
    {
        $this->assertTrue($this->filter->matches(
            $this->lead(tags: ['VIP']),
            ['tags' => ['vip']]
        ));
    }

    public function testMissingTagFail(): void
    {
        $this->assertFalse($this->filter->matches(
            $this->lead(tags: ['wholesale']),
            ['tags' => ['vip', 'wholesale']]
        ));
    }

    public function testEmptyLeadTagsFail(): void
    {
        $this->assertFalse($this->filter->matches(
            $this->lead(tags: []),
            ['tags' => ['vip']]
        ));
    }

    // ── Custom fields ─────────────────────────────────────────────────────────

    public function testCustomFieldEqPass(): void
    {
        $this->assertTrue($this->filter->matches(
            $this->lead(customFields: [[42, 'Москва']]),
            ['custom_fields' => [['field_id' => 42, 'operator' => 'eq', 'value' => 'Москва']]]
        ));
    }

    public function testCustomFieldEqCaseInsensitivePass(): void
    {
        $this->assertTrue($this->filter->matches(
            $this->lead(customFields: [[42, 'москва']]),
            ['custom_fields' => [['field_id' => 42, 'operator' => 'eq', 'value' => 'Москва']]]
        ));
    }

    public function testCustomFieldEqFail(): void
    {
        $this->assertFalse($this->filter->matches(
            $this->lead(customFields: [[42, 'Санкт-Петербург']]),
            ['custom_fields' => [['field_id' => 42, 'operator' => 'eq', 'value' => 'Москва']]]
        ));
    }

    public function testCustomFieldContainsPass(): void
    {
        $this->assertTrue($this->filter->matches(
            $this->lead(customFields: [[42, 'Доставка в Москву']]),
            ['custom_fields' => [['field_id' => 42, 'operator' => 'contains', 'value' => 'Москву']]]
        ));
    }

    public function testCustomFieldContainsFail(): void
    {
        $this->assertFalse($this->filter->matches(
            $this->lead(customFields: [[42, 'Доставка в Казань']]),
            ['custom_fields' => [['field_id' => 42, 'operator' => 'contains', 'value' => 'Москву']]]
        ));
    }

    public function testCustomFieldGtePass(): void
    {
        $this->assertTrue($this->filter->matches(
            $this->lead(customFields: [[99, '100']]),
            ['custom_fields' => [['field_id' => 99, 'operator' => 'gte', 'value' => '50']]]
        ));
    }

    public function testCustomFieldGteFail(): void
    {
        $this->assertFalse($this->filter->matches(
            $this->lead(customFields: [[99, '30']]),
            ['custom_fields' => [['field_id' => 99, 'operator' => 'gte', 'value' => '50']]]
        ));
    }

    public function testCustomFieldLtePass(): void
    {
        $this->assertTrue($this->filter->matches(
            $this->lead(customFields: [[99, '40']]),
            ['custom_fields' => [['field_id' => 99, 'operator' => 'lte', 'value' => '50']]]
        ));
    }

    public function testCustomFieldLteFail(): void
    {
        $this->assertFalse($this->filter->matches(
            $this->lead(customFields: [[99, '60']]),
            ['custom_fields' => [['field_id' => 99, 'operator' => 'lte', 'value' => '50']]]
        ));
    }

    public function testMissingCustomFieldFail(): void
    {
        $this->assertFalse($this->filter->matches(
            $this->lead(customFields: []),
            ['custom_fields' => [['field_id' => 42, 'operator' => 'eq', 'value' => 'Москва']]]
        ));
    }

    public function testMultipleCustomFieldsAllPass(): void
    {
        $this->assertTrue($this->filter->matches(
            $this->lead(customFields: [[42, 'Москва'], [99, '200']]),
            ['custom_fields' => [
                ['field_id' => 42, 'operator' => 'eq',  'value' => 'Москва'],
                ['field_id' => 99, 'operator' => 'gte', 'value' => '100'],
            ]]
        ));
    }

    public function testMultipleCustomFieldsOneFail(): void
    {
        $this->assertFalse($this->filter->matches(
            $this->lead(customFields: [[42, 'Москва'], [99, '50']]),
            ['custom_fields' => [
                ['field_id' => 42, 'operator' => 'eq',  'value' => 'Москва'],
                ['field_id' => 99, 'operator' => 'gte', 'value' => '100'],
            ]]
        ));
    }

    // ── Combined filters ──────────────────────────────────────────────────────

    public function testCombinedFiltersAllPass(): void
    {
        $this->assertTrue($this->filter->matches(
            $this->lead(
                name:         'Доставка VIP-клиенту',
                price:        75_000,
                tags:         ['vip'],
                customFields: [[42, 'Москва']],
            ),
            [
                'budget_min'    => 50_000,
                'budget_max'    => 100_000,
                'name_contains' => 'доставка',
                'tags'          => ['vip'],
                'custom_fields' => [['field_id' => 42, 'operator' => 'eq', 'value' => 'Москва']],
            ]
        ));
    }

    public function testCombinedFiltersOneFilterFails(): void
    {
        $this->assertFalse($this->filter->matches(
            $this->lead(
                name:  'Доставка VIP-клиенту',
                price: 200_000,   // exceeds budget_max
                tags:  ['vip'],
            ),
            [
                'budget_min'    => 50_000,
                'budget_max'    => 100_000,
                'name_contains' => 'доставка',
                'tags'          => ['vip'],
            ]
        ));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function lead(
        string $name         = 'Test Lead',
        int    $price        = 0,
        array  $tags         = [],
        array  $customFields = [],
    ): array {
        return [
            'name'  => $name,
            'price' => $price,
            '_embedded' => [
                'tags' => array_map(
                    static fn(string $t): array => ['name' => $t],
                    $tags
                ),
            ],
            'custom_fields_values' => array_map(
                static fn(array $cf): array => [
                    'field_id' => $cf[0],
                    'values'   => [['value' => $cf[1]]],
                ],
                $customFields
            ),
        ];
    }
}
