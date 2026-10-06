# Build Spec — ระบบบริหารโครงการและงบประมาณ สถานศึกษาอาชีวศึกษา

> เอกสารนี้สำหรับ **Claude Code** ใช้สร้างระบบจริง
> คู่กับ `design-brief.md` (หน้าจอและ UX จาก Claude Design) — ชื่อสถานะ ฟิลด์ และกฎต้องตรงกันทั้งสองไฟล์
> ภาษา UI: ไทยทั้งหมด · ภาษาโค้ด/ชื่อตาราง/คอมเมนต์ในโค้ด: อังกฤษ

---

## 0. วิธีใช้เอกสารนี้กับ Claude Code

- สร้างทีละระยะตามข้อ 14 อย่าสร้างทั้งหมดในครั้งเดียว แต่ละระยะต้องผ่านเกณฑ์ตรวจรับก่อนไปต่อ
- กฎธุรกิจทุกข้อมีรหัส `BR-xx` ให้เขียน test อ้างรหัสนั้น
- ถ้าข้อกำหนดขัดกันหรือไม่ครบ ให้ถามก่อน อย่าเดา โดยเฉพาะเรื่องเงิน
- ข้อ 15 คือคำถามที่ยังเปิดอยู่ ให้ทำตามค่าเริ่มต้นที่ระบุ และทำให้เปลี่ยนได้ผ่านการตั้งค่า

---

## 1. ภาพรวมและอภิธานศัพท์

ระบบเว็บภายในวิทยาลัย สำหรับงานยุทธศาสตร์ แผนงานและงบประมาณ ครอบคลุม: เสนอโครงการ → จำลองงบ/พิจารณา → มติ (แผนตั้งต้น) → ขออนุญาตดำเนินการ → ติดตาม/เบิกจ่าย → รายงานผล/ปิด → ปรับแผน และบริหารกองเงินแบบสมุดบัญชี (ledger)

| ไทย | identifier | ความหมาย |
|---|---|---|
| ปีงบประมาณ | `fiscal_year` | 1 ต.ค. (ค.ศ. y−1) – 30 ก.ย. (ค.ศ. y); แสดงเป็น พ.ศ. เช่น ปีงบ 2570 = 1 ต.ค. 2026 – 30 ก.ย. 2027 |
| หน่วยงาน | `org_unit` | ฝ่าย / งาน / แผนกวิชา (ต้นไม้) |
| แหล่งเงิน / กองเงิน | `fund_source` | ต้นไม้ เช่น เงินงบประมาณ > ปวช.; เงินอุดหนุน > ค่าจัดการเรียนการสอน |
| หมวดรายจ่าย | `expense_category` | งบบุคลากร / งบดำเนินงาน (ค่าตอบแทน ค่าใช้สอย ค่าวัสดุ ค่าสาธารณูปโภค) / งบลงทุน (ครุภัณฑ์ ที่ดินและสิ่งก่อสร้าง) / งบเงินอุดหนุน / งบรายจ่ายอื่น |
| ความสอดคล้อง | `alignment_item` | ยุทธศาสตร์ชาติ, นโยบาย/ยุทธศาสตร์ สอศ., พันธกิจ/กลยุทธ์สถานศึกษา, มาตรฐานการอาชีวศึกษา, ปรัชญาเศรษฐกิจพอเพียง |
| ชุดพิจารณา | `scenario` | ชุดผลพิจารณาทดลองของรอบเสนอโครงการ |
| แผนตั้งต้น | baseline | ยอดอนุมัติตามมติที่ประชุม (ล็อก) |
| ขออนุญาตดำเนินโครงการ | permit request | อนุมัติชั้นที่ 2 ก่อนใช้เงินจริง ทำได้หลายรอบต่อโครงการ |
| ผูกพัน | `commitment` | สั่งซื้อ/ทำสัญญาแล้ว ยังไม่จ่าย |
| เงินเหลือจ่าย | return | ยอดอนุมัติที่ไม่ได้ใช้ คืนกองเงิน |
| คำขอปรับแผน | adjustment request | โอนเงินจากโครงการต้นทางไปปลายทาง |

---

## 2. เทคโนโลยี

ค่าเริ่มต้น (เปลี่ยนได้ถ้าผู้ใช้ระบุ):

- **Backend:** Laravel 11 (PHP 8.3)
- **Database:** PostgreSQL 16 — ใช้ `NUMERIC(14,2)` สำหรับเงินทุกช่อง, CHECK constraints, trigger กัน UPDATE/DELETE บน ledger
- **Frontend:** Inertia.js + Vue 3 + TypeScript + Tailwind CSS; ตารางใช้ TanStack Table; กราฟใช้ Apache ECharts
- **PDF:** mPDF พร้อมฟอนต์ TH Sarabun New (รองรับการตัดคำไทย)
- **Excel:** Laravel Excel (PhpSpreadsheet)
- **Queue/Scheduler:** Laravel queue (database driver) + scheduler สำหรับแจ้งเตือนรายวัน
- **Auth:** Laravel Breeze/Fortify + (ทางเลือก) Google Workspace หรือ Microsoft 365 OAuth ผ่าน Socialite
- **Notifications:** อีเมล + LINE Official Account (LINE Messaging API; ไม่ใช้ LINE Notify ซึ่งปิดบริการแล้ว)
- **Files:** Laravel Storage (local disk หรือ NAS ที่ mount ไว้) แยกโฟลเดอร์ตามปีงบ/โครงการ
- **Deploy:** Docker Compose (app, nginx, postgres, queue worker, scheduler) บน Linux VM ในเครือข่ายวิทยาลัย; backup `pg_dump` + ไฟล์แนบ รายวัน เก็บ 30 วัน
- **Testing:** Pest (unit + feature) ครอบคลุม BR ทุกข้อเกี่ยวกับเงิน

---

## 3. บทบาทและสิทธิ์

บทบาทผูกกับ `(user, role, org_unit, fiscal_year)` ผู้ใช้หนึ่งคนมีได้หลายบทบาท

