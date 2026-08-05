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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
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

【本設計に固有の重点確認事項】
- 本バッチはアプリのドメインコードを変更しない (Architecture テスト / 運用スクリプト / 台帳ドキュメント / lockfile のみ)。
  観点 5・6・10・11 は該当有無から評価してよい
- 施策 A1 の PCRE リテラル抽出器 (PhpToken ベース) に見落としがないか。誤検出・見逃しのパターン
- 施策 A2 の `|| pgid=""` 修正と C25 検証ケースが、狙った偽赤を本当に閉じるか
- 施策 C1 の git index 操作手順・受入条件・ロールバック 4 段階に穴がないか
- 施策 C2 の分類優先順位 (Protected → Live → Foreign → Orphan → Unlabeled) が
  「comment を細工しても生存 DB を落とせない」を本当に保証するか。
  confirm token の canonical JSON + lock 下再計算に残る穴はないか。
  AGENTS.md 禁止事項 3 (dev DB への破壊操作) に抵触しないか
- 各施策の「空振り防止 (下限ガード)」の閾値が妥当か (脆すぎ / 緩すぎ)
- 新設ゲート 4 本が形骸化しない設計になっているか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
| P2 | **空振り防止（下限ガード）**: 走査で抽出した PCRE パターンリテラル総数が **100 件以上** | pass |
| P3 | **走査対象ファイル数**が 300 件以上（ディレクトリ指定ミスで 0 件 green を防ぐ） | pass |
| P4 | 正コントロール: `preg_split('/\R/', $x)` を含む合成ソース | **1 件検出** |
| P5 | 正コントロール: `preg_match("/\R/m", $x)`（`u` なし・別修飾子あり） | **1 件検出** |
| P6 | 正コントロール: `'#\R#'`（別デリミタ） | **1 件検出** |
| P7 | 負コントロール: `preg_split('/\R/u', $x)` | 0 件 |
| P8 | 負コントロール: `'#\R#u'` | 0 件 |
| P9 | 負コントロール: `preg_split('/\r\n|\r|\n/', $x)`（`\R` 不使用） | 0 件 |
| P10 | 負コントロール: **コメント内**の `// preg_split('/\R/')` | 0 件（コメントは別トークン） |
| P11 | 負コントロール: 通常文字列 `'\R は改行クラスです'`（デリミタ始まりでない） | 0 件 |

P2/P3 の下限を「`\R` を含むリテラルが N 件以上」にしない理由: 将来 3 箇所すべてが
リファクタで消えたときに**正しい状態が偽赤になる**ため。下限は「抽出器が動いていること」に掛ける。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`list<array{literal: string, body: string, modifiers: string}>` / `list<string>`）
- [x] null 安全（`preg_split` の `false` は `?: []` で潰さず、`Assert::isArray()` で明示する）
- [x] DTO を返している（本ファイルは Architecture テストの純関数なので配列 shape で表現。アプリ層の DTO 規約の対象外）
- [x] Generics の型パラメータが正しい（`list<...>` を使用）

### テスト計画

- [x] バグ修正の再現テストを先に書く: **P4（`/u` 無しを検出する）を先に書き、現行の 3 箇所で赤くなることを確認してから修正する**（思考原則 5 テストファースト）
- [x] 既存テストの更新: `GlobalTestLockInventoryTest` / `BughuntOrchestratorGateInvariantTest` は**挙動が変わる**（解析入力が正しくなる）ため、両テストが green のままであることを確認する
- [x] 新規テスト: `PcreUnicodeModifierGateTest`（P1〜P11）
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

- `main()` の呼び出し列に `case_c25` を **`case_c11`（残党検出）より前**に追加する
  （C11 は最後に走る掃除確認なので、その前に置く既存の並びに従う）
- 20 回反復にするのは、1 回だけだと race が確率的に外れて偽グリーンになりうるため

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
- [x] 既存テストの更新: `scripts/run-browser-test.contract.test.ts`（回避策撤去）
- [x] 既存契約の再検証: `bash scripts/verify-global-test-lock.sh`（C01〜C25 全 pass / skip 数を確認）+ `tests/Architecture/GlobalTestLockInventoryTest.php`（層 2）
- [x] 全レーンの実走: `composer test` / `pnpm test` / `pnpm test:packages`（ロック経由）
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

| リスク | 対処 |
|---|---|
| `\|\| pgid=""` が「ps が本当に異常（権限エラー等）」の場合も握り潰す | 元の設計意図がそもそも best-effort（コメントが明示）。異常検出は `_gtl_probe_process_group` の**取得時 1 回だけの強制検証**が担っており、そちらは 3 回リトライして「値が取れて不一致」なら `_gtl_die` する。責務分担は変わらない |
| C25 が環境依存（`ps` 不在）で常に skip になり偽グリーン | 既存スイートは `HAVE_PS` で skip し、**skip 数を必ず出力する**設計。C25 も同じ扱いにし、skip が出たら報告に記載する |
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

