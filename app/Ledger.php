<?php
declare(strict_types=1);

/**
 * Double-entry, append-only fund ledger (spec §4.4, BR-20..BR-24).
 *
 * Every entry moves money from one ledger account to another; both accounts belong to the
 * same leaf fund source (BR-20). An account balance is Σ(to) − Σ(from), so reversals
 * (which swap from/to of the original) cancel the original automatically.
 */
class Ledger
{
    /** entry_type => [from account kind, to account kind] */
    public const FLOWS = [
        'carry_in'               => ['carry_forward', 'fund_pool'],
        'receipt'                => ['external', 'fund_pool'],
        'allocation_adjust_up'   => ['external', 'fund_pool'],
        'allocation_adjust_down' => ['fund_pool', 'external'],
        'reserve'                => ['fund_pool', 'reserve'],
        'allocate'               => ['fund_pool', 'project'],
        'transfer'               => ['project', 'project'],
        'spend'                  => ['project', 'external'],
        'return'                 => ['project', 'fund_pool'],   // or reserve → fund_pool (release a reserve)
        'carry_out'              => ['fund_pool', 'carry_forward'],
    ];

    public const TYPE_LABELS = [
        'carry_in' => 'ยกมา', 'receipt' => 'รับเงิน', 'allocation_adjust_up' => 'ปรับเพิ่มจัดสรร',
        'allocation_adjust_down' => 'ปรับลดจัดสรร', 'reserve' => 'กันเงิน', 'allocate' => 'จัดสรร',
        'transfer' => 'โอนตามปรับแผน', 'spend' => 'จ่ายจริง', 'return' => 'คืนกอง', 'carry_out' => 'ยกไป',
        'reversal' => 'กลับรายการ',
    ];

    /** Accounts whose balance may never go below zero (BR-22). */
    private const NON_NEGATIVE = ['fund_pool', 'project', 'reserve'];

    /** Entry types whose BR-22 block a planner may override with a reason (decision 2026-10-06). */
    public const OVERRIDABLE = ['allocate'];

    /**
     * Post one entry. $e keys: fiscal_year_id, entry_type, fund_source_id, amount (satang int),
     * entry_date, project_id / from_project_id / to_project_id, reserve_name, from_reserve,
     * expense_category_id, reference_no, reference_date, installment_no, source_type, source_id, note.
     * $override = ['reason' => string] lets a planner bypass BR-22 for OVERRIDABLE types.
     */
    public static function post(array $e, int $userId, ?array $override = null): array
    {
        return tx(function (PDO $pdo) use ($e, $userId, $override) {
            $type = $e['entry_type'] ?? '';
            if (!isset(self::FLOWS[$type])) fail('ประเภทรายการไม่ถูกต้อง');
            $amount = (int)$e['amount'];
            if ($amount <= 0) fail('จำนวนเงินต้องมากกว่า 0');

            $fy = fiscal_year((int)$e['fiscal_year_id']);
            if ($fy['status'] === 'closed') fail('ปีงบประมาณนี้ปิดแล้ว');
            $date = $e['entry_date'] ?? today();
            if (!valid_date($date)) fail('วันที่ไม่ถูกต้อง');
            if ($date < $fy['starts_on'] || $date > $fy['ends_on']) fail('วันที่ต้องอยู่ในปีงบประมาณ ' . $fy['year_be']);

            $fund = self::leafFund((int)$e['fund_source_id'], (int)$fy['id']);

            if ($type === 'receipt' && (trim((string)($e['reference_no'] ?? '')) === '' || !valid_date($e['reference_date'] ?? null))) {
                fail('การรับเงินต้องระบุเลขที่และวันที่เอกสารอ้างอิง (BR-23)');
            }
            if ($type === 'spend' && empty($e['expense_category_id'])) fail('การจ่ายต้องระบุหมวดรายจ่าย');

            [$fromKind, $toKind] = self::FLOWS[$type];
            $fromExtra = $toExtra = [];
            if ($type === 'return' && !empty($e['from_reserve'])) {
                $fromKind = 'reserve';
                $fromExtra = ['reserve_name' => $e['from_reserve']];
            }
            if ($fromKind === 'project') $fromExtra['project_id'] = (int)($type === 'transfer' ? ($e['from_project_id'] ?? 0) : ($e['project_id'] ?? 0));
            if ($toKind === 'project') $toExtra['project_id'] = (int)($type === 'transfer' ? ($e['to_project_id'] ?? 0) : ($e['project_id'] ?? 0));
            if ($toKind === 'reserve') $toExtra['reserve_name'] = trim((string)($e['reserve_name'] ?? ''));
            foreach ([$fromExtra, $toExtra] as $x) {
                if (array_key_exists('project_id', $x)) {
                    if (!$x['project_id']) fail('ต้องระบุโครงการ');
                    self::assertProjectInYear($x['project_id'], (int)$fy['id']);
                }
                if (array_key_exists('reserve_name', $x) && $x['reserve_name'] === '') fail('ต้องระบุชื่อรายการกันเงิน');
            }
            if ($type === 'transfer' && $fromExtra['project_id'] === $toExtra['project_id']) fail('โครงการต้นทางและปลายทางต้องต่างกัน');

            $from = self::account((int)$fy['id'], $fromKind, (int)$fund['id'], $fromExtra);
            $to = self::account((int)$fy['id'], $toKind, (int)$fund['id'], $toExtra);
            self::lockAccounts([$from, $to]);

            $overrideReason = self::checkNonNegative($from, $fromKind, $amount, $type, $override);

            return self::insert($pdo, [
                'fy' => $fy, 'entry_date' => $date, 'entry_type' => $type, 'from' => $from, 'to' => $to, 'amount' => $amount,
                'expense_category_id' => $e['expense_category_id'] ?? null,
                'reference_no' => self::str($e['reference_no'] ?? null), 'reference_date' => ($e['reference_date'] ?? null) ?: null,
                'installment_no' => isset($e['installment_no']) && $e['installment_no'] !== '' ? (int)$e['installment_no'] : null,
                'source_type' => $e['source_type'] ?? null, 'source_id' => $e['source_id'] ?? null,
                'reverses' => null, 'note' => self::str($e['note'] ?? null), 'override_reason' => $overrideReason,
            ], $userId);
        });
    }

