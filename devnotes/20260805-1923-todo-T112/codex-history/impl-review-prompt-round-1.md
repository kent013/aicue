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


---

あなたはコードレビュアーとして、Laravel + Svelte アプリの改善実装をレビューする。

## 本レビューの対象範囲 (重要)

参照する詳細設計書は A/B/C/D の 4 グループをカバーする文書だが、**本 TODO (T112) で実装するのは施策 A (A1/A2) のみ**である。
B (bug-hunt inventory + docs) / C (NFC/NFD + 孤児DB) / D (advisory upgrade) は別 TODO の担当であり、
本差分に含まれていないことは欠落ではない。以下では施策 A の抜粋のみを添付している。

## レビュー観点

1. **設計との一致性**: 詳細設計 施策 A1 / A2 の意図どおりに実装されているか。設計からの逸脱があれば、その逸脱が正当か
2. **正確性**: PCRE リテラル抽出器のロジック (エスケープ復元・デリミタ判定・`\R` 判定) にバグはないか。
   shell の `|| pgid=""` 修正が `set -euo pipefail` 下で意図どおり働き、かつ厳格判定を弱めていないか
3. **PHPStan 適合性**: 型注釈は正しいか。widen / ignore を使っていないか
4. **テスト網羅性**: gate が空振りしていないか (drift ガード)。正/負コントロールが十分か。
   偽グリーン・偽赤の両方に対する耐性があるか
5. **セキュリティ**: 検証スイートが実ロック・実 DB・dev 環境に触れていないか
6. **禁止事項違反**: 上記「禁止事項」に触れていないか

本差分は shell / PHP テスト / TypeScript テストのみで、UI・DTO・JsonResource・Svelte component の変更を含まないため、
DESIGN.md 準拠 / Atomic Design 準拠の観点は該当しない。

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で明示する


---

## 詳細設計書 (施策 A の抜粋)

# 詳細設計: audit-followup-maintenance (サイクル 2 監査の残り是正)

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- `declare(strict_types=1)` + 日本語コメント
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

> **本バッチ固有の注意**: 本バッチは**アプリのドメインコードを 1 行も変更しない**。
> 変更対象は Architecture テスト / 運用スクリプト / 台帳・規約ドキュメント / lockfile に限られる。
> したがって DTO / JsonResource / Inertia Props / Svelte / DESIGN.md / Atomic Design への
> 波及は**構造的に存在しない**（各施策の「波及変更」欄で個別に確認する）。

## 概念設計リファレンス

- [devnotes/20260805-1813-audit-followup-maintenance/conceptual-design.md](conceptual-design.md)（Codex 合議 Round 5 で **APPROVED**）
- 監査の出典: `devnotes/20260805-1600-audit-cycle-2/`（`audit-report.md` / `tech-debt.md` / `docs-freshness.md`）
- 削除対象 manifest: [nfd-index-entries.txt](nfd-index-entries.txt)（58 行）

### 概念設計 Round 5 の承認条件（本詳細設計が満たすべきもの）

| # | 承認条件 | 本書での対応 |
|---|---|---|
| 1 | 分類優先順位を詳細設計で明記し、テストで固定する | 施策 C2 §分類優先順位（`Protected → Live → Foreign → Orphan → Unlabeled`）+ テスト計画 T-C2-4 |
| 2 | C4 の `--apply` は人間の明示指示なしでは実行しない | 施策 C2 §apply の運用契約 + `AGENTS.md` への明記 + 実装 TODO の受入条件 |

# 施策 A1: `preg_split('/\R/')` の `/u` 是正 + PCRE `\R` ゲート新設

### 変更箇所

- `tests/Architecture/GlobalTestLockInventoryTest.php` L107, L146（**監査は 2 箇所と報告したが実測は 3 箇所**）
- `tests/Architecture/BughuntOrchestratorGateInvariantTest.php` L95
- **新規**: `tests/Architecture/PcreUnicodeModifierGateTest.php`

実測（`rg -n "preg_split" -g '!vendor' -g '!node_modules' -g '!devnotes'`）:

```
tests/Architecture/GlobalTestLockInventoryTest.php:107:  preg_split('/\R/', $command)
tests/Architecture/GlobalTestLockInventoryTest.php:146:  preg_split('/\R/', $source)
tests/Architecture/BughuntOrchestratorGateInvariantTest.php:95: preg_split('/\R/', $window)
tests/Architecture/ScriptsReadmeInventoryTest.php:71:  preg_split('/\r\n|\r|\n/', $markdown)   ← 安全 (\R 不使用)
tests/Architecture/DefensiveInstructionsPresenceTest.php:47: preg_split('/\r?\n/', ...)      ← 安全
```

`\R` を含む PCRE リテラルは PHP 側で上記 3 箇所のみ。JS 側に `\R` の使用はゼロ（`rg` で 0 件）。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 変更対象そのものが Architecture テスト。新規ゲート 1 本を追加

### 現行コード

```php
// tests/Architecture/GlobalTestLockInventoryTest.php:146
function globalTestLockCodeLines(string $source): string
{
    $lines = preg_split('/\R/', $source) ?: [];
    ...
}
```

### 変更後コード

```php
    $lines = preg_split('/\R/u', $source) ?: [];
```

3 箇所すべて同様に `'/\R/'` → `'/\R/u'` とする（差分は 3 文字 × 3 箇所）。

### 新規ゲートの設計

**契約**: 「PCRE パターンリテラルが `\R` を含むなら `u` 修飾子が必須」を **deny-by-default** で強制する。
免除リストは持たない（このリポジトリに `\R` を非 UTF-8 モードで使う正当な用途が 1 つも無いため）。

**なぜ共通ヘルパ化ではなくゲートか**（概念設計の判断を再掲）:
呼び出し箇所は 3 つしかなく、共通の行分割ヘルパを作ると新しい共有クラスが 1 本増える
（思考原則 2）。ゲートがあれば `/u` 忘れは**書いた瞬間に検出できる**ので、ヘルパは不要。

**解析方式**: `PhpToken::tokenize()` で走査し `T_CONSTANT_ENCAPSED_STRING` だけを見る。
文字列 grep にしないのは、**コメント内の `preg_split('/\R/')` という説明文で偽赤になる**ため
（`ci-workflow-inventory.test.ts` が「YAML を parse してから歩く」のと同じ理由）。
既存の `PhpToken` 系ゲート（`DocumentTitleCoverageTest` / `CarbonOverflowArithmeticGateTest` /
`NoNonCompoundGlobalUseTest`）と API 世代を揃える（`token_get_all` 系を増やさない）。

```php
<?php

declare(strict_types=1);

use Webmozart\Assert\Assert;

/*
 * PCRE の `\R` は **8bit 非 UTF-8 モードでバイト 0x85 (NEL) にもマッチする**。
 * UTF-8 の日本語には 0x85 を含む文字が多数あり (「全」E5 85 A8 /「先」E5 85 88 /
 * 「共」E5 85 B1 /「内」E5 86 85 /「入」E5 85 A5 /「公」E5 85 AC など)、
 * `/u` の無い `preg_split('/\R/')` は**文字の途中で行を分断する**。
 *
 * 実害 (監査サイクル 2 で実測): GlobalTestLockInventoryTest の解析入力で
 * scripts/global-test-lock.sh が 380 行 → 454 行に偽分割され、4.8 KB のコメント文字列が
 * 「コード」として検査対象に漏出していた。漏出テキストに検査語が 1 つ現れた時点で
 * ゲートが偽赤になる。本リポジトリは**コメントを日本語で書く規約** (AGENTS.md §実装規約)
 * なので、踏むのは時間の問題である。
 *
 * 本ゲートは「`\R` を含む PCRE パターンリテラルには `u` 修飾子が必須」を
 * deny-by-default で固定する。免除リストは持たない (正当な用途が存在しないため)。
 *
 * 解析は PhpToken (コメントは別トークンなので拾わない)。文字列 grep にすると
 * 「本ゲートの説明コメント」自身で偽赤になる。
 *
 * 本テストは DB を触らない (ファイル読み取りのみ)。
 */

/** PCRE パターンリテラルとして認識するデリミタ (このリポジトリで実際に使われているもの)。 */
const PCRE_DELIMITERS = ['/', '#', '~', '%', '!', '@'];

/**
 * 走査対象ディレクトリ (リポジトリルートからの相対)。
 *
 * @var list<string>
 */
const PCRE_SCAN_DIRS = ['app', 'tests', 'config', 'database', 'routes', 'scripts'];

/**
 * PHP ソースから **PCRE パターンリテラル** を抽出する (純関数)。
 *
 * @return list<array{literal: string, body: string, modifiers: string}>
 */
function pcrePatternLiterals(string $source): array { /* PhpToken 走査 */ }

/**
 * `\R` を含むのに `u` 修飾子が無いパターンリテラルを返す (純関数)。
 *
 * @return list<string> 違反リテラル (原文のまま)
 */
function pcreLiteralsMissingUnicodeModifier(string $source): array { /* 上記を filter */ }
```