`screens.md` は「GET × web」の表であり、既に `capture.csrf-cookie` / `session.status` という
**非 Inertia の GET** を載せている。同じ扱いで載せたうえで、誤解を防ぐ節を追加する:

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
it("W16: php が bug-hunt インベントリの drift 検知を実行すること", () => {
    const workflow = loadWorkflow();
    expect(runScript(job(workflow, "php"))).toContain("scripts/bug-hunt-inventory-check.sh");
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

```markdown
9. **変更系 route は認可を通る**: POST/PUT/PATCH/DELETE のアプリ所有 route は
   `Gate::authorize` を持つか、`ControllerAuthorizationGateTest` の exemption inventory へ
   `ControllerAuthorizationExemption` enum + 具体的根拠(30 文字以上)付きで登録する
   (deny-by-default)。**層 2(テナント境界 = 404)と層 3(認可 = 403)の順序は不可侵** —
   inline guard は必ず `Gate` より前(逆にすると cross-org が 403 を返し存在が漏れる)
10. **層 2 は binding の直後・FormRequest より前で閉じる**: `SubstituteBindings` は
    **不在 id だけ**を 404 にする。binding とテナント境界 404 の間に 404 以外で短絡する
    middleware が 1 つでもあると「他組織に実在 = その短絡の応答 / 不在 = 404」という
    **1 bit の存在オラクル**になる。実行順の正本は `bootstrap/app.php` の **priority list**
    (route の宣言順ではない)。API の順序契約は `resolve.api-actor` → `SubstituteBindings`
    → `api.project-in-org` → `api-key.ability:*` → `idempotent`
    (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
    `TenantBoundaryOrderingTest` が強制)

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

**doc 側の変更**:

```diff
 - 検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
-  `pnpm typecheck:packages` / `pnpm test:packages`(全 green でコミット)
+  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
+  (全 green でコミット。`verification-commands-doc-sync.test.ts` が
+  package.json の検証系 script との同期を deny-by-default で強制する)
```

`app-implement/SKILL.md:158` の品質チェック行:

```diff
-   cd {repo_root}/.claude/worktrees/tasks/{todo_id} && vendor/bin/pint --test && pnpm lint && pnpm typecheck && pnpm test && pnpm build
+   cd {repo_root}/.claude/worktrees/tasks/{todo_id} && vendor/bin/pint --test && pnpm lint && pnpm typecheck && pnpm test && pnpm build
+   cd {repo_root}/.claude/worktrees/tasks/{todo_id} && pnpm typecheck:packages && pnpm build:packages && pnpm test:packages
```

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
 */
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
| V1 | 非免除 script が **AGENTS.md** の検証コマンド列に全て現れる | pass |
| V2 | 非免除 script が **app-implement/SKILL.md** の品質チェック行に全て現れる | pass |
| V3 | **逆方向**: `EXEMPT` の全 key が package.json の scripts に実在する（stale 免除の検出） | pass |
| V4 | **空振り防止**: 非免除 script 数が **7 件以上**（現状 `build` / `lint` / `typecheck` / `test` / `build:packages` / `typecheck:packages` / `test:packages`） | pass |
| V5 | 免除理由が空文字・10 文字未満でない | pass |
| V6 | 正コントロール: 合成 package.json に未記載 script を足すと違反を検出 | **検出** |

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
- [x] 新規テスト: `RouteBindingCustomBinderDocSyncTest`（CB1〜CB7）/ `verification-commands-doc-sync.test.ts`（V1〜V6）
- [x] 既存テストの更新: なし（AGENTS.md / README.md / .env.example の変更を検査する既存ゲートは無い）
- [x] `docs/` 変更後に `composer test` / `pnpm test` 全緑
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

| リスク | 対処 |
|---|---|
| AGENTS.md に不変条件を 2 本足すことで、`app-codex-review` スキルが挿入するプロンプトが長くなる | 使命・禁止事項セクションのみを挿入する仕様なので、§セキュリティ不変条件の追記はプロンプト長に影響しない |
| G2 の照合が「AGENTS.md のどこかに `pnpm build` があれば通る」ため形骸化しうる | 検証コマンド列の**行範囲を限定せず**、代わりに **V4 の下限ガード**と **V6 の正コントロール**で検出力を担保する。行範囲マーカーまで足すのは D5 と違い過剰（package.json 側が正本で、doc は列挙するだけ） |
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
find doc/reference -type f | wc -l          # → 139
git status --porcelain=v1 -uall             # → 「D」でも「??」でもなく、staged な削除 58 件のみ
#   ※ 作業ツリーの実体は 1 つも消えていないこと (find の 139 が不変であること) を必ず確認する
git ls-files -s doc/reference | awk '{print $2}' | sort > after-blobs.txt
#   → NFC 側 entry の blob 集合が施策前と一致すること (内容が失われていない証明)

# 5) 正規化衝突が 0 になったことを確認 (新設ゲートと同じ判定)
#    → NFC 正規化衝突グループ 0 件

# 6) ローカル設定 (再発の緩和。リポジトリ恒久対策ではない)
git config core.precomposeunicode true

# 7) コミット (ゲート追加と同一コミットにする = 「直したが検査は無い」状態を作らない)
```

#### 検証（受入条件）

| # | 条件 | 確認方法 |
|---|---|---|
| V-C1 | index entry = **139** | `git ls-files doc/reference \| wc -l` |
| V-C2 | 作業ツリー実体 = **139**（**減っていない**） | `find doc/reference -type f \| wc -l` |
| V-C3 | NFC 正規化衝突 = **0** | `GitIndexNormalizationTest` |
| V-C4 | NFC 側 blob 集合が施策前後で**一致** | `index-before.txt` と `after-blobs.txt` の突合 |
| V-C5 | `git status --porcelain=v1 -uall` が**空**（コミット後） | 同左 |
| V-C6 | **worktree ラウンドトリップ**: `setup-worktree.sh` → 何もせず `teardown-worktree.sh` が **成功する**（dirty チェックを通る） | 実走。これが施策 C2 の前提条件でもある |
| V-C7 | `tests/Unit/Manual/SopTextExtractorTest.php` が green（`sample-sop` の参照が生きている） | `composer test` |

#### ロールバック方法

| 段階 | 状況 | ロールバック手順 |
|---|---|---|
| **R1** | C2 の手順 3〜6 の途中（**未コミット**） | `git reset HEAD -- doc/reference` で index を復元 → `git status --porcelain=v1 -uall` が空になることを確認 → `git config core.precomposeunicode false` で設定を戻す。**作業ツリーのファイルは触っていないので復元不要** |
| **R2** | コミット済み・**未マージ**（task branch 上） | `git reset --hard <BASE_SHA>`（手順 0 で控えた値）。worktree 内の branch なので main に影響しない |
| **R3** | main へマージ済み | `git revert <merge-commit> -m 1` で index entry が復活する（blob は object DB に残っているため内容も完全に戻る）。あわせて `git config core.precomposeunicode false` |
| **R4** | 最終手段（index が壊れた） | `git update-index --index-info < devnotes/{dir}/index-before.txt` で **手順 0 で保存した index を丸ごと再構成**する |

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
| N2 | **空振り防止**: 読み取った index entry 数が **500 件以上** | pass |
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
- `scripts/ci/drop-test-db.php`（`--orphans` / `--apply` / `--confirm` / `--protect-hash` / `--include-unlabeled`）
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

```php
// scripts/ci/ensure-test-db.php の CREATE 直後 (L45-46 の間)
$pdo->exec(pgsqlCreateDatabaseSql($base));
// 出自を記録する (非破壊)。孤児 sweep の分類材料であり、guard ではない。
$pdo->exec(pgsqlCommentDatabaseSql($pdo, $base, (string) realpath($projectRoot)));
```

> **comment は base DB にのみ付く**。paratest の worker DB（`_test_N`）は Laravel の
> `ParallelTesting` が作るため comment を持たない。**hash グループ全体の出自を base の
> comment で代表させる**。base が不在で worker だけが残っている場合は **unlabeled** になる。

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
1. Protected  — hash が --protect-hash に含まれる          → shouldDrop = false
2. Live       — hash が生存 worktree hash 集合に含まれる    → shouldDrop = false
3. Foreign    — hash グループの provenancePath が実在する   → shouldDrop = false
4. Orphan     — hash グループの provenancePath が実在しない → shouldDrop = true
5. Unlabeled  — hash グループに provenance が無い          → shouldDrop = $includeUnlabeled
```

- **1 が 2 より先**: 明示保護は生存判定より強い（人間の意思を最優先する）
- **2 が 3 より先**: comment は書き換え可能な分類材料にすぎず、**生存 worktree の突合が優先**する
  （comment を細工しても生存 DB は落ちない）
- **3 が 4 より先**: path が実在する = 誰かが使っている可能性がある側へ倒す（fail-safe）
- **5 は既定で落とさない**: `--include-unlabeled` を明示したときだけ候補になる
- **worker DB は base と同じ hash グループの分類を継承する**（base の provenance が代表）

```php
/**
 * 孤児判定 (純関数)。上記の優先順位で評価する。
 *
 * @param  list<TestDatabaseCandidate>  $candidates
 * @param  list<string>  $liveHashes
 * @param  list<string>  $protectedHashes
 * @return list<TestDatabaseDecision>
 */
function classifyTestDatabases(
    array $candidates,
    array $liveHashes,
    array $protectedHashes,
    bool $includeUnlabeled,
): array { /* ... */ }
```

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
  php scripts/ci/drop-test-db.php --orphans --include-unlabeled
  php scripts/ci/drop-test-db.php --orphans --apply --confirm=<token> [--include-unlabeled] [--protect-hash=<hash> ...]
```

dry-run 出力（例）:

```
== 生存 worktree hash ==
  8af22c44  /workspace

== 保護 (--protect-hash) ==
  (なし)

== 所有元を確認できない hash (unlabeled) ==
  3a7d6b4e 823cbbd2 b4f0102e 018d63c6 91c7197b
  → これらは本施策より前に作られた DB か、base が既に消えた worker のみの群です。
     **同一 PostgreSQL を共有する別クローン / 別チェックアウトがある場合、その生存 DB が
     ここに含まれます**。apply する前に、別チェックアウトが無いことを必ず確認してください。

== 分類 ==
  app                       skip     dev DB denylist
  app_test_8af22c44         keep     live (生存 worktree /workspace)
  app_test_8af22c44_test_1  keep     live (生存 worktree /workspace)
  ...
  app_test_3a7d6b4e         DROP*    unlabeled (--include-unlabeled 指定時のみ)
  ...

== 集計 ==
  DROP 対象: 17 / 保持: 5 / skip: 1
  合計サイズ: 221.9 MB

--confirm=<64 桁の SHA-256>
  (この token は「DROP 対象 + 生存 hash + 保護 hash」の canonical JSON から算出しています。
   --apply は lock 取得後に同じ入力を再計算して token を照合し、一致した場合だけ実行します)
```

**token の定義**:

```
canonical = json_encode([
    'orphans'     => <DROP 対象 DB 名 / 昇順>,
    'live_hashes' => <生存 hash / 昇順>,
    'protected'   => <保護 hash / 昇順>,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
token = hash('sha256', canonical)   // 全長 64 桁。先頭切り詰めをしない
```

JSON 配列にする理由: 区切りなしの連結は `["a_b","c"]` と `["a","b_c"]` を区別できない。

### apply の運用契約（**概念設計 Round 5 の承認条件 2**）

| # | 契約 | 実装での担保 |
|---|---|---|
| 1 | 既定は **dry-run**。`--apply` 無しでは 1 件も DROP しない | 引数解析の既定値。`--apply` が無い経路に `pdo->exec(DROP)` を通さない |
| 2 | **`--apply` は人間の明示指示があるときだけ実行してよい**（エージェント判断での実行を禁止） | `--confirm=<token>` 必須。token は dry-run 出力を**人間が読んで**転記しない限り得られない。AGENTS.md §worktree 運用ルールにも明記する |
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
+        echo "      (実行は人間の明示指示 + --apply --confirm=<token> が必要)" >&2
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
+| `ci/drop-test-db.php` | worktree の base テスト DB と paratest worker DB を回収 (dev-DB は無条件 skip)。`--orphans` で「生存 worktree に紐づかない孤児 DB」の検出も行う (既定 dry-run。`--apply` は人間の明示指示 + `--confirm=<token>` が必要) | worktree teardown / CI cleanup / 孤児回収 (手動) |
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
| T-C2-6 | unlabeled: `$includeUnlabeled = false` | `Unlabeled` / `shouldDrop = false` |
| T-C2-7 | unlabeled: `$includeUnlabeled = true` | `Unlabeled` / `shouldDrop = true` |
| T-C2-8 | **dev DB `app` は候補に入らない**（境界で弾かれる） | 候補生成が `InvalidArgumentException` |
| T-C2-9 | **`bug_hunt` / `bug_hunt_3` は候補に入らない** | 同上 |
| T-C2-10 | allowlist 外（`app_test_XYZ` / `app_test_8af22c44_backup`）は候補に入らない | 同上 |
| T-C2-11 | worker DB は base と同じ分類を継承する | 同左 |
| T-C2-12 | base 不在で worker のみ → `Unlabeled` | 同左 |
| T-C2-13 | token: 同じ入力で同じ token / 1 件でも変われば別 token | 同左 |
| T-C2-14 | token: canonical JSON なので `["a_b","c"]` と `["a","b_c"]` が別 token | 同左 |
| T-C2-15 | 実行順序が違っても（入力順シャッフル）token が同一（昇順ソートの検証） | 同左 |

- [x] 既存テストの更新: `TestDatabaseEnv` の denylist 拡張により、既存の
  `assertPgsqlTestDatabaseSafe` / `isDevDatabase` のテストへ `bug_hunt*` ケースを追加
- [x] `ensure-test-db.php` の COMMENT 付与: **実 DB を作らない**単体テストで
  `pgsqlCommentDatabaseSql()` の生成 SQL（識別子クォート / リテラルクォート）を固定する
- [x] 受入テスト（実環境）: **dry-run のみ**を実行し、出力が
  「生存 5 件を keep / 孤児 17 件を unlabeled として列挙 / dev DB と bug_hunt を skip」に
  なることを確認する。**`--apply` は人間の明示指示があるまで実行しない**
- [x] 個別の `DatabaseTransactions` を使っていない（`tests/Unit/Ci/` は DB を触らない純粋な単体テスト）

### リスク

| リスク | 対処 |
|---|---|
| **別クローンの生存 DB を落とす** | Foreign 分類（provenance path 実在）で保持 / `--protect-hash` / unlabeled は既定除外 / dry-run 出力で「所有元不明 hash」を明示 / 人間承認必須。**現存 17 件は全て unlabeled なので `--include-unlabeled` が無ければ落ちない** |
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
| `eslint-plugin-better-tailwindcss` 4.4.1 → 4.7.0 で lint 指摘が増え、既存コードが赤くなる | 受入条件に `pnpm lint` 緑を含める。指摘が出たら**コード側を是正**する（ルールを無効化しない）。是正が大きすぎる場合は plugin の pin 上げを分離し、`pnpm.overrides` での valibot 引き上げを代替案として検討する（ただし overrides は最終手段） |
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


---

## 関連する現行コード

### tests/Architecture/GlobalTestLockInventoryTest.php (L100-160)
```php
            continue;
        }
        // 部分一致で通すと `with-global-test-lock.sh true && unlocked-test` のような
        // 「ラッパ名は含むが実体は無ロック」が素通りする。
        // **最終行 (= 実際に走るコマンド) が公式入口そのものであること**を要求し、
        // 同一行のシェル演算子で別コマンドを繋ぐことを禁止する。
        $lines = array_values(array_filter(
            array_map(trim(...), preg_split('/\R/', $command) ?: []),
            static fn (string $l): bool => $l !== '',
        ));
        $last = $lines === [] ? '' : $lines[count($lines) - 1];

        if (preg_match('/(&&|\|\||;|(?<!\|)\|(?!\|))/', $last) === 1) {
            $violations[] = "script '{$name}' がロック配下のコマンドをシェル演算子で連結している: {$last}";

            continue;
        }

        $entrypoints = array_merge(['scripts/with-global-test-lock.sh'], GLOBAL_TEST_LOCK_LANE_SCRIPTS);
        $viaEntrypoint = false;
        foreach ($entrypoints as $entrypoint) {
            if (preg_match('#^bash\s+'.preg_quote($entrypoint, '#').'(?:\s|$)#', $last) === 1) {
                $viaEntrypoint = true;
                break;
            }
        }
        if (! $viaEntrypoint) {
            $violations[] = "script '{$name}' がグローバルテストロックを経由していない: {$last}";
        }
    }

    return $violations;
}

/**
 * shell ソースから **実行行だけ** を取り出す (純関数)。
 *
 * 全ての静的検査はこの結果を単一の解析入力として使う。変更後スクリプトは
 * 「旧 worktree-local な test.lock を廃止した」「flock -n をやめた」といった説明を
 * **コメントに書く**ため、生ソースを検査すると正しい実装が偽赤になる。
 *
 * 行頭 (空白を除く) が `#` の行だけを落とす。行末コメントの除去はしない —
 * `'#'` のような引用符内の `#` を壊してコードを誤って削るリスクの方が大きい。
 */
function globalTestLockCodeLines(string $source): string
{
    $lines = preg_split('/\R/', $source) ?: [];
    $code = array_filter(
        $lines,
        static fn (string $line): bool => preg_match('/^\s*#/', $line) !== 1,
    );

    return implode("\n", $code);
}

/**
 * `CI` 環境変数の参照禁止を検査する対象 = ロック機構の全ファイル (ライブラリ本体を含む)。
 *
 * 「CI では素通り」の分岐は、**正しさが最も要求される場所に、ローカルでは一度も
 * 実行されないコードパス**を増やす。CI が検証しているものと開発者が走らせるものを
 * 同一に保つため、ロック機構は CI を特別扱いしない (概念設計 §CI の扱い)。
```

### tests/Architecture/BughuntOrchestratorGateInvariantTest.php (L85-112)
```php
 * 関数窓から「最初の実効文」を返す。関数定義行・`{`・コメント・空行・引数束縛のみの
 * `local ...` 宣言は読み飛ばす (= 副作用を持たない前置き)。
 * ただしコマンド置換を含む `local` は副作用を持つため読み飛ばさない。
 *
 * aigenba 版の「gate が特定の呼び出しより前に現れる」より強く、「gate が最初の実効文である」
 * ことを直接固定する (AI-CUE の cmd_teardown は aigenba と本体構造が異なり、
 * 特定呼び出しへのアンカーが脆いため)。
 */
function bughuntGateFirstEffectiveStatement(string $window): string
{
    foreach (preg_split('/\R/', $window) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#') || $trimmed === '{') {
            continue;
        }
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\(\)\s*\{?$/', $trimmed) === 1) {
            continue; // 関数定義行
        }
        if (bughuntGateIsInertLocal($trimmed)) {
            continue; // 引数束縛のみ (副作用なし)
        }

        return $trimmed;
    }

    return '';
}

```

### scripts/global-test-lock.sh (L255-275, L334-360)
```bash
# (各レーン実行時の ps 検証は、高速終了する子に対して空を返す race があるため best-effort にする)。
_gtl_probe_process_group() {
    local prev=0 pid pgid attempt=0
    case "$-" in *m*) prev=1 ;; esac
    # ps が空を返す race (probe 対象が先に終わった) は「作れなかった」ではないので数回試す。
    while [ "${attempt}" -lt 3 ]; do
        attempt=$(( attempt + 1 ))
        set -m
        sleep 0.3 &
        pid=$!
        [ "${prev}" = "1" ] || set +m
        pgid="$(ps -o pgid= -p "${pid}" 2>/dev/null | tr -d ' ')"
        kill "${pid}" 2>/dev/null || true
        wait "${pid}" 2>/dev/null || true
        [ "${pgid}" = "${pid}" ] && return 0
        [ -n "${pgid}" ] && break      # 値が取れて不一致 = 本当に作れていない
    done
    _gtl_die "job control で専用プロセスグループを作れない (set -m 不可)"
}

