-- @description โอนข้อมูลผู้ใช้จากระบบ RMS: รูปโปรไฟล์ แหล่งที่มาของบัญชี และ URL ของ RMS แยกตามสถานศึกษา
-- @up
ALTER TABLE users
  ADD COLUMN avatar_path VARCHAR(255) NULL AFTER position_title,
  -- people_pic the avatar was downloaded from, so an unchanged picture is not fetched again.
  ADD COLUMN avatar_source VARCHAR(255) NULL AFTER avatar_path,
  ADD COLUMN source VARCHAR(20) NULL AFTER avatar_source,
  ADD COLUMN synced_at DATETIME NULL AFTER source;

-- Each institution keeps its own RMS address (settings.rms_base_url); the first institution starts with RVC's.
INSERT IGNORE INTO settings (institution_id, skey, svalue) SELECT id, 'rms_base_url', 'http://rms.rvc.ac.th' FROM institutions WHERE id = 1;

-- @down
DELETE FROM settings WHERE skey = 'rms_base_url';
ALTER TABLE users DROP COLUMN synced_at, DROP COLUMN source, DROP COLUMN avatar_source, DROP COLUMN avatar_path;
