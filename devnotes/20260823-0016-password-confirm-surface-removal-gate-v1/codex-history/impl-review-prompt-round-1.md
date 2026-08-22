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

# system: 実装レビュアー

あなたは Laravel 12 + Svelte 5 + Inertia アプリ「aicue」のコードレビュアーである。
TODO T250「撤去表面の不在 gate を家系正典 v1 の標準形へ揃える」の実装差分をレビューせよ。

## レビュー観点

1. **設計との一致性** — 詳細設計書の施策 S1〜S8 が実装されているか。乖離があるならそれが正当か
2. **正確性** — 走査器の判定ロジック (トークン分割・PHP 名前解決・middleware 位置の切り出し・
   FQCN 一致) に**誤検出 / 見逃し**が無いか。とくに **fail-open (見逃す方向) の穴**を厳しく見よ
3. **PHPStan level 10 適合性** — 型の widen や `@phpstan-ignore` が無いか (実行結果は下記)
4. **DTO / JsonResource パターン** — 本変更はアプリ応答を作らないため非該当
5. **テスト網羅性** — 正例・負例・未解決の三軸が揃っているか。母集団の空振り検査があるか
6. **セキュリティ** — 本変更はテスト層のみ。ただし gate が黙ると保護が外れる経路 (recent-auth への
   置換が巻き戻る / OCR フラグ復活で受理形式が割れる) の検知力が落ちていないか
7. **AGENTS.md「静的検査 (gate) と走査器の共通規約」の 5 条 (a)〜(e)** の適合
   - (a) クラス参照は完全修飾名で突き合わせる
   - (b) 解決できない形は落とす (fail-closed)。保証範囲外は docblock へ明記し検出力を主張しない。
         「違反 0 件」と「母集団 0 件」を区別する
   - (c) 検出力を負例で裏取りする (両方向)
   - (d) 集めた走査結果を判定に使わない形を作らない
   - (e) 語彙一致の否定形は区切り文字で分割したトークンの完全一致で判定する
8. **DESIGN.md 準拠 / Atomic Design 準拠** — 本変更の Svelte 差分は**ブロックコメント 2 行の文言のみ**で
   描画にも props にも触れない。token / 階層への影響は無いはずだが、差分を見て確認せよ
9. **不必要な複雑化** (思考原則 2) — 2 件の撤去物に対して過剰な機構になっていないか

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に**全体判定**を `APPROVED` または `CHANGES_REQUESTED` の 1 語で明記する

---

# user

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
| S8 | 乖離台帳への登録追加と件数 pin の更新 | `docs/template-divergence.md`（D40 の追加）, `tests/Support/TemplateDivergence/LedgerPins.php`（`DIVERGENCE_ENTRY_COUNT` 36 → 37） | 中（実装 PR の最後） |

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
- 新設: `tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php` / `ScannedFile.php` / `ScanPopulation.php` / `ContentClassification.php`（公開型は PSR-4 に沿った専用ファイルへ置く）

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
- 新設: `tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php` / `RemovedTerm.php` / `TermMatchMode.php` / `Occurrence.php` / `MiddlewareReference.php` / `MiddlewareReferenceKind.php` / `MethodReference.php` / `MethodReferenceKind.php` / `ScanOutcome.php` / `PhpNameResolver.php`（**enum も専用ファイルへ置く**）

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