global_test_lock_acquire() {
...
global_test_lock_run() {
    # 再入 / flock 不在では素通り (fd 7 を保持していないので 7>&- もプロセスグループも不要)
    if [ "${_GTL_MODE}" != "owner" ]; then
        "$@"
        return $?
    fi

    local status=0 pgid=""
    case "$-" in *m*) _GTL_PREV_MONITOR=1 ;; *) _GTL_PREV_MONITOR=0 ;; esac
    set -m
    "$@" 7>&- &                                   # fd 7 は子へ渡さない (orphan による lock leak 防止)
    _GTL_CHILD_PID=$!
    [ "${_GTL_PREV_MONITOR}" = "1" ] || set +m
    _GTL_CHILD_PGID="${_GTL_CHILD_PID}"           # set -m により PGID == PID (取得時に probe 済み)

    # best-effort 検証: 空 = 既に終了 (race) なので異常ではない。値が違うときだけ落とす。
    pgid="$(ps -o pgid= -p "${_GTL_CHILD_PID}" 2>/dev/null | tr -d ' ')"
    if [ -n "${pgid}" ] && [ "${pgid}" != "${_GTL_CHILD_PID}" ]; then
        _gtl_die "専用プロセスグループを作れなかった (pid=${_GTL_CHILD_PID} pgid=${pgid})"
    fi

    wait "${_GTL_CHILD_PID}" || status=$?
    _GTL_CHILD_PID=""
    _gtl_wait_group_gone "${_GTL_CHILD_PGID}"     # 孫が残っている間はロックを離さない
    _GTL_CHILD_PGID=""
    return "${status}"
}
```

### scripts/teardown-worktree.sh (L60-112)
```bash
        echo "error: 別の setup/teardown が実行中です (${LOCK_DIR})。完了を待って再実行してください。" >&2
        echo "       (異常終了で残った stale lock の場合は rmdir '${LOCK_DIR}' で解除)" >&2
        exit 1
    fi
    LOCK_DIR_HELD=1
