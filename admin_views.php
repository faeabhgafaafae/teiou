<?php
/**
 * 管理ツール(JSON API)の人が読めるHTML表示(2026-10-08)。
 *
 * 各ツールは従来どおりデータ配列を作り、最後に av_output() を呼ぶ:
 *   - ?format=json のときだけ、従来と同一の JSON(json_encode の引数も各ツールの従来値)を返す
 *   - それ以外は HTML(見出し・目的の説明・「JSONで見る」リンク・日本語の表)
 * 表示関数 av_view_*() はデータ配列だけを受け取る純粋関数(DB非依存。tests/AdminViewsTest.php で検証)。
 * WAF 制約: CSS はページ内 <style> のみ。JS はヘッダーの日付表示だけ(admin.php と同じ。テンプレートリテラル不可)。
 */
require_once __DIR__ . '/model_status_lib.php';
require_once __DIR__ . '/data_quality.php';

// ── 出力の切り替え ───────────────────────────────────────────────────

function av_wants_json(): bool {
    return ($_GET['format'] ?? '') === 'json';
}

/** 現在のURLに format=json を付けたもの(既存の format は置き換え) */
function av_json_url(): string {
    $params = $_GET;
    $params['format'] = 'json';
    $path = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    return $path . '?' . http_build_query($params);
}

/**
 * @param array  $data       JSON にする配列(従来の出力そのもの)
 * @param int    $json_flags 従来の json_encode フラグ(JSON の完全一致のため各ツールの値を渡す)
 * @param string $view       表示関数名の後半(av_view_{$view})
 * @param array  $page       ['title'=>, 'purpose'=>]
 */
function av_output(array $data, int $json_flags, string $view, array $page): void {
    if (av_wants_json()) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, $json_flags);
        return;
    }
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    av_render_page($page['title'], $page['purpose'], av_json_url(), call_user_func('av_view_' . $view, $data));
}