    /** BR-21: correct an entry by posting a reversal that swaps from/to. One reversal per entry. */
    public static function reverse(int $entryId, string $reason, int $userId, ?string $date = null): array
    {
        $reason = trim($reason);
        if ($reason === '') fail('ต้องระบุเหตุผลการกลับรายการ');
        return tx(function (PDO $pdo) use ($entryId, $reason, $userId, $date) {
            $st = $pdo->prepare('SELECT * FROM ledger_entries WHERE id = ? FOR UPDATE');
            $st->execute([$entryId]);
            $orig = $st->fetch();
            if (!$orig) fail('ไม่พบรายการ', 404);
            if ($orig['entry_type'] === 'reversal') fail('ไม่สามารถกลับรายการของรายการกลับได้');
            $chk = $pdo->prepare('SELECT entry_no FROM ledger_entries WHERE reverses_entry_id = ?');
            $chk->execute([$entryId]);
            if ($no = $chk->fetchColumn()) fail('รายการนี้ถูกกลับรายการแล้วโดย ' . $no);

            $fy = fiscal_year((int)$orig['fiscal_year_id']);
            if ($fy['status'] === 'closed') fail('ปีงบประมาณนี้ปิดแล้ว');
            $date = $date ?: today();
            if ($date < $fy['starts_on']) $date = $fy['starts_on'];
            if ($date > $fy['ends_on']) $date = $fy['ends_on'];

            $from = self::accountById((int)$orig['to_account_id']);
            $to = self::accountById((int)$orig['from_account_id']);
            self::lockAccounts([$from, $to]);
            self::checkNonNegative($from, $from['kind'], to_cents($orig['amount']), 'reversal', null);

            return self::insert($pdo, [
                'fy' => $fy, 'entry_date' => $date, 'entry_type' => 'reversal', 'from' => $from, 'to' => $to,
                'amount' => to_cents($orig['amount']), 'expense_category_id' => $orig['expense_category_id'],
                'reference_no' => $orig['reference_no'], 'reference_date' => $orig['reference_date'], 'installment_no' => $orig['installment_no'],
                'source_type' => $orig['source_type'], 'source_id' => $orig['source_id'], 'reverses' => (int)$orig['id'],
                'note' => $reason, 'override_reason' => null,
            ], $userId);
        });
    }

    // ------------------------------------------------------------------ balances