fi

# --- worktree dirty チェック (worktree が存在する場合のみ) ---
WORKTREE_PRESENT=0
if [[ -d "${WORKTREE_DIR}" ]]; then
    WORKTREE_PRESENT=1
    worktree_status=$(cd "${WORKTREE_DIR}" && git status --porcelain --untracked-files=all)
    if [[ -n "${worktree_status}" ]]; then
        echo "error: ${WORKTREE_DIR} に未コミット変更または untracked ファイルがあります" >&2
        echo "${worktree_status}" >&2
        echo "先に commit / stash / clean してください" >&2
        echo "(依存変更 = package.json / pnpm-lock.yaml / composer.json / composer.lock も必ずコミット)" >&2
        exit 1
    fi
else
    echo "notice: ${WORKTREE_DIR} が存在しません (teardown を経ずに削除された可能性)。prune して継続します。" >&2
fi

# --- main マージ済みかチェック (警告のみ) ---
if git -C "${REPO_ROOT}" rev-parse --verify --quiet "${BRANCH_NAME}" >/dev/null; then
    if git -C "${REPO_ROOT}" merge-base --is-ancestor "${BRANCH_NAME}" main; then
        echo ">>> ${BRANCH_NAME} は main にマージ済み"
    else
        echo "⚠️  warning: ${BRANCH_NAME} は main 未マージです (worktree 削除後もブランチは残ります)" >&2
    fi
