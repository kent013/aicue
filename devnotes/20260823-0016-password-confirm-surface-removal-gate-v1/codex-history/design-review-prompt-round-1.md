【アプリの使命 (North Star) — AGENTS.md より】

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

（アプリの使命・禁止事項は上に挿入済み）

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC

【背景 — この設計が追従する家系正典】
lctl feature `surface-removal-absence-gate` canonical v1。必須要素は (1) 実行時の不在層 (2) 静的字句走査層 (許可一覧を持たず 0 件固定) (3) 検出器自身の自己検証 (正例・負例) (4) 消しすぎていない確認層 (5) 走査根に .github/ と scripts/ を必ず含める (database/migrations/ は含めない)。加えて母集団の生成・既定拒否。
概念設計は別セッションの Codex レビュー Round 5 で APPROVED 済み。本レビューは**詳細設計**が対象。

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
12. 正典 v1 の不変条件を全て満たしているか、かつ**最小スコープ**に留まっているか（過大化していないか）
13. AGENTS.md「静的検査 (gate) と走査器の共通規約」5 条 (a)〜(e) への適合（完全修飾名での突合 / fail-closed / 負例での裏取り / 集めた結果を判定に使う / 語彙一致は区切り文字宣言つきトークン完全一致）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: password-confirm-surface-removal-gate-v1

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

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- テストデータは必ず Factory で生成（本設計は DB を使わない静的検査が中心で、Factory 追加は無い）
- **DTO + JsonResource** パターン（本設計はアプリの応答を作らないため非該当）
- アーリーリターン推奨 / `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- **AGENTS.md「静的検査 (gate) と走査器の共通規約」の 5 条 (a)〜(e)** に従う
- **AGENTS.md「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」** に従う（テストファーストで先に赤くする / 解決できない形を落とす分岐 / 空振り検査 / docblock に走査対象と保証しないものを書く）

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex 概念レビュー Round 5 で **APPROVED**）
- 家系の正典: lctl feature `surface-removal-absence-gate` canonical **v1**

## 正典 v1 の不変条件と、本設計での満たし方（全列挙）

| # | 正典 v1 の不変条件 | 本設計での担保 |
|---|---|---|
| I1 | 撤去後の**実行時の不在**を層に分けて固定する（route 名 / メソッド×URI / クラス・表 / 実 HTTP 404 と無副作用） | S3（A）と S5（B）。**該当しない軸は理由つきで宣言**する（下表） |
| I2 | production surface への**参照の再流入を字句で止める** Architecture テスト | S4（A）と S5（B）。Tier 1（PHP トークン）と Tier 2（非 PHP 生テキスト） |
| I3 | 静的層は**許可一覧を持たない（0 件固定）** | Tier 2 は許可形 **0 個**。Tier 1 は A のみ**場所ではなく形**で 1 種を許す（4 条件の連言。家系解釈 (a)） |
| I4 | 検出器自身の**自己検証**を正例・負例の両方で持つ | S4 / S5 の各 gate 内に、`tests/Architecture/fixtures/surface-removal/` の見本を使った自己検証を置く（**撤去語ごと**にマトリクスを持つ） |
| I5 | **消しすぎていない**ことの確認層 | S3 の層 3（recent-auth の生存）と S5（画像受理の既存テストを docblock から指す） |
| I6 | 走査根に **`.github/` と `scripts/` を必ず含める** | S2 の `ROOT_DIRECTORIES` に含める。実走査母集団の種別検査で「拡張子なし 1 件以上 / `.sh` 1 件以上 / `workflows/` の YAML 1 件以上」を固定 |
| I7 | **`database/migrations/` は走査根に含めない** | S2 の docblock に理由つきで明記（撤去した表名は移行履歴に必ず残るため原理的に赤くなる） |
| I8 | **母集団の生成・既定拒否**（検査対象の列挙が腐らない） | 走査根は `git ls-files` から生成。再有効化スイッチは設定木から `confirmPassword` キーを**生成**して全件 `false` を要求。空振り検査は**除外・検証後の実走査母集団**に対して行う |
| I9 | 検出力の主張を**誇張しない**（保証範囲を docblock に書く） | 分割連結・定数経由・動的組み立て・PHP コメント内・NUL を含むバイナリ・裸の `imagesEnabled`（非 PHP）には**沈黙する**と明記 |

### 撤去物 × 実行時観測軸（I1 の全軸を埋める）

| 観測軸 | A: `password.confirm` step-up 機構 | B: OCR 機能フラグ |
|---|---|---|
| route 名の不在 | **該当なし**（撤去したのは機構。同名 route 3 本は Fortify が救済 redirect / 状態プローブとして意図的に残す現役資産） | **該当なし**（設定値であり route を持たない） |
| メソッド×URI の不在 | **該当なし**（`user/confirm-password` は現役） | **該当なし** |
| クラス・表の不在 | **該当なし**（機構は vendor 側クラス。aicue が撤去したのは*適用*） | **該当なし**（`AcceptedSourceDocumentTypes` は現役、削除された表も無い） |
| 実 HTTP 404・無副作用 | **該当なし**（同上） | **該当なし** |
| 機構に対応する等価の実行時層（家系解釈 (b)） | **検査する 3 つ**: (i) `password.confirm` middleware を持つ route が 0 本 / (ii) 生成した `confirmPassword` 母集団が全件 `false` / (iii) 置換先 recent-auth が生存 | **検査する 3 つ**: (i) `manual` 設定木に `ocr_analysis_enabled` キーが**存在しない** / (ii) `method_exists(AcceptedSourceDocumentTypes::class, 'imagesEnabled') === false` / (iii) 画像受理は T242 の既存テストが担保（docblock から指す） |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 自己検証の見本を置く | `tests/Architecture/fixtures/surface-removal/**`（新設） | 高（テストファースト） |
| S2 | 走査根の単一出典と実走査母集団 | `tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php`, `ScannedFile.php`, `ScanPopulation.php`（新設） | 高 |
| S3 | 走査器（形だけを返す。ポリシーを持たない） | `tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php`, `PhpStringOccurrence.php`, `TextOccurrence.php`, `MethodReferenceOccurrence.php`, `ScanOutcome.php`（新設） | 高 |
| S4 | A の実行時層を v1 へ強化 | `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php`（変更） | 高 |
| S5 | A の静的層 + 自己検証 | `tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php`（新設） | 高 |
| S6 | B（OCR フラグ）の実行時層 + 静的層 + 自己検証 | `tests/Architecture/OcrFeatureFlagAbsenceGateTest.php`（新設） | 中 |
| S7 | Tier 2 を 0 件固定にするためのコメント文言修正 | `resources/js/pages/Settings/Security.svelte`, `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte`（コメント各 1 行） | 高（S5/S6 の前提） |

---

## S1. 自己検証の見本を置く

### 変更箇所
- 新設: `tests/Architecture/fixtures/surface-removal/password-confirm/` と `tests/Architecture/fixtures/surface-removal/ocr-flag/`

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: S5 / S6 が本見本を読む

### 設計

**拡張子の規約**: PHP の見本は既存 `tests/Architecture/fixtures/global-use/` と同じく **`.php.txt`** にする。理由は、`StrictTypesDeclarationGateTest` が **git 追跡下の PHP 全数**を免除なしで対象にしており、違反を意図的に含む見本を `.php` で置くと**無関係な gate が赤くなる**ため。走査器へは「PHP として扱う」ことを引数で明示して渡す（拡張子から推定しない）。

非 PHP の見本は本物の拡張子（`.svelte.txt` `.ts.txt` `.css.txt` `.sh.txt` `.yaml.txt` / 拡張子なしは `noext.txt`）で置き、走査器へは「非 PHP として扱う」ことを明示して渡す。

**A（`password.confirm`）の見本**

正例（違反として検出されなければならない）:

| ファイル | 内容の要点 |
|---|---|
| `positive-middleware-array.php.txt` | `Route::get('/x', X::class)->middleware(['auth', 'password.confirm']);` |
| `positive-middleware-arg.php.txt` | `->middleware('password.confirm')` |
| `positive-config-middleware-key.php.txt` | `'management_middleware' => ['password.confirm']` |
| `positive-value-namespaced-class.php.txt` | `'password.confirm' => 'App\\Http\\Middleware\\Example'`（値が namespace 付きクラス名らしい形 → 許可条件 3 で落ちる） |
| `positive-value-pascal-class.php.txt` | `'password.confirm' => 'ExampleMiddleware'`（PascalCase 短名） |
| `positive-value-class-const.php.txt` | `'password.confirm' => Example::class`（値が単独文字列でない → 条件 2 で落ちる） |
| `positive-value-array.php.txt` | `'password.confirm' => ['throttle' => 'x']`（同上） |
| `positive-unregistered-route-key.php.txt` | `'password.confirm.legacy-view' => 'タイトル'` を**未登録 route 名**として置き、条件 4 で落ちることを見る（※ 語境界で `password.confirm` に一致しない形なので、本見本は撤去語を `'password.confirm'` にしたうえで **gate 側で「登録済み route 名の集合」を差し替えて**評価する。詳細は S5） |
| `positive-css-id-selector.css.txt` | `#password.confirm { content: "x"; }` |
| `positive-css-universal.css.txt` | `* { content: "password.confirm"; }` |
| `positive-ts-generator.ts.txt` | 行頭 `*` の generator メソッド内に `password.confirm` を含む |
| `positive-svelte-markup.svelte.txt` | markup 領域に出現 |
| `positive-svelte-script.svelte.txt` | `<script>` 領域に出現 |
| `positive-svelte-style.svelte.txt` | `<style>` 領域に出現 |
| `positive-shell.sh.txt` | `.sh` 相当に出現 |
| `positive-noext.txt` | shebang のみの拡張子なしファイル相当に出現 |
| `positive-workflow.yaml.txt` | YAML に出現 |

負例（反応してはならない）:

| ファイル | 内容の要点 |
|---|---|
| `negative-allowed-title-map.php.txt` | `'password.confirm' => 'パスワードの確認'`（4 条件をすべて満たす唯一の許可形） |
| `negative-suffix.php.txt` | `'password.confirm.store'` / `'password.confirmation'` / `'password.confirmed'`（接尾辞つき） |
| `negative-prefix.php.txt` | `'x-password.confirm'`（接頭辞つき） |
| `negative-negated.php.txt` | `'no-password.confirm'`（打ち消しつき） |
| `negative-php-comment.php.txt` | `// password.confirm は撤去済み` / docblock 内の出現 |
| `negative-binary.bin.txt` | NUL バイトを含む（バイナリとして除外され、違反にならない） |

**B（OCR）の見本**

正例:

| ファイル | 内容の要点 |
|---|---|
| `positive-config-key.php.txt` | `'ocr_analysis_enabled' => true`（文字列リテラルとして出現） |
| `positive-env.sh.txt` | `OCR_ANALYSIS_ENABLED=1` |
| `positive-prop.svelte.txt` | `imageSourceDocumentsEnabled` を props に持つ |
| `positive-method-declaration.php.txt` | `namespace App\Support\Manual; final class AcceptedSourceDocumentTypes { public static function imagesEnabled(): bool { … } }` |
| `positive-static-call-use.php.txt` | `use App\Support\Manual\AcceptedSourceDocumentTypes;` → `AcceptedSourceDocumentTypes::imagesEnabled()` |
| `positive-static-call-alias.php.txt` | `use App\Support\Manual\AcceptedSourceDocumentTypes as Types;` → `Types::imagesEnabled()` |
| `positive-static-call-group-use.php.txt` | `use App\Support\Manual\{AcceptedSourceDocumentTypes};` → 同上 |
| `positive-static-call-fqcn.php.txt` | `\App\Support\Manual\AcceptedSourceDocumentTypes::imagesEnabled()` |
| `positive-same-namespace.php.txt` | `namespace App\Support\Manual;` 内で `AcceptedSourceDocumentTypes::imagesEnabled()`（取り込み無しで解決） |
| `positive-fqcn-in-text.sh.txt` | 非 PHP に `App\Support\Manual\AcceptedSourceDocumentTypes::imagesEnabled` |

負例:

| ファイル | 内容の要点 |
|---|---|
| `negative-other-class-declaration.php.txt` | `namespace App\Other; class Thing { public static function imagesEnabled(): bool {…} }` |
| `negative-other-class-static-call.php.txt` | `App\Other\Thing::imagesEnabled()` |
| `negative-dynamic-call.php.txt` | `$x->imagesEnabled()`（保証範囲外として**沈黙する**ことの固定） |
| `negative-bare-imagesenabled.sh.txt` | 非 PHP の裸の `imagesEnabled`（検出力を主張しない） |
| `negative-suffix.php.txt` | `'ocr_analysis_enabled_at'` |
| `negative-prefix.php.txt` | `'legacy_ocr_analysis_enabled'` |
| `negative-negated.php.txt` | `'disable_ocr_analysis_enabled'` |

未解決（gate を失敗させることの固定）:

| ファイル | 内容の要点 |
|---|---|
| `unresolved-untraceable-alias.php.txt` | `$cls::imagesEnabled()` のように**クラス参照を完全修飾名へ解決できない** `::imagesEnabled` 参照 |
| `unresolved-broken-php.php.txt` | トークン化に失敗する PHP |

### テスト計画
- [ ] 見本自体のテストは持たない（見本は S5 / S6 の自己検証の入力）
- [ ] ただし **S5 / S6 の自己検証は「見本ファイルが検索語を実際に連続して含むこと」を先に assert する**（見本が壊れて静かに空振りするのを防ぐ）

### リスク
- 見本を `.php.txt` にすると IDE の PHP 補完が効かないが、既存 `global-use` 見本と同じ規約であり許容する。

---

## S2. 走査根の単一出典と実走査母集団

### 変更箇所
- 新設: `tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php` / `ScannedFile.php` / `ScanPopulation.php`

### 波及変更
- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: S5 / S6 が使う

### 変更後コード（要点）

```php
<?php

declare(strict_types=1);

namespace Tests\Support\SurfaceRemoval;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * 撤去物の不在 gate が共有する**走査根と実走査母集団**の単一出典。
 *
 * ★走査根 (8 本): .github / app / bootstrap / config / lang / resources / routes / scripts。
 *   `.github` と `scripts` は家系の正典 v1 が**必須**にしている
 *   (撤去直後に CI 設定へ参照が残り CI ジョブ 5 本が全滅した実測事故の教訓)。
 * ★`database/migrations` は**含めない**。撤去した表の名前は移行履歴に必ず残るため、
 *   含めると原理的に赤くなる (正典 v1 の明文)。
 * ★母集団は**拡張子で絞らない**。`scripts/` には拡張子なしの実行ファイルが実在し、
 *   拡張子の許可集合方式ではそれらが落ちて上記の事故をそのまま再現する。
 * ★確定は**この 1 経路だけ**で行う: git 追跡下の列挙 → 読み取り (失敗は未解決) →
 *   NUL 判定 (含むならバイナリとして除外) → UTF-8 検証 (不正は未解決) → 実走査母集団へ登録。
 *   **数える集合は本体の検査が実際に走査した集合と同一**である (別に数え直さない)。
 * ★**保証しないもの**: NUL バイトを含むファイル (バイナリ) には沈黙する。
 *   git 未追跡のファイルは列挙しない (gate が守る境界は commit / CI であり、そこでは追跡下にある)。
 * ★`Tests\Support\TrackedPhpSourceFiles` との関係: あちらは拡張子 `.php` に限った全数列挙で、
 *   本クラスは**同じ作法 (git ls-files) で母集団を全ファイルへ広げた兄弟**である。
 *   走査根を絞る点も違う (あちらはリポジトリ全体)。
 */
