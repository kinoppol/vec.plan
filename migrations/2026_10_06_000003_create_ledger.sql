-- @description สมุดบัญชีเงินแบบ append-only (BR-20..24), ประมาณการรายรับ, ไฟล์แนบ, ตัวนับเลขที่เอกสาร
-- @up
CREATE TABLE ledger_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fiscal_year_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(20) NOT NULL,
  fund_source_id BIGINT UNSIGNED NOT NULL,
  project_id BIGINT UNSIGNED NULL,
  reserve_name VARCHAR(200) NULL,
  -- Generated key so the UNIQUE also works when project_id / reserve_name are NULL.
  uniq_key VARCHAR(300) AS (CONCAT_WS('|', fiscal_year_id, kind, fund_source_id, IFNULL(project_id, 0), IFNULL(reserve_name, ''))) STORED,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ledger_account (uniq_key),
  KEY idx_la_fund (fiscal_year_id, fund_source_id),
  KEY idx_la_project (project_id),
  CONSTRAINT fk_la_fy FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id),
  CONSTRAINT fk_la_fund FOREIGN KEY (fund_source_id) REFERENCES fund_sources(id),
  CONSTRAINT fk_la_project FOREIGN KEY (project_id) REFERENCES projects(id),
  CONSTRAINT chk_la_kind CHECK (kind IN ('fund_pool','reserve','project','external','carry_forward')),
  CONSTRAINT chk_la_project CHECK ((kind = 'project') = (project_id IS NOT NULL)),
  CONSTRAINT chk_la_reserve CHECK ((kind = 'reserve') = (reserve_name IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ledger_entries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_no VARCHAR(20) NOT NULL,
  fiscal_year_id BIGINT UNSIGNED NOT NULL,
  entry_date DATE NOT NULL,
  posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  entry_type VARCHAR(30) NOT NULL,
  from_account_id BIGINT UNSIGNED NOT NULL,
  to_account_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  expense_category_id BIGINT UNSIGNED NULL,
  reference_no VARCHAR(120) NULL,
  reference_date DATE NULL,
  installment_no INT NULL,
  source_type VARCHAR(40) NULL,
  source_id BIGINT UNSIGNED NULL,
  reverses_entry_id BIGINT UNSIGNED NULL,
  note TEXT NULL,
  override_reason TEXT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_ledger_no (entry_no),
  UNIQUE KEY uq_ledger_reverses (reverses_entry_id),
  KEY idx_le_fy_date (fiscal_year_id, entry_date),
  KEY idx_le_from (from_account_id),
  KEY idx_le_to (to_account_id),
  CONSTRAINT fk_le_fy FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id),
  CONSTRAINT fk_le_from FOREIGN KEY (from_account_id) REFERENCES ledger_accounts(id),
  CONSTRAINT fk_le_to FOREIGN KEY (to_account_id) REFERENCES ledger_accounts(id),
  CONSTRAINT fk_le_cat FOREIGN KEY (expense_category_id) REFERENCES expense_categories(id),
  CONSTRAINT fk_le_reverses FOREIGN KEY (reverses_entry_id) REFERENCES ledger_entries(id),
  CONSTRAINT fk_le_user FOREIGN KEY (created_by) REFERENCES users(id),
  CONSTRAINT chk_le_type CHECK (entry_type IN ('carry_in','receipt','allocation_adjust_up','allocation_adjust_down','reserve','allocate','transfer','spend','return','carry_out','reversal')),
  CONSTRAINT chk_le_amount CHECK (amount > 0),
  CONSTRAINT chk_le_accounts CHECK (from_account_id <> to_account_id),
  CONSTRAINT chk_le_spend_cat CHECK (entry_type <> 'spend' OR expense_category_id IS NOT NULL),
  CONSTRAINT chk_le_reversal CHECK ((entry_type = 'reversal') = (reverses_entry_id IS NOT NULL)),
  CONSTRAINT chk_le_receipt_ref CHECK (entry_type <> 'receipt' OR (reference_no IS NOT NULL AND reference_no <> '' AND reference_date IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- BR-21: append-only at the database level.
CREATE TRIGGER trg_ledger_entries_no_update BEFORE UPDATE ON ledger_entries FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only (BR-21): use a reversal entry';

CREATE TRIGGER trg_ledger_entries_no_delete BEFORE DELETE ON ledger_entries FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'ledger_entries is append-only (BR-21): use a reversal entry';

CREATE TABLE revenue_estimates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fiscal_year_id BIGINT UNSIGNED NOT NULL,
  fund_source_id BIGINT UNSIGNED NOT NULL,
  version INT NOT NULL DEFAULT 1,
  label VARCHAR(255) NOT NULL,
  installment_no INT NULL,
  expected_month TINYINT NULL,
  basis JSON NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_current TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_est_fund (fiscal_year_id, fund_source_id, version),
  CONSTRAINT fk_est_fy FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id),
  CONSTRAINT fk_est_fund FOREIGN KEY (fund_source_id) REFERENCES fund_sources(id),
  CONSTRAINT chk_est_amount CHECK (amount >= 0),
  CONSTRAINT chk_est_month CHECK (expected_month IS NULL OR expected_month BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attachments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  attachable_type VARCHAR(40) NOT NULL,
  attachable_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(40) NOT NULL DEFAULT 'other',
  original_name VARCHAR(255) NOT NULL,
  path VARCHAR(500) NOT NULL,
  mime VARCHAR(120) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_att_owner (attachable_type, attachable_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE counters (
  name VARCHAR(60) NOT NULL PRIMARY KEY,
  value BIGINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- @down
DROP TABLE IF EXISTS counters;
DROP TABLE IF EXISTS attachments;
DROP TABLE IF EXISTS revenue_estimates;
DROP TRIGGER IF EXISTS trg_ledger_entries_no_delete;
DROP TRIGGER IF EXISTS trg_ledger_entries_no_update;
DROP TABLE IF EXISTS ledger_entries;
DROP TABLE IF EXISTS ledger_accounts;
