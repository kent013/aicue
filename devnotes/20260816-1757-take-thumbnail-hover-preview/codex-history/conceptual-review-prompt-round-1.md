【アプリの使命 (North Star) — AGENTS.md より】

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: take-thumbnail-hover-preview (テイクサムネイルのホバー自動再生)

## 背景・課題

`doc/04` シナリオ編集画面の「動画列」要件は次の 1 文である。

> **動画列**: 登録済みテイクはサムネイル表示（ホバーで自動再生）。サムネイルまたは「ファイルの選択」からテイク選択画面へ。

現行実装を読んだ結果、この 1 文は **2 つとも未充足**であることが分かった。

| 面 | 現状 | 出典 |
|---|---|---|
| シナリオ編集の動画列 | **サムネイルが無い**。「テイク N 件」+「採用済み」バッジ + 遷移ボタンだけ | `resources/js/components/features/manual/ScenarioEditor.svelte` L1019-1050 (`videoCell` snippet) |
| PC テイク選択画面 (左ペイン) | 静止サムネイルあり。ホバー再生は無い | `components/features/manual/TakePickerList.svelte` / `TakeThumbnail.svelte` |
| 撮影 PWA の TakeStrip | 静止サムネイルあり。ホバー再生は無い | `components/features/capture/TakeStrip.svelte` L288-308 |

つまり**課題の所在は当初の想定と異なる**。動画列は「サムネイル表示」の段階から欠けており、
ホバー自動再生だけを足せる面ではない。T183 (サムネイル生成・配信) と T184 (動画列・PC 選択画面) は
入っているが、動画列は要約テキストのままである。

編集者の実際の困りごとは「シナリオを直しながら、このカットに何が撮れているかを**画面遷移せずに**
確かめたい」である。現状はカットごとにテイク選択画面へ遷移し、戻ってくるしかない。

## 改善アイデア

**シナリオ編集画面の動画列にだけ**、採用テイクのサムネイルを出し、
マウスを載せている間だけ**無音・ループ**で自動再生する。ポインタが外れたら静止画へ戻す。
サムネイル自体をテイク選択画面へのリンクにする (doc/04 の「サムネイルから…テイク選択画面へ」も同時に満たす)。

### 対象の面を 1 つに絞る判断

| 候補 | 採否 | 根拠 |
|---|---|---|
| **シナリオ編集の動画列** | **採用** | doc/04 が名指ししている面。ここには**動画を見る手段が 1 つも無い**ため、ホバー再生が節約するのは「ページ遷移 1 往復」であり価値が最大。 |
| PC テイク選択画面 (TakePickerList) | 見送り | 同一画面の中央ペインに `controls` 付きの本物のプレイヤーが既にあり (`TakePreviewPanel.svelte`)、左の一覧をクリックすれば 1 クリックで再生できる。ホバー再生が節約するのは 1 クリックだけで、動画要素を増やす対価に見合わない。 |
| 撮影 PWA の TakeStrip | 見送り | **ホバーが存在しない**面 (スマホ・タッチ専用)。撮影者は録画直後に自分の手元で確認しており、`TakePreviewDialog` も既にある。 |

「全部に出す」ことはしない (思考原則 2)。**1 面に閉じる**ことで、同時に生きる `<video>` の
上限も構造的に 1 本になる (後述)。

### 何を代表として映すか — 「採用テイク」に限る

動画列に出すサムネイルは **そのカットの採用テイク (`adoptedTake`) の 1 件だけ**とする。
採用テイクが無いカットは今の要約テキストのままで、サムネイルを出さない。

- 動画列の意味は「**このカットの成果は何か**」である。候補テイクを見比べて選ぶのはテイク選択画面の仕事で、
  動画列にそれを持ち込むと画面の役割が二重になる (思考原則 4)。
- 「未採用テイクの中から代表を 1 つ選ぶ規則」は**新しい概念**であり、今は誰も必要としていない (思考原則 2)。
- 実装上も、動画列の要約は既に `with('adoptedTake')` で eager load 済みなので
  **追加クエリが 1 本も要らない** (`VideoManualController::takeSummaries()`)。
- doc/04 の「登録済みテイクはサムネイル表示」に対しては**意図的な狭め**である。この判断は詳細設計に
  明記し、後から広げたくなったときに根拠を読み直せるようにする。

## 期待効果

