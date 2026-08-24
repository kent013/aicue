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


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 (ただし phpstan.neon の paths は app/config/database/routes で tests/ は対象外)
- Pest テストフレームワーク (Architecture lane は Tests\TestCase を extend、DB 未使用)
- DTO + JsonResource パターン
- Laratrust RBAC (Organization → Team → Project 階層)

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

【本件の追加文脈】
- 本件は家系 (6 リポジトリ) 共有の機能台帳 lctl の feature `gate-env-example-sync` の
  正典 t2 追従である。正典の不変条件 i1〜i14 は 2026-08-22 に確定済みで、
  正典そのものの妥当性は争点ではない (aicue 単独では動かせない)。
- 変更はアプリ実行コード 0 / Architecture テスト 1 本 / テンプレート乖離台帳 3 ファイル。
  DB・UI・API・フロントの変更は無いので観点 5・6・10・11 は「該当なし」の判定で構わない。
- 概念設計は同じ Codex セッションではない別レビューで APPROVED 済みである。

---

## 詳細設計書

# 詳細設計: gate-env-example-sync の正典 t2 追従 (aicue)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）


<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項


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

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)。ただし `phpstan.neon` の `paths` は
  `app` / `config` / `database` / `routes` であり **`tests/` は解析対象外**である。
  型注記は将来の編入に耐える形で書く (T213 の既存方針を踏襲。closure に直接 `@param` を付ける)
- **Pest** テストフレームワーク (`composer test`)。Architecture lane は `Tests\TestCase` を
  extend し DB を使わない (`RefreshDatabase` なし)。個別の `DatabaseTransactions` 禁止
- **テストデータは Factory** — 本件はファイル走査のみで DB を触らないため該当なし
- **DTO + JsonResource** パターン — 本件はアプリコードを変更しないため該当なし
- **アーリーリターン**推奨。解析器の行分類は早期 `continue` で書く
- **コードフォーマット**: `composer fix` (Pint) / `pnpm lint:fix`
- `declare(strict_types=1)` + 日本語コメント (git 追跡下の PHP 全数が対象。免除の登録簿は無い)
- **`echo` / `goto` / `global` / 開始タグ付きの出力記法を書かない**
  (`ForbiddenStatementTokenInvariantTest` が字句単位で検出する)
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

### 本件に効く追加規約 (AGENTS.md)

- **「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」が発火する**
  (走査ロジック・走査対象・判定条件・目録のすべてを変えるため):
  1. 負例と正例をテストファーストで (先に赤くする)
  2. 解決できない形を落とす分岐 (fail-closed)
  3. 走査が空振りしていないことの検査 (母集団の非空 / 駆動元の床)
  4. docblock に走査対象と保証しないものを書く (中身の正本は docblock 側。AGENTS.md へ写さない)
- **走査器の共通規約**のうち **(b) fail-closed / (c) 負例で両方向 / (d) 集めた結果を必ず判定に使う**
  が該当する。**(a)** はクラス参照の名前解決を伴わないため無関係、**(e)** は語彙一致の否定形を
  持たないため無関係である
- **負例の置き場**は「gate 内の合成入力」を採る (AGENTS.md が認める 3 通りのうちの 1 つ)。
  gate の docblock から辿れるようにする

## 概念設計リファレンス

- `devnotes/20260824-1014-gate-env-example-sync-t2/conceptual-design.md` (Round 4 で APPROVED)
- 正典: lctl feature `gate-env-example-sync` / `canonical_version: t2` /
  `design.settled_at: 2026-08-22T01:29:16+09:00` / `doc_sha: 97d72c394bcb`

## 設計時に実測した前提 (実装時に再確認すること)

| 事実 | 実測値 | 使う場所 |
|---|---|---|
| `.env.example` の代入行 | 81 行 | 母集団の非空の検査 |
| 形式違反 / 重複 | 0 / 0 | 見本の変更が 0 行であることの根拠 |
| 制御文字 (C0 + TAB + DEL + C1) / 不正 UTF-8 | 0 / 0 | 同上 (TAB を違反にしても見本は緑) |
| `APP_ENV` の値 | `local` (前後の空白なし) | M2 の値の固定 |
| `${VAR}` 参照 | 3 件すべて後方参照 (`MAIL_FROM_NAME` / `VITE_APP_NAME` / `GOOGLE_REDIRECT_URI`) | i11 は現状維持 |
| 必須キー 35 件の存在 | 35/35 実在 | M2 の台帳 |
| `phpunit.xml` の env | `<server name="APP_ENV" value="testing" force="true"/>` + `.env.testing` 実在 | M3 が緑になる根拠 |
| 指紋台帳の母集合 | 281 件。`tests/Architecture/EnvExampleInvariantTest.php` を**含む** (テンプレート側 `add11034…`) | M5 |
| 採用時債務 | 148 件。同ファイルが在り、採用時ハッシュ `d672f63c…` = **現在の内容と一致** | M5 |
| `docs/template-divergence.md` | 登録 46 件 / 最大番号 D49 | M5 (新規は **D50**) |
| `webmozart/assert` | `composer.json` の require に `^2.4` | M1 |
| `ext-mbstring` | `composer.json` に **明示宣言が無い** | M1 (mb_* を使わず PCRE で UTF-8 妥当性を見る根拠) |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| M1 | 解析器の受理規則の強化 (i3) と fail-closed 化、反証 12 件の追加 | `tests/Architecture/EnvExampleInvariantTest.php` | High |
| M2 | 台帳の正規化 + 由来・件数の機械検査 (i7/i8/i9) と `APP_ENV` の移送 (i6) | 同上 | High |
| M3 | 検査の前提の実行時確認 (i12) | 同上 | High |
| M4 | docblock の更新 (i13 / 2 解析器の対比表 / 走査対象と保証しないもの) | 同上 | High |
| M5 | 乖離台帳の更新 (D50 の登録 / 債務行の削除 / 件数 pin の更新) | `docs/template-divergence.md` / `tests/Support/TemplateDivergence/adoption-debt.tsv` / `tests/Support/TemplateDivergence/LedgerPins.php` | High |

**波及変更の全数** (インターフェース変更の波及チェック):

- TypeScript 型定義: **なし** (フロントに影響しない)
- Inertia Props / API Resource / DTO: **なし** (アプリコードを変更しない)
- テストファイル: `tests/Architecture/EnvExampleInvariantTest.php` **のみ**。
  グローバル関数・定数の名前は他の Pest ファイルと衝突しないことを確認済み
  (`envExample*` / `ENV_EXAMPLE_*` は本ファイル以外に 1 件も無い。
  同居する `tests/Architecture/BughuntEnvExampleContractTest.php` は
  `bughuntEnvExampleViolations()` のみを定義する)
- 参照元: `grep -rn "EnvExampleInvariantTest\|envExampleParse\|ENV_EXAMPLE_"` の結果、
  `devnotes/` 以外にアプリコード・他テストからの参照は **0 件**

---

## M1 解析器の受理規則の強化 (i3) と fail-closed 化

### 変更箇所

- `tests/Architecture/EnvExampleInvariantTest.php`
  - L43-L109 (`envExampleParseContents()` の docblock と本体)
  - L120-L127 (`envExampleParse()`。`expect()` を `Assert` へ)
  - L327-L415 (反証データセット。inline から関数へ切り出し、R17〜R28 を追加)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 同ファイル内のみ (反証の駆動元を関数化するため `->with()` の引数が変わる)

### 現行コード

```php
function envExampleParseContents(string $contents): array
{
    $lines = preg_split('/\r\n|\r|\n/', $contents);
    expect($lines)->toBeArray();
    /** @var list<string> $lines */
    $values = [];
    $duplicateKeys = [];
    $malformedLineNumbers = [];

    foreach ($lines as $index => $line) {
        if (trim($line) === '') {
            continue;
        }
        if (preg_match('/^\s*#/', $line) === 1) {
            continue;
        }
        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches) !== 1) {
            $malformedLineNumbers[] = $index + 1;

            continue;
        }
        // ... 重複判定と $values への記録 (変更しない)
    }
    // ...
}
```

**現行の 3 つの穴** (いずれも今日は緑で通る):

1. 受理正規表現が制御文字を値として素通しする (`SESSION_SECURE_COOKIE=true\x01` が「正常な代入」)
2. `trim($line)` の既定 charlist は `" \t\n\r\0\x0B"` なので、**`\0` だけの行・`\x0B` だけの行が
   空行として飛ばされる**
3. コメント判定の `\s` は `\f` を含むので、**`\f#` で始まる行がコメントとして飛ばされる**
4. `preg_split()` / `preg_match()` の `false` を「違反なし」へ畳んでいる (走査器規約 (b) 違反)

### 変更後コード

```php
/**
 * 値になりうる行 (空行でもコメントでもない行) に現れてはならない文字。
 *
 * C0 制御文字 (`\x00`-`\x08`) + **TAB (`\x09`)** + VT / FF (`\x0B` / `\x0C`) +
 * 残りの C0 (`\x0E`-`\x1F`) + DEL (`\x7F`) + **C1 域 (U+0080-U+009F)**。
 * `\n` / `\r` は行分割で除去済みなので含めない。
 *
 * ★C1 は UTF-8 では必ず `\xC2` + `\x80`-`\x9F` の 2 バイトで表される。この判定は
 *   **行が妥当な UTF-8 だと確かめた後にだけ**適用するので、多バイト文字の継続バイトと
 *   衝突しない (先に検査すると `日本語` のような正当な値を誤検出する)。
 * ★TAB が許されるのは「空白だけの行」と「コメントの字下げ」だけである
 *   (正典 i3 が「空白だけの行は飛ばす」「コメント行の字下げは各リポジトリの裁量」と定めるため)。
 *   **値になりうる行に TAB が現れたら形式違反**である。
 */
const ENV_EXAMPLE_FORBIDDEN_CHARS = '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]|\xC2[\x80-\x9F]/';

/** 素の代入行の受理規則 (キーは `[A-Z][A-Z0-9_]*`、等号の直後から行末までが値)。 */
const ENV_EXAMPLE_ASSIGNMENT = '/^([A-Z][A-Z0-9_]*)=(.*)$/';

/** コメント行 (字下げは半角空白と TAB のみ許す。`\s` は `\f` を含むため使わない)。 */
const ENV_EXAMPLE_COMMENT = '/^[ \t]*#/';

/**
 * 正規表現の一致判定 (失敗を「一致しなかった」へ**畳まない**)。
 *
 * `preg_match()` は失敗時に `false` を返す。`!== 1` で書くと失敗が「違反なし」になり、
 * 走査器規約 (b) の fail-closed を破るため、失敗は例外にする。
 */
function envExampleMatches(string $pattern, string $line, int $lineNumber): bool
{
    $matched = preg_match($pattern, $line);
    Assert::notFalse($matched, "行の判定に失敗した (L{$lineNumber}): {$pattern}");

    return $matched === 1;
}

/**
 * 行が妥当な UTF-8 か (不正 UTF-8 は **形式違反**として扱うため、判定は真偽で返す)。
 *
 * ★`mb_check_encoding()` を使わない — `composer.json` は `ext-mbstring` を明示宣言して
 *   いないので、宣言の無い拡張に検査の根幹を依存させない。`preg_match('//u', …)` は
 *   不正 UTF-8 のときだけ `false` + `PREG_BAD_UTF8_ERROR` を返すので、これで代替する。
 *   **それ以外の失敗 (バックトラック上限など) は例外にする** (fail-closed)。
 */
function envExampleIsValidUtf8(string $line, int $lineNumber): bool
{
    $matched = preg_match('//u', $line);
    if ($matched === false) {
        Assert::same(
            preg_last_error(),
            PREG_BAD_UTF8_ERROR,
            "UTF-8 妥当性の判定に失敗した (L{$lineNumber})",
        );

        return false;
    }

    return true;
}

/**
 * 見本ファイルの本文を行単位で解析する (**純粋関数**。ファイルも `env()` も `config()` も読まない)。
 *
 * 行の分類 (**この順序が正典 i3 の文面そのもの**である):
 *   1. 空白だけの行 (半角空白と TAB のみ) → 実効値を作らないので飛ばす
 *   2. コメント行 (`^[ \t]*#`)             → 同上 (**中身は一切見ない**)
 *   3. 不正 UTF-8 を含む行                 → 形式違反 (fail-closed)
 *   4. 禁止文字を含む行                    → 形式違反 (ENV_EXAMPLE_FORBIDDEN_CHARS)
 *   5. 素の代入行 (`^[A-Z][A-Z0-9_]*=`)     → 受理
 *   6. それ以外                            → 形式違反
 *
 * ★3〜4 を 5 より**前**に置く。後に置くと `A=x\x01` が「正常な代入」として受理され、
 *   値の固定の検査が制御文字入りの値を通してしまう。
 * ★これは dotenv の構文検査ではない (dotenv は `export FOO=1` も小文字のキーも読む)。
 *   「見本に許す最小の書式」である。
 * ★重複キーの値は**最初に現れた方**を記録し、2 回目以降は `duplicateKeys` に載せる。
 *   したがって「`values` にキーが在り、かつ `duplicateKeys` に無い」⟺「実代入がちょうど 1 件」
 *   であり、値の固定の検査 (i5) はこの同値性を使う。
 *
 * 改行は CRLF / CR / LF のいずれでも行に割る。値は前後の空白を落とさない
 * (等号の**後ろ**の空白は値の一部)。
 *
 * @return array{
 *   values: array<string, string>,
 *   duplicateKeys: list<string>,
 *   malformedLineNumbers: list<int>,
 * }
 */
