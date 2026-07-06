-- resultsテーブルに進入コース(course)カラムを追加する
-- time, start_timingは既存カラムをそのまま使う（このマイグレーションでは追加しない）
-- 実行方法: LolipopのphpMyAdminから手動で実行してください（このリポジトリからは自動実行しません）

ALTER TABLE results
    ADD COLUMN course TINYINT NULL COMMENT '進入コース番号(1-6)' AFTER lane;
