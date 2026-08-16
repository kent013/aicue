# Round 2: Round 1 指摘への対応

Round 1 の指摘に対する対応マトリクスと、修正後の詳細設計書全文を送ります。
反論している箇所 (Critical の一部 / Capture の category 正規化) については、
根拠が成立しているかを特に見てください。残る [Critical] [Warning] があれば指摘し、
無ければ全体判定 APPROVED を出してください。

## 対応マトリクス

# 対応マトリクス: design-review Round 1

全体判定 CHANGES_REQUESTED。Critical 1 件 / Warning 9 件 / Suggestion 5 件。

## [Critical] 旧 `?status=` が pagination の query string に残る経路が未処理

- 判断: **一部反論 + 対応する**
- 根拠 (反論部分): `ProjectController::manualRows()` は paginator の `links` / `url()` を
  **props へ 1 つも出していない**。返しているのは自前で組んだ `data` と
  `meta` (`current_page` / `last_page` / `per_page` / `total`) の 2 キーだけであり、
  ページ送り UI (`Projects/Show.svelte` の `changeManualPage`) は
  **クライアント側で `manualQuery(pageNumber)` を組み直して `router.get`** する。
  したがって `withQueryString()` が拾った旧キーがリンクとして外に出る経路は現状**存在しない**
  (旧キーに限らず `?foo=bar` のような未知キーも同じで、これは本変更以前からの性質である)。
- 根拠 (対応部分): とはいえ「allowlist を通った値だけを外へ出す」という本 VO の設計意図に対して
  `withQueryString()` (生クエリをそのまま拾う) は**意図の緩い側**である。1 行で締められるので締める。
- 対応内容:
  - 施策 C に `->withQueryString()` → `->appends($listQuery->toQueryParams())` の置換を追加した。
    `AbstractPaginator::addQuery()` は `pageName` (`page`) を除外するため、
    `toQueryParams()` が持つ `page` と paginator 自身のページ番号は衝突しない。
  - Feature テストに「`manuals` props のキーは `data` / `meta` だけで `links` を持たない」
    「`manualFilters` に `status` キーが存在しない (`missing`)」の 2 本を追加した。

## [Warning] B: Svelte 側 state の型が `string` で緩い

- 判断: **対応する**
- 根拠: 妥当。`ManualFilters.progress` を union にする以上、state 側も union で受けないと
  「型で防ぐ」効果が select の値で切れる。
- 対応内容: `let filterProgress = $state<ManualProgress | "">(manualFilters.progress ?? "")` へ変更し、
  `manualQuery()` の分岐も `!== ""` のまま union を保つ形に書き直した (施策 F)。

## [Warning] E: `VIDEO_MANUAL_STATUS_LABELS` の用途説明が狭すぎる (5 値の正当な用途を否定してしまう)

- 判断: **対応する**
- 根拠: 指摘のとおり。同ファイルの `CAPTURE_NAVIGABLE_BY_STATUS` / `SCENARIO_ESTABLISHED_BY_STATUS` /
  `SCENARIO_ANALYZABLE_BY_STATUS` は 5 値を使う正当な判定であり、
  「5 値は詳細画面とダッシュボードだけ」と書くとこれらと矛盾する。
- 対応内容: docblock を**ラベル / トーン (表示語彙) の使用面**の話に限定し、
  「一覧の行バッジと絞り込みでは使わない」と狭めた。5 値の型そのものの用途は制限しない。

## [Warning] F: 旧 testId の参照ゼロが設計書の主張に依存している

- 判断: **対応する**
- 根拠: 妥当。設計書の「実読で確認済み」は再現手順が無い。
- 対応内容: 実測した grep の結果 (対象と件数) を施策 F に記載し、
  テスト計画へ「実装時に `rg 'manual-status-|manual-filter-status'` が
  **実装対象ファイル以外で 0 件**であることを確認する」を明記した。
  (実測: 参照は `ManualListRow.svelte` / `Projects/Show.svelte` と
  Vitest 2 ファイル (`ProjectsShow.test.ts` / `ManualListRow.test.ts`) のみ。
  Browser lane・Feature テスト・bug-hunt 目録に参照は無い。
  `Manuals/Show.svelte` の `manual-status` (id 無し) は詳細画面の 5 値バッジで**別物**なので変えない)

## [Warning] G: Capture 側の `category` 入力正規化が PC 側 allowlist と不一致

- 判断: **見送る (据え置きと明記する)**
- 根拠: `(int) 'abc' = 0` は「該当なし」へ倒れる (fail-closed 方向) 挙動で、
  権限やテナント境界を跨がない。PC 側は破棄して「全件」へ倒れるので方向が逆だが、
  **どちらも安全側**であり、本タスクの対象は**表示語彙**である。
  ここで Capture 側に VO を新設するのは別タスク相当のスコープ拡大 (思考原則 2)。
- 対応内容: 施策 G に「本設計では触らない (既存仕様として据え置き)」と理由付きで明記した。

## [Warning] G: `captureProgressOf()` の `cuts_total=0 && cuts_with_takes>0` の扱いが仕様として固定される

- 判断: **対応する**
- 根拠: 関数化すると判定順序が仕様になる、という指摘は正しい。
- 対応内容: 関数に「takes は cut に属するため `cuts_total=0` かつ `cuts_with_takes>0` は
  構造上生じないが、生じても**撮影の分母が無い**ので『未撮影』へ倒す」というコメントを付け、
  Vitest の境界テストに同ケースを追加した。

## [Warning] I: 純粋 enum テストを Feature に置くのは配置が不適切

- 判断: **対応する**
- 根拠: 妥当。本リポジトリには `tests/Unit/Manual/` が既にあり (`CutSequencerTest` 等の
  DB を使わない純粋テストの置き場)、そこが正しい所在である。
- 対応内容: `tests/Feature/Manual/ManualProgressMappingTest.php` →
  **`tests/Unit/Manual/ManualProgressMappingTest.php`** へ移した。
  Inertia payload / 絞り込み挙動は Feature (`ProjectShowManualsTest`) に残す。

## [Warning] I: `has('manuals.data', 5)` は脆く、対象の同定が曖昧

- 判断: **対応する**
- 根拠: 妥当。件数だけの assertion は fixture の増減で意味が変わる。
- 対応内容: fixture の 5 本に status ごとの固有 title を付け、
  `in_progress` は**3 件の title を集合として**固定する形へ書き換えた。

## [Warning] 追加: 検証コマンドが設計の完了条件に書かれていない

- 判断: **対応する**
- 根拠: 妥当。PHP / TS / Svelte / payload を同時に変えるため、完了条件の明示は必要。
- 対応内容: 「完了条件 (検証コマンド)」節を新設し、AGENTS.md の検証コマンド一覧のうち
  本変更で必ず走らせるものを列挙した。

## [Suggestion] A / C / D / H / J への肯定的評価

- 判断: 対応不要 (設計維持)。`statuses()` を導出のままにする判断も維持する。