    public static function balance(int $accountId): int
    {
        $st = db()->prepare('SELECT
            COALESCE((SELECT SUM(amount) FROM ledger_entries WHERE to_account_id = ?), 0) -
            COALESCE((SELECT SUM(amount) FROM ledger_entries WHERE from_account_id = ?), 0)');
        $st->execute([$accountId, $accountId]);
        return to_cents($st->fetchColumn());
    }

    /**
     * Fund positions for a fiscal year, per leaf fund (spec BR-10, BR-24).
     * Amounts in satang. Reversals are netted against the original entry type.
     */
    public static function fundPositions(int $fyId): array
    {
        $pdo = db();
        $funds = $pdo->prepare('SELECT * FROM fund_sources WHERE fiscal_year_id = ? ORDER BY sort, id');
        $funds->execute([$fyId]);
        $funds = $funds->fetchAll();

        $blank = ['carry_in' => 0, 'receipts' => 0, 'adjust_up' => 0, 'adjust_down' => 0, 'reserved' => 0,
            'allocated' => 0, 'returns' => 0, 'spent' => 0, 'carry_out' => 0, 'pool_actual' => 0,
            'estimate' => 0, 'estimate_to_date' => 0, 'committed_open' => 0, 'project_count' => 0];
        $pos = [];
        foreach ($funds as $f) if ($f['is_leaf']) $pos[(int)$f['id']] = $blank;

        // Effective movements: reversals contribute negatively to the original type and original accounts.
        $sql = "SELECT a_from.fund_source_id AS fund,
                COALESCE(o.entry_type, e.entry_type) AS etype,
                CASE WHEN e.entry_type = 'reversal' THEN a_to.kind ELSE a_from.kind END AS from_kind,
                CASE WHEN e.entry_type = 'reversal' THEN a_from.kind ELSE a_to.kind END AS to_kind,
                SUM(CASE WHEN e.entry_type = 'reversal' THEN -e.amount ELSE e.amount END) AS total
            FROM ledger_entries e
            JOIN ledger_accounts a_from ON a_from.id = e.from_account_id
            JOIN ledger_accounts a_to ON a_to.id = e.to_account_id
            LEFT JOIN ledger_entries o ON o.id = e.reverses_entry_id
            WHERE e.fiscal_year_id = ?
            GROUP BY fund, etype, from_kind, to_kind";
        $st = $pdo->prepare($sql);
        $st->execute([$fyId]);
        foreach ($st as $r) {
            $f = (int)$r['fund'];
            if (!isset($pos[$f])) $pos[$f] = $blank;
            $amt = to_cents($r['total']);
            switch ($r['etype']) {
                case 'carry_in': $pos[$f]['carry_in'] += $amt; break;
                case 'receipt': $pos[$f]['receipts'] += $amt; break;
                case 'allocation_adjust_up': $pos[$f]['adjust_up'] += $amt; break;
                case 'allocation_adjust_down': $pos[$f]['adjust_down'] += $amt; break;
                case 'reserve': $pos[$f]['reserved'] += $amt; break;
                case 'allocate': $pos[$f]['allocated'] += $amt; break;
                case 'spend': $pos[$f]['spent'] += $amt; break;
                case 'carry_out': $pos[$f]['carry_out'] += $amt; break;
                case 'return':
                    if ($r['from_kind'] === 'reserve') $pos[$f]['reserved'] -= $amt;
                    else { $pos[$f]['returns'] += $amt; $pos[$f]['allocated'] -= $amt; }
                    break;
            }
        }

        // Current revenue estimates; "to date" = installments expected up to the current fiscal month.
        $fy = fiscal_year($fyId);
        $todayIdx = today() < $fy['starts_on'] ? 0 : (today() > $fy['ends_on'] ? 12 : fiscal_month(today()));
        $st = $pdo->prepare('SELECT fund_source_id, expected_month, amount FROM revenue_estimates WHERE fiscal_year_id = ? AND is_current = 1');
        $st->execute([$fyId]);
        foreach ($st as $r) {
            $f = (int)$r['fund_source_id'];
            if (!isset($pos[$f])) continue;
            $amt = to_cents($r['amount']);
            $pos[$f]['estimate'] += $amt;
            if ($r['expected_month'] === null || (int)$r['expected_month'] <= $todayIdx) $pos[$f]['estimate_to_date'] += $amt;
        }

        $st = $pdo->prepare("SELECT la.fund_source_id, COUNT(DISTINCT la.project_id) n FROM ledger_accounts la
            WHERE la.fiscal_year_id = ? AND la.kind = 'project' GROUP BY la.fund_source_id");
        $st->execute([$fyId]);
        foreach ($st as $r) if (isset($pos[(int)$r['fund_source_id']])) $pos[(int)$r['fund_source_id']]['project_count'] = (int)$r['n'];

        foreach ($pos as $f => $p) {
            // BR-24
            $pos[$f]['pool_actual'] = $p['carry_in'] + $p['receipts'] + $p['adjust_up'] - $p['adjust_down']
                - $p['reserved'] - $p['allocated'] - $p['carry_out'];
            // BR-10 (without revenue shock): estimates replace actual receipts.
            $pos[$f]['pool_estimate'] = $pos[$f]['pool_actual'] - $p['receipts'] + $p['estimate'];
        }
        return ['funds' => $funds, 'positions' => $pos];
    }

    /** Per project, per leaf fund: allocated (BR-25), spent and free. */
    public static function projectBalances(int $fyId, ?array $projectIds = null): array
    {
        $where = 'la.fiscal_year_id = ? AND la.kind = \'project\'';
        $params = [$fyId];
        if ($projectIds !== null) {
            if (!$projectIds) return [];
            $where .= ' AND la.project_id IN (' . implode(',', array_fill(0, count($projectIds), '?')) . ')';
            $params = array_merge($params, array_values($projectIds));
        }
        $sql = "SELECT la.project_id, la.fund_source_id,
                COALESCE((SELECT SUM(e.amount) FROM ledger_entries e WHERE e.to_account_id = la.id), 0) AS inflow,
                COALESCE((SELECT SUM(e.amount) FROM ledger_entries e WHERE e.from_account_id = la.id), 0) AS outflow,
                COALESCE((SELECT SUM(e.amount) FROM ledger_entries e WHERE e.from_account_id = la.id AND e.entry_type = 'spend'), 0)
              - COALESCE((SELECT SUM(r.amount) FROM ledger_entries r JOIN ledger_entries o ON o.id = r.reverses_entry_id
                          WHERE r.to_account_id = la.id AND o.entry_type = 'spend'), 0) AS spent
            FROM ledger_accounts la WHERE {$where}";
        $st = db()->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st as $r) {
            $balance = to_cents($r['inflow']) - to_cents($r['outflow']);
            $spent = to_cents($r['spent']);
            $out[(int)$r['project_id']][(int)$r['fund_source_id']] = [
                'allocated' => $balance + $spent,
                'spent' => $spent,
                'committed_open' => 0,          // commitments arrive in phase 2
                'free' => $balance,
            ];
        }
        return $out;
    }

    /** Leaf fund ids under (and including) $fundId within a fiscal year. */
    public static function leafIds(int $fundId, int $fyId): array
    {
        $st = db()->prepare('SELECT id, parent_id, is_leaf FROM fund_sources WHERE fiscal_year_id = ?');
        $st->execute([$fyId]);
        $children = [];
        $leaf = [];
        foreach ($st as $f) {
            $children[(int)$f['parent_id']][] = (int)$f['id'];
            $leaf[(int)$f['id']] = (bool)$f['is_leaf'];
        }
        if (!isset($leaf[$fundId])) return [];
        $out = [];
        $stack = [$fundId];
        while ($stack) {
            $id = array_pop($stack);
            if ($leaf[$id]) $out[] = $id;
            foreach ($children[$id] ?? [] as $c) $stack[] = $c;
        }
        return $out;
    }

    // ------------------------------------------------------------------ internals

    private static function insert(PDO $pdo, array $x, int $userId): array
    {
        $no = next_number('LG', (int)$x['fy']['year_be']);
        $pdo->prepare('INSERT INTO ledger_entries (entry_no, fiscal_year_id, entry_date, entry_type, from_account_id, to_account_id,
                amount, expense_category_id, reference_no, reference_date, installment_no, source_type, source_id,
                reverses_entry_id, note, override_reason, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$no, $x['fy']['id'], $x['entry_date'], $x['entry_type'], $x['from']['id'], $x['to']['id'],
                cents_str($x['amount']), $x['expense_category_id'], $x['reference_no'], $x['reference_date'], $x['installment_no'],
                $x['source_type'], $x['source_id'], $x['reverses'], $x['note'], $x['override_reason'], $userId]);
        $id = (int)$pdo->lastInsertId();
        $row = ['id' => $id, 'entry_no' => $no, 'entry_type' => $x['entry_type'], 'amount' => cents_str($x['amount']),
            'fund_source_id' => (int)$x['from']['fund_source_id'], 'from_account_id' => (int)$x['from']['id'], 'to_account_id' => (int)$x['to']['id'],
            'reverses_entry_id' => $x['reverses'], 'override_reason' => $x['override_reason']];
        audit($x['entry_type'] === 'reversal' ? 'ledger.reverse' : 'ledger.post', 'ledger_entry', $id, null, $row);
        return $row;
    }

