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


あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

（アプリの使命・禁止事項は上記に挿入済み）

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
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: design token 経由で color / radius / typography を参照する設計か、hex 直書きを増やさないか
11. Atomic Design準拠: atoms/molecules/organisms/features/templates/pages の単方向 import と責務分離に沿った配置か。アイコンは Lucide 前提で SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: manual-cover-thumbnail (マニュアル代表サムネイルの表示)

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
5. LLM 呼び出しの Prism 直呼び(本タスクは LLM を呼ばない)
6. prompt 文字列のコード直書き(本タスクは prompt を持たない)
7. 操作系 POST の応答での `redirect()->intended()`(本タスクは POST を足さない)
8. 必須条件未充足を理由にボタンを disabled にする UI(本タスクはボタンを足さない)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- 新モデルは追加しない（Factory 追加も不要）
- **DTO + Inertia props** パターン（本タスクに API Resource は無い）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- フロントは Svelte 5 runes + DS token のみ（`DESIGN.md`）。アイコンは `@lucide/svelte` のみ
- component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import

### 本タスクに直接効くドメイン規約

- **ドメイン固有規約 12 (T148)**: 「採用済みかつ ready のテイクを持つか」の判定式を書いてよいのは
  `Services/Manual/AdoptedReadyTakeCoverage` ただ 1 ファイル。`adoptedTake` を参照する `app/` 配下の
  ファイルは `AdoptedTakeReferenceInventory` へ区分と 30 文字以上の根拠付きで登録が必須
  (`AdoptedReadyTakeCriterionInventoryTest` が deny-by-default + exact-fit)。
  **`adoptedTake` と `TakeStatus::Ready` を同じファイルに書かない** (検出 B)。
- **ドメイン固有規約 3**: 認証済み画面の 3 枚セット (no-store baseline / bfcache 秘匿 /
  Inertia history 暗号化) を壊さない。
- **T154 の `RenderArtifactSelectionInventory` は対象外**: 母集団は「`render_jobs` に対する
  succeeded 条件つきの直接クエリ」であり、本設計は `render_jobs` に触れない。**登録不要**。

## 概念設計リファレンス

- `devnotes/20260817-0003-manual-cover-thumbnail/conceptual-design.md` (Round 3 で APPROVED)

決定事項の要約:

- **D1 代表の決め方**: 表示順 (`cuts.sort_order` 昇順 → `cuts.id` 昇順) で最初に来る
  「採用テイクの `thumbnail_path` が非 null」なカットの、その採用テイク。
- **D1-1 責務の 3 層**: (a) 候補選択 = relation / (b) 状態判定 = `AdoptedReadyTakeCoverage` へ委譲 /
  (c) 合成 = DTO。(b) は eager load 済み relation だけを読み **DB へ問い合わせない**。
- **D2 フォールバック**: 代表なし・読み込み失敗はどちらも同寸法のプレースホルダ。
- **D3 配信**: 既存 `capture.takes.thumbnail` を再利用。**route を増やさない**。props には URL を載せず id だけ。
- **D4 整合契約**: (i) 配信可能性 / (ii) 代表選択の完全性 / (iii) 認可委譲の drift 検出 の 3 本。
- **D5 出す面**: 撮影 PWA の一覧のみ。PC 一覧には出さない (doc/04 に要件が無い)。
- **D6 転送量**: `loading="lazy"` (上限は保証しない)。ホバー自動再生は持ち込まない。

## 現行コードの確認結果（ブリーフ前提の検証）

| ブリーフの前提 | 検証 | 実際 |
|---|---|---|
| Capture/Index にサムネイルが無い | ○ | `resources/js/pages/Capture/Index.svelte` L106-134 に画像要素なし |
| T183 のテイクサムネイル配信 endpoint がある | ○ | `capture.takes.thumbnail` (`CaptureTakeController::thumbnail`) |
| `VideoManual::latestSucceededRender` が eager load 候補の作法 | ○ | `app/Models/VideoManual.php` L116-144 |
| シナリオ編集の動画列にサムネイルが無い | **×（訂正）** | **既に有る**。`CutTakeSummaryData::$adoptedHasThumbnail` → `ScenarioEditor.svelte` L1085-1090 が `TakeThumbnail.svelte` へ URL を渡している。前ラウンドの誤りが訂正されている |
| — | **追加発見** | 撮影 PWA 一覧は `Gate::authorize('view', $project)` (組織メンバー可)、サムネイル endpoint は `Gate::authorize('preview', $take)` → `ProjectPolicy::capture` (project メンバー以上)。**権限差がある** |
| — | **追加発見** | URL 導出は `resources/js/lib/capture/take-endpoints.ts#takeUrl()` に既に 1 本化されている |
| — | **追加発見** | `takes.thumbnail_path` が非 null になるのは `TakeThumbnailPipeline` の条件付き UPDATE (`where status=ready`) だけ |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 代表カット relation `VideoManual::coverCut()` | `app/Models/VideoManual.php` | 高 |
| 2 | T148 目録への登録 | `app/Support/Security/AdoptedTakeReferenceInventory.php` | 高 |
| 3 | cover DTO と summary への合成 | `app/DataTransferObjects/Capture/CaptureManualCoverData.php` (新規) / `CaptureManualSummaryData.php` | 高 |
| 4 | 一覧クエリの eager load と権限の 1 回評価 | `app/Http/Controllers/Capture/CaptureManualController.php` | 高 |
| 5 | TypeScript 型 | `resources/js/types/capture.ts` | 高 |
| 6 | 代表サムネイル component | `resources/js/components/features/capture/ManualCoverThumbnail.svelte` (新規) | 高 |
| 7 | 一覧カードへの差し込み | `resources/js/pages/Capture/Index.svelte` | 高 |
| 8 | Feature テスト | `tests/Feature/Capture/CaptureCoverThumbnailTest.php` (新規) / `CaptureManualListQueryCountTest.php` (新規) / `CaptureManualBrowsingTest.php` (更新) | 高 |
| 9 | Vitest | `tests/js/components/features/capture/ManualCoverThumbnail.test.ts` (新規) / `tests/js/pages/CaptureIndex.test.ts` (更新) | 高 |
| 10 | ドキュメント追記 | `docs/architecture.md` | 中 |

---

## 施策 1: 代表カット relation `VideoManual::coverCut()`

### 変更箇所

- ファイル: `app/Models/VideoManual.php` (docblock L26-35 / relation を L144 の後へ追加)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: 施策 3 が消費する
- テストファイル: 施策 8 (選択規則・タイブレーク・クエリ数)
- **目録**: 施策 2 (`adoptedTake` の文字列リテラルを持つため T148 の登録が必須)

### 現行コード

```php
 * @property int|null $total_length_ms
 * @property-read RenderJob|null $latestSucceededRender
 */
class VideoManual extends Model
{
```

```php
    public function latestSucceededRender(): HasOne
    {
        return $this->hasOne(RenderJob::class)->ofMany(
            ['id' => 'max'],
            /** @param Builder<RenderJob> $query */
            function (Builder $query): void {
                $query->where('kind', RenderKind::Render->value)
                    ->where('status', JobStatus::Succeeded->value);
            }
        );
    }
}
```

### 変更後コード

```php
 * @property int|null $total_length_ms
 * @property-read RenderJob|null $latestSucceededRender
 * @property-read Cut|null $coverCut
 */
```

```php
    /**
     * 一覧カードの代表サムネイルに使う**候補カット** (撮影 PWA のシナリオ選択画面。doc/05 §5.2)。
     *
     * 規則は「**表示順で最初に来る、採用テイクのサムネイルが出来ているカット**」ちょうど 1 件である。
     * 表示順は `sort_order` 昇順、同値は `id` 昇順 (シナリオ編集・撮影ナビと同じ規則)。
     *
     * **この relation は状態 (ready) を判定しない**。判定できる位置に置くと
     * `adoptedTake` と `TakeStatus::Ready` が同居し、ドメイン固有規約 12 (T148) が閉じた
     * 「判定式は `AdoptedReadyTakeCoverage` ただ 1 ファイル」という不変条件を壊す。
     * ここが持つのは「候補の絞り込み条件 (`thumbnail_path` が非 null)」と「順序」だけで、
     * 「採用済みかつ ready か」の決定は `AdoptedReadyTakeCoverage::readyTakeId()` に残る
     * (合成は `CaptureManualCoverData` を組む `CaptureManualSummaryData` 側で行う)。
     *
     * 一覧が行ごとにカットを走査すると N+1 になるため eager load できる形を用意する
     * (`CaptureManualListQueryCountTest` がクエリ数の行数非依存を固定する)。
     * 消費側は `with(['coverCut.adoptedTake'])` の**入れ子まで**張ること —
     * `adoptedTake` を載せ忘れると `AdoptedReadyTakeCoverage::readyTakeId()` が
     * 行ごとに lazy load して N+1 が復活する。
     * 目録登録は `AdoptedTakeReferenceInventory` (区分 DifferentCriterion)。
     *
     * @return HasOne<Cut, $this>
     */
    public function coverCut(): HasOne
    {
        return $this->hasOne(Cut::class)->ofMany(
            ['sort_order' => 'min', 'id' => 'min'],
            /** @param Builder<Cut> $query */
            function (Builder $query): void {
                $query->whereHas(
                    'adoptedTake',
                    /** @param Builder<Take> $take */
                    function (Builder $take): void {
                        $take->whereNotNull('thumbnail_path');
                    }
                );
            }
        );
    }
```

### 設計上の根拠 (`ofMany` の機序)

`vendor/laravel/framework/.../Concerns/CanBeOneOfMany.php` の `ofMany()` を実読して確認した:

- 第 1 引数が配列のとき、第 2 引数の `Closure` は**各集約サブクエリへ適用**される
  (`if ($aggregate instanceof Closure) { $closure = $aggregate; }` → ループ内で `$closure($subQuery)`)。
- 列は宣言順に段階適用される。`['sort_order' => 'min', 'id' => 'min']` は
  「候補のうち最小 `sort_order`」→「そのうち最小 `id`」の辞書順になる。
- **最後の列が主キー `id`** なので、外側クエリは主キー一致で join され 1 行に確定する
  (closure が外側クエリへ適用されないことは結果に影響しない)。
- `latestSucceededRender` (T182) と同じ形なので、一覧の eager load は 1 クエリで済む。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`HasOne`) + generics 注釈 `@return HasOne<Cut, $this>`
- [x] closure の引数に `@param Builder<Cut>` / `@param Builder<Take>` を明示
- [x] `@property-read Cut|null $coverCut` を追加 (property fetch の型が確定する)
- [x] null 安全: relation は null を返しうるため消費側で `?->` / 早期 return
- [x] DTO を返している (relation なので該当なし。props 化は施策 3)

### テスト計画

- [ ] 新規: 先頭カットが未採用でも、次に来る「採用テイク + サムネイル生成済み」カットが選ばれる
- [ ] 新規: `sort_order` が同値のときは `id` 昇順で選ばれる
- [ ] 新規: 候補が 0 件のとき relation は null
- [ ] 新規: クエリ数が行数・候補件数に依存しない
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- `ofMany` + `whereHas` の組み合わせが意図した SQL を作らない可能性。
  → **実 DB の Feature テスト**で選択規則とタイブレークを固定する (実装時に最初に書く)。
- 候補条件 (`thumbnail_path` 非 null) と表示条件 (ready) が将来ずれる可能性。
  → 施策 3 が `AdoptedReadyTakeCoverage` へ委譲し、ずれたら cover を出さない (安全側)。

---

## 施策 2: T148 目録への登録

### 変更箇所

- ファイル: `app/Support/Security/AdoptedTakeReferenceInventory.php` (`entries()` へ 1 件追加 + 1 件の根拠更新)

### 波及変更

- テストファイル: `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php` は**変更しない**
  (exact-fit の判定は目録側の登録で満たす)

### 現行コード

