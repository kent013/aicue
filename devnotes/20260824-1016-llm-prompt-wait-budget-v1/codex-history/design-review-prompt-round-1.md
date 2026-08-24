# 詳細設計レビュー依頼 (Round 1)

## アプリの使命 (North Star) — 絶対遵守

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

## 禁止事項

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)
## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 (ただし phpstan.neon の paths は app/config/database/routes で tests を含まない)
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）
11. Atomic Design準拠（UI/frontend 変更を含む場合）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

## 追加の文脈

- 本件は社内の複数リポジトリを横断する「機能台帳 (lctl)」が確定した正典 (canonical) v1 への追従作業である。正典 v1 の参照実装は姉妹リポジトリ spirux の `tests/Support/PromptWaitBudget.php` + `tests/Architecture/PromptYamlContractTest.php`。aicue は「判定を gate ファイル内にインラインで持つひな形形 (t1)」であり、単一読み取り器 / 検出器の負例自己テスト / 分母の到達証明の 3 点が欠けている。
- 概念設計は Codex (gpt-5.6-terra) の 3 ラウンドで APPROVED 済み。争点だった公開面 (evaluate() を private 化し未検査の timeout を外へ出さない) と失敗分類 (ファイル不在を独立ラベルにする) は決着済みである。
- 変更はすべて `tests/` と `docs/` であり、アプリ実行コード・DB・UI・API・route は 1 行も変わらない。

---

## 詳細設計書

# 詳細設計: llm-prompt-wait-budget-v1 (LLM 待ち予算の読み取り規則を単一化する)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

> 本施策はすべて `tests/` と `docs/` の変更であり、2・4・5・6・7・8 に触れる面を持たない。
> 1 に対しては「負例で検出力を裏取りした自己テスト」までを完了条件にする。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）。ただし `phpstan.neon` の `paths` は
  `app` `config` `database` `routes` であり **`tests` を含まない**。本設計の変更は
  すべて `tests/` 配下なので**静的解析の対象外**である。
  「level 10 が通っている」を「本変更の型が保証されている」と読み替えない
  (代わりに PHPDoc の array shape と、呼び出し側に解釈を残さない構造で担保する)
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止）
- テストデータは必ず Factory で生成（本設計では DB を使う施策は無い）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）
- `declare(strict_types=1)` + 日本語コメント（`StrictTypesDeclarationGateTest` が
  git 追跡下の PHP 全数を deny-by-default で強制する = 新規 PHP 2 本にも必須）
- PHP 8.4 + Laravel 12

### 本設計に固有の強制規約 (AGENTS.md)

- **「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」の適用対象**である
  (判定条件と走査結果の使い方を変える)。
  (1) 負例と正例をテストファーストで先に赤くする / (2) 解決できない形を落とす分岐 /
  (3) 走査が空振りしていないことの検査 / (4) docblock に走査対象と保証しないものを書く。
- 「静的検査 (gate) と走査器の共通規約」5 条のうち適用されるのは
  **(b) fail-closed / (c) 負例での裏取り / (d) 使わない収集を作らない** の 3 条。
  (a) はクラス名・名前参照を解決しないので無関係、(e) は語彙一致を判定しないので無関係。
- 「後方互換の並走を残さない」(思考原則 3): 旧 3 実装は**同じ PR で削除する**。
  移行期間・feature flag・旧関数の残置はいずれも作らない。

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md) (Codex `gpt-5.6-terra` Round 3 で APPROVED)
- Codex 議論履歴: `conceptual-review-round-{1,2,3}.md` /
  `codex-history/conceptual-review-{prompt,decisions}-round-*.md`
- 正典 (lctl feature `llm-prompt-wait-budget` / canonical v1) の参照実装:
  `spirux:tests/Support/PromptWaitBudget.php` + `spirux:tests/Architecture/PromptYamlContractTest.php`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 検出器の自己テストと見本ファイル (先に赤くする) | `tests/Unit/Architecture/PromptWaitBudgetTest.php` (新規) / `tests/Architecture/fixtures/prompt-wait-budget/*.yaml` (新規 12 本) | 最高 |
| 2 | 単一読み取り器 `PromptWaitBudget` の新設 | `tests/Support/PromptWaitBudget.php` (新規) | 最高 |
| 3 | gate の書き換え (インライン判定の削除) と分母の到達証明 | `tests/Architecture/PromptClientTimeoutInvariantTest.php` | 高 |
| 4 | 下限式側の読み出しを読み取り器へ寄せる | `tests/Support/AnalysisBudget.php` | 高 |
| 5 | 素の配列参照を読み取り器へ寄せる | `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` | 高 |
| 6 | 乖離台帳への登録 (D50) と件数 pin の更新 | `docs/template-divergence.md` / `tests/Support/TemplateDivergence/LedgerPins.php` | 高 (施策 3 と同一 PR 必須) |
| 7 | 実効性の運用契約への 3 行追記 | `docs/architecture.md` | 中 |

**波及の全体像 (施策横断)**

- TypeScript 型定義: **なし** (フロントに一切触れない)
- Inertia Props / API Resource / DTO: **なし** (アプリ実行コードに触れない)
- migration / DB: **なし**
- 既存テストファイルの更新: 施策 3 / 5 (書き換え)。
  **削除する既存テストは無い** (test の本数は施策 3 で 1 → 2 に増える)
- `resources/prompts/*.yaml` の内容: **変更しない** (値は 360 / 60 のまま)

---

## 施策 1: 検出器の自己テストと見本ファイル (先に赤くする)

### 変更箇所

- 新規: `tests/Unit/Architecture/PromptWaitBudgetTest.php`
- 新規: `tests/Architecture/fixtures/prompt-wait-budget/` に 12 本の見本 YAML

置き場の根拠: AGENTS.md「負例の置き場は 3 通りとも認める」のうち
**見本ファイル (`tests/Architecture/fixtures/`) + 検出器の自己検査 (`tests/Unit/Architecture/`)**
の組み合わせを採る (既存の `ForbiddenStatementScannerTest` /
`StrictTypesDeclarationScannerTest` と同じ置き方)。
見本を**実ファイル**にするのは、`violations()` が「ファイルを読む口」であり、
parse の委譲まで含めて 1 本の経路として裏取りするためである。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本施策が新設する 1 本のみ

### 見本ファイルの一覧 (12 本)

`tests/Architecture/fixtures/prompt-wait-budget/` 配下。**拡張子は `.yaml`** のままにする
(リポジトリ全数を走査する gate のうち `.yaml` を見るのは
`PasswordConfirmSurfaceAbsenceGateTest` だけで、その走査根は
`.github app bootstrap config lang resources routes scripts` の 8 つで **`tests` を含まない**。
`PromptYaml::paths()` の走査根は `resources/prompts` なので見本は分母に入らない)。

| ファイル | 中身 | 期待 |
|---|---|---|
| `missing-client-options.yaml` | `name: x` のみ | 違反 (未宣言) |
| `client-options-not-array.yaml` | `client_options: 300` | 違反 (非配列) |
| `missing-timeout.yaml` | `client_options:` に `retries: 1` だけ | 違反 (キー無し) |
| `zero.yaml` | `timeout: 0` | 違反 (非正) |
| `negative.yaml` | `timeout: -1` | 違反 (非正) |
| `numeric-string.yaml` | `timeout: "300"` | 違反 (非 int) |
| `float.yaml` | `timeout: 300.5` | 違反 (非 int) |
| `bool.yaml` | `timeout: true` | 違反 (非 int) |
| `null.yaml` | `timeout:` (空値) | 違反 (非 int) |
| `declared.yaml` | `timeout: 300` | **正例** (違反 0 件) |
| `broken.yaml` | 構文不正 (`client_options: [unclosed`) | 違反 (parse 不能) |
| `list-top-level.yaml` | `- a` / `- b` (最上位が list) | 違反 (非 map) |

### 自己テストの内容 (5 本の test)

