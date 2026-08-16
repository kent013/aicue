【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考えてから手を動かせ。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン
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
10. DESIGN.md準拠: design token 経由で参照する設計か、hex 直書きを増やさないか
11. Atomic Design準拠: atoms/molecules/organisms/features/templates/pages の単方向 import。アイコンは Lucide 前提で SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: take-thumbnail-hover-preview (テイクサムネイルのホバー自動再生)

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → `PromptDefense` → `GuardedPrompt` の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI**(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨 / `declare(strict_types=1)` + 日本語コメント
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript
- フロントは **DS token のみ** (DESIGN.md canonical / `ds-purity.test.ts`)、アイコンは `@lucide/svelte` のみ
- component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の**単方向 import**
  (`tests/js/architecture/atomic-import-graph.test.ts`)

## 概念設計リファレンス

- `devnotes/20260816-1757-take-thumbnail-hover-preview/conceptual-design.md` (Round 5 で APPROVED)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | `CutTakeSummaryData` に採用テイクの `has_thumbnail` を足す | `app/DataTransferObjects/Manual/CutTakeSummaryData.php` / `resources/js/types/manual.ts` | 高 |
| 2 | ホバー自動再生の component を新設する | `resources/js/components/features/manual/TakeHoverPreview.svelte` (新規) | 高 |
| 3 | シナリオ編集の動画列へ組み込む | `resources/js/components/features/manual/ScenarioEditor.svelte` | 高 |
| 4 | テスト整備 | `tests/Feature/Manual/ScenarioVideoColumnTest.php` / `tests/js/components/features/manual/TakeHoverPreview.test.ts` (新規) / `tests/js/components/features/manual/ScenarioEditor.test.ts` | 高 |

---

## 施策 1: `CutTakeSummaryData` に採用テイクの `has_thumbnail` を足す

### 変更箇所

- `app/DataTransferObjects/Manual/CutTakeSummaryData.php` (全体)
- `resources/js/types/manual.ts` (L384-389 `CutTakeSummary`)

### 波及変更

- TypeScript 型定義: `CutTakeSummary.adopted` に `has_thumbnail: boolean` を追加 (**必須**)
- API Resource/DTO: `CutTakeSummaryData` のみ。`SelectableTakeData` / `CaptureTakeData` は**触らない**
  (別 shape であり合流させない既存判断を維持する)
- テストファイル: `tests/Feature/Manual/ScenarioVideoColumnTest.php` に 4 ケース追加
- **クエリは変えない**。`VideoManualController::takeSummaries()` は既に `with('adoptedTake')` 済みで、
  `thumbnail_path` は takes 表の列なので**追加クエリ 0 本**。同ファイルは変更しない
- `AdoptedTakeReferenceInventory` の登録は**変更不要**。`CutTakeSummaryData.php` は既に
  `DifferentCriterion` で登録済みで、読む列が 1 つ増えても区分は変わらない
  (ready 判定を持ち込まないため。根拠文の意味も変わらない)

### 現行コード

```php
final readonly class CutTakeSummaryData
{
    public function __construct(
        public int $cutId,
        public int $takesCount,
        public ?int $adoptedId,
        public ?string $adoptedStatus,
    ) {}

    /** withCount('takes') + with('adoptedTake') 済みの cut から生成する */
    public static function fromCut(Cut $cut): self
    {
        $takesCount = $cut->getAttribute('takes_count');
        Assert::integer($takesCount, 'withCount(takes) 済みの cut を渡してください');
        $adopted = $cut->adoptedTake;

        return new self(
            cutId: $cut->id,
            takesCount: $takesCount,
            adoptedId: $adopted?->id,
            adoptedStatus: $adopted?->status->value,
        );
    }

    /**
     * @return array{cut_id: int, takes_count: int, adopted: array{id: int, status: string}|null}
     */
    public function toArray(): array
    {
        return [
            'cut_id' => $this->cutId,
            'takes_count' => $this->takesCount,
            // id と status は同時に決まる (両方 null か両方非 null)
            'adopted' => $this->adoptedId === null || $this->adoptedStatus === null
                ? null
                : ['id' => $this->adoptedId, 'status' => $this->adoptedStatus],
        ];
    }
}
```

### 変更後コード

```php
final readonly class CutTakeSummaryData
{
    public function __construct(
        public int $cutId,
        public int $takesCount,
        public ?int $adoptedId,
        public ?string $adoptedStatus,
        /**
         * 採用テイクのサムネイルが生成済みか。採用テイクが無いときは false。
         * ★ 生成は非同期なので、録画直後・生成失敗・過去分は false になる。
         *   true のときだけ画像 URL (capture.takes.thumbnail) を張る = 404 を踏まない。
         */
        public bool $adoptedHasThumbnail,
    ) {}

    /** withCount('takes') + with('adoptedTake') 済みの cut から生成する */
    public static function fromCut(Cut $cut): self
    {
        $takesCount = $cut->getAttribute('takes_count');
        Assert::integer($takesCount, 'withCount(takes) 済みの cut を渡してください');
        $adopted = $cut->adoptedTake;

        return new self(
            cutId: $cut->id,
            takesCount: $takesCount,
            adoptedId: $adopted?->id,
            adoptedStatus: $adopted?->status->value,
            // thumbnail_path は takes 表の列なので追加クエリは発生しない
            adoptedHasThumbnail: $adopted?->thumbnail_path !== null,
        );
    }

    /**
     * @return array{cut_id: int, takes_count: int,
     *   adopted: array{id: int, status: string, has_thumbnail: bool}|null}
     */
    public function toArray(): array
    {
        return [
            'cut_id' => $this->cutId,
            'takes_count' => $this->takesCount,
            // id と status は同時に決まる (両方 null か両方非 null)
            'adopted' => $this->adoptedId === null || $this->adoptedStatus === null
                ? null
                : [
                    'id' => $this->adoptedId,
                    'status' => $this->adoptedStatus,
                    'has_thumbnail' => $this->adoptedHasThumbnail,
                ],
        ];
    }
}
```

> **`$adopted?->thumbnail_path !== null` の意味**: `$adopted` が null のときは
> `null !== null` = `false` に落ちる。「採用テイクが無い ⇒ サムネイルも無い」で意味が一致するため、
> 三項で書き分けない。PHPStan level 10 でも `?->` の結果は `string|null` で `!== null` は `bool` に
> 確定するので narrowing 問題は起きない。

### TypeScript 型 (変更後)

```ts
/** PHP: CutTakeSummaryData と対 (シナリオ編集画面「動画」列の 1 カット分) */
export interface CutTakeSummary {
    cut_id: number;
    takes_count: number;
    adopted: {
        id: number;
        status: SelectableTakeStatus;
        /** サムネイル生成済みか。true のときだけ .../takes/{id}/thumbnail を表示に使う */
        has_thumbnail: boolean;
    } | null;
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`toArray(): array` + `@return` の array shape 更新)
- [x] null 安全 (`Assert::integer` は既存のまま。`?->` の結果を `!== null` で bool へ確定)
- [x] DTO を返している (配列返却は `toArray()` の Inertia props 化のみ。`response()->json()` は無い)
- [x] Generics の型パラメータ: `Collection::map` の callable 戻り値型は既存の
      `VideoManualController::takeSummaries()` の `@return list<array{...}>` を**同じ shape へ更新**する
      (**この 1 行だけコントローラも触る**。docblock を直さないと level 10 が食い違いを検出する)

> ⚠ 上表「変更ファイル」に `app/Http/Controllers/Projects/VideoManualController.php` を追加する
> (`takeSummaries()` の `@return` docblock 1 行のみ。実行コードは変更しない)。

### テスト計画

`tests/Feature/Manual/ScenarioVideoColumnTest.php` に追加 (既存テストは削除・上書きしない):

- [ ] 新規: `採用テイクにサムネイルがあれば adopted.has_thumbnail が true` —
      `Take::factory()->forCut($cut)->withThumbnail()->create()` を採用させ `true` を確認
- [ ] 新規: `採用テイクにサムネイルが無ければ adopted.has_thumbnail が false` —
      `withThumbnail()` を付けずに採用させ `false` を確認
- [ ] 新規: `採用テイクが無いカットは adopted が null のまま` (既存テストで担保済みだが、
      `has_thumbnail` 追加で shape が壊れていないことを `adopted` が `null` であることで確認)
- [ ] 新規: `非 ready の採用テイクでも adopted.status がそのまま出る` —
      `has_thumbnail` は status と独立であること (UI 側が `ready && has_thumbnail` で
      AND を取る前提を、サーバ側で先取りして潰していないことの固定)
- [ ] 既存の N+1 テスト (`cut を増やしてもクエリ本数が増えない`) が緑のままであること = 追加クエリ 0 本の証拠
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- 既存の `takeSummaries` shape に**キーが 1 つ増える**。破壊的ではない (既存キーは不変) が、
  TS 型を更新しないと `pnpm typecheck` が落ちる → 同じ PR で必ず更新する。
- `adopted.has_thumbnail` は**描画時点のスナップショット**である。サムネイル生成は非同期なので、
  採用直後にこの画面を開くと `false` になりうる。シナリオ編集画面には T183 の再取得スケジューラ
  (`ThumbnailRefreshScheduler`) を持ち込まない (撮影 PWA 専用の機構であり、編集画面に
  ポーリングを新設するのはこの施策の範囲外)。**その場合は今と同じ要約テキスト表示に落ちるだけ**で
  詰まない。この非対称は component の日本語コメントに残す。

---

## 施策 2: ホバー自動再生の component を新設する

### 変更箇所

- `resources/js/components/features/manual/TakeHoverPreview.svelte` (**新規**)

### 波及変更

- TypeScript 型定義: component の `Props` interface (新規ファイル内)。既存型の変更なし
- API Resource/DTO: なし (既存 endpoint をそのまま叩く)
- テストファイル: `tests/js/components/features/manual/TakeHoverPreview.test.ts` (**新規**)

### 設計の要点 (概念設計の確定事項)

| 項目 | 決定 |
|---|---|
| 既定表示 | `<img>` (静止サムネイル。`capture.takes.thumbnail`) |
| 起動条件 (3 つ全て) | `pointerType === "mouse"` / `event.buttons === 0` / `prefersReducedMotion() === false` |
| 滞留 | 200ms。満了時に **(a) タイマー無効化されていない (b) ホバー継続中 (c) reduced-motion でない** を再確認 |
| 再生開始 | **`play()` の明示呼び出し**。`autoplay` 属性は使わない |
| 属性 | `muted loop playsinline preload="metadata"`、`controls` なし、`poster` に同じサムネイル URL |
| 失敗 | 自動再生拒否 = `play()` の rejection / 取得・デコード失敗 = `error` イベント。**どちらも `stopPreview()`**。文言・トーストは出さない |
| 世代管理 | 失敗処理は**現在 mount されている video 要素と同一のときだけ**実行する |
| 停止条件 (5 つ) | `pointerleave` / `pointercancel` / `pointerdown` / component 破棄 / `visibilitychange` (非表示) |
| タッチ | 起動しない。ラッパの `<a>` (Inertia Link) がテイク選択画面へ運ぶ |
| キーボード | フォーカスで自動再生しない (Enter でリンクを辿れば `controls` 付きで観られる) |

### 現行コード

新規ファイルのため無し。既存の類似実装は `TakeThumbnail.svelte` (静止画のみ) と
`TakePreviewPanel.svelte` (`controls` 付き `<video>`)。**どちらも変更しない**
(役割が違うため合流させない)。

### 変更後コード

```svelte
<script lang="ts">
    import { onDestroy, onMount } from "svelte";
    import { Link } from "@inertiajs/svelte";
    import { Film } from "@lucide/svelte";
    import { prefersReducedMotion } from "@/lib/capture/panel-navigation";

    /**
     * サムネイルにマウスを載せている間だけ、そのテイクを**無音・ループ**で自動再生する
     * (doc/04 動画列「登録済みテイクはサムネイル表示 (ホバーで自動再生)」)。
     *
     * 設計上の約束 (誇張しない):
     * - <video> は**ホバー中しか DOM に存在しない**ので、**1 コンポーネントにつき高々 1 本**である。
     *   画面全体で 1 本に収まるのは「マウスが同時に 1 か所しかホバーできない」ことに依る性質で、
     *   この component が画面横断の相互排他を保証しているわけではない。
     * - **タッチ・ペンでは起動しない**。ホバーの無い環境ではリンク (タップ = 遷移) として働く。
     *   同じ場所のタップに「遷移」と「再生」の 2 つの意味を持たせない。
     * - `prefers-reduced-motion: reduce` では起動しない (静止画のまま)。
     * - 失敗は静かに静止画へ戻す。**エラー文言・トーストは出さない**
     *   (ホバーは補助的な確認手段であり、失敗が編集作業を妨げてはならない)。
     */
    interface Props {
        /** 静止サムネイルの URL (capture.takes.thumbnail)。未生成なら null */
        thumbnailUrl: string | null;
        /** 再生 URL (capture.takes.playback)。ready でなければ null */
        playbackUrl: string | null;
        /** クリック / タップの行き先 (テイク選択画面) */
        href: string;
        /** リンクの読み上げ名 (画像は装飾扱いで alt="") */
        label: string;
        testId?: string;
    }

    let { thumbnailUrl, playbackUrl, href, label, testId }: Props = $props();

    /** 滞留タイマー。null = 予約なし */
    let dwellTimer = $state<ReturnType<typeof setTimeout> | null>(null);
    /** ポインタがまだ載っているか (満了時の再確認に使う) */
    let hovering = $state(false);
    /** 再生中か = <video> を mount しているか */
    let playing = $state(false);
    /** 世代判定の基準。現在 mount されている video 要素 */
    let videoEl = $state<HTMLVideoElement | null>(null);

    const DWELL_MS = 200;

    /** 起動条件を満たすポインタか (タッチ・ペン / ボタン押下中は起動しない) */
    function isPreviewablePointer(event: PointerEvent): boolean {
        return event.pointerType === "mouse" && event.buttons === 0;
    }

    function onPointerEnter(event: PointerEvent): void {
        hovering = true;
        if (playbackUrl === null) return;
        if (!isPreviewablePointer(event)) return;
        if (prefersReducedMotion()) return;
        clearDwell();
        dwellTimer = setTimeout(startPreview, DWELL_MS);
    }

    /**
     * 満了時の再確認は 3 つだけ:
     * (a) タイマーが無効化されていない (pointerdown 等は clearDwell でここへ来なくする)
     * (b) ホバーが継続している
     * (c) reduced-motion でない (200ms の間に設定が変わることがある)
     * ★ ボタンの押下状態は**読み直さない**。pointerdown が停止条件として
     *   タイマーそのものを破棄することで保証する (過去のイベントを現在の状態の代理にしない)。
     */
    function startPreview(): void {
        dwellTimer = null;
        if (!hovering) return;
        if (prefersReducedMotion()) return;
        playing = true;
    }

    /** 停止 (冪等)。タイマー clear と video unmount を必ず両方行う */
    function stopPreview(): void {
        clearDwell();
        playing = false;
        videoEl = null;
    }

    function clearDwell(): void {
        if (dwellTimer === null) return;
        clearTimeout(dwellTimer);
        dwellTimer = null;
    }

    function onPointerLeave(): void {
        hovering = false;
        stopPreview();
    }

    /** mount 後に再生を開始する。開始の正本は play() で、autoplay 属性は使わない */
    function onVideoMounted(el: HTMLVideoElement): void {
        videoEl = el;
        el.muted = true; // 属性だけでなく property でも立てる (自動再生の許可条件)
        void el.play().catch(() => {
            // 自動再生ポリシーによる拒否。error イベントでは飛んでこない経路。
            // ★ 古い試行の rejection が新しい試行を止めないよう、要素の同一性で世代を判定する
            if (videoEl === el) stopPreview();
        });
    }

    /** 取得・デコード失敗。世代判定は play() の catch と同じ規則 */
    function onVideoError(event: Event): void {
        if (videoEl === event.currentTarget) stopPreview();
    }

    /** タブが隠れたら止める (見えない場所で再生し続けない) */
    function onVisibilityChange(): void {
        if (document.visibilityState === "hidden") stopPreview();
    }

    onMount(() => {
        document.addEventListener("visibilitychange", onVisibilityChange);
    });

    onDestroy(() => {
        // listener を必ず外し、予約済みタイマーも捨てる
        document.removeEventListener("visibilitychange", onVisibilityChange);
        clearDwell();
    });
</script>

<Link
    {href}
    class="relative block size-16 shrink-0 overflow-hidden rounded-md border border-border bg-neutral"
    aria-label={label}
    data-testid={testId}
    onpointerenter={onPointerEnter}
    onpointerleave={onPointerLeave}
    onpointercancel={onPointerLeave}
    onpointerdown={stopPreview}
>
    {#if playing && playbackUrl !== null}
        <!-- svelte-ignore a11y_media_has_caption (無音・装飾のホバープレビュー) -->
        <video
            {@attach onVideoMounted}
            src={playbackUrl}
            poster={thumbnailUrl}
            muted
            loop
            playsinline
            preload="metadata"
            class="size-full object-cover"
            aria-hidden="true"
            onerror={onVideoError}
            data-testid={testId ? `${testId}-video` : undefined}
        ></video>
    {:else if thumbnailUrl !== null}
        <img
            src={thumbnailUrl}
            alt=""
            loading="lazy"
            decoding="async"
            class="size-full object-cover"
            data-testid={testId ? `${testId}-image` : undefined}
        />
    {:else}
        <span class="flex size-full items-center justify-center" aria-hidden="true">
            <Film class="size-4 text-text-secondary" />
        </span>
    {/if}
</Link>
```

> **`{@attach ...}` について**: Svelte 5 の attachment (要素が mount された直後に呼ばれる) を使う。
> `package.json` の `svelte` は `^5.56.2` で attachment (5.29 で導入) が使えることを実読で確認済み。

### PHPStan適合チェック

- 該当なし (フロントのみ)。`pnpm typecheck` / `pnpm lint` / `pnpm test` が対象。
- DS purity: 色・角丸・タイポは token 由来のクラス (`border-border` / `bg-neutral` /
  `text-text-secondary` / `rounded-md`) のみ。**hex 直書き・inline style は無い**。
- アイコンは `@lucide/svelte` の `Film` のみ (SVG 直書きなし)。
- Atomic 階層: `features/manual` から参照するのは `@/lib/*` と `@inertiajs/svelte` と
  `@lucide/svelte` だけで、**下層 (atoms/molecules) からの参照も上層 (pages) への参照も作らない**。

### テスト計画

`tests/js/components/features/manual/TakeHoverPreview.test.ts` (**新規**):

- [ ] 既定は `<img>` を描画し `<video>` は存在しない
- [ ] `pointerType: "mouse"` の `pointerenter` → 200ms 経過で `<video>` が現れる (fake timers)
- [ ] `pointerType: "touch"` の `pointerenter` → 200ms 経っても `<video>` は現れない
- [ ] `buttons: 1` (押下中) の `pointerenter` → `<video>` は現れない
- [ ] `pointerenter` の 100ms 後に `pointerdown` → 満了しても `<video>` は現れない (**D&D の競合**)
- [ ] `prefersReducedMotion()` を `true` にモック → `<video>` は現れない
- [ ] 満了直前に reduced-motion が `true` へ変わったら `<video>` は現れない (満了時の再評価)
- [ ] `pointerleave` で `<video>` が消え `<img>` へ戻る
- [ ] `visibilitychange` (hidden) で `<video>` が消える
- [ ] `play()` が reject → `<video>` が消え `<img>` へ戻る。**トーストが 1 件も出ない**
- [ ] `error` イベント → `<video>` が消え `<img>` へ戻る
- [ ] **世代判定**: A を再生 → `pointerleave` → 再ホバーで B を mount → A の rejection が遅れて到着しても
      B は消えない
- [ ] `stopPreview()` の**冪等性**: `pointerleave` を 2 回続けても例外を出さず状態が壊れない
- [ ] unmount 後に `visibilitychange` を発火しても例外が出ない (listener が外れている)
- [ ] `playbackUrl === null` (非 ready) のときはホバーしても `<video>` を作らない
- [ ] `thumbnailUrl === null` のときは `<img>` ではなく Film アイコンのプレースホルダを出す

### リスク

- **jsdom は `HTMLMediaElement.play()` を実装していない**。テストでは
  `HTMLMediaElement.prototype.play` を `vi.fn()` で差し替える (既存 `TakePreviewDialog.test.ts` /
  `RenderPanel.test.ts` の作法を確認して合わせる)。差し替えを忘れると
  「play is not a function」で落ちるため、テスト冒頭の共通 setup に置く。
- ホバーのたびに `/playback` の 302 が 1 本走る。連続ホバーで署名 URL の発行回数が増えるが、
  **200ms の滞留**で素通りは抑制され、発行はユーザーの意図的操作と 1:1 で対応する。
  署名 URL のキャッシュは**しない** (`no-store, private` の既存判断を緩めない)。
- `<a>` (Inertia `Link`) の中に `<video>` を入れる。動画上のクリックはリンクの遷移になる
  (`controls` を出していないので再生操作と競合しない)。これは意図した挙動である。

---

## 施策 3: シナリオ編集の動画列へ組み込む

### 変更箇所

- `resources/js/components/features/manual/ScenarioEditor.svelte`
  - import 追加 (`TakeHoverPreview` / `takeUrl`)
  - `videoCell` snippet (L1019-1050) の書き換え

### 波及変更

- TypeScript 型定義: 施策 1 で更新済みの `CutTakeSummary` をそのまま読む (追加変更なし)
- API Resource/DTO: なし
- テストファイル: `tests/js/components/features/manual/ScenarioEditor.test.ts` に
  「採用テイクがあればサムネイルが出る / 無ければ出ない」を追加。
  既存の `video-cell-count` / `video-cell-link` / `video-cell-unsaved` の testId は**変えない**
  (既存テストを壊さない)

### 現行コード

```svelte
{#snippet videoCell(cutId: number | null, testIdSuffix: string)}
    <!-- 動画列 (doc/04)。未保存行はリンクを出さず、押せるのに詰むボタンを作らない。
         行 Card の中に角丸カードを入れ子にせず、区切り線で段を分ける -->
    <div class="mt-3 border-t border-border pt-3" data-testid={`video-cell-${testIdSuffix}`}>
        <p class="text-caption text-text-secondary">動画</p>
        {#if cutId === null}
            <p class="mt-1 text-caption text-text-secondary" data-testid="video-cell-unsaved">
                「シナリオを更新」で保存すると、このカットに動画を登録できます。
            </p>
        {:else}
            {@const summary = summaryByCutId.get(cutId)}
            <p class="mt-1 flex items-center gap-2 text-caption text-text">
                <span data-testid="video-cell-count">テイク {summary?.takes_count ?? 0} 件</span>
                {#if summary?.adopted}
                    <Badge tone="primary" testId="video-cell-adopted">採用済み</Badge>
                {/if}
            </p>
            <div class="mt-2">
                <Button
                    variant="neutral"
                    size="sm"
                    href={`/projects/${projectId}/manuals/${manualId}/cuts/${cutId}/takes`}
                    inertia
                    testId="video-cell-link"
                >
                    <Film class="size-4" aria-hidden="true" />
                    {summary && summary.takes_count > 0 ? "テイクを選択" : "ファイルの選択"}
                </Button>
            </div>
        {/if}
    </div>
{/snippet}
```

### 変更後コード

```svelte
{#snippet videoCell(cutId: number | null, testIdSuffix: string)}
    <!-- 動画列 (doc/04)。未保存行はリンクを出さず、押せるのに詰むボタンを作らない。
         行 Card の中に角丸カードを入れ子にせず、区切り線で段を分ける。

         ★ サムネイルを出すのは**採用テイク 1 件だけ**である (doc/04 の
           「登録済みテイクはサムネイル表示」に対する意図的な狭め)。動画列の意味は
           「このカットの成果は何か」であり、候補テイクを見比べるのはテイク選択画面の仕事。
           未採用テイクの一覧表示は残ギャップとして扱う
           (devnotes/20260816-1757-take-thumbnail-hover-preview/conceptual-design.md)。
         ★ has_thumbnail は**描画時点のスナップショット**である。生成は非同期なので、
           採用直後は false になりうる。その場合は今までどおり要約テキストだけになる
           (詰まないので、この画面に再取得ポーリングは持ち込まない)。 -->
    <div class="mt-3 border-t border-border pt-3" data-testid={`video-cell-${testIdSuffix}`}>
        <p class="text-caption text-text-secondary">動画</p>
        {#if cutId === null}
            <p class="mt-1 text-caption text-text-secondary" data-testid="video-cell-unsaved">
                「シナリオを更新」で保存すると、このカットに動画を登録できます。
            </p>
        {:else}
            {@const summary = summaryByCutId.get(cutId)}
            {@const takesHref = `/projects/${projectId}/manuals/${manualId}/cuts/${cutId}/takes`}
            {@const adopted = summary?.adopted ?? null}
            {@const previewable = adopted !== null && adopted.status === "ready"}
            <div class="mt-1 flex items-start gap-2">
                {#if adopted !== null && previewable && adopted.has_thumbnail}
                    <TakeHoverPreview
                        thumbnailUrl={takeUrl(
                            { projectId, manualId, cutId },
                            adopted.id,
                            "/thumbnail",
                        )}
                        playbackUrl={takeUrl({ projectId, manualId, cutId }, adopted.id, "/playback")}
                        href={takesHref}
                        label="採用テイクを開く"
                        testId={`video-cell-preview-${testIdSuffix}`}
                    />
                {/if}
                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-2 text-caption text-text">
                        <span data-testid="video-cell-count">
                            テイク {summary?.takes_count ?? 0} 件
                        </span>
                        {#if adopted !== null}
                            <Badge tone="primary" testId="video-cell-adopted">採用済み</Badge>
                        {/if}
                    </p>
                    <div class="mt-2">
                        <Button
                            variant="neutral"
                            size="sm"
                            href={takesHref}
                            inertia
                            testId="video-cell-link"
                        >
                            <Film class="size-4" aria-hidden="true" />
                            {summary && summary.takes_count > 0 ? "テイクを選択" : "ファイルの選択"}
                        </Button>
                    </div>
                </div>
            </div>
        {/if}
    </div>
{/snippet}
```

追加 import:

```ts
import TakeHoverPreview from "@/components/features/manual/TakeHoverPreview.svelte";
import { takeUrl } from "@/lib/capture/take-endpoints";
```

> **サムネイルを出す条件が 3 つある理由**: `adopted !== null` (採用がある) /
> `status === "ready"` (`/thumbnail` も `/playback` も ready 以外は 404) /
> `has_thumbnail` (未生成は `/thumbnail` が 404)。**サーバが 404 を返す状態へは
> 最初から URL を張らない** — 既存 `TakePickerList` / `TakePreviewPanel` と同じ判断規則である。

> **禁止事項 8 (disabled にしない) との関係**: この施策は**ボタンを disabled にしない**。
> サムネイルが出ないケースでも「テイクを選択 / ファイルの選択」ボタンは今までどおり常に押せる。
> 条件未充足で消えるのは**補助的なサムネイル表示だけ**であり、操作を塞いでいない。

### PHPStan適合チェック

- 該当なし (フロントのみ)。`pnpm typecheck` は `CutTakeSummary.adopted.has_thumbnail` の
  追加で型が通ること、`adopted` の narrowing (`adopted !== null` の後で `adopted.id` を読む) が
  通ることを確認する。

### テスト計画

`tests/js/components/features/manual/ScenarioEditor.test.ts` に追加:

- [ ] 新規: `採用テイクが ready かつ has_thumbnail のとき動画列にサムネイルが出る`
      (`video-cell-preview-step-0` が存在し、`-image` が `/thumbnail` を指す)
- [ ] 新規: `採用テイクが無いカットにはサムネイルが出ない` (要素が存在しない)
- [ ] 新規: `has_thumbnail=false の採用テイクにはサムネイルが出ない`
- [ ] 新規: `非 ready の採用テイクにはサムネイルが出ない` (404 になる URL を張らないことの固定)
- [ ] 新規: `サムネイルが出ない場合でも「テイクを選択」ボタンは押せる` (禁止事項 8 の固定)
- [ ] 既存: `video-cell-count` / `video-cell-adopted` / `video-cell-link` / `video-cell-unsaved` の
      既存アサーションが緑のままであること (**既存テストは削除・上書きしない**)
- [ ] `tests/js/pages/ManualsEdit.test.ts` が緑のままであること (props shape 追加の影響確認)

### リスク

- **D&D との干渉**: 行の並べ替えは `DragHandle` の `pointerdown` から始まり
  `lib/dnd/pointer-drag.ts` が `setPointerCapture()` を張る。加えて本 component 側で
  `buttons !== 0` と `pointerdown` の両方を停止条件にしているため、ドラッグ中に再生が始まる経路は
  閉じている (テスト計画にケースあり)。ただし**「ブラウザ実機で並べ替えの体感が変わらないこと」は
  自動テストでは保証しない** — 実装時に手で 1 度確認する。
- **行の縦幅が増える**。サムネイルは `size-16` (64px) で、既存の要約テキスト + ボタンの段
  (おおよそ 60px) とほぼ同じ高さに収まるため、増分は小さい。採用テイクが無い行は**今と同じ**。
- 1 画面に多数のカットが並ぶと `<img>` の数が増える。`loading="lazy"` を付けているので
  画面外は取得されない (既存 `TakeThumbnail` / `TakeStrip` と同じ作法)。

---

## 施策 4: テスト整備 (施策 1-3 の計画をまとめたもの)

| ファイル | 種別 | 追加内容 |
|---|---|---|
| `tests/Feature/Manual/ScenarioVideoColumnTest.php` | Pest Feature | `has_thumbnail` の 4 ケース (採用なし / ready+あり / ready+なし / 非 ready)。既存 N+1 テストは変更せず緑を維持 |
| `tests/js/components/features/manual/TakeHoverPreview.test.ts` | Vitest (新規) | 施策 2 の 15 ケース |
| `tests/js/components/features/manual/ScenarioEditor.test.ts` | Vitest | 施策 3 の 5 ケース追加 |

- 新しいモデル・新しい Factory は**追加しない** (`TakeFactory::withThumbnail()` が既にある)。
- Architecture テストへの新規登録は**不要**。理由:
  - `AdoptedTakeReferenceInventory`: `CutTakeSummaryData.php` は登録済みで区分も変わらない
    (ready 判定を持ち込まない)。新規ファイルは PHP ではない
  - `NestedRouteIdorDefenseTest` / `ControllerAuthorizationGateTest`: **route を 1 本も足さない**
  - `ThrottleCoverageInventoryTest`: 同上
  - `ScenarioWritePathInventoryTest`: `cuts` / `scenario_version` / `status` を**書かない** (読み取りのみ)
  - `atomic-import-graph.test.ts` / `ds-purity.test.ts` / `lucide-scoped-import.test.ts`:
    **既存の deny-by-default が新規ファイルを自動的に走査する** (登録作業は不要。緑であることを確認する)

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 変更点が 4 ファイル (うち新規 2) と局所的で、既存の endpoint / route / 認可 / クエリを 1 つも変えない。DTO へのキー追加は後方互換 (既存キー不変) であり、段階的に main へ載せても他タスクの動作を壊さない。standalone にするほどの構造変更が無い。 |
| 競合リスク | `ScenarioEditor.svelte` は T185 (D&D 並べ替え) が直近で触った大きいファイルで、他タスクが同時に触ると衝突しうる。ただし本施策が触るのは `videoCell` snippet と import 行だけで、drag 関連コードには一切触れない。`types/manual.ts` の `CutTakeSummary` は本施策以外に触る予定が無い。`CutTakeSummaryData.php` も同様。 |

## 実装順序

1. 施策 1 (DTO + TS 型) → `composer phpstan` / `pnpm typecheck` が通ることを確認
2. 施策 1 のテストを**先に**書いて fail を見る (テストファースト。思考原則 5)
3. 施策 2 (component 新規 + テスト)。component 単体で緑にする
4. 施策 3 (組み込み + テスト)
5. 全検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`
6. 実機で 1 度、シナリオ編集画面のホバー再生と D&D の並べ替えを手で確認する

## 前提の確認結果 (設計時に実読で確定済み)

- **`{@attach}` は使える**: `package.json` の `svelte` は `^5.56.2` で、attachment は 5.29 で入った機能。
  代替 (`bind:this` + `$effect`) へ落とす必要は無い。
- **jsdom の `HTMLMediaElement` スタブは既存の作法をそのまま使う**:
  `tests/js/components/features/capture/TakePreviewDialog.test.ts` L48-51 が
  `vi.spyOn(HTMLMediaElement.prototype, "play").mockResolvedValue(undefined)` /
  `"pause"` / `"load"` の 3 本を `beforeEach` で張っている。新規テストも同じ 3 本を張り、
  拒否のテストだけ `mockRejectedValueOnce(new DOMException("NotAllowedError"))` で上書きする。

---

## 関連する現行コード

### app/Http/Controllers/Projects/VideoManualController.php (takeSummaries)

```php
    /**
     * 動画列用のカット別テイク要約。
     *
     * cut 件数に依存しない**定数本のクエリ**で取る (withCount は cuts の SELECT に畳まれ、
     * adoptedTake は eager load の 1 本。cut ごとの追加クエリ = N+1 を作らない)。
     * 並びは CutSequencer と同じ (sort_order, id) にする (同値 sort_order で揺れないため)。
     *
     * @return list<array{cut_id: int, takes_count: int, adopted: array{id: int, status: string}|null}>
     */
    private function takeSummaries(VideoManual $manual): array
    {
        return array_values($manual->cuts()
            ->withCount('takes')
            ->with('adoptedTake')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (Cut $cut): array => CutTakeSummaryData::fromCut($cut)->toArray())
            ->all());
    }
```

### app/Http/Controllers/Capture/CaptureTakeController.php (playback / thumbnail)

```php
    /** 302 → S3 署名 URL。認可より前に 404 の 2 層 guard。Cache-Control: no-store, private */
    public function playback(Request $request, Project $project, VideoManual $manual, Cut $cut, Take $take, TakeObjectStorage $storage): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('preview', $take);
        if ($take->status !== TakeStatus::Ready) { abort(404); }
        return redirect()->away($storage->temporaryPlaybackUrl($take->video_path))
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    public function thumbnail(Request $request, Project $project, VideoManual $manual, Cut $cut, Take $take, TakeObjectStorage $storage): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('preview', $take);
        if ($take->status !== TakeStatus::Ready) { abort(404); }
        $path = $take->thumbnail_path;
        if ($path === null) { abort(404); }
        return redirect()->away($storage->temporaryThumbnailUrl($path))
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }
```

route (routes/web.php。認可は意図的に非対称 = 画面は編集者限定 / takes.* は撮影者にも開く):

```php
Route::middleware(['require-active-subscription', 'project.in-current-org'])
    ->prefix('app')->as('capture.')->group(function (): void {
        Route::scopeBindings()->group(function (): void {
            Route::get('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback', [CaptureTakeController::class, 'playback'])->name('takes.playback');
            Route::get('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/thumbnail', [CaptureTakeController::class, 'thumbnail'])->name('takes.thumbnail');
        });
    });