- **使命への貢献**: 「編集ゼロ」。編集者がシナリオ (台本) と撮れ高を**同じ画面で**突き合わせられるようになり、
  カットの文言を直すか撮り直すかの判断が 1 画面で完結する。ページ遷移の往復が消える。
- doc/04 動画列の要件 2 点 (サムネイル表示 / ホバーで自動再生) と「サムネイルから選択画面へ」を同時に満たす。
- 静止画では区別できない「本当に狙った動きが撮れているか」を、押す前に確認できる。

## 実装方針（概要）

### 1. 動画列に採用テイクのサムネイルを出す (サーバ側は 1 フィールドだけ)

- `CutTakeSummaryData` の `adopted` に `has_thumbnail` を 1 つ足す
  (`$adopted->thumbnail_path !== null`)。**新しいクエリ・新しい relation は足さない**。
- TS 側 `CutTakeSummary.adopted` に同名フィールドを足す (`types/manual.ts`)。
- 画像 URL は既存の `capture.takes.thumbnail` (302 → 署名 URL)。URL 組み立ては既存の
  `lib/capture/take-endpoints.ts#takeUrl` をそのまま使う (規則を 2 か所に持たない)。

### 2. ホバー自動再生を担う小さな component を 1 つ作る

`resources/js/components/features/manual/TakeHoverPreview.svelte` (features/manual 層)。

- 既定は `<img>` (静止サムネイル)。
- `pointerenter` (かつ `pointerType === "mouse"`) から **200ms の滞留**後に `<video>` を差し替えで mount。
  `muted` / `loop` / `playsinline` / `controls` なし / `poster` に同じサムネイル URL。
- `pointerleave` / `pointercancel` で即座に unmount → 静止画へ戻る。
- `<video>` は**ホバー中しか DOM に存在しない**。マウスは同時に 1 か所しかホバーできないため、
  **同時に生きる video は構造的に高々 1 本**になる (画面横断のコーディネータを作らない = 思考原則 2)。
- 再生 URL は既存の `capture.takes.playback` (302 → 署名 URL)。**props に署名 URL を載せない**
  (`SelectableTakeData` / `CutTakeSummaryData` が署名 URL のスロットを持たない設計を維持する)。

### 3. 署名 URL を取りに行く回数の設計

- **事前取得はしない**。ホバーした瞬間に初めて `/playback` を 1 回叩く。
  理由: 画面描画時に全カット分の署名 URL を先に発行すると、**使われない署名 URL が大量に発行**され、
  props に載せない方針とも矛盾する。
- 無駄打ちの抑制は「**200ms の滞留を待つ**」で行う。一覧の上をマウスが素通りしても要求は出ない。
- `/playback` は 302 + `Cache-Control: no-store, private` なので、同じテイクを再ホバーすると
  再度 302 を辿る。これは**受け入れる** (署名 URL の再利用を防ぐ既存の設計判断であり、
  この施策のためにキャッシュ方針を緩めない)。1 回のホバーで発生するのは 302 が 1 本 + 動画本体の
  レンジ要求であり、ユーザーの意図的な操作に 1:1 で対応する。

### 4. タッチ端末での挙動 — 「何もしない」

- `pointerType !== "mouse"` のイベントでは**再生を開始しない**。
- 代わりに、サムネイルは**テイク選択画面へのリンク**にする。タッチではタップ = 遷移になり、
  そこに `controls` 付きの本物のプレイヤーがある。
- 根拠: タッチで「タップしたら再生」にすると、**同じ場所のタップが遷移と再生の 2 つの意味を持つ**
  ことになり、doc/04 が求める「サムネイルから選択画面へ」の導線と衝突する。
  また自動で音のない映像が動き出す挙動はモバイル回線の通信量を無断で使う。
  **ホバーの無い環境では静止画のまま**が最も予測可能である。
- キーボード操作でも自動再生しない (フォーカスで映像が動き出す驚きを作らない)。
  リンクを Enter で辿れば同じ動画を `controls` 付きで再生できるため、代替手段は確保されている。

### 5. prefers-reduced-motion

`(prefers-reduced-motion: reduce)` のときは**自動再生しない**(静止画のまま)。
既存の `lib/capture/panel-navigation.ts#prefersReducedMotion()` を再利用する
(非対応環境では `true` = 動かさない側に倒す既存の作法をそのまま使う)。
自分で `matchMedia` を書き直さない。

## 制約・前提

- 既存 endpoint (`capture.takes.playback` / `capture.takes.thumbnail`) を**そのまま使う**。
  route も認可も増やさない。両方とも `preview` ability + ready 判定 + 302 no-store で、
  編集者は既に到達できる。
