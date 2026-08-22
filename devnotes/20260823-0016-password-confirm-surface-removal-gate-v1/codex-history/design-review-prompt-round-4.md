# Round 4: Round 3 指摘への対応

Round 3 の Critical 1 件・Warning 10 件すべてに対応した（反論・見送りは 0 件）。

## 主要な修正

1. **trait 内の `self` / `static` / `parent` を未解決にした**（Critical）。trait のメンバーは利用クラスへ組み込まれ、`self` 等の意味は利用クラスに依存するという PHP の意味論に従う。trait 自身の FQCN へ確定すると `self::imagesEnabled()` を trait に置いて対象クラスが `use` する形が静かに通ってしまう。v1 は trait-use graph を実装しないので、対象メソッド参照と middleware 位置のクラス参照に限り **fail-closed で落とす**。見本 `unresolved-trait-self-call.php.txt` / `unresolved-trait-used-by-target.php.txt` と自己検証テストを追加し、S6 の docblock に役割分担（trait 経由の実混入は実行時層の `method_exists()` が検出する）を明記した
2. **見本の前提検査を検出経路ごとに分けた**。一律の `str_contains()` は大小違い・`self::` 呼び出し・alias / group use の正例で成立しないため、alias 値 / メソッド名（大小無視）/ クラス短名 + namespace 宣言 / `::` + メソッド名 / 撤去語そのまま、の 5 経路別にした
3. **バイナリ見本を hex テキストにした**。`hex2bin()` で復号して `classifyContents()` へ渡す。差分レビューで内容が読める
4. **`isPathInsideRepository()` を純関数として切り出した**。`population()` と自己検証の双方が通る seam になり、接頭辞が偶然一致するだけのパスの負例も置ける
5. **公開 enum を専用ファイルへ置き、施策一覧に追加した**（`ContentClassification.php` / `MiddlewareReferenceKind.php` / `MethodReferenceKind.php`）
6. **保証範囲の表現を S3 / S4 / S5 で統一した** —「実行時層はテスト起動時に実体化した route のみを補完し、環境依存で実体化しない経路は保証しない」
7. **再帰関数の型注釈と診断パスの規則を明記した** — `@param array<array-key, mixed> $tree` / `@param array<string, mixed> $found`、文字列キーは `.`、整数キーは `[0]` で連結
8. **テスト件数の表記をやめ、テスト構成表を正本にした**
9. **正典対応表の施策番号を修正した**（I1 → S4/S6、I2 → S5/S6、I4 → S5/S6、I5 → S4/S6）

## 対応マトリクス

# 対応マトリクス: design-review Round 3

Critical 1 件・Warning 10 件。**すべて対応する**（反論・見送りは 0 件）。

## [Critical] trait 内の `self` / `static` / `parent` を「trait 名 + namespace」へ解決するのは PHP の意味論と一致しない（S3）
- 判断: **対応する**
- 根拠: 完全に正しい。trait のメンバーは利用クラスへ組み込まれるので `self` 等の意味は**利用クラス**に依存する。trait 自身の FQCN へ確定すると誤った解決済み結果になり、`self::imagesEnabled()` を trait に置いて対象クラスが `use` する形で**静かに通ってしまう**（fail-open）。
- 対応内容:
  - **class / enum 内**: `self` → 現在の宣言クラスへ解決。`static` → 現在クラスを保守的候補として扱う。`parent` → `extends` を解ければそれへ、解けなければ未解決
  - **trait 内**: `self` / `static` / `parent` は **trait 利用関係（trait-use graph）を解析しない限り未解決**とする。v1 では trait-use graph を実装しないので、**対象メソッド参照（`::imagesEnabled` / middleware 位置のクラス参照）に限り fail-closed で落とす**
  - この限界を `PhpNameResolver` と両 gate の docblock に明記する
  - 見本を追加する: trait 内の `self::imagesEnabled()`（**未解決**になること）、対象クラスがその trait を `use` する形（同じく未解決）

## [Warning] 「見本が検索語を連続して含むこと」の一律 assert が一部の正例で成立しない（S1）
- 判断: **対応する**
- 根拠: 指摘のとおり。大小違いの正例は canonical 表記を含まず、`self::imagesEnabled()` は対象 FQCN を含まず、alias / group use も参照位置に FQCN が無い。
- 対応内容: 一律の `str_contains()` をやめ、**検出経路ごとの見本前提検査**にする:
  - alias 文字列の正例 → 「alias 値の綴りをその見本が含む」
  - メソッド参照の正例 → 「メソッド名の綴り（大小を無視）をその見本が含む」
  - クラス宣言の正例 → 「クラス短名と namespace 宣言をその見本が含む」
  - `FqcnMethodReference` の正例 → 「`::` とメソッド名をその見本が含む」
  - Tier 2 の正例 → 「撤去語をそのまま含む」

## [Warning] 実バイナリ見本は編集・レビューが難しい（S1）
- 判断: **対応する**
- 対応内容: 実バイトのファイルを置くのをやめ、**hex のテキスト見本を復号して `classifyContents()` へ渡す**方式にする（`binary-with-nul.hex.txt` / `invalid-utf8.hex.txt` / `text-plain.txt`）。見本の生成・レビュー方法（hex を `hex2bin()` で復号する）を設計に明記する。

## [Warning] symlink 判定に自己検証の seam が無い（S2）
- 判断: **対応する**
- 対応内容: 純関数 **`RemovedSurfaceScanTargets::isPathInsideRepository(string $repositoryRoot, string $resolvedTarget): bool`** を切り出し、`population()` と自己検証の**双方がこの関数を使う**形にする。実際の symlink 解決失敗（`realpath()` の `false`）は統合側で `unresolved` になることを併せて固定する。

## [Warning] 公開 enum の配置ファイルが変更ファイル一覧に無い（S2 / S3）
- 判断: **対応する**
- 対応内容: `ContentClassification.php` / `MiddlewareReferenceKind.php` / `MethodReferenceKind.php` を施策一覧の変更ファイルへ追加する。公開型は PSR-4 に沿った**専用ファイル**へ置く。

## [Warning] S3 末尾に「実行時層がその穴を塞ぐ」の断定が残っており S5 と矛盾（S3）
- 判断: **対応する**
- 対応内容: S3 の本文とリスクも次へ統一する。
  > 実行時層はテスト起動時に実体化した route のみを補完し、環境依存で実体化しない経路は保証しない。

## [Warning] S4 の docblock「列挙外からの復活は本テストが捕まえる」も保証過剰（S4）
- 判断: **対応する**
- 対応内容: S5 と同じ限定表現へ揃える。

## [Warning] `collectConfirmPasswordKeys…()` の再帰入力型が不明（下位配列に整数キーがあり得る）（S4）
- 判断: **対応する**
- 対応内容: 再帰引数を `@param array<array-key, mixed> $tree` / `@param array<string, mixed> $found` と注釈する。**数値キーを診断パスへ変換する規則**も明記する（数値キーは `[0]` のように角括弧で連結し、文字列キーは `.` で連結する）。

## [Warning] S5 / S6 のテスト件数表記が表と食い違う（13 本 / 12 本 vs 表 14 本）
- 判断: **対応する**
- 対応内容: **件数表記をやめ、テスト構成表を正本にする**（表を編集するたびに件数を直す運用にしない）。

## [Warning] trait 経由で対象クラスへ `imagesEnabled` が混入する場合の扱いが未定義（S6）
- 判断: **対応する**
- 対応内容: v1 では trait-use graph を扱わないことを docblock に明記し、
  - **対象クラスが trait を `use` している場合、その trait 内の `::imagesEnabled` 参照と `self/static/parent` 参照は未解決として落とす**
  - trait 宣言そのものの `imagesEnabled` は対象クラスの宣言として認識しないが、**実行時層の `method_exists()` が混入を検出する**
  という役割分担を正確に書く。

## [Warning] 正典対応表の施策番号が旧設計のまま（横断）
- 判断: **対応する**
- 対応内容: I1 を `S4（A）/ S6（B）`、I2 を `S5（A）/ S6（B）`、I4 を `S5 / S6`、I5 を `S4 / S6` へ修正する。

## [APPROVE] S7
- 変更なし。


---

## 改訂後の詳細設計書（全文）

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
| I1 | 撤去後の**実行時の不在**を層に分けて固定する（route 名 / メソッド×URI / クラス・表 / 実 HTTP 404 と無副作用） | S4（A）と S6（B）。**該当しない軸は理由つきで宣言**する（下表） |
| I2 | production surface への**参照の再流入を字句で止める** Architecture テスト | S5（A）と S6（B）。Tier 1（PHP トークン）と Tier 2（非 PHP 生テキスト） |
| I3 | 静的層は**許可一覧を持たない（0 件固定）** | **許可形は全 Tier で 0 個**。A の Tier 1 は母集団そのものを「撤去した middleware の適用・登録を表す構文」に定義し、`config/seo.php` の route 名対応表は**最初から母集団に入らない**（除外規則を持たない） |
| I4 | 検出器自身の**自己検証**を正例・負例の両方で持つ | S5 / S6 の各 gate 内に、`tests/Architecture/fixtures/surface-removal/` の見本を使った自己検証を置く（**撤去語ごと**にマトリクスを持つ） |
| I5 | **消しすぎていない**ことの確認層 | S4 の層 3（recent-auth の生存）と S6（画像受理の既存テストを docblock から指す） |
| I6 | 走査根に **`.github/` と `scripts/` を必ず含める** | S2 の `ROOT_DIRECTORIES` に含める。実走査母集団の種別検査で「拡張子なし 1 件以上 / `.sh` 1 件以上 / `workflows/` の YAML 1 件以上」を固定 |
| I7 | **`database/migrations/` は走査根に含めない** | S2 の docblock に理由つきで明記（撤去した表名は移行履歴に必ず残るため原理的に赤くなる） |
| I8 | **母集団の生成・既定拒否**（検査対象の列挙が腐らない） | 走査根は `git ls-files` から生成。再有効化スイッチは設定木から `confirmPassword` キーを**生成**して全件 `false` を要求。空振り検査は**除外・検証後の実走査母集団**に対して行う |
| I9 | 検出力の主張を**誇張しない**（保証範囲を docblock に書く） | 分割連結・定数経由・動的組み立て・PHP コメント内・裸の `imagesEnabled`（非 PHP）には**沈黙する**と明記。A の静的層は**列挙した middleware 位置 M1〜M3 の外は保証外**で、実行時層が**テスト起動時に実体化した route** を補完するが、**環境依存で実体化しない経路までは保証しない**ことまで書く。NUL を含むファイルは母集団に入らないが、**`binaryExcluded === []` を不変条件**にして無言の迂回を塞ぐ |