```php
<?php

declare(strict_types=1);

use Tests\Support\PromptWaitBudget;

/*
 * `Tests\Support\PromptWaitBudget` の検出力を**両方向**で固定する (AGENTS.md 共通規約 (c))。
 *
 * ★本自己テストが本読み取り器の存在理由である。待ち予算の判定を各 gate へインラインで
 *   書くと「0 以下を見ていない実装」が混ざっても誰も気付けない
 *   (実際に AnalysisBudget::clientTimeoutSecondsFromYaml() が `timeout: 0` を通していた)。
 *
 * 見本ファイル: tests/Architecture/fixtures/prompt-wait-budget/
 */

/** 見本ディレクトリの絶対パス。 */
function promptWaitBudgetFixtureDir(): string
{
    return base_path('tests/Architecture/fixtures/prompt-wait-budget');
}

test('待ち予算の 9 類型の違反がラベルの集合として全部上がる', function (): void {
    $bad = [
        'missing-client-options.yaml', 'client-options-not-array.yaml', 'missing-timeout.yaml',
        'zero.yaml', 'negative.yaml', 'numeric-string.yaml', 'float.yaml', 'bool.yaml', 'null.yaml',
    ];

    // ★件数ではなく**ラベルの集合**で照合する (1 件取りこぼして別の 1 件を二重報告しても
    //   件数だけは一致してしまう = 偽の緑)。
    $labels = [];
    foreach ($bad as $name) {
        foreach (PromptWaitBudget::violations(promptWaitBudgetFixtureDir().'/'.$name, $name) as $violation) {
            $label = strstr($violation, ': ', true);
            expect($label)->not->toBeFalse("違反メッセージにラベルがありません: {$violation}");
            $labels[] = (string) $label;
        }
    }

    sort($labels);
    $expected = $bad;
    sort($expected);
    expect($labels)->toBe($expected);
});

test('正例 (正の整数を宣言した見本) を誤検出しない', function (): void {
    expect(PromptWaitBudget::violations(promptWaitBudgetFixtureDir().'/declared.yaml', 'declared.yaml'))
        ->toBe([]);
});

test('解決できない形は 3 種それぞれが違反になる (fail-closed)', function (): void {
    // ★1 件だけ確かめて「解決不能形は落ちる」と主張しない。分岐は 3 つある。
    $unresolvable = [
        'ファイル不在' => promptWaitBudgetFixtureDir().'/does-not-exist.yaml',
        'parse 不能' => promptWaitBudgetFixtureDir().'/broken.yaml',
        '最上位が map でない' => promptWaitBudgetFixtureDir().'/list-top-level.yaml',
    ];

    foreach ($unresolvable as $kind => $path) {
        $violations = PromptWaitBudget::violations($path, basename($path));
        expect($violations)->not->toBe([], "{$kind} が違反として上がっていません");
        // メッセージの文面は pin しない (どのファイルの話かだけを固定する)
        expect(implode(PHP_EOL, $violations))->toContain(basename($path));
    }
});

test('requirePositive は違反があれば例外にする (違反を無視しない)', function (): void {
    expect(fn (): int => PromptWaitBudget::requirePositive(
        promptWaitBudgetFixtureDir().'/zero.yaml', 'zero.yaml',
    ))->toThrow(RuntimeException::class);
});

test('requirePositive は正常な見本から正の整数を返す', function (): void {
    expect(PromptWaitBudget::requirePositive(
        promptWaitBudgetFixtureDir().'/declared.yaml', 'declared.yaml',
    ))->toBe(300);
});
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`function promptWaitBudgetFixtureDir(): string`)
- [x] null 安全 (`strstr()` の `false` を `expect(...)->not->toBeFalse()` で明示的に落とす)
- [x] DTO を返している — **非該当** (テスト支援コードであり HTTP 応答を作らない)
- [x] Generics の型パラメータ — 非該当
- 備考: `tests` は `phpstan.neon` の `paths` 外。型注釈は IDE と将来の解析のために書く

### テスト計画

- [x] **これがテストファーストの「先に赤くする」段**である。施策 2 の前に書き、
      `Tests\Support\PromptWaitBudget` が存在しないことで **5 本すべてが赤**になることを確認する
- [x] 施策 2 の後は 5 本すべて緑
- [x] **負のコントロールの裏取り**: 施策 2 の完成後に読み取り器の
      `<= 0` 分岐を一時的に削り、`zero.yaml` / `negative.yaml` のラベルが集合から落ちて
      **1 本目の test が赤くなる**ことを確認する (確認したら戻す)。
      同じく `is_int()` を `is_numeric()` へ緩めると `numeric-string` / `float` / `bool` が落ちて赤になる。
      実測の記録は `devnotes/20260824-1016-llm-prompt-wait-budget-v1/red-green-log.md` に残す
- [x] 個別の `DatabaseTransactions` を使っていない (DB を使わない)

### リスク

- 見本の `broken.yaml` は**意図的に構文が壊れた YAML** である。リポジトリに YAML linter は
  無い (CI / `pnpm lint` / prettier のいずれも `*.yaml` を検査しない) ので副作用は無いが、
  エディタが警告を出す。ファイル冒頭に「意図的に壊してある」コメントを置く
  (コメント行は parse 失敗の前に読まれないので判定には影響しない)

---

## 施策 2: 単一読み取り器 `PromptWaitBudget` の新設

### 変更箇所

- 新規: `tests/Support/PromptWaitBudget.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 施策 1 (自己テスト) / 施策 3・4・5 (呼び出し側)

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * prompt YAML が宣言する「LLM の待ち予算」(`client_options.timeout`) の**唯一の読み取り器**。
 *
 * lctl 機能台帳 feature `llm-prompt-wait-budget` の正典 v1 (単一読み取り器 + 検出器自己テスト形)。
 * 設計: devnotes/20260824-1016-llm-prompt-wait-budget-v1/
 *
 * 【走査対象】呼び出し側が渡した prompt YAML の絶対パス 1 本。
 *   分母の列挙 (再帰全数走査) は `Tests\Support\PromptYaml::paths()` の責務であり、
 *   本クラスは**列挙しない** (判定だけを持つ)。
 *
 * 【既定拒否】次はすべて違反である。
 *   1. `client_options` が無い (未宣言)
 *   2. `client_options` が配列でない
 *   3. `client_options.timeout` キーが無い
 *   4. `timeout` が `is_int()` でない (数値文字列 `"300"` / 小数 / 真偽値 / 空値を含む)
 *   5. `timeout` が `<= 0`
 *
 * 【公開面】用途の違う 2 口だけを公開し、**どちらも検査済みの結果しか返さない**。
 *   未検査の `timeout` を外へ出す口を作らないので、呼び出し側に
 *   `is_int()` / `> 0` の再判定 (= 第 4 の読み取り実装) が生まれない。
 *   - `violations()`: gate 用。違反ラベルの列 (空なら適合)
 *   - `requirePositive()`: 仕様値との突合用。違反が 1 件でもあれば例外、適合なら正の整数
 *   判定の純関数 `evaluate()` は private である (自己テストは公開 2 口を通して行う)。
 *
 * 【解決できない形は落とす (fail-closed)】3 段のどこでも**無言で null を返さない**。
 *   段 1: ファイルが無い → 独立したラベル。`Yaml::parseFile()` は不在も `ParseException`
 *         にするため、段 2 に混ぜると「構文が壊れている」と区別できなくなる。
 *         走査由来のパスでは起きないが `requirePositive()` は名前から組んだパスを受ける
 *         (prompt の改名で現実に起きる)。
 *   段 2: parse 不能 / 最上位が map でない → `PromptYaml::parseOrFail()` が積む既存の 2 ラベル。
 *         **本クラスは自前の `catch` を書かない** (無差別な `Throwable` 捕捉は
 *         テストコード自身のバグまで契約違反へ潰す)。
 *   段 3: 上記 5 類型 → `evaluate()` が積む。
 *
 * 【保証しないもの (誇張しない)】
 *   - **宣言値が実効値であることは主張しない**。見るのは宣言の有無と型と正負だけである。
 *     実効性は 3 つの前提に依存し、**そのどれも本クラスは見ていない** —
 *     (i) `app/Prompts/` の factory が vendor の `$clientOptions` クラスプロパティを
 *     設定しないこと、(ii) `resources/prompts` を読む非 PHP の実装が無いこと、
 *     (iii) vendor の解決順序が「クラスプロパティ > YAML > config」であること。
 *     2026-08-24 に実読で確認した事実であり、機械では見ていない。
 *     崩れたときの手当ては `docs/architecture.md` §AI 解析ジョブの運用契約 に書いてある。
 *   - **待ち予算の値の妥当性は判定しない** (360 秒が適切かは本クラスの範囲外)。
 *   - **4 本目の読み取り実装が生まれることは機械では止めない**。字句走査では
 *     「読み取り実装」と失敗メッセージ中の文字列を区別できず、区別のために目録へ
 *     メッセージ側まで登録すると保護しないのに更新が要る目録になるため作らない。
 *     唯一の読み取り器であることは本 docblock の宣言とレビューが担う。
 *   - 段 2 のラベルは共有ヘルパが**絶対パス**で積む (段 1・段 3 は `$label`)。
 *     ラベル集合での照合は段 3 に対してのみ成立する。
 */
final class PromptWaitBudget
{
    /** インスタンス化しない (判定の置き場)。 */
    private function __construct() {}

    /**
     * 待ち予算の契約違反 (gate 用)。適合なら空配列。
     *
     * @param  string  $absolutePath  読む YAML の絶対パス
     * @param  string  $label  違反メッセージに出す識別子 (走査根からの相対パス等)
     * @return list<string>
     */
    public static function violations(string $absolutePath, string $label): array
    {
        return self::read($absolutePath, $label)['violations'];
    }