fi

# --- pgsql test DB を best-effort 回収 ---
# worktree 削除の前に、worktree の base + paratest worker DB を allowlist guard 付きで drop する。
# best-effort: 接続失敗・スクリプト不在は warning で続行。
if (( WORKTREE_PRESENT )) && [[ -f "${WORKTREE_DIR}/scripts/ci/drop-test-db.php" ]]; then
    echo ">>> drop pgsql test DB (best-effort)"
    php "${WORKTREE_DIR}/scripts/ci/drop-test-db.php" || \
        echo "⚠️  warning: drop-test-db に失敗 (手動回収が必要な場合あり)" >&2
fi

# --- worktree 削除 ---
if (( WORKTREE_PRESENT )); then
    echo ">>> git worktree remove --force ${WORKTREE_DIR}"
    git -C "${REPO_ROOT}" worktree remove --force "${WORKTREE_DIR}"
else
    echo ">>> git worktree prune"
fi
git -C "${REPO_ROOT}" worktree prune

echo ""
echo "✅ worktree teardown 完了: ${TASK_ID}"
echo "   ブランチ ${BRANCH_NAME} の削除/マージは呼び出し側の責務 (本スクリプトは触らない)"
```

### scripts/ci/drop-test-db.php (全文)
```php
<?php

declare(strict_types=1);

/*
 * scripts/ci/drop-test-db.php
 *
 * worktree の base テスト DB (`<slug>_test_<hash>`) と paratest worker DB (`_test_<token>`)
 * を回収する。ensure と接続 resolver を共有 (pgsql_test_conn.php) し、
 * 同一 PostgreSQL を見る (stale DB 排除)。
 *
 * dev-DB 保護 (NON-NEGOTIABLE):
 *   - base 名は TestDatabaseEnv::pgsqlBaseDatabase() (唯一のソース)。
 *   - pg_database を `datname = base OR datname LIKE base||'\_test\_%'` で列挙し、
 *     1 件ずつ isAllowedTestDatabase() で再検証。一致したものだけ DROP する。
 *   - isDevDatabase() true は無条件 skip + 警告 (理論上到達しないが防壁)。
 *   - best-effort: 接続失敗は skip + exit 0 (teardown を止めない)。失敗 DB 名は stderr に明示。
 */

