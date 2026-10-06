<?php
declare(strict_types=1);

/** Base master data (spec §13) and optional demo data (design-brief §8). */
class Seeder
{
    public const DEMO_PASSWORD = 'demo-password';

    public const DEFAULT_FY_SETTINGS = [
        'weights' => ['necessity' => 30, 'impact' => 25, 'alignment' => 15, 'beneficiaries' => 15, 'past' => 15],
        'health' => ['report_due_days' => 15],
        'adjustment_compare_mode' => 'per_fund',
    ];

    /** Fiscal year (พ.ศ.) that contains today: October starts the next year. */
    public static function currentYearBe(): int
    {
        return (int)date('Y') + 543 + ((int)date('n') >= 10 ? 1 : 0);
    }

    /** Seed the master data a new institution needs (its name lives in institutions). Returns the fiscal year id. */
    public static function base(PDO $pdo, int $institutionId, int $yearBe = 2570): int
    {
        $start = sprintf('%04d-10-01', $yearBe - 544);
        $end = sprintf('%04d-09-30', $yearBe - 543);
        $pdo->prepare('INSERT INTO fiscal_years (institution_id, year_be, starts_on, ends_on, status, proposal_open_from, proposal_open_to, settings)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$institutionId, $yearBe, $start, $end, 'execution', sprintf('%04d-06-01', $yearBe - 544), sprintf('%04d-08-31', $yearBe - 544),
                json_encode(self::DEFAULT_FY_SETTINGS, JSON_UNESCAPED_UNICODE)]);
        $fyId = (int)$pdo->lastInsertId();
        set_setting('current_fiscal_year_id', (string)$fyId, $institutionId);

