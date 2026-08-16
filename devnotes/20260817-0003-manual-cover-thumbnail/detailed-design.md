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

### 設計上の根拠 (`ofMany` の機序 — 実測済み)

`vendor/laravel/framework/.../Concerns/CanBeOneOfMany.php` の `ofMany()` を実読して確認した:

- 第 1 引数が配列のとき、第 2 引数の `Closure` は**各集約サブクエリへ適用**される
  (`if ($aggregate instanceof Closure) { $closure = $aggregate; }` → ループ内で `$closure($subQuery)`)。
- 列は宣言順に段階適用される。`['sort_order' => 'min', 'id' => 'min']` は
  「候補のうち最小 `sort_order`」→「そのうち最小 `id`」の辞書順になる。
- **最後の列が主キー `id`** なので、外側クエリは主キー一致で join され 1 行に確定する
  (closure が外側クエリへ適用されないことは結果に影響しない)。

さらに**憶測で済ませず生成 SQL を実測した**。手順と全文は
`devnotes/20260817-0003-manual-cover-thumbnail/ofmany-sql-evidence.md`
(検証スクリプト `verify-ofmany-sql.php`。`app/` を 1 行も変えず、匿名の派生モデル上で
relation を宣言し、**DB へクエリを 1 件も投げずに** `toSql()` / `getBindings()` だけを見る)。
確認できたのは 3 点:

1. 内側 `min(sort_order) group by video_manual_id` (候補条件 `exists(takes … thumbnail_path is not null)` 付き)
   → 中間 `min(id)` (同 `sort_order` の中で) → 外側は **`id_aggregate = cuts.id` の主キー一致 join**。
   **辞書順の選択が SQL の構造として実現している**。
2. 候補条件 (closure) は**各集約サブクエリに入っている** (外側には入らないが 1. により結果は同じ)。
3. `addEagerConstraints([...])` を与えると `video_manual_id in (1, 2, 3)` になる =
   **eager load は 1 クエリ**で、行数ぶんのクエリは出ない。

**この実測が保証しないもの**: 実データでの選択結果 (見たのは SQL 文字列の構造だけ) / 方言差。
よって選択規則とタイブレークは**実 DB の Feature テストで固定する** (施策 8 #1 / #2 を fail-first の
必須条件とし、`toSql()` の比較ではなく**実レコードの選択結果**で確認する)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`HasOne`) + generics 注釈 `@return HasOne<Cut, $this>`
- [x] closure の引数に `@param Builder<Cut>` / `@param Builder<Take>` を明示
- [x] `@property-read Cut|null $coverCut` を追加 (property fetch の型が確定する)
- [x] null 安全: relation は null を返しうるため消費側で `?->` / 早期 return
- [x] DTO を返している (relation なので該当なし。props 化は施策 3)

### テスト計画

- [ ] **fail-first (必須)**: 先頭カットが未採用でも、次に来る「採用テイク + サムネイル生成済み」カットが選ばれる
- [ ] **fail-first (必須)**: `sort_order` が同値のときは `id` 昇順で選ばれる。
      フィクスチャは「**最小 `id` のカットが最小 `sort_order` ではない**」配置にし、
      単一列 `['id' => 'min']` の実装なら必ず落ちる判別力を持たせる
- [ ] 新規: 候補が 0 件のとき relation は null
- [ ] 新規: クエリ数が行数・候補件数に依存しない
- [ ] **確認は実レコードの選択結果で行う** (`toSql()` の文字列比較で代用しない)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- `ofMany` + `whereHas` の組み合わせが意図した SQL を作らない可能性。
  → 生成 SQL は**実測済み** (上記 + `ofmany-sql-evidence.md`)。残るのは実データ挙動と方言差で、
  そこは実 DB の Feature テスト (fail-first) が受ける。
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
- **`fromManual()` の呼び出し元 (引数追加は破壊的変更なので全数確認した)**:
  リポジトリ全体で `CaptureManualSummaryData` を参照するのは
  **`app/Http/Controllers/Capture/CaptureManualController.php` の 1 ファイルだけ** (import 1 + 呼び出し 1)。
  テストからの直接呼び出しも無い。よって波及は施策 4 に閉じる。
  **互換用のデフォルト引数は付けない** (後方互換の並走を残さない = 思考原則 3)。

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
        src={url}
        alt=""
        loading="lazy"
        decoding="async"
        class="size-16 shrink-0 rounded-md border border-border object-cover"
        data-testid={testId}
        data-state="image"
        onerror={() => (failedSrc = src)}
    />
{:else}
    <div
        class="flex size-16 shrink-0 items-center justify-center rounded-md border border-border bg-neutral text-text-secondary"
        data-testid={testId}
        data-state="placeholder"
        aria-hidden="true"
    >
        <Film class="size-5" />
    </div>
{/if}
```

### 実装注記 (2 点)

- **`data-state` で分岐を明示する**。枠の位置と `data-testid` は 2 分岐で同じ (レイアウトを跳ねさせない
  ため) なので、テストが「画像か / プレースホルダか」を要素の種類から推測しなくて済むようにする。
  判定は `data-state` で行う。
- **失敗の記憶は「失敗した URL そのもの」で持つ**。`failedSrc` に真偽値ではなく URL を入れると、
  `src` が別の値に変わった瞬間に `url` が再び非 null になる = 自動的に再挑戦になる
  (`$effect` で明示的に消す必要がない)。Svelte 5 runes で `$props()` の分割代入が
  再代入に追随することは既存 `TakeThumbnail.svelte` (`$derived(size === "sm" …)`) が前例だが、
  **設計で断言せず Vitest で固定する** (施策 9 の「`src` を差し替えると再び `<img>`」は必須項目)。
  もし追随しないことが実測で分かったら `$effect` で `src` 変更時に `failedSrc` を解除する形へ切り替える。

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

- [ ] 新規 Vitest: `src` 非 null で `data-state="image"` になり `src` / `loading="lazy"` が付く
- [ ] 新規 Vitest: `src` が null で `data-state="placeholder"` になる
- [ ] 新規 Vitest: `<img>` の error でプレースホルダへ落ちる
- [ ] 新規 Vitest (**必須**): `src` が別の値に変わると再び `data-state="image"` に戻る
      (失敗の記憶が新しい URL に及ばない = runes の props 追随の固定)

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
                            <div class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3">
                                <ManualCoverThumbnail
                                    src={coverUrl(manual)}
                                    testId={`capture-cover-${manual.id}`}
                                />
                                <div class="min-w-0">
                                    <p class="truncate text-body font-medium">{manual.title}</p>
                                    …既存の 2 行 (カテゴリ・進捗 / 作成者・更新日) はそのまま…
                                </div>
                                <div class="shrink-0">
                                    …既存のバッジはそのまま…
                                </div>
                            </div>
                        </Card>
                    </a>
```