| role code | ชื่อ | ขอบเขตข้อมูล |
|---|---|---|
| `proposer` | ผู้เสนอ/ผู้รับผิดชอบโครงการ | โครงการที่ตนเป็นผู้รับผิดชอบ + อ่านโครงการในหน่วยงานตน |
| `unit_head` | หัวหน้างาน/หัวหน้าแผนกวิชา | org_unit ของตน (รวมหน่วยย่อย) |
| `division_deputy` | รองผู้อำนวยการฝ่าย | ฝ่ายของตน (รวมหน่วยย่อย) |
| `planner` | งานวางแผนและงบประมาณ | ทั้งหมด + ตั้งค่า |
| `planning_deputy` | รองผู้อำนวยการฝ่ายแผนงานฯ | ทั้งหมด (อ่าน + ให้ความเห็น) |
| `director` | ผู้อำนวยการ | ทั้งหมด (อ่าน + อนุมัติ) |
| `finance` | งานการเงิน/งานบัญชี | การเงินทั้งหมด |
| `procurement` | งานพัสดุ | ผูกพัน/รายการจัดซื้อ |
| `board_viewer` | กรรมการวิทยาลัย | แดชบอร์ดสรุป + เอกสารเสนอที่ประชุม (อ่านอย่างเดียว) |
| `admin` | ผู้ดูแลระบบ (IT) | ผู้ใช้ บทบาท สำรองข้อมูล — ไม่มีสิทธิ์อนุมัติหรือบันทึกเงิน |

เมทริกซ์การกระทำหลัก (✓ = ทำได้ภายในขอบเขตข้อมูล):

| การกระทำ | proposer | unit_head | division_deputy | planner | planning_deputy | director | finance | procurement |
|---|---|---|---|---|---|---|---|---|
| สร้าง/แก้ร่างโครงการ | ✓ | ✓ | | ✓ | | | | |
| เห็นชอบในสายอนุมัติ | | ✓ | ✓ | ✓ | ✓ | ✓ | | |
| จัดการชุดพิจารณา | | | | ✓ | | | | |
| บันทึกมติ / ยืนยันแผนตั้งต้น | | | | ✓ | | | | |
| ขออนุญาตดำเนินการ / รายงานผล / ขอปรับแผน | ✓ | ✓ | | ✓ | | | | |
| ตั้งยอดยกมา / บันทึกรับเงิน / ปรับจัดสรร | | | | | | | ✓ | |
| ประมาณการรายรับ / กันเงิน | | | | ✓ | | | ✓ | |
| บันทึกผูกพัน | | | | | | | ✓ | ✓ |
| บันทึกเบิกจ่าย | | | | | | | ✓ | |
| ตั้งค่า (master data, สายอนุมัติ, น้ำหนัก) | | | | ✓ | | | | |

ใช้ Laravel Policies; ทุก query ต้องผ่าน scope ตามขอบเขตข้อมูล (ห้ามเชื่อ id จาก client)

---

## 4. โครงสร้างข้อมูล

ทุกตารางมี `id` (bigint identity), `created_at`, `updated_at` เว้นแต่ระบุ เงินทั้งหมด `NUMERIC(14,2) NOT NULL DEFAULT 0` และ `CHECK (>= 0)` ยกเว้นที่ระบุ

### 4.1 ข้อมูลหลัก

```sql
fiscal_years(
  id, year_be INT UNIQUE,            -- 2570
  starts_on DATE, ends_on DATE,
  status TEXT CHECK (status IN ('setup','proposal_open','deliberation','execution','closing','closed')),
  proposal_open_from DATE, proposal_open_to DATE,
  baseline_locked_at TIMESTAMPTZ NULL,
  settings JSONB                      -- น้ำหนักคะแนนเริ่มต้น, เกณฑ์สัญญาณไฟ, adjustment_compare_mode ฯลฯ
)

org_units(id, parent_id NULL REFERENCES org_units, name, kind TEXT CHECK (kind IN ('division','section','department')),
          code, student_count_vc INT NULL, student_count_hvc INT NULL, active BOOL)
-- division = ฝ่าย, section = งาน, department = แผนกวิชา; student_count_* ใช้เทียบสัดส่วนในเครื่องมือจำลองงบ

users(id, name, email UNIQUE, line_user_id NULL, position_title, active)
role_assignments(id, user_id, role, org_unit_id NULL, fiscal_year_id, UNIQUE(user_id, role, org_unit_id, fiscal_year_id))

fund_sources(id, fiscal_year_id, parent_id NULL, code, name, color_token,
  fund_type TEXT CHECK (fund_type IN ('state_budget','subsidy','institution_income','donation','other')),
  permit_rule TEXT CHECK (permit_rule IN ('allocation_letter','cumulative_receipts','cash_available')),
  is_leaf BOOL)                      -- โครงการ/รายการเงินอ้างถึงได้เฉพาะ leaf
fund_source_allowed_categories(fund_source_id, expense_category_id, PRIMARY KEY(...))

expense_categories(id, parent_id NULL, code, name, sort)   -- 2 ระดับ: หมวด > หมวดย่อย; อ้างถึงได้เฉพาะหมวดย่อย/leaf

alignment_sets(id, fiscal_year_id, code, name, required BOOL, sort)
-- code: national_strategy, ovec_policy, ovec_strategy, college_mission, vocational_standard, sufficiency_economy, other
alignment_items(id, alignment_set_id, parent_id NULL, code, label, sort, active)

approval_chains(id, fiscal_year_id, request_type, name)
approval_chain_steps(id, approval_chain_id, step_no, role, scope TEXT CHECK (scope IN ('project_unit','project_division','global')),
                     can_return BOOL, can_reject BOOL)
```

### 4.2 โครงการ