function av_render_page(string $title, string $purpose, string $json_url, string $body): void {
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>艇王 - <?= av_e($title) ?></title>
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
.container { max-width: 1000px; margin: 0 auto; padding: 20px 16px; }
.av-top { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; flex-wrap: wrap; }
.av-back { color: #0055a4; text-decoration: none; font-size: 20px; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 8px; }
.av-back:hover { background: #e8f0fd; }
.av-json { margin-left: auto; font-size: 12px; color: #0055a4; border: 1px solid #c7d7f0; border-radius: 6px; padding: 4px 10px; text-decoration: none; background: #fff; }
.av-json:hover { background: #f0f5ff; }
.av-purpose { font-size: 13px; color: #555; line-height: 1.7; background: #f7f8fa; border: 1px solid #e0e3e8; border-radius: 8px; padding: 10px 14px; margin-bottom: 18px; }
h2.av-h { font-size: 14px; font-weight: 700; color: #333; margin: 22px 0 6px; }
.av-desc { font-size: 12px; color: #777; line-height: 1.6; margin-bottom: 8px; }
.av-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border: 1px solid #e0e3e8; border-radius: 8px; }
.av-wrap.tall { max-height: 520px; overflow-y: auto; }
table.av { width: 100%; border-collapse: collapse; background: #fff; }
table.av th { background: #f7f8fa; font-size: 11px; font-weight: 700; color: #777; padding: 7px 10px; border-bottom: 2px solid #e0e3e8; white-space: nowrap; text-align: center; position: sticky; top: 0; }
table.av td { padding: 6px 10px; font-size: 13px; border-bottom: 1px solid #f0f0f0; text-align: right; white-space: nowrap; }
table.av td.l { text-align: left; }
table.av td.c { text-align: center; }
table.av tr:last-child td { border-bottom: none; }
table.av tr.total td { font-weight: 700; background: #f7f8fa; }
table.av tr.muted td { color: #94a3b8; background: #f8fafc; }
table.av tr.alert td { background: #fef2f2; }
.av-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; background: #f1f5f9; color: #64748b; }
.av-badge.v3w { background: #e0ecff; color: #0055a4; }
.av-badge.v2 { background: #fef3c7; color: #b45309; }
.av-note { font-size: 11px; color: #888; line-height: 1.6; margin-top: 6px; }
.av-warn { font-size: 12px; color: #92400e; background: #fffbeb; border: 1px solid #f59e0b; border-radius: 8px; padding: 8px 12px; margin: 8px 0; line-height: 1.6; }
.av-good { color: #16a34a; font-weight: 700; }
.av-bad { color: #dc2626; font-weight: 700; }
.av-tabs a { display: inline-block; font-size: 12px; margin: 0 6px 6px 0; padding: 4px 10px; border: 1px solid #e0e3e8; border-radius: 14px; text-decoration: none; color: #0055a4; background: #fff; }
.av-tabs a.on { background: #0055a4; color: #fff; border-color: #0055a4; }
</style>
</head>
<body>
  <?php include __DIR__ . '/header.php'; ?>
<div class="dashboard-container">
  <script>var ACTIVE_NAV = 'admin';</script>
  <?php include __DIR__ . '/sidebar.php'; ?>
  <main class="main-content">
  <div class="container">
    <div class="av-top">
      <a class="av-back" href="admin.php" title="管理画面へ">&larr;</a>
      <h2 class="section-title"><?= av_e($title) ?></h2>
      <a class="av-json" href="<?= av_e($json_url) ?>">JSONで見る</a>
    </div>
    <p class="av-purpose"><?= av_e($purpose) ?></p>
    <?= $body ?>
  </div>
  </main>
</div>
<script>
// app.js を読み込まないページなので #headerDate・#headerLogo をここで設定する(admin.php と同じ)
window.addEventListener('DOMContentLoaded', function() {
  var d = new Date();
  var days = ['日','月','火','水','木','金','土'];
  var el = document.getElementById('headerDate');
  if (el) el.textContent = d.getFullYear() + '年' + (d.getMonth() + 1) + '月' + d.getDate() + '日 (' + days[d.getDay()] + ')';
  var logo = document.getElementById('headerLogo');
  if (logo) logo.addEventListener('click', function() { location.href = 'index.php'; });
});
</script>
</body>
</html>
<?php
}

// ── 書式 ────────────────────────────────────────────────────────────

function av_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
/** 百分率(値はすでに%単位)。小数1桁 */
function av_pct($v): string { return ($v === null || $v === '') ? '-' : sprintf('%.1f%%', (float)$v); }
/** 比率(分子/分母)を小数1桁の% */
function av_rate($num, $den): string { return ((float)$den) > 0 ? sprintf('%.1f%%', $num / $den * 100) : '-'; }
function av_yen($v): string { return ($v === null || $v === '') ? '-' : number_format((float)round((float)$v)) . '円'; }
function av_int($v): string { return ($v === null || $v === '') ? '-' : number_format((int)round((float)$v)); }
function av_num($v, int $dec = 1): string { return ($v === null || $v === '') ? '-' : number_format((float)$v, $dec); }
/** 符号付きポイント差 */
function av_diff($v): string {
    if ($v === null) return '-';
    $cls = $v > 0 ? 'av-good' : ($v < 0 ? 'av-bad' : '');
    return '<span class="' . $cls . '">' . sprintf('%+.1f', $v) . 'pt</span>';
}
function av_badge(string $model): string {
    $cls = in_array($model, ['v3w', 'v2'], true) ? $model : '';
    return '<span class="av-badge ' . $cls . '">' . av_e($model === 'NULL' ? '記録なし' : $model) . '</span>';
}
/** 'Y-m-d H:i:s' → 'm/d H:i' */
function av_time($ts): string { return $ts ? date('m/d H:i', strtotime($ts)) : '-'; }

/**
 * 表。$cols = [[見出し, 'l'|'c'|'r'], ...]。$rows の各要素はセルHTML配列、
 * または ['class'=>'total'|'muted'|'alert', 'cells'=>[...]]。セルはエスケープ済みHTMLを渡す。
 */
function av_table(array $cols, array $rows, bool $tall = false): string {
    $h = '<div class="av-wrap' . ($tall ? ' tall' : '') . '"><table class="av"><thead><tr>';
    foreach ($cols as $c) $h .= '<th>' . av_e($c[0]) . '</th>';
    $h .= '</tr></thead><tbody>';
    if (!$rows) {
        $h .= '<tr class="muted"><td class="c" colspan="' . count($cols) . '">データなし</td></tr>';
    }
    foreach ($rows as $r) {
        $class = '';
        $cells = $r;
        if (isset($r['cells'])) { $class = $r['class'] ?? ''; $cells = $r['cells']; }
        $h .= '<tr' . ($class ? ' class="' . av_e($class) . '"' : '') . '>';
        if (count($cells) === 1 && count($cols) > 1) {
            $h .= '<td class="l" colspan="' . count($cols) . '">' . $cells[0] . '</td>';
        } else {
            foreach ($cells as $i => $cell) {
                $align = $cols[$i][1] ?? 'r';
                $h .= '<td' . ($align === 'r' ? '' : ' class="' . $align . '"') . '>' . $cell . '</td>';
            }
        }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div>';
}

function av_section(string $title, string $desc, string $html): string {
    return '<h2 class="av-h">' . av_e($title) . '</h2>'
        . ($desc !== '' ? '<p class="av-desc">' . av_e($desc) . '</p>' : '')
        . $html;
}

/** 日付行が欠損日に当たるときの表示(該当しなければ null)。理由は av_issue_notes() で上部に1回だけ出す */
function av_issue_label(string $date): ?string {
    return data_quality_issue_on($date, ['gap']) ? '欠損' : null;
}

/** 期間に該当するデータ品質の問題(data_quality.php)を注意欄として返す */
function av_issue_notes(string $from, string $to): string {
    $h = '';
    foreach (data_quality_issues($from, $to) as $i) {
        $h .= '<p class="av-warn">' . av_e(($i['type'] === 'gap' ? '欠損: ' : 'リーク込み: ') . $i['text']) . '</p>';
    }
    return $h;
}

// ═══════════ model_switch_status.php ═══════════════════════════════════

function av_view_model_switch_status(array $d): string {
    $cfg   = $d['config'];
    $daily = $d['daily'];
    $out   = av_issue_notes($d['from'], $d['to']);

    // 1. 現在の設定
    $rows = [];
    foreach ($cfg['STRATEGY_MODEL_MAP'] as $type => $m) {
        $rows[] = ['<b>' . av_e($type) . '</b>', av_badge($m), '買い目生成'];
    }
    $rows[] = ['<b>予測表示</b>', av_badge($cfg['PREDICTION_DISPLAY_MODEL']), '予測順位・確率・AI解説'];
    $rows[] = ['<b>フォールバック先</b>', av_badge($cfg['STRATEGY_MODEL_FALLBACK']), '参照モデルの予測が無いレースで使用'];
    $out .= av_section('現在の設定', 'model_switch.php の値。v3w=predictions_v2、v2=predictions。',
        av_table([['対象', 'l'], ['参照モデル', 'c'], ['用途', 'l']], $rows));

    // 2. 1着的中率
    $rows = [];
    foreach ($daily as $date => $v) {
        $t = $v['top1'] ?? null;
        if ($t === null || $t['races'] === 0) {
            $why = av_issue_label($date)
                ?? ((int)($v['races_settled'] ?? 0) === 0 ? '結果未確定' : 'v2・v3w 両方の予測があるレースなし');
            $rows[] = ['class' => 'muted', 'cells' => [av_e($date), av_e($why), '', '', '', '']];
            continue;
        }
        $rows[] = [av_e($date), av_int($t['races']), av_rate($t['v3w_hits'], $t['races']),
                   av_rate($t['v2_hits'], $t['races']), av_rate($t['lane1_wins'], $t['races']),
                   av_rate($t['v3w_lane1_rank1'], $t['races'])];
    }
    $sum = ms_sum_top1(array_filter(array_map(function ($v) { return $v['top1'] ?? null; }, $daily)));
    if ($sum['races'] > 0) {
        $rows[] = ['class' => 'total', 'cells' => ['期間累計', av_int($sum['races']), av_rate($sum['v3w_hits'], $sum['races']),
                   av_rate($sum['v2_hits'], $sum['races']), av_rate($sum['lane1_wins'], $sum['races']),
                   av_rate($sum['v3w_lane1_rank1'], $sum['races'])]];
    }
    $out .= av_section('1着的中率',
        '結果取込み前に保存された予測の1位と実際の1着の一致率。v2・v3w とも予測があり結果確定済みのレースのみ。'
        . '「v3wの1号艇1位率」は v3w が1号艇を1位に予測した割合(本命寄りの度合い)。',
        av_table([['日付', 'l'], ['レース数'], ['v3w的中率'], ['v2的中率'], ['1号艇決め打ち'], ['v3wの1号艇1位率']], $rows, true));

    // 3. 4戦略の成績(戦略×日×参照モデル)
    $rows = [];
    foreach ($daily as $date => $v) {
        $sr = $v['strategy_results'] ?? [];
        if (!$sr) {
            $why = av_issue_label($date) ?? ((int)($v['races_settled'] ?? 0) === 0 ? '結果未確定(清算前)' : '清算データなし');
            $rows[] = ['class' => 'muted', 'cells' => [av_e($date), av_e($why), '', '', '', '', '', '', '']];
            continue;
        }
        foreach ($sr as $type => $refs) {
            foreach ($refs as $ref => $k) {
                $rows[] = [av_e($date), av_e($type), av_badge((string)$ref), av_int($k['n']), av_int($k['hits']),
                           av_rate($k['hits'], $k['n']), av_yen($k['cost']), av_yen($k['payout']), av_rate($k['payout'], $k['cost'])];
            }
        }
    }
    $total = ms_sum_strategy_results(array_map(function ($v) {
        return is_array($v['strategy_results'] ?? null) ? $v['strategy_results'] : [];
    }, $daily));
    foreach ($total as $type => $refs) {
        foreach ($refs as $ref => $k) {
            $rows[] = ['class' => 'total', 'cells' => ['期間累計', av_e($type), av_badge((string)$ref), av_int($k['n']), av_int($k['hits']),
                       av_rate($k['hits'], $k['n']), av_yen($k['cost']), av_yen($k['payout']), av_rate($k['payout'], $k['cost'])]];
        }
    }
    $out .= av_section('4戦略の成績',
        '清算済み(strategy_results)の実績。参照モデルは実際に買い目を作ったモデル(model_ref)。回収率=払戻額÷投資額。',
        av_table([['日付', 'l'], ['戦略', 'l'], ['参照モデル', 'c'], ['レース数'], ['的中数'], ['的中率'], ['投資額'], ['払戻額'], ['回収率']], $rows, true));

    // 4. 予測・買い目の保存状況
    $rows = [];
    foreach ($daily as $date => $v) {
        $refs = [];
        foreach (($v['strategies'] ?? []) as $type => $m) {
            foreach ($m as $ref => $n) $refs[$ref] = ($refs[$ref] ?? 0) + $n;
        }
        $refHtml = $refs ? implode(' ', array_map(function ($ref) use ($refs) {
            return av_badge((string)$ref) . ' ' . av_int($refs[$ref]);
        }, array_keys($refs))) : '-';
        $issue = av_issue_label($date);
        $rows[] = ['class' => $issue ? 'muted' : '', 'cells' => [av_e($date), av_int($v['races']), av_int($v['races_settled']),
                   av_int($v['races_with_v2']), av_int($v['races_with_v3w']), $refHtml . ($issue ? ' 欠損' : '')]];
    }
    $out .= av_section('予測・買い目の保存状況',
        '日別の全レース数に対する、結果確定・予測保存・買い目生成(参照モデル別の件数、4戦略合計)の状況。',
        av_table([['日付', 'l'], ['レース数'], ['結果確定'], ['v2予測あり'], ['v3w予測あり'], ['買い目(参照モデル別)', 'l']], $rows, true));
    return $out;
}

// ═══════════ audit_leak_rewrites.php ═════════════════════════════════

function av_view_audit_leak_rewrites(array $d): string {
    $t = $d['totals'];
    // 既知のリーク期間(data_quality.php)内の書き換えは想定内。期間外に1件でもあれば異常として警告する
    $known = 0; $unexpected = 0;
    foreach ($d['daily'] as $date => $v) {
        $rw = (int)($v['rewritten_races'] ?? 0);
        if (data_quality_issue_on($date, ['leak'])) { $known += $rw; } else { $unexpected += $rw; }
    }
    $warn = '';
    if ($unexpected > 0) {
        $warn .= '<p class="av-warn"><b>要確認:</b> 既知のリーク期間外に、結果取込み後の書き換えが ' . av_int($unexpected)
            . 'R あります(赤い行)。api_predict.php / generate_strategies.php の結果確定済みガードが効いていない可能性があります。</p>';
    }
    if ($known > 0) {
        $warn .= '<p class="av-note">既知のリーク期間(2026-08-19〜09-28)内の書き換え ' . av_int($known) . 'R は修正前のもので、未復元のまま残っています(灰色の行)。</p>';
    }
    if ($known + $unexpected === 0) {
        $warn .= '<p class="av-note">期間内の書き換えは 0 件です。</p>';
    }
    $rows = [];
    foreach ($d['daily'] as $date => $v) {
        $rw = (int)($v['rewritten_races'] ?? 0);
        $cl = (int)($v['clean_races'] ?? 0);
        $class = $rw > 0 ? (data_quality_issue_on($date, ['leak']) ? 'muted' : 'alert') : '';
        $rows[] = ['class' => $class, 'cells' => [
            av_e($date), av_int($v['settled_races']), av_int($v['races_with_predictions'] ?? 0), av_int($rw),
            av_rate($rw, (int)($v['races_with_predictions'] ?? 0)), av_int($v['rewritten_strategies'] ?? 0),
            av_int($v['rewritten_strategy_results'] ?? 0),
            $rw > 0 ? av_rate($v['top1_hits_rewritten'] ?? 0, $rw) : '-', $cl > 0 ? av_rate($v['top1_hits_clean'] ?? 0, $cl) : '-',
            av_time($v['first_rewrite_at'] ?? null), av_time($v['last_rewrite_at'] ?? null)]];
    }
    $rw = (int)($t['rewritten_races'] ?? 0);
    $rows[] = ['class' => 'total', 'cells' => ['期間合計', av_int($t['settled_races'] ?? 0), av_int($t['races_with_predictions'] ?? 0),
        av_int($rw), av_rate($rw, (int)($t['races_with_predictions'] ?? 0)), av_int($t['rewritten_strategies'] ?? 0),
        av_int($t['rewritten_strategy_results'] ?? 0), av_pct($t['top1_rate_rewritten'] ?? null), av_pct($t['top1_rate_clean'] ?? null), '', '']];
    return $warn . av_section('日別の書き換え状況',
        '「書き換え」= 予測(predictions)の最終書き込みが、そのレースの結果の初回取込みより後のレース。'
        . '影響した買い目・清算は同じレースの strategies / strategy_results の行数。1位的中率は predictions(v2)の1位予測。',
        av_table([['日付', 'l'], ['結果確定'], ['予測あり'], ['書き換え'], ['書き換え率'], ['影響した買い目'], ['影響した清算'],
                  ['1位的中率(書き換え)'], ['1位的中率(書き換えなし)'], ['最初の書き換え', 'c'], ['最後の書き換え', 'c']], $rows, true));
}

// ═══════════ simulate_balance.php ═════════════════════════════════════

function av_view_simulate_balance(array $d): string {
    $out = '<p class="av-note">期間: ' . av_e($d['date_from']) . '〜' . av_e($d['date_to']) . '(' . av_int($d['simulation_days'])
        . '日間)/ v2切り替え日: ' . av_e($d['v2_cutover']) . '。DB書き込みなし。払戻は確定払戻を優先(なければ直前オッズ×100円)。</p>';
    $rows = [];
    foreach ($d['part1_diagnosis'] as $era => $x) {
        if ((int)$x['races'] === 0) {
            $rows[] = ['class' => 'muted', 'cells' => [av_e($era) . ':対象期間にレースなし(days を増やすと表示されます)']];
            continue;
        }
        foreach (['nofilter' => 'フィルタなし12点', 'cap25' => 'オッズ上限25倍'] as $k => $label) {
            $rows[] = [av_e($era), av_e($label), av_int($x['races']), av_int($x[$k]['hits']), av_pct($x[$k]['hit_rate']),
                       av_yen($x[$k]['cost']), av_yen($x[$k]['payout']), av_pct($x[$k]['roi']), av_yen($x['avg_hit_payout_' . $k])];
        }
        $rows[] = ['class' => 'muted', 'cells' => [av_e($era) . ':1位予測が1号艇 ' . av_pct($x['rank1_lane1_pct'])
            . ' / 候補のうちオッズ25倍以下 ' . av_pct($x['combo_odds_le25_pct']) . ' / 候補の平均オッズ ' . av_num($x['combo_odds_avg']) . '倍'
            . ' / 25倍上限で逃した的中 ' . av_int($x['lost_hits_by_cap25']) . '件・' . av_yen($x['lost_payout_by_cap25'])]];
    }
    $out .= av_section('診断:実運用の予測での25倍上限の影響',
        '本番の予測順位でバランス12点候補を作り、オッズ上限25倍の有無で比較(2026-09-03 に上限を100倍へ変更済み。当時の判断材料)。',
        av_table([['期間', 'l'], ['買い方', 'l'], ['レース数'], ['的中数'], ['的中率'], ['投資額'], ['払戻額'], ['回収率'], ['平均的中払戻']], $rows));

    $rows = [];
    foreach ($d['part2_variants']['results'] as $r) {
        $rows[] = [av_e($r['variant']), av_int($r['total_races']), av_int($r['active_races']), av_pct($r['entry_rate_pct']),
                   av_num($r['avg_combos'], 2), av_int($r['hits']), av_pct($r['hit_rate_active']), av_pct($r['hit_rate_all']),
                   av_yen($r['avg_hit_payout']), av_yen($r['total_cost']), av_yen($r['total_payout']), av_yen($r['profit']), av_pct($r['roi'])];
    }
    $out .= av_section('代替フィルタの比較(v2順位、' . av_int($d['part2_variants']['v2_races']) . 'R)',
        '参加率=フィルタ後に買い目が残ったレースの割合。的中率(参加)は参加レースが分母、的中率(全)は全レースが分母。',
        av_table([['フィルタ', 'l'], ['対象レース'], ['参加レース'], ['参加率'], ['平均点数'], ['的中数'], ['的中率(参加)'], ['的中率(全)'],
                  ['平均的中払戻'], ['投資額'], ['払戻額'], ['損益'], ['回収率']], $rows));
    return $out;
}

// ═══════════ simulate_ichigeki.php ════════════════════════════════════

function av_view_simulate_ichigeki(array $d): string {
    $rows = [];
    foreach ($d['results'] as $r) {
        $rows[] = [av_e($r['pool']), av_num($r['min_odds']) . '倍', av_int($r['total_races']), av_int($r['active_races']),
                   av_pct($r['entry_rate_pct']), av_int($r['hits']), av_pct($r['hit_rate_active']), av_pct($r['hit_rate_all']),
                   av_num($r['avg_combos'], 2), av_yen($r['total_cost']), av_yen($r['total_payout']), av_yen($r['profit']), av_pct($r['roi'])];
    }
    return '<p class="av-note">期間: ' . av_e($d['date_from']) . '〜' . av_e($d['date_to']) . '(' . av_int($d['simulation_days'])
        . '日間、' . av_int($d['race_count_total']) . 'R)。DB書き込みなし。</p>'
        . av_section('選定プール × オッズ下限の比較',
            '1着=予測1位固定、2・3着を選定プールから流し、オッズ下限未満の買い目を除外した場合の成績。参加率=買い目が残ったレースの割合。',
            av_table([['選定プール', 'l'], ['オッズ下限'], ['対象レース'], ['参加レース'], ['参加率'], ['的中数'], ['的中率(参加)'],
                      ['的中率(全)'], ['平均点数'], ['投資額'], ['払戻額'], ['損益'], ['回収率']], $rows, true));
}

// ═══════════ analyze_ichigeki.php ═════════════════════════════════════

function av_view_analyze_ichigeki(array $d): string {
    $modes = ['weekly' => '週別', 'venue' => '会場別', 'daily' => '日別', 'odds_dist' => 'オッズ帯別', 'payout_compare' => '期間比較'];
    $tabs = '<div class="av-tabs">';
    foreach ($modes as $m => $label) {
        $tabs .= '<a class="' . ($d['mode'] === $m ? 'on' : '') . '" href="analyze_ichigeki.php?mode=' . $m . '">' . av_e($label) . '</a>';
    }
    $tabs .= '</div>';
    $period = isset($d['start']) ? '<p class="av-note">期間: ' . av_e($d['start']) . '〜' . av_e($d['end']) . '(start/end で指定)</p>' : '';
    $rows = [];
    switch ($d['mode']) {
        case 'weekly':
            foreach ($d['data'] as $r) {
                $rows[] = [av_e($r['week_start']) . '〜' . av_e($r['week_end']), av_int($r['total_races']), av_int($r['hits']), av_pct($r['hit_rate']),
                           av_yen($r['total_cost']), av_yen($r['total_payout']), av_pct($r['return_rate']), av_pct($r['roi'])];
            }
            $tbl = av_table([['週', 'l'], ['レース数'], ['的中数'], ['的中率'], ['投資額'], ['払戻額'], ['回収率'], ['損益率']], $rows, true);
            break;
        case 'venue':
        case 'daily':
            $key = $d['mode'] === 'venue' ? 'venue' : 'date';
            foreach ($d['data'] as $r) {
                $rows[] = [av_e($r[$key]), av_int($r['total_races']), av_int($r['hits']), av_pct($r['hit_rate']),
                           av_yen($r['total_cost']), av_yen($r['total_payout']), av_pct($r['return_rate'])];
            }
            $tbl = av_table([[$d['mode'] === 'venue' ? '会場' : '日付', 'l'], ['レース数'], ['的中数'], ['的中率'], ['投資額'], ['払戻額'], ['回収率']], $rows, true);
            break;
        case 'odds_dist':
            $labels = ['under10' => '10倍未満', '10-20' => '10〜20倍', '20-50' => '20〜50倍', '50-100' => '50〜100倍', 'over100' => '100倍以上'];
            foreach ($d['data'] as $r) {
                $rows[] = [av_e($labels[$r['odds_range']] ?? $r['odds_range']), av_int($r['combos_checked']), av_int($r['hits'])];
            }
            $tbl = av_table([['オッズ帯', 'l'], ['買い目数'], ['的中を含むレースの買い目数']], $rows);
            break;
        default: // payout_compare
            foreach ($d['data'] as $r) {
                $rows[] = [av_e($r['label']), av_int($r['total_races']), av_int($r['hits']), av_pct($r['hit_rate']), av_pct($r['return_rate']),
                           av_yen($r['avg_cost']), av_yen($r['avg_hit_payout']), av_yen($r['max_hit_payout'])];
            }
            $tbl = av_table([['期間', 'l'], ['レース数'], ['的中数'], ['的中率'], ['回収率'], ['1Rあたり投資額'], ['平均的中払戻'], ['最大的中払戻']], $rows);
    }
    return $tabs . $period . av_section($modes[$d['mode']] ?? $d['mode'],
        '一撃重視の清算実績(strategy_results)。2026-08〜09 の不振調査用の一時分析で、2026-08-19〜09-28 はリーク込みの値を含む。', $tbl);
}

// ═══════════ backfill_list_run.php ════════════════════════════════════

function av_view_backfill_list_run(array $d): string {
    $s = $d['entries_summary'];
    $w = $d['entries_summary_week_0715_0722'];
    $sum = av_table([['範囲', 'l'], ['出走エントリー'], ['展示タイム欠損'], ['欠損率'], ['ST欠損'], ['欠損率']], [
        ['全期間', av_int($s['total_entries']), av_int($s['exhibit_null']), av_rate($s['exhibit_null'], $s['total_entries']),
         av_int($s['st_null']), av_rate($s['st_null'], $s['total_entries'])],
        ['2026-07-15〜07-22', av_int($w['total_entries']), av_int($w['exhibit_null']), av_rate($w['exhibit_null'], $w['total_entries']),
         av_int($w['st_null']), av_rate($w['st_null'], $w['total_entries'])],
    ]);
    $rows = [];
    foreach ($d['races'] as $r) {
        $rows[] = [av_e($r['date']), av_e($r['venue']), av_int($r['race_no']) . 'R', av_e($r['scheduled_time']), av_int($r['race_id'])];
    }
    return av_section('欠損の概況', '展示タイム(exhibit_time)・展示ST(start_timing)が入っていない出走エントリーの件数。', $sum)
        . av_section('バックフィル対象レース(' . av_int($d['race_count']) . 'R)',
            '発走予定時刻を過ぎていて、展示タイムまたはSTが欠損しているエントリーを含むレース。backfill_beforeinfo.py の対象。',
            av_table([['日付', 'l'], ['会場', 'l'], ['レース', 'c'], ['発走予定', 'c'], ['レースID']], $rows, true));
}

// ═══════════ shadow_eval_v3.php ═══════════════════════════════════════

function av_view_shadow_eval_v3(array $d): string {
    $out = '<p class="av-warn">v3w シャドウテスト(2026-09-13〜09-29)の評価用。v2 側(本番)の値は 2026-08-19〜09-28 が'
        . 'リーク込みのため比較には使えません。現在の監視は model_switch_status.php を使ってください。</p>';
    $t = $d['top1'];
    $out .= av_section('1着的中率(期間合計)', '', av_table([['モデル', 'l'], ['レース数'], ['1着的中率']], [
        ['v2(predictions)', av_int($t['v2']['races']), av_pct($t['v2']['hit_rate'])],
        ['v3w(predictions_v2)', av_int($t['v3']['races']), av_pct($t['v3']['hit_rate'])],
    ]));
    $rows = [];
    foreach ($d['daily'] as $date => $v) {
        $rows[] = [av_e($date), av_int($v['v2_races']), av_rate($v['v2_hits'], $v['v2_races']), av_rate($v['v3_hits'], $v['v3_races'])];
    }
    $out .= av_section('日別の1着的中率', '', av_table([['日付', 'l'], ['レース数'], ['v2的中率'], ['v3w的中率']], $rows, true));
    $rows = [];
    foreach ($d['strategy_sim_v3'] as $type => $s) {
        $p = $d['strategy_prod_v2'][$type] ?? null;
        $rows[] = [av_e($type), av_int($s['races']), av_pct($s['hit_rate']), av_pct($s['roi']), av_num($s['avg_combos'], 2),
                   $p ? av_int($p['races']) : '-', $p ? av_pct($p['hit_rate']) : '-', $p ? av_pct($p['roi']) : '-'];
    }
    $out .= av_section('4戦略', '左: v3w 順位での戦略シミュレーション(旧ロジック・均等100円)/ 右: 本番の清算実績(strategy_results)。',
        av_table([['戦略', 'l'], ['シム レース数'], ['シム 的中率'], ['シム 回収率'], ['シム 平均点数'],
                  ['本番 レース数'], ['本番 的中率'], ['本番 回収率']], $rows));
    return $out;
}

// ═══════════ export_lr_data_v3.php(stats=1) ═══════════════════════════

function av_view_export_lr_stats(array $d): string {
    $rows = [];
    $tot = 0; $mat = 0;
    foreach ($d['stats'] as $r) {
        $tot += $r['races_total']; $mat += $r['races_matched'];
        $rows[] = ['class' => $r['races_matched'] < $r['races_total'] ? 'alert' : '', 'cells' => [
            av_e($r['date']), av_e($r['venue']), av_int($r['races_total']), av_int($r['races_matched']), av_rate($r['races_matched'], $r['races_total'])]];
    }
    $rows[] = ['class' => 'total', 'cells' => ['合計', '', av_int($tot), av_int($mat), av_rate($mat, $tot)]];
    return '<p class="av-note">期間: ' . av_e($d['from']) . '〜' . av_e($d['to']) . '。stats=1 を外すと学習用CSVをダウンロードします。</p>'
        . av_section('学習用データの揃い具合(日×会場)',
            '「出力対象」= 出走表と着順(1着)がそろい学習用CSVに含められるレース。赤い行は一部のレースが出力されない日×会場。',
            av_table([['日付', 'l'], ['会場', 'l'], ['レース数'], ['出力対象'], ['出力率']], $rows, true));
}