use Tests\Support\Ci\TestDatabaseEnv;

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/pgsql_test_conn.php';

$projectRoot = dirname(__DIR__, 2);
$base = TestDatabaseEnv::pgsqlBaseDatabase($projectRoot);

try {
    $pdo = pgsqlTestMaintenancePdo($projectRoot);
} catch (Throwable $e) {
    fwrite(STDERR, "drop-test-db: maintenance DB connect failed; skipping (best-effort): {$e->getMessage()}\n");
    exit(0);
}

// base 完全一致 OR base_test_<token>。LIKE の _ / % を ESCAPE でリテラル化。
$pattern = str_replace(['_', '%'], ['\_', '\%'], $base).'\_test\_%';
$stmt = $pdo->prepare("SELECT datname FROM pg_database WHERE datname = :base OR datname LIKE :pat ESCAPE '\\'");
$stmt->execute(['base' => $base, 'pat' => $pattern]);

/** @var list<string> $names */
$names = array_values(array_filter(
    array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $stmt->fetchAll(PDO::FETCH_COLUMN)),
    static fn (string $v): bool => $v !== '',
));

$dropped = 0;
foreach ($names as $name) {
    if (TestDatabaseEnv::isDevDatabase($name)) {
        fwrite(STDERR, "drop-test-db: refusing to drop dev DB (skipped): {$name}\n");

        continue;
    }
    if (! TestDatabaseEnv::isAllowedTestDatabase($name)) {
        fwrite(STDERR, "drop-test-db: name not allowlisted (skipped): {$name}\n");

        continue;
    }
    try {
        $pdo->exec(pgsqlDropDatabaseSql($name));
        $dropped++;
    } catch (Throwable $e) {
        fwrite(STDERR, "drop-test-db: failed to drop {$name} (manual cleanup may be needed): {$e->getMessage()}\n");
    }
}

fwrite(STDERR, "drop-test-db: dropped {$dropped} test DB(s) for base {$base}\n");
exit(0);
```

### scripts/ci/ensure-test-db.php (L24-47)
```php
$projectRoot = dirname(__DIR__, 2);
$base = TestDatabaseEnv::pgsqlBaseDatabase($projectRoot);

// dev-DB 二重防御 (pgsqlBaseDatabase 内でも検査済だが、CREATE 直前に再確認)。
Assert::false(TestDatabaseEnv::isDevDatabase($base), "refusing to ensure dev DB: {$base}");
Assert::true(TestDatabaseEnv::isAllowedTestDatabase($base), "computed base name not allowlisted: {$base}");

try {
    $pdo = pgsqlTestMaintenancePdo($projectRoot);
} catch (Throwable $e) {
    fwrite(STDERR, "ensure-test-db: failed to connect to maintenance DB (postgres): {$e->getMessage()}\n");
    exit(1);
}

$stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :name');
$stmt->execute(['name' => $base]);
if ($stmt->fetchColumn() !== false) {
    fwrite(STDERR, "ensure-test-db: base DB already exists: {$base}\n");
    exit(0);
}

$pdo->exec(pgsqlCreateDatabaseSql($base));
fwrite(STDERR, "ensure-test-db: created base DB: {$base}\n");
exit(0);
```

### scripts/ci/pgsql_test_conn.php (L52-87)
```php
function pgsqlTestMaintenancePdo(string $projectRoot): PDO
{
    $c = pgsqlTestConnValues($projectRoot);
    $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname=postgres";

    return new PDO($dsn, $c['username'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10,
    ]);
}

/**
 * 識別子 (DB 名) を PostgreSQL の二重引用符でクォートする。
 * DB 名は allowlist 正規表現で検証済みのものだけを渡す前提 (二重防御)。
 */
function pgsqlQuoteIdentifier(string $name): string
{
    return '"'.str_replace('"', '""', $name).'"';
}

/**
 * base DB 不在時のみ実行する CREATE DATABASE 文を生成する (冪等は呼び出し側で pg_database 確認)。
 * base 名は TestDatabaseEnv::pgsqlBaseDatabase() (allowlist 準拠) からのみ渡される前提。
 */
function pgsqlCreateDatabaseSql(string $name): string
{
    return 'CREATE DATABASE '.pgsqlQuoteIdentifier($name);
}

/**
 * allowlist 検証済み DB 名に対する DROP 文を生成する。WITH (FORCE) で接続中でも落とす。
 */
function pgsqlDropDatabaseSql(string $name): string
{
    return 'DROP DATABASE IF EXISTS '.pgsqlQuoteIdentifier($name).' WITH (FORCE)';
}
```

### tests/Support/Ci/TestDatabaseEnv.php (L27-101)
```php
final class TestDatabaseEnv
{
    /** テスト DB 名 prefix。config('template.slug') 既定値 'app' + '_test_' (init.sh 置換対象)。 */
    public const TEST_DB_PREFIX = 'app_test_';

    /** 実テスト DB 名の許可パターン (base または paratest worker)。不可逆 DROP / bootstrap ガードの正の allow。 */
    public const TEST_DB_ALLOWLIST_PATTERN = '/^app_test_[0-9a-f]{8}(_test_[0-9]+)?$/';

    /** dev DB 名の hard-deny 対象 (docker-compose の POSTGRES_DB / slug 既定値)。trim+lowercase 比較。 */
    public const DEV_DB_DENYLIST = ['app'];

    /** worktree root realpath の決定論的 8 桁 hash。別 worktree との DB 衝突を防ぐキー。 */
    public static function workrootHash(string $projectRoot): string
    {
        $real = realpath($projectRoot);
        Assert::string($real, 'projectRoot must resolve to a real path');

        return substr(sha1($real), 0, 8);
    }

