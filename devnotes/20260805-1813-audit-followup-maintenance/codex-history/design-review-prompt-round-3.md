Round 2 の指摘 ([Critical] 1 件 / [Warning] 4 件 / [Suggestion] 1 件) をすべて受け入れ、詳細設計を修正しました。

特に C2 は指摘どおり **`Orphan` も明示指定制** に変更し、
「分類は説明のために行い、削除可否を分類だけで自動決定しない」という原則へ寄せています
(= `--include-hash` で名指ししない限り 1 件も落ちない)。

## 対応マトリクス

# 対応マトリクス: design-review Round 2

## C2 [Critical] `--include-hash` だけでは「細工された labeled foreign DB」の経路が残る

- 判断: **対応する**（提案どおり `Orphan` も明示指定制にする）
- 根拠: 指摘は正しい。現設計では
  - 別クローンの生存 DB の provenance を**存在しないパスへ書き換える**
  - あるいはその path が**現在のコンテナ / namespace から見えない**（bind mount の差など）

  のいずれでも分類は `Foreign` ではなく **`Orphan`** になり、`Orphan → shouldDrop = true` なので
  `--include-hash` なしで DROP 対象に入ってしまう。
  「現在のクローンから生存を否定できない hash」は**すべて明示指定制**にするのが正しい。
  これは「**分類は説明のために行い、削除可否を分類だけで自動決定しない**」という
  一段強い原則へ設計を寄せることでもある（AGENTS.md 禁止事項 3 の趣旨にも合う）。
- 対応内容:
  - 優先順位表の 4・5 を変更:
    ```
    4. Orphan    → shouldDrop = (hash ∈ --include-hash)
    5. Unlabeled → shouldDrop = (hash ∈ --include-hash)
    ```
    = **`--include-hash` で名指ししない限り 1 件も落ちない**
  - `Protected` / `Live` は `--include-hash` に指定されても **DROP しない**（保護が優先）と明記
  - dry-run 出力の「DROP 対象」欄は `--include-hash` 指定分のみになる旨を反映
  - テスト追加: T-C2-19（細工された provenance の foreign が指定なしで落ちない）/
    T-C2-20（Orphan は指定時のみ落ちる）/ T-C2-21（Protected・Live は指定されても落ちない）/
    T-C2-22（provenance path が見えないケースを Orphan として保護する）

## C2 [Warning] T-C2-17/18 が「生成 SQL の検証」だけでは分岐と例外経路を証明できない

- 判断: **対応する**（PDO 境界を注入可能な関数へ分離する）
- 根拠: 指摘のとおり。「両分岐が実際に stamp を呼ぶ」「例外時に exit 0 で続行する」は
  SQL 文字列の検証では証明できない。PDO を fake するのは筋が悪いので、
  **PDO に触れない形へ境界を切る**。
- 対応内容: 2 つの関数へ分離し、どちらも PDO 無しで単体テストできるようにする。
  - `testDatabaseEnsurePlan(bool $exists, string $base, string $provenance): list<string>`
    — **純関数**。存在するときは `[COMMENT]`、しないときは `[CREATE, COMMENT]` を返す。
    「**両分岐とも COMMENT を含む**」をテストで固定する
  - `pgsqlStampProvenance(callable(string): mixed $exec, string $sql): bool`
    — `$exec` を注入する best-effort 実行器。`Throwable` を捕まえて `false` を返し stderr へ warning。
    **例外を投げるクロージャ**と**成功するクロージャ**の 2 本で例外経路を直接テストできる
  - `ensure-test-db.php` 本体は「plan を作って exec に流す」だけになる

## A1 [Warning] P13 の PHP 文字列評価が誤っている

- 判断: **対応する**（指摘が正しい。こちらの記述ミス）
- 根拠: PHP の単一引用符では `'/\\R/'` は評価後 `/\R/` = **PCRE の改行クラス**であり、
  **検出対象**である。非検出になるのは評価後に `\\R` となる `'/\\\\R/'` の方。
