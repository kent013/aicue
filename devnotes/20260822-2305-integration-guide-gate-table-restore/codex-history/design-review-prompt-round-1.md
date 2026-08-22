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

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【本件の前提（レビューの土台として読むこと）】
- 家系の機能台帳 lctl の feature `app-integration-guide` への追従設計である。裁定 AG-116 が「配る中身は合成版」と確定し、その残タスクとして台帳が aicue 側に名指しした作業が「aicue が失った docs/app-integration-guide.md §2 のゲート表 2 本（計 34 行）の復帰」である。
- テンプレート（laravel-claude-template）の現物はこのリポジトリにも CI にも無い。台帳が保存しているのは「表が 2 本・8 本 / 13 本・合計 34 行」という規模の記述だけで、行の中身の逐語は台帳にも無い。したがって本件は逐語復元ではなく意味的写像であり、テンプレート側の還流 (T121/T132) も同じ作法を採っている。
- 概念設計は Codex レビュー 4 ラウンドで APPROVED 済み。本レビューは詳細設計（本文の全文・新規テストの全文・乖離台帳エントリの全文）を対象とする。
- app/ routes/ resources/ database/ config/ のコード変更は 0 行である。

---

## 詳細設計書

# 詳細設計: integration-guide-gate-table-restore

## 使命・制約(絶対遵守)

### アプリの使命(North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて
   「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

**本設計での該当**: 4〜8 は HTTP 境界・UI・LLM 経路を一切変えないため発生しない。
1 は施策 2(同期検査の新設)が担う。2 は施策 2 の PHPStan 適合チェックで担保する。
9 は本設計の成果物をすべて `devnotes/` 配下のファイルとして出力することで守る。

### コーディングルール

