-- races テーブルにオッズ専用の更新時刻カラムを追加
-- 従来の before_updated_at は直前情報(exhibit_time/start_timing)専用として維持し、
-- オッズの更新時刻はこのカラムで独立管理する(import_odds.phpのみが更新する)
ALTER TABLE races
  ADD COLUMN odds_updated_at DATETIME NULL AFTER before_updated_at;