    /**
     * pgsql base テスト DB 名 `<slug>_test_<hash>`。
     * 生成名が dev DB でない・allowlist 準拠であることを Assert する (理論破綻で fail-closed)。
     */
    public static function pgsqlBaseDatabase(string $projectRoot): string
    {
        $name = self::TEST_DB_PREFIX.self::workrootHash($projectRoot);

        if (self::isDevDatabase($name)) {
            throw new InvalidArgumentException("computed test DB name collided with a dev DB: {$name}");
        }
        Assert::true(self::isAllowedTestDatabase($name), "computed test DB name is not allowlisted: {$name}");

        return $name;
    }

    /**
     * pgsql のとき DB_DATABASE に強制すべき base 名。pgsql 以外 / 未設定は null。
     *
     * @param  array<string, mixed>  $server  $_SERVER 相当 (DB_CONNECTION を見て分岐)
     */
    public static function pgsqlOverrideDatabase(array $server, string $projectRoot): ?string
    {
        if (($server['DB_CONNECTION'] ?? null) !== 'pgsql') {
            return null;
        }

        return self::pgsqlBaseDatabase($projectRoot);
    }

    /**
     * 単一点 fail-closed ガード本体。pgsql lane で最終 DB_DATABASE が test DB
     * (allowlist 一致 + 非 dev) でなければ例外。tests/bootstrap.php から Laravel boot 前に呼ぶ。
     *
     * @throws InvalidArgumentException dev DB / 非 allowlist の場合
     */
    public static function assertPgsqlTestDatabaseSafe(string $effectiveDb): void
    {
        if (self::isDevDatabase($effectiveDb)) {
            throw new InvalidArgumentException("refusing to run pgsql tests against a dev DB: {$effectiveDb}");
        }
        Assert::true(self::isAllowedTestDatabase($effectiveDb), "effective pgsql test DB is not allowlisted: {$effectiveDb}");
    }

    /** DB 名が dev DB (variant 含む) か。前後空白・大小バリアントも塞ぐ。 */
    public static function isDevDatabase(string $name): bool
    {
        return in_array(strtolower(trim($name)), self::DEV_DB_DENYLIST, true);
    }

    /** DB 名が test allowlist に一致するか (不可逆 DROP・bootstrap ガードの正の allow)。 */
    public static function isAllowedTestDatabase(string $name): bool
    {
        return preg_match(self::TEST_DB_ALLOWLIST_PATTERN, $name) === 1;
    }
```

### scripts/bug-hunt-inventory-check.sh (L20-97)
```bash

drift=0

# screens.md / operations.md が「設計上ブラウザ非対象」と明記しているルート名 prefix。
# これらは UX ブラウザ監査の対象外として意図的にインベントリ表から外しているため、
# drift 検出 (新ルート未追記) からも除外する。新たに非対象を増やす場合は両方を更新すること。
# filament.* は S9 (管理画面) が screens.md/operations.md に手動メンテで載せる admin guard ルート。
# route:list 抽出側 (forward) は uri prefix 'admin' で既に除外済み。reverse (消失検知) でも除外し、
# admin インベントリ行が誤って「消失候補」warning にならないようにする。
OUT_OF_SCOPE_PREFIXES='seo.|social.|recent-auth.sso.|two-factor.qr-code|two-factor.secret-key|two-factor.recovery-codes|password.confirmation|cashier.|passport.|livewire|default-livewire|mcp.|oauth.|webhooks.|sanctum.|filament.'

# 対象 GET×inertia(web) のルート名 (admin/api/debug/mcp/seo/oauth/xhr-only 等は除外)。
get_screen_names() {
    php artisan route:list --json | OOS="${OUT_OF_SCOPE_PREFIXES}" python3 -c "
import json,os,re,sys
oos=re.compile('^('+os.environ['OOS']+')')
for r in json.load(sys.stdin):
    if 'GET' not in r['method']: continue
    uri=r['uri']; mw=str(r.get('middleware',[]))
    if uri.startswith(('api/','admin','_','.well-known','storage','sanctum','livewire','oauth','mcp')) or 'debug' in uri: continue
    if 'web' not in mw: continue
    name=r.get('name')
    if not name or oos.match(name): continue
    print(name)" | sort -u
}

# 対象 非GET×web の操作名 (webhook/passport/livewire/out-of-scope は除外)。
get_op_names() {
    php artisan route:list --json | OOS="${OUT_OF_SCOPE_PREFIXES}" python3 -c "
import json,os,re,sys
oos=re.compile('^('+os.environ['OOS']+')')
for r in json.load(sys.stdin):
    m=r['method'].split('|')[0]
    if m in ('GET','HEAD','OPTIONS'): continue
    mw=str(r.get('middleware',[])); name=r.get('name')
    if 'web' not in mw or not name: continue
    if oos.match(name) or 'webhook' in name: continue
    print(name)" | sort -u
}

check() {
    local label=$1 file=$2; shift 2
    local names; names="$("$@")"
    echo "== ${label} =="
    local n
    while IFS= read -r n; do
        [[ -z "${n}" ]] && continue
        if ! grep -qF "${n}" "${file}"; then
            echo "  [新ルート未追記] ${n} が ${file} に無い"
            drift=3
        fi
    done <<< "${names}"
    # file に書かれた route 名が route:list から消えていないか (簡易: name 列を抽出して照合)
    local listed
    listed="$(grep -oE '[a-z0-9-]+\.[a-z0-9.-]+|^\| `?/' "${file}" 2>/dev/null || true)"
    # 消失検知は名前トークン単位で行う (誤検知を避けるため warning のみ)
    while IFS= read -r tok; do
        [[ -z "${tok}" ]] && continue
        # out-of-scope として表に記録した名前は消失検知から除外する。
        echo "${tok}" | grep -qE "^(${OUT_OF_SCOPE_PREFIXES})" && continue
        case "${tok}" in
            *.*)
                if ! echo "${names}" | grep -qF "${tok}"; then
                    echo "  [消失候補] ${file} の '${tok}' が現 route:list に無い (削除漏れの可能性)"
                fi
                ;;
        esac
    done < <(grep -oE '\| [a-z0-9-]+\.[a-z0-9.-]+ ' "${file}" | tr -d '| ' | sort -u)
}

