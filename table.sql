-- 艇王 DBスキーマダンプ
-- 生成日時: 2026-07-15 12:07:03
-- テーブル数: 14

-- ----------------------------
-- Table: entries
-- ----------------------------
CREATE TABLE `entries` (
  `id` int NOT NULL AUTO_INCREMENT,
  `race_id` int NOT NULL,
  `lane` tinyint NOT NULL COMMENT '枠番 1〜6',
  `player_id` int NOT NULL,
  `motor_2rate` float DEFAULT NULL COMMENT 'モーター2連率',
  `odds` float DEFAULT NULL COMMENT '直前オッズ',
  `exhibit_time` float DEFAULT NULL COMMENT '展示タイム(秒)',
  `start_timing` float DEFAULT NULL COMMENT 'ST(秒) マイナス=フライング',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `adjust_weight` float DEFAULT NULL,
  `tilt` float DEFAULT NULL,
  `propeller_mark` varchar(10) DEFAULT NULL,
  `parts_exchange` varchar(100) DEFAULT NULL,
  `exhibit_course` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_entry` (`race_id`,`lane`),
  KEY `idx_race_id` (`race_id`),
  KEY `idx_player_id` (`player_id`),
  KEY `idx_entries_exhibit_course` (`exhibit_course`),
  CONSTRAINT `fk_entry_player` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`),
  CONSTRAINT `fk_entry_race` FOREIGN KEY (`race_id`) REFERENCES `races` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=57427 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='出走表';