```sql
projects(
  id, fiscal_year_id, code UNIQUE,                 -- P70-014 (สร้างอัตโนมัติ)
  title, org_unit_id, created_by,
  project_kind TEXT CHECK (project_kind IN ('routine','ovec_policy','budget_act','other')),
  project_kind_note NULL,
  is_continuing BOOL, predecessor_project_id NULL REFERENCES projects,
  rationale TEXT, objectives JSONB, quantitative_targets JSONB, qualitative_targets JSONB,
  target_group TEXT, target_count INT, location TEXT, partners TEXT,
  necessity TEXT CHECK (necessity IN ('must','should','nice')),
  necessity_reason TEXT, impact_if_rejected TEXT,
  impact_areas JSONB,                              -- [{area:'students'|'safety'|'quality_assessment'|'external_obligation', level:'low'|'medium'|'high'}]
  beneficiaries INT,
  requested_total NUMERIC(14,2),                   -- คำนวณจาก budget_lines
  minimum_viable NUMERIC(14,2),                    -- BR-05
  is_phased BOOL,
  planned_quarters INT[],                          -- {1,2}
  expected_results JSONB,
  status TEXT,                                     -- ดูข้อ 5.1
  health TEXT CHECK (health IN ('green','yellow','red')) NULL,
  origin_adjustment_id NULL,                       -- โครงการที่เกิดจากการปรับแผน
  submitted_at, approved_at, closed_at
)
project_owners(project_id, user_id, is_primary BOOL)
project_alignments(project_id, alignment_item_id, indicator_note NULL)
project_phases(id, project_id, phase_no, amount, description)
budget_lines(id, project_id, phase_no NULL, expense_category_id, fund_source_id, item_name,
             quantity NUMERIC(12,2), unit, unit_price NUMERIC(14,2), amount NUMERIC(14,2), note)
monthly_plans(id, project_id, fund_source_id, month SMALLINT CHECK (month BETWEEN 1 AND 12), amount)
-- month 1 = ต.ค., 12 = ก.ย. (เดือนของปีงบ)
activities(id, project_id, pdca_stage TEXT CHECK (pdca_stage IN ('plan','do','check','act')), description, months SMALLINT[])
indicators(id, project_id, name, target_value NUMERIC, unit, actual_value NUMERIC NULL, achieved BOOL NULL)
attachments(id, attachable_type, attachable_id, kind TEXT, original_name, path, mime, size_bytes, uploaded_by)
progress_logs(id, project_id, percent SMALLINT, note, created_by)
project_reports(id, project_id, outputs TEXT, outcomes TEXT, problems TEXT, suggestions TEXT,
                status TEXT CHECK (status IN ('draft','submitted','returned','accepted')), submitted_at, reviewed_by, reviewed_at)
```

### 4.3 การพิจารณา

```sql
scenarios(id, fiscal_year_id, name, weights JSONB, revenue_shock_pct NUMERIC(5,2) DEFAULT 0,
          based_on_scenario_id NULL, created_by, is_final BOOL DEFAULT false)
scenario_decisions(id, scenario_id, project_id,
  decision TEXT CHECK (decision IN ('full','partial','phase1','waitlist','reject')),
  amounts JSONB,                     -- {fund_source_id: amount} จำนวนที่ให้ต่อแหล่งเงิน
  locked BOOL DEFAULT false, score NUMERIC(6,2), note,
  UNIQUE(scenario_id, project_id))
meetings(id, fiscal_year_id, meeting_no, held_on DATE, body TEXT, scenario_id, resolution_note, minutes_attachment_id NULL)
waitlist_entries(id, fiscal_year_id, project_id, priority_score, requested JSONB, status TEXT CHECK (status IN ('waiting','funded','dropped')))
```

### 4.4 เงิน

```sql
ledger_accounts(
  id, fiscal_year_id,
  kind TEXT CHECK (kind IN ('fund_pool','reserve','project','external','carry_forward')),
  fund_source_id NOT NULL,           -- ทุกบัญชีผูกกับแหล่งเงิน leaf (เงินติดป้ายแหล่งเงินเสมอ BR-20)
  project_id NULL, reserve_name NULL,
  UNIQUE(fiscal_year_id, kind, fund_source_id, project_id, reserve_name))

ledger_entries(                      -- APPEND-ONLY (trigger ห้าม UPDATE/DELETE)
  id, fiscal_year_id, entry_date DATE, posted_at TIMESTAMPTZ DEFAULT now(),
  entry_type TEXT CHECK (entry_type IN (
    'carry_in','receipt','allocation_adjust_up','allocation_adjust_down','reserve',
    'allocate','transfer','spend','return','carry_out','reversal')),
  from_account_id REFERENCES ledger_accounts,
  to_account_id REFERENCES ledger_accounts,
  amount NUMERIC(14,2) CHECK (amount > 0),
  expense_category_id NULL,          -- บังคับเมื่อ entry_type='spend'
  reference_no TEXT, reference_date DATE NULL, installment_no INT NULL,
  source_type TEXT NULL, source_id BIGINT NULL,     -- เช่น meeting, adjustment_request, disbursement
  reverses_entry_id NULL REFERENCES ledger_entries, -- สำหรับ reversal
  note TEXT, created_by)

revenue_estimates(id, fiscal_year_id, fund_source_id, version INT, label, basis JSONB, amount, is_current BOOL)
-- basis ตัวอย่าง {"students":540,"rate":1500,"terms":2}

commitments(id, project_id, fund_source_id, expense_category_id, document_no, document_date, amount,
            amount_settled NUMERIC(14,2) DEFAULT 0, status TEXT CHECK (status IN ('open','settled','cancelled')), created_by)
disbursements(id, project_id, fund_source_id, expense_category_id, commitment_id NULL, paid_on DATE,
              amount, voucher_no, external_ref NULL, ledger_entry_id, created_by)
```

`external` account มีหนึ่งบัญชีต่อแหล่งเงินต่อปี ใช้เป็นต้นทางของ `receipt` และปลายทางของ `spend`

### 4.5 คำขอและการอนุมัติ

```sql
requests(id, fiscal_year_id, request_type TEXT CHECK (request_type IN ('proposal','permit','adjustment','report_review')),
  project_id NULL, requested_by, status TEXT CHECK (status IN ('draft','pending','returned','approved','rejected','cancelled')),
  current_step_no INT, payload JSONB, decided_at)
permit_requests(id, request_id, project_id, run_on DATE, run_to DATE NULL, location, amounts JSONB, items JSONB, note)
adjustment_requests(id, request_id, adjustment_type TEXT CHECK (adjustment_type IN
  ('detail','line_change','split','merge','replace','increase')),
  reason TEXT, extra_funding_note TEXT NULL, has_increase BOOL)
adjustment_lines(id, adjustment_request_id, project_id NULL, new_project_draft JSONB NULL,
  role TEXT CHECK (role IN ('source','target','extra_funding')),
  fund_source_id, amount)
approval_steps(id, request_id, step_no, role, assignee_user_id NULL,
  decision TEXT CHECK (decision IN ('approve','return','reject')) NULL, comment, decided_by NULL, decided_at NULL)
audit_logs(id, user_id, action, subject_type, subject_id, before JSONB, after JSONB, ip, user_agent, created_at)
notifications(id, user_id, channel, kind, payload JSONB, sent_at NULL, read_at NULL)
```

---

## 5. สถานะและการเปลี่ยนสถานะ

### 5.1 `projects.status`