- 対応内容:
  - P13 を「PHP ソース上の `'/\\\\R/'`（評価後 `/\\R/`）→ **非検出**」に修正
  - **P14 を新設**: 「PHP ソース上の `'/\\R/'`（評価後 `/\R/`）→ **検出**」
  - 抽出器の**復元規則**を明記:
    「`\\` → `\` の 1 パスだけを畳み、それ以外のエスケープは畳まない
    （single-quoted は追加で `\'` → `'`）。`\R` は PHP のエスケープ列ではないため
    single/double のどちらでもそのまま残る = この 1 パスで必要十分」
  - テスト計画の参照を「P1〜P11」→「**P1〜P14**」に更新

## A2 [Warning] `ps` 不在時の説明が現行コードと一致しない

- 判断: **対応する**（設計側の記述を実挙動に合わせ、さらに contract を追加する）
- 根拠: 指摘のとおり。現行 `_gtl_probe_process_group()` は 3 回とも `pgid` が空なら
  ループを抜けて `_gtl_die` する。したがって**ロック機構は `ps` を必須としている**のが実挙動で、
  「`ps` 不在なら通す」と書いた前回の記述は誤り。
- 対応内容:
  - 記述を「**`ps` 不在ではロック取得が fail する（現行挙動。本施策はこれを変更しない）**」へ訂正
  - `|| pgid=""` が strict 検証を弱めない理由を明示:
    厳格判定は `_gtl_probe_process_group()`（取得時 1 回・3 回リトライ・空なら `_gtl_die`）にあり、
    `global_test_lock_run()` 側は元から best-effort。**責務分担は変わらない**
  - **C26 を追加**: `PATH` から `ps` を外した環境で `global_test_lock_acquire` が
    **失敗する**ことを検証する（`|| pgid=""` を入れても strict 検証が生きていることの正コントロール）
  - `verify-global-test-lock.sh` の `HAVE_PS=0` では C25 / C26 を skip し、**skip 数として必ず報告**する

## C1 [Warning] 適用直後の `git status` 期待値が矛盾している

- 判断: **対応する**（指摘が正しい。こちらの記述ミス）
- 根拠: `git rm --cached` 後の staged deletion は porcelain で `D ` と出る。
  「D でもなく」と書いたのは誤り。
- 対応内容: 機械判定へ置き換える:
  - `^D ` で始まる行が **ちょうど 58 件**
  - **列 2（unstaged）が空白でない行が 0 件**
  - `^\?\?` の行が **0 件**
  - `find doc/reference -type f | wc -l` が **139 のまま**（作業ツリー無傷）

## B2 [Suggestion] テスト計画の参照を V0〜V7 に更新

- 判断: **対応する**
- 対応内容: B2 のテスト計画の「V1〜V6」を「**V0〜V7**」に更新。

## B1 / D1

- 判断: **対応不要**（APPROVE。指摘なし）


## 修正後の詳細設計書

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

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 | グループ |
|---|--------|------------|--------|---|
| A1 | `preg_split('/\R/')` の `/u` 是正 + PCRE `\R` ゲート新設 | `tests/Architecture/GlobalTestLockInventoryTest.php` / `tests/Architecture/BughuntOrchestratorGateInvariantTest.php` / **新規** `tests/Architecture/PcreUnicodeModifierGateTest.php` | High | A |
| A2 | `global-test-lock.sh` の pgid 取得 race 修正 + 回避策撤去 | `scripts/global-test-lock.sh` / `scripts/verify-global-test-lock.sh` / `scripts/run-browser-test.contract.test.ts` | High | A |
| B1 | bug-hunt インベントリ drift 解消 + CI 配線 | `.claude/skills/app-bug-hunt/{screens,operations}.md` / `stories/{S1,S6}*.md` / `.github/workflows/ci.yml` / `tests/js/architecture/ci-workflow-inventory.test.ts` | High | B |
| B2 | ドキュメント乖離 7 件の是正 + 同期ゲート 2 本 | `AGENTS.md` / `README.md` / `.env.example` / `docs/architecture.md` / `docs/worktree-isolation-strategy.md` / `.claude/skills/app-implement/SKILL.md` / **新規** `tests/Architecture/RouteBindingCustomBinderDocSyncTest.php` / **新規** `tests/js/architecture/verification-commands-doc-sync.test.ts` | Medium | B |
| C1 | `doc/reference/` の NFC/NFD 重複解消 + 再発防止ゲート | git index（`doc/reference/` の NFD entry 58 件）/ `docs/worktree-isolation-strategy.md` / **新規** `tests/Architecture/GitIndexNormalizationTest.php` | Medium | C |
| C2 | 孤児テスト DB の回収経路（provenance + 三重 guard + confirm token） | `scripts/ci/ensure-test-db.php` / `scripts/ci/drop-test-db.php` / `scripts/ci/pgsql_test_conn.php` / `tests/Support/Ci/*`（新規 3 型）/ `scripts/teardown-worktree.sh` / `scripts/README.md` / `AGENTS.md` / **新規** `tests/Unit/Ci/TestDatabaseClassificationTest.php` | Medium | C |
| D1 | 未受容 advisory 4 件の upgrade | `packages/cli/package.json` / `package.json` / `pnpm-lock.yaml` | Medium | D |

---

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
- [x] 新規テスト: `PcreUnicodeModifierGateTest`（P1〜P14）
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
# PATH から ps を外したレーンで acquire が非ゼロ終了することを検証する。
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

# 施策 B1: bug-hunt インベントリ drift 解消 + CI 配線

### 変更箇所

- `.claude/skills/app-bug-hunt/screens.md`（3 route 追記 + 説明節）
- `.claude/skills/app-bug-hunt/operations.md`（5 route 追記 + パスキー認可契約の節）
- `.claude/skills/app-bug-hunt/stories/S1-guest-registration-funnel.md`（パスキーログイン手順）
- `.claude/skills/app-bug-hunt/stories/S6-security-2fa-profile.md`（パスキー登録/削除・初回パスワード設定）
- `.github/workflows/ci.yml`（php job に drift 検知 step を追加）
- `tests/js/architecture/ci-workflow-inventory.test.ts`（**W16** を追加して step を pin）

### 実測（現状の drift）

`bash scripts/bug-hunt-inventory-check.sh` → **exit 3**、未追記は **8 route**
（課題文の 4 本に加え、`passkey.confirm` と options 系 3 本）:

```
== screens (GET×inertia) ==  passkey.confirm-options / passkey.login-options / passkey.registration-options
== operations (非GET×web) ==  passkey.confirm / passkey.destroy / passkey.login / passkey.store / settings.password.store
```

route の実体（`php artisan route:list --json` 実測）:

| method | uri | name | middleware（要点） |
|---|---|---|---|
| GET | `passkeys/confirm/options` | `passkey.confirm-options` | auth + `throttle:passkeys` |
| POST | `passkeys/confirm` | `passkey.confirm` | auth + `throttle:passkeys` |
| GET | `passkeys/login/options` | `passkey.login-options` | guest + `throttle:passkeys` + `NoStore...` |
| POST | `passkeys/login` | `passkey.login` | guest + `throttle:passkeys` |
| GET | `user/passkeys/options` | `passkey.registration-options` | auth + `throttle:passkeys` + `RequireRecentAuth` |
| POST | `user/passkeys` | `passkey.store` | auth + `throttle:passkeys` + `RequireRecentAuth` |
| DELETE | `user/passkeys/{passkey}` | `passkey.destroy` | auth + `throttle:passkeys` + `RequireRecentAuth` + `ensure-login-method` |
| POST | `settings/password` | `settings.password.store` | auth + `throttle:6,1` + verified |

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/js/architecture/ci-workflow-inventory.test.ts`（W16 追加）

### 変更後コード（screens.md）

既存の 3 列書式 `| route (URL) | name | 割当ストーリー |` に合わせて追記する
（アルファベット順の既存並びを維持し、`pricing` の直前に挿入）:

```markdown
| passkeys/confirm/options | passkey.confirm-options | S6 |
| passkeys/login/options | passkey.login-options | S1 |
| user/passkeys/options | passkey.registration-options | S6 |
```

`screens.md` は実体として「GET × web」の表であり、既に `capture.csrf-cookie` / `session.status`
という**非 Inertia の GET** を載せている。ただし現在の見出し（`## 画面一覧`）と
チェックスクリプトのラベル（`screens (GET×inertia)`）は実態とずれているため、
**見出しと冒頭説明を実態に合わせる**（列は増やさない。`coverage/correlate.py` が解析するのは
`operations.md` の 5 列だけで `screens.md` は解析しないので列追加自体は可能だが、
既存 55 行の書き換えを避け、注記で区別する）:

```diff
-## 画面一覧
+## GET × web 一覧 (画面 + 画面に付随する JSON GET)
+
+> 本表は「GET × web セッション面」の一覧であり、**Inertia 画面だけではない**。
+> 以下は画面ではなく**画面に付随する JSON GET**として載せている
+> (bug-hunt は単独で開かず、対応する画面操作の副作用として通過させる):
+> `capture.csrf-cookie` / `session.status` / `passkey.registration-options` /
+> `passkey.login-options` / `passkey.confirm-options`
```

そのうえで、誤解を防ぐ節を追加する:

```markdown
## パスキー options endpoint の扱い (要検出)

`passkey.*-options` の 3 本は**画面ではなく WebAuthn の challenge を返す JSON GET**
(`capture.csrf-cookie` / `session.status` と同じ扱いで表に載せている)。
bug-hunt はこれらを**単独で開くのではなく**、S1/S6 のパスキー操作を UI から実走した
副作用として通過させる。加えて逸脱アイデアとして直叩きを行う:

- `passkey.registration-options` / `passkey.confirm-options` は `RequireRecentAuth` /
  auth の配下。**未ログイン・再認証切れで直叩きしたときに 401/302 で止まり、
  challenge が漏れない**こと。
- `passkey.login-options` は guest 配下。**メールアドレスを列挙できる応答差
  (存在するユーザーと存在しないユーザーで応答が変わる)** が出ないこと (存在オラクル)。
- 3 本とも `throttle:passkeys` 配下。連打時の 429 が**画面上で説明される**こと
  (無反応で詰まないこと。H4)。
```

### 変更後コード（operations.md）

既存の 5 列書式 `| method | route | name | story | 区分 |` に合わせて追記する:

```markdown
| POST | passkeys/confirm | passkey.confirm | S6 | 通常 |
| DELETE | user/passkeys/{passkey} | passkey.destroy | S6 | 通常 |
| POST | passkeys/login | passkey.login | S1 | 通常 |
| POST | user/passkeys | passkey.store | S6 | 通常 |
| POST | settings/password | settings.password.store | S6 | 通常 |
```

加えて、既存の「課金ゲート allowlist と認可」節と同じ粒度で**パスキーの認可契約**を書く
（bug-hunt が「何を破れば finding か」を判断できるようにするため）:

```markdown
## パスキー / ログイン手段の認可・guard 契約 (P106/P107 後、要検出)

正本は `docs/auth-security-mechanisms.md` §5・§6。**認証系は IDOR・詰みが最も出やすい面**
なので、以下の 4 つは必ず破壊を試みる。

- **他人の passkey は 404** (`{passkey}` は `SelfScopedPasskeyBinder` が
  「認証ユーザー所有 + 数値正規化」を担う explicit binder。403 で存在を漏らさない
  = セキュリティ不変条件 2 の実装点)。**他組織・他ユーザーの passkey id を
  `passkey.destroy` に流し込んで 404 以外が返れば finding (Critical)**。
- **唯一のログイン手段は消せない** (`ensure-login-method` middleware)。
  パスキーだけのユーザーが唯一の passkey を削除しようとしたとき、
  **403 で突き放さず「先に別の手段を登録してください」と行き先が示される**こと
  (行き先のない詰みを作らない = H4)。
- **登録・削除は再認証の後ろ** (`RequireRecentAuth`)。再認証が切れた状態で直 POST して
  通ったら finding。再認証を求められたとき、**パスキーしか持たないユーザーが
  `recent-auth.confirm` で詰まない**こと (T107 の `passkeyAvailable` 配線が効いているか)。
- **`throttle:passkeys` / `settings.password.store` の `throttle:6,1`**。
  連打で 429 になったとき**画面上で説明される**こと (無反応にしない)。

`settings.password.store` は **SSO / パスキーのみで登録したユーザーがパスワードを
初めて設定する経路** (T107 で新設)。既存の `user-password.update` (現行パスワード必須) とは
別物なので、**現行パスワードを持たないユーザーが到達できること**、および
**既にパスワードを持つユーザーがこの経路で現行パスワード検証を迂回できないこと**の
両方を見る。
```

### ユーザーストーリーの追加

**S6（`stories/S6-security-2fa-profile.md`）** — 手順 3 と 4 の間に挿入:

```markdown
3-b. **パスキーの登録 → 削除 (T106/T107)**:
   `settings.security` → 「パスキーを追加」→ 再認証 (`RequireRecentAuth`) を求められる →
   `recent-auth.confirm` で通過 → `passkey.registration-options` で challenge 取得 →
   `passkey.store` で登録完了。一覧に登録済みパスキーが出るか。
   - **詰み検証**: 登録直後に**パスワードを削除できない / 唯一の手段を消せない**ことを
     `passkey.destroy` で試す。`ensure-login-method` に弾かれたとき、
     **「先に別のログイン手段を登録してください」という行き先付きの説明**が出るか
     (403 の素っ気ないエラーで終わったら finding = H4)。
   - **IDOR 検証**: 他ユーザーの passkey id を `passkey.destroy` に流し込む →
     **必ず 404** (403 だと「その id は存在する」と漏れる)。
   - 削除成功後、一覧から消えてトーストが 1 つだけ出るか (T026)。

3-c. **パスワード未設定ユーザーの初回パスワード設定 (`settings.password.store`, T107)**:
   SSO / パスキーのみで登録したユーザーで `settings.security` を開く →
   「パスワードを設定」導線が**存在し、押下できる**こと (必須条件未充足で
   disabled にしていないこと = 禁止事項 8)。現行パスワード欄が**要求されない**こと。
   設定後に `login` からパスワードでログインできること。
   - **逸脱**: 既にパスワードを持つユーザーが `settings.password.store` を直 POST して
     現行パスワード検証を迂回できないか (できたら finding = Critical)。
```

「このストーリーで消化する screens / operations」の行にも追記する:

```
- screens: ..., passkey.registration-options, passkey.confirm-options
- operations: ..., passkey.store, passkey.destroy, passkey.confirm, settings.password.store
```

**S1（`stories/S1-guest-registration-funnel.md`）** — 手順 4 の直後に挿入:

```markdown
4-b. **パスキーでのログイン (T106)**:
   S6 でパスキーを登録したユーザーでログアウト → `login` 画面に
   **「パスキーでログイン」導線が出ている**こと → `passkey.login-options` で challenge 取得 →
   `passkey.login` で `dashboard` へ到達できること。
   - **存在オラクル検証**: 存在しないメールアドレスで `passkey.login-options` を叩いたときの
     応答が、存在するユーザーのときと**区別できない**こと (区別できたら finding = High)。
   - **詰み検証**: パスキー非対応ブラウザ / WebAuthn が利用不可の環境で
     「パスキーでログイン」を押したとき、**説明が出て通常ログインに戻れる**こと
     (無反応・白画面なら finding = H4)。
```

同じく消化行に `passkey.login-options` / `passkey.login` を追記する。

### CI 配線（再発防止）

`.github/workflows/ci.yml` の `php` job、`Prepare environment`（`.env` 生成 + `key:generate`）の
**直後**に追加する（`route:list` は APP_KEY を要するが DB は不要なので、Pest より前で fail-fast できる）:

```yaml
      # bug-hunt インベントリ (screens.md / operations.md) と route:list のドリフト検知。
      # T106 (passkey 7 route) / T107 (settings.password.store) で 2 サイクル連続してドリフトし、
      # 「認証系が bug-hunt のカバレッジから丸ごと落ちる」という実害が出た。台帳が正本である以上
      # soft-fail にしない (exit 3 で job を落とす)。判定ロジックは既存スクリプトのままで、
      # PHP 側に再実装しない (自前解析器の重複を増やさない = tech-debt.md §4.4)。
      - name: Bug-hunt inventory drift
        run: bash scripts/bug-hunt-inventory-check.sh
```

`tests/js/architecture/ci-workflow-inventory.test.ts` に **W16** を追加（既存 W1〜W15 の作法に従う）:

```typescript
it("W16: php が bug-hunt インベントリの drift 検知を **実行行として** 持つこと", () => {
    const workflow = loadWorkflow();
    // runScript ではなく runLines を使う: runScript はコメント行も連結するため
    // 「# bug-hunt-inventory-check.sh は将来入れる」というコメントで green になる
    // (既存 W14b/W14c と同じ「実行行だけを見る」方針)。
    const lines = runLines(job(workflow, "php"));
    expect(lines.some((l) => l.includes("scripts/bug-hunt-inventory-check.sh"))).toBe(true);
});
```

`continue-on-error` の不在は既存 W13 が workflow 全体で deny-by-default 済み。

### PHPStan 適合チェック

対象外（Markdown / YAML / TypeScript のみ）。TypeScript は `pnpm typecheck` / `pnpm test` で確認。

### テスト計画

- [x] バグ修正の再現テスト: **W16 を先に追加して現行 ci.yml で赤くなることを確認**してから CI step を足す
- [x] 受入条件: `bash scripts/bug-hunt-inventory-check.sh` → **exit 0**（`echo $?` で直接確認する。パイプすると終了コードが隠れるので注意）
- [x] 既存テストの更新: `ci-workflow-inventory.test.ts`（W1 の job 集合は変更なし）
- [x] `.claude/skills/app-bug-hunt/coverage/correlate.py` が operations.md の**5 列 leading-pipe 書式**に依存しているため、追記行が書式に従っていることを `python3 -m unittest`（coverage/ledger の stdlib テスト）で確認する
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

| リスク | 対処 |
|---|---|
| CI に blocking step を足すことで、route を足すたびに CI が赤くなり開発が止まる | それが deny-by-default の目的（`ScriptsReadmeInventoryTest` の先例と同じ）。逃げ道は既存の `OUT_OF_SCOPE_PREFIXES` で、**理由をコメントに書いて**追加する運用（スクリプト L23-29 に既に規約がある） |
| `php artisan route:list` が CI で失敗する（env 不足） | `Prepare environment` の後ろに置くことで `.env` + `APP_KEY` + passport 鍵が揃った状態にする。DB は不要 |
| options endpoint を screens.md に載せるのは「画面」の定義と食い違う | 既に `capture.csrf-cookie` / `session.status` が同じ扱いで載っている（先例に従う）。誤解防止の節を screens.md に明記する |
| correlate.py の 5 列書式を崩す | 既存行をコピーして値だけ差し替える。stdlib テストで検証 |

---

# 施策 B2: ドキュメント乖離 7 件の是正 + 同期ゲート 2 本

### 変更箇所

| # | 対象 | 内容 |
|---|---|---|
| D1 | `AGENTS.md` §セキュリティ不変条件（L41-57） | T103 の不変条件 2 本を **9/10 として追記** + 番号非対応の注記 |
| D2 | `AGENTS.md` §セキュリティ不変条件（末尾） | **`TRUSTED_PROXIES`（T108）への導線**を 1 行追加 |
| D3 | `README.md` ドキュメント表 | `docs/trusted-proxies-runbook.md` / `docs/auth-security-mechanisms.md` を追加 |
| D4 | `.env.example` L194 | 参照先を `docs/auth-security-mechanisms.md §5` へ訂正 |
| D5 | `docs/architecture.md` L83-85 | `CUSTOM_BINDER` 列挙に `{passkey}` + 同期マーカー |
| D6 | `AGENTS.md` L77-79 / `.claude/skills/app-implement/SKILL.md` L158 | 検証コマンド列に packages 系を補完 |
| D7 | `AGENTS.md`（worktree 節 / 実装規約） | **グローバルテストロックの周知**（待つ・heartbeat・kill 禁止）+ 「4 軸」→ 2 層構造 |
| G1 | **新規** `tests/Architecture/RouteBindingCustomBinderDocSyncTest.php` | D5 の機械強制（双方向） |
| G2 | **新規** `tests/js/architecture/verification-commands-doc-sync.test.ts` | D6 の機械強制（deny-by-default） |

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 新規ゲート 2 本

### D1: AGENTS.md §セキュリティ不変条件

**現状**: AGENTS.md は 1〜8（8 = SSRF 検査経由）。`docs/app-integration-guide.md` §7 は
T103 で **10 項目に拡張**され、8 = 変更系 route の認可 gate / 9 = 層 2 は binding 直後 /
10 = テストなしの実装完了はない、になっている。**AGENTS.md に新設 2 本が無い**。

**採番の扱い（重要）**: AGENTS.md と guide §7 の番号は**元々 1:1 でない**
（AGENTS #6 = PII CipherSweet / guide #6 = 逆シリアライズ）。
`docs/app-integration-guide.md:71` が「§7 不変条件 8」と guide の番号で参照しており、
`database/migrations/2026_06_11_091300_create_stripe_webhook_events_table.php:12` が
「不変条件 7」を参照している（7 は両者一致）。
**AGENTS.md 側を renumber すると既存参照が壊れる**ため、**1〜8 は据え置いて 9/10 を追記**し、
番号が 1:1 でないことを明記する（`grep -rn "不変条件 [0-9]"` で live 参照が上記 2 件だけであることを確認済み）。

既存 1〜8 は 2〜3 行で言い切り、詳細は guide §7 へ委ねる体裁になっている。
**9/10 も同じ密度に揃える**（middleware 名・順序契約の詳細は guide §7 が正本。
AGENTS.md を runbook 化しない）:

```markdown
9. **変更系 route は認可を通る**: POST/PUT/PATCH/DELETE は `Gate::authorize` を通すか、
   exemption inventory へ理由付きで登録する(deny-by-default)。
   **層 2(テナント境界 = 404)は層 3(認可 = 403)より前**(逆にすると存在が漏れる)
   (`ControllerAuthorizationGateTest`)
10. **層 2 は binding の直後・FormRequest より前で閉じる**: binding とテナント境界 404 の間に
    404 以外で短絡する middleware があると **1 bit の存在オラクル**になる。実行順の正本は
    `bootstrap/app.php` の **priority list**(route の宣言順ではない)
    (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
    `TenantBoundaryOrderingTest`)

> **採番の注意**: 本節の番号と `docs/app-integration-guide.md` §7 の番号は **1:1 対応しない**
> (本節 6 = PII / guide 6 = 逆シリアライズ、本節 8 = SSRF / guide 8 = 認可 gate)。
> 相互参照するときは**番号ではなく項目名**で指すこと。
```

### D2/D3: TRUSTED_PROXIES（T108）への導線

**現状の実測**: `docs/trusted-proxies-runbook.md` は実在し内容も十分。参照元は
`bootstrap/app.php:78` / `app/Support/TrustedProxiesConfigValidator.php` / `config/trustedproxy.php` /
`tests/Architecture/TrustedProxiesRunbookTest.php` / `docs/auth-security-mechanisms.md:212,408`。
**しかし `AGENTS.md` からも `README.md` のドキュメント表からも辿れない**
（`README.md` の表は 6 行しかなく、T108 で増えた runbook を含んでいない）。

AGENTS.md §セキュリティ不変条件の末尾（採番注記の後）に運用要件として 1 行:

```markdown
> **運用要件 (T108)**: production は `TRUSTED_PROXIES` の**明示宣言が必須**
> (未宣言 / `*` / `REMOTE_ADDR` / 書式不正は `ProductionEnvGuard` が起動時 fail-fast)。
> `trustProxies(at:'*')` はレート制限を総当りに無効化するため復活させない。
> 実 hop 一覧・CIDR 管理主体・変更手順は `docs/trusted-proxies-runbook.md` が正本。
```

`README.md` のドキュメント表に 2 行追加:

```markdown
| `docs/auth-security-mechanisms.md` | 認証・セッション・パスキー・SSO・信頼境界の仕組みと不変条件 |
| `docs/trusted-proxies-runbook.md` | client IP の信頼境界(`TRUSTED_PROXIES`)の運用契約 |
```

### D4: `.env.example` の dangling 参照

```diff
-#    運用契約は docs/architecture.md §パスキー (WebAuthn)。
+#    運用契約は docs/auth-security-mechanisms.md §5 パスキー (WebAuthn) の「運用上の注意」。
```

（`docs/architecture.md` に「§パスキー (WebAuthn)」というセクションは存在しない。
内容自体は `docs/auth-security-mechanisms.md` に正確に書かれている = リンクだけが誤り）

### D5 + G1: `docs/architecture.md` の CUSTOM_BINDER 列挙と同期ゲート

**現状**: `docs/architecture.md:83-85` は `CUSTOM_BINDER` を `{organization}` の 1 件しか挙げていない。
実装（`app/Http/Routing/RouteBindingTypes.php:134-140`）は **`organization` + `passkey`** の 2 件。
`{passkey}` は「他人の passkey を 404 に倒す」= セキュリティ不変条件 2 の実装点なので、
inventory 表現の陳腐化として無視できない。

**doc 側の変更（同期マーカーで囲む）**:

```markdown
- **5 分類 (deny-by-default)**: `BIGINT` / `UUID` (param => モデルの map。pattern 適用) /
  `CUSTOM_BINDER` / `NON_MODEL` / `EXTERNAL` (vendor route が持ち込む param を
  route identity ごとに登録)。
  `CUSTOM_BINDER` の現在の登録は以下 (`RouteBindingCustomBinderDocSyncTest` が
  `RouteBindingTypes::CUSTOM_BINDER` と双方向で同期を強制する):
  <!-- CUSTOM_BINDER:BEGIN -->
  - `{organization}` — `MembershipScopedOrganizationBinder`。`{organization:slug}` 併用のため
    pattern を適用せず、binder が入力正規化を担う
  - `{passkey}` — `SelfScopedPasskeyBinder`。Fortify (vendor) が登録する route の param で、
    app 側から `Route::pattern` を掛けると vendor の route 定義変更に追随できないため、
    binder が「認証ユーザー所有 + 数値正規化」を担う (**他人の passkey は 404** =
    セキュリティ不変条件 2 の実装点)
  <!-- CUSTOM_BINDER:END -->
```

マーカー方式にする理由: 散文全体を grep すると、別の文脈で `{passkey}` に言及しただけで
green になる（形骸化）。**囲まれた範囲だけを解析入力にする**ことで双方向検査が成立する
（`ci-workflow-inventory.test.ts` が「YAML を parse してから歩く」のと同じ考え方）。

**ゲート G1 の設計**:

```php
<?php

declare(strict_types=1);

use App\Http\Routing\RouteBindingTypes;
use Webmozart\Assert\Assert;

/*
 * `RouteBindingTypes::CUSTOM_BINDER` と docs/architecture.md の列挙を **双方向** で同期する。
 *
 * なぜ必要か: T106 が `{passkey}` を CUSTOM_BINDER へ足したとき、docs/architecture.md の
 * 「単一 SoT の全 binding param 型 inventory」を説明する節が 1 件のままドリフトした
 * (docs-freshness.md §2-2)。`{passkey}` は「他人の passkey を 404 に倒す」=
 * セキュリティ不変条件 2 の実装点であり、inventory から落ちている影響は小さくない。
 *
 * 解析対象は <!-- CUSTOM_BINDER:BEGIN --> 〜 <!-- CUSTOM_BINDER:END --> の範囲のみ。
 * 文書全体を grep すると別文脈の言及で green になり形骸化する。
 */