-- ----------------------------
-- Table: odds_3t
-- ----------------------------
CREATE TABLE `odds_3t` (
  `id` int NOT NULL AUTO_INCREMENT,
  `race_id` int NOT NULL,
  `combo` varchar(8) NOT NULL COMMENT '1-2-3 形式',
  `odds` float DEFAULT NULL COMMENT '3連単オッズ',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_odds` (`race_id`,`combo`),
  KEY `idx_race_id` (`race_id`),
  CONSTRAINT `fk_odds_race` FOREIGN KEY (`race_id`) REFERENCES `races` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=418561 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='3連単オッズ';

-- ----------------------------
-- Table: odds_multi
-- ----------------------------
CREATE TABLE `odds_multi` (
  `id` int NOT NULL AUTO_INCREMENT,
  `race_id` int NOT NULL,
  `bet_type` varchar(20) NOT NULL,
  `combo` varchar(20) NOT NULL,
  `odds` varchar(20) NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_race_bettype_combo` (`race_id`,`bet_type`,`combo`),
  KEY `idx_race_bettype` (`race_id`,`bet_type`)
) ENGINE=InnoDB AUTO_INCREMENT=221131 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------
-- Table: player_periods
-- ----------------------------
CREATE TABLE `player_periods` (
  `id` int NOT NULL AUTO_INCREMENT,
  `player_id` int NOT NULL COMMENT '登番',
  `year` int NOT NULL COMMENT '年',
  `period` tinyint NOT NULL COMMENT '1=前期 2=後期',
  `grade` varchar(3) DEFAULT NULL COMMENT '当期の級',
  `win_rate` float DEFAULT NULL COMMENT '勝率',
  `fukusho_rate` float DEFAULT NULL COMMENT '複勝率',
  `ability_index` float DEFAULT NULL COMMENT '能力指数',
  `race_count` int DEFAULT NULL COMMENT '出走回数',
  `avg_st` float DEFAULT NULL COMMENT '平均ST',
  `c1_fukusho` float DEFAULT NULL,
  `c2_fukusho` float DEFAULT NULL,
  `c3_fukusho` float DEFAULT NULL,
  `c4_fukusho` float DEFAULT NULL,
  `c5_fukusho` float DEFAULT NULL,
  `c6_fukusho` float DEFAULT NULL,
  `c1_rank1` int DEFAULT NULL,
  `c2_rank1` int DEFAULT NULL,
  `c3_rank1` int DEFAULT NULL,
  `c4_rank1` int DEFAULT NULL,
  `c5_rank1` int DEFAULT NULL,
  `c6_rank1` int DEFAULT NULL,
  `c1_count` int DEFAULT NULL,
  `c2_count` int DEFAULT NULL,
  `c3_count` int DEFAULT NULL,
  `c4_count` int DEFAULT NULL,
  `c5_count` int DEFAULT NULL,
  `c6_count` int DEFAULT NULL,
  `c1_f` int DEFAULT NULL,
  `c2_f` int DEFAULT NULL,
  `c3_f` int DEFAULT NULL,
  `c4_f` int DEFAULT NULL,
  `c5_f` int DEFAULT NULL,
  `c6_f` int DEFAULT NULL,
  `period_from` date DEFAULT NULL COMMENT '算出期間(自)',
  `period_to` date DEFAULT NULL COMMENT '算出期間(至)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_player_period` (`player_id`,`year`,`period`),
  KEY `idx_player_id` (`player_id`),
  CONSTRAINT `fk_pp_player` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=210347 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='期別成績';

-- ----------------------------
-- Table: players
-- ----------------------------
CREATE TABLE `players` (
  `id` int NOT NULL COMMENT '登番',
  `name` varchar(20) NOT NULL COMMENT '名前漢字',
  `name_kana` varchar(20) DEFAULT NULL COMMENT '名前カナ',
  `branch` varchar(10) DEFAULT NULL COMMENT '支部',
  `grade` varchar(3) DEFAULT NULL COMMENT '現在の級 A1/A2/B1/B2',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_players_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='選手マスタ';

-- ----------------------------
-- Table: predictions
-- ----------------------------
CREATE TABLE `predictions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `race_id` int NOT NULL,
  `player_id` int NOT NULL,
  `predicted_rank` tinyint DEFAULT NULL COMMENT '予測順位',
  `score_total` float DEFAULT NULL COMMENT '合計スコア(100点満点)',
  `score_ability` float DEFAULT NULL COMMENT '①選手能力(40点)',
  `score_course` float DEFAULT NULL COMMENT '②コース別補正(20点)',
  `score_today` float DEFAULT NULL COMMENT '③当日情報(35点)',
  `score_weather` float DEFAULT NULL COMMENT '④気象補正(5点)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `explanation` text,
  `explanation_personal` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prediction` (`race_id`,`player_id`),
  KEY `idx_race_id` (`race_id`),
  KEY `fk_pred_player` (`player_id`),
  CONSTRAINT `fk_pred_player` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`),
  CONSTRAINT `fk_pred_race` FOREIGN KEY (`race_id`) REFERENCES `races` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=116018 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='予測結果';

-- ----------------------------
-- Table: race_payouts
-- ----------------------------
CREATE TABLE `race_payouts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `race_id` int NOT NULL,
  `bet_type` varchar(10) NOT NULL COMMENT '単勝/複勝/2連単/2連複/拡連複/3連単/3連複',
  `combo` varchar(20) NOT NULL COMMENT '組番。例: 1-3-2',
  `amount` int NOT NULL COMMENT '払戻金額（円）',
  `popularity` int DEFAULT NULL COMMENT '人気順。単勝・複勝はNULL',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_race_bet_combo` (`race_id`,`bet_type`,`combo`),
  KEY `idx_race_id` (`race_id`)
) ENGINE=InnoDB AUTO_INCREMENT=35966 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------
-- Table: races
-- ----------------------------
CREATE TABLE `races` (
  `id` int NOT NULL AUTO_INCREMENT,
  `date` date NOT NULL COMMENT 'レース日',
  `venue` varchar(20) NOT NULL COMMENT '競艇場名',
  `race_no` tinyint NOT NULL COMMENT 'レース番号 1〜12',
  `scheduled_time` varchar(5) DEFAULT NULL COMMENT '締切予定時刻 HH:MM',
  `wind_speed` float DEFAULT NULL COMMENT '風速(m/s)',
  `wind_dir` varchar(10) DEFAULT NULL COMMENT '風向',
  `wave_height` int DEFAULT NULL COMMENT '波高(cm)',
  `weather` varchar(20) DEFAULT NULL,
  `temperature` float DEFAULT NULL,
  `water_temperature` float DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `before_updated_at` datetime DEFAULT NULL COMMENT '直前情報の最終更新時刻',
  `odds_updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_race` (`date`,`venue`,`race_no`),
  KEY `idx_date` (`date`),
  KEY `idx_races_date` (`date`),
  KEY `idx_races_venue_date` (`venue`,`date`),
  KEY `idx_races_weather` (`weather`),
  KEY `idx_races_wind_speed` (`wind_speed`),
  KEY `idx_races_wave_height` (`wave_height`)
) ENGINE=InnoDB AUTO_INCREMENT=121144 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='レース情報';

-- ----------------------------
-- Table: results
-- ----------------------------
CREATE TABLE `results` (
  `id` int NOT NULL AUTO_INCREMENT,
  `race_id` int NOT NULL,
  `lane` tinyint NOT NULL COMMENT '枠番',
  `course` tinyint DEFAULT NULL COMMENT '進入コース番号(1-6)',
  `player_id` int NOT NULL,
  `actual_rank` tinyint DEFAULT NULL COMMENT '実際の着順',
  `time` varchar(10) DEFAULT NULL COMMENT 'タイム',
  `start_timing` float DEFAULT NULL COMMENT 'ST(秒)',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_result` (`race_id`,`lane`),
  KEY `idx_race_id` (`race_id`),
  KEY `fk_result_player` (`player_id`),
  CONSTRAINT `fk_result_player` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`),
  CONSTRAINT `fk_result_race` FOREIGN KEY (`race_id`) REFERENCES `races` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=656052 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='競走成績';

-- ----------------------------
-- Table: strategies
-- ----------------------------
CREATE TABLE `strategies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `race_id` int NOT NULL,
  `strategy_type` varchar(20) NOT NULL,
  `combinations` json NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_race_strategy` (`race_id`,`strategy_type`),
  KEY `idx_race_id` (`race_id`)
) ENGINE=InnoDB AUTO_INCREMENT=49573 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------
-- Table: strategy_results
-- ----------------------------
CREATE TABLE `strategy_results` (
  `id` int NOT NULL AUTO_INCREMENT,
  `strategy_id` int NOT NULL,
  `race_id` int NOT NULL,
  `is_hit` tinyint(1) NOT NULL DEFAULT '0',
  `payout` int NOT NULL DEFAULT '0',
  `cost` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_strategy_id` (`strategy_id`),
  KEY `idx_race_id` (`race_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5637 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------
-- Table: user_favorites
-- ----------------------------
CREATE TABLE `user_favorites` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `venue_name` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_venue` (`user_id`,`venue_name`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------
-- Table: user_picks
-- ----------------------------
CREATE TABLE `user_picks` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL COMMENT 'users.id',
  `race_id` int NOT NULL COMMENT 'races.id',
  `bet_type` varchar(10) NOT NULL COMMENT '3連単/3連複/2連単/2連複/拡連複/単勝/複勝',
  `combo` varchar(20) NOT NULL COMMENT '組番。例: 1-3-2',
  `cost` int NOT NULL COMMENT '購入額（円）',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_race_id` (`race_id`),
  KEY `idx_user_created` (`user_id`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ----------------------------
-- Table: users
-- ----------------------------
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(50) NOT NULL,
  `plan` enum('free','standard','premium') NOT NULL DEFAULT 'free',
  `favorite_venue` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