```
draft ──submit──▶ submitted ──(ครบสายเห็นชอบ)──▶ planning_review ──planner รับเข้ารอบ──▶ pending_decision
  ▲                  │ return                         │ return
  └──── returned ◀───┴────────────────────────────────┘

pending_decision ──มติ full/partial/phase1──▶ approved
pending_decision ──มติ waitlist──▶ waitlisted ──มีเงิน (BR-33)──▶ approved
pending_decision ──มติ reject──▶ rejected

approved ──permit แรกได้รับอนุมัติ──▶ in_progress
in_progress ──ผู้รับผิดชอบกด "ดำเนินการเสร็จ"──▶ awaiting_report
awaiting_report ──รายงานผ่านการตรวจ──▶ closed        (สร้าง return อัตโนมัติ BR-41)
approved | in_progress ──ปรับแผนโอนออกทั้งหมด──▶ transferred_out
สถานะใดก็ได้ก่อน closed ──director ยกเลิก──▶ cancelled (คืนยอดคงเหลือ BR-42)
```

ห้ามข้ามสถานะ ทุกการเปลี่ยนต้องผ่าน service เดียว (`ProjectStateMachine`) และเขียน audit log

### 5.2 `health` (คำนวณทุกคืนและเมื่อมีเหตุการณ์)

ค่าเริ่มต้น (ตั้งค่าได้ใน `fiscal_years.settings.health`):

- **red**: (ก) ยังไม่มี permit อนุมัติ และวันนี้อยู่ในไตรมาส 4 ขณะที่ `planned_quarters` ไม่มี 4, หรือ (ข) spent > approved ในแหล่งเงินใด
- **yellow**: เลยไตรมาสสุดท้ายใน `planned_quarters` แล้วยังไม่มี permit, หรือ อยู่ใน `awaiting_report` เกิน `report_due_days` (ค่าเริ่มต้น 15)
- **green**: นอกเหนือจากนี้ (เฉพาะ approved / in_progress / awaiting_report)
- สถานะอื่น: `NULL`

---

## 6. กฎธุรกิจ

### การเสนอโครงการ
- **BR-01** ยอด `requested_total` = Σ `budget_lines.amount` และ `amount` = `quantity × unit_price` (ปัดเศษ 2 ตำแหน่ง half-up)
- **BR-02** Σ `monthly_plans.amount` ต่อแหล่งเงิน = Σ `budget_lines.amount` ของแหล่งเงินนั้น
- **BR-03** ต้องเลือก alignment อย่างน้อย 1 รายการในทุก `alignment_sets.required = true`
- **BR-04** `necessity = 'must'` → `impact_if_rejected` บังคับกรอก
- **BR-05** `0 < minimum_viable ≤ requested_total`; ถ้า `is_phased` → Σ `project_phases.amount` = `requested_total` และ phase 1 ≥ `minimum_viable` ไม่บังคับ
- **BR-06** `budget_lines.expense_category_id` ต้องอยู่ใน `fund_source_allowed_categories` ของแหล่งเงินบรรทัดนั้น
- **BR-07** สร้าง/ส่งข้อเสนอได้เฉพาะช่วง `proposal_open_from..proposal_open_to` (planner ข้ามได้ พร้อมเหตุผล)

### การจำลองงบและมติ
- **BR-10** วงเงินจัดสรรได้ต่อแหล่งเงิน (ใช้ในชุดพิจารณา):
  `available_est = carry_in + Σ current revenue_estimates × (1 − revenue_shock_pct/100) ± allocation adjustments − reserves`
- **BR-11** ในชุดพิจารณา `partial` ต้อง ≥ `minimum_viable` และ ≤ ยอดขอ; `phase1` = ยอด phase 1; `waitlist`/`reject` = 0
- **BR-12** คะแนน (ข้อ 7.2) คำนวณใหม่ทุกครั้งที่เปลี่ยนน้ำหนัก
- **BR-13** ยืนยันแผนตั้งต้นได้เมื่อทุกแหล่งเงิน Σ จัดสรรในชุด ≤ `available_est` (planner ข้ามได้ด้วย flag + เหตุผล ซึ่งต้องแสดงในเอกสารเสนอ)
- **BR-14** การยืนยันแผนตั้งต้นทำใน transaction เดียว: สร้าง `allocate` entry ต่อโครงการต่อแหล่งเงิน, เปลี่ยนสถานะโครงการ, สร้าง `waitlist_entries`, ตั้ง `baseline_locked_at`, ส่งแจ้งเตือนผลถึงผู้เสนอทุกคน
- **BR-15** หลัง `baseline_locked_at` แก้ยอดอนุมัติได้ผ่านคำขอปรับแผนเท่านั้น

### กองเงินและสมุดบัญชี
- **BR-20** ทุก ledger account ผูกกับแหล่งเงิน leaf หนึ่งแหล่ง; `transfer` ต้องมี `from` และ `to` เป็นแหล่งเงินเดียวกัน (เงินไม่เปลี่ยนป้ายแหล่งเงินระหว่างโอน)
- **BR-21** `ledger_entries` ห้าม UPDATE/DELETE (trigger) แก้ด้วย `reversal` ที่สลับ from/to และอ้าง `reverses_entry_id`; รายการหนึ่งถูก reverse ได้ครั้งเดียว
- **BR-22** ห้ามโพสต์รายการที่ทำให้ยอดคงเหลือของบัญชี `fund_pool` (ชั้นจริง) หรือ `project` ติดลบ — ตรวจใน transaction ด้วย `SELECT ... FOR UPDATE` บนบัญชีที่เกี่ยวข้อง
- **BR-23** `receipt` ต้องมี `reference_no` และ `reference_date`; แนบไฟล์ได้
- **BR-24** สูตรยอดคงเหลือกองเงิน (ชั้นจริง):
  `pool_actual = carry_in + receipts + adjust_up − adjust_down − reserves − allocations + returns − carry_out` (คำนวณจาก entries; อนุญาต cache ใน materialized view แต่ต้อง refresh ใน transaction เดียวกับการโพสต์)
- **BR-25** ยอดต่อโครงการต่อแหล่งเงิน:
  `allocated = allocate + transfer_in − transfer_out − return`
  `committed_open = Σ (commitments.amount − amount_settled) where status='open'`
  `spent = Σ spend`
  `free = allocated − committed_open − spent` (ใช้ทั้งการโอนออกและการขออนุญาต)