    /**
     * 仕様値との突合に使う正の整数。違反が 1 件でもあれば例外にする。
     *
     * ★`violations()` と**同じ private 判定**を通るので、「gate は落とすが突合は通る」
     *   食い違いが構造的に起きない。
     *
     * @throws RuntimeException 契約違反 (未宣言 / 型違い / 非正 / 解決不能)
     */
    public static function requirePositive(string $absolutePath, string $label): int
    {
        $result = self::read($absolutePath, $label);

        if ($result['violations'] !== []) {
            throw new RuntimeException(
                'LLM の待ち予算の宣言が契約を満たしていません: '.implode(' / ', $result['violations']),
            );
        }

        $timeout = $result['timeout'];
        if ($timeout === null) {
            // 到達しない (違反 0 件なら evaluate() は必ず int を返す)。
            // 到達したら読み取り器の不変条件が壊れているので黙って 0 を返さず落とす。
            throw new RuntimeException("待ち予算の読み取りが不整合です: {$label}");
        }

        return $timeout;
    }

    /**
     * 読み取りの 3 段 (ファイル存在 → parse → 判定)。
     *
     * @return array{timeout: int|null, violations: list<string>}
     */
    private static function read(string $absolutePath, string $label): array
    {
        if (! is_file($absolutePath)) {
            return self::rejected("{$label}: prompt YAML が無い ({$absolutePath})");
        }

        /** @var list<string> $parseErrors */
        $parseErrors = [];
        $parsed = PromptYaml::parseOrFail($absolutePath, $parseErrors);
        if ($parsed === null) {
            return ['timeout' => null, 'violations' => $parseErrors];
        }

        return self::evaluate($parsed, $label);
    }

    /**
     * parse 済みの YAML から待ち予算と契約違反を返す (判定の純関数)。
     *
     * @param  array<string, mixed>  $parsed
     * @return array{timeout: int|null, violations: list<string>}
     */
    private static function evaluate(array $parsed, string $label): array
    {
        if (! array_key_exists('client_options', $parsed)) {
            return self::rejected("{$label}: client_options が無い (LLM の待ち予算が未宣言)");
        }

        // 配列 offset 式のままだと narrowing が保たれないためローカル変数へ移す
        $options = $parsed['client_options'];
        if (! is_array($options)) {
            return self::rejected(sprintf(
                '%s: client_options が配列ではない (%s)', $label, get_debug_type($options),
            ));
        }

        if (! array_key_exists('timeout', $options)) {
            return self::rejected("{$label}: client_options.timeout が無い (LLM の待ち予算が未宣言)");
        }

        $timeout = $options['timeout'];
        if (! is_int($timeout)) {
            return self::rejected(sprintf(
                '%s: client_options.timeout が整数ではない (%s)。数値文字列 ("300") も許さない',
                $label, get_debug_type($timeout),
            ));
        }

        if ($timeout <= 0) {
            return self::rejected(sprintf(
                '%s: client_options.timeout が %d (正の整数でなければならない)', $label, $timeout,
            ));
        }

        return ['timeout' => $timeout, 'violations' => []];
    }

    /** @return array{timeout: null, violations: list<string>} */
    private static function rejected(string $message): array
    {
        return ['timeout' => null, 'violations' => [$message]];
    }
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (public 2 本は `array` / `int`、shape は PHPDoc)
- [x] null 安全: `requirePositive()` は `null` を黙って 0 に落とさず例外にする
      (spirux で実害が出た「`?? 0` で fail-open」の再発経路を型ではなく分岐で塞ぐ)
- [x] DTO を返している — 非該当 (テスト支援。array shape を PHPDoc で固定)
- [x] Generics — 非該当

### テスト計画

- [x] 施策 1 の自己テスト 5 本が緑になること
- [x] 新規テストは書かない (本施策は施策 1 のテストを緑にする実装側)
- [x] `declare(strict_types=1)` があること (`StrictTypesDeclarationGateTest` が強制)

### リスク

- `PromptYaml::parseOrFail()` に依存するため、同ヘルパの**採用時債務としての凍結**に縛られる
  (`adoption-debt.tsv` にあるので内容を変えられない)。今回は読むだけなので影響なし。
  将来ヘルパ側を直す必要が出たら、そのとき 3 択 (戻す / テンプレートへ同期 / 逸脱登録) を判断する

---

## 施策 3: gate の書き換えと分母の到達証明

### 変更箇所

- `tests/Architecture/PromptClientTimeoutInvariantTest.php` (全面書き換え。42 行)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本ファイル自身
- **乖離台帳**: 本ファイルは `docs/template-fingerprints.json` のキーに在り、
  かつ現在テンプレートと**一致**している (app 側 sha256 `3e2cd834…` = 台帳の指紋)。
  書き換えると `TemplateDivergenceFingerprintTest` が
  「一致していた状態から新たに不一致になった、未登録かつ非債務のパス」として落とす。
  → **施策 6 (D50 登録 + 件数 pin) を同じ PR で必ず行う**

### 現行コード

```php
use Tests\Support\PromptYaml;

test('全 prompt YAML が client_options.timeout (>0) を宣言する', function (): void {
    $files = PromptYaml::paths();

    expect($files)->not->toBeEmpty();

    $violations = [];
    foreach ($files as $file) {
        $parseErrors = [];
        $parsed = PromptYaml::parseOrFail($file, $parseErrors);
        if ($parsed === null) {
            array_push($violations, ...$parseErrors);

            continue;
        }
        if (! array_key_exists('client_options', $parsed) || ! is_array($parsed['client_options'])) {
            $violations[] = "{$file}: client_options (map) がありません";

            continue;
        }
        $timeout = $parsed['client_options']['timeout'] ?? null;
        if (! is_int($timeout) || $timeout <= 0) {
            $violations[] = "{$file}: client_options.timeout は正の int で宣言してください";
        }
    }

    expect($violations)->toBe([], /* ... */);
});
```

### 変更後コード

```php
<?php

declare(strict_types=1);

use Tests\Support\PromptWaitBudget;
use Tests\Support\PromptYaml;

/*
 * LLM provider のハング対策として、全 prompt YAML が client_options.timeout (>0 の int) を
 * 宣言する不変条件を固定する。prism-prompt は YAML metadata の client_options を Prism
 * リクエストへ渡すため、これにより provider 無応答時に明示 timeout で打ち切られる。
 * 宣言を落とすと実効値は config/prism.php の request_timeout (30 秒) へ縮み、
 * 360 秒前提の時間 budget 連鎖 (AnalysisTimeBudgetInvariantTest) が黙って途中で切れる。
 *
 * 【走査対象】`PromptYaml::paths()` = resources/prompts 配下の *.yaml / *.yml を再帰全数
 *   (大文字拡張子も拾う)。0 件は失敗にする。
 * 【判定の正本】`Tests\Support\PromptWaitBudget` **1 箇所**である。
 *   待ち予算を読む検査 (本 gate / AnalysisTimeBudgetInvariantTest /
 *   AnalysisTokenBudgetInvariantTest) はすべて同じ読み取り器を参照する
 *   (同じ規則を 2 実装持つと、片方だけが緩んでも気付けない)。
 *   検出力の裏取り (負例 9 類型 + 正例 + 解決不能形 3 種) は
 *   tests/Unit/Architecture/PromptWaitBudgetTest.php が持つ。
 *
 * 【この gate が保証しないもの (誇張しない)】
 *  1. **宣言値が実効値であること**は見ない (読み取り器の docblock が正本)。
 *  2. **走査の再帰そのものの検出力は裏取りしていない**。`PromptYaml::paths()` は
 *     探索根を引数で受けず `base_path('resources/prompts')` を直接見るため、見本
 *     ディレクトリを食わせられない。テスト中に resources/prompts へ一時ファイルを作る形は
 *     同じ分母を見る他の 3 gate (PromptYamlContractTest / DefensiveInstructionsPresenceTest /
 *     PromptDefenseWindowGateTest) を汚すので採らない。**実データにも
 *     サブディレクトリが無い**ので、再帰が壊れても本 gate は気付かない。
 *  3. 到達証明は**既知 5 本の包含**である。新規 prompt が分母に入ることは
 *     再帰全数走査 (既定拒否) の側が受け持ち、本証明は保証しない。
 */

/** 走査根 (resources/prompts) からの相対パス。違反ラベルに使う。 */
function promptWaitBudgetLabel(string $absolutePath): string
{
    $prefix = rtrim(base_path('resources/prompts'), '/').'/';
    $normalized = str_replace(DIRECTORY_SEPARATOR, '/', $absolutePath);

    return str_starts_with($normalized, $prefix) ? substr($normalized, strlen($prefix)) : $normalized;
}

/**
 * 分母に必ず在る既知の prompt (到達証明)。
 *
 * ★件数の pin ではなく**包含**である。新規 prompt の追加でこの一覧を直す必要は無く、
 *   既知の 1 本が消えた・改名された・走査根が別物になったときだけ赤くなる。
 *   意図した削除なら同じ PR でこの一覧を直す。
 */
const PROMPT_WAIT_BUDGET_REQUIRED_LABELS = [
    'example-summary.yaml',
    'scenario-generation.yaml',
    'sop-extract-media.yaml',
    'sop-extract.yaml',
    'work-decomposition.yaml',
];

test('走査が既知の prompt YAML 全数へ到達している (分母の到達証明)', function (): void {
    $labels = array_map(promptWaitBudgetLabel(...), PromptYaml::paths());

    expect($labels)->not->toBeEmpty();

    $missing = array_values(array_diff(PROMPT_WAIT_BUDGET_REQUIRED_LABELS, $labels));

    expect($missing)->toBe([],
        '走査が既知の prompt YAML に到達していません (走査根の改名・移動か再帰の破損)。'
        .PHP_EOL.'不足: '.implode(', ', $missing));
});

test('全 prompt YAML が client_options.timeout (>0 の int) を宣言する', function (): void {
    $violations = [];
    foreach (PromptYaml::paths() as $file) {
        array_push($violations, ...PromptWaitBudget::violations($file, promptWaitBudgetLabel($file)));
    }

    expect($violations)->toBe([],
        'client_options.timeout invariant に違反があります (provider 無応答時に打ち切れない)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
```

**削除するもの (後方互換を残さない)**: インライン判定 3 分岐 (`client_options` の map 検査 /
`['timeout'] ?? null` / `is_int` && `> 0`) と、そこでの `parseOrFail` の直呼び。
`PromptYaml::parseOrFail()` の呼び出しは読み取り器の内側へ移る。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`promptWaitBudgetLabel(): string`)
- [x] null 安全 (`array_diff` / `array_map` はいずれも null を返さない)
- [x] DTO / Generics — 非該当

