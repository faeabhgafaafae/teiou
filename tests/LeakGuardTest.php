<?php
use PHPUnit\Framework\TestCase;

/**
 * 先読みリーク修正(2026-09-30、design_leak_fix_20260930.md)の回帰テスト。
 *  1. 当地・コース別成績に「レース当日以降」の結果が混入しないこと
 *     (結果取込みの前後で特徴量が変わらないこと)
 *  2. 結果確定済みレースで predictions / strategies が上書きされないこと
 *     (未確定レースは従来どおり保存されること)
 *  3. 表示用に保存済み予測を優先すること
 *  4. 静的チェック: results を期間集計するクエリに日付上限があること
 *
 * DBはSQLiteインメモリ。本番SQLのMySQL方言(ON DUPLICATE KEY UPDATE / VALUES() / NOW()
 * / 既存カラムALTERのerror 1060)だけを MysqlCompatPdo がSQLite構文へ読み替える。
 */
final class LeakGuardTest extends TestCase
{
    private const RACE_DATE = '2026-09-29';
    private const PLAYER    = 4001;
    private const VENUE     = '戸田';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new MysqlCompatPdo('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE races (id INTEGER PRIMARY KEY, date TEXT, venue TEXT, race_no INTEGER)');
        $this->pdo->exec('CREATE TABLE results (id INTEGER PRIMARY KEY AUTOINCREMENT, race_id INTEGER, lane INTEGER,
            player_id INTEGER, actual_rank INTEGER, UNIQUE (race_id, lane))');
        $this->pdo->exec('CREATE TABLE entries (id INTEGER PRIMARY KEY AUTOINCREMENT, race_id INTEGER, lane INTEGER, player_id INTEGER)');
        $this->pdo->exec('CREATE TABLE predictions (id INTEGER PRIMARY KEY AUTOINCREMENT, race_id INTEGER, player_id INTEGER,
            predicted_rank INTEGER, score_total REAL, score_ability REAL, score_course REAL, score_today REAL,
            score_weather REAL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (race_id, player_id))');
        $this->pdo->exec('CREATE TABLE strategies (id INTEGER PRIMARY KEY AUTOINCREMENT, race_id INTEGER, strategy_type TEXT,
            combinations TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (race_id, strategy_type))');
        $this->pdo->exec('CREATE TABLE odds_3t (race_id INTEGER, combo TEXT, odds REAL)');
    }

    // ── fixtures ──────────────────────────────────────────

    private function addRace(int $id, string $date, string $venue = self::VENUE): void
    {
        $this->pdo->prepare('INSERT INTO races (id, date, venue, race_no) VALUES (?, ?, ?, 1)')
            ->execute([$id, $date, $venue]);
    }

    private function addResult(int $race_id, int $lane, int $player_id, ?int $rank): void
    {
        $this->pdo->prepare('INSERT INTO results (race_id, lane, player_id, actual_rank) VALUES (?, ?, ?, ?)')
            ->execute([$race_id, $lane, $player_id, $rank]);
    }

    /** 6艇の出走表+着順(1号艇から順に1〜6着)を持つレース */
    private function addSettledRace(int $id, string $date): void
    {
        $this->addRace($id, $date);
        for ($lane = 1; $lane <= 6; $lane++) {
            $this->addResult($id, $lane, 5000 + $lane, $lane);
        }
    }

    /** api_predict.php の $scores と同じ形(v2確率ベースの予測) */
    private function scores(array $lane_order, array $probs): array
    {
        $out = [];
        foreach ($lane_order as $i => $lane) {
            $out[] = [
                'lane' => $lane, 'player_id' => 5000 + $lane, 'predicted_rank' => $i + 1,
                'score_total' => $probs[$lane] * 100, 'score_ability' => 10.0, 'score_course' => 10.0,
                'score_today' => 10.0, 'score_weather' => 2.0,
            ];
        }
        return $out;
    }

    private function prediction(int $race_id, int $player_id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM predictions WHERE race_id = ? AND player_id = ?');
        $st->execute([$race_id, $player_id]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    private function strategyRows(int $race_id): array
    {
        $st = $this->pdo->prepare('SELECT strategy_type, combinations, created_at FROM strategies WHERE race_id = ? ORDER BY strategy_type');
        $st->execute([$race_id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── 1. 集計窓(レース当日以降を含まない) ─────────────────

    public function test_stats_window_excludes_race_day(): void
    {
        $this->assertSame(['2024-09-29', '2026-09-29'], stats_window(self::RACE_DATE));
    }

    public function test_local_stats_use_only_days_before_race(): void
    {
        $p = self::PLAYER;
        $this->addRace(1, '2024-09-28'); $this->addResult(1, 1, $p, 1);  // 2年窓の外(古すぎ)
        $this->addRace(2, '2024-09-29'); $this->addResult(2, 1, $p, 1);  // 2年窓の下限ちょうど(含む)
        $this->addRace(3, '2026-09-28'); $this->addResult(3, 2, $p, 2);  // 前日(含む)
        $this->addRace(4, '2026-09-29'); $this->addResult(4, 1, $p, 1);  // レース当日(除外)
        $this->addRace(5, '2026-09-30'); $this->addResult(5, 1, $p, 1);  // 翌日(除外)
        $this->addRace(6, '2026-09-28', '桐生'); $this->addResult(6, 1, $p, 1);  // 他場(除外)

        $this->assertSame(
            ['total' => 2, 'rank1' => 1, 'rank2' => 2],
            fetch_local_stats($this->pdo, $p, self::VENUE, self::RACE_DATE)
        );
    }

    public function test_course_stats_use_only_days_before_race(): void
    {
        $p = self::PLAYER;
        $this->addRace(1, '2026-09-27'); $this->addResult(1, 3, $p, 3);  // 含む
        $this->addRace(2, '2026-09-28', '桐生'); $this->addResult(2, 3, $p, 1);  // 他場でも枠番別は含む
        $this->addRace(3, '2026-09-29'); $this->addResult(3, 3, $p, 1);  // 当日(除外)
        $this->addRace(4, '2026-10-01'); $this->addResult(4, 3, $p, 1);  // 未来(除外)
        $this->addRace(5, '2026-09-27', '桐生'); $this->addResult(5, 4, $p, 1);  // 別枠(除外)

        $this->assertSame(
            ['total' => 2, 'rank1' => 1, 'rank2' => 1, 'rank3' => 2],
            fetch_course_stats($this->pdo, $p, 3, self::RACE_DATE)
        );
    }

    /** リークの本質: 当該レースの結果取込みの前後で特徴量が変わらないこと */
    public function test_features_identical_before_and_after_result_import(): void
    {
        $p = self::PLAYER;
        $this->addRace(1, '2026-09-20'); $this->addResult(1, 1, $p, 4);
        $this->addRace(9, self::RACE_DATE);  // 予測対象レース
        $this->addRace(10, self::RACE_DATE); // 同日の後続レース

        $before = [fetch_local_stats($this->pdo, $p, self::VENUE, self::RACE_DATE),
                   fetch_course_stats($this->pdo, $p, 1, self::RACE_DATE)];

        // 結果取込み(当該レース1着・同日後続レース1着)
        $this->addResult(9, 1, $p, 1);
        $this->addResult(10, 1, $p, 1);

        $after = [fetch_local_stats($this->pdo, $p, self::VENUE, self::RACE_DATE),
                  fetch_course_stats($this->pdo, $p, 1, self::RACE_DATE)];

        $this->assertSame($before, $after);
        $this->assertSame(1, $after[0]['total']);
        $this->assertSame(0, $after[0]['rank1']);
    }

    // ── 2. 結果確定済みレースの上書き防止 ─────────────────────

    public function test_race_is_settled(): void
    {
        $this->addRace(1, self::RACE_DATE);
        $this->assertFalse(race_is_settled($this->pdo, 1), '結果なし');
        $this->addResult(1, 1, 5001, null);
        $this->assertFalse(race_is_settled($this->pdo, 1), '着順NULLのみ(未確定)');
        $this->addResult(1, 2, 5002, 1);
        $this->assertTrue(race_is_settled($this->pdo, 1), '着順あり');
    }

    public function test_persist_predictions_saves_unsettled_race(): void
    {
        $this->addRace(1, self::RACE_DATE);
        $probs = [1 => 0.40, 2 => 0.20, 3 => 0.15, 4 => 0.12, 5 => 0.08, 6 => 0.05];

        $this->assertTrue(persist_predictions($this->pdo, 1, $this->scores([1, 2, 3, 4, 5, 6], $probs)));
        $row = $this->prediction(1, 5001);
        $this->assertSame(1, (int)$row['predicted_rank']);
        $this->assertSame('v2_lr', $row['model_version']);

        // 未確定なら再実行で更新される(直前情報の反映など従来挙動)
        $this->pdo->exec("UPDATE predictions SET created_at = '2000-01-01 00:00:00'");
        $this->assertTrue(persist_predictions($this->pdo, 1, $this->scores([2, 1, 3, 4, 5, 6], $probs)));
        $row = $this->prediction(1, 5001);
        $this->assertSame(2, (int)$row['predicted_rank']);
        $this->assertNotSame('2000-01-01 00:00:00', $row['created_at']);
    }

    public function test_persist_predictions_does_not_overwrite_settled_race(): void
    {
        $this->addRace(1, self::RACE_DATE);
        $probs = [1 => 0.40, 2 => 0.20, 3 => 0.15, 4 => 0.12, 5 => 0.08, 6 => 0.05];
        persist_predictions($this->pdo, 1, $this->scores([1, 2, 3, 4, 5, 6], $probs));
        $this->pdo->exec("UPDATE predictions SET created_at = '2000-01-01 00:00:00'");
        $before = $this->pdo->query('SELECT * FROM predictions ORDER BY player_id')->fetchAll(PDO::FETCH_ASSOC);

        $this->addResult(1, 2, 5002, 1);  // 結果取込み(2号艇1着)
        // 結果を知った再計算(2号艇を本命に)が来ても保存しない
        $this->assertFalse(persist_predictions($this->pdo, 1, $this->scores([2, 1, 3, 4, 5, 6], $probs)));

        $after = $this->pdo->query('SELECT * FROM predictions ORDER BY player_id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame($before, $after);
    }

    public function test_persist_predictions_does_not_insert_for_settled_race_without_predictions(): void
    {
        $this->addSettledRace(1, self::RACE_DATE);
        $probs = [1 => 0.40, 2 => 0.20, 3 => 0.15, 4 => 0.12, 5 => 0.08, 6 => 0.05];
        $this->assertFalse(persist_predictions($this->pdo, 1, $this->scores([1, 2, 3, 4, 5, 6], $probs)));
        $this->assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM predictions')->fetchColumn());
    }

    /** 買い目生成の前提: 6艇の出走表とv2予測(model_version付き)を用意 */
    private function prepareRaceForStrategies(int $race_id): void
    {
        $this->addRace($race_id, self::RACE_DATE);
        for ($lane = 1; $lane <= 6; $lane++) {
            $this->pdo->prepare('INSERT INTO entries (race_id, lane, player_id) VALUES (?, ?, ?)')
                ->execute([$race_id, $lane, 5000 + $lane]);
        }
        $probs = [1 => 0.40, 2 => 0.20, 3 => 0.15, 4 => 0.12, 5 => 0.08, 6 => 0.05];
        persist_predictions($this->pdo, $race_id, $this->scores([1, 2, 3, 4, 5, 6], $probs));
    }

    public function test_strategies_generated_for_unsettled_race(): void
    {
        $this->prepareRaceForStrategies(1);
        $saved = generate_and_save_strategies($this->pdo, 1);
        $this->assertCount(4, $saved);
        $this->assertCount(4, $this->strategyRows(1));
    }

    public function test_strategies_not_regenerated_for_settled_race(): void
    {
        $this->prepareRaceForStrategies(1);
        generate_and_save_strategies($this->pdo, 1);
        $this->pdo->exec("UPDATE strategies SET created_at = '2000-01-01 00:00:00'");
        $before = $this->strategyRows(1);

        // 結果取込み後、結果を知った予測に書き換わった状態で再生成が呼ばれても何もしない
        $this->addResult(1, 2, 5002, 1);
        $this->pdo->exec('UPDATE predictions SET predicted_rank = 7 - predicted_rank WHERE race_id = 1');

        $this->assertSame([], generate_and_save_strategies($this->pdo, 1));
        $this->assertSame($before, $this->strategyRows(1));
    }

    public function test_strategies_not_created_for_settled_race_without_strategies(): void
    {
        $this->prepareRaceForStrategies(1);
        $this->addResult(1, 1, 5001, 1);
        $this->assertSame([], generate_and_save_strategies($this->pdo, 1));
        $this->assertSame([], $this->strategyRows(1));
    }

    // ── 3. 表示: 保存済み予測を優先 ───────────────────────────

    public function test_overlay_uses_stored_predictions_when_complete(): void
    {
        $probs    = [1 => 0.40, 2 => 0.20, 3 => 0.15, 4 => 0.12, 5 => 0.08, 6 => 0.05];
        $computed = $this->scores([2, 1, 3, 4, 5, 6], $probs);  // 再計算値(2号艇本命)
        $stored   = [];
        foreach ($this->scores([1, 2, 3, 4, 5, 6], $probs) as $s) {  // レース前の保存値(1号艇本命)
            $stored[$s['player_id']] = $s;
        }

        $out = overlay_stored_predictions($computed, $stored);
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($out, 'lane'));
        $this->assertSame([1, 2, 3, 4, 5, 6], array_column($out, 'predicted_rank'));
        $this->assertEquals(40.0, $out[0]['score_total']);
    }

    public function test_overlay_keeps_computed_when_stored_incomplete(): void
    {
        $probs    = [1 => 0.40, 2 => 0.20, 3 => 0.15, 4 => 0.12, 5 => 0.08, 6 => 0.05];
        $computed = $this->scores([2, 1, 3, 4, 5, 6], $probs);
        $stored   = [5001 => $computed[1]];  // 1艇分しかない
        $this->assertSame($computed, overlay_stored_predictions($computed, $stored));
    }

    // ── 4. 静的チェック ──────────────────────────────────────

    /**
     * results を rc.date の下限で期間集計するクエリは、同じSQL文字列内に
     * rc.date の上限(< または <=)も持つこと。下限だけのクエリは結果取込み後の
     * 実行で当日以降の着順を拾う(2026-09-30 api_predict.php / api_v2_batch.php で発生)。
     */
    // レース単位ではなく「現在時点」の集計を返す画面用API(レース日で区切る対象ではない)
    private const NOW_BASED_STATS_FILES = [
        'get_analysis_venue.php', // 場別コース傾向(固定開始日〜現在の全期間集計)
        'get_stats.php',          // 選手詳細の成績(直近6ヶ月・今期など現在基準)
    ];

    public function test_results_date_filters_have_upper_bound(): void
    {
        $violations = [];
        foreach (glob(dirname(__DIR__) . '/*.php') as $file) {
            if (in_array(basename($file), self::NOW_BASED_STATS_FILES, true)) continue;
            $src = file_get_contents($file);
            // SQL文字列リテラル単位で検査(" ... " / ' ... ')。下限の数だけ上限があること
            if (!preg_match_all('/"[^"]*FROM\s+results[^"]*"|\'[^\']*FROM\s+results[^\']*\'/is', $src, $m)) continue;
            foreach ($m[0] as $sql) {
                $lower = preg_match_all('/\w+\.date\s*>=/i', $sql);
                if ($lower === 0) continue;
                if (preg_match_all('/\w+\.date\s*<=?\s*\?/i', $sql) < $lower) {
                    $violations[] = basename($file) . ': ' . preg_replace('/\s+/', ' ', substr($sql, 0, 160));
                }
            }
        }
        $this->assertSame([], $violations, "日付上限のない results 集計クエリ:\n" . implode("\n", $violations));
    }

    public function test_api_predict_saves_only_via_guard(): void
    {
        $src = file_get_contents(dirname(__DIR__) . '/api_predict.php');
        $this->assertStringNotContainsString('INSERT INTO predictions', $src, 'predictions への保存は persist_predictions() 経由に限る');
        $this->assertStringNotContainsString('DATE_SUB(?', $src);
        $this->assertStringContainsString('persist_predictions(', $src);
        $this->assertStringContainsString('fetch_local_stats(', $src);
        $this->assertStringContainsString('fetch_course_stats(', $src);
    }
}