## 修正後の詳細設計書 (全文)

# 詳細設計: manual-status-display-vocabulary (動画マニュアル状態の表示語彙の写像)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → `PromptDefense` → `GuardedPrompt` の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI**(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- アーリーリターン推奨 / `declare(strict_types=1)` + 日本語コメント
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- フロントは Svelte 5 runes + DS token のみ。component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import。アイコンは `@lucide/svelte` のみ
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260816-2359-manual-status-display-vocabulary/conceptual-design.md` (Round 1 で APPROVED)
- 対応マトリクス: `codex-history/conceptual-review-decisions-round-1.md`

### 概念設計で確定した判断 (詳細設計はこれを前提に書く)

1. doc の 3 値は **doc/04 の語 (作成済 / 作成中 / 未着手)** を採用し、doc/02 §2.4 を合わせる。
2. 写像の正本は **`App\Enums\Manual\ManualProgress` ただ 1 か所** (網羅 match)。逆写像は導出する。
3. **一覧 (絞り込み + 行バッジ) は 3 値**、**詳細画面とダッシュボードは 5 値のまま**。
4. URL クエリは `?status=` → `?progress=` へ**置き換え、旧値の互換は残さない**。
5. 撮影 PWA の進捗語彙は**別物として維持**し、コードとテストで別物と宣言する。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | 写像の正本 `ManualProgress` enum 新設 | `app/Enums/Manual/ManualProgress.php` (新規) | 高 |
| B | 一覧クエリ VO の `status` → `progress` 置換 | `app/DataTransferObjects/Manual/ManualListQuery.php` | 高 |
| C | 一覧 WHERE を 3 値写像経由へ | `app/Http/Controllers/Projects/ProjectController.php` | 高 |
| D | 一覧行 DTO の `status` → `progress` 置換 | `app/DataTransferObjects/Manual/ManualListItemData.php` | 高 |
| E | TS 型・ラベル・トーンの追加と役割の明記 | `resources/js/types/manual.ts` | 高 |
| F | 一覧 UI (絞り込み select + 行バッジ) の 3 値化 | `resources/js/pages/Projects/Show.svelte` / `resources/js/components/features/manual/ManualListRow.svelte` | 高 |
| G | 撮影 PWA 語彙の明示化と dead payload 撤去 | `resources/js/types/capture.ts` / `resources/js/pages/Capture/Index.svelte` / `app/DataTransferObjects/Capture/CaptureManualSummaryData.php` / `app/Http/Controllers/Capture/CaptureManualController.php` | 中 |
| H | PHP enum ⇔ TS union 値集合同期テストの拡張 | `tests/Architecture/ManualEnumTsSyncInvariantTest.php` | 高 |
| I | 写像テスト新設 + 既存 Feature/Vitest の更新 | `tests/Unit/Manual/ManualProgressMappingTest.php` (新規) 他 | 高 |
| J | doc の語彙統一と写像正本の明記 | `doc/02_システム全体像.md` / `doc/04_PCサイト機能仕様.md` | 中 |

---

## A. 写像の正本 `ManualProgress` enum 新設

### 変更箇所
- ファイル: `app/Enums/Manual/ManualProgress.php` (新規)

### 波及変更
- TypeScript型定義: `resources/js/types/manual.ts` に同名 union を追加 (施策 E)
- API Resource/DTO: `ManualListQuery` (B) / `ManualListItemData` (D)
- テストファイル: `tests/Unit/Manual/ManualProgressMappingTest.php` (新規) /
  `tests/Architecture/ManualEnumTsSyncInvariantTest.php` (H)

### 現行コード
```php
// 存在しない (写像規則はリポジトリ内に 0 か所。5 値がそのまま UI に出ている)
```

参考: 写像元の enum は変更しない。
```php
// app/Enums/Manual/VideoManualStatus.php (現行・変更なし)
enum VideoManualStatus: string
{
    case Draft = 'draft';
    case Analyzing = 'analyzing';
    case Ready = 'ready';
    case Rendering = 'rendering';
    case Published = 'published';
}
```

### 変更後コード
```php
<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * 動画マニュアル一覧の状態語彙 (doc/04 §動画一覧ページ の 3 値: 作成済 / 作成中 / 未着手)。
 *
 * **制作状態 (VideoManualStatus, 5 値) → 一覧の状態 (3 値) の写像規則は本 enum の
 * forStatus() ただ 1 か所にある**。逆写像 (statuses()) も同じ match から導出するため、
 * 写像表が 2 か所に分かれることが構造的に起きない。
 *
 * 2 つの enum は**別の問いに答える**:
 * - VideoManualStatus = いま何をしているか (制作パイプラインの進行状態。詳細画面 /
 *   ダッシュボードが実況に使う。数十秒で遷移する短命な値を含む)
 * - ManualProgress = 仕上がっているか (一覧の絞り込みと行バッジ。ポーリングしない面で使う)
 *
 * 撮影 PWA の撮影進捗 (types/capture.ts の CaptureProgress) とは**別の量**である
 * (あちらは 1 本のマニュアルのカット採用状況の導出であり、本 enum とは母集団も更新契機も違う)。
 * 語が似ているという理由で統合しないこと。
 */
enum ManualProgress: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    /**
     * 制作状態 → 一覧の状態の写像 (**唯一の写像規則**)。
     *
     * - Draft: シナリオ (cuts) が未確定。解析が失敗しても cuts が無ければ Draft へ戻る
     *   (AnalysisJobService::failJob) ため「未着手」と一致する
     * - Analyzing / Ready / Rendering: シナリオはあるが完成動画が無い = 作成中
     * - Published: 現行世代の完成動画がある。シナリオを保存すると Ready へ戻る
     *   (ScenarioService) ので「作成済」の意味と一致する
     *
     * default を持たない網羅 match なので、VideoManualStatus に case を足すと
     * PHPStan level 10 が未処理の case として落とす (無音の drift を作らない)。
     */
    public static function forStatus(VideoManualStatus $status): self
    {
        return match ($status) {
            VideoManualStatus::Draft => self::NotStarted,
            VideoManualStatus::Analyzing,
            VideoManualStatus::Ready,
            VideoManualStatus::Rendering => self::InProgress,
            VideoManualStatus::Published => self::Completed,
        };
    }

    /**
     * この値に写る制作状態の集合 (forStatus からの導出。**逆写像表を別に持たない**)。
     *
     * @return list<VideoManualStatus>
     */
    public function statuses(): array
    {
        return array_values(array_filter(
            VideoManualStatus::cases(),
            fn (VideoManualStatus $status): bool => self::forStatus($status) === $this,
        ));
    }

    /**
     * 一覧の WHERE へ渡す DB 値。**型 (enum) と SQL (文字列) の境界をここで閉じる**
     * (binding 側の暗黙変換に依存しない)。
     *
     * @return list<string>
     */
    public function statusValues(): array
    {
        return array_map(
            static fn (VideoManualStatus $status): string => $status->value,
            $this->statuses(),
        );
    }
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている (`self` / `list<VideoManualStatus>` / `list<string>`)
- [x] null 安全 (null を扱わない。`forStatus` は非 null の enum を受ける)
- [x] DTO を返している (enum。配列返却は `list<...>` を PHPDoc で明示)
- [x] Generics の型パラメータが正しい (`array_values(array_filter(...))` で `list<>` に畳む)
- [x] `match` に default を置かない (網羅性を level 10 に検査させる = widen しない)