/** 同期マーカー。doc 側を書き換えるときは両方を維持すること。 */
const CUSTOM_BINDER_DOC_BEGIN = '<!-- CUSTOM_BINDER:BEGIN -->';
const CUSTOM_BINDER_DOC_END = '<!-- CUSTOM_BINDER:END -->';

/**
 * マーカー間から `{param}` トークンを抽出する (純関数)。
 *
 * @return list<string> 出現順・重複除去済み
 */
function customBinderDocParams(string $markdown): array { /* ... */ }
```

| ID | 内容 | 期待 |
|---|---|---|
| CB1 | マーカーが `docs/architecture.md` に**ちょうど 1 組**存在する | pass |
| CB2 | **forward**: `CUSTOM_BINDER` の全 key が doc の `{key}` として現れる | pass |
| CB3 | **reverse（stale 検出）**: doc の `{param}` が全て `CUSTOM_BINDER` の key である | pass |
| CB4 | **空振り防止**: 抽出した param 数が 1 件以上 かつ `CUSTOM_BINDER` の件数と一致 | pass |
| CB5 | 正コントロール: key を 1 つ増やした合成入力で forward 違反を検出 | **検出** |
| CB6 | 正コントロール: doc に存在しない `{ghost}` を足した合成入力で reverse 違反を検出 | **検出** |
| CB7 | 負コントロール: マーカー外に `{ghost}` があっても違反にならない | 0 件 |

### D6 + G2: 検証コマンド列の同期

**現状の実測**:
- `AGENTS.md:77-79` は 9 本（`composer test` / `composer phpstan` / `pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
  `pnpm test:packages`）で **`pnpm build:packages` が欠落**
- `.claude/skills/app-implement/SKILL.md:158` は
  `vendor/bin/pint --test && pnpm lint && pnpm typecheck && pnpm test && pnpm build` のままで
  **packages 系 3 本を 1 つも含まない**
- CI（`.github/workflows/ci.yml` frontend job）は `typecheck:packages` → `build:packages` →
  `test:packages` → `build` を実行している = **規約と CI が不一致**

**doc 側の変更（G1 と同じマーカー方式で範囲を限定する）**:

```diff
+<!-- VERIFICATION_COMMANDS:BEGIN -->
 - 検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
-  `pnpm typecheck:packages` / `pnpm test:packages`(全 green でコミット)
+  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
+  (全 green でコミット。`verification-commands-doc-sync.test.ts` が
+  package.json の検証系 script との同期を deny-by-default で強制する)
+<!-- VERIFICATION_COMMANDS:END -->
```

`app-implement/SKILL.md:158` の品質チェック行（同じマーカーで囲む）:

```diff
+<!-- VERIFICATION_COMMANDS:BEGIN -->
    cd {repo_root}/.claude/worktrees/tasks/{todo_id} && vendor/bin/pint --test && pnpm lint && pnpm typecheck && pnpm test && pnpm build
+   cd {repo_root}/.claude/worktrees/tasks/{todo_id} && pnpm typecheck:packages && pnpm build:packages && pnpm test:packages
+<!-- VERIFICATION_COMMANDS:END -->
```

マーカー方式にする理由（G1 と同じ）: 文書全体を検索すると、
**別の文脈で `pnpm build` に言及しただけで green になる**（形骸化）。
新規ゲートを最初から緩く作る理由がない。

**ゲート G2 の設計**（`ScriptsReadmeInventoryTest` と同じ「exempt は理由付き + 逆方向 stale 検出」の作法）:

