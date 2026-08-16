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
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: design token 経由の参照か、hex 直書きを増やしていないか
11. Atomic Design準拠（UI/frontend 変更を含む場合）: atoms/molecules/organisms/features/templates/pages の単方向 import を守っているか。アイコンは Lucide のみか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
| I | 写像テスト新設 + 既存 Feature/Vitest の更新 | `tests/Feature/Manual/ManualProgressMappingTest.php` (新規) 他 | 高 |
| J | doc の語彙統一と写像正本の明記 | `doc/02_システム全体像.md` / `doc/04_PCサイト機能仕様.md` | 中 |

---

## A. 写像の正本 `ManualProgress` enum 新設

### 変更箇所
- ファイル: `app/Enums/Manual/ManualProgress.php` (新規)

### 波及変更
- TypeScript型定義: `resources/js/types/manual.ts` に同名 union を追加 (施策 E)
- API Resource/DTO: `ManualListQuery` (B) / `ManualListItemData` (D)
- テストファイル: `tests/Feature/Manual/ManualProgressMappingTest.php` (新規) /
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
- [ ] 新規 `tests/Feature/Manual/ManualProgressMappingTest.php`:
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
- ファイル: `app/Http/Controllers/Projects/ProjectController.php` (L151-158 の shape PHPDoc / L181-183)

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

### PHPStan適合チェック
- [x] 戻り値の型が明示されている (array shape PHPDoc を更新)
- [x] null 安全 (`!== null` の早期分岐)
- [x] DTO を返している (`ManualListItemData::toArray()`)
- [x] Generics の型パラメータが正しい (`list<string>` を `whereIn` に渡す)

### テスト計画
- [ ] `?progress=in_progress` で analyzing / ready / rendering の 3 行が返り、draft / published が返らない
- [ ] `?progress=not_started` で draft のみ、`?progress=completed` で published のみ
- [ ] `mine` / `category` / `q` / `sort` との併用テスト (既存の結合テスト) を `progress` へ更新して緑

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
 * VideoManualStatus の表示ラベル。
 * **使ってよいのは「いま何をしているか」を実況する面だけ** = 詳細画面 (Manuals/Show) と
 * ダッシュボード。**一覧では使わない** (一覧はポーリングせず、短命な遷移状態を出すと
 * 再読込まで嘘になるため。一覧は MANUAL_PROGRESS_LABELS を使う)。
 */
export const VIDEO_MANUAL_STATUS_LABELS: Record<VideoManualStatus, string> = { /* 現行のまま */ };

/** 状態バッジの tone (詳細画面 / ダッシュボード用。一覧は MANUAL_PROGRESS_TONES) */
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
import { MANUAL_PROGRESS_LABELS } from "@/types/manual";

// 状態の絞り込み (doc/04 の 3 値)。制作状態 5 値では絞らない
let filterProgress = $state<string>(manualFilters.progress ?? "");
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

### リスク
- Browser lane (Chromium/WebKit) が `manual-status-` testid を参照していないことは確認済み
  (参照は Vitest 側のみ)。念のため実装時に `rg 'manual-status'` で 0 件を確認する。

---

## G. 撮影 PWA 語彙の明示化と dead payload 撤去

### 変更箇所
- ファイル: `resources/js/types/capture.ts` (`CaptureManualSummary` 付近に導出を追加、`status` を削除)
- ファイル: `resources/js/pages/Capture/Index.svelte` (L122-129 の三項式バッジ)
- ファイル: `app/DataTransferObjects/Capture/CaptureManualSummaryData.php` (`$status` 撤去)
- ファイル: `app/Http/Controllers/Capture/CaptureManualController.php` (**変更なし** — 母集団の
  `whereIn('status', [Ready, Published])` は撮影対象の定義であり表示語彙ではないので触らない)

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

/** 撮影進捗の導出 (現行の三項式と同一の判定を名前付きにしたもの) */
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
      `cuts_total=0` は「未撮影」/ `cuts_adopted < cuts_total` かつ `cuts_with_takes>0` は「撮影中」
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
- 新規: `tests/Feature/Manual/ManualProgressMappingTest.php`
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
// tests/Feature/Manual/ManualProgressMappingTest.php (新規。写像の正本を固定する)
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
test('progress フィルタで絞り込める (3 値 / 不正値と旧 status は無視)', function (): void {
    // Factory で draft / analyzing / ready / rendering / published を 1 本ずつ用意する
    $this->actingAs($owner)->get("/projects/{$project->id}?progress=in_progress")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 3)                       // analyzing / ready / rendering
            ->where('manualFilters.progress', 'in_progress'));

    $this->actingAs($owner)->get("/projects/{$project->id}?progress=not_started")
        ->assertInertia(fn (Assert $page) => $page->has('manuals.data', 1));

    $this->actingAs($owner)->get("/projects/{$project->id}?progress=completed")
        ->assertInertia(fn (Assert $page) => $page->has('manuals.data', 1));

    // allowlist 外 (旧 5 値を含む) は無視して全件 = 絞り込み無しへ倒す
    $this->actingAs($owner)->get("/projects/{$project->id}?progress=ready")
        ->assertInertia(fn (Assert $page) => $page->has('manuals.data', 5)
            ->where('manualFilters.progress', null));

    // **旧 URL の互換は無い** (?status=published は未知キーとして無視される)
    $this->actingAs($owner)->get("/projects/{$project->id}?status=published")
        ->assertInertia(fn (Assert $page) => $page->has('manuals.data', 5)
            ->where('manualFilters.progress', null));
});