    private static function checkNonNegative(array $from, string $fromKind, int $amount, string $type, ?array $override): ?string
    {
        if (!in_array($fromKind, self::NON_NEGATIVE, true)) return null;
        $balance = self::balance((int)$from['id']);
        if ($balance - $amount >= 0) return null;
        $short = $amount - $balance;
        $label = ['fund_pool' => 'กองเงิน (ชั้นจริง)', 'project' => 'ยอดคงเหลือของโครงการ', 'reserve' => 'เงินกันไว้'][$fromKind];
        $reason = trim((string)($override['reason'] ?? ''));
        if ($reason !== '' && in_array($type, self::OVERRIDABLE, true) && $fromKind === 'fund_pool') {
            if (!has_role('planner', (int)$from['fiscal_year_id'])) fail('เฉพาะงานแผนฯ เท่านั้นที่ยืนยันข้ามการตรวจยอดได้', 403);
            return $reason;
        }
        fail($label . 'ไม่พอ ขาด ' . fmt_money($short) . ' บาท (BR-22)', 422, [
            'rule' => 'BR-22', 'short_by' => cents_num($short), 'balance' => cents_num($balance),
            'overridable' => in_array($type, self::OVERRIDABLE, true) && $fromKind === 'fund_pool',
        ]);
    }