```php
            'Http/Controllers/Capture/CaptureManualController.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'whereHas(adoptedTake) による採用済みカット数の集計。'
                    .'ready を見ない別基準であり、レンダの充足判定とは意図的に統合しない。',
            ],
```

### 変更後コード

```php
            'Models/VideoManual.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'coverCut() が一覧カードの代表サムネイル候補を絞る条件として'
                    .'whereHas(adoptedTake, thumbnail_path 非 null) を持つ。'
                    .'見るのはサムネイルの生成有無だけで ready 状態は見ない別基準であり、'
                    .'採用済み ready テイクの充足判定 (AdoptedReadyTakeCoverage) とは意図的に統合しない。',
            ],
            'Http/Controllers/Capture/CaptureManualController.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'whereHas(adoptedTake) による採用済みカット数の集計。'
                    .'ready を見ない別基準であり、レンダの充足判定とは意図的に統合しない。'
                    .'代表サムネイルの eager load (coverCut.adoptedTake) も同ファイルに並ぶが、'
                    .'こちらは N+1 を防ぐ構造上の指定で判定を持たない。',
            ],
```

### 目録判定の確認 (検出 A / B)

| ファイル | 検出 A (参照) | 検出 B (`TakeStatus::Ready` 同居) | 対応 |
|---|---|---|---|
| `Models/VideoManual.php` | **する** (`'adoptedTake'` 文字列リテラル) | しない (同ファイルに `TakeStatus` の参照を書かない) | 新規登録 |
| `Http/Controllers/Capture/CaptureManualController.php` | する (既存の `whereHas('adoptedTake')`) | しない | 登録済み (根拠を更新) |
| `DataTransferObjects/Capture/CaptureManualSummaryData.php` | **しない** | — | 登録不要 |
| `DataTransferObjects/Capture/CaptureManualCoverData.php` | しない | — | 登録不要 |

- `CaptureManualSummaryData` は `AdoptedReadyTakeCoverage::readyTakeId($cut)` を呼ぶだけで、
  `adoptedTake` という識別子も文字列リテラルも持たない (検出 A に該当しない)。
- 一覧の eager load は `with(['coverCut.adoptedTake'])` = 文字列は `'coverCut.adoptedTake'` であり、
  走査が比べるのは `'adoptedTake'` との完全一致なので**新たな検出は生まない**
  (当該ファイルは既存の `whereHas('adoptedTake')` で既に登録済みなので、いずれにせよ登録は満たされる)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`array<string, array{kind: ..., rationale: string}>` は既存注釈のまま)
- [x] 追加要素の shape が既存注釈と一致

### テスト計画

- [ ] 既存 `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php` が緑であること
      (未登録参照でも stale entry でも赤くなる exact-fit)

### リスク

- 登録の根拠文が 30 文字未満だとテストが落ちる。→ 上記はいずれも 30 文字を大きく超える。

---

## 施策 3: cover DTO と summary への合成

### 変更箇所

- 新規: `app/DataTransferObjects/Capture/CaptureManualCoverData.php`
- 変更: `app/DataTransferObjects/Capture/CaptureManualSummaryData.php` (全域)

### 波及変更

- TypeScript 型定義: 施策 5 (`CaptureManualSummary.cover`)
- API Resource/DTO: 本施策そのもの
- テストファイル: `tests/Feature/Capture/CaptureManualBrowsingTest.php` のキー集合テスト (施策 8)

### 変更後コード (新規 DTO)

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

/**
 * 撮影一覧カードの代表サムネイル 1 枚を指す座標 (doc/05 §5.2 のサムネイル要件)。
 *
 * **2 つの別の行から合成する** (cut と take) ため、両方揃ったときだけ存在する形にしてある。
 * 片方だけ非 null という不正状態を型で表現できないようにするのが本 DTO の役目で、
 * 「代表が無い」は `CaptureManualSummaryData::$cover` が null であることで表す。
 *
 * URL は載せない。組み立て規則はフロント側の `lib/capture/take-endpoints.ts#takeUrl()` に
 * 1 本化されており (撮影 PWA と PC 編集面が共用する)、props に URL 文字列を持つと
 * 規則の置き場所が 2 つになる。署名 URL も載せない (取得は endpoint の 302 に限る)。
 */
final readonly class CaptureManualCoverData
{
    public function __construct(
        public int $cutId,
        public int $takeId,
    ) {}

    /**
     * @return array{cut_id: int, take_id: int}
     */
    public function toArray(): array
    {
        return [
            'cut_id' => $this->cutId,
            'take_id' => $this->takeId,
        ];
    }
}
```

### 現行コード (`CaptureManualSummaryData`, 抜粋)

```php
    public static function fromManual(VideoManual $manual): self
    {
        $cutsTotal = $manual->getAttribute('cuts_count');
        // …略…
        return new self(
            id: $manual->id,
            // …略…
            creatorName: $manual->creator?->name,
        );
    }

    /**
     * @return array{id: int, title: string, status: string, category_id: int|null,
     *   category_name: string|null, cuts_total: int, cuts_adopted: int, cuts_with_takes: int,
     *   updated_at: string|null, creator_name: string|null}
     */
    public function toArray(): array
    {
        return [
            // …略…
            'creator_name' => $this->creatorName,
        ];
    }
