【アプリの使命 (North Star)】

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】

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


あなたは Laravel 12 + Svelte 5 (runes) + Inertia.js のコードレビュアーである。
以下の詳細設計書に基づく実装差分をレビューせよ。

## レビュー観点
1. **設計との一致性**: 詳細設計書の施策 A〜J が実装されているか。逸脱があるなら妥当か
2. **正確性**: 写像 (5 値 → 3 値) の漏れ・重複、絞り込みクエリ、Inertia props 契約の破壊的変更の追随漏れ
3. **PHPStan level 10 適合性**: 網羅 match、array shape PHPDoc、null 安全
4. **DTO / JsonResource パターン**: response()->json() の直書きが増えていないか
5. **テスト網羅性**: 各施策にテストがあるか。件数だけでなく対象を同定しているか。fail-first で赤を確認できる形か
6. **セキュリティ**: allowlist を通らない値が外へ出ていないか。認可・テナント境界に影響していないか
7. **DESIGN.md 準拠**: color / radius / typography は design token 経由か。hex 直書き (#RRGGBB) を増やしていないか。token 値を変える diff なら resources/css/tokens.css と同一 diff で同期しているか
8. **Atomic Design 準拠**: atoms → molecules → organisms → features/{domain} → templates → pages の単方向 import を逆流していないか。アイコンは @lucide/svelte のみで SVG 直書きを増やしていないか
9. **後方互換の並走を残していないか**: 旧 `?status=` / 旧 testId / 旧キーの残骸

## 出力形式
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で明示する


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
    倒れる方向は逆 (PC は「絞り込み無し = 全件」、PWA は「該当なし」) だが、
    **どちらも認可・テナント境界には影響しない既存仕様**である。
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
 * 撮影進捗の導出 (現行の三項式と**同一の判定**を名前付きにしたもの。判定は 1 ビットも変えない)。
 *
 * 判定順序の帰結を正確に書く:
 * - `cuts_total === 0 && cuts_with_takes === 0` → 未撮影 (カットが無い = 撮影の分母が無い)
 * - **`cuts_total === 0 && cuts_with_takes > 0` → 撮影中**。take は cut に属するため
 *   この組合せは構造上生じないが、生じた場合は 2 つ目の条件に掛かって「撮影中」になる。
 *   本施策は**表示語彙の整理であり判定の変更ではない**ので、この帰結もそのまま残す
 *   (直したくなったら別タスクとして根拠付きで起こすこと)。
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
      **`cuts_total=0 && cuts_with_takes>0` (構造上生じない不整合) は「撮影中」**
      (= 現行の三項式の帰結そのもの。挙動を変えていないことの証拠として固定する) /
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
    // 並び順への依存を避けるため **manual 1 本だけ**の fixture で契約を見る
    // (詳細レビュー Round 2 [Suggestion] 対応)
    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '下書き')
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
| `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` | リポジトリ規約上の完了条件 (本変更と直接の関係は薄いが、AGENTS.md の検証コマンド一覧は全 green でコミットと定めている) |

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


---

## 実装差分 (git diff HEAD)

