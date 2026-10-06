<?php
declare(strict_types=1);

/** Role → action matrix (spec §3) and data scopes. */
class Access
{
    // Who may post which ledger entry types (and reverse them).
    private const POST_ROLES = [
        'carry_in' => ['finance'],
        'receipt' => ['finance'],
        'allocation_adjust_up' => ['finance'],
        'allocation_adjust_down' => ['finance'],
        'reserve' => ['planner', 'finance'],
        'return_reserve' => ['planner', 'finance'],
        'allocate' => ['planner'],
    ];

    public const FUND_VIEWERS = ['planner', 'planning_deputy', 'director', 'finance', 'procurement', 'board_viewer'];
    public const LEDGER_VIEWERS = ['planner', 'planning_deputy', 'director', 'finance', 'procurement'];
    public const GLOBAL_PROJECT_VIEWERS = ['planner', 'planning_deputy', 'director', 'finance', 'procurement'];

    public static function canPost(string $type, int $fyId): bool
    {
        return isset(self::POST_ROLES[$type]) && has_role(self::POST_ROLES[$type], $fyId);
    }

    /** Ledger key used for permissions: a return out of a reserve is "return_reserve". */
    public static function postKey(array $entry, ?string $fromKind = null): string
    {
        if ($entry['entry_type'] === 'return' && $fromKind === 'reserve') return 'return_reserve';
        return $entry['entry_type'];
    }

    public static function permissions(int $fyId): array
    {
        $p = [
            'view_funds' => has_role(self::FUND_VIEWERS, $fyId),
            'view_ledger' => has_role(self::LEDGER_VIEWERS, $fyId),
            'view_projects' => has_role(array_merge(self::GLOBAL_PROJECT_VIEWERS, ['proposer', 'unit_head', 'division_deputy']), $fyId),
            'estimates' => has_role(['planner', 'finance'], $fyId),
            'import' => has_role('planner', $fyId),
            'settings' => has_role('planner', $fyId),
            'admin' => has_role('admin', $fyId),
            'override' => has_role('planner', $fyId),
        ];
        foreach (array_keys(self::POST_ROLES) as $t) $p['post_' . $t] = self::canPost($t, $fyId);
        return $p;
    }

    /** All unit ids in the subtree rooted at each of $roots (inclusive). */
    public static function subtree(array $roots): array
    {
        $children = [];
        foreach (db()->query('SELECT id, parent_id FROM org_units') as $u) $children[(int)$u['parent_id']][] = (int)$u['id'];
        $out = [];
        $stack = array_map('intval', $roots);
        while ($stack) {
            $id = array_pop($stack);
            if (isset($out[$id])) continue;
            $out[$id] = true;
            foreach ($children[$id] ?? [] as $c) $stack[] = $c;
        }
        return array_keys($out);
    }

    /**
     * SQL condition limiting projects (alias p) to the user's data scope.
     * Returns null when the user may see every project, or [sql, params].
     */
    public static function projectScope(array $user, int $fyId): ?array
    {
        if (has_role(self::GLOBAL_PROJECT_VIEWERS, $fyId, $user)) return null;
        $units = [];
        foreach (roles_for($user, $fyId) as $r) {
            if (in_array($r['role'], UNIT_SCOPED_ROLES, true) && $r['org_unit_id']) $units[] = (int)$r['org_unit_id'];
        }
        $units = $units ? self::subtree($units) : [];
        $parts = ['EXISTS (SELECT 1 FROM project_owners po WHERE po.project_id = p.id AND po.user_id = ?)', 'p.created_by = ?'];
        $params = [(int)$user['id'], (int)$user['id']];
        if ($units) {
            $parts[] = 'p.org_unit_id IN (' . implode(',', array_fill(0, count($units), '?')) . ')';
            $params = array_merge($params, $units);
        }
        return ['(' . implode(' OR ', $parts) . ')', $params];
    }
}
