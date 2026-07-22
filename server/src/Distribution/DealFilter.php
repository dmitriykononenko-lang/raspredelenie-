<?php

declare(strict_types=1);

namespace DealDist\Distribution;

/**
 * Checks whether a lead matches the filter conditions of a distribution rule.
 *
 * Supported filter keys in a rule:
 *
 *   budget_min       int     Lead price ≥ value
 *   budget_max       int     Lead price ≤ value
 *   tags             array   Lead must have ALL listed tags (case-insensitive)
 *   name_contains    string  Lead name contains this substring (case-insensitive)
 *   custom_fields    array   [{field_id: int, value: string}] — lead custom field equals value
 *
 * Example rule fragment:
 * {
 *   "pipeline_id": 111,
 *   "stage_id": null,
 *   "filters": {
 *     "budget_min": 10000,
 *     "budget_max": 500000,
 *     "tags": ["vip", "wholesale"],
 *     "name_contains": "доставка",
 *     "custom_fields": [
 *       {"field_id": 42, "value": "Москва"}
 *     ]
 *   },
 *   "managers": [...]
 * }
 */
class DealFilter
{
    /**
     * Returns true if the lead data matches all conditions defined in $filters.
     * An empty / absent filters array always matches.
     *
     * @param array $leadData  Full lead object from AmoCRM API v4
     * @param array $filters   Filter conditions from the rule
     */
    public function matches(array $leadData, array $filters): bool
    {
        if (empty($filters)) {
            return true;
        }

        // ── Budget ─────────────────────────────────────────────────────────────
        $price = (int) ($leadData['price'] ?? 0);

        if (isset($filters['budget_min']) && $price < (int) $filters['budget_min']) {
            return false;
        }
        if (isset($filters['budget_max']) && $price > (int) $filters['budget_max']) {
            return false;
        }

        // ── Name ───────────────────────────────────────────────────────────────
        if (!empty($filters['name_contains'])) {
            $needle   = mb_strtolower((string) $filters['name_contains']);
            $haystack = mb_strtolower((string) ($leadData['name'] ?? ''));
            if (!str_contains($haystack, $needle)) {
                return false;
            }
        }

        // ── Tags ───────────────────────────────────────────────────────────────
        if (!empty($filters['tags'])) {
            $leadTags = array_map(
                static fn(array $t): string => mb_strtolower($t['name'] ?? ''),
                $leadData['_embedded']['tags'] ?? []
            );

            foreach ((array) $filters['tags'] as $requiredTag) {
                if (!in_array(mb_strtolower((string) $requiredTag), $leadTags, true)) {
                    return false;
                }
            }
        }

        // ── Custom fields ──────────────────────────────────────────────────────
        if (!empty($filters['custom_fields'])) {
            $leadFields = [];
            foreach ($leadData['custom_fields_values'] ?? [] as $cf) {
                $fieldId = (int) $cf['field_id'];
                $value   = $cf['values'][0]['value'] ?? null;
                $leadFields[$fieldId] = $value;
            }

            foreach ((array) $filters['custom_fields'] as $condition) {
                $fieldId       = (int) ($condition['field_id'] ?? 0);
                $expectedValue = (string) ($condition['value'] ?? '');

                $actualValue = (string) ($leadFields[$fieldId] ?? '');

                if (!isset($condition['operator']) || $condition['operator'] === 'eq') {
                    if (mb_strtolower($actualValue) !== mb_strtolower($expectedValue)) {
                        return false;
                    }
                } elseif ($condition['operator'] === 'contains') {
                    if (!str_contains(mb_strtolower($actualValue), mb_strtolower($expectedValue))) {
                        return false;
                    }
                } elseif ($condition['operator'] === 'gte') {
                    if ((float) $actualValue < (float) $expectedValue) {
                        return false;
                    }
                } elseif ($condition['operator'] === 'lte') {
                    if ((float) $actualValue > (float) $expectedValue) {
                        return false;
                    }
                }
            }
        }

        return true;
    }
}