```

### 変更後コード

```php
final readonly class CaptureManualSummaryData
{
    public function __construct(
        public int $id,
        public string $title,
        public string $status,
        public ?int $categoryId,
        public ?string $categoryName,
        public int $cutsTotal,
        public int $cutsAdopted,
        public int $cutsWithTakes,
        public ?string $updatedAt,
        public ?string $creatorName,
        /**
         * 代表サムネイル 1 枚の座標。無い場合は null で、UI はプレースホルダを描く。
         * **UI はこの 1 つの値だけで判断する** (権限も状態もここで解決済み = 判断を 2 箇所に持たない)。
         */
        public ?CaptureManualCoverData $cover,
    ) {}

    /**
     * withCount('cuts', 'cuts as cuts_adopted_count', 'cuts as cuts_with_takes_count') +
     * with('category', 'creator') 済みの manual から生成する (Capture/IndexController の一覧クエリと対)。
     *
     * @param  bool  $canViewCover  代表サムネイルを見せてよいか
     *   (`ProjectPolicy::capture` を **project 単位に 1 回**評価した結果。行ごとに評価しない)。
     *   false のときは `coverCut` relation に**触れない** — 触ると relation 未ロード時に
     *   行ごとの lazy load が走り N+1 になる (権限の無い利用者には eager load を張らないため)。
     */
    public static function fromManual(VideoManual $manual, bool $canViewCover): self
    {
        // …既存の withCount 検証はそのまま…

        return new self(
            // …既存の引数はそのまま…
            creatorName: $manual->creator?->name,
            cover: self::resolveCover($manual, $canViewCover),
        );
    }

    /**
     * 代表サムネイルの座標を決める (概念設計 D1-1 の層 (c) = 合成のみ)。
     *
     * 層の分担:
     *   (a) 候補選択 … `VideoManual::coverCut()` (表示順 + サムネイル生成済み)
     *   (b) 状態判定 … `AdoptedReadyTakeCoverage::readyTakeId()` へ**委譲** (自前の述語を持たない)
     *   (c) 合成    … 本メソッド
     *
     * (b) は eager load 済み relation を読むだけで **DB へ問い合わせない**
     * (`with(['coverCut.adoptedTake'])` を張るのが呼び出し側の義務)。
     *
     * (a) が選んだカットで (b) が null を返したときは**次のカットを探さずに null を返す**。
     * 候補条件 (サムネイル生成済み) と表示条件 (採用済みかつ ready) は現行コードでは一致する
     * (`thumbnail_path` は `where status=ready` の条件付き UPDATE でしか非 null にならず、
     * ready から離れる遷移が存在しない) が、一致を前提にせず安全側 = 壊れた画像を出さない側へ倒す。
     */
    private static function resolveCover(VideoManual $manual, bool $canViewCover): ?CaptureManualCoverData
    {
        if (! $canViewCover) {
            return null; // relation に触れない (未ロードのため触ると lazy load = N+1)
        }

        $cut = $manual->coverCut;
        if ($cut === null) {
            return null; // 採用テイク付き + サムネイル生成済みのカットが 1 つも無い
        }

        $takeId = AdoptedReadyTakeCoverage::readyTakeId($cut);
        if ($takeId === null) {
            return null; // 候補条件と表示条件の食い違い → 出さない
        }

        return new CaptureManualCoverData(cutId: $cut->id, takeId: $takeId);
    }

    /**
     * @return array{id: int, title: string, status: string, category_id: int|null,
     *   category_name: string|null, cuts_total: int, cuts_adopted: int, cuts_with_takes: int,
     *   updated_at: string|null, creator_name: string|null,
     *   cover: array{cut_id: int, take_id: int}|null}
     */
    public function toArray(): array
    {
        return [
            // …既存キーはそのまま (順序も変えない)…
            'creator_name' => $this->creatorName,
            'cover' => $this->cover?->toArray(),
        ];
    }
}
```

追加 import: `use App\Services\Manual\AdoptedReadyTakeCoverage;`

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`?CaptureManualCoverData` / 配列 shape 注釈を更新)
- [x] null 安全 (`?->toArray()` / 早期 return の 3 段)
- [x] DTO を返している (配列返却は `toArray()` の 1 箇所のみ)
- [x] Genericsの型パラメータが正しい (該当なし)
- [x] `readyTakeId()` の戻り値 `?int` を早期 return で `int` に絞ってから DTO へ渡す

### テスト計画

- [ ] 新規: 代表の選択規則 (表示順・タイブレーク・候補なし)
- [ ] 新規: 候補はあるが ready でない → cover null (安全側)
- [ ] 新規: 権限が無い利用者 → 全行 cover null
- [ ] 更新: `CaptureManualBrowsingTest` のキー集合に `cover` を追加、非 null 時の内側キーと int 型を固定
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- `resolveCover` の早期 return を将来並べ替えて relation を先に触ると、権限の無い利用者で N+1 が復活する。
  → クエリ数テストを**権限の無い利用者でも**回す (施策 8)。

---

## 施策 4: 一覧クエリの eager load と権限の 1 回評価

### 変更箇所

- ファイル: `app/Http/Controllers/Capture/CaptureManualController.php` (`index()` L50-96)

### 波及変更

- TypeScript 型定義: 施策 5 経由
- API Resource/DTO: 施策 3 (`fromManual` の引数が 1 → 2 に増える。呼び出し元はこの 1 箇所のみ)
- テストファイル: 施策 8

### 現行コード

```php
        $manuals = $project->manuals()
            ->whereIn('status', [VideoManualStatus::Ready, VideoManualStatus::Published])
            ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId))
            ->when($search !== null, function (Builder $query) use ($search): void { /* …略… */ })
            ->when($mine, fn (Builder $query) => $query->where('created_by', $userId))
            ->with(['category', 'creator'])
            ->withCount([
                'cuts',
                'cuts as cuts_adopted_count' => fn (Builder $query) => $query->whereHas('adoptedTake'),
                'cuts as cuts_with_takes_count' => fn (Builder $query) => $query->whereHas('takes'),
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(static fn (VideoManual $manual): array => CaptureManualSummaryData::fromManual($manual)->toArray())
            ->all();
```

### 変更後コード

```php
        // 代表サムネイルの可視性は **project 単位に 1 回**だけ決める (行ごとに評価しない)。
        // 一覧の閲覧は組織メンバーなら可 (view) だが、サムネイル endpoint は
        // ProjectPolicy::capture (project メンバー以上) を要求する。この差を props 側で吸収し、
        // 撮れない利用者には 403 になる <img> を 1 つも描かせない (秘匿境界は props 側。T154 の作法)。
        $canViewCover = Gate::allows('capture', $project);

        $manuals = $project->manuals()
            ->whereIn('status', [VideoManualStatus::Ready, VideoManualStatus::Published])
            ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId))
            ->when($search !== null, function (Builder $query) use ($search): void { /* …略 (現行のまま)… */ })
            ->when($mine, fn (Builder $query) => $query->where('created_by', $userId))
            ->with(['category', 'creator'])
            // 代表サムネイル: 候補カットと**その採用テイクまで**入れ子で eager load する。
            // adoptedTake を載せ忘れると AdoptedReadyTakeCoverage::readyTakeId() が
            // 行ごとに lazy load して N+1 になる。見せない利用者には積まない (2 クエリの節約)。
            ->when($canViewCover, fn (Builder $query) => $query->with(['coverCut.adoptedTake']))
            ->withCount([
                'cuts',
                'cuts as cuts_adopted_count' => fn (Builder $query) => $query->whereHas('adoptedTake'),
                'cuts as cuts_with_takes_count' => fn (Builder $query) => $query->whereHas('takes'),
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(static fn (VideoManual $manual): array => CaptureManualSummaryData::fromManual($manual, $canViewCover)->toArray())
            ->all();
```

- `Gate` は既に import 済み (`use Illuminate\Support\Facades\Gate;`)。
- **層の順序は変えない**: `resolveOrganizationProject()` (404) → `Gate::authorize('view', $project)` (403)
  → 本追加の `Gate::allows('capture', ...)` (表示判断)。テナント境界 404 が認可より前という
  既存の順序に手を入れない。
- `Gate::allows` は**例外を投げない**ため、撮れない利用者の一覧表示は現状どおり成功する
  (画面ごと 403 にしない = 行き先のない詰みを作らない)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`Response` のまま)
- [x] `Gate::allows()` の戻り値は `bool` (そのまま DTO の `bool` 引数へ渡る)
- [x] closure の `Builder` generics は既存の書式を踏襲
- [x] DTO を返している (`CaptureManualSummaryData::toArray()`)

### テスト計画

- [ ] 新規: project メンバーには cover が入り、組織メンバー (非 project メンバー) には全行 null
- [ ] 新規: クエリ数が行数に比例しない (権限あり / 権限なしの両方)
- [ ] 既存: `CaptureManualBrowsingTest` の絞り込み・進捗カウント・cross-org 404 が緑のまま

### リスク

- `when($canViewCover, ...)` により**利用者によってクエリ数が変わる**。
  → クエリ数テストは「同一利用者で行数を変えて比較する」形にして、利用者間の比較はしない。

---

## 施策 5: TypeScript 型

### 変更箇所

- ファイル: `resources/js/types/capture.ts` (`CaptureManualSummary`)

### 波及変更

- テストファイル: `tests/js/pages/CaptureIndex.test.ts` の fixture (`makeSummary`) に `cover: null` を追加

### 現行コード

```ts
export interface CaptureManualSummary {
    id: number;
    title: string;
    status: string;
    category_id: number | null;
    category_name: string | null;
    cuts_total: number;
    cuts_adopted: number;
    cuts_with_takes: number;
    updated_at: string | null;
    /** 作成者名。退会/削除で解決不可のときは null (UI は「不明」) */
    creator_name: string | null;
}
```

### 変更後コード

```ts
/** PHP: App\DataTransferObjects\Capture\CaptureManualCoverData と対 */
export interface CaptureManualCover {
    cut_id: number;
    take_id: number;
}

export interface CaptureManualSummary {
    id: number;
    title: string;
    status: string;
    category_id: number | null;
    category_name: string | null;
    cuts_total: number;
    cuts_adopted: number;
    cuts_with_takes: number;
    updated_at: string | null;
    /** 作成者名。退会/削除で解決不可のときは null (UI は「不明」) */
    creator_name: string | null;
    /**
     * 代表サムネイル 1 枚の座標 (無ければ null = プレースホルダ)。
     * URL ではなく id を持つ。組み立ては lib/capture/take-endpoints.ts#takeUrl() が唯一の規則。
     * **null 判定以外の条件を UI 側で足さない** — 権限も状態もサーバ側で解決済みである。
     */
    cover: CaptureManualCover | null;
}
```

### PHPStan適合チェック

- 該当なし (TypeScript)。`pnpm typecheck` で確認する。

### テスト計画

- [ ] 更新: PHP↔TS キー集合契約テスト (施策 8) が `cover` を含む
- [ ] `pnpm typecheck` が緑

### リスク

- fixture 更新漏れで既存 Vitest が型エラーになる。→ 施策 9 で同時に直す。

---

## 施策 6: 代表サムネイル component

### 変更箇所

- 新規: `resources/js/components/features/capture/ManualCoverThumbnail.svelte`

### 波及変更

- TypeScript 型定義: なし (props はローカル interface)
- テストファイル: 施策 9 (新規 Vitest)

### 変更後コード

```svelte
<script lang="ts">
    import { Film } from "@lucide/svelte";

    /**
     * 撮影 PWA のシナリオ選択カードに出す**代表サムネイル 1 枚** (doc/05 §5.2)。
     *
     * 表示するか否かはサーバが決めている (props の cover が非 null かどうか)。
     * ここは「与えられた URL を出す / 出せなければ同寸法のプレースホルダを描く」だけで、
     * 権限や状態の判断を持たない (判断を 2 箇所に持たない)。
     *
     * 読み込みに失敗したときもプレースホルダへ戻す。署名 URL は期限を持ち、
     * PWA は画面を開いたまま放置されうるため、壊れた画像アイコンを現場に出さない。
     * 再試行はしない (画面を訪ね直せば新しい署名 URL を取り直せる)。
     */
    interface Props {
        /** 代表サムネイルの取得 URL (代表が無いときは null) */
        src: string | null;
        testId?: string;
    }

    let { src, testId }: Props = $props();

    // 失敗した URL そのものを覚える = src が変わったら自動的に再挑戦できる
    let failedSrc = $state<string | null>(null);
    const url = $derived(src !== null && src !== failedSrc ? src : null);
</script>

{#if url !== null}
    <img
        {src}
        alt=""
        loading="lazy"
        decoding="async"
        class="size-16 shrink-0 rounded-md border border-border object-cover"
        data-testid={testId}
        onerror={() => (failedSrc = src)}
    />
{:else}
    <div
        class="flex size-16 shrink-0 items-center justify-center rounded-md border border-border bg-neutral text-text-secondary"
        data-testid={testId}
        aria-hidden="true"
    >
        <Film class="size-5" />
    </div>
{/if}
```

- **DS token のみ**: `border-border` / `bg-neutral` / `text-text-secondary` は既存
  `TakeThumbnail.svelte` が使っている token と同一。hex 直書きなし。
- **アイコンは `@lucide/svelte`** の `Film` (テイクのプレースホルダと同じ絵柄に揃える)。
- **Atomic Design**: `features/capture/` に置く。pages (`Capture/Index.svelte`) からのみ import され、
  他 domain の features からは参照しない。
- `alt=""` (装飾画像。隣にタイトル文字列がある) / プレースホルダは `aria-hidden`。
  進捗はバッジが読み上げ対象として既にある。

### PHPStan適合チェック

- 該当なし (Svelte)。`pnpm lint` / `pnpm typecheck` / ds-purity テストで確認する。

### テスト計画

- [ ] 新規 Vitest: `src` 非 null で `<img>` が出て `src` / `loading="lazy"` が付く
- [ ] 新規 Vitest: `src` が null でプレースホルダが出る
- [ ] 新規 Vitest: `<img>` の error でプレースホルダへ落ちる
- [ ] 新規 Vitest: `src` が別の値に変わると再び `<img>` が出る (失敗の記憶が引きずられない)

### リスク

- 画像のアスペクト比が縦横まちまち (最大辺 640 で長辺を合わせる生成) のため、
  `object-cover` の正方形枠で見切れる。→ 一覧の識別用途では許容 (原寸表示は撮影画面が担う)。

---

## 施策 7: 一覧カードへの差し込み

### 変更箇所

- ファイル: `resources/js/pages/Capture/Index.svelte` (import 追加 + カード L108-131)

### 波及変更

- TypeScript 型定義: 施策 5
- テストファイル: 施策 9

### 現行コード

```svelte
                    <a href={`/app/projects/${project.id}/manuals/${manual.id}`} class="block">
                        <Card>
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-body font-medium">{manual.title}</p>
                                    …
                                </div>
                                <div class="shrink-0">
                                    {#if manual.cuts_total > 0 && manual.cuts_adopted === manual.cuts_total}
                                        <Badge tone="success">撮影完了</Badge>
                                    …
                                </div>
                            </div>
                        </Card>
                    </a>
```

### 変更後コード

```svelte
<script lang="ts">
    // …既存 import…
    import ManualCoverThumbnail from "@/components/features/capture/ManualCoverThumbnail.svelte";
    import { takeUrl } from "@/lib/capture/take-endpoints";
    import type { CaptureManualSummary } from "@/types/capture";

    /**
     * 代表サムネイルの URL。cover が null なら null (= プレースホルダ)。
     * **判断材料は cover の null 判定だけ**で、権限も状態もサーバ側で解決済みである。
     */
    function coverUrl(manual: CaptureManualSummary): string | null {
        if (manual.cover === null) return null;
        return takeUrl(
            { projectId: project.id, manualId: manual.id, cutId: manual.cover.cut_id },
            manual.cover.take_id,
            "/thumbnail",
        );
    }
</script>
```

```svelte
                    <a href={`/app/projects/${project.id}/manuals/${manual.id}`} class="block">
                        <Card>
                            <div class="flex items-center justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <ManualCoverThumbnail
                                        src={coverUrl(manual)}
                                        testId={`capture-cover-${manual.id}`}
                                    />
                                    <div class="min-w-0">
                                        <p class="truncate text-body font-medium">{manual.title}</p>
                                        …既存の 2 行 (カテゴリ・進捗 / 作成者・更新日) はそのまま…
                                    </div>
                                </div>
                                <div class="shrink-0">
                                    …既存のバッジはそのまま…
                                </div>
                            </div>
                        </Card>
                    </a>
```

- `min-w-0` を外側の flex にも付け、長いタイトルの `truncate` が効き続けるようにする。
- カード全体が `<a>` のままなので、サムネイルをタップしても遷移先は変わらない (導線を増やさない)。

### PHPStan適合チェック

- 該当なし (Svelte)。

### テスト計画

- [ ] 更新 Vitest: `cover` 非 null のとき `<img>` の `src` が
      `/app/projects/1/manuals/1/cuts/{cut_id}/takes/{take_id}/thumbnail` になる
- [ ] 更新 Vitest: `cover` が null のときプレースホルダが出る
- [ ] 更新 Vitest: 既存の作成者名・mine トグルのテストが緑のまま (fixture に `cover` を足す)

### リスク

- カード高さが 64px 分増える → 1 画面に入る件数が減る。
  識別性の向上と引き換えであり、doc/05 の要件どおり。ページネーションはスコープ外。

---

## 施策 8: Feature テスト

### 変更箇所

- 新規: `tests/Feature/Capture/CaptureCoverThumbnailTest.php`
- 新規: `tests/Feature/Capture/CaptureManualListQueryCountTest.php`
- 変更: `tests/Feature/Capture/CaptureManualBrowsingTest.php` (キー集合テスト)

### 波及変更

- なし (テストのみ)

### テスト計画 (D4 の 3 契約 + 選択規則 + 境界)

`tests/Feature/Capture/CaptureCoverThumbnailTest.php`:

| # | テスト名 | 検証内容 |
|---|---|---|
| 1 | 代表は表示順で最初の「採用テイク + サムネイル生成済み」カットになる | `sort_order` 0 は未採用、1 は採用済み+生成済み → cover は 1 の cut / その take |
| 2 | `sort_order` が同値なら `id` 昇順で決まる | 同 `sort_order` の 2 カットで小さい `id` が選ばれる |
| 3 | 採用テイクが無ければ cover は null | 撮影前の manual |
| 4 | 採用テイクのサムネイル未生成なら cover は null | `withThumbnail()` を付けない take を採用 |
| 5 | 生成済みだが ready でない採用テイクは cover にしない (安全側) | `status=processing` + `withThumbnail()` → null (**次のカットも探さない**ことも固定) |
| 6 | **契約 (i) 配信可能性**: cover 非 null なら、その id で組んだ URL が 302 を返す | props → URL → `assertRedirect` 相当 (`TakeObjectStorage` を mock) |
| 7 | **契約 (ii) 完全性**: 3 条件を 1 つずつ落とすと cover が null になる | 権限 / 候補 / ready の 3 軸 (#3・#4・#5・#8 と対) |
| 8 | **権限**: 組織メンバー (非 project メンバー) は全行 cover が null で、同 URL は 403 | 一覧は 200 のまま (画面ごと 403 にしない) |
| 9 | **契約 (iii) 認可委譲**: `Gate::allows('preview', $take)` と `Gate::allows('capture', $project)` が同値 | owner / project member / 組織メンバー / 他組織ユーザーの 4 者で一致 |
| 10 | **境界**: cover の id を別 org の URL に嵌めると 404 | cross-org (認可より前に 404) |
| 11 | **境界**: cover の id を別 project / 別 manual の URL に嵌めると 404 | cross-project / cross-manual (scopeBindings) |
| 12 | **props の内容**: cover の cut / take は必ずその manual 配下のものである | 2 つの manual を並べて取り違えが無いこと |
| 13 | **props に URL 文字列を載せない** | `cover` のキーは `cut_id` / `take_id` の 2 つだけで、値は int |

`tests/Feature/Capture/CaptureManualListQueryCountTest.php`
(`tests/Feature/Projects/ManualListQueryCountTest.php` と同じ作法: 暖機 GET → `DB::getQueryLog()` 比較):

| # | テスト名 | 検証内容 |
|---|---|---|
| 14 | 撮影一覧のクエリ数は行数に比例しない | 1 行の project と 10 行の project で同数 (全行が代表を持つ) |
| 15 | 代表の有無が混在してもクエリ数は変わらない | 候補 0 件・1 件・複数件が混ざった 10 行 vs 1 行 |
| 16 | 代表を見られない利用者でもクエリ数は行数に比例しない | 組織メンバー (非 project メンバー) で 1 行 vs 10 行 (= `resolveCover` の早期 return が relation に触れていないこと) |

`tests/Feature/Capture/CaptureManualBrowsingTest.php` (更新):

| # | テスト名 | 変更内容 |
|---|---|---|
| 17 | index の summary shape は TS `CaptureManualSummary` と対のキー集合 | 期待キー配列の末尾に `'cover'` を追加 |

- テストデータは Factory のみ (`VideoManual::factory()` / `Cut::factory()->withSortOrder()` /
  `Take::factory()->forCut()->withThumbnail()`)。採用は既存テストと同じく
  `$cut->forceFill(['adopted_take_id' => $take->id])->save()`。
- 個別の `DatabaseTransactions` は使わない (`tests/Pest.php` の `RefreshDatabase` グローバル適用)。
- 302 を返す #6 は `TakeObjectStorage` を Mockery で差し替える
  (既存 `CaptureManualBrowsingTest` の show テストと同じ作法。外部 HTTP は出さない)。

### PHPStan適合チェック

- [x] テストの closure に型注釈 (`function (): void`)
- [x] `inertiaPage()['props']` からの取り出しは既存テストと同じ書式を踏襲

### リスク

- #14〜#16 は初回リクエスト固有の初期化を拾うと不安定になる。
  → 既存 `ManualListQueryCountTest` と同じく**暖機 GET を 1 回**撃ってから計測する。

---

## 施策 9: Vitest

### 変更箇所

- 新規: `tests/js/components/features/capture/ManualCoverThumbnail.test.ts`
- 変更: `tests/js/pages/CaptureIndex.test.ts`

### 波及変更

- なし

### テスト計画

`ManualCoverThumbnail.test.ts`:
- [ ] `src` 非 null → `<img>` が描画され `src` と `loading="lazy"` を持つ
- [ ] `src` が null → プレースホルダ (img ではない) が描画される
- [ ] `<img>` に `error` を発火 → プレースホルダへ切り替わる
- [ ] `src` を別の値へ差し替えると再び `<img>` が出る (失敗の記憶が新しい URL に及ばない)

`CaptureIndex.test.ts` (更新):
- [ ] fixture `makeSummary()` に `cover: null` を追加 (既存 3 テストを緑に保つ)
- [ ] `cover` 非 null のとき `<img>` の `src` が
      `/app/projects/1/manuals/1/cuts/7/takes/9/thumbnail` になる (URL 規則の固定)
- [ ] `cover` が null のときプレースホルダが出る

### リスク

- `takeUrl()` の規則を将来変えると Vitest のリテラル比較が落ちる。
  → 落ちるのが正しい (URL 規則の変更は撮影 PWA と PC 面の両方に効く破壊的変更である)。

---

## 施策 10: ドキュメント追記

### 変更箇所

- ファイル: `docs/architecture.md` (§撮影 PWA のサムネイル生成 (T183) の直後へ 1 項追加)

### 変更後コード (追記する箇条書き)

```markdown
- **一覧カードの代表サムネイル (撮影 PWA。doc/05 §5.2)**: 代表は
  「**表示順 (`cuts.sort_order` 昇順 → `cuts.id` 昇順) で最初に来る、採用テイクの
  `thumbnail_path` が非 null のカット**」の、その採用テイク 1 枚である
  (`VideoManual::coverCut()` が候補を 1 件選び、`AdoptedReadyTakeCoverage::readyTakeId()` が
  採用済みかつ ready かを決める = 判定式は増やさない)。**配信は既存の
  `GET .../takes/{take}/thumbnail` をそのまま使い、route を増やさない**。props は URL ではなく
  `cut_id` / `take_id` を持ち、組み立て規則はフロントの `take-endpoints.ts` に 1 本化されている。
  **可視性は project 単位に 1 回**だけ `ProjectPolicy::capture` で決める (一覧の閲覧は
  組織メンバーなら可だが、サムネイル endpoint は project メンバー以上を要求するため、
  撮れない利用者には代表を出さない = 403 になる `<img>` を描かない)。
- **代表サムネイルについて保証しないもの**: 代表が「内容を最もよく表すカット」であること
  (規則は表示順であり内容を見ない) / 候補条件と表示条件が食い違ったときに次のカットへ
  探しに行くこと (**行わない**。安全側に倒して代表なしにする) / 一覧の取得枚数の上限
  (`loading="lazy"` は初期取得を抑制するヒントであり上限を保証しない。一覧はページネーションを持たない) /
  PC 一覧への表示 (doc/04 の一覧要件にサムネイル列が無いため出さない)
```

### テスト計画

- [ ] `docs/` の整合テストは無いため機械検証は無し。レビューで確認する。

### リスク

- なし (ドキュメントのみ)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) `AdoptedTakeReferenceInventory` は **exact-fit の目録**で、他タスクが同ファイルへ同時に登録すると Architecture テストが両方で赤くなる。(2) `VideoManual` に relation を足すため、モデルを触る他タスクと衝突しやすい。(3) 変更は Model / DTO / Controller / TS 型 / Svelte / テストと層を縦断するが、**依存関係は 1 本道**で他施策と分割実装する利点が無い。単独 worktree で一気通貫に実装し、テスト green を確認してから main へ入れるのが安全。 |
| 競合リスク | `resources/js/pages/Capture/Index.svelte` と `tests/js/pages/CaptureIndex.test.ts` は撮影 PWA を触る他タスクと衝突しうる。`docs/architecture.md` は追記位置が §撮影 PWA に固定されるため、同節を触るタスクとは行単位の競合が起きうる (追記のみなので解決は容易)。 |

## 実装順序 (テストファースト)

1. 施策 8 の #1〜#5 (代表の選択規則) を**先に書いて fail を確認**する
   (`coverCut` も `cover` props もまだ無い状態で赤くなること)。
2. 施策 1 → 施策 2 → 施策 3 → 施策 4 (サーバ側)。#1〜#5 が緑になる。
3. 施策 8 の #6〜#17 (契約・境界・クエリ数) を追加。
4. 施策 5 → 施策 6 → 施策 7 (フロント)、施策 9 の Vitest。
5. 施策 10 のドキュメント追記。
6. `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
   `pnpm typecheck` / `pnpm test` / `pnpm build` を全 green で完了。

## 全体のリスクと後退可能性

| リスク | 影響 | 緩和 |
|---|---|---|
| `ofMany` + `whereHas` が意図した SQL を作らない | 代表が出ない / 誤った行が出る | 実 DB の Feature テストを最初に書く (施策 8 #1・#2)。vendor 実読で機序は確認済み |
| eager load の張り忘れで N+1 | 現場の通信環境で一覧が遅くなる | クエリ数テスト 3 本 (行数 / 候補混在 / 権限なし) |
| 撮れない利用者に壊れた画像 | 現場の混乱 | props 側で権限を解決 + 契約テスト (i)(iii) |
| 署名 URL 期限切れ・S3 失敗 | 壊れた画像アイコン | component の読み込み失敗フォールバック |
| 転送量の増加 | 通信環境の悪い現場で重い | `loading="lazy"` / 64px 表示 / ホバー自動再生を持ち込まない。保証しないものを docs に明記 |
| T148 目録の exact-fit 違反 | Architecture テストが赤 | 施策 2 で登録。検出 A/B の判定表を設計に明記済み |
| 3 枚セット (規約 3) への影響 | 認証済み画面の復元 | 追加は props 1 キーと `<img>` 1 つのみ。no-store / bfcache guard / history 暗号化のいずれにも触れない |


---

## 参考: 概念設計 (Codex 合議で APPROVED 済み)

# 概念設計: manual-cover-thumbnail (マニュアル代表サムネイルの表示)

## 背景・課題

`doc/05 §5.2 シナリオ選択画面` は撮影 PWA の一覧要件を
「シナリオをカード形式で一覧表示 (サムネイル / タイトル / カテゴリ / 作成者 / 更新日 / **撮影進捗**)」
と定めている。

現行 `resources/js/pages/Capture/Index.svelte` は 6 要素のうち 5 つ
(タイトル / カテゴリ / 作成者 / 更新日 / 撮影進捗バッジ) を出しているが、
**サムネイルだけが無い**。カードは文字だけで、現場作業者が「どのマニュアルか」を
一目で判別する手がかりが無い。

### 現行コードで検証した前提 (ブリーフの前提の検証結果)

ブリーフの前提を鵜呑みにせず、現行コードを読んで 1 件ずつ確認した。

| ブリーフの主張 | 検証結果 | 根拠 |
|---|---|---|
| Capture/Index にサムネイルが無い | **正しい** | `resources/js/pages/Capture/Index.svelte` L106-134 にカード。画像要素なし |
| 一覧は他 5 要素を出している | **正しい** | 同 L111-129 (title / category_name / creator_name / updated_at / 進捗バッジ) |
| T183 でテイク単位のサムネイル生成と配信 endpoint が入っている | **正しい** | `takes.thumbnail_path` / `capture.takes.thumbnail` (`CaptureTakeController::thumbnail`) |
| 「代表する 1 枚」の決め方が残っている | **正しい** | 代表を選ぶコードは app/ に 1 つも無い |

**ブリーフに書かれていなかったが設計に効く事実** (現行コードを読んで判明したもの):

1. **PC 側 (シナリオ編集画面の動画列) には既にサムネイルがある**。
   `CutTakeSummaryData::$adoptedHasThumbnail` → `ScenarioEditor.svelte` L1085-1090 が
   `TakeThumbnail.svelte` へ `capture.takes.thumbnail` の URL を渡している。
   よって「サムネイル表示そのものが未実装」ではなく、**マニュアル単位の代表を決める層だけ**が無い。
2. **URL 導出の規則は既に 1 箇所にある** — `resources/js/lib/capture/take-endpoints.ts` の
   `takeUrl(target, takeId, "/thumbnail")`。props に URL 文字列を入れる必要はない。
3. **撮影 PWA の一覧は「撮影できない人」も見られる**。`CaptureManualController::index` の認可は
   `Gate::authorize('view', $project)` = 組織メンバーなら可。一方
   `capture.takes.thumbnail` は `Gate::authorize('preview', $take)` →
   `ProjectPolicy::capture` = 管理権限者または project メンバーのみ。
   **project メンバーでない組織メンバーは一覧を見られるがサムネイルは 403** になる
   (既存テスト `CaptureManualBrowsingTest`「撮影者 (project_member) も org member (非 project member) も閲覧はできる」)。
   → 素朴に img を貼ると、その利用者には行数ぶんの 403 と壊れた画像が並ぶ。
4. **`takes.thumbnail_path` が非 null になるのは `status=ready` の行だけ**である
   (`TakeThumbnailPipeline` の条件付き UPDATE `where status=ready and thumbnail_path is null`)。
   かつ take の status を `ready` 以外へ遷移させる経路は app/ に無い
   (`TakeRegistrationService` が INSERT 時に `ready` を明示代入するのが唯一の代入)。
5. **「採用済みかつ ready」の判定式はドメイン固有規約 12 (T148) で 1 ファイルに固定**されている
   (`Services/Manual/AdoptedReadyTakeCoverage`)。`adoptedTake` を参照する app/ 配下ファイルは
   `AdoptedTakeReferenceInventory` への登録が必須で、**`adoptedTake` と `TakeStatus::Ready` が
   同居するファイルは Canonical 1 件しか許されない** (検出 B)。
   → 代表サムネイルの選択を書く場所は、この gate を壊さない形に**設計段階で**寄せる必要がある。

## 改善アイデア

撮影 PWA のシナリオ選択画面のカードに、**そのマニュアルを代表する 1 枚**を出す。

### D1. 代表サムネイルの決め方 (決定的で説明できる規則)

> **表示順で最初に来る「採用テイクのサムネイルが出来ているカット」の、その採用テイクのサムネイル**

- 順序は `cuts.sort_order` 昇順、同値は `cuts.id` 昇順 (シナリオ編集・撮影ナビの表示順と同じ規則)。
  同値時のタイブレークは**テストで固定する** (実装依存の順序に寄りかからない)。
- 条件は「そのカットに採用テイクがあり、その採用テイクの `thumbnail_path` が非 null」。
- 「最初のカット固定」にはしない。最初のカットが未撮影のまま 2 番目以降を撮る運用は普通にあり、
  固定にすると**撮影が進んでいるのに代表が出ない**行が大量に出る。
  「先頭から探して最初に見つかったもの」なら、説明も 1 行で済み、撮影が進むほど安定する。
- 撮り直し・採用差し替えで代表が変わるのは**仕様**である (代表は「いま採用されている素材」を映す)。

#### D1-1. 責務の分離 (規約 12 を壊さないための必須条件)

代表の決定は**意図的に 3 層に分ける**。1 か所に寄せると、
`adoptedTake` と `TakeStatus::Ready` が同居するファイルが増えて T148 の検出 B に触れる。

| 層 | 置き場所 | 持つもの | 持たないもの |
|---|---|---|---|
| (a) 候補選択 | `VideoManual` の relation | 表示順で 1 件に絞る規則 + 「採用テイクの `thumbnail_path` が非 null」 | **状態 (ready) の判定を書かない** (`TakeStatus::Ready` をこのファイルに書かない) |
| (b) 状態判定 | `AdoptedReadyTakeCoverage::readyTakeId()` へ**委譲** | 「採用済みかつ ready のテイク id」 | 新しい述語を作らない (既存の唯一の式をそのまま呼ぶ) / **DB へ問い合わせない** |
| (c) 合成 | `CaptureManualSummaryData` | (a) が選んだカット + (b) が返した take id から cover を組む | 自前の ready 判定・自前の順序規則 |

- **(b) は行ごとの追加クエリを 1 本も出さない**。`readyTakeId()` の実体は
  `$cut->adoptedTake` のプロパティ読み出しであり、relation が eager load 済みなら
  DB へ行かない。これは canonical 自身の docblock が明文化している前提でもある
  (「一覧の直列化では eager load 必須 (`with('adoptedTake')`)。無いと N+1 になる」)。
  したがって **(a) の relation と (c) が使う採用テイクを同じ 1 回の eager load で載せることが、
  (b) を呼ぶ側の義務**である。複数 cut を一括判定する新 API は作らない
  (状態述語の入口が 2 つになり、規約 12 が閉じた「1 ファイル 1 述語」を弱めるため)。
- (a) と (b) の条件が食い違った場合 (= (a) が選んだカットで (b) が null を返した場合) は
  **cover を出さない** (次のカットへ探しに行かない)。安全側 = 壊れた画像を出さない側に倒す。
- この食い違いは**現行コードでは到達不能**である: `thumbnail_path` が非 null になるのは
  `TakeThumbnailPipeline` の条件付き UPDATE (`where status=ready`) だけで、
  take の status を `ready` 以外へ遷移させる経路が app/ に存在しないため。
  到達不能であることに寄りかからず、Feature テストで「食い違ったら cover が出ない」ことを固定する
  (将来 status 遷移が増えたときに壊れた画像ではなくプレースホルダへ落ちる)。

### D2. フォールバック

代表が決まらない (採用テイクが 1 つも無い / サムネイル未生成 / 生成失敗 / 過去分) 場合は
**同じ寸法のプレースホルダタイル**を描く。空欄にしない。
`TakeThumbnail.svelte` が既に採っている作法 (「生成完了後の再取得で同じ枠が画像へ置き換わる =
レイアウトが跳ねない」) をカード側でも踏襲する。撮影進捗バッジ (未撮影 / 撮影中 / 撮影完了) が
既にあるので、プレースホルダに文言を足して二重に説明しない (アイコンのみ)。

**画像の読み込みに失敗したときも同じプレースホルダへ戻す**。props が非 null でも、
署名 URL の期限切れ (PWA を開いたまま放置)・S3 側の失敗・通信断は起こりうる。
壊れた画像アイコンを現場に出さないため、component 側で読み込み失敗を捕まえて
プレースホルダへ落とす (再取得の再試行は入れない — 画面の再訪で新しい署名 URL を取り直せる)。

### D3. 配信は既存 endpoint をそのまま使う (route を増やさない)

`GET /app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/thumbnail`
(`capture.takes.thumbnail`) をそのまま使う。**新しい route は 1 本も足さない** (思考原則 2)。

- 代表サムネイルは「特定のテイクのサムネイル」以上のものではない。専用 endpoint を作ると
  同じ資源に 2 本目の API 面が生える (T184 が明示的に避けた形)。
- props には URL ではなく **`cut_id` / `take_id`** を載せ、URL は既存の
  `take-endpoints.ts#takeUrl()` で組む (規則の置き場所を増やさない)。
  **props に署名 URL も endpoint URL 文字列も載せない**ことは契約としてテストで固定する
  (props 面積を増やさない / 署名 URL を HTML に焼き付けない)。

### D4. props と endpoint の整合契約 (秘匿境界を props 側に置く)

`docs/architecture.md` は「props の `has_thumbnail` はこの 302 条件と 1 対 1 である」を
既存契約として持つ。代表サムネイルも同じ発想を採り、UI は `cover !== null` **だけ**で判断する
(`canManage` 等の第 2 の条件を UI 側に積まない = 判断を 2 箇所に持たない。T154 の作法)。

ただし**双条件としては書かない**。`capture.takes.thumbnail` は「その take が代表か」を
判定する endpoint ではなく、代表でない take でも条件を満たせば 302 を返す。
また `cover === null` のときは叩く URL 自体が props に無い。よって契約は**独立した 3 本**にする:

| # | 契約 | 固定の仕方 |
|---|---|---|
| (i) | **配信可能性**: `cover !== null` なら、その id から組んだ URL は同一利用者に対して 302 を返す | Feature テスト (props を取り → その URL を叩く) |
| (ii) | **代表選択の完全性**: 「capture 権限あり ∧ D1 の候補あり ∧ canonical の ready 判定成立」がすべて成り立つときちょうど `cover !== null`。いずれか不成立なら `cover === null` | Feature テスト (3 条件を 1 つずつ落とす) |
| (iii) | **認可委譲の drift 検出**: `TakePolicy::preview` の判定が `ProjectPolicy::capture` と同値である | 代表の有無とは独立したテスト |

そのために props 側で 2 つを閉じる:

1. **状態条件**: 「採用済みかつ ready」の判定は `AdoptedReadyTakeCoverage::readyTakeId()` へ
   **委譲**する (自前で書かない = 規約 12)。加えて `thumbnail_path` 非 null を見る。
2. **権限条件**: `Gate::allows('capture', $project)` が false の利用者には
   全行の代表を `null` にする (= プレースホルダ)。**判定は 1 リクエストにつき 1 回**で、
   行数に比例しない。これで 3.の「見えるが撮れない人」に 403 の壁紙を見せずに済む。

**この同値は「`ProjectPolicy::capture` が endpoint 側の唯一の判定源である」ことに依存する**
(`TakePolicy` は全 ability を `capture` へ委譲するだけのクラスである)。
行ごとに `Gate::allows('preview', $take)` を評価する案は採らない — `TakePolicy::preview` が
`$take->cut?->videoManual?->project` を辿るため**行数ぶんの lazy load** を生み、
本設計の主目的 (N+1 回避) と正面衝突するからである。
代わりに**依存を機械で固定する**: 上表 (i)(iii) の 2 本がこの依存のトリップワイヤになる。
`preview` 側に `capture` 以外の条件が増えたら、(iii) が直接赤くなり、
(i) も「props は cover を出すのに endpoint が 403」で赤くなる。

### D5. 出す面は撮影 PWA の一覧だけ (PC 一覧には出さない)

- 撮影 PWA (`Capture/Index`) は `doc/05 §5.2` が明示的にサムネイルを要求している → **出す**。
- PC 一覧 (`doc/04 動画一覧ページ`) の列は「No / 状態 / タイトル / カテゴリ / 再生時間 /
  更新日 / DL / 削除」で、**サムネイル列は要件に無い**。PC 側は既に
  ①行内プレビュー (T189 のオーバーレイ) で中身を確認でき、
  ②シナリオ編集画面の動画列でカットごとのサムネイルを見られる。
  代表 1 枚を足しても新しい判断材料にならない一方、転送量と props 面積は増える。
  → **出さない**。要件が無いものを作らない (思考原則 2)。

### D6. 転送量と署名 URL の取得回数 (現場の通信環境)

- 生成物は `capture.thumbnail_max_edge=640` / `thumbnail_jpeg_quality=5` の JPEG。
  実測は取っていないが、この設定なら 1 枚あたり数十 KB のオーダーである。
- **`loading="lazy"` を付ける** (既存 `TakeThumbnail.svelte` と同じ)。
  **保証範囲を誇張しない**: lazy loading は初期表示時の取得を抑制する**ヒント**であり、
  取得枚数の上限は保証しない (viewport 近傍の先読み量はブラウザ実装依存)。
  一覧が現状ページネーションを持たないことと合わせ、行数が非常に多い組織では
  取得枚数が大きくなりうる。これは受容する (ページネーションは別タスク)。
- 1 枚の表示につき **アプリへの GET 1 回 (302) + S3 への GET 1 回**。
  302 は `no-store, private` なので、画面を再訪するたびに署名 URL を取り直す
  (= 期限切れ URL を握らない代わりに、回数は「表示した枚数」ぶん発生する)。
  署名 URL の発行はローカル計算 (S3 への往復なし) なので、サーバ側費用は無視できる。
- 描画サイズは小さく固定する (カード左のタイル)。**ホバー自動再生 (T190) は PWA 一覧には
  持ち込まない** — 動画本体の転送が発生し、現場の通信環境では割に合わない。

## 期待効果

- **主効果 — 識別性の向上**: 「思考ゼロ」で撮る導線の入口で、現場作業者が**読まずに**目的の
  マニュアルを選べるようになる。文字だけのカードは、手袋・屋外・小さい画面という
  撮影現場の条件で読みにくい。
- `doc/05 §5.2` の要件を満たす (6 要素中 5 → 6)。
- **副次効果 — 進捗の補助**: 撮影が進むと代表が付く。ただし**進捗表現としては穴がある**
  (過去分・生成失敗・生成待ちはプレースホルダのまま) ため、進捗の正本は既存の
  撮影進捗バッジのままとし、代表サムネイルにその役割を負わせない。

## 実装方針 (概要)

| 層 | 変更 |
|---|---|
| Model | `VideoManual` に代表カットの `HasOne` relation (`ofMany` で 1 件確定) を足す。`latestSucceededRender` (T182) と同じ作法 |
| Controller | `CaptureManualController::index` の eager load に代表カット + その採用テイクを足す。`Gate::allows('capture', $project)` を 1 回だけ評価して DTO へ渡す |
| DTO | `CaptureManualCoverData` (readonly / `cutId` / `takeId`) を新設し、`CaptureManualSummaryData::$cover` を `?CaptureManualCoverData` にする。ready 判定は `AdoptedReadyTakeCoverage` へ委譲 |
| TS 型 | `types/capture.ts` の `CaptureManualSummary` に `cover` を追加 |
| UI | `features/capture/` に代表サムネイルのタイル component を 1 つ追加し、`pages/Capture/Index.svelte` のカードへ差し込む |
| 目録 | `AdoptedTakeReferenceInventory` に新規参照ファイルを登録 (deny-by-default) |
| テスト | Feature (props 契約 / 選択規則 / 権限 / 境界 404 / parity / クエリ数の行数非依存) + Vitest |

`cover` を小 DTO にするのは、**2 つの別の行 (cut と take) から合成する 2 つの id** を
「両方 null か両方非 null」という型の形で表せるからである。既存 `CutTakeSummaryData` は
同種の合成を配列 shape のまま持ったため `toArray()` に防御的な三重 null 判定が残っている
(同じ形を増やさない)。

### テストの軸 (境界系は 3 つに分ける)

1. **index 自体の境界**: 別 org / 別 project の `{project}` で撮影一覧が 404 になる (既存の回帰)。
2. **cover の id を使った endpoint の境界**: props から得た `cut_id` / `take_id` を
   別 org・別 project の URL に嵌めて叩くと**認可より前に 404** になる。
3. **props の内容**: 他 org / 他 manual の take id が cover に混入しない
   (代表は必ずその manual 配下のカット・その採用テイクである)。

加えて **shape の契約**: 既存の「summary shape は TS `CaptureManualSummary` と対のキー集合」
テストに `cover` を足し、非 null 時の内側キー (`cut_id` / `take_id`) が int であることも固定する。
**クエリ数テストは「候補 0 件・1 件・複数件」で追加クエリ数が一定であることを見る**
(行数だけでなく候補の有無でもクエリが増えないこと)。

## 制約・前提

- **ドメイン固有規約 12 (T148)**: `adoptedTake` を参照する app/ ファイルは目録登録必須。
  `adoptedTake` と `TakeStatus::Ready` を**同じファイルに書かない** (検出 B)。
  → 状態判定は `AdoptedReadyTakeCoverage::readyTakeId()` への委譲で満たす。
- **T154 の `RenderArtifactSelectionInventory` は対象外**。あの目録の母集団は
  「`render_jobs` に対する succeeded 条件つきの直接クエリ」であり、本設計は `render_jobs` に
  一切触れない。**登録は不要**である (ブリーフの確認事項への回答)。
- **T182 の eager load 作法は踏襲する**: 一覧が行ごとに解決するとクエリが行数に比例するため、
  `ofMany` の relation として eager load 可能な形で持ち、クエリ数の行数非依存を
  Feature テストで固定する (`ManualListQueryCountTest` の撮影 PWA 版)。
- **認証済み画面の 3 枚セット (ドメイン固有規約 3) を壊さない**: 追加するのは Inertia props の
  1 キーと `<img>` 1 つだけで、no-store baseline / bfcache guard / history 暗号化のいずれにも
  触れない。302 の `no-store, private` も現状のまま (弱めない)。
- **`response()->json()` 直書き禁止**: 変更は Inertia props (DTO 経由) のみ。新規 endpoint なし。
- **PHPStan level 10**: relation の generics 注釈、null 安全 (`?->`)、DTO の配列 shape 注釈を明示。
- **DESIGN.md**: 色・角丸・余白は DS token のみ。アイコンは `@lucide/svelte`。
- **Atomic Design**: 新 component は `features/capture/` (pages から import。features 間の
  横参照はしない)。

## スコープ外

- PC 一覧へのサムネイル列追加 (D5 の理由により作らない)。
- 代表サムネイルの**手動選択 UI** (「この 1 枚を表紙にする」)。要件に無い。決め方が決定的なら
  まず自動で足りる。必要になったら別タスクで判断する。
- 専用の表紙画像生成 (別解像度・別トリミング)。既存のテイクサムネイルを流用する。
- 過去分テイクのサムネイル一括バックフィル (T183 が「行わない」と決めた方針を変えない)。
  古いマニュアルは代表なし = プレースホルダになる。
- 一覧のページネーション。現状の仕様を変えない (`loading="lazy"` で転送を抑える)。
- ホバー/タップでの自動再生 (T190) の PWA 一覧への移植。


---

## 関連する現行コード

### app/Models/VideoManual.php (全文)

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\VideoManualStatus;
use Database\Factories\VideoManualFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * VideoManual (Project 配下の動画マニュアル)。
 *
 * - project_id / created_by / category_id は保護キーのため $fillable 外
 * - category は project スコープで再解決した Category を associate する (payload 直代入しない)
 * - Project 側 relation は manuals() (route パラメータ {manual} の scopeBindings 推論と一致させ
 *   IDOR 防御を確実にするため videoManuals() は使わない)
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $category_id
 * @property int $created_by
 * @property string $title
 * @property VideoManualStatus $status
 * @property int $scenario_version
 * @property int|null $total_length_ms
 * @property-read RenderJob|null $latestSucceededRender
 */
class VideoManual extends Model
{
    /** @use HasFactory<VideoManualFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'title',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VideoManualStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<SourceDocument, $this>
     */
    public function sourceDocuments(): HasMany
    {
        return $this->hasMany(SourceDocument::class);
    }

    /**
     * @return HasMany<Cut, $this>
     */
    public function cuts(): HasMany
    {
        return $this->hasMany(Cut::class);
    }

    /**
     * AI 解析ジョブ (route param {analysisJob} の scopeBindings 推論と一致する relation 名)。
     *
     * @return HasMany<AnalysisJob, $this>
     */
    public function analysisJobs(): HasMany
    {
        return $this->hasMany(AnalysisJob::class);
    }

    /**
     * レンダジョブ (route param {renderJob} の scopeBindings 推論と一致する relation 名)。
     *
     * @return HasMany<RenderJob, $this>
     */
    public function renderJobs(): HasMany
    {
        return $this->hasMany(RenderJob::class);
    }

    /**
     * 「いま受け取れる完成動画」の**候補行** (kind=render の最新 succeeded 1 行)。
     *
     * 世代の選び方は `CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)` と
     * **同一**である (同 manual・同 kind の最新 succeeded。旧世代へフォールバックしない)。
     * 違いは 1 点だけで、**こちらは受け取れるかを判断しない** — `output_path` を見ないため
     * NULL (掃除済み) の行も返す。受け取れるかの決定 (`output_path` を含む) は
     * `CurrentRenderArtifact` が行い、一覧向けの入口は
     * `CurrentRenderArtifact::fromLoadedRenderCandidate($manual)` である
     * (`ManualListItemData` が合成するのは published 判定と ability 判定だけ)。
     * 候補行と選択式が同じ行を指すことは `ManualRowFinishedVideoParityTest` が固定する。
     *
     * 一覧が行ごとに `currentSucceeded()` を呼ぶと N+1 になるため、eager load できる形を用意する
     * (`ManualListQueryCountTest` がクエリ数の行数非依存を固定する)。
     * 目録登録は `RenderArtifactSelectionInventory` (区分 EagerLoadCandidate)。
     *
     * @return HasOne<RenderJob, $this>
     */
    public function latestSucceededRender(): HasOne
    {
        return $this->hasOne(RenderJob::class)->ofMany(
            ['id' => 'max'],
            /** @param Builder<RenderJob> $query */
            function (Builder $query): void {
                $query->where('kind', RenderKind::Render->value)
                    ->where('status', JobStatus::Succeeded->value);
            }
        );
    }
}

```

### app/Http/Controllers/Capture/CaptureManualController.php (index 抜粋 L49-96)

```php
    /** 撮影対象 (ready/published) の manual 一覧。category / q で絞り込み */
    public function index(Request $request, Project $project): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
        Gate::authorize('view', $project);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class); // view 認可済み = 認証済み。早期に int を確定
        $userId = $user->id;

        $categoryId = $request->filled('category') ? (int) $request->string('category')->value() : null;
        $search = $request->filled('q') ? $request->string('q')->value() : null;
        $mine = $request->boolean('mine'); // "1"/"true" を bool 正規化

        $manuals = $project->manuals()
            ->whereIn('status', [VideoManualStatus::Ready, VideoManualStatus::Published])
            ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId))
            // LIKE メタ文字 (%/_/\) はリテラル検索として扱う (PC 一覧 manualRows と統一)
            ->when($search !== null, function (Builder $query) use ($search): void {
                Assert::string($search);
                $query->where('title', 'like', '%'.addcslashes($search, '%_\\').'%');
            })
            // 自作フィルタ: 自ユーザー id のみ (payload 非受領 = tenant/actor キー不信)
            ->when($mine, fn (Builder $query) => $query->where('created_by', $userId))
            ->with(['category', 'creator'])
            ->withCount([
                'cuts',
                // 採用済み cut 数 (relation 経由 = 'adopted_take_id' リテラルを撮影経路に増やさない)
                'cuts as cuts_adopted_count' => fn (Builder $query) => $query->whereHas('adoptedTake'),
                'cuts as cuts_with_takes_count' => fn (Builder $query) => $query->whereHas('takes'),
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(static fn (VideoManual $manual): array => CaptureManualSummaryData::fromManual($manual)->toArray())
            ->all();

        return Inertia::render('Capture/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'manuals' => array_values($manuals),
            'categories' => $project->categories()
                ->orderBy('sort_order')
                ->get()
                ->map(static fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])
                ->all(),
            'filters' => ['category' => $categoryId, 'q' => $search, 'mine' => $mine],
        ]);
    }
```

### app/DataTransferObjects/Capture/CaptureManualSummaryData.php (全文)

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use App\Models\VideoManual;
use Webmozart\Assert\Assert;

/**
 * 撮影一覧 (Capture/Index) の 1 行分。TS 側 types/capture.ts の CaptureManualSummary と対で保守。
 * 進捗カウント (cuts_total / cuts_adopted / cuts_with_takes) は withCount 済みモデルから読む。
 * creator は表示目的のみ (検索対象外)。User.name は CipherSweet PII のため whereBlind 検索の
 * 対象にはしない (自作フィルタは created_by の id 一致で行う)。
 */
final readonly class CaptureManualSummaryData
{
    public function __construct(
        public int $id,
        public string $title,
        public string $status,
        public ?int $categoryId,
        public ?string $categoryName,
        public int $cutsTotal,
        public int $cutsAdopted,
        public int $cutsWithTakes,
        public ?string $updatedAt,
        public ?string $creatorName,
    ) {}

    /**
     * withCount('cuts', 'cuts as cuts_adopted_count', 'cuts as cuts_with_takes_count') +
     * with('category', 'creator') 済みの manual から生成する (Capture/IndexController の一覧クエリと対)。
     */
    public static function fromManual(VideoManual $manual): self
    {
        $cutsTotal = $manual->getAttribute('cuts_count');
        $cutsAdopted = $manual->getAttribute('cuts_adopted_count');
        $cutsWithTakes = $manual->getAttribute('cuts_with_takes_count');
        Assert::integer($cutsTotal, 'withCount(cuts) 済みの manual を渡してください');
        Assert::integer($cutsAdopted, 'withCount(cuts as cuts_adopted_count) 済みの manual を渡してください');
        Assert::integer($cutsWithTakes, 'withCount(cuts as cuts_with_takes_count) 済みの manual を渡してください');

        return new self(
            id: $manual->id,
            title: $manual->title,
            status: $manual->status->value,
            categoryId: $manual->category?->id,
            categoryName: $manual->category?->name,
            cutsTotal: $cutsTotal,
            cutsAdopted: $cutsAdopted,
            cutsWithTakes: $cutsWithTakes,
            updatedAt: $manual->updated_at?->toIso8601String(),
            creatorName: $manual->creator?->name, // 退会/削除で null (実運用では FK RESTRICT)
        );
    }

    /**
     * @return array{id: int, title: string, status: string, category_id: int|null,
     *   category_name: string|null, cuts_total: int, cuts_adopted: int, cuts_with_takes: int,
     *   updated_at: string|null, creator_name: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status,
            'category_id' => $this->categoryId,
            'category_name' => $this->categoryName,
            'cuts_total' => $this->cutsTotal,
            'cuts_adopted' => $this->cutsAdopted,
            'cuts_with_takes' => $this->cutsWithTakes,
            'updated_at' => $this->updatedAt,
            'creator_name' => $this->creatorName,
        ];
    }
}

```

### app/Services/Manual/AdoptedReadyTakeCoverage.php (readyTakeId まで)

```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\Render\OrderedCut;
use App\DataTransferObjects\Manual\TakeCoverageData;
use App\Enums\Manual\TakeStatus;
use App\Models\Cut;
use App\Models\VideoManual;

/**
 * 「採用済みかつ ready のテイクを持つか」の**唯一の判定**。
 *
 * render (422 でブロック) と preview (ブロックせず告知) は**制裁が違うだけで基準は同じ**である。
 * 基準がファイルをまたいで複製されると再び乖離する (bug-hunt F-1-01 の構造的原因) ため、
 * 述語 isMissing() をここ 1 箇所に閉じ、`AdoptedReadyTakeCriterionInventoryTest` が
 * deny-by-default で「他ファイルが同じ判定を書き直していないこと」を機械検査する。
 *
 * 読み取り専用 (cuts / takes / status を 1 バイトも書かない)。
 */
final class AdoptedReadyTakeCoverage
{
    /**
     * 「使用できる採用テイク」の **id** (無ければ null)。**この式が唯一の実体**である。
     *
     * `isMissing()` は本メソッドの上に載る (bool しか返さない述語のままだと、id が要る側が
     * `adopted_take_id` と `TakeStatus::Ready` を組み直すことになり、T148 が閉じた二重化が
     * そのまま復活する)。撮影 PWA の通し再生はこの id を props 経由で受け取り、
     * TypeScript 側で述語を再実装しない。
     *
     * 前提 ($cut の adoptedTake の鮮度。3 段で読むこと):
     *   1. **一覧の直列化では eager load 必須** (`with('adoptedTake')`)。無いと N+1 になる
     *      (CutSequencer::orderedWithLabels / CaptureManualDetailData::fromManual が張っている)。
     *   2. **単一 Cut の直列化では lazy load を許容する** — relation 未ロードで、かつ最新の
     *      `adopted_take_id` を持つインスタンスなら結果は同じである (adopt 応答の経路)。
     *   3. **古い relation cache を持つインスタンスは不可**。ロード後に `adopted_take_id` を
     *      書き換えたインスタンスをそのまま渡さないこと (呼び出し側の責務)。
     */
    public static function readyTakeId(Cut $cut): ?int
    {
        $take = $cut->adoptedTake;
        if ($take === null || $take->status !== TakeStatus::Ready) {
            return null;
        }

        return $take->id;
    }

    /**
     * 唯一の述語。**この式を他所へ写経しない**。
     *
     * TakeStatus は uploading / processing / ready / failed の 4 値を持つため、
     * 本述語が真になるのは「まだ撮っていない」だけではない
```

### app/Support/Security/AdoptedTakeReferenceInventory.php (docblock と一部 entries)

```php
<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Enums\Security\AdoptedTakeReferenceKind;

/**
 * `adoptedTake` relation を参照する app/ 配下ファイルの目録 (deny-by-default。T148)。
 *
 * 守る不変条件:
 *   「採用済みかつ ready のテイクを持つか」の判定式を書いてよいのは
 *   `Services/Manual/AdoptedReadyTakeCoverage.php` ただ 1 ファイルである。
 *
 * 強制は `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php`
 * (exact-fit: 未登録の参照も、参照実体を失った stale entry も fail させる)。
 */
final class AdoptedTakeReferenceInventory
{
    /**
     * app/ 相対パス => [区分, 根拠 (30 文字以上)]。
     *
     * @return array<string, array{kind: AdoptedTakeReferenceKind, rationale: string}>
     */
    public static function entries(): array
    {
        return [
            'Services/Manual/AdoptedReadyTakeCoverage.php' => [
                'kind' => AdoptedTakeReferenceKind::Canonical,
                'rationale' => '判定式の実体。render の 422 と preview の事前告知・Placeholder 分岐が'
                    .'同じ述語 isMissing() を通るための唯一の場所 (bug-hunt F-1-01 の再発防止)。',
            ],
            'Services/Manual/CutSequencer.php' => [
                'kind' => AdoptedTakeReferenceKind::RelationWiring,
            // …中略…
            'DataTransferObjects/Manual/CutTakeSummaryData.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'シナリオ編集画面の動画列が、カットごとに採用テイクの id / status / '
                    .'サムネイル生成有無を表示条件として読むだけで、採用済み ready テイクの充足判定はしない。'
                    .'レンダの充足判定 (AdoptedReadyTakeCoverage) とは基準が違うため意図的に統合しない。',
            ],
            'DataTransferObjects/Manual/TakeSelectionPageData.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'PC テイク選択画面が「今どれを採用しているか」を示すために'
                    .'採用テイクの id と status を読むだけで、ready 判定も充足判定もしない。'
                    .'レンダの充足判定 (AdoptedReadyTakeCoverage) とは意図的に統合しない。',
            ],
            'Http/Controllers/Projects/VideoManualController.php' => [
                'kind' => AdoptedTakeReferenceKind::RelationWiring,
                'rationale' => 'シナリオ編集画面の動画列を N+1 なしで取るため with(adoptedTake) の'
                    .'eager load を張るだけで、判定も読み取りも持たない。値の取り出しは'
                    .'CutTakeSummaryData 側にあり、そちらが別基準として登録済みである。',
            ],
            'Http/Controllers/Capture/CaptureManualController.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'whereHas(adoptedTake) による採用済みカット数の集計。'
                    .'ready を見ない別基準であり、レンダの充足判定とは意図的に統合しない。',
            ],
            'Services/Dashboard/DashboardService.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'whereDoesntHave(adoptedTake) による撮影待ち件数の集計。'
                    .'ready を見ない別基準であり、レンダの充足判定とは意図的に統合しない。',
            ],
            'Console/Commands/Development/PipelineSmokeCommand.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'bug-hunt のパイプライン通し確認で未採用カット件数を数えるだけの'
                    .'開発用コマンド。adoptedTake 参照側は ready を見ない (別の TakeStatus::Ready '
                    .'参照は登録直後のテイク自身の確認であって採用テイクの充足判定ではない)。',
            ],
        ];
    }
}
```

### app/Http/Controllers/Capture/CaptureTakeController.php (thumbnail 抜粋)

```php

    /**
     * テイクのサムネイル表示 (302 → S3 署名 URL)。撮影者/編集者 (capture ability)。
     * doc/04 動画列 / doc/05 撮影後の下部サムネイル確認。
     *
     * 層の順序は playback と同一 (認可より前に 404):
     * 1. {project} ∈ current org (project.in-current-org middleware + resolveOrganizationProject)
     * 2. {manual}∈{project}, {cut}∈{manual}, {take}∈{cut} は Route::scopeBindings()
     * 3. 認可 (preview ability。動画の再生と同じ権限で見せる)
     *
     * 404 にするのは 2 つ: ready でないテイク (内部状態を存在有無として漏らさない) と、
     * **サムネイル未生成** (生成前・生成失敗・過去分)。UI は has_thumbnail で出し分けるため
     * 通常この 404 は起きないが、生成前の取得競合を安全側に倒すために閉じておく。
     *
     * 302 応答は Cache-Control: no-store, private (期限付き署名 URL の再利用防止)。
     * ※ リダイレクト先の画像本体の cache までは保証しない (動画側と同じ扱い)。
     */
    public function thumbnail(
        Request $request,
        Project $project,
        VideoManual $manual,
        Cut $cut,
        Take $take,
        TakeObjectStorage $storage,
    ): RedirectResponse {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('preview', $take);

        if ($take->status !== TakeStatus::Ready) {
            abort(404);
        }
        // ローカル変数へ取ってから早期 return する (プロパティのままだと level 10 が narrowing を保持しない)
        $path = $take->thumbnail_path;
        if ($path === null) {
            abort(404); // 未生成 (生成前 / 失敗 / 過去分)
        }

        return redirect()
            ->away($storage->temporaryThumbnailUrl($path))
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }
}
```

### app/Policies/TakePolicy.php (抜粋) と ProjectPolicy::capture

```php

/**
 * Take (撮影素材) の認可。全 ability を親 (ProjectPolicy::capture) へ委譲する (直 fetch 禁止)。
 * 撮影者 (project_member) は upload/登録/並べ替え/コメント/削除/adopt/DL ACK が可能
 * (adopt を撮影者に含めるのは doc/10 §10.5 の確定仕様。概念設計 D10)。
 */
class TakePolicy
{
    public function __construct(
        private readonly ProjectPolicy $projectPolicy,
    ) {}

    /** 作成 (upload-url / POST takes): 対象 Take が無いため Project を追加引数に取る */
    public function create(User $user, Project $project): bool
    {
        return $this->projectPolicy->capture($user, $project);
    }

    public function update(User $user, Take $take): bool
    {
        return $this->captureVia($user, $take);
    }

    public function delete(User $user, Take $take): bool
    {
        return $this->captureVia($user, $take);
    }

    public function adopt(User $user, Take $take): bool
    {
        return $this->captureVia($user, $take);
    }

    public function markDownloaded(User $user, Take $take): bool
    {
        return $this->captureVia($user, $take);
    }

    /** プレビュー再生: 撮影者 (project_member) 以上。採用前テイクも対象 (doc/04・doc/05) */
    public function preview(User $user, Take $take): bool
    {
        return $this->captureVia($user, $take);
    }

    private function captureVia(User $user, Take $take): bool
    {
        $project = $take->cut?->videoManual?->project;

        return $project !== null && $this->projectPolicy->capture($user, $project);
    }
}

// ProjectPolicy (抜粋)

    /** 閲覧: 所属組織のメンバーなら可 */
    public function view(User $user, Project $project): bool
    {
        $organization = $project->organization;

        return $organization !== null && $user->organizationRole($organization) !== null;
    }

    /** 作成: 組織の owner / admin */
    public function create(User $user, Organization $organization): bool
    {
        return $user->organizationRole($organization)?->canManage() ?? false;
    }

    /** 更新: 組織の owner / admin または project_admin */
    public function update(User $user, Project $project): bool
    {
        return $this->canManageProject($user, $project);
    }

    /** 削除: 組織の owner / admin または project_admin */
    public function delete(User $user, Project $project): bool
    {
        return $this->canManageProject($user, $project);
    }

    /**
     * 撮影 (take の capture/upload/adopt): 管理権限者または project メンバー
     * (doc/10 §10.5 撮影者)。TakePolicy が全 ability を本メソッドへ委譲する。
     */
    public function capture(User $user, Project $project): bool
    {
        if ($this->canManageProject($user, $project)) {
            return true;
        }

        $organization = $project->organization;
        if ($organization === null || $user->organizationRole($organization) === null) {
            return false; // cross-org 不変条件
        }

        return $project->memberRole($user) !== null; // Admin / Member どちらも撮影可
    }
```

### app/Services/Capture/TakeThumbnailPipeline.php (条件付き UPDATE 抜粋)

```php
                return;
            }

            $this->storage->upload($thumbnail, $key, 'image/jpeg');

            // 結果の一回性: preflight と同じ述語を条件へ再掲する。
            // 0 行 = 先着したワーカーか状態変化 → 何もしない (**オブジェクトは消さない**。
            // キーが決定的なので、消すと勝者の実体を壊すことになる)
            Take::query()
                ->whereKey($take->getKey())
                ->where('status', TakeStatus::Ready->value)
                ->whereNull('thumbnail_path')
                ->update([
                    'thumbnail_path' => $key,
                    'thumbnail_size_bytes' => $size,
                ]);
        } finally {
            File::deleteDirectory($workDir); // 自分の作業領域だけを消す (他人のものには触れない)
        }
    }

```

### resources/js/pages/Capture/Index.svelte (全文)

```svelte
<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import { Camera, Search } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Checkbox from "@/components/atoms/Checkbox.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import { formatDate } from "@/lib/date-format";
    import type { SharedProps } from "@/lib/shared-props";
    import type { CaptureManualSummary } from "@/types/capture";

    /**
     * 撮影 PWA: シナリオ (動画マニュアル) 一覧。カテゴリ / キーワードで絞り込み、
     * 行タップで撮影ナビ (Capture/Show) へ。撮影対象は ready/published のみ (サーバ側で絞り込み済み)。
     */
    interface Props {
        project: { id: number; name: string };
        manuals: CaptureManualSummary[];
        categories: { id: number; name: string }[];
        filters: { category: number | null; q: string | null; mine: boolean };
    }

    let { project, manuals, categories, filters }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    let search = $state(filters.q ?? "");
    let categoryId = $state(filters.category === null ? "" : String(filters.category));
    let mine = $state(filters.mine);

    function applyFilters(): void {
        const query: Record<string, string> = {};
        if (search !== "") query.q = search;
        if (categoryId !== "") query.category = categoryId;
        if (mine) query.mine = "1";
        router.get(`/app/projects/${project.id}/manuals`, query, {
            preserveState: true,
            preserveScroll: true,
        });
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="撮影するマニュアルを選ぶ"
            description={project.name}
            icon={Camera}
            testId="capture-heading"
        />
        <PageContent>
            <div class="flex flex-col gap-2 sm:flex-row">
                <form
                    novalidate
                    class="flex min-w-0 flex-1 items-center gap-2"
                    onsubmit={(event) => {
                        event.preventDefault();
                        applyFilters();
                    }}
                >
                    <Input
                        type="search"
                        bind:value={search}
                        placeholder="タイトルで検索"
                        testId="capture-search"
                    />
                    <button type="submit" class="shrink-0 text-text-secondary" aria-label="検索">
                        <Search class="size-5" aria-hidden="true" />
                    </button>
                </form>
                <div class="sm:w-56">
                    <Select bind:value={categoryId} onchange={applyFilters} testId="capture-category">
                        <option value="">すべてのカテゴリ</option>
                        {#each categories as category (category.id)}
                            <option value={String(category.id)}>{category.name}</option>
                        {/each}
                    </Select>
                </div>
            </div>

            <div class="mt-3">
                <Checkbox
                    id="capture-mine"
                    bind:checked={mine}
                    label="自分が作ったシナリオ"
                    onchange={applyFilters}
                    testId="capture-mine"
                />
            </div>

            <div class="mt-4 flex flex-col gap-3" data-testid="capture-manual-list">
                {#if manuals.length === 0}
                    <EmptyState
                        icon={Camera}
                        title="撮影できるマニュアルがありません"
                        description="シナリオが確定 (ready) になると、ここに表示されます。"
                    />
                {/if}
                {#each manuals as manual (manual.id)}
                    <a href={`/app/projects/${project.id}/manuals/${manual.id}`} class="block">
                        <Card>
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="truncate text-body font-medium">{manual.title}</p>
                                    <p class="mt-1 text-caption text-text-secondary">
                                        {manual.category_name ?? "未分類"}
                                        ・カット {manual.cuts_total} / 採用済 {manual.cuts_adopted}
                                    </p>
                                    <p class="mt-0.5 text-caption text-text-secondary">
                                        {manual.creator_name ?? "不明"} ・ 更新 {formatDate(
                                            manual.updated_at,
                                        )}
                                    </p>
                                </div>
                                <div class="shrink-0">
                                    {#if manual.cuts_total > 0 && manual.cuts_adopted === manual.cuts_total}
                                        <Badge tone="success">撮影完了</Badge>
                                    {:else if manual.cuts_with_takes > 0}
                                        <Badge tone="tertiary">撮影中</Badge>
                                    {:else}
                                        <Badge tone="neutral">未撮影</Badge>
                                    {/if}
                                </div>
                            </div>
                        </Card>
                    </a>
                {/each}
            </div>
        </PageContent>
    </PageContainer>
</AppLayout>

```

### resources/js/lib/capture/take-endpoints.ts (全文)

```ts
/**
 * テイク API (capture.takes.*) の URL 導出。**規則をここ 1 箇所に置く**。
 *
 * この API 面は撮影 PWA (Capture/Show の TakeStrip) と PC 編集面
 * (Manuals/Takes) の**両方が叩く**。URL prefix が /app なのは歴史的経緯であり、
 * テイク資源の唯一の API 面である (doc/10 / docs/architecture.md §撮影 PWA の運用契約)。
 */
export interface TakeEndpointTarget {
    projectId: number;
    manualId: number;
    cutId: number;
}

/** カット配下のテイクコレクション URL (POST = 登録) */
export function cutTakesUrl({ projectId, manualId, cutId }: TakeEndpointTarget): string {
    return `/app/projects/${projectId}/manuals/${manualId}/cuts/${cutId}/takes`;
}

/** テイク単体の URL (suffix で /adopt /playback 等を足す) */
export function takeUrl(target: TakeEndpointTarget, takeId: number, suffix = ""): string {
    return `${cutTakesUrl(target)}/${takeId}${suffix}`;
}

/** presigned upload-url 発行 URL */
export function takeUploadUrlEndpoint(target: TakeEndpointTarget): string {
    return `${cutTakesUrl(target)}/upload-url`;
}

```

### resources/js/components/features/manual/TakeThumbnail.svelte (全文。既存の類似 component)

```svelte
<script lang="ts">
    import { Film } from "@lucide/svelte";
    import { TAKE_STATUS_LABELS, type SelectableTakeStatus } from "@/types/manual";

    /**
     * テイクのタイル。サムネイル生成は非同期なので、録画直後・生成失敗・過去分のテイクは
     * has_thumbnail=false になる。その場合は**同じ寸法の状態タイル**を描き、枠の大きさを
     * 変えない (生成完了後の再取得で同じ枠が画像へ置き換わる = レイアウトが跳ねない)。
     * 表示差し替え点をこの 1 コンポーネントに閉じている。
     */
    interface Props {
        /** 一覧内の 0 始まり位置 (表示は +1) */
        index: number;
        status: SelectableTakeStatus;
        durationMs: number | null;
        /** 生成済みサムネイルの URL (未生成は null) */
        thumbnailUrl: string | null;
        /** sm = 一覧タイル / lg = プレビュー枠の代替 */
        size?: "sm" | "lg";
        testId?: string;
    }

    let { index, status, durationMs, thumbnailUrl, size = "sm", testId }: Props = $props();

    const boxClass = $derived(size === "sm" ? "size-16" : "aspect-video w-full");
    const seconds = $derived(durationMs === null ? null : Math.round(durationMs / 1000));
</script>

{#if thumbnailUrl !== null}
    <img
        src={thumbnailUrl}
        alt=""
        loading="lazy"
        decoding="async"
        class="{boxClass} shrink-0 rounded-md border border-border object-cover"
        data-testid={testId}
    />
{:else}
    <div
        class="{boxClass} flex shrink-0 flex-col items-center justify-center gap-1 rounded-md border border-border bg-neutral text-text-secondary"
        data-testid={testId}
    >
        <Film class="size-4" aria-hidden="true" />
        <span class="text-caption">テイク {index + 1}</span>
        <span class="text-caption">
            {TAKE_STATUS_LABELS[status]}{#if seconds !== null}・{seconds} 秒{/if}
        </span>
    </div>
{/if}

```

### resources/js/types/capture.ts (CaptureManualSummary 抜粋)

```ts
export interface CaptureManualSummary {
    id: number;
    title: string;
    status: string;
    category_id: number | null;
    category_name: string | null;
    cuts_total: number;
    cuts_adopted: number;
    cuts_with_takes: number;
    updated_at: string | null;
    /** 作成者名。退会/削除で解決不可のときは null (UI は「不明」) */
    creator_name: string | null;
}
```

### tests/Feature/Projects/ManualListQueryCountTest.php (全文。クエリ数テストの既存作法)

```php
<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\RenderJob;
use App\Models\VideoManual;
use Illuminate\Support\Facades\DB;

/*
 * T182: 一覧描画のクエリ数が**行数に比例しない**ことを固定する。
 *
 * 行ごとに ability を評価したり現行世代の render を引いたりすると、
 * per_page=10 の一覧で権限解決と render 取得が 10 倍になる。
 * 計測は「GET 1 回ぶん」に限る (fixture 生成は flushQueryLog で計測外にする)。
 * 初回リクエスト固有の初期化を計測に混ぜないよう、計測前に暖機の GET を 1 回撃つ。
 */

test('一覧のクエリ数は行数に比例しない (1 行のページと 10 行のページで同数)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $singleRowProject = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($singleRowProject)->published(60_000)->create();
    RenderJob::factory()->forManual($manual)->succeeded('renders/1.mp4')->create();

    $tenRowsProject = Project::factory()->forOrganization($organization)->create();
    foreach (range(1, 10) as $i) {
        $row = VideoManual::factory()->forProject($tenRowsProject)->published(60_000)->create();
        RenderJob::factory()->forManual($row)->succeeded("renders/{$i}.mp4")->create();
    }

    /** @return list<string> 実行された SQL */
    $measure = function (Project $project) use ($owner): array {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($owner)->get("/projects/{$project->id}")->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return array_map(fn (array $entry): string => (string) $entry['query'], $log);
    };

    // 暖機 (初回リクエストだけに出る初期化を計測から外す)
    $measure($singleRowProject);

    $singleQueries = $measure($singleRowProject);
    $tenQueries = $measure($tenRowsProject);

    expect($singleQueries)->not->toBeEmpty();
    expect(count($tenQueries))->toBe(
        count($singleQueries),
        '一覧のクエリ数が行数に比例しました (1 行: '.count($singleQueries).' 件 / 10 行: '
        .count($tenQueries)." 件)。\n10 行ページの SQL:\n".implode("\n", $tenQueries)
    );
});

```

### tests/Feature/Capture/CaptureManualBrowsingTest.php (キー集合テスト抜粋 L120-131)

```php
test('index の summary shape は TS CaptureManualSummary と対のキー集合 (PHP↔TS 契約)', function (): void {
    [, $owner, $project] = browsingContext();
    VideoManual::factory()->forProject($project)->create(['status' => 'ready']);

    $summary = $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals")
        ->inertiaPage()['props']['manuals'][0];
    expect(array_keys($summary))->toBe([
        'id', 'title', 'status', 'category_id', 'category_name',
        'cuts_total', 'cuts_adopted', 'cuts_with_takes', 'updated_at', 'creator_name',
    ]);
});

```