### 撤去物 × 実行時観測軸（I1 の全軸を埋める）

| 観測軸 | A: `password.confirm` step-up 機構 | B: OCR 機能フラグ |
|---|---|---|
| route 名の不在 | **該当なし**（撤去したのは機構。同名 route 3 本は Fortify が救済 redirect / 状態プローブとして意図的に残す現役資産） | **該当なし**（設定値であり route を持たない） |
| メソッド×URI の不在 | **該当なし**（`user/confirm-password` は現役） | **該当なし** |
| クラス・表の不在 | **該当なし**（機構は vendor 側クラス。aicue が撤去したのは*適用*） | **該当なし**（`AcceptedSourceDocumentTypes` は現役、削除された表も無い） |
| 実 HTTP 404・無副作用 | **該当なし**（同上） | **該当なし** |
| 機構に対応する等価の実行時層（家系解釈 (b)） | **検査する 3 つ**: (i) **`Router::gatherRouteMiddleware()` が返す解決済み middleware**（group 展開・alias 解決・クラス直指定を含む）に `RequirePassword` が 0 件、かつ alias 文字列 `password.confirm` が 0 件 / (ii) **`config()->all()` 全体から生成した** `confirmPassword` 母集団が全件 `false` / (iii) 置換先 recent-auth が生存（同じ解決済み集合で見る） | **検査する 3 つ**: (i) `manual` 設定木に `ocr_analysis_enabled` キーが**存在しない** / (ii) `method_exists(AcceptedSourceDocumentTypes::class, 'imagesEnabled') === false` / (iii) 画像受理は T242 の既存テストが担保（docblock から指す） |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 自己検証の見本を置く | `tests/Architecture/fixtures/surface-removal/**`（新設） | 高（テストファースト） |
| S2 | 走査根の単一出典と実走査母集団 | `tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php`, `ScannedFile.php`, `ScanPopulation.php`, `ContentClassification.php`（新設。公開型は PSR-4 に沿った専用ファイルへ置く） | 高 |
| S3 | 走査器（形だけを返す。ポリシーを持たない） | `tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php`, `RemovedTerm.php`, `TermMatchMode.php`, `Occurrence.php`, `MiddlewareReference.php`, `MiddlewareReferenceKind.php`, `MethodReference.php`, `MethodReferenceKind.php`, `ScanOutcome.php`, `PhpNameResolver.php`（新設。**enum も専用ファイルへ置く**） | 高 |
| S4 | A の実行時層を v1 へ強化 | `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php`（変更） | 高 |
| S5 | A の静的層 + 自己検証 | `tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php`（新設） | 高 |
| S6 | B（OCR フラグ）の実行時層 + 静的層 + 自己検証 | `tests/Architecture/OcrFeatureFlagAbsenceGateTest.php`（新設） | 中 |
| S7 | Tier 2 を 0 件固定にするためのコメント文言修正 | `resources/js/pages/Settings/Security.svelte`, `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte`（コメント各 1 行） | 高（S5/S6 の前提） |

> **Round 2 レビューでの修正（重要）**: 非 PHP の完全修飾参照は、宣言したトークン文字集合 `[A-Za-z0-9_.-]` では `\` と `:` が区切りになるため `ExactRun` では**原理的に一致しない**という指摘を受け、**`TermMatchMode::FqcnMethodReference`（専用トークン文字集合 `[A-Za-z0-9_\\]` + 構文的な `::` 分解 + ASCII 大小無視）を追加**した。あわせて `classifyContents()` の切り出し、symlink 方針、`self` / `static` / `parent` の解決、同じ短名を持つ別クラスの負例を追加した。詳細は `codex-history/design-review-decisions-round-2.md`。
>
> **Round 1 レビューでの根本的な設計変更（重要）**: A の静的層から**許可形を全廃**した。母集団を「文字列 `password.confirm` の全出現」から「**撤去した middleware の適用・登録を表す構文**」へ変え、`config/seo.php` の route 名対応表が**最初から母集団に入らない**ようにした。これにより正典 I3（許可一覧を持たない 0 件固定）と設計が自家撞着しなくなる。詳細は `codex-history/design-review-decisions-round-1.md`。

---

## S1. 自己検証の見本を置く

### 変更箇所
- 新設: `tests/Architecture/fixtures/surface-removal/password-confirm/` と `tests/Architecture/fixtures/surface-removal/ocr-flag/`

### 波及変更
- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: S5 / S6 が本見本を読む

### 拡張子の規約
PHP の見本は既存 `tests/Architecture/fixtures/global-use/` と同じく **`.php.txt`** にする。`StrictTypesDeclarationGateTest` が git 追跡下の PHP 全数を免除なしで対象にするため、違反を意図的に含む見本を `.php` で置くと**無関係な gate が赤くなる**。走査器へは「PHP として扱うか」を**引数で明示**して渡す（拡張子から推定しない）。非 PHP の見本は `.svelte.txt` `.ts.txt` `.css.txt` `.sh.txt` `.yaml.txt` / 拡張子なし相当は `noext.txt`。

### A（`password.confirm` 機構）の見本

**正例（違反として検出されなければならない）**

| ファイル | 内容の要点 | 当たる検出対象 |
|---|---|---|
| `positive-middleware-array.php.txt` | `->middleware(['auth', 'password.confirm'])` | M1 + alias |
| `positive-middleware-arg.php.txt` | `->middleware('password.confirm')` | M1 + alias |
| `positive-middleware-param.php.txt` | `->middleware('password.confirm:web')` | M1 + alias（パラメータ付き） |
| `positive-middleware-class.php.txt` | `->middleware(RequirePassword::class)`（`use Illuminate\Auth\Middleware\RequirePassword;`） | M1 + 実体クラス |
| `positive-middleware-class-fqcn.php.txt` | `->middleware(\Illuminate\Auth\Middleware\RequirePassword::class)` | M1 + 実体クラス |
| `positive-middleware-class-alias.php.txt` | `use Illuminate\Auth\Middleware\RequirePassword as RP;` → `->middleware(RP::class)` | M1 + 別名解決 |
| `positive-middleware-class-groupuse.php.txt` | `use Illuminate\Auth\Middleware\{RequirePassword as RP};` → `->middleware(RP::class)` | M1 + group use 内 alias |
| `positive-middleware-class-relative.php.txt` | `namespace Illuminate\Auth\Middleware; … ->middleware(namespace\RequirePassword::class)` | M1 + `T_NAME_RELATIVE` |
| `positive-middleware-class-case.php.txt` | `->middleware(\illuminate\auth\middleware\requirepassword::class)` | 大小無視の解決 |
| `positive-config-management-middleware.php.txt` | `'management_middleware' => ['password.confirm']` | M2 |
| `positive-kernel-property.php.txt` | `protected array $middlewareGroups = ['web' => ['password.confirm']];` | M3 |
| `positive-alias-registration.php.txt` | `->alias(['password.confirm' => RequirePassword::class])` | M1（alias 登録そのもの） |
| `positive-css-id-selector.css.txt` | `#password.confirm { content: "x"; }` | Tier 2 |
| `positive-css-universal.css.txt` | `* { content: "password.confirm"; }` | Tier 2 |
| `positive-ts-generator.ts.txt` | 行頭 `*` の generator メソッド内に出現 | Tier 2 |
| `positive-svelte-markup.svelte.txt` / `-script` / `-style` | Svelte の 3 構文領域それぞれ | Tier 2 |
| `positive-shell.sh.txt` / `positive-noext.txt` / `positive-workflow.yaml.txt` | `.sh` / 拡張子なし（shebang のみ）/ YAML | Tier 2 |

**負例（反応してはならない）**

| ファイル | 内容の要点 |
|---|---|
| `negative-seo-title-map.php.txt` | `'password.confirm' => 'パスワードの確認'`（middleware 位置ではないので**母集団に入らない**） |
| `negative-route-name-usage.php.txt` | `route('password.confirm')` / `->name('password.confirm')` |
| `negative-suffix.php.txt` | `'password.confirm.store'` / `'password.confirmation'` / `'password.confirmed'`（run 完全一致に失敗） |
| `negative-prefix.php.txt` | `'x-password.confirm'` |
| `negative-negated.php.txt` | `'no-password.confirm'` |
| `negative-session-key.php.txt` | `'auth.password_confirmed_at'` |
| `negative-php-comment.php.txt` | `// password.confirm は撤去済み` と docblock 内の出現（middleware 位置の形を**コメントの中に**書く） |
| `negative-other-middleware-class.php.txt` | `->middleware(\App\Http\Middleware\RequireRecentAuth::class)`（短名も違う別クラス） |
| `negative-same-shortname-import.php.txt` | `use App\Other\RequirePassword;` → `->middleware(RequirePassword::class)`（**同じ短名を持つ別クラス**。短名一致へ退行したら赤くなる） |
| `negative-same-shortname-fqcn.php.txt` | `->middleware(\App\Other\RequirePassword::class)` |
| `negative-alias-to-target-shortname.php.txt` | `use App\Other\Foo as RequirePassword;` → `->middleware(RequirePassword::class)`（**alias を対象と同じ短名へ寄せた形**） |