```typescript
/**
 * package.json の「検証系 script」が AGENTS.md と app-implement/SKILL.md の
 * 検証コマンド列に載っていることを deny-by-default で強制する。
 *
 * なぜ必要か: T104 で CI frontend job が build:packages を回すようになったが、
 * AGENTS.md への追記が漏れ、app-implement/SKILL.md に至っては packages 系 3 本が
 * 1 つも無い状態が続いた (docs-freshness.md §2-3)。手順どおり実装した worktree は
 * packages のビルド破壊をローカルで検出できず CI で初めて赤くなる。
 *
 * 免除は「理由付き」でしか書けない。免除エントリが package.json から消えたら
 * 逆方向検査が落ちる (stale 免除の残置を許さない)。
 *
 * 照合範囲は <!-- VERIFICATION_COMMANDS:BEGIN --> 〜 END の内側のみ。文書全体を検索すると
 * 別文脈の言及で green になり形骸化する (RouteBindingCustomBinderDocSyncTest と同じ方式)。
 */
const MARKER_BEGIN = "<!-- VERIFICATION_COMMANDS:BEGIN -->";
const MARKER_END = "<!-- VERIFICATION_COMMANDS:END -->";

/** 照合対象ファイル (どちらにも同じ script 集合が載っている必要がある)。 */
const TARGETS = ["AGENTS.md", ".claude/skills/app-implement/SKILL.md"];

const EXEMPT: Record<string, string> = {
    dev: "開発サーバ起動。検証コマンドではない",
    "lint:fix": "lint の自動修正。検証は lint 側が担う",
    "test:ui": "vitest UI の対話起動。CI/検証で回すものではない",
    "test:watch": "watch 実行。単発検証ではない",
    "test:coverage": "カバレッジ計測。検証ゲートではない (test が正本)",
    "audit:gate": "supply-chain gate は CI/nightly の blocking 実行が正本 (AGENTS.md §依存脆弱性に別記)",
};
```

| ID | 内容 | 期待 |
|---|---|---|
| V0 | `TARGETS` の各ファイルに `MARKER_BEGIN` / `MARKER_END` が**ちょうど 1 組ずつ**存在する | pass |
| V1 | 非免除 script が **AGENTS.md のマーカー範囲内**に全て現れる | pass |
| V2 | 非免除 script が **app-implement/SKILL.md のマーカー範囲内**に全て現れる | pass |
| V3 | **逆方向**: `EXEMPT` の全 key が package.json の scripts に実在する（stale 免除の検出） | pass |
| V4 | **空振り防止**: 非免除 script 数が **7 件以上**（現状 `build` / `lint` / `typecheck` / `test` / `build:packages` / `typecheck:packages` / `test:packages`） | pass |
| V5 | 免除理由が空文字・10 文字未満でない | pass |
| V6 | 正コントロール: 合成 package.json に未記載 script を足すと違反を検出 | **検出** |
| V7 | 負コントロール: **マーカー範囲外**に `pnpm ghost:script` があっても照合対象にならない | 0 件 |

**照合方法**: script 名の単純な部分一致は `test` が `test:packages` に含まれて誤 green になる。
**`pnpm <name>` / `pnpm run <name>` というトークン境界付きの正規表現**で照合する
（`new RegExp("pnpm (run )?" + escapeRegExp(name) + "(?![:\\w-])")`）。

### D7: グローバルテストロックの周知 + 「4 軸」の更新

**現状**: `docs/testing-browser.md:188-206` の runbook は内容として完璧だが、
**`AGENTS.md` はロックに一言も触れていない**（grep で 0 件）。
`AGENTS.md:77-79` の検証コマンドを素直に実行したエージェントは
「数分無反応 → ハングと誤認 → 中断 / kill」に倒れうる。ロックは全レーン共通
（`composer test` / `composer test:browser` / `pnpm test` / `pnpm test:packages` / `pnpm test:coverage`）
なのに、周知先がブラウザ専用ドキュメントに閉じている。

AGENTS.md §worktree 運用ルールの末尾に追加:

```markdown
- **テストレーンのグローバルロック (T099)**: `composer test` / `composer test:browser` /
  `pnpm test` / `pnpm test:packages` / `pnpm test:coverage` は**ホスト全体で 1 本ずつ**しか
  走らない (worktree 横断で直列化。テスト DB とポートの衝突を構造的に防ぐ)。
  - **待ち時間が出るのは正常**。他レーンが走っていると**エラーにはならず待つ**。
    待機中は **30 秒ごとに heartbeat** が stderr に出るので、出ている間はそのまま待つ
  - **kill しない / ロックファイルを消さない**。中断が必要なら
    **ロック保持者の pid に `kill -TERM`** を送る (プロセスグループが空になるまで解放されない)。
    ロックファイルの手動削除は二重実行を生む
  - 手動復旧の runbook は `docs/testing-browser.md` §グローバルテストロックの手動復旧
```

同時に `AGENTS.md:154` の要約を更新する:

```diff
-- **背景と障害対応**: 分離設計 (vendor / node_modules / テスト DB / 実行時ファイルの 4 軸) の
-  意図は `docs/worktree-isolation-strategy.md`
+- **背景と障害対応**: 分離設計は「**リソース名前空間** (vendor / node_modules / テスト DB /
+  実行時ファイル) と **実行そのもの** (グローバルテストロック)」の 2 層構造。意図は
+  `docs/worktree-isolation-strategy.md`
```

（`docs/worktree-isolation-strategy.md:36-48` は既に 2 層構造へ拡張済みで、AGENTS.md の要約が 1 段古い）

### PHPStan 適合チェック（G1）

- [x] 戻り値の型が明示されている（`list<string>`）
- [x] null 安全（`file_get_contents` の `false` を `Assert::string()` で潰す。`preg_match_all` の戻りも検証）
- [x] DTO を返している（Architecture テストの純関数なので `list<string>` で表現）
- [x] Generics の型パラメータが正しい（`RouteBindingTypes::CUSTOM_BINDER` は `array<string, class-string>`）

### テスト計画

- [x] バグ修正の再現テスト: **G1 の CB2 / G2 の V1・V2 を先に書き、現行の doc で赤くなることを確認**してから doc を直す（テストファースト）
- [x] 新規テスト: `RouteBindingCustomBinderDocSyncTest`（CB1〜CB7）/ `verification-commands-doc-sync.test.ts`（V0〜V7）
- [x] 既存テストの更新: なし（AGENTS.md / README.md / .env.example の変更を検査する既存ゲートは無い）
- [x] `docs/` 変更後に `composer test` / `pnpm test` 全緑
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

| リスク | 対処 |
|---|---|
| AGENTS.md に不変条件を 2 本足すことで、`app-codex-review` スキルが挿入するプロンプトが長くなる | 使命・禁止事項セクションのみを挿入する仕様なので、§セキュリティ不変条件の追記はプロンプト長に影響しない |
| G2 の照合が「AGENTS.md のどこかに `pnpm build` があれば通る」ため形骸化しうる | **マーカー範囲に限定**する（V0 / V7）。加えて V4 の下限ガードと V6 の正コントロールで検出力を担保する |
| doc の同期マーカーが将来の doc 整形で消える | CB1 が「マーカーがちょうど 1 組存在する」ことを検査するので、消えたら即赤くなる |
| 「不変条件 9/10」の追記で番号が guide とずれたままになる | 採番の注記を明記し、相互参照は項目名で行う規約にする（renumber は既存参照 2 件を壊すため選ばない） |

---

# 施策 C1: `doc/reference/` の NFC/NFD 重複解消 + 再発防止ゲート

### 変更箇所

- git index（`doc/reference/` の NFD 形 entry **58 件**を除去）
- `.git/config` の `core.precomposeunicode`（ローカル設定。**リポジトリ恒久対策ではない**）
- `docs/worktree-isolation-strategy.md`（背景と再発防止の記述）
- **新規** `tests/Architecture/GitIndexNormalizationTest.php`

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 新規ゲート 1 本
- **作業ツリーのファイル**: **0 件**（`git rm --cached` は index のみを操作する）
- **コードからの参照**: `doc/reference/sample-sop/`（`tests/Unit/Manual/SopTextExtractorTest.php:38` が
  `base_path("doc/reference/sample-sop/{$name}")` で参照）は **NFD entry に 1 件も含まれない**
  （NFD の内訳は `mockups` 57 / `scenarios` 1）

### 現状（実測 2026-08-05 18:13 JST / main = `c490de0`）

| 指標 | 値 | 算出方法 |
|---|---|---|
| index entry 総数 | **197** | `git ls-files doc/reference \| wc -l` |
| 作業ツリーの実体 | **139** | `find doc/reference -type f \| wc -l` |
| NFC 正規化衝突グループ | **58**（全て size 2） | `git ls-files -z` を `unicodedata.normalize('NFC', p)` でグルーピング |
| blob が異なるグループ | **0 件** | 各グループ内の blob hash 集合サイズ |
| NFC 形 entry を持たないグループ | **0 件** | グループ key（NFC path）が entry として実在するか |
| 197 − 58 | **139** | 実体数と一致 |
| `core.precomposeunicode` | **false** | `git config --get core.precomposeunicode` |

> **注意**: 監査レポートの「重複 blob **55**」は**別指標**である
> （index 内で 2 回以上出現する distinct blob hash 数。出現内訳は 2 回×42 / 3 回×3 / 4 回×7 / 6 回×3 で、
> NFC/NFD 由来でない「同一内容・別名」も数える。distinct blob 総数は 113）。
> **本施策の判定に使うのは 58 の側**であり、両者は矛盾しない。

### 安全性の根拠（概念設計 Round 2 で訂正した論拠）

**「`git rm --cached` は作業ツリーを触らないから安全」は根拠として不十分**である
（index から落とした entry はコミット後、他環境の checkout からは消えるため）。

正しい根拠は「**落とす NFD entry の内容が、残す NFC entry に同一 blob で保存されていること**」。
これを上表の 4 条件で確認済みであり、**実装時にも事前確認として再検証し、
1 つでも崩れたら中止する**。

`git rm --cached` が作業ツリーを壊さないことは、**作業中断時の回復を容易にする副次的性質**として扱う。

### 手順（C1 → C2 の 2 段階）

**前提**: AGENTS.md §worktree 運用ルールに従い、**task worktree の branch/index で完結させる**
（main 直接実装はしない）。worktree 上で完結できないことが実証された場合は、
通常の contingency ではなく**人間の明示的な例外承認を要する停止条件**として扱う。

#### C1（検証 + manifest 生成。index を一切変更しない）

```bash
# 0) 実行前の記録 (ロールバックの基点)
git rev-parse HEAD                                  # → BASE_SHA を控える
git status --porcelain=v1 -uall                     # → 空であること
git worktree list                                   # → 想定どおりの worktree のみ
git config --get core.precomposeunicode             # → 現在値を控える (false)
git ls-files -s doc/reference > devnotes/{dir}/index-before.txt   # index のフルバックアップ

# 1) 事前確認 4 条件 (1 つでも崩れたら中止)
#    - index entry 総数 197
#    - NFC 正規化衝突グループ 58 / 全て size 2
#    - blob が異なるグループ 0 件
#    - NFC 形 entry を持たないグループ 0 件
#    - 197 - 58 == 139 == find doc/reference -type f | wc -l
#    (nfd-index-entries.txt の manifest と一致することも確認する)

# 2) 削除対象 manifest の再生成 (devnotes/{dir}/nfd-index-entries.txt と一致すること)
#    NUL 区切りの pathspec ファイルも生成する (日本語 + 結合文字の path を安全に渡すため)
```

#### C2（適用）

