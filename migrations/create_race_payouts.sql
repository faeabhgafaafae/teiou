-- 払戻金一覧（単勝/複勝/2連単/2連複/拡連複/3連単/3連複、人気順）を保存するテーブル
-- 実行方法: LolipopのphpMyAdminから手動で実行してください（このリポジトリからは自動実行しません）

CREATE TABLE race_payouts (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    race_id     INT NOT NULL,
    bet_type    VARCHAR(10) NOT NULL COMMENT '単勝/複勝/2連単/2連複/拡連複/3連単/3連複',
    combo       VARCHAR(20) NOT NULL COMMENT '組番。例: 1-3-2',
    amount      INT NOT NULL COMMENT '払戻金額（円）',
    popularity  INT NULL COMMENT '人気順。単勝・複勝はNULL',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_race_bet_combo (race_id, bet_type, combo),
    KEY idx_race_id (race_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