**Round 1 で廃止したもの**: 前後で `.` の扱いを変える非対称な継続文字集合。トークン完全一致として説明できないため。副作用として `config.password.confirm` のような形は**静的層の保証外**になる。実行時層は、それが**テスト起動時に route middleware として実体化した場合のみ**補完する（実体化しない経路までは保証しない）ことを docblock に明記する。

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
| `検出器の自己検証: 正例をすべて検出する` | 見本の正例をすべて検出。**各正例について S1 で定義した検出経路別の前提検査を先に行う**（一律の `str_contains()` は使わない） |
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
| `検出器の自己検証: 正例をすべて検出する` | 見本の正例（大小違い / group use alias / `namespace\` / bracketed namespace / heredoc / プロパティ / 定数 / 変数 を含む）。**各正例について S1 で定義した検出経路別の前提検査を先に行う** |
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

## S8. 乖離台帳への登録追加と件数 pin の更新

### 判定の根拠（乖離台帳の確認段）

`docs/template-fingerprints.json` の `entries`（281 件）を実測で突き合わせた結果:

| 本設計が触るパス | 指紋台帳のキーに在るか |
|---|---|
| `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php` | **無い** |
| `tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php`（新設） | 無い |
| `tests/Architecture/OcrFeatureFlagAbsenceGateTest.php`（新設） | 無い |
| `tests/Support/SurfaceRemoval/**`（新設） | 無い |
| `tests/Architecture/fixtures/surface-removal/**`（新設） | 無い |
| `resources/js/pages/Settings/Security.svelte` | 無い（`resources/` のキーは 0 件） |
| `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte` | 無い |

→ **テンプレートと共有するファイルは 1 つも触らない**。採用時債務一覧（`tests/Support/TemplateDivergence/adoption-debt.tsv`、171 件）にも該当パスは 1 件も無い。したがって「変更したまま債務に残す」の問題は起きず、(1) 採用時の姿へ戻す /(2) テンプレートへ同期 /(3) 意図的逸脱として登録、の三択にも当たらない。

**それでも登録する理由**（「登録するか迷ったら登録する」= 登録簿の記録の原則）:

- 本設計は**テンプレートに無い領域への上積み**である（`tests/Support/SurfaceRemoval/` という共通基盤を新設する）
- しかも**同じ家系正典（`surface-removal-absence-gate` v1）に対する別の形**である。テンプレートは同じ正典を `tests/Architecture/RetiredRecoveryReferenceGateTest.php`（指紋台帳に**在る** = 共有ファイル）と実行時層で満たしており、**撤去 1 件ごとに自ファイル内へ走査を書く形**を採る。本設計は**走査根と走査器を共通基盤へ切り出し、許可ポリシーを撤去物ごとの gate が指定する形**であり、揃え続ける不変条件が生じる
- 先例として D15（strict_types gate の走査域）/ D25（母集団と目録を静的 gate のファイル内に置く）/ D28（生成 CSS 検査の実装形）が、いずれも**gate の作り方の違い**を登録している

### 変更箇所
- `docs/template-divergence.md`: 新規登録 **D40** を追加（番号は再利用しない。実測で使用済みの最大は D39）
- `tests/Support/TemplateDivergence/LedgerPins.php`: `DIVERGENCE_ENTRY_COUNT` を **36 → 37**

### 波及変更
- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: `TemplateDivergenceLedgerFormatTest`（宣言行・見出しの実数・`LedgerPins` の 3 点一致を見る）と `TemplateDivergenceFingerprintTest` が自動で新値を検査する。**テスト側の変更は不要**

### 登録するエントリの内容（D40）

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Support/SurfaceRemoval/`（ディレクトリ） |
| 業務要件起因の説明 | aicue が撤去した表面（Fortify 標準 step-up 機構 / OCR 機能フラグ）はテンプレートには存在しない。撤去物が 2 件あり、走査根の列挙を 2 本持たないために共通基盤へ切り出す必要がある |
| 揃え続ける不変条件と保証機構 | 走査根に `.github/` と `scripts/` を含み `database/migrations/` を含まないこと、実走査母集団が根・種別ごとに非空であること、静的層が許可形を 0 個で保つこと、検出器の自己検証を正例・負例・未解決の三軸で持つこと。`PasswordConfirmSurfaceAbsenceGateTest` と `OcrFeatureFlagAbsenceGateTest` が固定する |
| 再判定の条件 | 3 件目の撤去物が来て aigenba 形（撤去項目の台帳から 4 層を機械駆動する形）へ移すとき。またはテンプレートが同じ共通基盤を取り込んだとき（そのときは上積みを撤去して正典実装へ揃え直す） |
| 決めた日 | 実装 PR の日付 |
| 決めた人 | 開発者 |
| 根拠 | `devnotes/20260823-0016-password-confirm-surface-removal-gate-v1/` と、`/app-todo-add` で採番される T 番号 |
| 状態 | 恒久 |
| 見直し期限 | — |

> **順序の制約**: 台帳の形式検査は **根拠欄の T 番号が `docs/TODO.md` または `docs/TODO-closed.md` に実在すること**を要求する（`TodoLedgerReference::existsIn()`）。したがって S8 は **TODO 登録の後**に行う。実装 PR の最後の施策として実施する。

### PHPStan 適合チェック
- [x] `LedgerPins::DIVERGENCE_ENTRY_COUNT` は `int` 定数の値変更のみ（型は変わらない）

### テスト計画
- [ ] 既存テスト `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が緑（宣言行・見出しの実数・`LedgerPins` の 3 点一致）
- [ ] 既存テスト `tests/Architecture/TemplateDivergenceFingerprintTest.php` が緑
- [ ] **先に赤くする**: `LedgerPins::DIVERGENCE_ENTRY_COUNT` を 36 のままにして形式検査が赤くなることを確認してから 37 へ上げる

### リスク
- `DIVERGENCE_ENTRY_COUNT` は他作業と同じ行を触るため、**唯一の共有ファイル衝突点**になる。実装 PR の最後に実施し、衝突したら両方の登録を残して件数を足し直す。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 変更は**新設の見本群・`tests/Support/SurfaceRemoval/`・Architecture gate 2 本、既存 Architecture test 1 本、Svelte のコメント 2 箇所、乖離台帳の登録 1 件と件数 pin**に限定されており、恒久規約の目録・`routes/` などの**衝突しやすい共有ファイルを触らない**。`docs/template-fingerprints.json` の entries（281 件）に本設計の変更対象パスは 1 件も含まれない（実測。`docs/template-divergence.md` と `tests/Support/TemplateDivergence/LedgerPins.php` も**テンプレート共有ではない**）。並行作業とぶつかる面が小さい |
| 競合リスク | 低〜中。`tests/Support/` 配下は新規ディレクトリ `SurfaceRemoval/` を作るため既存ファイルと衝突しない。唯一の**既存テスト変更**である `PasswordConfirmMiddlewareAbsenceTest.php` は 44 行の小さなファイルで、直近 3 か月の変更履歴が無い。Svelte 2 ファイルのコメント 2 箇所も描画にも props にも触れない。**唯一の衝突しやすい点は S8**（`LedgerPins::DIVERGENCE_ENTRY_COUNT` の 1 行と `docs/template-divergence.md` の末尾追記）で、他の逸脱登録作業と同じ行を触る。実装 PR の**最後**に行い、衝突したら両方の登録を残して件数を足し直す |

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


## 実装で設計から意図的に外した点 (レビューの主対象)

1. **middleware 位置の「クラス参照」を `X::class` 構文に限定した**。
   設計の見本 `unresolved-dynamic-middleware-class.php.txt` は `->middleware($cls)` と
   `->middleware($cls::class)` の両方を「未解決 = gate を落とす」としていたが、
   production に**実在する後付け経路**が bare variable を使っている:
   - `app/Support/Http/RouteMiddlewareBinder.php:154` `$route->middleware($alias);`
   - `app/Support/Http/RouteThrottleBinder.php:134` `$route->middleware('throttle:'.$limiter);`
   - `app/DataTransferObjects/Bughunt/InventoryRouteData.php:45` `'middleware' => $this->middleware,`
   これらを未解決にすると gate が初日から赤くなり、免除で黙らせるしかなくなる (正典 I3 の
   「許可一覧を持たない」に反する)。そこで**規則の段階で**クラス参照を `X::class` 構文に定義し、
   受け手が名前でないもの (`$cls::class`) だけを未解決にした。bare variable / 式は
   母集団に入らない (沈黙する) ことを docblock に明記し、`negative-dynamic-middleware-value.php.txt`
   で「沈黙すること」を固定した。実体化した route は実行時層が補完する。
   **この判断が (b) 「見逃す方向へ倒すのは不可」に照らして許容範囲かを判定してほしい。**

2. **乖離台帳 D40 の対象パスをディレクトリでなくファイル列にした**。
   設計は `tests/Support/SurfaceRemoval/` (ディレクトリ) と書いていたが、台帳の形式検査
   (`DivergenceLedgerRules` TD3) が「ファイルとして実在すること (ディレクトリは対象パスに書けない)」を
   強制するため、代表 5 ファイルを列挙した。

3. **`RemovedSurfaceScanner::textMatches()` を公開 API に足した**。gate が alias 文字列の値を
   絞り込むときに、走査器と**同じ 1 本のトークン一致**を通すため (判定を 2 本持たないため)。

4. **実走査母集団をクラス内で memoize した**。設計は「ファイルスコープの静的キャッシュ関数」と
   書いていたが、2 gate が同じ母集団を共有するには `RemovedSurfaceScanTargets` 側に置くほうが
   出典が 1 つになる。

5. **`unresolved-trait-used-by-target.php.txt` の判定規則**を「対象クラスの宣言が trait を
   取り込んでいたら未解決」と具体化した (設計は「未解決にする」とだけ書いていた)。

## テストファースト (先に赤くしたことの実測)

| 壊した箇所 | 赤くなった検査 |
|---|---|
| `config/fortify.php` の `Features::passkeys(['confirmPassword' => true])` | 実行時層の層 2 (設定キー全件 false) **と** 層 1 の (a) alias / (b) 解決済みクラスの両方 |
| `RemovedSurfaceScanner` の M2 (キー名が middleware を含む配列) を無効化 | 自己検証「正例をすべて検出する」が `positive-config-management-middleware.php.txt` で赤 |
| `TermMatchMode::ExactRun` を run 完全一致から `str_contains` へ弱化 | 自己検証「負例に反応しない」が `password.confirm.store` / `password.confirmation` / `password.confirmed` で赤 |
| `token_get_all(…, TOKEN_PARSE)` の事前検証を外す | 自己検証「壊れた PHP は未解決になる」が赤 |
| `config/manual.php` に `'ocr_analysis_enabled' => null` を追加 | 実行時層「設定キーが設定木に存在しない」が赤 (値判定なら通っていた形) |
| `AcceptedSourceDocumentTypes` に `imagesEnabled()` を復活 | 実行時層 `method_exists` **と** 静的層の宣言検出の両方が赤 |
| `LedgerPins::DIVERGENCE_ENTRY_COUNT` を 36 のまま D40 を追加 | 台帳形式検査 TD12 (3 点一致) が赤 |

## テスト結果

- `composer phpstan` (level 10, 1010 files): **No errors**
- `composer test`: **6454 tests / 6451 passed / 1 failed / 2 skipped / 5 risky**
  - 唯一の失敗は `BughuntSelfTestExecutionTest`「bug-hunt harness の self-test が通ること」で、
    内容は `shard-8 worker (database-media) pid=... は存在するが所有確認できない — kill せず pidfile 保持`。
    本ホストで多数のエージェントが並列稼働しており **PID の再利用による環境起因の flake**。
    単独再実行で 3 tests / 3 passed (緑)。T250 の差分は bug-hunt 関連を 1 行も触らない。
- 新規/変更した gate 単独: `PasswordConfirmMiddlewareAbsenceTest` 3 passed /
  `PasswordConfirmSurfaceAbsenceGateTest` 15 passed / `OcrFeatureFlagAbsenceGateTest` 15 passed /
  `TemplateDivergenceLedgerFormatTest` + `TemplateDivergenceFingerprintTest` 17 passed
- `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm build` /
  `pnpm typecheck:packages` / `pnpm build:packages`: すべて green

## design system 参照 (Svelte 差分の判定用)

本差分の `resources/js` 変更は次の 2 箇所の**ブロックコメント 1 行ずつ**だけである
(描画・props・class・token に一切触れない):

- `resources/js/pages/Settings/Security.svelte` L64
  `注: Fortify の password.confirm は撤去済み` → `注: Fortify 標準のパスワード確認 step-up は撤去済み`
- `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte` L7
  ``旧 `manual.ocr_analysis_enabled` フラグは`` → `旧 OCR 有効化フラグ (config/manual.php) は`

理由: 静的層の Tier 2 (非 PHP の生テキスト) は**許可形を 0 個**にする設計であり、
「行頭がコメント記号なら許可」という分類を置くと `#` が CSS の id セレクタ、`*` が CSS の
ユニバーサルセレクタや JS の generator になり得て、Svelte は markup / script / style の
3 構文領域を持つため実行コード中の出現をコメントと誤認して許してしまう (fail-closed にならない)。

## 実装差分 (git diff)

```diff
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 14198914..0f66f95e 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 36 件
+登録エントリ: 37 件
 
 ## 記録の原則
 
@@ -2259,3 +2259,59 @@ ### 関連
 
 - 実装: `tests/Architecture/PasskeyPackageContractTest.php`
 - 設計: `devnotes/20260821-2015-auth-method-change-notification/`
+
+---
+
+## D40 撤去表面の不在 gate を、走査根と走査器を共通基盤へ切り出した形で持つ
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php` / `tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php` / `tests/Support/SurfaceRemoval/PhpNameResolver.php` / `tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php` / `tests/Architecture/OcrFeatureFlagAbsenceGateTest.php` |
+| 業務要件起因の説明 | aicue が撤去した表面 (Fortify 標準のパスワード確認 step-up 機構 / OCR 機能フラグ) はテンプレートには存在しない。撤去物が 2 件あり、走査根 (`.github` と `scripts` を含む 8 本) の列挙と PHP の名前解決を 2 本持たないために共通基盤へ切り出す必要がある |
+| 揃え続ける不変条件と保証機構 | 走査根に `.github/` と `scripts/` を含み `database/migrations/` を含まないこと、実走査母集団が根・種別ごとに非空で未解決もバイナリ除外も 0 件であること、静的層が許可形を 0 個で保つこと、検出器の自己検証を正例・負例・未解決の三軸で持つこと。`PasswordConfirmSurfaceAbsenceGateTest` と `OcrFeatureFlagAbsenceGateTest` が固定する |
+| 再判定の条件 | 3 件目の撤去物が来て、撤去項目の台帳から層を機械駆動する形へ移すとき。またはテンプレートが同じ共通基盤を取り込んだとき (そのときは上積みを撤去して正典実装へ揃え直す) |
+| 決めた日 | 2026-08-22 |
+| 決めた人 | 開発者 |
+| 根拠 | T250 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 走査根の持ち方 | 撤去 1 件ごとに gate 自身のファイル内へ走査を書く (`RetiredRecoveryReferenceGateTest`) | 走査根と走査器を `tests/Support/SurfaceRemoval/` へ切り出し、許可ポリシーは撤去物ごとの gate が指定する |
+| 名前の突合 | 語彙一致中心 | クラス参照は完全修飾名へ解決してから突合する (`PhpNameResolver`)。解決できない形は未解決として gate を落とす |
+| 母集団 | 拡張子で絞った列挙 | `git ls-files` から生成し拡張子で絞らない (`scripts/` の拡張子なし実行ファイルを落とさない) |
+
+### なぜ正当な差分か (logic-driven)
+
+同じ家系正典 (`surface-removal-absence-gate` v1) を満たす形は 1 つではない。テンプレートは
+撤去物が 1 件のため gate のファイル内に走査を閉じているが、aicue は撤去物が **2 件**あり、
+両者が同じ走査根 (8 本) と同じ PHP 名前解決を要る。ここで各 gate に走査を複写すると
+「走査根の列挙を 2 本持つ」ことになり、AGENTS.md「静的検査 (gate) と走査器の共通規約」の
+**走査根の単一出典**に反する。したがって共通基盤へ切り出す側を選んだ。
+
+3 件目が来たら台帳駆動へ移す判断が要るが、2 件のために台帳機構を先回りして作るのは
+思考原則 2 (今必要なものだけ作る) に反するため v1 では作らない。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「撤去した表面への参照は、走査根 8 本の git 追跡下の全ファイルで 0 件である。
+> 許可一覧は持たない (母集団の定義そのもので絞る)。解決できない形は未解決として gate を落とす」
+
+- 母集団の空振り (走査根の改名・ディレクトリ移動) は代表パス pin と種別検査が検出する
+- 検出力は見本 (`tests/Architecture/fixtures/surface-removal/`) の正例・負例・未解決で裏取りする
+- NUL を 1 つ入れて静的層を迂回する経路は `binaryExcluded === []` の要求が塞ぐ
+
+### 保証しないもの
+
+- 静的層が見るのは列挙した構文だけである。middleware 位置の変数・式、分割連結、定数経由、
+  動的組み立て、PHP のコメント内には沈黙する。網羅的な一覧の正本は
+  `RemovedSurfaceScanner` と各 gate の docblock であり、ここには写さない
+- 実行時層が補完するのは**テスト起動時に実体化した route** までで、環境依存で実体化しない
+  経路 (production 限定の条件分岐・未実行コード) は両層とも見えない
+
+### 関連
+
+- 実装: `tests/Support/SurfaceRemoval/` / `tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php` / `tests/Architecture/OcrFeatureFlagAbsenceGateTest.php`
+- 実行時層: `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php`
+- 設計: `devnotes/20260823-0016-password-confirm-surface-removal-gate-v1/`
diff --git a/resources/js/components/features/manual/SourceDocumentUploadNotice.svelte b/resources/js/components/features/manual/SourceDocumentUploadNotice.svelte
index bb54811a..aadbfd6e 100644
--- a/resources/js/components/features/manual/SourceDocumentUploadNotice.svelte
+++ b/resources/js/components/features/manual/SourceDocumentUploadNotice.svelte
@@ -4,7 +4,7 @@
      * 作成画面と詳細画面が共有する。複写すると片方だけ更新される事故が起きるため
      * component 1 つへ集約している)。
      *
-     * 画像・スキャン PDF の OCR 対応は常時有効 (旧 `manual.ocr_analysis_enabled` フラグは
+     * 画像・スキャン PDF の OCR 対応は常時有効 (旧 OCR 有効化フラグ (config/manual.php) は
      * オーナー決定により撤去済み) なので、OCR 固有警告も常時表示する。props は持たない。
      *
      * **wrapper 要素を作らない**: 呼び出し側の flex 列 (gap) が案内の各段落へ直接効く
diff --git a/resources/js/pages/Settings/Security.svelte b/resources/js/pages/Settings/Security.svelte
index 48174495..a1be1b10 100644
--- a/resources/js/pages/Settings/Security.svelte
+++ b/resources/js/pages/Settings/Security.svelte
@@ -61,7 +61,7 @@
      * 2FA 管理
      * 未有効 → 有効化開始 (POST) → QR + コード確認 (confirming)
      * → リカバリコード表示 → 有効。無効化は ConfirmDialog 経由。
-     * 注: Fortify の password.confirm は撤去済み (generic recent-auth へ統一)。
+     * 注: Fortify 標準のパスワード確認 step-up は撤去済み (generic recent-auth へ統一)。
      * リカバリコード表示/再生成の endpoint は recent-auth 配線済み
      * (FortifyServiceProvider::attachRecentAuthToSensitiveRoutes())。フロントは
      * guardWithRecentAuth で precheck し、stale なら再認証モーダルを挟んで再開する。
diff --git a/tests/Architecture/OcrFeatureFlagAbsenceGateTest.php b/tests/Architecture/OcrFeatureFlagAbsenceGateTest.php
new file mode 100644
index 00000000..cce11f28
--- /dev/null
+++ b/tests/Architecture/OcrFeatureFlagAbsenceGateTest.php
@@ -0,0 +1,390 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\Manual\AcceptedSourceDocumentTypes;
+use Illuminate\Support\Arr;
+use Tests\Support\SurfaceRemoval\MethodReference;
+use Tests\Support\SurfaceRemoval\MiddlewareReference;
+use Tests\Support\SurfaceRemoval\Occurrence;
+use Tests\Support\SurfaceRemoval\RemovedSurfaceScanner;
+use Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets;
+use Tests\Support\SurfaceRemoval\RemovedTerm;
+use Tests\Support\SurfaceRemoval\ScannedFile;
+use Tests\Support\SurfaceRemoval\ScanOutcome;
+use Tests\Support\SurfaceRemoval\TermMatchMode;
+
+/*
+ * 撤去した OCR 機能フラグ (`manual.ocr_analysis_enabled` / `AcceptedSourceDocumentTypes::imagesEnabled()` /
+ * props `imageSourceDocumentsEnabled`) の**不在**を固定する gate
+ * (家系正典 surface-removal-absence-gate v1。実行時層 + 静的層 + 自己検証)。
+ *
+ * 画像・スキャン SOP の OCR 対応は**オーナー決定により常時有効**で、rollout gate は撤去済み。
+ * フラグが復活すると「受理形式の唯一の情報源」が 2 つに割れ、FormRequest / Service /
+ * Inertia Props の受理形式が食い違う (T242 で撤去したのはその割れそのもの)。
+ *
+ * ★**撤去物 × 実行時観測軸** (正典 I1。該当しない軸は理由つきで宣言する):
+ *   - route 名の不在 / メソッド×URI の不在 / 実 HTTP 404 … **該当なし** (設定値とクラスメソッドであり
+ *     route を持たない)
+ *   - クラス・表の不在 … **該当なし** (`AcceptedSourceDocumentTypes` は現役で、削除された表も無い)
+ *   - 機構に対応する等価の実行時層 … 本ファイルの実行時層 2 本
+ *     (設定木にキーが無いこと / メソッドが実行時に存在しないこと)
+ *
+ * ★**消しすぎていないことの確認は二重に持たない**。画像受理が現役であることは既存テストが担保する:
+ *   - `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php`
+ *     - `画像 (jpg/jpeg/png) を含む (常時有効)`
+ *     - `前提の pin: 拡張子集合が現在値ちょうど (ずれたらラベルの見直しが必要)`
+ *   - `tests/Feature/Projects/SourceDocumentUploadOcrTest.php`
+ *     - `jpg/png アップロードが成功する`
+ *     - `公開面の一貫性: FormRequest / Service / Inertia Props (create/show) が同じ受理形式 (画像込み) を表す`
+ *
+ * ★走査対象は `RemovedSurfaceScanTargets` の走査根 8 本の git 追跡下の全ファイル
+ *   (`database/migrations` は含めない)。**許可形は全 Tier で 0 個**である。
+ *
+ * ★`imagesEnabled` を**素のトークン一致で見ない**理由: 一般名すぎて、将来 OCR と無関係な
+ *   同名メソッドが必要になったときに全 production surface を止めてしまう。よって PHP 側は
+ *   **対象クラスの完全修飾名を基準にした宣言形・静的呼び出し形だけ**を見る。
+ *   非 PHP 側で裸の `imagesEnabled` を見ないのは、非 PHP から実行可能な参照になるには
+ *   クラスの完全修飾名が要るからである (完全修飾の参照文字列のほうは 0 件固定する)。
+ *
+ * ★**trait 経由の混入 (v1 の役割分担。誇張しない)**: v1 は **trait-use graph を扱わない**。
+ *   - trait 宣言そのものの `imagesEnabled` は**対象クラスの宣言として認識しない**
+ *   - 対象クラスが trait を取り込んでいる形と、trait 内の `self` / `static` / `parent` を
+ *     受け手にした `::imagesEnabled` 参照は**未解決として落とす** (fail-closed)
+ *   - それでも trait 経由で実際に混入した場合は、**実行時層の `method_exists()` が検出する**
+ *
+ * ★**保証しないもの**の正本は `RemovedSurfaceScanner` の docblock
+ *   (分割連結・定数経由・動的組み立て・PHP のコメント内・middleware 位置の変数式)。
+ * ★自己検証は本ファイル下部の「検出器の自己検証」節が持つ
+ *   (見本: `tests/Architecture/fixtures/surface-removal/ocr-flag/`)。
+ */
+
+/** 撤去した対象クラスの完全修飾名 (静的層の基準)。 */
+function ocrFeatureFlagTargetClass(): string
+{
+    return AcceptedSourceDocumentTypes::class;
+}
+
+/** 撤去したメソッド名。 */
+function ocrFeatureFlagTargetMethod(): string
+{
+    return 'imagesEnabled';
+}
+
+/**
+ * Tier 1 / Tier 2 に共通して 0 件固定する撤去語 (語ごとに一致様式を宣言する)。
+ *
+ * @return list<RemovedTerm>
+ */
+function ocrFeatureFlagRemovedTerms(): array
+{
+    return [
+        // 設定パス表記 (`manual.ocr_analysis_enabled`) に当てるため run の segment 一致
+        new RemovedTerm('ocr_analysis_enabled', TermMatchMode::RunSegment),
+        new RemovedTerm('OCR_ANALYSIS_ENABLED', TermMatchMode::ExactRun),
+        new RemovedTerm('imageSourceDocumentsEnabled', TermMatchMode::ExactRun),
+    ];
+}
+
+/** 非 PHP に 0 件固定する完全修飾参照。 */
+function ocrFeatureFlagFqcnTerm(): RemovedTerm
+{
+    return new RemovedTerm(
+        ocrFeatureFlagTargetClass().'::'.ocrFeatureFlagTargetMethod(),
+        TermMatchMode::FqcnMethodReference,
+    );
+}
+
+/** 見本ディレクトリ。 */
+function ocrFeatureFlagFixtureDirectory(): string
+{
+    return __DIR__.'/fixtures/surface-removal/ocr-flag';
+}
+
+/** 見本を走査対象として読み込む (**PHP として扱うかは引数で明示する**)。 */
+function ocrFeatureFlagFixtureFile(string $name, bool $isPhp): ScannedFile
+{
+    $path = ocrFeatureFlagFixtureDirectory().'/'.$name;
+    $contents = file_get_contents($path);
+    if ($contents === false) {
+        throw new RuntimeException("見本を読めません: {$name}");
+    }
+
+    return new ScannedFile('fixtures', 'fixtures/'.$name, $contents, $isPhp, 'txt');
+}
+
+/**
+ * 撤去物への参照を 4 つの検出対象へ分けて返す。
+ *
+ * ★**production の検査と自己検証は必ずこの 1 本を通る** (判定を 2 本持たない)。
+ *
+ * @param  list<ScannedFile>  $files
+ * @return array{lexemes: list<string>, texts: list<string>, methods: list<string>, fqcnTexts: list<string>, unresolved: list<string>}
+ */
+function ocrFeatureFlagFindings(array $files): array
+{
+    $nonPhp = array_values(array_filter($files, static fn (ScannedFile $file): bool => ! $file->isPhp));
+
+    $lexemes = [];
+    $texts = [];
+    /** @var list<ScanOutcome<Occurrence|MiddlewareReference|MethodReference>> $outcomes */
+    $outcomes = [];
+
+    foreach (ocrFeatureFlagRemovedTerms() as $term) {
+        $php = RemovedSurfaceScanner::scanPhpLexemes($files, $term);
+        $text = RemovedSurfaceScanner::scanText($nonPhp, $term);
+        $outcomes[] = $php;
+        $outcomes[] = $text;
+        $lexemes = [...$lexemes, ...$php->descriptions()];
+        $texts = [...$texts, ...$text->descriptions()];
+    }
+
+    $methods = RemovedSurfaceScanner::scanMethodReferences(
+        $files,
+        ocrFeatureFlagTargetClass(),
+        ocrFeatureFlagTargetMethod(),
+    );
+    $fqcnTexts = RemovedSurfaceScanner::scanText($nonPhp, ocrFeatureFlagFqcnTerm());
+    $outcomes[] = $methods;
+    $outcomes[] = $fqcnTexts;
+
+    return [
+        'lexemes' => $lexemes,
+        'texts' => $texts,
+        'methods' => $methods->descriptions(),
+        'fqcnTexts' => $fqcnTexts->descriptions(),
+        'unresolved' => ScanOutcome::mergeUnresolved($outcomes),
+    ];
+}
+
+/**
+ * 見本の正例 (検出経路と、経路別の前提検査)。
+ *
+ * ★一律の `str_contains($contents, $term)` は使わない — `self::imagesEnabled()` は対象の
+ *   完全修飾名を含まず、大小違いの正例は canonical 表記を含まないため成立しない。
+ *
+ * @return list<array{file: string, php: bool, buckets: list<string>, requires: list<string>}>
+ */
+function ocrFeatureFlagPositiveFixtures(): array
+{
+    return [
+        ['file' => 'positive-config-key.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['ocr_analysis_enabled']],
+        ['file' => 'positive-config-path.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['manual.ocr_analysis_enabled']],
+        ['file' => 'positive-class-const.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['OCR_ANALYSIS_ENABLED', 'const']],
+        ['file' => 'positive-property.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['$imageSourceDocumentsEnabled']],
+        ['file' => 'positive-variable.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['$ocr_analysis_enabled']],
+        ['file' => 'positive-heredoc.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['imageSourceDocumentsEnabled', '<<<']],
+        ['file' => 'positive-env.sh.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['OCR_ANALYSIS_ENABLED']],
+        ['file' => 'positive-prop.svelte.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['imageSourceDocumentsEnabled']],
+        ['file' => 'positive-method-declaration.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['acceptedsourcedocumenttypes', 'namespace', 'imagesenabled']],
+        ['file' => 'positive-method-declaration-bracketed.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['acceptedsourcedocumenttypes', 'namespace', 'imagesenabled']],
+        ['file' => 'positive-static-call-use.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', '::']],
+        ['file' => 'positive-static-call-alias.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', ' as ']],
+        ['file' => 'positive-static-call-groupuse-alias.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', '{']],
+        ['file' => 'positive-static-call-fqcn.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', '::']],
+        ['file' => 'positive-static-call-relative.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'namespace\\']],
+        ['file' => 'positive-static-call-same-namespace.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'namespace']],
+        ['file' => 'positive-self-call.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'self::']],
+        ['file' => 'positive-static-keyword-call.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'static::']],
+        ['file' => 'positive-case-insensitive.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', '::']],
+        ['file' => 'positive-fqcn-in-text.sh.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['::', 'imagesenabled']],
+        ['file' => 'positive-fqcn-leading-backslash.sh.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['::', 'imagesenabled']],
+        ['file' => 'positive-fqcn-case.yaml.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['::', 'imagesenabled']],
+    ];
+}
+
+/**
+ * 見本の負例 (反応してはならない。未解決にもならない)。
+ *
+ * @return list<array{file: string, php: bool}>
+ */
+function ocrFeatureFlagNegativeFixtures(): array
+{
+    return [
+        ['file' => 'negative-other-class-declaration.php.txt', 'php' => true],
+        ['file' => 'negative-other-class-static-call.php.txt', 'php' => true],
+        ['file' => 'negative-self-in-other-class.php.txt', 'php' => true],
+        ['file' => 'negative-target-other-method.php.txt', 'php' => true],
+        ['file' => 'negative-method-suffix.php.txt', 'php' => true],
+        ['file' => 'negative-dynamic-call.php.txt', 'php' => true],
+        ['file' => 'negative-suffix.php.txt', 'php' => true],
+        ['file' => 'negative-prefix.php.txt', 'php' => true],
+        ['file' => 'negative-negated.php.txt', 'php' => true],
+        ['file' => 'negative-php-comment.php.txt', 'php' => true],
+        ['file' => 'negative-bare-imagesenabled.sh.txt', 'php' => false],
+    ];
+}
+
+test('撤去した OCR フラグの設定キーが設定木に存在しない', function (): void {
+    $manual = config('manual');
+    // ★ is_array で絞り込む (expect()->toBeArray() は PHPStan の型を絞らない)
+    if (! is_array($manual)) {
+        throw new RuntimeException('設定木 manual を配列として解決できない');
+    }
+
+    // ★値ではなく**キーの存在**で判定する (null 値で復活しても落ちるように)
+    expect(Arr::has($manual, 'ocr_analysis_enabled'))->toBeFalse();
+
+    // ★母集団が空なのに緑になる形を作らない (設定木そのものが読めていることの確認)
+    expect(Arr::has($manual, 'source_document_mimes'))->toBeTrue();
+});
+
+test('撤去した imagesEnabled メソッドが実行時に存在しない', function (): void {
+    expect(method_exists(AcceptedSourceDocumentTypes::class, 'imagesEnabled'))->toBeFalse();
+    // ★クラス自体は現役である (消しすぎていないことの最小確認)
+    expect(method_exists(AcceptedSourceDocumentTypes::class, 'extensions'))->toBeTrue();
+});
+
+test('母集団に未解決もバイナリ除外も無い', function (): void {
+    $population = RemovedSurfaceScanTargets::population();
+
+    expect($population->unresolved)->toBe([]);
+    expect($population->binaryExcluded)->toBe([]);
+    expect(count($population->files))->toBeGreaterThan(0);
+});
+
+test('撤去した 3 語が走査根の PHP lexeme に 1 件も無い', function (): void {
+    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);
+
+    expect($findings['lexemes'])->toBe(
+        [],
+        'PHP lexeme への撤去語の再流入: '.implode(', ', $findings['lexemes']),
+    );
+});
+
+test('撤去した 3 語が走査根の非 PHP に 1 件も無い', function (): void {
+    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);
+
+    expect($findings['texts'])->toBe(
+        [],
+        '非 PHP への撤去語の再流入: '.implode(', ', $findings['texts']),
+    );
+});
+
+test('imagesEnabled の宣言と静的呼び出しが対象クラスに 1 件も無い', function (): void {
+    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);
+
+    expect($findings['methods'])->toBe(
+        [],
+        'imagesEnabled の再流入: '.implode(', ', $findings['methods']),
+    );
+});
+
+test('非 PHP に完全修飾の imagesEnabled 参照が 1 件も無い', function (): void {
+    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);
+
+    expect($findings['fqcnTexts'])->toBe(
+        [],
+        '非 PHP への完全修飾参照の再流入: '.implode(', ', $findings['fqcnTexts']),
+    );
+});
+
+test('走査で未解決が 1 件も出ていない', function (): void {
+    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);
+
+    expect($findings['unresolved'])->toBe(
+        [],
+        '解決できない形が残っている: '.implode(', ', $findings['unresolved']),
+    );
+});
+
+test('検出器の自己検証: 正例をすべて検出する', function (): void {
+    foreach (ocrFeatureFlagPositiveFixtures() as $fixture) {
+        $file = ocrFeatureFlagFixtureFile($fixture['file'], $fixture['php']);
+
+        // ★経路別の前提検査 (見本が壊れて静かに空振りするのを防ぐ)
+        foreach ($fixture['requires'] as $needle) {
+            expect(str_contains(strtolower($file->contents), strtolower($needle)))
+                ->toBeTrue("見本 {$fixture['file']} が前提の綴り「{$needle}」を含まない");
+        }
+
+        $findings = ocrFeatureFlagFindings([$file]);
+        expect($findings['unresolved'])->toBe([], "正例 {$fixture['file']} が未解決になった");
+
+        foreach ($fixture['buckets'] as $bucket) {
+            expect(count($findings[$bucket]))
+                ->toBeGreaterThan(0, "正例 {$fixture['file']} を {$bucket} で検出できない");
+        }
+    }
+});
+
+test('検出器の自己検証: 負例に反応しない', function (): void {
+    foreach (ocrFeatureFlagNegativeFixtures() as $fixture) {
+        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($fixture['file'], $fixture['php'])]);
+
+        expect($findings['lexemes'])->toBe([], "負例 {$fixture['file']} に lexeme で反応した");
+        expect($findings['texts'])->toBe([], "負例 {$fixture['file']} に text で反応した");
+        expect($findings['methods'])->toBe([], "負例 {$fixture['file']} に method で反応した");
+        expect($findings['fqcnTexts'])->toBe([], "負例 {$fixture['file']} に fqcn で反応した");
+        expect($findings['unresolved'])->toBe([], "負例 {$fixture['file']} が未解決になった");
+    }
+});
+
+test('検出器の自己検証: 同じ短名を持つ別クラスに反応しない', function (): void {
+    $fixtures = [
+        ['file' => 'negative-same-shortname-declaration.php.txt', 'php' => true],
+        ['file' => 'negative-same-shortname-static-call.php.txt', 'php' => true],
+        ['file' => 'negative-fqcn-other-namespace.sh.txt', 'php' => false],
+    ];
+
+    foreach ($fixtures as $fixture) {
+        $file = ocrFeatureFlagFixtureFile($fixture['file'], $fixture['php']);
+        // 短名一致へ退行したら赤くなる見本であること (前提検査)
+        expect(str_contains($file->contents, 'AcceptedSourceDocumentTypes'))->toBeTrue();
+
+        $findings = ocrFeatureFlagFindings([$file]);
+        expect($findings['methods'])->toBe([], "同じ短名の別クラス {$fixture['file']} に反応した");
+        expect($findings['fqcnTexts'])->toBe([], "同じ短名の別クラス {$fixture['file']} に fqcn で反応した");
+        expect($findings['unresolved'])->toBe([], "同じ短名の別クラス {$fixture['file']} が未解決になった");
+    }
+});
+
+test('検出器の自己検証: FQCN 様式の境界', function (): void {
+    $shouldMatch = [
+        'positive-fqcn-in-text.sh.txt',           // 先頭 `\` 無し
+        'positive-fqcn-leading-backslash.sh.txt', // 先頭 `\` あり
+        'positive-fqcn-case.yaml.txt',            // ASCII 大小違い
+    ];
+    $shouldNotMatch = [
+        'negative-fqcn-other-namespace.sh.txt',  // 同じ短名の別 namespace
+        'negative-fqcn-other-method.sh.txt',     // 対象クラスだが別メソッド
+        'negative-fqcn-method-suffix.sh.txt',    // メソッド名の接尾辞つき
+        'negative-bare-imagesenabled.sh.txt',    // 裸のメソッド名 (完全修飾でない)
+    ];
+
+    foreach ($shouldMatch as $name) {
+        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, false)]);
+        expect(count($findings['fqcnTexts']))->toBeGreaterThan(0, "FQCN 正例 {$name} を検出できない");
+    }
+
+    foreach ($shouldNotMatch as $name) {
+        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, false)]);
+        expect($findings['fqcnTexts'])->toBe([], "FQCN 負例 {$name} に反応した");
+    }
+});
+
+test('検出器の自己検証: 解決できないクラス参照は未解決になる', function (): void {
+    $findings = ocrFeatureFlagFindings([
+        ocrFeatureFlagFixtureFile('unresolved-dynamic-class-static-call.php.txt', true),
+    ]);
+
+    expect(count($findings['unresolved']))->toBeGreaterThan(0);
+});
+
+test('検出器の自己検証: trait 内の self と対象クラスの trait 取り込みは未解決になる', function (): void {
+    foreach (['unresolved-trait-self-call.php.txt', 'unresolved-trait-used-by-target.php.txt'] as $name) {
+        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, true)]);
+
+        expect(count($findings['unresolved']))->toBeGreaterThan(0, "{$name} が未解決にならない");
+        // ★誤って「解決済みの違反」として数えていないこと (fail-open でも fail-loud でもない形を防ぐ)
+        expect($findings['methods'])->toBe([]);
+    }
+});
+
+test('検出器の自己検証: 壊れた PHP は未解決になる', function (): void {
+    $findings = ocrFeatureFlagFindings([
+        ocrFeatureFlagFixtureFile('unresolved-broken-php.php.txt', true),
+    ]);
+
+    expect(count($findings['unresolved']))->toBeGreaterThan(0);
+});
diff --git a/tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php b/tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php
index 6352a747..cb493e14 100644
--- a/tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php
+++ b/tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php
@@ -2,10 +2,15 @@
 
 declare(strict_types=1);
 