test('行 payload は progress を持ち status を持たない', function (): void {
    // ->where('manuals.data.0.progress', 'not_started')
    // ->missing('manuals.data.0.status')
});
```

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


## 関連する現行コード

### app/Enums/Manual/VideoManualStatus.php (全文)
```php
<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * 動画マニュアルの状態 (doc/10 §10.2)。
 * フェーズ1で実際に遷移するのは Draft (作成時既定) のみ。
 * 状態遷移メソッドは解析/レンダの後続フェーズで追加する。
 */
enum VideoManualStatus: string
{
    case Draft = 'draft';
    case Analyzing = 'analyzing';
    case Ready = 'ready';
    case Rendering = 'rendering';
    case Published = 'published';
}

```

### app/DataTransferObjects/Manual/ManualListQuery.php (全文)
```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Enums\Manual\ManualSortOption;
use App\Enums\Manual\VideoManualStatus;
use Illuminate\Http\Request;

/**
 * 動画マニュアル一覧の GET クエリ (allowlist 済みの値)。
 *
 * **唯一の解析点**である: 一覧の絞り込み (ProjectController::show) と、
 * 行内削除の着地先 (VideoManualController::destroy が redirect に載せ直す値) が
 * 同じ VO を通るため、両者が食い違うことが構造的に起きない。
 *
 * 値の約束:
 * - `category`: 数値 id 文字列 | 'uncategorized' (未分類 sentinel) | null。それ以外は null
 * - `status`: VideoManualStatus の値のみ。それ以外は null
 * - `keyword`: 前後の空白を除いた検索語。**先頭 MAX_KEYWORD_LENGTH 文字だけを使う (truncate)**。
 *   破棄 (= 絞り込み無し) にしないのは「全件が出る」驚きの方向へ倒れるためで、
 *   切り詰めは「より広く当たる」方向にしか倒れない。title の validation が max:200 なので、
 *   201 文字目以降が一致に寄与することは無い
 * - `sort`: ManualSortOption の allowlist のみ (ユーザー入力をカラム名に渡さない)
 * - `mine`: 自分の作成分のみ
 * - `page`: 1 以上 maxPage() 以下。数字以外は 1、上限超過は maxPage()
 *   (「最後の方を見たい」意図に近い側へ倒す。着地は一覧側の丸めで最終ページになる)
 */
