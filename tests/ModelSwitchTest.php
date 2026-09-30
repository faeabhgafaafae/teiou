<?php
use PHPUnit\Framework\TestCase;

/**
 * v3w 本番切り替え(2026-10-01、design_v3w_switch_20261001.md)のテスト。
 *  1. 切り替え定数(model_switch.php)が既知の値であること(v2 へ戻しても通る)
 *  2. v3w 入力(1レース単位)がレース日より前のデータだけで作られること
 *  3. predictions_v2 への保存が結果確定済みレースで止まること
 *  4. 買い目が STRATEGY_MODEL_MAP どおりのモデルで生成され、model_ref が保存されること
 *  5. 画面表示の予測(順位・スコア)が表示モデルに従い、無ければ v2 に戻ること
 * DBはSQLiteインメモリ(tests/support/MysqlCompatPdo.php)。
 */
final class ModelSwitchTest extends TestCase
{
    private const RACE_DATE = '2026-10-01';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new MysqlCompatPdo('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE races (id INTEGER PRIMARY KEY, date TEXT, venue TEXT, race_no INTEGER)',
            'CREATE TABLE results (id INTEGER PRIMARY KEY AUTOINCREMENT, race_id INTEGER, lane INTEGER, player_id INTEGER,
                actual_rank INTEGER, start_timing REAL, UNIQUE (race_id, lane))',
            'CREATE TABLE entries (id INTEGER PRIMARY KEY AUTOINCREMENT, race_id INTEGER, lane INTEGER, player_id INTEGER)',
            'CREATE TABLE player_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, player_id INTEGER, year INTEGER, period INTEGER,
                win_rate REAL, avg_st REAL, grade TEXT)',
            'CREATE TABLE predictions (id INTEGER PRIMARY KEY AUTOINCREMENT, race_id INTEGER, player_id INTEGER,
                predicted_rank INTEGER, score_total REAL, score_ability REAL, score_course REAL, score_today REAL,
                score_weather REAL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (race_id, player_id))',
            'CREATE TABLE predictions_v2 (id INTEGER PRIMARY KEY AUTOINCREMENT, race_id INTEGER, player_id INTEGER,
                predicted_rank INTEGER, win_probability REAL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (race_id, player_id))',
            'CREATE TABLE strategies (id INTEGER PRIMARY KEY AUTOINCREMENT, race_id INTEGER, strategy_type TEXT,
                combinations TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (race_id, strategy_type))',
            'CREATE TABLE odds_3t (race_id INTEGER, combo TEXT, odds REAL)',
        ] as $ddl) {
            $this->pdo->exec($ddl);
        }
    }

    // ── fixtures ──────────────────────────────────────────

    private function exec(string $sql, array $params): void
    {
        $this->pdo->prepare($sql)->execute($params);
    }

    private function addRace(int $id, string $date, string $venue = '戸田'): void
    {
        $this->exec('INSERT INTO races (id, date, venue, race_no) VALUES (?, ?, ?, 1)', [$id, $date, $venue]);
    }

    private function addResult(int $race_id, int $lane, int $pid, ?int $rank, ?float $st = 0.15): void
    {
        $this->exec('INSERT INTO results (race_id, lane, player_id, actual_rank, start_timing) VALUES (?, ?, ?, ?, ?)',
            [$race_id, $lane, $pid, $rank, $st]);
    }

    /** 予測対象レース(6艇)+ v2 予測(1号艇本命) + 任意で v3w 予測(2号艇本命) */
    private function prepareRace(int $race_id, bool $with_v3w, string $date = self::RACE_DATE): void
    {
        $this->addRace($race_id, $date);
        $v2  = [1 => 0.40, 2 => 0.20, 3 => 0.15, 4 => 0.12, 5 => 0.08, 6 => 0.05];
        $v3w = [2 => 0.30, 1 => 0.25, 3 => 0.17, 4 => 0.13, 5 => 0.09, 6 => 0.06];
        $scores = [];
        $rank = 1;
        foreach ($v2 as $lane => $p) {
            $this->exec('INSERT INTO entries (race_id, lane, player_id) VALUES (?, ?, ?)', [$race_id, $lane, 5000 + $lane]);
            $scores[] = ['player_id' => 5000 + $lane, 'predicted_rank' => $rank++, 'score_total' => $p * 100,
                         'score_ability' => 10.0, 'score_course' => 10.0, 'score_today' => 10.0, 'score_weather' => 2.0];
        }
        persist_predictions($this->pdo, $race_id, $scores);
        if ($with_v3w) {
            $results = [];
            $rank = 1;
            foreach ($v3w as $lane => $p) {
                $results[5000 + $lane] = ['lane' => $lane, 'player_id' => 5000 + $lane,
                                          'probability' => $p, 'predicted_rank' => $rank++];
            }
            persist_v3_predictions($this->pdo, $race_id, $results);
        }
    }

    private function strategyModelRefs(int $race_id): array
    {
        $st = $this->pdo->prepare('SELECT strategy_type, model_ref, combinations FROM strategies WHERE race_id = ?');
        $st->execute([$race_id]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[$r['strategy_type']] = [$r['model_ref'], json_decode($r['combinations'], true)];
        }
        return $out;
    }

    // ── 1. 切り替え定数 ─────────────────────────────────────

    public function test_switch_constants_are_known_values(): void
    {
        $this->assertContains(PREDICTION_DISPLAY_MODEL, ['v2', 'v3w']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', V3W_PREDICTIONS_FROM);
        $this->assertSame('predictions_v2', prediction_table('v3w'));
        $this->assertSame('predictions', prediction_table('v2'));
    }

    // ── 2. v3w 入力 ──────────────────────────────────────────

    public function test_recent10_stats(): void
    {
        $this->assertSame([null, null, null], v3_recent10_stats([[1, 0.1], [2, 0.2]]), '3走未満は欠損');
        $rows = [[6, null], [1, 0.10], [2, 0.20], [3, null]];  // 4走(10走以内なので全部)。STはある走のみ平均
        $this->assertSame([3.0, 0.25, 0.15], v3_recent10_stats($rows));
        $many = array_merge(array_fill(0, 5, [6, 0.3]), array_fill(0, 10, [1, 0.1]));
        $this->assertSame([1.0, 1.0, 0.1], v3_recent10_stats($many), '直近10走のみ');
    }

    public function test_v3_inputs_use_only_data_before_race_day(): void
    {
        $pid = 4001;
        $this->exec('INSERT INTO player_periods (player_id, year, period, win_rate, avg_st, grade) VALUES (?, ?, ?, ?, ?, ?)',
            [$pid, 2026, 1, 5.5, 0.16, 'A2']);
        $this->exec('INSERT INTO player_periods (player_id, year, period, win_rate, avg_st, grade) VALUES (?, ?, ?, ?, ?, ?)',
            [$pid, 2026, 2, 6.1, 0.14, 'A1']);  // 最新期

        // 過去(集計対象): 当地・1号艇で 1着, 3着, 2着(着順NULLは直近10走から除外)
        $this->addRace(1, '2026-09-20'); $this->addResult(1, 1, $pid, 1, 0.12);
        $this->addRace(2, '2026-09-25'); $this->addResult(2, 1, $pid, 3, 0.18);
        $this->addRace(3, '2026-09-30'); $this->addResult(3, 1, $pid, 2, 0.15);
        $this->addRace(4, '2026-09-30'); $this->addResult(4, 2, $pid, null, null);
        // 対象外: 181日前 / レース当日 / 翌日
        $this->addRace(5, '2026-04-03'); $this->addResult(5, 3, $pid, 6, 0.30);
        $this->addRace(6, self::RACE_DATE); $this->addResult(6, 1, $pid, 1, 0.05);
        $this->addRace(7, '2026-10-02'); $this->addResult(7, 1, $pid, 1, 0.05);

        $in = build_v3_inputs($this->pdo, [[
            'player_id' => $pid, 'lane' => 1, 'exhibit_time' => '6.70', 'start_timing' => '0.11', 'motor_2rate' => '38.5',
        ]], self::RACE_DATE, '戸田')[0];

        $this->assertSame(6.1, $in['global_win_rate'], '最新期');
        $this->assertSame('A1', $in['grade']);
        $this->assertEqualsWithDelta(1 / 5, $in['local_win_rate'], 1e-9, '当地: 過去5件(当日・翌日を除く)で1着1回');
        $this->assertSame(3, $in['course_count'], '1号艇: 過去3件(181日前は3号艇)');
        $this->assertSame(1, $in['course_rank1']);
        $this->assertSame(2, $in['course_rank2']);
        $this->assertSame(2.0, $in['recent10_avg_rank'], '直近: 1,3,2着(当日・181日前・着順NULLを除く)');
        $this->assertSame(0.15, $in['recent10_st_mean']);
        $this->assertSame(6.7, $in['exhibit_time']);
    }

    public function test_v3_inputs_score_to_a_distribution(): void
    {
        $entries = [];
        for ($lane = 1; $lane <= 6; $lane++) {
            $entries[] = ['player_id' => 5000 + $lane, 'lane' => $lane, 'exhibit_time' => 6.7 + $lane / 100,
                          'start_timing' => 0.12, 'motor_2rate' => 30 + $lane];
        }
        $res = PredictV3::score_race(build_v3_inputs($this->pdo, $entries, self::RACE_DATE, '戸田'),
                                     ['wind_speed' => null, 'wave_height' => null]);
        $this->assertCount(6, $res);
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($res, 'probability')), 0.001);
        $this->assertSame([1, 2, 3, 4, 5, 6], array_values(array_map(fn($r) => $r['predicted_rank'], $res)));
    }

    // ── 3. predictions_v2 保存の確定済みガード ──────────────────

    public function test_persist_v3_predictions_guard(): void
    {
        $this->prepareRace(1, true);
        $n = fn() => (int)$this->pdo->query('SELECT COUNT(*) FROM predictions_v2 WHERE race_id = 1')->fetchColumn();
        $this->assertSame(6, $n(), '未確定レースは保存される');

        $this->pdo->exec("UPDATE predictions_v2 SET created_at = '2000-01-01 00:00:00'");
        $before = $this->pdo->query('SELECT * FROM predictions_v2 ORDER BY player_id')->fetchAll(PDO::FETCH_ASSOC);
        $this->addResult(1, 3, 5003, 1);
        $this->assertFalse(persist_v3_predictions($this->pdo, 1, [
            5003 => ['lane' => 3, 'player_id' => 5003, 'probability' => 0.9, 'predicted_rank' => 1],
        ]));
        $this->assertSame($before, $this->pdo->query('SELECT * FROM predictions_v2 ORDER BY player_id')->fetchAll(PDO::FETCH_ASSOC));
    }

    // ── 4. 買い目の参照モデルと model_ref ──────────────────────

    public function test_strategies_follow_model_map_and_record_model_ref(): void
    {
        $this->prepareRace(1, true);
        generate_and_save_strategies($this->pdo, 1);
        $refs = $this->strategyModelRefs(1);

        $this->assertEqualsCanonicalizing(array_keys(STRATEGY_MODEL_MAP), array_keys($refs));
        foreach (STRATEGY_MODEL_MAP as $type => $model) {
            $this->assertSame($model, $refs[$type][0], "{$type}: model_ref はマップどおり");
        }
        // 一撃重視の1着は参照モデルの本命(v2=1号艇 / v3w=2号艇)
        $expectFirst = STRATEGY_MODEL_MAP['一撃重視'] === 'v3w' ? '2' : '1';
        foreach ($refs['一撃重視'][1] as $combo) {
            $this->assertSame($expectFirst, explode('-', $combo)[0]);
        }
    }

    public function test_strategies_fall_back_to_v2_without_v3w_predictions(): void
    {
        $this->prepareRace(1, false);
        generate_and_save_strategies($this->pdo, 1);
        foreach ($this->strategyModelRefs(1) as $type => [$ref, $combos]) {
            $this->assertSame('v2', $ref, "{$type}: v3w 未生成なら v2 で生成し model_ref=v2");
        }
    }

    // ── 5. 画面表示 ──────────────────────────────────────────

    public function test_display_uses_v3w_when_selected_and_available(): void
    {
        $this->prepareRace(1, true);
        $d = load_display_predictions($this->pdo, 1, self::RACE_DATE, $used, 'v3w');
        $this->assertSame('v3w', $used);
        $this->assertSame(1, $d[5002]['predicted_rank']);
        $this->assertEquals(30.0, $d[5002]['score_total'], 'score_total は 1着確率×100');
    }

    public function test_display_falls_back_to_v2(): void
    {
        $this->prepareRace(1, false);                 // v3w 未生成
        $this->prepareRace(2, true, '2026-09-10');    // v3w 開始日より前(predictions_v2 は旧モデル)
        $this->prepareRace(3, true);

        foreach ([[1, self::RACE_DATE, 'v3w'], [2, '2026-09-10', 'v3w'], [3, self::RACE_DATE, 'v2']] as [$rid, $date, $model]) {
            $d = load_display_predictions($this->pdo, $rid, $date, $used, $model);
            $this->assertSame('v2', $used, "race {$rid}");
            $this->assertSame(1, $d[5001]['predicted_rank'], "race {$rid}: v2 の本命は1号艇");
        }
    }

    public function test_apply_display_ranks_reorders_and_requires_all_boats(): void
    {
        $rows = [];
        foreach ([1, 2, 3] as $i => $lane) {
            $rows[] = ['player_id' => 5000 + $lane, 'lane' => $lane, 'predicted_rank' => $i + 1, 'score_total' => 10.0];
        }
        $display = [5001 => ['predicted_rank' => 3, 'score_total' => 5.0],
                    5002 => ['predicted_rank' => 1, 'score_total' => 50.0],
                    5003 => ['predicted_rank' => 2, 'score_total' => 20.0]];
        $out = apply_display_ranks($rows, $display);
        $this->assertSame([2, 3, 1], array_column($out, 'lane'));
        $this->assertSame([50.0, 20.0, 5.0], array_column($out, 'score_total'));

        unset($display[5003]);
        $this->assertSame($rows, apply_display_ranks($rows, $display), '欠けがあれば元のまま');
    }

    // ── 静的: 画面系が表示モデル経由で読むこと ──────────────────

    public function test_display_endpoints_use_display_model(): void
    {
        $root = dirname(__DIR__);
        foreach (['api_predict.php', 'get_prediction.php', 'gemini_explain.php'] as $f) {
            $src = file_get_contents("$root/$f");
            $this->assertStringContainsString('apply_display_ranks(', $src, "$f: 表示モデルで順位を差し替える");
        }
        $api = file_get_contents("$root/api_predict.php");
        $this->assertStringContainsString('persist_v3_predictions(', $api, 'api_predict は v3w も保存する');
        $this->assertStringContainsString('build_v3_inputs(', $api);
        $gem = file_get_contents("$root/gemini_explain.php");
        $this->assertStringNotContainsString("UPDATE predictions SET", $gem, '解説キャッシュは表示モデルのテーブルへ');
    }
}