### テスト計画

- [x] **先に赤くする**: 到達証明の test を追加した直後に
      `PROMPT_WAIT_BUDGET_REQUIRED_LABELS` へ実在しない名前 (`sop-extract-v9.yaml`) を
      一時的に足し、赤になることを確認する (確認したら戻す)。
      これが AGENTS.md「既存の抽出器を流用して最初から緑になる場合は、負例が押さえる分岐を
      一時的に壊して赤を確認する」の実施である
- [x] 既存 test の**更新** (名前は据え置き。判定の中身を読み取り器へ委譲)
- [x] 新規 test: 「走査が既知の prompt YAML 全数へ到達している (分母の到達証明)」
      — 走査根の改名・移動・再帰破損を検出する
- [x] 既存の 5 本の prompt YAML は現状すべて適合しているので緑のまま
- [x] 個別の `DatabaseTransactions` を使っていない (Architecture lane は DB を使わない)

### リスク

- Pest のファイルスコープ関数・const は**テストファイル間で衝突しうる**。
  名前を `promptWaitBudgetLabel` / `PROMPT_WAIT_BUDGET_REQUIRED_LABELS` と長く取り、
  実装時に `grep -rn "promptWaitBudgetLabel\|PROMPT_WAIT_BUDGET_REQUIRED_LABELS" tests/` で
  0 件を確認する (施策 1 の `promptWaitBudgetFixtureDir` も同様)
- 到達証明は「既知 5 本」を literal で持つので、**prompt の意図的な削除・改名で赤くなる**。
  これは仕様である (削除するなら同じ PR でこの一覧を直す)

---

## 施策 4: 下限式側の読み出しを読み取り器へ寄せる

### 変更箇所

- `tests/Support/AnalysisBudget.php` L41-64 (`clientTimeoutSecondsFromYaml()`) と
  `use` 2 行 (`Symfony\Component\Yaml\Yaml` / `Webmozart\Assert\Assert`)

これが正典の**構造依存要件**「依頼ごとの上限をジョブの持ち時間の下限式にも
同じ読み取り器で参照させる」への対応である。aicue の下限式は
`DEADLINE_SECONDS = STAGE_COUNT × CLIENT_TIMEOUT_SECONDS` と
`RunManualAnalysis::$timeout >= D + C + M₁ + S` で、その YAML 側の突合が本メソッドである。

### 波及変更

- 呼び出し側 2 本 (`AnalysisTimeBudgetInvariantTest` L60 / `AnalysisTokenBudgetInvariantTest` L58)
  は**シグネチャが変わらないので無変更**(`array<string, int>` を返す契約は維持)
- TypeScript 型定義 / API Resource / DTO: なし
- **`docs/template-fingerprints.json` のキーに無い** = テンプレートと共有しないファイル。
  乖離台帳の操作は不要 (`adoption-debt.tsv` にも無い)

### 現行コード

```php
use Symfony\Component\Yaml\Yaml;
use Webmozart\Assert\Assert;

    public static function clientTimeoutSecondsFromYaml(): array
    {
        $timeouts = [];
        foreach (self::PROMPT_NAMES as $name) {
            $yaml = Yaml::parseFile(resource_path("prompts/{$name}.yaml"));
            Assert::isArray($yaml, "{$name}.yaml が map ではありません");
            Assert::keyExists($yaml, 'client_options', "{$name}.yaml に client_options がありません");

            $clientOptions = $yaml['client_options'];
            Assert::isArray($clientOptions, "{$name}.yaml の client_options が map ではありません");
            Assert::keyExists($clientOptions, 'timeout', "{$name}.yaml に client_options.timeout がありません");

            $timeout = $clientOptions['timeout'];
            Assert::integer($timeout, "{$name}.yaml の client_options.timeout が int ではありません");

            $timeouts[$name] = $timeout;
        }

        return $timeouts;
    }
```

**この実装の穴**: `Assert::integer()` までしか見ないので **`timeout: 0` と負数を通す**。
`AnalysisTimeBudgetInvariantTest` は `toBe(360)` で比較するので結果的に落ちるが、
落ちる理由が「値が違う」であって「非正である」ではない。
下限式が別の C を採る形へ変わった瞬間に穴が開く。

### 変更後コード

```php
/**
 * AI 解析の時間 budget 不変条件で使う「仕様値」と、prompt YAML からの実測読み出し。
 *
 * **CLIENT_TIMEOUT_SECONDS は仕様値であり、YAML から導出しない**。
 * これは意図的な重複である: YAML と仕様値を突き合わせることで初めて
 * 「YAML を勝手に変えた」ことを検出できる (YAML から導出すると同時変更で素通りする)。
 * 統一したのは**読み取り規則だけ**であって、値の出所は二重のままである。
 */

    /**
     * prompt YAML から読んだ client_options.timeout (プロンプト名 => 値)。
     *
     * ★読み取り規則の正本は `Tests\Support\PromptWaitBudget` 1 箇所である
     *   (未宣言 / 非配列 / キー無し / 非 int / 非正 をすべて例外にする)。
     *   ここに `Assert::integer()` 相当を書き戻さないこと — 以前の実装は
     *   `timeout: 0` を通していた。
     *
     * @return array<string, int>
     */
    public static function clientTimeoutSecondsFromYaml(): array
    {
        $timeouts = [];
        foreach (self::PROMPT_NAMES as $name) {
            $timeouts[$name] = PromptWaitBudget::requirePositive(
                resource_path("prompts/{$name}.yaml"),
                "{$name}.yaml",
            );
        }

        return $timeouts;
    }
```

`use Symfony\Component\Yaml\Yaml;` と `use Webmozart\Assert\Assert;` は**削除**する
(同ファイルの他の場所で使っていないことを実装時に確認する)。
`use Tests\Support\PromptWaitBudget;` は不要 — 同一 namespace `Tests\Support` にあるため。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`@return array<string, int>` 維持)
- [x] null 安全: `requirePositive()` が int を返すので narrowing 用のローカル変数退避も不要になる
- [x] DTO / Generics — 非該当

### テスト計画

- [x] 既存テストの更新は**不要** (シグネチャ不変)。既存 2 gate が緑のままであることを確認する:
      - `AnalysisTimeBudgetInvariantTest`「解析 3 プロンプトの client timeout が仕様値 (C) と一致する」
      - `AnalysisTokenBudgetInvariantTest`「解析プロンプト YAML の client timeout は時間 budget の仕様値 C と一致する」
- [x] 穴が閉じたことの裏取りは施策 1 の自己テスト (`zero.yaml` → `requirePositive()` が例外)
      が担う。**実 prompt YAML を一時的に壊して確認する形は採らない**
      (同じ分母を見る 4 gate が同時に赤くなり、何を確かめたのか分からなくなる)

### リスク

- `PROMPT_NAMES` の 3 本のいずれかが改名されると、以前は `Yaml::parseFile()` の
  `ParseException` (vendor 由来) で落ちていたのが、今後は
  `RuntimeException`「prompt YAML が無い」で落ちる。**どちらも赤になる**ので
  検出力は落ちない (メッセージが読みやすくなる)

---

## 施策 5: 素の配列参照を読み取り器へ寄せる

### 変更箇所

- `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` L128-137
  (最後の test「`sop-extract-media.yaml` の max_tokens / client timeout も解析 3 段と
  同じ仕様値に揃っている」) の client timeout 側 1 行

### 波及変更