### การขออนุญาตและเบิกจ่าย
- **BR-30** ยอดขออนุญาตต่อแหล่งเงิน ≤ `allocated − Σ permits ที่อนุมัติแล้ว`
- **BR-31** ตรวจตาม `fund_sources.permit_rule` ตอนส่งคำขอและตอน director อนุมัติ:
  - `allocation_letter`: ต้องมี `receipt` หรือ `allocation_adjust_up` อย่างน้อย 1 รายการที่มี reference ของแหล่งเงินนั้นในปีนี้
  - `cumulative_receipts`: Σ permits อนุมัติแล้วของทั้งแหล่งเงิน + คำขอนี้ ≤ Σ receipts สะสม
  - `cash_available`: คำขอนี้ ≤ Σ receipts − Σ spend − Σ committed_open ของทั้งแหล่งเงิน
  ไม่ผ่าน → แสดงเหตุผลพร้อมจำนวนที่ขาด, ส่งไม่ได้ (planner override ได้พร้อมเหตุผล)
- **BR-32** `spend` ต่อโครงการต่อแหล่งเงินห้ามเกิน `allocated` (BR-22); ถ้าเกิน permits ที่อนุมัติให้เตือนแต่ไม่บล็อก
- **BR-33** เมื่อ `pool_actual` ของแหล่งเงินเพิ่มขึ้น (receipt / return) ระบบแจ้ง planner ถึงรายการ `waitlist_entries` ที่ยอดขอ ≤ คงเหลือ เรียงตามคะแนน (planner กดอนุมัติเอง ไม่อนุมัติอัตโนมัติ) — การอนุมัติจาก waitlist สร้างคำขอประเภท `adjustment` ชนิด `increase` ส่งถึง director

### การปิดโครงการ
- **BR-40** ส่งรายงานผลได้เมื่อสถานะ `awaiting_report`; ตาราง "ตามแผน vs ใช้จริง" ดึง spent จาก ledger อัตโนมัติ (แก้ไม่ได้)
- **BR-41** รายงานถูก `accepted` → สร้าง `return` entry ของ `free` ทุกแหล่งเงินกลับ fund_pool, ยกเลิก commitments ที่ open (ต้องยืนยันกับ finance ก่อนถ้ามี) → สถานะ `closed`
- **BR-42** ยกเลิกโครงการ: ห้ามถ้ามี commitment open; คืน `free` ทั้งหมดเป็น `return`

### การปรับแผน
- **BR-50** ต้นทางให้ได้ไม่เกิน `free` ของแต่ละแหล่งเงิน (BR-25)
- **BR-51** โหมดเปรียบเทียบ `fiscal_years.settings.adjustment_compare_mode` ค่าเริ่มต้น `per_fund`:
  - `per_fund`: Δ_f = Σ target_f − Σ source_f คำนวณแยกทุกแหล่งเงิน f
  - `total`: Δ = Σ target − Σ source รวมทุกแหล่ง (แต่ BR-20 ยังบังคับ: เงินส่วนที่เปลี่ยนแหล่งต้องมาจาก extra_funding)
- **BR-52** Δ ≤ 0 ทุกแหล่ง → `has_increase = false`, ส่วนต่างสร้าง `return` เข้ากองเงินเดิมเมื่ออนุมัติ, ใช้สายอนุมัติ `adjustment` ปกติ
- **BR-53** Δ_f > 0 → `has_increase = true`, ต้องมี `adjustment_lines.role='extra_funding'` ของแหล่งเงิน f รวม = Δ_f, ต้องกรอก `extra_funding_note`, ระบบตรวจ `pool_actual` ของ f ≥ Δ_f, สายอนุมัติบังคับจบที่ director เสมอ และติดป้าย "ใช้งบเพิ่ม" ในรายงานทุกฉบับ
- **BR-54** เมื่ออนุมัติ ทำใน transaction เดียว: สร้างโครงการใหม่จาก `new_project_draft` (สถานะ `approved`, `origin_adjustment_id`), `transfer` entries จากต้นทางไปปลายทาง, `allocate` จาก pool สำหรับ extra_funding, `return` สำหรับส่วนเหลือ, เปลี่ยนสถานะต้นทางเป็น `transferred_out` ถ้า allocated เหลือ 0
- **BR-55** แหล่งเงิน `fund_type='state_budget'` ที่อยู่ในคำขอปรับแผน → แสดงคำเตือนให้ตรวจหลักเกณฑ์การโอนเปลี่ยนแปลงเงินจัดสรร (เตือนเท่านั้น ไม่บล็อก)
- **BR-56** `detail` และ `line_change` ไม่เปลี่ยนยอดรวมต่อแหล่งเงินของโครงการ (`line_change` เปลี่ยนการกระจายหมวดใน budget_lines ซึ่งต้องผ่าน BR-06)

### ปิดปีงบประมาณ
- **BR-60** ปิดปีได้เมื่อ finance ยืนยันยอดทุกกอง; สร้าง `carry_out` ของ `pool_actual` ทุกแหล่งเงิน และ `carry_in` ในปีถัดไป (map แหล่งเงินด้วย `code`)
- **BR-61** โครงการที่มี commitment open ณ วันปิดปี → รายงานแยก "ก่อหนี้ผูกพันข้ามปี" (ไม่ย้ายอัตโนมัติ ให้ planner ตัดสิน)

---

## 7. การคำนวณ

### 7.1 แปลงปัจจัยเป็นคะแนน 0–100

| ปัจจัย | key | วิธีคำนวณ |
|---|---|---|
| ความจำเป็น | `necessity` | must = 100, should = 60, nice = 20 |
| ผลกระทบ | `impact` | ระดับสูงสุดใน `impact_areas`: high = 100, medium = 60, low = 30, ไม่มี = 0; +10 ถ้ามี `safety` หรือ `external_obligation` (เพดาน 100) |
| ความสอดคล้อง | `alignment` | (จำนวน alignment_sets ที่เลือก ≥1 รายการ / จำนวน sets ทั้งหมด) × 100 |
| ผู้ได้รับประโยชน์ | `beneficiaries` | percentile rank ของ `beneficiaries` ภายในรอบเดียวกัน × 100 |
| ผลปีก่อน | `past` | ร้อยละ indicators ที่ achieved ของ predecessor; ไม่มี predecessor = 60 |

### 7.2 คะแนนรวม

`score = Σ w_k × factor_k / 100` โดย Σ w = 100 ค่าเริ่มต้น `{necessity:30, impact:25, alignment:15, beneficiaries:15, past:15}`

### 7.3 เติมอัตโนมัติตามคะแนน (ต่อ scenario)

```
remaining[f] = available_est[f] for each fund f
for d in decisions where locked:            # หักโครงการที่ล็อกก่อน
    remaining -= d.amounts
for p in unlocked projects ordered by score desc, then requested_total asc:
    req = p.requested_by_fund               # {f: amount}
    if all(req[f] <= remaining[f]):            set full;    remaining -= req
    elif p.is_phased and fits(phase1_by_fund): set phase1;  remaining -= phase1
    elif fits(scale(req, p.minimum_viable / p.requested_total)):
         set partial at the largest uniform ratio r in [min/req_total, 1] that fits (binary search, ปัดลงหลักร้อย)
    else:                                    set waitlist
```

