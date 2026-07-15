-- table.sqlダンプで見つかったスキーマ不整合4件の修正
-- 実行方法: migrate_run.php経由でapi_key認証の上、production DBに適用する

-- 1. predictions.score_course のCOMMENTを実装(35点満点)に合わせる
--    (コミットab3ba3bでコース補正の配点を20点→35点に変更済みだが、COMMENTが未更新だった)
ALTER TABLE predictions
  MODIFY COLUMN score_course float DEFAULT NULL COMMENT '②コース別補正(35点)';

-- 2. odds_multi.race_id に FOREIGN KEY を追加(兄弟テーブルodds_3tと同様の制約)
ALTER TABLE odds_multi
  ADD CONSTRAINT fk_odds_multi_race FOREIGN KEY (race_id) REFERENCES races(id);

-- 3. user_favorites のcollationを他テーブルと統一(utf8mb4_general_ci → utf8mb4_0900_ai_ci)
ALTER TABLE user_favorites
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

-- 4. user_picks / user_favorites に users への FOREIGN KEY を追加
--    (従来はアプリ側の手動カスケード削除に依存していた参照整合性をDB側で保証する)
ALTER TABLE user_picks
  ADD CONSTRAINT fk_user_picks_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE user_favorites
  ADD CONSTRAINT fk_user_favorites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