> **射程の明示（docblock に必ず書く）**: 本抽出器は**完全な PCRE parser ではない**。
> escaped delimiter（`'/a\/b/'`）や文字クラス内の delimiter（`'/[/]/'`）を厳密に扱わない。
> 射程は「**`\R` を含むパターンリテラルの `u` 修飾子欠落を検出すること**」に限定する。
> 判定対象は **PHP の文字列リテラルを評価した後の値**である。
> **復元規則**: 引用符を剥がしたあと **`\\` → `\` の 1 パスだけ**を畳む
> （single-quoted では加えて `\'` → `'`）。`\R` は PHP のエスケープ列ではないため
> single / double のどちらでもそのまま残る = **この 1 パスで必要十分**。
>
> | PHP ソース上の記述 | 評価後の値 | 判定 |
> |---|---|---|
> | `'/\R/'` | `/\R/` | 検出（改行クラス・`u` なし） |
> | `"/\R/"` | `/\R/` | 検出 |
> | `'/\\R/'` | `/\R/` | **検出**（`\\` が `\` に畳まれる） |
> | `'/\\\\R/'` | `/\\R/` | **非検出**（リテラルの `\` + `R`。改行クラスではない） |
> | `'/\R/u'` | `/\R/u` | 非検出（`u` あり） |
>
> **意図的な射程外（docblock とテストに明記する）**: 射程は「**`\R` を直接記述したリテラル**」に限る。
> double-quoted の `"\x5cR"` / `"\u{5c}R"` は PHP 評価後に `\R` になるが、
> **本抽出器は復元しない**（16 進 / Unicode エスケープまで復元すると PHP の
> 文字列評価器を再実装することになり、費用対効果が合わない）。
> このリポジトリに該当記述は 1 件も無く（`rg '\\x5c|\\u\{5c\}'` で 0 件）、
> 将来必要になったら射程を広げる。**P15 がこの射程外を固定する**。

**判定手順（`pcrePatternLiterals`）**:

1. `PhpToken::tokenize($source)` のうち `T_CONSTANT_ENCAPSED_STRING` のみを対象にする
2. 引用符を剥がす（`'...'` / `"..."`。`'` 内の `\\` `\'` のみアンエスケープ）
3. 先頭 1 文字が `PCRE_DELIMITERS` に含まれなければ**パターンではない**として捨てる
   （「これは `\R` の説明」のような通常文字列を拾わない）
4. 同じデリミタの**最後の出現位置**を終端とし、そこから後ろを modifiers とする
5. modifiers が `[a-zA-Z]*` でなければパターンではないとして捨てる
6. body に `\R`（バックスラッシュ + `R`）が含まれ、modifiers に `u` が無ければ違反

**テストケース**:

| ID | 内容 | 期待 |
|---|---|---|
| P1 | `PCRE_SCAN_DIRS` 配下の全 `.php` に違反が 1 件も無い | pass |
| P2 | **空振り防止（下限ガード）**: 抽出した PCRE パターンリテラル総数が **20 件以上** かつ **`tests/Architecture/` 配下から 1 件以上**抽出できる | pass |
| P3 | **走査対象ファイル数**が **50 件以上** かつ **代表ファイル `tests/Architecture/GlobalTestLockInventoryTest.php` が走査対象に含まれる** | pass |
| P4 | 正コントロール: `preg_split('/\R/', $x)` を含む合成ソース | **1 件検出** |
| P5 | 正コントロール: `preg_match("/\R/m", $x)`（`u` なし・別修飾子あり） | **1 件検出** |
| P6 | 正コントロール: `'#\R#'`（別デリミタ） | **1 件検出** |
| P7 | 負コントロール: `preg_split('/\R/u', $x)` | 0 件 |
| P8 | 負コントロール: `'#\R#u'` | 0 件 |
| P9 | 負コントロール: `preg_split('/\r\n|\r|\n/', $x)`（`\R` 不使用） | 0 件 |
| P10 | 負コントロール: **コメント内**の `// preg_split('/\R/')` | 0 件（コメントは別トークン） |
| P11 | 負コントロール: 通常文字列 `'\R は改行クラスです'`（デリミタ始まりでない） | 0 件 |
| P12 | 正コントロール: **double-quoted** `"/\R/"`（評価後 `/\R/`） | **1 件検出** |
| P13 | 負コントロール: PHP ソース上の `'/\\\\R/'`（評価後 `/\\R/` = リテラルの `\` + `R`） | 0 件 |
| P14 | 正コントロール: PHP ソース上の `'/\\R/'`（評価後 `/\R/` = 改行クラス） | **1 件検出** |
| P15 | **射程外の固定**: `"/\x5cR/"`（PHP 評価後は `/\R/` だが 16 進エスケープは復元しない） | 0 件（**意図的な見逃し**。テスト名とコメントに「射程外」と明記する） |

P2/P3 の下限を「`\R` を含むリテラルが N 件以上」にしない理由: 将来 3 箇所すべてが
リファクタで消えたときに**正しい状態が偽赤になる**ため。下限は「抽出器が動いていること」に掛ける。
また閾値を現在値（PCRE リテラル多数 / 対象ファイル 300 超）から**大きく下げた固定値**にし、
「**代表ファイルが走査対象に含まれる**」検査を併用する。規模に連動する高い閾値は
リポジトリの縮小・分割で偽赤になり、本バッチが減らそうとしているものと同種の罠になるため。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`list<array{literal: string, body: string, modifiers: string}>` / `list<string>`）
- [x] null 安全（`preg_split` の `false` は `?: []` で潰さず、`Assert::isArray()` で明示する）
- [x] DTO を返している（本ファイルは Architecture テストの純関数なので配列 shape で表現。アプリ層の DTO 規約の対象外）
- [x] Generics の型パラメータが正しい（`list<...>` を使用）

### テスト計画

- [x] バグ修正の再現テストを先に書く: **P4（`/u` 無しを検出する）を先に書き、現行の 3 箇所で赤くなることを確認してから修正する**（思考原則 5 テストファースト）
- [x] 既存テストの更新: `GlobalTestLockInventoryTest` / `BughuntOrchestratorGateInvariantTest` は**挙動が変わる**（解析入力が正しくなる）ため、両テストが green のままであることを確認する
- [x] 新規テスト: `PcreUnicodeModifierGateTest`（P1〜P15）
- [x] 個別の `DatabaseTransactions` を使っていない（DB を触らない）

### リスク

| リスク | 対処 |
|---|---|
| `/u` 付与で `GlobalTestLockInventoryTest` の解析結果が変わり、これまで漏出テキストに救われて green だった検査が赤くなる | 監査の実測で「漏出フラグメント由来の偽陽性は 0 件」を確認済み（"CI" を含む断片 2 件は `$CI` / `${CI}` 形式を要求するパターンに当たらない）。修正後に両テストの green を必ず確認する |
| ゲートの抽出器が `sprintf('/%s/', $x)` のような動的パターンを見逃す | **意図的な射程外**。リテラルに閉じることで誤検出をゼロにする方を選ぶ（動的生成は本リポジトリに `\R` 用途が無い） |
| `"...$var..."` のような補間文字列は `T_CONSTANT_ENCAPSED_STRING` にならず拾えない | 同上。射程を docblock に明記する |

---

# 施策 A2: `global-test-lock.sh` の pgid 取得 race 修正

### 変更箇所

- `scripts/global-test-lock.sh` **L266**（`_gtl_probe_process_group`）と **L350**（`global_test_lock_run`）
  — 監査は L350 の 1 箇所を報告したが、**実測で同型が L266 にもある**
- `scripts/verify-global-test-lock.sh`（層 1 スイートに **C25** を追加）
- `scripts/run-browser-test.contract.test.ts`（回避策 `sleep 0.1` 2 箇所を撤去）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `scripts/run-browser-test.contract.test.ts`（スタブから `sleep 0.1` を撤去）/ `scripts/verify-global-test-lock.sh`（C25 追加）

### 現行コード

```bash
# scripts/global-test-lock.sh:349-353
    # best-effort 検証: 空 = 既に終了 (race) なので異常ではない。値が違うときだけ落とす。
    pgid="$(ps -o pgid= -p "${_GTL_CHILD_PID}" 2>/dev/null | tr -d ' ')"
    if [ -n "${pgid}" ] && [ "${pgid}" != "${_GTL_CHILD_PID}" ]; then
        _gtl_die "専用プロセスグループを作れなかった (pid=${_GTL_CHILD_PID} pgid=${pgid})"
    fi