- TypeScript 型定義 / API Resource / DTO: なし
- テストファイル: 本ファイル自身。**指紋台帳のキーに無い**ので乖離台帳の操作は不要
- `AnalysisBudget::PROMPT_NAMES` は**変えない** — `STAGE_COUNT = 3` と対の
  「解析パイプラインの 3 段」であり、OCR 変種 (`sop-extract-media`) を混ぜると
  `DEADLINE_SECONDS = 3C` の意味が崩れる (思考原則 4「別物を似ているからで統合しない」)

### 現行コード

```php
test('sop-extract-media.yaml の max_tokens / client timeout も解析 3 段と同じ仕様値に揃っている', function (): void {
    $yaml = Yaml::parseFile(resource_path('prompts/sop-extract-media.yaml'));
    expect($yaml)->toBeArray();
    expect($yaml['max_tokens'] ?? null)->toBe(OUTPUT_RESERVE_TOKENS);
    expect($yaml['client_options']['timeout'] ?? null)->toBe(AnalysisBudget::CLIENT_TIMEOUT_SECONDS);
});
```

**この実装の穴**: 未宣言 (`null`) と「宣言はあるが値が違う」が同じ失敗に潰れる。
`toBe()` の失敗メッセージからは「書き忘れ」なのか「値の不一致」なのか読めない。

### 変更後コード

```php
test('sop-extract-media.yaml の max_tokens / client timeout も解析 3 段と同じ仕様値に揃っている', function (): void {
    $yaml = Yaml::parseFile(resource_path('prompts/sop-extract-media.yaml'));
    expect($yaml)->toBeArray();
    expect($yaml['max_tokens'] ?? null)->toBe(OUTPUT_RESERVE_TOKENS);

    // 待ち予算の読み取り規則の正本は Tests\Support\PromptWaitBudget 1 箇所である
    // (未宣言・非正・非 int はここへ来る前に例外になる)。素の配列参照を書き戻さないこと。
    expect(PromptWaitBudget::requirePositive(
        resource_path('prompts/sop-extract-media.yaml'), 'sop-extract-media.yaml',
    ))->toBe(
        AnalysisBudget::CLIENT_TIMEOUT_SECONDS,
        'sop-extract-media.yaml の client_options.timeout が時間 budget の C と不一致',
    );
});
```

`use Tests\Support\PromptWaitBudget;` を追記する。`Yaml` の import は
`max_tokens` / provider / model 側でまだ使うので残す。

### PHPStan適合チェック

- [x] 戻り値の型 / null 安全 / DTO / Generics — 非該当 (test 本体)

### テスト計画

- [x] 既存テストの**更新**: 同ファイルの当該 test 1 本 (名前は据え置き)
- [x] 緑のまま (`sop-extract-media.yaml` は `timeout: 360` を宣言済み)
- [x] 施策 4 と併せて「待ち予算を読む実装が 1 本になった」ことを
      `grep -rn "client_options" tests/ | grep -v PromptWaitBudget` の結果が
      **メッセージ文字列とコメントだけ**になることで確認する (実装時の手順に含める)

### リスク

- 同ファイルには `AnalysisBudget::clientTimeoutSecondsFromYaml()` を使う test も在り、
  読み取り器を 2 度通ることになる (YAML を 2 回 parse する)。
  テストの実行時間として無視できる差であり、**キャッシュ層は作らない** (思考原則 2)

---

## 施策 6: 乖離台帳への登録 (D50) と件数 pin の更新

### 変更箇所

- `docs/template-divergence.md`: 宣言行「登録エントリ: 46 件」→「47 件」、末尾に `## D50` を追加
- `tests/Support/TemplateDivergence/LedgerPins.php`:
  `DIVERGENCE_ENTRY_COUNT = 46` → `47`

### 乖離台帳の確認段 (app-design スキル 3-0 の必須段)

`docs/template-fingerprints.json` の `entries` キーに**在るか**で共有ファイルかを判定した:

| 変更ファイル | 指紋台帳 | 採用時債務 | 判断 |
|---|---|---|---|
| `tests/Architecture/PromptClientTimeoutInvariantTest.php` | **在る** (sha `3e2cd834…`。現物と一致 = テンプレート準拠) | 無い | **D50 として登録が必要** |
| `tests/Support/PromptYaml.php` | 在る | **在る** (sha `e473358f…`。現物と一致) | **触らない**ので操作不要 |
| `tests/Architecture/PromptYamlContractTest.php` | 在る | **在る** | **触らない**ので操作不要 |
| `tests/Support/PromptWaitBudget.php` (新規) | 無い | 無い | テンプレートに無い領域への上積み。D50 の対象パスに**含める** (「登録するか迷ったら登録する」) |
| `tests/Unit/Architecture/PromptWaitBudgetTest.php` (新規) | 無い | 無い | 同上。D50 の対象パスに含める |
| `tests/Architecture/fixtures/prompt-wait-budget/*.yaml` (新規) | 無い | 無い | 見本データ。**対象パスには含めない**(登録メタ表の対象パスは「ファイルとして実在」する必要があり 12 本の列挙は台帳を読みにくくする。エントリ本文の節で言及する) |
| `tests/Support/AnalysisBudget.php` | 無い | 無い | 操作不要 |
| `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` | 無い | 無い | 操作不要 |
| `docs/architecture.md` | 無い (アプリ固有) | 無い | 操作不要 |
| `tests/Support/TemplateDivergence/LedgerPins.php` | 無い | 無い | 件数 pin の更新のみ |

**採用時債務のパスは 1 本も変更しない**ので、突合 gate の `mutatedDebtPaths` は発火しない
(3 択の判断は不要)。

### 登録するエントリ (D50)

番号は `D50` を使う。台帳の最大番号は D49 (登録件数 46・欠番あり) で、
**番号は再利用しない / 詰めない**という規約に従う。

```markdown
## D50 LLM 待ち予算の宣言検査を、単一読み取り器 + 検出器自己テスト形へ差し替える

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Architecture/PromptClientTimeoutInvariantTest.php` / `tests/Support/PromptWaitBudget.php` / `tests/Unit/Architecture/PromptWaitBudgetTest.php` |
| 業務要件起因の説明 | 本アプリの AI 解析は 1 呼び出し 360 秒の待ち予算を前提に deadline (3C) / job timeout / retry_after / 予約 TTL の連鎖を組んでおり、prompt YAML の宣言が落ちると実効値が 30 秒へ縮んで解析が黙って途中で切れる。テンプレートの形は判定を gate ファイル内へインラインで持つため、同じ規則が時間 budget 側にも複製されて実際に緩い実装 (0 以下を通す) が生まれた。判定を 1 クラスへ切り出し、待ち予算を読む検査すべてがそれを参照する形にする |
| 揃え続ける不変条件と保証機構 | 全 prompt YAML が `client_options.timeout` を正の整数で宣言することは `PromptClientTimeoutInvariantTest` が再帰全数走査 + 既定拒否で固定する (テンプレートと同じ不変条件)。判定の検出力は `tests/Unit/Architecture/PromptWaitBudgetTest.php` が負例 9 類型 + 正例 + 解決不能形 3 種で裏取りする。分母の到達証明 (既知 5 本の包含) は同 gate が持つ |
| 再判定の条件 | テンプレート側が判定の 1 クラス化を取り込んだとき (家系の正典 v1 が既にこの形なので、追従で差分が消える可能性がある)。または `resources/prompts` の走査ヘルパ (`PromptYaml`) をテンプレートへ同期する判断をしたとき |
| 決めた日 | 2026-08-24 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260824-1016-llm-prompt-wait-budget-v1/ |
| 状態 | 監視中 |
| 見直し期限 | 2027-06-30 |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 判定の置き場 | gate ファイル内のインライン判定 | `Tests\Support\PromptWaitBudget` 1 クラス (public 2 口・判定は private) |
| 検出力の裏取り | なし | 負例 9 類型 + 正例 + 解決不能形 3 種 (`tests/Unit/Architecture/PromptWaitBudgetTest.php`) |
| 分母の証明 | 非空のみ | 非空 + 既知 5 本の包含 (走査根の改名・移動で赤くなる) |
| 時間 budget 側との関係 | 無関係 (テンプレートに解析パイプラインが無い) | 同じ読み取り器を `AnalysisBudget::clientTimeoutSecondsFromYaml()` が参照する |

### なぜ正当な差分か (logic-driven)
...(上表の「業務要件起因の説明」を展開)...

### 揃えている不変条件 (これは保証し続ける)
> 「resources/prompts 配下の prompt YAML は全数が client_options.timeout を
> 正の整数で宣言しており、未宣言・0 以下・整数でない値は検査で落ちる」

### 保証しないもの
- 宣言値が**実効値であること**は見ない (正本は読み取り器の docblock)
- 走査の**再帰そのものの検出力**は裏取りしていない (走査ヘルパが探索根を引数で受けないため)
- 4 本目の読み取り実装の再流入は機械では止めない