```diff
diff --git a/app/DataTransferObjects/Capture/CaptureManualSummaryData.php b/app/DataTransferObjects/Capture/CaptureManualSummaryData.php
index 9a6faaf..4128777 100644
--- a/app/DataTransferObjects/Capture/CaptureManualSummaryData.php
+++ b/app/DataTransferObjects/Capture/CaptureManualSummaryData.php
@@ -12,13 +12,17 @@
  * 進捗カウント (cuts_total / cuts_adopted / cuts_with_takes) は withCount 済みモデルから読む。
  * creator は表示目的のみ (検索対象外)。User.name は CipherSweet PII のため whereBlind 検索の
  * 対象にはしない (自作フィルタは created_by の id 一致で行う)。
+ *
+ * **制作状態 (video_manuals.status) は載せない** (T197)。撮影 PWA が出す進捗バッジは
+ * カットの採用状況から導出する別の量 (types/capture.ts の captureProgressOf) であり、
+ * 制作状態は表示にも分岐にも使われていなかったため。撮影対象の母集団を
+ * ready / published に絞るのは CaptureManualController の責務で、こちらは変えていない。
  */
 final readonly class CaptureManualSummaryData
 {
     public function __construct(
         public int $id,
         public string $title,
-        public string $status,
         public ?int $categoryId,
         public ?string $categoryName,
         public int $cutsTotal,
@@ -44,7 +48,6 @@ public static function fromManual(VideoManual $manual): self
         return new self(
             id: $manual->id,
             title: $manual->title,
-            status: $manual->status->value,
             categoryId: $manual->category?->id,
             categoryName: $manual->category?->name,
             cutsTotal: $cutsTotal,
@@ -56,7 +59,7 @@ public static function fromManual(VideoManual $manual): self
     }
 
     /**
-     * @return array{id: int, title: string, status: string, category_id: int|null,
+     * @return array{id: int, title: string, category_id: int|null,
      *   category_name: string|null, cuts_total: int, cuts_adopted: int, cuts_with_takes: int,
      *   updated_at: string|null, creator_name: string|null}
      */
@@ -65,7 +68,6 @@ public function toArray(): array
         return [
             'id' => $this->id,
             'title' => $this->title,
-            'status' => $this->status,
             'category_id' => $this->categoryId,
             'category_name' => $this->categoryName,
             'cuts_total' => $this->cutsTotal,
diff --git a/app/DataTransferObjects/Manual/ManualListItemData.php b/app/DataTransferObjects/Manual/ManualListItemData.php
index 7d894fd..742bf1d 100644
--- a/app/DataTransferObjects/Manual/ManualListItemData.php
+++ b/app/DataTransferObjects/Manual/ManualListItemData.php
@@ -4,6 +4,7 @@
 
 namespace App\DataTransferObjects\Manual;
 
+use App\Enums\Manual\ManualProgress;
 use App\Enums\Manual\VideoManualStatus;
 use App\Models\VideoManual;
 use App\Services\Manual\CurrentRenderArtifact;
@@ -31,7 +32,11 @@
     public function __construct(
         public int $id,
         public string $title,
-        public VideoManualStatus $status,
+        /**
+         * 一覧の状態 (3 値)。**制作状態 5 値は一覧行に載せない** (行バッジ以外の用途が無く、
+         * 絞り込みと語彙が食い違うため。実況は詳細画面 / ダッシュボードの責務)
+         */
+        public ManualProgress $progress,
         public ?ManualListRefData $category,
         public ?ManualListRefData $creator,
         public string $createdAt,
@@ -63,7 +68,7 @@ public static function fromManual(VideoManual $manual, ManualRowAbilities $abili
         return new self(
             id: $manual->id,
             title: $manual->title,
-            status: $manual->status,
+            progress: ManualProgress::forStatus($manual->status),
             category: $category === null ? null : new ManualListRefData($category->id, $category->name),
             creator: $creator === null ? null : new ManualListRefData($creator->id, $creator->name),
             createdAt: $manual->created_at?->format('Y-m-d H:i') ?? '',
@@ -75,7 +80,7 @@ public static function fromManual(VideoManual $manual, ManualRowAbilities $abili
     }
 
     /**
-     * @return array{id: int, title: string, status: string,
+     * @return array{id: int, title: string, progress: string,
      *   category: array{id: int, name: string}|null,
      *   creator: array{id: int, name: string}|null,
      *   created_at: string, updated_at: string,
@@ -86,7 +91,7 @@ public function toArray(): array
         return [
             'id' => $this->id,
             'title' => $this->title,
-            'status' => $this->status->value,
+            'progress' => $this->progress->value,
             'category' => $this->category?->toArray(),
             'creator' => $this->creator?->toArray(),
             'created_at' => $this->createdAt,
diff --git a/app/DataTransferObjects/Manual/ManualListQuery.php b/app/DataTransferObjects/Manual/ManualListQuery.php
index 29cf97c..1c11d61 100644
--- a/app/DataTransferObjects/Manual/ManualListQuery.php
+++ b/app/DataTransferObjects/Manual/ManualListQuery.php
@@ -4,8 +4,8 @@
 
 namespace App\DataTransferObjects\Manual;
 
+use App\Enums\Manual\ManualProgress;
 use App\Enums\Manual\ManualSortOption;
-use App\Enums\Manual\VideoManualStatus;
 use Illuminate\Http\Request;
 
 /**
@@ -17,7 +17,10 @@
  *
  * 値の約束:
  * - `category`: 数値 id 文字列 | 'uncategorized' (未分類 sentinel) | null。それ以外は null
- * - `status`: VideoManualStatus の値のみ。それ以外は null
+ * - `progress`: ManualProgress の値のみ (not_started / in_progress / completed)。それ以外は null。
+ *   **旧 `?status=` (制作状態 5 値) は受け付けない**。値域が変わった時点で意味を保てないため、
+ *   互換の受理経路を残さない (思考原則 3)。旧 URL は未知キーとして無視され「すべて」になる
+ *   (allowlist 外は絞り込み無し = より広く当たる方向へ倒す、という本 VO の既定方針と一致)
  * - `keyword`: 前後の空白を除いた検索語。**先頭 MAX_KEYWORD_LENGTH 文字だけを使う (truncate)**。
  *   破棄 (= 絞り込み無し) にしないのは「全件が出る」驚きの方向へ倒れるためで、
  *   切り詰めは「より広く当たる」方向にしか倒れない。title の validation が max:200 なので、
@@ -37,7 +40,7 @@
 
     public function __construct(
         public ?string $category,
-        public ?string $status,
+        public ?ManualProgress $progress,
         public ?string $keyword,
         public ?ManualSortOption $sort,
         public bool $mine,
@@ -73,8 +76,9 @@ public static function fromRequest(Request $request): self
             $category = ctype_digit($category) ? (string) (int) $category : null;
         }
 
-        $status = $request->query('status');
-        $status = is_string($status) && VideoManualStatus::tryFrom($status) !== null ? $status : null;
+        // allowlist 外は null (= 既定「すべて」)。旧 `?status=` (5 値) は未知キーとして無視される
+        $progressRaw = $request->query('progress');
+        $progress = is_string($progressRaw) ? ManualProgress::tryFrom($progressRaw) : null;
 
         $keyword = $request->query('q');
         $keyword = is_string($keyword) && trim($keyword) !== ''
@@ -94,7 +98,7 @@ public static function fromRequest(Request $request): self
 
         return new self(
             category: $category,
-            status: $status,
+            progress: $progress,
             keyword: $keyword,
             sort: $sort,
             mine: $request->boolean('mine'), // "1"/"true" を bool 正規化
@@ -107,13 +111,13 @@ public static function fromRequest(Request $request): self
      * **page を含めない**: ページ位置は manuals.meta.current_page が唯一の正本である
      * (2 か所に持つと必ず食い違う)。
      *
-     * @return array{category: string|null, status: string|null, q: string|null, sort: string|null, mine: bool}
+     * @return array{category: string|null, progress: string|null, q: string|null, sort: string|null, mine: bool}
      */
     public function toProps(): array
     {
         return [
             'category' => $this->category,
-            'status' => $this->status,
+            'progress' => $this->progress?->value, // string|null (TS の ManualFilters.progress と一致)
             'q' => $this->keyword,
             'sort' => $this->sort?->value, // string|null (TS の ManualFilters.sort と一致)
             'mine' => $this->mine,
@@ -132,8 +136,8 @@ public function toQueryParams(): array
         if ($this->category !== null) {
             $params['category'] = $this->category;
         }
-        if ($this->status !== null) {
-            $params['status'] = $this->status;
+        if ($this->progress !== null) {
+            $params['progress'] = $this->progress->value;
         }
         if ($this->keyword !== null) {
             $params['q'] = $this->keyword;
diff --git a/app/Enums/Manual/ManualProgress.php b/app/Enums/Manual/ManualProgress.php
new file mode 100644
index 0000000..4dee675
--- /dev/null
+++ b/app/Enums/Manual/ManualProgress.php
@@ -0,0 +1,78 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Manual;
+
+/**
+ * 動画マニュアル一覧の状態語彙 (doc/04 §動画一覧ページ の 3 値: 作成済 / 作成中 / 未着手)。
+ *
+ * **制作状態 (VideoManualStatus, 5 値) → 一覧の状態 (3 値) の写像規則は本 enum の
+ * forStatus() ただ 1 か所にある**。逆写像 (statuses()) も同じ match から導出するため、
+ * 写像表が 2 か所に分かれることが構造的に起きない。
+ *
+ * 2 つの enum は**別の問いに答える**:
+ * - VideoManualStatus = いま何をしているか (制作パイプラインの進行状態。詳細画面 /
+ *   ダッシュボードが実況に使う。数十秒で遷移する短命な値を含む)
+ * - ManualProgress = 仕上がっているか (一覧の絞り込みと行バッジ。ポーリングしない面で使う)
+ *
+ * 撮影 PWA の撮影進捗 (types/capture.ts の CaptureProgress) とは**別の量**である
+ * (あちらは 1 本のマニュアルのカット採用状況の導出であり、本 enum とは母集団も更新契機も違う)。
+ * 語が似ているという理由で統合しないこと。
+ */
+enum ManualProgress: string
+{
+    case NotStarted = 'not_started';
+    case InProgress = 'in_progress';
+    case Completed = 'completed';
+
+    /**
+     * 制作状態 → 一覧の状態の写像 (**唯一の写像規則**)。
+     *
+     * - Draft: シナリオ (cuts) が未確定。解析が失敗しても cuts が無ければ Draft へ戻る
+     *   (AnalysisJobService::failJob) ため「未着手」と一致する
+     * - Analyzing / Ready / Rendering: シナリオはあるが完成動画が無い = 作成中
+     * - Published: 現行世代の完成動画がある。シナリオを保存すると Ready へ戻る
+     *   (ScenarioService) ので「作成済」の意味と一致する
+     *
+     * default を持たない網羅 match なので、VideoManualStatus に case を足すと
+     * PHPStan level 10 が未処理の case として落とす (無音の drift を作らない)。
+     */
+    public static function forStatus(VideoManualStatus $status): self
+    {
+        return match ($status) {
+            VideoManualStatus::Draft => self::NotStarted,
+            VideoManualStatus::Analyzing,
+            VideoManualStatus::Ready,
+            VideoManualStatus::Rendering => self::InProgress,
+            VideoManualStatus::Published => self::Completed,
+        };
+    }
+
+    /**
+     * この値に写る制作状態の集合 (forStatus からの導出。**逆写像表を別に持たない**)。
+     *
+     * @return list<VideoManualStatus>
+     */
+    public function statuses(): array
+    {
+        return array_values(array_filter(
+            VideoManualStatus::cases(),
+            fn (VideoManualStatus $status): bool => self::forStatus($status) === $this,
+        ));
+    }
+
+    /**
+     * 一覧の WHERE へ渡す DB 値。**型 (enum) と SQL (文字列) の境界をここで閉じる**
+     * (binding 側の暗黙変換に依存しない)。
+     *
+     * @return list<string>
+     */
+    public function statusValues(): array
+    {
+        return array_map(
+            static fn (VideoManualStatus $status): string => $status->value,
+            $this->statuses(),
+        );
+    }
+}
diff --git a/app/Http/Controllers/Projects/ProjectController.php b/app/Http/Controllers/Projects/ProjectController.php
index e515cb4..8704a8b 100644
--- a/app/Http/Controllers/Projects/ProjectController.php
+++ b/app/Http/Controllers/Projects/ProjectController.php
@@ -149,7 +149,7 @@ public function show(Request $request, Project $project, SeoManager $seo): Respo
      * creator は退会/削除で解決不可のとき null (実運用では FK RESTRICT で常に解決)。
      *
      * @return array{
-     *   data: list<array{id: int, title: string, status: string,
+     *   data: list<array{id: int, title: string, progress: string,
      *     category: array{id: int, name: string}|null,
      *     creator: array{id: int, name: string}|null,
      *     created_at: string, updated_at: string,
@@ -178,8 +178,9 @@ private function manualRows(Project $project, ManualListQuery $listQuery, User $
         } elseif ($listQuery->category !== null) {
             $baseQuery->where('category_id', (int) $listQuery->category);
         }
-        if ($listQuery->status !== null) {
-            $baseQuery->where('status', $listQuery->status);
+        if ($listQuery->progress !== null) {
+            // 3 値 → 制作状態の集合は ManualProgress が唯一の正本 (ここに写像表を書かない)
+            $baseQuery->whereIn('status', $listQuery->progress->statusValues());
         }
         if ($listQuery->keyword !== null) {
             // LIKE メタ文字 (%/_/\) はリテラル検索として扱う
@@ -188,7 +189,10 @@ private function manualRows(Project $project, ManualListQuery $listQuery, User $
 
         $paginated = (clone $baseQuery)
             ->paginate(perPage: ManualListQuery::PER_PAGE, page: $listQuery->page)
-            ->withQueryString();
+            // 生クエリをそのまま拾う withQueryString ではなく、**allowlist を通った値だけ**を載せる
+            // (未知キー・旧 `?status=` を paginator の query に持ち込まない)。
+            // `page` は AbstractPaginator::appends() が pageName として除外するため衝突しない
+            ->appends($listQuery->toQueryParams());
 
         // 範囲外ページ (行内削除で件数が減った / 古いブックマーク) は最終ページへ丸める。
         // 「空の一覧」に着地させない (行き先のない詰みを作らない)。
@@ -199,7 +203,7 @@ private function manualRows(Project $project, ManualListQuery $listQuery, User $
         if ($paginated->currentPage() > $paginated->lastPage()) {
             $paginated = (clone $baseQuery)
                 ->paginate(perPage: ManualListQuery::PER_PAGE, page: $paginated->lastPage())
-                ->withQueryString();
+                ->appends($listQuery->toQueryParams());
         }
 
         /** @var list<VideoManual> $manuals */
diff --git "a/doc/02_\343\202\267\343\202\271\343\203\206\343\203\240\345\205\250\344\275\223\345\203\217.md" "b/doc/02_\343\202\267\343\202\271\343\203\206\343\203\240\345\205\250\344\275\223\345\203\217.md"
index d49f000..5e9c47a 100644
--- "a/doc/02_\343\202\267\343\202\271\343\203\206\343\203\240\345\205\250\344\275\223\345\203\217.md"
+++ "b/doc/02_\343\202\267\343\202\271\343\203\206\343\203\240\345\205\250\344\275\223\345\203\217.md"
@@ -77,7 +77,10 @@ ### ユーザー
 `ユーザーID`（半角英数 1〜20 字, ユニーク）/ `パスワード`（8〜64 字）/ `表示名`（1〜50 字）/ `メールアドレス`（任意, 形式チェック）/ `所属ID` / `権限`（管理者・一般利用者）/ `作成日時` / `最終ログイン日時`
 
 ### 動画マニュアル
-`動画マニュアルID` / `動画タイトル`（1〜200 字）/ `カテゴリID` / `作成者ID` / `作成日時` / `更新日時` / `動画の尺`（自動計算）/ `手順数` / `状態`（撮影完了・着手中・未着手）/ `公開範囲`（作成者のみ・同じ所属・全ユーザー）/ `登録データ容量`
+`動画マニュアルID` / `動画タイトル`（1〜200 字）/ `カテゴリID` / `作成者ID` / `作成日時` / `更新日時` / `動画の尺`（自動計算）/ `手順数` / `状態`（作成済・作成中・未着手）/ `公開範囲`（作成者のみ・同じ所属・全ユーザー）/ `登録データ容量`
+
+※ `状態` の 3 値は doc/04 §動画一覧ページ と同じ語彙である。撮影 PWA が出す「撮影完了 / 撮影中 / 未撮影」は
+本項目とは**別の量**（1 本のマニュアルのカット撮影進捗）なので混同しないこと。
 
 ### カテゴリ
 `カテゴリID` / `カテゴリ名`（1〜50 字, ユニーク）/ `表示順`（D&D で変更）/ `作成日時` / `更新日時` / `含まれる動画数`（削除時、所属動画は「未分類」へ）
diff --git "a/doc/04_PC\343\202\265\343\202\244\343\203\210\346\251\237\350\203\275\344\273\225\346\247\230.md" "b/doc/04_PC\343\202\265\343\202\244\343\203\210\346\251\237\350\203\275\344\273\225\346\247\230.md"
index 72bdf3f..d99af10 100644
--- "a/doc/04_PC\343\202\265\343\202\244\343\203\210\346\251\237\350\203\275\344\273\225\346\247\230.md"
+++ "b/doc/04_PC\343\202\265\343\202\244\343\203\210\346\251\237\350\203\275\344\273\225\346\247\230.md"
@@ -25,6 +25,8 @@ ### ログイン / パスワード再設定
 ### 動画一覧ページ（ホーム）
 - 動画リスト（No / 状態 / タイトル / カテゴリ / 再生時間 / 更新日 / DL / 削除）を表示。
 - **絞り込み**: カテゴリ / 「自分が作成したタイトルのみ」/ 状態（作成済・作成中・未着手）。
+  実装の制作状態 5 値（draft/analyzing/ready/rendering/published）からの写像は
+  `App\Enums\Manual\ManualProgress` が正本（写像表はここに書かない）。URL クエリは `?progress=`。
 - **検索**: タイトル・作成者名などのキーワード。**並べ替え**: 更新日・タイトルで昇順/降順。
 - **操作**: プレビュー（オーバーレイ、再生/停止/音量/言語切替）、ダウンロード（言語選択して mp4）、削除（確認ダイアログ）、新規動画作成、編集画面遷移、ページネーション。
 - 管理者ログイン時のみサイドバーに「カテゴリ管理」「ユーザー管理」を表示。ユーザー名メニューからログアウト。
diff --git a/resources/js/components/features/manual/ManualListRow.svelte b/resources/js/components/features/manual/ManualListRow.svelte
index fbcdec5..5172f52 100644
--- a/resources/js/components/features/manual/ManualListRow.svelte
+++ b/resources/js/components/features/manual/ManualListRow.svelte
@@ -5,7 +5,7 @@
     import TextLink from "@/components/atoms/TextLink.svelte";
     import { formatDurationMs } from "@/lib/manual/format-duration";
     import type { ManualListItem } from "@/types/manual";
-    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";
+    import { MANUAL_PROGRESS_LABELS, MANUAL_PROGRESS_TONES } from "@/types/manual";
 
     /**
      * 動画マニュアル一覧の 1 行 (doc/04: 状態 / タイトル / カテゴリ / 再生時間 / 更新日 /
@@ -60,8 +60,10 @@
         >
             {durationLabel}
         </span>
-        <Badge tone={STATUS_TONES[manual.status]} testId={`manual-status-${manual.id}`}>
-            {VIDEO_MANUAL_STATUS_LABELS[manual.status]}
+        <!-- 一覧の状態は 3 値 (絞り込みと同じ語彙でないと絞り込み結果を説明できない)。
+             「解析中 / 書き出し中」の実況は詳細画面 (AnalysisPanel / RenderPanel) が持つ -->
+        <Badge tone={MANUAL_PROGRESS_TONES[manual.progress]} testId={`manual-progress-${manual.id}`}>
+            {MANUAL_PROGRESS_LABELS[manual.progress]}
         </Badge>
         <!-- 受け取れるとサーバが判断した行にだけ出す。押せない (disabled) ボタンは作らない。
              出ていない行の理由は状態バッジと再生時間「—」が語り、書き出しの CTA は
diff --git a/resources/js/pages/Capture/Index.svelte b/resources/js/pages/Capture/Index.svelte
index 3f27b52..d08f7f4 100644
--- a/resources/js/pages/Capture/Index.svelte
+++ b/resources/js/pages/Capture/Index.svelte
@@ -14,6 +14,11 @@
     import { formatDate } from "@/lib/date-format";
     import type { SharedProps } from "@/lib/shared-props";
     import type { CaptureManualSummary } from "@/types/capture";
+    import {
+        CAPTURE_PROGRESS_LABELS,
+        CAPTURE_PROGRESS_TONES,
+        captureProgressOf,
+    } from "@/types/capture";
 
     /**
      * 撮影 PWA: シナリオ (動画マニュアル) 一覧。カテゴリ / キーワードで絞り込み、
@@ -104,6 +109,9 @@
                     />
                 {/if}
                 {#each manuals as manual (manual.id)}
+                    <!-- 撮影進捗は PC 一覧の制作状態 (ManualProgress) とは別の量。
+                         導出は captureProgressOf ただ 1 か所に置く -->
+                    {@const captureProgress = captureProgressOf(manual)}
                     <a href={`/app/projects/${project.id}/manuals/${manual.id}`} class="block">
                         <Card>
                             <div class="flex items-center justify-between gap-3">
@@ -120,13 +128,9 @@
                                     </p>
                                 </div>
                                 <div class="shrink-0">
-                                    {#if manual.cuts_total > 0 && manual.cuts_adopted === manual.cuts_total}
-                                        <Badge tone="success">撮影完了</Badge>
-                                    {:else if manual.cuts_with_takes > 0}
-                                        <Badge tone="tertiary">撮影中</Badge>
-                                    {:else}
-                                        <Badge tone="neutral">未撮影</Badge>
-                                    {/if}
+                                    <Badge tone={CAPTURE_PROGRESS_TONES[captureProgress]}>
+                                        {CAPTURE_PROGRESS_LABELS[captureProgress]}
+                                    </Badge>
                                 </div>
                             </div>
                         </Card>
diff --git a/resources/js/pages/Projects/Show.svelte b/resources/js/pages/Projects/Show.svelte
index 1e9220e..f19e3c5 100644
--- a/resources/js/pages/Projects/Show.svelte
+++ b/resources/js/pages/Projects/Show.svelte
@@ -26,9 +26,10 @@
         CategoryOption,
         ManualFilters,
         ManualListItem,
+        ManualProgress,
         PaginationMeta,
     } from "@/types/manual";
-    import { VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";
+    import { MANUAL_PROGRESS_LABELS } from "@/types/manual";
 
     /**
      * プロジェクト詳細。動画マニュアル一覧 (フィルタ + paginate)・カテゴリ管理・
@@ -88,7 +89,9 @@
 
     /* ---- 動画マニュアル: フィルタ (GET クエリで manuals のみ部分更新) ---- */
     let filterCategory = $state(manualFilters.category ?? "");
-    let filterStatus = $state(manualFilters.status ?? "");
+    // 状態の絞り込み (doc/04 の 3 値)。制作状態 5 値では絞らない。
+    // "" = すべて。union で受けることで select の値が型で閉じる
+    let filterProgress = $state<ManualProgress | "">(manualFilters.progress ?? "");
     let filterQ = $state(manualFilters.q ?? "");
     let filterSort = $state<string>(manualFilters.sort ?? "");
     let filterMine = $state(manualFilters.mine);
@@ -105,7 +108,7 @@
     function manualQuery(pageNumber?: number): Record<string, string | number> {
         const query: Record<string, string | number> = {};
         if (filterCategory !== "") query.category = filterCategory;
-        if (filterStatus !== "") query.status = filterStatus;
+        if (filterProgress !== "") query.progress = filterProgress;
         if (filterQ.trim() !== "") query.q = filterQ.trim();
         if (filterSort !== "") query.sort = filterSort;
         if (filterMine) query.mine = 1;
@@ -420,17 +423,17 @@
                         </Select>
                     </div>
                     <div class="flex flex-col gap-1">
-                        <label class="text-caption text-text-secondary" for="manual-filter-status">
+                        <label class="text-caption text-text-secondary" for="manual-filter-progress">
                             状態
                         </label>
                         <Select
-                            id="manual-filter-status"
-                            bind:value={filterStatus}
+                            id="manual-filter-progress"
+                            bind:value={filterProgress}
                             onchange={() => applyManualFilters()}
-                            testId="manual-filter-status"
+                            testId="manual-filter-progress"
                         >
                             <option value="">すべて</option>
-                            {#each Object.entries(VIDEO_MANUAL_STATUS_LABELS) as [value, label] (value)}
+                            {#each Object.entries(MANUAL_PROGRESS_LABELS) as [value, label] (value)}
                                 <option {value}>{label}</option>
                             {/each}
                         </Select>
diff --git a/resources/js/types/capture.ts b/resources/js/types/capture.ts
index b12a756..627e798 100644
--- a/resources/js/types/capture.ts
+++ b/resources/js/types/capture.ts
@@ -3,6 +3,8 @@
  * (キー集合の契約は tests/Feature/Capture/CaptureManualBrowsingTest が固定する)。
  */
 
+import type { BadgeTone } from "@/components/atoms/Badge.types";
+
 export type TakeStatus = "uploading" | "processing" | "ready" | "failed";
 
 /** PHP: App\Enums\Manual\MaterialType と値集合を一致させる */
@@ -60,7 +62,6 @@ export interface CaptureManualDetail {
 export interface CaptureManualSummary {
     id: number;
     title: string;
-    status: string;
     category_id: number | null;
     category_name: string | null;
     cuts_total: number;
@@ -71,6 +72,44 @@ export interface CaptureManualSummary {
     creator_name: string | null;
 }
 
+/**
+ * 撮影進捗 (この 1 本のマニュアルの撮影がどこまで進んだか)。
+ * **PC 一覧の ManualProgress (制作の到達段階) とは別の量である** —
+ * 導出元 (カットの採用状況 vs video_manuals.status)、更新契機、値の動きが独立している
+ * (例: 制作は「作成中」でも撮影は「撮影完了」は正常な組合せ)。語が似ていても統合しないこと。
+ */
+export type CaptureProgress = "captured" | "capturing" | "not_captured";
+
+export const CAPTURE_PROGRESS_LABELS = {
+    captured: "撮影完了",
+    capturing: "撮影中",
+    not_captured: "未撮影",
+} as const satisfies Record<CaptureProgress, string>;
+
+export const CAPTURE_PROGRESS_TONES = {
+    captured: "success",
+    capturing: "tertiary",
+    not_captured: "neutral",
+} as const satisfies Record<CaptureProgress, BadgeTone>;
+
+/**
+ * 撮影進捗の導出 (現行の三項式と**同一の判定**を名前付きにしたもの。判定は 1 ビットも変えない)。
+ *
+ * 判定順序の帰結を正確に書く:
+ * - `cuts_total === 0 && cuts_with_takes === 0` → 未撮影 (カットが無い = 撮影の分母が無い)
+ * - **`cuts_total === 0 && cuts_with_takes > 0` → 撮影中**。take は cut に属するため
+ *   この組合せは構造上生じないが、生じた場合は 2 つ目の条件に掛かって「撮影中」になる。
+ *   本施策は**表示語彙の整理であり判定の変更ではない**ので、この帰結もそのまま残す
+ *   (直したくなったら別タスクとして根拠付きで起こすこと)。
+ */
+export function captureProgressOf(
+    summary: Pick<CaptureManualSummary, "cuts_total" | "cuts_adopted" | "cuts_with_takes">,
+): CaptureProgress {
+    if (summary.cuts_total > 0 && summary.cuts_adopted === summary.cuts_total) return "captured";
+    if (summary.cuts_with_takes > 0) return "capturing";
+    return "not_captured";
+}
+
 /** POST .../takes/upload-url の応答 (TakeUploadTicketResource と対) */
 export interface UploadTicket {
     upload_url: string;
diff --git a/resources/js/types/manual.ts b/resources/js/types/manual.ts
index 20e73ef..e0b6d3b 100644
--- a/resources/js/types/manual.ts
+++ b/resources/js/types/manual.ts
@@ -2,14 +2,25 @@
  * 動画マニュアル (VideoManual) / カテゴリ関連の Inertia props 型。
  * PHP 側の typed array PHPDoc (ProjectController::manualRows 等) と対で保守する。
  * status は PHP enum App\Enums\Manual\VideoManualStatus と値集合を一致させる
- * (literal union で UI 分岐漏れを検出する。乖離検知は当面手動確認)。
+ * (literal union で UI 分岐漏れを検出する)。**乖離検知の正本は
+ * tests/Architecture/ManualEnumTsSyncInvariantTest.php** (VideoManualStatus /
+ * ManualProgress を含む値集合同期テスト) であり、手動確認ではない。
  */
 
 import type { BadgeTone } from "@/components/atoms/Badge.types";
 
 export type VideoManualStatus = "draft" | "analyzing" | "ready" | "rendering" | "published";
 
-/** VideoManualStatus の表示ラベル (UI 共通) */
+/**
+ * VideoManualStatus の**表示ラベル**。
+ * **一覧 (Projects/Show の行バッジと絞り込み) では使わない** — 一覧はポーリングせず、
+ * 短命な遷移状態を出すと再読込まで嘘になるため。一覧は MANUAL_PROGRESS_LABELS を使う。
+ * 実況する面 (詳細画面 Manuals/Show / ダッシュボード) では引き続きこれを使う。
+ *
+ * 注: 制限しているのは**表示語彙 (このラベル表とトーン表) の使用面**だけである。
+ * 5 値の型そのものを使う判定 (CAPTURE_NAVIGABLE_BY_STATUS / SCENARIO_ESTABLISHED_BY_STATUS /
+ * SCENARIO_ANALYZABLE_BY_STATUS など) は正当な用途であり、本制限の対象外。
+ */
 export const VIDEO_MANUAL_STATUS_LABELS: Record<VideoManualStatus, string> = {
     draft: "下書き",
     analyzing: "解析中",
@@ -19,7 +30,7 @@ export const VIDEO_MANUAL_STATUS_LABELS: Record<VideoManualStatus, string> = {
 };
 
 /**
- * 状態バッジの tone (結果表示の意味色。UI 共通)。
+ * 状態バッジの tone (結果表示の意味色。**実況する面**で使う。一覧は MANUAL_PROGRESS_TONES)。
  * satisfies でキー漏れ (status 追加時) をコンパイル時検出する。
  */
 export const STATUS_TONES = {
@@ -30,6 +41,27 @@ export const STATUS_TONES = {
     published: "primary",
 } as const satisfies Record<VideoManualStatus, BadgeTone>;
 
+/**
+ * PHP App\Enums\Manual\ManualProgress と値集合を一致させる (doc/04 の 3 値)。
+ * 5 値 → 3 値の**写像規則は PHP 側 ManualProgress::forStatus() だけが持つ**。
+ * TS 側は写像を書かず、サーバが決めた値を表示するだけである (2 か所に写像を持たない)。
+ */
+export type ManualProgress = "not_started" | "in_progress" | "completed";
+
+/** 一覧の状態ラベル (doc/04 の語)。satisfies でキー漏れをコンパイル時検出する */
+export const MANUAL_PROGRESS_LABELS = {
+    not_started: "未着手",
+    in_progress: "作成中",
+    completed: "作成済",
+} as const satisfies Record<ManualProgress, string>;
+
+/** 一覧の状態バッジの tone (結果表示の意味色) */
+export const MANUAL_PROGRESS_TONES = {
+    not_started: "neutral",
+    in_progress: "tertiary",
+    completed: "success",
+} as const satisfies Record<ManualProgress, BadgeTone>;
+
 /**
  * 撮影ナビ (capture.manuals.show) へ導線を出してよい状態か。
  * 撮影ナビ一覧 (CaptureManualController::index) が列挙する ready/published と一致させる
@@ -103,7 +135,8 @@ export type ManualSortOption = "updated_desc" | "updated_asc" | "title_asc" | "t
 export interface ManualListItem {
     id: number;
     title: string;
-    status: VideoManualStatus;
+    /** 一覧の状態 (3 値)。サーバが写像済みの値であり、UI 側で再写像しない */
+    progress: ManualProgress;
     /** null = 未分類 */
     category: { id: number; name: string } | null;
     /** 作成者。退会/削除で解決不可のときは null (UI は「不明」) */
@@ -139,7 +172,8 @@ export interface CategoryOption {
 /** 一覧絞り込み条件 (GET クエリ)。category は id 文字列 | "uncategorized" | null */
 export interface ManualFilters {
     category: string | null;
-    status: string | null;
+    /** 状態の絞り込み (3 値)。null = すべて。旧 `status` (5 値) は廃止 */
+    progress: ManualProgress | null;
     q: string | null;
     /** 並べ替え。null = 既定 (作成日降順) */
     sort: ManualSortOption | null;
diff --git a/tests/Architecture/ManualEnumTsSyncInvariantTest.php b/tests/Architecture/ManualEnumTsSyncInvariantTest.php
index e0e2c16..9701f13 100644
--- a/tests/Architecture/ManualEnumTsSyncInvariantTest.php
+++ b/tests/Architecture/ManualEnumTsSyncInvariantTest.php
@@ -3,11 +3,13 @@
 declare(strict_types=1);
 
 use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\ManualProgress;
 use App\Enums\Manual\MaterialType;
 use App\Enums\Manual\RenderConflictType;
 use App\Enums\Manual\RenderErrorCode;
 use App\Enums\Manual\RenderKind;
 use App\Enums\Manual\RenderStep;
+use App\Enums\Manual\VideoManualStatus;
 use Tests\Support\TsUnionValues;
 
 /*
@@ -30,6 +32,16 @@ function extractTsUnionValues(string $typeName): array
     return TsUnionValues::extract('resources/js/types/manual.ts', $typeName);
 }
 
+test('VideoManualStatus の PHP enum ⇔ TS union 値集合が一致する', function (): void {
+    expect(extractTsUnionValues('VideoManualStatus'))
+        ->toBe(TsUnionValues::enumStringValues(VideoManualStatus::cases()));
+});
+
+test('ManualProgress の PHP enum ⇔ TS union 値集合が一致する', function (): void {
+    expect(extractTsUnionValues('ManualProgress'))
+        ->toBe(TsUnionValues::enumStringValues(ManualProgress::cases()));
+});
+
 test('RenderKind の PHP enum ⇔ TS union 値集合が一致する', function (): void {
     expect(extractTsUnionValues('RenderKind'))->toBe(TsUnionValues::enumStringValues(RenderKind::cases()));
 });
diff --git a/tests/Feature/Capture/CaptureManualBrowsingTest.php b/tests/Feature/Capture/CaptureManualBrowsingTest.php
index a2680bd..baa12f6 100644
--- a/tests/Feature/Capture/CaptureManualBrowsingTest.php
+++ b/tests/Feature/Capture/CaptureManualBrowsingTest.php
@@ -123,8 +123,9 @@ function browsingContext(): array
 
     $summary = $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals")
         ->inertiaPage()['props']['manuals'][0];
+    // 制作状態 (status) は載せない (T197: 撮影 PWA の進捗はカットの採用状況から導出する別の量)
     expect(array_keys($summary))->toBe([
-        'id', 'title', 'status', 'category_id', 'category_name',
+        'id', 'title', 'category_id', 'category_name',
         'cuts_total', 'cuts_adopted', 'cuts_with_takes', 'updated_at', 'creator_name',
     ]);
 });
diff --git a/tests/Feature/Projects/ManualRowActionsTest.php b/tests/Feature/Projects/ManualRowActionsTest.php
index a64e7a0..9d571ed 100644
--- a/tests/Feature/Projects/ManualRowActionsTest.php
+++ b/tests/Feature/Projects/ManualRowActionsTest.php
@@ -21,14 +21,14 @@
     $category = Category::factory()->forProject($project)->create();
     $manual = VideoManual::factory()->forProject($project)->forCategory($category)->create();
 
-    $query = "category={$category->id}&status=published&q=".urlencode('ネジ')
+    $query = "category={$category->id}&progress=completed&q=".urlencode('ネジ')
         .'&sort=title_asc&mine=1&page=2';
 
     $response = $this->actingAs($owner)
         ->delete("/projects/{$project->id}/manuals/{$manual->id}?{$query}");
 
     $response->assertRedirect(
-        "/projects/{$project->id}?category={$category->id}&status=published&q=".urlencode('ネジ')
+        "/projects/{$project->id}?category={$category->id}&progress=completed&q=".urlencode('ネジ')
         .'&sort=title_asc&mine=1&page=2'
     );
     $response->assertSessionHas('success');
@@ -50,8 +50,9 @@
     $manual = VideoManual::factory()->forProject($project)->create();
 
     $this->actingAs($owner)
+        // 旧 `?status=` (制作状態 5 値) も allowlist 外なので着地先には載らない (互換を残さない)
         ->delete("/projects/{$project->id}/manuals/{$manual->id}?sort=".urlencode(';DROP')
-            .'&category=abc&status=bogus')
+            .'&category=abc&progress=bogus&status=published')
         ->assertRedirect("/projects/{$project->id}");
 });
 
diff --git a/tests/Feature/Projects/ProjectShowManualsTest.php b/tests/Feature/Projects/ProjectShowManualsTest.php
index 083e1d4..11109f2 100644
--- a/tests/Feature/Projects/ProjectShowManualsTest.php
+++ b/tests/Feature/Projects/ProjectShowManualsTest.php
@@ -12,7 +12,8 @@
 
 /*
  * Projects/Show に内包する動画マニュアル一覧 (manuals/categories/manualFilters props)。
- * GET クエリ (?category=&status=&q=) の絞り込みと paginate の shape を固定する。
+ * GET クエリ (?category=&progress=&q=) の絞り込みと paginate の shape を固定する。
+ * 状態の語彙は一覧の 3 値 (ManualProgress)。制作状態 5 値は行にも絞り込みにも出さない (T197)。
  */
 
 test('projects.show は manuals / categories / manualFilters を供給する', function (): void {
@@ -35,7 +36,7 @@
             ->has('categories', 1)
             ->where('categories.0.name', '準備作業')
             ->where('manualFilters.category', null)
-            ->where('manualFilters.status', null)
+            ->where('manualFilters.progress', null)
             ->where('manualFilters.q', null));
 });
 
@@ -47,7 +48,7 @@
     $this->actingAs($owner)->get("/projects/{$project->id}")
         ->assertInertia(fn (Assert $page) => $page
             ->where('manuals.data.0.category', null)
-            ->where('manuals.data.0.status', 'draft'));
+            ->where('manuals.data.0.progress', 'not_started'));
 });
 
 test('category フィルタ (id / uncategorized sentinel) で絞り込める', function (): void {
@@ -70,26 +71,94 @@
             ->where('manualFilters.category', 'uncategorized'));
 });
 
-test('status フィルタで絞り込める (不正値は無視)', function (): void {
+/**
+ * 制作状態 5 値をそれぞれ 1 本ずつ持つ一覧を作る (T197 の写像を Inertia payload で見るための fixture)。
+ * title は status ごとに固有にする (件数だけの assertion にしない = 対象を同定する)。
+ */
+function seedManualsForEachStatus(Project $project): void
+{
+    foreach ([
+        '下書き' => VideoManualStatus::Draft,
+        '解析中' => VideoManualStatus::Analyzing,
+        '準備完了' => VideoManualStatus::Ready,
+        '書き出し中' => VideoManualStatus::Rendering,
+        '公開済み' => VideoManualStatus::Published,
+    ] as $title => $status) {
+        VideoManual::factory()->forProject($project)->create([
+            'title' => $title,
+            'status' => $status->value,
+        ]);
+    }
+}
+
+test('progress=in_progress は analyzing / ready / rendering の 3 件を返す', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     $project = Project::factory()->forOrganization($organization)->create();
-    VideoManual::factory()->forProject($project)->create(['title' => '下書き']);
-    VideoManual::factory()->forProject($project)->create([
-        'title' => '公開済み',
-        'status' => VideoManualStatus::Published->value,
-    ]);
+    seedManualsForEachStatus($project);
 
-    $this->actingAs($owner)->get("/projects/{$project->id}?status=published")
+    $response = $this->actingAs($owner)->get("/projects/{$project->id}?progress=in_progress");
+
+    $response->assertInertia(fn (Assert $page) => $page
+        ->has('manuals.data', 3)
+        ->where('manualFilters.progress', 'in_progress'));
+
+    // 対象の同定は title の集合で行う (件数一致だけに頼らない)
+    $titles = array_column($response->inertiaPage()['props']['manuals']['data'], 'title');
+    sort($titles);
+    expect($titles)->toBe(['書き出し中', '準備完了', '解析中']);
+});
+
+test('progress=not_started は draft のみ / progress=completed は published のみ', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    seedManualsForEachStatus($project);
+
+    $this->actingAs($owner)->get("/projects/{$project->id}?progress=not_started")
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 1)
+            ->where('manuals.data.0.title', '下書き')
+            ->where('manuals.data.0.progress', 'not_started'));
+
+    $this->actingAs($owner)->get("/projects/{$project->id}?progress=completed")
         ->assertInertia(fn (Assert $page) => $page
             ->has('manuals.data', 1)
             ->where('manuals.data.0.title', '公開済み')
-            ->where('manualFilters.status', 'published'));
+            ->where('manuals.data.0.progress', 'completed'));
+});
 
-    // enum に無い値は無視 (全件)
-    $this->actingAs($owner)->get("/projects/{$project->id}?status=bogus")
+test('allowlist 外の値と旧 ?status= は無視して全件になる (互換は残さない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    seedManualsForEachStatus($project);
+
+    // 旧 5 値をそのまま渡しても progress の allowlist は通らない
+    $this->actingAs($owner)->get("/projects/{$project->id}?progress=ready")
         ->assertInertia(fn (Assert $page) => $page
-            ->has('manuals.data', 2)
-            ->where('manualFilters.status', null));
+            ->has('manuals.data', 5)
+            ->where('manualFilters.progress', null));
+
+    // **旧 URL の互換は無い** (?status=published は未知キーとして無視される)
+    $this->actingAs($owner)->get("/projects/{$project->id}?status=published")
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 5)
+            ->where('manualFilters.progress', null)
+            ->missing('manualFilters.status'));
+});
+
+test('行 payload は progress を持ち status を持たない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    // 並び順への依存を避けるため manual 1 本だけの fixture で契約を見る
+    VideoManual::factory()->forProject($project)->create(['title' => '下書き']);
+
+    $this->actingAs($owner)->get("/projects/{$project->id}")
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 1)
+            ->where('manuals.data.0.title', '下書き')
+            ->where('manuals.data.0.progress', 'not_started')
+            ->missing('manuals.data.0.status')
+            // paginator の query が外に出ないことの構造的確認 (links を props に出していない)
+            ->missing('manuals.links'));
 });
 
 test('q フィルタは title 部分一致 (LIKE メタ文字はリテラル扱い)', function (): void {
@@ -247,7 +316,7 @@
             ->where('manualFilters.mine', true));
 });
 
-test('mine と category/status/q/sort の併用で結合絞り込みできる', function (): void {
+test('mine と category/progress/q/sort の併用で結合絞り込みできる', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     $other = attachOrganizationMember($organization);
     $project = Project::factory()->forOrganization($organization)->create();
@@ -268,7 +337,7 @@
     ]);
 
     $this->actingAs($owner)
-        ->get("/projects/{$project->id}?mine=1&category={$category->id}&status=published&q=ネジ&sort=updated_desc")
+        ->get("/projects/{$project->id}?mine=1&category={$category->id}&progress=completed&q=ネジ&sort=updated_desc")
         ->assertInertia(fn (Assert $page) => $page
             ->has('manuals.data', 1)
             ->where('manuals.data.0.id', $target->id));
diff --git a/tests/Unit/Manual/ManualProgressMappingTest.php b/tests/Unit/Manual/ManualProgressMappingTest.php
new file mode 100644
index 0000000..6289a7b
--- /dev/null
+++ b/tests/Unit/Manual/ManualProgressMappingTest.php
@@ -0,0 +1,48 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\ManualProgress;
+use App\Enums\Manual\VideoManualStatus;
+
+/*
+ * T197: 制作状態 5 値 → 一覧の状態 3 値の写像 (ManualProgress) の正本を固定する。
+ *
+ * DB を使わない純粋 enum テストなので Unit レーンに置く。
+ * Inertia payload / 絞り込み挙動は Feature (ProjectShowManualsTest) が持つ。
+ */
+
+test('制作状態 5 値が一覧の状態 3 値へ写る (写像表)', function (): void {
+    expect(ManualProgress::forStatus(VideoManualStatus::Draft))->toBe(ManualProgress::NotStarted)
+        ->and(ManualProgress::forStatus(VideoManualStatus::Analyzing))->toBe(ManualProgress::InProgress)
+        ->and(ManualProgress::forStatus(VideoManualStatus::Ready))->toBe(ManualProgress::InProgress)
+        ->and(ManualProgress::forStatus(VideoManualStatus::Rendering))->toBe(ManualProgress::InProgress)
+        ->and(ManualProgress::forStatus(VideoManualStatus::Published))->toBe(ManualProgress::Completed);
+});
+
+test('逆写像は漏れなく排他である (和 = 全 status / 重複なし)', function (): void {
+    $union = [];
+    foreach (ManualProgress::cases() as $progress) {
+        foreach ($progress->statuses() as $status) {
+            $union[] = $status->value;
+        }
+    }
+    sort($union);
+    $all = array_map(static fn (VideoManualStatus $status): string => $status->value, VideoManualStatus::cases());
+    sort($all);
+
+    expect($union)->toBe($all)                                  // 漏れなし
+        ->and(count($union))->toBe(count(array_unique($union))); // 排他
+});
+
+test('statusValues() は statuses() の DB 値列と一致する', function (): void {
+    foreach (ManualProgress::cases() as $progress) {
+        expect($progress->statusValues())->toBe(
+            array_map(static fn (VideoManualStatus $status): string => $status->value, $progress->statuses()),
+        );
+    }
+});
+
+test('一覧の状態は 3 値である (doc/04 の 3 値と件数一致)', function (): void {
+    expect(ManualProgress::cases())->toHaveCount(3);
+});
diff --git a/tests/js/components/features/manual/ManualListRow.test.ts b/tests/js/components/features/manual/ManualListRow.test.ts
index d7f69e1..658a9a5 100644
--- a/tests/js/components/features/manual/ManualListRow.test.ts
+++ b/tests/js/components/features/manual/ManualListRow.test.ts
@@ -26,7 +26,7 @@ function manualItem(overrides: Partial<ManualListItem> = {}): ManualListItem {
     return {
         id: 1,
         title: "ネジ締め作業",
-        status: "published",
+        progress: "completed",
         category: { id: 1, name: "準備作業" },
         creator: { id: 2, name: "編集 花子" },
         created_at: "2026-07-10 12:00",
@@ -55,10 +55,30 @@ describe("features/manual/ManualListRow", () => {
         renderRow();
 
         expect(screen.getByTestId("manual-duration-1")).toHaveTextContent("3:05");
-        expect(screen.getByTestId("manual-status-1")).toHaveTextContent("公開済み");
+        expect(screen.getByTestId("manual-progress-1")).toHaveTextContent("作成済");
         expect(screen.getByText(/準備作業 ・ 編集 花子 ・ 更新 2026-07-11 09:00/)).toBeInTheDocument();
     });
 
+    it("状態バッジは一覧の 3 値語彙で出す (制作状態 5 値のラベルは使わない)", () => {
+        for (const [progress, label] of [
+            ["not_started", "未着手"],
+            ["in_progress", "作成中"],
+            ["completed", "作成済"],
+        ] as const) {
+            const { unmount } = render(ManualListRow, {
+                props: {
+                    projectId: 7,
+                    manual: manualItem({ progress }),
+                    onRequestPreview: vi.fn(),
+                    onRequestDelete: vi.fn(),
+                },
+            });
+
+            expect(screen.getByTestId("manual-progress-1")).toHaveTextContent(label);
+            unmount();
+        }
+    });
+
     it("duration_ms が null のときは「—」を表示する (0:00 と書かない)", () => {
         renderRow({ duration_ms: null });
 
diff --git a/tests/js/components/features/manual/ManualPreviewModal.test.ts b/tests/js/components/features/manual/ManualPreviewModal.test.ts
index 438b3c6..854184d 100644
--- a/tests/js/components/features/manual/ManualPreviewModal.test.ts
+++ b/tests/js/components/features/manual/ManualPreviewModal.test.ts
@@ -17,7 +17,7 @@ function item(overrides: Partial<ManualListItem> = {}): ManualListItem {
     return {
         id: 2,
         title: "洗浄手順",
-        status: "published",
+        progress: "completed",
         category: null,
         creator: null,
         created_at: "2026-07-10 13:00",
diff --git a/tests/js/pages/CaptureIndex.test.ts b/tests/js/pages/CaptureIndex.test.ts
index 0382e5a..e43be6b 100644
--- a/tests/js/pages/CaptureIndex.test.ts
+++ b/tests/js/pages/CaptureIndex.test.ts
@@ -13,7 +13,6 @@ function makeSummary(overrides: Partial<CaptureManualSummary> = {}): CaptureManu
     return {
         id: 1,
         title: "ネジ締め作業",
-        status: "ready",
         category_id: 1,
         category_name: "準備作業",
         cuts_total: 3,
@@ -51,6 +50,33 @@ describe("Capture/Index 自作フィルタ・作成者表示", () => {
         expect(screen.getByText(/不明 ・ 更新/)).toBeInTheDocument();
     });
 
+    /*
+     * T197: 撮影 PWA の進捗語彙 (撮影完了 / 撮影中 / 未撮影) は PC 一覧の 3 値
+     * (作成済 / 作成中 / 未着手) とは**別の量**なので寄せない。判定の境界も現行のまま固定する。
+     */
+    it("撮影進捗バッジは撮影語彙のまま (判定境界も現行どおり)", () => {
+        const cases = [
+            { counts: { cuts_total: 0, cuts_adopted: 0, cuts_with_takes: 0 }, label: "未撮影" },
+            // 構造上生じない不整合 (take は cut に属する)。生じたら現行の三項式どおり「撮影中」
+            { counts: { cuts_total: 0, cuts_adopted: 0, cuts_with_takes: 1 }, label: "撮影中" },
+            { counts: { cuts_total: 3, cuts_adopted: 1, cuts_with_takes: 2 }, label: "撮影中" },
+            { counts: { cuts_total: 3, cuts_adopted: 3, cuts_with_takes: 3 }, label: "撮影完了" },
+        ];
+
+        for (const { counts, label } of cases) {
+            const { unmount } = render(CaptureIndex, {
+                props: { ...baseProps, manuals: [makeSummary(counts)] },
+            });
+
+            expect(screen.getByText(label)).toBeInTheDocument();
+            // PC 一覧の語彙へ寄せていないこと (別の量なので統合しない)
+            for (const pcLabel of ["未着手", "作成中", "作成済"]) {
+                expect(screen.queryByText(pcLabel)).toBeNull();
+            }
+            unmount();
+        }
+    });
+
     it("自作トグルで GET クエリに mine=1 が載る", async () => {
         const getSpy = vi.spyOn(router, "get").mockImplementation(() => {});
         render(CaptureIndex, { props: baseProps });
diff --git a/tests/js/pages/ProjectsShow.test.ts b/tests/js/pages/ProjectsShow.test.ts
index 989fefb..f213d7a 100644
--- a/tests/js/pages/ProjectsShow.test.ts
+++ b/tests/js/pages/ProjectsShow.test.ts
@@ -7,7 +7,7 @@ import type { ManualFilters, ManualListItem, PaginationMeta } from "@/types/manu
 const emptyMeta: PaginationMeta = { current_page: 1, last_page: 1, per_page: 10, total: 0 };
 const emptyFilters: ManualFilters = {
     category: null,
-    status: null,
+    progress: null,
     q: null,
     sort: null,
     mine: false,
@@ -17,7 +17,7 @@ const manualsFixture: ManualListItem[] = [
     {
         id: 1,
         title: "ネジ締め作業",
-        status: "draft",
+        progress: "not_started",
         category: { id: 1, name: "準備作業" },
         creator: { id: 2, name: "編集 花子" },
         created_at: "2026-07-10 12:00",
@@ -29,7 +29,7 @@ const manualsFixture: ManualListItem[] = [
     {
         id: 2,
         title: "洗浄手順",
-        status: "published",
+        progress: "completed",
         category: null,
         creator: null,
         created_at: "2026-07-10 13:00",
@@ -140,10 +140,10 @@ describe("Projects/Show", () => {
         expect(screen.getByTestId("manual-link-1").getAttribute("href")).toMatch(
             /\/projects\/1\/manuals\/1$/,
         );
-        expect(screen.getByTestId("manual-status-1")).toHaveTextContent("下書き");
+        expect(screen.getByTestId("manual-progress-1")).toHaveTextContent("未着手");
         // カテゴリ ・ 作成者 ・ 更新日 (作成者 null は「不明」)
         expect(screen.getByText(/準備作業 ・ 編集 花子 ・ 更新 2026-07-11 09:00/)).toBeInTheDocument();
-        expect(screen.getByTestId("manual-status-2")).toHaveTextContent("公開済み");
+        expect(screen.getByTestId("manual-progress-2")).toHaveTextContent("作成済");
         expect(screen.getByText(/未分類 ・ 不明 ・ 更新 2026-07-11 10:00/)).toBeInTheDocument();
     });
 
@@ -165,9 +165,16 @@ describe("Projects/Show", () => {
         expect(screen.getByRole("option", { name: "未分類" })).toBeInTheDocument();
         expect(screen.getByRole("option", { name: "準備作業" })).toBeInTheDocument();
 
-        const statusSelect = screen.getByTestId("manual-filter-status");
-        expect(statusSelect).not.toBeDisabled();
-        expect(screen.getByRole("option", { name: "下書き" })).toBeInTheDocument();
+        // 状態は一覧の 3 値 (doc/04)。制作状態 5 値のラベルは一覧に出さない
+        const progressSelect = screen.getByTestId("manual-filter-progress");
+        expect(progressSelect).not.toBeDisabled();
+        // 「すべて」はカテゴリ select にもあるため、状態 select の中に限定して見る
+        for (const label of ["すべて", "未着手", "作成中", "作成済"]) {
+            expect(within(progressSelect).getByRole("option", { name: label })).toBeInTheDocument();
+        }
+        for (const label of ["下書き", "解析中", "準備完了", "書き出し中", "公開済み"]) {
+            expect(screen.queryByRole("option", { name: label })).toBeNull();
+        }
 
         const submit = screen.getByTestId("manual-filter-submit");
         expect(submit).toBeInTheDocument();
@@ -256,6 +263,29 @@ describe("Projects/Show 並べ替え・自作フィルタ", () => {
         expect(getSpy.mock.calls[0][1]).not.toHaveProperty("page");
     });
 
+    it("状態変更で GET クエリに progress が載る (旧 status は載せない)", async () => {
+        const getSpy = vi.spyOn(router, "get").mockImplementation(() => {});
+        render(Show, { props: baseProps });
+
+        await fireEvent.change(screen.getByTestId("manual-filter-progress"), {
+            target: { value: "in_progress" },
+        });
+
+        expect(getSpy).toHaveBeenCalledTimes(1);
+        expect(getSpy.mock.calls[0][1]).toEqual({ progress: "in_progress" });
+        expect(getSpy.mock.calls[0][1]).not.toHaveProperty("status");
+    });
+
+    it("既存の状態絞り込みは props から復元される", () => {
+        render(Show, {
+            props: { ...baseProps, manualFilters: { ...emptyFilters, progress: "completed" } },
+        });
+
+        expect((screen.getByTestId("manual-filter-progress") as HTMLSelectElement).value).toBe(
+            "completed",
+        );
+    });
+
     it("自作 checkbox で GET クエリに mine=1 が載る", async () => {
         const getSpy = vi.spyOn(router, "get").mockImplementation(() => {});
         render(Show, { props: baseProps });
@@ -358,7 +388,7 @@ describe("Projects/Show 動画マニュアルの行内操作", () => {
                 ...baseProps,
                 manualFilters: {
                     category: "3",
-                    status: "published",
+                    progress: "completed",
                     q: "ネジ",
                     sort: "title_asc",
                     mine: true,
@@ -379,7 +409,7 @@ describe("Projects/Show 動画マニュアルの行内操作", () => {
         expect(url.pathname).toBe("/projects/1/manuals/2");
         expect(Object.fromEntries(url.searchParams.entries())).toEqual({
             category: "3",
-            status: "published",
+            progress: "completed",
             q: "ネジ",
             sort: "title_asc",
             mine: "1",

```