final class RemovedSurfaceScanTargets
{
    /** @var list<string> 走査根 (リポジトリルート相対)。 */
    private const array ROOT_DIRECTORIES = [
        '.github', 'app', 'bootstrap', 'config', 'lang', 'resources', 'routes', 'scripts',
    ];

    /**
     * 走査根 (相対 => 絶対)。**存在しない根は fail-fast**。
     *
     * @return array<string, string>
     */
    public static function roots(): array { /* realpath、解決不能なら RuntimeException */ }

    /** 実走査母集団を確定する (唯一の経路)。 */
    public static function population(): ScanPopulation { /* 上記の順序を固定 */ }

    /** @return list<string> git 追跡下の相対パス (root 配下) */
    private static function trackedFiles(string $root): array
    {
        $process = new Process(['git', 'ls-files', '-z', '--', $root], self::repositoryRoot());
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('git ls-files の実行に失敗しました: '.$process->getErrorOutput());
        }
        // \0 分割・空要素除去・is_file() 確認
    }
}
```

```php
/** 走査対象 1 ファイル。 */
final readonly class ScannedFile
{
    public function __construct(
        public string $root,        // '.github' 等
        public string $relative,    // 'scripts/ci/drop-test-db.php'
        public string $contents,    // UTF-8 検証済み
        public bool $isPhp,         // '.php' で終わり '.blade.php' でない
        public ?string $extension,  // 拡張子なしは null
    ) {}
}