### テスト計画
- [ ] 新規 `tests/Unit/Manual/ManualProgressMappingTest.php`:
  - 5 値それぞれが期待する 3 値へ写る (写像表をテスト側に明示して固定)
  - `statuses()` の 3 集合の和が `VideoManualStatus::cases()` と一致する (漏れなし)
  - 3 集合が互いに排他である (重複なし)
  - `statusValues()` が `statuses()` の value 列と一致する
  - `ManualProgress::cases()` が 3 件である (doc/04 の 3 値と件数一致)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 将来 `VideoManualStatus` に case が増えたとき、写像先の判断が必要になる (= 網羅 match が落ちて気づく)。
  これは意図した設計であり、静かに `in_progress` へ流れる default を置かない。

---

## B. 一覧クエリ VO の `status` → `progress` 置換

### 変更箇所
- ファイル: `app/DataTransferObjects/Manual/ManualListQuery.php` (L38-45 / L76-77 / L95-121 / L129-152 とクラス docblock)

### 波及変更
- TypeScript型定義: `ManualFilters.status` → `progress` (施策 E)
- API Resource/DTO: `toProps()` のキー (`status` → `progress`) は Inertia props 契約の破壊的変更
- 呼び出し側: `ProjectController::show/manualRows` (C) / `VideoManualController::destroy` (**コード変更不要** —
  `ManualListQuery::fromRequest()` + `toQueryParams()` を通しているだけなので、VO の置換だけで着地先も追随する。
  これが「唯一の解析点」設計の効き目である)
- テストファイル: `tests/Feature/Projects/ProjectShowManualsTest.php` /
  `tests/Feature/Projects/ManualRowActionsTest.php`

### 現行コード
```php
/**
 * 値の約束:
 * - `status`: VideoManualStatus の値のみ。それ以外は null
 */
public function __construct(
    public ?string $category,
    public ?string $status,
    public ?string $keyword,
    public ?ManualSortOption $sort,
    public bool $mine,
    public int $page,
) {}

// fromRequest() 内
$status = $request->query('status');
$status = is_string($status) && VideoManualStatus::tryFrom($status) !== null ? $status : null;

// toProps()
'status' => $this->status,

// toQueryParams()
if ($this->status !== null) {
    $params['status'] = $this->status;
}
```