- 採用テイクが `ready` でないとき (`uploading` / `processing` / `failed`) は
  `/playback` も `/thumbnail` も 404 になる。UI 側は `status === "ready"` かつ
  `has_thumbnail` のときだけ画像・映像を張る (既存 `TakePickerList` と同じ判断規則)。
- シナリオ編集の行は D&D 並べ替え可能 (T185)。ドラッグ中は `lib/dnd/pointer-drag.ts` が
  `setPointerCapture()` を張るため、ドラッグ中のポインタは他要素の `pointerenter` を発火させない。
  したがってドラッグとホバー再生は干渉しない**見込み**で、詳細設計で実挙動を確認する。
- フロントは Svelte 5 runes + DS token のみ。アイコンは `@lucide/svelte`。
  component 階層は `features/manual` 内に閉じ、下層 (atoms/molecules) からの参照は作らない。
- サーバ側の変更は DTO の 1 フィールドのみ。`response()->json()` の直書きは無い
  (Inertia props 経由)。PHPStan level 10 の `toArray()` 戻り値 shape を更新する。

## スコープ外

- PC テイク選択画面 (`TakePickerList`) / 撮影 PWA (`TakeStrip`) へのホバー再生。
- **未採用テイクのサムネイル表示**。動画列は採用テイク 1 件だけを映す。
- 音声再生・`controls` 表示・シーク・全画面。ホバー中は無音ループのみ。
- 署名 URL のキャッシュ・事前発行・プリフェッチ。
- 撮影 PWA 側でのタップ再生 (既に `TakePreviewDialog` がある)。
- サムネイル生成・配信そのもの (T183 で完了済み)。

---

## 補助情報: 現行コードの要点

### resources/js/components/features/manual/ScenarioEditor.svelte (videoCell snippet)

```svelte
{#snippet videoCell(cutId: number | null, testIdSuffix: string)}
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
                <Button variant="neutral" size="sm"
                    href={`/projects/${projectId}/manuals/${manualId}/cuts/${cutId}/takes`}
                    inertia testId="video-cell-link">
                    <Film class="size-4" aria-hidden="true" />
                    {summary && summary.takes_count > 0 ? "テイクを選択" : "ファイルの選択"}
                </Button>
            </div>
        {/if}
    </div>
{/snippet}
```

### app/DataTransferObjects/Manual/CutTakeSummaryData.php

```php
final readonly class CutTakeSummaryData
{
    public function __construct(
        public int $cutId,
        public int $takesCount,
        public ?int $adoptedId,
        public ?string $adoptedStatus,
    ) {}

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

    /** @return array{cut_id: int, takes_count: int, adopted: array{id: int, status: string}|null} */
    public function toArray(): array
    {
        return [
            'cut_id' => $this->cutId,
            'takes_count' => $this->takesCount,
            'adopted' => $this->adoptedId === null || $this->adoptedStatus === null
                ? null
                : ['id' => $this->adoptedId, 'status' => $this->adoptedStatus],
        ];
    }
}
```

### app/Http/Controllers/Capture/CaptureTakeController.php (playback / thumbnail)

```php
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
    // 同じ 2 層 guard + Gate::authorize('preview', $take)
    if ($take->status !== TakeStatus::Ready) { abort(404); }
    $path = $take->thumbnail_path;
    if ($path === null) { abort(404); }
    return redirect()->away($storage->temporaryThumbnailUrl($path))
        ->withHeaders(['Cache-Control' => 'no-store, private']);
}
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

### resources/js/lib/capture/panel-navigation.ts (抜粋)

```ts
export function prefersReducedMotion(): boolean {
    if (typeof window === "undefined" || typeof window.matchMedia !== "function") return true;
    return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}
```

---

上記の概念設計をレビューしてください。とくに次の 3 点について明確な判断を求めます。

1. **対象の面を「シナリオ編集の動画列」1 つに絞った判断**は妥当か。PC テイク選択画面を見送った根拠は成立しているか。
2. **代表を採用テイク 1 件に限った判断** (未採用テイクにはサムネイルを出さない) は、doc/04 の「登録済みテイクはサムネイル表示」に対する狭めとして許容できるか。それとも要件未充足として扱うべきか。
3. **タッチ端末は「何もしない」**という判断、および **prefers-reduced-motion で自動再生しない**という判断は妥当か。