`scale` กระจายตามสัดส่วนแหล่งเงินเดิมของโครงการ

### 7.4 ลดเท่ากันทุกโครงการ x%

สำหรับโครงการที่ไม่ล็อกและผล = full/partial: ยอดใหม่ = max(minimum_viable, ยอดเดิม × (1 − x/100)) กระจายตามสัดส่วนแหล่งเงิน แล้วแสดงยอดที่ยังขาดต่อแหล่งเงิน

### 7.5 ทดสอบรายรับลดลง

เปลี่ยน `revenue_shock_pct` แล้วคำนวณ BR-10 ใหม่ โครงการที่ทำให้ remaining ติดลบ (ไล่จากคะแนนต่ำสุดขึ้นไป) ทำเครื่องหมาย `at_risk` ในผลลัพธ์ (ไม่เปลี่ยน decision จริง)

### 7.6 การกระจายเทียบสัดส่วนผู้เรียน

ต่อแผนกวิชา: `share_money = Σ จัดสรรของแผนก / Σ จัดสรรทุกแผนก`, `share_students = (vc + hvc) / Σ ทุกแผนก` แสดงคู่กัน

---

## 8. สายอนุมัติ (ค่าเริ่มต้น ตั้งค่าได้)

| request_type | ขั้นตอน |
|---|---|
| `proposal` | unit_head (project_unit) → division_deputy (project_division) → planner (global, ตรวจครบถ้วน แล้วเข้า `planning_review`) |
| `permit` | division_deputy → planner → planning_deputy → director |
| `adjustment` (ไม่มีเพิ่ม) | unit_head → planner → director |
| `adjustment` (has_increase) | unit_head → division_deputy → planner → planning_deputy → director |
| `report_review` | planner |

- `return` กลับไปผู้ขอ แก้แล้วส่งใหม่เริ่มจากขั้นแรก
- ถ้าผู้ขอดำรงบทบาทของขั้นใด ข้ามขั้นนั้นอัตโนมัติ (บันทึกว่า skipped)
- ผู้มีสิทธิ์ขั้นใดก็ตามที่ไม่ตัดสินภายใน 3 วันทำการ ได้รับแจ้งเตือนซ้ำ

---

## 9. การแจ้งเตือน

| เหตุการณ์ | ผู้รับ | ช่องทาง |
|---|---|---|
| มีคำขอรอฉันตัดสิน | ผู้มีสิทธิ์ขั้นปัจจุบัน | อีเมล + LINE + ในระบบ |
| คำขอถูกส่งกลับ/อนุมัติ/ไม่อนุมัติ | ผู้ขอ | อีเมล + LINE + ในระบบ |
| ประกาศผลแผนตั้งต้น | ผู้เสนอทุกคน | อีเมล + ในระบบ |
| ถึงไตรมาสที่วางแผนแต่ยังไม่ขออนุญาต (วันแรกของไตรมาส) | ผู้รับผิดชอบ | LINE + ในระบบ |
| health เปลี่ยนเป็น yellow/red | ผู้รับผิดชอบ → (ค้าง 7 วัน) unit_head → (ค้าง 14 วัน) division_deputy | LINE + ในระบบ |
| รายงานผลค้างเกินกำหนด | ผู้รับผิดชอบ, unit_head | LINE + ในระบบ |
| รับเงินจริงสะสมต่ำกว่าประมาณการตามสัดส่วนเวลาเกิน 10% | planner, finance | อีเมล + ในระบบ |
| มีเงินเพียงพอสำหรับรายการรอเงินเพิ่ม (BR-33) | planner | ในระบบ |

ผู้ใช้ปิดช่องทาง LINE ได้เอง; เชื่อม LINE ด้วยการสแกน QR เพิ่มเพื่อน Official Account แล้วยืนยันรหัสในหน้าโปรไฟล์

---

## 10. รายงาน (PDF + Excel)

1. **เล่มแผนปฏิบัติราชการ ส่วนที่ 3** — สรุปผลการใช้จ่ายปีก่อน, ประมาณการรายรับ-รายจ่ายรายกองเงิน (แยกหมวด), สรุปงบหน้ารายจ่าย, ปฏิทินโครงการ/แผนใช้จ่ายรายเดือน
2. **รายงานผลรอบ 6 / 9 / 12 เดือน** — ต่อแหล่งเงินและหมวด: อนุมัติ, จ่ายจริง, ร้อยละ; รายการโครงการตามสถานะ
3. **เทียบแผนตั้งต้นกับแผนปัจจุบัน** — ต่อโครงการ + รายการคำขอปรับแผนทั้งหมด, ไฮไลต์ "ใช้งบเพิ่ม"
4. **ฐานะเงินรายกอง** — ยกมา / ประมาณการ / รับจริง / ปรับ / กันไว้ / จัดสรร / ผูกพัน / จ่าย / คงเหลือ + ledger ช่วงวันที่
5. **ตามยุทธศาสตร์/มาตรฐาน** — งบและจำนวนโครงการต่อ alignment_item (สำหรับ SAR)
6. **แบบฟอร์มรายโครงการ** — แบบเสนอ, แบบขออนุญาต, แบบรายงานผล ตามรูปแบบหนังสือราชการ ฟอนต์ TH Sarabun New 16pt, ช่องลงนามตามสายอนุมัติ
7. **เอกสารเสนอที่ประชุม** — จาก scenario: สรุปรายกอง, ตารางโครงการพร้อมผลที่เสนอและคะแนน, น้ำหนักที่ใช้

ทุกรายงานแสดง "ข้อมูล ณ วันที่ ... เวลา ..." และเลือก baseline หรือ current ได้

---

## 11. หน้าและ endpoint หลัก

Inertia routes (GET แสดงหน้า, POST/PUT/PATCH ทำการกระทำ) — การคำนวณสด (scenario, Δ ปรับแผน, ตรวจเงิน) เป็น JSON endpoint ภายใต้ `/api/*` (session auth)