### 関連
- 実装: devnotes/20260824-1016-llm-prompt-wait-budget-v1/
- 見本ファイル: tests/Architecture/fixtures/prompt-wait-budget/ (12 本)
- 家系の正典: lctl feature `llm-prompt-wait-budget` canonical v1 (参照実装 spirux)
```

**状態を `監視中` にする理由**: 家系の正典 v1 がこの形であり、テンプレート側も
`update_pending` (同じ 3 点が不足) である。テンプレートが追従すれば逸脱は消えるので
「期限付きで能動的に見直す根拠」がある。見直し期限は **検査実行日 (基準日) から 400 日以内**という規約
(`DivergenceLedgerRules::MAX_REVIEW_WINDOW_DAYS`) に、実装が多少遅れても収まる
2027-06-30 とする (期限は「今日」との相対で判定されるため、上限ぎりぎりの日付を置かない)。

### 波及変更

- テストファイル: `TemplateDivergenceLedgerFormatTest` (宣言行 / 見出しの実数 / `LedgerPins`
  の 3 点一致を機械で見る) と `TemplateDivergenceFingerprintTest` (突合) が対象。
  **どちらも変更しない** — 通すために値を合わせるだけである
- TypeScript 型定義 / API Resource / DTO: なし

### PHPStan適合チェック

- [x] `LedgerPins` は `public const int` の値変更のみ (型は不変)

### テスト計画

- [x] **先に赤くする**: 施策 3 のファイル書き換え後、施策 6 を当てる前に
      `TemplateDivergenceFingerprintTest` が「未登録の新たな不一致」で赤くなることを確認する
      (これが登録の必要性の実測である)
- [x] `TemplateDivergenceLedgerFormatTest` が緑 (登録メタ表 9 行 / 値域 / 対象パスの実在と
      非重複 / 件数の 3 点一致)
- [x] `TemplateDivergenceFingerprintTest` が緑 (登録済みなので不一致は許容される)
- [x] 対象パス 3 本が既存エントリの対象パスと**重複していない**ことを確認する
      (実装時に `grep -n "PromptClientTimeoutInvariantTest\|PromptWaitBudget" docs/template-divergence.md`)

### リスク

- 件数 pin (46 → 47) を忘れると形式検査が赤くなる。**同じコミットで直す**
- D50 の対象パスへ新規 2 本を含めるかは「テンプレートに無い領域への上積み」の解釈判断である。
  登録簿の原則「登録するか迷ったら登録する」に従い含める (誤登録は削除で是正できるが
  登録漏れには気付けない)

---

## 施策 7: 実効性の運用契約への 3 行追記

### 変更箇所

- `docs/architecture.md` §AI 解析ジョブの運用契約 の
  「**LLM 呼び出しの実効タイムアウトは `resources/prompts/*.yaml` の `client_options.timeout`** である」
  の箇条の直後

### 変更後コード (追記する箇条)

```markdown
- **宣言の読み取り規則の正本は `Tests\Support\PromptWaitBudget` の 1 クラス**である
  (未宣言 / `client_options` 非配列 / `timeout` キー無し / 整数でない値 / 0 以下を
  すべて違反にする既定拒否)。待ち予算を読む検査 — 全数の宣言検査
  (`PromptClientTimeoutInvariantTest`) と時間 budget の突合
  (`AnalysisBudget::clientTimeoutSecondsFromYaml()`) — はすべてこの 1 本を参照する。
  同じ規則を 2 実装持つと片方だけが緩んでも気付けない (実測: 旧実装は `timeout: 0` を通していた)
- **上の「実効である」は 3 つの前提に依存し、機械では見ていない** —
  (i) `app/Prompts/` の factory が vendor の `$clientOptions` クラスプロパティを設定しない、
  (ii) `resources/prompts` を読む非 PHP の実装が無い、
  (iii) vendor の解決順序が「クラスプロパティ > YAML > config」である。
  2026-08-24 の実読で 3 つとも成立していることを確認した (batch/pool 経路も無い)
- **前提が崩れたときの手当て**: 宣言値が実効でない経路が生まれたら、その経路を本節へ
  列挙し、記述が消えたら赤くなる検査を置く (家系の正典が spirux で採っている形)。
  「宣言は在るのに効かない」状態を検査が緑で覆い隠すのが最悪の帰結なので、
  経路を作る PR で必ず同時に行う
```

### 波及変更

- テストファイル: `docs/architecture.md` の本節を pin する検査は無い
  (`QueuedJobLeaseInventoryTest` / `JobExecutionDedupInventoryTest` /
  `RouteBindingCustomBinderDocSyncTest` はいずれも別の節を見る)。
  実装時に `grep -rn "AI 解析ジョブの運用契約" tests/` で 0 件を再確認する
- 指紋台帳のキーに無い (アプリ固有ファイル) ので乖離台帳の操作は不要

### PHPStan適合チェック

- [x] 非該当 (Markdown)

### テスト計画

- [x] 新規テストは書かない。**文書 pin の検査を新設しない**のは、列挙すべき経路が
      現時点で 0 件であり、0 件の列挙を固定する検査は「今必要なものだけ作る」に反するため
      (概念設計 §正典要求 (5) の扱い に判断根拠を記載)
- [x] `composer test` 全体が緑 (文書変更で赤くなる検査が無いことの確認を兼ねる)

### リスク

- 文書と docblock の 2 か所に実効性の話が載る。**役割を分ける**ことで食い違いを防ぐ —
  docblock は「読み取り器が主張しないこと」の正本、architecture.md は「運用契約と
  前提が崩れたときの導入条件」の正本。同じ文を写さない

---

## テストファースト手順 (実装順・どのテストを先に赤くするか)

| 段 | 作業 | 期待する状態 |
|---|---|---|
| 1 | 見本 12 本 + `tests/Unit/Architecture/PromptWaitBudgetTest.php` を書く (施策 1) | **赤** (`Tests\Support\PromptWaitBudget` が無い = Error) |
| 2 | `tests/Support/PromptWaitBudget.php` を書く (施策 2) | 段 1 の 5 本が**緑** |
| 3 | 負のコントロールの裏取り: `<= 0` 分岐を一時削除 → `is_int()` を `is_numeric()` へ緩める | それぞれ**赤** (ラベル集合が欠ける)。確認後に戻し、`red-green-log.md` へ記録 |
| 4 | gate の到達証明 test を追加 (施策 3 の前半) | **緑** (実データが揃っているため)。続けて `PROMPT_WAIT_BUDGET_REQUIRED_LABELS` へ架空の名前を足して**赤**を確認し戻す |
| 5 | gate 本体を読み取り器へ委譲 (施策 3 の後半。インライン判定を削除) | **緑** |
| 6 | `TemplateDivergenceFingerprintTest` だけを走らせる | **赤** (未登録の新たな不一致)。これが施策 6 の必要性の実測 |
| 7 | D50 登録 + `LedgerPins` 46 → 47 (施策 6) | `TemplateDivergence*Test` が**緑** |
| 8 | `AnalysisBudget::clientTimeoutSecondsFromYaml()` を委譲 (施策 4) | 既存 2 gate が**緑** |
| 9 | `AnalysisTokenBudgetInvariantTest` の素の配列参照を委譲 (施策 5) | **緑** |
| 10 | `docs/architecture.md` へ 3 箇条 (施策 7) | **緑** |
| 11 | `grep -rn "client_options" tests/ \| grep -v PromptWaitBudget` | 残るのは**メッセージ文字列とコメントだけ** (読み取り実装は 0 件) |
| 12 | `composer test` / `composer phpstan` / `vendor/bin/pint --test` | 全**緑** |
| 13 | `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` | 全**緑** (JS は無変更だが AGENTS.md の検証コマンドは全数走らせる) |

- 段 3 と段 4 の「一時的に壊して赤を見る」は AGENTS.md
  「既存の抽出器を流用して最初から緑になる場合は、負例が押さえる分岐を一時的に壊して
  赤を確認する」の実施であり、**実測を devnotes に残す**ことまでが完了条件である
- **実 prompt YAML を壊して確認する形は採らない** (同じ分母を見る 4 gate が同時に赤くなり、
  何を確かめたのか分からなくなる)

## migration / 後方互換の扱い

- **DB migration は無い** (スキーマ・データに触れない)
- **後方互換の並走を残さない** (AGENTS.md 思考原則 3)。同じ PR で次の 3 実装を削除する:
  1. `PromptClientTimeoutInvariantTest` のインライン判定 3 分岐
  2. `AnalysisBudget::clientTimeoutSecondsFromYaml()` の `Webmozart\Assert` 版読み出し
     (`use` 2 行も削除)
  3. `AnalysisTokenBudgetInvariantTest` の素の配列参照 1 行
- 旧実装を残す移行期間・deprecated ラッパ・feature flag は**作らない**
- 削除する既存**テスト**は無い (test の本数は 1 本増える。禁止事項「既存テストの削除・上書き」に触れない)
- `resources/prompts/*.yaml` の値は不変 (360 / 60)。**運用への影響は無い**

## docs/template-divergence.md の登録/更新/削除の要否

| 判定 | 内容 |
|---|---|
| **登録が必要** | あり (D50 新設)。理由: `tests/Architecture/PromptClientTimeoutInvariantTest.php` が指紋台帳のキーに在り、現在テンプレートと一致しているため、書き換えると突合 gate が「未登録の新たな不一致」で落ちる |
| **更新が必要** | `LedgerPins::DIVERGENCE_ENTRY_COUNT` 46 → 47、宣言行「登録エントリ: 46 件」→「47 件」 |
| **削除が必要** | なし (解消する既存エントリは無い) |
| **採用時債務の 3 択** | 発生しない (`adoption-debt.tsv` のパスを 1 本も変更しない) |
| **指紋台帳の書き換え** | しない (突合 gate が赤いときに指紋台帳や債務一覧を書き換えて黙らせない、という規約に従う。書くのは登録である) |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 1〜7 は 1 つの意味単位で動く。特に施策 3 (gate の書き換え) と施策 6 (D50 登録 + 件数 pin) は**同じコミットでなければ CI が赤くなる**。施策 2 が無いと施策 1・3・4・5 が成立せず、途中の状態でコミットできる切れ目が無い。他タスクと並行して部分マージする余地が無いので standalone とする |
| 競合リスク | Open な TODO は T249 (起動 probe の共通 runner への一元化) のみで対象ファイルが重ならない。ただし **`LedgerPins::DIVERGENCE_ENTRY_COUNT` と `docs/template-divergence.md` の末尾は、乖離登録を伴う任意の PR と競合する** (件数の 3 点一致があるため機械的に検出される)。同時期に別の逸脱登録が入った場合は番号 (D50 → 次の未使用番号) と件数を rebase 時に合わせる |
| 想定規模 | 新規 2 ファイル + 見本 12 本 / 既存 5 ファイルの部分変更。medium |

## 使命・禁止事項の最終確認

- **使命への寄与**: SOP → シナリオ生成の 3 段と OCR 経路は 360 秒の待ち予算を前提に
  時間 budget 連鎖を組む。宣言が落ちると「思考ゼロ・編集ゼロ」の中核である AI 解析が
  provider 沈黙時に黙って途中で切れ、ワーカーが塞がって後続の解析まで詰まる。
  読み取り規則を 1 本にすると、どの検査から見ても同じ厳しさで落ちる
- **禁止事項 1 (テストなしの実装完了)**: 検出力を負例で裏取りした自己テストまでを完了条件にした
- **禁止事項 2 (PHPStan の widen / baseline)**: 該当なし (`tests` は解析対象外。
  型を緩める変更も無い)
- **禁止事項 3 (dev DB への破壊操作)**: 該当なし
- **禁止事項 4〜8**: アプリ実行コード・UI・route に触れないため該当なし
- **禁止事項 9 (Artifact の使用)**: 成果物はすべて `devnotes/` 配下のファイルである
- **スキル禁止事項 3 (既存テストの削除・上書き)**: 削除するテストは無い。
  書き換えるのは判定の実装であり、test 名と不変条件は据え置く
- **スキル禁止事項 5 (個別 `DatabaseTransactions`)**: 使わない
- **スキル禁止事項 6 (やたらに複雑な案)**: 正典が要求する 3 点 + 構造依存要件 1 点に限定し、
  正典要求 (5) は「該当構造なし」として文書節と検査の新設を退けた

---

## 関連する現行コード

### tests/Architecture/PromptClientTimeoutInvariantTest.php (全文。施策 3 で書き換える)

```php
<?php

declare(strict_types=1);

use Tests\Support\PromptYaml;

/*
 * LLM provider のハング対策として、全 prompt YAML が client_options.timeout (>0 の int) を
 * 宣言する不変条件を固定する。prism-prompt は YAML metadata の client_options を Prism
 * リクエストへ渡すため、これにより provider 無応答時に明示 timeout で打ち切られる。
 *
 * 走査は PromptYamlContractTest と同じ deny-by-default (再帰 + 0 件 fail-fast)。
 */