    private static function leafFund(int $fundId, int $fyId): array
    {
        $st = db()->prepare('SELECT * FROM fund_sources WHERE id = ?');
        $st->execute([$fundId]);
        $f = $st->fetch();
        if (!$f || (int)$f['fiscal_year_id'] !== $fyId) fail('ไม่พบแหล่งเงินในปีงบประมาณนี้');
        if (!$f['is_leaf']) fail('ต้องเลือกแหล่งเงินระดับย่อยสุด (leaf) เท่านั้น');
        if (!$f['active']) fail('แหล่งเงินนี้ปิดการใช้งานแล้ว');
        return $f;
    }

    private static function assertProjectInYear(int $projectId, int $fyId): void
    {
        $st = db()->prepare('SELECT fiscal_year_id FROM projects WHERE id = ?');
        $st->execute([$projectId]);
        $p = $st->fetchColumn();
        if ($p === false || (int)$p !== $fyId) fail('ไม่พบโครงการในปีงบประมาณนี้');
    }

    /** Get or create a ledger account. */
    public static function account(int $fyId, string $kind, int $fundId, array $extra = []): array
    {
        $pdo = db();
        $projectId = $extra['project_id'] ?? null;
        $reserve = $extra['reserve_name'] ?? null;
        $pdo->prepare('INSERT IGNORE INTO ledger_accounts (fiscal_year_id, kind, fund_source_id, project_id, reserve_name) VALUES (?, ?, ?, ?, ?)')
            ->execute([$fyId, $kind, $fundId, $projectId, $reserve]);
        $st = $pdo->prepare('SELECT * FROM ledger_accounts WHERE fiscal_year_id = ? AND kind = ? AND fund_source_id = ?
            AND project_id <=> ? AND reserve_name <=> ?');
        $st->execute([$fyId, $kind, $fundId, $projectId, $reserve]);
        return $st->fetch();
    }

    private static function accountById(int $id): array
    {
        $st = db()->prepare('SELECT * FROM ledger_accounts WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch();
    }

    /** Row-lock accounts in id order so concurrent postings serialize without deadlocks. */
    private static function lockAccounts(array $accounts): void
    {
        $ids = array_unique(array_map(fn($a) => (int)$a['id'], $accounts));
        sort($ids);
        $st = db()->prepare('SELECT id FROM ledger_accounts WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE');
        $st->execute($ids);
    }

    private static function str($v): ?string
    {
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }
}