```

```bash
# scripts/global-test-lock.sh:266 (_gtl_probe_process_group)
        pgid="$(ps -o pgid= -p "${pid}" 2>/dev/null | tr -d ' ')"
```

**機序**: lane スクリプトは `set -euo pipefail` で動く。子が probe 前に終了すると
`ps -o pgid= -p <dead pid>` が exit 1 → `pipefail` でパイプライン全体が exit 1 →
**コマンド置換の終了ステータスが代入に伝播** → `set -e` でその場で終了。
つまり直後の `[ -n "${pgid}" ]` による「空 = race として許容」という**コメントの意図に到達しない**。
失敗モードは**偽赤**（レーンが走らずに落ちる）。

### 変更後コード

```bash
    # best-effort 検証: 空 = 既に終了 (race) なので異常ではない。値が違うときだけ落とす。
    #
    # `|| pgid=""` が必須: lane は `set -euo pipefail` で動くため、死んだ pid に対する
    # ps の exit 1 が代入へ伝播して **直下の -n 判定に到達する前にレーンごと落ちる**
    # (偽赤)。空を許容するという下の意図を成立させるために、代入の失敗をここで吸収する。
    pgid=""
    pgid="$(ps -o pgid= -p "${_GTL_CHILD_PID}" 2>/dev/null | tr -d ' ')" || pgid=""
    if [ -n "${pgid}" ] && [ "${pgid}" != "${_GTL_CHILD_PID}" ]; then
        _gtl_die "専用プロセスグループを作れなかった (pid=${_GTL_CHILD_PID} pgid=${pgid})"
    fi