**未解決（gate を失敗させることの固定）**

| ファイル | 内容の要点 |
|---|---|
| `unresolved-dynamic-middleware-class.php.txt` | `->middleware($cls)` / `->middleware($cls::class)`（middleware 位置のクラス参照を完全修飾名へ解決できない） |
| `unresolved-broken-php.php.txt` | `token_get_all(…, TOKEN_PARSE)` が `ParseError` を投げる PHP |

### B（OCR）の見本

**正例**

| ファイル | 内容の要点 |
|---|---|
| `positive-config-key.php.txt` | `'ocr_analysis_enabled' => true`（文字列リテラル） |
| `positive-config-path.php.txt` | `config('manual.ocr_analysis_enabled')`（`RunSegment` 様式の裏取り） |
| `positive-class-const.php.txt` | `const OCR_ANALYSIS_ENABLED = true;`（`T_STRING` 定数名） |
| `positive-property.php.txt` | `public bool $imageSourceDocumentsEnabled;`（`T_VARIABLE`） |
| `positive-variable.php.txt` | `$ocr_analysis_enabled = true;` |
| `positive-heredoc.php.txt` | heredoc 本体に `imageSourceDocumentsEnabled` |
| `positive-env.sh.txt` | `OCR_ANALYSIS_ENABLED=1` |
| `positive-prop.svelte.txt` | `let { imageSourceDocumentsEnabled } = $props();` |
| `positive-method-declaration.php.txt` | `namespace App\Support\Manual; final class AcceptedSourceDocumentTypes { public static function imagesEnabled(): bool {…} }` |
| `positive-method-declaration-bracketed.php.txt` | `namespace App\Support\Manual { class AcceptedSourceDocumentTypes { … } }`（ブロック形 namespace） |
| `positive-static-call-use.php.txt` | `use App\Support\Manual\AcceptedSourceDocumentTypes;` → `AcceptedSourceDocumentTypes::imagesEnabled()` |
| `positive-static-call-alias.php.txt` | `use … as Types;` → `Types::imagesEnabled()` |
| `positive-static-call-groupuse-alias.php.txt` | `use App\Support\Manual\{AcceptedSourceDocumentTypes as Types};` → `Types::imagesEnabled()` |
| `positive-static-call-fqcn.php.txt` | `\App\Support\Manual\AcceptedSourceDocumentTypes::imagesEnabled()` |
| `positive-static-call-relative.php.txt` | `namespace App\Support\Manual; namespace\AcceptedSourceDocumentTypes::imagesEnabled()` |
| `positive-static-call-same-namespace.php.txt` | `namespace App\Support\Manual;` 内で取り込み無しの `AcceptedSourceDocumentTypes::imagesEnabled()` |
| `positive-self-call.php.txt` | 対象クラス内の `self::imagesEnabled()`（`self` を現在クラスへ解決する） |
| `positive-static-keyword-call.php.txt` | 対象クラス内の `static::imagesEnabled()`（保守的に現在の宣言クラスとして扱う） |
| `positive-case-insensitive.php.txt` | `AcceptedSourceDocumentTypes::IMAGESENABLED()` と `\app\support\manual\AcceptedSourceDocumentTypes::imagesEnabled()` |
| `positive-fqcn-in-text.sh.txt` | 非 PHP に `App\Support\Manual\AcceptedSourceDocumentTypes::imagesEnabled`（`FqcnMethodReference` 様式） |
| `positive-fqcn-leading-backslash.sh.txt` | 先頭 `\` つき `\App\Support\Manual\AcceptedSourceDocumentTypes::imagesEnabled` |
| `positive-fqcn-case.yaml.txt` | ASCII 大小違い `app\support\manual\acceptedsourcedocumenttypes::IMAGESENABLED` |

**負例**

| ファイル | 内容の要点 |
|---|---|
| `negative-other-class-declaration.php.txt` | `namespace App\Other; class Thing { public static function imagesEnabled(): bool {…} }`（短名も違う別クラス） |
| `negative-other-class-static-call.php.txt` | `\App\Other\Thing::imagesEnabled()` |
| `negative-same-shortname-declaration.php.txt` | `namespace App\Other; class AcceptedSourceDocumentTypes { public static function imagesEnabled(): bool {…} }`（**同じ短名を持つ別クラス**の宣言） |
| `negative-same-shortname-static-call.php.txt` | `use App\Other\AcceptedSourceDocumentTypes;` → `AcceptedSourceDocumentTypes::imagesEnabled()` と `\App\Other\AcceptedSourceDocumentTypes::imagesEnabled()` |
| `negative-self-in-other-class.php.txt` | 別クラス内の `self::imagesEnabled()`（`self` が現在クラスへ解決され、対象クラスでないので違反にならない） |
| `negative-target-other-method.php.txt` | `AcceptedSourceDocumentTypes::extensions()`（対象クラスだが別メソッド） |
| `negative-method-suffix.php.txt` | `AcceptedSourceDocumentTypes::imagesEnabledAt()`（メソッド名の接尾辞つき） |
| `negative-fqcn-other-namespace.sh.txt` | 非 PHP の `App\Other\AcceptedSourceDocumentTypes::imagesEnabled`（同じ短名の別 namespace） |
| `negative-fqcn-other-method.sh.txt` | 非 PHP の `App\Support\Manual\AcceptedSourceDocumentTypes::extensions` |
| `negative-fqcn-method-suffix.sh.txt` | 非 PHP の `App\Support\Manual\AcceptedSourceDocumentTypes::imagesEnabledAt` |
| `negative-dynamic-call.php.txt` | `$x->imagesEnabled()`（保証範囲外として沈黙することの固定） |
| `negative-bare-imagesenabled.sh.txt` | 非 PHP の裸の `imagesEnabled` |
| `negative-suffix.php.txt` | `'ocr_analysis_enabled_at'` |
| `negative-prefix.php.txt` | `'legacy_ocr_analysis_enabled'` |
| `negative-negated.php.txt` | `'disable_ocr_analysis_enabled'` |
| `negative-php-comment.php.txt` | コメント / docblock 内の `ocr_analysis_enabled` |

**未解決**

| ファイル | 内容の要点 |
|---|---|
| `unresolved-dynamic-class-static-call.php.txt` | `$cls::imagesEnabled()`（`::imagesEnabled` を伴うクラス参照を解決できない） |
| `unresolved-trait-self-call.php.txt` | **trait 内**の `self::imagesEnabled()`（trait のメンバーは利用クラスへ組み込まれるので `self` の意味が利用クラス依存。v1 は trait-use graph を扱わないため**未解決**） |
| `unresolved-trait-used-by-target.php.txt` | 対象クラスが上の trait を `use` する形（同じく**未解決**） |
| `unresolved-broken-php.php.txt` | `ParseError` を投げる PHP |

### バイナリ判定の見本（S2 の純関数の自己検証用）

**実バイトのファイルは置かない**。編集もレビューも難しいため、**hex のテキスト見本を `hex2bin()` で復号して `classifyContents()` へ渡す**。

| ファイル | 内容 | 期待 |
|---|---|---|
| `binary-with-nul.hex.txt` | NUL バイトを含むバイト列の hex（例: `48690041`） | `classifyContents()` → `Binary` |
| `text-plain.txt` | NUL を含まない正常な UTF-8（そのまま渡す） | `classifyContents()` → `Text` |
| `invalid-utf8.hex.txt` | NUL は無いが UTF-8 として不正なバイト列の hex（例: `48c3286f`） | `classifyContents()` → `InvalidUtf8` |

> **見本の作り方とレビュー方法（設計に明記する）**: hex 文字列は 1 行のテキストで置き、テスト側で空白を除いて `hex2bin()` する。復号に失敗したら見本の破損としてテストを落とす。hex なので差分レビューで内容が読める。

> **Round 1 / Round 2 の指摘への対応**: バイナリ負例を「走査器へ渡す負例」から外し、**母集団側の純関数 `RemovedSurfaceScanTargets::classifyContents()` の自己検証**へ移した（走査器自身は NUL を除外しないため、走査器の負例としては成立しない）。さらに Round 2 の指摘に従い、NUL 判定と UTF-8 検証を**同じ 1 関数**にまとめ、`population()` と自己検証が**必ず同じ経路を通る**ようにした（切り出していないと「不正 UTF-8 が `unresolved` に落ちる」というテスト計画が実装できない）。

### テスト計画
- [ ] 見本自体のテストは持たない（見本は S2 / S5 / S6 の自己検証の入力）
- [ ] **S5 / S6 の自己検証は、見本が壊れて静かに空振りするのを防ぐ前提検査を先に行う**。ただし**一律の `str_contains($contents, $term)` は使わない**（大小違いの正例は canonical 表記を含まず、`self::imagesEnabled()` は対象 FQCN を含まず、alias / group use も参照位置に FQCN が無いため成立しない）。**検出経路ごとの前提検査**にする:
  - alias 文字列の正例 → その見本が **alias 値の綴り**を含む
  - メソッド参照の正例 → その見本が **メソッド名の綴り（ASCII 大小を無視）**を含む
  - クラス宣言の正例 → その見本が **クラス短名**と **namespace 宣言**を含む
  - `FqcnMethodReference` の正例 → その見本が **`::` とメソッド名**を含む
  - Tier 2 の正例 → その見本が **撤去語をそのまま**含む

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
 * ★母集団は**拡張子で絞らない**。`scripts/` には拡張子なしの実行ファイルが 4 本実在し、
 *   拡張子の許可集合方式ではそれらが落ちて上記の事故をそのまま再現する。
 * ★確定は**この 1 経路だけ**で行う (順序を固定する):
 *     git 追跡下の列挙 → 通常ファイルとして読めるか (失敗は unresolved)
 *     → NUL 判定 (含むなら binaryExcluded) → UTF-8 検証 (不正は unresolved)
 *     → 実走査母集団へ登録
 *   **数える集合は本体の検査が実際に走査した集合と同一**である (別に数え直さない)。
 * ★**fail-open を作らない**: git 追跡下にあるのに通常ファイルとして読めないパスを
 *   `continue` で捨てない (削除途中 / 壊れた symlink に撤去語があると検査から消えるため)。
 *   必ず `unresolved` へ理由つきで登録する。
 * ★**バイナリ除外は無言で許容しない**: 利用側 gate は `binaryExcluded === []` を
 *   不変条件にする (NUL を 1 つ入れて静的層を迂回する経路を塞ぐ。実測 0 件)。
 * ★**symlink の方針**: 追跡下のパスが symlink のときは `realpath()` が
 *   リポジトリルート配下へ解決されることを検証し、外へ出るものは `unresolved` にする
 *   (リポジトリ外のファイルを黙って走査対象へ引き込まない)。
 * ★**保証しないもの**: git 未追跡のファイルは列挙しない
 *   (gate が守る境界は commit / CI であり、そこでは必ず追跡下にある)。
 * ★`Tests\Support\TrackedPhpSourceFiles` との関係: あちらは拡張子 `.php` に限った
 *   リポジトリ全体の全数列挙で、本クラスは**同じ作法 (git ls-files) で母集団を
 *   全ファイルへ広げ、走査根を 8 本へ絞った兄弟**である。列挙を 2 本持つのではなく、
 *   対象の定義が違う (docblock 相互参照する)。
 */
final class RemovedSurfaceScanTargets
{
    /** @var list<string> 走査根 (リポジトリルート相対)。 */
    private const array ROOT_DIRECTORIES = [
        '.github', 'app', 'bootstrap', 'config', 'lang', 'resources', 'routes', 'scripts',
    ];

    /**
     * 各根に必ず含まれる代表パス (root 割当 / パス計算の誤りを検出する pin)。
     *
     * @var array<string, string>
     */
    public const array REPRESENTATIVE_PATHS = [
        '.github' => '.github/workflows/ci.yml',
        'app' => 'app/Providers/FortifyServiceProvider.php',
        'bootstrap' => 'bootstrap/app.php',
        'config' => 'config/seo.php',
        'lang' => 'lang/ja/validation.php',
        'resources' => 'resources/js/pages/Settings/Security.svelte',
        'routes' => 'routes/web.php',
        'scripts' => 'scripts/ci/drop-test-db.php',
    ];

    /**
     * 走査根 (相対 => 絶対)。**存在しない根は fail-fast**。
     *
     * @return array<string, string>
     */
    public static function roots(): array
    {
        $repositoryRoot = self::repositoryRoot();
        $roots = [];
        foreach (self::ROOT_DIRECTORIES as $relative) {
            $absolute = realpath($repositoryRoot.'/'.$relative);
            if (! is_string($absolute)) {
                throw new RuntimeException("走査根を解決できません: {$relative}");
            }
            $roots[$relative] = $absolute;
        }

        return $roots;
    }

    /**
     * 解決済みの絶対パスがリポジトリルート配下かどうか (純関数。自己検証の seam)。
     *
     * ★`population()` も自己検証も必ずこの関数を通す。symlink 判定を population() 内へ
     *   閉じ込めると、`git ls-files` の母集団外から確かめる手立てが無くなる。
     */
    public static function isPathInsideRepository(string $repositoryRoot, string $resolvedTarget): bool
    {
        return str_starts_with($resolvedTarget, rtrim($repositoryRoot, '/').'/');
    }

    /**
     * 内容の分類 (純関数。**population() も自己検証も必ずここを通る**)。
     *
     * ★同じ判定を 2 本持たない。NUL 判定と UTF-8 検証を 1 つの入口に閉じることで、
     *   見本 (走査根の外に置く) からも実母集団からも同じ経路で確かめられる。
     */
    public static function classifyContents(string $contents): ContentClassification
    {
        if (str_contains($contents, "\0")) {
            return ContentClassification::Binary;
        }
        if (! mb_check_encoding($contents, 'UTF-8')) {
            return ContentClassification::InvalidUtf8;
        }

        return ContentClassification::Text;
    }

    /** 実走査母集団を確定する (唯一の経路)。 */
    public static function population(): ScanPopulation
    {
        $files = [];
        $unresolved = [];
        $binaryExcluded = [];

        foreach (self::roots() as $root => $_absolute) {
            foreach (self::trackedPaths($root) as $relative) {
                $absolute = self::repositoryRoot().'/'.$relative;

                if (! is_file($absolute)) {
                    // ★ git 追跡下なのに通常ファイルとして無い = 無言で捨てない
                    $unresolved[$relative] = '追跡下だが通常ファイルとして読めない';

                    continue;
                }

                if (is_link($absolute)) {
                    // ★ symlink 先がリポジトリ外なら未解決にする (外のファイルを
                    //   走査対象へ引き込まない / 走査対象から逃がさない)。
                    //   判定は純関数 isPathInsideRepository() を通す (自己検証と同じ経路)。
                    $target = realpath($absolute);
                    if ($target === false
                        || ! self::isPathInsideRepository(self::repositoryRoot(), $target)) {
                        $unresolved[$relative] = 'symlink がリポジトリ外へ解決される';

                        continue;
                    }
                }

                $contents = @file_get_contents($absolute);
                if ($contents === false) {
                    $unresolved[$relative] = 'ファイルの読み取りに失敗';

                    continue;
                }

                // ★分類は必ず classifyContents() を通す (自己検証と同じ経路)
                $classification = self::classifyContents($contents);
                if ($classification === ContentClassification::Binary) {
                    $binaryExcluded[] = $relative;

                    continue;
                }
                if ($classification === ContentClassification::InvalidUtf8) {
                    $unresolved[$relative] = 'UTF-8 として不正';

                    continue;
                }

                $files[] = new ScannedFile(
                    root: $root,
                    relative: $relative,
                    contents: $contents,
                    isPhp: str_ends_with($relative, '.php') && ! str_ends_with($relative, '.blade.php'),
                    extension: self::extensionOf($relative),
                );
            }
        }

        return new ScanPopulation($files, $unresolved, $binaryExcluded);
    }

    /** @return list<string> git 追跡下の相対パス (root 配下) */
    private static function trackedPaths(string $root): array
    {
        $process = new Process(['git', 'ls-files', '-z', '--', $root], self::repositoryRoot());
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('git ls-files の実行に失敗しました: '.$process->getErrorOutput());
        }
        // \0 分割 + 空要素除去。is_file() 判定は population() 側で行う (捨てずに unresolved へ入れるため)
    }
}
```