```
/                                   แดชบอร์ดตามบทบาท
/inbox                              งานรอฉัน
/projects                           รายการ (filters: fy, unit, fund, status, health, q)
/projects/create                    wizard (บันทึกร่างอัตโนมัติ PATCH ทุก 10 วินาที)
/projects/{id}                      รายละเอียด (tabs)
/projects/{id}/permits/create       ขออนุญาต
/projects/{id}/report               รายงานผล
/adjustments, /adjustments/create   คำขอปรับแผน
/requests/{id}                      หน้าพิจารณาคำขอ  POST /requests/{id}/decide {decision, comment}
/scenarios, /scenarios/{id}         workspace
/scenarios/compare?ids=1,2,3
/meetings/create                    บันทึกมติ  POST /meetings/{id}/lock-baseline
/funds                              ภาพรวมกองเงิน
/funds/estimates                    ประมาณการรายรับ
/funds/receipts/create              บันทึกรับเงิน
/ledger                             สมุดบัญชี  POST /ledger/{entry}/reverse {reason}
/finance/commitments, /finance/disbursements
/reports, /reports/{key}?format=pdf|xlsx&...
/settings/*                         master data

GET  /api/funds/balances?fy=          ยอดทุกกองทั้งสองชั้น
POST /api/scenarios/{id}/recalculate  {weights?, revenue_shock_pct?} → scores, remaining, at_risk
POST /api/scenarios/{id}/autofill
POST /api/scenarios/{id}/reduce       {pct}
PATCH /api/scenarios/{id}/decisions/{project}
POST /api/adjustments/preview         {lines[]} → delta per fund, has_increase, errors
POST /api/permits/check               {project_id, amounts} → per fund: ok | {short_by, rule}
```

---

## 12. ข้อกำหนดที่ไม่ใช่ฟังก์ชัน

- **ความถูกต้องของเงิน:** การกระทำที่สร้าง ledger entries + เปลี่ยนสถานะ ต้องอยู่ใน DB transaction เดียว พร้อม row lock; test ครอบคลุมกรณีพร้อมกัน (2 คำขอแย่งเงินก้อนเดียว)
- **Audit:** ทุกการเปลี่ยนแปลงข้อมูลโครงการ คำขอ เงิน ตั้งค่า บันทึกลง `audit_logs` (before/after)
- **ความปลอดภัย:** HTTPS, CSRF, rate limit login, รหัสผ่าน ≥ 10 ตัว หรือ OAuth, session timeout 8 ชม., ไฟล์แนบตรวจ MIME (pdf, docx, xlsx, jpg, png) ≤ 20 MB, เก็บนอก public path และดาวน์โหลดผ่าน controller ตรวจสิทธิ์
- **ภาษาและรูปแบบ:** UI ไทยทั้งหมด (`lang/th`), วันที่ พ.ศ. ทั้งแสดงและกรอก (date picker แบบ พ.ศ.), ตัวเลข `1,234,567.89`, ปีงบเริ่ม ต.ค.
- **ประสิทธิภาพ:** รองรับ ≥ 500 โครงการ/ปี, ≥ 200 ผู้ใช้; หน้า scenario คำนวณใหม่ < 300 ms สำหรับ 500 โครงการ
- **Responsive:** หน้าของ proposer/unit_head/director/permit/report ใช้งานได้ที่กว้าง 360 px
- **Accessibility:** contrast ≥ 4.5:1, ทุกปุ่มใช้คีย์บอร์ดได้, สถานะไม่สื่อด้วยสีอย่างเดียว
- **สำรองข้อมูล:** รายวัน 02:00, ทดสอบกู้คืนได้ (มีคำสั่ง `php artisan backup:restore-test`)

---

## 13. ข้อมูลตั้งต้น (seeders)

- ปีงบ 2570 (`starts_on 2026-10-01`, `ends_on 2027-09-30`, status `execution`)
- หน่วยงาน: 4 ฝ่าย (บริหารทรัพยากร, แผนงานและความร่วมมือ, พัฒนากิจการนักเรียนนักศึกษา, วิชาการ) + งานมาตรฐานในแต่ละฝ่าย (บริหารงานทั่วไป, บุคลากร, การเงิน, การบัญชี, พัสดุ, อาคารสถานที่, ทะเบียน, ประชาสัมพันธ์, วางแผนและงบประมาณ, ศูนย์ข้อมูลสารสนเทศ, ความร่วมมือ, วิจัยพัฒนานวัตกรรมและสิ่งประดิษฐ์, ประกันคุณภาพและมาตรฐานการศึกษา, ส่งเสริมผลิตผลการค้าและประกอบธุรกิจ, กิจกรรมนักเรียนนักศึกษา, ครูที่ปรึกษา, ปกครอง, แนะแนวอาชีพและการจัดหางาน, สวัสดิการนักเรียนนักศึกษา, โครงการพิเศษและการบริการชุมชน, พัฒนาหลักสูตรการเรียนการสอน, วัดผลและประเมินผล, วิทยบริการและห้องสมุด, อาชีวศึกษาระบบทวิภาคี, สื่อการเรียนการสอน) + แผนกวิชาตัวอย่าง (ให้แก้ตามจริงของวิทยาลัย)
- แหล่งเงิน:
  - เงินงบประมาณ (`state_budget`, `allocation_letter`) > ปวช., ปวส., ระยะสั้น, ครุภัณฑ์, โครงการตามนโยบาย สอศ.
  - เงินอุดหนุน (`subsidy`, `cumulative_receipts`) > ค่าจัดการเรียนการสอน, ค่าหนังสือเรียน, ค่าอุปกรณ์การเรียน, ค่าเครื่องแบบนักเรียน, ค่ากิจกรรมพัฒนาคุณภาพผู้เรียน
  - เงินนอกงบประมาณ > เงินรายได้สถานศึกษา (`institution_income`, `cash_available`) > ค่าบำรุงการศึกษา, ผลประโยชน์จากทรัพย์สิน; เงินบริจาค (`donation`, `cash_available`); เงินอื่น ๆ (`other`, `cash_available`)
- หมวดรายจ่าย: ตามข้อ 1 (เปิดทุกหมวดให้ทุกแหล่งเงินไว้ก่อน งานแผนฯ ปรับตามระเบียบจริง)
- alignment sets: ยุทธศาสตร์ชาติ 20 ปี (6 ด้าน: ความมั่นคง, การสร้างความสามารถในการแข่งขัน, การพัฒนาและเสริมสร้างศักยภาพทรัพยากรมนุษย์, การสร้างโอกาสและความเสมอภาคทางสังคม, การสร้างการเติบโตบนคุณภาพชีวิตที่เป็นมิตรต่อสิ่งแวดล้อม, การปรับสมดุลและพัฒนาระบบการบริหารจัดการภาครัฐ); มาตรฐานการอาชีวศึกษา (มาตรฐานที่ 1 ด้าน 1.1–1.3, มาตรฐานที่ 2 ด้าน 2.1–2.4, มาตรฐานที่ 3 ด้าน 3.1–3.2); นโยบาย สอศ., พันธกิจ/กลยุทธ์สถานศึกษา, ปรัชญาเศรษฐกิจพอเพียง ใส่เป็นข้อความตัวอย่างให้งานแผนฯ แก้
- ข้อมูลตัวอย่าง (เฉพาะ environment `local`/`demo`): ตามข้อ 8 ของ `design-brief.md`