```bash
# 3) index から NFD entry のみを除去する。--cached なので作業ツリーは触らない。
git rm --cached --quiet --pathspec-from-file=<NUL 区切りファイル> --pathspec-file-nul

# 4) 直後の検証 (すべて満たすこと)
git ls-files doc/reference | wc -l          # → 139
find doc/reference -type f | wc -l          # → 139 (作業ツリーの実体は 1 つも消えていない)
git status --porcelain=v1 -uall
#   機械判定 (3 条件すべてを満たすこと):
#     (a) '^D ' で始まる行 (staged deletion / 列 2 = 空白) が **ちょうど 58 件**
#     (b) **列 2 が空白でない行が 0 件** (unstaged 変更が無い = 作業ツリーを触っていない)
#     (c) '^\?\?' の行が **0 件** (untracked が生まれていない)
git ls-files -s doc/reference | awk '{print $2}' | sort > after-blobs.txt
#   → NFC 側 entry の blob 集合が施策前と一致すること (内容が失われていない証明)

# 5) 正規化衝突が 0 になったことを確認 (新設ゲートと同じ判定)
#    → NFC 正規化衝突グループ 0 件

# 6) コミット (ゲート追加と同一コミットにする = 「直したが検査は無い」状態を作らない)
```

**任意の補助手順（受入条件にもロールバックにも含めない）**:

```bash
git config core.precomposeunicode true
```

`core.precomposeunicode` は **`.git/config` のローカル設定**であり、
clone した各人が設定しない限り効かない = **リポジトリの恒久対策にはならない**。
実装者の環境差を受入条件に持ち込まないよう、受入条件（V-C1〜V-C7）とロールバック（R1〜R4）から
**外す**。恒久対策は index 正規化 + `GitIndexNormalizationTest` に限定する
（`docs/worktree-isolation-strategy.md` には「各自 `true` にしておくと再発を緩和できる」と
補助情報として書く）。

#### 検証（受入条件）

| # | 条件 | 確認方法 |
|---|---|---|
| V-C1 | index entry = **139** | `git ls-files doc/reference \| wc -l` |
| V-C2 | 作業ツリー実体 = **139**（**減っていない**） | `find doc/reference -type f \| wc -l` |
| V-C3 | NFC 正規化衝突 = **0** | `GitIndexNormalizationTest` |
| V-C4 | **NFC 正規化した path → blob の map が施策前後で完全一致** | `index-before.txt`（197 entry）を NFC 正規化すると **139 個の key** になり、施策後の 139 entry と **1:1 で一致する**こと。※blob **集合**の比較では不十分（同一 blob が複数 path にあるため path 消失を検出できない。実測: 2 回×42 / 3 回×3 / 4 回×7 / 6 回×3） |
| V-C5 | コミット**前**は `^D ` が 58 件 / 列 2 が非空白の行 0 件 / `^??` 0 件。コミット**後**は `git status --porcelain=v1 -uall` が**空** | 同左 |
| V-C6 | **worktree ラウンドトリップ**: `setup-worktree.sh` → 何もせず `teardown-worktree.sh` が **成功する**（dirty チェックを通る） | 実走。これが施策 C2 の前提条件でもある |
| V-C7 | `tests/Unit/Manual/SopTextExtractorTest.php` が green（`sample-sop` の参照が生きている） | `composer test` |

#### ロールバック方法

| 段階 | 状況 | ロールバック手順 |
|---|---|---|
| **R1** | C2 の手順 3〜5 の途中（**未コミット**） | `git reset HEAD -- doc/reference` で index を復元（**`--hard` ではない。作業ツリーに触れない**）→ `git status --porcelain=v1 -uall` が空になることを確認。**作業ツリーのファイルは一度も触っていないので復元不要** |
| **R2** | コミット済み・**未マージ**（task branch 上） | **`git revert <commit>`**（履歴を残す非破壊のロールバック）を原則とする。直前コミットのみを取り消す場合は `git reset --soft HEAD^`（**作業ツリーに触れない**）。**`git reset --hard` は使わない** — task branch 上でも未コミットの作業を消しうるため、必要な場合は**人間の明示承認を得てから**実行する |
| **R3** | main へマージ済み | `git revert <merge-commit> -m 1` で index entry が復活する（blob は object DB に残っているため内容も完全に戻る） |
| **R4** | 最終手段（index が壊れた） | `git update-index --index-info < devnotes/{dir}/index-before.txt` で **手順 0 で保存した index を丸ごと再構成**する（作業ツリーは触らない） |

> **ロールバック手順に破壊的コマンドを既定で置かない**: ロールバックは*復元*操作であり、
> それ自体が新しい損失を生んではならない。`--hard` 系は既定手順から外し、
> 人間の明示承認を要する例外に限定する。

**ロールバックが安全である理由**: 本施策は blob を 1 つも削除しない（entry の付け替えのみ）。
`index-before.txt` が全 entry の `<mode> <blob> <stage>\t<path>` を保持しているので、
最悪でも手順 0 時点の index を機械的に再構成できる。

### 新規ゲート `GitIndexNormalizationTest` の設計

```php
<?php

declare(strict_types=1);

use Webmozart\Assert\Assert;

/*
 * git index に **NFC 正規化で衝突する path** が無いことを deny-by-default で固定する。
 *
 * なぜ必要か: doc/reference/ に NFD 形と NFC 形の entry が両方載っており
 * (index 197 に対し実体 139)、正規化非依存 lookup の FS では 1 ファイルに潰れる。
 * worktree では「削除済み扱いの NFD entry + untracked 扱いの NFC ファイル」が現れて
 * teardown-worktree.sh の dirty チェックを**常に fail** させ、
 * `git worktree remove --force` による迂回 → drop-test-db.php を通らない →
 * **孤児テスト DB が単調増加**する、という運用事故の起点になっていた
 * (tech-debt.md §5-3 / §5-4)。
 *
 * `core.precomposeunicode` は**ローカル設定**であってリポジトリの恒久対策にならない
 * (clone した各人が設定しない限り効かない)。恒久対策は本ゲートである。
 *
 * 本テストは DB を触らない (git index の読み取りのみ)。
 */

/**
 * NFC 正規化して衝突する path のグループを返す (純関数)。
 *
 * @param  list<string>  $paths  index 上の path 一覧
 * @return array<string, list<string>>  NFC path => 衝突している元 path 群 (2 件以上のみ)
 */
function gitIndexNormalizationCollisions(array $paths): array
{
    $byNfc = [];
    foreach ($paths as $path) {
        $nfc = Normalizer::normalize($path, Normalizer::FORM_C);
        Assert::string($nfc, "path を NFC 正規化できない: {$path}");
        $byNfc[$nfc][] = $path;
    }

    return array_filter($byNfc, static fn (array $g): bool => count($g) > 1);
}
```

| ID | 内容 | 期待 |
|---|---|---|
| N1 | git index 全体に NFC 正規化衝突が **0 件** | pass |
| N2 | **空振り防止**: 読み取った index entry 数が **50 件以上** かつ **代表 path（`AGENTS.md` / `composer.json`）が含まれる**（規模連動の高い閾値は将来の偽赤になるため、代表 path の存在検査を主にする） | pass |
| N3 | `git ls-files -z` の実行が**成功する**（失敗したら skip せず **fail**。偽グリーン禁止） | pass |
| N4 | 正コントロール: NFD/NFC ペアを含む合成 path 配列で衝突を検出 | **1 グループ検出** |
| N5 | 正コントロール: 3 件衝突（NFC + NFD 2 種）を検出 | **1 グループ / 3 要素** |
| N6 | 負コントロール: 純 NFC のみの配列 | 0 件 |
| N7 | 負コントロール: 日本語を含むが正規化しても衝突しない path 群 | 0 件 |

**intl 依存**: `Normalizer` クラスの可用性は確認済み
（`php -r 'var_dump(extension_loaded("intl"), class_exists("Normalizer"));'` → `true` / `true`）。
CI の `shivammathur/setup-php` は intl を既定で含むが、**N3 と同じ思想で
「`Normalizer` が無ければ skip ではなく fail」**とする（偽グリーンを作らない）。

**`git` 実行方法**: `exec('git ls-files -z', $out, $rc)` ではなく、
NUL 区切りを正しく扱うため `shell_exec` ではなく **`proc_open` / `Symfony\Component\Process`**
（Laravel 同梱）を使い、`getOutput()` を `explode("\0", ...)` する。
`$rc !== 0` なら `Assert::same(0, $rc, ...)` で明示的に fail させる。

### docs の追記（`docs/worktree-isolation-strategy.md`）

teardown が常時失敗していた経路と、その恒久対策（本ゲート）を短く記録する
（「なぜこのゲートがあるか」を散文で辿れるようにする。docs-freshness.md §6 の作法）。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`array<string, list<string>>`）
- [x] null 安全（`Normalizer::normalize()` は失敗時 `false` を返すため `Assert::string()` で潰す）
- [x] DTO を返している（Architecture テストの純関数なので配列 shape で表現）
- [x] Generics の型パラメータが正しい

### テスト計画

- [x] バグ修正の再現テスト: **N1 を先に書き、現行 index（衝突 58 件）で赤くなることを確認**してから index を直す（テストファースト）
- [x] 新規テスト: `GitIndexNormalizationTest`（N1〜N7）
- [x] 既存テストの更新: なし
- [x] 実運用の受入テスト: **V-C6（setup → teardown のラウンドトリップ成功）**
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

| リスク | 対処 |
|---|---|
| 正規化に敏感な FS（素の ext4 等）では NFD/NFC が**別ファイル**になるため、他環境の clone で 58 ファイルが減る | 減るのは**重複していた同一内容のコピー**だけ（blob 一致 58/58 を実測済み）。参照しているコード・テストは 0 件（`sample-sop` は対象外） |
| `git rm --cached` の pathspec が結合文字を含み、シェル経由で壊れる | **`--pathspec-from-file` + `--pathspec-file-nul`** で NUL 区切りファイルから渡す（シェルの引数展開を通さない） |
| worktree 作成時点で phantom deletion が出て作業できない | 事前確認で「worktree の dirty 集合が想定 NFD 集合と一致すること」を検査する。一致しなければ**中止して人間へ報告**（勝手に main へ逃げない） |
| ゲートが他の（正当な）NFD path を将来ブロックする | それが目的（deny-by-default）。正当な理由で NFD が必要になったら、そのとき例外機構を設計する（今は必要ない = 思考原則 2） |
| `Normalizer` 不在環境で fail する | intl の可用性を確認済み。CI も含む。skip にすると偽グリーンになるので fail を選ぶ |

---

# 施策 C2: 孤児テスト DB の回収経路

### 変更箇所

- `scripts/ci/pgsql_test_conn.php`（`pgsqlCommentDatabaseSql()` を追加）
- `scripts/ci/ensure-test-db.php`（CREATE 直後に `COMMENT ON DATABASE` を実行）
- `scripts/ci/drop-test-db.php`（`--orphans` / `--apply` / `--confirm` / `--protect-hash` / `--include-hash`）
- **新規** `tests/Support/Ci/TestDatabaseCandidate.php` / `TestDatabaseClassification.php` / `TestDatabaseDecision.php`
- `tests/Support/Ci/TestDatabaseEnv.php`（denylist に `bug_hunt*` を追加 + 分類の純関数）
- `scripts/teardown-worktree.sh`（dirty 失敗メッセージに回収導線を追記）
- `scripts/README.md`（`drop-test-db.php` の行を更新。`ScriptsReadmeInventoryTest` が整合を強制）
- `AGENTS.md` §worktree 運用ルール（強制撤去したときの回収手順 + `--apply` の運用条件）
- **新規** `tests/Unit/Ci/TestDatabaseClassificationTest.php`

### 現状（実測 2026-08-05 18:13 JST）

`git worktree list` は `/workspace` のみ（hash `8af22c44`）。`pg_database` の実測:

| DB 群 | 個数 | 判定 |
|---|---|---|
| `app` | 1 | **dev DB（絶対に触らない）** |
| `app_test_8af22c44` + `_test_1..4` | 5 | **生存**（`/workspace`） |
| `app_test_3a7d6b4e` + `_test_1..4` | 5 | 孤児 |
| `app_test_823cbbd2` + `_test_1..4` | 5 | 孤児 |
| `app_test_b4f0102e` + `_test_1..4` | 5 | 孤児（**今サイクルで新規発生**） |
| `app_test_018d63c6` | 1 | 孤児 |
| `app_test_91c7197b` | 1 | 孤児 |
| **孤児 合計** | **17** | **221.9 MB** |