/** 実走査母集団 + 未解決 + バイナリ除外。 */
final readonly class ScanPopulation
{
    /**
     * @param list<ScannedFile>        $files
     * @param array<string, string>    $unresolved  相対パス => 理由 (読み取り失敗 / UTF-8 不正)
     * @param list<string>             $binaryExcluded
     */
    public function __construct(
        public array $files,
        public array $unresolved,
        public array $binaryExcluded,
    ) {}

    /** @return list<ScannedFile> */
    public function php(): array { /* isPhp === true */ }

    /** @return list<ScannedFile> */
    public function nonPhp(): array { /* isPhp === false */ }

    /** @return list<ScannedFile> */
    public function inRoot(string $root): array { /* root 一致 */ }
}
```

### PHPStan 適合チェック
- [x] 戻り値の型が明示されている（`array<string, string>` / `list<ScannedFile>` / `ScanPopulation`）
- [x] null 安全（`realpath()` の `false` を `is_string()` で分岐して例外にする。`Assert` は不要な箇所で使わない）
- [x] 値オブジェクトを返している（配列の生返しをしない）
- [x] Generics の型パラメータが正しい（`list<>` / `array<string, string>` を明示）

### テスト計画
- [ ] 新規テスト（S5 内）: 各走査根の**実走査母集団が 1 件以上**
- [ ] 新規テスト（S5 内）: `scripts/` の実走査母集団に**拡張子なし 1 件以上**かつ **`.sh` 1 件以上**
- [ ] 新規テスト（S5 内）: `.github/workflows/` の実走査母集団に **YAML 1 件以上**
- [ ] 新規テスト（S5 内）: `php()` と `nonPhp()` が**それぞれ 1 件以上**
- [ ] 新規テスト（S5 内）: `unresolved` が空であること（1 件でもあれば gate 失敗）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認（DB 不使用）

### リスク
- `git ls-files` が使えない環境では例外になる（`TrackedPhpSourceFiles` と同じ fail-open 防止の方針。CI/commit 境界では必ず追跡下にある）。
- 走査根 8 本 × 全ファイル（実測 1,157 本）の読み取りが 2 gate で走る。**母集団は 1 回だけ確定して gate 内で共有**し、二重読み取りを避ける。

---

## S3. 走査器（形だけを返す。ポリシーを持たない）

### 変更箇所
- 新設: `tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php` ほか値オブジェクト

### 設計の骨子

**走査器は許可ポリシーを持たない**。出現とその**構文上の形**だけを返し、どれを許すかは gate が決める（撤去語ごとに妥当な許可形が違うため）。

**語の境界判定（AGENTS.md (e)。区切りを宣言し、非対称にする）**

```php
/** 撤去語の**直後**が継続文字ならば一致としない。`.` を含む。 */
private const string CONTINUATION_AFTER = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.-';

/** 撤去語の**直前**が継続文字ならば一致としない。**`.` を含めない**。 */
private const string CONTINUATION_BEFORE = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';
```

**非対称にする理由**（docblock に書く）: 直後に `.` を含めるのは `password.confirm.store` / `password.confirmation` を巻き込まないため。直前から `.` を外すのは、`config.password.confirm` のような**同一語への到達路**を一致として拾うためであり、`auth.password_confirmed_at` のような**別語の一部**は直後の `_` で既に落ちる。

**正規表現を使わない**: 一致は `strpos()` のオフセット走査 + 前後 1 文字の集合判定で行う。正規表現の語境界に頼らない（AGENTS.md (e)）ことに加え、`PcreUnicodeModifierGateTest` が課す修飾子規約の対象を増やさないためでもある。

### 公開 API

```php
/**
 * 撤去語の出現と**構文上の形**だけを返す純関数群 (許可ポリシーを持たない)。
 *
 * ★語境界は上記の継続文字集合で判定する (非対称。理由は定数の docblock)。
 * ★**保証しないもの**: 撤去語を分割して連結する書き方・定数経由の参照・
 *   実行時に組み立てた文字列には沈黙する。PHP のコメント / docblock の中でも沈黙する
 *   (そこに middleware 登録や宣言は書けないため、保護対象の操作を書ける構文ではない)。
 * ★解決できない形は**未解決として分けて返す** (空配列へ混ぜない)。走査中の例外も
 *   「一致しなかった」へ落とさない。
 */
