-- @description ผู้ใช้จาก RVC-ONE: รหัสผู้ใช้ภายนอก (oa_user_id) สำหรับ SSO และ User-sync API
-- @up
ALTER TABLE users
  ADD COLUMN oa_user_id BIGINT UNSIGNED NULL AFTER synced_at,
  ADD COLUMN oa_user_type VARCHAR(20) NULL AFTER oa_user_id,
  -- Last status RVC-ONE reported; only a change of it switches users.active, so a local deactivation stays.
  ADD COLUMN oa_status VARCHAR(20) NULL AFTER oa_user_type,
  ADD COLUMN department VARCHAR(200) NULL AFTER position_title,
  ADD UNIQUE KEY uq_users_oa_user_id (oa_user_id);

-- @down
ALTER TABLE users DROP KEY uq_users_oa_user_id, DROP COLUMN department, DROP COLUMN oa_status, DROP COLUMN oa_user_type, DROP COLUMN oa_user_id;