```php
/** 内容の分類 (バイナリ判定と UTF-8 検証の単一出典)。 */
enum ContentClassification { case Text; case Binary; case InvalidUtf8; }

/** 走査対象 1 ファイル。 */
final readonly class ScannedFile
{
    public function __construct(
        public string $root,       // '.github' 等
        public string $relative,   // 'scripts/ci/drop-test-db.php'
        public string $contents,   // NUL なし・UTF-8 検証済み
        public bool $isPhp,        // '.php' で終わり '.blade.php' でない
        public ?string $extension, // 拡張子なしは null
    ) {}
}

/** 実走査母集団 + 未解決 + バイナリ除外。 */
final readonly class ScanPopulation
{
    /**
     * @param  list<ScannedFile>     $files
     * @param  array<string, string> $unresolved      相対パス => 理由
     * @param  list<string>          $binaryExcluded
     */
    public function __construct(
        public array $files,
        public array $unresolved,
        public array $binaryExcluded,
    ) {}

    /** @return list<ScannedFile> */
    public function php(): array;

    /** @return list<ScannedFile> */
    public function nonPhp(): array;

    /** @return list<ScannedFile> */
    public function inRoot(string $root): array;

    /** @return list<string> */
    public function relativePaths(): array;
}
```

### PHPStan 適合チェック
- [x] 戻り値の型が明示（`array<string, string>` / `list<ScannedFile>` / `ScanPopulation` / `bool`）
- [x] null 安全（`realpath()` の `false` を `is_string()` で分岐して例外。`file_get_contents()` の `false` を明示分岐）
- [x] 値オブジェクトを返している（配列の生返しをしない）
- [x] Generics の型パラメータが正しい（`list<>` / `array<string, string>` を明示）

### テスト計画（S5 / S6 の gate 内に置く）
- [ ] 各走査根の**実走査母集団が 1 件以上**ある
- [ ] 各走査根の実走査母集団に **`REPRESENTATIVE_PATHS` の代表パスが含まれる**（root 割当・パス計算の誤り検出）
- [ ] `scripts/` の実走査母集団に**拡張子なし 1 件以上**かつ **`.sh` 1 件以上**ある
- [ ] `.github/workflows/` の実走査母集団に **YAML 1 件以上**ある
- [ ] `php()` と `nonPhp()` が**それぞれ 1 件以上**ある
- [ ] `unresolved === []` かつ **`binaryExcluded === []`**
- [ ] `classifyContents()` の自己検証（NUL 入り見本 → `Binary` / UTF-8 不正見本 → `InvalidUtf8` / 通常テキスト見本 → `Text`）。**`population()` と同じ関数を通す**ことで、自己検証と実母集団の経路が切れないことを保証する
- [ ] `isPathInsideRepository()` の自己検証（配下パスで true / 外のパスで false / 接頭辞が偶然一致するだけのパス `{root}-other/x` で false）。`population()` はこの関数を通るので、統合側では「`realpath()` が `false` を返す symlink が `unresolved` に入る」ことを固定する
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認（DB 不使用）