final readonly class ManualListQuery
{
    /** 検索語の最大長 (StoreVideoManualRequest の title max:200 と一致させる) */
    public const int MAX_KEYWORD_LENGTH = 200;

    /** 1 ページあたり件数 (現行踏襲)。**一覧の perPage はここだけが正本** */
    public const int PER_PAGE = 10;

    public function __construct(
        public ?string $category,
        public ?string $status,
        public ?string $keyword,
        public ?ManualSortOption $sort,
        public bool $mine,
        public int $page,
    ) {}

    /**
     * 受け付けるページ番号の上限。
     *
     * チューニング値ではなく**計算安全性の境界**である: paginator の offset は
     * `($page - 1) * PER_PAGE` で求まるため、この上限が無いと
     * `ctype_digit` を通った巨大な数字列 ((int) キャストで PHP_INT_MAX へ飽和する) が
     * int 範囲を超える乗算 (= float 化) を起こす。PER_PAGE から導出しているので
     * 説明のつかない定数にはならない。
     *
     * **定数ではなくメソッドである理由**: クラス定数の初期化式に関数呼び出しは書けない
     * (`const int MAX_PAGE = intdiv(PHP_INT_MAX, self::PER_PAGE);` はコンパイルエラー)。
     */
    public static function maxPage(): int
    {
        return intdiv(PHP_INT_MAX, self::PER_PAGE);
    }

    public static function fromRequest(Request $request): self
    {
        $category = $request->query('category');
        $category = is_string($category) && $category !== '' ? $category : null;
        if ($category !== null && $category !== 'uncategorized') {
            // 数値 id 以外は破棄。数値は**正規形へ畳む** ('0003' → '3')。
            // 破棄にしないのは絞り込みが消えて全件が出る方向に倒れるためで、正規化なら
            // 同じ結果集合のまま「フィルタ select の選択値」「着地先 URL」と一致する。
            // 桁溢れは (int) が PHP_INT_MAX へ飽和して該当なしになる (URL も有界に保たれる)。
            $category = ctype_digit($category) ? (string) (int) $category : null;
        }

        $status = $request->query('status');
        $status = is_string($status) && VideoManualStatus::tryFrom($status) !== null ? $status : null;

        $keyword = $request->query('q');
        $keyword = is_string($keyword) && trim($keyword) !== ''
            ? mb_substr(trim($keyword), 0, self::MAX_KEYWORD_LENGTH)
            : null;

        $sortRaw = $request->query('sort');
        // allowlist 外は null (= 既定順)。ユーザー入力をカラム名に渡さない
        $sort = is_string($sortRaw) ? ManualSortOption::tryFrom($sortRaw) : null;

        // (int) は PHP_INT_MAX へ飽和するため、上限で丸めてから使う
        // (offset 計算 ($page - 1) * PER_PAGE を int 範囲に収める)
        $pageRaw = $request->query('page');
        $page = is_string($pageRaw) && ctype_digit($pageRaw)
            ? min(max(1, (int) $pageRaw), self::maxPage())
            : 1;

        return new self(
            category: $category,
            status: $status,
            keyword: $keyword,
            sort: $sort,
            mine: $request->boolean('mine'), // "1"/"true" を bool 正規化
            page: $page,
        );
    }

    /**
     * Inertia へ返す manualFilters prop (sort enum → string 値へ落とす単一変換点)。
     * **page を含めない**: ページ位置は manuals.meta.current_page が唯一の正本である
     * (2 か所に持つと必ず食い違う)。
     *
     * @return array{category: string|null, status: string|null, q: string|null, sort: string|null, mine: bool}
     */
    public function toProps(): array
    {
        return [
            'category' => $this->category,
            'status' => $this->status,
            'q' => $this->keyword,
            'sort' => $this->sort?->value, // string|null (TS の ManualFilters.sort と一致)
            'mine' => $this->mine,
        ];
    }

    /**
     * この絞り込みを再現する route() 用クエリ (既定値は載せない = URL を短く保つ)。
     * 値は上の allowlist を通った後のものだけである (生の入力を Location に素通ししない)。
     *
     * @return array<string, string|int>
     */
    public function toQueryParams(): array
    {
        $params = [];
        if ($this->category !== null) {
            $params['category'] = $this->category;
        }
        if ($this->status !== null) {
            $params['status'] = $this->status;
        }
        if ($this->keyword !== null) {
            $params['q'] = $this->keyword;
        }
        if ($this->sort !== null) {
            $params['sort'] = $this->sort->value;
        }
        if ($this->mine) {
            $params['mine'] = 1;
        }
        if ($this->page > 1) {
            $params['page'] = $this->page;
        }

        return $params;
    }
}