function envExampleParseContents(string $contents): array
{
    $lines = preg_split('/\r\n|\r|\n/', $contents);
    Assert::isArray($lines, '見本の本文を行に分割できなかった');
    /** @var list<string> $lines */
    $values = [];
    $duplicateKeys = [];
    $malformedLineNumbers = [];

    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;

        // 1. 空白だけの行 (既定 charlist を使わない — `\0` と `\x0B` を空白として飛ばさないため)
        if (trim($line, " \t") === '') {
            continue;
        }
        // 2. コメント行 (中身は見ない)
        if (envExampleMatches(ENV_EXAMPLE_COMMENT, $line, $lineNumber)) {
            continue;
        }
        // 3. 不正 UTF-8
        if (! envExampleIsValidUtf8($line, $lineNumber)) {
            $malformedLineNumbers[] = $lineNumber;

            continue;
        }
        // 4. 禁止文字 (C0 + TAB + DEL + C1)
        if (envExampleMatches(ENV_EXAMPLE_FORBIDDEN_CHARS, $line, $lineNumber)) {
            $malformedLineNumbers[] = $lineNumber;

            continue;
        }
        // 5. 素の代入行
        $matched = preg_match(ENV_EXAMPLE_ASSIGNMENT, $line, $matches);
        Assert::notFalse($matched, "代入行の判定に失敗した (L{$lineNumber})");
        if ($matched !== 1) {
            $malformedLineNumbers[] = $lineNumber;

            continue;
        }

        $key = $matches[1];
        if (array_key_exists($key, $values)) {
            // 同じキーが 3 回以上でも、重複の一覧にはキー名を 1 度だけ載せる (診断の安定)。
            if (! in_array($key, $duplicateKeys, true)) {
                $duplicateKeys[] = $key;
            }

            continue;
        }
        $values[$key] = $matches[2];
    }

    return [
        'values' => $values,
        'duplicateKeys' => $duplicateKeys,
        'malformedLineNumbers' => $malformedLineNumbers,
    ];
}

/**
 * `.env.example` を読んで解析する (**入出力のアダプタ**。判定は持たない)。
 *
 * @return array{
 *   values: array<string, string>,
 *   duplicateKeys: list<string>,
 *   malformedLineNumbers: list<int>,
 * }
 */
function envExampleParse(): array
{
    $contents = file_get_contents(base_path(ENV_EXAMPLE_PATH));
    Assert::string($contents, '見本ファイルを読めなかった: '.ENV_EXAMPLE_PATH);

    return envExampleParseContents($contents);
}
```

`ENV_EXAMPLE_PATH` は M3 と共用するので新設する:

```php
/** 本 gate の対象 (正典 i1 = そのリポジトリが配るローカル開発用の見本 1 枚)。 */
const ENV_EXAMPLE_PATH = '.env.example';
```

### 反証データセット (i10) — 駆動元を関数へ切り出し R17〜R28 を追加

```php
/**
 * 反証データセット (i10)。**見本を壊さずに「壊れたら赤くなる」ことを示す**ための合成入力。
 *
 * ラベルの先頭の `R<n>` は**恒久の識別子**である (床の検査が集合として突き合わせるので、
 * 番号を詰めたり付け替えたりしない。廃止するときは床の期待値も同じ変更で直す)。
 *
 * @return array<string, array{0: string, 1: array{
 *   values: array<string, string>,
 *   duplicateKeys: list<string>,
 *   malformedLineNumbers: list<int>,
 * }}>
 */