### リスク
- `git ls-files` が使えない環境では例外になる（`TrackedPhpSourceFiles` と同じ fail-open 防止の方針）。
- 走査根 8 本 × 全ファイル（実測 1,157 本）の読み取りが走る。**母集団は 1 回だけ確定して gate 内で共有**し、二重読み取りを避ける（Pest の `beforeAll` 相当ではなく、ファイルスコープの静的キャッシュ関数で持つ）。

---

## S3. 走査器（形だけを返す。ポリシーを持たない）

### 変更箇所
- 新設: `tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php` / `RemovedTerm.php` / `TermMatchMode.php` / `Occurrence.php` / `MiddlewareReference.php` / `MethodReference.php` / `ScanOutcome.php` / `PhpNameResolver.php`

### (1) 語彙一致は「宣言した区切りで分割したトークンの完全一致」で判定する

AGENTS.md (e) に従い、**正規表現の語境界にも素の部分文字列一致にも頼らない**。

```php
/**
 * トークン文字の集合。**これ以外の文字はすべて区切り**である。
 * 生テキストはこの集合の**最長の連なり (run)** へ分割される。
 */
private const string TOKEN_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.-';
```

撤去語ごとに**一致様式**を宣言する:

| 様式 | トークン文字集合 | 判定 | 使う撤去語 |
|---|---|---|---|
| `TermMatchMode::ExactRun` | `[A-Za-z0-9_.-]` | **run 全体**と完全一致（case-sensitive） | `password.confirm` / `imageSourceDocumentsEnabled` / `OCR_ANALYSIS_ENABLED` / `imagesEnabled` |
| `TermMatchMode::RunSegment` | `[A-Za-z0-9_.-]` | run を **`.` で割ったいずれかの segment** と完全一致（case-sensitive） | `ocr_analysis_enabled`（`manual.ocr_analysis_enabled` のような設定パス表記に当てるため） |
| `TermMatchMode::FqcnMethodReference` | `[A-Za-z0-9_\\]`（**`\` を含む**） | **クラス部 + `::` + メソッド名**を構文的に切り出し、先頭 `\` を落として正規化したうえで両方を **ASCII case-insensitive** の完全一致 | `App\Support\Manual\AcceptedSourceDocumentTypes::imagesEnabled`（非 PHP 用） |

> **`FqcnMethodReference` を別様式にする理由（Round 2 の Critical）**: `ExactRun` のトークン文字集合では `\` と `:` が区切りになるため、`App\Support\Manual\AcceptedSourceDocumentTypes::imagesEnabled` は 5 つの run へ割れ、**原理的に一致しない**。専用の文字集合で「名前要素を `\` で連ねたクラス部」→「`::`」→「メソッド名」の順に構文的へ切り出し、完全一致で判定する。PHP のクラス参照として使われる文字列を守る様式なので、**PHP の言語仕様に合わせて大小を無視する**。
> 正例・負例で固定する組み合わせ: 先頭 `\` の有無 / ASCII 大小違い / **同じ短名を持つ別 namespace** / メソッド名の接尾辞つき（`imagesEnabledAt`）/ 対象クラスだが別メソッド / 別クラスだが同じメソッド。

この様式で、次はすべて**完全一致に失敗して除外**される（負例で固定する）:
`password.confirm.store` / `password.confirmation` / `password.confirmed` / `auth.password_confirmed_at` / `x-password.confirm` / `no-password.confirm` / `ocr_analysis_enabled_at` / `legacy_ocr_analysis_enabled` / `disable_ocr_analysis_enabled`。
一方 `password.confirm:web` は `:` が区切りなので run が `password.confirm` になり**一致する**。

**Round 1 で廃止したもの**: 前後で `.` の扱いを変える非対称な継続文字集合。トークン完全一致として説明できないため。副作用として `config.password.confirm` のような「同一語への到達路」は静的層で一致しなくなるが、**実行時層（解決済み middleware の全数走査）が捕まえる**ことを docblock に明記する。

**大小の扱い（宣言する）**: 生テキスト・文字列リテラル・env 名は **case-sensitive**。**PHP のクラス名とメソッド名だけ** ASCII case-insensitive で比較する（PHP の言語仕様に合わせる）。

### (2) PHP は「文字列リテラル」ではなく「lexeme」を見る

```php
/**
 * コメント / docblock を除いた PHP トークン列から、撤去語と突き合わせる **lexeme** を取り出す。
 *
 * 対象トークン:
 *   - T_STRING                     … 識別子・定数名・メソッド名 (const OCR_ANALYSIS_ENABLED)
 *   - T_VARIABLE                   … 先頭の `$` を除いた名前 (public bool $imageSourceDocumentsEnabled)
 *   - T_CONSTANT_ENCAPSED_STRING   … 引用符を除いた値
 *   - T_ENCAPSED_AND_WHITESPACE    … heredoc / nowdoc / 補間文字列の本体
 *   - T_NAME_QUALIFIED / T_NAME_FULLY_QUALIFIED / T_NAME_RELATIVE … 名前
 *
 * ★文字列リテラルだけに限ると `public bool $imageSourceDocumentsEnabled;` や
 *   `const OCR_ANALYSIS_ENABLED = true;` での復活を検出できない (Round 1 の指摘)。
 */
```

### (3) 構文検証を先に行い、壊れた PHP を未解決にする

```php
try {
    token_get_all($file->contents, TOKEN_PARSE);   // ★構文検証のみ (結果は捨てる)
} catch (\ParseError $e) {                         // ★ParseError だけを捕まえる
    $unresolved[$file->relative] = 'PHP のトークン化に失敗: '.$e->getMessage();
    continue;
}
$tokens = PhpTokenScan::normalize($file->contents);  // ★正規化は既存の単一出典を使う
```

> **`ParseError` だけを捕まえる理由**: `TOKEN_PARSE` の失敗は `ParseError` で表現される。親型 `\Error` まで捕まえると、**予期しない実行時障害まで「解析未解決」へ変換**してしまい、本来テストを落とすべき異常が別の意味に化ける。捕まえるのは 1 つに絞る。

**共有 `PhpTokenScan::normalize()` の挙動は変更しない**（既存利用者 `QueuedJobLeaseInventoryTest` / `ExternalClientBoundaryScanner` への波及を避ける）。二重トークン化のコストは PHP 925 本で許容範囲であり、理由を docblock に書く。

### (4) 名前解決（AGENTS.md (a)）

`PhpNameResolver` を新設し、**対応する名前構文を列挙**する:

- `namespace A\B;`（文形）と `namespace A\B { … }`（ブロック形）、**1 ファイル内の複数 namespace**
- `use A\B\C;` / `use A\B\C as D;`
- group use `use A\B\{C, D as E};`
- `T_NAME_FULLY_QUALIFIED`（`\A\B\C`）/ `T_NAME_QUALIFIED`（`A\B\C`）/ `T_NAME_RELATIVE`（`namespace\C`）
- **class / enum の中**:
  - `self` → **現在の宣言クラス**へ解決する
  - `static` → **現在の宣言クラス**を候補として保守的に扱う（遅延静的束縛で別クラスになり得るが、AGENTS.md (b) の「拾いすぎる方向は可、見逃す方向は不可」に従う）
  - `parent` → `extends` の参照を解ければそれへ解決し、**解けなければ未解決**にする
- **trait の中**: `self` / `static` / `parent` は **すべて未解決**にする。trait のメンバーは利用クラスへ組み込まれるため、`self` 等の意味は**利用クラスに依存する**（PHP の意味論）。trait 自身の FQCN へ確定すると誤った解決済み結果になり、`self::imagesEnabled()` を trait に置いて対象クラスが `use` する形が**静かに通ってしまう**（fail-open）。**v1 では trait-use graph を実装しない**ので、対象メソッド参照（`::imagesEnabled`）と middleware 位置のクラス参照に限り **fail-closed で落とす**

**列挙外の構文（動的クラス名 `$cls::` など）は未解決として gate を失敗させる**。「保証対象から外すだけでは保護対象の静的呼び出しを書ける」という Round 1 の指摘に従い、外すのではなく落とす側にする。

**未解決にする対象の限定**（概念レビュー Round 5 の Suggestion）: 未解決として落とすのは
- **middleware 位置に現れるクラス参照**（S5 用）
- **`::{$method}` を伴うクラス参照**（S6 用）
だけに限る。それ以外の無関係なクラス参照は走査対象にしない（gate の責務を超えないため）。

### (5) 公開 API

```php
/**
 * 撤去語の出現と**構文上の形**だけを返す純関数群 (許可ポリシーを持たない)。
 *
 * ★語彙一致は TOKEN_CHARACTERS で分割した run のトークン完全一致で判定する
 *   (正規表現の語境界にも素の部分文字列一致にも頼らない。AGENTS.md (e))。
 * ★クラス参照は完全修飾名 (ASCII 大小無視) で突き合わせる (AGENTS.md (a))。
 * ★**保証しないもの**: 撤去語を分割して連結する書き方・定数経由の参照・
 *   実行時に組み立てた文字列には沈黙する。PHP のコメント / docblock の中でも沈黙する。
 *   NUL を含むファイルは母集団に入らない (S2)。
 * ★解決できない形は**未解決として分けて返す** (空配列へ混ぜない)。走査中の例外も
 *   「一致しなかった」へ落とさない。利用側 gate は必ず unresolved の空を要求すること。
 */
final class RemovedSurfaceScanner
{
    /**
     * Tier 2: 非 PHP の生テキストを run へ分割してトークン完全一致で走査する。
     *
     * @param  list<ScannedFile>  $files
     * @return ScanOutcome<Occurrence>
     */
    public static function scanText(array $files, RemovedTerm $term): ScanOutcome;

    /**
     * Tier 1: PHP の lexeme (識別子・変数・定数・文字列・heredoc・名前) を走査する。
     *
     * @param  list<ScannedFile>  $files
     * @return ScanOutcome<Occurrence>
     */
    public static function scanPhpLexemes(array $files, RemovedTerm $term): ScanOutcome;

