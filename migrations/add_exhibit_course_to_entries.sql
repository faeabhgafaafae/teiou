-- entries テーブルにスタート展示時の進入コースカラムを追加
ALTER TABLE entries
  ADD COLUMN exhibit_course INT NULL;
