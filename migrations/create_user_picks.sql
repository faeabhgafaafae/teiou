-- マイ的中トラッカー: ユーザー個人の買い目記録テーブル
-- is_hit / payout は閲覧時に race_payouts と照合して動的に計算する(NULL=未確定)
-- 実行方法: LolipopのphpMyAdminから手動で実行してください

CREATE TABLE user_picks (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT NOT NULL COMMENT 'users.id',
    race_id     INT NOT NULL COMMENT 'races.id',
    bet_type    VARCHAR(10) NOT NULL COMMENT '3連単/3連複/2連単/2連複/拡連複/単勝/複勝',
    combo       VARCHAR(20) NOT NULL COMMENT '組番。例: 1-3-2',
    cost        INT NOT NULL COMMENT '購入額（円）',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_user_id (user_id),
    KEY idx_race_id (race_id),
    KEY idx_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