+use App\Http\Middleware\RequireRecentAuth;
+use Illuminate\Auth\Middleware\RequirePassword;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Routing\Router;
 use Illuminate\Support\Facades\Route;
 
 /*
- * `password.confirm` middleware の **全 route での不在** を deny-by-default で固定する。
+ * 撤去した Fortify 標準 step-up 機構 (`password.confirm` middleware) の
+ * **実行時の不在**を deny-by-default で固定する (家系正典 surface-removal-absence-gate v1 の実行時層)。
  *
  * 本アプリは Fortify 標準の password.confirm (3h 窓・パスワード限定) を撤去し、
  * generic recent-auth (15 分窓・パスワード or 再SSO or パスキー) へ統一している。
@@ -16,20 +21,89 @@
  *
  * 特に laravel/passkeys は config 既定が `management_middleware = ['password.confirm']` で、
  * `fortify-options.passkeys.confirmPassword` を落とすと即座に復活する。
+ *
+ * ★**撤去物 × 実行時観測軸** (正典 I1。該当しない軸は理由つきで宣言する):
+ *   - route 名の不在      … **該当なし**。撤去したのは*機構*であり、同名 route 3 本
+ *     (`password.confirm` / `password.confirm.store` / `password.confirmation`) は
+ *     Fortify が救済 redirect / 状態プローブとして意図的に残す現役資産である
+ *   - メソッド×URI の不在 … **該当なし** (`user/confirm-password` は現役)
+ *   - クラス・表の不在    … **該当なし** (機構は vendor 側クラス。aicue が撤去したのは*適用*)
+ *   - 実 HTTP 404・無副作用 … **該当なし** (同上)
+ *   - 機構に対応する等価の実行時層 … **本ファイルの 3 層**が担う
+ *
+ * ★**静的層との分担**: `PasswordConfirmSurfaceAbsenceGateTest` が、列挙した middleware 位置
+ *   (M1〜M3) への**参照の再流入**を字句で止める。**列挙外は静的層の保証外**であり、
+ *   本テスト (解決済み middleware の全数走査) が**テスト起動時に実体化した全 route について
+ *   補完する**が、**環境依存で実体化しない経路 (production 限定の条件分岐・未実行コード) までは
+ *   保証しない**。
+ * ★`PasskeyPackageContractTest` は `fortify-options.passkeys.confirmPassword` を**名指しで**
+ *   pin する。本ファイルの層 2 は**設定木全体から生成した母集団**を見る (新しい設定ファイルに
+ *   `confirmPassword` が生えたことを捕まえる) ので二重化ではない。
  */