```

### app/Http/Controllers/Projects/ProjectController.php L146-227 (manualRows)
```php
    /**
     * 動画マニュアル一覧 rows (paginate + DTO で shape を固定)。
     * 未分類は category => null (フロントは「未分類」を表示する)。
     * creator は退会/削除で解決不可のとき null (実運用では FK RESTRICT で常に解決)。
     *
     * @return array{
     *   data: list<array{id: int, title: string, status: string,
     *     category: array{id: int, name: string}|null,
     *     creator: array{id: int, name: string}|null,
     *     created_at: string, updated_at: string,
     *     duration_ms: int|null, current_finished_render_job_id: int|null, deletable: bool}>,
     *   meta: array{current_page: int, last_page: int, per_page: int, total: int}
     * }
     */
    private function manualRows(Project $project, ManualListQuery $listQuery, User $user): array
    {
        // latestSucceededRender も eager load する (行ごとの現行世代判定で N+1 を作らない)
        $baseQuery = $project->manuals()->with(['category', 'creator', 'latestSucceededRender']);

        // 並べ替え (allowlist enum 由来のカラム名のみ。既定は現行踏襲 created_at desc, id desc)
        $orderings = $listQuery->sort?->orderings() ?? ManualSortOption::defaultOrderings();
        foreach ($orderings as $ordering) {
            /** @var ManualOrdering $ordering */
            $baseQuery->orderBy($ordering['column'], $ordering['direction']);
        }

        if ($listQuery->mine) {
            // 自ユーザー id のみ (payload 非受領 = tenant/actor キー不信)
            $baseQuery->where('created_by', $user->id);
        }
        if ($listQuery->category === 'uncategorized') {
            $baseQuery->whereNull('category_id');
        } elseif ($listQuery->category !== null) {
            $baseQuery->where('category_id', (int) $listQuery->category);
        }
        if ($listQuery->status !== null) {
            $baseQuery->where('status', $listQuery->status);
        }
        if ($listQuery->keyword !== null) {
            // LIKE メタ文字 (%/_/\) はリテラル検索として扱う
            $baseQuery->where('title', 'like', '%'.addcslashes($listQuery->keyword, '%_\\').'%');
        }

        $paginated = (clone $baseQuery)
            ->paginate(perPage: ManualListQuery::PER_PAGE, page: $listQuery->page)
            ->withQueryString();

        // 範囲外ページ (行内削除で件数が減った / 古いブックマーク) は最終ページへ丸める。
        // 「空の一覧」に着地させない (行き先のない詰みを作らない)。
        // **0 件のときも丸める**: 一覧が空でも lastPage() は 1 なので、丸めないと
        // current_page=99 / last_page=1 という食い違った meta を渡すことになる。
        // URL の ?page=99 と meta.current_page は食い違うが、ページ送り UI は
        // meta.current_page を見る (**props が正本**であり redirect はしない)。
        if ($paginated->currentPage() > $paginated->lastPage()) {
            $paginated = (clone $baseQuery)
                ->paginate(perPage: ManualListQuery::PER_PAGE, page: $paginated->lastPage())
                ->withQueryString();
        }

        /** @var list<VideoManual> $manuals */
        $manuals = [];
        foreach ($paginated->items() as $manual) {
            Assert::isInstanceOf($manual, VideoManual::class);
            $manuals[] = $manual;
        }

        // ability はページで 1 回だけ評価する (理由は ManualRowAbilities の docblock)
        $abilities = ManualRowAbilities::forPage($user, $project, $manuals);

        return [
            'data' => array_map(
                fn (VideoManual $manual): array => ManualListItemData::fromManual($manual, $abilities)->toArray(),
                $manuals,
            ),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ];
    }
```

### app/DataTransferObjects/Manual/ManualListItemData.php (全文)
```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Enums\Manual\VideoManualStatus;
use App\Models\VideoManual;
use App\Services\Manual\CurrentRenderArtifact;
use App\Services\Manual\ManualRowAbilities;

/**
 * 動画マニュアル一覧 (Projects/Show に内包) の 1 行。TS の ManualListItem と対。
 *
 * **判断はここで 1 回だけ**行う (UI 側で published / ability を再判定しない。
 * RenderPanel の finishedJob と同じ流儀)。
 */