    /**
     * Tier 1: **middleware 位置**に現れる alias 文字列 / クラス参照を返す。
     *
     * middleware 位置の定義 (有限。これ以外は母集団に入らない):
     *   M1 呼び出し名が middleware / withoutMiddleware / middlewareGroup /
     *      appendToGroup / prependToGroup / alias の引数 (直接、または引数の配列リテラルの要素)
     *   M2 キー名が `middleware` を部分文字列として含む (ASCII 大小無視) 配列要素の値、
     *      およびその値が配列リテラルならその要素
     *   M3 プロパティ $middleware / $middlewareGroups / $middlewarePriority の初期化配列の要素
     *
     * @param  list<ScannedFile>  $files
     * @return ScanOutcome<MiddlewareReference>
     */
    public static function scanMiddlewarePositions(array $files): ScanOutcome;

    /**
     * Tier 1: 指定クラス (完全修飾名) のメソッド宣言と静的呼び出し。
     *
     * @param  list<ScannedFile>  $files
     * @return ScanOutcome<MethodReference>
     */
    public static function scanMethodReferences(array $files, string $fqcn, string $method): ScanOutcome;
}
```

```php
/** 撤去語 (語そのものと一致様式を 1 つにまとめる)。 */
final readonly class RemovedTerm
{
    public function __construct(public string $term, public TermMatchMode $mode) {}
}

enum TermMatchMode { case ExactRun; case RunSegment; case FqcnMethodReference; }

/** 撤去語の出現 (どこに何行目で出たか)。 */
final readonly class Occurrence
{
    public function __construct(public string $relative, public int $line, public string $matched) {}
}

/** middleware 位置に現れた参照。alias 文字列とクラス参照を区別する。 */
final readonly class MiddlewareReference
{
    public function __construct(
        public string $relative,
        public int $line,
        public MiddlewareReferenceKind $kind, // AliasString | ClassReference
        public string $value,                  // alias 文字列、またはクラスの完全修飾名 (小文字化前の原文)
        public ?string $resolvedFqcn,          // ClassReference のとき解決済み完全修飾名
    ) {}
}

enum MiddlewareReferenceKind { case AliasString; case ClassReference; }

/** 指定クラスのメソッド宣言 / 静的呼び出し。 */
final readonly class MethodReference
{
    public function __construct(
        public string $relative,
        public int $line,
        public MethodReferenceKind $kind, // Declaration | StaticCall
    ) {}
}

enum MethodReferenceKind { case Declaration; case StaticCall; }

/**
 * 走査結果。**出現**と**未解決**を型上区別する (未解決を空配列へ混ぜない)。
 *
 * @template TOccurrence of Occurrence|MiddlewareReference|MethodReference
 */
final readonly class ScanOutcome
{
    /**
     * @param  list<TOccurrence>     $occurrences
     * @param  array<string, string> $unresolved  相対パス => 理由
     */
    public function __construct(public array $occurrences, public array $unresolved) {}
}
```

### PHPStan 適合チェック
- [x] 戻り値の型が明示（`ScanOutcome<Occurrence>` 等を `@template` / `@return` で表す）
- [x] null 安全（`resolvedFqcn` は `?string`。null 分岐を gate 側で明示）
- [x] 値オブジェクトを返す（配列返却なし）
- [x] Generics の型パラメータが正しい（`@template TOccurrence of …`）
- [x] `token_get_all()` の戻り値 `array<int, array{int, string, int}|string>` を正規化前に触らない（正規化は `PhpTokenScan` の既存型に委ねる）

### テスト計画
- [ ] 自己検証は S5 / S6 の gate 内に置く（正典 v1 が「同ファイルに持つ」ことを求めるため）
- [ ] **テストファーストで先に赤くする**: `TermMatchMode::ExactRun` の完全一致を一時的に部分一致へ壊し、`password.confirm.store` の負例が赤くなることを確認してから本体を書く
- [ ] **先に赤くする 2**: `token_get_all(…, TOKEN_PARSE)` の事前検証を外し、`unresolved-broken-php.php.txt` が未解決にならないことを確認してから実装

### リスク
- `token_get_all()` は PHP 8.4 の構文しか解けない。将来構文で失敗した場合は**未解決**として gate を落とす（fail-closed）。
- middleware 位置の列挙 M1〜M3 の外から再導入された場合、静的層は沈黙する。**実行時層はテスト起動時に実体化した route のみを補完し、環境依存で実体化しない経路（production 限定の条件分岐・未実行コード）は保証しない**。この限定表現で両 gate の docblock に相互参照を書く。

---

## S4. A の実行時層を v1 へ強化

### 変更箇所
- ファイル: `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php`（既存 44 行。**名前を変えず・既存 test を消さず**層を足す）

### 波及変更
- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 本ファイルのみ（`PasskeyPackageContractTest` / `PasskeyRouteProtectionTest` は**変更しない**。役割の違いを docblock に書く）

### 変更後コード（要点）

**層 1: 解決済み middleware の全数走査（deny-by-default）**

Round 1 の指摘に従い、`gatherMiddleware()` だけでは group 展開と alias のクラス解決を保証できないため、**`Router::gatherRouteMiddleware(Route $route)`（public。実測で確認）** が返す**解決済み**の middleware 集合を使う。

```php
test('password.confirm middleware を持つ route が 1 本も無い', function (): void {
    /** @var \Illuminate\Routing\Router $router */
    $router = app('router');

    $violations = [];
    $checked = 0;
    $routesWithResolvedMiddleware = 0;

    foreach (Route::getRoutes() as $route) {
        $checked++;

        // (a) alias 文字列そのものの再流入 (alias 登録側の復活を見る)
        foreach ($route->gatherMiddleware() as $declared) {
            if (! is_string($declared)) {
                continue;
            }
            if ($declared === 'password.confirm' || str_starts_with($declared, 'password.confirm:')) {
                $violations[] = 'alias: '.routeLabelForPasswordConfirmGate($route);
            }
        }

        // (b) group 展開・alias 解決・クラス直指定をすべて含む**解決済み**集合
        $resolved = $router->gatherRouteMiddleware($route);
        if ($resolved !== []) {
            $routesWithResolvedMiddleware++;
        }
        foreach ($resolved as $entry) {
            if (! is_string($entry)) {
                continue;   // Closure middleware は名前を持たない
            }
            $class = strtolower(explode(':', $entry, 2)[0]);
            if ($class === strtolower(RequirePassword::class)) {
                $violations[] = 'class: '.routeLabelForPasswordConfirmGate($route);
            }
        }
    }

    expect($violations)->toBe(
        [],
        'password.confirm は generic recent-auth へ置換済み。復活すると SSO-only ユーザーが詰む: '
        .implode(', ', $violations),
    );
    expect($checked)->toBeGreaterThan(0);
    // ★ middleware 解決自体が壊れて全 route が空を返す形で緑になるのを防ぐ
    expect($routesWithResolvedMiddleware)->toBeGreaterThan(0);
});
```

**層 2: 再有効化スイッチの既定拒否（`config()->all()` 全体から母集団を生成）**

```php
test('confirmPassword の設定キーは生成した母集団のうえで全件 false', function (): void {
    // ★ config()->all() は Config Repository の契約上すでに配列。
    //   is_array() を置くと PHPStan が「常に true」の不要条件として報告するため置かない
    //   (mixed 戻りの config('manual') 側とは事情が違う)。要素型だけ局所注釈する。
    /** @var array<string, mixed> $all */
    $all = config()->all();

    /** @var array<string, mixed> $found */
    $found = [];
    collectConfirmPasswordKeysForPasswordConfirmGate($all, '', $found);   // 再帰。キー名の完全一致のみ

    // ★母集団が空なのに緑になる形を作らない (実測 2 件を下限に pin)
    expect(count($found))->toBeGreaterThanOrEqual(2);

    // ★既知の 2 パスが含まれること (パッケージ設定の未ロードを検出する代表値 pin)
    expect(array_keys($found))->toContain('fortify-options.two-factor-authentication.confirmPassword');
    expect(array_keys($found))->toContain('fortify-options.passkeys.confirmPassword');

    $enabled = array_keys(array_filter($found, static fn (mixed $v): bool => $v !== false));
    expect($enabled)->toBe([], 'confirmPassword が false 以外: '.implode(', ', $enabled));
});
```

> **`config()->all()` を使う理由**: `fortify` / `fortify-options` だけを名指しすると、新しい設定ファイルに `confirmPassword` が追加されても検出できない（Round 1 の指摘）。全設定木から**生成**することで「検査対象の列挙が腐らない」（正典 I8）。

**層 3: 消しすぎていないことの確認（同じ解決済み集合で見る）**

```php
test('置換先の generic recent-auth が生きている', function (): void {
    /** @var \Illuminate\Routing\Router $router */
    $router = app('router');

    expect(Route::has('recent-auth.confirm'))->toBeTrue();
    expect(Route::has('recent-auth.password'))->toBeTrue();

    $guarded = 0;
    foreach (Route::getRoutes() as $route) {
        foreach ($router->gatherRouteMiddleware($route) as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            if (strtolower(explode(':', $entry, 2)[0]) === strtolower(RequireRecentAuth::class)) {
                $guarded++;
                break;
            }
        }
    }
    expect($guarded)->toBeGreaterThan(0, 'recent-auth を実際に適用している route が 1 本も無い');
});
```

> **alias 名をハードコードしない**: 解決済み集合を使うので `'recent-auth'` という alias 綴りに依存しない（Round 1 の指摘）。

> **再帰関数の型注釈と診断パスの規則**: 設定木の下位配列には整数キーがあり得るため、再帰引数は
> `@param array<array-key, mixed> $tree` / `@param array<string, mixed> $found` と注釈する
> （`array<string, mixed>` にすると PHPStan level 10 で不整合になる）。診断パスは
> **文字列キーは `.` で連結し、整数キーは `[0]` のように角括弧で連結する**
> （`fortify-options.passkeys.confirmPassword` / `some.list[0].confirmPassword`）。
>
> **補助関数の命名**: Pest のファイルスコープ関数はテストファイル間で衝突しうるため、`routeLabelForPasswordConfirmGate()` / `collectConfirmPasswordKeysForPasswordConfirmGate()` のように本ファイル固有の名前にする（既存 `retiredRecoveryCommandNames()` と同じ流儀）。

### docblock に追記するもの
- 撤去物 × 実行時観測軸の対応表（該当なしの軸とその理由）
- `PasskeyPackageContractTest`（`fortify-options.passkeys.confirmPassword` を**名指しで** pin）との役割分担 — 本テストの層 2 は**生成された母集団**を見る（新しいキーの出現を捕まえる）ので二重化ではない
- **静的層との分担**: `PasswordConfirmSurfaceAbsenceGateTest` が列挙した middleware 位置（M1〜M3）の再流入を止める。**列挙外は静的層の保証外**であり、本テスト（解決済み middleware の全数走査）が**テスト起動時に実体化した全 route について補完する**が、**環境依存で実体化しない経路（production 限定の条件分岐・未実行コード）までは保証しない**

### PHPStan 適合チェック
- [x] 戻り値の型が明示（`void` / `array<string, mixed>`）
- [x] null 安全（`config()->all()` は契約上配列なので `is_array()` を置かず、要素型だけ `array<string, mixed>` を局所注釈する。`mixed` 戻りの `config('manual')` 側は `is_array()` で絞り込む。`expect()->toBeArray()` は型を絞らないため使わない）
- [x] 参照渡しの out 引数へ `array<string, mixed>` を注釈
- [x] `in_array()` を使う箇所は第 3 引数 `true`（本設計では `strtolower()` 比較に置換済み）
- [x] `app('router')` の戻りに `@var \Illuminate\Routing\Router` を明示

### テスト計画
- [ ] 既存テスト `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php` の更新（**削除・上書きはしない**。既存 test 名と assertion の意味を保ったまま層を足す）
- [ ] 新規テスト: `confirmPassword の設定キーは生成した母集団のうえで全件 false`
- [ ] 新規テスト: `置換先の generic recent-auth が生きている`
- [ ] **先に赤くする 1**: `config/fortify.php` の `confirmPassword` を一時的に `true` にして層 2 が赤くなることを確認してから戻す
- [ ] **先に赤くする 2**: 任意の route へ一時的に `->middleware(RequirePassword::class)` を付け、層 1 の (b) が赤くなることを確認してから戻す（alias ではなくクラス直指定を捕まえられることの裏取り）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認（DB 不使用）

### リスク
- 層 2 の下限 2 件は、Fortify / laravel-passkeys の更新でキーが**減った**ときにも赤くなる。これは意図した挙動であり、減った場合は下限と代表値 pin を同じ PR で更新する。
- `config()->all()` の再帰走査は設定木全体（実測で数千ノード）を舐めるが、1 テストにつき 1 回であり実行時間への影響は無視できる。

---

## S5. A の静的層 + 自己検証

### 変更箇所
- 新設: `tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php`

### 検出対象（**許可形なし**。すべて 0 件固定）

Round 1 の指摘に従い、母集団を「文字列 `password.confirm` の全出現」から「**撤去した middleware の適用・登録を表す構文**」へ変えた。`config/seo.php` の route 名対応表は**母集団に入らない**ので、除外規則が要らない。

| # | 検出対象 | 走査 |
|---|---|---|
| D1 | middleware 位置に現れる alias 文字列（`password.confirm` の `ExactRun` 一致、または `password.confirm:` で始まる） | `scanMiddlewarePositions()` の `AliasString` |
| D2 | middleware 位置に現れるクラス参照で、完全修飾名が `Illuminate\Auth\Middleware\RequirePassword` に解決されるもの | `scanMiddlewarePositions()` の `ClassReference` |
| D3 | 非 PHP（Tier 2）の生テキストに現れる `password.confirm`（`ExactRun`） | `scanText()` |

### テスト構成

```php
/*
 * 撤去した Fortify 標準 step-up 機構 (`password.confirm` middleware) の
 * **参照の再流入**を字句で止める gate (家系正典 surface-removal-absence-gate v1)。
 *
 * ★走査対象: Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets の走査根 8 本
 *   (.github / app / bootstrap / config / lang / resources / routes / scripts) の
 *   git 追跡下の全ファイル。database/migrations は含めない (理由は同クラスの docblock)。
 * ★検出対象は「撤去した middleware の**適用・登録を表す構文**」であり、
 *   文字列 `password.confirm` の全出現ではない。したがって
 *   config/seo.php の route 名対応表は**母集団に入らず**、除外規則を持たない。
 *   **許可一覧は 0 個**である。
 * ★middleware 位置の定義 (M1〜M3) は RemovedSurfaceScanner::scanMiddlewarePositions() の
 *   docblock が正本。
 * ★**保証しないもの (検出力を誇張しない)**:
 *   - 列挙した middleware 位置 (M1〜M3) の**外**は**静的層の保証外**である。
 *     実行時層 (PasswordConfirmMiddlewareAbsenceTest。解決済み middleware の全数走査、
 *     deny-by-default) が**テスト起動時に実体化した全 route について補完する**が、
 *     **環境依存で実体化しない経路 (production 限定の条件分岐・未実行コード) までは
 *     保証しない**。
 *   - 分割連結・定数経由・動的組み立て・PHP のコメント内には沈黙する。
 *   - NUL を含むファイルは母集団に入らない (ただし binaryExcluded === [] を要求する)。
 * ★自己検証は本ファイル下部の「検出器の自己検証」節が持つ
 *   (見本: tests/Architecture/fixtures/surface-removal/password-confirm/)。
 */