- **1 段 flex + `justify-between` から 3 列 grid へ変える**。狭幅では 3 要素 (サムネイル / 本文 /
  バッジ) の伸縮順が flex では読み切れず、バッジが潰れるかタイトルが truncate しないかのどちらかに
  倒れる。`grid-cols-[auto_minmax(0,1fr)_auto]` なら
  「**サムネイルは固有幅・本文だけが縮んで truncate・バッジは潰れない**」が構造で決まる
  (`minmax(0,1fr)` が本文列に truncate 可能な最小幅 0 を与える)。
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
| 1 | **(fail-first)** 代表は表示順で最初の「採用テイク + サムネイル生成済み」カットになる | `sort_order` 0 は未採用、1 は採用済み+生成済み → cover は 1 の cut / その take。**実レコードで確認** |
| 2 | **(fail-first)** `sort_order` が同値なら `id` 昇順で決まる | 同 `sort_order` の 2 カットで小さい `id` が選ばれる。フィクスチャは「**最小 `id` のカットが最小 `sort_order` ではない**」配置にし、単一列 `['id' => 'min']` 実装なら必ず落ちるようにする |
| 3 | 採用テイクが無ければ cover は null | 撮影前の manual |
| 4 | 採用テイクのサムネイル未生成なら cover は null | `withThumbnail()` を付けない take を採用 |
| 5 | 生成済みだが ready でない採用テイクは cover にしない (安全側) | `status=processing` + `withThumbnail()` → null (**次のカットも探さない**ことも固定) |
| 6 | **契約 (i) 配信可能性 (主契約)**: cover 非 null なら、その id で組んだ URL が 302 を返す | props から `cut_id` / `take_id` を取り、**実際に thumbnail route を GET** して `assertRedirect(署名 URL)` と **`Cache-Control: no-store, private`** を確認する (`TakeObjectStorage` の mock は署名 URL を決定的にするためだけに使う) |
| 7 | **契約 (ii) 完全性の正例**: 3 条件がすべて成立すると cover は非 null | 候補が複数あるケースを含む正例専用。負例 (1 つずつ落とす) は #3・#4・#5・#8 が担当 |
| 8 | **権限 (主契約)**: 組織メンバー (非 project メンバー) は全行 cover が null で、同 URL は 403 | 一覧は 200 のまま (画面ごと 403 にしない)。cover の id は権限を持つ利用者の props から取る |
| 9 | **契約 (iii) 認可委譲 (補助)**: `Gate::allows('preview', $take)` と `Gate::allows('capture', $project)` が同値 | owner / project member / 組織メンバー / 他組織ユーザーの 4 者で一致。**relation 未ロードの再取得インスタンスと eager load 済みインスタンスの両方**で同じ結果になることを確認する (policy が relation を辿るため、片方だけだと偽陰性・偽陽性になる)。**主契約は #6 / #8 の実リクエスト**で、本ケースは drift の早期検出という位置づけ |
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
- [ ] `cover` 非 null のとき `data-state="image"` になり `src` が
      `/app/projects/1/manuals/1/cuts/7/takes/9/thumbnail` になる (URL 規則の固定)
- [ ] `cover` が null のとき `data-state="placeholder"` になる

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
6. **AGENTS.md の検証コマンド 10 本を全 green** で完了する
   (`VERIFICATION_COMMANDS` の集合。package 側を変更しなくても省略しない):
   `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
   `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
7. 一時検証スクリプト `devnotes/20260817-0003-manual-cover-thumbnail/verify-ofmany-sql.php` は
   設計時の記録として devnotes に残す (`scripts/` へ昇格させない)。

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