---

## テスト結果

- `composer test`: 5504 tests / 5502 passed / 2 skipped / 0 failed (assertions 23806)
- `composer phpstan`: level 10, No errors (970 files)
- `vendor/bin/pint --test`: passed
- `pnpm lint`: 0 problems
- `pnpm typecheck`: 0 errors
- `pnpm test` (Vitest): 157 files / 1938 passed / 0 failed
- `pnpm build`: 成功
- `pnpm typecheck:packages` / `pnpm build:packages`: 成功
- `pnpm test:packages`: 10 files / 106 passed

### fail-first の実測 (施策 H)

`resources/js/types/manual.ts` の `VideoManualStatus` から `"published"` を、`ManualProgress` から
`"completed"` を一時的に削除して `composer test tests/Architecture/ManualEnumTsSyncInvariantTest.php`
を実行したところ、新設した 2 本がともに赤になった (10 tests / 8 passed / 2 failed)。
復元後は 10 passed。値集合同期テストが degenerate PASS していないことの実測である。

---

## design system 参照 (DESIGN.md)

---
version: "1.0"
name: Slate × Blue (Neutral)
description: テンプレート既定のニュートラルテーマ。中立的な青を主役に、無彩のスレートを支配色とする。アプリはこのファイルと tokens.css の値を差し替えてテーマを定義する。
colors:
    primary: "#2563EB"
    primary-hover: "#1D4ED8"
    tertiary: "#0F766E"
    tertiary-hover: "#115E59"
    neutral: "#F4F4F5"
    surface: "#FFFFFF"
    border: "#E4E4E7"
    border-strong: "#A1A1AA"
    text-primary: "#18181B"
    text-secondary: "#52525B"
    success: "#15803D"
    warning: "#B45309"
    danger: "#B91C1C"