---

## 14. ลำดับการพัฒนาและเกณฑ์ตรวจรับ

### ระยะที่ 1 — ฐานระบบและสมุดบัญชีเงิน
ขอบเขต: auth, บทบาท, master data (ข้อ 4.1), ledger (4.4), ประมาณการ, รับเงิน, ปรับจัดสรร, กันเงิน, นำเข้าแผนปี 2570 ที่อนุมัติแล้วจาก Excel (template ให้ดาวน์โหลด: รหัส, ชื่อ, หน่วยงาน, แหล่งเงิน, หมวด, ยอดอนุมัติ, ไตรมาส) → สร้าง projects สถานะ `approved` + `allocate` entries, หน้าภาพรวมกองเงิน + ledger
ตรวจรับ:
- [ ] ledger ห้าม UPDATE/DELETE ที่ระดับฐานข้อมูล (test ยิง SQL ตรงแล้ว error)
- [ ] reversal ทำงานและยอดกลับเป็นเดิม
- [ ] นำเข้า Excel ตัวอย่าง 100 โครงการ ยอดรวมต่อแหล่งเงินตรงกับไฟล์
- [ ] ยอดคงเหลือ BR-24 ถูกต้องในทุก test case รวมกรณีพร้อมกัน

### ระยะที่ 2 — ติดตามโครงการ
ขอบเขต: ขออนุญาต (BR-30/31), สายอนุมัติ, ผูกพัน/เบิกจ่าย, ความก้าวหน้า, รายงานผล, ปิดโครงการ + return, health, แจ้งเตือน, แดชบอร์ด, รายงานรอบ 6/9/12 เดือน, ฐานะเงิน
ตรวจรับ:
- [ ] permit ที่ขัด permit_rule ถูกบล็อกพร้อมจำนวนที่ขาด
- [ ] เบิกจ่ายเกิน allocated ถูกบล็อก
- [ ] ปิดโครงการคืนเงินเหลือจ่ายถูกต้องทุกแหล่งเงิน
- [ ] health คำนวณตรงตามข้อ 5.2 ด้วยวันที่จำลอง

### ระยะที่ 3 — ปรับแผน
ขอบเขต: adjustment ทุกประเภท, preview Δ, has_increase, ผังที่มาของเงิน, รายงานเทียบแผน
ตรวจรับ:
- [ ] 3 ตัวอย่างในข้อ 15 ของเอกสารนี้ให้ผลตรงตาราง
- [ ] โอนเกิน free ถูกบล็อก
- [ ] has_increase บังคับ extra_funding + note + จบที่ director

### ระยะที่ 4 — เสนอโครงการและจำลองงบ
ขอบเขต: wizard เสนอ (BR-01..07), สายอนุมัติ proposal, scenario workspace (คะแนน, autofill, reduce, shock, เทียบชุด), meeting + lock baseline (BR-13..15), waitlist (BR-33), เอกสารเสนอที่ประชุม
ตรวจรับ:
- [ ] autofill ไม่ทำให้แหล่งเงินใดติดลบ และเคารพ locked/minimum_viable/phase
- [ ] lock baseline เป็น transaction เดียว (จำลองล้มกลางทาง → ไม่มีอะไรเปลี่ยน)
- [ ] เปลี่ยนน้ำหนักแล้วลำดับเปลี่ยนตามสูตร 7.2

### ระยะที่ 5 — เล่มแผนและการเชื่อมต่อ
ขอบเขต: รายงานเล่มแผน ส่วนที่ 3, รายงานตามยุทธศาสตร์, นำเข้าจำนวนผู้เรียน (CSV) เพื่อคำนวณ basis ประมาณการ, ปิดปีงบ (BR-60/61)

---

## 15. ตัวอย่างทดสอบการปรับแผน (ใช้เป็น fixture)

| กรณี | ต้นทาง (free) | ปลายทาง | โหมด per_fund ผลที่ถูกต้อง |
|---|---|---|---|
| รวม | A 50,000 + B 30,000 (เงินรายได้) | C 75,000 (เงินรายได้) | Δ_รายได้ = −5,000 → has_increase=false, return 5,000 เข้ากองเงินรายได้ |
| แยก | A 100,000 (เงินอุดหนุน) | A1 60,000 + A2 55,000 (เงินอุดหนุน) | Δ_อุดหนุน = +15,000 → has_increase=true, ต้องมี extra_funding อุดหนุน 15,000 |
| เปลี่ยนแหล่ง | A 40,000 (เงินรายได้) | B 40,000 (เงินอุดหนุน) | Δ_รายได้ = −40,000 (return), Δ_อุดหนุน = +40,000 → has_increase=true |

---

## 16. คำถามที่ยังเปิด (ใช้ค่าเริ่มต้นไปก่อน แต่ทำให้ตั้งค่าได้)

1. **โหมดเปรียบเทียบการปรับแผน** — แยกรายกองเงิน (`per_fund`, ค่าเริ่มต้น) หรือยอดรวม (`total`)
2. **กฎตรวจเงินก่อนอนุญาต** ต่อแหล่งเงิน — ค่าเริ่มต้นตามข้อ 13 รอยืนยันจากงานการเงิน
3. **หมวดรายจ่ายที่แต่ละแหล่งเงินใช้ได้** — รอระเบียบ/แนวปฏิบัติปัจจุบันจากงานการเงิน
4. **สายอนุมัติจริงของวิทยาลัย** และกำหนดส่งรายงานผล (15 หรือ 30 วัน)
5. **บัญชีเข้าสู่ระบบ** — วิทยาลัยใช้ Google Workspace หรือ Microsoft 365 หรือไม่
6. **เซิร์ฟเวอร์ติดตั้ง** — มี Linux VM/Docker host หรือต้องติดตั้งบน Windows Server (ถ้าเป็น Windows ให้ใช้ Docker Desktop/WSL2 หรือ Laragon แล้วปรับข้อ 2)
