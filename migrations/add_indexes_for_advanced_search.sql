-- 高度検索パフォーマンス改善用インデックス
-- 実行前に既存インデックスを確認すること (SHOW INDEX FROM races; etc.)

-- races テーブル
ALTER TABLE races ADD INDEX idx_races_date         (date);
ALTER TABLE races ADD INDEX idx_races_venue_date   (venue, date);
ALTER TABLE races ADD INDEX idx_races_weather      (weather);
ALTER TABLE races ADD INDEX idx_races_wind_speed   (wind_speed);
ALTER TABLE races ADD INDEX idx_races_wave_height  (wave_height);

-- players テーブル (名前 LIKE 検索の高速化)
ALTER TABLE players ADD INDEX idx_players_name (name);

-- entries テーブル (exhibit_course フィルタ)
ALTER TABLE entries ADD INDEX idx_entries_exhibit_course (exhibit_course);