test('全 prompt YAML が client_options.timeout (>0) を宣言する', function (): void {
    $files = PromptYaml::paths();

    expect($files)->not->toBeEmpty();

    $violations = [];
    foreach ($files as $file) {
        $parseErrors = [];
        $parsed = PromptYaml::parseOrFail($file, $parseErrors);
        if ($parsed === null) {
            array_push($violations, ...$parseErrors);

            continue;
        }
        if (! array_key_exists('client_options', $parsed) || ! is_array($parsed['client_options'])) {
            $violations[] = "{$file}: client_options (map) がありません";

            continue;
        }
        $timeout = $parsed['client_options']['timeout'] ?? null;
        if (! is_int($timeout) || $timeout <= 0) {
            $violations[] = "{$file}: client_options.timeout は正の int で宣言してください";
        }
    }

    expect($violations)->toBe([],
        'client_options.timeout invariant に違反があります (provider 無応答時に打ち切れない)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
```

### tests/Support/PromptYaml.php (全文。**採用時債務のため変更しない**。読み取り器が parse を委譲する先)

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * prompt YAML 契約テスト群 (PromptYamlContractTest / PromptClientTimeoutInvariantTest /
 * DefensiveInstructionsPresenceTest) の共有走査ヘルパ。
 *
 * 各テストを targeted 実行しても動くよう、Pest テストファイル内の関数ではなく
 * autoload されるクラスに置く。
 */
final class PromptYaml
{
    /**
     * resources/prompts 配下の *.yaml/*.yml 絶対パス (deny-by-default 再帰走査)。
     *
     * @return list<string>
     */
    public static function paths(): array
    {
        $base = base_path('resources/prompts');
        if (! is_dir($base)) {
            return [];
        }

        $paths = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            // 大文字拡張子 (.YAML/.YML) も拾う (deny-by-default の穴を塞ぐ)。
            if (in_array(strtolower($file->getExtension()), ['yaml', 'yml'], true)) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        return $paths;
    }

    /**
     * parse して連想配列 (map) を返す。失敗 / 非 map は $violations に積み null を返す。
     *
     * @param  list<string>  $violations
     * @return array<string, mixed>|null
     */
    public static function parseOrFail(string $path, array &$violations): ?array
    {
        try {
            $parsed = Yaml::parseFile($path);
        } catch (Throwable $e) {
            $violations[] = "{$path}: parse 失敗 ({$e->getMessage()})";

            return null;
        }

        if (! is_array($parsed) || array_is_list($parsed)) {
            $violations[] = "{$path}: top-level が連想配列(map)でない";

            return null;
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }
}
```

### tests/Support/AnalysisBudget.php (全文。施策 4 で clientTimeoutSecondsFromYaml() を書き換える)

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Yaml\Yaml;
use Webmozart\Assert\Assert;

/**
 * AI 解析の時間 budget 不変条件で使う「仕様値」と、prompt YAML からの実測読み出し。
 *
 * Pest のファイルスコープ const / 関数はテスト間で衝突しうるため、
 * Tests\Support\PromptYaml と同じく autoload されるクラスに集約する。
 *
 * **CLIENT_TIMEOUT_SECONDS は仕様値であり、YAML から導出しない**。
 * これは意図的な重複である: YAML と仕様値を突き合わせることで初めて
 * 「YAML を勝手に変えた」ことを検出できる (YAML から導出すると同時変更で素通りする)。
 */
final class AnalysisBudget
{
    /** C: 1 呼び出しの client timeout (仕様値。prompt YAML と一致すること) */
    public const CLIENT_TIMEOUT_SECONDS = 360;

    /** extract / decompose / generate */
    public const STAGE_COUNT = 3;

    /** M₁: deadline 通過後の terminal tx + commit/release + 通知 */
    public const FINALIZE_BUDGET_SECONDS = 30;

    /** S: P (worker alarm → run() 入口) + タイマー精度 + シグナル配送 + ログ */
    public const SAFETY_MARGIN_SECONDS = 90;

    /** D: パイプライン deadline の仕様値 */
    public const DEADLINE_SECONDS = self::STAGE_COUNT * self::CLIENT_TIMEOUT_SECONDS;

    /** 解析パイプラインの 3 プロンプト */
    public const PROMPT_NAMES = ['sop-extract', 'work-decomposition', 'scenario-generation'];

    /**
     * prompt YAML から読んだ client_options.timeout (プロンプト名 => 値)。
     *
     * @return array<string, int>
     */
    public static function clientTimeoutSecondsFromYaml(): array
    {
        $timeouts = [];
        foreach (self::PROMPT_NAMES as $name) {
            $yaml = Yaml::parseFile(resource_path("prompts/{$name}.yaml"));
            Assert::isArray($yaml, "{$name}.yaml が map ではありません");
            Assert::keyExists($yaml, 'client_options', "{$name}.yaml に client_options がありません");

            // 配列 offset 式のままだと PHPStan の narrowing が保たれないためローカル変数へ移す
            $clientOptions = $yaml['client_options'];
            Assert::isArray($clientOptions, "{$name}.yaml の client_options が map ではありません");
            Assert::keyExists($clientOptions, 'timeout', "{$name}.yaml に client_options.timeout がありません");

            $timeout = $clientOptions['timeout'];
            Assert::integer($timeout, "{$name}.yaml の client_options.timeout が int ではありません");

            $timeouts[$name] = $timeout;
        }

        return $timeouts;
    }
}
```

### tests/Architecture/AnalysisTimeBudgetInvariantTest.php (抜粋。clientTimeoutSecondsFromYaml() の呼び出し側。無変更)

```php
    expect(config()->string('queue.connections.database-analysis.queue'))->toBe('analysis');
    expect(config()->string('queue.connections.database-analysis.driver'))->toBe('database');
});

test('解析 3 プロンプトの client timeout が仕様値 (C) と一致する', function (): void {
    foreach (AnalysisBudget::clientTimeoutSecondsFromYaml() as $name => $timeout) {
        expect($timeout)->toBe(
            AnalysisBudget::CLIENT_TIMEOUT_SECONDS,
            "{$name}.yaml の client_options.timeout が時間 budget の C と不一致",
        );
    }
});

test('config の deadline が仕様値 (D = 3C) と一致する', function (): void {
    expect(config()->integer('manual.analysis_deadline_seconds'))
        ->toBe(AnalysisBudget::DEADLINE_SECONDS);
});

test('job timeout は worst-case (deadline + client timeout 1 回 + finalize + 余白) を満たす', function (): void {
    // deadline 判定は「過ぎたか」のみなので、deadline 通過後に走りうるのは高々 1 回分の C
    $worstCase = AnalysisBudget::DEADLINE_SECONDS
        + AnalysisBudget::CLIENT_TIMEOUT_SECONDS
        + AnalysisBudget::FINALIZE_BUDGET_SECONDS
        + AnalysisBudget::SAFETY_MARGIN_SECONDS;

    expect((new RunManualAnalysis(1))->timeout)->toBeGreaterThanOrEqual($worstCase);
});

test('モデル上限 (deadline + C + finalize) に対して明示的な安全余白がある', function (): void {
    $modelBound = AnalysisBudget::DEADLINE_SECONDS
        + AnalysisBudget::CLIENT_TIMEOUT_SECONDS
        + AnalysisBudget::FINALIZE_BUDGET_SECONDS;

    expect((new RunManualAnalysis(1))->timeout - $modelBound)
        ->toBeGreaterThanOrEqual(AnalysisBudget::SAFETY_MARGIN_SECONDS);
});

test('解析ジョブは自動再試行しない (tries=1。再実行は analyze 再トリガーのみ)', function (): void {
    expect((new RunManualAnalysis(1))->tries)->toBe(1);
});
```

### tests/Architecture/AnalysisTokenBudgetInvariantTest.php (抜粋。施策 5 で最後の test の 1 行を書き換える)

```php
const PROMPT_OVERHEAD_TOKENS = 4_000;   // 固定 system/prompt + UserInput タグの余裕

const INPUT_BUDGET_TOKENS = MODEL_CONTEXT_TOKENS - OUTPUT_RESERVE_TOKENS - PROMPT_OVERHEAD_TOKENS; // 180,000

/**
 * 解析パイプラインの 3 プロンプト (施策 8)。
 * 正本は Tests\Support\AnalysisBudget::PROMPT_NAMES (時間 budget 側と二重管理しない)。
 *
 * @return list<string>
 */
function analysisPromptNames(): array
{
    return AnalysisBudget::PROMPT_NAMES;
}

test('LLM 入力バイト上限が入力 token budget を超えない (分割上界: token数<=バイト数)', function (): void {
    expect(config()->integer('manual.analysis_max_text_bytes'))
        ->toBeLessThanOrEqual(INPUT_BUDGET_TOKENS);
});

test('解析プロンプト YAML の max_tokens は出力予約と一致する', function (): void {
    foreach (analysisPromptNames() as $name) {
        $path = resource_path("prompts/{$name}.yaml");
        expect(file_exists($path))->toBeTrue("解析プロンプト {$name}.yaml が存在しません");
        $yaml = Yaml::parseFile($path);
        expect($yaml)->toBeArray();
        expect($yaml['max_tokens'] ?? null)
            ->toBe(OUTPUT_RESERVE_TOKENS, "{$name}.yaml の max_tokens が出力予約 (OUTPUT_RESERVE_TOKENS) と不一致");
    }
});

test('解析プロンプト YAML の client timeout は時間 budget の仕様値 C と一致する', function (): void {
    // AnalysisTimeBudgetInvariantTest の worst-case 計算 (D = 3C / T >= D + C + M1 + S) と対
    foreach (AnalysisBudget::clientTimeoutSecondsFromYaml() as $name => $timeout) {
        expect($timeout)->toBe(
            AnalysisBudget::CLIENT_TIMEOUT_SECONDS,
            "{$name}.yaml の client_options.timeout が時間 budget の C と不一致",
        );
    }
});

test('最小テキスト閾値 < 最大バイト上限 (validation の縮退防止)', function (): void {
    expect(config()->integer('manual.analysis_min_text_bytes'))
        ->toBeLessThan(config()->integer('manual.analysis_max_text_bytes'));
});

...
    $mismatched = Yaml::parse(<<<'YAML'
    name: sop-extract-media
    provider: openai
    model: gpt-5.6-example
    YAML);

    expect($mismatched['provider'])->not->toBe(OCR_ESTIMATE_PINNED_PROVIDER);
    expect($mismatched['model'])->not->toBe(OCR_ESTIMATE_PINNED_MODEL);
});