```

| test | 内容 |
|---|---|
| `走査根がすべて解決でき、実走査母集団が空でない` | 各根 ≥ 1 / `php()` ≥ 1 / `nonPhp()` ≥ 1 |
| `各走査根に代表パスが含まれる` | `REPRESENTATIVE_PATHS` の 8 件が実走査母集団に在る |
| `scripts と .github の実走査母集団に期待する種別が含まれる` | `scripts/` に拡張子なし ≥ 1 かつ `.sh` ≥ 1 / `.github/workflows/` に YAML ≥ 1 |
| `母集団に未解決もバイナリ除外も無い` | `unresolved === []` かつ `binaryExcluded === []` |
| `middleware 位置に password.confirm alias が 1 件も無い` | D1 が 0 件 |
| `middleware 位置に RequirePassword の参照が 1 件も無い` | D2 が 0 件 |
| `非 PHP に password.confirm が 1 件も無い` | D3 が 0 件 |
| `走査で未解決が 1 件も出ていない` | 本 gate が呼んだ**すべての** `ScanOutcome::$unresolved` を 1 つに集めて空を要求（AGENTS.md (d) 対策。共通ヘルパで実装） |
| `検出器の自己検証: 正例をすべて検出する` | 見本の正例をすべて検出。**見本が検索語を実際に含むことを先に assert** |
| `検出器の自己検証: 負例に反応しない` | seo の route 名対応表 / route 名用法 / 接頭辞・接尾辞・打ち消し / session キー / PHP コメント / 別 middleware クラス |
| `検出器の自己検証: 同じ短名を持つ別クラスに反応しない` | `App\Other\RequirePassword` を import / FQCN / alias（対象と同じ短名へ寄せた形を含む）で middleware 位置に置き、**違反にならない**こと（AGENTS.md (a) の裏取り） |
| `検出器の自己検証: 解決できない middleware クラス参照は未解決になる` | `unresolved-dynamic-middleware-class.php.txt` |
| `検出器の自己検証: 壊れた PHP は未解決になる` | `unresolved-broken-php.php.txt` |
| `検出器の自己検証: 内容分類が効く` | `classifyContents()` が NUL 入り見本を `Binary`、UTF-8 不正見本を `InvalidUtf8`、通常テキストを `Text` に分類すること（`population()` と同じ関数） |

> **未解決の取り扱いを構造で担保する**（Round 1 の Warning）: gate ごとに「呼んだ `ScanOutcome` をすべて配列へ積み、最後に `unresolved` の和集合が空であることを要求する」ヘルパ関数を置き、S5 / S6 の**両方**がそれぞれ呼ぶ。集めるだけで判定に使わない出力を作らない（AGENTS.md (d)）。

### PHPStan 適合チェック
- [x] 戻り値の型が明示（判定関数は `list<string>` の違反説明を返す）
- [x] null 安全（`MiddlewareReference::$resolvedFqcn` の null は「未解決へ入る」経路と排他であることを明示）
- [x] 値オブジェクト経由（走査器の戻り値を配列に潰さない）
- [x] Generics（`ScanOutcome<MiddlewareReference>` を受ける型注釈）

### テスト計画
- [ ] 新規テスト: **上のテスト構成表を正本とする**（件数はここに書かない。表を編集するたびに件数を直す運用にしない）
- [ ] **先に赤くする**: `scanMiddlewarePositions()` の M2（キー名が `middleware` を含む配列）を一時的に外し、`positive-config-management-middleware.php.txt` が通ってしまうことを確認してから実装
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認（DB 不使用）

### リスク
- middleware 位置の列挙 M1〜M3 は有限であり、列挙外の再導入には沈黙する。実行時層が**テスト起動時に実体化した route** については補完するが、**production 限定の条件分岐や未実行コードからの再導入は両層を通過し得る**。この限界を両 gate の docblock に明記し、検出力の主張をその範囲に狭める（AGENTS.md (b)）。
- 走査コストは 2 gate 合計で全追跡ファイル 2 周。実測 1,157 ファイルで、既存の全数走査 gate と同水準。

---

## S6. B（OCR 機能フラグ）の実行時層 + 静的層 + 自己検証

### 変更箇所
- 新設: `tests/Architecture/OcrFeatureFlagAbsenceGateTest.php`

### 撤去語と扱い（許可形は**全 Tier で 0 個**）

| 撤去語 | 一致様式 | Tier 1 (PHP lexeme) | Tier 2 (非 PHP) |
|---|---|---|---|
| `ocr_analysis_enabled` | `RunSegment` | 0 件 | 0 件 |
| `OCR_ANALYSIS_ENABLED` | `ExactRun` | 0 件 | 0 件 |
| `imageSourceDocumentsEnabled` | `ExactRun` | 0 件 | 0 件 |
| `imagesEnabled` | — | **FQCN 基準**の宣言形・静的呼び出し形のみ 0 件 | — |
| `App\Support\Manual\AcceptedSourceDocumentTypes::imagesEnabled` | `FqcnMethodReference` | — | 0 件 |

**trait 経由の混入について（docblock に明記する）**: v1 では **trait-use graph を扱わない**。したがって
- trait 宣言そのものの `imagesEnabled` は**対象クラスの宣言として認識しない**
- 対象クラスが trait を `use` している場合、その trait 内の `::imagesEnabled` 参照と `self` / `static` / `parent` 参照は**未解決として落とす**（fail-closed）
- trait 経由で対象クラスへ `imagesEnabled` が実際に混入した場合は、**実行時層の `method_exists()` が検出する**
という役割分担を正確に書く（静的層が検出できると誇張しない）。

`imagesEnabled` を素のトークン一致で見ない理由（docblock に書く）: 一般名すぎて、将来 OCR と無関係な同名メソッドが必要になったときに全 production surface を止めてしまう。非 PHP で裸の `imagesEnabled` を見ないのは、非 PHP から実行可能な参照になるにはクラスの完全修飾名が必要だからである（完全修飾の参照文字列のほうは 0 件固定する）。

### 実行時層

```php
test('撤去した OCR フラグの設定キーが設定木に存在しない', function (): void {
    $manual = config('manual');
    if (! is_array($manual)) {                       // ★ is_array で絞り込む (toBeArray は絞らない)
        throw new RuntimeException('設定木 manual を配列として解決できない');
    }
    // ★値ではなく**キーの存在**で判定する (null 値で復活しても落ちるように)
    expect(Arr::has($manual, 'ocr_analysis_enabled'))->toBeFalse();
});