final readonly class ManualListItemData
{
    /**
     * @param  ManualListRefData|null  $category  null = 未分類
     * @param  ManualListRefData|null  $creator  null = 退会/削除で解決不可
     * @param  int|null  $durationMs  いま公開されている完成動画の長さ (ms)。null = 未確定
     * @param  int|null  $currentFinishedRenderJobId  いま受け取れる完成動画 (kind=render) の
     *                                                render job id。**null = 受け取れない**。非 null であることは download endpoint が
     *                                                302 を返す条件と 1 対 1 (download ability × published × 現行世代の succeeded render に
     *                                                output_path がある)。値は再生 endpoint
     *                                                `projects.manuals.render-jobs.playback` のパスにそのまま使える
     *                                                (完成動画の再生条件は download と完全同一 = ドメイン規約 13 / T154)
     */
    public function __construct(
        public int $id,
        public string $title,
        public VideoManualStatus $status,
        public ?ManualListRefData $category,
        public ?ManualListRefData $creator,
        public string $createdAt,
        public string $updatedAt,
        public ?int $durationMs,
        public ?int $currentFinishedRenderJobId,
        public bool $deletable,
    ) {}

    public static function fromManual(VideoManual $manual, ManualRowAbilities $abilities): self
    {
        $category = $manual->category;
        $creator = $manual->creator; // 退会/削除で null になり得る (実運用では FK RESTRICT)
        $isPublished = $manual->status === VideoManualStatus::Published;

        // 再生時間は「**いま公開されている**完成動画の長さ」。published が外れた行
        // (公開後にシナリオを保存すると ScenarioService が ready へ戻す) の total_length_ms は
        // 最新シナリオと対応しない古い尺なので出さない。
        $durationMs = $isPublished ? $manual->total_length_ms : null;

        // 「どの行か」の選択は **CurrentRenderArtifact ただ 1 箇所**に委ねる (T154)。
        // 一覧は eager load 済み候補から選ぶ入口を使う (行数に比例したクエリを撃たない)。
        // ここに残るのは Canonical が持たない責務 = published 判定と ability 判定だけである。
        // **ストレージ実体の存在確認ではない** (download / playback endpoint もしていない)。
        $currentFinishedRenderJobId = $abilities->canDownload && $isPublished
            ? CurrentRenderArtifact::fromLoadedRenderCandidate($manual)?->id
            : null;

        return new self(
            id: $manual->id,
            title: $manual->title,
            status: $manual->status,
            category: $category === null ? null : new ManualListRefData($category->id, $category->name),
            creator: $creator === null ? null : new ManualListRefData($creator->id, $creator->name),
            createdAt: $manual->created_at?->format('Y-m-d H:i') ?? '',
            updatedAt: $manual->updated_at?->format('Y-m-d H:i') ?? '',
            durationMs: $durationMs,
            currentFinishedRenderJobId: $currentFinishedRenderJobId,
            deletable: $abilities->canDelete,
        );
    }

    /**
     * @return array{id: int, title: string, status: string,
     *   category: array{id: int, name: string}|null,
     *   creator: array{id: int, name: string}|null,
     *   created_at: string, updated_at: string,
     *   duration_ms: int|null, current_finished_render_job_id: int|null, deletable: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'category' => $this->category?->toArray(),
            'creator' => $this->creator?->toArray(),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'duration_ms' => $this->durationMs,
            'current_finished_render_job_id' => $this->currentFinishedRenderJobId,
            'deletable' => $this->deletable,
        ];
    }
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

### app/Http/Controllers/Projects/VideoManualController.php L275-292 (destroy = 着地先に絞り込みを載せ直す)
```php
     * 付いてくるクエリは**対象の決定には一切使わない** (対象は route パラメータのみが決める)。
     */
    public function destroy(Request $request, Project $project, VideoManual $manual, VideoManualService $manuals): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('delete', $manual);

        $listQuery = ManualListQuery::fromRequest($request);

        $manuals->delete($project, $manual);

        return redirect()
            ->route('projects.show', ['project' => $project, ...$listQuery->toQueryParams()])
            ->with('success', '動画マニュアルを削除しました');
    }

```

### resources/js/types/manual.ts L1-50, L99-150 (状態語彙と一覧型)
```ts
/**
 * 動画マニュアル (VideoManual) / カテゴリ関連の Inertia props 型。
 * PHP 側の typed array PHPDoc (ProjectController::manualRows 等) と対で保守する。
 * status は PHP enum App\Enums\Manual\VideoManualStatus と値集合を一致させる
 * (literal union で UI 分岐漏れを検出する。乖離検知は当面手動確認)。
 */

import type { BadgeTone } from "@/components/atoms/Badge.types";

export type VideoManualStatus = "draft" | "analyzing" | "ready" | "rendering" | "published";

/** VideoManualStatus の表示ラベル (UI 共通) */
export const VIDEO_MANUAL_STATUS_LABELS: Record<VideoManualStatus, string> = {
    draft: "下書き",
    analyzing: "解析中",
    ready: "準備完了",
    rendering: "書き出し中",
    published: "公開済み",
};

/**
 * 状態バッジの tone (結果表示の意味色。UI 共通)。
 * satisfies でキー漏れ (status 追加時) をコンパイル時検出する。
 */
export const STATUS_TONES = {
    draft: "neutral",
    analyzing: "tertiary",
    ready: "success",
    rendering: "warning",
    published: "primary",
} as const satisfies Record<VideoManualStatus, BadgeTone>;

/**
 * 撮影ナビ (capture.manuals.show) へ導線を出してよい状態か。
 * 撮影ナビ一覧 (CaptureManualController::index) が列挙する ready/published と一致させる
 * (draft/analyzing/rendering はシナリオ未確定でナビ画面が空になるため導線を出さない)。
 * satisfies で status 追加時のキー漏れをコンパイル時検出する (STATUS_TONES と同方針)。
 */
export const CAPTURE_NAVIGABLE_BY_STATUS = {
    draft: false,
    analyzing: false,
    ready: true,
    rendering: false,
    published: true,
} as const satisfies Record<VideoManualStatus, boolean>;

/** PC 編集/詳細から撮影ナビへ導線を出してよいか (型付き判定の単一ソース) */
export function isCaptureNavigable(status: VideoManualStatus): boolean {
    return CAPTURE_NAVIGABLE_BY_STATUS[status];
}
```

```ts
/** PHP App\Enums\Manual\ManualSortOption と値集合を一致させる (allowlist) */
export type ManualSortOption = "updated_desc" | "updated_asc" | "title_asc" | "title_desc";