### 根本原因（コードで確認）

`scripts/teardown-worktree.sh` の実行順は
**(2) dirty チェック（L69-81、失敗で `exit 1`） → (4) DB 回収（L95-99） → (5) worktree 削除**。
`doc/reference/` の NFC/NFD 重複（施策 C1）により dirty チェックが**必ず fail** するため、
**(4) に到達しない**。開発者は `git worktree remove --force` で迂回し、
`drop-test-db.php` を通らないまま worktree が消える → **孤児 DB が残る**。

さらに `drop-test-db.php` は base 名を `TestDatabaseEnv::pgsqlBaseDatabase($projectRoot)`
= **projectRoot の realpath から算出**するため、**worktree が既に消えている孤児には使えない**
（projectRoot が存在しないので hash を再現できない）。これが「掃除コマンドが無い」理由。

### 設計方針（生 DDL を新設しないこと）

- **DROP の実行責務を既存ファイルから分散させない**: DROP DDL を実行するのは
  `scripts/ci/drop-test-db.php` の 1 本のままとし、`--orphans` は
  「**どの DB を落とすかを決める入口**」を足すだけ。DROP の実装（`pgsqlDropDatabaseSql()` +
  `isDevDatabase()` / `isAllowedTestDatabase()` の再検証ループ）は既存コードを共有する
- 追加する DDL は `ensure-test-db.php` から実行する**非破壊の `COMMENT ON DATABASE` のみ**
- 孤児の列挙は **SELECT のみ**（`pg_database` + `shobj_description`）

### 出自の機械記録（provenance）

DB 名の hash だけでは「削除済み worktree の残骸」と「**同一 PostgreSQL を共有する
別クローンの生存 DB**」を区別できない。「由来不明は除外」を素直に適用すると
**現存 17 個はすべて由来不明なので 1 つも掃除できず施策が無意味になる**。
したがって**区別できるようにする** — PostgreSQL 標準の `COMMENT ON DATABASE` を使う。

```php
// scripts/ci/pgsql_test_conn.php に追加
/**
 * allowlist 検証済み DB 名に、出自 (worktree の realpath) を記録する COMMENT 文を生成する。
 *
 * 孤児 sweep が「削除済み worktree の残骸」と「別クローンの生存 DB」を区別するための
 * **分類材料**。信頼境界ではない (誰でも書き換えられるため単独では guard にならない)。
 * 識別子は pgsqlQuoteIdentifier、リテラルは呼び出し側で PDO::quote する。
 */
function pgsqlCommentDatabaseSql(PDO $pdo, string $name, string $provenance): string
{
    return 'COMMENT ON DATABASE '.pgsqlQuoteIdentifier($name).' IS '.$pdo->quote($provenance);
}
```

**ラベル付与は冪等にする（作成時だけでなく既存時も更新する）**。
現行の `ensure-test-db.php` は base が既にあれば `exit 0` する（L40-43）ため、
**そのままだと既に存在する生存 base DB に provenance が付かない**。
ラベルの無い現役 DB を作らないよう、両経路でラベルを付け直す:

```php
// scripts/ci/ensure-test-db.php
// 出自を記録/更新する (非破壊)。孤児 sweep の**分類材料**であり guard ではない。
// 既存 DB でも必ず通す (ここを通さないと「ラベルの無い現役 DB」が生まれる)。
$exists = $stmt->fetchColumn() !== false;
$provenance = (string) realpath($projectRoot);

// plan は純関数 (PDO に触れない) なので両分岐を単体テストできる。
foreach (testDatabaseEnsurePlan($exists, $base, $provenance) as $sql) {
    if (str_starts_with($sql, 'COMMENT ')) {
        // best-effort: ラベルは分類材料であって必須ではない。ここで落とすと
        // 権限設定の差でテスト実行そのものが止まり、偽赤を増やす。
        pgsqlStampProvenance(static fn (string $s): mixed => $pdo->exec($s), $sql);

        continue;
    }
    $pdo->exec($sql);
}
fwrite(STDERR, $exists
    ? "ensure-test-db: base DB already exists: {$base}\n"
    : "ensure-test-db: created base DB: {$base}\n");
exit(0);
```

**PDO 境界を注入可能にする**（design-review R2 [Warning]。「両分岐が実際に stamp を呼ぶ」
「例外時に exit 0 で続行する」を SQL 文字列の検証ではなく**実行で**証明するため）:

```php
// scripts/ci/pgsql_test_conn.php

/**
 * ensure が実行すべき SQL 列を返す (純関数。PDO に触れない)。
 *
 * **両分岐とも COMMENT を含む**のが契約: 既存 DB のとき COMMENT を省くと
 * 「ラベルの無い現役 DB」が生まれ、将来の孤児 sweep の分類材料が欠ける。
 *
 * @return list<string>
 */
function testDatabaseEnsurePlan(bool $exists, string $base, string $provenance): array;

/**
 * provenance ラベルを best-effort で実行する。`$exec` を注入するので PDO 無しでテストできる。
 *
 * @param  callable(string): mixed  $exec
 * @return bool  成功したか (失敗時は false + stderr へ warning。例外は伝播させない)
 */
function pgsqlStampProvenance(callable $exec, string $sql): bool;
```

> **comment は base DB にのみ付く**。paratest の worker DB（`_test_N`）は Laravel の
> `ParallelTesting` が作るため comment を持たない。**hash グループ全体の出自を base の
> comment で代表させる**。base が不在で worker だけが残っている場合は **unlabeled** になる。

**ラベル付与失敗を fail-closed にしない理由**（および、それでも安全である理由）:
「COMMENT 失敗時に作成した base DB を DROP して失敗させる」案は、
**`ensure-test-db.php` に DROP DDL を持ち込む**ことになり、本設計の中核方針
（DROP の実行責務を `drop-test-db.php` から分散させない）を壊す。
かつテスト前処理が権限差で落ちるようになり、**偽赤を増やす**。
危険の本体は「ラベルが無いこと」ではなく
「**ラベルの無い DB がフラグ 1 つで一括 DROP されうること**」なので、
そちらを次項の `--include-hash` で構造的に潰す。

### 分類の型（PHPStan level 10 前提）

```php
namespace Tests\Support\Ci;

/** 孤児判定の入力 1 件 (境界で検証済みの値だけを持つ)。 */
final readonly class TestDatabaseCandidate
{
    public function __construct(
        public string $name,             // 実 DB 名 (allowlist 検証済み)
        public string $hash,             // 8 桁 worktree hash
        public bool $isWorker,           // `_test_N` サフィックスを持つか
        public ?string $provenancePath,  // COMMENT ON DATABASE の値 (base のみ / 無ければ null)
    ) {}
}

enum TestDatabaseClassification: string
{
    case Protected = 'protected';   // --protect-hash で明示保護
    case Live = 'live';             // 生存 worktree hash
    case Foreign = 'foreign';       // ラベルあり / その path が実在 = 別クローンが生きている
    case Orphan = 'orphan';         // ラベルあり / その path が実在しない
    case Unlabeled = 'unlabeled';   // ラベルなし (legacy / worker のみ)
}

/** 分類結果 1 件。理由は必ず具体文字列で持つ (dry-run 出力の説明責任)。 */
final readonly class TestDatabaseDecision
{
    public function __construct(
        public TestDatabaseCandidate $candidate,
        public TestDatabaseClassification $classification,
        public string $reason,
        public bool $shouldDrop,
    ) {}
}
```

### 分類優先順位（**概念設計 Round 5 の承認条件 1**）

**同一候補が複数条件を満たす場合も結果が一意になるよう、以下の順に評価して最初に一致した分類で確定する**:

```
1. Protected  — hash が --protect-hash に含まれる          → shouldDrop = false (常に保護)
2. Live       — hash が生存 worktree hash 集合に含まれる    → shouldDrop = false (常に保護)
3. Foreign    — hash グループの provenancePath が実在する   → shouldDrop = false (常に保護)
4. Orphan     — hash グループの provenancePath が実在しない → shouldDrop = (hash ∈ --include-hash)
5. Unlabeled  — hash グループに provenance が無い          → shouldDrop = (hash ∈ --include-hash)
```

**中核原則: 分類は「説明」のために行い、削除可否を分類だけで自動決定しない。**
`--include-hash=<hash>`（複数指定可）で**人間が 1 つずつ名指しした hash 以外は 1 件も落ちない**。

- **1 が 2 より先**: 明示保護は生存判定より強い（人間の意思を最優先する）
- **2 が 3 より先**: comment は書き換え可能な分類材料にすぎず、**生存 worktree の突合が優先**する
  （**comment を細工しても生存 DB は落とせない**。T-C2-5 が固定する）
- **3 が 4 より先**: path が実在する = 誰かが使っている可能性がある側へ倒す（fail-safe）
- **4・5 は「現在のクローンから生存を否定できない」群**なので、**どちらも明示指定制**にする
- **`Protected` / `Live` は `--include-hash` に指定されても DROP しない**（保護が優先。T-C2-21）
- **worker DB は base と同じ hash グループの分類を継承する**（base の provenance が代表）

**なぜ `Orphan` まで明示指定制にするのか**（design-review R2 [Critical]）:
「ラベルあり / path 不在」は**本当に消えた worktree の残骸**とは限らない。
別クローンの生存 DB について、

- provenance を**存在しないパスへ書き換えられた**
- あるいはその path が**現在のコンテナ / namespace から見えない**（bind mount の差など）

のいずれでも分類は `Orphan` に落ちる。`Orphan → 自動 DROP` にすると
**細工または可視性の差だけで他人の生存 DB が消える**。
「現在のクローンから生存を否定できない hash はすべて人間の名指しを要求する」方が正しい。

**`--include-unlabeled`（一括フラグ）を採らない理由**:
権限不足・一時失敗で provenance が付かなかった**現役 DB**が、フラグの巻き添えで
一括 DROP されうる（design-review R1 [Critical]）。
`--include-hash` なら、**dry-run 出力を人間が読んで hash を転記しない限り 1 件も落ちない**。
現存 17 個（5 hash 群）の初回回収は `--include-hash` を 5 回指定する運用になり、
明示性が上がる（手間は初回 1 回だけ）。

```php
/**
 * 孤児判定 (純関数)。上記の優先順位で評価する。
 *
 * @param  list<TestDatabaseCandidate>  $candidates
 * @param  list<string>  $liveHashes       生存 worktree の hash
 * @param  list<string>  $protectedHashes  --protect-hash
 * @param  list<string>  $includeHashes    --include-hash (unlabeled をこの hash に限り候補化)
 * @return list<TestDatabaseDecision>
 */
function classifyTestDatabases(
    array $candidates,
    array $liveHashes,
    array $protectedHashes,
    array $includeHashes,
): array { /* ... */ }
```

`--protect-hash` / `--include-hash` の値は **`^[0-9a-f]{8}$` を強制**する
（不正なら即エラー。T-C2-16 が固定）。

**境界での正規化（PHPStan level 10 の要件）**: `pg_database` の問い合わせ結果と
`git worktree list --porcelain` の出力はいずれも `mixed` 由来なので、
`TestDatabaseCandidate` を生成する時点で
「`isAllowedTestDatabase()` に一致する」「hash が `[0-9a-f]{8}`」「provenance は string か null」を
検証してから純関数へ渡す。純関数は `mixed` を受け取らない。

### `--orphans` の入出力

