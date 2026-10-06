<?php
declare(strict_types=1);

/** Fund positions grouped for display (fund cards on the funds page and the dashboard). */
class FundReport
{
    public const FIGURES = ['carry_in', 'receipts', 'adjust_up', 'adjust_down', 'reserved', 'allocated', 'returns', 'spent', 'carry_out',
        'pool_actual', 'pool_estimate', 'estimate', 'estimate_to_date', 'committed_open'];

    /**
     * Cards = top-level funds that have a fund_type; a typeless grouping node (e.g. เงินนอกงบประมาณ)
     * is expanded so each of its typed children gets its own card. Each card sums its leaf funds.
     * Amounts are returned in baht (float, 2 decimals) for display.
     */
    public static function cards(int $fyId): array
    {
        $data = Ledger::fundPositions($fyId);
        $byId = [];
        foreach ($data['funds'] as $f) $byId[(int)$f['id']] = $f;
        $isCard = function (array $f) use ($byId): bool {
            if ($f['fund_type'] === null) return $f['parent_id'] === null && (bool)$f['is_leaf'];
            return $f['parent_id'] === null || $byId[(int)$f['parent_id']]['fund_type'] === null;
        };
        $cardOf = function (int $id) use ($byId, $isCard): ?int {
            while ($id && !$isCard($byId[$id])) $id = (int)$byId[$id]['parent_id'];
            return $id ?: null;
        };

        $blank = array_fill_keys(self::FIGURES, 0);
        $cards = [];
        foreach ($data['funds'] as $f) {
            if (!$isCard($f)) continue;
            $cards[(int)$f['id']] = ['id' => (int)$f['id'], 'code' => $f['code'], 'name' => $f['name'], 'color' => $f['color_token'],
                'fund_type' => $f['fund_type'], 'permit_rule' => $f['permit_rule'], 'leaves' => [], 'project_count' => 0] + $blank;
        }
        $totals = $blank;
        foreach ($data['funds'] as $f) {
            if (!$f['is_leaf']) continue;
            $cid = $cardOf((int)$f['id']);
            if (!$cid) continue;
            $p = $data['positions'][(int)$f['id']] ?? $blank;
            $parentId = (int)$f['parent_id'];
            $leaf = ['id' => (int)$f['id'], 'code' => $f['code'], 'name' => $f['name'],
                'parent_name' => $parentId && $parentId !== $cid ? $byId[$parentId]['name'] : null,
                'active' => (bool)$f['active'], 'project_count' => $p['project_count'] ?? 0];
            foreach (self::FIGURES as $k) {
                $v = cents_num($p[$k] ?? 0);
                $leaf[$k] = $v;
                $cards[$cid][$k] += $v;
                $totals[$k] += $v;
            }
            $cards[$cid]['project_count'] += $p['project_count'] ?? 0;
            $cards[$cid]['leaves'][] = $leaf;
        }
        foreach ($cards as &$c) foreach (self::FIGURES as $k) $c[$k] = round($c[$k], 2);
        unset($c);
        // Display in tree order (depth-first by sort), not by the flat sort column.
        $children = [];
        foreach ($data['funds'] as $f) $children[(int)$f['parent_id']][] = $f;
        $order = [];
        $walk = function (int $parent) use (&$walk, &$order, $children): void {
            $kids = $children[$parent] ?? [];
            usort($kids, fn($a, $b) => [(int)$a['sort'], (int)$a['id']] <=> [(int)$b['sort'], (int)$b['id']]);
            foreach ($kids as $k) {
                $order[(int)$k['id']] = count($order);
                $walk((int)$k['id']);
            }
        };
        $walk(0);
        uasort($cards, fn($a, $b) => ($order[$a['id']] ?? 0) <=> ($order[$b['id']] ?? 0));
        foreach (self::FIGURES as $k) $totals[$k] = round($totals[$k], 2);
        return ['cards' => array_values($cards), 'totals' => $totals];
    }
}