/** PHP: App\DataTransferObjects\Manual\ManualListItemData::toArray() と対 */
export interface ManualListItem {
    id: number;
    title: string;
    status: VideoManualStatus;
    /** null = 未分類 */
    category: { id: number; name: string } | null;
    /** 作成者。退会/削除で解決不可のときは null (UI は「不明」) */
    creator: { id: number; name: string } | null;
    created_at: string;
    updated_at: string;
    /**
     * いま公開されている完成動画の長さ (ms)。**null = 未確定**
     * (published でない / 総尺が記録されていない)。
     * published が外れた行の古い総尺はサーバが null に畳んでいるため、
     * UI 側で status を見て再判定しない。
     */
    duration_ms: number | null;
    /**
     * いま受け取れる完成動画 (kind=render) の render job id。**null = 受け取れない**。
     * サーバが「download ability × published × 現行世代の succeeded render に output_path がある」を
     * 判定した結果そのもので、**UI 側で条件を再判定しない**。
     * 非 null は download endpoint が 302 を返す条件と 1 対 1 であり、
     * 値は再生 endpoint `/projects/{p}/manuals/{m}/render-jobs/{id}/playback` にそのまま使う
     * (完成動画の再生条件は download と完全同一)。
     * 描画時点のスナップショットであり、ストレージ実体の存在確認ではない。
     */
    current_finished_render_job_id: number | null;
    /** 削除できるか (サーバの delete ability 判定結果。撮影者は false) */
    deletable: boolean;
}

export interface CategoryOption {
    id: number;
    name: string;
}

/** 一覧絞り込み条件 (GET クエリ)。category は id 文字列 | "uncategorized" | null */
export interface ManualFilters {
    category: string | null;
    status: string | null;
    q: string | null;
    /** 並べ替え。null = 既定 (作成日降順) */
    sort: ManualSortOption | null;
    /** 自分の作成分のみ */
    mine: boolean;
}

/**
```

### resources/js/components/features/manual/ManualListRow.svelte L1-70
```svelte
<script lang="ts">
    import { Download, Play, Trash2 } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import { formatDurationMs } from "@/lib/manual/format-duration";
    import type { ManualListItem } from "@/types/manual";
    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";

    /**
     * 動画マニュアル一覧の 1 行 (doc/04: 状態 / タイトル / カテゴリ / 再生時間 / 更新日 /
     * プレビュー / DL / 削除)。
     *
     * 表示の出し分けは**サーバが決めた行 props だけ**で行う
     * (current_finished_render_job_id / deletable。published も ability も UI 側で再判定しない)。
     * プレビューと DL は**同じ props 1 本**で出し分ける (再生条件は download と完全同一 = T154)。
     * 実行は一覧ページが持つ (この component は要求を上へ返すだけ)。
     */
    interface Props {
        projectId: number;
        manual: ManualListItem;
        /** プレビュー (オーバーレイ再生) を開く要求 */
        onRequestPreview: (manual: ManualListItem) => void;
        /** 削除確認ダイアログを開く要求 */
        onRequestDelete: (manual: ManualListItem) => void;
    }

    let { projectId, manual, onRequestPreview, onRequestDelete }: Props = $props();

    const durationLabel = $derived(formatDurationMs(manual.duration_ms));
    /** 受け取れる完成動画があるか (プレビュー / DL の唯一の出し分け根拠) */
    const finishedRenderJobId = $derived(manual.current_finished_render_job_id);
</script>

<!-- 狭い画面では縦積み (操作群を次行へ逃がす)、sm 以上で現行と同じ横並びに戻す。
     操作が 2 つ増えて shrink-0 側が広がるため、モバイルで行が潰れないようにする -->
<li
    class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
    data-testid={`manual-row-${manual.id}`}
>
    <div class="min-w-0">
        <!-- タイトルは 1 行省略にする (空白の無い長いタイトルでも行の操作領域を押し出さない)。
             TextLink は class prop を受け取れるので、幅制約用の要素で包まずに付与する -->
        <TextLink
            href={`/projects/${projectId}/manuals/${manual.id}`}
            class="block truncate"
            testId={`manual-link-${manual.id}`}
        >
            {manual.title}
        </TextLink>
        <p class="mt-1 truncate text-caption text-text-secondary">
            {manual.category?.name ?? "未分類"} ・ {manual.creator?.name ?? "不明"} ・ 更新 {manual.updated_at}
        </p>
    </div>
    <div class="flex shrink-0 items-center gap-2">
        <!-- 再生時間: 公開済みの完成動画の長さ。未確定は「—」。権限では隠さない -->
        <span
            class="text-caption text-text-secondary"
            data-testid={`manual-duration-${manual.id}`}
        >
            {durationLabel}
        </span>
        <Badge tone={STATUS_TONES[manual.status]} testId={`manual-status-${manual.id}`}>
            {VIDEO_MANUAL_STATUS_LABELS[manual.status]}
        </Badge>
        <!-- 受け取れるとサーバが判断した行にだけ出す。押せない (disabled) ボタンは作らない。
             出ていない行の理由は状態バッジと再生時間「—」が語り、書き出しの CTA は
             詳細画面 (RenderPanel) が唯一持つ。
             プレビューと DL は同じ条件 (playback の完成動画条件 = download 条件) なので
             同じ枝に置く = 2 つの条件を持たない。 -->
