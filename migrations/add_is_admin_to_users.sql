-- usersテーブルに管理者フラグを追加
-- 管理画面(admin.php)・管理系ページ(admin_v2.php等)の認可判定に使用
ALTER TABLE users
  ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0;