final class RemovedSurfaceScanner
{
    /**
     * Tier 2: 非 PHP の生テキスト走査。
     *
     * @param  list<ScannedFile>  $files
     * @return ScanOutcome<TextOccurrence>
     */
    public static function scanText(array $files, string $term): ScanOutcome;

    /**
     * Tier 1: PHP の**文字列リテラル**走査 (コメント / docblock は `PhpTokenScan::normalize()` が除く)。
     * 各出現について、gate がポリシー判定に使う構文事実を添える。
     *
     * @param  list<ScannedFile>  $files
     * @return ScanOutcome<PhpStringOccurrence>
     */
    public static function scanPhpStringLiterals(array $files, string $term): ScanOutcome;

    /**
     * Tier 1: 指定クラス (完全修飾名) のメソッド宣言と静的呼び出し。
     *
     * ★AGENTS.md (a) に従い、`use` / group use / 別名つき取り込み / 現在の namespace を
     *   解いた**完全修飾名**で突き合わせる。短名一致では判定しない。
     * ★**未解決として gate を失敗させる対象は `::{$method}` を伴うクラス参照だけ**に限る。
     *   `imagesEnabled` を伴わないクラス参照は本 gate の関心事ではないため走査しない
     *   (無関係な動的クラス参照まで走査失敗にすると gate の責務を超える)。
     *
     * @param  list<ScannedFile>  $files
     * @return ScanOutcome<MethodReferenceOccurrence>
     */
    public static function scanMethodReferences(array $files, string $fqcn, string $method): ScanOutcome;
}
```

```php
/**
 * 走査結果。**出現**と**未解決**を型上区別する (未解決を空配列へ混ぜない)。
 *
 * @template TOccurrence of TextOccurrence|PhpStringOccurrence|MethodReferenceOccurrence
 */
final readonly class ScanOutcome
{
    /**
     * @param  list<TOccurrence>     $occurrences
     * @param  array<string, string> $unresolved  相対パス => 理由
     */
    public function __construct(public array $occurrences, public array $unresolved) {}
}

final readonly class TextOccurrence
{
    public function __construct(public string $relative, public int $line) {}
}

/** PHP の文字列リテラル中の出現 + gate がポリシー判定に使う構文事実。 */
final readonly class PhpStringOccurrence
{
    public function __construct(
        public string $relative,
        public int $line,
        public string $literal,          // 出現を含む文字列リテラルの中身 (引用符を除く)
        public bool $followedByArrow,    // 直後のトークンが `=>` か (キー位置か)
        public ?string $valueLiteral,    // `=>` の直後が単独の文字列リテラルならその中身、でなければ null
    ) {}
}

final readonly class MethodReferenceOccurrence
{
    public function __construct(
        public string $relative,
        public int $line,
        public MethodReferenceKind $kind, // Declaration | StaticCall
    ) {}
}
```

### PHPStan 適合チェック
- [x] 戻り値の型が明示（`ScanOutcome<TextOccurrence>` 等の generics を `@template` / `@return` で表す）
- [x] null 安全（`valueLiteral` は `?string` で表し、null 分岐を gate 側で明示）
- [x] 値オブジェクトを返す（配列返却なし）
- [x] Generics の型パラメータが正しい（`ScanOutcome` に `@template TOccurrence of …` を付ける）

### テスト計画
- [ ] 自己検証は S5 / S6 の gate 内に置く（正典 v1 が「同ファイルに持つ」ことを求めるため）
- [ ] テストファーストで**先に赤くする**: 境界判定の分岐（`CONTINUATION_AFTER` から `.` を外す）を一時的に壊し、`password.confirm.store` の負例が赤くなることを確認してから本体を書く

### リスク
- `token_get_all()` は PHP 8.4 の構文しか解けない。将来構文で失敗した場合は**未解決**として gate を落とす（fail-closed）。

---

## S4. A の実行時層を v1 へ強化

### 変更箇所
- ファイル: `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php`（既存 44 行。**名前を変えず・既存 test を消さず**層を足す）

### 波及変更
- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 本ファイルのみ（`PasskeyPackageContractTest` / `PasskeyRouteProtectionTest` は**変更しない**。役割の違いを docblock に書くだけ）

### 現行コード

```php
test('password.confirm middleware を持つ route が 1 本も無い', function (): void {
    $violations = [];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $checked++;
        foreach ($route->gatherMiddleware() as $middleware) { /* … */ }
    }

    expect($violations)->toBe([], '…');
    // route 走査自体が空振りしていないこと
    expect($checked)->toBeGreaterThan(0);
});
```

### 変更後コード（要点）

docblock に**観測軸の対応表**（該当なしの軸とその理由）と、`PasskeyPackageContractTest` との役割分担を追記する。そのうえで:

```php
test('password.confirm middleware を持つ route が 1 本も無い', function (): void {
    $violations = [];
    $checked = 0;
    $routesWithStringMiddleware = 0;   // ★追加: 空振り検出の強化

    foreach (Route::getRoutes() as $route) {
        $checked++;
        $hasStringMiddleware = false;

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            $hasStringMiddleware = true;
            if ($middleware === 'password.confirm' || str_starts_with($middleware, 'password.confirm:')) {
                $violations[] = $route->getName() ?? implode('|', $route->methods()).':'.$route->uri();
            }
        }

        if ($hasStringMiddleware) {
            $routesWithStringMiddleware++;
        }
    }

    expect($violations)->toBe([], '…（既存の文言を維持）');
    expect($checked)->toBeGreaterThan(0);
    // ★ middleware 解決自体が壊れて全 route が空を返す形で緑になるのを防ぐ
    expect($routesWithStringMiddleware)->toBeGreaterThan(0);
});
```

```php
/**
 * 層 2: 再有効化スイッチの**既定拒否**。
 * キーを名指しせず設定木から**生成**するので、依存パッケージ更新で
 * 新しい confirmPassword キーが増えたら既定で赤くなる。
 */
test('confirmPassword の設定キーは生成した母集団のうえで全件 false', function (): void {
    $trees = ['fortify' => config('fortify'), 'fortify-options' => config('fortify-options')];

    $found = [];   // 'fortify-options.passkeys.confirmPassword' => mixed
    foreach ($trees as $name => $tree) {
        // ★配列でなければ fail-closed
        expect($tree)->toBeArray("設定木 {$name} を配列として解決できない");
        collectConfirmPasswordKeys($tree, $name, $found);   // 再帰。キー名の完全一致のみ
    }

    // ★母集団が空なのに緑になる形を作らない (実測 2 件を下限に pin)
    expect(count($found))->toBeGreaterThanOrEqual(2);

    $enabled = array_keys(array_filter($found, static fn (mixed $v): bool => $v !== false));
    expect($enabled)->toBe([], 'confirmPassword が false 以外: '.implode(', ', $enabled));
});
```

```php
/**
 * 層 3: **消しすぎていない**ことの確認。
 * 置換先 generic recent-auth が生きていなければ、撤去は「詰み」を作っただけになる。
 */