```

### resources/js/pages/Projects/Show.svelte L86-125 (フィルタ state / query)
```svelte
    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    /* ---- 動画マニュアル: フィルタ (GET クエリで manuals のみ部分更新) ---- */
    let filterCategory = $state(manualFilters.category ?? "");
    let filterStatus = $state(manualFilters.status ?? "");
    let filterQ = $state(manualFilters.q ?? "");
    let filterSort = $state<string>(manualFilters.sort ?? "");
    let filterMine = $state(manualFilters.mine);

    // 並べ替え option (空値 = 既定「新しい順(作成)」)。ManualSortOption の allowlist と対
    const MANUAL_SORT_OPTIONS: { value: string; label: string }[] = [
        { value: "", label: "新しい順（作成）" },
        { value: "updated_desc", label: "更新が新しい順" },
        { value: "updated_asc", label: "更新が古い順" },
        { value: "title_asc", label: "タイトル昇順" },
        { value: "title_desc", label: "タイトル降順" },
    ];

    function manualQuery(pageNumber?: number): Record<string, string | number> {
        const query: Record<string, string | number> = {};
        if (filterCategory !== "") query.category = filterCategory;
        if (filterStatus !== "") query.status = filterStatus;
        if (filterQ.trim() !== "") query.q = filterQ.trim();
        if (filterSort !== "") query.sort = filterSort;
        if (filterMine) query.mine = 1;
        // pageNumber 未指定 (フィルタ変更時) は page を載せない = 1 ページ目にリセットする
        if (pageNumber !== undefined && pageNumber > 1) query.page = pageNumber;
        return query;
    }

    function applyManualFilters(event?: SubmitEvent): void {
        event?.preventDefault();
        router.get(`/projects/${project.id}`, manualQuery(), {
            preserveState: true,
            preserveScroll: true,
            only: ["manuals", "manualFilters"],
        });
    }

```

### resources/js/pages/Projects/Show.svelte L420-440 (状態 select)
```svelte
                        </Select>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-caption text-text-secondary" for="manual-filter-status">
                            状態
                        </label>
                        <Select
                            id="manual-filter-status"
                            bind:value={filterStatus}
                            onchange={() => applyManualFilters()}
                            testId="manual-filter-status"
                        >
                            <option value="">すべて</option>
                            {#each Object.entries(VIDEO_MANUAL_STATUS_LABELS) as [value, label] (value)}
                                <option {value}>{label}</option>
                            {/each}
                        </Select>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-caption text-text-secondary" for="manual-filter-sort">
                            並べ替え
```

### resources/js/pages/Capture/Index.svelte L100-135 (撮影進捗バッジ)
```svelte
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
```

### resources/js/types/capture.ts L55-75 (CaptureManualSummary)
```ts
    title: string;
    status: string;
    cuts: CaptureCut[];
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
}

/** POST .../takes/upload-url の応答 (TakeUploadTicketResource と対) */
export interface UploadTicket {
```

### tests/Architecture/ManualEnumTsSyncInvariantTest.php (全文)
```php
<?php

declare(strict_types=1);

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\MaterialType;
use App\Enums\Manual\RenderConflictType;
use App\Enums\Manual\RenderErrorCode;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\RenderStep;
use Tests\Support\TsUnionValues;

/*
 * PHP enum ⇔ TS literal union の値集合同期 invariant (概念設計 Round 3)。
 *
 * resources/js/types/manual.ts の literal union を正規表現で抽出し、PHP enum の
 * 値集合と完全一致することを固定する (フロントの CTA 分岐・型分岐が enum 追加で
 * silent に壊れるのを防ぐ)。抽出不能 (degenerate PASS) は fail させる。
 * 抽出ロジックは共有 helper (Tests\Support\TsUnionValues) に置き、
 * NotificationTypeTsSyncInvariantTest と共用する。
 */

/**
 * types/manual.ts から `export type {Name} = "a" | "b" | ...;` の値集合を抽出する。
 *
 * @return list<string>
 */
function extractTsUnionValues(string $typeName): array
{
    return TsUnionValues::extract('resources/js/types/manual.ts', $typeName);
}

test('RenderKind の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('RenderKind'))->toBe(TsUnionValues::enumStringValues(RenderKind::cases()));
});