        self::orgUnits($pdo, $institutionId);
        self::expenseCategories($pdo, $institutionId);
        self::fundSources($pdo, $fyId);
        self::alignment($pdo, $fyId);
        self::approvalChains($pdo, $fyId);
        return $fyId;
    }

    public static function orgUnits(PDO $pdo, int $institutionId): void
    {
        $tree = [
            ['ADM', 'ฝ่ายบริหารทรัพยากร', ['บริหารงานทั่วไป', 'บุคลากร', 'การเงิน', 'การบัญชี', 'พัสดุ', 'อาคารสถานที่', 'ทะเบียน', 'ประชาสัมพันธ์']],
            ['PLN', 'ฝ่ายแผนงานและความร่วมมือ', ['วางแผนและงบประมาณ', 'ศูนย์ข้อมูลสารสนเทศ', 'ความร่วมมือ', 'วิจัยพัฒนานวัตกรรมและสิ่งประดิษฐ์', 'ประกันคุณภาพและมาตรฐานการศึกษา', 'ส่งเสริมผลิตผลการค้าและประกอบธุรกิจ']],
            ['STD', 'ฝ่ายพัฒนากิจการนักเรียนนักศึกษา', ['กิจกรรมนักเรียนนักศึกษา', 'ครูที่ปรึกษา', 'ปกครอง', 'แนะแนวอาชีพและการจัดหางาน', 'สวัสดิการนักเรียนนักศึกษา', 'โครงการพิเศษและการบริการชุมชน']],
            ['ACD', 'ฝ่ายวิชาการ', ['พัฒนาหลักสูตรการเรียนการสอน', 'วัดผลและประเมินผล', 'วิทยบริการและห้องสมุด', 'อาชีวศึกษาระบบทวิภาคี', 'สื่อการเรียนการสอน']],
        ];
        // Sample departments (แผนกวิชา) with student counts — edit to match the college.
        $departments = [
            ['DEP-AUTO', 'แผนกวิชาช่างยนต์', 320, 110], ['DEP-POWER', 'แผนกวิชาช่างไฟฟ้ากำลัง', 312, 96],
            ['DEP-ELEC', 'แผนกวิชาช่างอิเล็กทรอนิกส์', 180, 64], ['DEP-ACC', 'แผนกวิชาการบัญชี', 210, 80],
            ['DEP-DBT', 'แผนกวิชาเทคโนโลยีธุรกิจดิจิทัล', 160, 58], ['DEP-GEN', 'แผนกวิชาสามัญสัมพันธ์', null, null],
        ];
        $ins = $pdo->prepare('INSERT INTO org_units (institution_id, parent_id, name, kind, code, student_count_vc, student_count_hvc, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($tree as $i => [$code, $name, $sections]) {
            $ins->execute([$institutionId, null, $name, 'division', $code, null, null, $i + 1]);
            $divId = (int)$pdo->lastInsertId();
            foreach ($sections as $j => $s) {
                $ins->execute([$institutionId, $divId, 'งาน' . $s, 'section', sprintf('%s-%02d', $code, $j + 1), null, null, $j + 1]);
            }
            if ($code === 'ACD') {
                foreach ($departments as $k => [$dc, $dn, $vc, $hvc]) {
                    $ins->execute([$institutionId, $divId, $dn, 'department', $dc, $vc, $hvc, 100 + $k]);
                }
            }
        }
    }

    public static function expenseCategories(PDO $pdo, int $institutionId): void
    {
        $tree = [
            ['PERS', 'งบบุคลากร', [['PERS-SAL', 'เงินเดือน'], ['PERS-PERM', 'ค่าจ้างประจำ'], ['PERS-TEMP', 'ค่าจ้างชั่วคราว'], ['PERS-GOV', 'ค่าตอบแทนพนักงานราชการ']]],
            ['OPS', 'งบดำเนินงาน', [['OPS-COMP', 'ค่าตอบแทน'], ['OPS-SERV', 'ค่าใช้สอย'], ['OPS-MAT', 'ค่าวัสดุ'], ['OPS-UTIL', 'ค่าสาธารณูปโภค']]],
            ['INV', 'งบลงทุน', [['INV-EQUIP', 'ค่าครุภัณฑ์'], ['INV-BUILD', 'ค่าที่ดินและสิ่งก่อสร้าง']]],
            ['SUBS', 'งบเงินอุดหนุน', [['SUBS-GEN', 'เงินอุดหนุน']]],
            ['OTHER', 'งบรายจ่ายอื่น', [['OTHER-GEN', 'รายจ่ายอื่น']]],
        ];
        $ins = $pdo->prepare('INSERT INTO expense_categories (institution_id, parent_id, code, name, sort) VALUES (?, ?, ?, ?, ?)');
        foreach ($tree as $i => [$code, $name, $children]) {
            $ins->execute([$institutionId, null, $code, $name, $i + 1]);
            $pid = (int)$pdo->lastInsertId();
            foreach ($children as $j => [$cc, $cn]) $ins->execute([$institutionId, $pid, $cc, $cn, $j + 1]);
        }
    }

    public static function fundSources(PDO $pdo, int $fyId): void
    {
        $tree = [
            ['BUD', 'เงินงบประมาณ', '#2F6FDB', 'state_budget', 'allocation_letter', [
                ['BUD-VC', 'ปวช.'], ['BUD-HVC', 'ปวส.'], ['BUD-SHORT', 'ระยะสั้น'], ['BUD-EQUIP', 'ครุภัณฑ์'], ['BUD-POLICY', 'โครงการตามนโยบาย สอศ.']]],
            ['SUB', 'เงินอุดหนุน', '#0E9494', 'subsidy', 'cumulative_receipts', [
                ['SUB-TEACH', 'ค่าจัดการเรียนการสอน'], ['SUB-BOOK', 'ค่าหนังสือเรียน'], ['SUB-SUPPLY', 'ค่าอุปกรณ์การเรียน'],
                ['SUB-UNIFORM', 'ค่าเครื่องแบบนักเรียน'], ['SUB-ACT', 'ค่ากิจกรรมพัฒนาคุณภาพผู้เรียน']]],
            ['EXT', 'เงินนอกงบประมาณ', null, null, null, [
                ['INC', 'เงินรายได้สถานศึกษา', '#C98A06', 'institution_income', 'cash_available', [['INC-FEE', 'ค่าบำรุงการศึกษา'], ['INC-ASSET', 'ผลประโยชน์จากทรัพย์สิน']]],
                ['DON', 'เงินบริจาค', '#7A5AC8', 'donation', 'cash_available', []],
                ['OTH', 'เงินอื่น ๆ', '#64748B', 'other', 'cash_available', []],
            ]],
        ];
        $ins = $pdo->prepare('INSERT INTO fund_sources (fiscal_year_id, parent_id, code, name, color_token, fund_type, permit_rule, is_leaf, sort)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $add = function (?int $parent, array $node, int $sort, ?array $inherit) use (&$add, $ins, $pdo, $fyId) {
            // Node formats: [code, name, children] (inherits) or [code, name, color, type, rule, children]
            if (count($node) === 2) $node = [$node[0], $node[1], null, null, null, []];
            [$code, $name, $color, $type, $rule, $children] = $node;
            $color = $color ?? $inherit[0] ?? null;
            $type = $type ?? $inherit[1] ?? null;
            $rule = $rule ?? $inherit[2] ?? null;
            $ins->execute([$fyId, $parent, $code, $name, $color, $type, $rule, $children ? 0 : 1, $sort]);
            $id = (int)$pdo->lastInsertId();
            foreach ($children as $i => $c) $add($id, $c, $i + 1, [$color, $type, $rule]);
        };
        foreach ($tree as $i => $node) $add(null, $node, $i + 1, null);
    }

    public static function alignment(PDO $pdo, int $fyId): void
    {
        $sets = [
            ['national_strategy', 'ยุทธศาสตร์ชาติ 20 ปี', 1, [
                'ด้านความมั่นคง', 'ด้านการสร้างความสามารถในการแข่งขัน', 'ด้านการพัฒนาและเสริมสร้างศักยภาพทรัพยากรมนุษย์',
                'ด้านการสร้างโอกาสและความเสมอภาคทางสังคม', 'ด้านการสร้างการเติบโตบนคุณภาพชีวิตที่เป็นมิตรต่อสิ่งแวดล้อม',
                'ด้านการปรับสมดุลและพัฒนาระบบการบริหารจัดการภาครัฐ']],
            ['ovec_policy', 'นโยบาย/ยุทธศาสตร์ สอศ.', 1, [
                'ยุทธศาสตร์ที่ 1 ผลิตและพัฒนากำลังคนอาชีวศึกษา', 'ยุทธศาสตร์ที่ 2 ยกระดับคุณภาพการจัดการเรียนการสอน',
                'ยุทธศาสตร์ที่ 3 ขยายโอกาสและความร่วมมือ', 'ยุทธศาสตร์ที่ 4 พัฒนาระบบบริหารจัดการ', 'ยุทธศาสตร์ที่ 5 ส่งเสริมนวัตกรรมและเทคโนโลยี']],
            ['college_mission', 'พันธกิจ/กลยุทธ์สถานศึกษา', 0, [
                'พันธกิจที่ 1 จัดการศึกษาวิชาชีพให้มีคุณภาพตามมาตรฐาน (ตัวอย่าง — แก้ตามจริง)',
                'พันธกิจที่ 2 พัฒนาครูและบุคลากรให้มีสมรรถนะ (ตัวอย่าง — แก้ตามจริง)',
                'พันธกิจที่ 3 บริการวิชาชีพสู่ชุมชน (ตัวอย่าง — แก้ตามจริง)']],
            ['vocational_standard', 'มาตรฐานการอาชีวศึกษา', 1, [
                ['มาตรฐานที่ 1 คุณลักษณะของผู้สำเร็จการศึกษาอาชีวศึกษาที่พึงประสงค์', ['1.1 ด้านความรู้', '1.2 ด้านทักษะและการประยุกต์ใช้', '1.3 ด้านคุณธรรม จริยธรรม และคุณลักษณะที่พึงประสงค์']],
                ['มาตรฐานที่ 2 การจัดการอาชีวศึกษา', ['2.1 ด้านหลักสูตรอาชีวศึกษา', '2.2 ด้านการจัดการเรียนการสอนอาชีวศึกษา', '2.3 ด้านการบริหารจัดการ', '2.4 ด้านการนำนโยบายสู่การปฏิบัติ']],
                ['มาตรฐานที่ 3 การสร้างสังคมแห่งการเรียนรู้', ['3.1 ด้านความร่วมมือในการสร้างสังคมแห่งการเรียนรู้', '3.2 ด้านนวัตกรรม สิ่งประดิษฐ์ งานสร้างสรรค์ และงานวิจัย']],
            ]],
            ['sufficiency_economy', 'ปรัชญาเศรษฐกิจพอเพียง', 0, ['ความพอประมาณ', 'ความมีเหตุผล', 'การมีภูมิคุ้มกันที่ดี', 'เงื่อนไขความรู้', 'เงื่อนไขคุณธรรม']],
        ];
        $insSet = $pdo->prepare('INSERT INTO alignment_sets (fiscal_year_id, code, name, required, sort) VALUES (?, ?, ?, ?, ?)');
        $insItem = $pdo->prepare('INSERT INTO alignment_items (alignment_set_id, parent_id, code, label, sort) VALUES (?, ?, ?, ?, ?)');
        foreach ($sets as $i => [$code, $name, $required, $items]) {
            $insSet->execute([$fyId, $code, $name, $required, $i + 1]);
            $setId = (int)$pdo->lastInsertId();
            foreach ($items as $j => $item) {
                $label = is_array($item) ? $item[0] : $item;
                $insItem->execute([$setId, null, null, $label, $j + 1]);
                if (is_array($item)) {
                    $pid = (int)$pdo->lastInsertId();
                    foreach ($item[1] as $k => $sub) $insItem->execute([$setId, $pid, null, $sub, $k + 1]);
                }
            }
        }
    }

    public static function approvalChains(PDO $pdo, int $fyId): void
    {
        $chains = [
            ['proposal', 'เสนอโครงการ', [['unit_head', 'project_unit'], ['division_deputy', 'project_division'], ['planner', 'global']]],
            ['permit', 'ขออนุญาตดำเนินโครงการ', [['division_deputy', 'project_division'], ['planner', 'global'], ['planning_deputy', 'global'], ['director', 'global']]],
            ['adjustment', 'ปรับแผน (ไม่ใช้งบเพิ่ม)', [['unit_head', 'project_unit'], ['planner', 'global'], ['director', 'global']]],
            ['adjustment_increase', 'ปรับแผน (ใช้งบเพิ่ม)', [['unit_head', 'project_unit'], ['division_deputy', 'project_division'], ['planner', 'global'], ['planning_deputy', 'global'], ['director', 'global']]],
            ['report_review', 'ตรวจรายงานผล', [['planner', 'global']]],
        ];
        $insChain = $pdo->prepare('INSERT INTO approval_chains (fiscal_year_id, request_type, name) VALUES (?, ?, ?)');
        $insStep = $pdo->prepare('INSERT INTO approval_chain_steps (approval_chain_id, step_no, role, scope, can_return, can_reject) VALUES (?, ?, ?, ?, 1, ?)');
        foreach ($chains as [$type, $name, $steps]) {
            $insChain->execute([$fyId, $type, $name]);
            $cid = (int)$pdo->lastInsertId();
            foreach ($steps as $i => [$role, $scope]) $insStep->execute([$cid, $i + 1, $role, $scope, in_array($role, ['director', 'planner'], true) ? 1 : 0]);
        }
    }

    // ================================================================ demo data

    /** Demo users, projects and fund movements matching design-brief §8. */
    public static function demo(PDO $pdo, int $fyId, int $adminId): void
    {
        $st = $pdo->prepare('SELECT institution_id FROM fiscal_years WHERE id = ?');
        $st->execute([$fyId]);
        $inst = (int)$st->fetchColumn();
        $unit = function (string $codeOrName) use ($pdo, $inst): int {
            $st = $pdo->prepare('SELECT id FROM org_units WHERE institution_id = ? AND (code = ? OR name = ?) LIMIT 1');
            $st->execute([$inst, $codeOrName, $codeOrName]);
            $id = $st->fetchColumn();
            if (!$id) throw new RuntimeException('demo: unit not found ' . $codeOrName);
            return (int)$id;
        };
        $fund = function (string $code) use ($pdo, $fyId): int {
            $st = $pdo->prepare('SELECT id FROM fund_sources WHERE fiscal_year_id = ? AND code = ?');
            $st->execute([$fyId, $code]);
            return (int)$st->fetchColumn();
        };
        $cat = function (string $code) use ($pdo, $inst): int {
            $st = $pdo->prepare('SELECT id FROM expense_categories WHERE institution_id = ? AND code = ?');
            $st->execute([$inst, $code]);
            return (int)$st->fetchColumn();
        };

        // --- users
        $hash = password_hash(self::DEMO_PASSWORD, PASSWORD_DEFAULT);
        $users = [
            ['planner', 'นางสาวสมศรี ใจดี', 'เจ้าหน้าที่งานวางแผนและงบประมาณ', [['planner', null]]],
            ['finance', 'นางกนกพร มีสุข', 'เจ้าหน้าที่งานการเงิน', [['finance', null]]],
            ['procurement', 'นายวิชัย พงษ์ไทย', 'เจ้าหน้าที่งานพัสดุ', [['procurement', null]]],
            ['director', 'นายอำนาจ รุ่งเรือง', 'ผู้อำนวยการ', [['director', null]]],
            ['plandeputy', 'นางสุภาพร แสงทอง', 'รองผู้อำนวยการฝ่ายแผนงานและความร่วมมือ', [['planning_deputy', null], ['division_deputy', 'PLN']]],
            ['acddeputy', 'นางวรรณา ทองดี', 'รองผู้อำนวยการฝ่ายวิชาการ', [['division_deputy', 'ACD']]],
            ['autohead', 'นายประเสริฐ บุญมา', 'หัวหน้าแผนกวิชาช่างยนต์', [['unit_head', 'DEP-AUTO'], ['proposer', 'DEP-AUTO']]],
            ['teacher', 'ครูสมชาย แก้วมณี', 'ครูแผนกวิชาช่างยนต์', [['proposer', 'DEP-AUTO']]],
            ['board', 'นายบุญชัย ศรีสวัสดิ์', 'กรรมการวิทยาลัย', [['board_viewer', null]]],
        ];
        $insU = $pdo->prepare('INSERT INTO users (institution_id, username, name, password_hash, position_title, password_changed_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $insR = $pdo->prepare('INSERT INTO role_assignments (user_id, role, org_unit_id, fiscal_year_id) VALUES (?, ?, ?, ?)');
        $uid = [];
        foreach ($users as [$username, $name, $pos, $roles]) {
            $insU->execute([$inst, $username, $name, $hash, $pos]);
            $uid[$username] = (int)$pdo->lastInsertId();
            foreach ($roles as [$role, $unitCode]) $insR->execute([$uid[$username], $role, $unitCode ? $unit($unitCode) : null, $fyId]);
        }

        // Ledger posting needs a logged-in planner/finance identity for audit + override checks.
        $_SESSION['uid'] = $uid['finance'];

        $fy = fiscal_year($fyId);
        $start = $fy['starts_on'];
        $lastDay = min(today(), $fy['ends_on']);
        if ($lastDay < $start) $lastDay = $start;
        $day = function (int $offset) use ($start, $lastDay): string {
            $d = date('Y-m-d', strtotime($start . ' +' . $offset . ' day'));
            return $d > $lastDay ? $lastDay : $d;
        };

        // --- revenue estimates (version 1 = ฉบับต้นปี)
        $est = [
            ['BUD-VC', 'เงินงบประมาณ ปวช. งวดที่ ', 7000000, 4, null],
            ['BUD-HVC', 'เงินงบประมาณ ปวส. งวดที่ ', 4200000, 4, null],
            ['BUD-POLICY', 'โครงการตามนโยบาย สอศ. งวดที่ ', 1200000, 2, null],
            ['BUD-EQUIP', 'งบครุภัณฑ์ งวดที่ ', 450000, 2, null],
            ['SUB-TEACH', 'ค่าจัดการเรียนการสอน ภาคเรียนที่ ', 9800000, 2, null],
            ['SUB-BOOK', 'ค่าหนังสือเรียน ภาคเรียนที่ ', 2300000, 2, null],
            ['SUB-SUPPLY', 'ค่าอุปกรณ์การเรียน ภาคเรียนที่ ', 1580000, 2, null],
            ['SUB-ACT', 'ค่ากิจกรรมพัฒนาคุณภาพผู้เรียน ภาคเรียนที่ ', 2000000, 2, null],
            ['INC-FEE', 'ค่าบำรุงการศึกษา ภาคเรียนที่ ', 12000000, 2, ['students' => 1500, 'rate' => 4000, 'terms' => 2]],
            ['INC-ASSET', 'ผลประโยชน์จากทรัพย์สิน งวดที่ ', 2520000, 4, null],
            ['DON', 'เงินบริจาค งวดที่ ', 200000, 2, null],
        ];
        $insE = $pdo->prepare('INSERT INTO revenue_estimates (fiscal_year_id, fund_source_id, version, label, installment_no, expected_month, basis, amount, is_current, created_by)
            VALUES (?, ?, 1, ?, ?, ?, ?, ?, 1, ?)');
        foreach ($est as [$code, $label, $total, $n, $basis]) {
            $per = intdiv($total * 100, $n);
            for ($i = 1; $i <= $n; $i++) {
                $amt = $i === $n ? $total * 100 - $per * ($n - 1) : $per;
                $month = $n === 4 ? ($i - 1) * 3 + 1 : ($i === 1 ? 1 : 5);
                $b = $basis ? $basis + ['term' => $i] : null;
                if ($b) { $b['terms'] = 1; }
                $insE->execute([$fyId, $fund($code), $label . $i, $i, $month, $b ? json_encode($b) : null, cents_str($amt), $uid['planner']]);
            }
        }

        // --- carry in, receipts, reserves
        $post = fn(array $e) => Ledger::post($e + ['fiscal_year_id' => $fyId], $uid['finance']);
        $post(['entry_type' => 'carry_in', 'fund_source_id' => $fund('SUB-TEACH'), 'amount' => to_cents('3210500'), 'entry_date' => $start, 'reference_no' => 'ยอดยกมาปีงบ ' . ($fy['year_be'] - 1), 'note' => 'ยอดคงเหลือยกมาจากปีก่อน']);
        $post(['entry_type' => 'carry_in', 'fund_source_id' => $fund('INC-FEE'), 'amount' => to_cents('6845220.50'), 'entry_date' => $start, 'reference_no' => 'ยอดยกมาปีงบ ' . ($fy['year_be'] - 1)]);
        $post(['entry_type' => 'carry_in', 'fund_source_id' => $fund('DON'), 'amount' => to_cents('125000'), 'entry_date' => $start, 'reference_no' => 'ยอดยกมาปีงบ ' . ($fy['year_be'] - 1)]);

        $receipts = [
            ['BUD-VC', '3500000', 1, 'หนังสือ ศธ 0606/1101', 'สอศ. → กองเงินงบประมาณ (ปวช.)'],
            ['BUD-HVC', '1760000', 1, 'หนังสือ ศธ 0606/1102', 'สอศ. → กองเงินงบประมาณ (ปวส.)'],
            ['BUD-POLICY', '600000', 1, 'หนังสือ ศธ 0606/1140', 'สอศ. → โครงการตามนโยบาย'],
            ['SUB-TEACH', '4900000', 1, 'หนังสือ ศธ 0606/1453', 'สอศ. → กองเงินอุดหนุน'],
            ['SUB-BOOK', '1150000', 1, 'หนังสือ ศธ 0606/1454', 'สอศ. → ค่าหนังสือเรียน'],
            ['SUB-SUPPLY', '790000', 1, 'หนังสือ ศธ 0606/1455', 'สอศ. → ค่าอุปกรณ์การเรียน'],
            ['SUB-ACT', '1100000', 1, 'หนังสือ ศธ 0606/1456', 'สอศ. → ค่ากิจกรรมพัฒนาคุณภาพผู้เรียน'],
            ['INC-FEE', '7680400', 1, 'ใบเสร็จ 2569/2-0001–0842', 'ค่าบำรุงการศึกษา → กองเงินรายได้'],
            ['INC-ASSET', '630000', 1, 'ใบเสร็จ R70-0001', 'ค่าเช่าพื้นที่ → กองเงินรายได้'],
            ['DON', '85000', 1, 'ใบเสร็จ D70-0007', 'สมาคมศิษย์เก่า → กองเงินบริจาค'],
        ];
        foreach ($receipts as $i => [$code, $amt, $inst, $ref, $note]) {
            $post(['entry_type' => 'receipt', 'fund_source_id' => $fund($code), 'amount' => to_cents($amt), 'entry_date' => $day(1 + intdiv($i, 3)),
                'reference_no' => $ref, 'reference_date' => $day(1 + intdiv($i, 3)), 'installment_no' => $inst, 'note' => $note]);
        }
        // A receipt keyed with the wrong amount, reversed and re-entered (shows BR-21 in the demo).
        $wrong = $post(['entry_type' => 'receipt', 'fund_source_id' => $fund('DON'), 'amount' => to_cents('25000'), 'entry_date' => $day(4),
            'reference_no' => 'ใบเสร็จ D70-0008', 'reference_date' => $day(4), 'note' => 'ผู้ปกครองบริจาค']);
        Ledger::reverse((int)$wrong['id'], 'บันทึกจำนวนผิด ใบเสร็จระบุ 2,500.00 บาท', $uid['finance'], $day(4));
        $post(['entry_type' => 'receipt', 'fund_source_id' => $fund('DON'), 'amount' => to_cents('2500'), 'entry_date' => $day(4),
            'reference_no' => 'ใบเสร็จ D70-0008', 'reference_date' => $day(4), 'note' => 'ผู้ปกครองบริจาค (แก้ไขจำนวน)']);

        $_SESSION['uid'] = $uid['planner'];
        Ledger::post(['fiscal_year_id' => $fyId, 'entry_type' => 'reserve', 'fund_source_id' => $fund('SUB-TEACH'), 'amount' => to_cents('300000'),
            'entry_date' => $day(2), 'reserve_name' => 'สำรองค่าสาธารณูปโภค', 'note' => 'กันเงินตามมติ 2/2569'], $uid['planner']);
        Ledger::post(['fiscal_year_id' => $fyId, 'entry_type' => 'reserve', 'fund_source_id' => $fund('INC-FEE'), 'amount' => to_cents('500000'),
            'entry_date' => $day(2), 'reserve_name' => 'กันไว้กรณีฉุกเฉิน', 'note' => 'กันเงินตามมติ 2/2569'], $uid['planner']);

        // --- projects (design-brief §8 + prototype list)
        $projects = [
            // code, title, unit, fund leaf, category, requested, approved, status, quarters, necessity
            ['P70-014', 'พัฒนาทักษะวิชาชีพช่างยนต์สู่มาตรฐานฝีมือแรงงาน', 'DEP-AUTO', 'SUB-TEACH', 'OPS-MAT', 185000, 150000, 'approved', '2,3', 'must'],
            ['P70-022', 'ปรับปรุงระบบเครือข่ายไร้สายอาคารเรียน 3', 'งานศูนย์ข้อมูลสารสนเทศ', 'INC-FEE', 'INV-EQUIP', 420000, 420000, 'approved', '2', 'must'],
            ['P70-031', 'อบรมครูด้านการสอนแบบ Active Learning', 'งานพัฒนาหลักสูตรการเรียนการสอน', 'BUD-VC', 'OPS-COMP', 96500, 80000, 'approved', '1', 'should'],
            ['P70-052', 'ศูนย์ซ่อมสร้างเพื่อชุมชน (Fix it Center)', 'งานโครงการพิเศษและการบริการชุมชน', 'BUD-POLICY', 'OPS-MAT', 350000, 350000, 'approved', '1,2', 'should'],
            ['P70-008', 'จัดซื้อวัสดุฝึกแผนกวิชาช่างไฟฟ้ากำลัง', 'DEP-POWER', 'SUB-TEACH', 'OPS-MAT', 310000, 290000, 'approved', '1', 'must'],
            ['P70-011', 'ซ่อมบำรุงระบบไฟฟ้าอาคาร 1', 'งานอาคารสถานที่', 'BUD-VC', 'OPS-SERV', 240000, 240000, 'approved', '2', 'must'],
            ['P70-017', 'พัฒนาห้องปฏิบัติการอิเล็กทรอนิกส์อัจฉริยะ', 'DEP-ELEC', 'SUB-TEACH', 'INV-EQUIP', 280000, 150000, 'approved', '3', 'should'],
            ['P70-026', 'ทวิภาคีสัญจรสถานประกอบการ', 'งานความร่วมมือ', 'INC-FEE', 'OPS-SERV', 120000, 100000, 'approved', '2', 'should'],
            ['P70-033', 'ระบบบัญชีออนไลน์สำหรับฝึกปฏิบัติ', 'DEP-ACC', 'INC-FEE', 'OPS-SERV', 145000, 90000, 'approved', '1', 'nice'],
            ['P70-038', 'ค่ายพัฒนาคุณธรรมจริยธรรมผู้เรียน', 'งานกิจกรรมนักเรียนนักศึกษา', 'DON', 'OPS-SERV', 85000, 70000, 'approved', '2', 'should'],
            ['P70-061', 'จัดซื้อครุภัณฑ์สำนักงานงานทะเบียน', 'งานทะเบียน', 'BUD-EQUIP', 'INV-EQUIP', 90000, 90000, 'approved', '1', 'should'],
            ['P70-050', 'ทุนการศึกษาผู้เรียนขาดแคลน', 'งานแนะแนวอาชีพและการจัดหางาน', 'DON', 'SUBS-GEN', 60000, 60000, 'approved', '2', 'must'],
            ['P70-045', 'แข่งขันทักษะวิชาชีพระดับภาค', 'งานกิจกรรมนักเรียนนักศึกษา', 'INC-FEE', 'OPS-SERV', 260000, 0, 'waitlisted', '3', 'should'],
            ['P70-029', 'ส่งเสริมการเรียนรู้ภาษาอังกฤษเพื่อการสื่อสาร', 'DEP-GEN', 'BUD-VC', 'OPS-COMP', 75000, 0, 'rejected', null, 'nice'],
        ];
        $insP = $pdo->prepare('INSERT INTO projects (fiscal_year_id, code, title, org_unit_id, created_by, necessity, requested_total, minimum_viable,
                planned_quarters, status, source, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insL = $pdo->prepare('INSERT INTO budget_lines (project_id, expense_category_id, fund_source_id, item_name, quantity, unit, unit_price, amount)
            VALUES (?, ?, ?, ?, 1, ?, ?, ?)');
        foreach ($projects as [$code, $title, $u, $f, $c, $req, $app, $status, $q, $need]) {
            $unitId = $unit($u);
            $creator = $u === 'DEP-AUTO' ? $uid['teacher'] : $uid['planner'];
            $insP->execute([$fyId, $code, $title, $unitId, $creator, $need, cents_str($req * 100), cents_str($req * 100),
                $q, $status, 'demo', $status === 'approved' ? $day(3) . ' 09:00:00' : null]);
            $pid = (int)$pdo->lastInsertId();
            $insL->execute([$pid, $cat($c), $fund($f), 'ตามแบบเสนอโครงการ', 'งาน', cents_str($req * 100), cents_str($req * 100)]);
            if ($code === 'P70-014') $pdo->prepare('INSERT INTO project_owners (project_id, user_id, is_primary) VALUES (?, ?, 1)')->execute([$pid, $uid['teacher']]);
            if ($app > 0) {
                Ledger::post(['fiscal_year_id' => $fyId, 'entry_type' => 'allocate', 'fund_source_id' => $fund($f), 'project_id' => $pid,
                    'amount' => $app * 100, 'entry_date' => $day(3), 'reference_no' => 'มติ 2/2569', 'source_type' => 'demo',
                    'note' => 'จัดสรรตามแผนตั้งต้น'], $uid['planner'],
                    // No cash yet for equipment budget: shows the planner override of BR-22.
                    $f === 'BUD-EQUIP' ? ['reason' => 'ได้รับหนังสือแจ้งการจัดสรรงบครุภัณฑ์แล้ว รอการโอนเงินงวดแรก'] : null);
            }
        }
        $pdo->prepare('INSERT INTO counters (institution_id, name, value) VALUES (?, ?, 70) ON DUPLICATE KEY UPDATE value = GREATEST(value, 70)')->execute([$inst, 'P' . $fy['year_be']]);
        unset($_SESSION['uid']);
    }
}
