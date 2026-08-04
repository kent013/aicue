## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

## あなたの役割

あなたは Laravel + Svelte アプリのコードレビュアーである。TODO T096 の実装差分をレビューせよ。
これは **Round 2** である (Round 1 は別セッションで実施済み。文脈が無いため下に全文を再掲する)。

### 背景 (T096 が塞ぐ問題)

`smalot/pdfparser` v2.12.5 が定義済み CJK CMap (`90ms-RKSJ-H` 等) を一切サポートしておらず、
CP932 バイト列を Windows-1252 として decode する。結果は**正当な UTF-8** になるため
`SopTextExtractor::ensureUtf8()` の検証を素通りし、**文字化けテキストがそのまま LLM に渡っている**
(North Star の入口が壊れている Critical)。

対策は 2 つの独立した関門:
- (A) **区間単位**の SJIS 誤解釈復元。3 段の検証を**すべて**満たした区間だけ置換する。
  文書一括変換は禁止 (実サンプル `AS_作業手順書.pdf` は mojibake と隠し OCR 層由来の正規日本語 63 文字の混在)。
- (B) 日本語本文比率ゲート (`config('manual.analysis_min_japanese_ratio')` = 0.10 未満は LLM に渡さず拒否)。

### レビュー観点

1. **設計との一致性** — 詳細設計書のとおりに実装されているか。逸脱があれば妥当か
2. **正確性** — アルゴリズムの正しさ、境界条件、例外経路、副作用
3. **PHPStan level 10 適合性** — mixed の漏れ、型の widen、@phpstan-ignore の混入がないか
4. **テスト網羅性** — 不変条件がテストで固定されているか。テストが実装の写像になっていないか
5. **セキュリティ** — untrusted な顧客手順書テキストの扱い、ログへの本文漏洩、ReDoS 等
6. **Round 1 指摘の解消** — 下記の対応マトリクスの判断 (特に「反論」した #1) が妥当か

### 出力形式

- ファイルごとに判定 (APPROVE / CHANGES_REQUESTED) と指摘
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- Round 1 で指摘した内容が解消されたかを明記する
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記する

---

## Round 1 の Codex レビュー結果 (全文)

**レビュー結果**

- `app/Services/Manual/SopTextExtractor.php:56` — **CHANGES_REQUESTED**
  - [Warning] `repairSjisMojibake()` が `pdf` 以外（`plain` / `spreadsheet`）にも適用されています。今回の原因は `smalot/pdfparser` の CMap 非対応に起因するため、現状実装だと正当な CP1252 文字（例: `é`）を半角カナ等へ誤変換する余地があります。`$kind === 'pdf'` のときだけ復元段を通すか、復元成立条件をさらに厳格化すべきです。
  - [Suggestion] 観測ログの `japanese_ratio_before/after` は `normalize()` 前、最終ゲートは `normalize()` 後で計算されており、運用分析時に比較がズレます。比較用途なら同一フェーズ基準に揃えると解釈しやすいです。

- `tests/Unit/Manual/SopTextExtractorTest.php:149` — **CHANGES_REQUESTED**
  - [Warning] 「正当な日本語 + CP1252拡張文字（例: `Café`）が混在する非PDF入力で文字化け復元が発火しない」回帰テストが不足しています。上記実装リスクを固定するテスト追加が必要です。
  - [Suggestion] `file_get_contents($tmp)` の `(string)` キャスト（`tests/Unit/Manual/SopTextExtractorTest.php:257`）は失敗を空文字へ潰すため、`is_string` 検証で明示失敗にした方が型・診断の両面で安全です。

- `app/Exceptions/Manual/AnalysisFailedException.php:15` — **APPROVE**
  - 指摘なし（文言体系の整理・次アクション追記は設計意図に整合）。

- `config/manual.php:34` — **APPROVE**
  - 指摘なし（`analysis_min_japanese_ratio` の導入とコメントは妥当）。

- `docs/architecture.md:119` — **APPROVE**
  - 指摘なし（実装方針の明文化として適切）。

**全体判定: CHANGES_REQUESTED**
## Round 1 に対する対応マトリクス

# Codex 実装レビュー Round 1 — 対応マトリクス (T096)

レビュー返答: `../impl-review-round-1.md` (全体判定: CHANGES_REQUESTED)
プロンプト: `impl-review-prompt-round-1.md`

| # | 分類 | 指摘 | 判断 | 対応 |
|---|---|---|---|---|
| 1 | Warning | `repairSjisMojibake()` が `pdf` 以外 (`plain` / `spreadsheet`) にも適用され、正当な CP1252 文字 (`é` 等) を誤変換する余地がある。`$kind === 'pdf'` に限定するか復元条件を厳格化すべき | **反論 (媒体限定は見送り) + 回帰テストで固定** | `probe/probe-mixed-latin.php` を追加して実測。テスト `正当な日本語に CP1252 拡張文字が混在しても復元は発火しない (非 PDF)` を追加 |
| 2 | Suggestion | 観測ログの `japanese_ratio_before/after` は `normalize()` 前、最終ゲートは `normalize()` 後で計算されており運用分析時に比較がズレる | **対応** | ログを `normalize()` 後へ移動し、before も `normalize($extracted)` 基準に揃えた (`SopTextExtractor::extract()`) |
| 3 | Warning | 「正当な日本語 + CP1252 拡張文字が混在する非 PDF 入力で復元が発火しない」回帰テストが不足 | **対応** | 上記 #1 のテストを追加 (`é ö ß à € ™` を含む日本語手順書が 1 文字も変化しないことを固定) |
| 4 | Suggestion | `file_get_contents($tmp)` の `(string)` キャストは失敗を空文字へ潰す | **対応** | `Assert::string($contents, ...)` へ変更し、パスを含むメッセージで明示失敗させる |

---

## #1 の判断根拠 (媒体で限定しない理由)

### (a) 実測: 正当なテキストは 1 件も変化しない

`probe/probe-mixed-latin.php` で復元アルゴリズムを 5 種の正当テキストに適用した結果、
**変化件数 0**。3 段検証がどこで落ちているかもトレースで確認した:

| 入力 | 区間の判定 | 変化 |
|---|---|---|
| 日本語 + `Café` / `Größe` / `à la carte` | `" (Café "` = SJIS-win 不正 / `" Größe "` = 日本語が増えない | なし (ja 0.645 → 0.645) |
| 日本語 + `—` `「」` | `" — "` = SJIS-win 不正 | なし (ja 0.744 → 0.744) |
| ドイツ語のみ (`ö ü ß`) | 文書全体が 1 区間 → SJIS-win 不正 | なし |
| フランス語のみ (`é à è`) | 同上 | なし |
| 日本語 + `€` `™` | SJIS-win 不正 | なし (ja 0.711 → 0.711) |
| 対照: 人工 SJIS 化け | 採用 | **復元 OK** |

`é` (0xE9) / `à` (0xE0) / `ö` (0xF6) は確かに Shift_JIS の lead byte だが、
**次バイトが妥当な trail byte (0x40-0x7E / 0x80-0xFC) でなければ区間全体が SJIS-win 不正**になる。
欧文では accent の直後がほぼ必ず空白か ASCII 英字の一部であり、
運良く妥当な 2 バイトになっても (`Größe` → `Gre`) **日本語が 1 文字も増えないため第 3 段で落ちる**。
誤変換は「区間全体が妥当な CP932 で、かつ decode 結果の 10% 以上が日本語文字になる」
場合にしか起きない = **それは mojibake そのもの**である。

### (b) 判定基準は「媒体」ではなく「内容」であるべき

`$kind === 'pdf'` で囲うのは *媒体* による近似であり、
実際に守っている不変条件 (「CP1252 誤解釈由来で、読み直すと日本語になる区間だけを置換する」) と
一対一に対応しない。3 段検証は既に内容ベースで、媒体を見る必要がない。
逆に破損した .txt (他ツールが吐いた mojibake) が来たとき、pdf 限定だと
North Star の入口が素通りする — これは本タスクが潰そうとしている Critical そのものである。

### (c) 媒体限定はアルゴリズムのテスト可能性を失わせる

詳細設計の T1 / T4 (復元の発火・混在文書の保全) は `text/plain` の合成 fixture で
境界を作っている。pdf 限定にすると、これらは実 PDF (`AS_作業手順書.pdf`) 経由でしか
検証できなくなり、**アルゴリズムの境界 (3 段検証の各段) を独立に固定する手段が消える**。
バイナリ fixture を増やす方向は設計の fixture 方針 (複製せず参照する) にも反する。

### 結論

媒体による限定は行わず、**Codex が懸念したケースを回帰テストとして固定する**ことで応じた。
実測データ (probe) とテストの両方をリポジトリに残し、後から検証できるようにしてある。

---

## 未反映の指摘

なし (Critical 0 件 / Warning 2 件 = 1 件対応・1 件は回帰テストで代替対応 / Suggestion 2 件 = 対応)。

## Round 1 の判断根拠となった probe スクリプトと実測出力

### devnotes/20260804-0900-sop-pdf-mojibake/probe/probe-mixed-latin.php

```php
<?php

declare(strict_types=1);

/*
 * Codex impl-review round 1 の Warning 1 (「復元段が pdf 以外にも適用され、正当な CP1252 拡張文字を
 * 誤変換しうる」) を実測で検証する probe。
 *
 * SopTextExtractor の復元アルゴリズム (区間抽出 + 3 段検証) をそのまま写して、
 * 「正当な日本語 + CP1252 拡張文字 (Café / Größe / à la carte)」の混在入力に対して
 * 1 文字でも変化するかを確認する。
 *
 * 実行: php devnotes/20260804-0900-sop-pdf-mojibake/probe/probe-mixed-latin.php
 */

const CP1252_RUN_PATTERN = '/[\x{0000}-\x{007F}\x{0081}\x{008D}\x{008F}\x{0090}\x{009D}'
    .'\x{00A0}-\x{00FF}\x{20AC}\x{201A}\x{0192}\x{201E}\x{2026}\x{2020}\x{2021}\x{02C6}'
    .'\x{2030}\x{0160}\x{2039}\x{0152}\x{017D}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}'
    .'\x{2013}\x{2014}\x{02DC}\x{2122}\x{0161}\x{203A}\x{0153}\x{017E}\x{0178}]+/u';

const JAPANESE_PATTERN = '/[\x{3001}-\x{303F}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}'
    .'\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{FF01}-\x{FF60}\x{FF66}-\x{FF9D}]/u';

const NON_SPACE_PATTERN = '/[^\s\x{3000}]/u';

function japaneseCount(string $text): int
{
    $count = preg_match_all(JAPANESE_PATTERN, $text);

    return is_int($count) ? $count : 0;
}

function japaneseRatio(string $text): float
{
    $assessable = preg_match_all(NON_SPACE_PATTERN, $text);
    if (! is_int($assessable) || $assessable === 0) {
        return 0.0;
    }

    return japaneseCount($text) / $assessable;
}

/** @return array{string, string} 復元結果と、区間ごとの判定トレース */
function repair(string $text, float $min = 0.10): array
{
    $trace = '';
    $repaired = preg_replace_callback(CP1252_RUN_PATTERN, function (array $m) use ($min, &$trace): string {
        $run = (string) $m[0];
        $bytes = mb_convert_encoding($run, 'CP1252', 'UTF-8');
        if (mb_convert_encoding($bytes, 'UTF-8', 'CP1252') !== $run) {
            $trace .= sprintf("  [不採用: CP1252 不可逆] %s\n", json_encode($run, JSON_UNESCAPED_UNICODE));

            return $run;
        }
        if (! mb_check_encoding($bytes, 'SJIS-win')) {
            $trace .= sprintf("  [不採用: SJIS-win 不正] %s\n", json_encode($run, JSON_UNESCAPED_UNICODE));

            return $run;
        }
        $decoded = mb_convert_encoding($bytes, 'UTF-8', 'SJIS-win');
        if (! mb_check_encoding($decoded, 'UTF-8')) {
            $trace .= sprintf("  [不採用: UTF-8 不正] %s\n", json_encode($run, JSON_UNESCAPED_UNICODE));

            return $run;
        }
        if (japaneseCount($decoded) <= japaneseCount($run)) {
            $trace .= sprintf("  [不採用: 日本語が増えない] %s → %s\n",
                json_encode($run, JSON_UNESCAPED_UNICODE), json_encode($decoded, JSON_UNESCAPED_UNICODE));

            return $run;
        }
        if (japaneseRatio($decoded) < $min) {
            $trace .= sprintf("  [不採用: 比率 %.3f < %.2f] %s\n", japaneseRatio($decoded), $min,
                json_encode($run, JSON_UNESCAPED_UNICODE));

            return $run;
        }
        $trace .= sprintf("  [採用] %s → %s\n",
            json_encode($run, JSON_UNESCAPED_UNICODE), json_encode($decoded, JSON_UNESCAPED_UNICODE));

        return $decoded;
    }, $text);

    return [is_string($repaired) ? $repaired : $text, $trace];
}

$cases = [
    '日本語 + CP1252 拡張 (Café)' => "作業手順書 (Café ラインの Größe 点検)\n"
        ."1. ネジを締める。トルクは 5Nm とする。\n"
        ."2. カバーを取り付ける。\n"
        .'備考: à la carte の設備は対象外とする。',
    '日本語 + 記号ダッシュ・引用符' => "作業手順書 — 「安全確認」\n1. ネジを締める (5Nm)。\n2. 保護メガネを着用する。",
    'CP1252 拡張のみ (独)' => 'Arbeitsanweisung: Schraube mit 5Nm anziehen. Größe prüfen. Für die Straße. Öl nachfüllen.',
    'CP1252 拡張のみ (仏)' => 'Mode opératoire: Serrer la vis à 5 Nm. Vérifier la référence arrière. Côté gauche.',
    '通貨・商標記号混在' => "作業手順書\n1. €120 の部材を使う。2. ™ 表示を確認する。3. ネジを締める。",
];

$ng = 0;
foreach ($cases as $label => $text) {
    [$out, $trace] = repair($text);
    $same = $out === $text;
    $ng += $same ? 0 : 1;
    printf("=== %s ===\n  変化: %s (ja %.3f → %.3f)\n%s\n", $label, $same ? 'なし' : '★あり★',
        japaneseRatio($text), japaneseRatio($out), $trace);
}

// 対照: 本物の SJIS 化けは復元されること (アルゴリズムが死んでいないことの確認)
$mojibake = mb_convert_encoding(
    (string) mb_convert_encoding('作業手順書 ネジを締める 安全確認', 'CP932', 'UTF-8'), 'UTF-8', 'CP1252');
[$out] = repair((string) $mojibake);
printf("=== 対照: 人工 SJIS 化け ===\n  復元: %s\n", $out === '作業手順書 ネジを締める 安全確認' ? 'OK' : "NG ({$out})");

printf("\n判定: 正当テキストで変化した件数 = %d (0 なら Warning 1 のリスクは実測で不成立)\n", $ng);
```

### 実行結果

```
=== 日本語 + CP1252 拡張 (Café) ===
  変化: なし (ja 0.645 → 0.645)
  [不採用: SJIS-win 不正] " (Café "
  [不採用: 日本語が増えない] " Größe " → " Gre "
  [不採用: 日本語が増えない] ")\n1. " → ")\n1. "
  [不採用: 日本語が増えない] " 5Nm " → " 5Nm "
  [不採用: 日本語が増えない] "\n2. " → "\n2. "
  [不採用: 日本語が増えない] "\n" → "\n"
  [不採用: SJIS-win 不正] ": à la carte "

=== 日本語 + 記号ダッシュ・引用符 ===
  変化: なし (ja 0.744 → 0.744)
  [不採用: SJIS-win 不正] " — "
  [不採用: 日本語が増えない] "\n1. " → "\n1. "
  [不採用: 日本語が増えない] " (5Nm)" → " (5Nm)"
  [不採用: 日本語が増えない] "\n2. " → "\n2. "

=== CP1252 拡張のみ (独) ===
  変化: なし (ja 0.000 → 0.000)
  [不採用: SJIS-win 不正] "Arbeitsanweisung: Schraube mit 5Nm anziehen. Größe prüfen. Für die Straße. Öl nachfüllen."

=== CP1252 拡張のみ (仏) ===
  変化: なし (ja 0.000 → 0.000)
  [不採用: SJIS-win 不正] "Mode opératoire: Serrer la vis à 5 Nm. Vérifier la référence arrière. Côté gauche."

=== 通貨・商標記号混在 ===
  変化: なし (ja 0.711 → 0.711)
  [不採用: SJIS-win 不正] "\n1. €120 "
  [不採用: SJIS-win 不正] "2. ™ "
  [不採用: 日本語が増えない] "3. " → "3. "

=== 対照: 人工 SJIS 化け ===
  復元: OK

判定: 正当テキストで変化した件数 = 0 (0 なら Warning 1 のリスクは実測で不成立)
```

## 現在の実装差分 (Round 1 の修正を反映済み・全文)

```diff
diff --git a/app/Exceptions/Manual/AnalysisFailedException.php b/app/Exceptions/Manual/AnalysisFailedException.php
index e6791df..e73983d 100644
--- a/app/Exceptions/Manual/AnalysisFailedException.php
+++ b/app/Exceptions/Manual/AnalysisFailedException.php
@@ -12,10 +12,28 @@
  */
 final class AnalysisFailedException extends RuntimeException
 {
-    /** テキスト抽出不能 (画像/スキャン手順書・破損・バイナリ) */
+    /** テキスト抽出不能 (画像/スキャン手順書・破損・バイナリ・PDF から 1 バイトも取れない) */
     public static function unextractable(): self
     {
-        return new self('テキストを抽出できません。画像・スキャンの手順書は現在未対応です。');
+        return new self(
+            'テキストを抽出できません。画像・スキャンの手順書は現在未対応です。'
+            .'Excel・テキスト形式か、文字が選択できる PDF をアップロードしてください。'
+        );
+    }
+
+    /**
+     * 抽出はできたが日本語の本文が閾値に満たない
+     * (文字化け / テキスト埋め込みの破損 / 日本語以外の手順書)。
+     * 3 つの原因をアプリ側で識別する手段は無いため、どの原因でも実行できる次アクションを示す。
+     */
+    public static function insufficientJapaneseText(): self
+    {
+        return new self(
+            '手順書から十分な日本語の本文を読み取れませんでした。'
+            .'文字が画像になっている / PDF のテキスト埋め込みが壊れている / '
+            .'日本語以外の手順書、のいずれかの可能性があります。'
+            .'日本語の手順書を、Excel・テキスト形式か文字を選択できる PDF でアップロードしてください。'
+        );
     }
 
     /** 抽出できたが本文が実質空 (min_text_bytes 未満)。画像扱いと混同しない明示文言 */
diff --git a/app/Services/Manual/SopTextExtractor.php b/app/Services/Manual/SopTextExtractor.php
index 7e2062c..93337f3 100644
--- a/app/Services/Manual/SopTextExtractor.php
+++ b/app/Services/Manual/SopTextExtractor.php
@@ -7,6 +7,7 @@
 use App\DataTransferObjects\Manual\Analysis\ExtractedText;
 use App\Exceptions\Manual\AnalysisFailedException;
 use App\Models\SourceDocument;
+use Illuminate\Support\Facades\Log;
 use Illuminate\Support\Facades\Storage;
 use PhpOffice\PhpSpreadsheet\Cell\Cell;
 use PhpOffice\PhpSpreadsheet\IOFactory;
@@ -20,9 +21,34 @@
  * - 分岐はアップロード時に内容 sniff 済みの mime を使う (クライアント拡張子は信頼しない)
  * - 抽出不能/実質空/バイト上限超過は AnalysisFailedException (ユーザー向け文言)
  * - byteLength (strlen = UTF-8 bytes) が token budget 判定値 (config manual.analysis_max_text_bytes)
+ * - SJIS 誤解釈 (pdfparser の定義済み CJK CMap 非対応) を区間単位で復元し、
+ *   日本語本文が閾値未満のテキストは LLM に渡さない (manual.analysis_min_japanese_ratio)
  */
 class SopTextExtractor
 {
+    /**
+     * CP1252 の 256 バイトと 1:1 対応する文字だけからなる極大連続区間。
+     *
+     * pdfparser は定義済み CJK CMap (90ms-RKSJ-H 等) を知らないため、CP932 バイト列を
+     * Windows-1252 として decode してしまう (Font::decodeContentByAutodetectIfNecessary)。
+     * その化けを元バイト列へ戻せる文字集合が、この 256 文字の全単射である。
+     * U+0081/008D/008F/0090/009D は CP1252 未定義バイトだが mbstring が素通しし、かつ
+     * Shift_JIS の主要 lead byte (0x81 = JIS 記号行 / 0x8D / 0x8F / 0x90) なので必須。
+     * BMP 全走査で「mbstring の CP1252 往復が同一になる集合」と完全一致を検証済み
+     * (devnotes/20260804-0900-sop-pdf-mojibake/probe/probe-cp1252-table.php)。
+     */
+    private const CP1252_RUN_PATTERN = '/[\x{0000}-\x{007F}\x{0081}\x{008D}\x{008F}\x{0090}\x{009D}'
+        .'\x{00A0}-\x{00FF}\x{20AC}\x{201A}\x{0192}\x{201E}\x{2026}\x{2020}\x{2021}\x{02C6}'
+        .'\x{2030}\x{0160}\x{2039}\x{0152}\x{017D}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}'
+        .'\x{2013}\x{2014}\x{02DC}\x{2122}\x{0161}\x{203A}\x{0153}\x{017E}\x{0178}]+/u';
+
+    /** 日本語文字 (かな / 漢字 / 全角句読点 / 全角英数記号 / 半角カナ) */
+    private const JAPANESE_PATTERN = '/[\x{3001}-\x{303F}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}'
+        .'\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{FF01}-\x{FF60}\x{FF66}-\x{FF9D}]/u';
+
+    /** 比率の分母 = 空白を除いた文字数 (レイアウト由来の空白量に判定を引きずられない) */
+    private const NON_SPACE_PATTERN = '/[^\s\x{3000}]/u';
+
     public function extract(SourceDocument $document): ExtractedText
     {
         $contents = Storage::get($document->file_path);
@@ -30,7 +56,7 @@ public function extract(SourceDocument $document): ExtractedText
 
         $kind = $this->kindFor($document->mime);
         try {
-            $text = match ($kind) {
+            $extracted = match ($kind) {
                 'pdf' => $this->fromPdf($contents),
                 'spreadsheet' => $this->fromSpreadsheet($contents),
                 'plain' => $contents,
@@ -42,10 +68,30 @@ public function extract(SourceDocument $document): ExtractedText
             throw AnalysisFailedException::unextractable();
         }
 
-        $text = $this->ensureUtf8($text); // JSON 化・UserInput 生成を不正バイトで壊さない
-        $text = $this->normalize($text);
+        // 「区間の採否」と「文書ゲート」は同じ閾値で判断する = ここで 1 回だけ読む
+        $minJapaneseRatio = config()->float('manual.analysis_min_japanese_ratio');
+
+        $extracted = $this->ensureUtf8($extracted); // JSON 化・UserInput 生成を不正バイトで壊さない
+        $repaired = $this->repairSjisMojibake($extracted, $minJapaneseRatio);
+        $text = $this->normalize($repaired);
+        if ($repaired !== $extracted) {
+            // 現場でこの化けがどれだけ起きているかを後から測れるようにする (本文は出さない)。
+            // 比率は下段の日本語本文ゲートと同じ normalize 後基準で出す (運用分析で直接比較できる)
+            Log::info('SOP テキストの SJIS 誤解釈を復元しました', [
+                'reason' => 'sjis_mojibake_repaired',
+                'source_document_id' => $document->id,
+                'source_kind' => $kind,
+                'japanese_ratio_before' => round($this->japaneseRatio($this->normalize($extracted)), 4),
+                'japanese_ratio_after' => round($this->japaneseRatio($text), 4),
+            ]);
+        }
 
         $bytes = strlen($text);
+        if ($bytes === 0 && $kind === 'pdf') {
+            // PDF から 1 バイトも取れない = 文字が画像 (スキャン手順書)。
+            // plain / spreadsheet の空ファイルは原因が違うので tooShort のままにする
+            throw AnalysisFailedException::unextractable();
+        }
         if ($bytes < config()->integer('manual.analysis_min_text_bytes')) {
             throw AnalysisFailedException::tooShort(); // 短い有効テキスト → 画像未対応と別文言
         }
@@ -53,9 +99,89 @@ public function extract(SourceDocument $document): ExtractedText
             throw AnalysisFailedException::tooLarge();
         }
 
+        $ratio = $this->japaneseRatio($text);
+        if ($ratio < $minJapaneseRatio) {
+            Log::info('SOP テキストの日本語本文が不足しています', [
+                'reason' => 'insufficient_japanese_text',
+                'source_document_id' => $document->id,
+                'source_kind' => $kind,
+                'japanese_ratio' => round($ratio, 4),
+                'byte_length' => $bytes,
+            ]);
+
+            throw AnalysisFailedException::insufficientJapaneseText();
+        }
+
         return new ExtractedText($text, $bytes, $kind);
     }
 
+    /**
+     * CP932 バイト列を Windows-1252 として解釈された文字列 (mojibake) の復元。
+     *
+     * CP1252 レパートリ内の**極大連続区間**だけを単位に読み直す。区間外の文字
+     * (= 正しく decode された日本語。AS_作業手順書.pdf では隠し OCR 層由来の 63 文字)
+     * には一切触れないため、混在文書でも既存の正しいテキストを壊さない。
+     */
+    private function repairSjisMojibake(string $text, float $minJapaneseRatio): string
+    {
+        $repaired = preg_replace_callback(
+            self::CP1252_RUN_PATTERN,
+            fn (array $matches): string => $this->decodeRunAsSjis((string) $matches[0], $minJapaneseRatio),
+            $text,
+        );
+
+        return is_string($repaired) ? $repaired : $text;
+    }
+
+    /**
+     * 1 区間を SJIS-win として読み直す。3 段の検証をすべて満たしたときだけ置換し、
+     * 1 つでも欠けたら原文をそのまま返す (推測変換をしない)。
+     *   1. CP1252 へ可逆に戻せる (= この区間が CP1252 誤解釈由来である)
+     *   2. 得たバイト列が SJIS-win として妥当である
+     *   3. 復号で日本語が増え、かつ日本語比率が閾値以上である
+     */
+    private function decodeRunAsSjis(string $run, float $minJapaneseRatio): string
+    {
+        // encoding 名がリテラルのため mb_convert_encoding は string を返す (不正名は ValueError)
+        $bytes = mb_convert_encoding($run, 'CP1252', 'UTF-8');
+        if (mb_convert_encoding($bytes, 'UTF-8', 'CP1252') !== $run) {
+            return $run;
+        }
+        if (! mb_check_encoding($bytes, 'SJIS-win')) {
+            return $run;
+        }
+
+        $decoded = mb_convert_encoding($bytes, 'UTF-8', 'SJIS-win');
+        if (! mb_check_encoding($decoded, 'UTF-8')) {
+            return $run;
+        }
+        if ($this->japaneseCount($decoded) <= $this->japaneseCount($run)) {
+            return $run;
+        }
+
+        // 区間の採否も文書ゲートと同じ閾値で判断する (「日本語として読めるか」は 1 つの基準)
+        return $this->japaneseRatio($decoded) >= $minJapaneseRatio ? $decoded : $run;
+    }
+
+    /** 日本語文字数 */
+    private function japaneseCount(string $text): int
+    {
+        $count = preg_match_all(self::JAPANESE_PATTERN, $text);
+
+        return is_int($count) ? $count : 0;
+    }
+
+    /** 空白を除いた文字数に占める日本語文字の比率 (0.0〜1.0) */
+    private function japaneseRatio(string $text): float
+    {
+        $assessable = preg_match_all(self::NON_SPACE_PATTERN, $text);
+        if (! is_int($assessable) || $assessable === 0) {
+            return 0.0;
+        }
+
+        return $this->japaneseCount($text) / $assessable;
+    }
+
     /**
      * mime → 抽出方式。未知 mime はアップロード時 sniff で弾かれている前提だが、
      * 防御的に unextractable で落とす (LLM に渡さない)。
diff --git a/config/manual.php b/config/manual.php
index b899381..cda3f8c 100644
--- a/config/manual.php
+++ b/config/manual.php
@@ -27,9 +27,19 @@
     // 安全側上界のため strlen で保証する (AnalysisTokenBudgetInvariantTest が算術を固定)
     'analysis_max_text_bytes' => 150_000,
 
-    // 抽出テキストの実質空判定 (これ未満は「テキストを抽出できません」)
+    // 抽出テキストの実質空判定 (これ未満は「本文が短すぎます」。PDF の 0 バイトのみ unextractable)
     'analysis_min_text_bytes' => 100,
 
+    // 抽出テキストが「日本語の手順書本文」と言えるかの下限 (空白を除く文字数に占める
+    // かな/漢字/全角記号/半角カナの比率)。これ未満は LLM に渡さず insufficientJapaneseText。
+    // v1 の原稿は日本語 (doc/08 §182 / config/app.php の locale=ja) であることが前提。
+    // 導出 (devnotes/20260804-0900-sop-pdf-mojibake): 破損クラスの実測は 0.000 (glyph ノイズ /
+    // 欧文) 〜 0.020 (SJIS 化け未修復) で誤受理側に 5 倍、正当な日本語 SOP は復元後 0.661 /
+    // 型番を極端に詰めた対照でも 0.196 で誤拒否側に約 2 倍のマージンがある。
+    // 誤拒否は運用ログ (reason=insufficient_japanese_text) で観測できるようにしてあり、
+    // field データが出るまでこの値は動かさない。
+    'analysis_min_japanese_ratio' => 0.10,
+
     // stale ジョブ回復閾値 (分)。queued: dispatch 喪失、running: worker 異常終了
     'analysis_stale_after_minutes' => 30,
 
diff --git a/docs/architecture.md b/docs/architecture.md
index 7a7a534..4dc6e68 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -116,7 +116,7 @@ ## 主要 Service (テンプレート同梱)
 | `Manual/SourceDocumentService` | AI-CUE: SOP (SourceDocument) の保存。追記型 immutable (差し替え = 新規行)。専用 route 経路は VideoManual 行ロック + draft/ready guard、MIME は内容 sniff で再判定 (polyglot 対策) |
 | `Manual/AnalysisJobService` | AI-CUE: AI 解析の状態機械 (trigger = draft/ready→analyzing + in-flight 冪等 + 残高事前チェック / failJob = 行ロック + terminal guard の冪等失敗確定 / recoverStale = stale 回復 cron 本体) |
 | `Manual/AnalysisPipeline` | AI-CUE: 解析パイプライン本体 (extract→decompose→generate→terminal tx)。チケット 2 フェーズ (予約冪等キー = analysis_jobs.ticket_reservation_id、materialize + commit + succeeded を単一 tx で原子化)。LLM 出力の有界リトライ (JSON 検証失敗のみ最大 2 回) |
-| `Manual/SopTextExtractor` | AI-CUE: SOP テキスト抽出 (pdf = smalot/pdfparser / xlsx·xls = phpoffice/phpspreadsheet / txt)。UTF-8 strict 検証 + UTF-8 バイト上限 (token budget 導出。AnalysisTokenBudgetInvariantTest が算術を固定) |
+| `Manual/SopTextExtractor` | AI-CUE: SOP テキスト抽出 (pdf = smalot/pdfparser / xlsx·xls = phpoffice/phpspreadsheet / txt)。UTF-8 strict 検証 + **SJIS 誤解釈 (pdfparser が定義済み CJK CMap 非対応のため CP932 を Windows-1252 として decode する) の区間単位復元** + **日本語本文ゲート** (`manual.analysis_min_japanese_ratio` 未満は LLM に渡さず insufficientJapaneseText。評価対象は**正規化後・空白を除いた文字数**に占める日本語文字の比率。**閾値の変更は TODO 起票 + 実測の再提出を必須とする**) + UTF-8 バイト上限 (token budget 導出。AnalysisTokenBudgetInvariantTest が算術を固定)。0 バイトは媒体で弁別する (pdf = unextractable / plain・spreadsheet = tooShort) |
 | `Manual/RenderJobService` | AI-CUE: レンダの状態機械 (trigger = ready→rendering + render 冪等 + 採用テイク/尺/残高 guard / triggerPreview = Organization 行ロックで org 同時 preview 上限を直列化 / failJob = 冪等失敗確定 / completeRenderIntoLockedManual = ロック済み前提メソッド / recoverStale・reconcileOutputs = cron 本体) |
 | `Manual/RenderPipeline` | AI-CUE: レンダパイプライン本体 (startJob→buildManifest→compose→upload→finalize)。チケット 2 フェーズ (予約冪等キー = render_jobs.ticket_reservation_id、complete + commit + succeeded を terminal tx で原子化)。version スナップショット固定 (§10.8-6) |
 | `Manual/CutSequencer` | AI-CUE: カット表示順 (step→配下 point) と表示ラベル (手順N/急所N-M) の導出 (読み取り専用) |
diff --git a/tests/Unit/Manual/SopTextExtractorTest.php b/tests/Unit/Manual/SopTextExtractorTest.php
index 05e33eb..2e79eac 100644
--- a/tests/Unit/Manual/SopTextExtractorTest.php
+++ b/tests/Unit/Manual/SopTextExtractorTest.php
@@ -8,11 +8,13 @@
 use Illuminate\Support\Facades\Storage;
 use PhpOffice\PhpSpreadsheet\Spreadsheet;
 use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
+use Webmozart\Assert\Assert;
 
 /*
  * SOP テキスト抽出 (施策 7):
  * - plain / xlsx の抽出、UTF-8 strict 検証 (SJIS 変換 / バイナリ拒否)
  * - 実質空 (min_text_bytes 未満) / バイト上限超過の明示エラー
+ * - SJIS 誤解釈 (pdfparser の CJK CMap 非対応) の区間単位復元 / 日本語本文ゲート (T096)
  */
 
 /** 保存済み SourceDocument (Storage::fake 上) を作る */
@@ -27,6 +29,32 @@ function storedDocument(string $contents, string $mime, string $ext): SourceDocu
     ]);
 }
 
+/**
+ * 同梱サンプル SOP の中身 (回帰コーパス)。
+ * fixture を複製せず参照するため、欠落は黙ってスキップせず明示的に失敗させる。
+ */
+function sampleSopContents(string $name): string
+{
+    $path = base_path("doc/reference/sample-sop/{$name}");
+    $contents = file_exists($path) ? file_get_contents($path) : false;
+    if (! is_string($contents)) {
+        throw new RuntimeException("回帰コーパスのサンプル SOP を読めません: {$path}");
+    }
+
+    return $contents;
+}
+
+/** CP932 バイト列を Windows-1252 として読んだときの化け (pdfparser が返すもの) を合成する */
+function sjisMojibake(string $japanese): string
+{
+    $sjis = mb_convert_encoding($japanese, 'CP932', 'UTF-8');
+    Assert::string($sjis);
+    $mojibake = mb_convert_encoding($sjis, 'UTF-8', 'CP1252');
+    Assert::string($mojibake);
+
+    return $mojibake;
+}
+
 test('plain テキストをそのまま抽出する (byteLength = strlen)', function (): void {
     Storage::fake();
     $text = str_repeat("手順1 部品を取り付ける\n", 10);
@@ -115,3 +143,158 @@ function storedDocument(string $contents, string $mime, string $ext): SourceDocu
     expect(fn () => app(SopTextExtractor::class)->extract($document))
         ->toThrow(AnalysisFailedException::class);
 });
+
+/*
+ * T096: SJIS 誤解釈の区間単位復元 + 日本語本文ゲート
+ */
+
+test('CP1252 として読まれた SJIS テキストは日本語へ復元される', function (): void {
+    Storage::fake();
+    $document = storedDocument(
+        sjisMojibake(str_repeat('作業手順書 ネジを締める 安全確認 保護メガネ着用。', 5)),
+        'text/plain',
+        'txt',
+    );
+
+    $extracted = app(SopTextExtractor::class)->extract($document);
+
+    expect($extracted->text)->toContain('ネジを締める');
+    expect($extracted->text)->toContain('保護メガネ');
+    expect($extracted->byteLength)->toBe(strlen($extracted->text));
+});
+
+test('正当な日本語テキストは 1 文字も変化しない', function (): void {
+    Storage::fake();
+    // normalize() で変化しない形 (連続空白・連続改行・前後空白なし) にして「復元による誤変換ゼロ」を固定する
+    $text = "作業手順書\n1. ネジを締める (トルク 5Nm)\n2. カバーを取り付ける\n"
+        ."3. 動作確認を行う\n安全: 保護メガネと手袋を着用する";
+    $document = storedDocument($text, 'text/plain', 'txt');
+
+    $extracted = app(SopTextExtractor::class)->extract($document);
+
+    expect($extracted->text)->toBe($text);
+});
+
+test('正当な日本語に CP1252 拡張文字が混在しても復元は発火しない (非 PDF)', function (): void {
+    Storage::fake();
+    // 復元段は媒体非依存だが、採否は 3 段検証 (CP1252 可逆 / SJIS-win 妥当 / 日本語が増える) が決める。
+    // 正当な CP1252 拡張文字 (é ö ß à € ™) を含む日本語手順書が 1 文字も変わらないことを固定する
+    // (Codex impl-review round 1 Warning 1 の回帰。実測は probe/probe-mixed-latin.php)
+    $text = "作業手順書 (Café ラインの Größe 点検)\n"
+        ."1. ネジを締める。トルクは 5Nm とする。\n"
+        ."2. カバーを取り付ける。€120 の部材と ™ 表示を確認する。\n"
+        .'備考: à la carte の設備は対象外とする。';
+    $document = storedDocument($text, 'text/plain', 'txt');
+
+    $extracted = app(SopTextExtractor::class)->extract($document);
+
+    expect($extracted->text)->toBe($text);
+});
+
+test('正当な欧文テキストは復元されず日本語不足で拒否される', function (string $text): void {
+    Storage::fake();
+    // いずれも analysis_min_text_bytes (100) 以上にして tooShort と競合させない
+    expect(strlen($text))->toBeGreaterThanOrEqual(100);
+    $document = storedDocument($text, 'text/plain', 'txt');
+
+    expect(fn () => app(SopTextExtractor::class)->extract($document))
+        ->toThrow(AnalysisFailedException::class, '十分な日本語の本文');
+})->with([
+    'en' => ['Work Instruction: 1. Tighten the screw to 5Nm. 2. Attach the cover plate. 3. Check the operation before use.'],
+    'de' => ['Arbeitsanweisung: Schraube mit 5Nm anziehen. Größe prüfen. Für die Straße. Öl nachfüllen. Weiß markieren.'],
+    'fr' => ['Mode opératoire: Serrer la vis à 5 Nm. Vérifier la référence arrière. Côté gauche. Contrôler après usage.'],
+]);
+
+test('復元は混在文書の正規日本語を壊さない', function (): void {
+    Storage::fake();
+    // 正しく decode された日本語 (AS の隠し OCR 層に相当) と mojibake の混在
+    $document = storedDocument(
+        '非鉄金属はその特性に応じた研削をする。'.sjisMojibake('作業手順書 ネジを締める 安全確認 保護メガネ着用'),
+        'text/plain',
+        'txt',
+    );
+
+    $extracted = app(SopTextExtractor::class)->extract($document);
+
+    expect($extracted->text)->toContain('非鉄金属');
+    expect($extracted->text)->toContain('ネジを締める');
+});
+
+test('日本語比率が閾値未満のテキストは拒否される (境界: 下側)', function (): void {
+    Storage::fake();
+    config()->set('manual.analysis_min_japanese_ratio', 0.10);
+    // 空白を除く 100 文字中 日本語 9 文字 = 0.09
+    $document = storedDocument(str_repeat('A', 91).'安全確認手順書作業', 'text/plain', 'txt');
+
+    expect(fn () => app(SopTextExtractor::class)->extract($document))
+        ->toThrow(AnalysisFailedException::class, '十分な日本語の本文');
+});
+
+test('日本語比率が閾値以上のテキストは通る (境界: 上側)', function (): void {
+    Storage::fake();
+    config()->set('manual.analysis_min_japanese_ratio', 0.10);
+    // 空白を除く 100 文字中 日本語 11 文字 = 0.11
+    $document = storedDocument(str_repeat('A', 89).'安全確認手順書作業前点', 'text/plain', 'txt');
+
+    $extracted = app(SopTextExtractor::class)->extract($document);
+
+    expect($extracted->sourceKind)->toBe('plain');
+});
+
+test('抽出結果が空の PDF は unextractable (tooShort と弁別)', function (): void {
+    Storage::fake();
+    $document = storedDocument(sampleSopContents('AP_オペレーション手順書.pdf'), 'application/pdf', 'pdf');
+
+    expect(fn () => app(SopTextExtractor::class)->extract($document))
+        ->toThrow(AnalysisFailedException::class, 'テキストを抽出できません');
+});
+
+test('空の text/plain は tooShort (画像未対応と弁別)', function (): void {
+    Storage::fake();
+    $document = storedDocument('', 'text/plain', 'txt');
+
+    expect(fn () => app(SopTextExtractor::class)->extract($document))
+        ->toThrow(AnalysisFailedException::class, '本文が短すぎます');
+});
+
+test('空の Spreadsheet は tooShort', function (): void {
+    Storage::fake();
+    $spreadsheet = new Spreadsheet;
+    $tmp = tempnam(sys_get_temp_dir(), 'sop-xlsx-');
+    Assert::string($tmp);
+    (new Xlsx($spreadsheet))->save($tmp);
+    $contents = file_get_contents($tmp);
+    @unlink($tmp);
+    Assert::string($contents, "一時 xlsx を読めません: {$tmp}"); // 失敗を空文字へ潰さない
+    $document = storedDocument(
+        $contents,
+        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
+        'xlsx',
+    );
+
+    expect(fn () => app(SopTextExtractor::class)->extract($document))
+        ->toThrow(AnalysisFailedException::class, '本文が短すぎます');
+});
+
+test('同梱サンプル SOP 5 本の抽出結果は期待値表どおりである', function (string $file, ?string $expectedError): void {
+    Storage::fake();
+    $document = storedDocument(sampleSopContents($file), 'application/pdf', 'pdf');
+
+    if ($expectedError !== null) {
+        expect(fn () => app(SopTextExtractor::class)->extract($document))
+            ->toThrow(AnalysisFailedException::class, $expectedError);
+
+        return;
+    }
+
+    $extracted = app(SopTextExtractor::class)->extract($document);
+    expect($extracted->sourceKind)->toBe('pdf');
+    expect($extracted->text)->toContain('グラインダー研削作業');
+    expect($extracted->text)->toContain('保護メガネ');
+})->with([
+    ['AP_オペレーション手順書.pdf', 'テキストを抽出できません'],
+    ['AT_作業手順書.pdf', 'テキストを抽出できません'],
+    ['作業要領書.pdf', 'テキストを抽出できません'],
+    ['AW_作業手順書 (1).pdf', '十分な日本語の本文'],
+    ['AS_作業手順書.pdf', null],
+]);
```

## 検証結果

- `vendor/bin/pint --test`: passed
- `composer phpstan` (level 10): No errors
- `vendor/bin/pest tests/Unit/Manual/SopTextExtractorTest.php`: 25 passed / 57 assertions