test('撤去した imagesEnabled メソッドが実行時に存在しない', function (): void {
    expect(method_exists(AcceptedSourceDocumentTypes::class, 'imagesEnabled'))->toBeFalse();
});
```

### 消しすぎていないことの確認（**二重に持たない**）

docblock から既存テストを**正確な test 名まで**指す（Round 1 の Suggestion）:

- `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php`
  - `画像 (jpg/jpeg/png) を含む (常時有効)`
  - `前提の pin: 拡張子集合が現在値ちょうど (ずれたらラベルの見直しが必要)`
- `tests/Feature/Projects/SourceDocumentUploadOcrTest.php`
  - `jpg/png アップロードが成功する`
  - `公開面の一貫性: FormRequest / Service / Inertia Props (create/show) が同じ受理形式 (画像込み) を表す`

### テスト構成

| test | 内容 |
|---|---|
| `撤去した OCR フラグの設定キーが設定木に存在しない` | `Arr::has()` による**存在**判定。非配列は fail-closed |
| `撤去した imagesEnabled メソッドが実行時に存在しない` | `method_exists() === false` |
| `撤去した 3 語が走査根の PHP lexeme に 1 件も無い` | Tier 1（識別子・変数・定数・文字列・heredoc・名前） |
| `撤去した 3 語が走査根の非 PHP に 1 件も無い` | Tier 2 |
| `imagesEnabled の宣言と静的呼び出しが対象クラスに 1 件も無い` | FQCN 基準（ASCII 大小無視） |
| `非 PHP に完全修飾の imagesEnabled 参照が 1 件も無い` | `App\Support\Manual\AcceptedSourceDocumentTypes::imagesEnabled` |
| `母集団に未解決もバイナリ除外も無い` | S5 と同じ不変条件を**S6 自身も**要求（単独実行で fail-closed になるように） |
| `走査で未解決が 1 件も出ていない` | 本 gate が呼んだすべての `ScanOutcome::$unresolved` の和集合が空 |
| `検出器の自己検証: 正例をすべて検出する` | 見本の正例（大小違い / group use alias / `namespace\` / bracketed namespace / heredoc / プロパティ / 定数 / 変数 を含む）。**見本が検索語を実際に含むことを先に assert** |
| `検出器の自己検証: 負例に反応しない` | 別クラスの同名宣言 / 別クラスの静的呼び出し / 動的呼び出し / 非 PHP の裸の語 / 接頭辞・接尾辞・打ち消し / PHP コメント |
| `検出器の自己検証: 同じ短名を持つ別クラスに反応しない` | `App\Other\AcceptedSourceDocumentTypes` の宣言・静的呼び出し（PHP）と `App\Other\AcceptedSourceDocumentTypes::imagesEnabled`（非 PHP）が**違反にならない**こと |
| `検出器の自己検証: FQCN 様式の境界` | 先頭 `\` の有無 / ASCII 大小違い / 対象クラスだが別メソッド / メソッド名の接尾辞つき / 別クラスだが同じメソッド |
| `検出器の自己検証: 解決できないクラス参照は未解決になる` | `$cls::imagesEnabled()` |
| `検出器の自己検証: trait 内の self/static/parent は未解決になる` | trait 内の `self::imagesEnabled()` と、対象クラスがその trait を `use` する形（v1 は trait-use graph を扱わないので fail-closed で落とす） |
| `検出器の自己検証: 壊れた PHP は未解決になる` | `ParseError` を投げる見本 |

### PHPStan 適合チェック
- [x] 戻り値の型が明示
- [x] null 安全（`config('manual')` の非配列を `is_array()` で先に落とす）
- [x] `Arr::has()` の第 1 引数に `array<array-key, mixed>` を渡す型注釈
- [x] Generics 正しい（`ScanOutcome<Occurrence>` / `ScanOutcome<MethodReference>`）

### テスト計画
- [ ] 新規テスト: **上のテスト構成表を正本とする**（件数はここに書かない）
- [ ] **先に赤くする 1**: `config/manual.php` に `'ocr_analysis_enabled' => null` を一時的に足し、存在判定が赤くなることを確認してから戻す（値判定なら通ってしまうことも同時に確認する）
- [ ] **先に赤くする 2**: `AcceptedSourceDocumentTypes` に `imagesEnabled()` を一時的に戻し、実行時層と静的層の**両方**が赤くなることを確認してから消す
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認（DB 不使用）

### リスク
- `imagesEnabled` の FQCN 解決は `use` / group use / alias / `namespace\` / 現在 namespace / ブロック形 namespace を扱う。解決できない `::imagesEnabled` 参照は**未解決として gate を落とす**ため、動的クラス参照（`$cls::imagesEnabled()`）を production surface に書くと赤くなる。実測では該当 0 件であり、書きたくなった時点で設計判断を求めるのは意図した挙動。

---

## S7. Tier 2 を 0 件固定にするためのコメント文言修正

（Round 1 で **APPROVE**。内容は変更しない）

### 変更箇所
- `resources/js/pages/Settings/Security.svelte` L64（ブロックコメント 1 行）
- `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte` L7（ブロックコメント 1 行）

### 波及変更
- TypeScript 型定義: なし（コメントのみ）/ API Resource・DTO: なし / テストファイル: なし
- **DESIGN.md / Atomic Design**: 該当なし（描画にも props にも触れない）

### 現行コード → 変更後コード

```svelte
- * 注: Fortify の password.confirm は撤去済み (generic recent-auth へ統一)。
+ * 注: Fortify 標準のパスワード確認 step-up は撤去済み (generic recent-auth へ統一)。
```

```svelte
- * 画像・スキャン PDF の OCR 対応は常時有効 (旧 `manual.ocr_analysis_enabled` フラグは
+ * 画像・スキャン PDF の OCR 対応は常時有効 (旧 OCR 有効化フラグ (config/manual.php) は
```

### なぜこうするか

Tier 2 で「行頭がコメント記号なら許可」という分類を置くと、`#` が CSS の id セレクタ、`*` が CSS のユニバーサルセレクタや JS の generator になり得て、Svelte は markup / `<script>` / `<style>` の 3 構文領域を持つため、**実行コード中の出現をコメントと誤認して許してしまう**（fail-closed にならない）。拡張子・構文領域ごとのコメント字句解析を自作するより、**許可形を 0 個にして正典 I3 を字義どおり満たす**ほうが単純で強い。家系の先行実装（`laravel-claude-template:tests/js/architecture/retired-script-name.test.ts`）も「撤去名の文字列を自ファイルに置かない」形を採っている。

**PHP のコメント / docblock は Tier 1 のトークン走査で母集団に入らない**ため、撤去の理由を書いた既存 docblock 10 件はそのまま残す。

### PHPStan 適合チェック
- 非該当（Svelte のコメントのみ）

### テスト計画
- [ ] 既存テストの更新: なし（`pnpm lint` / `pnpm typecheck` / `pnpm build` が通ることで足りる）
- [ ] S5 / S6 の Tier 2 が 0 件になることで結果的に検証される

### リスク
- コメントから正式なキー名が消えることで grep の手掛かりが弱まる。緩和として、両方のコメントに**参照先**（`config/manual.php` / `generic recent-auth`）を残し、正式名は PHP 側の docblock（Tier 1 の母集団外）に温存する。

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