check "screens (GET×inertia)" "${SCREENS}" get_screen_names
check "operations (非GET×web)" "${OPS}" get_op_names

if [[ "${drift}" == 3 ]]; then
    echo "drift 検出: インベントリと route:list に差分あり (上記を確認)"
    exit 3
fi
echo "drift なし: インベントリは route:list と整合"
```

### app/Http/Routing/RouteBindingTypes.php (L130-145)
```php
     * slug route が全滅する (概念設計 Round 1 Critical)。
     *
     * @var array<string, class-string>
     */
    public const CUSTOM_BINDER = [
        'organization' => MembershipScopedOrganizationBinder::class,
        // {passkey} は Fortify (vendor) が登録する route の param。app 側から
        // Route::pattern を掛けると vendor の route 定義変更に追随できないため、
        // binder が「認証ユーザー所有 + 数値正規化」を担う (他人の passkey は 404)。
        'passkey' => SelfScopedPasskeyBinder::class,
    ];

    /**
     * モデル binding ではない文字列 param。型制約の対象外。
     *
     * @var list<string>
```

### docs/architecture.md (L82-92)
```markdown
- **5 分類 (deny-by-default)**: `BIGINT` / `UUID` (param => モデルの map。pattern 適用) /
  `CUSTOM_BINDER` (`{organization}`。`{organization:slug}` 併用のため pattern を適用せず
  `MembershipScopedOrganizationBinder` が入力正規化を担う) / `NON_MODEL` / `EXTERNAL`
  (vendor route が持ち込む param を route identity ごとに登録)。
  未登録 param の出現は `RouteBindingTypeConstraintInventoryTest` が fail させる
  (未知 param を数値と推測しない)。実挙動 (非適合 → 404) は
  `tests/Feature/Routing/RouteBindingTypeConstraintTest` が pgsql 実接続で固定する
- **`MANUALLY_RESOLVED` (IV-9(a) の免除)**: controller が implicit binding を使わず
  手動解決する param は action 引数が string になるため、**「param 名 + route identity」の
  両方**で免除登録する (param 名だけの免除は同名 param を使う将来 route を丸ごと素通りさせる)。
  免除しても pattern の型制約と PK 型検査は効き続ける。現在の登録は
```

### AGENTS.md (L41-57, L74-80, L147-157)
```markdown
## セキュリティ不変条件(アプリ都合で緩めない)

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)

...
  禁止。`tests/js/architecture/atomic-import-graph.test.ts` が強制)。アイコンは
  `@lucide/svelte` のみ。Lucide に無いブランド/SSO ロゴの SVG 内包は
  `components/atoms/icons/` 配下に限る(`svg-inline-allowlist.test.ts` が強制)
- 検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
  `pnpm typecheck:packages` / `pnpm test:packages`(全 green でコミット)

...
- **後片付け**: `scripts/teardown-worktree.sh <task-id>` が dirty チェック
  (未コミット/untracked があれば fail)→ テスト DB の best-effort 回収 →
  `git worktree remove --force` を行う。ブランチ `todo/<task-id>` の削除/マージは
  呼び出し側の責務(main マージ後に `git branch -d todo/<task-id>`)
- **orphan 化した worktree**(teardown を経ず破棄)は `git worktree prune` で整理。
  検証なしの強制削除は
  `git worktree remove --force .claude/worktrees/tasks/<task-id> && git worktree prune`
- **背景と障害対応**: 分離設計 (vendor / node_modules / テスト DB / 実行時ファイルの 4 軸) の
  意図は `docs/worktree-isolation-strategy.md`、`enableGlobalVirtualStore` の前提・落とし穴・
  復旧手順は `docs/pnpm-global-virtual-store-runbook.md`(GVS 無効化・暗黙 peer・ENOMEM 等)

```

### package.json scripts
```json
{
 "audit:gate": "bash scripts/audit-gate.sh",
 "build": "vite build",
 "dev": "NODE_OPTIONS='--max-old-space-size=2048' vite",
 "lint": "eslint resources/js",
 "lint:fix": "eslint resources/js --fix",
 "typecheck": "tsc --noEmit",
 "test": "bash scripts/run-vitest.sh",
 "test:ui": "vitest --ui",
 "test:coverage": "bash scripts/with-global-test-lock.sh pnpm exec vitest run --coverage",
 "test:watch": "vitest --watch",
 "build:packages": "pnpm -F \"./packages/*\" build",
 "typecheck:packages": "pnpm -F \"./packages/*\" typecheck",
 "test:packages": "bash scripts/with-global-test-lock.sh pnpm -F \"./packages/*\" test"
}
```

### tests/js/architecture/ci-workflow-inventory.test.ts (L40-70 抜粋)
```ts

const WORKFLOW_PATH = resolve(process.cwd(), ".github/workflows/ci.yml");

function loadWorkflow(): Workflow {
    return parseYaml(readFileSync(WORKFLOW_PATH, "utf-8")) as Workflow;
}

function job(workflow: Workflow, name: string): WorkflowJob {
    const found = workflow.jobs?.[name];
    if (!found) throw new Error(`job "${name}" が ci.yml に無い`);
    return found;
}

/** 全 run 文字列を job 単位で連結する (step の分割に依存せず「実行しているか」を見るため)。 */
function runScript(target: WorkflowJob): string {
    return (target.steps ?? []).map((s) => s.run ?? "").join("\n");
}

/** `run` 文字列を「空行とコメント行を除いた実行行」へ分解する。 */
function runLines(target: WorkflowJob): string[] {
    return (target.steps ?? [])
        .flatMap((s) => (s.run ?? "").split("\n"))
        .map((l) => l.trim())
        .filter((l) => l !== "" && !l.startsWith("#"));
}

/** 任意の深さのオブジェクト木に指定 **キー名** が現れる位置を返す純関数 (W9 / W13 用)。 */
export function findKeyPaths(node: unknown, key: string, path = "$"): string[] {
    const hits: string[] = [];
    if (Array.isArray(node)) {
        node.forEach((child, i) => hits.push(...findKeyPaths(child, key, `${path}[${i}]`)));
```