+
+/** route の診断ラベル (本ファイル固有の名前にする。Pest のファイルスコープ関数は衝突しうる)。 */
+function routeLabelForPasswordConfirmGate(RoutingRoute $route): string
+{
+    return $route->getName() ?? implode('|', $route->methods()).':'.$route->uri();
+}
+
+/**
+ * 設定木から `confirmPassword` キーを**生成**して集める (再帰。キー名の完全一致のみ)。
+ *
+ * 診断パスは文字列キーを `.` で、整数キーを `[0]` の角括弧で連結する。
+ *
+ * @param  array<array-key, mixed>  $tree
+ * @param  array<string, mixed>  $found
+ */
+function collectConfirmPasswordKeysForPasswordConfirmGate(array $tree, string $prefix, array &$found): void
+{
+    foreach ($tree as $key => $value) {
+        $path = is_int($key)
+            ? $prefix.'['.$key.']'
+            : ($prefix === '' ? $key : $prefix.'.'.$key);
+
+        if ($key === 'confirmPassword') {
+            $found[$path] = $value;
+        }
+
+        if (is_array($value)) {
+            collectConfirmPasswordKeysForPasswordConfirmGate($value, $path, $found);
+        }
+    }
+}
+
 test('password.confirm middleware を持つ route が 1 本も無い', function (): void {
+    /** @var Router $router */
+    $router = app('router');
+
     $violations = [];
     $checked = 0;
+    $routesWithResolvedMiddleware = 0;
 
     foreach (Route::getRoutes() as $route) {
         $checked++;
 
-        foreach ($route->gatherMiddleware() as $middleware) {
-            if (! is_string($middleware)) {
+        // (a) alias 文字列そのものの再流入 (alias 登録側の復活を見る)
+        foreach ($route->gatherMiddleware() as $declared) {
+            if (! is_string($declared)) {
                 continue;
             }
-            if ($middleware === 'password.confirm' || str_starts_with($middleware, 'password.confirm:')) {
-                $violations[] = $route->getName() ?? implode('|', $route->methods()).':'.$route->uri();
+            if ($declared === 'password.confirm' || str_starts_with($declared, 'password.confirm:')) {
+                $violations[] = 'alias: '.routeLabelForPasswordConfirmGate($route);
+            }
+        }
+
+        // (b) group 展開・alias 解決・クラス直指定をすべて含む**解決済み**集合
+        $resolved = $router->gatherRouteMiddleware($route);
+        if ($resolved !== []) {
+            $routesWithResolvedMiddleware++;
+        }
+        foreach ($resolved as $entry) {
+            if (! is_string($entry)) {
+                continue; // Closure middleware は名前を持たない
+            }
+            if (strtolower(explode(':', $entry, 2)[0]) === strtolower(RequirePassword::class)) {
+                $violations[] = 'class: '.routeLabelForPasswordConfirmGate($route);
             }
         }
     }
@@ -41,4 +115,51 @@
     );
     // route 走査自体が空振りしていないこと
     expect($checked)->toBeGreaterThan(0);
+    // ★ middleware 解決自体が壊れて全 route が空を返す形で緑になるのを防ぐ
+    expect($routesWithResolvedMiddleware)->toBeGreaterThan(0);
+});
+
+test('confirmPassword の設定キーは生成した母集団のうえで全件 false', function (): void {
+    // ★ config()->all() は Config Repository の契約上すでに配列。
+    //   is_array() を置くと PHPStan が「常に true」の不要条件として報告するため置かない。
+    /** @var array<string, mixed> $all */
+    $all = config()->all();
+
+    /** @var array<string, mixed> $found */
+    $found = [];
+    collectConfirmPasswordKeysForPasswordConfirmGate($all, '', $found);
+
+    // ★母集団が空なのに緑になる形を作らない (実測 2 件を下限に pin)
+    expect(count($found))->toBeGreaterThanOrEqual(2);
+
+    // ★既知の 2 パスが含まれること (パッケージ設定の未ロードを検出する代表値 pin)
+    expect(array_keys($found))->toContain('fortify-options.two-factor-authentication.confirmPassword');
+    expect(array_keys($found))->toContain('fortify-options.passkeys.confirmPassword');
+
+    $enabled = array_keys(array_filter($found, static fn (mixed $value): bool => $value !== false));
+    expect($enabled)->toBe([], 'confirmPassword が false 以外: '.implode(', ', $enabled));
+});
+
+test('置換先の generic recent-auth が生きている', function (): void {
+    /** @var Router $router */
+    $router = app('router');
+
+    expect(Route::has('recent-auth.confirm'))->toBeTrue();
+    expect(Route::has('recent-auth.password'))->toBeTrue();
+
+    $guarded = 0;
+    foreach (Route::getRoutes() as $route) {
+        foreach ($router->gatherRouteMiddleware($route) as $entry) {
+            if (! is_string($entry)) {
+                continue;
+            }
+            // ★alias 名 ('recent-auth') をハードコードしない。解決済み集合で見る
+            if (strtolower(explode(':', $entry, 2)[0]) === strtolower(RequireRecentAuth::class)) {
+                $guarded++;
+                break;
+            }
+        }
+    }
+
+    expect($guarded)->toBeGreaterThan(0, 'recent-auth を実際に適用している route が 1 本も無い');
 });
diff --git a/tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php b/tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php
new file mode 100644
index 00000000..0019c095
--- /dev/null
+++ b/tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php
@@ -0,0 +1,365 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Auth\Middleware\RequirePassword;
+use Tests\Support\SurfaceRemoval\ContentClassification;
+use Tests\Support\SurfaceRemoval\MiddlewareReference;
+use Tests\Support\SurfaceRemoval\MiddlewareReferenceKind;
+use Tests\Support\SurfaceRemoval\RemovedSurfaceScanner;
+use Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets;
+use Tests\Support\SurfaceRemoval\RemovedTerm;
+use Tests\Support\SurfaceRemoval\ScannedFile;
+use Tests\Support\SurfaceRemoval\ScanOutcome;
+use Tests\Support\SurfaceRemoval\ScanPopulation;
+use Tests\Support\SurfaceRemoval\TermMatchMode;
+
+/*
+ * 撤去した Fortify 標準 step-up 機構 (`password.confirm` middleware) の
+ * **参照の再流入**を字句で止める gate (家系正典 surface-removal-absence-gate v1 の静的層)。
+ *
+ * ★走査対象: `Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets` の走査根 8 本
+ *   (`.github` / `app` / `bootstrap` / `config` / `lang` / `resources` / `routes` / `scripts`) の
+ *   git 追跡下の全ファイル。`database/migrations` は含めない (理由は同クラスの docblock)。
+ * ★検出対象は「撤去した middleware の**適用・登録を表す構文**」であり、
+ *   文字列 `password.confirm` の全出現ではない。したがって `config/seo.php` の
+ *   route 名対応表 (`app_titles`) は**母集団に入らず**、除外規則を持たない。
+ *   **許可一覧は 0 個**である。
+ * ★middleware 位置の定義 (M1〜M3) は
+ *   `RemovedSurfaceScanner::scanMiddlewarePositions()` の docblock が正本。
+ *
+ * ★**保証しないもの (検出力を誇張しない)**:
+ *   - 列挙した middleware 位置 (M1〜M3) の**外**は**静的層の保証外**である。
+ *     実行時層 (`PasswordConfirmMiddlewareAbsenceTest`。解決済み middleware の全数走査、
+ *     deny-by-default) が**テスト起動時に実体化した全 route について補完する**が、
+ *     **環境依存で実体化しない経路 (production 限定の条件分岐・未実行コード) までは保証しない**。
+ *   - middleware 位置の**変数・式** (`->middleware($alias)` /
+ *     `->middleware('throttle:'.$limiter)`) はクラス参照でも文字列リテラルでもないため
+ *     母集団に入らない。これは免除ではなく**規則段階の定義**であり、
+ *     見本 `negative-dynamic-middleware-value.php.txt` が「沈黙すること」を固定している。
+ *   - 分割連結・定数経由・動的組み立て・PHP のコメント内には沈黙する。
+ *   - NUL を含むファイルは母集団に入らない (ただし `binaryExcluded === []` を要求する)。
+ * ★自己検証は本ファイル下部の「検出器の自己検証」節が持つ
+ *   (見本: `tests/Architecture/fixtures/surface-removal/password-confirm/` と
+ *   `tests/Architecture/fixtures/surface-removal/content/`)。
+ */
+
+/** 撤去した alias 名 (一致様式つき)。 */
+function passwordConfirmRemovedTerm(): RemovedTerm
+{
+    return new RemovedTerm('password.confirm', TermMatchMode::ExactRun);
+}
+
+/** 実走査母集団 (プロセス内で 1 度だけ確定する)。 */
+function passwordConfirmScanPopulation(): ScanPopulation
+{
+    return RemovedSurfaceScanTargets::population();
+}
+
+/** 見本ディレクトリ。 */
+function passwordConfirmFixtureDirectory(): string
+{
+    return __DIR__.'/fixtures/surface-removal/password-confirm';
+}
+
+/**
+ * 見本を走査対象として読み込む (**PHP として扱うかは引数で明示する**。拡張子から推定しない)。
+ */
+function passwordConfirmFixtureFile(string $name, bool $isPhp): ScannedFile
+{
+    $path = passwordConfirmFixtureDirectory().'/'.$name;
+    $contents = file_get_contents($path);
+    if ($contents === false) {
+        throw new RuntimeException("見本を読めません: {$name}");
+    }
+
+    return new ScannedFile('fixtures', 'fixtures/'.$name, $contents, $isPhp, 'txt');
+}
+
+/**
+ * 撤去した機構への参照を 3 つの検出対象へ分けて返す。
+ *
+ * ★**production の検査と自己検証は必ずこの 1 本を通る** (判定を 2 本持たない)。
+ *
+ * @param  list<ScannedFile>  $files
+ * @return array{aliases: list<string>, classes: list<string>, texts: list<string>, unresolved: list<string>}
+ */
+function passwordConfirmSurfaceFindings(array $files): array
+{
+    $term = passwordConfirmRemovedTerm();
+
+    $middleware = RemovedSurfaceScanner::scanMiddlewarePositions($files);
+    $text = RemovedSurfaceScanner::scanText(
+        array_values(array_filter($files, static fn (ScannedFile $file): bool => ! $file->isPhp)),
+        $term,
+    );
+
+    $aliases = [];
+    $classes = [];
+    foreach ($middleware->occurrences as $reference) {
+        if (! $reference instanceof MiddlewareReference) {
+            continue;
+        }
+        if ($reference->kind === MiddlewareReferenceKind::AliasString) {
+            // D1: alias 文字列 (`password.confirm` / `password.confirm:web`)。
+            //     判定は走査器と同じ 1 本のトークン一致を通す
+            if (RemovedSurfaceScanner::textMatches($reference->value, $term)) {
+                $aliases[] = $reference->describe();
+            }
+
+            continue;
+        }
+        // D2: 完全修飾名が撤去した実体クラスへ解決されるもの
+        if (strtolower((string) $reference->resolvedFqcn) === strtolower(RequirePassword::class)) {
+            $classes[] = $reference->describe();
+        }
+    }
+
+    return [
+        'aliases' => $aliases,
+        'classes' => $classes,
+        'texts' => $text->descriptions(),   // D3: 非 PHP の生テキスト
+        'unresolved' => ScanOutcome::mergeUnresolved([$middleware, $text]),
+    ];
+}
+
+/**
+ * 見本の正例 (検出経路と、見本が壊れて空振りしないための**経路別の前提検査**)。
+ *
+ * ★一律の `str_contains($contents, $term)` は使わない — 大小違いの正例は canonical 表記を
+ *   含まず、alias / group use の正例は参照位置に完全修飾名を持たないため成立しない。
+ *
+ * @return list<array{file: string, php: bool, buckets: list<string>, requires: list<string>}>
+ */
+function passwordConfirmPositiveFixtures(): array
+{
+    return [
+        ['file' => 'positive-middleware-array.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', 'middleware(']],
+        ['file' => 'positive-middleware-arg.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', 'middleware(']],
+        ['file' => 'positive-middleware-param.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm:', 'middleware(']],
+        ['file' => 'positive-middleware-class.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', 'middleware(']],
+        ['file' => 'positive-middleware-class-fqcn.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', 'middleware(']],
+        ['file' => 'positive-middleware-class-alias.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', ' as ']],
+        ['file' => 'positive-middleware-class-groupuse.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', '{']],
+        ['file' => 'positive-middleware-class-relative.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', 'namespace']],
+        ['file' => 'positive-middleware-class-case.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', 'middleware(']],
+        ['file' => 'positive-config-management-middleware.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', 'management_middleware']],
+        ['file' => 'positive-kernel-property.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', '$middlewareGroups']],
+        ['file' => 'positive-alias-registration.php.txt', 'php' => true, 'buckets' => ['aliases', 'classes'], 'requires' => ['password.confirm', 'requirepassword', 'alias(']],
+        ['file' => 'positive-css-id-selector.css.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+        ['file' => 'positive-css-universal.css.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+        ['file' => 'positive-ts-generator.ts.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+        ['file' => 'positive-svelte-markup.svelte.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+        ['file' => 'positive-svelte-script.svelte.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+        ['file' => 'positive-svelte-style.svelte.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+        ['file' => 'positive-shell.sh.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+        ['file' => 'positive-noext.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+        ['file' => 'positive-workflow.yaml.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+    ];
+}
+
+/**
+ * 見本の負例 (反応してはならない。未解決にもならない)。
+ *
+ * @return list<string>
+ */
+function passwordConfirmNegativeFixtures(): array
+{
+    return [
+        'negative-seo-title-map.php.txt',
+        'negative-route-name-usage.php.txt',
+        'negative-suffix.php.txt',
+        'negative-prefix.php.txt',
+        'negative-negated.php.txt',
+        'negative-session-key.php.txt',
+        'negative-php-comment.php.txt',
+        'negative-other-middleware-class.php.txt',
+        'negative-dynamic-middleware-value.php.txt',
+    ];
+}
+
+test('走査根がすべて解決でき、実走査母集団が空でない', function (): void {
+    $population = passwordConfirmScanPopulation();
+
+    expect(RemovedSurfaceScanTargets::roots())->toHaveCount(8);
+
+    foreach (array_keys(RemovedSurfaceScanTargets::roots()) as $root) {
+        expect(count($population->inRoot($root)))->toBeGreaterThan(0, "走査根 {$root} の母集団が空");
+    }
+
+    expect(count($population->php()))->toBeGreaterThan(0);
+    expect(count($population->nonPhp()))->toBeGreaterThan(0);
+});
+
+test('各走査根に代表パスが含まれる', function (): void {
+    $paths = passwordConfirmScanPopulation()->relativePaths();
+
+    foreach (RemovedSurfaceScanTargets::REPRESENTATIVE_PATHS as $root => $representative) {
+        expect(in_array($representative, $paths, true))
+            ->toBeTrue("走査根 {$root} の代表パス {$representative} が母集団に無い");
+    }
+});
+
+test('scripts と .github の実走査母集団に期待する種別が含まれる', function (): void {
+    $population = passwordConfirmScanPopulation();
+
+    $scripts = $population->inRoot('scripts');
+    $withoutExtension = array_filter($scripts, static fn (ScannedFile $f): bool => $f->extension === null);
+    $shell = array_filter($scripts, static fn (ScannedFile $f): bool => $f->extension === 'sh');
+
+    expect(count($withoutExtension))->toBeGreaterThan(0, 'scripts/ に拡張子なしの実行ファイルが 1 件も無い');
+    expect(count($shell))->toBeGreaterThan(0, 'scripts/ に .sh が 1 件も無い');
+
+    $workflows = array_filter(
+        $population->inRoot('.github'),
+        static fn (ScannedFile $f): bool => str_starts_with($f->relative, '.github/workflows/')
+            && in_array($f->extension, ['yml', 'yaml'], true),
+    );
+    expect(count($workflows))->toBeGreaterThan(0, '.github/workflows/ に YAML が 1 件も無い');
+});
+
+test('母集団に未解決もバイナリ除外も無い', function (): void {
+    $population = passwordConfirmScanPopulation();
+
+    expect($population->unresolved)->toBe([]);
+    // ★NUL を 1 つ入れて静的層を迂回する経路を塞ぐ
+    expect($population->binaryExcluded)->toBe([]);
+});
+
+test('middleware 位置に password.confirm alias が 1 件も無い', function (): void {
+    $findings = passwordConfirmSurfaceFindings(passwordConfirmScanPopulation()->files);
+
+    expect($findings['aliases'])->toBe(
+        [],
+        'password.confirm alias の再流入: '.implode(', ', $findings['aliases']),
+    );
+});
+
+test('middleware 位置に RequirePassword の参照が 1 件も無い', function (): void {
+    $findings = passwordConfirmSurfaceFindings(passwordConfirmScanPopulation()->files);
+
+    expect($findings['classes'])->toBe(
+        [],
+        'RequirePassword の再流入: '.implode(', ', $findings['classes']),
+    );
+});
+
+test('非 PHP に password.confirm が 1 件も無い', function (): void {
+    $findings = passwordConfirmSurfaceFindings(passwordConfirmScanPopulation()->files);
+
+    expect($findings['texts'])->toBe(
+        [],
+        '非 PHP への password.confirm 残留: '.implode(', ', $findings['texts']),
+    );
+});
+
+test('走査で未解決が 1 件も出ていない', function (): void {
+    $findings = passwordConfirmSurfaceFindings(passwordConfirmScanPopulation()->files);
+
+    expect($findings['unresolved'])->toBe(
+        [],
+        '解決できない形が残っている: '.implode(', ', $findings['unresolved']),
+    );
+});
+
+test('検出器の自己検証: 正例をすべて検出する', function (): void {
+    foreach (passwordConfirmPositiveFixtures() as $fixture) {
+        $file = passwordConfirmFixtureFile($fixture['file'], $fixture['php']);
+
+        // ★経路別の前提検査 (見本が壊れて静かに空振りするのを防ぐ)
+        foreach ($fixture['requires'] as $needle) {
+            expect(str_contains(strtolower($file->contents), strtolower($needle)))
+                ->toBeTrue("見本 {$fixture['file']} が前提の綴り「{$needle}」を含まない");
+        }
+
+        $findings = passwordConfirmSurfaceFindings([$file]);
+        expect($findings['unresolved'])->toBe([], "正例 {$fixture['file']} が未解決になった");
+
+        foreach ($fixture['buckets'] as $bucket) {
+            expect(count($findings[$bucket]))
+                ->toBeGreaterThan(0, "正例 {$fixture['file']} を {$bucket} で検出できない");
+        }
+    }
+});
+
+test('検出器の自己検証: 負例に反応しない', function (): void {
+    foreach (passwordConfirmNegativeFixtures() as $name) {
+        $findings = passwordConfirmSurfaceFindings([passwordConfirmFixtureFile($name, true)]);
+
+        expect($findings['aliases'])->toBe([], "負例 {$name} に alias で反応した");
+        expect($findings['classes'])->toBe([], "負例 {$name} に class で反応した");
+        expect($findings['texts'])->toBe([], "負例 {$name} に text で反応した");
+        expect($findings['unresolved'])->toBe([], "負例 {$name} が未解決になった");
+    }
+});
+
+test('検出器の自己検証: 同じ短名を持つ別クラスに反応しない', function (): void {
+    $names = [
+        'negative-same-shortname-import.php.txt',
+        'negative-same-shortname-fqcn.php.txt',
+        'negative-alias-to-target-shortname.php.txt',
+    ];
+
+    foreach ($names as $name) {
+        $file = passwordConfirmFixtureFile($name, true);
+        // 短名一致へ退行したら赤くなる見本であること (前提検査)
+        expect(str_contains($file->contents, 'RequirePassword'))->toBeTrue();
+
+        $findings = passwordConfirmSurfaceFindings([$file]);
+        expect($findings['classes'])->toBe([], "同じ短名の別クラス {$name} に反応した");
+        expect($findings['unresolved'])->toBe([], "同じ短名の別クラス {$name} が未解決になった");
+    }
+});
+
+test('検出器の自己検証: 解決できない middleware クラス参照は未解決になる', function (): void {
+    $findings = passwordConfirmSurfaceFindings([
+        passwordConfirmFixtureFile('unresolved-dynamic-middleware-class.php.txt', true),
+    ]);
+
+    expect(count($findings['unresolved']))->toBeGreaterThan(0);
+});
+
+test('検出器の自己検証: 壊れた PHP は未解決になる', function (): void {
+    $findings = passwordConfirmSurfaceFindings([
+        passwordConfirmFixtureFile('unresolved-broken-php.php.txt', true),
+    ]);
+
+    expect(count($findings['unresolved']))->toBeGreaterThan(0);
+});
+
+test('検出器の自己検証: 内容分類が効く', function (): void {
+    $directory = __DIR__.'/fixtures/surface-removal/content';
+
+    $decode = static function (string $name) use ($directory): string {
+        $hex = file_get_contents($directory.'/'.$name);
+        if ($hex === false) {
+            throw new RuntimeException("見本を読めません: {$name}");
+        }
+        $bytes = @hex2bin((string) preg_replace('/\s+/', '', $hex));
+        if ($bytes === false) {
+            throw new RuntimeException("見本の hex を復号できません (見本の破損): {$name}");
+        }
+
+        return $bytes;
+    };
+
+    $plain = file_get_contents($directory.'/text-plain.txt');
+    expect($plain)->toBeString();
+
+    // ★population() と**同じ関数**を通す (自己検証と実母集団の経路が切れないこと)
+    expect(RemovedSurfaceScanTargets::classifyContents($decode('binary-with-nul.hex.txt')))
+        ->toBe(ContentClassification::Binary);
+    expect(RemovedSurfaceScanTargets::classifyContents($decode('invalid-utf8.hex.txt')))
+        ->toBe(ContentClassification::InvalidUtf8);
+    expect(RemovedSurfaceScanTargets::classifyContents((string) $plain))
+        ->toBe(ContentClassification::Text);
+});
+
+test('検出器の自己検証: リポジトリ内外の判定が効く', function (): void {
+    $root = '/repo';
+
+    expect(RemovedSurfaceScanTargets::isPathInsideRepository($root, '/repo/app/X.php'))->toBeTrue();
+    expect(RemovedSurfaceScanTargets::isPathInsideRepository($root, '/elsewhere/X.php'))->toBeFalse();
+    // 接頭辞が偶然一致するだけのパスは配下ではない
+    expect(RemovedSurfaceScanTargets::isPathInsideRepository($root, '/repo-other/X.php'))->toBeFalse();
+});
diff --git a/tests/Architecture/fixtures/surface-removal/content/binary-with-nul.hex.txt b/tests/Architecture/fixtures/surface-removal/content/binary-with-nul.hex.txt
new file mode 100644
index 00000000..de816ced
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/content/binary-with-nul.hex.txt
@@ -0,0 +1 @@
+48690041
diff --git a/tests/Architecture/fixtures/surface-removal/content/invalid-utf8.hex.txt b/tests/Architecture/fixtures/surface-removal/content/invalid-utf8.hex.txt
new file mode 100644
index 00000000..0172de57
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/content/invalid-utf8.hex.txt
@@ -0,0 +1 @@
+48c3286f
diff --git a/tests/Architecture/fixtures/surface-removal/content/text-plain.txt b/tests/Architecture/fixtures/surface-removal/content/text-plain.txt
new file mode 100644
index 00000000..067d6d2d
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/content/text-plain.txt
@@ -0,0 +1,2 @@
+通常の UTF-8 テキスト (NUL を含まない)。
+plain ASCII line.
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-bare-imagesenabled.sh.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-bare-imagesenabled.sh.txt
new file mode 100644
index 00000000..1923a71c
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-bare-imagesenabled.sh.txt
@@ -0,0 +1,2 @@
+#!/usr/bin/env bash
+echo "imagesEnabled"
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-dynamic-call.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-dynamic-call.php.txt
new file mode 100644
index 00000000..f74a3e4e
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-dynamic-call.php.txt
@@ -0,0 +1,5 @@
+<?php
+
+declare(strict_types=1);
+
+$enabled = $x->imagesEnabled();
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-fqcn-method-suffix.sh.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-fqcn-method-suffix.sh.txt
new file mode 100644
index 00000000..0605b9c6
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-fqcn-method-suffix.sh.txt
@@ -0,0 +1,2 @@
+#!/usr/bin/env bash
+php -r "var_dump(App\Support\Manual\AcceptedSourceDocumentTypes::imagesEnabledAt());"
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-fqcn-other-method.sh.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-fqcn-other-method.sh.txt
new file mode 100644
index 00000000..352938eb
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-fqcn-other-method.sh.txt
@@ -0,0 +1,2 @@
+#!/usr/bin/env bash
+php -r "var_dump(App\Support\Manual\AcceptedSourceDocumentTypes::extensions());"
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-fqcn-other-namespace.sh.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-fqcn-other-namespace.sh.txt
new file mode 100644
index 00000000..1548a993
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-fqcn-other-namespace.sh.txt
@@ -0,0 +1,2 @@
+#!/usr/bin/env bash
+php -r "var_dump(App\Other\AcceptedSourceDocumentTypes::imagesEnabled());"
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-method-suffix.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-method-suffix.php.txt
new file mode 100644
index 00000000..8e39c87a
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-method-suffix.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Manual;
+
+$at = AcceptedSourceDocumentTypes::imagesEnabledAt();
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-negated.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-negated.php.txt
new file mode 100644
index 00000000..136842c1
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-negated.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+return [
+    'disable_ocr_analysis_enabled' => false,
+];
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-other-class-declaration.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-other-class-declaration.php.txt
new file mode 100644
index 00000000..a65368f8
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-other-class-declaration.php.txt
@@ -0,0 +1,13 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Other;
+
+class Thing
+{
+    public static function imagesEnabled(): bool
+    {
+        return true;
+    }
+}
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-other-class-static-call.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-other-class-static-call.php.txt
new file mode 100644
index 00000000..410b5863
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-other-class-static-call.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+$enabled = \App\Other\Thing::imagesEnabled();
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-php-comment.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-php-comment.php.txt
new file mode 100644
index 00000000..8fe16783
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-php-comment.php.txt
@@ -0,0 +1,11 @@
+<?php
+
+declare(strict_types=1);
+
+// 旧 rollout gate `ocr_analysis_enabled` は撤去済み。
+
+/**
+ * 撤去した設定キー: ocr_analysis_enabled / OCR_ANALYSIS_ENABLED
+ * 撤去した props 名: imageSourceDocumentsEnabled
+ */
+return [];
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-prefix.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-prefix.php.txt
new file mode 100644
index 00000000..4973875c
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-prefix.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+return [
+    'legacy_ocr_analysis_enabled' => false,
+];
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-same-shortname-declaration.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-same-shortname-declaration.php.txt
new file mode 100644
index 00000000..90fe75e1
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-same-shortname-declaration.php.txt
@@ -0,0 +1,13 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Other;
+
+class AcceptedSourceDocumentTypes
+{
+    public static function imagesEnabled(): bool
+    {
+        return true;
+    }
+}
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-same-shortname-static-call.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-same-shortname-static-call.php.txt
new file mode 100644
index 00000000..6653bc00
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-same-shortname-static-call.php.txt
@@ -0,0 +1,10 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+use App\Other\AcceptedSourceDocumentTypes;
+
+$a = AcceptedSourceDocumentTypes::imagesEnabled();
+$b = \App\Other\AcceptedSourceDocumentTypes::imagesEnabled();
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-self-in-other-class.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-self-in-other-class.php.txt
new file mode 100644
index 00000000..bda3678c
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-self-in-other-class.php.txt
@@ -0,0 +1,13 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Other;
+
+final class Thing
+{
+    public static function probe(): bool
+    {
+        return self::imagesEnabled();
+    }
+}
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-suffix.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-suffix.php.txt
new file mode 100644
index 00000000..5570b778
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-suffix.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+return [
+    'ocr_analysis_enabled_at' => null,
+];
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-target-other-method.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-target-other-method.php.txt
new file mode 100644
index 00000000..6139e96b
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/negative-target-other-method.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Manual;
+
+$extensions = AcceptedSourceDocumentTypes::extensions();
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-case-insensitive.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-case-insensitive.php.txt
new file mode 100644
index 00000000..8a5d50f6
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-case-insensitive.php.txt
@@ -0,0 +1,8 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Manual;
+
+$a = AcceptedSourceDocumentTypes::IMAGESENABLED();
+$b = \app\support\manual\AcceptedSourceDocumentTypes::imagesEnabled();
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-class-const.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-class-const.php.txt
new file mode 100644
index 00000000..727fcebe
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-class-const.php.txt
@@ -0,0 +1,10 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+final class Flags
+{
+    public const bool OCR_ANALYSIS_ENABLED = true;
+}
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-config-key.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-config-key.php.txt
new file mode 100644
index 00000000..a7e3c871
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-config-key.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+return [
+    'ocr_analysis_enabled' => true,
+];
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-config-path.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-config-path.php.txt
new file mode 100644
index 00000000..df18d513
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-config-path.php.txt
@@ -0,0 +1,5 @@
+<?php
+
+declare(strict_types=1);
+
+$enabled = config('manual.ocr_analysis_enabled');
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-env.sh.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-env.sh.txt
new file mode 100644
index 00000000..90f01ac6
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-env.sh.txt
@@ -0,0 +1,4 @@
+#!/usr/bin/env bash
+set -euo pipefail
+OCR_ANALYSIS_ENABLED=1
+export OCR_ANALYSIS_ENABLED
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-fqcn-case.yaml.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-fqcn-case.yaml.txt
new file mode 100644
index 00000000..06493211
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-fqcn-case.yaml.txt
@@ -0,0 +1,5 @@
+name: ci
+jobs:
+  probe:
+    steps:
+      - run: php -r "app\support\manual\acceptedsourcedocumenttypes::IMAGESENABLED();"
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-fqcn-in-text.sh.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-fqcn-in-text.sh.txt
new file mode 100644
index 00000000..45ec2359
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-fqcn-in-text.sh.txt
@@ -0,0 +1,2 @@
+#!/usr/bin/env bash
+php -r "var_dump(App\Support\Manual\AcceptedSourceDocumentTypes::imagesEnabled());"
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-fqcn-leading-backslash.sh.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-fqcn-leading-backslash.sh.txt
new file mode 100644
index 00000000..1ff57a1b
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-fqcn-leading-backslash.sh.txt
@@ -0,0 +1,2 @@
+#!/usr/bin/env bash
+php -r "var_dump(\App\Support\Manual\AcceptedSourceDocumentTypes::imagesEnabled());"
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-heredoc.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-heredoc.php.txt
new file mode 100644
index 00000000..e6494a26
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-heredoc.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+$doc = <<<'TXT'
+props: imageSourceDocumentsEnabled
+TXT;
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-method-declaration-bracketed.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-method-declaration-bracketed.php.txt
new file mode 100644
index 00000000..bfef44a9
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-method-declaration-bracketed.php.txt
@@ -0,0 +1,13 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Manual {
+    class AcceptedSourceDocumentTypes
+    {
+        public static function imagesEnabled(): bool
+        {
+            return true;
+        }
+    }
+}
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-method-declaration.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-method-declaration.php.txt
new file mode 100644
index 00000000..4e1efb63
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-method-declaration.php.txt
@@ -0,0 +1,13 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Manual;
+
+final class AcceptedSourceDocumentTypes
+{
+    public static function imagesEnabled(): bool
+    {
+        return true;
+    }
+}
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-prop.svelte.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-prop.svelte.txt
new file mode 100644
index 00000000..dd039afc
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-prop.svelte.txt
@@ -0,0 +1,3 @@
+<script lang="ts">
+    let { imageSourceDocumentsEnabled } = $props();
+</script>
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-property.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-property.php.txt
new file mode 100644
index 00000000..0a05be58
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-property.php.txt
@@ -0,0 +1,10 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+final class Props
+{
+    public bool $imageSourceDocumentsEnabled = true;
+}
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-self-call.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-self-call.php.txt
new file mode 100644
index 00000000..86aaa382
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-self-call.php.txt
@@ -0,0 +1,13 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Manual;
+
+final class AcceptedSourceDocumentTypes
+{
+    public static function probe(): bool
+    {
+        return self::imagesEnabled();
+    }
+}
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-alias.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-alias.php.txt
new file mode 100644
index 00000000..38079cea
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-alias.php.txt
@@ -0,0 +1,9 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+use App\Support\Manual\AcceptedSourceDocumentTypes as Types;
+
+$enabled = Types::imagesEnabled();
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-fqcn.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-fqcn.php.txt
new file mode 100644
index 00000000..83f908de
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-fqcn.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+$enabled = \App\Support\Manual\AcceptedSourceDocumentTypes::imagesEnabled();
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-groupuse-alias.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-groupuse-alias.php.txt
new file mode 100644
index 00000000..888a8ded
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-groupuse-alias.php.txt
@@ -0,0 +1,9 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+use App\Support\Manual\{AcceptedSourceDocumentTypes as Types};
+
+$enabled = Types::imagesEnabled();
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-relative.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-relative.php.txt
new file mode 100644
index 00000000..c1dd2661
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-relative.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Manual;
+
+$enabled = namespace\AcceptedSourceDocumentTypes::imagesEnabled();
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-same-namespace.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-same-namespace.php.txt
new file mode 100644
index 00000000..dd148464
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-same-namespace.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Manual;
+
+$enabled = AcceptedSourceDocumentTypes::imagesEnabled();
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-use.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-use.php.txt
new file mode 100644
index 00000000..62e82ad2
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-use.php.txt
@@ -0,0 +1,9 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+use App\Support\Manual\AcceptedSourceDocumentTypes;
+
+$enabled = AcceptedSourceDocumentTypes::imagesEnabled();
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-keyword-call.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-keyword-call.php.txt
new file mode 100644
index 00000000..35bff890
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-keyword-call.php.txt
@@ -0,0 +1,13 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Manual;
+
+class AcceptedSourceDocumentTypes
+{
+    public static function probe(): bool
+    {
+        return static::imagesEnabled();
+    }
+}
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-variable.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-variable.php.txt
new file mode 100644
index 00000000..413010bf
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/positive-variable.php.txt
@@ -0,0 +1,5 @@
+<?php
+
+declare(strict_types=1);
+
+$ocr_analysis_enabled = true;
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-broken-php.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-broken-php.php.txt
new file mode 100644
index 00000000..62a2c050
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-broken-php.php.txt
@@ -0,0 +1,5 @@
+<?php
+
+declare(strict_types=1);
+
+$enabled = AcceptedSourceDocumentTypes::imagesEnabled(
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-dynamic-class-static-call.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-dynamic-class-static-call.php.txt
new file mode 100644
index 00000000..0cf214ba
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-dynamic-class-static-call.php.txt
@@ -0,0 +1,5 @@
+<?php
+
+declare(strict_types=1);
+
+$enabled = $cls::imagesEnabled();
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-trait-self-call.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-trait-self-call.php.txt
new file mode 100644
index 00000000..0397585e
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-trait-self-call.php.txt
@@ -0,0 +1,13 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Manual;
+
+trait ImageSupport
+{
+    public static function probe(): bool
+    {
+        return self::imagesEnabled();
+    }
+}
diff --git a/tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-trait-used-by-target.php.txt b/tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-trait-used-by-target.php.txt
new file mode 100644
index 00000000..39dfb2e0
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-trait-used-by-target.php.txt
@@ -0,0 +1,10 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Manual;
+
+final class AcceptedSourceDocumentTypes
+{
+    use ImageSupport;
+}
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/negative-alias-to-target-shortname.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-alias-to-target-shortname.php.txt
new file mode 100644
index 00000000..648f0fed
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-alias-to-target-shortname.php.txt
@@ -0,0 +1,9 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+use App\Other\Foo as RequirePassword;
+
+Route::get('/x', [Controller::class, 'show'])->middleware(RequirePassword::class);
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/negative-dynamic-middleware-value.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-dynamic-middleware-value.php.txt
new file mode 100644
index 00000000..451247cf
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-dynamic-middleware-value.php.txt
@@ -0,0 +1,13 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+/*
+ * middleware 位置の**変数・式**はクラス参照でも文字列リテラルでもないため母集団に入らない
+ * (静的層は沈黙する)。実在する後付け経路 (RouteMiddlewareBinder / RouteThrottleBinder) を
+ * 免除で黙らせないための規則段階の定義であり、許可一覧ではない。
+ */
+$route->middleware($alias);
+$route->middleware('throttle:'.$limiter);
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/negative-negated.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-negated.php.txt
new file mode 100644
index 00000000..23affa4c
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-negated.php.txt
@@ -0,0 +1,5 @@
+<?php
+
+declare(strict_types=1);
+
+Route::post('/x', [Controller::class, 'store'])->middleware('no-password.confirm');
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/negative-other-middleware-class.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-other-middleware-class.php.txt
new file mode 100644
index 00000000..6f025066
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-other-middleware-class.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+Route::get('/x', [Controller::class, 'show'])->middleware(\App\Http\Middleware\RequireRecentAuth::class);
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/negative-php-comment.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-php-comment.php.txt
new file mode 100644
index 00000000..644ca7ff
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-php-comment.php.txt
@@ -0,0 +1,11 @@
+<?php
+
+declare(strict_types=1);
+
+// password.confirm は撤去済み。復活させる場合は ->middleware('password.confirm') を書かないこと。
+
+/**
+ * Fortify 標準の step-up は撤去済み。
+ * かつては ->middleware(['auth', 'password.confirm']) と書いていた。
+ */
+Route::get('/x', [Controller::class, 'show'])->middleware(['auth']);
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/negative-prefix.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-prefix.php.txt
new file mode 100644
index 00000000..b9950a82
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-prefix.php.txt
@@ -0,0 +1,5 @@
+<?php
+
+declare(strict_types=1);
+
+Route::post('/x', [Controller::class, 'store'])->middleware('x-password.confirm');
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/negative-route-name-usage.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-route-name-usage.php.txt
new file mode 100644
index 00000000..fc39efbb
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-route-name-usage.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+Route::get('/user/confirm-password', [Controller::class, 'show'])->name('password.confirm');
+
+$url = route('password.confirm');
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/negative-same-shortname-fqcn.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-same-shortname-fqcn.php.txt
new file mode 100644
index 00000000..041e3b81
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-same-shortname-fqcn.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+Route::get('/x', [Controller::class, 'show'])->middleware(\App\Other\RequirePassword::class);
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/negative-same-shortname-import.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-same-shortname-import.php.txt
new file mode 100644
index 00000000..070e47e5
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-same-shortname-import.php.txt
@@ -0,0 +1,9 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+use App\Other\RequirePassword;
+
+Route::get('/x', [Controller::class, 'show'])->middleware(RequirePassword::class);
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/negative-seo-title-map.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-seo-title-map.php.txt
new file mode 100644
index 00000000..c89645d2
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-seo-title-map.php.txt
@@ -0,0 +1,10 @@
+<?php
+
+declare(strict_types=1);
+
+return [
+    'app_titles' => [
+        'password.confirm' => 'パスワードの確認',
+        'recent-auth.confirm' => '本人確認',
+    ],
+];
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/negative-session-key.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-session-key.php.txt
new file mode 100644
index 00000000..4b9182ab
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-session-key.php.txt
@@ -0,0 +1,5 @@
+<?php
+
+declare(strict_types=1);
+
+Route::post('/x', [Controller::class, 'store'])->middleware(['auth.password_confirmed_at']);
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/negative-suffix.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-suffix.php.txt
new file mode 100644
index 00000000..f85d9bda
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/negative-suffix.php.txt
@@ -0,0 +1,5 @@
+<?php
+
+declare(strict_types=1);
+
+Route::post('/x', [Controller::class, 'store'])->middleware(['password.confirm.store', 'password.confirmation', 'password.confirmed']);
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-alias-registration.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-alias-registration.php.txt
new file mode 100644
index 00000000..f34bb1f4
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-alias-registration.php.txt
@@ -0,0 +1,9 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+use Illuminate\Auth\Middleware\RequirePassword;
+
+$middlewareConfigurator->alias(['password.confirm' => RequirePassword::class]);
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-config-management-middleware.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-config-management-middleware.php.txt
new file mode 100644
index 00000000..eb19fe10
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-config-management-middleware.php.txt
@@ -0,0 +1,9 @@
+<?php
+
+declare(strict_types=1);
+
+return [
+    'passkeys' => [
+        'management_middleware' => ['password.confirm'],
+    ],
+];
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-css-id-selector.css.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-css-id-selector.css.txt
new file mode 100644
index 00000000..d77efcec
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-css-id-selector.css.txt
@@ -0,0 +1,3 @@
+#password.confirm {
+    content: "x";
+}
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-css-universal.css.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-css-universal.css.txt
new file mode 100644
index 00000000..50ce0ab9
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-css-universal.css.txt
@@ -0,0 +1,3 @@
+* {
+    content: "password.confirm";
+}
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-kernel-property.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-kernel-property.php.txt
new file mode 100644
index 00000000..6cd4253e
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-kernel-property.php.txt
@@ -0,0 +1,11 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+final class Kernel
+{
+    /** @var array<string, list<string>> */
+    protected array $middlewareGroups = ['web' => ['password.confirm']];
+}
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-arg.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-arg.php.txt
new file mode 100644
index 00000000..58ad4fb9
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-arg.php.txt
@@ -0,0 +1,5 @@
+<?php
+
+declare(strict_types=1);
+
+Route::get('/x', [Controller::class, 'show'])->middleware('password.confirm');
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-array.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-array.php.txt
new file mode 100644
index 00000000..977c52b1
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-array.php.txt
@@ -0,0 +1,5 @@
+<?php
+
+declare(strict_types=1);
+
+Route::get('/x', [Controller::class, 'show'])->middleware(['auth', 'password.confirm']);
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-alias.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-alias.php.txt
new file mode 100644
index 00000000..0f2a58c4
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-alias.php.txt
@@ -0,0 +1,9 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+use Illuminate\Auth\Middleware\RequirePassword as RP;
+
+Route::get('/x', [Controller::class, 'show'])->middleware(RP::class);
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-case.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-case.php.txt
new file mode 100644
index 00000000..5a062fed
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-case.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+Route::get('/x', [Controller::class, 'show'])->middleware(\illuminate\auth\middleware\requirepassword::class);
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-fqcn.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-fqcn.php.txt
new file mode 100644
index 00000000..c67b56dd
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-fqcn.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+Route::get('/x', [Controller::class, 'show'])->middleware(\Illuminate\Auth\Middleware\RequirePassword::class);
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-groupuse.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-groupuse.php.txt
new file mode 100644
index 00000000..5acdb6a5
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-groupuse.php.txt
@@ -0,0 +1,9 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+use Illuminate\Auth\Middleware\{RequirePassword as RP};
+
+Route::get('/x', [Controller::class, 'show'])->middleware(RP::class);
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-relative.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-relative.php.txt
new file mode 100644
index 00000000..29256b3c
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-relative.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Illuminate\Auth\Middleware;
+
+Route::get('/x', [Controller::class, 'show'])->middleware(namespace\RequirePassword::class);
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class.php.txt
new file mode 100644
index 00000000..64e2a9c1
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class.php.txt
@@ -0,0 +1,9 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+use Illuminate\Auth\Middleware\RequirePassword;
+
+Route::get('/x', [Controller::class, 'show'])->middleware(RequirePassword::class);
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-param.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-param.php.txt
new file mode 100644
index 00000000..b69fa677
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-param.php.txt
@@ -0,0 +1,5 @@
+<?php
+
+declare(strict_types=1);
+
+Route::get('/x', [Controller::class, 'show'])->middleware('password.confirm:web');
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-noext.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-noext.txt
new file mode 100644
index 00000000..78672253
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-noext.txt
@@ -0,0 +1,2 @@
+#!/usr/bin/env bash
+exec php artisan route:list --name=password.confirm
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-shell.sh.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-shell.sh.txt
new file mode 100644
index 00000000..bf40837d
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-shell.sh.txt
@@ -0,0 +1,3 @@
+#!/usr/bin/env bash
+set -euo pipefail
+php artisan route:list --name=password.confirm
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-svelte-markup.svelte.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-svelte-markup.svelte.txt
new file mode 100644
index 00000000..2e6271ca
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-svelte-markup.svelte.txt
@@ -0,0 +1 @@
+<a href="/x" data-route="password.confirm">再認証</a>
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-svelte-script.svelte.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-svelte-script.svelte.txt
new file mode 100644
index 00000000..7a4a6d6b
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-svelte-script.svelte.txt
@@ -0,0 +1,3 @@
+<script lang="ts">
+    const routeName = 'password.confirm';
+</script>
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-svelte-style.svelte.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-svelte-style.svelte.txt
new file mode 100644
index 00000000..6bf3d8ff
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-svelte-style.svelte.txt
@@ -0,0 +1,5 @@
+<style>
+    .step-up::after {
+        content: 'password.confirm';
+    }
+</style>
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-ts-generator.ts.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-ts-generator.ts.txt
new file mode 100644
index 00000000..ab2a7c15
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-ts-generator.ts.txt
@@ -0,0 +1,5 @@
+export const registry = {
+    *names(): Generator<string> {
+        yield 'password.confirm';
+    },
+};
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/positive-workflow.yaml.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-workflow.yaml.txt
new file mode 100644
index 00000000..ed953495
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/positive-workflow.yaml.txt
@@ -0,0 +1,5 @@
+name: ci
+jobs:
+  smoke:
+    steps:
+      - run: php artisan route:list --name=password.confirm
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/unresolved-broken-php.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/unresolved-broken-php.php.txt
new file mode 100644
index 00000000..820153a6
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/unresolved-broken-php.php.txt
@@ -0,0 +1,5 @@
+<?php
+
+declare(strict_types=1);
+
+Route::get('/x', [Controller::class, 'show'
diff --git a/tests/Architecture/fixtures/surface-removal/password-confirm/unresolved-dynamic-middleware-class.php.txt b/tests/Architecture/fixtures/surface-removal/password-confirm/unresolved-dynamic-middleware-class.php.txt
new file mode 100644
index 00000000..3103ae7c
--- /dev/null
+++ b/tests/Architecture/fixtures/surface-removal/password-confirm/unresolved-dynamic-middleware-class.php.txt
@@ -0,0 +1,7 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Fixture;
+
+$route->middleware($cls::class);
diff --git a/tests/Support/SurfaceRemoval/ContentClassification.php b/tests/Support/SurfaceRemoval/ContentClassification.php
new file mode 100644
index 00000000..9f33d3bb
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/ContentClassification.php
@@ -0,0 +1,23 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/**
+ * 走査対象ファイルの内容の分類 (バイナリ判定と UTF-8 検証の単一出典が返す値)。
+ *
+ * ★`RemovedSurfaceScanTargets::classifyContents()` **だけ**がこの値を作る。
+ *   同じ判定を 2 本持たないための型である。
+ */
+enum ContentClassification
+{
+    /** NUL を含まず UTF-8 として妥当 (実走査母集団へ入る)。 */
+    case Text;
+
+    /** NUL バイトを含む (母集団から外すが、利用側 gate は 0 件を要求する)。 */
+    case Binary;
+
+    /** NUL は無いが UTF-8 として不正 (未解決として gate を落とす)。 */
+    case InvalidUtf8;
+}
diff --git a/tests/Support/SurfaceRemoval/MethodReference.php b/tests/Support/SurfaceRemoval/MethodReference.php
new file mode 100644
index 00000000..28b636d1
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/MethodReference.php
@@ -0,0 +1,21 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/** 指定クラスのメソッド宣言 / 静的呼び出し。 */
+final readonly class MethodReference
+{
+    public function __construct(
+        public string $relative,
+        public int $line,
+        public MethodReferenceKind $kind,
+    ) {}
+
+    /** 違反説明の 1 行 (gate の失敗メッセージ用)。 */
+    public function describe(): string
+    {
+        return sprintf('%s:%d %s', $this->relative, $this->line, $this->kind->name);
+    }
+}
diff --git a/tests/Support/SurfaceRemoval/MethodReferenceKind.php b/tests/Support/SurfaceRemoval/MethodReferenceKind.php
new file mode 100644
index 00000000..c67c3877
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/MethodReferenceKind.php
@@ -0,0 +1,15 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/** 対象クラスのメソッドに触れる形。 */
+enum MethodReferenceKind
+{
+    /** 対象クラスの本体に書かれたメソッド宣言。 */
+    case Declaration;
+
+    /** 対象クラスを受け手にした静的呼び出し (`Types::imagesEnabled()`)。 */
+    case StaticCall;
+}
diff --git a/tests/Support/SurfaceRemoval/MiddlewareReference.php b/tests/Support/SurfaceRemoval/MiddlewareReference.php
new file mode 100644
index 00000000..7c165feb
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/MiddlewareReference.php
@@ -0,0 +1,30 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/** middleware 位置に現れた参照。alias 文字列とクラス参照を区別する。 */
+final readonly class MiddlewareReference
+{
+    public function __construct(
+        public string $relative,
+        public int $line,
+        public MiddlewareReferenceKind $kind,
+        /** alias 文字列、または `X::class` の受け手の原文。 */
+        public string $value,
+        /** `ClassReference` のときの解決済み完全修飾名 (解決できない形は未解決へ入るので常に非 null)。 */
+        public ?string $resolvedFqcn,
+    ) {}
+
+    /** 違反説明の 1 行 (gate の失敗メッセージ用)。 */
+    public function describe(): string
+    {
+        return sprintf(
+            '%s:%d %s',
+            $this->relative,
+            $this->line,
+            $this->resolvedFqcn ?? $this->value,
+        );
+    }
+}
diff --git a/tests/Support/SurfaceRemoval/MiddlewareReferenceKind.php b/tests/Support/SurfaceRemoval/MiddlewareReferenceKind.php
new file mode 100644
index 00000000..80d845b0
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/MiddlewareReferenceKind.php
@@ -0,0 +1,15 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/** middleware 位置に現れた参照の種別。 */
+enum MiddlewareReferenceKind
+{
+    /** 文字列リテラル (alias 名。`password.confirm` / `password.confirm:web`)。 */
+    case AliasString;
+
+    /** `X::class` 形のクラス参照 (完全修飾名へ解決済み)。 */
+    case ClassReference;
+}
diff --git a/tests/Support/SurfaceRemoval/Occurrence.php b/tests/Support/SurfaceRemoval/Occurrence.php
new file mode 100644
index 00000000..c036a45a
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/Occurrence.php
@@ -0,0 +1,22 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/** 撤去語の出現 (どこに何行目で出たか)。 */
+final readonly class Occurrence
+{
+    public function __construct(
+        public string $relative,
+        public int $line,
+        /** 一致した run (診断用の原文)。 */
+        public string $matched,
+    ) {}
+
+    /** 違反説明の 1 行 (gate の失敗メッセージ用)。 */
+    public function describe(): string
+    {
+        return sprintf('%s:%d %s', $this->relative, $this->line, $this->matched);
+    }
+}
diff --git a/tests/Support/SurfaceRemoval/PhpNameResolver.php b/tests/Support/SurfaceRemoval/PhpNameResolver.php
new file mode 100644
index 00000000..6c9af0d3
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/PhpNameResolver.php
@@ -0,0 +1,479 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/**
+ * PHP のクラス参照を**完全修飾名へ解決する**(AGENTS.md「静的検査の共通規約」(a))。
+ *
+ * 短名一致は別名つき取り込み 1 つで検査が黙り、末尾の要素だけの一致は同名の別クラスを拾う。
+ * 本クラスは `Tests\Support\PhpTokenScan::normalize()` が返すトークン列を 1 度走査して
+ * 「その位置での namespace / 取り込み表 / 囲んでいる型」を索引し、参照位置のトークンから
+ * 完全修飾名を返す。
+ *
+ * ★**対応する名前構文** (これ以外は解決しない = null を返す):
+ *   - `namespace A\B;` (文形) と `namespace A\B { … }` (ブロック形)、1 ファイル内の複数 namespace
+ *   - `use A\B\C;` / `use A\B\C as D;` / group use `use A\B\{C, D as E};`
+ *   - `T_NAME_FULLY_QUALIFIED` (`\A\B\C`) / `T_NAME_QUALIFIED` (`A\B\C`) /
+ *     `T_NAME_RELATIVE` (`namespace\C`) / `T_STRING` (短名)
+ *   - class / enum / interface の中の `self` (現在の宣言クラス) /
+ *     `static` (遅延静的束縛で別クラスになり得るが**現在の宣言クラスを候補として保守的に扱う**。
+ *     拾いすぎる方向は可・見逃す方向は不可) / `parent` (`extends` を解ければそれ、解けなければ**未解決**)
+ * ★**trait の中の `self` / `static` / `parent` はすべて未解決にする**。trait のメンバーは
+ *   利用クラスへ組み込まれるため `self` 等の意味は**利用クラスに依存する** (PHP の意味論)。
+ *   trait 自身の完全修飾名へ確定すると誤った解決済み結果になり、対象メソッドの呼び出しを
+ *   trait に置いて対象クラスが `use` する形が**静かに通ってしまう** (fail-open)。
+ *   v1 は trait-use graph を実装しないので fail-closed で落とす。
+ * ★**保証しないもの**: 動的なクラス名 (`$cls::` / 文字列変数) は解決しない (null を返し、
+ *   利用側 gate が未解決として落とす)。`use function` / `use const` は取り込み表に入れない
+ *   (クラス参照ではないため対象外)。取り込み表は **namespace 区間全体へ一様に適用する**
+ *   (使用位置より後ろに書かれた `use` も効く = 拾いすぎる方向)。
+ *   条件分岐の中で宣言されたクラスや、`class_alias()` による別名は扱わない。
+ *
+ * @phpstan-type NormalizedToken array{id: int|null, text: string, line: int}
+ * @phpstan-type NamespaceSegment array{start: int, namespace: string, uses: array<string, string>}
+ * @phpstan-type TypeSegment array{start: int, end: int, fqcn: string, isTrait: bool, parentRaw: string|null, parentId: int|null, usesTraits: bool}
+ */
+final class PhpNameResolver
+{
+    /**
+     * @param  list<NamespaceSegment>  $namespaceSegments
+     * @param  list<TypeSegment>  $typeSegments
+     */
+    private function __construct(
+        private readonly array $namespaceSegments,
+        private readonly array $typeSegments,
+    ) {}
+
+    /**
+     * トークン列を索引する。
+     *
+     * @param  list<NormalizedToken>  $tokens
+     */
+    public static function analyze(array $tokens): self
+    {
+        /** @var list<NamespaceSegment> $namespaceSegments */
+        $namespaceSegments = [['start' => 0, 'namespace' => '', 'uses' => []]];
+        /** @var list<TypeSegment> $typeSegments */
+        $typeSegments = [];
+        /** @var list<TypeSegment> $openTypes */
+        $openTypes = [];
+        /** @var TypeSegment|null $pendingType */
+        $pendingType = null;
+        $depth = 0;
+        $count = count($tokens);
+
+        for ($i = 0; $i < $count; $i++) {
+            $id = $tokens[$i]['id'];
+            $text = $tokens[$i]['text'];
+
+            if (self::isOpeningBrace($id, $text)) {
+                $depth++;
+                if ($pendingType !== null) {
+                    $pendingType['start'] = $i;
+                    $pendingType['end'] = $depth; // 一時的に body の brace 深さを保持する
+                    $openTypes[] = $pendingType;
+                    $pendingType = null;
+                }
+
+                continue;
+            }
+
+            if ($id === null && $text === '}') {
+                $last = count($openTypes) - 1;
+                if ($last >= 0 && $openTypes[$last]['end'] === $depth) {
+                    $closed = $openTypes[$last];
+                    array_pop($openTypes);
+                    $closed['end'] = $i;
+                    $typeSegments[] = $closed;
+                }
+                $depth--;
+
+                continue;
+            }
+
+            if ($id === T_NAMESPACE) {
+                $name = '';
+                $j = $i + 1;
+                while ($j < $count && in_array($tokens[$j]['id'], [T_STRING, T_NAME_QUALIFIED], true)) {
+                    $name .= $tokens[$j]['text'];
+                    $j++;
+                }
+                $namespaceSegments[] = ['start' => $i, 'namespace' => trim($name, '\\'), 'uses' => []];
+                $i = $j - 1;
+
+                continue;
+            }
+
+            if ($id === T_USE) {
+                $isClosureUse = isset($tokens[$i + 1]) && $tokens[$i + 1]['id'] === null && $tokens[$i + 1]['text'] === '(';
+                if ($isClosureUse) {
+                    continue;
+                }
+                if ($openTypes !== []) {
+                    // 型の本体に書かれた `use` = trait の取り込み (v1 では追跡しない)
+                    $openTypes[count($openTypes) - 1]['usesTraits'] = true;
+
+                    continue;
+                }
+                $i = self::parseImport($tokens, $i, $namespaceSegments);
+
+                continue;
+            }
+
+            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
+                if ($i > 0 && $tokens[$i - 1]['id'] === T_DOUBLE_COLON) {
+                    continue; // `Foo::class`
+                }
+                if (! isset($tokens[$i + 1]) || $tokens[$i + 1]['id'] !== T_STRING) {
+                    continue; // 無名クラス
+                }
+                $name = $tokens[$i + 1]['text'];
+                $namespace = $namespaceSegments[count($namespaceSegments) - 1]['namespace'];
+                $parent = self::readExtends($tokens, $i + 2);
+                $pendingType = [
+                    'start' => $i,
+                    'end' => 0,
+                    'fqcn' => $namespace === '' ? $name : $namespace.'\\'.$name,
+                    'isTrait' => $id === T_TRAIT,
+                    'parentRaw' => $parent['raw'],
+                    'parentId' => $parent['id'],
+                    'usesTraits' => false,
+                ];
+            }
+        }
+
+        // 閉じ括弧が足りない (構文検証済みなら起きない) 場合も型区間を捨てない
+        foreach (array_reverse($openTypes) as $open) {
+            $open['end'] = $count - 1;
+            $typeSegments[] = $open;
+        }
+
+        return new self($namespaceSegments, $typeSegments);
+    }
+
+    /**
+     * 位置 `$index` を囲む型 (最も内側)。
+     *
+     * @return TypeSegment|null
+     */
+    public function typeAt(int $index): ?array
+    {
+        $innermost = null;
+        foreach ($this->typeSegments as $segment) {
+            if ($segment['start'] <= $index && $index <= $segment['end']) {
+                if ($innermost === null || $segment['start'] > $innermost['start']) {
+                    $innermost = $segment;
+                }
+            }
+        }
+
+        return $innermost;
+    }
+
+    /**
+     * 対象の完全修飾名を持つ型の宣言 (大小無視)。
+     *
+     * @return list<TypeSegment>
+     */
+    public function typeDeclarationsOf(string $fqcn): array
+    {
+        $needle = strtolower(ltrim($fqcn, '\\'));
+
+        return array_values(array_filter(
+            $this->typeSegments,
+            static fn (array $segment): bool => strtolower($segment['fqcn']) === $needle,
+        ));
+    }
+
+    /**
+     * 参照位置のトークンから完全修飾名を解決する。**解決できない形は null**。
+     *
+     * @param  list<NormalizedToken>  $tokens
+     */
+    public function resolveClassReference(array $tokens, int $index): ?string
+    {
+        if (! isset($tokens[$index])) {
+            return null;
+        }
+        $id = $tokens[$index]['id'];
+        $text = $tokens[$index]['text'];
+        $lower = strtolower($text);
+
+        if ($id === T_STATIC || ($id === T_STRING && ($lower === 'static' || $lower === 'self'))) {
+            $type = $this->typeAt($index);
+            if ($type === null || $type['isTrait']) {
+                return null;
+            }
+
+            return $type['fqcn'];
+        }
+
+        if ($id === T_STRING && $lower === 'parent') {
+            $type = $this->typeAt($index);
+            if ($type === null || $type['isTrait'] || $type['parentRaw'] === null) {
+                return null;
+            }
+
+            return $this->resolveRawName($type['parentRaw'], $type['parentId'], $index);
+        }
+
+        if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
+            return $this->resolveRawName($text, $id, $index);
+        }
+
+        return null;
+    }
+
+    /** 名前の原文を、位置 `$index` の namespace と取り込み表で解決する。 */
+    private function resolveRawName(string $raw, ?int $id, int $index): ?string
+    {
+        $namespace = $this->namespaceAt($index);
+        $uses = $this->usesAt($index);
+
+        if ($id === T_NAME_FULLY_QUALIFIED) {
+            return ltrim($raw, '\\');
+        }
+
+        if ($id === T_NAME_RELATIVE) {
+            $rest = ltrim(substr($raw, strlen('namespace')), '\\');
+
+            return $namespace === '' ? $rest : $namespace.'\\'.$rest;
+        }
+
+        if ($id === T_NAME_QUALIFIED) {
+            $parts = explode('\\', $raw);
+            $first = strtolower($parts[0]);
+            if (isset($uses[$first])) {
+                array_shift($parts);
+
+                return $parts === [] ? $uses[$first] : $uses[$first].'\\'.implode('\\', $parts);
+            }
+
+            return $namespace === '' ? $raw : $namespace.'\\'.$raw;
+        }
+
+        if ($id === T_STRING) {
+            $lower = strtolower($raw);
+            if (isset($uses[$lower])) {
+                return $uses[$lower];
+            }
+
+            return $namespace === '' ? $raw : $namespace.'\\'.$raw;
+        }
+
+        return null;
+    }
+
+    /** 位置 `$index` の namespace。 */
+    private function namespaceAt(int $index): string
+    {
+        return $this->segmentAt($index)['namespace'];
+    }
+
+    /**
+     * 位置 `$index` の取り込み表 (別名を小文字化したキー => 完全修飾名)。
+     *
+     * @return array<string, string>
+     */
+    private function usesAt(int $index): array
+    {
+        return $this->segmentAt($index)['uses'];
+    }
+
+    /** @return NamespaceSegment */
+    private function segmentAt(int $index): array
+    {
+        $current = $this->namespaceSegments[0];
+        foreach ($this->namespaceSegments as $segment) {
+            if ($segment['start'] <= $index) {
+                $current = $segment;
+            }
+        }
+
+        return $current;
+    }
+
+    /**
+     * `use` 文 (group use を含む) を取り込み表へ登録し、文末のトークン位置を返す。
+     *
+     * @param  list<NormalizedToken>  $tokens
+     * @param  list<NamespaceSegment>  $segments
+     */
+    private static function parseImport(array $tokens, int $useIndex, array &$segments): int
+    {
+        $count = count($tokens);
+        $j = $useIndex + 1;
+
+        if (isset($tokens[$j]) && in_array($tokens[$j]['id'], [T_FUNCTION, T_CONST], true)) {
+            // `use function` / `use const` はクラス参照ではないので取り込み表に入れない
+            return self::skipToStatementEnd($tokens, $j);
+        }
+
+        $segmentIndex = count($segments) - 1;
+
+        while ($j < $count) {
+            $name = self::readName($tokens, $j);
+            if ($name === null) {
+                break;
+            }
+            $j = $name['next'];
+
+            // group use は `T_NAME_QUALIFIED` + `T_NS_SEPARATOR` + `{` の 3 トークンで始まる
+            $isGroupUse = isset($tokens[$j], $tokens[$j + 1])
+                && $tokens[$j]['id'] === T_NS_SEPARATOR
+                && $tokens[$j + 1]['id'] === null
+                && $tokens[$j + 1]['text'] === '{';
+
+            if ($isGroupUse) {
+                // group use: `use A\B\{C, D as E};`
+                $prefix = rtrim($name['text'], '\\');
+                $j += 2;
+                while ($j < $count) {
+                    if ($tokens[$j]['id'] === null && $tokens[$j]['text'] === '}') {
+                        $j++;
+                        break;
+                    }
+                    if ($tokens[$j]['id'] === null && $tokens[$j]['text'] === ',') {
+                        $j++;
+
+                        continue;
+                    }
+                    if (in_array($tokens[$j]['id'], [T_FUNCTION, T_CONST], true)) {
+                        $j++;
+
+                        continue;
+                    }
+                    $item = self::readName($tokens, $j);
+                    if ($item === null) {
+                        $j++;
+
+                        continue;
+                    }
+                    $j = $item['next'];
+                    $alias = self::readAlias($tokens, $j);
+                    $j = $alias['next'];
+                    $fqcn = $prefix.'\\'.ltrim($item['text'], '\\');
+                    $segments[$segmentIndex]['uses'][strtolower($alias['name'] ?? self::shortName($fqcn))] = $fqcn;
+                }
+
+                return self::skipToStatementEnd($tokens, $j);
+            }
+
+            $alias = self::readAlias($tokens, $j);
+            $j = $alias['next'];
+            $fqcn = ltrim($name['text'], '\\');
+            $segments[$segmentIndex]['uses'][strtolower($alias['name'] ?? self::shortName($fqcn))] = $fqcn;
+
+            if (isset($tokens[$j]) && $tokens[$j]['id'] === null && $tokens[$j]['text'] === ',') {
+                $j++;
+
+                continue;
+            }
+            break;
+        }
+
+        return self::skipToStatementEnd($tokens, $j);
+    }
+
+    /**
+     * `extends` の名前を読む (`{` の手前まで)。
+     *
+     * @param  list<NormalizedToken>  $tokens
+     * @return array{raw: string|null, id: int|null}
+     */
+    private static function readExtends(array $tokens, int $from): array
+    {
+        $count = count($tokens);
+        for ($k = $from; $k < $count; $k++) {
+            if (self::isOpeningBrace($tokens[$k]['id'], $tokens[$k]['text'])) {
+                break;
+            }
+            if ($tokens[$k]['id'] === T_EXTENDS) {
+                $name = self::readName($tokens, $k + 1);
+                if ($name === null) {
+                    break;
+                }
+
+                return ['raw' => $name['text'], 'id' => $name['id']];
+            }
+        }
+
+        return ['raw' => null, 'id' => null];
+    }
+
+    /**
+     * 名前トークンを 1 つ読む。
+     *
+     * @param  list<NormalizedToken>  $tokens
+     * @return array{text: string, id: int, next: int}|null
+     */
+    private static function readName(array $tokens, int $index): ?array
+    {
+        if (! isset($tokens[$index])) {
+            return null;
+        }
+        $id = $tokens[$index]['id'];
+        if (! in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
+            return null;
+        }
+
+        /** @var int $id */
+        return ['text' => $tokens[$index]['text'], 'id' => $id, 'next' => $index + 1];
+    }
+
+    /**
+     * `as X` を読む (無ければ name = null)。
+     *
+     * @param  list<NormalizedToken>  $tokens
+     * @return array{name: string|null, next: int}
+     */
+    private static function readAlias(array $tokens, int $index): array
+    {
+        if (isset($tokens[$index], $tokens[$index + 1])
+            && $tokens[$index]['id'] === T_AS
+            && $tokens[$index + 1]['id'] === T_STRING) {
+            return ['name' => $tokens[$index + 1]['text'], 'next' => $index + 2];
+        }
+
+        return ['name' => null, 'next' => $index];
+    }
+
+    /** 完全修飾名の短名。 */
+    private static function shortName(string $fqcn): string
+    {
+        $position = strrpos($fqcn, '\\');
+
+        return $position === false ? $fqcn : substr($fqcn, $position + 1);
+    }
+
+    /**
+     * `;` までスキップする (その位置を返す)。
+     *
+     * @param  list<NormalizedToken>  $tokens
+     */
+    private static function skipToStatementEnd(array $tokens, int $from): int
+    {
+        $count = count($tokens);
+        for ($k = $from; $k < $count; $k++) {
+            if ($tokens[$k]['id'] === null && $tokens[$k]['text'] === ';') {
+                return $k;
+            }
+        }
+
+        return $count - 1;
+    }
+
+    /**
+     * 開き波括弧か (文字列補間が開く `{` を含める。閉じは素の `}` なので数が合う)。
+     */
+    private static function isOpeningBrace(?int $id, string $text): bool
+    {
+        if ($id === null) {
+            return $text === '{';
+        }
+
+        return in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
+    }
+}
diff --git a/tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php b/tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php
new file mode 100644
index 00000000..db3e4996
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php
@@ -0,0 +1,242 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+use RuntimeException;
+use Symfony\Component\Process\Process;
+
+/**
+ * 撤去物の不在 gate が共有する**走査根と実走査母集団**の単一出典。
+ *
+ * ★走査根 (8 本): `.github` / `app` / `bootstrap` / `config` / `lang` / `resources` /
+ *   `routes` / `scripts`。`.github` と `scripts` は家系の正典 v1 が**必須**にしている
+ *   (撤去直後に CI 設定へ参照が残り CI ジョブが全滅した実測事故の教訓)。
+ * ★`database/migrations` は**含めない**。撤去した表の名前は移行履歴に必ず残るため、
+ *   含めると原理的に赤くなる (正典 v1 の明文)。
+ * ★母集団は**拡張子で絞らない**。`scripts/` には拡張子なしの実行ファイルが実在し、
+ *   拡張子の許可集合方式ではそれらが落ちて上記の事故をそのまま再現する。
+ * ★確定は**この 1 経路だけ**で行う (順序を固定する):
+ *     git 追跡下の列挙 → 通常ファイルとして読めるか (失敗は unresolved)
+ *     → symlink の解決先がリポジトリ内か (外なら unresolved)
+ *     → NUL 判定 (含むなら binaryExcluded) → UTF-8 検証 (不正は unresolved)
+ *     → 実走査母集団へ登録
+ *   **数える集合は本体の検査が実際に走査した集合と同一**である (別に数え直さない)。
+ * ★**fail-open を作らない**: git 追跡下にあるのに通常ファイルとして読めないパスを
+ *   `continue` で捨てない (削除途中 / 壊れた symlink に撤去語があると検査から消えるため)。
+ *   必ず `unresolved` へ理由つきで登録する。
+ * ★**バイナリ除外は無言で許容しない**: 利用側 gate は `binaryExcluded === []` を
+ *   不変条件にする (NUL を 1 つ入れて静的層を迂回する経路を塞ぐ)。
+ * ★**保証しないもの**: git 未追跡のファイルは列挙しない
+ *   (gate が守る境界は commit / CI であり、そこでは必ず追跡下にある)。
+ *   走査根の外 (`tests/` / `docs/` / `database/` 等) は見ない。
+ * ★`Tests\Support\TrackedPhpSourceFiles` との関係: あちらは拡張子 `.php` に限った
+ *   リポジトリ全体の全数列挙で、本クラスは**同じ作法 (`git ls-files`) で母集団を
+ *   全ファイルへ広げ、走査根を 8 本へ絞った兄弟**である。列挙を 2 本持つのではなく
+ *   対象の定義が違う。
+ */
+final class RemovedSurfaceScanTargets
+{
+    /** @var list<string> 走査根 (リポジトリルート相対)。 */
+    private const array ROOT_DIRECTORIES = [
+        '.github', 'app', 'bootstrap', 'config', 'lang', 'resources', 'routes', 'scripts',
+    ];
+
+    /**
+     * 各根に必ず含まれる代表パス (root 割当 / パス計算の誤りを検出する pin)。
+     *
+     * @var array<string, string>
+     */
+    public const array REPRESENTATIVE_PATHS = [
+        '.github' => '.github/workflows/ci.yml',
+        'app' => 'app/Providers/FortifyServiceProvider.php',
+        'bootstrap' => 'bootstrap/app.php',
+        'config' => 'config/seo.php',
+        'lang' => 'lang/ja/validation.php',
+        'resources' => 'resources/js/pages/Settings/Security.svelte',
+        'routes' => 'routes/web.php',
+        'scripts' => 'scripts/ci/drop-test-db.php',
+    ];
+
+    /**
+     * 確定済みの実走査母集団 (プロセス内で 1 度だけ確定する)。
+     *
+     * ★2 つの gate が同じ母集団を共有するためのメモ化であり、判定を持たない。
+     */
+    private static ?ScanPopulation $memoizedPopulation = null;
+
+    /** インスタンス化しない (純関数の置き場)。 */
+    private function __construct() {}
+
+    /** リポジトリルート (テスト実行時の base path)。 */
+    public static function repositoryRoot(): string
+    {
+        $root = realpath(__DIR__.'/../../..');
+        if (! is_string($root)) {
+            throw new RuntimeException('リポジトリルートを解決できません');
+        }
+
+        return $root;
+    }
+
+    /**
+     * 走査根 (相対 => 絶対)。**存在しない根は fail-fast**。
+     *
+     * @return array<string, string>
+     */
+    public static function roots(): array
+    {
+        $repositoryRoot = self::repositoryRoot();
+        $roots = [];
+        foreach (self::ROOT_DIRECTORIES as $relative) {
+            $absolute = realpath($repositoryRoot.'/'.$relative);
+            if (! is_string($absolute)) {
+                throw new RuntimeException("走査根を解決できません: {$relative}");
+            }
+            $roots[$relative] = $absolute;
+        }
+
+        return $roots;
+    }
+
+    /**
+     * 解決済みの絶対パスがリポジトリルート配下かどうか (純関数。自己検証の seam)。
+     *
+     * ★`population()` も自己検証も必ずこの関数を通す。symlink 判定を `population()` 内へ
+     *   閉じ込めると、`git ls-files` の母集団外から確かめる手立てが無くなる。
+     */
+    public static function isPathInsideRepository(string $repositoryRoot, string $resolvedTarget): bool
+    {
+        return str_starts_with($resolvedTarget, rtrim($repositoryRoot, '/').'/');
+    }
+
+    /**
+     * 内容の分類 (純関数。**`population()` も自己検証も必ずここを通る**)。
+     *
+     * ★同じ判定を 2 本持たない。NUL 判定と UTF-8 検証を 1 つの入口に閉じることで、
+     *   見本 (走査根の外に置く) からも実母集団からも同じ経路で確かめられる。
+     */
+    public static function classifyContents(string $contents): ContentClassification
+    {
+        if (str_contains($contents, "\0")) {
+            return ContentClassification::Binary;
+        }
+        if (! mb_check_encoding($contents, 'UTF-8')) {
+            return ContentClassification::InvalidUtf8;
+        }
+
+        return ContentClassification::Text;
+    }
+
+    /** 実走査母集団を確定する (唯一の経路)。 */
+    public static function population(): ScanPopulation
+    {
+        if (self::$memoizedPopulation instanceof ScanPopulation) {
+            return self::$memoizedPopulation;
+        }
+
+        $repositoryRoot = self::repositoryRoot();
+        $files = [];
+        $unresolved = [];
+        $binaryExcluded = [];
+
+        foreach (array_keys(self::roots()) as $root) {
+            foreach (self::trackedPaths($repositoryRoot, $root) as $relative) {
+                $absolute = $repositoryRoot.'/'.$relative;
+
+                if (! is_file($absolute)) {
+                    // ★ git 追跡下なのに通常ファイルとして無い = 無言で捨てない
+                    $unresolved[$relative] = '追跡下だが通常ファイルとして読めない';
+
+                    continue;
+                }
+
+                if (is_link($absolute)) {
+                    // ★ symlink 先がリポジトリ外なら未解決にする (外のファイルを
+                    //   走査対象へ引き込まない / 走査対象から逃がさない)。
+                    $target = realpath($absolute);
+                    if ($target === false || ! self::isPathInsideRepository($repositoryRoot, $target)) {
+                        $unresolved[$relative] = 'symlink がリポジトリ外へ解決される';
+
+                        continue;
+                    }
+                }
+
+                $contents = @file_get_contents($absolute);
+                if ($contents === false) {
+                    $unresolved[$relative] = 'ファイルの読み取りに失敗';
+
+                    continue;
+                }
+
+                // ★分類は必ず classifyContents() を通す (自己検証と同じ経路)
+                $classification = self::classifyContents($contents);
+                if ($classification === ContentClassification::Binary) {
+                    $binaryExcluded[] = $relative;
+
+                    continue;
+                }
+                if ($classification === ContentClassification::InvalidUtf8) {
+                    $unresolved[$relative] = 'UTF-8 として不正';
+
+                    continue;
+                }
+
+                $files[] = new ScannedFile(
+                    root: $root,
+                    relative: $relative,
+                    contents: $contents,
+                    isPhp: str_ends_with($relative, '.php') && ! str_ends_with($relative, '.blade.php'),
+                    extension: self::extensionOf($relative),
+                );
+            }
+        }
+
+        return self::$memoizedPopulation = new ScanPopulation($files, $unresolved, $binaryExcluded);
+    }
+
+    /**
+     * 拡張子 (小文字)。拡張子なしは null。
+     *
+     * ★`.github/workflows/ci.yml` → `yml` / `scripts/codex` → null。
+     *   ドットで始まるだけのファイル (`.gitignore`) は拡張子なしとして扱う。
+     */
+    public static function extensionOf(string $relative): ?string
+    {
+        $basename = basename($relative);
+        $position = strrpos($basename, '.');
+        if ($position === false || $position === 0) {
+            return null;
+        }
+
+        return strtolower(substr($basename, $position + 1));
+    }
+
+    /**
+     * git 追跡下の相対パス (root 配下)。
+     *
+     * ★`is_file()` 判定はここでは**行わない** (捨てずに `unresolved` へ入れるため
+     *   `population()` 側の責務にする)。
+     *
+     * @return list<string>
+     */
+    private static function trackedPaths(string $repositoryRoot, string $root): array
+    {
+        $process = new Process(['git', 'ls-files', '-z', '--', $root], $repositoryRoot);
+        $process->run();
+        if (! $process->isSuccessful()) {
+            throw new RuntimeException('git ls-files の実行に失敗しました: '.$process->getErrorOutput());
+        }
+
+        $paths = [];
+        foreach (explode("\0", $process->getOutput()) as $relative) {
+            if ($relative === '') {
+                continue;
+            }
+            $paths[] = $relative;
+        }
+
+        return $paths;
+    }
+}
diff --git a/tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php b/tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php
new file mode 100644
index 00000000..2ba5668c
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php
@@ -0,0 +1,632 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+use ParseError;
+use Tests\Support\PhpTokenScan;
+
+/**
+ * 撤去語の出現と**構文上の形**だけを返す純関数群 (許可ポリシーを持たない)。
+ *
+ * ★語彙一致は `TOKEN_CHARACTERS` で分割した run のトークン完全一致で判定する
+ *   (正規表現の語境界にも素の部分文字列一致にも頼らない。AGENTS.md「静的検査の共通規約」(e))。
+ *   区切りは**宣言した文字集合の外のすべてのバイト**であり、UTF-8 の多バイト文字は
+ *   すべて区切りになる (ASCII 以外はトークン文字に入れていない)。
+ * ★クラス参照は完全修飾名 (ASCII 大小無視) で突き合わせる (同 (a))。解決は `PhpNameResolver`。
+ * ★PHP は「文字列リテラル」ではなく **lexeme** を見る。文字列リテラルだけに限ると
+ *   `public bool $imageSourceDocumentsEnabled;` や `const OCR_ANALYSIS_ENABLED = true;` での
+ *   復活を検出できない。
+ * ★PHP は**構文検証を先に行い**、`ParseError` を投げるファイルは未解決にする (fail-closed)。
+ *   捕まえるのは `ParseError` **だけ**である (親型 `\Error` まで捕まえると、予期しない実行時障害まで
+ *   「解析未解決」へ変換してしまい、本来テストを落とすべき異常が別の意味に化ける)。
+ *   正規化は既存の単一出典 `Tests\Support\PhpTokenScan::normalize()` を使う (挙動は変えない)。
+ *
+ * ★**保証しないもの (検出力を誇張しない)**:
+ *   - 撤去語を分割して連結する書き方・定数経由の参照・実行時に組み立てた文字列には沈黙する。
+ *   - PHP のコメント / docblock の中では沈黙する (`normalize()` が落とすため)。
+ *   - **middleware 位置に現れる変数・式** (`->middleware($alias)` /
+ *     `->middleware('throttle:'.$limiter)`) は**クラス参照でも文字列リテラルでもない**ため
+ *     母集団に入らない。これは許可一覧ではなく**規則の段階での定義**である
+ *     (`X::class` 構文だけをクラス参照として扱い、受け手が名前でないものは未解決にする)。
+ *     実体化した route については実行時層 (`PasswordConfirmMiddlewareAbsenceTest`) が補完する。
+ *   - `FqcnMethodReference` は `クラス部::メソッド名` が**空白を挟まず**並んでいる形だけを見る。
+ *   - NUL を含むファイルは母集団に入らない (`RemovedSurfaceScanTargets`。利用側は 0 件を要求する)。
+ * ★解決できない形は**未解決として分けて返す** (空配列へ混ぜない)。利用側 gate は必ず
+ *   `ScanOutcome::mergeUnresolved()` で空を要求すること。
+ */
+final class RemovedSurfaceScanner
+{
+    /**
+     * トークン文字の集合。**これ以外のバイトはすべて区切り**である。
+     * 生テキストはこの集合の**最長の連なり (run)** へ分割される。
+     */
+    private const string TOKEN_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.-';
+
+    /**
+     * 完全修飾参照専用のトークン文字集合 (`\` を含み `.` `-` を含まない)。
+     *
+     * `TOKEN_CHARACTERS` では `\` と `:` が区切りになるため、完全修飾参照は複数の run へ割れて
+     * 原理的に一致しない。専用の集合でクラス部とメソッド部を構文的に切り出す。
+     */
+    private const string FQCN_TOKEN_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_\\';
+
+    /**
+     * M1: middleware 位置を作る呼び出し名 (ASCII 大小無視の完全一致)。
+     *
+     * @var list<string>
+     */
+    private const array MIDDLEWARE_CALL_NAMES = [
+        'middleware', 'withoutmiddleware', 'middlewaregroup', 'appendtogroup', 'prependtogroup', 'alias',
+    ];
+
+    /**
+     * M3: middleware 位置を作るプロパティ名 (ASCII 大小無視の完全一致)。
+     *
+     * @var list<string>
+     */
+    private const array MIDDLEWARE_PROPERTY_NAMES = [
+        '$middleware', '$middlewaregroups', '$middlewarepriority',
+    ];
+
+    /** インスタンス化しない (純関数の置き場)。 */
+    private function __construct() {}
+
+    /**
+     * Tier 2: 生テキストを run へ分割してトークン完全一致で走査する。
+     *
+     * @param  list<ScannedFile>  $files
+     * @return ScanOutcome<Occurrence>
+     */
+    public static function scanText(array $files, RemovedTerm $term): ScanOutcome
+    {
+        $occurrences = [];
+
+        foreach ($files as $file) {
+            if ($term->mode === TermMatchMode::FqcnMethodReference) {
+                foreach (self::fqcnMethodOccurrences($file, $term) as $occurrence) {
+                    $occurrences[] = $occurrence;
+                }
+
+                continue;
+            }
+
+            foreach (self::runs($file->contents, self::TOKEN_CHARACTERS) as $run) {
+                if (! self::runMatches($run['text'], $term)) {
+                    continue;
+                }
+                $occurrences[] = new Occurrence(
+                    $file->relative,
+                    self::lineAt($file->contents, $run['offset']),
+                    $run['text'],
+                );
+            }
+        }
+
+        return new ScanOutcome($occurrences, []);
+    }
+
+    /**
+     * Tier 1: PHP の lexeme (識別子・変数・定数・文字列・heredoc・名前) を走査する。
+     *
+     * @param  list<ScannedFile>  $files
+     * @return ScanOutcome<Occurrence>
+     */
+    public static function scanPhpLexemes(array $files, RemovedTerm $term): ScanOutcome
+    {
+        $occurrences = [];
+        /** @var array<string, string> $unresolved */
+        $unresolved = [];
+
+        foreach ($files as $file) {
+            if (! $file->isPhp) {
+                continue;
+            }
+            $tokens = self::tokenize($file, $unresolved);
+            if ($tokens === null) {
+                continue;
+            }
+
+            foreach ($tokens as $token) {
+                $lexeme = self::lexemeOf($token);
+                if ($lexeme === null) {
+                    continue;
+                }
+                foreach (self::runs($lexeme, self::TOKEN_CHARACTERS) as $run) {
+                    if (! self::runMatches($run['text'], $term)) {
+                        continue;
+                    }
+                    $occurrences[] = new Occurrence($file->relative, $token['line'], $run['text']);
+                }
+            }
+        }
+
+        return new ScanOutcome($occurrences, $unresolved);
+    }
+
+    /**
+     * Tier 1: **middleware 位置**に現れる alias 文字列 / クラス参照を返す。
+     *
+     * middleware 位置の定義 (有限。これ以外は母集団に入らない):
+     *   M1 呼び出し名が `middleware` / `withoutMiddleware` / `middlewareGroup` /
+     *      `appendToGroup` / `prependToGroup` / `alias` の引数領域
+     *   M2 キー名が `middleware` を部分文字列として含む (ASCII 大小無視) 配列要素の値の領域
+     *   M3 プロパティ `$middleware` / `$middlewareGroups` / `$middlewarePriority` の初期化式の領域
+     *
+     * 領域からは **`X::class` 構文のクラス参照**と**文字列リテラル**だけを取り出す。
+     * 受け手が名前でない `X::class` (`$cls::class`) は未解決にする。
+     *
+     * @param  list<ScannedFile>  $files
+     * @return ScanOutcome<MiddlewareReference>
+     */
+    public static function scanMiddlewarePositions(array $files): ScanOutcome
+    {
+        $references = [];
+        /** @var array<string, string> $unresolved */
+        $unresolved = [];
+
+        foreach ($files as $file) {
+            if (! $file->isPhp) {
+                continue;
+            }
+            $tokens = self::tokenize($file, $unresolved);
+            if ($tokens === null) {
+                continue;
+            }
+            $resolver = PhpNameResolver::analyze($tokens);
+            $count = count($tokens);
+
+            /** @var array<int, bool> $marks */
+            $marks = [];
+            for ($i = 0; $i < $count; $i++) {
+                $id = $tokens[$i]['id'];
+                $text = $tokens[$i]['text'];
+
+                if ($id === T_STRING
+                    && in_array(strtolower($text), self::MIDDLEWARE_CALL_NAMES, true)
+                    && self::isChar($tokens, $i + 1, '(')) {
+                    $close = self::matchingBracket($tokens, $i + 1);
+                    if ($close === null) {
+                        $unresolved[$file->relative] = 'middleware 呼び出しの括弧の対応を解決できない';
+
+                        continue;
+                    }
+                    self::markRange($marks, $i + 2, $close - 1);
+
+                    continue;
+                }
+
+                if ($id === T_CONSTANT_ENCAPSED_STRING
+                    && isset($tokens[$i + 1])
+                    && $tokens[$i + 1]['id'] === T_DOUBLE_ARROW
+                    && str_contains(strtolower(self::unquote($text)), 'middleware')) {
+                    $end = self::valueEnd($tokens, $i + 2);
+                    if ($end === null) {
+                        $unresolved[$file->relative] = 'middleware キーの値の範囲を解決できない';
+
+                        continue;
+                    }
+                    self::markRange($marks, $i + 2, $end);
+
+                    continue;
+                }
+
+                if ($id === T_VARIABLE
+                    && in_array(strtolower($text), self::MIDDLEWARE_PROPERTY_NAMES, true)
+                    && self::isChar($tokens, $i + 1, '=')) {
+                    $end = self::valueEnd($tokens, $i + 2);
+                    if ($end === null) {
+                        $unresolved[$file->relative] = 'middleware プロパティの初期化式の範囲を解決できない';
+
+                        continue;
+                    }
+                    self::markRange($marks, $i + 2, $end);
+                }
+            }
+
+            for ($i = 0; $i < $count; $i++) {
+                if (! isset($marks[$i])) {
+                    continue;
+                }
+                $token = $tokens[$i];
+
+                if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
+                    $references[] = new MiddlewareReference(
+                        $file->relative,
+                        $token['line'],
+                        MiddlewareReferenceKind::AliasString,
+                        self::unquote($token['text']),
+                        null,
+                    );
+
+                    continue;
+                }
+
+                if ($token['id'] === T_DOUBLE_COLON && isset($tokens[$i + 1]) && $tokens[$i + 1]['id'] === T_CLASS) {
+                    $resolved = $resolver->resolveClassReference($tokens, $i - 1);
+                    if ($resolved === null) {
+                        $unresolved[$file->relative] = sprintf(
+                            'middleware 位置のクラス参照を完全修飾名へ解決できない (行 %d)',
+                            $token['line'],
+                        );
+
+                        continue;
+                    }
+                    $references[] = new MiddlewareReference(
+                        $file->relative,
+                        $token['line'],
+                        MiddlewareReferenceKind::ClassReference,
+                        $tokens[$i - 1]['text'],
+                        ltrim($resolved, '\\'),
+                    );
+                }
+            }
+        }
+
+        return new ScanOutcome($references, $unresolved);
+    }
+
+    /**
+     * Tier 1: 指定クラス (完全修飾名) のメソッド宣言と静的呼び出し。
+     *
+     * ★対象クラスの宣言が trait を取り込んでいたら**未解決**にする (v1 は trait-use graph を
+     *   扱わないため、メソッドが混入しているかを静的に判定できない)。
+     *
+     * @param  list<ScannedFile>  $files
+     * @return ScanOutcome<MethodReference>
+     */
+    public static function scanMethodReferences(array $files, string $fqcn, string $method): ScanOutcome
+    {
+        $targetFqcn = strtolower(ltrim($fqcn, '\\'));
+        $targetMethod = strtolower($method);
+        $references = [];
+        /** @var array<string, string> $unresolved */
+        $unresolved = [];
+
+        foreach ($files as $file) {
+            if (! $file->isPhp) {
+                continue;
+            }
+            $tokens = self::tokenize($file, $unresolved);
+            if ($tokens === null) {
+                continue;
+            }
+            $resolver = PhpNameResolver::analyze($tokens);
+            $count = count($tokens);
+
+            foreach ($resolver->typeDeclarationsOf($fqcn) as $declaration) {
+                if ($declaration['usesTraits']) {
+                    $unresolved[$file->relative] =
+                        '対象クラスが trait を取り込んでおり、メソッドの混入を静的に判定できない';
+                }
+            }
+
+            for ($i = 0; $i < $count; $i++) {
+                $token = $tokens[$i];
+
+                if ($token['id'] === T_FUNCTION) {
+                    $nameIndex = self::isChar($tokens, $i + 1, '&') ? $i + 2 : $i + 1;
+                    if (isset($tokens[$nameIndex])
+                        && $tokens[$nameIndex]['id'] === T_STRING
+                        && strtolower($tokens[$nameIndex]['text']) === $targetMethod) {
+                        $type = $resolver->typeAt($i);
+                        if ($type !== null && strtolower($type['fqcn']) === $targetFqcn) {
+                            $references[] = new MethodReference(
+                                $file->relative,
+                                $token['line'],
+                                MethodReferenceKind::Declaration,
+                            );
+                        }
+                    }
+
+                    continue;
+                }
+
+                if ($token['id'] === T_DOUBLE_COLON
+                    && isset($tokens[$i + 1])
+                    && $tokens[$i + 1]['id'] === T_STRING
+                    && strtolower($tokens[$i + 1]['text']) === $targetMethod) {
+                    $resolved = $resolver->resolveClassReference($tokens, $i - 1);
+                    if ($resolved === null) {
+                        $unresolved[$file->relative] = sprintf(
+                            '`::%s` を伴うクラス参照を完全修飾名へ解決できない (行 %d)',
+                            $method,
+                            $token['line'],
+                        );
+
+                        continue;
+                    }
+                    if (strtolower(ltrim($resolved, '\\')) === $targetFqcn) {
+                        $references[] = new MethodReference(
+                            $file->relative,
+                            $token['line'],
+                            MethodReferenceKind::StaticCall,
+                        );
+                    }
+                }
+            }
+        }
+
+        return new ScanOutcome($references, $unresolved);
+    }
+
+    /**
+     * 生テキストに撤去語と一致する run が含まれるか。
+     *
+     * ★利用側 gate が「middleware 位置の alias 文字列」のような**値**を絞り込むための入口で、
+     *   判定は `scanText()` / `scanPhpLexemes()` と**同じ 1 本のトークン一致**を通る
+     *   (同じ判定を 2 本持たない)。
+     */
+    public static function textMatches(string $text, RemovedTerm $term): bool
+    {
+        if ($term->mode === TermMatchMode::FqcnMethodReference) {
+            return self::fqcnMethodOccurrences(
+                new ScannedFile('memory', 'memory', $text, false, null),
+                $term,
+            ) !== [];
+        }
+
+        foreach (self::runs($text, self::TOKEN_CHARACTERS) as $run) {
+            if (self::runMatches($run['text'], $term)) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * 生テキストを宣言した文字集合の最長連なり (run) へ分割する。
+     *
+     * @return list<array{text: string, offset: int}>
+     */
+    private static function runs(string $text, string $tokenCharacters): array
+    {
+        $runs = [];
+        $length = strlen($text);
+        $start = null;
+
+        for ($i = 0; $i < $length; $i++) {
+            if (str_contains($tokenCharacters, $text[$i])) {
+                if ($start === null) {
+                    $start = $i;
+                }
+
+                continue;
+            }
+            if ($start !== null) {
+                $runs[] = ['text' => substr($text, $start, $i - $start), 'offset' => $start];
+                $start = null;
+            }
+        }
+        if ($start !== null) {
+            $runs[] = ['text' => substr($text, $start), 'offset' => $start];
+        }
+
+        return $runs;
+    }
+
+    /** run が撤去語と一致するか (様式ごとの完全一致)。 */
+    private static function runMatches(string $run, RemovedTerm $term): bool
+    {
+        return match ($term->mode) {
+            TermMatchMode::ExactRun => $run === $term->term,
+            TermMatchMode::RunSegment => in_array($term->term, explode('.', $run), true),
+            // 完全修飾参照は run 単体では判定できない (fqcnMethodOccurrences が担当する)
+            TermMatchMode::FqcnMethodReference => false,
+        };
+    }
+
+    /**
+     * `クラス部::メソッド名` の完全一致 (ASCII 大小無視・先頭 `\` は落として正規化)。
+     *
+     * @return list<Occurrence>
+     */
+    private static function fqcnMethodOccurrences(ScannedFile $file, RemovedTerm $term): array
+    {
+        $parts = explode('::', $term->term, 2);
+        if (count($parts) !== 2) {
+            return [];
+        }
+        $targetClass = strtolower(ltrim($parts[0], '\\'));
+        $targetMethod = strtolower($parts[1]);
+
+        /** @var array<int, string> $endingAt */
+        $endingAt = [];
+        /** @var array<int, string> $startingAt */
+        $startingAt = [];
+        foreach (self::runs($file->contents, self::FQCN_TOKEN_CHARACTERS) as $run) {
+            $startingAt[$run['offset']] = $run['text'];
+            $endingAt[$run['offset'] + strlen($run['text'])] = $run['text'];
+        }
+
+        $occurrences = [];
+        $offset = 0;
+        while (($position = strpos($file->contents, '::', $offset)) !== false) {
+            $offset = $position + 2;
+            if (! isset($endingAt[$position], $startingAt[$position + 2])) {
+                continue;
+            }
+            $class = strtolower(ltrim($endingAt[$position], '\\'));
+            $method = strtolower($startingAt[$position + 2]);
+            if ($class !== $targetClass || $method !== $targetMethod) {
+                continue;
+            }
+            $occurrences[] = new Occurrence(
+                $file->relative,
+                self::lineAt($file->contents, $position),
+                $endingAt[$position].'::'.$startingAt[$position + 2],
+            );
+        }
+
+        return $occurrences;
+    }
+
+    /**
+     * PHP を構文検証してから正規化トークン列を返す。`ParseError` は未解決。
+     *
+     * @param  array<string, string>  $unresolved
+     * @return list<array{id: int|null, text: string, line: int}>|null
+     */
+    private static function tokenize(ScannedFile $file, array &$unresolved): ?array
+    {
+        try {
+            token_get_all($file->contents, TOKEN_PARSE); // ★構文検証のみ (結果は捨てる)
+        } catch (ParseError $error) {                    // ★ParseError だけを捕まえる
+            $unresolved[$file->relative] = 'PHP のトークン化に失敗: '.$error->getMessage();
+
+            return null;
+        }
+
+        return PhpTokenScan::normalize($file->contents);
+    }
+
+    /**
+     * 撤去語と突き合わせる lexeme (対象外のトークンは null)。
+     *
+     * @param  array{id: int|null, text: string, line: int}  $token
+     */
+    private static function lexemeOf(array $token): ?string
+    {
+        return match ($token['id']) {
+            T_VARIABLE => substr($token['text'], 1),
+            T_CONSTANT_ENCAPSED_STRING => self::unquote($token['text']),
+            T_STRING, T_ENCAPSED_AND_WHITESPACE,
+            T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE => $token['text'],
+            default => null,
+        };
+    }
+
+    /** 文字列リテラルの引用符を落とす (エスケープの復元はしない)。 */
+    private static function unquote(string $literal): string
+    {
+        $value = $literal;
+        if ($value !== '' && (strtolower($value[0]) === 'b')) {
+            $value = substr($value, 1);
+        }
+        if (strlen($value) >= 2) {
+            $first = $value[0];
+            $last = $value[strlen($value) - 1];
+            if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
+                $value = substr($value, 1, -1);
+            }
+        }
+
+        return $value;
+    }
+
+    /** バイト位置の行番号 (1 起点)。 */
+    private static function lineAt(string $contents, int $offset): int
+    {
+        return substr_count($contents, "\n", 0, $offset) + 1;
+    }
+
+    /**
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function isChar(array $tokens, int $index, string $char): bool
+    {
+        return isset($tokens[$index]) && $tokens[$index]['id'] === null && $tokens[$index]['text'] === $char;
+    }
+
+    /**
+     * 開き括弧に対応する閉じ括弧の位置 (対応が取れなければ null)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function matchingBracket(array $tokens, int $openIndex): ?int
+    {
+        $depth = 0;
+        $count = count($tokens);
+        for ($k = $openIndex; $k < $count; $k++) {
+            $delta = self::bracketDelta($tokens[$k]);
+            if ($delta > 0) {
+                $depth++;
+
+                continue;
+            }
+            if ($delta < 0) {
+                $depth--;
+                if ($depth === 0) {
+                    return $k;
+                }
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * 値の式が終わる位置 (配列リテラルなら閉じ括弧、単一式なら深さ 0 の区切りの手前)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function valueEnd(array $tokens, int $from): ?int
+    {
+        if (! isset($tokens[$from])) {
+            return null;
+        }
+        if (self::isChar($tokens, $from, '[')) {
+            return self::matchingBracket($tokens, $from);
+        }
+        if ($tokens[$from]['id'] === T_ARRAY && self::isChar($tokens, $from + 1, '(')) {
+            return self::matchingBracket($tokens, $from + 1);
+        }
+
+        $depth = 0;
+        $count = count($tokens);
+        for ($k = $from; $k < $count; $k++) {
+            $delta = self::bracketDelta($tokens[$k]);
+            if ($delta > 0) {
+                $depth++;
+
+                continue;
+            }
+            if ($delta < 0) {
+                if ($depth === 0) {
+                    return $k - 1;
+                }
+                $depth--;
+
+                continue;
+            }
+            if ($depth === 0 && $tokens[$k]['id'] === null && in_array($tokens[$k]['text'], [',', ';'], true)) {
+                return $k - 1;
+            }
+        }
+
+        return $count - 1;
+    }
+
+    /**
+     * 括弧の深さの増減 (文字列補間が開く `{` と属性の `#[` を開き括弧として数える)。
+     *
+     * @param  array{id: int|null, text: string, line: int}  $token
+     */
+    private static function bracketDelta(array $token): int
+    {
+        if ($token['id'] === null) {
+            if (in_array($token['text'], ['(', '[', '{'], true)) {
+                return 1;
+            }
+            if (in_array($token['text'], [')', ']', '}'], true)) {
+                return -1;
+            }
+
+            return 0;
+        }
+
+        return in_array($token['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true) ? 1 : 0;
+    }
+
+    /**
+     * @param  array<int, bool>  $marks
+     */
+    private static function markRange(array &$marks, int $from, int $to): void
+    {
+        for ($i = $from; $i <= $to; $i++) {
+            $marks[$i] = true;
+        }
+    }
+}
diff --git a/tests/Support/SurfaceRemoval/RemovedTerm.php b/tests/Support/SurfaceRemoval/RemovedTerm.php
new file mode 100644
index 00000000..05cadfcc
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/RemovedTerm.php
@@ -0,0 +1,19 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/**
+ * 撤去語 (語そのものと一致様式を 1 つにまとめる)。
+ *
+ * ★語だけを渡す API にしない。様式を語と別に持ち回ると、呼び出し側ごとに
+ *   違う様式で同じ語を判定する事故が起きる。
+ */
+final readonly class RemovedTerm
+{
+    public function __construct(
+        public string $term,
+        public TermMatchMode $mode,
+    ) {}
+}
diff --git a/tests/Support/SurfaceRemoval/ScanOutcome.php b/tests/Support/SurfaceRemoval/ScanOutcome.php
new file mode 100644
index 00000000..0b6917d7
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/ScanOutcome.php
@@ -0,0 +1,62 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/**
+ * 走査結果。**出現**と**未解決**を型上区別する (未解決を空配列へ混ぜない)。
+ *
+ * ★利用側 gate は `mergeUnresolved()` で**呼んだすべての結果**の未解決を 1 つに集め、
+ *   空であることを必ず要求する (AGENTS.md (b) / (d))。
+ *
+ * @template-covariant TOccurrence of Occurrence|MiddlewareReference|MethodReference
+ */
+final readonly class ScanOutcome
+{
+    /**
+     * @param  list<TOccurrence>  $occurrences
+     * @param  array<string, string>  $unresolved  相対パス => 理由
+     */
+    public function __construct(
+        public array $occurrences,
+        public array $unresolved,
+    ) {}
+
+    /**
+     * 出現の説明行 (gate の失敗メッセージ用)。
+     *
+     * @return list<string>
+     */
+    public function descriptions(): array
+    {
+        return array_values(array_map(
+            static fn (Occurrence|MiddlewareReference|MethodReference $o): string => $o->describe(),
+            $this->occurrences,
+        ));
+    }
+
+    /**
+     * 複数の走査結果の未解決を 1 つへまとめる。
+     *
+     * ★集めるだけで判定に使わない出力を作らないため、gate は必ずこの戻り値を
+     *   「空であること」の assertion に渡す。
+     *
+     * @param  list<self<Occurrence|MiddlewareReference|MethodReference>>  $outcomes
+     * @return list<string> `相対パス: 理由` の説明行 (昇順)
+     */
+    public static function mergeUnresolved(array $outcomes): array
+    {
+        $merged = [];
+        foreach ($outcomes as $outcome) {
+            foreach ($outcome->unresolved as $relative => $reason) {
+                $merged[$relative.': '.$reason] = true;
+            }
+        }
+
+        $lines = array_keys($merged);
+        sort($lines);
+
+        return $lines;
+    }
+}
diff --git a/tests/Support/SurfaceRemoval/ScanPopulation.php b/tests/Support/SurfaceRemoval/ScanPopulation.php
new file mode 100644
index 00000000..63674a7e
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/ScanPopulation.php
@@ -0,0 +1,51 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/**
+ * 実走査母集団 + 未解決 + バイナリ除外。
+ *
+ * ★**数える集合と走査する集合を分けない**。gate が空振り検査に使う件数は、
+ *   本体の検査が実際に走査した `$files` そのものから数える。
+ * ★`$unresolved` と `$binaryExcluded` は**利用側 gate が空を要求する**。
+ *   捨てた事実を型の上に残すことで、無言の fail-open を作らない。
+ */
+final readonly class ScanPopulation
+{
+    /**
+     * @param  list<ScannedFile>  $files  実走査母集団
+     * @param  array<string, string>  $unresolved  相対パス => 理由
+     * @param  list<string>  $binaryExcluded  NUL を含むため外した相対パス
+     */
+    public function __construct(
+        public array $files,
+        public array $unresolved,
+        public array $binaryExcluded,
+    ) {}
+
+    /** @return list<ScannedFile> PHP ソースとして扱うファイル */
+    public function php(): array
+    {
+        return array_values(array_filter($this->files, static fn (ScannedFile $f): bool => $f->isPhp));
+    }
+
+    /** @return list<ScannedFile> PHP ソースとして扱わないファイル */
+    public function nonPhp(): array
+    {
+        return array_values(array_filter($this->files, static fn (ScannedFile $f): bool => ! $f->isPhp));
+    }
+
+    /** @return list<ScannedFile> 指定した走査根に属するファイル */
+    public function inRoot(string $root): array
+    {
+        return array_values(array_filter($this->files, static fn (ScannedFile $f): bool => $f->root === $root));
+    }
+
+    /** @return list<string> 実走査母集団の相対パス */
+    public function relativePaths(): array
+    {
+        return array_values(array_map(static fn (ScannedFile $f): string => $f->relative, $this->files));
+    }
+}
diff --git a/tests/Support/SurfaceRemoval/ScannedFile.php b/tests/Support/SurfaceRemoval/ScannedFile.php
new file mode 100644
index 00000000..b439fb38
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/ScannedFile.php
@@ -0,0 +1,29 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/**
+ * 走査対象 1 ファイル (内容込みの値オブジェクト)。
+ *
+ * ★`$isPhp` は**拡張子から推定させない**。実母集団は
+ *   `RemovedSurfaceScanTargets::population()` が決め、自己検証の見本 (`*.php.txt`) は
+ *   gate 側が**引数で明示**して組み立てる (見本を `.php` で置くと
+ *   `StrictTypesDeclarationGateTest` など無関係な gate が赤くなるため)。
+ */
+final readonly class ScannedFile
+{
+    public function __construct(
+        /** 走査根 (`.github` / `app` / … / 見本は `fixtures`)。 */
+        public string $root,
+        /** リポジトリルート相対パス (見本は見本ファイルの相対パス)。 */
+        public string $relative,
+        /** NUL を含まず UTF-8 検証済みの内容。 */
+        public string $contents,
+        /** PHP ソースとして扱うか (`.blade.php` は PHP ソースではない)。 */
+        public bool $isPhp,
+        /** 拡張子 (小文字。拡張子なしは null)。 */
+        public ?string $extension,
+    ) {}
+}
diff --git a/tests/Support/SurfaceRemoval/TermMatchMode.php b/tests/Support/SurfaceRemoval/TermMatchMode.php
new file mode 100644
index 00000000..996116d5
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/TermMatchMode.php
@@ -0,0 +1,41 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/**
+ * 撤去語の一致様式 (語ごとに宣言する)。
+ *
+ * ★AGENTS.md「静的検査 (gate) と走査器の共通規約」(e) に従い、判定は
+ *   **宣言した区切りで分割したトークンの完全一致**で行う。正規表現の語境界にも
+ *   素の部分文字列一致にも頼らない。
+ */
+enum TermMatchMode
+{
+    /**
+     * トークン文字集合 `[A-Za-z0-9_.-]` の最長連なり (run) 全体と完全一致 (大小区別あり)。
+     *
+     * `password.confirm:web` は `:` が区切りなので run が `password.confirm` になり一致する。
+     * `password.confirm.store` / `x-password.confirm` は run 全体が違うので一致しない。
+     */
+    case ExactRun;
+
+    /**
+     * run を `.` で割ったいずれかの segment と完全一致 (大小区別あり)。
+     *
+     * 設定パス表記 (`manual.ocr_analysis_enabled`) に当てるための様式。
+     */
+    case RunSegment;
+
+    /**
+     * 非 PHP の生テキストに現れる**完全修飾クラス名 + `::` + メソッド名**の完全一致。
+     *
+     * ★専用のトークン文字集合 `[A-Za-z0-9_\\]` を使う。`ExactRun` の文字集合では
+     *   `\` と `:` が区切りになるため、完全修飾参照は複数の run へ割れて
+     *   **原理的に一致しない**。
+     * ★PHP のクラス参照として使われる文字列を守る様式なので、PHP の言語仕様に合わせて
+     *   クラス部・メソッド部とも **ASCII 大小を無視**して比較し、先頭の `\` は落として正規化する。
+     */
+    case FqcnMethodReference;
+}
diff --git a/tests/Support/TemplateDivergence/LedgerPins.php b/tests/Support/TemplateDivergence/LedgerPins.php
index 80e882c9..1160e1ae 100644
--- a/tests/Support/TemplateDivergence/LedgerPins.php
+++ b/tests/Support/TemplateDivergence/LedgerPins.php
@@ -19,7 +19,7 @@ final class LedgerPins
     private function __construct() {}
 
     /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
-    public const int DIVERGENCE_ENTRY_COUNT = 36;
+    public const int DIVERGENCE_ENTRY_COUNT = 37;
 
     /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
     public const int FINGERPRINT_POPULATION_COUNT = 281;

```