- **PHPStan level 10** 必須(`composer phpstan`)
- **Pest** テストフレームワーク(`composer test`)
- **RefreshDatabase** + `--parallel` 並列実行(`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止)。**本件の新規テストは DB を使わない**静的検査であり、
  既存 Architecture テストと同じ作法(`TestCase` のみ)で置く
- テストデータは Factory 経由(本件は DB を使わないため該当なし)
- `declare(strict_types=1)` + 日本語コメント(`StrictTypesDeclarationGateTest` が
  git 追跡下の PHP 全数に deny-by-default で強制。免除の登録簿は持たない)
- アーリーリターン推奨 / `composer fix`(Pint) / PHP 8.4 + Laravel 12

## 概念設計リファレンス

- `devnotes/20260822-2305-integration-guide-gate-table-restore/conceptual-design.md`(APPROVED / Round 4)

---

## 正典(lctl feature `app-integration-guide`)の不変条件 — 全列挙と本設計での扱い

台帳の `gates` に登録されている不変条件は 5 本である。**1 本ずつ、本設計が壊さないことを示す。**

| # | 正典の不変条件(要旨) | 本設計での扱い |
|---|---|---|
| G1 | 契約文書が実在するのはテンプレートから生成された 3 リポジトリだけであり、git オブジェクトの実在で受け取り方が 3 段階に分かれる。テンプレート乖離台帳を持つかの分かれ目もこの線と完全に一致する | **触らない**。aicue は「初期コミットで中身だけを持ち込んだ」側であり、本設計はその文書の §2 に節を足すだけで、受け取り方の分類にも乖離台帳の有無にも影響しない |
| G2 | **設計時に §2〜§7 の判定を踏んだかどうかを確かめる機械は家系のどこにも無い**。文書そのものに掛かる機械は 3 本(テンプレートの指紋照合 / motivation の §7 doc-sync / テンプレートの §7 doc-sync)で、いずれも別目的である | **この結論を変えない**。施策 2 が足すのは「§2 の表が指すゲート名の実在・件数・一意性」までで、**設計者が判定を踏んだかは見ない**。この保証範囲を docblock に明記し、本文にも書かない(誇張しない) |
| G3 | 文書ファイルをコピーするだけでは到達経路が生まれない。規約ファイル・README・初期化スクリプト・文書更新スキル・ソースコメント・アーキテクチャ文書からの導線が揃って初めて設計者が文書へ届く。**件数は増え続けるため固定値としては扱わない** | **既存の導線を壊さない**。AGENTS.md 実装規約の「新規リソースの追加手順は §2」という名指しは既に在り、本設計はその参照先の中身を充実させる。導線の件数を新たに pin する検査は**作らない**(正典が固定値として扱わないと明記しているため) |
| G4 | 版の分岐は文書の中だけの話で終わらない。§7 の不変条件は各リポジトリの規約ファイル・ソースコメントから**番号で**参照されており、3 者で番号と中身の対応が崩れている | **番号に触らない**。表から §7 を参照するときは**項目名**で指す(AGENTS.md の採番注意「相互参照するときは番号ではなく項目名で指すこと」「どちらの側も renumber しない」に従う)。§7 の番号体系の統一はスコープ外 |
| G5 | `motivation:tests/Architecture/SecurityInvariantDocSyncTest.php` が契約文書 §7 と AGENTS.md の相互整合(番号体系の一致 / 締め文の数字 / 言及されたゲート名の実在)を機械照合する。テンプレートが同名 gate を移植して家系 2 本目になった | **移植しない**(スコープ外)。ただし家系が価値を認めている「言及されたゲート名の実在」の部分だけを、**§2 の 2 表に限って**施策 2 が持つ。§7 全体・AGENTS.md との相互整合・締め文の数字は対象にしない |

### 裁定 AG-116(2026-08-08)の要求と本設計の対応

> 配る中身は **(b) 合成版**とする — テンプレート現行版へ aicue の独自 3 節を還流し、
> **aicue が失った §2 ゲート表 2 本(34 行)を復帰させた統合版**を家系の正として配る。
> 合成版の作成はテンプレート側の還流タスク。

- テンプレート側の還流 3 節は `T121` / `T132` で完了済み(台帳のテンプレートセルは `implemented (t2)`)。
- 台帳は「aicue が失った §2 ゲート表 2 本の復帰」を **aicue 側の取り込み作業**と名指しし、
  テンプレートセルの担当から外している。**本設計はその 1 点だけを扱う。**
- **完了は宣言しない**。台帳セルの status を上げる判断はキュレーターの巡回の責務である。

### 正典 boundary が定める §2 の規定範囲(本設計が満たす部分)

boundary の (2) は §2 を次のように記述する — 「新しいエンティティをどのテナント層へ置くかの
決定手順 4 段」+「決めた後に機械的に従う 6 項」+「見本リソースの実装ファイル対応表 21 行」+
**「新規リソースで必ず踏む Architecture ゲート表 8 本」**+**「条件付きで発火するゲート表 13 本」**。

aicue には前 3 者が実在し、後 2 者が欠落している(boundary の (4) が
「結果として §2 のゲート表 2 本(計 34 行)が aicue には存在せず」と明記)。
**本設計が埋めるのは後 2 者だけである。**

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | §2 へゲート表 2 本を追加する(逐語復元ではなく判定規準としての写像) | `docs/app-integration-guide.md` | 高 |
| 2 | 表が指すゲート名の実在・件数・一意性を固定する同期検査を新設する | `tests/Architecture/IntegrationGuideGateTableSyncTest.php`(新規) | 高 |
| 3 | 乖離台帳の付け替え(D40 新設 / 採用時債務から 1 行削除 / 件数 pin 更新) | `docs/template-divergence.md` / `tests/Support/TemplateDivergence/adoption-debt.tsv` / `tests/Support/TemplateDivergence/LedgerPins.php` | 高(施策 1 と不可分) |

**実施順(テストファースト。思考原則 5 / 走査器を新設するときに揃える 4 点の 1 番目)**:
施策 2 → 施策 1 → 施策 2 の負例 → 施策 3。理由は各施策のテスト計画に書く。

---

## 施策 1: §2 へゲート表 2 本を追加する

### 変更箇所

- ファイル: `docs/app-integration-guide.md`
- 挿入位置: **L93 の直後・L95(`## 3. ロール・権限のマッピング`)の直前**
  (§2 の「見本: Item リソース」の注意点 3 項の後)
- 既存の 21 行の対応表(L62-82)と注意点(L84-93)は**1 文字も変えない**

### 波及変更

- TypeScript 型定義: なし(フロント・API・DTO を触らない)
- API Resource/DTO: なし
- テストファイル: 施策 2 の新規テストが本文を走査するため、**施策 2 とセットでしか意味を持たない**
- 他ドキュメント: なし。AGENTS.md 実装規約の「新規リソースの追加手順は §2 のチェックリスト」は
  節名で参照しており、表の追加で参照が壊れない(**AGENTS.md は変更しない**)

### 現行コード(該当箇所の末尾)

```markdown
- 子リソースの作成は親 FK を **relation 経由で代入**する
  (`$project->items()->create([...])`)。FK の mass assignment を書かない。

## 3. ロール・権限のマッピング
```

### 変更後コード(挿入する本文の全文)

```markdown
- 子リソースの作成は親 FK を **relation 経由で代入**する
  (`$project->items()->create([...])`)。FK の mass assignment を書かない。

### 新規リソースで踏む Architecture ゲートの索引

下の 2 表は「新しいドメインリソースを足すとき、どの Architecture ゲートが発火し、
何をどこへ登録しなければならないか」の索引である。上の対応表(見本 Item のどのファイルか)とは
軸が違うので併存させる。**§7 を参照するときは番号ではなく項目名で指す**
(本書 §7 と AGENTS.md のセキュリティ不変条件は番号が 1:1 対応しない)。

> **この索引の性質**: 家系の裁定 AG-116 が定めた合成版の一部として復帰させた表である。
> テンプレート現物を参照できないため**逐語復元ではなく、本アプリの実在ゲートへ写した
> 判定規準**である(テンプレート側の還流も同じ作法を採っている)。
> 網羅性は主張しない — ここに無いゲートが発火しないという意味ではない。

#### 新規リソースで必ず踏む Architecture ゲート

**対象は「§2 の手順で Project 配下に *書き込み可能な* ドメインリソースを 1 つ足すこと」**
= 見本 Item と同型の実装単位である。この単位は定義上、マイグレーション(親 FK `constrained()` +
NOT NULL)/ Model / Factory / FormRequest(store・update)/ nested route(変更系を含む)/
親 Policy 経由の認可 / Feature テスト を必ず持つので、下の 8 本は条件なしに発火する。

この単位から外れる形(読み取り専用リソース / 画面だけの追加 / 組織直下に置くマスタデータ)を
足すときは、**該当しない行を設計書で名指しして外す理由を書く**。黙って外さない。

| ゲート | 何を落とすか | 何をどこへ登録するか |
|---|---|---|
| `MassAssignmentSafetyTest` | ownership / actor / tenant / secret キーが Model の `$fillable` に載ること | 登録簿は無い。新 Model の親 FK を `$fillable` に入れず、relation 経由か明示代入で書く |
| `FormRequestProhibitedKeyTest` | FormRequest が `ProhibitsProtectedKeys` を欠くこと / 保護キーの missing rule が無いこと | 新 FormRequest に trait を適用し `rules()` に missing を書く。アプリ固有の新 FK 名は `app/Support/Security/MassAssignmentProtectedKeys.php` へ追記 |
| `ValidationAttributeCoverageTest` | `rules()` のキーが利用者向け文言の attributes に無いこと(生のキー名が画面に出る) | `lang/ja/validation.php` の attributes に新しいキーを追記 |
| `ProjectRouteCurrentOrgGuardTest` | `{project}` を受ける route が middleware 層の URL 整合 guard を欠くこと(FormRequest の DB ルールが先に走り 422 と 404 の差が存在オラクルになる) | web は `project.in-current-org`、API は `api.project-in-org` を route group に付ける |
| `NestedRouteIdorDefenseTest` | param 付き route の防御方式が未分類・stale・無記名であること | `tests/Architecture/NestedRouteIdorDefenseTest.php` が読む inventory へ **parameter 単位**で `NestedRouteDefenseMode` を登録。テナント親子でない param は理由を `nonTenantReasons()` に書く |
| `TenantBoundaryOrderingTest` | テナント境界の 404 が binding の直後で閉じていないこと(1 bit の存在オラクル) | 新しい middleware を足したら `middlewareShortCircuitInventory()` に「短絡しうるか」を分類する(疑わしきは `true` 側)。`SubstituteBindings` より前に置くなら `preBindingShortCircuitInventory()` にも登録 |
| `RouteBindingTypeConstraintInventoryTest` | binding param が型分類の目録に無く、型不一致が 404 ではなく生 500 になること | `RouteBindingTypes` の 5 分類のいずれかへ登録し、分類に応じた制約を route に付ける |
| `ControllerAuthorizationGateTest` | 変更系(POST/PUT/PATCH/DELETE)ハンドラが認可判断を 1 度も通らないこと | ハンドラ冒頭(URL 整合 guard の**後**)に `Gate::authorize`。認可が不要なら exemption inventory へ enum + 「何が代わりに守っているか」を 30 文字以上で登録 |

#### 条件付きで発火するゲート

リソースの性質や付随機能が加わって初めて母集団に入るもの。

| ゲート | 発火条件 | 何をどこへ登録するか |
|---|---|---|
| `ModelDirectFetchInvariantTest` | route parameter **以外**の id 受け口(POST payload / query string / MCP tool 引数 / token claim / queue payload)を足すとき | まず relation 起点(`$organization->users()->whereKey($id)`)で書けないか検討する。書けないときだけ `DirectFetchInventory` へ `DirectFetchJustification` + 30 文字以上の具体的根拠 + case ごとの構造化 field を登録 |
| `ThrottleCoverageInventoryTest` | 保護対象群(未認証で到達しうる変更系 / 機械向け経路 `api/`・`oauth/`・`.well-known/oauth-` / 認証面の変更系)に route が入るとき | throttle をちょうど 1 本持たせるか、`ThrottleCoverageExemption` + 30 文字以上の根拠で登録(§7b) |
| `ThrottleLaneAssignmentTest` | named レーンへ route を割り当てるとき | レーンごとの route 目録へ登録する(どの route がどのレーンに属するかを固定する) |
| `IdempotentRouteCoverageTest` | `api/v1/*` に変更系 route を足すとき | `idempotent` middleware をちょうど 1 本持たせるか、型付き分類 + 30 文字以上の根拠で免除登録 |
| `ApiGuardAllowlistInvariantTest` | REST API v1 の endpoint を足すとき | guard 分類(dual / oauth / public)を宣言表へ登録 |
| `McpAuthorizationChokePointTest` | MCP tool を足すとき | 認可の関門を業務処理より前に通し、結果を捨てない。書き込み tool を足すときは冪等キーの必須化も同時に要る(別ゲートが trip-wire として発火する) |
| `QuotaKeyConfigInvariantTest` | 上限(Quota 項目)を足すとき | `config/quota.php` の limits キーと `App\Enums\QuotaKey` の case を**対で**足す |
| `PromptGuardrailTest` | LLM 呼び出しを足すとき | `app/Prompts/` の factory → 窓口 → 実行単位の 1 本道で書く(vendor 直呼びを作らない)。prompt 文字列は `resources/prompts/*.yaml` に置く |
| `PromptUntrustedInputContractTest` | untrusted 文字列を prompt に入れるとき | 窓口 `App\Support\Llm\PromptDefense` 経由にし、inventory へ帰属キーを登録(帰属の対象を持たない見本だけが空配列で exempt 登録できる) |
| `CachePayloadPlainDataGateTest` | キャッシュ書き込み経路を足すとき | 素のデータ(配列 / 文字列 / 数値 / 真偽値 / `null`)だけを入れ、書き込み経路を目録へ登録する。読み戻しは `fromArray()` 等で組み立て直して検査し、失敗したら `forget` する |
| `SsrfPinBoundaryTest` | 外部 URL(特にユーザ入力由来)を取得するとき | `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。安全境界は `config/ssrf-pin.php` に pin する |
| `DocumentTitleCoverageTest` | Inertia を render する GET named route を足すとき | ページ固有のタイトルを controller 供給メタか `config/seo.php` に持たせる(無いとサイト名だけになる) |
| `InertiaRenderPageExistsInvariantTest` | 新しいページコンポーネントを足すとき | `resources/js/pages/` に実体を置く(literal 参照と 1:1。参照先が無いページは本番で白画面になる) |

## 3. ロール・権限のマッピング
```

### 表の設計上の決めごと(施策 2 の走査契約と対になる)

- **両表とも 1 列目がゲート名**である(表 B も「発火条件」ではなくゲート名を先頭に置く)。
  列の意味が表ごとに違っても走査位置が変わらないようにするため。
- ゲート名は**バッククォート 1 対で囲み、末尾が `Test`** の英数字だけで書く。
  パス表記(`tests/Architecture/...`)や `.php` を 1 列目に書かない(2 列目以降には書いてよい)。
- アンカーになる小見出しは `#### 新規リソースで必ず踏む Architecture ゲート` と
  `#### 条件付きで発火するゲート` の 2 本で、**この文字列を変えるときは施策 2 の定数も同じ変更で直す**。

### リスク

- 文書が長くなる(§2 が約 45 行増える)。§7 が既に 310 行を占める文書なので相対的な影響は小さく、
  索引としての価値が上回ると判断した。
- 表の内容が実装からずれる余地は残る(施策 2 が見るのは名前の実在まで)。
  この非対称は施策 2 の docblock と本文の「網羅性は主張しない」で明示する。

---

## 施策 2: 同期検査 `IntegrationGuideGateTableSyncTest` を新設する

### 変更箇所

- ファイル: `tests/Architecture/IntegrationGuideGateTableSyncTest.php`(**新規**)
- 負例は**本ファイル内の合成入力**に置く(AGENTS.md は負例の置き場を 3 通りとも認めている。
  fixture ファイルを新設しない = 変更ファイルを増やさない)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本ファイルのみ。既存テストの変更なし
  (`docs/app-integration-guide.md` を読む既存テストは `RouteCacheExemptionPremiseTest` の
  「D19 の参照が切れていない」検査だけで、`D19` の文字列は §7c に在り続けるので影響しない)

### 走査器共通規約(AGENTS.md §静的検査 (gate) と走査器の共通規約)への適合

| 条 | 適用 | 本設計での満たし方 |
|---|---|---|
| (a) クラス参照は完全修飾名で突き合わせる | **対象外** | PHP のクラス参照を解決しない。見るのは Markdown のセル文字列と**ファイルの実在**だけである(この旨を docblock に明記する) |
| (b) 解決できない形は落とす(fail-closed) | 適用 | 走査根が読めない / §2 の見出しが無い / アンカーが無い / 表が無い / 1 列目からゲート名を取り出せない、はすべて**例外**にする。未解決を `null` や空文字列として戻り値へ混ぜない。「違反 0 件」と「母集団 0 件」は別検査で区別する |
| (c) 検出力は負例で裏取りする | 適用 | 負例 7 形 + 正例 1 形を両方向で固定する(下のテスト計画) |
| (d) 集めた走査結果を判定に使わない形を作らない | 適用 | 抽出した名前は件数 pin・実在・一意性の 3 判定すべてに使う。数えるだけの目録を作らない |
| (e) 語彙一致の否定形はトークン完全一致で判定 | 適用 | **区切りは半角縦棒 `|` の 1 文字だけ**と宣言する。セルは前後の空白を落として比較し、ゲート名は正規表現の**完全一致**(`/^[A-Za-z][A-Za-z0-9]*Test$/`)で判定する。部分文字列一致・語境界に頼らない |

### 変更後コード(新規ファイルの全文)

```php
<?php

declare(strict_types=1);

use Webmozart\Assert\Assert;

/*
 * 契約文書 §2 のゲート表が指すゲート名の**実在・件数・一意性**を固定する。
 *
 * 家系の裁定 AG-116 が定めた合成版の一部として、本アプリは
 * docs/app-integration-guide.md §2 に「新規リソースで必ず踏むゲート」と
 * 「条件付きで発火するゲート」の 2 表を持つ (設計は
 * devnotes/20260822-2305-integration-guide-gate-table-restore/)。表はゲート名を名指しするため、
 * ゲートの改名・削除で**表だけが古い名前を指し続ける**と、索引を読んで登録しに行った設計者が
 * 存在しないゲートを探すことになる。それを機械で落とす。
 *
 * ★走査対象: docs/app-integration-guide.md の **§2 の範囲だけ**。
 *   `## 2. ` の行から次の `## ` の行の手前までを切り出し、その中の 2 つのアンカー小見出しの
 *   直後にある最初の表を見る。§2 の外の同名文字列は 1 件も見ない。
 * ★区切り文字の宣言 (走査器共通規約 (e)): 表の行を割るのは**半角縦棒 `|` の 1 文字だけ**である。
 *   セルは前後の空白を落として比較し、ゲート名は完全一致の正規表現で判定する。
 * ★名前解決 (同 (a)) は行わない。見るのは
 *   `tests/Architecture/<名前>.php` が**ファイルとして実在するか**だけである。
 *
 * ---------------------------------------------------------------------------
 * **この検査が保証しないもの** (誇張しない。ここが正本であり AGENTS.md や
 * 契約文書本文には写さない):
 *
 *  1. **表の構成集合そのものは固定しない**。ある行を**別の実在するゲート名へ差し替える**ことは
 *     検出しない。21 件の期待集合を本ファイルへ写すと表と検査の 2 か所に同じ一覧を持つことになり、
 *     必ず食い違う。**正本は文書側の表 1 か所**とし、ここは件数・実在・一意性に限る
 *     (`LedgerPins` の 3 定数や ForbiddenStatement の件数 pin と同じ作法)。
 *  2. 表に書かれた**発火条件・登録先の意味的な正確さ**は見ない。
 *  3. **設計者が実際に §2 を読んで登録したかは見ない**。家系の正典が
 *     「設計時に §2〜§7 の判定を踏んだかどうかを確かめる機械は家系のどこにも無い」と
 *     記録しており、本検査はその状況を変えない。
 *  4. **索引の網羅性は主張しない**。表に載っていないゲートの存在は見ないので、
 *     「ここに無いゲートは発火しない」とは読めない。
 *  5. ゲートの**中身**が生きているか (検査が空振りしていないか) は各ゲート自身の責務である。
 *  6. 表の列のうち 2 列目以降は見ない (パス表記や別ゲート名を書いてよい欄である)。
 * ---------------------------------------------------------------------------
 *
 * 実行不能 (文書が読めない / §2 が無い / アンカーが無い / 表が無い /
 * 1 列目からゲート名を取り出せない) は skip でも緑でもなく**不合格**にする。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/** 走査根 (リポジトリ相対)。 */
const INTEGRATION_GUIDE_SOURCE_PATH = 'docs/app-integration-guide.md';

/** ゲートの実装が置かれるディレクトリ (リポジトリ相対)。 */
const INTEGRATION_GUIDE_GATE_DIRECTORY = 'tests/Architecture';

/**
 * アンカー小見出し => 期待するゲート件数 (完全一致)。
 *
 * ★件数は**完全一致**で、増えても減っても赤になる。表の行を増減させるときは
 *   同じ変更でこの値を直す (無断の縮小を黙らせない)。
 * ★小見出しの文字列は文書側と本定数の 2 か所に現れる。**同じ変更で直す**こと
 *   (アンカーが無ければ例外になるので、片方だけ変えると必ず気付く)。
 *
 * @var array<string, int>
 */
const INTEGRATION_GUIDE_GATE_TABLES = [
    '#### 新規リソースで必ず踏む Architecture ゲート' => 8,
    '#### 条件付きで発火するゲート' => 13,
];

/** 1 列目のセルが満たすべき形 (バッククォート 1 対で囲まれた、末尾が Test の英数字)。 */
const INTEGRATION_GUIDE_GATE_CELL = '/^`([A-Za-z][A-Za-z0-9]*Test)`$/';

/**
 * 走査が空振りでないことを確かめる代表ゲート (母集団に必ず在るもの)。
 *
 * @var list<string>
 */
const INTEGRATION_GUIDE_SENTINEL_GATES = [
    'ControllerAuthorizationGateTest',
    'NestedRouteIdorDefenseTest',
];

/** ゲート母集団の下限 (これを下回ったら列挙そのものを疑う)。 */
const INTEGRATION_GUIDE_GATE_FLOOR = 50;

/**
 * 契約文書の本文 (読めないことは空ではなく不合格)。
 */
function integrationGuideMarkdown(): string
{
    $markdown = @file_get_contents(base_path(INTEGRATION_GUIDE_SOURCE_PATH));
    Assert::string($markdown, INTEGRATION_GUIDE_SOURCE_PATH.' を読めない (実行不能は不合格)');

    return $markdown;
}

/**
 * 本文を行へ割る (改行の種類に依存しない)。
 *
 * @return list<string>
 */
function integrationGuideLines(string $text): array
{
    $lines = preg_split('/\R/u', $text);
    Assert::isArray($lines, '本文を行へ割れない');

    /** @var list<string> $lines */
    return array_values($lines);
}

/**
 * §2 の範囲だけを切り出す。
 *
 * 見出しが無いことは**空ではなく例外**にする (走査根の改名・章立ての変更で
 * 母集団が空になったまま緑になる形を作らない)。
 */
function integrationGuideSectionTwo(string $markdown): string
{
    $lines = integrationGuideLines($markdown);
    $start = null;

    foreach ($lines as $index => $line) {
        if ($start === null) {
            if (str_starts_with($line, '## 2. ')) {
                $start = $index;
            }

            continue;
        }

        if (str_starts_with($line, '## ')) {
            return implode("\n", array_slice($lines, $start, $index - $start));
        }
    }

    if ($start === null) {
        throw new RuntimeException(
            '§2 の見出し (`## 2. ` で始まる行) が '.INTEGRATION_GUIDE_SOURCE_PATH.' に無い',
        );
    }

    return implode("\n", array_slice($lines, $start));
}

/**
 * アンカー小見出しの直後にある最初の表のデータ行から、1 列目のゲート名を取り出す。
 *
 * ★**正常に全行を解決できたときだけ** `list<string>` を返す。解決できない行が 1 行でもあれば
 *   行番号と理由を持つ例外を投げる (未解決を戻り値へ混ぜない / 無言で候補から外さない)。
 * ★行番号は §2 の切り出しの中での 1 始まりの位置である (絶対行ではない)。
 *
 * @return list<string>
 */
function integrationGuideGateNames(string $section, string $anchor): array
{
    $lines = integrationGuideLines($section);
    $anchorIndex = null;

    foreach ($lines as $index => $line) {
        if (trim($line) === $anchor) {
            $anchorIndex = $index;
            break;
        }
    }

    if ($anchorIndex === null) {
        throw new RuntimeException('アンカー小見出し「'.$anchor.'」が §2 に無い');
    }

    /** @var list<array{0: int, 1: string}> $tableLines 1 始まりの行番号と本文 */
    $tableLines = [];
    $started = false;

    foreach (array_slice($lines, $anchorIndex + 1) as $offset => $line) {
        $trimmed = trim($line);
        $isRow = str_starts_with($trimmed, '|');

        if ($isRow) {
            $started = true;
            $tableLines[] = [$anchorIndex + $offset + 2, $trimmed];

            continue;
        }

        if ($started) {
            break;
        }
    }

    if (count($tableLines) < 3) {
        throw new RuntimeException(
            'アンカー「'.$anchor.'」の直後に表 (ヘッダ / 区切り / データ行) が無い',
        );
    }

    [, $header] = $tableLines[0];
    if (integrationGuideCells($header)[0] !== 'ゲート') {
        throw new RuntimeException(
            'アンカー「'.$anchor.'」の表の 1 列目の見出しが「ゲート」ではない (実測: '.$header.')',
        );
    }

    [, $separator] = $tableLines[1];
    if (preg_match('/^\|[\s\-:|]+\|$/', $separator) !== 1) {
        throw new RuntimeException(
            'アンカー「'.$anchor.'」の表にヘッダ区切り行が無い (実測: '.$separator.')',
        );
    }

    /** @var list<string> $names */
    $names = [];

    foreach (array_slice($tableLines, 2) as [$lineNumber, $row]) {
        $cells = integrationGuideCells($row);

        if (count($cells) < 3) {
            throw new RuntimeException(
                '§2 内 '.$lineNumber.' 行目: 表の行が 3 列に足りない (実測 '
                .count($cells).' 列): '.$row,
            );
        }

        if (preg_match(INTEGRATION_GUIDE_GATE_CELL, $cells[0], $matches) !== 1) {
            throw new RuntimeException(
                '§2 内 '.$lineNumber.' 行目: 1 列目からゲート名を取り出せない '
                .'(バッククォート 1 対で囲んだ、末尾が Test の英数字だけを許す。'
                .'パス表記や .php は 1 列目に書かない)。実測: '.$cells[0],
            );
        }

        $names[] = $matches[1];
    }

    return $names;
}

/**
 * 表の 1 行をセルへ割る。
 *
 * ★区切りは**半角縦棒 `|` の 1 文字だけ**である (走査器共通規約 (e) の宣言)。
 *   前後の空白は落とす。両端の空セルは区切りの副産物なので捨てる。
 *
 * @return list<string>
 */
function integrationGuideCells(string $row): array
{
    $cells = array_map(static fn (string $cell): string => trim($cell), explode('|', $row));

    if ($cells !== [] && $cells[0] === '') {
        array_shift($cells);
    }
    if ($cells !== [] && end($cells) === '') {
        array_pop($cells);
    }

    return array_values($cells);
}

/**
 * 実在するゲート名の母集団 (拡張子なし)。
 *
 * ★読めないことは空ではなく例外にする (fail-open を作らない)。
 *
 * @return list<string>
 */
function integrationGuideExistingGates(): array
{
    $paths = glob(base_path(INTEGRATION_GUIDE_GATE_DIRECTORY).'/*.php');
    Assert::isArray($paths, INTEGRATION_GUIDE_GATE_DIRECTORY.' を列挙できない');

    /** @var list<string> $names */
    $names = [];

    foreach ($paths as $path) {
        $names[] = basename($path, '.php');
    }

    sort($names);

    return $names;
}

/**
 * 抽出した名前を、件数 pin / 実在 / 一意性の 3 観点で突き合わせる (純関数)。
 *
 * ★負のコントロールは実ファイルを触らず、合成した `$tables` と `$existing` を渡して同じ関数を走らせる。
 *
 * @param  array<string, list<string>>  $tables  アンカー => 1 列目のゲート名
 * @param  array<string, int>  $expected  アンカー => 期待件数
 * @param  list<string>  $existing  実在するゲート名
 * @return list<string>
 */
function integrationGuideGateTableViolations(array $tables, array $expected, array $existing): array
{
    /** @var list<string> $violations */
    $violations = [];
    /** @var array<string, string> $seen ゲート名 => 初出のアンカー */
    $seen = [];

    foreach ($expected as $anchor => $count) {
        if (! array_key_exists($anchor, $tables)) {
            $violations[] = 'アンカー「'.$anchor.'」の表が抽出できていない';

            continue;
        }

        $names = $tables[$anchor];

        if (count($names) !== $count) {
            $violations[] = 'アンカー「'.$anchor.'」のゲート件数が '.count($names)
                .' 件で、pin した '.$count.' 件と食い違う (表を増減させたら同じ変更で pin も直す)';
        }

        foreach ($names as $name) {
            if (! in_array($name, $existing, true)) {
                $violations[] = 'ゲート `'.$name.'` が '
                    .INTEGRATION_GUIDE_GATE_DIRECTORY.' に実在しない (改名・削除で索引が腐っている)';
            }

            if (isset($seen[$name])) {
                $violations[] = 'ゲート `'.$name.'` が重複している ('
                    .$seen[$name].' と '.$anchor.')';

                continue;
            }

            $seen[$name] = $anchor;
        }
    }

    return $violations;
}

/**
 * 実ファイルから 2 表を抽出する。
 *
 * @return array<string, list<string>>
 */
function integrationGuideGateTables(): array
{
    $section = integrationGuideSectionTwo(integrationGuideMarkdown());

    /** @var array<string, list<string>> $tables */
    $tables = [];

    foreach (array_keys(INTEGRATION_GUIDE_GATE_TABLES) as $anchor) {
        $tables[$anchor] = integrationGuideGateNames($section, $anchor);
    }

    return $tables;
}

/**
 * 負のコントロール用に §2 相当の合成入力を組み立てる。
 *
 * 規定どおりの形を既定とし、引数で行だけを差し替える。
 */
function integrationGuideSyntheticSection(string $rows, ?string $anchor = null): string
{
    $anchor ??= '#### 新規リソースで必ず踏む Architecture ゲート';

    return implode("\n", [
        '## 2. ドメインモデルの配置',
        '',
        $anchor,
        '',
        '| ゲート | 何を落とすか | 何をどこへ登録するか |',
        '|---|---|---|',
        $rows,
        '',
    ]);
}

test('§2 の 2 表が実在し、件数 pin / 実在 / 一意性を満たす', function (): void {
    $violations = integrationGuideGateTableViolations(
        integrationGuideGateTables(),
        INTEGRATION_GUIDE_GATE_TABLES,
        integrationGuideExistingGates(),
    );

    expect($violations)->toBe([], "§2 のゲート表の違反:\n".implode("\n", $violations));
});

test('走査が空振りしていない (走査根 / §2 / 各表 / ゲート母集団)', function (): void {
    // 走査根と §2 が生きていること
    $section = integrationGuideSectionTwo(integrationGuideMarkdown());
    expect($section)->toContain('## 2. ');

    // 各表のデータ行が非空であること (母集団 0 件を緑にしない)
    foreach (array_keys(INTEGRATION_GUIDE_GATE_TABLES) as $anchor) {
        expect(integrationGuideGateNames($section, $anchor))->not->toBeEmpty();
    }

    // ゲート母集団の床値と代表ゲート
    $existing = integrationGuideExistingGates();
    expect(count($existing))->toBeGreaterThanOrEqual(INTEGRATION_GUIDE_GATE_FLOOR);
    foreach (INTEGRATION_GUIDE_SENTINEL_GATES as $sentinel) {
        expect($existing)->toContain($sentinel);
    }
});

test('負のコントロール: 走査根を差し替えると母集団が空になる', function (): void {
    // §2 を持たない本文では例外になる (無言で 0 件にならない)
    expect(fn (): string => integrationGuideSectionTwo("# 別の文書\n\n## 3. 別の章\n"))
        ->toThrow(RuntimeException::class);
});

test('負例: 抽出できない形は例外になる', function (string $rows): void {
    expect(fn (): array => integrationGuideGateNames(
        integrationGuideSyntheticSection($rows),
        '#### 新規リソースで必ず踏む Architecture ゲート',
    ))->toThrow(RuntimeException::class);
})->with([
    'バッククォート欠落' => ['| MassAssignmentSafetyTest | 落とすもの | 登録先 |'],
    'ゲート列が空' => ['|  | 落とすもの | 登録先 |'],
    'パス表記' => ['| `tests/Architecture/MassAssignmentSafetyTest.php` | 落とすもの | 登録先 |'],
    '末尾が Test でない' => ['| `MassAssignmentSafety` | 落とすもの | 登録先 |'],
    '列が足りない' => ['| `MassAssignmentSafetyTest` | 落とすもの |'],
]);

test('負例: アンカーや表が無い形は例外になる', function (): void {
    $section = integrationGuideSyntheticSection(
        '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
        '#### 別の小見出し',
    );

    expect(fn (): array => integrationGuideGateNames(
        $section,
        '#### 新規リソースで必ず踏む Architecture ゲート',
    ))->toThrow(RuntimeException::class);

    expect(fn (): array => integrationGuideGateNames(
        "## 2. 章\n\n#### 新規リソースで必ず踏む Architecture ゲート\n\n表が無い\n",
        '#### 新規リソースで必ず踏む Architecture ゲート',
    ))->toThrow(RuntimeException::class);
});

test('負例: 不存在・重複・件数不一致は違反として報告される', function (): void {
    $anchor = '#### 新規リソースで必ず踏む Architecture ゲート';
    $other = '#### 条件付きで発火するゲート';

    // 実在しないゲート名
    expect(integrationGuideGateTableViolations(
        [$anchor => ['NoSuchGateTest']],
        [$anchor => 1],
        ['MassAssignmentSafetyTest'],
    ))->not->toBeEmpty();

    // 表をまたいだ重複
    expect(integrationGuideGateTableViolations(
        [$anchor => ['MassAssignmentSafetyTest'], $other => ['MassAssignmentSafetyTest']],
        [$anchor => 1, $other => 1],
        ['MassAssignmentSafetyTest'],
    ))->not->toBeEmpty();

    // 件数不一致 (減った側)
    expect(integrationGuideGateTableViolations(
        [$anchor => ['MassAssignmentSafetyTest']],
        [$anchor => 2],
        ['MassAssignmentSafetyTest'],
    ))->not->toBeEmpty();
});

test('正例: 規定どおりの合成入力は誤検出しない', function (): void {
    $anchor = '#### 新規リソースで必ず踏む Architecture ゲート';

    $names = integrationGuideGateNames(
        integrationGuideSyntheticSection(implode("\n", [
            '| `MassAssignmentSafetyTest` | 落とすもの | 登録先 |',
            '| `ControllerAuthorizationGateTest` | 落とすもの | `tests/Architecture` への言及は 2 列目以降なら可 |',
        ])),
        $anchor,
    );

    expect($names)->toBe(['MassAssignmentSafetyTest', 'ControllerAuthorizationGateTest']);
    expect(integrationGuideGateTableViolations(
        [$anchor => $names],
        [$anchor => 2],
        ['MassAssignmentSafetyTest', 'ControllerAuthorizationGateTest'],
    ))->toBe([]);
});
```

### PHPStan 適合チェック

- [x] 戻り値の型が全関数に明示されている(`string` / `list<string>` / `array<string, list<string>>`)
- [x] null 安全: `file_get_contents` / `preg_split` / `glob` の失敗は
      `Webmozart\Assert\Assert` で string / array へ絞る(`false` を型へ混ぜない)
- [x] **未解決を戻り値へ混ぜない**: 抽出に失敗した行は `null` / `''` を返さず `RuntimeException`
- [x] `preg_match` は戻り値 `1` との**厳密比較**で判定する(`false` と `0` を混ぜない)
- [x] `$matches[1]` はマッチ成立枝の中でのみ参照する
- [x] DTO 返却は不要(テスト内の純関数。配列の形は phpdoc の generics で固定)
- [x] `list<string>` を返す関数は `array_values` で連番を保証する
- [x] Genericsの型パラメータ: `array<string, int>` / `array<string, list<string>>` を明示

### テスト計画

**実施順(テストファースト)**:

1. **先にこのテストファイルを置き、施策 1 の本文をまだ書かない**。この時点で
   「§2 の 2 表が実在し…」と「走査が空振りしていない…」の 2 本が
   `アンカー小見出し「…」が §2 に無い` の例外で**赤になる**ことを確認する
   (= 走査根は生きているが母集団が作れない状態を先に見る)。
2. 施策 1 の本文を挿入して緑にする。
3. 負例 3 本(抽出不能 5 形 / アンカー・表の欠落 / 不存在・重複・件数不一致)と
   正例 1 本、負のコントロール 1 本が緑になることを確認する。
   **正例が最初から緑になる分岐**(規定どおりの入力を誤検出しない側)は、
   `INTEGRATION_GUIDE_GATE_CELL` を一時的に緩める / `integrationGuideCells` の両端処理を外す等で
   **一度赤にして検出分岐が生きていることを確認する**。
4. `composer test`(全体)/ `composer phpstan` / `vendor/bin/pint --test` を緑にする。

- [x] バグ修正ではないため再現テストは不要。新設ゲートの負例・正例で代替する
- [x] 既存テストの更新: **なし**(既存の inventory を触らない)
- [x] 新規テスト: 上記 6 ケース(正常系 1 / 空振り検査 1 / 負のコントロール 1 / 負例 3)
- [x] 個別の `DatabaseTransactions` は使わない(DB 不使用の静的検査)

### リスク

- **小見出しの文字列が文書と定数の 2 か所に現れる**。片方だけ変えるとアンカー不在の例外で
  必ず赤くなるので、黙って壊れることはない(この非対称を docblock に書く)。
- **件数 pin の更新忘れ**で表を増やしたときに赤くなる。これは仕様(無断の縮小・膨張を許さない)。
- グローバル関数名の衝突: Pest のテストファイル内関数はグローバルなので、
  `integrationGuide*` の接頭辞で 8 関数すべてを名前空間的に分ける。
  既存に同名は無いことを確認済み(`integrationGuide` で始まる関数は現状 0 件)。

---

## 施策 3: 乖離台帳の付け替え

### なぜ施策 1 と不可分か

`docs/app-integration-guide.md` は **テンプレート共有ファイル**
(`docs/template-fingerprints.json` の `entries` にキーとして実在。指紋
`9377362c…`)であり、かつ**採用時債務一覧に載っている**
(`tests/Support/TemplateDivergence/adoption-debt.tsv` の 53 行目。採用時ハッシュ
`8b9aa9f3…`)。現物の sha256 は採用時ハッシュと**一致している**(実測済み = 採用時の姿のまま)。

本文を 1 行でも変えると突合 gate の `mutatedDebtPaths`(F10)が落ちる。
債務一覧が縮む契機は `AdoptionDebtInventory` の docblock と D34 が定める 2 つだけ —
「内容をテンプレートへ戻す」か「意図的逸脱として登録簿へ書く」。テンプレート現物が無いので
前者は取れない。よって**後者が唯一の経路**である。

### 3-1. `docs/template-divergence.md` へ D40 を追加

- 番号: 現在の最大が **D39**。番号は再利用しないので **D40**(欠番は正常)。
- 挿入位置: ファイル末尾(D39 のエントリの後)。
- 冒頭の件数行 `登録エントリ: 36 件` → `登録エントリ: 37 件`
  (TD12 が「明示件数 / 見出しの実数 / `LedgerPins` の固定件数」の 3 点一致を強制する)。
- 対象パスは**全登録の和集合で重複しない**こと(TD4)。
  `docs/app-integration-guide.md` は現在どの登録の対象パスにも現れていないことを確認済み。

#### 追加する本文(全文)

```markdown
## D40 契約文書のゲート索引を、本アプリの実在ゲートへ写した判定規準として持つ

| 行 | 内容 |
|---|---|
| 対象パス | `docs/app-integration-guide.md` / `tests/Architecture/IntegrationGuideGateTableSyncTest.php` |
| 業務要件起因の説明 | 本文書は監査で実測された存在オラクルへの対処として本アプリ独自の節 (§5 のエラー応答の優先順位 / §7b 流量制限の付与規約 / §7c vendor route への後付け機構と経路キャッシュの契約) を持ち、§7 だけで 310 行を占める。家系の裁定 AG-116 はこの独自節を還流したうえで、本アプリが失っていた §2 のゲート表 2 本を復帰させた合成版を家系の正と定めた。テンプレート現物を参照できないため、復帰させた 2 表は逐語復元ではなく**本アプリの実在ゲート (tests/Architecture 配下) へ写した判定規準**である。ドメイン (SOP・シナリオ・撮影テイクのテナントデータ) に固有のゲート構成を索引にする以上、テンプレートの汎用形にも採用時点の姿にも収束しない |
| 揃え続ける不変条件と保証機構 | 索引が指すゲート名の実在・件数 (必ず踏む 8 件 / 条件付き 13 件)・表をまたいだ一意性は `tests/Architecture/IntegrationGuideGateTableSyncTest.php` が固定し続ける。§7 の不変条件を参照するときは番号ではなく項目名で指す (本文書 §7 と AGENTS.md の採番は 1:1 対応しないため、どちらの側も renumber しない) |
| 再判定の条件 | テンプレート更新の一括取り込みを行うとき / 家系の巡回で裁定 AG-116 の合成版の現物が配られたとき / §2 のゲート表の行を増減させるとき。いずれの場合も再照合の正本は家系の機能台帳 lctl の feature `app-integration-guide` とテンプレートの `docs/app-integration-guide.md` である |
| 決めた日 | 2026-08-22 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260822-2305-integration-guide-gate-table-restore/ |
| 状態 | 監視中 |
| 見直し期限 | 2027-02-28 |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| §2 のゲート索引 | 必ず踏む 8 本 / 条件付き 13 本 (テンプレートの汎用ゲート構成) | 同じ 8 件 / 13 件だが、行は本アプリの実在ゲート名で構成する |
| 独自節 | 持たない | §5 のエラー応答の優先順位 / §7b 流量制限の付与規約 / §7c 経路キャッシュの契約 |
| §7 の採番 | 1〜11 | 1〜10 (renumber しない。相互参照は項目名で行う) |
| §9 (正本から生成し写しを同期検査する) | 持つ | 持たない (裁定 AG-116 が名指しした 3 節の外) |
| 索引と実装の同期検査 | 文書と実装ゲートの整合を見る gate を持つ | §2 の 2 表に限った実在・件数・一意性の検査を持つ |

### なぜ正当な差分か (logic-driven)

本アプリの独自節は「エラーを返す順番を間違えると他組織のデータの存在が 1 bit 漏れる」という
**実測された監査所見**への対処であり、家系の裁定 AG-116 自身が「テンプレートに無いのは
取りこぼしに近い」と評価して還流の対象にした。逸脱の理由は互換・UX・作業量ではなく、
本アプリのドメイン (組織を跨いで漏れてはならない SOP・シナリオ・撮影テイク) に対する
実所見である。

§2 のゲート索引を本アプリの実在ゲート名で構成するのも同じ性質の判断である。索引は
「新しいドメインリソースを足すときにどの検査へ登録するか」を指すものなので、
実在しないゲート名を指す索引は無価値であり、テンプレートの汎用形をそのまま写すことはできない。

### 揃えている不変条件 (これは保証し続ける)

> 「§2 のゲート索引が指すゲートは実在する。必ず踏む表は 8 件、条件付きの表は 13 件で、
> 同じゲートが 2 度現れない」

- 実在・件数・一意性は同期検査が deny-by-default ではなく**抽出した各行の未解決・不存在・
  件数不一致・重複を拒否する**形で固定する
- §7 を参照するときに番号を使わない規約は人のレビューが担う

### 保証しないもの

- **採用時ハッシュによる追跡を失う**。突合 gate は「登録済みのパスの追加の drift は検出しない
  (検出するのは一致から不一致へ移る瞬間である)。**債務パスは例外**で、採用時ハッシュとの
  一致まで見る」と定めており、債務から登録へ移した本パスは以後の内容変更を検出されない。
  再照合の契機は本登録の見直し期限とテンプレート更新の一括取り込みである
- 表に書かれた発火条件・登録先の**意味的な正確さ**は機械では見ない (同期検査の docblock が正本)
- 設計者が実際に §2 の判定を踏んだかは見ない (家系の正典が「それを確かめる機械は家系のどこにも
  無い」と記録しており、本登録はその状況を変えない)

### 関連

- 実装: `tests/Architecture/IntegrationGuideGateTableSyncTest.php`
- 設計: `devnotes/20260822-2305-integration-guide-gate-table-restore/`
```

### 3-2. `tests/Support/TemplateDivergence/adoption-debt.tsv`

53 行目(`docs/app-integration-guide.md<TAB>8b9aa9f3…`)の **1 行だけを削除**する。
ヘッダ行(`# template_ledger_commit=a078806b…`)は**触らない**
(突合 gate の F14 が指紋台帳の `generated_at_commit` と突き合わせる)。
結果: 172 行 → 171 行(データ 171 件 → 170 件)。

### 3-3. `tests/Support/TemplateDivergence/LedgerPins.php`

```php
    /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
-    public const int DIVERGENCE_ENTRY_COUNT = 36;
+    public const int DIVERGENCE_ENTRY_COUNT = 37;
```

```php
-    public const int ADOPTION_DEBT_COUNT = 171;
+    public const int ADOPTION_DEBT_COUNT = 170;
```

`FINGERPRINT_POPULATION_COUNT`(281)/ `TEMPLATE_LEDGER_SOURCE_COMMIT` /
`TEMPLATE_LEDGER_SOURCE_SHA256` / `ADOPTION_DEBT_DIVERGENCE_ID`(34)は**変更しない**
(指紋台帳の母集合は縮まないし、取り込んだ正典台帳の出自も変わらない。
D34 は「一覧が 0 件になったとき」以外は据え置き)。

### 波及変更

- TypeScript 型定義 / API Resource・DTO: なし
- テストファイル: 既存 `TemplateDivergenceFingerprintTest` / `TemplateDivergenceLedgerFormatTest` は
  **変更しない**(値の変更だけで緑に戻る)。両者が本施策の検証手段そのものである

### PHPStan 適合チェック

- [x] `LedgerPins` は `public const int` の値変更のみ(型宣言・可視性を変えない)
- [x] 新しいメンバを足さない(解析・ファイル I/O を持たない定数置き場という docblock の約束を守る)

### テスト計画

- [x] 施策 1 の本文変更だけを入れた状態で `TemplateDivergenceFingerprintTest` が
      **`mutatedDebtPaths` で赤になることを先に確認する**(債務パスを編集したという事実の裏取り。
      施策 3 を後に回す理由)
- [x] 債務 1 行削除 + D40 追加 + 件数 pin 2 件の更新で、
      `TemplateDivergenceFingerprintTest`(F1〜F14)と
      `TemplateDivergenceLedgerFormatTest`(TD0〜TD12)が緑に戻ることを確認する
- [x] TD12 の 3 点一致(明示件数 37 / 見出しの実数 37 / `DIVERGENCE_ENTRY_COUNT` 37)
- [x] TD3(対象パス 2 件がファイルとして実在)/ TD4(和集合で重複なし)/
      TD10(根拠 `devnotes/20260822-2305-integration-guide-gate-table-restore/` が実在)/
      TD6(見直し期限 2027-02-28 が基準日から 400 日以内かつ未期限切れ)
- [x] TD1(見出し要約に印・矢印・「解消」「済み」を含めない)

### リスク

- **他 TODO との衝突**: `DIVERGENCE_ENTRY_COUNT` / `ADOPTION_DEBT_COUNT` と
  `docs/template-divergence.md` の件数行は、別の TODO が同時に乖離を登録すると必ず衝突する。
  → 実装モードを `standalone` にする理由。
- **見直し期限の切れ**: 2027-02-28 を過ぎると CI が赤くなる(仕様)。直し方は登録簿の規約節の
  4 通りから選ぶ(検査は緩めない)。
- **債務からの離脱で追跡が緩む**: 上記のとおり D40 の「保証しないもの」に明記して補償する。

---

## 全施策に共通する確認事項

### 変更しないもの(明示)

- `AGENTS.md`(§2 への参照は節名なので壊れない。セキュリティ不変条件の採番も触らない)
- `docs/template-fingerprints.json`(テンプレート側の指紋なので**アプリ側の編集では変えない**。
  書き換えて黙らせるのは禁止行為)
- 既存の Architecture テスト・inventory・exemption 目録
- `app/` / `routes/` / `resources/` / `database/` / `config/`(コード変更 0 行)
- `docs/TODO.md`(`app-todo-add` の責務)

### 検証コマンド(全 green でコミット)

`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
`pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
`pnpm build:packages` / `pnpm test:packages`

(JS 側は 1 行も変えないが、AGENTS.md の検証コマンド節が全 green を要求しているため通す)

### 波及変更の総括

インターフェース変更(API / ルート / DTO / コンポーネント Props)は**一切無い**。
よって TypeScript 型定義・Inertia Props・API Resource・既存テストへの波及は無い。
波及が発生する唯一の結線は「施策 1 の小見出し文字列 ↔ 施策 2 の定数」で、
これは同じ PR 内の 2 ファイルに閉じており、片方だけ変えると例外で赤になる。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 3 施策は不可分(本文を変えた瞬間に突合 gate が落ちるため、施策 1 だけを先にマージできない)。加えて `LedgerPins` の 2 定数と `docs/template-divergence.md` の件数行・番号 D40 は、**別の TODO が同時に乖離を登録すると必ず衝突する**共有カウンタである。単独の worktree で完結させ、乖離登録を伴う他 TODO と並走させない |
| 競合リスク | `DIVERGENCE_ENTRY_COUNT` / `ADOPTION_DEBT_COUNT` / 件数行 / D 番号の同時更新。マージ時に他 TODO が D40 を先に取っていたら D41 へ繰り上げ、件数も再計算する(番号は再利用しない) |


---

## 関連する現行コード

### docs/app-integration-guide.md §2 の現行末尾 (L56-95)

```markdown
### 見本: Item リソース(この手順の実演)

テンプレートには Project 配下のサンプルリソース **Item** が同梱されている。
**新しいドメインリソースを足すときは Item を見本として参照する**(またはリネームして使う)。
上の手順と実際のファイルの対応は次のチェックリストの通り:

| 手順 | Item での実装ファイル |
|---|---|
| マイグレーション(親 FK `constrained()` + NOT NULL + cascade) | `database/migrations/2026_06_11_080000_create_items_table.php` |
| Model(FK は `$fillable` 外、親 BelongsTo) | `app/Models/Item.php` + `app/Models/Project.php` の `items()` |
| 保護キー集合への FK 追記 | `app/Support/Security/MassAssignmentProtectedKeys.php`(Item の FK `project_id` は**既存リストに含まれる**ため追記不要。新規 FK 名のときだけ追記する) |
| FormRequest(`ProhibitsProtectedKeys` + missing rule) | `app/Http/Requests/Projects/StoreItemRequest.php` / `UpdateItemRequest.php` |
| nested route(Team セグメントなし = Default Team パターン) | `routes/web.php` の `/projects/{project}/items` 系 |
| URL 整合 guard(認可より**前**に 404) | {project} ∈ current org は 2 層: `project.in-current-org` middleware(`app/Http/Middleware/EnsureProjectBelongsToCurrentOrganization.php`。FormRequest の DB ルールより**前**に cross-org を 404 に落とす = 存在オラクル防止。web の {project} route group に一括付与、網羅性は `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php`)+ `app/Http/Concerns/ResolvesCurrentOrganization.php` の `resolveOrganizationProject()`(inline guard、二重防御)。{item} ∈ {project} は `routes/web.php` の `Route::scopeBindings()`(`$project->items()` 経由で解決) |
| API 側の URL 整合 guard(認可より**前**に 404、**FormRequest より前**) | {project} ∈ actor の組織は 2 層: `api.project-in-org` middleware(`app/Http/Middleware/EnsureProjectBelongsToApiOrganization.php`。組織は API キー / OAuth token から確定。網羅性と middleware 順序契約は `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php`)+ `ResolvesApiOrganization::resolveOrganizationProject()`(inline guard、二重防御)。{item} ∈ {project} は `routes/api.php` の `Route::scopeBindings()` |
| guard inventory への登録 | `tests/Architecture/NestedRouteIdorDefenseTest.php`(Web の `projects.items.update/destroy`、API の `api.v1.projects.items.update/destroy` = いずれも ScopeBindings) |
| 変更系 route の認可 gate | `tests/Architecture/ControllerAuthorizationGateTest.php`(POST/PUT/PATCH/DELETE は `Gate` を通るか exemption inventory に理由付き登録。§7 不変条件 8) |
| REST API v1 controller(Web と同じ FormRequest 再利用、org-scoped 解決、`Gate::forUser` 認可) | `app/Http/Controllers/Api/V1/ItemController.php`(`ResolvesApiOrganization` + `ReadsApiActor`) |
| API リソース(レスポンス整形) | `app/Http/Resources/Api/V1/ItemResource.php` |
| API ルート(nested + dual guard + ability + idempotent) | `routes/api.php` の `api.v1.projects.items.{index,store,update,destroy}` |
| API Feature テスト | `tests/Feature/Api/{ApiEndpointTest,ApiKeyTest,IdempotencyTest,OAuthDualGuardTest}.php` + `tests/Feature/Api/V1/ItemAuthorizationTest.php`(認可境界 / cross-org 404 / 存在オラクル封じ) |
| Policy(親 Policy へ委譲、直 fetch 禁止) | `app/Policies/ItemPolicy.php` → `app/Policies/ProjectPolicy.php` |
| Service(transaction + 所有権キーの明示代入) | 親側の見本: `app/Services/Project/ProjectService.php`(Default Team 自動割当)。Item は単一 insert のため relation 経由で Controller 直書き |
| Factory(親 Factory 連鎖) | `database/factories/ItemFactory.php`(project 未指定なら `ProjectFactory` 連鎖) |
| 画面(一覧は親の Show に内包。DS token/ramp のみ) | `resources/js/pages/Projects/Show.svelte`(+ Index/Create/Edit) |
| Feature テスト(保護キー 422 / cross-org・cross-project 404 / 権限) | `tests/Feature/Item/ItemCrudTest.php` / `ItemUrlIntegrityTest.php`(親側: `tests/Feature/Project/`) |
| フロント単体テスト | `tests/js/pages/ProjectsShow.test.ts` |

注意点(手順との差分・補足):
- Item の親 FK `project_id` はテンプレートの保護キー集合に最初から含まれているため
  「prohibited キー集合への追記」は発生しない。アプリ固有の新 FK(`site_id` 等)を持つ
  リソースを足すときに追記が必要になる。
- API(`/api/v1/...`)も REST API v1 として実装済み。Item は Web ルートに加えて API リソース
  (`Api/V1/ItemController` + `ItemResource`、nested route `api.v1.projects.items.*`)としても見本が
  存在する。API 追加時は同じ nested 形状 + flat ability(§5)+ dual guard(`auth:api-key,api-oauth`)
  + 書き込みへの `idempotent` middleware に従う。
- 子リソースの作成は親 FK を **relation 経由で代入**する
  (`$project->items()->create([...])`)。FK の mass assignment を書かない。

## 3. ロール・権限のマッピング
```

### tests/Support/TemplateDivergence/LedgerPins.php (全文)

```php
<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

/**
 * 逸脱の登録簿と指紋台帳の固定値 (不変の scalar 定数だけを持つ)。
 *
 * ★**解析・ファイル I/O・git 実行を一切持たない**。値の置き場所を 1 か所にするための型である。
 *   Pest のテストファイルに書いた `const` は**そのファイルが読み込まれた後にしか見えない**ため、
 *   2 つの gate (形式検査と突合) が同じ値を読むにはクラス定数である必要がある。
 * ★**これは免除の一覧ではない**。個別のパスや D 番号を名指しして規則を免除する仕組みは
 *   本機構のどこにも無い。
 */
final class LedgerPins
{
    /** インスタンス化しない (定数の置き場)。 */
    private function __construct() {}

    /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
    public const int DIVERGENCE_ENTRY_COUNT = 36;

    /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
    public const int FINGERPRINT_POPULATION_COUNT = 281;

    /**
     * 採用時債務の件数。
     *
     * ★機械が保証するのは**無断の増減の検出**までである (一覧と本定数を同じ変更で
     *   増やせば通る)。増加を許さないのは生成器のガードとレビュー規約であり、
     *   検査は「一覧と定数と実測が食い違ったら赤」を担う。
     */
    public const int ADOPTION_DEBT_COUNT = 171;

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

    /**
     * 取り込んだ正典台帳ファイル自身の sha256 (生成器の入力ガード)。
     *
     * 取得元は laravel-claude-template の `docs/template-fingerprints.json`
     * (読み取りコミット `0597a0c24d7fa7a054e3337704ccc97e4409b866` / 947 キー / 128420 バイト)。
     * 別の台帳を食わせるには生成器へ `--adopt-new-template-ledger` を明示する。
     */
    public const string TEMPLATE_LEDGER_SOURCE_SHA256 = '0c9add21dc79429f6d80e38cfeb95736af750bd760ee9584d2e2b8a1285c0c90';

    /** アプリ側の指紋台帳の置き場 (リポジトリ相対)。 */
    public const string FINGERPRINT_LEDGER_PATH = 'docs/template-fingerprints.json';
}

```

### tests/Support/TemplateDivergence/adoption-debt.tsv (先頭と該当行)

```
# template_ledger_commit=a078806b0574518ddc64966f60f7d536b1338b2f
.claude/agents/bughunt-shard.md	85c2a7b649178200415baa06768940aebb7d9ffce8f615c23da856dbec8922cf
.claude/skills/app-bug-hunt/SKILL.md	72504c5e21f3acb24eedde7bec4f6a4923005d9d99e941b708657649d48a4e81
...
docs/app-integration-guide.md	8b9aa9f354384c313144e5c3192242dae6fa81d1a90d1fb398696bc7398202a7
...
(全 172 行 = ヘッダ 1 + データ 171)
```

### docs/template-divergence.md の書式規約と直近エントリ D39 (見本)

```markdown
# テンプレート差分レジストリ

テンプレート(laravel-claude-template)の構造から**意図的に逸脱**した箇所の正本記録。
逸脱が正当なのは **logic-driven(ドメイン要件起因)のときだけ**。互換・UX・作業量を理由にした
逸脱は記録せず是正する(`docs/app-integration-guide.md` §0)。

**書式の正本は本節である**。家系の統一形式 (機能台帳 lctl の feature
`template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
`tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。

登録エントリ: 36 件

## 記録の原則

- 判定軸は「ライブラリ/実装が同じか」でなく「**同じ不変条件を同じタイミング/抽象度で保証するか**」。
  不変条件が揃っていれば構文差は許容
- **登録は逸脱を作る変更そのものに含める**。後でまとめて書かない。まだ実在しない逸脱
  (これから作る予定) は登録しない — 予定の管理は `docs/TODO.md` の役目である
- **解消した逸脱は登録から消す**。全パスが戻ったならエントリごと、一部が戻ったなら
  そのパスを対象パス欄から削る。状態の語で「解消済み」を表さない。
  台帳の中に履歴の節を作らない (走査の対象外になる領域は回避口になるため)
- 番号 (`D<n>`) は**再利用しない**。削除しても後続を詰めない (欠番は正常)。
  他リポジトリから参照するときは `aicue:D<n>` と書く
- **登録するか迷ったら登録する**。テンプレートの実物は手元に無いので「テンプレートに無い領域への
  上積み」か「ひな形から外れた判断」かを本アプリだけで確定できないことがある。
  誤登録はエントリを削除すれば是正できるが、登録漏れには気付けない。台帳リポジトリの巡回から
  「記録されるべき乖離」として届いた指摘は、この理由で登録する側へ倒す

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

## 見直し期限が切れたときの直し方 (4 通り)

1. 逸脱を解消して登録を消す
2. `恒久` へ変えて理由を足す
3. 期限を延ばして再判断の根拠を足す
4. 対象を分けて個別に判断する

**検査を緩めることは選択肢に入れない**。期限切れで CI が赤くなるのは仕様である。

## この登録簿が保証しないもの

```

```markdown
## D39 パスキー削除の同期購読者 pin は listener 追加ごとに更新され続ける固定値である

| 行 | 内容 |
|---|---|
| 対象パス | `tests/Architecture/PasskeyPackageContractTest.php` |
| 業務要件起因の説明 | 本ファイルは `PasskeyDeleted` の直接購読者を「同期で走る N 件だけ」という完全一致 pin で固定している (削除の巻き戻りの前提の検査)。新設の `App\Listeners\Auth\NotifyAuthMethodChange` (T110) が同じイベントを同期購読するため、この pin (顔ぶれ・件数・購読順) を更新する必要がある。この pin は「同期購読という前提が保たれているか」を業務追加ごとに人手で確認させる deny-by-default 機構であり、テンプレートの汎用形にも「採用時点の姿」にも収束しないため、採用時債務一覧が要求する 3 択のうち「意図的逸脱として登録する」を選ぶ |
| 揃え続ける不変条件と保証機構 | 「`PasskeyDeleted` の直接購読者は `ShouldQueue` を実装しない (同期で走る)」ことは本ファイルの検査が deny-by-default で強制し続ける。顔ぶれ・購読順の完全一致 pin は、新しい購読者が増減したときに人手での確認 (同期性が保たれているか) を強制する仕組みとして機能する |
| 再判定の条件 | 本ファイルの構成をテンプレート側の汎用形へ統合する判断をしたとき (現時点でその予定は無い) |
| 決めた日 | 2026-08-21 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260821-2015-auth-method-change-notification/ |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| pin の内容 | 汎用の骨組み (業務購読者無し) | 本アプリが `PasskeyDeleted` へ同期購読させた listener 全数の顔ぶれ・順序の固定値 |
| 更新頻度 | テンプレート更新時のみ | 同期購読 listener の追加・削除ごとに更新 (構造上恒常的) |

### なぜ正当な差分か (logic-driven)

本 pin は「新しい同期購読者を追加したら、それが本当に同期で走るかを人手で確認させる」という
deny-by-default 機構そのものであり、業務ドメイン (認証手段の変更に反応する処理) が
増える限り内容が変わり続けることが**設計の目的**である。今回 (T110) は
`NotifyAuthMethodChange` の追加 (2 件 → 3 件、購読順
`RecordSecurityEvent → NotifyAuthMethodChange → ClearRecentAuthOnPasskeyChange`) が
この恒常的な更新の 1 例である。

### 揃えている不変条件 (これは保証し続ける)

> 「`PasskeyDeleted` の直接購読者は全員 `ShouldQueue` を実装しない (同期で走る)。
```

### tests/Architecture/TemplateDivergenceFingerprintTest.php の docblock (保証範囲)

```php
/*
 * 指紋台帳 (`docs/template-fingerprints.json`) と実ファイルの**突合** (家系の正典 t1)。
 *
 * 落とすのは 2 つである:
 *  (3a) テンプレートと内容が食い違っているのに、逸脱の登録も採用時債務の記載も無いパス
 *  (3b) 内容がテンプレート準拠へ戻ったのに、逸脱の登録が残っているパス
 * 判定の実体は `FingerprintReconciler` (純関数) にあり、本テストは**現物を読んで観測を組み立て、
 * 種別ごとに空であることを見るだけ**の薄い層である。検出力 (負例) は
 * `tests/Unit/Architecture/TemplateDivergenceFingerprintRulesTest.php` と
 * `tests/Unit/Architecture/TemplateFingerprintGeneratorTest.php` が固定する。
 *
 * 母集合は**正典が公開する指紋台帳のキー ∩ 本リポジトリの追跡ファイル**である
 * (生成規則の正本は `AppFingerprintBuilder` の docblock)。
 *
 * ---------------------------------------------------------------------------
 * **この検査が保証しないもの** (誇張しない。ここが正本であり AGENTS.md や
 * docs/template-divergence.md には写さない):
 *
 *  1. **粒度はファイル単位**である。共有ファイルの**内部**の逸脱 (規約の一部だけを変えた等) は
 *     検出しない
 *  2. **母集合の外には沈黙する**。アプリ固有ファイル (提供元が共有しないと分類したもの。
 *     `AGENTS.md` / `tests/Pest.php` / `composer.json` / `docs/architecture.md` 等) と、
 *     正典側にしか無いパス (未受領 / 追従遅れ) は 1 件も見ない
 *  3. **テンプレート更新への追従遅れは検出しない**。指紋は取り込んだ時点の写しなので、
 *     正典が先へ進んでも本リポジトリでは食い違いが生じない
 *  4. **登録済みのパスの追加の drift は検出しない**。既に不一致で登録があるパスは、
 *     その後どれだけ内容が変わっても「不一致のまま」であり同じ判定になる
 *     (検出するのは**一致から不一致へ移る瞬間**である)。
 *     **債務パスは例外**で、採用時ハッシュとの一致まで見るので追加の変更は落ちる
 *  5. **採用時債務の中身は説明されていない**。意図的逸脱と追従遅れの区別は付いていない
 *     (分類の契機は登録簿の D34 の見直し期限である)。件数の正本は
 *     `LedgerPins::ADOPTION_DEBT_COUNT` であり、本 docblock には件数を書かない
 *  6. **手編集による無効化は止まらない**。指紋台帳 / 債務一覧 / `LedgerPins` / 本検査自身の
 *     書き換えは検査を書き換えるのと等価であり、PR レビューの義務である。
 *     F6 が保証するのは**必須メンバが母集合に残り regular file であること**までで、
 *     登録済みになった本検査の**中身**は固定しない
 *  7. **`generated_at_commit` の実在は検証しない** (別リポジトリの commit なので原理的に不可能)。
 *     書式と pin との一致だけを見る
 *  8. **git 追跡外のファイルは母集合に入らない**
 *  9. **本検査は突合であって遮断ではない**。逸脱を作れなくするものではなく、
 *     登録なしに作れなくするものである
 * 10. **債務一覧の増加は機械では止まらない**。生成器のガードと件数 pin の PR 差分に依存する
 *     (本検査は履歴を入力に取らないので旧コミットとの比較はできない)
 * ---------------------------------------------------------------------------
 *
 * 実行不能 (指紋台帳 / 登録簿 / 債務一覧が読めない、解釈できない、git が失敗する) は
 * skip でも緑でもなく**不合格**にする。
 */
```

### AGENTS.md 「静的検査 (gate) と走査器の共通規約」(新規ゲートが従う 5 条)

## 静的検査 (gate) と走査器の共通規約

**対象**: `tests/Support/` 配下の検出器 / gate の中に直接書かれた走査ロジック /
それらを使う gate (`tests/Architecture/` / `tests/js/architecture/`)。
次の 5 条を満たす。家系の機能台帳の正典 v1 をそのまま写したもので、5 条とも
**「検査は緑なのに穴が開いていた」実測事故**から出ている
(設計と既存の食い違いの棚卸しは `devnotes/20260818-0303-scanner-common-conventions/`)。

**条ごとの適用範囲**: (b)〜(d) は**該当するすべての走査**に適用する。
(a) は**クラス名・名前参照を解決する走査**、(e) は**語彙一致を判定する走査**にだけ適用する
(文字列だけを見る走査に (a) は無意味であり、名前を解決する走査に (e) は無関係である)。

- **(a) クラス参照は完全修飾名で突き合わせる**。`use` / group use / 別名つき取り込みを解いた
  完全修飾名で比べる。短名一致は別名つき取り込み 1 つで検査が黙り、末尾の要素だけの一致は
  同名の別クラスを拾う。**構文解析ライブラリの使用は必須ではない** (家系の裁定 AG-154 の (2))。
  字句走査 + 取り込み対応表でよく、条件は (b) と (c) を満たすことだけである
- **(b) 解決できない形は落とす (fail-closed)**。判定を拾いすぎる方向へ倒すのは可、
  見逃す方向へ倒すのは不可。ここでいう「落とす」は**見逃さない**という意味であり、
  正常なコードを違反と断定することではない。具体的には次の 3 つを守る。
  - **未解決を解決済みと同じ値へ混ぜない**。gate が保証すると宣言した範囲の中で参照を
    解決できなかったら、**未解決だと判別できる結果**か解析の失敗として利用側へ返し、
    gate を失敗させる。**無言で候補から外さない**
  - **保証範囲の外にする構文は docblock へ明記する**。明記したなら、その構文について
    **検出力を主張しない** (明記せずに落ちこぼすのは (b) 違反である)。
    ただし**保証範囲は走査器 1 本の docblock だけでは決まらない** — 利用側 gate の名前・
    守ると宣言した不変条件・検出力の主張まで含めて判定する。
    **走査器の限界を書き足すことは、既にある見逃しを規約適合へ変えない**。
    保証範囲の外にした構文で保護対象の操作を書ける場合、利用側 gate は
    **検出力の主張をその構文を除く形へ明示的に狭める**か、**未解決として失敗させる**かのどちらかにする
  - **「違反が 0 件」と「母集団が 0 件」を区別する**。落とすのは後者だけである。
    違反ゼロが正常な gate はいくらでもあるが、**判定に使う母集団が空**なのに緑になる形は、
    走査根の改名・ディレクトリ移動・抽出条件の綴り間違いで**走査が壊れても気付けない**。
    適用対象は「母集団の非空が不変条件である gate」で、**入力を受け取って候補を返し、
    母集団の非空を契約としない再利用可能な検出器は対象外**である
    (その場合は検出器を**使う側の gate** が母集団の非空を持つ)
- **(c) 検出力は負例で裏取りする**。わざと違反させた入力を検出できることと、
  規定どおりの入力を誤検出しないことの**両方向**を固定する
- **(d) 集めた走査結果を判定に使わない形を作らない**。収集するが誰も参照しない出力、
  数えるだけで比べない目録を作らない
- **(e) 語彙一致の否定形は区切り文字で分割したトークンの完全一致で判定する**。
  正規表現の語境界や素の部分文字列一致に頼らない。
  **何を区切りとするかは走査ごとに宣言する** (準拠実装: `tests/js/support/ds-purity.ts` が
  スタイル記述を class トークンへ割る文字集合を宣言し、その文字集合で割れない書き方は
  許可一覧へ登録できないことも併せて書いている)。
  負例には最低でも**接頭辞つき・打ち消しつき・接尾辞つきの 3 形**を置く
  (許可語の除去を素の部分文字列で書いたため、この 3 形まで一緒に消えて検出漏れになっていた、
  が本リポジトリの実測である)

### 走査器・gate を新設・変更するときに同じ PR で揃える 4 点

**発火条件**: 走査ロジック・走査対象・名前解決・判定条件・目録のいずれかを新設または変更するとき。
**コメントや docblock を実態に合わせて訂正するだけで検出範囲を変えない変更は発火しない**
(既知の不適合はその場で直さず、棚卸しに記録して別 TODO で追跡する)。

1. **負例と正例**。テストファーストで**先に赤くしてから**本体を書く (思考原則 5)。
   既存の抽出器を流用して最初から緑になる場合は、負例が押さえる分岐を一時的に壊して赤を確認する
2. **解決できない形を落とす分岐** ((b))
3. **走査が空振りしていないことの検査**。母集団が空でないこと / 走査根がそれぞれ生きていること
   (準拠実装: `FfmpegProcessLaunchInventoryTest` の「母集団が空でない」検査、
   `PromptGuardrailTest` の「各走査根が解決でき、いずれも空でない」検査)
4. **docblock に走査対象と保証しないものを書く**。中身の正本は docblock 側に置き、
   本書へ写さない

### 本リポジトリでの置き方

- **走査根の単一出典**: git 追跡下の PHP 全数を母集団にする走査は
  `Tests\Support\TrackedPhpSourceFiles` を使う。同じ列挙を 2 本持たない。
  母集団がそれより狭い走査は自分の根を持ってよいが、**存在しない根は fail-fast** で落とす
  (準拠実装 `PrismDirectDispatchScanner::roots()`)
- **負例の置き場は 3 通りとも認める**: 見本ファイル (`tests/Architecture/fixtures/`) /
  検出器の自己検査 (`tests/Unit/Architecture/`) / gate 内の合成入力。
  どこに置いてもよいが、**gate または検出器の docblock から辿れること**。
  1 つへ寄せる作業に見合う効果が無いため寄せない (思考原則 2)

### 検出力の主張の書き方

「検査ファイルが実在する」と「検出力が裏取りされている」は**別物**である。
後者を主張する記述は根拠を**同じ行に併記**し、併記の無い記述は**検出力未確認**と読む。
**遡及して裏取りを付ける作業は求めない** (家系の裁定 AG-154 の (1))。

> **本節の保証範囲 (誇張しない)**: 本節は**人がレビュー時に適用する規約であり、
> 機械では強制しない**。走査器の書き方を検査する仕組み (家系の先行実装が持つ走査器の索引と、
> その索引を文書へ投影して整合を見張る検査) は**作っていない**。したがって本節があっても
> 「すべての gate が 5 条を満たしている」とは読めない。**満たしていない箇所は実在し**、
> `devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md` に記録してある。
> 索引の新設を再検討する条件は同ディレクトリの概念設計に書いてある
> (新設 gate のレビューで規約の適用漏れが見つかった / 走査器候補の棚卸しをもう一度やる必要が出た /
> 全数性を主張する棚卸しが必要になった、の 3 つ)。

