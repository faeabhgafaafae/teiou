-- entries テーブルに調整体重・チルト・プロペラ・部品交換カラムを追加
ALTER TABLE entries
  ADD COLUMN adjust_weight  FLOAT        NULL,
  ADD COLUMN tilt           FLOAT        NULL,
  ADD COLUMN propeller_mark VARCHAR(10)  NULL,
  ADD COLUMN parts_exchange VARCHAR(100) NULL;