test('RenderStep の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('RenderStep'))->toBe(TsUnionValues::enumStringValues(RenderStep::cases()));
});

test('RenderErrorCode の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('RenderErrorCode'))->toBe(TsUnionValues::enumStringValues(RenderErrorCode::cases()));
});

test('RenderConflictType の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('RenderConflictType'))->toBe(TsUnionValues::enumStringValues(RenderConflictType::cases()));
});

test('AnalysisJobStatus (JobStatus 共用) の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('AnalysisJobStatus'))->toBe(TsUnionValues::enumStringValues(JobStatus::cases()));
});

test('抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
    expect(fn (): array => extractTsUnionValues('NoSuchUnionName'))
        ->toThrow(RuntimeException::class, 'degenerate PASS');
});

/*
 * MaterialType の TS 側の写しは **2 ファイルにある** (PC 側 types/manual.ts の CutMaterialType /
 * 撮影 PWA 側 types/capture.ts の MaterialType)。2 つの types ファイルは
 * 「PC は署名 URL の口を持たない」という理由で意図的に分けてあり、片方が他方を import すると
 * その分離が崩れる。したがって**写しは 2 つ残し、両方を enum と突き合わせる**
 * (片方だけ pin すると drift が起きる)。
 */
test('CutMaterialType (types/manual.ts) の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(extractTsUnionValues('CutMaterialType'))->toBe(TsUnionValues::enumStringValues(MaterialType::cases()));
});

test('MaterialType (types/capture.ts) の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(TsUnionValues::extract('resources/js/types/capture.ts', 'MaterialType'))
        ->toBe(TsUnionValues::enumStringValues(MaterialType::cases()));
});

```

### tests/Feature/Projects/ProjectShowManualsTest.php L1-95 (現行の status フィルタテスト)
```php
<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Enums\ProjectRole;
use App\Models\Category;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\VideoManual;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Projects/Show に内包する動画マニュアル一覧 (manuals/categories/manualFilters props)。
 * GET クエリ (?category=&status=&q=) の絞り込みと paginate の shape を固定する。
 */

test('projects.show は manuals / categories / manualFilters を供給する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create(['name' => '準備作業']);
    VideoManual::factory()->forProject($project)->forCategory($category)->create(['title' => '分類済み']);
    VideoManual::factory()->forProject($project)->create(['title' => '未分類マニュアル']);

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Show')
            ->has('manuals.data', 2)
            ->has('manuals.meta', fn (Assert $meta) => $meta
                ->where('current_page', 1)
                ->where('last_page', 1)
                ->where('per_page', 10)
                ->where('total', 2))
            ->has('categories', 1)
            ->where('categories.0.name', '準備作業')
            ->where('manualFilters.category', null)
            ->where('manualFilters.status', null)
            ->where('manualFilters.q', null));
});

test('未分類 manual は category=null で返る (フロントは「未分類」を表示)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->create(['title' => '未分類マニュアル']);

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manuals.data.0.category', null)
            ->where('manuals.data.0.status', 'draft'));
});

test('category フィルタ (id / uncategorized sentinel) で絞り込める', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create();
    VideoManual::factory()->forProject($project)->forCategory($category)->create(['title' => '分類済み']);
    VideoManual::factory()->forProject($project)->create(['title' => '未分類マニュアル']);

    $this->actingAs($owner)->get("/projects/{$project->id}?category={$category->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '分類済み')
            ->where('manualFilters.category', (string) $category->id));

    $this->actingAs($owner)->get("/projects/{$project->id}?category=uncategorized")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '未分類マニュアル')
            ->where('manualFilters.category', 'uncategorized'));
});

test('status フィルタで絞り込める (不正値は無視)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->create(['title' => '下書き']);
    VideoManual::factory()->forProject($project)->create([
        'title' => '公開済み',
        'status' => VideoManualStatus::Published->value,
    ]);

    $this->actingAs($owner)->get("/projects/{$project->id}?status=published")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '公開済み')
            ->where('manualFilters.status', 'published'));

    // enum に無い値は無視 (全件)
    $this->actingAs($owner)->get("/projects/{$project->id}?status=bogus")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 2)
            ->where('manualFilters.status', null));
});

test('q フィルタは title 部分一致 (LIKE メタ文字はリテラル扱い)', function (): void {
```

### app/Http/Controllers/Capture/CaptureManualController.php L49-96 (撮影一覧の母集団)
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
