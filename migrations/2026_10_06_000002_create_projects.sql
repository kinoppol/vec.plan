-- @description โครงการ รายการงบประมาณ และผู้รับผิดชอบ (เท่าที่ระยะที่ 1 ใช้ในการนำเข้าแผน)
-- @up
CREATE TABLE projects (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fiscal_year_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(30) NOT NULL,
  title VARCHAR(500) NOT NULL,
  org_unit_id BIGINT UNSIGNED NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  project_kind VARCHAR(20) NOT NULL DEFAULT 'routine',
  project_kind_note VARCHAR(255) NULL,
  is_continuing TINYINT(1) NOT NULL DEFAULT 0,
  predecessor_project_id BIGINT UNSIGNED NULL,
  rationale TEXT NULL,
  objectives JSON NULL,
  quantitative_targets JSON NULL,
  qualitative_targets JSON NULL,
  target_group VARCHAR(500) NULL,
  target_count INT NULL,
  location VARCHAR(500) NULL,
  partners VARCHAR(500) NULL,
  necessity VARCHAR(10) NULL,
  necessity_reason TEXT NULL,
  impact_if_rejected TEXT NULL,
  impact_areas JSON NULL,
  beneficiaries INT NULL,
  requested_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  minimum_viable DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_phased TINYINT(1) NOT NULL DEFAULT 0,
  planned_quarters VARCHAR(20) NULL,
  expected_results JSON NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft',
  health VARCHAR(10) NULL,
  origin_adjustment_id BIGINT UNSIGNED NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'proposal',
  submitted_at DATETIME NULL,
  approved_at DATETIME NULL,
  closed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_project_code (code),
  KEY idx_project_fy_status (fiscal_year_id, status),
  CONSTRAINT fk_proj_fy FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id),
  CONSTRAINT fk_proj_unit FOREIGN KEY (org_unit_id) REFERENCES org_units(id),
  CONSTRAINT fk_proj_creator FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT fk_proj_pred FOREIGN KEY (predecessor_project_id) REFERENCES projects(id),
  CONSTRAINT chk_proj_kind CHECK (project_kind IN ('routine','ovec_policy','budget_act','other')),
  CONSTRAINT chk_proj_necessity CHECK (necessity IS NULL OR necessity IN ('must','should','nice')),
  CONSTRAINT chk_proj_status CHECK (status IN ('draft','submitted','returned','planning_review','pending_decision','waitlisted','rejected','approved','in_progress','awaiting_report','closed','cancelled','transferred_out')),
  CONSTRAINT chk_proj_health CHECK (health IS NULL OR health IN ('green','yellow','red')),
  CONSTRAINT chk_proj_money CHECK (requested_total >= 0 AND minimum_viable >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE project_owners (
  project_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (project_id, user_id),
  CONSTRAINT fk_po_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_po_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE budget_lines (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id BIGINT UNSIGNED NOT NULL,
  phase_no INT NULL,
  expense_category_id BIGINT UNSIGNED NOT NULL,
  fund_source_id BIGINT UNSIGNED NOT NULL,
  item_name VARCHAR(500) NOT NULL,
  quantity DECIMAL(12,2) NOT NULL DEFAULT 1,
  unit VARCHAR(50) NULL,
  unit_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_bl_project (project_id),
  CONSTRAINT fk_bl_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_bl_cat FOREIGN KEY (expense_category_id) REFERENCES expense_categories(id),
  CONSTRAINT fk_bl_fund FOREIGN KEY (fund_source_id) REFERENCES fund_sources(id),
  CONSTRAINT chk_bl_money CHECK (quantity >= 0 AND unit_price >= 0 AND amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS budget_lines;
DROP TABLE IF EXISTS project_owners;
DROP TABLE IF EXISTS projects;