typography:
    display:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 48px
        fontWeight: 500
        lineHeight: 1.2
        letterSpacing: 0.02em
    h1:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 32px
        fontWeight: 500
        lineHeight: 1.3
        letterSpacing: 0.02em
    h2:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 24px
        fontWeight: 500
        lineHeight: 1.4
    h3:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 18px
        fontWeight: 500
        lineHeight: 1.5
    body:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 16px
        fontWeight: 400
        lineHeight: 1.7
    caption:
        fontFamily: "Noto Sans JP, sans-serif"
        fontSize: 12px
        fontWeight: 400
        lineHeight: 1.5
rounded:
    sm: 4px
    md: 6px
    lg: 8px
spacing:
    xs: 4px
    sm: 8px
    md: 16px
    lg: 24px
    xl: 40px
---

# Design System

本ファイルが**デザインの canonical source**。`resources/css/tokens.css` はその実装写像であり、
独自に値を変えてはいけない(同期契約は `docs/design-system.md`)。

## Overview

テンプレート既定のニュートラルテーマ。中立的な青(#2563EB)を主役、teal(#0F766E)を強アクセント、
無彩のスレート(#F4F4F5)を背景に据える。**アプリ固有のテーマは frontmatter の色値と
tokens.css の値を差し替えて定義する**(制約体系=影なし・最小色・ramp は維持したまま色だけ変える)。

## Colors

色は意味で割り当てる。順序や見た目の好みで使い分けない。

- **Primary(#2563EB)**: ブランドの中核。プライマリボタン、リンク、選択中のナビゲーション。
  1 画面の主要 CTA 以外には濫用しない。
  - tailwind: `bg-primary`, `text-primary`, `border-primary`、hover は `hover:bg-primary-hover`
- **Tertiary(#0F766E)**: 強いアクセント。緊急性・重要性のある前向き CTA、特別なバッジに限定。
  1 画面に 1 箇所が原則。
  - tailwind: `bg-tertiary`, `text-tertiary`, `border-tertiary`、hover は `hover:bg-tertiary-hover`
- **Neutral(#F4F4F5)**: 主要な背景色。画面全体はこの色で塗る。
  - tailwind: `bg-neutral`
- **Surface(#FFFFFF)**: カード・モーダル・浮いた要素の背景。Neutral との明度差で奥行きを出す。
  - tailwind: `bg-surface`
- **Border(#E4E4E7)**: 区切り線、入力欄の枠。常に細く(1px)。
  - tailwind: `border-border`
- **Border Strong(#A1A1AA)**: 区切りの強調、ghost ボタンの枠。
  - tailwind: `border-border-strong`
- **Text Primary(#18181B)**: 本文・見出しの主たる色。純黒は使わない。
  - tailwind: `text-text`(`--color-text` を参照)
- **Text Secondary(#52525B)**: 補足文、キャプション、ラベル。
  - tailwind: `text-text-secondary`

### 状態色

- **Success(#15803D)**: 完了・正常・公開済み。
  - tailwind: `text-success`, `bg-success`, `border-success`
- **Warning(#B45309)**: 注意・確認が必要・保留。
  - tailwind: `text-warning`, `bg-warning`, `border-warning`
- **Danger(#B91C1C)**: 失敗・破壊的操作・エラー。Tertiary とは別物
  (Tertiary は前向きな強調、Danger は否定的なシグナル)。
  - tailwind: `text-danger`, `bg-danger`, `border-danger`

状態色・アクセントは Tailwind の **-700 段**で揃える(`tertiary` teal-700 / `success` green-700 /
`warning` amber-700 / `danger` red-700)。`neutral`(#F4F4F5)や `surface`(#FFFFFF)の上で
**本文コントラスト 4.5:1** を確保するための下限であり、これより明るい段は使わない
(`tests/js/architecture/contrast-invariant.test.ts` が機械検証する)。

ソフト背景は状態色の opacity 修飾で表現する(`bg-success/10`, `bg-danger/10`,
`bg-primary-soft` 等)。**新しい色トークンを足す前に opacity 修飾と atom 化で表現できないか
検討すること**(追加条件は `docs/design-system.md` の 4 条件)。

## Typography

全ランプ Noto Sans JP。フォントウェイトは **400 と 500 の 2 階層のみ**(700 は使わない)。
コード・識別子・数値整列には `font-mono` を許可する(日本語 prose には使わない)。

### Typography ramp utility

各 ramp は `resources/css/tokens.css` の `@utility` で定義済。実装はこの utility を
そのまま class として適用する。**raw の `text-sm` / `font-bold` 等は禁止**(ds-purity が検出)。

- **text-display**: 48px / 500 / lh 1.2 / ls 0.02em — tailwind: `text-display`
- **text-h1**: 32px / 500 / lh 1.3 / ls 0.02em — tailwind: `text-h1`
- **text-h2**: 24px / 500 / lh 1.4 — tailwind: `text-h2`
- **text-h3**: 18px / 500 / lh 1.5 — tailwind: `text-h3`
- **text-body**: 16px / 400 / lh 1.7 — tailwind: `text-body`
- **text-caption**: 12px / 400 / lh 1.5 — tailwind: `text-caption`

役割マッピング: 本文/入力値/主要数値 → `text-body`、ラベル/補助情報/日時 → `text-caption`、
page タイトル → `text-h1`/`text-h2`、section/card 見出し → `text-h3`。
強調は `font-medium`(500)を上限とし、足りなければ weight を上げず ramp 昇格+余白+
色階層(text vs text-secondary)でコントラストを作る。

## Layout

8px ベースのスケール。要素間は `md (16px)` を基本に、セクション間は `xl (40px)`。
コンテナは最大幅 1080px を目安に、画面の左右に 32px の余白を確保する。

## Elevation & Depth

**`box-shadow` は使わない。** Neutral(背景)と Surface(カード)の明度差、および 1px の
ボーダーで階層を表現する。ホバー時も影を出さず、ボーダー色や文字色の変化で反応を示す。
グラデーション・scale 効果も使わない。

## Shapes

角丸 ramp は **`rounded-sm`(4px)/ `rounded-md`(6px)/ `rounded-lg`(8px)の 3 段のみ**。
DOM 役割で選ぶ(上から優先): カード・モーダル=`lg` / 中間 box(パネル・`<pre>`)=`md` /
ボタン・入力・バッジ等の小コントロール=`sm`。
素の `rounded`・`rounded-xl` 以上・任意値・方向別(`rounded-t-*` 等)は使わない。
完全円(`rounded-full`)はアバター/status dot/トグル等の**真に円形な UI に限る** ramp 外の例外で、
file-scoped allowlist で個別管理する。

## Components

> component 仕様は実装(`resources/js/components/`)と型定義が真実。本節は意味論と
> 使い分けルールのみを定義する。各 component を追加したら本節に追記すること。

### Button

実装: `components/atoms/Button.svelte`(仕様の真実は `Button.types.ts`)。

| variant | 用途 | スタイル要旨 |
|---------|------|------------|
| `primary` | 主要 CTA(1 画面 1 つ目安) | bg-primary + text-neutral |
| `tertiary` | 真に重要な前向き CTA(1 画面 1 箇所) | bg-tertiary + text-neutral |
| `ghost` | 補助・キャンセル | 透明 + border-border-strong、hover で primary 化 |
| `neutral` | 取消可能・UI-only の補助操作(一時停止等) | bg-neutral + 常時 border(境界確保) |
| `success` | 肯定操作(追加・承認・付与) | bg-success + text-neutral |
| `danger` | dialog/form の主破壊 CTA | bg-danger + text-neutral |
| `danger-outline` | section 単位の破壊(card 内の削除) | border-danger、hover で塗り |
| `danger-ghost` | dense な row/list 内の破壊アクション | text-danger + 透明、hover で淡い tint |

- **全 variant が border(透明 or 色)を持ち外形高さを統一する**
- danger 系は irreversible / destructive 操作専用(削除・revoke・移譲・再開不可の中断)。
  危険度ではなく**配置文脈**で 3 重みを選ぶ
- **anchor 対応**: `href` 指定で `<a>`(`inertia` 指定で Inertia Link)。anchor モードでは
  `type`/`disabled` は型レベルで禁止。`target="_blank"` には `rel="noopener noreferrer"` を自動補完
- **iconOnly**: `ghost` / `neutral` / `danger-ghost` のみ許可。`ariaLabel` が型で必須
- **disclosure**: button モード限定で `ariaExpanded` / `ariaControls` / `element`(bindable な
  `HTMLButtonElement` 参照)を受ける。ハンバーガー等のトグルはこれを使い素の `<button>` を書かない
- size: `sm`(caption)/ `md`(既定)/ `lg`(form 入力面との高さ整合限定)

### Input / Textarea / Select(入力系 atom)

実装: `components/atoms/Input.svelte` / `Textarea.svelte` / `Select.svelte`。
見た目は `components/atoms/input-state.ts`(`INPUT_BASE_CLASSES` + `inputStateClass`)に集約し、
入力系 atom 間で統一する。`error` prop で danger 枠と `aria-invalid` が連動する。
`aria-describedby` 等は restProps で透過。Select の `<option>` 群は呼び出し側が
children snippet として記述する。Input の `type` は text 系に限定した union。
ラベル・エラー文言・`aria-describedby` の配線は FormField molecule の責務
(入力 atom は最小責務に保つ)。パスワード入力は素の `Input type="password"` ではなく
PasswordInput molecule を使う。

- **`type` は入力補助であって検証手段ではない**。`email` / `tel` / `url` / `number` 等は
  モバイルキーボード・autofill・スクリーンリーダーの型アナウンスのために付ける。
  検証の正本はサーバ(日本語)と押下時の client エラーで、native constraint validation には
  依存しない(form 側で `novalidate`。§Do's and Don'ts)。`inputmode` は restProps で透過する
- **readonly は「編集できない」ことを面で示す**(`Input` / `Textarea` の `readonly` prop)。
  `bg-neutral` + `cursor-default`。ただし **disabled と同じ見た目にしない** — readonly の値は
  生きている(送信される・選択してコピーできる・フォーカスできる)ので、文字色は `text-text` の
  ままにし focus ring も維持する。disabled は `text-text-secondary` + `cursor-not-allowed` +
  フォーカス不可。`<select>` は HTML 仕様上 readonly を持たない(編集させないなら値を
  読み取り表示にする)
- 「編集させない値」の表現は 2 通り。**そのフォームの送信対象に含む / コピーさせたい**なら
  readonly input(例: 招待 email の prefill、権限が無い閲覧者への設定値提示)、
  **編集手段自体を出さない**なら読み取り表示(`<dl>` 等。例: 請求先情報カードの非管理者表示)。
  readonly input を選んだ場合、上記の見た目が付くことは atom が保証する

### Checkbox

実装: `components/atoms/Checkbox.svelte`。インラインラベル(右側)とエラー表示
(FormError 内包)を持つチェックボックス。ラベルは string のほか snippet でも受けられる
(利用規約リンク等を含める用)。複数行ラベルでもチェックボックスが 1 行目に揃う行揃えは
本 atom の責務。ページ側で素の `<input type="checkbox">` を書かない(§Do's and Don'ts)。

### FormError

実装: `components/atoms/FormError.svelte`。フィールド単位のエラー文言
(`text-caption text-danger`。message が無ければ何も描画しない)。FormField / Checkbox から
composition される前提の最小 atom。単体で使う場合、`aria-describedby` の配線は呼び出し側の
責務。ページ常在の通知は Alert、一時通知は Toast を使う。
**フィールドに紐づかない失敗(ceremony 失敗・端末非対応等)を FormError に流さない**
(原因と提示先が食い違い、「パスキー失敗がパスワード欄の赤字として出る」species のバグになる)。
非フィールド起因は Alert(§Alert)。

### Avatar

実装: `components/atoms/Avatar.svelte`。`src` があれば画像、無ければ `name` の先頭 1 文字
(大文字化。サロゲートペアも 1 文字扱い)をイニシャル表示する。アバターは真に円形な UI
のため `rounded-full` を使う ramp 外例外(Toggle と並び ds-purity の file-scoped allowlist
出荷時 2 件の 1 つ)。size: `sm` / `md`(既定)/ `lg`。

### Badge

実装: `components/atoms/Badge.svelte`(仕様の真実は `Badge.types.ts`)。状態・属性の
**結果表示**ラベル(操作は Button。action button と status badge は意味色を独立に判断する
— §色の意味的割り当てルール)。tone: `primary` / `tertiary` / `success` / `warning` /
`danger` / `neutral`(中立ラベル)。既定は soft(tone 色の淡い背景 + tone 色文字)、
`bordered` は tone 色 border を atom 内で付与する(呼び出し側から border を足さない)。
左アイコン 1 つを snippet で受け、size/色の責務は Badge 内 wrapper に閉じる。
小コントロールなので `rounded-sm`。size: `sm`(既定)/ `md`。

### Card

実装: `components/atoms/Card.svelte`。浮いた要素の基本サーフェス
(`bg-surface border border-border rounded-lg`。影を使わず明度差 + 1px border で階層を
表現する — §Elevation & Depth)。padding: `none`(table/list 等を内包し内側で個別に
padding を制御する箱用)/ `sm` / `md`(既定)/ `lg`。

### Spinner

実装: `components/atoms/Spinner.svelte`。LoaderCircle(@lucide/svelte)+ `animate-spin`。
色は currentColor 継承(置かれた文脈の文字色に従う)。既定は装飾扱い(`aria-hidden`)で、
単独のローディング表示に使うときだけ `label` を渡す(`role="status"` + sr-only で
読み上げ)。size: `sm` / `md`(既定)/ `lg` / `xl`。

### TextLink

実装: `components/atoms/TextLink.svelte`(仕様の真実は `TextLink.types.ts`)。
リンク風 `<a>` / `<button>` の手書きは禁止(§Do's and Don'ts)、本 atom を使う。
3 モードの discriminated union: (a) `href` のみ = Inertia Link(SPA 遷移)、
(b) `href` + `external` = ネイティブ `<a>` + 別タブ + `rel="noopener noreferrer"` +
末尾 ExternalLink アイコン(`icon` で差し替え可)、(c) `onclick` のみ = リンク風
`<button type="button">`。様式は `text-primary` + 下線(hover で下線が濃くなる)で 3 モード共通。

### Toggle

実装: `components/atoms/Toggle.svelte`(仕様の真実は `Toggle.types.ts`)。
オン/オフを**即時反映**する設定スイッチ(ネイティブ `<button>` + `role="switch"` +
`aria-checked`)。フォーム送信を伴う選択には使わない。`ariaLabel` は型レベルで必須。
トラックは On=`bg-primary` / Off=`bg-border-strong`、つまみは `bg-surface`(影なし、
明度差で表現)。`rounded-full` は真に円形な UI の例外として file-scoped allowlist で管理する。

### Modal

実装: `components/organisms/Modal.svelte`(仕様の真実は `Modal.types.ts`)。bits-ui Dialog のラップ。

- overlay は `bg-text/50`(墨色 50%。黒 hex を使わない)、本体は `bg-surface border border-border rounded-lg`
  (影が使えないためボーダーで背景と区別する)
- size: `sm`(max-w-md)/ `md`(max-w-lg 既定)/ `lg`(max-w-2xl)
- `processing` 中は ESC / overlay クリックでの close を抑止し、X ボタンを disabled にする(二重実行防止)
- title は `text-h3`。a11y 名は bits-ui `Dialog.Title` 経由で `aria-labelledby` に配線される

### ConfirmDialog

実装: `components/organisms/ConfirmDialog.svelte`(仕様の真実は `ConfirmDialog.types.ts`)。Modal の composition。

- `confirmVariant` は `primary` / `danger` の 2 値のみ。**irreversible / destructive な操作は danger**
  (§色の意味的割り当てルール)
- footer は Button atom(cancel=`ghost` / confirm=`confirmVariant`、processing 中は loading)
- confirm で自動 close しない(処理完了後に呼び出し側が `open=false` にする)。
  cancel / ESC / overlay / X は `onCancel` を発火して close
- `banner?: Snippet` は message 直上の任意スロット(サーバ validation エラーの Alert 等)。
  未指定なら描画されない(既存の出力は不変)

### Toast

実装: `components/organisms/ToastContainer.svelte` + `lib/stores/toast.ts`(addToast / dismissToast)。
Laravel flash の取り込みは `lib/stores/flash-to-toast.ts` の `consumeFlash`(visitKey で de-dup)。

- 上部中央 fixed(`top-6 left-1/2 -translate-x-1/2 z-50`)に縦 stack 表示。アプリで 1 箇所のみ mount する
  (mount するのは layout: AppLayout / AuthLayout / GuestLayout の 3 種。ページ側では mount しない)
- 自動消去: **success / info / warning = 4 秒、error = 手動閉じのみ**
- 消去境界: **layout(AppLayout / AuthLayout / GuestLayout)の初期化時に既存 toast を破棄**してから
  当該 visit の flash を消費する。= **layout が再初期化される遷移**では toast を持ち越さない
  (認証済み文脈の toast を未認証面へ出さない)。`preserveState` の visit / partial reload は
  layout を再初期化しないため toast は残る。別タブの既表示 toast の即時消去は保証しない
- 各 toast は `bg-surface` + type 別 border / アイコン色(success / primary(info)/ warning / danger)。
  アイコンは CircleCheck / Info / TriangleAlert / CircleX(@lucide/svelte)
- a11y: `role="status"`(error のみ `role="alert"`)

### Alert

実装: `components/atoms/Alert.svelte`。ページ内に常在するインライン通知ボックス
(一時通知は Toast、フィールド単位のエラーは FormField/FormError を使う)。

- type: `success` / `warning` / `danger` / `info`(info は primary を流用。Toast と同じ規約)
- 配色: ボーダー=状態色、見出し(title 任意)=状態色、本文=`text-text`、背景=`bg-surface`。
  テーマ色を面塗りに使わない。中間 box なので `rounded-md`
- `action` snippet(本文下の CTA)、`dismissible` + `onDismiss`(右上の X)を持つ
- a11y: **danger のみ `role="alert"`(assertive)**、他は `role="status"`(polite)
- **非フィールド起因の操作失敗は Alert**。フォームのフィールドに紐づかない失敗
  (WebAuthn ceremony 失敗・端末非対応・ネットワーク失敗など)は、操作したその場に残る
  Alert で出す。FormError は**フィールド単位**のエラー専用であり、Toast は「一時通知」なので、
  押した直後に読ませたい失敗理由を画面外(上部中央)へ飛ばさない

### FormField

実装: `components/molecules/FormField.svelte`。ラベル + 入力 + エラー(FormError)+
ヘルプの複合 molecule。入力 atom を最小責務に保つため、ラベル・エラー文言・
`aria-describedby` の配線は本 molecule が担う(関心分離)。children snippet に
`{ id, describedBy, invalid }` を渡すので、呼び出し側はそれを入力 atom へ流し込む。
`required` は `*`(danger 色、`aria-hidden`)をラベルに付与する。フォームの入力欄は
本 molecule 経由で組む(AGENTS.md 実装規約)。

- **押下時に出した client エラーは、その後の入力に追随させる**(stale invalid を残さない)。
  ボタンを disabled にしない(§Do's and Don'ts / AGENTS.md 禁止事項 8)代わりに押下時にエラーを
  出すのだから、そのエラーは常に「今の入力」を説明していなければならない — 有効に戻ったら消え、
  無効の理由が変わったら文言も変わる。押下前には出さない。
  **canonical なのはこの不変条件であって実装形ではない**。実装は
  **「提示を開始したかの boolean」+ 文言は `$derived`** で組むのが既定(文言を `$state` で
  持つと同期漏れが起きる。`$effect` での状態同期はしない = Svelte 公式の指針)。
  先行実装(`Billing/PurchaseTickets.svelte` / `Organizations/Settings.svelte`)は `$effect` に
  よる連動クリアで**同じ不変条件を満たしており、そのまま許容する**(動いている仕組みを
  churn させない)。**新規は `$derived` 形で書く**
- サーバ由来の errors(`form.errors.*`)はこの追随の対象外。入力の変更で消さない

### DangerZone

実装: `components/molecules/DangerZone.svelte`。破壊的・取り返しのつかない操作
(アカウント削除等)を集約する警告セクション(presentational・状態なし)。
`border-danger/30` + 淡い danger 背景の枠に title(danger 色 `text-h3`)+ 任意の
description、children には danger 系 Button(card 内なら `danger-outline`)を置く。
`<section>` + `aria-labelledby` で region 境界に accessible name を紐付ける。
複数同居時は `idBase` で id 衝突を回避する。

### Divider

実装: `components/molecules/Divider.svelte`。区切り線の正規化(「または」セパレータ等)。
`label` 指定時は中央ラベル付き区切り(線は `aria-hidden`、ラベルは `bg-surface` で線を
切り抜く)、省略時は素の `<hr>`。余白は呼び出し側が class で渡す(`my-6` 等)。

### Pagination

実装: `components/molecules/Pagination.svelte`。前へ / ページ番号 / 次へのページ送り UI。
callback ベース(ページング state は親が持ち、`currentPage` / `totalPages` を受けて
`onChange(page)` を返す)で遷移手段を持たないため、全て `<button type="button">` で構成する
(Inertia 遷移かローカル state 更新かは呼び出し側裁量)。総ページ ≤ 7 は全番号表示、
超過時は先頭・末尾 + 現在ページ ± 1 の窓を出し、飛びに省略記号を挿入する最小実装。
`<nav>` ランドマーク + 現在ページに `aria-current="page"`。

### Tabs

実装: `components/molecules/Tabs.svelte`。**同一ページ内 section 切替**の WAI-ARIA タブバー
(tablist のみ。URL 遷移で切り替えるページ間タブは ApiKeyTabNav のような専用 molecule を
使う)。パネル本体の描画は呼び出し側責務(god component 回避)で、
`id="{idBase}-panel-{tab.id}"` / `role="tabpanel"` / `aria-labelledby` を id 生成規則に
揃えて配線する。キーボードは ←/→(端でラップしない)+ Home/End、自動アクティベーション +
roving tabindex(active のみ tabindex=0)。`active` は bindable、`idBase` は必須
(複数同居時の id 衝突回避)。

### PasswordInput

実装: `components/molecules/PasswordInput.svelte`。Input atom + 右端の Eye/EyeOff トグルで
`password` ↔ `text` を即時切替する(button トグル + `aria-pressed`)。`id` は必須
(トグルの `aria-controls` に結線)。label/error 配線は FormField 側が担う。
Auth 系のパスワード入力は素の `Input type="password"` ではなく本 molecule を使う。

### CodeSnippet

実装: `components/molecules/CodeSnippet.svelte`。コピー付きコードブロック
(API キー・リカバリコード・CLI コマンド等)。コピー処理(navigator.clipboard)は
component 内に内包し、成功「コピー完了」/失敗「コピー失敗」を 2 秒表示する。
`<pre>` は `rounded-md bg-neutral` + `font-mono text-caption`。

### StatCard

実装: `components/molecules/StatCard.svelte`。Card atom に label(`text-caption`)+
value(`text-h2`。weight でなく ramp 昇格で強調)+ 任意の subtext / Lucide icon
(`bg-primary-soft` の rounded-md box)を載せる統計カード。

### EmptyState

実装: `components/molecules/EmptyState.svelte`。リストやテーブルが空のとき、次の行動を
案内する空状態表示。`description`(必須)+ 任意の `title` / Lucide `icon`(装飾なので
`aria-hidden`、`size-10`)。`cta` は discriminated union で遷移(`kind: "link"` = Button
の anchor+inertia)と操作(`kind: "action"` = onclick)を型安全に出し分ける。`bordered`
で破線枠サーフェス(`border-dashed`。drop 領域や明示的な空 region 向け)。

### Breadcrumb

実装: `components/molecules/Breadcrumb.svelte`。`BreadcrumbItem[]`(`@/types/components`)を
`ChevronRight` 区切りで並べるパンくず。**`href` 省略の項目は現在位置**としてリンクにしない。
atom 非依存(Lucide アイコンのみ)。単体で置かず、通常は PageHeaderSection 経由で出す。

### PageHeader / PageHeaderSection

実装: `components/molecules/PageHeaderSection.svelte`(full feature)と
`components/molecules/PageHeader.svelte`(shorthand)。

- **PageHeaderSection**: `title` / `breadcrumbs` / `description` / `icon`(Lucide 互換
  `Component`)/ actions(`children` Snippet)を持つ詳細画面用ヘッダ。全幅バーは
  PageContainer の padding を打ち消す**負マージン契約**で敷き、サイドバーのロゴブロックと
  同じ高さに揃える。**パンくずは 2 件以上のときだけ出す**(1 件は h1 と二重提示になるため)。
- **PageHeader**: breadcrumbs / actions を使わないルート画面用の薄いラッパー。
  内部で PageHeaderSection を呼ぶだけ。**actions や breadcrumbs が要るなら
  PageHeaderSection を直接使う**(PageHeader に prop を足さない)。
- actions は children Snippet で渡す(旧 slot API は使わない)。

### NotificationBell

実装: `components/molecules/NotificationBell.svelte`。`/notifications` への Inertia link に
未読数バッジを重ねた通知ベル。未読数は shared props(`notifications.unreadCount`)を親が渡す。
**100 以上は `99+` に丸める**。v1 はドロップダウンを持たない最小構成(フォーカス管理・
開閉状態を持たない)。**通知はこのベルが単一導線**で、サイドバー nav 項目に重複掲載しない。
`data-testid` は既定 `notification-bell`(mobile は呼び出し側が `notification-bell-mobile`)。

### PricingPlanCard

実装: `components/molecules/PricingPlanCard.svelte`(仕様の真実は `PricingPlanCard.types.ts`)。
料金プランカード。**DTO 非依存**(primitive props)で、feature 文言と CTA は呼び出し側が
props / Snippet で供給する。

- `priceAmount` が **null = 基本料金を持たない = 「無料」表示**(0 も防御的に同一表示)。
- `priceCaption`(例: 「基本料金」)は表示価格が総額と誤解されるのを防ぐための価格直上の説明。
- `isHighlighted` で `border-primary` の強調枠(現在のプラン等)。
- `headerBadges`(header 右上)/ `footerCta`(card 下部)は Snippet 専用スロット。

### ApiKeyTabNav

実装: `components/molecules/ApiKeyTabNav.svelte`。API キー管理ドメインのページ間
(API キー ⇔ 接続セッション ⇔ 導入ガイド)を **URL 遷移**(Inertia `Link`)で切替えるタブナビ。
同一ページ内 section 切替の `molecules/Tabs.svelte` とは責務が異なる。`tabs`(label + href +
active)はページ側が組み立てる(どのタブを出すか・URL は呼び出し側責務)。active タブに
`aria-current="page"` を付与する。

### RecentAuthModal

実装: `components/organisms/RecentAuthModal.svelte`(Modal の composition)。機微操作
(API キー発行/失効・アカウント削除・オーナー移譲)の前に出す**同一画面の再認証(step-up)
モーダル**。パスワード設定済みは再入力 → `POST /recent-auth/password`(成功は XHR 204)、
再 SSO 可能な provider は `reauthUrl` へフルリダイレクト、パスキー登録済みは WebAuthn 検証。
認可の最終ゲートは各操作の recent-auth middleware で、本モーダルは UX 補助。

- **props 契約は `status: RecentAuthStatus | null` の 1 本**(`bind:open` / `onConfirmed` を除く)。
  `/recent-auth/status` の応答を field へ分解して手渡さない — field が増えるたびに配線漏れが
  生まれる(T106 で `passkeyAvailable` を足した際、6 呼び出し中 5 箇所が未配線のまま出荷され
  passkey-only ユーザーが 5 画面で詰んだ)。`tsc --noEmit` は `.svelte` テンプレートを型検査
  しないため、強制点は `tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts`
  (deny-by-default。`status={recentAuthStatus}` の識別子・旧 prop 不在・`onStale` での代入まで検査)
- `status === null` は**状態不明**として扱い、空表示や事実に反する文言を出さず再読み込み導線を出す
- 再認証が成立しないユーザー(`canSatisfy=false` / この端末で実行不能)への回復導線は
  **`molecules/RecentAuthRecoveryNotice` に集約**する(下記)

### RecentAuthRecoveryNotice

実装: `components/molecules/RecentAuthRecoveryNotice.svelte`。再認証(step-up)が**この場では
成立しない**ユーザーに出す回復導線。全画面 confirm(`pages/Auth/ConfirmRecentAuth`)と
インラインモーダル(`organisms/RecentAuthModal`)の**両方が使う唯一の実装**(分けて持つと
片方だけ旧作法が残る)。

- `variant`: `no-satisfier`(アカウントに手段が無い)/ `not-executable-here`(手段はあるが
  この端末で実行できない = パスキー非対応ブラウザ)
- **`/forgot-password` へ直接リンクしない**。Fortify が `guest` middleware 付きで登録しており
  ログイン済みの本 UI 利用者はフォームに到達できない(踏破不能 CTA)。案内するのは
  「ログアウト → guest としてパスワード再設定」の経路だけ。アプリ内の初回設定
  (`POST /settings/password`)は recent-auth 必須なので、ここに来ているユーザーには使えない
- ログアウトは **Inertia visit(`router.post`)**(経路 C の保証条件。
  `tests/js/architecture/logout-call-site-inventory.test.ts` が inventory で固定)
- molecule 配置は構造的制約: 呼び出し元の RecentAuthModal は organism であり、
  atomic-import-graph 上 organism は features 層を import できない

## Do's and Don'ts

**Do**

- 背景は常に neutral、浮いた要素は surface(逆に使わない)
- 余白を多めにとる。色は Primary / Tertiary / 状態色 1 種までを目安に
- 操作の可否は**押した後のフィードバック**で伝える(バリデーションエラー表示+フォーカス移動)
- **認証フロー画面(`AuthLayout`)には離脱導線を footer に必ず置く**。その手順を完了できない
  ユーザー(リンク期限切れ・コード紛失・再認証手段なし)が別の入口へ抜けられる `TextLink` を
  `{#snippet footer()}` に 1 つ以上持つ。行き先は**その画面のユーザーの認証状態で実際に
  踏破できる先**に限る(`tests/js/architecture/page-shell-structure.test.ts` が機械強制。
  例外は理由付き allowlist)

**Don't**

- グラデーション・ドロップシャドウ・scale 効果を使わない
- Danger と Tertiary を同一 action cluster・隣接 CTA 群で併置しない(赤系・強調系の意味が混ざる)
- **必須条件未充足を理由にボタンを disabled でブロックしない**。ボタンは活性のまま、
  押下時に何が足りないかをエラー表示する(例: 利用規約同意チェック。
  disabled はユーザーに「なぜ押せないか」を伝えられない)
- **表示条件と踏破条件が食い違う導線を出さない**。押しても必ず失敗するボタン・リンク
  (認証・権限・ゲートで確実に弾かれる先を指すもの)は**出さずに、なぜ今は進めないかを
  文章で説明する**。disabled 化でも代替しない(上の Don't と同根。例: メール未認証画面から
  `verified` ゲート内の checkout へ進む CTA)
- ページ内で素の `<input>` / `<table>` / リンク風 `<a>` 手書きをしない(対応する atom/molecule を使う)
- **native の constraint validation に検証を任せない**。`<form>` には `novalidate` を付け、
  検証文言はサーバ(日本語)と押下時の client エラーに一本化する。
  native validation は submit より先に発火してブラウザロケール依存の文言で送信を止めるため、
  日本語 UI の検証経路に到達できなくなる(`tests/js/architecture/form-novalidate.test.ts` が機械検証)

## 色の意味的割り当てルール

- **danger** = irreversible な喪失・破壊(削除・revoke・unassign・移譲・再開不可の中断)。
  確認 dialog があっても操作自体が不可逆ならボタン色は danger
- **warning** = 注意喚起 / 保留 / 可逆な要確認状態
- **tertiary** = 前向きな強調のみ(1 画面 1 箇所)
- **primary** = ブランド中核 / 主要 CTA / 選択中
- **neutral / text-secondary** = 中立・取消可能・UI-only の補助操作

action button(操作)と status badge(結果表示)は意味色を**独立に判断**する。


---

## 触れた atomic ディレクトリ

- `resources/js/components/features/manual/ManualListRow.svelte` (features 層。import 先は atoms のみ: Badge / Button / TextLink)
- `resources/js/pages/Projects/Show.svelte` (pages 層)
- `resources/js/pages/Capture/Index.svelte` (pages 層。import 先は atoms/molecules/templates)
- `resources/js/types/manual.ts` / `resources/js/types/capture.ts` (型定義。atoms/Badge.types のみ import)

## 参考: 変更後の resources/js/types/manual.ts 全文

```ts
/**
 * 動画マニュアル (VideoManual) / カテゴリ関連の Inertia props 型。
 * PHP 側の typed array PHPDoc (ProjectController::manualRows 等) と対で保守する。
 * status は PHP enum App\Enums\Manual\VideoManualStatus と値集合を一致させる
 * (literal union で UI 分岐漏れを検出する)。**乖離検知の正本は
 * tests/Architecture/ManualEnumTsSyncInvariantTest.php** (VideoManualStatus /
 * ManualProgress を含む値集合同期テスト) であり、手動確認ではない。
 */

import type { BadgeTone } from "@/components/atoms/Badge.types";

export type VideoManualStatus = "draft" | "analyzing" | "ready" | "rendering" | "published";

/**
 * VideoManualStatus の**表示ラベル**。
 * **一覧 (Projects/Show の行バッジと絞り込み) では使わない** — 一覧はポーリングせず、
 * 短命な遷移状態を出すと再読込まで嘘になるため。一覧は MANUAL_PROGRESS_LABELS を使う。
 * 実況する面 (詳細画面 Manuals/Show / ダッシュボード) では引き続きこれを使う。
 *
 * 注: 制限しているのは**表示語彙 (このラベル表とトーン表) の使用面**だけである。
 * 5 値の型そのものを使う判定 (CAPTURE_NAVIGABLE_BY_STATUS / SCENARIO_ESTABLISHED_BY_STATUS /
 * SCENARIO_ANALYZABLE_BY_STATUS など) は正当な用途であり、本制限の対象外。
 */
export const VIDEO_MANUAL_STATUS_LABELS: Record<VideoManualStatus, string> = {
    draft: "下書き",
    analyzing: "解析中",
    ready: "準備完了",
    rendering: "書き出し中",
    published: "公開済み",
};

/**
 * 状態バッジの tone (結果表示の意味色。**実況する面**で使う。一覧は MANUAL_PROGRESS_TONES)。
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

/**
 * シナリオが確定した「表示相」か (ready 以降)。
 * status がシナリオ確定相 (ready / rendering / published) かを表す **UI 表示判定** であり、
 * cuts の実在判定ではない (複製直後の draft+cuts はここでは false = 別症状)。
 * これにより確定相で「未生成」案内を出さない。
 * 注: CAPTURE_NAVIGABLE_BY_STATUS (撮影ナビ導線, rendering=false) とは別概念なので統合しない。
 * satisfies で status 追加時のキー漏れをコンパイル時検出する。
 */
export const SCENARIO_ESTABLISHED_BY_STATUS = {
    draft: false,
    analyzing: false,
    ready: true,
    rendering: true,
    published: true,
} as const satisfies Record<VideoManualStatus, boolean>;

/** status がシナリオ確定相 (ready 以降) の表示相か (型付き判定の単一ソース) */
export function isScenarioEstablished(status: VideoManualStatus): boolean {
    return SCENARIO_ESTABLISHED_BY_STATUS[status];
}

/**
 * AI 解析操作を適用できる状態か (サーバ AnalysisJobService の許可集合 = draft / ready と一致)。
 * これは **解析操作の適用可能状態** の判定であり、rendering / published / analyzing は
 * status_not_analyzable (409) となるため false。AI 解析ボタン (CTA) の表示可否に使う。
 * satisfies で status 追加時のキー漏れをコンパイル時検出する。
 */
export const SCENARIO_ANALYZABLE_BY_STATUS = {
    draft: true,
    analyzing: false,
    ready: true,
    rendering: false,
    published: false,
} as const satisfies Record<VideoManualStatus, boolean>;

/** AI 解析操作を適用できる状態か (draft / ready。型付き判定の単一ソース) */
export function isAnalyzable(status: VideoManualStatus): boolean {
    return SCENARIO_ANALYZABLE_BY_STATUS[status];
}

export interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

/** PHP App\Enums\Manual\ManualSortOption と値集合を一致させる (allowlist) */
export type ManualSortOption = "updated_desc" | "updated_asc" | "title_asc" | "title_desc";

/** PHP: App\DataTransferObjects\Manual\ManualListItemData::toArray() と対 */
export interface ManualListItem {
    id: number;
    title: string;
    /** 一覧の状態 (3 値)。サーバが写像済みの値であり、UI 側で再写像しない */
    progress: ManualProgress;
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
    /** 状態の絞り込み (3 値)。null = すべて。旧 `status` (5 値) は廃止 */
    progress: ManualProgress | null;
    q: string | null;
    /** 並べ替え。null = 既定 (作成日降順) */
    sort: ManualSortOption | null;
    /** 自分の作成分のみ */
    mine: boolean;
}

/**
 * PHP: App\DataTransferObjects\Manual\ScenarioPointData と対。
 * サーバ shape の id は常に number (確定 id)。未保存行 (id: null) は
 * 編集中の作業コピー専用型 DraftPoint / DraftStep で表現し、型を分離する。
 */
export interface ScenarioPoint {
    id: number;
    scene: string;
    shot_type: "hiki" | "yori";
    shooting_point: string | null;
    narration: string;
    subtitle_primary: string | null;
    subtitle_secondary: string;
    material_type: CutMaterialType | null;
    static_display_seconds: number | null;
}

/** PHP: ScenarioStepData と対 (step 行 + 配下の points) */
export interface ScenarioStep extends ScenarioPoint {
    points: ScenarioPoint[];
}

/** PHP: ScenarioDocumentData と対 (edit props / PUT 成功応答の共通 shape) */
export interface ScenarioDocument {
    scenario_version: number;
    steps: ScenarioStep[];
}

/**
 * 編集中の作業コピー (未保存行は id: null)。
 * clientKey は each の安定 key 用のクライアント専用識別子。
 * serializeSteps() には含めるが PUT payload (payloadSteps) には含めない (サーバ非公開)。
 */
export type DraftPoint = Omit<ScenarioPoint, "id"> & { id: number | null; clientKey: string };
export type DraftStep = Omit<ScenarioStep, "id" | "points"> & {
    id: number | null;
    clientKey: string;
    points: DraftPoint[];
};

/** PHP: App\Enums\Manual\JobStatus と対 (値集合を一致させる) */
export type AnalysisJobStatus = "queued" | "running" | "succeeded" | "failed";

/** PHP: App\Enums\Manual\AnalysisStep と対 */
export type AnalysisStep = "extract" | "decompose" | "generate";

/** AnalysisStep の表示ラベル (AnalysisPanel の進捗表示) */
export const ANALYSIS_STEP_LABELS: Record<AnalysisStep, string> = {
    extract: "手順書を読み取り中",
    decompose: "作業を分解中",
    generate: "シナリオを生成中",
};

/** PHP: AnalysisJobData::toArray() と対 (show props / ポーリング / analyze 201 の共通 shape) */
export interface AnalysisJobProps {
    id: number;
    status: AnalysisJobStatus;
    step: AnalysisStep | null;
    progress: number | null;
    error: string | null;
    manual_status: VideoManualStatus;
}

/** PHP: VideoManualController::show の analysis props と対 */
export interface AnalysisProps {
    job: AnalysisJobProps | null;
    hasDocument: boolean;
}

/** PHP: App\Enums\Manual\AnalysisConflictType と対 */
export type AnalysisConflictType = "in_flight" | "status_not_analyzable";

/** PHP: AnalysisConflictResource と対 (analyze 409 ボディ。code 厳格一致) */
export interface AnalysisConflictBody {
    code: "analysis_conflict";
    conflict_type: AnalysisConflictType;
    message: string;
}

/** PHP: InsufficientTicketsResource と対 (analyze 402 ボディ。code 厳格一致) */
export interface InsufficientTicketsBody {
    code: "insufficient_tickets";
    message: string;
}

/** PHP: App\Enums\Manual\RenderKind と対 (値集合同期テストあり = ManualEnumTsSyncInvariantTest) */
export type RenderKind = "render" | "preview";

/** PHP: App\Enums\Manual\RenderStep と対 (値集合同期テストあり) */
export type RenderStep = "compose" | "concat";

/** PHP: App\Enums\Manual\RenderErrorCode と対 (値集合同期テストあり。CTA 分岐はこの code で行う) */
export type RenderErrorCode = "scenario_version_changed" | "timeout" | "internal";

/** RenderStep の表示ラベル (RenderPanel の進捗表示) */
export const RENDER_STEP_LABELS: Record<RenderStep, string> = {
    compose: "カットを合成中",
    concat: "動画を連結中",
};

/** PHP: RenderJobData::toArray() と対 (show props / ポーリング / トリガー 201 の共通 shape) */
export interface RenderJobProps {
    id: number;
    kind: RenderKind;
    status: AnalysisJobStatus; // JobStatus 共用 (queued|running|succeeded|failed)
    step: RenderStep | null;
    progress: number | null;
    error: string | null;
    error_code: RenderErrorCode | null;
    manual_status: VideoManualStatus;
    /**
     * 生成物に含まれたプレースホルダ (黒背景) クリップ数。
     * null = その動画について言えることが無い (未完了 / T148 以前の succeeded 行)。
     * **null を 0 と同一視しない** (0 は「黒背景ゼロで生成された」という積極的な事実)。
     */
    placeholder_cut_count: number | null;
}

/** PHP: App\DataTransferObjects\Manual\TakeCoverageData::toProps() と対 */
export interface TakeCoverageProps {
    /** カット総数 */
    total_cuts: number;
    /** 使用できる採用テイクがないカット数 (**打ち切らない全件数**) */
    missing_count: number;
    /** 該当カットの表示ラベル (先頭 10 件で打ち切られる。件数は missing_count が正) */
    missing_labels: string[];
}

/** PHP: App\Enums\Manual\RenderConflictType と対 (値集合同期テストあり) */
export type RenderConflictType =
    | "in_flight"
    | "status_not_renderable"
    | "status_not_previewable"
    | "org_preview_limit";

/** PHP: RenderConflictResource と対 (render/preview 409 ボディ。code 厳格一致) */
export interface RenderConflictBody {
    code: "render_conflict";
    conflict_type: RenderConflictType;
    message: string;
}

/** PHP: VideoManualController::show の render props と対 */
export interface RenderProps {
    /** 最新 kind=render の job (無ければ null) */
    job: RenderJobProps | null;
    /** 最新 kind=preview の job (無ければ null) */
    previewJob: RenderJobProps | null;
    /**
     * 再生可能な最新 succeeded preview の job (無ければ null)。
     * 動画 URL と黒背景の注記が同一オブジェクトから出る (別世代の値で説明しないため)。
     */
    playbackJob: RenderJobProps | null;
    /**
     * 受け取れる完成動画の job (無ければ null)。
     * サーバが「published + download ability + 現行世代」を判定した結果そのものであり、
     * **UI 側で条件を再判定しない** (判断は props で 1 回)。
     */
    finishedJob: RenderJobProps | null;
    /**
     * 採用テイクの充足状況 (描画時点のスナップショット。常に最新ではない)。
     * 生成物の実績は playbackJob.placeholder_cut_count が語る (別概念なので混ぜない)。
     */
    coverage: TakeCoverageProps;
}

/** PHP: App\Enums\Manual\ScenarioConflictType と対 (discriminated union) */
export type ScenarioConflictType = "version_mismatch" | "rendering" | "analyzing";

/** PHP: ScenarioConflictResource と対 (409 ボディ。code 厳格一致で自分宛て応答のみ処理する) */
export interface ScenarioConflictBody {
    code: "scenario_conflict";
    conflict_type: ScenarioConflictType;
    message: string;
    current_version: number;
}

/**
 * PC テイク選択画面 (Manuals/Takes) の型。PHP 側 App\DataTransferObjects\Manual\
 * {TakeSelectionPageData, SelectableTakeData, CutTakeSummaryData} と対で保守する。
 * 撮影 PWA の types/capture.ts とは**別 shape** (PC は署名 URL の口を持たない)。
 */

/** PHP: App\Enums\Manual\TakeStatus と値集合を一致させる (literal union) */
export type SelectableTakeStatus = "uploading" | "processing" | "ready" | "failed";

/** PHP: App\Enums\Manual\MaterialType と値集合を一致させる */
export type CutMaterialType = "video" | "still";

/** テイクの状態ラベル (UI 共通)。satisfies でキー漏れをコンパイル時検出する */
export const TAKE_STATUS_LABELS = {
    uploading: "アップロード中",
    processing: "処理中",
    ready: "使用できます",
    failed: "失敗",
} as const satisfies Record<SelectableTakeStatus, string>;

/** 採用できる状態か (サーバ CaptureTakeService::adopt の ready 条件と一致させる) */
export const TAKE_ADOPTABLE_BY_STATUS = {
    uploading: false,
    processing: false,
    ready: true,
    failed: false,
} as const satisfies Record<SelectableTakeStatus, boolean>;

/** PHP: SelectableTakeData と対 */
export interface SelectableTake {
    id: number;
    status: SelectableTakeStatus;
    /** 登録された素材の**実体** (NOT NULL)。UI はこの値で <video> と <img> を出し分ける */
    material_type: CutMaterialType;
    size_bytes: number;
    duration_ms: number | null;
    comment: string | null;
    captured_at: string | null;
    sort_order: number;
    /** DL 済み (削除できない。押下前に理由を説明するために出す) */
    downloaded: boolean;
    /** サムネイル生成済みか。true のときだけ GET .../takes/{id}/thumbnail を表示に使う */
    has_thumbnail: boolean;
}

/** PHP: TakeSelectionPageData の cut キーと対 */
export interface TakeSelectionCut {
    id: number;
    type: "step" | "point";
    label: string;
    scene: string;
    narration: string;
    subtitle_primary: string | null;
    subtitle_secondary: string;
    /** カットの**計画** (未指定あり)。ファイル選択の accept 切替に使う */
    material_type: CutMaterialType | null;
    adopted: { id: number; status: SelectableTakeStatus; material_type: CutMaterialType } | null;
}

/** PHP: TakeSelectionPageData::toArray() 全体と対 (Manuals/Takes の props) */
export interface TakeSelectionPageProps {
    project: { id: number; name: string };
    manual: { id: number; title: string; status: VideoManualStatus };
    cut: TakeSelectionCut;
    takes: SelectableTake[];
}

/** PHP: CutTakeSummaryData と対 (シナリオ編集画面「動画」列の 1 カット分) */
export interface CutTakeSummary {
    cut_id: number;
    takes_count: number;
    adopted: {
        id: number;
        status: SelectableTakeStatus;
        /** サムネイル生成済みか。true のときだけ .../takes/{id}/thumbnail を表示に使う */
        has_thumbnail: boolean;
        /** 採用テイクの**実体**種別 (NOT NULL)。素材登録状況バッジの文言に使う */
        material_type: CutMaterialType;
    } | null;
}

```