```

### resources/js/lib/capture/take-endpoints.ts

```ts
export function cutTakesUrl({ projectId, manualId, cutId }: TakeEndpointTarget): string {
    return `/app/projects/${projectId}/manuals/${manualId}/cuts/${cutId}/takes`;
}
export function takeUrl(target: TakeEndpointTarget, takeId: number, suffix = ""): string {
    return `${cutTakesUrl(target)}/${takeId}${suffix}`;
}
```

### resources/js/lib/capture/panel-navigation.ts

```ts
/** ブラウザ側でのみ評価する。SSR / matchMedia 非対応では true (= アニメーションしない) に倒す */
export function prefersReducedMotion(): boolean {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") return true;
    return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}
```

### resources/js/types/manual.ts (現行)

```ts
export type SelectableTakeStatus = "uploading" | "processing" | "ready" | "failed";

/** PHP: CutTakeSummaryData と対 (シナリオ編集画面「動画」列の 1 カット分) */
export interface CutTakeSummary {
    cut_id: number;
    takes_count: number;
    adopted: { id: number; status: SelectableTakeStatus } | null;
}
```

### resources/js/components/features/manual/TakeThumbnail.svelte (既存。今回は変更しない)

```svelte
<script lang="ts">
    import { Film } from "@lucide/svelte";
    import { TAKE_STATUS_LABELS, type SelectableTakeStatus } from "@/types/manual";
    interface Props {
        index: number;
        status: SelectableTakeStatus;
        durationMs: number | null;
        thumbnailUrl: string | null;
        size?: "sm" | "lg";
        testId?: string;
    }
    let { index, status, durationMs, thumbnailUrl, size = "sm", testId }: Props = $props();
    const boxClass = $derived(size === "sm" ? "size-16" : "aspect-video w-full");