function envExampleParseCounterexamples(): array
{
    return [
        // R1〜R16 は現行のまま (T213 で入れた 16 件。番号も文面も変えない)
        'R1 コメント偽装した代入行は実効値にならない' => [
            '# SESSION_SECURE_COOKIE=true',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
        ],
        // …… R2〜R16 (現行の 15 件をそのまま移設) ……

        // ---- ここから t2 (i3) の追加分 ----
        // R17〜R24 は負例 (値になりうる行の禁止文字と不正 UTF-8)
        'R17 値の中の NUL は形式違反' => [
            "A=1\x00",
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        'R18 キーの側の SOH は形式違反' => [
            "A\x01=1",
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        'R19 値の中の DEL は形式違反' => [
            "A=1\x7F",
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        'R20 値の中の TAB は形式違反 (TAB も C0 制御文字である)' => [
            "A=1\t2",
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        'R21 制御文字だけの行 (VT) は空行として飛ばさない' => [
            "\x0B",
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        'R22 FF で始まる行はコメントとして飛ばさない' => [
            "\x0C# コメント",
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        'R23 C1 (U+0085) を含む値は形式違反' => [
            "A=1\u{0085}",
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        'R24 不正 UTF-8 を含む行は形式違反 (fail-closed)' => [
            "A=\xC3",
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        // R25〜R28 は正例 (厳しくした側が正当な書き方を巻き込んでいないことの裏取り)
        'R25 TAB だけの行は空白行として飛ばす' => [
            "\t",
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
        ],
        'R26 TAB で字下げしたコメント行は違反ではない' => [
            "\t# コメント",
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
        ],
        'R27 コメント行の中の制御文字は沈黙する (保証しない範囲の明示)' => [
            "# a\x00b",
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
        ],
        'R28 妥当な多バイト値を誤検出しない' => [
            'A=日本語',
            ['values' => ['A' => '日本語'], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
        ],
    ];
}

test('反証: 解析器は合成した本文を仕様どおりに分解する', /**
 * 型注記は closure に直接付ける (将来 tests/ を PHPStan の解析対象へ入れても
 * iterable の値の型が欠けないようにするため)。
 *
 * @param array{
 *   values: array<string, string>,
 *   duplicateKeys: list<string>,
 *   malformedLineNumbers: list<int>,
 * } $expected
 */
    function (string $contents, array $expected): void {
        expect(envExampleParseContents($contents))->toBe($expected);
    })->with(envExampleParseCounterexamples());
```

### 反証の床 (i9 の「駆動元が空になったら落ちる検査」)

```php
/** 反証データセットの識別子 (床の検査の期待値。データ駆動の空振りを落とす)。 */
const ENV_EXAMPLE_COUNTEREXAMPLE_IDS = [
    'R1', 'R2', 'R3', 'R4', 'R5', 'R6', 'R7', 'R8', 'R9', 'R10',
    'R11', 'R12', 'R13', 'R14', 'R15', 'R16', 'R17', 'R18', 'R19', 'R20',
    'R21', 'R22', 'R23', 'R24', 'R25', 'R26', 'R27', 'R28',
];

test('床: 反証の駆動元と解析の母集団が空でない', function (): void {
    $cases = envExampleParseCounterexamples();

    // 1. 識別子の集合が期待と完全一致する (1 件消しても増やしても赤くなる)
    $ids = [];
    foreach (array_keys($cases) as $label) {
        $matched = preg_match('/^(R\d+) /', $label, $m);
        Assert::notFalse($matched, "反証のラベルの判定に失敗した: {$label}");
        Assert::same($matched, 1, "反証のラベルが `R<n> ` で始まっていない: {$label}");
        $ids[] = $m[1];
    }
    expect($ids)->toBe(ENV_EXAMPLE_COUNTEREXAMPLE_IDS);

    // 2. 両方向 (違反を出すケースと出さないケース) がどちらも在る
    $withViolation = 0;
    $withoutViolation = 0;
    foreach ($cases as $case) {
        if ($case[1]['malformedLineNumbers'] === [] && $case[1]['duplicateKeys'] === []) {
            $withoutViolation++;

            continue;
        }
        $withViolation++;
    }
    expect($withViolation)->toBeGreaterThan(0)
        ->and($withoutViolation)->toBeGreaterThan(0);

    // 3. 現物の走査の母集団が空でない (走査根の改名・空ファイル化で緑にならない)
    expect(envExampleParse()['values'])->not->toBeEmpty();
});
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`envExampleMatches(): bool` / `envExampleIsValidUtf8(): bool` /
      `envExampleParseContents(): array{…}`)
- [x] null 安全 (`Assert::isArray` / `Assert::notFalse` / `Assert::string` / `Assert::same`)
- [x] DTO を返している — **該当しない** (テストの純粋関数であり配列 shape を `@return` で固定する。
      アプリコードへは配らない)
- [x] Generics の型パラメータ — `list<string>` / `array<string, string>` / `list<int>` を明示

### テスト計画

- [x] 反証データセット (R17〜R28) を**先に足して赤を確認する** (現行の解析器は R17〜R24 を
      malformed にしないため、8 ケースが赤くなる。R25〜R28 は現行でも緑なので、
      **R25〜R28 は「実装後に赤くならないこと」の裏取り**として扱う)
- [x] 床の検査は `ENV_EXAMPLE_COUNTEREXAMPLE_IDS` を先に 28 件で書くことで赤くなる
      (駆動元がまだ 16 件のため)
- [x] 実装後、`ENV_EXAMPLE_FORBIDDEN_CHARS` から `\x09` を一時的に外して R20 が赤くなることを
      確認する / `envExampleIsValidUtf8()` を常に真へ倒して R24 が赤くなることを確認する
      (検出力の裏取り。`red-first-evidence.md` に記録する)
- [x] 個別の `DatabaseTransactions` を使っていない (Architecture lane は DB を触らない)

### リスク

- **見本の変更 0 行の前提が崩れる**: TAB を違反にしたので、将来 `.env.example` に TAB を含む
  値を書くと赤くなる。実測で今日は 0 件であり、書きたくなった時点で
  「本当に TAB が要るのか」をレビューさせるのが意図した摩擦である
- **`preg_match('//u', …)` の副作用**: `preg_last_error()` はプロセス全体の直近のエラーを
  返すため、判定と読み出しの間に他の `preg_*` を挟まない (関数内で連続させる)
- **C1 の検出は妥当な UTF-8 が前提**: 不正 UTF-8 の行は C1 の判定へ進まず、
  先に形式違反として落ちる (どちらでも malformed なので取りこぼしは無い)

---

## M2 台帳の正規化 + 由来・件数の機械検査 (i7/i8/i9) と `APP_ENV` の移送 (i6)

### 変更箇所

- `tests/Architecture/EnvExampleInvariantTest.php`
  - L129-L163 (値の固定の 2 定数 + 合成関数) → **3 定数 + 由来つき entry** へ
  - L165-L260 (必須キーの 4 定数 + 合成関数) → **由来つき entry** へ。`APP_ENV` を削除
  - L262-L282 (検査 a / b) → 正規化した台帳を読む形へ
  - L301-L316 (台帳の誠実性) → **7 規則の純粋関数 + 負のコントロール**へ

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 同ファイル内のみ

### 現行コード (要点)

```php
const ENV_EXAMPLE_VALUE_PINS_AG007_CORE = [
    ['key' => 'SESSION_SECURE_COOKIE', 'value' => 'true'],
    ['key' => 'SESSION_ENCRYPT', 'value' => 'true'],
];
const ENV_EXAMPLE_VALUE_PINS_AICUE = [
    ['key' => 'ADMIN_MFA_REQUIRED', 'value' => 'true'],
    ['key' => 'MCP_STRICT_TRANSPORT', 'value' => 'true'],
];
const ENV_EXAMPLE_REQUIRED_KEYS_SETUP = ['APP_NAME', 'APP_ENV', 'APP_KEY', /* … */];
// …分類ごとに 4 定数。由来は定数の docblock の散文のみ

test('台帳の誠実性: 値の固定とキー網羅の二重登録・台帳の中の重複が無い', function (): void {
    $required = envExampleRequiredKeys();
    $pinKeys = [];
    foreach (envExampleValuePinEntries() as $entry) {
        $pinKeys[] = $entry['key'];
    }
    expect(array_values(array_unique($pinKeys)))->toBe($pinKeys);
    expect(array_values(array_intersect($required, $pinKeys)))->toBe([]);
    expect(array_values(array_unique($required)))->toBe($required);
});
```

**現行の穴**: (1) `APP_ENV` は存在確認だけなので `production` に書き換えても緑 /
(2) 由来が機械検査されない / (3) entry を消して同時に見本のキーを消せば静かに緑。

### 変更後コード

```php
/** 台帳の種別 (i7 / i8 / i9 でいう「種別」)。 */
const ENV_EXAMPLE_KIND_VALUE_PIN = 'value_pin';
const ENV_EXAMPLE_KIND_REQUIRED_KEY = 'required_key';

/**
 * 値の固定 (種別 `value_pin`) の分類 `ag007_core`: 家系の裁定 AG-007 が名指しする 2 件。
 * 緩めるには家系の機能台帳側の裁定変更が要る (本リポジトリ単独では動かせない)。
 *
 * ★形式はキー・値・由来の組の**リスト**にする (キー付きの連想配列にしない)。
 *   連想配列のリテラルは同じ定数の中の重複キーをコンパイル時に後勝ちで無音に潰すため、
 *   「行を足しただけに見える差分」で既存の固定を反転できてしまう。
 *   リストなら重複がそのまま残り、誠実性の検査が同じ機構で捕まえられる。
 */
const ENV_EXAMPLE_VALUE_PINS_AG007_CORE = [
    ['key' => 'SESSION_SECURE_COOKIE', 'value' => 'true', 'origin' => '裁定 AG-007。false だとセッション Cookie が平文 HTTP でも送られる (見本を写した環境が無防備になる)'],
    ['key' => 'SESSION_ENCRYPT', 'value' => 'true', 'origin' => '裁定 AG-007。false だとセッション本体が平文で保管され、撮影 PWA の履歴秘匿の前提が崩れる'],
];

/**
 * 分類 `canonical_t2`: 正典 t2 の i6 (見本の**用途宣言**) が足した 1 件。
 */
const ENV_EXAMPLE_VALUE_PINS_CANONICAL_T2 = [
    ['key' => 'APP_ENV', 'value' => 'local', 'origin' => '見本の用途宣言 (正典 t2 の i6 / s4)。「見本は APP_ENV=local の開発シードだから APP_DEBUG=true を許す」という論拠の根拠側であり、固定しないと論拠が黙って失効する'],
];

/**
 * 分類 `aicue`: 本リポジトリ固有の追加 (裁定で必須とされたものではない純増)。
 */
const ENV_EXAMPLE_VALUE_PINS_AICUE = [
    ['key' => 'ADMIN_MFA_REQUIRED', 'value' => 'true', 'origin' => 'false にすると管理画面の二要素が実質無効になる。local の値が本番へ写る事故の側が危険なので見本は安全側で固定する'],
    ['key' => 'MCP_STRICT_TRANSPORT', 'value' => 'true', 'origin' => 'false にすると Origin を送らないクライアントを受け入れる (DNS 再バインドの面が広がる)'],
];

/**
 * 必須キー (種別 `required_key`) の分類 `setup`:
 * 新しい環境を立てるときに要る座標。`composer setup` と `scripts/setup-worktree.sh` の案内が
 * `.env.example` をそのまま `.env` にするため、ここが欠けると「動かない .env」が出来上がる。
 *
 * ★`APP_ENV` は**値の固定側へ移した** (正典 i6)。存在確認は値の固定が含むので、
 *   両方に載せると誠実性の検査 (キーは台帳全体で一意) が赤くなる。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_SETUP = [
    ['key' => 'APP_NAME', 'origin' => 'アプリ名。config/app.php の app.name の入口で、欠けると画面タイトルとメール差出人名が既定名になる'],
    ['key' => 'APP_KEY', 'origin' => '暗号鍵の座標。空でも提示が要る (composer setup が key:generate で埋める前提の枠)'],
    ['key' => 'APP_URL', 'origin' => '絶対 URL の生成元。presigned URL と SSO の戻り先の組み立てに要る'],
    ['key' => 'APP_LOCALE', 'origin' => '既定ロケール。日本語の現場向け既定を見本で提示する'],
    ['key' => 'DB_CONNECTION', 'origin' => '接続ドライバの選択。pgsql 前提の環境で既定が sqlite に落ちると初回 migrate が別 DB へ走る'],
    ['key' => 'SESSION_DRIVER', 'origin' => 'セッション保管先。撮影 PWA は同一オリジンのセッション認証なので既定の提示が要る'],
    ['key' => 'QUEUE_CONNECTION', 'origin' => 'キュー接続。AI 解析と ffmpeg 合成は非同期ジョブなので既定が sync だと画面が待ち続ける'],
    ['key' => 'CACHE_STORE', 'origin' => 'キャッシュ保管先。FxRateService 等の素データキャッシュの前提'],
];

/**
 * 分類 `production_guard`: 本番の起動時に検査される座標のうち、**現在 `.env.example` に
 * 素の代入行として提示済みのもの**。正本は app/Support/ProductionEnvGuard.php で、
 * 依存は一方向である (guard が変われば本台帳が古くなる。機械では結線しない —
 * guard が読むのは config のキーであって環境変数名ではないため、結ぶには config の構文解析が要る)。
 *
 * ★これは guard の要求の**写しではない**。guard は SECURITY_HSTS_ENABLED /
 *   SECURITY_CSP_ENABLED も本番で true と要求するが、この 2 つは `.env.example` に
 *   1 行も無く、載せるには見本の書き方の判断が要るため本台帳には入れない
 *   (**この 2 件の欠落は検出しない**)。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD = [
    ['key' => 'CIPHERSWEET_KEY', 'origin' => 'ProductionEnvGuard が ciphersweet.providers.string.key の宣言を本番で要求する (PII の暗号化鍵)'],
    ['key' => 'STRIPE_WEBHOOK_SECRET', 'origin' => 'ProductionEnvGuard が cashier.webhook.secret の宣言を本番で要求する (webhook の署名検証)'],
    ['key' => 'DEBUG_LOGIN_USER', 'origin' => 'ProductionEnvGuard が debug.login.user の本番不在を要求する。座標を見本で提示しないと環境ごとに別名が発明される'],
    ['key' => 'DEBUG_LOGIN_PASSWORD', 'origin' => '同上 (debug.login.password)。提示しておくことで「本番では空である」ことがレビューで見える'],
    ['key' => 'PRIMARY_HOST', 'origin' => 'TrustHosts の許可リストの起点。未宣言のまま本番へ出ると ProductionEnvGuard が起動時に落とす'],
    ['key' => 'TRUSTED_HOSTS_ADDITIONAL', 'origin' => 'trusted_hosts.exact_hosts の入口。追加ホストの座標を見本で提示する'],
    ['key' => 'TRUSTED_HOSTS_WILDCARD_SUFFIXES', 'origin' => 'trusted_hosts.wildcard_suffixes の入口。書式不正は起動時 fail-fast なので提示が要る'],
    ['key' => 'TRUSTED_PROXIES', 'origin' => 'AGENTS.md の運用要件 T108。未宣言 / `*` / REMOTE_ADDR は起動時 fail-fast する (初回デプロイ前に設定が要る)'],
    ['key' => 'PASSKEYS_USER_HANDLE_SECRET', 'origin' => 'AGENTS.md の運用要件 (パスキー)。未宣言だと利用者ハンドルが APP_KEY 由来になり、鍵ローテートで登録済みパスキーが全件無効になる'],
];

/**
 * 分類 `integration`: 提示が無いと環境ごとに別の名前が発明されて食い違う座標
 * (外部との統合の秘密と、アプリ固有の座標)。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_INTEGRATION = [
    ['key' => 'STRIPE_KEY', 'origin' => '課金の公開鍵。Cashier の設定の入口'],
    ['key' => 'STRIPE_SECRET', 'origin' => '課金の秘密鍵。座標を提示しないと環境ごとに別名になる'],
    ['key' => 'OPENAI_API_KEY', 'origin' => 'SOP 解析のプロバイダ鍵 (Prism 経由)。使命の中核である AI 解析の入口'],
    ['key' => 'ANTHROPIC_API_KEY', 'origin' => '同上 (プロバイダ切り替えの座標)'],
    ['key' => 'GEMINI_API_KEY', 'origin' => '同上 (プロバイダ切り替えの座標)'],
    ['key' => 'GOOGLE_CLIENT_ID', 'origin' => 'SSO の client id。Socialite の設定の入口'],
    ['key' => 'GOOGLE_CLIENT_SECRET', 'origin' => 'SSO の client secret。同上'],
    ['key' => 'RECAPTCHA_SITE_KEY', 'origin' => '登録フォームの bot 対策の公開鍵'],
    ['key' => 'RECAPTCHA_SECRET_KEY', 'origin' => '同上 (検証側の秘密)'],
    ['key' => 'MCP_ALLOWED_ORIGINS', 'origin' => 'MCP の Origin 許可リスト。MCP_STRICT_TRANSPORT=true の相方で、空だと厳格輸送が実質使えない'],
    ['key' => 'PASSPORT_PRIVATE_KEY', 'origin' => 'OAuth 鍵を env 注入で運用する規約 (storage の鍵ファイルを配らない)'],
    ['key' => 'PASSPORT_PUBLIC_KEY', 'origin' => '同上 (検証側)'],
    ['key' => 'TEMPLATE_APP_SLUG', 'origin' => 'テンプレート由来のアプリ識別子。config/template.php の入口で、欠けると生成物の名前空間が既定値に落ちる'],
    ['key' => 'LEGAL_CONSENT_VERSION', 'origin' => '同意の版。上げ忘れると再同意が要求されないため座標の提示が要る'],
];

/**
 * 分類 `object_storage`: 撮影テイクとレンダ成果物の保管先。本リポジトリ固有の分類である。
 * 撮影 PWA は presigned URL で直接アップロードし、合成した動画も同じ保管先へ置く。
 * ここが欠けた環境では**撮った映像を保存できない** = 使命の中核が動かない。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_OBJECT_STORAGE = [
    ['key' => 'AWS_ACCESS_KEY_ID', 'origin' => 'S3 互換ストレージの資格情報。presigned PUT の署名に要る'],
    ['key' => 'AWS_SECRET_ACCESS_KEY', 'origin' => '同上 (秘密側)'],
    ['key' => 'AWS_DEFAULT_REGION', 'origin' => '署名のリージョン。誤ると presigned URL が 403 になる'],
    ['key' => 'AWS_BUCKET', 'origin' => 'テイクと成果物の保管先バケット。未宣言だと撮影の保存先が無い'],
];

/**
 * 台帳の申告件数 (i9)。**種別ごと**と**分類ごと**の 2 段で申告する。
 *
 * ★摩擦は意図したものである。「見本からキーを消す変更は台帳の entry と申告件数の
 *   両方の更新を要求する」ための設計で、種別の合計だけを申告する形にはしない
 *   (分類をまたいで 1 件ずつ入れ替える差分が合計値のまま緑になり、由来の入れ替えが無音になる)。
 */
const ENV_EXAMPLE_LEDGER_DECLARED_COUNTS = [
    'kinds' => [
        ENV_EXAMPLE_KIND_VALUE_PIN => 5,
        ENV_EXAMPLE_KIND_REQUIRED_KEY => 35,
    ],
    'classifications' => [
        ENV_EXAMPLE_KIND_VALUE_PIN => [
            'ag007_core' => 2,
            'aicue' => 2,
            'canonical_t2' => 1,
        ],
        ENV_EXAMPLE_KIND_REQUIRED_KEY => [
            'integration' => 14,
            'object_storage' => 4,
            'production_guard' => 9,
            'setup' => 8,
        ],
    ],
];

/**
 * 台帳を 1 本のリストへ正規化する (種別・分類・由来・固定値を entry ごとに持つ形)。
 *
 * ★分類名は**定数の割り方から付ける**。entry 側に分類名を書かせると、定数の中身と
 *   分類名が食い違う差分 (「別の定数へ移したのに分類名を直し忘れる」) を作れてしまう。
 * ★`value` は**常に存在するキー**である (必須キーは `null`)。任意キーにすると
 *   「値の固定なのに value の項目が無い」形と「必須キーで null」の形を型で区別できない。
 *
 * @return list<array{key: string, kind: string, classification: string, origin: string, value: string|null}>
 */
function envExampleLedgerEntries(): array
{
    $entries = [];

    /** @var array<string, list<array{key: string, value: string, origin: string}>> $pinGroups */
    $pinGroups = [
        'ag007_core' => ENV_EXAMPLE_VALUE_PINS_AG007_CORE,
        'aicue' => ENV_EXAMPLE_VALUE_PINS_AICUE,
        'canonical_t2' => ENV_EXAMPLE_VALUE_PINS_CANONICAL_T2,
    ];
    foreach ($pinGroups as $classification => $rows) {
        foreach ($rows as $row) {
            $entries[] = [
                'key' => $row['key'],
                'kind' => ENV_EXAMPLE_KIND_VALUE_PIN,
                'classification' => $classification,
                'origin' => $row['origin'],
                'value' => $row['value'],
            ];
        }
    }

    /** @var array<string, list<array{key: string, origin: string}>> $requiredGroups */
    $requiredGroups = [
        'integration' => ENV_EXAMPLE_REQUIRED_KEYS_INTEGRATION,
        'object_storage' => ENV_EXAMPLE_REQUIRED_KEYS_OBJECT_STORAGE,
        'production_guard' => ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD,
        'setup' => ENV_EXAMPLE_REQUIRED_KEYS_SETUP,
    ];
    foreach ($requiredGroups as $classification => $rows) {
        foreach ($rows as $row) {
            $entries[] = [
                'key' => $row['key'],
                'kind' => ENV_EXAMPLE_KIND_REQUIRED_KEY,
                'classification' => $classification,
                'origin' => $row['origin'],
                'value' => null,
            ];
        }
    }

    return $entries;
}

/**
 * 種別で絞った entry の一覧 (検査 a / b の入力)。
 *
 * @return list<array{key: string, kind: string, classification: string, origin: string, value: string|null}>
 */
function envExampleLedgerEntriesOfKind(string $kind): array
{
    return array_values(array_filter(
        envExampleLedgerEntries(),
        static fn (array $entry): bool => $entry['kind'] === $kind,
    ));
}
```

### 台帳の誠実性の検査 (i7 / i8 / i9) — 純粋関数 + 7 規則

```php
/**
 * 台帳自身の誠実性違反 (空なら健全)。**純粋関数**である (ファイルも見本も読まない)。
 *
 * | # | 規則 | 塞ぐ穴 | 正典 |
 * |---|---|---|---|
 * | 1 | entry が 1 件以上ある | 台帳を空にすると全検査が緑になる無言の失効 | i8 (1) |
 * | 2 | キーが `/^[A-Z][A-Z0-9_]*$/` に一致する | 検査対象にならない綴りの登録 | i8 (2) |
 * | 3 | キーが台帳全体で一意 (種別をまたいでも) | 台帳内の重複 / 値の固定と必須キーの二重登録 | i8 (3) |
 * | 4 | 種別が既知の 2 つのいずれかである | 綴り違いの種別が数え上げから漏れる | i8 (4) |
 * | 5 | `value_pin` は非空の固定値を持ち改行も禁止文字も含まない。`required_key` は値を持たない | 種別と値の取り違え | i8 (4) |
 * | 6 | 分類が申告 map に在る名前である | 未申告の分類の混入 (件数の照合をすり抜ける) | i7 / i9 |
 * | 7 | 由来が trim 後に非空である | 由来不明の entry の堆積 | i7 |
 * | 8 | 種別ごとの実件数が申告と一致し、分類ごとの実 map が申告 map と完全一致し、分類 map の合計が種別の申告と一致する | 静かな削除 / 分類の増減・改名 / 申告の片側だけの修正 | i9 |
 *
 * ★申告 map 自身の健全性も見る (キー集合が既知の 2 種別と完全一致 / 申告値が 1 以上)。
 *   申告を空にして緑にする迂回を塞ぐためである。
 * ★**保証しないもの**: 由来の**長さと内容**は見ない (trim 後に非空であることだけを見る)。
 *   台帳の内容が見本と一致するかは本関数の担当ではない (検査 a / b が見る)。
 *
 * @param  list<array{key: string, kind: string, classification: string, origin: string, value: string|null}>  $entries
 * @param  array{kinds: array<string, int>, classifications: array<string, array<string, int>>}  $declared
 * @return list<string>
 */
function envExampleLedgerViolations(array $entries, array $declared): array
{
    $kinds = [ENV_EXAMPLE_KIND_VALUE_PIN, ENV_EXAMPLE_KIND_REQUIRED_KEY];
    $violations = [];

    // 規則 1
    if ($entries === []) {
        $violations[] = '台帳に entry が 1 件も無い';
    }

    // 申告 map 自身の健全性 (キー集合が既知の種別と完全一致し、値が 1 以上であること)
    $declaredKinds = $declared['kinds'];
    $declaredClassifications = $declared['classifications'];
    ksort($declaredKinds);
    $expectedKinds = $kinds;
    sort($expectedKinds);
    if (array_keys($declaredKinds) !== $expectedKinds) {
        $violations[] = '種別の申告のキー集合が既知の種別と一致しない';
    }
    if (array_keys($declaredClassifications) !== $expectedKinds) {
        // ksort 済みの比較にするため、呼び出し側の定数も種別名の昇順で書く
        $violations[] = '分類の申告のキー集合が既知の種別と一致しない';
    }
    foreach ($declaredKinds as $kind => $count) {
        if ($count < 1) {
            $violations[] = "種別 {$kind} の申告件数が 1 未満である";
        }
    }
    foreach ($declaredClassifications as $kind => $map) {
        foreach ($map as $classification => $count) {
            if (trim((string) $classification) === '') {
                $violations[] = "種別 {$kind} に空白のみの分類名の申告がある";
            }
            if ($count < 1) {
                $violations[] = "分類 {$kind}/{$classification} の申告件数が 1 未満である";
            }
        }
    }

    $keyOccurrences = [];
    $actualKindCounts = array_fill_keys($kinds, 0);
    $actualClassificationCounts = array_fill_keys($kinds, []);

    foreach ($entries as $entry) {
        $key = $entry['key'];
        $kind = $entry['kind'];

        // 規則 2
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
            $violations[] = "{$key}: キーの綴りが env の代入行として成立しない";
        }
        // 規則 3 (件数は後でまとめて判定する)
        $keyOccurrences[$key] = ($keyOccurrences[$key] ?? 0) + 1;

        // 規則 4
        if (! in_array($kind, $kinds, true)) {
            $violations[] = "{$key}: 未知の種別 {$kind} である";

            continue;
        }
        $actualKindCounts[$kind]++;

        // 規則 5
        if ($kind === ENV_EXAMPLE_KIND_VALUE_PIN) {
            $value = $entry['value'];
            if ($value === null || $value === '') {
                $violations[] = "{$key}: 値の固定なのに固定値が無い";
            } elseif (
                str_contains($value, "\n")
                || str_contains($value, "\r")
                || preg_match(ENV_EXAMPLE_FORBIDDEN_CHARS, $value) === 1
            ) {
                $violations[] = "{$key}: 固定値に改行または禁止文字が含まれている";
            }
        } elseif ($entry['value'] !== null) {
            $violations[] = "{$key}: 値を持てない種別 ({$kind}) に固定値がある";
        }

        // 規則 6
        $classification = $entry['classification'];
        if (! array_key_exists($classification, $declaredClassifications[$kind] ?? [])) {
            $violations[] = "{$key}: 分類 {$classification} が種別 {$kind} の申告に無い";
        }
        $actualClassificationCounts[$kind][$classification]
            = ($actualClassificationCounts[$kind][$classification] ?? 0) + 1;

        // 規則 7
        if (trim($entry['origin']) === '') {
            $violations[] = "{$key}: 由来 (origin) が空である";
        }
    }

    // 規則 3
    foreach ($keyOccurrences as $key => $occurrences) {
        if ($occurrences > 1) {
            $violations[] = "{$key} が台帳に {$occurrences} 回現れる (種別をまたいだ重複も禁止)";
        }
    }

    // 規則 8
    foreach ($kinds as $kind) {
        $declaredCount = $declaredKinds[$kind] ?? null;
        if ($declaredCount !== $actualKindCounts[$kind]) {
            $violations[] = sprintf(
                '種別 %s の申告件数 %s と実件数 %d が一致しない',
                $kind,
                var_export($declaredCount, true),
                $actualKindCounts[$kind],
            );
        }

        $declaredMap = $declaredClassifications[$kind] ?? [];
        $actualMap = $actualClassificationCounts[$kind];
        ksort($declaredMap);
        ksort($actualMap);
        if ($declaredMap !== $actualMap) {
            $violations[] = sprintf(
                '種別 %s の分類ごとの件数が申告と一致しない (申告 %s / 実測 %s)',
                $kind,
                json_encode($declaredMap, JSON_UNESCAPED_UNICODE) ?: '?',
                json_encode($actualMap, JSON_UNESCAPED_UNICODE) ?: '?',
            );
        }
        if (array_sum($declaredMap) !== $declaredCount) {
            $violations[] = sprintf(
                '種別 %s の分類ごとの件数の合計 %d が種別の申告 %s と一致しない',
                $kind,
                array_sum($declaredMap),
                var_export($declaredCount, true),
            );
        }
    }

    return $violations;
}
```

### 検査 a / b の更新

```php
test('a: .env.example は安全側の既定値を実代入ちょうど 1 件 + 値の完全一致で満たす', function (): void {
    $parsed = envExampleParse();

    // 失敗時に出すのは**キー名だけ**である (見本の実値を出力しない)。
    $violations = [];
    foreach (envExampleLedgerEntriesOfKind(ENV_EXAMPLE_KIND_VALUE_PIN) as $entry) {
        // 「values に在り、かつ duplicateKeys に無い」= 実代入ちょうど 1 件 (解析器の docblock 参照)。
        // 重複そのものは c-2 も落とすが、i5 は**単独で**成立させる (i4 が将来消えても効くようにする)。
        if (in_array($entry['key'], $parsed['duplicateKeys'], true)) {
            $violations[] = $entry['key'].' (実代入が 2 件以上)';

            continue;
        }
        if (($parsed['values'][$entry['key']] ?? null) !== $entry['value']) {
            $violations[] = $entry['key'].' (値が固定と一致しない、または不在)';
        }
    }

    expect($violations)->toBe([]);
});

test('b: .env.example は必須キーの台帳を網羅する', function (): void {
    $parsed = envExampleParse();

    $required = array_map(
        static fn (array $entry): string => $entry['key'],
        envExampleLedgerEntriesOfKind(ENV_EXAMPLE_KIND_REQUIRED_KEY),
    );
    $missing = array_values(array_diff($required, array_keys($parsed['values'])));

    expect($missing)->toBe([]);
});

test('台帳の誠実性: 種別・分類・由来・件数の申告が整合する', function (): void {
    expect(envExampleLedgerViolations(envExampleLedgerEntries(), ENV_EXAMPLE_LEDGER_DECLARED_COUNTS))
        ->toBe([]);
});
```

### 誠実性の検査の負のコントロール (走査器規約 (c))

```php
/**
 * 誠実性の検査の負のコントロール (V1〜V10) と正のコントロール (V11)。
 *
 * 合成した台帳を `envExampleLedgerViolations()` へ直接食わせる (現物の台帳を壊さずに
 * 「壊れたら赤くなる」ことを示す)。期待値は**違反メッセージの部分一致**で持つ
 * (件数だけを見ると、別の規則が偶然発火しても緑になるため)。
 *
 * @return array<string, array{0: list<array{key: string, kind: string, classification: string, origin: string, value: string|null}>, 1: array{kinds: array<string, int>, classifications: array<string, array<string, int>>}, 2: string|null}>
 */
function envExampleLedgerCounterexamples(): array
{
    // 最小の健全な台帳 (V11 の正のコントロールと、各負例の素材)
    $soundEntries = [
        ['key' => 'A_PIN', 'kind' => ENV_EXAMPLE_KIND_VALUE_PIN, 'classification' => 'pins', 'origin' => '由来', 'value' => 'true'],
        ['key' => 'B_REQUIRED', 'kind' => ENV_EXAMPLE_KIND_REQUIRED_KEY, 'classification' => 'keys', 'origin' => '由来', 'value' => null],
    ];
    $soundDeclared = [
        'kinds' => [ENV_EXAMPLE_KIND_VALUE_PIN => 1, ENV_EXAMPLE_KIND_REQUIRED_KEY => 1],
        'classifications' => [
            ENV_EXAMPLE_KIND_VALUE_PIN => ['pins' => 1],
            ENV_EXAMPLE_KIND_REQUIRED_KEY => ['keys' => 1],
        ],
    ];

    return [
        'V1 空の台帳' => [[], ['kinds' => [ENV_EXAMPLE_KIND_VALUE_PIN => 0, ENV_EXAMPLE_KIND_REQUIRED_KEY => 0], 'classifications' => [ENV_EXAMPLE_KIND_VALUE_PIN => [], ENV_EXAMPLE_KIND_REQUIRED_KEY => []]], '台帳に entry が 1 件も無い'],
        'V2 代入行として成立しない綴りのキー' => [/* A_PIN を 'a_pin' に差し替えた entries */, $soundDeclared, 'キーの綴りが env の代入行として成立しない'],
        'V3 種別をまたいだ二重登録' => [/* 同じキーを両種別に持つ entries + 申告を合わせた declared */, /* … */, '台帳に 2 回現れる'],
        'V4 値の固定に固定値が無い' => [/* value を null にした entries */, $soundDeclared, '値の固定なのに固定値が無い'],
        'V5 必須キーに固定値がある' => [/* B_REQUIRED に value を入れた entries */, $soundDeclared, '値を持てない種別'],
        'V6 由来が空白のみ' => [/* origin を "  " にした entries */, $soundDeclared, '由来 (origin) が空である'],
        'V7 未申告の分類' => [/* classification を 'unknown' にした entries */, $soundDeclared, 'の申告に無い'],
        'V8 種別の申告件数が実件数と違う' => [$soundEntries, /* value_pin を 2 に増やした declared */, 'の申告件数'],
        'V9 分類の申告 map が実測と違う' => [$soundEntries, /* classifications を別名にした declared */, '分類ごとの件数が申告と一致しない'],
        'V10 分類 map の合計が種別の申告と違う' => [$soundEntries, /* value_pin => 2 かつ pins => 1 の declared */, '合計'],
        'V11 健全な台帳 (正のコントロール)' => [$soundEntries, $soundDeclared, null],
    ];
}

test('負のコントロール: 誠実性の検査は壊れた台帳を検出し、健全な台帳を誤検出しない', /**
 * @param  list<array{key: string, kind: string, classification: string, origin: string, value: string|null}>  $entries
 * @param  array{kinds: array<string, int>, classifications: array<string, array<string, int>>}  $declared
 */
    function (array $entries, array $declared, ?string $expected): void {
        $violations = envExampleLedgerViolations($entries, $declared);

        if ($expected === null) {
            expect($violations)->toBe([]);

            return;
        }

        expect($violations)->not->toBe([]);
        $matched = array_filter(
            $violations,
            static fn (string $violation): bool => str_contains($violation, $expected),
        );
        expect($matched)->not->toBe([], "期待した違反が出ていない: {$expected} / 実際: ".implode(' | ', $violations));
    })->with(envExampleLedgerCounterexamples());
```

> **実装時の注意**: 上のコメント `/* … */` の箇所は実装で具体値を書く
> (設計書では差分だけを示している)。V2〜V10 は `$soundEntries` / `$soundDeclared` の
> **複製に 1 か所だけ手を入れる**形で書き、複数の規則が同時に発火する形を避ける
> (V10 だけは種別の申告と合計の両方が食い違うので、期待値は合計側のメッセージで固定する)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`envExampleLedgerEntries(): list<array{…}>` /
      `envExampleLedgerViolations(): list<string>`)
- [x] null 安全 — `value` は `string|null` を宣言し、規則 5 が種別との整合を見る。
      `$declared['classifications'][$kind] ?? []` で未定義添字を作らない
- [x] DTO を返している — 該当しない (テスト内の配列 shape。`@param` / `@return` で固定)
- [x] Generics の型パラメータ — `array<string, int>` / `array<string, array<string, int>>` を明示

### テスト計画

- [x] `台帳の誠実性` を新形式へ書き換えて**先に赤を確認**する (台帳が旧形式のまま = 型不一致で落ちる)
- [x] 負のコントロール V1〜V11 を**先に足して赤を確認**する (`envExampleLedgerViolations()` 未実装)
- [x] `APP_ENV` の移送後に検査 a が緑になること / 移送前は誠実性の規則 3 が赤くなることを確認する
      (`APP_ENV` を両方に載せた状態を一時的に作って裏取りし、`red-first-evidence.md` に記録する)
- [x] 既存の検査 a / b / c-1 / c-2 は**名前を変えずに残す** (a のタイトルだけ i5 の文言へ更新)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- **台帳の記述量が増える** (35 + 5 件の由来)。由来の質は機械では見られない (trim 非空のみ)。
  これは正典 i7 が要求する範囲であり、質はレビューの責務として docblock に明記する
- **件数の申告は摩擦である**。見本からキーを消す変更が 3 か所 (見本・entry・申告) の更新を
  要求する。正典 i9 が「意図した摩擦」と明記しているので受け入れる
- **`ENV_EXAMPLE_LEDGER_DECLARED_COUNTS` の分類名は昇順で書く**必要がある
  (誠実性の検査が `ksort` 後に比較するため。順序違いで落ちることはないが、
  申告 map のキー集合の比較は `array_keys()` の順序に依存するので種別名も昇順で書く)

---

## M3 検査の前提の実行時確認 (i12)

### 変更箇所

- `tests/Architecture/EnvExampleInvariantTest.php` の末尾 (新規テスト 1 本)

### 波及変更

なし (TypeScript / DTO / 他テストへの影響なし)

### 現行コード

該当なし (`tests/` に env ファイル名を実行時に確認する表明は 1 本も無い。
`grep -rn "loadEnvironmentFrom\|environmentFilePath"` で出るのは
`tests/Support/ExternalFakes/fake-wiring-probe.php` と
`tests/Support/Concurrency/idempotency-claim-probe.php` の**別プロセス probe 用**の指定のみ)。

### 変更後コード

```php
/*
 * 検査の前提そのものの固定 (正典 i12)。見本の値がテスト実行時の env として効いていたら、
 * 「見本を検査している」という主張が反転しうる (見本を書き換えれば実行時の設定も動く)。
 *
 * ★主張は「**この リポジトリの見本 1 枚を env として選んでいない**」ことに限る。
 *   許可する env 名の集合までは固定しない (`.env.ci` のような正当な env 名を足しただけで
 *   落ちるのは過剰である)。
 * ★2 段で見る。(1) 解決済みの絶対パスの一致、(2) ファイル名の一致。(2) は別ディレクトリの
 *   同名の見本を経由する形まで拒む「拾いすぎる側」の検査で、走査器規約 (b) の
 *   「見逃す方向へ倒すのは不可」に従って併置する。
 * ★見本の `realpath()` が解決できないことは合格にせず**不合格**にする (fail-closed)。
 *   symlink は `realpath()` が解決した先で比べる (リンク越しに見本を env にする形も落ちる)。
 */
test('前提: テスト実行時に読み込まれている env ファイルが見本ではない', function (): void {
    $samplePath = realpath(base_path(ENV_EXAMPLE_PATH));
    Assert::string($samplePath, '見本ファイルの実パスを解決できない: '.ENV_EXAMPLE_PATH);

    $loadedPath = app()->environmentFilePath();
    $loadedReal = realpath($loadedPath);

    // (1) 絶対パスの一致 (解決できない env は「まだ存在しない env」なので生の文字列で比べる)
    expect(is_string($loadedReal) ? $loadedReal : $loadedPath)->not->toBe($samplePath);

    // (2) ファイル名の一致 (別ディレクトリの同名見本も拒む)
    expect(basename($loadedPath))->not->toBe(ENV_EXAMPLE_PATH);
});
```

### PHPStan適合チェック

- [x] 戻り値の型 — テストの closure は `void`
- [x] null 安全 — `realpath()` の `false` を `Assert::string` / `is_string()` で処理し、
      `false` を文字列比較へ流さない
- [x] DTO — 該当なし
- [x] Generics — 該当なし

### テスト計画

- [x] 先に本テストを足して**赤を確認**する方法: `ENV_EXAMPLE_PATH` の値を一時的に
      `.env.testing` へ差し替えると赤くなる (= 見本が読まれていたら赤くなることの裏取り)。
      `red-first-evidence.md` に記録する
- [x] 通常実行では `.env.testing` が読まれるため緑になる
      (`phpunit.xml` の `<server name="APP_ENV" value="testing" force="true"/>` + `.env.testing` 実在)

### リスク

- **`environmentFilePath()` は「読む場所の指定」であって「実際に読んだ結果」ではない**。
  Laravel は `LoadEnvironmentVariables` が `APP_ENV` に応じて `loadEnvironmentFrom()` を
  呼ぶので、起動後の値は実際に選ばれた env を指す。ただし **`.env` が存在しない場合も
  同じ値を返す**ので、「そのファイルが実在した」ことは主張できない。docblock に書く
- **他テストが同一プロセスで `loadEnvironmentFrom()` を呼ぶと結果が変わりうる**。
  現状の 2 か所は**別プロセスの probe 用のスクリプト**なので同一プロセスの状態は動かさない
  (実測で確認済み)。将来同一プロセスで差し替える形が入ったら本テストが赤くなる = 望ましい挙動

---

## M4 docblock の更新 (i13 / 走査対象と保証しないもの)

### 変更箇所

- `tests/Architecture/EnvExampleInvariantTest.php` L5-L41 (ファイル冒頭の docblock)

### 変更後コード (骨子)

```php
/*
 * `.env.example` の不変条件 (家系の機能台帳 lctl の feature gate-env-example-sync の**正典 t2**)。
 *
 * このファイルは「読み物」ではなく**生きた既定値**である。3 つの経路が見本を
 * そのまま実環境にする — `composer setup` / composer.json の post-root-package-install /
 * scripts/setup-worktree.sh の復旧案内。よって見本の欠落・危険な値は
 * 「文書の不備」ではなく**実環境の不備**になる。
 *
 * 検査は 4 部品 + 4 つ:
 *   (a)   値の固定    — 実代入ちょうど 1 件 + 値の完全一致 (部分一致・コメント偽装を封鎖)
 *   (b)   キー網羅    — 必須キーを台帳に持ち、存在を要求する (値は見ない)
 *   (c-1) 行の形式    — 非空・非コメント行は素の `KEY=` 形式のみ受理する
 *                       (制御文字・TAB・不正 UTF-8 を含む行は形式違反)
 *   (c-2) 重複        — 代入キーが全キー一意であることを要求する
 *   + 台帳の誠実性 (種別・分類・由来・件数の申告の整合。7 規則)
 *   + 反証の検査 (壊した入力を合成して解析器へ食わせる。R1〜R28)
 *   + 負のコントロール (壊した台帳を合成して誠実性の検査へ食わせる。V1〜V11)
 *   + 前提の固定 (テスト実行時に読まれている env が見本でないこと)
 *
 * ★**走査対象**: `.env.example` の**中身だけ**である (git 追跡下の 1 ファイル)。
 *
 * ★本ファイルには**受理規則が逆向きの解析器が 2 つ同居する**。統合しない
 *   (統合すると片方の意図が壊れる):
 *
 *   |                        | envExampleParseContents (下)   | collectUnresolvedEnvRefs (末尾) |
 *   |------------------------|--------------------------------|---------------------------------|
 *   | 対象                   | `.env.example` の 1 枚だけ     | 見本 3 枚                       |
 *   | `export` つきの行       | **違反にする**                 | 意図的に許容する                |
 *   | 先頭に空白のある代入   | **違反にする**                 | 意図的に許容する                |
 *   | 制御文字 / 不正 UTF-8   | **違反にする**                 | 見ない                          |
 *   | 見るもの               | キーと値・重複・行の形         | 値の中の `${VAR}` の解決可能性  |
 *
 *   `.env.example` については厳しい方 (行の形式の検査) が先に赤くなるので、
 *   緩い側の許容は残り 2 枚にしか意味を持たない。
 *
 * ★保証しないもの (誇張しない):
 *   1. 見るのは `.env.example` の中身だけで、実行中の `.env`・プロセスの環境変数・
 *      設定キャッシュには**無言で効かない**
 *   2. キー網羅は**存在だけ**を見る (空の値も通る)
 *   3. config の既定値と見本の値の一致は見ない (**同期の検査ではなく提示の検査**である)
 *   4. 台帳に載せていない要求の欠落は検出しない。`SECURITY_HSTS_ENABLED` /
 *      `SECURITY_CSP_ENABLED` は本番起動時に要求されるが見本に 1 行も無く、
 *      **この 2 件の欠落は検出しない**
 *   5. **見本をそのまま本番へ写す運用は検出しない** (`APP_ENV` ごと写るため。
 *      そこは本番起動時の検査 = `ProductionEnvGuard` の担当である)
 *   6. **TAB は「空白だけの行」と「コメントの字下げ」でのみ許容する**。
 *      値になりうる行の TAB は形式違反である
 *   7. **コメント行の中身は一切見ない** (制御文字も不正 UTF-8 も沈黙する)
 *   8. **不可視文字 (U+200B / U+FEFF 等) は対象外**である (正典が求めるのは制御文字。
 *      不可視文字の無害化は prompt 防御の窓口の責務)
 *   9. 前提の固定が主張するのは「**この見本を env として選んでいない**」ことだけで、
 *      許可する env 名の集合は固定しない。また `environmentFilePath()` は
 *      「読む場所の指定」なので**そのファイルが実在したこと**は主張しない
 *  10. 由来 (origin) の**長さと内容**は見ない (trim 後に非空であることだけを見る)
 *
 * ★負例の置き場: 本ファイル内の合成入力 2 系統 —
 *   `envExampleParseCounterexamples()` (解析器) と `envExampleLedgerCounterexamples()` (台帳)。
 *
 * 正典: lctl feature gate-env-example-sync / canonical_version t2 (2026-08-22 確定)
 * 設計: devnotes/20260824-1014-gate-env-example-sync-t2/
 *       (t1 化は devnotes/20260817-1309-todo-t213-env-example-gate-t1/)
 */
```

### テスト計画

- docblock は機械検査の対象外だが、**「保証しないもの」の 10 項目は本ファイルが正本**である
  (AGENTS.md / `docs/` へ写さない = 2 か所に書くと必ず食い違う)
- i13 の 4 要求 (実行中の env に効かない / 存在だけを見る / config との一致を見ない /
  台帳外の欠落は検出しない) が 1〜4 に対応することをレビューで確認する

### リスク

- docblock の更新漏れ。M1〜M3 の実装と同じコミットで書く (後回しにしない)

---

## M5 乖離台帳の更新 (テンプレートと共有するファイルを変えるため必須)

### 変更箇所

- `docs/template-divergence.md` — 末尾に **D50** を追加。冒頭の「登録エントリ: 46 件」→ 47 件
- `tests/Support/TemplateDivergence/LedgerPins.php` —
  `DIVERGENCE_ENTRY_COUNT` 46→47 / `ADOPTION_DEBT_COUNT` 148→147
- `tests/Support/TemplateDivergence/adoption-debt.tsv` —
  `tests/Architecture/EnvExampleInvariantTest.php	d672f63c…` の**1 行を削除**

### なぜ必要か (突合 gate の機序)

`FingerprintReconciler::reconcile()` は債務パスについて
「現在のハッシュ === 採用時ハッシュ」なら許容し、それ以外 (テンプレート一致へ戻った場合を除く) を
`mutatedDebtPaths` に入れる。本改修は債務パスの内容を変えるので **F10 が必ず赤くなる**。
また債務一覧と逸脱の登録が同じパスを持つと `doubleDeclaredPaths` で落ちるため、
**登録を足すのと債務行を削るのは同じ変更でなければならない**。

### D50 の登録 (実装時にこのまま貼る)

```markdown
## D50 `.env.example` の gate を家系の正典 t2 まで進める (テンプレートは t1)

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Architecture/EnvExampleInvariantTest.php` |
| 業務要件起因の説明 | 撮影 PWA は同一オリジンのセッション認証で動き、見本ファイルは `composer setup` と worktree の復旧案内でそのまま `.env` になるため、`SESSION_SECURE_COOKIE` / `SESSION_ENCRYPT` / `ADMIN_MFA_REQUIRED` / `MCP_STRICT_TRANSPORT` の既定値と保管先の座標が見本の段で崩れると実環境がそのまま無防備になる。家系の機能台帳が 2026-08-22 に確定した正典 t2 は t1 に 9 点を足しており、本アプリは 5 点 (`APP_ENV` の固定・制御文字の禁止・台帳 entry ごとの由来の機械検査・件数の申告・前提の実行時確認) を先に採る。テンプレート側は同じ feature で追従待ち (t1) のため、追従の順序として差分が生じる |
| 揃え続ける不変条件と保証機構 | 値の固定は実代入ちょうど 1 件 + 値の完全一致で見る (検査 a) / 必須キーは素の代入行としての存在を見る (検査 b) / 非空・非コメント行は素の `KEY=` 形式のみ受理し、制御文字 (C0 + TAB + DEL + C1) と不正 UTF-8 を含む行を形式違反にする (検査 c-1) / 代入キーは全キー一意 (検査 c-2) / 台帳自身の誠実性を 7 規則で見る (種別・分類・由来の非空・種別ごとと分類ごとの件数の申告と実件数の一致) / 壊れたら赤くなることを解析器の反証 28 件と台帳の負のコントロール 11 件で示す / 見本の値の `${VAR}` は先行定義だけを許す / テスト実行時に読まれている env が見本でないことを実行時に確かめる |
| 再判定の条件 | テンプレート側が正典 t2 以降へ追従して同じ不変条件集合を持ったとき (差分が消えるので登録を削る) / 家系の機能台帳が正典を t3 以降へ進めたとき / 対象を見本 1 枚から広げる判断 (正典の未決論点 q1 / q2 の決着) が入ったとき |
| 決めた日 | 2026-08-24 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260824-1014-gate-env-example-sync-t2/ |
| 状態 | 監視中 |
| 見直し期限 | 2027-08-24 |

| 観点 | テンプレート (正典 t1) | 本アプリ (正典 t2) |
|---|---|---|
| 値の固定の判定 | 行の完全一致 (先勝ちで解析) + 全キー一意 | 実代入ちょうど 1 件 + 値の完全一致 (i4 が消えても単独で成立する) |
| `APP_ENV` | 必須キーの存在確認のみ | `local` を値ごと固定 (見本の用途宣言) |
| 制御文字 | 見ない (受理正規表現が素通し) | C0 + TAB + DEL + C1 と不正 UTF-8 を形式違反にする |
| 台帳の由来 | 定数の docblock に散文で書く | entry ごとに `origin` を持ち、非空を機械検査する |
| 件数の申告 | 無い | 種別ごと + 分類ごとに申告し、実件数と完全一致を要求する |
| 反証 | 無い | 解析器 28 件 + 台帳 11 件 (負例と正例の両方向) |
| 前提の固定 | 無い | 実行時に読まれている env が見本でないことを 2 段で確認する |

### なぜ正当な差分か (logic-driven)

1. **追従の順序としての差分である**。家系の機能台帳 (lctl) の feature
   `gate-env-example-sync` は 2026-08-22 に正典を t2 へ確定し、テンプレートを含む 6 本すべてを
   `update_pending` に置いた。本アプリが先に t2 へ進む形は正典が想定した追従経路そのものであり、
   テンプレートが追いついた時点で差分は消える (だから状態は `恒久` ではなく `監視中` である)。
2. **見本がそのまま実環境になる経路が本アプリに 3 本ある**。`composer setup` /
   post-root-package-install / `scripts/setup-worktree.sh` の復旧案内が見本を `.env` にするので、
   見本の検査の緩さは「文書の不備」ではなく実環境の不備として現れる。
   本アプリは撮影 PWA のセッション秘匿を 3 枚セット (no-store baseline / bfcache 秘匿 /
   Inertia 履歴暗号化) で守っており、その土台がセッション Cookie の既定値である。
3. **見本ファイル自体は 1 行も変えていない**。差分は検査側だけである
   (実測: 代入 81 行 / 形式違反 0 / 重複 0 / 制御文字 0 / 不正 UTF-8 0 / `APP_ENV=local`)。

### 保証しないもの

- 保証しない範囲の正本は `tests/Architecture/EnvExampleInvariantTest.php` の docblock である
  (ここには写さない。2 か所に書くと必ず食い違う)
- テンプレート側の追従の有無は本アプリでは検出できない (指紋台帳は取り込んだ時点の写しである)

### 関連

- 実装: `tests/Architecture/EnvExampleInvariantTest.php`
- 設計: `devnotes/20260824-1014-gate-env-example-sync-t2/` (t1 化は `devnotes/20260817-1309-todo-t213-env-example-gate-t1/`)
- 家系: lctl feature `gate-env-example-sync` (`canonical_version: t2` / 2026-08-22 確定)
```

### テスト計画

- [x] `composer test -- --filter=TemplateDivergence` で F9 (3a/3b) / F10 (債務) /
      F11 (債務の件数) / F13 (書式) が緑になることを確認する
- [x] `TemplateDivergenceLedgerFormatTest` の 3 点一致 (宣言行「登録エントリ: 47 件」/
      見出しの実数 47 / `LedgerPins::DIVERGENCE_ENTRY_COUNT` = 47) を満たす
- [x] **番号 D50 は再利用ではない** (現在の最大は D49。欠番は詰めない)
- [x] 債務一覧の書式 (先頭行の世代識別子ヘッダ / タブ 2 列 / パスの昇順 / 末尾改行) を壊さない
      — 削除は 1 行の除去だけで、ヘッダと並び順に影響しない

### リスク

- **`ADOPTION_DEBT_COUNT` と実件数のずれ**: F11 が完全一致で落ちるので、
  行の削除と定数の減算を同じ変更で行う
- **状態を `恒久` にしない**: テンプレートが追いつけば差分は消えるので `監視中` + 期限が正しい。
  期限は基準日から 400 日以内 (2027-08-24 = 365 日) である

---

## 最終ファイル構成 (実装後の `tests/Architecture/EnvExampleInvariantTest.php` の並び)

1. `declare(strict_types=1);` / `use Webmozart\Assert\Assert;`
2. ファイル冒頭の docblock (M4)
3. `ENV_EXAMPLE_PATH` / `ENV_EXAMPLE_FORBIDDEN_CHARS` / `ENV_EXAMPLE_ASSIGNMENT` / `ENV_EXAMPLE_COMMENT`
4. `envExampleMatches()` / `envExampleIsValidUtf8()` / `envExampleParseContents()` / `envExampleParse()` (M1)
5. 種別の定数 / 値の固定 3 定数 / 必須キー 4 定数 / 申告件数 / `envExampleLedgerEntries()` /
   `envExampleLedgerEntriesOfKind()` (M2)
6. `envExampleLedgerViolations()` (M2)
7. 検査 a / b / c-1 / c-2 / 台帳の誠実性 (M2)
8. `envExampleParseCounterexamples()` + 反証の検査 + 床の検査 (M1)
9. `envExampleLedgerCounterexamples()` + 負のコントロール (M2)
10. 前提の固定 (M3)
11. `ENV_EXTERNAL_REF_ALLOWLIST` / `collectUnresolvedEnvRefs()` / `${VAR}` の検査 (**現行のまま**)

## テストファースト手順 (赤の順序。実装セッションはこの順に進める)

| 段 | 変更 | 期待される赤 |
|---|---|---|
| 1 | 反証 R17〜R28 を追加 (駆動元を関数へ切り出す) | R17〜R24 の 8 ケースが赤 (現行の解析器は malformed にしない) |
| 2 | 床の検査を追加 (`ENV_EXAMPLE_COUNTEREXAMPLE_IDS` を 28 件で宣言) | 段 1 を入れる前なら床も赤 |
| 3 | 台帳の誠実性を新形式へ / 負のコントロール V1〜V11 を追加 | `envExampleLedgerViolations()` 不在で赤 |
| 4 | 前提の固定 (M3) を追加 | `ENV_EXAMPLE_PATH` 不在で赤 (実装後に緑) |
| 5 | M1 の解析器・M2 の台帳・`APP_ENV` の移送を実装 | 全緑へ |
| 6 | 検出力の裏取り (一時的に壊して赤を確認 → 戻す) | `\x09` を外す → R20 赤 / UTF-8 判定を真固定 → R24 赤 / 件数の申告を 1 増やす → 誠実性が赤 / `ENV_EXAMPLE_PATH` を `.env.testing` へ → M3 が赤 |
| 7 | M5 (乖離台帳) を同じコミットで更新 | 突合 gate の F10 / F11 が緑へ |
| 8 | `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` | 全 green |

段 6 の記録は `devnotes/20260824-1014-gate-env-example-sync-t2/red-first-evidence.md` に残す。

## migration / 後方互換の扱い

- **DB migration は無い** (テストのみの変更。モデル・スキーマに触れない)
- **後方互換の並走を残さない** (AGENTS.md 思考原則 3):
  - 旧形式の台帳定数 (`list<string>` の必須キー / 由来なしの固定 entry) は**同じコミットで消す**。
    新旧を並べた互換関数 (`envExampleRequiredKeys()` の旧 shape を返す層など) は作らない
  - `envExampleRequiredKeys()` / `envExampleValuePinEntries()` は
    `envExampleLedgerEntriesOfKind()` へ**置き換えて削除する** (別名で残さない)
  - `APP_ENV` は**移送**である。必須キー側に残したまま値の固定へ足す形は、誠実性の規則 3 で
    赤くなる (=「並走を残さない」ことが機械で強制される)
  - 解析器も 1 本のままで、旧受理規則を選べるフラグは作らない
- **既存テストの削除はしない** (禁止事項 3)。7 本の既存テストは名前ごと残し、
  検査 a のタイトルだけ i5 の文言へ更新する。反証 R1〜R16 は番号も文面も維持する

## docs/template-divergence.md の登録/更新/削除の要否 (app-design 3-0 の判定)

| 判定項目 | 結果 |
|---|---|
| 変更ファイルが `docs/template-fingerprints.json` のキーに在るか | **在る** — `tests/Architecture/EnvExampleInvariantTest.php` (母集合 281 件のうち 1 件) |
| 採用時債務一覧に在るか | **在る** — 採用時ハッシュ `d672f63c…` が現在の内容と一致 |
| 選択肢 | (1) 採用時の姿へ戻す → 追従の放棄なので不可 / (2) テンプレートへ同期 → 本リポジトリから実行できない / **(3) 意図的逸脱として登録し債務から削る → 採用** |
| 追加する登録 | **D50** (状態 `監視中` / 見直し期限 2027-08-24) |
| 削除する登録 | なし |
| 件数 pin | `DIVERGENCE_ENTRY_COUNT` 46→47 / `ADOPTION_DEBT_COUNT` 148→147 |
| 他の変更ファイル | `.env.example` (**変更しない**) / `adoption-debt.tsv` / `LedgerPins.php` / `docs/template-divergence.md` はいずれも指紋台帳の母集合外 = 追加の登録は不要 |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 変更の中心は 1 ファイルだが、同じコミットで乖離台帳 3 ファイル (登録簿・債務一覧・件数 pin) を整合させる必要があり、途中状態では突合 gate (F10 / F11) が赤くなる。他施策と並行すると「どちらの変更で赤いのか」が切り分けられない。また段 6 の検出力の裏取りは gate を意図的に壊して戻す操作なので、他の実装と混ぜられない |
| 競合リスク | `docs/template-divergence.md` / `LedgerPins.php` / `adoption-debt.tsv` は**他の逸脱を伴う実装と衝突しやすい** (件数 pin が同時に動く)。同時期に別の逸脱登録を伴う TODO を走らせないこと。`tests/Architecture/EnvExampleInvariantTest.php` 自体は他 TODO が触っていない (Open の T249 は起動 probe の共通 runner 化で母集団が交わらない) |

---

## 関連する現行コード

### tests/Architecture/EnvExampleInvariantTest.php (全 477 行。本件の唯一の実質変更対象)

```php
<?php

declare(strict_types=1);

/*
 * `.env.example` の不変条件 (家系の裁定 AG-007 が定めた統合形)。
 *
 * このファイルは「読み物」ではなく**生きた既定値**である。3 つの経路が見本を
 * そのまま実環境にする — `composer setup` / composer.json の post-root-package-install /
 * scripts/setup-worktree.sh の復旧案内。よって見本の欠落・危険な値は
 * 「文書の不備」ではなく**実環境の不備**になる。
 *
 * 検査は 4 部品 + 2 つ:
 *   (a)   値の固定    — 行の完全一致で固定する (部分一致・コメント偽装を封鎖)
 *   (b)   キー網羅    — 必須キーを分類つきの台帳に持ち、存在を要求する (値は見ない)
 *   (c-1) 行の形式    — 非空・非コメント行は素の `KEY=` 形式のみ受理する
 *   (c-2) 重複        — 代入キーが全キー一意であることを要求する
 *   + 台帳の誠実性 (二重登録・台帳内の重複の禁止)
 *   + 反証の検査 (壊した入力を合成して解析器へ食わせる)
 *
 * ★本ファイルには**受理規則が逆向きの解析器が 2 つ同居する**。統合しない
 *   (統合すると片方の意図が壊れる):
 *
 *   |                      | envExampleParseContents (下) | collectUnresolvedEnvRefs (末尾) |
 *   |----------------------|------------------------------|---------------------------------|
 *   | 対象                 | `.env.example` の 1 枚だけ   | 見本 3 枚                       |
 *   | `export` つきの行     | **違反にする**               | 意図的に許容する                |
 *   | 先頭に空白のある代入 | **違反にする**               | 意図的に許容する                |
 *   | 見るもの             | キーと値・重複・行の形       | 値の中の `${VAR}` の解決可能性  |
 *
 *   `.env.example` については厳しい方 (行の形式の検査) が先に赤くなるので、
 *   緩い側の許容は残り 2 枚にしか意味を持たない。
 *
 * ★保証しないもの (誇張しない): 見るのは `.env.example` の中身だけで、実行中の `.env`・
 *   プロセスの環境変数・設定キャッシュには**無言で効かない**。キー網羅は存在だけを見る
 *   (空の値も通る)。`SECURITY_HSTS_ENABLED` / `SECURITY_CSP_ENABLED` は本番起動時に
 *   要求されるが見本に 1 行も無いため**欠落を検出しない**。config の既定値と見本の値が
 *   食い違っていても検出しない (同期の検査ではなく**提示の検査**である)。
 *
 * 設計: devnotes/20260817-1309-todo-t213-env-example-gate-t1/
 */

/**
 * 見本ファイルの本文を行単位で解析する (**純粋関数**。ファイルを読まない)。
 *
 * 行の分類:
 *   - 空白だけの行 → 実効値に影響しないので飛ばす
 *   - `^\s*#` の行 → コメント。同上
 *   - それ以外     → 素の代入行 `^[A-Z][A-Z0-9_]*=` **のみ**受理する
 *
 * ★これは dotenv の構文検査ではない。dotenv は `export FOO=1` も小文字のキーも読むが、
 *   本リポジトリの見本ファイルではそれらを許さない (存在検査・重複検査の母集合から
 *   外れたまま実効値だけを変えられる迂回になるため)。「見本に許す最小の書式」である。
 *
 * ★重複キーの値は**最初に現れた方**を記録する。dotenv は同一ファイル内の重複を
 *   **後に現れた方**で解決する。両者は食い違うので、重複が 1 件でもあると値の固定の検査は
 *   「実効値ではない値」を見ることになる。だから重複そのものを違反にする
 *   (どちらの解決順に合わせるかを選ばない)。
 *
 * 改行は CRLF / CR / LF のいずれでも行に割る (行末に CR を残さない)。
 * ★ただし**反証の表に CR 単独の行は無い** — 分割の規則が将来 CR 単独を落とすように弱っても
 *   赤くならない (保証範囲を誇張しないための注記)。
 * 値は前後の空白を落とさない (見本に書いてあるとおりを返す = 等号の後ろの空白は値の一部)。
 *
 * @return array{
 *   values: array<string, string>,
 *   duplicateKeys: list<string>,
 *   malformedLineNumbers: list<int>,
 * }
 */
function envExampleParseContents(string $contents): array
{
    $lines = preg_split('/\r\n|\r|\n/', $contents);
    expect($lines)->toBeArray();
    /** @var list<string> $lines */
    $values = [];
    $duplicateKeys = [];
    $malformedLineNumbers = [];

    foreach ($lines as $index => $line) {
        if (trim($line) === '') {
            continue;
        }
        if (preg_match('/^\s*#/', $line) === 1) {
            continue;
        }
        if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches) !== 1) {
            $malformedLineNumbers[] = $index + 1;

            continue;
        }
        $key = $matches[1];
        if (array_key_exists($key, $values)) {
            // 同じキーが 3 回以上でも、重複の一覧にはキー名を 1 度だけ載せる (診断の安定)。
            if (! in_array($key, $duplicateKeys, true)) {
                $duplicateKeys[] = $key;
            }

            continue;
        }
        $values[$key] = $matches[2];
    }

    return [
        'values' => $values,
        'duplicateKeys' => $duplicateKeys,
        'malformedLineNumbers' => $malformedLineNumbers,
    ];
}

/**
 * `.env.example` を読んで解析する (**入出力のアダプタ**。判定は持たない)。
 *
 * @return array{
 *   values: array<string, string>,
 *   duplicateKeys: list<string>,
 *   malformedLineNumbers: list<int>,
 * }
 */
function envExampleParse(): array
{
    $contents = file_get_contents(base_path('.env.example'));
    expect($contents)->toBeString();
    /** @var string $contents */

    return envExampleParseContents($contents);
}

/**
 * 値の固定: 裁定 AG-007 が名指しする 2 件。
 * 緩めるには家系の機能台帳側の裁定変更が要る (本リポジトリ単独では動かせない)。
 *
 * ★形式はキーと値の組の**リスト**にする (キー付きの連想配列にしない)。
 *   連想配列のリテラルは同じ定数の中の重複キーをコンパイル時に後勝ちで無音に潰すため、
 *   「行を足しただけに見える差分」で既存の固定を反転できてしまう。
 *   リストなら重複がそのまま残り、下の誠実性の検査が同じ機構で捕まえられる。
 */
const ENV_EXAMPLE_VALUE_PINS_AG007_CORE = [
    ['key' => 'SESSION_SECURE_COOKIE', 'value' => 'true'],
    ['key' => 'SESSION_ENCRYPT', 'value' => 'true'],
];

/**
 * 値の固定: 本リポジトリ固有の追加 (裁定で必須とされたものではない純増。個別に理由を書く)。
 * - ADMIN_MFA_REQUIRED=true: false にすると管理画面の二要素が実質無効になる。
 *   local の値が本番へ写る事故の側が危険なので、見本は安全側で固定する。
 * - MCP_STRICT_TRANSPORT=true: false にすると Origin を送らないクライアントを受け入れる
 *   (DNS 再バインドの面が広がる)。
 */
const ENV_EXAMPLE_VALUE_PINS_AICUE = [
    ['key' => 'ADMIN_MFA_REQUIRED', 'value' => 'true'],
    ['key' => 'MCP_STRICT_TRANSPORT', 'value' => 'true'],
];

/**
 * 値の固定の台帳の合成 (重複した組を保持したまま連結する)。
 *
 * @return list<array{key: string, value: string}>
 */
function envExampleValuePinEntries(): array
{
    return array_merge(ENV_EXAMPLE_VALUE_PINS_AG007_CORE, ENV_EXAMPLE_VALUE_PINS_AICUE);
}

/**
 * キー網羅の台帳。分類ごとに定数を分ける (平らな 1 本の配列にしない)。
 * 削るときに「どの根拠を外すのか」がレビューで見えるようにするためである。
 *
 * ★台帳は**床**であって天井ではない。`.env.example` に任意のキーを足すことは責務外で、
 *   完全一致の集合にはしない。
 *
 * (i) 新しい環境を立てるときに要る座標。`composer setup` と
 *     `scripts/setup-worktree.sh` の案内が `.env.example` をそのまま `.env` にするため、
 *     ここが欠けると「動かない .env」が出来上がる。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_SETUP = [
    'APP_NAME',
    'APP_ENV',
    'APP_KEY',
    'APP_URL',
    'APP_LOCALE',
    'DB_CONNECTION',
    'SESSION_DRIVER',
    'QUEUE_CONNECTION',
    'CACHE_STORE',
];

/**
 * (ii) 本番の起動時に検査される座標のうち、**現在 `.env.example` に素の代入行として
 *      提示済みのもの**。正本は app/Support/ProductionEnvGuard.php で、依存は一方向である
 *      (guard が変われば本台帳が古くなる。機械では結線しない — guard が読むのは config の
 *      キーであって環境変数名ではないため、結ぶには config の構文解析が要る)。
 *
 * ★これは guard の要求の**写しではない**。guard は SECURITY_HSTS_ENABLED /
 *   SECURITY_CSP_ENABLED も本番で true と要求するが、この 2 つは `.env.example` に
 *   1 行も無く、載せるには見本の書き方の判断が要るため本台帳には入れない
 *   (**この 2 件の欠落は検出しない**)。
 *
 * ★SESSION_SECURE_COOKIE / ADMIN_MFA_REQUIRED 等は値の固定の台帳が値ごと押さえるため
 *   ここには載せない (台帳をまたぐ二重登録は下の誠実性の検査が禁じる)。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD = [
    'CIPHERSWEET_KEY',
    'STRIPE_WEBHOOK_SECRET',
    'DEBUG_LOGIN_USER',
    'DEBUG_LOGIN_PASSWORD',
    'PRIMARY_HOST',
    'TRUSTED_HOSTS_ADDITIONAL',
    'TRUSTED_HOSTS_WILDCARD_SUFFIXES',
    'TRUSTED_PROXIES',
    'PASSKEYS_USER_HANDLE_SECRET',
];

/**
 * (iii) 提示が無いと環境ごとに別の名前が発明されて食い違う座標
 *       (外部との統合の秘密と、アプリ固有の座標)。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_INTEGRATION = [
    'STRIPE_KEY',
    'STRIPE_SECRET',
    'OPENAI_API_KEY',
    'ANTHROPIC_API_KEY',
    'GEMINI_API_KEY',
    'GOOGLE_CLIENT_ID',
    'GOOGLE_CLIENT_SECRET',
    'RECAPTCHA_SITE_KEY',
    'RECAPTCHA_SECRET_KEY',
    'MCP_ALLOWED_ORIGINS',
    'PASSPORT_PRIVATE_KEY',
    'PASSPORT_PUBLIC_KEY',
    'TEMPLATE_APP_SLUG',
    'LEGAL_CONSENT_VERSION',
];

/**
 * (iv) 撮影テイクとレンダ成果物の保管先。本リポジトリ固有の分類である。
 *      撮影 PWA は presigned URL で直接アップロードし、合成した動画も同じ保管先へ置く。
 *      ここが欠けた環境では**撮った映像を保存できない** = 使命の中核が動かない。
 */
const ENV_EXAMPLE_REQUIRED_KEYS_OBJECT_STORAGE = [
    'AWS_ACCESS_KEY_ID',
    'AWS_SECRET_ACCESS_KEY',
    'AWS_DEFAULT_REGION',
    'AWS_BUCKET',
];

/**
 * キー網羅の台帳の合成 (4 分類の連結)。
 *
 * @return list<string>
 */
function envExampleRequiredKeys(): array
{
    return array_merge(
        ENV_EXAMPLE_REQUIRED_KEYS_SETUP,
        ENV_EXAMPLE_REQUIRED_KEYS_PRODUCTION_GUARD,
        ENV_EXAMPLE_REQUIRED_KEYS_INTEGRATION,
        ENV_EXAMPLE_REQUIRED_KEYS_OBJECT_STORAGE,
    );
}

test('a: .env.example は安全側の既定値を行の完全一致で満たす', function (): void {
    $parsed = envExampleParse();

    // 失敗時に出すのは**キー名だけ**である (見本の実値を出力しない)。
    $violations = [];
    foreach (envExampleValuePinEntries() as $entry) {
        if (($parsed['values'][$entry['key']] ?? null) !== $entry['value']) {
            $violations[] = $entry['key'];
        }
    }

    expect($violations)->toBe([]);
});

test('b: .env.example は必須キーの台帳を網羅する', function (): void {
    $parsed = envExampleParse();

    $missing = array_values(array_diff(envExampleRequiredKeys(), array_keys($parsed['values'])));

    expect($missing)->toBe([]);
});

test('c-1: .env.example の非空・非コメント行は素の代入行 (KEY=) だけである', function (): void {
    $parsed = envExampleParse();

    // `export` つき・先頭に空白がある代入・小文字のキー・等号の**前**の空白は、
    // 存在検査と重複検査の母集合から外れたまま実効値だけを変えられる迂回になるので、
    // 行の形ごと禁じる。等号の**後ろ**の空白は値の一部なので違反にしない。
    // ★これは dotenv の構文検査ではない (dotenv はこれらを読む)。
    //   「本リポジトリの見本ファイルに許す最小の書式」である。
    expect($parsed['malformedLineNumbers'])->toBe([]);
});

test('c-2: .env.example の代入キーは一意である (重複で値の固定を無音で覆せなくする)', function (): void {
    $parsed = envExampleParse();

    expect($parsed['duplicateKeys'])->toBe([]);
});

test('台帳の誠実性: 値の固定とキー網羅の二重登録・台帳の中の重複が無い', function (): void {
    // 値の固定は存在の検査を含むので、キー網羅への二重登録は台帳の腐敗になる
    // (どちらを緩めたのか追えなくなる)。機械的に禁じる。
    $required = envExampleRequiredKeys();

    $pinKeys = [];
    foreach (envExampleValuePinEntries() as $entry) {
        $pinKeys[] = $entry['key'];
    }

    // 組のリスト形式は重複を保持するので、この一意性の検査 1 本で
    // 台帳の中 (同じ定数の中) と台帳の間 (2 つの定数にまたがる重複) の両方を捕まえられる。
    expect(array_values(array_unique($pinKeys)))->toBe($pinKeys);
    expect(array_values(array_intersect($required, $pinKeys)))->toBe([]);
    expect(array_values(array_unique($required)))->toBe($required);
});

/*
 * 反証の検査 (データ駆動)。見本ファイルは現に適合しているため、台帳駆動の検査は
 * 書いた瞬間に緑になる。それでは「壊れたら赤くなる」ことを誰も確かめていない。
 * そこで解析を純粋関数に分けておき、**壊した入力を合成して食わせる**検査を恒久で置く
 * (見本ファイルを実際に壊さずに「壊れたら赤くなる」ことを示せる)。
 *
 * ★これは dotenv の構文検査ではない。本リポジトリの見本ファイルに許す最小の書式である。
 */

test('反証: 解析器は合成した本文を仕様どおりに分解する', /**
 * 型注記は closure に直接付ける (将来 tests/ を PHPStan の解析対象へ入れても
 * iterable の値の型が欠けないようにするため)。
 *
 * @param array{
 *   values: array<string, string>,
 *   duplicateKeys: list<string>,
 *   malformedLineNumbers: list<int>,
 * } $expected
 */
    function (string $contents, array $expected): void {
        expect(envExampleParseContents($contents))->toBe($expected);
    })->with([
        // R1: コメント偽装。t0 の部分一致 (toContain) はこれを通していた = 偽グリーンの本体。
        'R1 コメント偽装した代入行は実効値にならない' => [
            '# SESSION_SECURE_COOKIE=true',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
        ],
        // R2: 字下げしたコメントを形式違反にしない。
        'R2 先頭に空白のあるコメント行は違反ではない' => [
            '   # コメント',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
        ],
        // R3: 正常系の下限 (空行を飛ばす)。
        'R3 素の代入行と空行' => [
            "A=1\n\nB=2",
            ['values' => ['A' => '1', 'B' => '2'], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
        ],
        // R4: 重複の検出と、解析器が**先勝ち**で記録すること。
        'R4 重複キーを検出し最初の値を記録する' => [
            "A=1\nA=2",
            ['values' => ['A' => '1'], 'duplicateKeys' => ['A'], 'malformedLineNumbers' => []],
        ],
        // R5: 3 回以上でも重複の一覧はキー名 1 件だけ (診断の安定)。
        'R5 3 回以上の重複でも一覧は 1 件' => [
            "A=1\nA=2\nA=3",
            ['values' => ['A' => '1'], 'duplicateKeys' => ['A'], 'malformedLineNumbers' => []],
        ],
        // R6: 複数キーの重複を取りこぼさない。
        'R6 複数キーの重複をすべて挙げる' => [
            "A=1\nB=2\nA=3\nB=4",
            ['values' => ['A' => '1', 'B' => '2'], 'duplicateKeys' => ['A', 'B'], 'malformedLineNumbers' => []],
        ],
        // R7〜R12: 存在検査・重複検査の母集合から外れたまま実効値だけを変えられる迂回を塞ぐ。
        'R7 export つきの行は形式違反' => [
            'export A=1',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        'R8 先頭に空白のある代入は形式違反' => [
            '  A=1',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        'R9 小文字のキーは形式違反' => [
            'a=1',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        'R10 等号の前の空白は形式違反' => [
            'A =1',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        'R11 素の区切り線は形式違反' => [
            '--- 区切り ---',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        'R12 数字始まりのキーは形式違反' => [
            '1A=1',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => [1]],
        ],
        // R13: CRLF の行末の CR を値に残さない。
        'R13 CRLF でも行末の CR を値に残さない' => [
            "A=1\r\nB=2",
            ['values' => ['A' => '1', 'B' => '2'], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
        ],
        // R14: 等号の**後ろ**の空白は値の一部である (R10 と対で「前だけを違反にする」ことを固定する)。
        'R14 値の前後の空白を落とさない' => [
            'A= 1 ',
            ['values' => ['A' => ' 1 '], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
        ],
        // R15: 行番号が 1 始まりで正しいこと。
        'R15 形式違反の行番号は 1 始まり' => [
            "A=1\nexport B=2\nc=3",
            ['values' => ['A' => '1'], 'duplicateKeys' => [], 'malformedLineNumbers' => [2, 3]],
        ],
        // R16: 端 (空ファイル) で落ちない。
        'R16 空文字列' => [
            '',
            ['values' => [], 'duplicateKeys' => [], 'malformedLineNumbers' => []],
        ],
    ]);

/*
 * env ファイルの `${VAR}` nested variable は「同一ファイル内の先行定義 or 実行環境変数」しか
 * 解決できない (APP_ENV 別ロードでは他ファイルを継承しない)。自己参照 (VAR="${VAR}") や
 * 前方参照はリテラル文字列がそのまま画面に露出する事故になる (bug-hunt F-01 の実例:
 * .env.bughunt.local の APP_NAME="${APP_NAME}" が全画面のタイトル/ロゴ/フッターに露出)。
 *
 * 意図的に「実行環境からの外部注入」を期待する参照は ENV_EXTERNAL_REF_ALLOWLIST に
 * ファイル => 変数名 => 理由 で登録する (deny-by-default)。
 */

/** @var array<string, array<string, string>> */
const ENV_EXTERNAL_REF_ALLOWLIST = [
    // '.env.example' => ['SOME_VAR' => '理由'],
];

/**
 * @return array<int, array{file: string, line: int, ref: string}> 違反一覧
 */
function collectUnresolvedEnvRefs(string $relativePath): array
{
    $contents = file_get_contents(base_path($relativePath));
    expect($contents)->toBeString();
    /** @var string $contents */
    $defined = [];
    $violations = [];

    foreach (explode("\n", $contents) as $i => $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        // export プレフィックス付き定義も将来混在しうるため許容する
        if (preg_match('/^(?:export\s+)?([A-Z0-9_]+)=(.*)$/', $trimmed, $m) !== 1) {
            continue;
        }
        [$_, $key, $value] = $m;

        // 値の中の ${VAR} 参照を全て検査 (定義行より前に VAR 定義が無ければ違反)
        if (preg_match_all('/\$\{([A-Z0-9_]+)\}/', $value, $refs) > 0) {
            foreach ($refs[1] as $ref) {
                $allowed = ENV_EXTERNAL_REF_ALLOWLIST[$relativePath][$ref] ?? null;
                if ($allowed === null && ! array_key_exists($ref, $defined)) {
                    $violations[] = ['file' => $relativePath, 'line' => $i + 1, 'ref' => $ref];
                }
            }
        }

        // 定義の登録は参照検査の後 (VAR="${VAR}" の自己参照を違反にするため)
        $defined[$key] = true;
    }

    return $violations;
}

test('コミット対象 env ファイルに自己参照・前方参照の ${VAR} が無い', function (): void {
    $violations = [];
    foreach (['.env.example', '.env.bughunt.local.example', '.env.testing'] as $file) {
        $violations = array_merge($violations, collectUnresolvedEnvRefs($file));
    }
    expect($violations)->toBe([], '未解決の ${VAR} 参照: '.json_encode($violations, JSON_UNESCAPED_SLASHES));
});
```

### tests/Support/TemplateDivergence/LedgerPins.php (件数 pin。M5 で 2 定数を更新)

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
     *   同定に使うので番号を pin する。
     *   ★**引退時に外すのは対象パスの 1 行だけで、登録そのものは残る** —
     *   一覧が 0 件になっても判定機構 (`AdoptionDebtInventory`) は残り続けるので、
     *   本アプリ固有の追加としての説明は要る (詳しくは同クラスの docblock)。
     */
    public const int ADOPTION_DEBT_DIVERGENCE_ID = 34;

    /** 取り込んだ正典台帳の generated_at_commit (指紋台帳の出自 pin)。 */
    public const string TEMPLATE_LEDGER_SOURCE_COMMIT = 'a078806b0574518ddc64966f60f7d536b1338b2f';

```

### tests/Support/TemplateDivergence/FingerprintReconciler.php (債務パスの判定部。M5 の必要性の根拠)

```php
        // --- 債務一覧の現況 ---
        $debtPathsOutsidePopulation = [];
        $doubleDeclaredPaths = [];
        $resolvedDebtPaths = [];
        $mutatedDebtPaths = [];
        foreach ($debt as $path => $adoptionHash) {
            if (! array_key_exists($path, $templateHashes)) {
                // 母集合外の債務はハッシュ比較へ進めない (未定義キーで途中終了させない)
                $debtPathsOutsidePopulation[] = $path;

                continue;
            }
            if (array_key_exists($path, $registeredCounts)) {
                $doubleDeclaredPaths[] = $path;
            }

            $observation = $observations[$path];
            if ($observation->inspectionFailure !== null) {
                continue; // 検査不能として既に報告済み
            }
            if ($observation->currentHash === null) {
                $mutatedDebtPaths[] = $path; // 削除された = 採用時の姿ではない

                continue;
            }
            if ($observation->currentHash === $adoptionHash) {
                continue; // 採用時の姿のまま = 未解消債務として許容する
            }
            if ($observation->currentHash === $templateHashes[$path]) {
                $resolvedDebtPaths[] = $path; // 一致へ戻った = 一覧から削れ

                continue;
            }
            $mutatedDebtPaths[] = $path; // 登録を書くか、採用時の姿へ戻すか、テンプレートへ同期する
        }

        // --- 母集合 − 債務 の範囲で 3a / 3b ---
```

### tests/Support/TemplateDivergence/adoption-debt.tsv (該当行)

```
# template_ledger_commit=a078806b0574518ddc64966f60f7d536b1338b2f
...
tests/Architecture/EnvExampleInvariantTest.php	d672f63cebdd419e639f7d3fa70288448fc5297aaa6da3fad29a88a5d1518e60
...
```

### docs/template-divergence.md の登録メタ表の値域 (書式の正本)

## 登録メタ表 (9 行ちょうど・この順序)

| 行 | 値域 |
|---|---|
| 対象パス | リポジトリ相対のファイルパスをバッククォート囲みで 1 件以上。区切りは半角スペースとスラッシュと半角スペース。glob・絶対パス・上位への相対指定は不可。ファイルとして実在すること。**全登録の和集合で重複しないこと** |
| 業務要件起因の説明 | なぜドメイン要件のせいでテンプレートの形から外れたか (1〜2 文) |
| 揃え続ける不変条件と保証機構 | 何を揃え続け、どの機構が保証するか |
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


### 参考: 正典 t2 の不変条件 (lctl feature gate-env-example-sync の確定設計)

## 確定した設計（不変条件）

- **i1**: 本 gate の対象は、そのリポジトリが commit して配る**ローカル開発用の見本
  1 枚** — `.env.example` である。本番用のひな形 (`.env.production-template`)・
  実行中の `.env`・テスト用の env・bug-hunt 用の見本は対象に含めない。
  これらはそれぞれ別の feature が担当する
- **i2**: 見本の解析は**純粋関数**として持つ (文字列を受け取り、代入・重複・形式違反を
  返す)。ファイルを読むのは入出力のアダプタだけに閉じ、判定は純粋関数の出力しか見ない。
  解析は `env()` / `config()` を呼ばない (見本の値がテスト実行時の設定に影響しない・
  されないことを構造で担保する)
- **i3**: 行の受理規則を固定する。空白だけの行とコメント行は実効値を作らないので飛ばす。
  それ以外の行は**素の代入行だけ**を受理する — キーは `[A-Z][A-Z0-9_]*`、等号の直後から
  行末までが値。`export` つきの行・先頭に空白のある代入・小文字のキー・等号の前に
  空白のある代入・素の区切り線は形式違反として落とす。制御文字を含む行も形式違反にする
  (見た目が同じでも dotenv・OS の環境変数・配備の経路で同じ値として扱われる保証が無い)。
  等号の**後ろ**の空白は値の一部として保つ。改行は CRLF / CR / LF のいずれでも行に割る。
  コメント行の字下げを許すかは各リポジトリの裁量とする (どちらでも偽の緑を作らない)
- **i4**: 代入キーは見本の中で**全キー一意**であること。重複は不合格にする。
  理由は「dotenv が後勝ちだから」ではない — 同一ファイル内の重複の解決順は
  仕様で保証されておらず、構成 (上書きを許すか) と実装で変わりうる。だから
  「どちらが実効値か」を選ばず、重複そのものを曖昧な設定として禁じる
- **i5**: 値の固定は「そのキーの**実代入がちょうど 1 件**あり、その値が台帳の値と
  完全一致する」ことを要求する。部分一致 (ファイル全体に文字列が含まれるか) や
  存在確認では固定にならない — コメント行だけでも通ってしまう
- **i6**: 固定する値は、**向きが安全側・危険側で決まるもの**に限る (セキュア Cookie の
  有効化・セッションの暗号化など) 。加えて見本の**用途宣言**として `APP_ENV=local` を
  固定する。運用の好みで変わる値 (ログの出力量・保存先ドライバの選択) は固定しない —
  固定しても変更検出器になるだけで、安全性を上げない
- **i7**: 必須キーの台帳を持ち、素の代入行としての存在を要求する (値は見ない)。
  台帳の entry は 1 件ごとに**分類**と**由来** (なぜ必須なのか) を持ち、
  由来が空でないことを機械で検査する。台帳は**床であって天井ではない** —
  台帳に載っていないキーを見本へ足すことは違反にしない
- **i8**: 台帳自身の誠実性を機械で検査する。少なくとも (1) entry が 1 件以上ある、
  (2) キーの綴りが代入行として成立する、(3) キーが台帳の中で一意である
  (値の固定と必須キーの二重登録も、種別をまたいだ重複も禁じる)、(4) 種別と値・分類の
  組み合わせが整合する、の 4 つを見る
- **i9**: 台帳から entry が**静かに消えても緑にならない**こと。種別ごと・分類ごとの
  件数を台帳自身に申告させ、実件数との一致を要求する。件数の申告は摩擦だが、
  「見本からキーを消す変更は台帳の entry と申告件数の両方の更新を要求する」という
  意図した摩擦である。検査をデータ駆動で回す形にする場合は、駆動元が空になったときに
  落ちる検査 (床) を併せて持つ
- **i10**: **壊れたら赤くなることを機械で示す**反証データセットを恒久で持つ。
  見本を実際に壊すのではなく、壊した本文を合成して i2 の純粋関数へ食わせる。
  少なくともコメントで偽装した代入・重複・`export` つき・先頭に空白のある代入・
  小文字のキー・等号の前の空白・空入力を含み、正常系の下限も対で置く
- **i11**: 見本の値の中の `${VAR}` 参照は、**同じファイルの先行行で定義されたキー**だけを
  指してよい。自己参照と前方参照は不合格にする (解決できずリテラルのまま残り、
  画面や送信メールへそのまま出る)。実行環境からの注入を期待する参照は、理由付きの
  許可台帳へ登録したものだけを通す (既定は拒否)
- **i12**: 検査の前提そのものを固定する。テスト実行時に**読み込まれている env ファイルが
  見本ファイルでない**ことを実行時に確認する。主張はここに限り、許可する env の名前の
  集合までは固定しない (正当な env 名を足しただけで落ちるのは過剰である)
- **i13**: 検査が**保証しない範囲を検査自身が明記する**。少なくとも (1) 実行中の `.env`・
  プロセスの環境変数・設定キャッシュには効かない、(2) キー網羅は存在だけを見て値を
  見ない (空の値も通る)、(3) config の既定値と見本の値の一致は見ない (同期の検査ではなく
  提示の検査である)、(4) 台帳に載せていない要求の欠落は検出しない、を書く
- **i14**: gate は 1 リポジトリにつき**1 本のファイル**へ集める。同じ関心事の検査を
  別名のファイルへ並置しない。正典のファイル名は
  `tests/Architecture/EnvExampleInvariantTest.php` とする

不変条件を支える構成: i2 の純粋関数が i3 / i4 / i5 の判定材料 (代入・重複・形式違反) を
1 か所で作り、i10 の反証はその純粋関数へ直接入力を与える。i7 / i8 / i9 は台帳という
1 つのデータに対する 3 層の検査で、i8 が「台帳が壊れていないこと」、i9 が「台帳が
痩せていないこと」、i7 が「台帳と現物が一致すること」を分担する。i11 と i12 は
gate の前提側を押さえる 2 本で、i11 は見本の値が実環境で意味を持つことを、
i12 は見本が検査の実行環境に混入していないことを担保する。