test('sop-extract-media.yaml の max_tokens / client timeout も解析 3 段と同じ仕様値に揃っている', function (): void {
    $yaml = Yaml::parseFile(resource_path('prompts/sop-extract-media.yaml'));
    expect($yaml)->toBeArray();
    expect($yaml['max_tokens'] ?? null)->toBe(OUTPUT_RESERVE_TOKENS);
    expect($yaml['client_options']['timeout'] ?? null)->toBe(AnalysisBudget::CLIENT_TIMEOUT_SECONDS);
});
```

### tests/Support/TemplateDivergence/LedgerPins.php (抜粋。施策 6 で件数を 46 → 47 にする)

```php

    /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
    public const int DIVERGENCE_ENTRY_COUNT = 46;

    /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
    public const int FINGERPRINT_POPULATION_COUNT = 281;

    /**
     * 採用時債務の件数。
     *
     * ★機械が保証するのは**無断の増減の検出**までである (一覧と本定数を同じ変更で
     *   増やせば通る)。増加を許さないのは生成器のガードとレビュー規約であり、
     *   検査は「一覧と定数と実測が食い違ったら赤」を担う。
     */
    public const int ADOPTION_DEBT_COUNT = 148;

    /**
     * 採用時債務一覧を説明する逸脱の登録番号 (D34)。
     *
     * ★掃除の判定は**登録の存在**で行う (対象パスだけを見ると、一覧ファイルを消して
     *   対象パス欄から一覧パスだけを削り登録を残す、という中途半端な掃除が緑になる)。
```

### docs/template-divergence.md の登録メタ表の規約 (抜粋)

```markdown
| 再判定の条件 | 何が変わったら見直すか (**恒久の登録にも必須**) |
| 決めた日 | `YYYY-MM-DD`。逸脱を最初に決めた日 (再判断で書き換えない)。未来日は不可 |
| 決めた人 | `オーナー` / `開発者` |
| 根拠 | `T<n>` (3 桁以上のゼロ埋め。`docs/TODO.md` / `docs/TODO-closed.md` の表に実在) または `devnotes/<dir>/` (ディレクトリが実在) |
| 状態 | `恒久` / `監視中` |
| 見直し期限 | `監視中` は `YYYY-MM-DD` (基準日から 400 日以内)。`恒久` は全角ダッシュ 1 文字 |

- **`恒久` も `監視中` も「今ある逸脱」を表す**。解消を意味する語は値域に無い
- `監視中` にするのは、期限付きで能動的に見直す根拠 (期限・予定時期・追跡中の事象) が
  あるときだけである。解消の条件が書けることは `監視中` の根拠にならない
  (`恒久` の登録も再判定の条件を必ず持つので、条件の有無は区別にならない)
- セルの中に縦棒を書かない (エスケープしても解釈しない)。表の区切りを使いたくなる内容は
  エントリ本文の節へ書く

## 見直し期限が切れたときの直し方 (4 通り)

1. 逸脱を解消して登録を消す
2. `恒久` へ変えて理由を足す
3. 期限を延ばして再判断の根拠を足す
4. 対象を分けて個別に判断する

**検査を緩めることは選択肢に入れない**。期限切れで CI が赤くなるのは仕様である。

## この登録簿が保証しないもの

```

### resources/prompts の現状 (5 本。いずれも client_options.timeout を宣言済み)

```
example-summary.yaml       timeout: 60
scenario-generation.yaml   timeout: 360
sop-extract-media.yaml     timeout: 360
sop-extract.yaml           timeout: 360
work-decomposition.yaml    timeout: 360
```
