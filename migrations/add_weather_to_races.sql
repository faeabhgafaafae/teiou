-- races テーブルに天候・気温・水温カラムを追加
-- 既存の wind_speed / wind_dir / wave_height は既に存在する前提
ALTER TABLE races
  ADD COLUMN weather           VARCHAR(20) NULL AFTER wave_height,
  ADD COLUMN temperature       FLOAT       NULL AFTER weather,
  ADD COLUMN water_temperature FLOAT       NULL AFTER temperature;
