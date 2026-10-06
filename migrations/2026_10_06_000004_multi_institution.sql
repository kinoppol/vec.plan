-- @description รองรับหลายสถานศึกษา: ตาราง institutions, ผูกปีงบประมาณ/หน่วยงาน/หมวดรายจ่าย/ผู้ใช้/ตั้งค่า/audit/ตัวนับเลขกับสถานศึกษา, บทบาทผู้ดูแลระบบกลาง
-- @up
CREATE TABLE institutions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(200) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inst_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing data becomes institution 1; its name moves out of settings.org_name.
INSERT INTO institutions (id, code, name)
  VALUES (1, 'MAIN', COALESCE((SELECT svalue FROM settings WHERE skey = 'org_name' LIMIT 1), 'สถานศึกษา'));

-- Year-scoped data (funds, projects, ledger, …) follows its fiscal year, so fiscal_years carries the institution.
ALTER TABLE fiscal_years
  ADD COLUMN institution_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  DROP INDEX uq_fy_year,
  ADD UNIQUE KEY uq_fy_year (institution_id, year_be),
  ADD CONSTRAINT fk_fy_inst FOREIGN KEY (institution_id) REFERENCES institutions(id);
ALTER TABLE fiscal_years ALTER COLUMN institution_id DROP DEFAULT;

ALTER TABLE org_units
  ADD COLUMN institution_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  DROP INDEX uq_unit_code,
  ADD UNIQUE KEY uq_unit_code (institution_id, code),
  ADD CONSTRAINT fk_unit_inst FOREIGN KEY (institution_id) REFERENCES institutions(id);
ALTER TABLE org_units ALTER COLUMN institution_id DROP DEFAULT;

ALTER TABLE expense_categories
  ADD COLUMN institution_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
  DROP INDEX uq_cat_code,
  ADD UNIQUE KEY uq_cat_code (institution_id, code),
  ADD CONSTRAINT fk_cat_inst FOREIGN KEY (institution_id) REFERENCES institutions(id);
ALTER TABLE expense_categories ALTER COLUMN institution_id DROP DEFAULT;

-- NULL institution = central system administrator (multi-institution mode). Usernames stay unique system-wide.
ALTER TABLE users
  ADD COLUMN institution_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_users_inst (institution_id),
  ADD CONSTRAINT fk_users_inst FOREIGN KEY (institution_id) REFERENCES institutions(id);
UPDATE users SET institution_id = 1;

ALTER TABLE role_assignments
  DROP CONSTRAINT chk_ra_role,
  ADD CONSTRAINT chk_ra_role CHECK (role IN ('proposer','unit_head','division_deputy','planner','planning_deputy','director','finance','procurement','board_viewer','admin','super_admin'));

-- Codes only need to be unique inside a fiscal year (and so inside an institution).
ALTER TABLE projects DROP INDEX uq_project_code, ADD UNIQUE KEY uq_project_code (fiscal_year_id, code);
ALTER TABLE ledger_entries DROP INDEX uq_ledger_no, ADD UNIQUE KEY uq_ledger_no (fiscal_year_id, entry_no);

-- institution_id 0 = system-wide value.
ALTER TABLE settings
  ADD COLUMN institution_id BIGINT UNSIGNED NOT NULL DEFAULT 0 FIRST,
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (institution_id, skey);
UPDATE settings SET institution_id = 1 WHERE skey <> 'tenancy_mode';
DELETE FROM settings WHERE skey = 'org_name';

ALTER TABLE counters
  ADD COLUMN institution_id BIGINT UNSIGNED NOT NULL DEFAULT 0 FIRST,
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (institution_id, name);
UPDATE counters SET institution_id = 1;

ALTER TABLE audit_logs
  ADD COLUMN institution_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY idx_audit_inst (institution_id, id);
UPDATE audit_logs SET institution_id = 1;

-- @down
ALTER TABLE audit_logs DROP INDEX idx_audit_inst, DROP COLUMN institution_id;

DELETE FROM counters WHERE institution_id <> 1;
ALTER TABLE counters DROP PRIMARY KEY, DROP COLUMN institution_id, ADD PRIMARY KEY (name);

DELETE FROM settings WHERE institution_id <> 1;
ALTER TABLE settings DROP PRIMARY KEY, DROP COLUMN institution_id, ADD PRIMARY KEY (skey);
INSERT INTO settings (skey, svalue) SELECT 'org_name', name FROM institutions WHERE id = 1;

ALTER TABLE ledger_entries DROP INDEX uq_ledger_no, ADD UNIQUE KEY uq_ledger_no (entry_no);
ALTER TABLE projects DROP INDEX uq_project_code, ADD UNIQUE KEY uq_project_code (code);

DELETE FROM role_assignments WHERE role = 'super_admin';
ALTER TABLE role_assignments
  DROP CONSTRAINT chk_ra_role,
  ADD CONSTRAINT chk_ra_role CHECK (role IN ('proposer','unit_head','division_deputy','planner','planning_deputy','director','finance','procurement','board_viewer','admin'));

ALTER TABLE users DROP FOREIGN KEY fk_users_inst, DROP INDEX idx_users_inst, DROP COLUMN institution_id;

ALTER TABLE expense_categories DROP FOREIGN KEY fk_cat_inst, DROP INDEX uq_cat_code, ADD UNIQUE KEY uq_cat_code (code);
ALTER TABLE expense_categories DROP COLUMN institution_id;

ALTER TABLE org_units DROP FOREIGN KEY fk_unit_inst, DROP INDEX uq_unit_code, ADD UNIQUE KEY uq_unit_code (code);
ALTER TABLE org_units DROP COLUMN institution_id;

ALTER TABLE fiscal_years DROP FOREIGN KEY fk_fy_inst, DROP INDEX uq_fy_year, ADD UNIQUE KEY uq_fy_year (year_be);
ALTER TABLE fiscal_years DROP COLUMN institution_id;

DROP TABLE IF EXISTS institutions;