</script>

{#if thumbnailUrl !== null}
    <img src={thumbnailUrl} alt="" loading="lazy" decoding="async"
        class="{boxClass} shrink-0 rounded-md border border-border object-cover" data-testid={testId} />
{:else}
    <div class="{boxClass} flex shrink-0 flex-col items-center justify-center gap-1 rounded-md border border-border bg-neutral text-text-secondary" data-testid={testId}>
        <Film class="size-4" aria-hidden="true" />
        <span class="text-caption">テイク {index + 1}</span>
    </div>
{/if}
```

### resources/js/components/atoms/Button.svelte (href + inertia のとき Link を描画する)

```svelte
{#if href !== undefined && inertia}
    <Link {href} class={computedClass} aria-label={ariaLabel} data-testid={testId} onclick={handleAnchorClick}>
        {@render content()}
    </Link>
{:else if href !== undefined}
    <a {href} {target} rel={computedRel} class={computedClass} ...>
```

### tests/Feature/Manual/ScenarioVideoColumnTest.php (既存。削除・上書きしない)

```php
test('takeSummaries に全カット分の要約が sort_order 順で載る', function (): void { /* ... */ });
test('採用テイクのあるカットは adopted.id / adopted.status が入る', function (): void { /* ... */ });
test('takeSummaries のキーに採用テイク外部キーの識別子が現れない (gate 回避の命名の固定)', function (): void {
    $summaries = json_encode($response->viewData('page')['props']['takeSummaries']);
    expect($summaries)->toBeString()->not->toContain('adopted_take_id');
});
test('cut を増やしてもクエリ本数が増えない (N+1 を作らない)', function (): void { /* ... */ });
```

### database/factories/TakeFactory.php (抜粋)

```php
    /** サムネイル生成済み (容量集計・一覧表示のテスト用) */
    public function withThumbnail(int $sizeBytes = 40_000): static
    {
        return $this->state(fn (): array => [
            'thumbnail_path' => 'takes/thumbnails/'.fake()->uuid().'.jpg',
            'thumbnail_size_bytes' => $sizeBytes,
        ]);
    }
```

### app/Support/Security/AdoptedTakeReferenceInventory.php (既存登録。今回は変更不要と判断した)

```php
    'DataTransferObjects/Manual/CutTakeSummaryData.php' => [
        'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
        'rationale' => 'シナリオ編集画面の動画列が、カットごとに採用テイクの id と status を'
            .'表示するために読むだけで ready 判定はしない。レンダの充足判定'
            .'(AdoptedReadyTakeCoverage) とは基準が違うため意図的に統合しない。',
    ],
```

（ドメイン規約 12: 「採用済みかつ ready のテイクを持つか」の判定式を書いてよいのは
`Services/Manual/AdoptedReadyTakeCoverage` ただ 1 ファイルである。`adoptedTake` を参照する
`app/` 配下のファイルは上記 inventory へ区分と根拠付きで登録が必須。）

---

上記の詳細設計をレビューしてください。とくに次を確認してください。

1. `CutTakeSummaryData` に `has_thumbnail` を足すことが、ドメイン規約 12 (採用テイク充足判定の単一化) の
   `AdoptedTakeReferenceInventory` 登録区分 (`DifferentCriterion`) を変えずに済むという判断は妥当か。
   UI 側で `status === "ready" && has_thumbnail` の AND を取ることは「ready 判定を持ち込んだ」ことに
   なるのか、それとも表示条件として別概念か。
2. Svelte component のロジック (滞留タイマー・世代判定・停止条件・reduced-motion 再評価) に
   競合や漏れが残っていないか。
3. テスト計画の網羅性と、Architecture テストへの新規登録が不要という判断の妥当性。
4. セキュリティ: 署名 URL を props に載せず 302 endpoint を経由する設計に穴は無いか。