```
使い方:
  php scripts/ci/drop-test-db.php                       # 従来どおり (この worktree の DB を回収)
  php scripts/ci/drop-test-db.php --orphans             # dry-run (既定。DROP しない)
  php scripts/ci/drop-test-db.php --orphans --include-hash=3a7d6b4e --include-hash=823cbbd2
  php scripts/ci/drop-test-db.php --orphans --apply --confirm=<token> \
      [--include-hash=<hash> ...] [--protect-hash=<hash> ...]

⚠ --apply は **LLM / エージェントが実行してはならない**。
   ユーザー自身が実行するか、ユーザーが明示的に承認した場合のみ実行できる
   (AGENTS.md 禁止事項 3)。
```

dry-run 出力（例）:

```
== hash 対応表 (人間が cross-clone を判断するための材料) ==
  hash      provenance / live path                 分類
  8af22c44  /workspace (live worktree)             Live
  3a7d6b4e  (ラベルなし)                            Unlabeled
  823cbbd2  (ラベルなし)                            Unlabeled
  b4f0102e  (ラベルなし)                            Unlabeled
  018d63c6  (ラベルなし)                            Unlabeled
  91c7197b  (ラベルなし)                            Unlabeled

== 保護 (--protect-hash) ==
  (なし)

== 所有元を確認できない hash (unlabeled) ==
  3a7d6b4e 823cbbd2 b4f0102e 018d63c6 91c7197b
  → これらは本施策より前に作られた DB か、base が既に消えた worker のみの群です。
     **同一 PostgreSQL を共有する別クローン / 別チェックアウトがある場合、その生存 DB が
     ここに含まれます**。apply する前に、別チェックアウトが無いことを必ず確認してください。
     落とすには --include-hash=<hash> で **1 つずつ明示**してください
     (一括指定のフラグは意図的に用意していません)。

== 分類 ==
  app                       skip     dev DB denylist
  bug_hunt                  skip     dev DB denylist (bug-hunt 環境)
  app_test_8af22c44         keep     Live (生存 worktree /workspace)
  app_test_8af22c44_test_1  keep     Live (生存 worktree /workspace / base の分類を継承)
  ...
  app_test_3a7d6b4e         keep     Unlabeled (--include-hash=3a7d6b4e 指定時のみ DROP)
  ...
  (Orphan 分類も同様に --include-hash 指定時のみ DROP される。
   分類は説明のためのもので、削除可否を分類だけで自動決定しない)

== 集計 ==
  DROP 対象: 0 / 保持: 22 / skip: 2
  (--include-hash を 5 群すべてに指定した場合: DROP 対象 17 / 221.9 MB)

--confirm=<64 桁の SHA-256>
  (token は「DROP 対象 + 生存 hash + 保護 hash + include hash + 分類バージョン」の
   canonical JSON から算出しています。--apply は lock 取得後に同じ入力を再計算して
   token を照合し、一致した場合だけ実行します)
```

**token の定義**:

```
canonical = json_encode([
    'classifier_version' => <分類ロジックのバージョン。規則を変えたら上げる>,
    'orphans'            => <DROP 対象 DB 名 / 昇順>,
    'live_hashes'        => <生存 hash / 昇順>,
    'protected'          => <保護 hash / 昇順>,
    'include_hashes'     => <--include-hash / 昇順>,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
token = hash('sha256', canonical)   // 全長 64 桁。先頭切り詰めをしない
```

- **JSON 配列にする理由**: 区切りなしの連結は `["a_b","c"]` と `["a","b_c"]` を区別できない
- **`include_hashes` を含める理由**: 承認文脈の一部だから
  （「どの unlabeled 群を落とすことを人間が承認したか」が token に焼き込まれる）
- **`classifier_version` を含める理由**: 分類規則を変更したら token が必ず変わり、
  **古い token では apply できない**（規則変更を人間の再承認なしに通過させない）

### apply の運用契約（**概念設計 Round 5 の承認条件 2**）

| # | 契約 | 実装での担保 |
|---|---|---|
| 1 | 既定は **dry-run**。`--apply` 無しでは 1 件も DROP しない | 引数解析の既定値。`--apply` が無い経路に `pdo->exec(DROP)` を通さない |
| 2 | **`--apply` は LLM / エージェントが実行してはならない**。ユーザー自身が実行するか、ユーザーが明示的に承認したときのみ | `--confirm=<token>` 必須（dry-run 出力を**人間が読んで**転記しない限り得られない）。同じ文言を **(a) スクリプトの usage、(b) `AGENTS.md` §worktree 運用ルール、(c) `scripts/README.md`** の 3 箇所に置く |
| 2-b | unlabeled は**一括フラグで落とせない** | `--include-hash=<hash>` で 1 つずつ名指し。`^[0-9a-f]{8}$` を強制 |
| 3 | apply は **lock 取得後に判定入力を再計算**し、`--confirm` と一致するときだけ DROP | `.claude/worktrees/.setup.lock` を token 再計算の直前に取得し、**全 DROP 完了まで保持**する。不一致なら中止して新 token を表示 |
| 4 | 三重 guard | 名前 allowlist regex / dev DB denylist（`app` + `bug_hunt` + `bug_hunt_1..8`）/ 生存 worktree hash 突合 |
| 5 | 排他の適用範囲を誇張しない | この lock が閉じるのは**同一クローンの協調スクリプト（setup / teardown / sweep）間の TOCTOU だけ**。別クローンとは共有されない。cross-clone の防御は **Foreign 分類 + `--protect-hash` + 人間承認**の 3 段 |

> **cross-clone advisory lock を採らない理由（スコープ外）**: `ensure-test-db.php` に
> PostgreSQL advisory lock を入れれば別クローンとも排他できるが、
> **CI と全 worktree のテスト前処理にロック待ちハングの経路を新設する**ことになり、
> 「偽赤を減らす」という本バッチの目的と逆行する。

### `TestDatabaseEnv` の denylist 拡張

```php
/** dev DB 名の hard-deny 対象。bug_hunt* は allowlist regex で既に構造的に除外されるが、
 *  「bug-hunt 環境の DB は絶対に触らない」という意図を明示する二重防御として列挙する。 */
public const DEV_DB_DENYLIST = ['app', 'bug_hunt', 'bug_hunt_1', ..., 'bug_hunt_8'];
```

### `teardown-worktree.sh` の導線追加

```diff
     if [[ -n "${worktree_status}" ]]; then
         echo "error: ${WORKTREE_DIR} に未コミット変更または untracked ファイルがあります" >&2
         echo "${worktree_status}" >&2
         echo "先に commit / stash / clean してください" >&2
         echo "(依存変更 = package.json / pnpm-lock.yaml / composer.json / composer.lock も必ずコミット)" >&2
+        echo "" >&2
+        echo "⚠️  ここで git worktree remove --force を使って強制撤去すると、下の DB 回収 (drop-test-db.php)" >&2
+        echo "    を通らずテスト DB が孤児として残ります。強制撤去した場合は後で必ず回収してください:" >&2
+        echo "      php scripts/ci/drop-test-db.php --orphans          # dry-run で対象を確認" >&2
+        echo "      (実 DROP は --apply --confirm=<token> が必要。LLM は実行しないこと)" >&2
         exit 1
     fi
```

**dirty チェックと DB 回収の順序は入れ替えない**。入れ替えると「teardown が失敗したのに
まだ使っている worktree のテスト DB が消える」という別の事故になる。
真の解決は施策 C1（dirty チェックが常時失敗する原因の除去）であり、
本施策は**迂回されたときの回収経路**を用意する役割に徹する。

### `scripts/README.md` の更新

```diff
-| `ci/drop-test-db.php` | worktree の base テスト DB と paratest worker DB を回収 (dev-DB は無条件 skip) | worktree teardown / CI cleanup |
+| `ci/drop-test-db.php` | worktree の base テスト DB と paratest worker DB を回収 (dev-DB は無条件 skip)。`--orphans` で「生存 worktree に紐づかない孤児 DB」の検出も行う (既定 dry-run。`--apply` は `--confirm=<token>` 必須で **LLM は実行しない** = ユーザー実行またはユーザーの明示承認のみ) | worktree teardown / CI cleanup / 孤児回収 (手動) |
```

（`ScriptsReadmeInventoryTest` が実ファイル ↔ 表の双方向を強制しているため、
**ファイルを増やさない**（`--orphans` をサブコマンド化する）設計は台帳の増加も伴わない）

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`list<TestDatabaseDecision>` / `list<TestDatabaseCandidate>` / `list<string>`）
- [x] null 安全（`realpath()` の `false`、`shobj_description` の `null`、`PDO::fetchAll` の `mixed` を境界で `Assert` する）
- [x] DTO を返している（`TestDatabaseCandidate` / `TestDatabaseDecision` は `final readonly class`。配列返却なし）
- [x] Generics の型パラメータが正しい（`list<...>` を使用。`array<string, list<string>>` は hash グループ用）
- [x] enum を使い、分類を文字列リテラルで持ち回らない

### テスト計画

- [x] バグ修正の再現テスト: **T-C2-1 を先に書き、現行の `TestDatabaseEnv` に分類関数が無い状態で赤くなることを確認**する
- [x] 新規テスト `tests/Unit/Ci/TestDatabaseClassificationTest.php`:

| ID | 内容 | 期待 |
|---|---|---|
| T-C2-1 | live: 生存 hash の base + worker 5 件 | 全て `Live` / `shouldDrop = false` |
| T-C2-2 | orphan: ラベルあり・path 不在 | `Orphan` / `shouldDrop = true` |
| T-C2-3 | foreign: ラベルあり・path 実在 | `Foreign` / `shouldDrop = false` |
| T-C2-4 | **優先順位**: `--protect-hash` 指定 かつ 生存 hash かつ ラベル不在 の候補 | `Protected` で確定（1 が 2・5 に勝つ） |
| T-C2-5 | **優先順位**: 生存 hash かつ ラベルの path 不在（comment 細工） | `Live` で確定（2 が 4 に勝つ = comment で生存 DB を落とせない） |
| T-C2-6 | unlabeled: `--include-hash` 指定なし | `Unlabeled` / `shouldDrop = false` |
| T-C2-7 | unlabeled: 当該 hash を `--include-hash` に指定 | `Unlabeled` / `shouldDrop = true` |
| T-C2-7b | unlabeled: **別の** hash を `--include-hash` に指定（巻き添えが起きない） | `shouldDrop = false` |
| T-C2-8 | **dev DB `app` は候補に入らない**（境界で弾かれる） | 候補生成が `InvalidArgumentException` |
| T-C2-9 | **`bug_hunt` / `bug_hunt_3` は候補に入らない** | 同上 |
| T-C2-10 | allowlist 外（`app_test_XYZ` / `app_test_8af22c44_backup`）は候補に入らない | 同上 |
| T-C2-11 | worker DB は base と同じ分類を継承する | 同左 |
| T-C2-12 | base 不在で worker のみ → `Unlabeled` | 同左 |
| T-C2-13 | token: 同じ入力で同じ token / 1 件でも変われば別 token | 同左 |
| T-C2-14 | token: canonical JSON なので `["a_b","c"]` と `["a","b_c"]` が別 token | 同左 |
| T-C2-15 | 実行順序が違っても（入力順シャッフル）token が同一（昇順ソートの検証） | 同左 |
| T-C2-15b | token: `include_hashes` が変われば別 token / `classifier_version` が変われば別 token | 同左 |
| T-C2-16 | `--protect-hash` / `--include-hash` の形式検証（`ZZZZ` / 7 桁 / 9 桁 / 大文字は拒否） | 例外 |
| T-C2-17 | `testDatabaseEnsurePlan(false, ...)` → `[CREATE, COMMENT]` / `testDatabaseEnsurePlan(true, ...)` → `[COMMENT]`。**両分岐とも COMMENT を含む** | pass |
| T-C2-18 | `pgsqlStampProvenance()` に**例外を投げる `$exec`** を注入 → `false` を返し stderr に warning（例外を伝播させない） | pass |
| T-C2-18b | `pgsqlStampProvenance()` に**成功する `$exec`** を注入 → `true` を返し、渡された SQL が COMMENT 文である | pass |
| T-C2-19 | **細工された foreign**: provenance が不存在 path の候補（= `Orphan` 分類）は `--include-hash` 無しで `shouldDrop = false` | pass |
| T-C2-20 | `Orphan` は当該 hash を `--include-hash` に指定したときだけ `shouldDrop = true` | pass |
| T-C2-21 | `Protected` / `Live` は `--include-hash` に指定されても `shouldDrop = false` | pass |
| T-C2-22 | provenance path が**現在の namespace から見えない**ケース（`is_dir()` が false）も `Orphan` として保護される（指定なしでは落ちない） | pass |