test('置換先の generic recent-auth が生きている', function (): void {
    expect(Route::has('recent-auth.confirm'))->toBeTrue();
    expect(Route::has('recent-auth.password'))->toBeTrue();

    $guarded = 0;
    foreach (Route::getRoutes() as $route) {
        if (in_array(RequireRecentAuth::class, $route->gatherMiddleware(), true)) {
            $guarded++;
        }
    }
    expect($guarded)->toBeGreaterThan(0, 'recent-auth を実際に適用している route が 1 本も無い');
});
```

> **注**: `collectConfirmPasswordKeys()` は Pest のファイルスコープ関数の衝突を避けるため、本ファイル固有の名前にする（既存 `retiredRecoveryCommandNames()` などと同じ流儀）。`mixed` の受け取りは PHPStan level 10 で問題ないよう `array<array-key, mixed>` を明示する。

### PHPStan 適合チェック
- [x] 戻り値の型が明示（`void` / `array<string, mixed>`）
- [x] null 安全（`config()` の戻りが配列でない場合を先に落とす）
- [x] 配列の生返しは補助関数の内部に閉じ、参照渡しの out 引数へ `array<string, mixed>` を注釈
- [x] Generics（`in_array()` の第 3 引数 `true` で厳密比較）

### テスト計画
- [ ] 既存テスト `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php` の更新（**削除・上書きはしない**。既存 test 名と assertion を保ったまま層を足す）
- [ ] 新規テスト: `confirmPassword の設定キーは生成した母集団のうえで全件 false` — 母集団下限 2 件と厳密 `false` を検証
- [ ] 新規テスト: `置換先の generic recent-auth が生きている` — route 2 本の実在と `RequireRecentAuth` 適用 route の非空
- [ ] **先に赤くする**: `config/fortify.php` の `confirmPassword` を一時的に `true` にして層 2 が赤くなることを確認してから戻す
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 層 2 の下限 2 件は、Fortify / laravel-passkeys の更新でキーが**減った**ときにも赤くなる。これは意図した挙動（撤去物の再有効化スイッチが見えなくなったことに気付ける）であり、減った場合は下限と理由を同じ PR で更新する。
- 層 3 の `RequireRecentAuth::class` は `gatherMiddleware()` がクラス名を返す形に依存する。alias（`'recent-auth'`）で返る可能性があるため、**クラス名と alias の両方**を許容する実装にする。

---

## S5. A の静的層 + 自己検証

### 変更箇所
- 新設: `tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php`

### 設計

**A の許可ポリシー（これ以外の出現はすべて違反）**: Tier 1 で次の **4 条件の連言**を満たす形 1 種のみ。

1. `followedByArrow === true`（キー位置）
2. `valueLiteral !== null`（`=>` の直後が単独の文字列リテラル。`::class` / 配列 / 定数はここで落ちる）
3. `valueLiteral` が**クラス名らしい形でない**こと。判定は**字句形だけ**で行い `class_exists()` を使わない（任意の文字列を autoload させると gate の結果が autoload 可能性に依存し、autoload 中の例外という新しい未解決ケースを抱えるため）。次のいずれかに当たれば違反:
   - namespace 区切り `\` を含む
   - 全体が PascalCase の識別子（`^[A-Z][A-Za-z0-9_]*$`）
   - `::` を含む
4. **撤去語を含むキー文字列**（`literal`）が、**実際に登録されている route の名前と完全一致**する（`Route::getRoutes()` の名前集合と突き合わせる）

条件 4 により、Fortify が同名 route を登録しなくなった時点で**許可も自動的に消える**（許可が腐らない）。実測でこの形に当たるのは `config/seo.php` の 1 件だけ。`route(` / `->name(` 形の許可は実測の該当が 0 件のため**宣言しない**。

**保証の表現（断定しない）**: この形について主張するのは「**登録済み route 名をキーとする文字列値の対応表の形である**」ことまでで、「見出し文字列である」とは断定しない。

**Tier 2 は許可形なし（0 件固定）。**

### テスト構成

```php
/*
 * 撤去した Fortify 標準 step-up 機構 (`password.confirm` middleware) の
 * **参照の再流入**を字句で止める gate (家系正典 surface-removal-absence-gate v1)。
 *
 * ★走査対象: Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets の走査根 8 本
 *   (.github / app / bootstrap / config / lang / resources / routes / scripts) の
 *   git 追跡下の全ファイル。database/migrations は含めない (理由は同クラスの docblock)。
 * ★Tier 1 (PHP): コメント / docblock を除いたトークン列の**文字列リテラル**だけを見る。
 *   許可形は「登録済み route 名をキーとする文字列値の対応表の形」1 種のみ (4 条件の連言)。
 *   **場所の許可一覧は持たない**。
 * ★Tier 2 (非 PHP 全ファイル): **許可形なし**の 0 件固定。
 * ★**保証しないもの**: 分割連結・定数経由・動的組み立て・PHP のコメント内・
 *   NUL を含むバイナリには沈黙する。
 * ★実行時の不在は PasswordConfirmMiddlewareAbsenceTest が別に固定する (層が違う)。
 * ★自己検証は本ファイル下部の「検出器の自己検証」節が持つ
 *   (見本: tests/Architecture/fixtures/surface-removal/password-confirm/)。
 */
```

| test | 内容 |
|---|---|
| `走査根がすべて解決でき、実走査母集団が空でない` | 各根の実走査母集団 ≥ 1 / `php()` ≥ 1 / `nonPhp()` ≥ 1 |
| `scripts と .github の実走査母集団に期待する種別が含まれる` | `scripts/` に拡張子なし ≥ 1 かつ `.sh` ≥ 1 / `.github/workflows/` に YAML ≥ 1 |
| `読み取り・UTF-8 検証で未解決になったファイルが 1 件も無い` | `ScanPopulation::$unresolved` が空 |
| `非 PHP に password.confirm が 1 件も無い` | Tier 2 の 0 件固定 |
| `PHP の password.confirm は登録済み route 名の見出し対応表の形だけ` | Tier 1 の 4 条件。違反 0 件 |
| `検出器の自己検証: 正例をすべて検出する` | 見本の正例 17 種すべてが違反として返ること。**見本が検索語を実際に含むことを先に assert** |
| `検出器の自己検証: 負例に反応しない` | 見本の負例（接頭辞 / 接尾辞 / 打ち消し / 許可形 / PHP コメント / バイナリ）で違反 0 件 |
| `検出器の自己検証: 未登録 route 名をキーにした形は許可されない` | 登録済み route 名の集合を**引数で差し替えて**評価し、条件 4 が効くことを固定 |

> **条件 4 の自己検証のやり方**: 「登録済み route 名の集合」は gate 内の純関数へ**引数**として渡す設計にする（`Route::getRoutes()` を関数の中で直接呼ばない）。これにより、自己検証では空集合や別集合を渡して条件 4 の効きを確かめられる。本番の検査は `Route::getRoutes()` から作った集合を渡す。

### PHPStan 適合チェック
- [x] 戻り値の型が明示（判定関数は `list<string>` の違反説明を返す）
- [x] null 安全（`valueLiteral` の null 分岐を明示）
- [x] 値オブジェクト経由（走査器の戻り値を配列に潰さない）
- [x] Generics（`ScanOutcome<PhpStringOccurrence>` を受ける型注釈）

### テスト計画
- [ ] 新規テスト: 上表の 8 本
- [ ] **先に赤くする**: 条件 3（クラス名らしい形の拒否）を一時的に外し、`positive-value-namespaced-class.php.txt` が通ってしまうことを確認してから実装
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認（DB 不使用）

### リスク
- Tier 1 が `Route::getRoutes()` に依存するため、route 登録が壊れた環境では条件 4 が空集合になり**許可が消えて赤くなる**。これは fail-closed 側であり許容する。
- 走査コストは 2 gate 合計で全追跡ファイル 2 周。実測 1,157 ファイルで、既存の全数走査 gate（`StrictTypesDeclarationGateTest` 等）と同水準。

---

## S6. B（OCR 機能フラグ）の実行時層 + 静的層 + 自己検証

### 変更箇所
- 新設: `tests/Architecture/OcrFeatureFlagAbsenceGateTest.php`

### 設計

**撤去語と扱い**

| 撤去語 | Tier 1 | Tier 2 | 許可形 |
|---|---|---|---|
| `ocr_analysis_enabled`（設定キー） | 文字列リテラル 0 件 | 0 件 | なし |
| `OCR_ANALYSIS_ENABLED`（env 名） | 文字列リテラル 0 件 | 0 件 | なし |
| `imageSourceDocumentsEnabled`（Inertia prop 名） | 文字列リテラル 0 件 | 0 件 | なし |
| `imagesEnabled`（撤去メソッド） | **FQCN 基準**で宣言形・静的呼び出し形のみ | **完全修飾の参照文字列**のみ 0 件固定。裸の `imagesEnabled` は**検出力を主張しない** | なし |

`imagesEnabled` を素のトークン一致で見ない理由（docblock に書く）: 一般名すぎて、将来 OCR と無関係な同名メソッドが必要になったときに全 production surface を止めてしまう。非 PHP で裸の `imagesEnabled` を見ないのは、非 PHP から実行可能な参照になるにはクラスの完全修飾名が必要だからである。

**実行時層**

```php
test('撤去した OCR フラグの設定キーが設定木に存在しない', function (): void {
    $manual = config('manual');
    expect($manual)->toBeArray('設定木 manual を配列として解決できない');   // fail-closed
    // ★値ではなく**キーの存在**で判定する (null 値で復活しても落ちるように)
    expect(Arr::has($manual, 'ocr_analysis_enabled'))->toBeFalse();
});

test('撤去した imagesEnabled メソッドが実行時に存在しない', function (): void {
    expect(method_exists(AcceptedSourceDocumentTypes::class, 'imagesEnabled'))->toBeFalse();
});
```

**消しすぎていないことの確認**: 常時有効化の帰結（画像 SOP が受理されること）は T242 が残した既存テスト（`AcceptedSourceDocumentTypesTest` の拡張子集合 pin と `SourceDocumentUploadTest`）が固定している。**同じ検査を二重に持たず**、docblock からそれらを指す。

### テスト構成

| test | 内容 |
|---|---|
| `撤去した OCR フラグの設定キーが設定木に存在しない` | `Arr::has()` による**存在**判定。配列でなければ fail-closed |
| `撤去した imagesEnabled メソッドが実行時に存在しない` | `method_exists() === false` |
| `撤去した 3 語が走査根のどこにも残っていない` | Tier 1 / Tier 2 で 0 件固定 |
| `imagesEnabled の宣言と静的呼び出しが対象クラスに 1 件も無い` | FQCN 基準。未解決があれば失敗 |
| `検出器の自己検証: 正例をすべて検出する` | 見本の正例 10 種。**見本が検索語を実際に含むことを先に assert** |
| `検出器の自己検証: 負例に反応しない` | 別クラスの同名宣言 / 別クラスの静的呼び出し / 動的呼び出し / 非 PHP の裸の語 / 接頭辞・接尾辞・打ち消し |
| `検出器の自己検証: 解決できないクラス参照は未解決として失敗させる` | `$cls::imagesEnabled()` と壊れた PHP で `unresolved` が非空になること |

### PHPStan 適合チェック
- [x] 戻り値の型が明示
- [x] null 安全（`config('manual')` の非配列を先に落とす）
- [x] `Arr::has()` の第 1 引数に `array<array-key, mixed>` を渡す型注釈
- [x] Generics 正しい

### テスト計画
- [ ] 新規テスト: 上表の 7 本
- [ ] **先に赤くする**: `config/manual.php` に `'ocr_analysis_enabled' => null` を一時的に足し、存在判定が赤くなることを確認してから戻す（値判定なら通ってしまうことも同時に確認）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認（DB 不使用）

### リスク
- `imagesEnabled` の FQCN 解決は `use` / group use / alias / 現在 namespace の 4 経路を扱う。解決できない `::imagesEnabled` 参照は**未解決として gate を落とす**ため、動的クラス参照（`$cls::imagesEnabled()`）を production surface に書くと赤くなる。実測では該当 0 件であり、書きたくなった時点で設計判断を求めるのは意図した挙動。

---

## S7. Tier 2 を 0 件固定にするためのコメント文言修正

### 変更箇所
- `resources/js/pages/Settings/Security.svelte` L64（ブロックコメント 1 行）
- `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte` L7（ブロックコメント 1 行）

### 波及変更
- TypeScript 型定義: なし（コメントのみ）
- API Resource/DTO: なし
- テストファイル: なし（S5 / S6 が 0 件を要求する前提になる）
- **DESIGN.md / Atomic Design**: 該当なし（描画にも props にも触れない）

### 現行コード

```svelte
     * 注: Fortify の password.confirm は撤去済み (generic recent-auth へ統一)。
```

```svelte
     * 画像・スキャン PDF の OCR 対応は常時有効 (旧 `manual.ocr_analysis_enabled` フラグは
```

### 変更後コード

```svelte
     * 注: Fortify 標準のパスワード確認 step-up は撤去済み (generic recent-auth へ統一)。
```

```svelte
     * 画像・スキャン PDF の OCR 対応は常時有効 (旧 OCR 有効化フラグ (config/manual.php) は
```

### なぜこうするか

Tier 2 で「行頭がコメント記号なら許可」という分類を置くと、`#` が CSS の id セレクタ、`*` が CSS のユニバーサルセレクタや JS の generator になり得て、Svelte は markup / `<script>` / `<style>` の 3 構文領域を持つため、**実行コード中の出現をコメントと誤認して許してしまう**（fail-closed にならない）。拡張子・構文領域ごとのコメント字句解析を自作するより、**許可形を 0 個にして正典 (2) の「許可一覧を持たない（0 件固定）」を字義どおり満たす**ほうが単純で強い。家系の先行実装（`laravel-claude-template:tests/js/architecture/retired-script-name.test.ts`）も「撤去名の文字列を自ファイルに置かない」形を採っている。

**PHP のコメント / docblock は Tier 1 のトークン走査で母集団に入らない**ため、撤去の理由を書いた既存 docblock 10 件（`FortifyServiceProvider` / `config/fortify.php` / `AcceptedSourceDocumentTypes` ほか）は**そのまま残す**。これらは撤去の記録として価値がある。

### PHPStan 適合チェック
- 非該当（Svelte のコメントのみ）

### テスト計画
- [ ] 既存テストの更新: なし（文言はテストに現れない。`pnpm lint` / `pnpm typecheck` / `pnpm build` が通ることで足りる）
- [ ] S5 / S6 の Tier 2 が 0 件になることで結果的に検証される

### リスク
- コメントから正式なキー名が消えることで、grep で辿る手掛かりが弱まる。緩和として、両方のコメントに**参照先（`config/manual.php` / `generic recent-auth`）**を残し、正式名は PHP 側の docblock（Tier 1 の母集団外）に温存する。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 変更はテスト層 6 ファイル（新設 5 + 変更 1）と Svelte のコメント 2 行に閉じており、恒久規約の目録・共有台帳・`routes/` などの**衝突しやすい共有ファイルを触らない**。`docs/template-fingerprints.json` の entries（281 件）にも本設計の変更対象パスは 1 件も含まれない（実測）。並行作業とぶつかる面が小さい |
| 競合リスク | 低。`tests/Support/` 配下は新規ディレクトリ `SurfaceRemoval/` を作るため既存ファイルと衝突しない。唯一の既存変更 `PasswordConfirmMiddlewareAbsenceTest.php` は 44 行の小さなファイルで、直近 3 か月の変更履歴が無い |

## スコープ外（明記）

- **aigenba 形の「撤去項目の台帳から 4 層を機械駆動する」構造**。対象が 2 件の aicue には過大（思考原則 2）。3 件目の撤去物が来たときに再判定する
- **改名残留（`rename-residual-name-gate`）の関心事**。`BughuntNamingResidualTest` はそちらの資産であり触らない
- **棚卸し表の C（滞留回収 T171）の v1 化**。既に静的 gate があり担保がゼロでないため、別 TODO で扱う
- **棚卸し表の D〜G**（`project_role` 列 / worktree-local flock / phantom password / T210・T110）。再流入経路が薄いか、等価の担保が既にある
- **`database/migrations/` の走査**。正典が明文で除外している
- **アプリコードの振る舞いの変更**。変更するのは S7 のコメント 2 行の文言だけ。`docs/` は触らない
- **走査器の索引**（家系先行実装が持つ「走査器の書き方を検査する仕組み」）の新設。AGENTS.md がその新設を再検討する条件を別に定めており、本設計はその条件に当たらない
- **分割連結・定数経由・動的組み立ての検出**。字句走査の原理的な限界であり、保証範囲外として docblock に明記する
- **`docs/TODO.md` の更新**。本設計は設計ファイルの生成のみで、TODO 登録は `/app-todo-add` の責務


---

## 関連する現行コード

### `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php` (S4 の変更対象。現行全文)

```
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * `password.confirm` middleware の **全 route での不在** を deny-by-default で固定する。
 *
 * 本アプリは Fortify 標準の password.confirm (3h 窓・パスワード限定) を撤去し、
 * generic recent-auth (15 分窓・パスワード or 再SSO or パスキー) へ統一している。
 * password.confirm が復活すると:
 *   1. SSO-only ユーザー (password 未設定) がその route で**詰む** (satisfier が無い)
 *   2. confirmPasswordView は recent-auth.confirm への redirect でしかなく
 *      `auth.password_confirmed_at` を満たせないため無限ループになる (bug-hunt F-11)
 *
 * 特に laravel/passkeys は config 既定が `management_middleware = ['password.confirm']` で、
 * `fortify-options.passkeys.confirmPassword` を落とすと即座に復活する。
 */
test('password.confirm middleware を持つ route が 1 本も無い', function (): void {
    $violations = [];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $checked++;

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            if ($middleware === 'password.confirm' || str_starts_with($middleware, 'password.confirm:')) {
                $violations[] = $route->getName() ?? implode('|', $route->methods()).':'.$route->uri();
            }
        }
    }

    expect($violations)->toBe(
        [],
        'password.confirm は generic recent-auth へ置換済み。復活すると SSO-only ユーザーが詰む: '
        .implode(', ', $violations),
    );
    // route 走査自体が空振りしていないこと
    expect($checked)->toBeGreaterThan(0);
});

```

### `tests/Support/PhpTokenScan.php` (S3 が使う既存ヘルパ。全文)

```
<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * PHP ソースの静的走査で共有する `token_get_all()` の正規化 (純関数)。
 *
 * ★同じ正規化を 2 本持たない。`QueuedJobLeaseInventoryTest` (既存) と
 *   `ExternalClientBoundaryScanner` (T126) の両方がここを使う。
 * ★Pest のファイルスコープ関数はテストファイル間で衝突しうるため、
 *   `Tests\Support\QueueLeaseConfig` と同じくクラスの static メソッドへ集約する。
 */
final class PhpTokenScan
{
    /**
     * `token_get_all()` を「空白・コメントを除いた添字連番のリスト」へ正規化する。
     *
     * 単一文字トークン (`{` / `}` / `;` など) は `id => null` で表現し、
     * 行番号は直前トークンの行を引き継ぐ (単一文字トークンは行情報を持たないため)。
     *
     * @return list<array{id: int|null, text: string, line: int}>
     */
    public static function normalize(string $phpSource): array
    {
        $normalized = [];
        foreach (token_get_all($phpSource) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $normalized[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];

                continue;
            }

            $line = $normalized === [] ? 0 : $normalized[count($normalized) - 1]['line'];
            $normalized[] = ['id' => null, 'text' => $token, 'line' => $line];
        }

        return $normalized;
    }
}

```

### `tests/Support/TrackedPhpSourceFiles.php` (S2 の兄弟にあたる既存クラス。全文)

```
<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * git 追跡下の PHP ソースファイル (blade を除く) を列挙する純関数。
 *
 * ★同じ列挙を 2 本持たない。`NoNonCompoundGlobalUseTest` (既存) と
 *   `StrictTypesDeclarationGateTest` の両方がここを使う。
 * ★git 管理下に限ることで vendor/ node_modules/ .claude/worktrees/ storage/ を
 *   **自動的に**除外できる (明示 exclude リストを保守しなくてよい)。
 * ★`*.blade.php` は**規則の段階で母集団に入れない**。blade はテンプレートであり
 *   先頭が PHP コードではない (PHP としては `<?php` より前に出力が始まる) ため、
 *   PHP ソースファイルに課す規約の対象にならない。免除ではなく対象外である。
 * ★**保証しないもの**: (a) 未追跡 (git add 前) のファイルは列挙されない。
 *   gate が守る境界は commit / CI であり、そこでは必ず追跡下にある。
 *   (b) 拡張子が `.php` でない PHP ファイル (`artisan` など) は列挙されない。
 *   (c) git が無い環境では**沈黙して空を返さず例外にする** (fail-open 防止)。
 * ★利用側は「自分が期待する母集団」を必ず pin すること (床値 + 代表パス)。
 *   共用したことで一方の都合の変更が他方の走査域を黙って変えるのを防ぐ。
 */
final class TrackedPhpSourceFiles
{
    /**
     * @param  string  $root  git worktree の root (絶対パス)
     * @return list<array{absolute: string, relative: string}> relative の昇順
     */
    public static function all(string $root): array
    {
        $process = new Process(['git', 'ls-files', '-z', '--', '*.php'], $root);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
                .$process->getErrorOutput()
            );
        }

        $files = [];
        foreach (explode("\0", $process->getOutput()) as $relative) {
            if ($relative === '' || str_ends_with($relative, '.blade.php')) {
                continue;
            }
            $absolute = $root.'/'.$relative;
            if (! is_file($absolute)) {
                continue; // 削除済みだが index に残っている等
            }
            $files[] = ['absolute' => $absolute, 'relative' => $relative];
        }

        usort($files, fn (array $a, array $b): int => strcmp($a['relative'], $b['relative']));

        return $files;
    }
}

```

### `tests/Support/Prompts/PrismDirectDispatchScanner.php` (走査根 fail-fast の準拠実装。冒頭 110 行)

```
<?php

declare(strict_types=1);

namespace Tests\Support\Prompts;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Prism Facade の LLM 系メソッド (`Prism::text()`, `Prism::structured()`, `Prism::stream()`,
 * `Prism::embeddings()`, `Prism::image()`, `Prism::audio()`, `Prism::moderation()`) を
 * 直接呼び出すコードを token ベースで検出する scanner。
 *
 * ★走査根は **`app/` + `routes/` + `database/` + `config/` + `bootstrap/` の 5 本**である
 *   (`routes/` のクロージャや seeder から直呼びできる場所を残さない)。
 *   scanner は `token_get_all` ベースでコメント・docblock・文字列リテラルを無視するため、
 *   `config/` を加えてもコメント中の文字列で偽陽性は出ない。
 *
 * ★`tests/Architecture/PromptGuardrailTest.php` から**移設**した (振る舞い不変)。
 *   Pest の `--parallel` はファイル単位でプロセスを分けるため、テストファイル内の
 *   グローバルクラスは他 gate から参照できない。委譲の生存確認
 *   (`ExternalSeamInventoryTest`) が本クラスを呼ぶため `tests/Support/` へ置く
 *   (`Tests\Support\QueueLeaseConfig` と同じ規律)。
 *
 * 検出アルゴリズム:
 *  - `token_get_all()` で PHP code をトークン化し、コメント / docblock / 文字列リテラル中の
 *    出現は無視する (誤検出防止)。
 *  - `Prism::method(` を `識別子 + T_DOUBLE_COLON + T_STRING(method) + '('` の sequence で判定。
 *  - 識別子が `Prism` 単体 (use alias 経由) または `Prism\Prism\Facades\Prism` (完全修飾名) の
 *    場合のみ facade とみなす。`Foo\Bar\Prism::text(` のような同名別クラスは誤検出しない。
 *  - method 名は case-insensitive 比較 (PHP のメソッド呼び出し仕様に整合)。
 *  - `use ... as alias` / カンマ区切り use も解決する。
 */
final class PrismDirectDispatchScanner
{
    /**
     * ★`moderation` は現行 vendor に無くても deny 側に置く (後から生えたときに黙って通らない)。
     *
     * @var list<string>
     */
    private const array TARGET_METHODS = ['text', 'structured', 'stream', 'embeddings', 'image', 'audio', 'moderation'];

    /**
     * @var list<string> リポジトリルートからの相対パスで指定。テンプレートは allowlist 不要のため空。
     *                   将来正当な理由で直叩きが必要になった場合のみ追加し、理由を明記すること。
     */
    private const array ALLOWED_FILES = [];

    /** 走査根 (リポジトリルートからの相対パス)。 */
    private const array ROOT_DIRECTORIES = ['app', 'routes', 'database', 'config', 'bootstrap'];

    /**
     * 走査根 (相対パス => 絶対パス)。**存在しない根は fail-fast** で落とす
     * (根の移動 / typo で黙って PASS する事故を防ぐ)。
     *
     * @return array<string, string>
     */
    public static function roots(): array
    {
        $repoRoot = dirname(__DIR__, 3);

        $roots = [];
        foreach (self::ROOT_DIRECTORIES as $relative) {
            $absolute = realpath($repoRoot.'/'.$relative);
            if (! is_string($absolute)) {
                throw new RuntimeException("走査根を解決できません: {$relative}");
            }
            $roots[$relative] = $absolute;
        }

        return $roots;
    }

    /**
     * 走査対象ファイル (**空振り防止 / 委譲の生存確認に使う**)。
     *
     * @return list<string> 絶対パス
     */
    public static function scannedFiles(): array
    {
        $files = [];
        foreach (self::roots() as $absolute) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            );
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @return list<string> 違反ファイル (リポジトリルート相対パス)
     */
    public static function findViolations(): array
    {
        $violations = [];
        foreach (self::roots() as $relativeRoot => $absoluteRoot) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS),
```

### 実測データ (設計の前提)

- 走査根 8 本 (`.github app bootstrap config lang resources routes scripts`) の git 追跡ファイル: php 925 / svelte 123 / ts 67 / sh 15 / yaml 5 / gitkeep 4 / css 3 / yml 2 / py 2 / txt 1 / md 1 / json 1 / gitignore 1 / **拡張子なし 4** (`scripts/codex` `scripts/claude` `scripts/claude-account` `scripts/claude-statusline`)。合計 1,157 本。NUL を含むファイルは 0 件
- `password.confirm` の厳密トークン一致は走査根全体で 12 件 = PHP のコメント/docblock 10 件 + `config/seo.php:90` の配列キー 1 件 (`'password.confirm' => 'パスワードの確認'`) + `resources/js/pages/Settings/Security.svelte:64` のブロックコメント 1 件。`.github/` と `scripts/` は 0 件
- 登録済み route (実測): `GET user/confirm-password` = `password.confirm`、`POST user/confirm-password` = `password.confirm.store`、`GET user/confirmed-password-status` = `password.confirmation`。`recent-auth.confirm` / `recent-auth.password` も登録済み
- `confirmPassword` キーの設定木実測: `fortify-options.two-factor-authentication.confirmPassword => false` / `fortify-options.passkeys.confirmPassword => false` の 2 件
- `config('manual')` の解決後キーに `ocr_analysis_enabled` は無い (30 キー)。`OCR_ANALYSIS_ENABLED` / `imageSourceDocumentsEnabled` / `imagesEnabled` は走査根全体で 0 件
- `scripts/ci/` に PHP が 3 本 (`drop-test-db.php` / `ensure-test-db.php` / `pgsql_test_conn.php`)
- `docs/template-fingerprints.json` の entries 281 件に、本設計の変更対象パスは 1 件も含まれない
- 既存 fixture の規約: `tests/Architecture/fixtures/global-use/` は `.php.txt` 拡張子 (PHP 全数を対象にする `StrictTypesDeclarationGateTest` に引っ掛からないため)
