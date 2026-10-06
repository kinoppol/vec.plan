-- @description ข้อมูลหลัก: ปีงบประมาณ หน่วยงาน ผู้ใช้ บทบาท แหล่งเงิน หมวดรายจ่าย ความสอดคล้อง สายอนุมัติ ตั้งค่า audit
-- @up
CREATE TABLE fiscal_years (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  year_be INT NOT NULL,
  starts_on DATE NOT NULL,
  ends_on DATE NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'setup',
  proposal_open_from DATE NULL,
  proposal_open_to DATE NULL,
  baseline_locked_at DATETIME NULL,
  settings JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fy_year (year_be),
  CONSTRAINT chk_fy_status CHECK (status IN ('setup','proposal_open','deliberation','execution','closing','closed')),
  CONSTRAINT chk_fy_dates CHECK (ends_on > starts_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE org_units (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id BIGINT UNSIGNED NULL,
  name VARCHAR(200) NOT NULL,
  kind VARCHAR(20) NOT NULL,
  code VARCHAR(30) NULL,
  student_count_vc INT NULL,
  student_count_hvc INT NULL,
  sort INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_unit_code (code),
  CONSTRAINT fk_unit_parent FOREIGN KEY (parent_id) REFERENCES org_units(id),
  CONSTRAINT chk_unit_kind CHECK (kind IN ('division','section','department')),
  CONSTRAINT chk_unit_students CHECK (COALESCE(student_count_vc,0) >= 0 AND COALESCE(student_count_hvc,0) >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL,
  name VARCHAR(200) NOT NULL,
  email VARCHAR(190) NULL,
  password_hash VARCHAR(255) NOT NULL,
  position_title VARCHAR(200) NULL,
  line_user_id VARCHAR(100) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  password_changed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_assignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(30) NOT NULL,
  org_unit_id BIGINT UNSIGNED NULL,
  fiscal_year_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_role_assign (user_id, role, org_unit_id, fiscal_year_id),
  CONSTRAINT fk_ra_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ra_unit FOREIGN KEY (org_unit_id) REFERENCES org_units(id),
  CONSTRAINT fk_ra_fy FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id),
  CONSTRAINT chk_ra_role CHECK (role IN ('proposer','unit_head','division_deputy','planner','planning_deputy','director','finance','procurement','board_viewer','admin'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(190) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_ip (ip, created_at),
  KEY idx_login_user (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fund_sources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fiscal_year_id BIGINT UNSIGNED NOT NULL,
  parent_id BIGINT UNSIGNED NULL,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(200) NOT NULL,
  color_token VARCHAR(20) NULL,
  fund_type VARCHAR(30) NULL,
  permit_rule VARCHAR(30) NULL,
  is_leaf TINYINT(1) NOT NULL DEFAULT 1,
  sort INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fund_code (fiscal_year_id, code),
  CONSTRAINT fk_fund_fy FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id),
  CONSTRAINT fk_fund_parent FOREIGN KEY (parent_id) REFERENCES fund_sources(id),
  CONSTRAINT chk_fund_type CHECK (fund_type IS NULL OR fund_type IN ('state_budget','subsidy','institution_income','donation','other')),
  CONSTRAINT chk_fund_rule CHECK (permit_rule IS NULL OR permit_rule IN ('allocation_letter','cumulative_receipts','cash_available'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expense_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id BIGINT UNSIGNED NULL,
  code VARCHAR(30) NOT NULL,
  name VARCHAR(200) NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cat_code (code),
  CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES expense_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No rows for a fund means every category is allowed (spec §13: open all categories at first).
CREATE TABLE fund_source_allowed_categories (
  fund_source_id BIGINT UNSIGNED NOT NULL,
  expense_category_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (fund_source_id, expense_category_id),
  CONSTRAINT fk_fsac_fund FOREIGN KEY (fund_source_id) REFERENCES fund_sources(id) ON DELETE CASCADE,
  CONSTRAINT fk_fsac_cat FOREIGN KEY (expense_category_id) REFERENCES expense_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE alignment_sets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fiscal_year_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(40) NOT NULL,
  name VARCHAR(200) NOT NULL,
  required TINYINT(1) NOT NULL DEFAULT 0,
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_aset (fiscal_year_id, code),
  CONSTRAINT fk_aset_fy FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE alignment_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  alignment_set_id BIGINT UNSIGNED NOT NULL,
  parent_id BIGINT UNSIGNED NULL,
  code VARCHAR(40) NULL,
  label VARCHAR(500) NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_aitem_set FOREIGN KEY (alignment_set_id) REFERENCES alignment_sets(id) ON DELETE CASCADE,
  CONSTRAINT fk_aitem_parent FOREIGN KEY (parent_id) REFERENCES alignment_items(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE approval_chains (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fiscal_year_id BIGINT UNSIGNED NOT NULL,
  request_type VARCHAR(40) NOT NULL,
  name VARCHAR(200) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_chain (fiscal_year_id, request_type),
  CONSTRAINT fk_chain_fy FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE approval_chain_steps (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  approval_chain_id BIGINT UNSIGNED NOT NULL,
  step_no INT NOT NULL,
  role VARCHAR(30) NOT NULL,
  scope VARCHAR(20) NOT NULL,
  can_return TINYINT(1) NOT NULL DEFAULT 1,
  can_reject TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_chain_step (approval_chain_id, step_no),
  CONSTRAINT fk_step_chain FOREIGN KEY (approval_chain_id) REFERENCES approval_chains(id) ON DELETE CASCADE,
  CONSTRAINT chk_step_scope CHECK (scope IN ('project_unit','project_division','global'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  skey VARCHAR(100) NOT NULL PRIMARY KEY,
  svalue TEXT NULL,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  subject_type VARCHAR(60) NULL,
  subject_id BIGINT UNSIGNED NULL,
  `before` JSON NULL,
  `after` JSON NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_subject (subject_type, subject_id),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS approval_chain_steps;
DROP TABLE IF EXISTS approval_chains;
DROP TABLE IF EXISTS alignment_items;
DROP TABLE IF EXISTS alignment_sets;
DROP TABLE IF EXISTS fund_source_allowed_categories;
DROP TABLE IF EXISTS expense_categories;
DROP TABLE IF EXISTS fund_sources;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS role_assignments;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS org_units;
DROP TABLE IF EXISTS fiscal_years;