- [x] 既存テストの更新: `TestDatabaseEnv` の denylist 拡張により、既存の
  `assertPgsqlTestDatabaseSafe` / `isDevDatabase` のテストへ `bug_hunt*` ケースを追加
- [x] `ensure-test-db.php` の COMMENT 付与: **実 DB を作らない**単体テストで
  `pgsqlCommentDatabaseSql()` の生成 SQL（識別子クォート / リテラルクォート）を固定する
- [x] 受入テスト（実環境）: **dry-run のみ**を実行し、出力が
  「生存 5 件を keep / 孤児 17 件を Unlabeled として列挙（`--include-hash` なしでは DROP 対象 0）/
  dev DB `app` と `bug_hunt*` を skip」になることを確認する。
  **`--apply` は LLM が実行しない**（ユーザー自身の実行またはユーザーの明示承認が必要）
- [x] 個別の `DatabaseTransactions` を使っていない（`tests/Unit/Ci/` は DB を触らない純粋な単体テスト）

### リスク

| リスク | 対処 |
|---|---|
| **別クローンの生存 DB を落とす** | **`--include-hash` で 1 つずつ名指ししない限り 1 件も落ちない**（`Orphan` / `Unlabeled` の両方が明示指定制）/ Foreign 分類（provenance path 実在）で保持 / `--protect-hash` / dry-run が hash → provenance の対応表と「所有元不明 hash」を明示 / 人間承認必須 |
| **provenance を細工されて他人の生存 DB が `Orphan` に落ちる**（または path が namespace 差で見えない） | `Orphan` も `--include-hash` 必須にすることで自動 DROP の経路を断つ（T-C2-19 / T-C2-22 が固定） |
| **ラベルが付かなかった現役 DB が掃除される** | `ensure-test-db.php` が**作成時・既存時の両方**でラベルを付け直す（冪等）。それでも付かなかった場合も、`--include-hash` が無ければ落ちない（一括フラグを用意しないことで構造的に防ぐ） |
| dev DB を落とす | allowlist regex（`^app_test_[0-9a-f]{8}(_test_[0-9]+)?$`）で `app` は構造的に不一致 + denylist で無条件 skip + 既存の `isDevDatabase()` 再検証ループを通す（DROP 実装は既存コード共有） |
| setup 中の worktree の DB を落とす（TOCTOU） | `.setup.lock` を token 再計算の直前に取得し、**全 DROP 完了まで保持**する |
| `COMMENT ON DATABASE` が権限不足で失敗し、ensure が落ちる | comment は**分類材料であって必須ではない**ため、`try { ... } catch (Throwable) { warning }` で best-effort にする（テスト実行を止めない） |
| comment を書き換えて生存 DB を落とさせる攻撃 | 分類優先順位で **Live（生存 hash 突合）が Foreign/Orphan より先**なので、comment 細工では生存 DB を落とせない（T-C2-5 が固定） |
| `--orphans` の追加で `drop-test-db.php` が複雑化する | 既存の無引数経路は**挙動を一切変えない**（回帰は既存 teardown で確認）。分類ロジックは `tests/Support/Ci/` の純関数へ出し、スクリプト側は入出力に徹する |
| 施策 C1 が終わる前に apply して「シグナルを消す」 | **C1 完了を C4（apply）の前提条件**として TODO の受入条件に書く。実装順は C3（純関数・テスト・dry-run）→ C1 → C2 → C4 を許容する |

---

# 施策 D1: 未受容 advisory 4 件の upgrade

### 変更箇所

- `packages/cli/package.json`（`undici` の解決版を上げる。**caret 範囲内なので宣言変更は不要**）
- `package.json`（`eslint-plugin-better-tailwindcss` の厳密 pin `4.4.1` → `4.7.0`）
- `pnpm-lock.yaml`

### 実測（レジストリ照会で確認済み）

| eco | パッケージ | 現在 | 修正版 | 経路 | 確認 |
|---|---|---|---|---|---|
| npm | `undici` (GHSA-8xcm-r25x-g524 / -m8rv-5g2x-5cg5 / -v3r7-h72x-cjcm) | 6.27.0 | **>= 6.28.0** | `packages/cli` の直接依存 `"undici": "^6.27.0"` | `pnpm view undici versions` に **6.28.0 が存在**。caret 範囲内なので `pnpm update undici` で解決 |
| npm | `valibot` (GHSA-5qjj-4xww-7phc) | 1.4.1 | >= 1.4.2 | `eslint-plugin-better-tailwindcss@4.4.1`（root で厳密 pin）の推移依存 | `pnpm view eslint-plugin-better-tailwindcss@4.7.0 dependencies` → **`valibot: ^1.4.2`** を確認 |

現在の `pnpm run audit:gate` は **PASS（moderate 4 / high 0 / critical 0 / accept-risk 0）**。
`docs/supply-chain/accepted-advisories.yaml` は空（`[]`）= 受容した負債はゼロ。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: なし（既存の `audit:gate` が受入判定）
- **`eslint-plugin-better-tailwindcss` は ESLint ルールの実装**なので、pin を上げると
  **lint の指摘内容が変わりうる**（4.4.1 → 4.7.0 は minor 3 つ分）

### 手順

```bash
# 1) undici (caret 範囲内 = 宣言変更なし)
pnpm -F @app/cli update undici
# 2) eslint plugin の pin 上げ (厳密 pin なので package.json の宣言を書き換える)
#    "eslint-plugin-better-tailwindcss": "4.4.1" → "4.7.0"
pnpm install
# 3) 受入判定
pnpm run audit:gate         # → Total advisories: 0
pnpm lint                   # → 指摘 0 (増えていたら是正)
pnpm test:packages && pnpm build:packages && pnpm typecheck:packages
```

### zod の major 分裂について（**次サイクル送り**）

root `package.json` は `zod: ^4.4.3`（devDependency）、`packages/cli/package.json` は
`zod: ^3.23.0`（runtime dependency）。AGENTS.md 思考原則 3「後方互換の並走を残さない」に
照らすと逸脱に近いが、**本バッチには含めない**:

- 解消には `packages/cli` のスキーマ定義を v3 → v4 へ**移行するコード変更**が必要で、
  「保守」の粒度を超える（本バッチは lockfile 更新に留める）
- `audit:gate` は緑であり、セキュリティ上の緊急性がない
- どのゲートも検出していない = 誰も気づかない負債なので、**TODO 化して追跡する**のが正しい対処

### PHPStan 適合チェック

対象外（npm 依存のみ）。

### テスト計画

- [x] 受入条件: `pnpm run audit:gate` → **Total advisories: 0** / accept-risk 0（`accepted-advisories.yaml` は空のまま）
- [x] 既存テストの更新: なし
- [x] 回帰確認: `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
      `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` が全緑
- [x] `scripts/audit-gate.contract.test.ts` が緑のまま（gate 自体の契約は変えない）
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

| リスク | 対処 |
|---|---|
| `eslint-plugin-better-tailwindcss` 4.4.1 → 4.7.0 で lint 指摘が増え、既存コードが赤くなる | 受入条件に `pnpm lint` 緑を含める。指摘が出たら**コード側を是正**する（ルールを無効化しない）。**分割の判断基準**: 是正が**コード修正 5 ファイルを超える**なら、D1 を `undici` のみに縮小し、plugin の pin 上げは**別 TODO へ分離**する。`pnpm.overrides` による valibot の強制引き上げは**採らない**（supply-chain の追跡が複雑になり、`audit:gate` の判定と実依存関係がずれる） |
| `undici` 6.28.0 で `packages/cli` の HTTP 挙動が変わる | patch/minor 更新であり、`pnpm test:packages`（106 tests）が回帰網。緑を受入条件にする |
| lockfile 変更が他グループと衝突する | **D1 を単独 TODO にする**（下記 実装モード）。lockfile を触るのはこの TODO だけ |

---

## 実装モード

7 施策を **4 つの TODO** に分割する。分割の軸は「主題の一致」と「触るファイルの重なり」。

| TODO | 含む施策 | 推奨モード | 判断根拠 | 競合リスク |
|---|---|---|---|---|
| **A: テストレーン基盤の偽赤除去** | A1 + A2 | **incremental** | 主題が同一（テスト基盤が偽赤を出す）。**両方が `tests/Architecture/GlobalTestLockInventoryTest.php` を触る**ため分けると競合する。変更は 3 文字 × 3 箇所 + 2 行 + 検証ケース 1 本で小さい | 低（他 TODO と重なるファイルなし） |
| **B: 正本 (台帳・規約) のドリフト是正** | B1 + B2 | **incremental** | 主題が同一（LLM エージェントが読む正本が実装に追いついていない）。両方が `AGENTS.md` 系の規約と CI/gate 配線を触る。B1 の W16 と B2 の G2 はいずれも「doc ↔ 機械可読な正本」の同期ゲート | 中（B1 が `ci-workflow-inventory.test.ts`、B2 が `AGENTS.md` を触る。**同一 TODO 内なので競合しない**） |
| **C: 運用事故の根本原因除去** | C1 → C2 | **standalone** | git index 操作と DB DROP という**破壊リスクの質が他と違う**作業を含み、人間の確認点（C4）を持つ。実装セッションを他施策と混ぜない。C1 と C2 は根本原因と回収経路の関係にあり、受入条件が連動する | 高（git index / DB。**単独セッションで実行する**） |
| **D: supply-chain の advisory 解消** | D1 | **incremental** | `pnpm-lock.yaml` 単独の変更。他 TODO と同時に走らせると lockfile が必ず衝突するため、**独立させて短時間で閉じる** | 中（lockfile。他 TODO は lockfile を触らないので、D を先に閉じるのが安全） |

### 推奨実行順

```
D (lockfile を先に閉じる、極小)
  → A (偽赤の除去。以降のテスト実行が安定する)
  → B (正本の是正。CI に blocking step が増える)
  → C (standalone。C3 → C1 → C2 → C4 の順で、C4 の前に人間確認)
```

- **C の内部順序**: 施策 C2 の純関数・テスト・dry-run（C3）は C1 より前でも実装できる。
  **必須なのは、C2 の `--apply`（C4）と「孤児 DB 0 件」の完了判定より前に C1 が完了していること**
  （C1 を直さないまま掃除だけすると、teardown 不全というシグナルを消して再発させるだけになる）
- **C4（`--apply`）は人間の明示指示があるまで実行しない**。TODO の受入条件に明記する

### 全 TODO 共通の受入条件

| 条件 | コマンド |
|---|---|
| PHP テスト全緑 | `composer test`（現状 3014 passed / 0 failed / 2 skipped） |
| PHPStan level 10 | `composer phpstan` |
| Pint | `vendor/bin/pint --test` |
| JS 全レーン | `pnpm lint` / `pnpm typecheck` / `pnpm test`（現状 1202 passed）/ `pnpm build` |
| packages 全レーン | `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` |
| supply-chain | `pnpm run audit:gate` |
| ロック層 1 | `bash scripts/verify-global-test-lock.sh`（skip 数も報告する） |
| bug-hunt 台帳 | `bash scripts/bug-hunt-inventory-check.sh; echo $?` → **0** |


Round 2 の指摘が解消できているかを確認し、各施策の判定 (APPROVE / REQUEST_CHANGES) と
全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