```

L266 も同様に `pgid=""` + `|| pgid=""` を付ける
（`sleep 0.3 &` を probe するので現状は顕在化しにくいが、**同型の潜在バグ**であり、
高負荷時に `sleep` の起動が遅れると踏む。ここを直さないと「同じ罠が 1 箇所残る」）。

### 検証ケース C25 の設計

`scripts/verify-global-test-lock.sh` に追加する（現行 C01〜C24 の次）。

```bash
# C25: sub-millisecond で終了する子でもレーンが落ちない (pgid probe の race 許容)
#
# 回帰の対象: `pgid="$(ps ...)"` が set -euo pipefail 下で代入ごと失敗し、
# 「空 = race として許容」に到達せずレーンが落ちていた (T104 で sleep 0.1 の
# 回避策が contract テストへ混入した原因)。
case_c25() {
    local id="C25"
    # 即座に終了する子 (true) を global_test_lock_run 経由で 20 回連続実行する。
    # 1 回でも非ゼロで落ちたら race が再発している。
    ...
    # 期待: 全 20 回 exit 0 / stderr に "専用プロセスグループを作れなかった" が出ない
}
```

```bash
# C26: ps が使えない環境ではロック取得が fail する (strict 検証が生きていることの正コントロール)
#
# `|| pgid=""` は best-effort probe の意図を成立させるだけで、厳格判定
# (_gtl_probe_process_group の 3 回リトライ → 空なら _gtl_die) を弱めない。
#
# **偽グリーン対策 (design-review R3)**: 単に PATH を空にすると flock / sleep / tr など
# 別コマンドの不在で先に落ち、「非ゼロ終了」だけを見ると通ってしまう。そこで
#   (a) 一時 PATH ディレクトリに **必要なコマンドだけ** symlink し、ps だけを置かない
#   (b) 終了コードに加えて **_gtl_probe_process_group 固有のメッセージ**
#       ("job control で専用プロセスグループを作れない") が stderr に出ることを検証する
#   (c) probe に到達した証跡 (acquire 開始マーカー) が出ていることを検査する
# の 3 点を満たして初めて PASS とする。
case_c26() { ... }
```

- `main()` の呼び出し列に `case_c25` / `case_c26` を **`case_c11`（残党検出）より前**に追加する
  （C11 は最後に走る掃除確認なので、その前に置く既存の並びに従う）
- 20 回反復にするのは、1 回だけだと race が確率的に外れて偽グリーンになりうるため

### `ps` 不在時の期待挙動（現行実挙動に合わせて明文化）

`|| pgid=""` を入れると「`ps` が本当に使えない環境」でも代入が通るため、
**strict な検出が弱くなる**のではないかという疑問が出る。実挙動は次のとおり:

- **ロック機構は `ps` を必須としている**（現行挙動。本施策はこれを変更しない）。
  `_gtl_probe_process_group()` は 3 回リトライして `pgid` が一度も取れなければ
  ループを抜けて **`_gtl_die "job control で専用プロセスグループを作れない (set -m 不可)"`** する。
  `ps` が不在なら値は毎回空になるため、**ロック取得そのものが fail する**
- **厳格判定の責務は `_gtl_probe_process_group()` に集約されている**（取得時 1 回だけ走る）。
  `global_test_lock_run()` 側の probe は**元から best-effort**（行 349 のコメントが明示）で、
  「値が取れて不一致」のときだけ落とす。`|| pgid=""` はこの best-effort を
  **設計どおりに機能させる**修正であって、strict 検証を弱めない
- **C26 を追加してこれを固定する**: `PATH` から `ps` を外した環境で
  `global_test_lock_acquire` が**失敗する**ことを検証する（`|| pgid=""` を入れても
  strict 検証が生きていることの正コントロール）
- `verify-global-test-lock.sh` では **`HAVE_PS=0` のとき C25 / C26 を skip** し、
  **skip 数として必ず出力する**（skip を隠さない既存方針と揃える）

### 回避策の撤去

```typescript
// scripts/run-browser-test.contract.test.ts:197 / :208
writeExecutable(join(sandbox, "bin/php"), "#!/usr/bin/env bash\nsleep 0.1\nexit 0\n");
```

→ `sleep 0.1` を削除し、コメント（L191-196 / L207）も撤去する。
**この撤去自体が回帰テストである**: race が残っていればスタブが sub-millisecond で終わり、
契約テストが落ちる。

### PHPStan 適合チェック

対象外（shell / TypeScript のみ）。TypeScript 側は `pnpm typecheck` で確認する。

### テスト計画

- [x] バグ修正の再現テスト: **C25 を先に追加して現行スクリプトで赤くなることを確認**してから 2 箇所を修正する
- [x] 正コントロール: **C26**（`ps` 不在でロック取得が fail する = strict 検証が生きている）
- [x] 既存テストの更新: `scripts/run-browser-test.contract.test.ts`（回避策撤去）
- [x] 既存契約の再検証: `bash scripts/verify-global-test-lock.sh`（C01〜C26 全 pass / skip 数を確認）+ `tests/Architecture/GlobalTestLockInventoryTest.php`（層 2）
- [x] 全レーンの実走: `composer test` / `pnpm test` / `pnpm test:packages`（ロック経由）
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

| リスク | 対処 |
|---|---|
| `\|\| pgid=""` が「ps が本当に異常（権限エラー等）」の場合も握り潰す | 元の設計意図がそもそも best-effort（コメントが明示）。厳格判定は `_gtl_probe_process_group` の**取得時 1 回だけの強制検証**が担い、**値が一度も取れなければ `_gtl_die` する**（= `ps` 不在ではロック取得自体が fail する）。**C26 がこれを正コントロールとして固定する** |
| C25 / C26 が環境依存（`ps` 不在）で常に skip になり偽グリーン | 既存スイートは `HAVE_PS` で skip し、**skip 数を必ず出力する**設計。C25 / C26 も同じ扱いにし、skip が出たら報告に記載する |
| A1 と同じ `GlobalTestLockInventoryTest.php` を触るため競合 | **同一 TODO（グループ A）にまとめる**ことで競合を構造的に排除する |

---



---

## 実装側の補足 (実測値つき)

### 設計からの意図的な逸脱

1. **`\R` の是正箇所は 3 箇所ではなく 4 箇所だった**。
   設計は `rg preg_split` で探索していたため `preg_match` の 1 件を取りこぼしていた。
   新設した gate 自身が 4 件目 (`tests/Feature/Mail/MailThemeDesignParityTest.php:31` の
   `'/\A---\R(.*?)\R---\R/s'`) を検出した。DESIGN.md は日本語を含むため、
   `/u` 無しでは front matter 終端を文字途中で誤検出しうる実バグである。4 箇所すべて是正した。

2. **C25 の再現条件が設計の記述より厳しかった**。
   設計は「`bash with-global-test-lock.sh true` を 20 回」を想定していたが、実測でこれでは再現しない。
   `with-global-test-lock.sh` は `global_test_lock_run "$@" || status=$?` と書くため、POSIX の規定により
   `||` リストの中では関数本体でも `set -e` が無効化され、代入失敗が致命傷にならないからである。
   実レーン (`run-test.sh` / `run-browser-test.sh`) は `global_test_lock_run` を **裸で** 呼ぶので落ちる。
   そこで `STRICTLANE` フィクスチャ (`set -euo pipefail` + 裸呼び出し) を新設して再現条件を満たした。

### 実測 (テストファースト)

- 新 gate を先に書いて実行 → **P1 が赤** (違反 4 件を検出) → 4 箇所を是正 → 緑
- C25/C26 を先に追加して実行 → **C25 が 20/20 で赤 / C26 が rc=127 で赤** → 2 箇所を是正 → 緑
- `verify-global-test-lock.sh`: 修正前 passed=66 failed=2 skipped=0 → 修正後 **passed=68 failed=0 skipped=0**
  (T099 時点の baseline は passed=65。C25 で +2、C26 で +1)
- **各修正の切り分けを実測で確認**:
  - L350 (`global_test_lock_run`) の修正だけを戻す → 契約テスト `run-browser-test.contract.test.ts` が
    6/15 failed で赤 (= `sleep 0.1` 撤去が本物の回帰テストになっている)
  - L266 (`_gtl_probe_process_group`) の修正だけを戻す → **C26 だけが赤** (C25 は緑)
    = C25 と C26 がそれぞれ別の修正箇所を固定している
- `composer test`: 3019 passed / 0 failed / 2 skipped (main の baseline 3014 passed から新 gate 5 本ぶん増)
- `pnpm test`: 1202 passed (contract テストの `sleep 0.1` 撤去後、**5 回連続フル実行して全て緑**)
- `pnpm test:packages`: 106 passed / `composer phpstan`: No errors / `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm typecheck:packages` / `pnpm build`: 全て緑
- `pnpm run audit:gate`: Total advisories 0 (gate passed)


---

## 実装差分 (git diff)

```diff
diff --git a/scripts/global-test-lock.sh b/scripts/global-test-lock.sh
index 92e7728..894bbe7 100755
--- a/scripts/global-test-lock.sh
+++ b/scripts/global-test-lock.sh
@@ -263,7 +263,12 @@ _gtl_probe_process_group() {
         sleep 0.3 &
         pid=$!
         [ "${prev}" = "1" ] || set +m
-        pgid="$(ps -o pgid= -p "${pid}" 2>/dev/null | tr -d ' ')"
+        # `|| pgid=""` が必須: 呼び出し元レーンは `set -euo pipefail` で動くため、
+        # ps の非ゼロ (probe 対象が先に終わった / ps 自体が不在) が代入へ伝播して
+        # **リトライにも _gtl_die にも到達せず、その場でレーンごと落ちる**。
+        # 空は下の判定が「取れなかった」として扱う (厳格判定はこの関数が担い続ける)。
+        pgid=""
+        pgid="$(ps -o pgid= -p "${pid}" 2>/dev/null | tr -d ' ')" || pgid=""
         kill "${pid}" 2>/dev/null || true
         wait "${pid}" 2>/dev/null || true
         [ "${pgid}" = "${pid}" ] && return 0
@@ -347,7 +352,13 @@ global_test_lock_run() {
     _GTL_CHILD_PGID="${_GTL_CHILD_PID}"           # set -m により PGID == PID (取得時に probe 済み)
 
     # best-effort 検証: 空 = 既に終了 (race) なので異常ではない。値が違うときだけ落とす。
-    pgid="$(ps -o pgid= -p "${_GTL_CHILD_PID}" 2>/dev/null | tr -d ' ')"
+    #
+    # `|| pgid=""` が必須: レーンは `set -euo pipefail` で動くため、既に終了した pid に
+    # 対する ps の非ゼロが代入へ伝播し、**直下の -n 判定に到達する前にレーンごと落ちる**
+    # (偽赤)。空を許容するという下の意図を成立させるために、代入の失敗をここで吸収する。
+    # 厳格判定は取得時 1 回の _gtl_probe_process_group が担う (ここは元から best-effort)。
+    pgid=""
+    pgid="$(ps -o pgid= -p "${_GTL_CHILD_PID}" 2>/dev/null | tr -d ' ')" || pgid=""
     if [ -n "${pgid}" ] && [ "${pgid}" != "${_GTL_CHILD_PID}" ]; then
         _gtl_die "専用プロセスグループを作れなかった (pid=${_GTL_CHILD_PID} pgid=${pgid})"
     fi
diff --git a/scripts/run-browser-test.contract.test.ts b/scripts/run-browser-test.contract.test.ts
index 9ea9f34..6033531 100644
--- a/scripts/run-browser-test.contract.test.ts
+++ b/scripts/run-browser-test.contract.test.ts
@@ -186,15 +186,15 @@ function runInSandbox(
         writeFileSync(join(sandbox, "artisan"), "<?php\n", "utf-8");
         writeFileSync(join(sandbox, "phpunit.browser.xml"), "<phpunit/>\n", "utf-8");
 
-        // php スタブ: 何もせず成功する。
+        // php スタブ: 何もせず即座に成功する。
         //
-        // **短い sleep を入れるのは必須**: global_test_lock_run は起動直後の子を
-        // `ps -o pgid= -p "$pid"` で probe して専用プロセスグループを確認する。
-        // 子が sub-millisecond で終わると ps が「そんな pid は無い」で非ゼロを返し、
-        // `set -euo pipefail` 下の代入が失敗してレーンごと落ちる。実運用の
-        // `php artisan config:clear` / `vendor/bin/pest` はミリ秒では終わらないため
-        // 顕在化しないが、スタブは現実の所要時間を最低限模す必要がある。
-        writeExecutable(join(sandbox, "bin/php"), "#!/usr/bin/env bash\nsleep 0.1\nexit 0\n");
+        // **意図的に sleep を入れない**: T104 は pgid probe の race (既に終了した pid に
+        // 対する ps の非ゼロが `set -euo pipefail` 下の代入へ伝播し、レーンごと落ちる)
+        // を回避するためここに `sleep 0.1` を置いていた。T112 で global-test-lock.sh 側を
+        // `|| pgid=""` で正しく直したので回避策は撤去した。
+        // **この「sleep が無いこと」自体が回帰テストである**: race が戻れば
+        // スタブが sub-millisecond で終わって本契約テストが落ちる。
+        writeExecutable(join(sandbox, "bin/php"), "#!/usr/bin/env bash\nexit 0\n");
 
         const callsPath = join(sandbox, "pest-calls.jsonl");
         const failing = options.failingLanes ?? [];
@@ -204,8 +204,7 @@ function runInSandbox(
             [
                 "#!/usr/bin/env bash",
                 "set -u",
-                // bin/php と同じ理由 (global_test_lock_run の ps probe との race 回避)
-                "sleep 0.1",
+                // bin/php と同じ理由で sleep は入れない (T112 で race を根治済み)
                 `CALLS="${callsPath}"`,
                 // argv を JSON 配列へ (jq に依存しない素朴なエスケープ: 実引数に " や \\ は現れない)
                 'out="["',
diff --git a/scripts/verify-global-test-lock.sh b/scripts/verify-global-test-lock.sh
index 2665a17..9098d58 100755
--- a/scripts/verify-global-test-lock.sh
+++ b/scripts/verify-global-test-lock.sh
@@ -17,7 +17,7 @@
 # 使い方:
 #   bash scripts/verify-global-test-lock.sh
 #
-# 出力: 各ケースを C01..C24 の ID 付きで PASS / FAIL / SKIP 報告し、
+# 出力: 各ケースを C01..C26 の ID 付きで PASS / FAIL / SKIP 報告し、
 #       最後に集計を出す。FAIL が 1 つでもあれば非 0 で終了する。
 #       **skip 数を必ず出す** (偽グリーンを避けるため)。
 #
@@ -343,8 +343,29 @@ global_test_lock_run bash "$2" 2
 echo "SHOULD_NOT_REACH"
 EOF
 
+STRICTLANE="${WORK}/strict-lane.sh"
+cat >"${STRICTLANE}" <<'EOF'
+#!/usr/bin/env bash
+# 実レーン (scripts/run-test.sh / scripts/run-browser-test.sh) と同じ呼び出し条件を
+# 再現するフィクスチャ ($1=lib)。
+#
+# **この 2 条件が非交渉** (どちらかを崩すと race を再現できない):
+#   (1) `set -e` あり
+#   (2) global_test_lock_run を `|| ...` で受けない
+# with-global-test-lock.sh は `global_test_lock_run "$@" || status=$?` と書くため、
+# POSIX の規定で関数本体の -e が無効化され、代入失敗が致命傷にならない。
+# 一方 run-test.sh / run-browser-test.sh は裸で呼ぶので落ちる。
+set -euo pipefail
+# shellcheck source=/dev/null
+. "$1"
+global_test_lock_acquire "strict lane fixture"
+global_test_lock_run true
+echo "lane_ok=1"
+EOF
+
 chmod +x "${SLEEPER}" "${SPAWNER}" "${IGNORER}" "${FDCHECK}" "${PGIDCHECK}" \
-    "${REENTER}" "${MONITORCHECK}" "${HOOKLANE}" "${DOUBLEACQ}" "${ABNORMAL}" "${SURVIVOR}"
+    "${REENTER}" "${MONITORCHECK}" "${HOOKLANE}" "${DOUBLEACQ}" "${ABNORMAL}" "${SURVIVOR}" \
+    "${STRICTLANE}"
 
 # ---------------------------------------------------------------------------
 # C01: lock path の導出
@@ -1316,6 +1337,102 @@ case_c24() {
     fi
 }
 
+# ---------------------------------------------------------------------------
+# C25: sub-millisecond で終了する子でもレーンが落ちない (pgid probe の race 許容)
+#
+# 回帰の対象: `pgid="$(ps ...)"` が set -euo pipefail 下で **代入ごと** 失敗し、
+# 直下の「空 = race として許容」判定に到達せずレーンが落ちていた
+# (T104 が contract テストへ sleep 0.1 の回避策を入れる原因になった偽赤)。
+#
+# 1 回だけだと race が確率的に外れて偽グリーンになりうるので 20 回反復する。
+# ---------------------------------------------------------------------------
+case_c25() {
+    local id="C25" d i=0 rc fails=0
+    if [ "${HAVE_PS}" -eq 0 ]; then
+        t_skip "${id}" "ps 不在 (pgid probe そのものに到達しない)"
+        t_skip "${id}" "ps 不在 (best-effort probe の die 検査)"
+        return
+    fi
+    d="$(new_dir)"
+    : >"${WORK}/c25.err"
+    while [ "${i}" -lt 20 ]; do
+        i=$((i + 1))
+        rc=0
+        GLOBAL_TEST_LOCK_DIR="${d}" bash "${STRICTLANE}" "${LIB}" \
+            >"${WORK}/c25.out" 2>>"${WORK}/c25.err" || rc=$?
+        if [ "${rc}" -ne 0 ] || ! grep -q '^lane_ok=1$' "${WORK}/c25.out"; then
+            fails=$((fails + 1))
+        fi
+    done
+
+    if [ "${fails}" -eq 0 ]; then
+        t_ok "${id}" "即座に終了する子でもレーンが落ちない (20/20)"
+    else
+        t_fail "${id}" "pgid probe の race でレーンが落ちた (${fails}/20)"
+    fi
+    # best-effort probe が「値が違うときだけ落とす」契約を守っているか
+    # (空を不一致と誤判定して die していないこと)。
+    if grep -q '専用プロセスグループを作れなかった' "${WORK}/c25.err"; then
+        t_fail "${id}" "best-effort probe が空の pgid を不一致として die した"
+    else
+        t_ok "${id}" "best-effort probe が空の pgid で die しない"
+    fi
+}
+
+# ---------------------------------------------------------------------------
+# C26: ps が使えない環境ではロック取得が fail する (strict 検証が生きていることの正コントロール)
+#
+# `|| pgid=""` は best-effort probe の意図を成立させるだけで、厳格判定
+# (_gtl_probe_process_group の 3 回リトライ → 一度も取れなければ _gtl_die) を弱めない。
+#
+# **偽グリーン対策**: 単に PATH を空にすると flock / tr / stat など別コマンドの不在で
+# 先に落ち、「非ゼロ終了」だけを見ると通ってしまう。そこで
+#   (a) 一時 PATH ディレクトリに **必要なコマンドだけ** symlink し、ps だけを置かない
+#   (b) 終了コードに加えて **_gtl_probe_process_group 固有のメッセージ** が stderr に出ること
+#   (c) acquire に到達した証跡 (override lock dir の警告) が出ていること
+# の 3 点を満たして初めて PASS とする。
+# ---------------------------------------------------------------------------
+case_c26() {
+    local id="C26" d fake cmd src rc=0
+    if [ "${HAVE_PS}" -eq 0 ]; then
+        t_skip "${id}" "ps 不在 (ps 有り環境との対比が取れないため正コントロールにならない)"
+        return
+    fi
+    if [ "${HAVE_FLOCK}" -eq 0 ]; then
+        t_skip "${id}" "flock(1) 不在 (probe に到達しない)"
+        return
+    fi
+
+    d="$(new_dir)"
+    fake="${WORK}/c26-bin"
+    rm -rf "${fake}"
+    mkdir -p "${fake}"
+    # ps 以外の依存コマンドだけを通す。ここに ps を **置かない** ことが本ケースの本体。
+    for cmd in bash dirname id mkdir stat flock sleep tr rm mv date awk cat head; do
+        src="$(command -v "${cmd}" 2>/dev/null || true)"
+        [ -n "${src}" ] && ln -sfn "${src}" "${fake}/${cmd}"
+    done
+    if [ ! -e "${fake}/bash" ] || [ ! -e "${fake}/flock" ] || [ ! -e "${fake}/stat" ]; then
+        t_skip "${id}" "隔離 PATH に必要なコマンドを揃えられなかった"
+        return
+    fi
+
+    env -i "PATH=${fake}" "HOME=${HOME:-/tmp}" \
+        "GLOBAL_TEST_LOCK_DIR=${d}" \
+        "GLOBAL_TEST_LOCK_HEARTBEAT_SECS=${GLOBAL_TEST_LOCK_HEARTBEAT_SECS}" \
+        "GLOBAL_TEST_LOCK_GRACE_SECS=${GLOBAL_TEST_LOCK_GRACE_SECS}" \
+        "${fake}/bash" "${STRICTLANE}" "${LIB}" \
+        >"${WORK}/c26.out" 2>"${WORK}/c26.err" || rc=$?
+
+    if [ "${rc}" -ne 0 ] &&
+        grep -q 'job control で専用プロセスグループを作れない' "${WORK}/c26.err" &&
+        grep -q 'using override lock dir' "${WORK}/c26.err"; then
+        t_ok "${id}" "ps 不在ならロック取得が明示エラーで fail する (strict 検証は健在)"
+    else
+        t_fail "${id}" "ps 不在を素通し / 別要因で落ちた (rc=${rc}, err=$(tr '\n' ' ' <"${WORK}/c26.err" | head -c 200))"
+    fi
+}
+
 # ---------------------------------------------------------------------------
 # C11: 全ケース終了後に子孫プロセスが残らない (最後に実行する)
 # ---------------------------------------------------------------------------
@@ -1362,6 +1479,8 @@ main() {
     case_c22
     case_c23
     case_c24
+    case_c25
+    case_c26
     case_c11
 
     echo ""
diff --git a/tests/Architecture/BughuntOrchestratorGateInvariantTest.php b/tests/Architecture/BughuntOrchestratorGateInvariantTest.php
index ec4778c..00904e2 100644
--- a/tests/Architecture/BughuntOrchestratorGateInvariantTest.php
+++ b/tests/Architecture/BughuntOrchestratorGateInvariantTest.php
@@ -92,7 +92,9 @@ function bughuntGateIsInertLocal(string $trimmed): bool
  */
 function bughuntGateFirstEffectiveStatement(string $window): string
 {
-    foreach (preg_split('/\R/', $window) ?: [] as $line) {
+    // `/u` は必須 (PcreUnicodeModifierGateTest): 非 UTF-8 モードの `\R` はバイト 0x85 (NEL)
+    // にも一致し、日本語コメントを文字途中で分断して行構造を壊す。
+    foreach (preg_split('/\R/u', $window) ?: [] as $line) {
         $trimmed = trim($line);
         if ($trimmed === '' || str_starts_with($trimmed, '#') || $trimmed === '{') {
             continue;
diff --git a/tests/Architecture/GlobalTestLockInventoryTest.php b/tests/Architecture/GlobalTestLockInventoryTest.php
index ae06ee0..238f43c 100644
--- a/tests/Architecture/GlobalTestLockInventoryTest.php
+++ b/tests/Architecture/GlobalTestLockInventoryTest.php
@@ -104,7 +104,7 @@ function globalTestLockLaneViolations(array $scripts): array
         // **最終行 (= 実際に走るコマンド) が公式入口そのものであること**を要求し、
         // 同一行のシェル演算子で別コマンドを繋ぐことを禁止する。
         $lines = array_values(array_filter(
-            array_map(trim(...), preg_split('/\R/', $command) ?: []),
+            array_map(trim(...), preg_split('/\R/u', $command) ?: []),
             static fn (string $l): bool => $l !== '',
         ));
         $last = $lines === [] ? '' : $lines[count($lines) - 1];
@@ -143,7 +143,9 @@ function globalTestLockLaneViolations(array $scripts): array
  */
 function globalTestLockCodeLines(string $source): string
 {
-    $lines = preg_split('/\R/', $source) ?: [];
+    // `/u` は必須: 非 UTF-8 モードの `\R` はバイト 0x85 (NEL) にも一致し、日本語コメントを
+    // 文字途中で分断して「コメント断片がコードとして漏出する」(PcreUnicodeModifierGateTest)。
+    $lines = preg_split('/\R/u', $source) ?: [];
     $code = array_filter(
         $lines,
         static fn (string $line): bool => preg_match('/^\s*#/', $line) !== 1,
diff --git a/tests/Architecture/PcreUnicodeModifierGateTest.php b/tests/Architecture/PcreUnicodeModifierGateTest.php
new file mode 100644
index 0000000..a4bec2c
--- /dev/null
+++ b/tests/Architecture/PcreUnicodeModifierGateTest.php
@@ -0,0 +1,408 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * Architecture invariant: PCRE パターンリテラルが `\R` を含むなら `u` 修飾子が必須。
+ *
+ * PCRE の `\R` は **8bit 非 UTF-8 モードでバイト 0x85 (NEL) にもマッチする**。
+ * UTF-8 の日本語には 0x85 を含む文字が多数あり (「全」E5 85 A8 /「先」E5 85 88 /
+ * 「共」E5 85 B1 /「内」E5 86 85 /「入」E5 85 A5 /「公」E5 85 AC など)、
+ * `/u` の無い `preg_split('/\R/')` は **文字の途中で行を分断する**。
+ *
+ * 実害 (監査サイクル 2 で実測): GlobalTestLockInventoryTest の解析入力で
+ * scripts/global-test-lock.sh が 380 行 → 454 行に偽分割され、4.8 KB のコメント文字列が
+ * 「コード」として検査対象に漏出していた。漏出テキストに検査語が 1 つ現れた時点で
+ * ゲートが偽赤になる。本リポジトリは **コメントを日本語で書く規約** (AGENTS.md §実装規約)
+ * なので、踏むのは時間の問題である。
+ *
+ * 本ゲートは deny-by-default で固定する。免除リストは持たない
+ * (このリポジトリに `\R` を非 UTF-8 モードで使う正当な用途が 1 つも無いため)。
+ *
+ * **共通ヘルパ化ではなくゲートを選んだ理由**: 呼び出し箇所は 3 つしかなく、共通の
+ * 行分割ヘルパを作ると新しい共有クラスが 1 本増える (AGENTS.md 思考原則 2)。
+ * ゲートがあれば `/u` 忘れは書いた瞬間に検出できるので、ヘルパは不要。
+ *
+ * 解析は PhpToken (コメントは別トークンなので拾わない)。文字列 grep にすると
+ * 「本ゲートの説明コメント」自身で偽赤になる。
+ *
+ * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
+ */
+
+/**
+ * PCRE パターンリテラルとして認識するデリミタ (このリポジトリで実際に使われているもの)。
+ *
+ * @var list<string>
+ */
+const PCRE_DELIMITERS = ['/', '#', '~', '%', '!', '@'];
+
+/**
+ * 走査対象ディレクトリ (リポジトリルートからの相対)。
+ *
+ * @var list<string>
+ */
+const PCRE_SCAN_DIRS = ['app', 'tests', 'config', 'database', 'routes', 'scripts'];
+
+/**
+ * 走査対象の PHP ファイル一覧。
+ *
+ * @return list<array{absolute: string, relative: string}>
+ */
+function pcreScanTargets(): array
+{
+    $root = base_path();
+    $files = [];
+    foreach (PCRE_SCAN_DIRS as $dir) {
+        $base = $root.'/'.$dir;
+        if (! is_dir($base)) {
+            continue;
+        }
+        $iterator = new RecursiveIteratorIterator(
+            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
+        );
+        foreach ($iterator as $file) {
+            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
+                continue;
+            }
+            $absolute = $file->getRealPath();
+            if (! is_string($absolute)) {
+                continue;
+            }
+            $files[] = [
+                'absolute' => $absolute,
+                'relative' => ltrim(str_replace($root, '', $absolute), '/'),
+            ];
+        }
+    }
+
+    return $files;
+}
+
+/**
+ * PHP の文字列リテラルトークンを **評価後の値** へ復元する (純関数)。
+ *
+ * **復元規則**: 引用符を剥がしたあと `\\` → `\` の 1 パスだけを畳む
+ * (加えて `\'` / `\"` のような「引用符自身のエスケープ」も畳む)。
+ * `\R` は PHP のエスケープ列ではないため single / double のどちらでもそのまま残る
+ * = この 1 パスで必要十分。
+ *
+ * **意図的な射程外**: double-quoted の `"\x5cR"` / `"\u{5c}R"` は PHP 評価後に `\R` に
+ * なるが、本関数は復元しない (16 進 / Unicode エスケープまで復元すると PHP の
+ * 文字列評価器を再実装することになり、費用対効果が合わない)。
+ * このリポジトリに該当記述は 1 件も無い。将来必要になったら射程を広げる。
+ *
+ * @return string|null literal でなければ null
+ */
+function pcreUnquoteLiteral(string $raw): ?string
+{
+    // b'...' / B"..." のようなバイナリ接頭辞を落とす。
+    $raw = preg_replace('/\A[bB]/', '', $raw) ?? $raw;
+    if (strlen($raw) < 2) {
+        return null;
+    }
+    $quote = $raw[0];
+    if (($quote !== "'" && $quote !== '"') || $raw[strlen($raw) - 1] !== $quote) {
+        return null;
+    }
+
+    $inner = substr($raw, 1, -1);
+    $out = '';
+    $len = strlen($inner);
+    for ($i = 0; $i < $len; $i++) {
+        if ($inner[$i] === '\\' && $i + 1 < $len) {
+            $next = $inner[$i + 1];
+            if ($next === '\\' || $next === $quote) {
+                $out .= $next;
+                $i++;
+
+                continue;
+            }
+            // PHP のエスケープ列でないものは `\` ごとそのまま残す (`\R` はここを通る)。
+            $out .= '\\'.$next;
+            $i++;
+
+            continue;
+        }
+        $out .= $inner[$i];
+    }
+
+    return $out;
+}
+
+/**
+ * PCRE パターン body が **改行クラス `\R`** を含むかを判定する (純関数)。
+ *
+ * 単純な `str_contains($body, '\R')` は誤判定する: `\\R` (エスケープされた
+ * バックスラッシュ + 文字 `R`) にも部分文字列として `\R` が現れるため。
+ * 先頭からエスケープを畳みながら走査して「奇数個目の `\` の直後の `R`」だけを拾う。
+ */
+function pcreBodyHasNewlineClass(string $body): bool
+{
+    $len = strlen($body);
+    for ($i = 0; $i < $len; $i++) {
+        if ($body[$i] !== '\\') {
+            continue;
+        }
+        if ($i + 1 >= $len) {
+            return false;
+        }
+        if ($body[$i + 1] === 'R') {
+            return true;
+        }
+        $i++; // エスケープされた 1 文字を読み飛ばす (`\\` → 次の文字は素の文字)
+    }
+
+    return false;
+}
+
+/**
+ * PHP ソースから **PCRE パターンリテラル** を抽出する (純関数)。
+ *
+ * **射程の明示**: 本抽出器は完全な PCRE parser ではない。escaped delimiter
+ * (`'/a\/b/'`) や文字クラス内の delimiter (`'/[/]/'`) を厳密に扱わない。
+ * 射程は「`\R` を含むパターンリテラルの `u` 修飾子欠落を検出すること」に限定する。
+ * 動的生成 (`sprintf('/%s/', $x)`) と補間文字列 (`"/$x/"`) は
+ * `T_CONSTANT_ENCAPSED_STRING` にならないため対象外 (意図的な射程外)。
+ *
+ * @return list<array{literal: string, body: string, modifiers: string}>
+ */
+function pcrePatternLiterals(string $source): array
+{
+    $patterns = [];
+
+    /** @var list<PhpToken> $tokens */
+    $tokens = PhpToken::tokenize($source);
+    foreach ($tokens as $token) {
+        if (! $token->is(T_CONSTANT_ENCAPSED_STRING)) {
+            continue;
+        }
+        $value = pcreUnquoteLiteral($token->text);
+        if ($value === null || $value === '') {
+            continue;
+        }
+
+        $delimiter = $value[0];
+        if (! in_array($delimiter, PCRE_DELIMITERS, true)) {
+            // デリミタ始まりでない = 通常の文字列 (「`\R` は改行クラス」等の説明文)。
+            continue;
+        }
+
+        $end = strrpos($value, $delimiter);
+        if ($end === false || $end === 0) {
+            continue;
+        }
+
+        $modifiers = substr($value, $end + 1);
+        if (preg_match('/\A[a-zA-Z]*\z/', $modifiers) !== 1) {
+            continue;
+        }
+
+        $patterns[] = [
+            'literal' => $token->text,
+            'body' => substr($value, 1, $end - 1),
+            'modifiers' => $modifiers,
+        ];
+    }
+
+    return $patterns;
+}
+
+/**
+ * `\R` を含むのに `u` 修飾子が無いパターンリテラルを返す (純関数)。
+ *
+ * @return list<string> 違反リテラル (原文のまま)
+ */
+function pcreLiteralsMissingUnicodeModifier(string $source): array
+{
+    $violations = [];
+    foreach (pcrePatternLiterals($source) as $pattern) {
+        if (! pcreBodyHasNewlineClass($pattern['body'])) {
+            continue;
+        }
+        if (str_contains($pattern['modifiers'], 'u')) {
+            continue;
+        }
+        $violations[] = $pattern['literal'];
+    }
+
+    return $violations;
+}
+
+/**
+ * 走査対象全体の収集結果。
+ *
+ * @return array{violations: list<string>, patterns: int, files: int, architecturePatterns: int, relatives: list<string>}
+ */
+function pcreCollectAll(): array
+{
+    $violations = [];
+    $patterns = 0;
+    $architecturePatterns = 0;
+    $files = 0;
+    $relatives = [];
+
+    foreach (pcreScanTargets() as $target) {
+        $source = file_get_contents($target['absolute']);
+        if (! is_string($source)) {
+            continue;
+        }
+        $files++;
+        $relatives[] = $target['relative'];
+
+        $found = pcrePatternLiterals($source);
+        $patterns += count($found);
+        if (str_starts_with($target['relative'], 'tests/Architecture/')) {
+            $architecturePatterns += count($found);
+        }
+
+        foreach (pcreLiteralsMissingUnicodeModifier($source) as $literal) {
+            $violations[] = "{$target['relative']} → {$literal}";
+        }
+    }
+
+    return [
+        'violations' => $violations,
+        'patterns' => $patterns,
+        'files' => $files,
+        'architecturePatterns' => $architecturePatterns,
+        'relatives' => $relatives,
+    ];
+}
+
+// P1
+test('`\R` を含む PCRE パターンリテラルに `u` 修飾子が付いている', function (): void {
+    $result = pcreCollectAll();
+
+    expect($result['violations'])->toBe([],
+        '`/u` の無い `\R` を検出しました。非 UTF-8 モードの `\R` はバイト 0x85 (NEL) にも '
+        .'マッチし、UTF-8 の日本語を文字途中で分断します。`u` 修飾子を付けてください。'
+        .PHP_EOL.implode(PHP_EOL, $result['violations']));
+});
+
+// P2 / P3: 空振り防止 (drift ガード)。走査基盤が壊れて「0 件検査して green」になる退行を落とす。
+// 下限を「`\R` を含むリテラルが N 件以上」にしないのは、将来 3 箇所すべてがリファクタで
+// 消えたときに **正しい状態が偽赤になる** ため。下限は「抽出器が動いていること」に掛ける。
+// 閾値は現在値 (PCRE リテラル多数 / 対象ファイル 300 超) から大きく下げた固定値にし、
+// 「代表ファイルが走査対象に含まれる」検査を併用する。規模に連動する高い閾値は
+// リポジトリの縮小・分割で偽赤になり、本ゲートが減らそうとしているものと同種の罠になる。
+test('走査が空振りしていない (PCRE リテラル抽出とファイル走査が実際に動いている)', function (): void {
+    $result = pcreCollectAll();
+
+    // P2: 抽出器が実際にパターンを拾えている
+    expect($result['patterns'])->toBeGreaterThanOrEqual(20);
+    expect($result['architecturePatterns'])->toBeGreaterThanOrEqual(1);
+
+    // P3: ファイル走査が実際に効いている + 代表ファイルが対象に入っている
+    expect($result['files'])->toBeGreaterThanOrEqual(50);
+    expect($result['relatives'])->toContain('tests/Architecture/GlobalTestLockInventoryTest.php');
+});
+
+/*
+ * 正のコントロール (P4/P5/P6/P12/P14): 実ファイルを書き換えず fixture ソースに対して
+ * ゲートが点灯することを確認する。本体の違反は 0 件 (= 予防ゲート) のため、
+ * ここが空振りでないことの唯一の担保になる。
+ *
+ * fixture は nowdoc に置く: nowdoc 本体は T_ENCAPSED_AND_WHITESPACE であって
+ * T_CONSTANT_ENCAPSED_STRING ではないため、**本ファイル自身が P1 で違反にならない**。
+ */
+test('正のコントロール: `u` 修飾子の無い `\R` を検出する', function (): void {
+    // P4: single-quoted `/\R/`
+    $p4 = <<<'PHP'
+    <?php
+    $lines = preg_split('/\R/', $x);
+    PHP;
+    expect(pcreLiteralsMissingUnicodeModifier($p4))->toHaveCount(1);
+
+    // P5: `u` 以外の修飾子だけがある
+    $p5 = <<<'PHP'
+    <?php
+    preg_match("/\R/m", $x);
+    PHP;
+    expect(pcreLiteralsMissingUnicodeModifier($p5))->toHaveCount(1);
+
+    // P6: 別デリミタ
+    $p6 = <<<'PHP'
+    <?php
+    preg_match('#\R#', $x);
+    PHP;
+    expect(pcreLiteralsMissingUnicodeModifier($p6))->toHaveCount(1);
+
+    // P12: double-quoted (評価後 `/\R/`)
+    $p12 = <<<'PHP'
+    <?php
+    preg_split("/\R/", $x);
+    PHP;
+    expect(pcreLiteralsMissingUnicodeModifier($p12))->toHaveCount(1);
+
+    // P14: PHP ソース上の `'/\\R/'` は評価後 `/\R/` = 改行クラス
+    $p14 = <<<'PHP'
+    <?php
+    preg_split('/\\R/', $x);
+    PHP;
+    expect(pcreLiteralsMissingUnicodeModifier($p14))->toHaveCount(1);
+});
+
+test('負のコントロール: `u` 付き / `\R` 不使用 / コメント / 通常文字列を誤検出しない', function (): void {
+    // P7: `u` 付き
+    $p7 = <<<'PHP'
+    <?php
+    preg_split('/\R/u', $x);
+    PHP;
+    expect(pcreLiteralsMissingUnicodeModifier($p7))->toBe([]);
+    expect(pcrePatternLiterals($p7))->toHaveCount(1);
+
+    // P8: 別デリミタ + `u`
+    $p8 = <<<'PHP'
+    <?php
+    preg_match('#\R#u', $x);
+    PHP;
+    expect(pcreLiteralsMissingUnicodeModifier($p8))->toBe([]);
+
+    // P9: `\R` 不使用 (明示列挙は非 UTF-8 でも安全)
+    $p9 = <<<'PHP'
+    <?php
+    preg_split('/\r\n|\r|\n/', $x);
+    PHP;
+    expect(pcreLiteralsMissingUnicodeModifier($p9))->toBe([]);
+
+    // P10: コメント内の記述 (これが文字列 grep ではなく PhpToken を使う理由そのもの)
+    $p10 = <<<'PHP'
+    <?php
+    // preg_split('/\R/') は NEL にも当たるので使わない
+    /** `/\R/` の説明 */
+    $x = 1;
+    PHP;
+    expect(pcreLiteralsMissingUnicodeModifier($p10))->toBe([]);
+    expect(pcrePatternLiterals($p10))->toBe([]);
+
+    // P11: デリミタ始まりでない通常文字列
+    $p11 = <<<'PHP'
+    <?php
+    $msg = '\R は改行クラスです';
+    PHP;
+    expect(pcreLiteralsMissingUnicodeModifier($p11))->toBe([]);
+    expect(pcrePatternLiterals($p11))->toBe([]);
+
+    // P13: PHP ソース上の `'/\\\\R/'` は評価後 `/\\R/` = リテラルの `\` + `R` (改行クラスではない)
+    $p13 = <<<'PHP'
+    <?php
+    preg_split('/\\\\R/', $x);
+    PHP;
+    expect(pcreLiteralsMissingUnicodeModifier($p13))->toBe([]);
+    expect(pcrePatternLiterals($p13))->toHaveCount(1);
+});
+
+/*
+ * P15: **意図的な射程外** の固定。
+ * `"/\x5cR/"` は PHP 評価後 `/\R/` になるが、本抽出器は 16 進エスケープを復元しない
+ * (PHP の文字列評価器の再実装は費用対効果が合わない)。このリポジトリに該当記述は
+ * 1 件も無く、将来必要になったら射程を広げる。ここが 0 件であることを固定しておくと、
+ * 射程を広げたときにこのテストが落ちて「射程が変わった」と気づける。
+ */
+test('射程外の固定: 16 進エスケープで書かれた `\R` は (意図的に) 検出しない', function (): void {
+    $p15 = <<<'PHP'
+    <?php
+    preg_split("/\x5cR/", $x);
+    PHP;
+    expect(pcreLiteralsMissingUnicodeModifier($p15))->toBe([]);
+});
diff --git a/tests/Feature/Mail/MailThemeDesignParityTest.php b/tests/Feature/Mail/MailThemeDesignParityTest.php
index 9b6efaa..03dc8d4 100644
--- a/tests/Feature/Mail/MailThemeDesignParityTest.php
+++ b/tests/Feature/Mail/MailThemeDesignParityTest.php
@@ -28,7 +28,10 @@ function mailThemeDesignColors(): array
     expect($content)->toBeString();
     assert(is_string($content));
 
-    expect(preg_match('/\A---\R(.*?)\R---\R/s', $content, $matches))->toBe(1);
+    // `/u` は必須 (PcreUnicodeModifierGateTest): 非 UTF-8 モードの `\R` はバイト 0x85 (NEL)
+    // にも一致する。DESIGN.md は日本語を含むため、`/u` が無いと front matter の終端を
+    // 文字の途中で誤検出しうる。
+    expect(preg_match('/\A---\R(.*?)\R---\R/su', $content, $matches))->toBe(1);
 
     $front = Yaml::parse($matches[1]);
     expect($front)->toBeArray()->toHaveKey('colors');

```