### 変更後コード
```php
use App\Enums\Manual\ManualProgress;   // VideoManualStatus の use は本ファイルから消える

/**
 * 値の約束:
 * - `progress`: ManualProgress の値のみ (not_started / in_progress / completed)。それ以外は null。
 *   **旧 `?status=` (制作状態 5 値) は受け付けない**。値域が変わった時点で意味を保てないため、
 *   互換の受理経路を残さない (思考原則 3)。旧 URL は未知キーとして無視され「すべて」になる
 *   (allowlist 外は絞り込み無し = より広く当たる方向へ倒す、という本 VO の既定方針と一致)
 */
public function __construct(
    public ?string $category,
    public ?ManualProgress $progress,
    public ?string $keyword,
    public ?ManualSortOption $sort,
    public bool $mine,
    public int $page,
) {}

// fromRequest() 内 (allowlist 外は null = 既定「すべて」。他の項目の解析は一切変更しない)
$progressRaw = $request->query('progress');
$progress = is_string($progressRaw) ? ManualProgress::tryFrom($progressRaw) : null;

// return new self(... progress: $progress, ...)

/**
 * @return array{category: string|null, progress: string|null, q: string|null, sort: string|null, mine: bool}
 */
public function toProps(): array
{
    return [
        'category' => $this->category,
        'progress' => $this->progress?->value, // string|null (TS の ManualFilters.progress と一致)
        // ...以下は現行のまま
    ];
}

// toQueryParams()
if ($this->progress !== null) {
    $params['progress'] = $this->progress->value;
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている (`toProps()` の array shape PHPDoc を `progress` へ更新)
- [x] null 安全 (`?->value` で null 伝播。`tryFrom` の戻りは `?ManualProgress`)
- [x] DTO を返している (readonly VO)
- [x] Generics の型パラメータが正しい (`array<string, string|int>` は現行のまま)

### テスト計画
- [ ] 既存 `ProjectShowManualsTest` の「status フィルタで絞り込める (不正値は無視)」を
      **progress フィルタ**の 3 ケース + 不正値 + **旧 `?status=published` が無視される**へ更新
- [ ] 既存 `ManualRowActionsTest` の削除着地先クエリ (`category=&status=published&q=`) を
      `progress=completed` へ更新し、着地先 URL に載ることを固定
- [ ] `manualFilters.status` を参照する assertion を `manualFilters.progress` へ更新
- [ ] MAX_KEYWORD_LENGTH / page 丸め / sort allowlist / category 正規化の既存テストが**無変更で緑**であること
      (T182 / T189 が固定した解析を壊していないことの確認)

### リスク
- 旧ブックマーク `?status=ready` の絞り込みが失われる (= すべて表示)。
  **意図した破壊的変更**であり、行き先のない詰みは作らない (一覧は必ず開く)。
- `manualFilters` prop のキー変更は Inertia の部分更新 (`only: ["manuals","manualFilters"]`) と整合する
  (キー名が変わるだけで prop 名は不変)。

---

## C. 一覧 WHERE を 3 値写像経由へ

### 変更箇所
- ファイル: `app/Http/Controllers/Projects/ProjectController.php` (L151-158 の shape PHPDoc / L181-183 / L189-203 の paginate)

### 波及変更
- TypeScript型定義: なし (行 shape の変更は施策 D/E で扱う)
- API Resource/DTO: `manualRows()` の戻り shape PHPDoc の `status: string` → `progress: string`
- テストファイル: `ProjectShowManualsTest`

### 現行コード
```php
if ($listQuery->status !== null) {
    $baseQuery->where('status', $listQuery->status);
}
```

### 変更後コード
```php
if ($listQuery->progress !== null) {
    // 3 値 → 制作状態の集合は ManualProgress が唯一の正本 (ここに写像表を書かない)
    $baseQuery->whereIn('status', $listQuery->progress->statusValues());
}
```

shape PHPDoc:
```php
 *   data: list<array{id: int, title: string, progress: string,
 *     category: array{id: int, name: string}|null,
```

#### paginator の query を allowlist 済みの値に寄せる (詳細レビュー Round 1 [Critical] 対応)

```php
// 現行 (2 箇所)
$paginated = (clone $baseQuery)
    ->paginate(perPage: ManualListQuery::PER_PAGE, page: $listQuery->page)
    ->withQueryString();

// 変更後 (2 箇所とも)
$paginated = (clone $baseQuery)
    ->paginate(perPage: ManualListQuery::PER_PAGE, page: $listQuery->page)
    // 生クエリをそのまま拾う withQueryString ではなく、**allowlist を通った値だけ**を載せる
    // (未知キー・旧 `?status=` を paginator の query に持ち込まない)。
    // `page` は AbstractPaginator::addQuery() が pageName として除外するため衝突しない
    ->appends($listQuery->toQueryParams());
```

**事実確認 (誇張しない)**: 現状 `manualRows()` は paginator の `links` / `url()` を
**props へ 1 つも出していない** (返すのは自前で組んだ `data` と `meta` の 2 キーだけで、
ページ送りはクライアントが `manualQuery(pageNumber)` を組み直して `router.get` する)。
よって旧キーがリンクとして外へ出る経路は**現状は存在しない**。
この変更は「allowlist を通った値だけを外へ出す」という VO の意図を paginator 側でも
構造的に閉じるためのものであり、**今ある漏洩を塞ぐものではない**。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている (array shape PHPDoc を更新)
- [x] null 安全 (`!== null` の早期分岐)
- [x] DTO を返している (`ManualListItemData::toArray()`)
- [x] Generics の型パラメータが正しい (`list<string>` を `whereIn` に渡す)

### テスト計画
- [ ] `?progress=in_progress` で analyzing / ready / rendering の 3 行が返り、draft / published が返らない
- [ ] `?progress=not_started` で draft のみ、`?progress=completed` で published のみ
- [ ] `mine` / `category` / `q` / `sort` との併用テスト (既存の結合テスト) を `progress` へ更新して緑
- [ ] `manuals` props のキーが `data` / `meta` の 2 つだけであること (`links` を持たない =
      paginator の query が外に出ないことの構造的確認)

### リスク
- `whereIn` になることで完全一致より広い集合を引く (意図どおり)。
  index 効率は 5 値の低カーディナリティ列であり、現行の `where` と実務上差は無い。

---

## D. 一覧行 DTO の `status` → `progress` 置換

### 変更箇所
- ファイル: `app/DataTransferObjects/Manual/ManualListItemData.php` (L31-42 / L44-75 / L77-98)

### 波及変更
- TypeScript型定義: `ManualListItem.status` → `progress: ManualProgress` (施策 E)
- API Resource/DTO: 行 payload のキーが変わる (Inertia props 契約の破壊的変更)
- テストファイル: `ProjectShowManualsTest` (`manuals.data.0.status` → `progress`) /
  `tests/js/pages/ProjectsShow.test.ts` / `tests/js/components/features/manual/ManualListRow.test.ts`

### 現行コード
```php
public function __construct(
    public int $id,
    public string $title,
    public VideoManualStatus $status,
    // ...
) {}

public static function fromManual(VideoManual $manual, ManualRowAbilities $abilities): self
{
    // ...
    $isPublished = $manual->status === VideoManualStatus::Published;
    // ...
    return new self(
        id: $manual->id,
        title: $manual->title,
        status: $manual->status,
        // ...
    );
}

// toArray()
'status' => $this->status->value,
```

### 変更後コード
```php
public function __construct(
    public int $id,
    public string $title,
    /** 一覧の状態 (3 値)。**制作状態 5 値は一覧行に載せない** (行バッジ以外の用途が無く、
     *  絞り込みと語彙が食い違うため。実況は詳細画面 / ダッシュボードの責務) */
    public ManualProgress $progress,
    // ...以下は現行のまま
) {}

public static function fromManual(VideoManual $manual, ManualRowAbilities $abilities): self
{
    // $isPublished / $durationMs / $currentFinishedRenderJobId の導出は**現行のまま**
    // (published 判定は「完成動画を受け取れるか」の判断であって表示語彙ではない)
    return new self(
        id: $manual->id,
        title: $manual->title,
        progress: ManualProgress::forStatus($manual->status),
        // ...
    );
}

// toArray()
'progress' => $this->progress->value,
```

`toArray()` の shape PHPDoc も `status: string` → `progress: string` へ更新する。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている (array shape PHPDoc)
- [x] null 安全 (`$manual->status` は cast 済みで非 null)
- [x] DTO を返している
- [x] Generics の型パラメータが正しい

### リスク
- 行 payload から `status` が消えるため、**将来 5 値が必要になった行操作を足すときは
  props の再設計が要る**。現時点で `status` を見ている行の分岐は 0 件 (実読で確認済み) なので、
  「あったら便利」で残さない (思考原則 2 / 3)。

---

## E. TS 型・ラベル・トーンの追加と役割の明記

### 変更箇所
- ファイル: `resources/js/types/manual.ts` (L1-31 のヘッダとラベル群 / L103-148 の型)

### 波及変更
- TypeScript型定義: 本施策そのもの
- API Resource/DTO: PHP 側 (A/B/D) と値集合を一致させる
- テストファイル: `tests/Architecture/ManualEnumTsSyncInvariantTest.php` (H) / Vitest 各種 (I)

### 現行コード
```ts
export type VideoManualStatus = "draft" | "analyzing" | "ready" | "rendering" | "published";

/** VideoManualStatus の表示ラベル (UI 共通) */
export const VIDEO_MANUAL_STATUS_LABELS: Record<VideoManualStatus, string> = {
    draft: "下書き",
    analyzing: "解析中",
    ready: "準備完了",
    rendering: "書き出し中",
    published: "公開済み",
};

export const STATUS_TONES = { /* draft..published の 5 キー */ } as const satisfies Record<VideoManualStatus, BadgeTone>;

export interface ManualListItem {
    id: number;
    title: string;
    status: VideoManualStatus;
    // ...
}

export interface ManualFilters {
    category: string | null;
    status: string | null;
    // ...
}
```

### 変更後コード
```ts
/**
 * VideoManualStatus の**表示ラベル**。
 * **一覧 (Projects/Show の行バッジと絞り込み) では使わない** — 一覧はポーリングせず、
 * 短命な遷移状態を出すと再読込まで嘘になるため。一覧は MANUAL_PROGRESS_LABELS を使う。
 * 実況する面 (詳細画面 Manuals/Show / ダッシュボード) では引き続きこれを使う。
 *
 * 注: 制限しているのは**表示語彙 (このラベル表とトーン表) の使用面**だけである。
 * 5 値の型そのものを使う判定 (CAPTURE_NAVIGABLE_BY_STATUS / SCENARIO_ESTABLISHED_BY_STATUS /
 * SCENARIO_ANALYZABLE_BY_STATUS など) は正当な用途であり、本変更の対象外。
 */
export const VIDEO_MANUAL_STATUS_LABELS: Record<VideoManualStatus, string> = { /* 現行のまま */ };

/** 状態バッジの tone (実況する面で使う。一覧は MANUAL_PROGRESS_TONES) */
export const STATUS_TONES = { /* 現行のまま */ } as const satisfies Record<VideoManualStatus, BadgeTone>;

/**
 * PHP App\Enums\Manual\ManualProgress と値集合を一致させる (doc/04 の 3 値)。
 * 5 値 → 3 値の**写像規則は PHP 側 ManualProgress::forStatus() だけが持つ**。
 * TS 側は写像を書かず、サーバが決めた値を表示するだけである (2 か所に写像を持たない)。
 */
export type ManualProgress = "not_started" | "in_progress" | "completed";

/** 一覧の状態ラベル (doc/04 の語)。satisfies でキー漏れをコンパイル時検出する */
export const MANUAL_PROGRESS_LABELS = {
    not_started: "未着手",
    in_progress: "作成中",
    completed: "作成済",
} as const satisfies Record<ManualProgress, string>;

/** 一覧の状態バッジの tone (結果表示の意味色) */
export const MANUAL_PROGRESS_TONES = {
    not_started: "neutral",
    in_progress: "tertiary",
    completed: "success",
} as const satisfies Record<ManualProgress, BadgeTone>;

export interface ManualListItem {
    id: number;
    title: string;
    /** 一覧の状態 (3 値)。サーバが写像済みの値であり、UI 側で再写像しない */
    progress: ManualProgress;
    // ...以下は現行のまま
}

export interface ManualFilters {
    category: string | null;
    /** 状態の絞り込み (3 値)。null = すべて。旧 `status` (5 値) は廃止 */
    progress: ManualProgress | null;
    // ...以下は現行のまま
}
```

### PHPStan適合チェック
- 対象外 (TypeScript)。代わりに `pnpm typecheck` と `satisfies` によるキー漏れ検出、
  および施策 H の値集合同期テストが型の一致を担保する。

### テスト計画
- [ ] `pnpm typecheck` (satisfies のキー漏れ / ManualListItem 参照側の型不整合を検出)
- [ ] 施策 H の Architecture テストで PHP enum と値集合一致を固定

### リスク
- `VIDEO_MANUAL_STATUS_LABELS` と `MANUAL_PROGRESS_LABELS` の 2 表が併存する。
  これは**別の問いに答える 2 語彙**であり写像の二重化ではない (写像は PHP 側に 1 つ)。
  役割を docblock に明記し、一覧で 5 値ラベルを使わないことは Vitest で固定する。

---

## F. 一覧 UI (絞り込み select + 行バッジ) の 3 値化

### 変更箇所
- ファイル: `resources/js/pages/Projects/Show.svelte` (L31 import / L91 state / L108 query / L422-437 select)
- ファイル: `resources/js/components/features/manual/ManualListRow.svelte` (L8 import / L63-65 badge)

### 波及変更
- TypeScript型定義: 施策 E
- API Resource/DTO: 施策 B/D
- テストファイル: `tests/js/pages/ProjectsShow.test.ts` / `tests/js/components/features/manual/ManualListRow.test.ts`

### 現行コード
```svelte
<!-- Projects/Show.svelte -->
import { VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";

let filterStatus = $state(manualFilters.status ?? "");
// manualQuery()
if (filterStatus !== "") query.status = filterStatus;

<Select id="manual-filter-status" bind:value={filterStatus} onchange={() => applyManualFilters()} testId="manual-filter-status">
    <option value="">すべて</option>
    {#each Object.entries(VIDEO_MANUAL_STATUS_LABELS) as [value, label] (value)}
        <option {value}>{label}</option>
    {/each}
</Select>
```
```svelte
<!-- ManualListRow.svelte -->
import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";

<Badge tone={STATUS_TONES[manual.status]} testId={`manual-status-${manual.id}`}>
    {VIDEO_MANUAL_STATUS_LABELS[manual.status]}
</Badge>
```

### 変更後コード
```svelte
<!-- Projects/Show.svelte -->
import type { ManualProgress } from "@/types/manual";
import { MANUAL_PROGRESS_LABELS } from "@/types/manual";

// 状態の絞り込み (doc/04 の 3 値)。制作状態 5 値では絞らない。
// "" = すべて。**union で受ける**ことで select の値が型で閉じる (詳細レビュー Round 1 [Warning] 対応)
let filterProgress = $state<ManualProgress | "">(manualFilters.progress ?? "");
// manualQuery()
if (filterProgress !== "") query.progress = filterProgress;

<label class="text-caption text-text-secondary" for="manual-filter-progress">状態</label>
<Select
    id="manual-filter-progress"
    bind:value={filterProgress}
    onchange={() => applyManualFilters()}
    testId="manual-filter-progress"
>
    <option value="">すべて</option>
    {#each Object.entries(MANUAL_PROGRESS_LABELS) as [value, label] (value)}
        <option {value}>{label}</option>
    {/each}
</Select>
```
```svelte
<!-- ManualListRow.svelte -->
import { MANUAL_PROGRESS_LABELS, MANUAL_PROGRESS_TONES } from "@/types/manual";

<!-- 一覧の状態は 3 値 (絞り込みと同じ語彙でないと絞り込み結果を説明できない)。
     「解析中 / 書き出し中」の実況は詳細画面 (AnalysisPanel / RenderPanel) が持つ -->
<Badge tone={MANUAL_PROGRESS_TONES[manual.progress]} testId={`manual-progress-${manual.id}`}>
    {MANUAL_PROGRESS_LABELS[manual.progress]}
</Badge>
```

- `testId` は `manual-status-{id}` → `manual-progress-{id}`、select は
  `manual-filter-status` → `manual-filter-progress` へ変える (旧名を残すと**古い語彙の名前が
  新しい値を指す**ことになり、テストの読み手を誤らせる)。
  - **旧 testId の参照の実測** (詳細レビュー Round 1 [Warning] 対応。`rg` 実行結果):
    参照は本施策の変更対象 2 ファイル (`ManualListRow.svelte` / `Projects/Show.svelte`) と
    Vitest 2 ファイル (`tests/js/pages/ProjectsShow.test.ts` /
    `tests/js/components/features/manual/ManualListRow.test.ts`) の**計 4 件だけ**である。
    Browser lane (`tests/Browser/`)・Feature テスト・bug-hunt 目録に参照は無い。
  - **`Manuals/Show.svelte` の `manual-status` (id 無し) は変えない**。あれは詳細画面の
    5 値バッジであり、語彙も面も別である (`ManualsShow.test.ts` は無変更で緑のままであるべき)。
- **DS token / Badge tone のみ**を使い hex 直書きは増やさない。アイコン追加なし。
- component 階層 (`features/manual` → `atoms`) の import 方向は現行のまま (逆流なし)。

### PHPStan適合チェック
- 対象外 (Svelte)。`pnpm lint` / `pnpm typecheck` / ds-purity テストで担保。

### テスト計画
- [ ] `ProjectsShow.test.ts`: 状態 select が **3 選択肢 + すべて**を出す (未着手/作成中/作成済)、
      かつ 5 値ラベル (下書き / 解析中 / 準備完了 / 書き出し中 / 公開済み) を**出さない**
- [ ] `ProjectsShow.test.ts`: 状態を選ぶと `router.get` の query に `progress` が載る (`status` が載らない)
- [ ] `ManualListRow.test.ts`: `progress: "completed"` の行が「作成済」バッジを出す (testId 更新)
- [ ] `ProjectsShow.test.ts`: 削除送信 URL のクエリ文字列に `progress=` が載る (着地先の絞り込み維持)
- [ ] disabled を新設していないこと (既存の「disabled 不使用」テストが緑のまま)
- [ ] 実装後に `rg 'manual-status-|manual-filter-status'` を実行し、
      **本施策の変更対象と Vitest 以外で 0 件**であることを確認する (旧 testId 参照ゼロ確認)

### リスク
- Browser lane (Chromium/WebKit) は `manual-status-` testid を参照していない (実測済み)。
  実装時に上記 grep で再確認する (設計書の主張だけに依存しない)。

---

## G. 撮影 PWA 語彙の明示化と dead payload 撤去

### 変更箇所
- ファイル: `resources/js/types/capture.ts` (`CaptureManualSummary` 付近に導出を追加、`status` を削除)
- ファイル: `resources/js/pages/Capture/Index.svelte` (L122-129 の三項式バッジ)
- ファイル: `app/DataTransferObjects/Capture/CaptureManualSummaryData.php` (`$status` 撤去)
- ファイル: `app/Http/Controllers/Capture/CaptureManualController.php` (**変更なし** — 母集団の
  `whereIn('status', [Ready, Published])` は撮影対象の定義であり表示語彙ではないので触らない)
  - **据え置きの明記** (詳細レビュー Round 1 [Warning] 対応): 同 controller の
    `category` 解析は `(int) $request->string('category')` であり、PC 側 `ManualListQuery` の
    allowlist (数値以外は破棄) と流儀が違う (`'abc'` → `0` = 該当なしへ倒れる)。
    どちらも安全側 (PC は「広く当たる」、PWA は「該当なし」) で、権限やテナント境界は跨がない。
    本タスクの対象は**表示語彙**であり、ここに VO を新設するのは別タスク相当のスコープ拡大
    (思考原則 2) なので**据え置く**。

### 波及変更
- TypeScript型定義: `CaptureManualSummary.status` の削除 + `CaptureProgress` の追加
- API Resource/DTO: `CaptureManualSummaryData` の shape PHPDoc
- テストファイル: `tests/Feature/Capture/CaptureManualBrowsingTest.php` (L127 の期待キー一覧から
  `status` を外す) / `tests/js/pages/CaptureIndex.test.ts`

### 現行コード
```svelte
{#if manual.cuts_total > 0 && manual.cuts_adopted === manual.cuts_total}
    <Badge tone="success">撮影完了</Badge>
{:else if manual.cuts_with_takes > 0}
    <Badge tone="tertiary">撮影中</Badge>
{:else}
    <Badge tone="neutral">未撮影</Badge>
{/if}
```
```php
// CaptureManualSummaryData (抜粋) — status は PWA の画面で表示にも分岐にも使われていない
public string $status,
// ...
status: $manual->status->value,
// toArray(): 'status' => $this->status,
```

### 変更後コード
```ts
// resources/js/types/capture.ts
import type { BadgeTone } from "@/components/atoms/Badge.types";

/**
 * 撮影進捗 (この 1 本のマニュアルの撮影がどこまで進んだか)。
 * **PC 一覧の ManualProgress (制作の到達段階) とは別の量である** —
 * 導出元 (カットの採用状況 vs video_manuals.status)、更新契機、値の動きが独立している
 * (例: 制作は「作成中」でも撮影は「撮影完了」は正常な組合せ)。語が似ていても統合しないこと。
 */
export type CaptureProgress = "captured" | "capturing" | "not_captured";

export const CAPTURE_PROGRESS_LABELS = {
    captured: "撮影完了",
    capturing: "撮影中",
    not_captured: "未撮影",
} as const satisfies Record<CaptureProgress, string>;

export const CAPTURE_PROGRESS_TONES = {
    captured: "success",
    capturing: "tertiary",
    not_captured: "neutral",
} as const satisfies Record<CaptureProgress, BadgeTone>;

/**
 * 撮影進捗の導出 (現行の三項式と**同一の判定**を名前付きにしたもの。判定は変えない)。
 *
 * 判定順序の意味: カットが 1 件も無いマニュアル (`cuts_total === 0`) は
 * **撮影の分母が無い**ので「未撮影」へ倒す。take は cut に属するため
 * `cuts_total === 0 && cuts_with_takes > 0` は構造上生じないが、生じても同じ扱いになる。
 */
export function captureProgressOf(
    summary: Pick<CaptureManualSummary, "cuts_total" | "cuts_adopted" | "cuts_with_takes">,
): CaptureProgress {
    if (summary.cuts_total > 0 && summary.cuts_adopted === summary.cuts_total) return "captured";
    if (summary.cuts_with_takes > 0) return "capturing";
    return "not_captured";
}
```
```svelte
<!-- Capture/Index.svelte (each の中) -->
{@const progress = captureProgressOf(manual)}
<Badge tone={CAPTURE_PROGRESS_TONES[progress]}>{CAPTURE_PROGRESS_LABELS[progress]}</Badge>
```
```php
// CaptureManualSummaryData: public string $status / status: ... / 'status' => ... の 3 箇所と
// toArray() の shape PHPDoc から status を削除する (表示にも分岐にも使われていない dead payload)
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている (`toArray()` の shape PHPDoc から `status` を削除)
- [x] null 安全 (削除のみ)
- [x] DTO を返している
- [x] Generics の型パラメータが正しい

### テスト計画
- [ ] `tests/js/pages/CaptureIndex.test.ts`: 3 状態のバッジ語が **撮影完了 / 撮影中 / 未撮影**のままであること
      (PC 語彙へ寄せる将来変更の回帰封じ)。境界も固定する:
      `cuts_total=0 && cuts_with_takes=0` は「未撮影」/
      **`cuts_total=0 && cuts_with_takes>0` (構造上生じない不整合) も「未撮影」** /
      `cuts_adopted < cuts_total` かつ `cuts_with_takes>0` は「撮影中」/
      `cuts_total>0 && cuts_adopted === cuts_total` は「撮影完了」
- [ ] `CaptureManualBrowsingTest`: 行 payload のキー一覧から `status` が消える
- [ ] `pnpm typecheck` で `CaptureManualSummary.status` の参照が 0 件であること

### リスク
- PWA payload の破壊的変更だが、参照している画面コードが無い (実読で確認)。
  テスト側の期待キー一覧のみ追随が要る。

---

## H. PHP enum ⇔ TS union 値集合同期テストの拡張

### 変更箇所
- ファイル: `tests/Architecture/ManualEnumTsSyncInvariantTest.php`

### 波及変更
- なし (テストのみ)

### 現行コード
```php
test('RenderKind の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('RenderKind'))->toBe(TsUnionValues::enumStringValues(RenderKind::cases()));
});
// ... RenderStep / RenderErrorCode / RenderConflictType / AnalysisJobStatus / CutMaterialType / MaterialType
// **VideoManualStatus は対象外** (types/manual.ts の docblock も「乖離検知は当面手動確認」)
```

### 変更後コード
```php
use App\Enums\Manual\ManualProgress;
use App\Enums\Manual\VideoManualStatus;

test('VideoManualStatus の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('VideoManualStatus'))
        ->toBe(TsUnionValues::enumStringValues(VideoManualStatus::cases()));
});

test('ManualProgress の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('ManualProgress'))
        ->toBe(TsUnionValues::enumStringValues(ManualProgress::cases()));
});
```
併せて `types/manual.ts` L1-6 の docblock から「乖離検知は当面手動確認」を削除し、
本テストが正本であることを書く (現状と食い違う説明を残さない)。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている (既存 helper `extractTsUnionValues(): list<string>` を再利用)
- [x] degenerate PASS 防止の自己検証テストが既にある (抽出不能なら例外)

### テスト計画
- [ ] 上記 2 本を追加して `composer test` で緑
- [ ] 片方の union を意図的に崩すと落ちることを実装時に手元で 1 度確認する (fail-first)

### リスク
- なし (検出面が増えるだけ)。

---

## I. 写像テスト新設 + 既存 Feature/Vitest の更新

### 変更箇所
- 新規: `tests/Unit/Manual/ManualProgressMappingTest.php`
- 更新: `tests/Feature/Projects/ProjectShowManualsTest.php` /
  `tests/Feature/Projects/ManualRowActionsTest.php` /
  `tests/Feature/Capture/CaptureManualBrowsingTest.php`
- 更新: `tests/js/pages/ProjectsShow.test.ts` /
  `tests/js/components/features/manual/ManualListRow.test.ts` /
  `tests/js/pages/CaptureIndex.test.ts`

### 波及変更
- なし (テストのみ)

### 現行コード
```php
// ProjectShowManualsTest (抜粋)
test('status フィルタで絞り込める (不正値は無視)', function (): void {
    // ...
    $this->actingAs($owner)->get("/projects/{$project->id}?status=published")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '公開済み')
            ->where('manualFilters.status', 'published'));

    $this->actingAs($owner)->get("/projects/{$project->id}?status=bogus")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 2)
            ->where('manualFilters.status', null));
});
```

### 変更後コード
```php
// tests/Unit/Manual/ManualProgressMappingTest.php (新規。写像の正本を固定する)
// DB を使わない純粋 enum テストなので Unit レーンに置く (既存の tests/Unit/Manual/ と同じ所在。
// 詳細レビュー Round 1 [Warning] 対応)。Inertia payload / 絞り込み挙動は Feature に残す。
test('制作状態 5 値が一覧の状態 3 値へ写る (写像表)', function (): void {
    expect(ManualProgress::forStatus(VideoManualStatus::Draft))->toBe(ManualProgress::NotStarted)
        ->and(ManualProgress::forStatus(VideoManualStatus::Analyzing))->toBe(ManualProgress::InProgress)
        ->and(ManualProgress::forStatus(VideoManualStatus::Ready))->toBe(ManualProgress::InProgress)
        ->and(ManualProgress::forStatus(VideoManualStatus::Rendering))->toBe(ManualProgress::InProgress)
        ->and(ManualProgress::forStatus(VideoManualStatus::Published))->toBe(ManualProgress::Completed);
});

test('逆写像は漏れなく排他である (和 = 全 status / 重複なし)', function (): void {
    $union = [];
    foreach (ManualProgress::cases() as $progress) {
        foreach ($progress->statuses() as $status) {
            $union[] = $status->value;
        }
    }
    sort($union);
    $all = array_map(static fn (VideoManualStatus $s): string => $s->value, VideoManualStatus::cases());
    sort($all);

    expect($union)->toBe($all)                      // 漏れなし
        ->and(count($union))->toBe(count(array_unique($union))); // 排他
});

test('statusValues() は statuses() の DB 値列と一致する', function (): void {
    foreach (ManualProgress::cases() as $progress) {
        expect($progress->statusValues())->toBe(
            array_map(static fn (VideoManualStatus $s): string => $s->value, $progress->statuses()),
        );
    }
});

test('一覧の状態は 3 値である (doc/04 の 3 値と件数一致)', function (): void {
    expect(ManualProgress::cases())->toHaveCount(3);
});
```
```php
// ProjectShowManualsTest (置換後の骨子)
// fixture は status ごとに固有 title を付ける (件数だけの assertion にしない =
// 詳細レビュー Round 1 [Warning] 対応)。並びは既定 (created_at desc, id desc)。
// '下書き' => Draft / '解析中' => Analyzing / '準備完了' => Ready /
// '書き出し中' => Rendering / '公開済み' => Published の 5 本を Factory で作る。

test('progress=in_progress は analyzing / ready / rendering の 3 件を返す', function (): void {
    $this->actingAs($owner)->get("/projects/{$project->id}?progress=in_progress")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 3)
            ->where('manualFilters.progress', 'in_progress'));

    // 対象の同定は title の集合で行う (件数一致だけに頼らない)
    $titles = collect(data_get($this->response->viewData('page')['props'], 'manuals.data'))
        ->pluck('title')->sort()->values()->all();
    expect($titles)->toBe(['解析中', '書き出し中', '準備完了']);
});

test('progress=not_started は draft のみ / progress=completed は published のみ', function (): void {
    $this->actingAs($owner)->get("/projects/{$project->id}?progress=not_started")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '下書き'));

    $this->actingAs($owner)->get("/projects/{$project->id}?progress=completed")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '公開済み'));
});

test('allowlist 外の値と旧 ?status= は無視して全件になる (互換は残さない)', function (): void {
    // 旧 5 値をそのまま渡しても progress の allowlist は通らない
    $this->actingAs($owner)->get("/projects/{$project->id}?progress=ready")
        ->assertInertia(fn (Assert $page) => $page->has('manuals.data', 5)
            ->where('manualFilters.progress', null));

    // **旧 URL の互換は無い** (?status=published は未知キーとして無視される)
    $this->actingAs($owner)->get("/projects/{$project->id}?status=published")
        ->assertInertia(fn (Assert $page) => $page->has('manuals.data', 5)
            ->where('manualFilters.progress', null)
            ->missing('manualFilters.status'));
});

test('行 payload は progress を持ち status を持たない', function (): void {
    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manuals.data.0.progress', 'not_started')
            ->missing('manuals.data.0.status')
            // paginator の query が外に出ないことの構造的確認 (links を props に出していない)
            ->missing('manuals.links'));
});
```

> title 集合の取り出しは実装時に既存テストの流儀へ合わせる
> (`assertInertia` の `has(..., fn (Assert $item) => ...)` で 1 件ずつ書く形でもよい)。
> 要点は「**件数だけでなく対象を同定する**」ことである。

### PHPStan適合チェック
- [x] Factory 経由でテストデータを生成する (`VideoManual::factory()->forProject($project)->create([...])`)
- [x] 個別の `DatabaseTransactions` を使わない (`RefreshDatabase` はグローバル適用)
- [x] `--parallel` 実行と両立する (状態を持たない)

### テスト計画 (このセクション自体がテスト計画)
- [ ] Feature: 上記 4 本 (新規) + 既存 4 ファイルの更新
- [ ] Vitest: `ProjectsShow` (select 3 値 / query キー / 5 値ラベル不在) /
      `ManualListRow` (3 値バッジ + testId) / `CaptureIndex` (PWA 語彙の維持と境界)
- [ ] **fail-first**: 施策 A〜G の実装前に I のテストを書いて赤を確認してから実装する (思考原則 5)
- [ ] 詳細画面 / ダッシュボードの既存テスト (`ManualsShow.test.ts` の「下書き」バッジ /
      `Dashboard.test.ts`) は**無変更で緑**であること (= 5 値語彙を壊していないことの確認)

### リスク
- 一覧の Feature テストの件数期待値 (5 本の manual) を作るため、既存テストのフィクスチャが増える。
  他テストへの影響は無い (テストごとに DB がリセットされる)。

---

## J. doc の語彙統一と写像正本の明記

### 変更箇所
- `doc/02_システム全体像.md` §2.4 動画マニュアル の `状態`（撮影完了・着手中・未着手）
- `doc/04_PCサイト機能仕様.md` §動画一覧ページ の絞り込み記述

### 波及変更
- なし (仕様書のみ。振る舞いの変更は施策 A〜G が持つ)

### 現行コード
```
doc/02 §2.4: ... / `状態`（撮影完了・着手中・未着手）/ `公開範囲`（...）...
doc/04 §動画一覧ページ: **絞り込み**: カテゴリ / 「自分が作成したタイトルのみ」/ 状態（作成済・作成中・未着手）。
```

### 変更後コード
```
doc/02 §2.4: ... / `状態`（作成済・作成中・未着手）/ ...
  ※ 撮影 PWA の「撮影完了 / 撮影中 / 未撮影」は本項目とは別の量 (カットの撮影進捗) である。

doc/04 §動画一覧ページ:
- **絞り込み**: カテゴリ / 「自分が作成したタイトルのみ」/ 状態（作成済・作成中・未着手）。
  実装の制作状態 5 値 (draft/analyzing/ready/rendering/published) からの写像は
  `App\Enums\Manual\ManualProgress` が正本。URL クエリは `?progress=`。
```

### PHPStan適合チェック
- 対象外 (ドキュメント)。

### テスト計画
- [ ] 機械テストは持たない (doc の文言は自動検査対象外)。
      代わりに写像の正本の所在は施策 A の docblock と施策 I の Feature テストが持つ
      (**doc に写像表そのものを書かない** = 2 か所に書かない)

### リスク
- doc の語を変えることで、旧語 (撮影完了) を前提にした過去の議事録との差が出る。
  差分の理由は本設計書に残っているので追跡可能。

---

## 完了条件 (検証コマンド)

PHP / TypeScript / Svelte / Inertia payload を同時に変えるため、以下が**全て green** で完了とする
(詳細レビュー Round 1 [Warning] 対応。AGENTS.md の検証コマンド一覧のうち本変更に関係するもの)。

| コマンド | 何を守るか |
|---|---|
| `composer test` | 写像テスト (Unit) / 値集合同期 (Architecture) / 一覧・削除着地・撮影一覧の Feature |
| `composer phpstan` | 網羅 match の未処理 case、array shape PHPDoc の整合 (level 10。widen / baseline 化しない) |
| `vendor/bin/pint --test` | PHP の書式 |
| `pnpm lint` | Svelte / TS の lint |
| `pnpm typecheck` | `satisfies` のキー漏れ、`ManualListItem.progress` 参照側の型不整合、`CaptureManualSummary.status` の残存参照 |
| `pnpm test` | Vitest (一覧 3 値 / 撮影 PWA 語彙の維持 / router query のキー) |
| `pnpm build` | 本番ビルドの通過 |

テストレーンは**ホスト全体のグローバルロックで直列化**される。待ちが出るのは正常で
30 秒ごとに heartbeat が出る (kill しない / ロックファイルを消さない)。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 行 props (`ManualListItem`) と GET クエリ (`?status=` → `?progress=`) の**破壊的変更**を含み、PHP DTO・Inertia props・TS 型・Svelte・Feature/Vitest を**同一コミットで整合**させる必要がある (中間状態では型もテストも通らない)。施策 A〜J は 1 本の変更として不可分。 |
| 競合リスク | `Projects/Show.svelte` は他タスクも触りやすい大きな page component (T182 / T189 が直近で変更)。`ManualListQuery` / `ManualListItemData` も同様。並行タスクがある場合は同ファイルの競合に注意する。撮影 PWA (`Capture/Index.svelte`) 側は施策 G のみで、他タスクとの重複は小さい。 |

## 全体のリスクと保証範囲 (誇張しない)

- **保証すること**: 5 値 → 3 値の写像規則がリポジトリに 1 つだけ存在すること (`ManualProgress::forStatus`)。
  status の追加時に網羅 match (PHPStan) と値集合同期テスト (Architecture) の**両方**が落ちること。
  一覧の絞り込みと行バッジが同じ語彙であること。
- **保証しないこと**:
  - 一覧の表示が常に最新であること (**一覧はポーリングしない**。3 値化しても
    `rendering → published` は再読込まで反映されない)。
  - 詳細画面 / ダッシュボードの 5 値語彙と一覧の 3 値語彙が同じ語に見えること
    (**意図的に別語彙**である。片方だけ見て他方を推測できるとは主張しない)。
  - 撮影 PWA の撮影進捗と PC の制作状態が連動すること (**別の量**である)。
  - 旧 URL (`?status=…`) の絞り込みが再現されること (**互換は残さない**)。
- **既存の不変条件を壊さないこと**の確認点:
  - `ManualListQuery` は依然として**唯一の解析点**である (解析を controller 側へ散らさない)。
  - シナリオ整合の共有ロック規約 (ドメイン規約 1) に触れる書き込み経路は**1 つも増やさない**
    (本設計は読み取りと表示だけを変える)。
  - 認可・テナント境界の判定 (`ManualRowAbilities` / `resolveOrganizationProject`) は無変更。
  - `response()->json()` の直書きを増やさない (Inertia props のみ)。
