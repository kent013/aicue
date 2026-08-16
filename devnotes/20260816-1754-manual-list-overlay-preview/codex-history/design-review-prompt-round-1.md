【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(app/Prompts/ の factory → PromptDefense → GuardedPrompt の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

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

【このリポジトリのドメイン固有規約 (抜粋。レビューの拘束条件)】
- **ドメイン規約 13 (T154) レンダ成果物の選択式の単一化**: 「いま受け取れるレンダ成果物はどれか」を 1 件選ぶ式を書いてよいのは `Services/Manual/CurrentRenderArtifact` ただ 1 ファイル。**成果物の受け取り口は route を増やさない** (`playback` が preview と完成動画の両方を扱う。kind→ability 写像は網羅 match)。完成動画の再生条件は download と**完全同一** (published + 現行世代 + download ability + 同じ評価順序)。**秘匿境界は props 側**に置き、UI は props が非 null かだけで判断する (`canManage` を積まない = 判断を 2 箇所に持たない)。
- **ドメイン規約 3 (3 枚セット)**: 認証済み Inertia 画面は (A) サーバ no-store baseline (B) クライアント bfcache 秘匿・再検証 (C) Inertia history 暗号化 + 履歴鍵破棄 の 3 枚で守る。壊さないこと。
- **セキュリティ不変条件**: 子は親に属する (nested route の不整合は認可より前に 404) / 変更系 route は認可を通る / tenant キー不信。
- component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import。アイコンは `@lucide/svelte` のみ。色・角丸は DESIGN.md の DS token 経由。

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
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: design token 経由か、hex 直書きを増やさないか
11. Atomic Design準拠（UI/frontend 変更を含む場合）: 責務分離・階層の逆流が無いか、SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: manual-list-overlay-preview (動画一覧からのオーバーレイプレビュー)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び (本設計は LLM を呼ばない)
6. prompt 文字列のコード直書き (本設計は prompt を持たない)
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI** (押下時にエラー表示する)
9. Artifact の使用

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`） / **RefreshDatabase** グローバル適用 + `--parallel`（個別 `DatabaseTransactions` 禁止）
- テストデータは必ず Factory で生成
- **DTO + JsonResource** パターン（本設計の変更は Inertia props = DTO 側）
- アーリーリターン推奨 / `composer fix`(Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript
- フロントは DS token のみ・アイコンは `@lucide/svelte`・component 階層は
  `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import

## 概念設計リファレンス

- `devnotes/20260816-1754-manual-list-overlay-preview/conceptual-design.md` (Codex 概念レビュー Round 1 APPROVED)
- 対応マトリクス: `devnotes/20260816-1754-manual-list-overlay-preview/codex-history/conceptual-review-decisions-round-1.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 行 props の置換 (`downloadable` → `current_finished_render_job_id`) | `app/DataTransferObjects/Manual/ManualListItemData.php` / `app/Http/Controllers/Projects/ProjectController.php` (PHPDoc) / `resources/js/types/manual.ts` | 高 |
| 2 | オーバーレイ再生 component の新設 | `resources/js/components/features/manual/ManualPreviewModal.svelte` (新規) | 高 |
| 3 | 行にプレビュー導線を追加 | `resources/js/components/features/manual/ManualListRow.svelte` | 高 |
| 4 | 一覧ページへのモーダル配線 | `resources/js/pages/Projects/Show.svelte` | 高 |
| 5 | テスト (Feature) | `tests/Feature/Projects/ProjectShowManualsTest.php` / `tests/Feature/Manual/ManualRowDownloadableParityTest.php` → `ManualRowFinishedVideoParityTest.php` (rename + 拡張) | 高 |
| 6 | テスト (Vitest) | `tests/js/components/features/manual/ManualListRow.test.ts` / `ManualPreviewModal.test.ts` (新規) / `tests/js/pages/ProjectsShow.test.ts` | 高 |

**新設しないもの**: route / Controller / Service / Job / migration / config キー。
サーバ側の変更は **DTO 1 ファイルの値の作り方だけ**である (判定式は現行のまま)。

---

## 施策 1: 行 props の置換 (`downloadable` → `current_finished_render_job_id`)

### 変更箇所

- `app/DataTransferObjects/Manual/ManualListItemData.php` (全体: コンストラクタ / `fromManual` / `toArray` の PHPDoc)
- `app/Http/Controllers/Projects/ProjectController.php` L149-158 (`manualRows()` の `@return` array shape)
- `resources/js/types/manual.ts` L119-127 (`ManualListItem`)

### 波及変更

- TypeScript 型定義: `ManualListItem.downloadable` → `current_finished_render_job_id: number | null`
- API Resource/DTO: `ManualListItemData` (Inertia props 専用 DTO。JsonResource は経由しない)
- テストファイル: `tests/Feature/Projects/ProjectShowManualsTest.php` /
  `tests/Feature/Manual/ManualRowDownloadableParityTest.php` (rename) /
  `tests/js/components/features/manual/ManualListRow.test.ts` / `tests/js/pages/ProjectsShow.test.ts`
- **その他の参照は無い** (`rg downloadable` の結果、app/ 側の参照は本 DTO と ProjectController の PHPDoc だけ。
  `EnsureLoginMethodRemains` の "downloadable response" は無関係なコメント語)

### 現行コード

```php
// app/DataTransferObjects/Manual/ManualListItemData.php (抜粋)
    /**
     * @param  int|null  $durationMs  いま公開されている完成動画の長さ (ms)。null = 未確定
     * @param  bool  $downloadable  download endpoint が 302 を返す条件と 1 対 1
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
        public bool $downloadable,
        public bool $deletable,
    ) {}

    public static function fromManual(VideoManual $manual, ManualRowAbilities $abilities): self
    {
        // …(category / creator / durationMs は不変)…

        // 受け取れる完成動画: download ability × published × 現行世代の succeeded render に
        // output_path がある。**ストレージ実体の存在確認ではない**。
        $currentRender = $manual->latestSucceededRender;
        $downloadable = $abilities->canDownload
            && $isPublished
            && $currentRender !== null
            && $currentRender->output_path !== null;

        return new self(
            // …
            durationMs: $durationMs,
            downloadable: $downloadable,
            deletable: $abilities->canDelete,
        );
    }

    /**
     * @return array{…, duration_ms: int|null, downloadable: bool, deletable: bool}
     */
    public function toArray(): array
    {
        return [
            // …
            'duration_ms' => $this->durationMs,
            'downloadable' => $this->downloadable,
            'deletable' => $this->deletable,
        ];
    }
```

### 変更後コード

```php
// app/DataTransferObjects/Manual/ManualListItemData.php (抜粋)
    /**
     * @param  int|null  $durationMs  いま公開されている完成動画の長さ (ms)。null = 未確定
     * @param  int|null  $currentFinishedRenderJobId  いま受け取れる完成動画 (kind=render) の
     *   render job id。**null = 受け取れない**。非 null であることは download endpoint が
     *   302 を返す条件と 1 対 1 (download ability × published × 現行世代の succeeded render に
     *   output_path がある)。値は再生 endpoint
     *   `projects.manuals.render-jobs.playback` のパスにそのまま使える
     *   (完成動画の再生条件は download と完全同一 = ドメイン規約 13 / T154)
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
        // …(category / creator / durationMs は不変)…

        // 「いま受け取れる完成動画」の行そのもの (無ければ null)。
        // 条件は現行の downloadable と**同一**で、運ぶ値だけを bool から id に変える:
        // download ability × published × 現行世代の succeeded render に output_path がある。
        // **ストレージ実体の存在確認ではない** (download endpoint もしていない)。
        // 世代の選び方は CurrentRenderArtifact と同一 (latestSucceededRender の docblock 参照)。
        $currentRender = $manual->latestSucceededRender;
        $receivableRender = ($abilities->canDownload
            && $isPublished
            && $currentRender !== null
            && $currentRender->output_path !== null)
                ? $currentRender
                : null;

        return new self(
            // …
            durationMs: $durationMs,
            currentFinishedRenderJobId: $receivableRender?->id,
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
            // …
            'duration_ms' => $this->durationMs,
            'current_finished_render_job_id' => $this->currentFinishedRenderJobId,
            'deletable' => $this->deletable,
        ];
    }
```

```php
// app/Http/Controllers/Projects/ProjectController.php (manualRows の @return。同じ shape へ追随)
    /**
     * @return array{
     *   data: list<array{id: int, title: string, status: string,
     *     category: array{id: int, name: string}|null,
     *     creator: array{id: int, name: string}|null,
     *     created_at: string, updated_at: string,
     *     duration_ms: int|null, current_finished_render_job_id: int|null, deletable: bool}>,
     *   meta: array{current_page: int, last_page: int, per_page: int, total: int}
     * }
     */
```

```ts
// resources/js/types/manual.ts (ManualListItem 抜粋)
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
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`fromManual(): self` / `toArray(): array{…}` は既存のまま key だけ変更)
- [x] null 安全: `$receivableRender` は `RenderJob|null` に確定し、`?->id` で `int|null` を返す。
      **bool 変数からの絞り込みに依存しない**書き方にしてある (PHPStan は真偽変数越しに
      `$currentRender !== null` を伝播しないため、条件式の結果として**行そのもの**を保持する)
- [x] DTO を返している (配列返却なし。`toArray()` は Inertia props 生成の末端で、shape を PHPDoc で固定)
- [x] Generics: 変更なし (`latestSucceededRender` は `HasOne<RenderJob, VideoManual>` の既存宣言)
- [x] `$manual->latestSucceededRender` は `@property-read RenderJob|null` 宣言済みで `->id` は `int`

### テスト計画

- [ ] 更新 `tests/Feature/Projects/ProjectShowManualsTest.php`
  - 旧 `downloadable は published × 現行世代の…` を
    `current_finished_render_job_id は published × 現行世代の succeeded render (output_path あり) のときだけ id を返す` に改題し、
    4 ケース (ok / stale=output_path null / preview のみ / 未 published) の期待値を `id` / `null` に更新
  - 旧 `撮影者は downloadable / deletable ともに false…` を
    `撮影者は current_finished_render_job_id=null / deletable=false、編集者は id と deletable=true` に更新
  - **新規** `一覧の行 props に旧キー downloadable が残っていない` —
    `array_key_exists('downloadable', $row)` が false であることを固定 (置換の取り残しを赤くする)
- [ ] rename + 拡張 `tests/Feature/Manual/ManualRowDownloadableParityTest.php`
      → `tests/Feature/Manual/ManualRowFinishedVideoParityTest.php`
  - **既存 5 ケースはすべて残す** (削除・上書きではなく key の更新と改名)。
    キー名が概念ごと変わるためファイル名も追随させる (「downloadable との parity」ではなく
    「一覧が返す完成動画の行と受け取り口の parity」になる)
  - **新規** `一覧が返す id は playback endpoint が 302 を返す id と一致する`
  - **新規** `旧世代の render job id を直叩きすると playback は 404 (一覧は最新世代の id を返す)`
  - **新規** `撮影者は一覧 id が null で playback も 403` (props と endpoint の非対称が無いこと)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (既存ファイルは未使用)

### リスク

- **props 名の置換漏れ**: サーバが新キーを出し UI が旧キーを読むと「導線が全行から消える」。
  Feature の旧キー不在テスト + Vitest の型 (fixture は `ManualListItem` 型注釈付き) で二重に赤くする。
- **他画面への波及**: `NotificationController` のコメントは「ページャ shape が同形」という言及のみで、
  `ManualListItemData` を使っていない (実装を確認済み)。よって影響なし。

---

## 施策 2: オーバーレイ再生 component の新設 (`ManualPreviewModal.svelte`)

### 変更箇所

- `resources/js/components/features/manual/ManualPreviewModal.svelte` (新規)

### 波及変更

- TypeScript 型定義: 既存 `ManualListItem` を props 型として使う (新しい型は作らない)
- API Resource/DTO: なし
- テストファイル: `tests/js/components/features/manual/ManualPreviewModal.test.ts` (新規)

### 現行コード

該当なし (新規)。参考にする現行実装は `RenderPanel.svelte` の完成動画ブロック:

```svelte
<!-- svelte-ignore a11y_media_has_caption (完成動画の字幕は焼き込み済み) -->
<video
    controls
    preload="none"
    class="w-full rounded-md bg-neutral"
    src={`/projects/${projectId}/manuals/${manualId}/render-jobs/${finishedJob.id}/playback`}
    aria-label="完成動画"
    data-testid="final-video"
></video>
```

### 変更後コード

```svelte
<script lang="ts">
    import Modal from "@/components/organisms/Modal.svelte";
    import type { ManualListItem } from "@/types/manual";

    /**
     * 動画一覧からの完成動画プレビュー (doc/04 一覧ページ「プレビュー（オーバーレイ）」)。
     *
     * - 再生/停止/音量/全画面はブラウザ標準の `video controls` が担う (自前の再生制御は作らない)
     * - **言語切替は持たない**。v1 は字幕のみ・`locale=ja` 固定で、切り替える対象の成果物が
     *   1 つも無い (doc/10 の `feature_multilang` は Quota キーの予約のみ)。
     *   多言語が入る日に一覧・詳細・DL をまとめて設計する
     * - src は**同一オリジンのアプリ route** (302 で S3 署名 URL へ飛ぶ)。署名 URL は props にも
     *   HTML にも現れないため、認証済み画面の 3 枚セット (no-store / bfcache 秘匿 /
     *   Inertia history 暗号化) の前提を変えない
     * - 描画するのは**サーバが再生可と判断した行だけ** (current_finished_render_job_id が非 null)。
     *   published も権限も UI 側で再判定しない
     */
    interface Props {
        projectId: number;
        /** 再生対象の行。null = 未選択 (open=false のときのみ) */
        manual: ManualListItem | null;
        /** 開閉状態 (bindable)。呼び出し側が $state で保持し bind:open する */
        open: boolean;
    }

    let { projectId, manual, open = $bindable(false) }: Props = $props();

    /**
     * 再生 URL。**行 props の id からのみ**組み立てる (status や権限から導出しない)。
     * 閉じている間は Modal が中身を DOM に載せないため、署名 URL の発行要求は
     * オーバーレイを開いたときだけ起きる。
     */
    const playbackSrc = $derived(
        manual === null || manual.current_finished_render_job_id === null
            ? null
            : `/projects/${projectId}/manuals/${manual.id}/render-jobs/` +
              `${manual.current_finished_render_job_id}/playback`,
    );
</script>

<Modal
    bind:open
    title={manual?.title ?? "プレビュー"}
    size="lg"
    testId="manual-preview-modal"
>
    {#if playbackSrc !== null}
        <!-- svelte-ignore a11y_media_has_caption (完成動画の字幕は焼き込み済み) -->
        <!-- preload="metadata": 開いた時点で尺と先頭フレームを出す (ユーザーが明示的に
             「プレビュー」を押した後なので、この 1 回の発行はユーザーの要求そのもの)。
             RenderPanel が preload="none" なのは詳細画面を**開くたびに**発行が走るのを
             避けるためで、前提が違う。
             autoplay は付けない: 音声付き autoplay はブラウザポリシーで拒否される環境があり
             「押したのに再生されない」が環境依存で生まれるため、再生開始は標準 controls に委ねる -->
        <video
            controls
            preload="metadata"
            class="w-full rounded-md bg-neutral"
            src={playbackSrc}
            aria-label={`${manual?.title ?? ""} の完成動画`}
            data-testid="manual-preview-video"
        ></video>
    {/if}
</Modal>
```

### PHPStan 適合チェック

- 対象外 (フロントエンド)。TypeScript 側は `pnpm typecheck` / `pnpm lint` で担保する
  (`playbackSrc` は `string | null` に推論され、`{#if}` で narrowing 済み)。

### テスト計画

- [ ] 新規 `tests/js/components/features/manual/ManualPreviewModal.test.ts`
  - `open=true` のとき `<video>` の `src` が
    `/projects/7/manuals/2/render-jobs/9/playback` になる
  - `<video>` が `controls` 属性を持つ (自前の再生制御を持たないことの契約)
  - `open=false` のとき `<video>` が DOM に存在しない (閉じている間は署名 URL 要求を出さない)
  - `current_finished_render_job_id === null` の行を渡しても `<video>` を描画しない
    (サーバが不可と判断した行で URL を組み立てない)
  - モーダル見出しに manual の title が出る

### リスク

- **モーダルを閉じても音が鳴り続ける**: `Modal` (bits-ui `Dialog.Content`) は閉じると中身を
  DOM から外すため `<video>` ごと破棄され再生は止まる。`forceMount` は使わない。
  Vitest の「閉じているとき `<video>` が無い」テストがこの前提を固定する。
- **モーダル幅**: `size="lg"` (max-w-2xl) で 16:9 動画が収まる。Modal 自体が `max-h-[85vh]` +
  `overflow-y-auto` を持つため、縦長画面でもはみ出さない。

---

## 施策 3: 行にプレビュー導線を追加 (`ManualListRow.svelte`)

### 変更箇所

- `resources/js/components/features/manual/ManualListRow.svelte` (Props / 操作群)

### 波及変更

- TypeScript 型定義: Props に `onRequestPreview: (manual: ManualListItem) => void` を追加
- API Resource/DTO: なし
- テストファイル: `tests/js/components/features/manual/ManualListRow.test.ts`

### 現行コード

```svelte
<script lang="ts">
    import { Download, Trash2 } from "@lucide/svelte";
    // …
    interface Props {
        projectId: number;
        manual: ManualListItem;
        /** 削除確認ダイアログを開く要求 */
        onRequestDelete: (manual: ManualListItem) => void;
    }

    let { projectId, manual, onRequestDelete }: Props = $props();
</script>

        {#if manual.downloadable}
            <Button
                variant="ghost"
                size="sm"
                href={`/projects/${projectId}/manuals/${manual.id}/download`}
                ariaLabel={`${manual.title} の完成動画をダウンロード`}
                testId={`manual-download-${manual.id}`}
            >
                <Download class="size-4" />
                DL
            </Button>
        {/if}
```

### 変更後コード

```svelte
<script lang="ts">
    import { Download, Play, Trash2 } from "@lucide/svelte";
    // …
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

        <!-- 受け取れるとサーバが判断した行にだけ出す。押せない (disabled) ボタンは作らない。
             プレビューと DL は同じ条件 (playback の完成動画条件 = download 条件) なので
             同じ枝に置く = 2 つの条件を持たない。 -->
        {#if finishedRenderJobId !== null}
            <Button
                variant="ghost"
                size="sm"
                onclick={() => onRequestPreview(manual)}
                ariaLabel={`${manual.title} の完成動画をプレビュー`}
                testId={`manual-preview-${manual.id}`}
            >
                <Play class="size-4" />
                プレビュー
            </Button>
            <!-- 素の <a> (inertia なし) = 非 Inertia 遷移。成功時は attachment 応答のため
                 画面は遷移しない。 -->
            <Button
                variant="ghost"
                size="sm"
                href={`/projects/${projectId}/manuals/${manual.id}/download`}
                ariaLabel={`${manual.title} の完成動画をダウンロード`}
                testId={`manual-download-${manual.id}`}
            >
                <Download class="size-4" />
                DL
            </Button>
        {/if}
```

> 行の操作群は既に `flex flex-col … sm:flex-row` (T182 でモバイル縦積み化済み) なので、
> ボタンが 1 つ増えてもレイアウト規則の変更は要らない。

### PHPStan 適合チェック

- 対象外 (フロントエンド)。`pnpm typecheck` / `pnpm lint` / ds-purity / atomic-import-graph で担保。
  `Play` は `@lucide/svelte` 由来 (SVG 直書きなし)。色・角丸は Button atom の DS token に従う。

### テスト計画

- [ ] 更新 `tests/js/components/features/manual/ManualListRow.test.ts`
  - fixture を `current_finished_render_job_id: 9` へ更新 (型注釈があるので旧キーはコンパイルエラー)
  - 既存 `downloadable=true のとき DL リンクを download endpoint へ出す` を新キー基準に更新
  - 既存 `downloadable=false のとき DL リンクを出さない` を
    `current_finished_render_job_id=null のときプレビュー / DL のどちらも出さない` に拡張
  - **新規** `current_finished_render_job_id が非 null のときプレビューボタンを出し、押すとその行で onRequestPreview が呼ばれる`
  - **新規** `プレビュー / DL のどちらも disabled 属性を持たない` (禁止事項 8 の退行封じ)
  - 既存 `DL は通常 anchor` は維持 (プレビューは `<button>` なので `inertia-link-stub` の件数契約は不変)

### リスク

- **アイコン付きボタンが 3 つ並び、狭い画面で窮屈になる**: 既に縦積みへ逃がす規則があるため
  レイアウト破綻はしない。ラベルを「プレビュー」と長めにするのは、DL / 削除と役割を取り違えないため。
- **プレビューだけ出て DL が出ない/その逆**は構造的に起きない (同一の `{#if}` 枝に置く)。

---

## 施策 4: 一覧ページへのモーダル配線 (`Projects/Show.svelte`)

### 変更箇所

- `resources/js/pages/Projects/Show.svelte`
  - import に `ManualPreviewModal` を追加
  - 動画マニュアル節に state 2 本 + open 関数 1 本を追加 (行内削除と同じ流儀)
  - `<ManualListRow>` に `onRequestPreview` を渡す
  - ページ末尾 (`ConfirmDialog` 群の近く) に `<ManualPreviewModal>` を **1 つだけ**置く

### 波及変更

- TypeScript 型定義: なし (既存 `ManualListItem` を使う)
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/ProjectsShow.test.ts`

### 現行コード

```svelte
    /* ---- 動画マニュアル: 行内削除 (ConfirmDialog → destroy) ---- */
    let removeManualTarget = $state<ManualListItem | null>(null);
    let removeManualDialogOpen = $state(false);
    let removingManual = $state(false);

    function openRemoveManualDialog(manual: ManualListItem): void {
        removeManualTarget = manual;
        removeManualDialogOpen = true;
    }
```

```svelte
                        {#each manuals.data as manual (manual.id)}
                            <ManualListRow
                                projectId={project.id}
                                {manual}
                                onRequestDelete={openRemoveManualDialog}
                            />
                        {/each}
```

### 変更後コード

```svelte
    import ManualPreviewModal from "@/components/features/manual/ManualPreviewModal.svelte";

    /* ---- 動画マニュアル: 行内プレビュー (オーバーレイ再生) ---- */
    // モーダルは**ページに 1 つ**だけ持つ (行ごとに Dialog を作らない = 行内削除と同じ流儀)。
    // 対象行を state に持つので、閉じた後も最後に開いた行が残るが、開く操作は必ず
    // openPreviewManualDialog を通るため取り違えは起きない。
    let previewManualTarget = $state<ManualListItem | null>(null);
    let previewManualDialogOpen = $state(false);

    function openPreviewManualDialog(manual: ManualListItem): void {
        previewManualTarget = manual;
        previewManualDialogOpen = true;
    }
```

```svelte
                        {#each manuals.data as manual (manual.id)}
                            <ManualListRow
                                projectId={project.id}
                                {manual}
                                onRequestPreview={openPreviewManualDialog}
                                onRequestDelete={openRemoveManualDialog}
                            />
                        {/each}
```

```svelte
        <!-- 完成動画のオーバーレイ再生 (doc/04 一覧ページの「プレビュー」)。
             ページに 1 つだけ置き、対象行を差し替えて使い回す -->
        <ManualPreviewModal
            bind:open={previewManualDialogOpen}
            projectId={project.id}
            manual={previewManualTarget}
        />
```

### PHPStan 適合チェック

- 対象外 (フロントエンド)。`pages` から `features` の import は単方向で合法。

### テスト計画

- [ ] 更新 `tests/js/pages/ProjectsShow.test.ts`
  - fixture 2 行のキーを `current_finished_render_job_id: null` / `: 9` に更新
  - 既存 `行の再生時間と DL 導線をサーバの props どおりに出し分ける` を新キー基準に更新
  - **新規** `プレビューを押すとオーバーレイが開き、その行の playback URL の video が出る`
    (`manual-preview-2` を click → `manual-preview-video` の `src` が
    `/projects/1/manuals/2/render-jobs/9/playback`)
  - **新規** `受け取れない行にはプレビュー導線が無い` (`manual-preview-1` が存在しない)

### リスク

- **jsdom で bits-ui Dialog が描画できないのでは**: 既に
  `tests/js/components/organisms/Modal.test.ts` と `ConfirmDialog.test.ts` が同じ組み合わせで
  緑になっており、`Projects/Show.svelte` の既存 Modal (アイテム編集) もテスト済み。前提は成立している。
- **クリック直後の非同期描画**: bits-ui は open 反映が microtask をまたぐ場合があるため、
  アサーションは `waitFor` / `findBy*` で待つ (同ファイルの既存テストが `waitFor` を使用済み)。

---

## 施策 5: Feature テスト

### 変更箇所

- `tests/Feature/Projects/ProjectShowManualsTest.php` (T182 ブロック L278 付近)
- `tests/Feature/Manual/ManualRowDownloadableParityTest.php`
  → `tests/Feature/Manual/ManualRowFinishedVideoParityTest.php` (rename + 拡張)

### 波及変更

- 既存テストの**削除はしない**。key の更新・改題・ケース追加のみ
  (rename はファイル名が概念名を含んでおり、置換後の名前と食い違うため)

### 現行コード

```php
// tests/Feature/Manual/ManualRowDownloadableParityTest.php (抜粋)
test('succeeded が 2 世代あるとき両者とも最新の行を指す', function (): void {
    // …
    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('manuals.data.0.downloadable', true));
    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
        ->assertRedirect();
});
```

### 変更後コード

```php
// tests/Feature/Manual/ManualRowFinishedVideoParityTest.php (抜粋)
/*
 * T182 + 本設計: 一覧の候補行 relation (VideoManual::latestSucceededRender) と
 * 受け取り対象の選択式 (CurrentRenderArtifact::currentSucceeded) の**世代定義が一致**すること、
 * および一覧の current_finished_render_job_id と受け取り口 2 本
 * (download の 302/404 / playback の 302/404) の判断が一致すること。
 */
test('succeeded が 2 世代あるとき両者とも最新の行を指し、一覧の id で再生できる', function (): void {
    [, $owner, $project] = parityFixture();
    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
    RenderJob::factory()->forManual($manual)->succeeded('renders/old.mp4')->create();
    $newest = RenderJob::factory()->forManual($manual)->succeeded('renders/new.mp4')->create();

    $manual->refresh();

    expect($manual->latestSucceededRender?->id)->toBe($newest->id);
    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)?->id)->toBe($newest->id);

    $rows = $this->actingAs($owner)->get("/projects/{$project->id}")
        ->inertiaPage()['props']['manuals']['data'];
    // 一覧が返す id = 受け取り口が受け付ける id (props と endpoint の非対称を作らない)
    expect($rows[0]['current_finished_render_job_id'])->toBe($newest->id);

    $this->actingAs($owner)
        ->get("/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$newest->id}/playback")
        ->assertRedirect();
    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
        ->assertRedirect();
});

test('旧世代の render job id を直叩きすると playback は 404 (一覧はその id を返さない)', function (): void {
    [, $owner, $project] = parityFixture();
    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
    $old = RenderJob::factory()->forManual($manual)->succeeded('renders/old.mp4')->create();
    $newest = RenderJob::factory()->forManual($manual)->succeeded('renders/new.mp4')->create();

    $rows = $this->actingAs($owner)->get("/projects/{$project->id}")
        ->inertiaPage()['props']['manuals']['data'];
    expect($rows[0]['current_finished_render_job_id'])->toBe($newest->id);

    $this->actingAs($owner)
        ->get("/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$old->id}/playback")
        ->assertNotFound();
});

test('撮影者は一覧 id が null で playback も 403 (props と endpoint が同じ結論を出す)', function (): void {
    // 既存の撮影者ケース (ProjectShowManualsTest / 本ファイル既存) と同じ Factory 経路で
    // project_member を用意し、props=null と playback=403 を同時に固定する
});
```

```php
// tests/Feature/Projects/ProjectShowManualsTest.php (新規ケース)
test('一覧の行 props に旧キー downloadable が残っていない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->published(60_000)->create();

    $rows = $this->actingAs($owner)->get("/projects/{$project->id}")
        ->inertiaPage()['props']['manuals']['data'];

    expect($rows[0])->toHaveKey('current_finished_render_job_id');
    expect(array_key_exists('downloadable', $rows[0]))->toBeFalse();
});
```

### PHPStan 適合チェック

- [x] テストも PHPStan の走査対象。`inertiaPage()['props']['manuals']['data']` の使い方は
      既存テスト (T182) と同一の形をそのまま踏襲する (新しい mixed アクセスの形を増やさない)
- [x] Factory のみでデータ生成 (`RenderJob::factory()->forManual()->succeeded()` は既存 state)

### テスト計画

上記が本施策そのもの。加えて回帰確認:

- [ ] `composer test -- --filter=ManualRowFinishedVideoParity`
- [ ] `composer test -- --filter=ProjectShowManuals`
- [ ] `composer test`(全体) / `composer phpstan` / `vendor/bin/pint --test`

### リスク

- **ファイル rename の取りこぼし**: 旧ファイルを残すと `downloadable` を参照する死んだテストが
  緑のまま残る (実際には key が無いので赤くなる)。rename は 1 コミット内で完結させる。

---

## 施策 6: Vitest

### 変更箇所

- `tests/js/components/features/manual/ManualListRow.test.ts` (更新)
- `tests/js/components/features/manual/ManualPreviewModal.test.ts` (新規)
- `tests/js/pages/ProjectsShow.test.ts` (更新)

### 波及変更

- なし (テストのみ)

### 現行コード

```ts
// tests/js/components/features/manual/ManualListRow.test.ts (fixture 抜粋)
        duration_ms: 185_000,
        downloadable: true,
        deletable: true,
```

### 変更後コード

```ts
// tests/js/components/features/manual/ManualListRow.test.ts (抜粋)
function manualItem(overrides: Partial<ManualListItem> = {}): ManualListItem {
    return {
        // …
        duration_ms: 185_000,
        current_finished_render_job_id: 9,
        deletable: true,
        ...overrides,
    };
}

function renderRow(
    overrides: Partial<ManualListItem> = {},
    onRequestPreview = vi.fn(),
    onRequestDelete = vi.fn(),
) {
    render(ManualListRow, {
        props: { projectId: 7, manual: manualItem(overrides), onRequestPreview, onRequestDelete },
    });

    return { onRequestPreview, onRequestDelete };
}

it("受け取れる行ではプレビューを押すとその行で onRequestPreview が呼ばれる", async () => {
    const { onRequestPreview } = renderRow();

    await fireEvent.click(screen.getByTestId("manual-preview-1"));

    expect(onRequestPreview).toHaveBeenCalledTimes(1);
    expect(onRequestPreview.mock.calls[0][0]).toMatchObject({ id: 1 });
});

it("current_finished_render_job_id=null のときプレビュー / DL のどちらも出さない", () => {
    renderRow({ current_finished_render_job_id: null });

    expect(screen.queryByTestId("manual-preview-1")).toBeNull();
    expect(screen.queryByTestId("manual-download-1")).toBeNull();
});

it("プレビュー / DL / 削除のどれも disabled を持たない (禁止事項 8)", () => {
    renderRow();

    for (const testId of ["manual-preview-1", "manual-download-1", "manual-remove-1"]) {
        expect(screen.getByTestId(testId)).not.toHaveAttribute("disabled");
    }
});
```

```ts
// tests/js/components/features/manual/ManualPreviewModal.test.ts (新規・抜粋)
it("開いているとき playback endpoint を src に持つ video を描画する", async () => {
    render(ManualPreviewModal, { props: { projectId: 7, manual: item(), open: true } });

    const video = await screen.findByTestId("manual-preview-video");
    expect(video.getAttribute("src")).toBe("/projects/7/manuals/2/render-jobs/9/playback");
    expect(video).toHaveAttribute("controls");
});

it("閉じているとき video を描画しない (署名 URL 要求を出さない)", () => {
    render(ManualPreviewModal, { props: { projectId: 7, manual: item(), open: false } });

    expect(screen.queryByTestId("manual-preview-video")).toBeNull();
});

it("受け取れない行 (id=null) では video を描画しない", async () => {
    render(ManualPreviewModal, {
        props: { projectId: 7, manual: item({ current_finished_render_job_id: null }), open: true },
    });

    await screen.findByTestId("manual-preview-modal");
    expect(screen.queryByTestId("manual-preview-video")).toBeNull();
});
```

### PHPStan 適合チェック

- 対象外 (Vitest)。`pnpm typecheck` が fixture の型不整合 (旧キー残置) を検出する。

### テスト計画

- [ ] `pnpm test`(全体) / `pnpm typecheck` / `pnpm lint` / `pnpm build`

### リスク

- **jsdom の `<video>`**: jsdom は `play()` を実装しないが、本設計は再生を script から
  呼ばないため影響なし (属性と src の契約だけを検証する)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 変更は既存ファイル 6 本の局所編集 + 新規 2 本 (component / テスト) で、DB・route・Service を触らない。施策 1 (props 置換) → 施策 2/3/4 (UI) → 施策 5/6 (テスト) の順に段階検証でき、各段で `composer test` / `pnpm test` を回せる。standalone にするほどの構造変更が無い |
| 競合リスク | `Projects/Show.svelte` と `ManualListRow.svelte` は他の一覧系タスク (絞り込み / ページャ) と衝突しうる。`ManualListItemData` は T182 で確定した DTO であり、同時期に他タスクが行 props を触っていないことを実装開始時に確認する。`routes/web.php` は触らないため route 系タスクとは競合しない |

## 全体リスクと非目標の再掲

- **保証しないもの**: 本設計は「行 props が非 null なら再生できる」ことを描画時点のスナップショットとして
  約束するだけで、S3 実体の存在確認はしない (現行 download / playback endpoint も同じ)。
  レンダの世代が入れ替わった直後に古い props で押すと endpoint が 404 を返す
  — これは既存の DL と同じ挙動であり、本設計で新しく生む穴ではない。
- **言語切替は実装しない** (v1 スコープ。理由は概念設計 決定 2)。
- **preview 成果物 (kind=preview) は一覧から再生しない** (詳細画面 RenderPanel の担当)。
- **3 枚セット (ドメイン規約 3)**: 署名 URL は props / HTML に載らず、`<video src>` は
  同一オリジンの route。認証済み画面の no-store baseline・bfcache 秘匿・Inertia history 暗号化の
  いずれにも新しい前提を持ち込まない。


---

## 関連する現行コード

### app/DataTransferObjects/Manual/ManualListItemData.php

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Enums\Manual\VideoManualStatus;
use App\Models\VideoManual;
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
     * @param  bool  $downloadable  download endpoint が 302 を返す条件と 1 対 1
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
        public bool $downloadable,
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

        // 受け取れる完成動画: download ability × published × 現行世代の succeeded render に
        // output_path がある。**ストレージ実体の存在確認ではない** (それは download endpoint も
        // していない。ここは endpoint が 302 を返す条件と同じものを見ているだけ)。
        // 世代の選び方は CurrentRenderArtifact と同一 (latestSucceededRender の docblock 参照)。
        $currentRender = $manual->latestSucceededRender;
        $downloadable = $abilities->canDownload
            && $isPublished
            && $currentRender !== null
            && $currentRender->output_path !== null;

        return new self(
            id: $manual->id,
            title: $manual->title,
            status: $manual->status,
            category: $category === null ? null : new ManualListRefData($category->id, $category->name),
            creator: $creator === null ? null : new ManualListRefData($creator->id, $creator->name),
            createdAt: $manual->created_at?->format('Y-m-d H:i') ?? '',
            updatedAt: $manual->updated_at?->format('Y-m-d H:i') ?? '',
            durationMs: $durationMs,
            downloadable: $downloadable,
            deletable: $abilities->canDelete,
        );
    }

    /**
     * @return array{id: int, title: string, status: string,
     *   category: array{id: int, name: string}|null,
     *   creator: array{id: int, name: string}|null,
     *   created_at: string, updated_at: string,
     *   duration_ms: int|null, downloadable: bool, deletable: bool}
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
            'downloadable' => $this->downloadable,
            'deletable' => $this->deletable,
        ];
    }
}
```

### app/Http/Controllers/Projects/ProjectController.php (manualRows 抜粋 L136-225)

```php
            'assignableUsers' => $this->assignableUserRows($organization, $memberRows, $canManage),
            // 動画マニュアル一覧 (専用 index は持たず本画面に内包。GET クエリで絞り込み + paginate)
            'manuals' => $this->manualRows($project, $listQuery, $user),
            'categories' => $this->categoryRows($project),
            'manualFilters' => $listQuery->toProps(),
            // 管理メニュー導線 (doc/04: 管理者のみサイドバー表示)。単一根拠は Gate
            'canManageMembers' => $user->can('manageMembers', $organization),
        ]);
    }

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
     *     duration_ms: int|null, downloadable: bool, deletable: bool}>,
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
```

### app/Services/Manual/ManualRowAbilities.php

```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;

/**
 * 一覧の行に出す操作 (完成動画のダウンロード / 削除) の可否。
 *
 * **前提 (名前が示す約束)**: download / delete の可否は「その manual が属する project」で決まり、
 * manual 個別の属性 (status / 作成者 / カテゴリ) には依存しない。
 * VideoManualPolicy::download / ::delete が対象から `project` しか読まず
 * ProjectPolicy::update へ委譲しているためである。よって**ページで 1 回だけ**評価して全行へ配る。
 *
 * **なぜ畳むか**: ProjectPolicy は毎回 DB を見る (Project::memberRole() は memo 無しのクエリ、
 * Laratrust のキャッシュは config/laratrust.php の既定で production 以外は無効)。
 * 行ごとに can() を呼ぶと権限解決クエリが行数に比例する (per_page=10 × 2 ability)。
 *
 * **なぜ ProjectPolicy::update を直接問わないか**: それは委譲関係を呼び出し側へ
 * ハードコードすることであり、policy が分岐した日に**赤くならずに間違う**。
 * 問う ability 名は download / delete のまま保ち、評価の**回数**だけを畳む。
 *
 * 前提は ManualRowAbilityPremiseTest が固定し (manual 依存になったら赤くなる)、
 * 行数に比例しないことは ManualListQueryCountTest が固定する。読み取り専用。
 *
 * **前提が崩れたときの手順**: ManualRowAbilityPremiseTest が赤くなったら、
 * 評価を行ループへ移す (そのとき N+1 の解消も同時に設計し直す)。
 */
final readonly class ManualRowAbilities
{
    private function __construct(
        public bool $canDownload,
        public bool $canDelete,
    ) {}

    /**
     * ページに載る行に対する可否。行が 1 件も無いページでは両方 false
     * (出す導線が無いので評価しない = 無駄な権限クエリを撃たない)。
     *
     * @param  list<VideoManual>  $manuals  同一 $project 配下であること (呼び出し側が保証する)
     */
    public static function forPage(User $user, Project $project, array $manuals): self
    {
        $representative = $manuals[0] ?? null;
        if ($representative === null) {
            return new self(canDownload: false, canDelete: false);
        }

        // policy が親を読み直すクエリを避けるため、解決済みの project を先に確定させる
        // (同一 project 配下であることは呼び出し側 = $project->manuals() が保証している)
        $representative->setRelation('project', $project);

        return new self(
            canDownload: $user->can('download', $representative),
            canDelete: $user->can('delete', $representative),
        );
    }
}
```

### app/Services/Manual/CurrentRenderArtifact.php

```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Models\RenderJob;
use App\Models\VideoManual;

/**
 * 「いま受け取れるレンダ成果物はどれか」の**唯一の選択式** (playback / download / 詳細画面 props)。
 *
 * 定義は保持ポリシー (RenderJobService::newerSucceededExists / DeleteRenderOutputsJob) と
 * **同じ世代定義**である: 実体が残るのは「同 manual・同 kind の最新 succeeded」だけなので、
 * 最新 succeeded の output_path が NULL (= 生成に失敗した / 掃除された) なら
 * **旧世代へフォールバックしない** (削除済みオブジェクトの署名 URL を出さないため)。
 *
 * **持たない責務**: published 判定 (完成動画の公開状態) と ability 判定は呼び出し側にある。
 * ここは「どの行か」だけを答える (名前が示す役割を超えない)。読み取り専用。
 */
final class CurrentRenderArtifact
{
    /** 同 manual・同 kind で現在受け取れる succeeded job (無ければ null) */
    public static function currentSucceeded(VideoManual $manual, RenderKind $kind): ?RenderJob
    {
        $job = $manual->renderJobs()
            ->where('kind', $kind->value)
            ->where('status', JobStatus::Succeeded->value)
            ->latest('id')
            ->first();

        if ($job === null || $job->output_path === null) {
            return null; // 旧世代へフォールバックしない (実体が無い可能性がある)
        }

        return $job;
    }
}
```

### app/Http/Controllers/Projects/ManualRenderController.php (playback 抜粋)

```php
     * 層は 3 段で、**すべて認可より前に 404** (AGENTS.md セキュリティ不変条件 2/10):
     *   1. {project} ∈ current org … project.in-current-org middleware + inline guard
     *   2. {manual}  ∈ {project}   … routes 側 Route::scopeBindings()
     *   3. {renderJob} ∈ {manual}  … scopeBindings + 下の inline 再検査 (二重防御)
     * その後に **成果物の性質に合う ability** を評価する:
     *   kind=preview → render ability / kind=render → download ability
     *   (現行はどちらも ProjectPolicy::update に落ちるため**可否は完全に同値**。
     *    UI 側の canManage が自動追従するという意味ではない = 誇張しない)
     * 完成動画だけ published を要求するのは download と同一条件にするため (順序も download と同じ
     * = authorize の後)。最後に「いま受け取れる成果物」と同一行かを照合する
     * (旧世代 job id の直叩き・未完了・実体削除済みはここで 404)。
     */
    public function playback(Request $request, Project $project, VideoManual $manual, RenderJob $renderJob, RenderObjectStorage $storage): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        if ($renderJob->video_manual_id !== $manual->id) {
            abort(404);
        }
        // 2 値 enum の網羅 match (到達不能な fallback を作らない)
        Gate::authorize(match ($renderJob->kind) {
            RenderKind::Preview => 'render',
            RenderKind::Render => 'download',
        }, $manual);

        // 完成動画は「公開中のマニュアルの現行版」だけ (download と同条件・同順序)
        if ($renderJob->kind === RenderKind::Render && $manual->status !== VideoManualStatus::Published) {
            abort(404);
        }

        $current = CurrentRenderArtifact::currentSucceeded($manual, $renderJob->kind);
        if ($current === null || $current->id !== $renderJob->id) {
            abort(404); // 未完了 / 旧世代 / 実体削除済み
        }
        $path = $current->output_path;
        if ($path === null) {
            abort(404); // currentSucceeded の契約上到達しないが、型を締めるため明示する
        }

        return redirect()->away($storage->temporaryPlaybackUrl($path));
    }
}
```

### app/Http/Controllers/Projects/ManualDownloadController.php

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Enums\Manual\RenderKind;
use App\Enums\Manual\VideoManualStatus;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\DownloadManualRequest;
use App\Models\Project;
use App\Models\VideoManual;
use App\Services\Manual\CurrentRenderArtifact;
use App\Services\Render\RenderObjectStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * 完成 mp4 のダウンロード (302 → S3 署名 URL。attachment disposition)。download ability。
 * アプリ内再生 (inline disposition) は playback route が同一条件で担う (T154)。
 * 受け取り対象の選択は CurrentRenderArtifact に集約済み (playback と同一式)。
 * JSON を返さないため DTO/JsonResource 規約の対象外 (redirect のみ)。
 */
class ManualDownloadController extends Controller
{
    use ResolvesCurrentOrganization;

    public function show(DownloadManualRequest $request, Project $project, VideoManual $manual, RenderObjectStorage $storage): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('download', $manual);

        // 完成物が存在しない (published でない / succeeded render なし) は 404 (409 系ではない)
        if ($manual->status !== VideoManualStatus::Published) {
            abort(404);
        }
        // 「いま受け取れる完成動画」の選択は CurrentRenderArtifact ただ 1 箇所 (playback と同一式)
        $job = CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render);
        if ($job === null || $job->output_path === null) {
            abort(404); // 完成物が無い / 実体が消えている
        }

        // filename の sanitize (CR/LF 除去・RFC 5987 + ASCII fallback) は Storage 側 helper が担う
        $filename = $manual->title.'.mp4';

        return redirect()->away($storage->temporaryDownloadUrl($job->output_path, $filename));
    }
}
```

### resources/js/components/features/manual/ManualListRow.svelte

```svelte
<script lang="ts">
    import { Download, Trash2 } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import { formatDurationMs } from "@/lib/manual/format-duration";
    import type { ManualListItem } from "@/types/manual";
    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";

    /**
     * 動画マニュアル一覧の 1 行 (doc/04: 状態 / タイトル / カテゴリ / 再生時間 / 更新日 / DL / 削除)。
     *
     * 表示の出し分けは**サーバが決めた行 props だけ**で行う
     * (downloadable / deletable。published も ability も UI 側で再判定しない)。
     * 削除の実行は一覧ページが持つ (この component は確認ダイアログを開く要求を上へ返すだけ)。
     */
    interface Props {
        projectId: number;
        manual: ManualListItem;
        /** 削除確認ダイアログを開く要求 */
        onRequestDelete: (manual: ManualListItem) => void;
    }

    let { projectId, manual, onRequestDelete }: Props = $props();

    const durationLabel = $derived(formatDurationMs(manual.duration_ms));
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
        {#if manual.downloadable}
            <!-- 受け取れるとサーバが判断した行にだけ出す。押せない (disabled) ボタンは作らない。
                 出ていない行の理由は状態バッジと再生時間「—」が語り、書き出しの CTA は
                 詳細画面 (RenderPanel) が唯一持つ。
                 素の <a> (inertia なし) = 非 Inertia 遷移。成功時は attachment 応答のため
                 画面は遷移しない。 -->
            <Button
                variant="ghost"
                size="sm"
                href={`/projects/${projectId}/manuals/${manual.id}/download`}
                ariaLabel={`${manual.title} の完成動画をダウンロード`}
                testId={`manual-download-${manual.id}`}
            >
                <Download class="size-4" />
                DL
            </Button>
        {/if}
        {#if manual.deletable}
            <Button
                variant="danger-ghost"
                size="sm"
                onclick={() => onRequestDelete(manual)}
                ariaLabel={`${manual.title} を削除`}
                testId={`manual-remove-${manual.id}`}
            >
                <Trash2 class="size-4" />
                削除
            </Button>
        {/if}
    </div>
</li>

```

### resources/js/components/organisms/Modal.svelte + Modal.types.ts

```svelte
<script lang="ts">
    import { Dialog } from "bits-ui";
    import { X } from "@lucide/svelte";
    import type { ModalProps } from "./Modal.types";
    import { SIZE_CLASSES } from "./Modal.types";

    let {
        open = $bindable(false),
        title,
        size = "md",
        processing = false,
        children,
        footer,
        testId,
    }: ModalProps = $props();
</script>

<Dialog.Root bind:open>
    <Dialog.Portal>
        <!-- 暗幕は墨色 (text) の 50% で表現する (黒 hex を使わない) -->
        <Dialog.Overlay class="fixed inset-0 z-30 bg-text/50" />
        <!-- 影が使えないため border + bg-surface で背景と区別する (DESIGN.md §Elevation) -->
        <Dialog.Content
            class="fixed top-1/2 left-1/2 z-40 max-h-[85vh] w-full -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-lg border border-border bg-surface p-6 {SIZE_CLASSES[size]}"
            escapeKeydownBehavior={processing ? "ignore" : "close"}
            interactOutsideBehavior={processing ? "ignore" : "close"}
            data-testid={testId}
        >
            <div class="mb-4 flex items-start justify-between gap-4">
                <Dialog.Title class="text-h3 text-text">{title}</Dialog.Title>
                <Dialog.Close
                    class="rounded-sm text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-40"
                    aria-label="閉じる"
                    disabled={processing}
                >
                    <X class="size-5" aria-hidden="true" />
                </Dialog.Close>
            </div>
            {#if children}
                <div class="text-body text-text">
                    {@render children()}
                </div>
            {/if}
            {#if footer}
                <div class="mt-6 flex items-center justify-end gap-3">
                    {@render footer()}
                </div>
            {/if}
        </Dialog.Content>
    </Dialog.Portal>
</Dialog.Root>

```

```ts
import type { Snippet } from "svelte";

/**
 * Modal organism の仕様の真実。意味論は DESIGN.md §Components > Modal を参照。
 */

export type ModalSize = "sm" | "md" | "lg";

/** size → 最大幅クラス (カード・モーダルの角丸は rounded-lg = DESIGN.md §Shapes) */
export const SIZE_CLASSES = {
    sm: "max-w-md",
    md: "max-w-lg",
    lg: "max-w-2xl",
} as const satisfies Record<ModalSize, string>;

export interface ModalProps {
    /** 開閉状態 (bindable)。呼び出し側が $state で保持し bind:open する */
    open: boolean;
    /** ダイアログ見出し (aria-labelledby に配線される) */
    title: string;
    /** 幅: sm=max-w-md / md=max-w-lg (既定) / lg=max-w-2xl */
    size?: ModalSize;
    /** true の間 ESC / overlay クリックでの close を抑止する (二重実行防止) */
    processing?: boolean;
    /** 本文 */
    children?: Snippet;
    /** アクション行 (ボタン群)。未指定なら footer 領域を描画しない */
    footer?: Snippet;
    testId?: string;
}
```

### resources/js/types/manual.ts (ManualListItem 抜粋)

```ts
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
     * 完成動画を受け取れるか。サーバが「download ability × published ×
     * 現行世代の succeeded render に output_path がある」を判定した結果そのもので、
     * **UI 側で条件を再判定しない**。download endpoint が 302 を返す条件と 1 対 1
     * (描画時点のスナップショットであり、ストレージ実体の存在確認ではない)。
     */
    downloadable: boolean;
    /** 削除できるか (サーバの delete ability 判定結果。撮影者は false) */
    deletable: boolean;
}

export interface CategoryOption {
```

### resources/js/pages/Projects/Show.svelte (一覧まわり抜粋)

```svelte
        });
    }

    /* ---- 動画マニュアル: 行内削除 (ConfirmDialog → destroy) ---- */
    let removeManualTarget = $state<ManualListItem | null>(null);
    let removeManualDialogOpen = $state(false);
    let removingManual = $state(false);

    function openRemoveManualDialog(manual: ManualListItem): void {
        removeManualTarget = manual;
        removeManualDialogOpen = true;
    }

    /** 現在の絞り込み + 表示中ページを query string 化する (削除の着地先を保つため) */
    function manualQueryString(): string {
        // 使い捨ての組み立てなので URLSearchParams / SvelteURLSearchParams は使わない
        // (反応性の要らない場面で反応クラスを持ち込まない。svelte/prefer-svelte-reactivity)
        const serialized = Object.entries(manualQuery(manuals.meta.current_page))
            .map(
                ([key, value]) =>
                    `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`,
            )
            .join("&");

        return serialized === "" ? "" : `?${serialized}`;
    }

    function removeManual(): void {
        const target = removeManualTarget;
        // 二重送信ガードは handler 早期 return で行う (ボタンに disabled を付けない = 禁止事項 8)
        if (target === null || removingManual) return;
        // 絞り込み・ページを付けて送る。サーバは同じ allowlist を通して着地先に載せ直すため、
        // 削除後も同じ絞り込み・同じページに戻る (そのページが消えたらサーバが最終ページへ丸める)
        router.delete(`/projects/${project.id}/manuals/${target.id}${manualQueryString()}`, {
            preserveScroll: true,
            onStart: () => {
                removingManual = true;
            },
            onFinish: () => {
                removingManual = false;
                removeManualDialogOpen = false;
            },
        });
    }

    /* ---- プロジェクトメンバー管理 ---- */
                </form>

                {#if manuals.data.length === 0}
                    <EmptyState
                        title="動画マニュアルはまだありません"
                        description="SOP を起点に動画マニュアルを作成すると、ここに表示されます。"
                        testId="manuals-empty"
                    />
                {:else}
                    <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="manual-list">
                        {#each manuals.data as manual (manual.id)}
                            <ManualListRow
                                projectId={project.id}
                                {manual}
                                onRequestDelete={openRemoveManualDialog}
                            />
                        {/each}
                    </ul>
                    <div class="mt-4">
                        <Pagination
                            currentPage={manuals.meta.current_page}
                            totalPages={manuals.meta.last_page}
                            onChange={changeManualPage}
                            testId="manuals-pagination"
                        />
                    </div>
                {/if}
            </Card>
```

### resources/js/components/features/manual/RenderPanel.svelte (完成動画ブロック抜粋)

```svelte
    {#if rendering}
        <div class="mt-4 flex flex-col gap-2" data-testid="render-progress">
            <div class="flex items-center gap-2 text-body text-text-secondary">
                <LoaderCircle class="size-4 animate-spin" />
                <span data-testid="render-step-label">{stepLabel}</span>
            </div>
            <div
                class="h-2 w-full overflow-hidden rounded-md bg-neutral"
                role="progressbar"
                aria-valuenow={renderJob?.progress ?? 0}
                aria-valuemin={0}
                aria-valuemax={100}
            >
                <div
                    class="h-full rounded-md bg-primary transition-all"
                    style={`width: ${renderJob?.progress ?? 0}%`}
                ></div>
            </div>
            <p class="text-caption text-text-secondary">
                採用テイクを合成して完成動画を書き出しています。このページを開いたまましばらくお待ちください。
            </p>
        </div>
    {:else}
        {#if failedRenderJob?.error}
            <div class="mt-4" data-testid="render-error">
                <Alert type="danger" title="完成動画の生成に失敗しました">
                    {failedRenderJob.error}
                </Alert>
            </div>
        {/if}
        {#if needsRegenerate}
            <p class="mt-2 text-body text-text-secondary" data-testid="render-regenerate-note">
                シナリオが編集されています。最新の内容で完成動画を再生成してください。
            </p>
        {/if}
        <!-- 完成動画 (再生 + DL)。表示の可否はサーバが決めた finishedJob **だけ**で判断する
```

### tests/js/components/features/manual/ManualListRow.test.ts (抜粋)

```ts
import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import ManualListRow from "@/components/features/manual/ManualListRow.svelte";
import type { ManualListItem } from "@/types/manual";

/*
 * 動画マニュアル一覧の 1 行 (T182)。
 *
 * 固定する契約:
 * - 再生時間はサーバの duration_ms をそのまま整形する (未確定は「—」)
 * - DL / 削除の導線はサーバが決めた downloadable / deletable **だけ**で出し分ける
 *   (UI 側で published や権限を再判定しない)
 * - DL は**通常 anchor** (非 Inertia 遷移)。Inertia の Link へ退行したら赤くなる
 * - どちらの導線も disabled を持たない (禁止事項 8 の回帰封じ)
 *
 * Inertia の `Link` は素の <a href> として描画され判別できる属性を持たないため、
 * 既存のスタブ (tests/js/support/InertiaLinkStub.svelte) へ差し替えて
 * 「描画されたら印が残る」状態で検証する。
 */
vi.mock("@inertiajs/svelte", async () => ({
    Link: (await import("../../../support/InertiaLinkStub.svelte")).default,
}));

function manualItem(overrides: Partial<ManualListItem> = {}): ManualListItem {
    return {
        id: 1,
        title: "ネジ締め作業",
        status: "published",
        category: { id: 1, name: "準備作業" },
        creator: { id: 2, name: "編集 花子" },
        created_at: "2026-07-10 12:00",
        updated_at: "2026-07-11 09:00",
        duration_ms: 185_000,
        downloadable: true,
        deletable: true,
        ...overrides,
    };
}

function renderRow(overrides: Partial<ManualListItem> = {}, onRequestDelete = vi.fn()) {
    render(ManualListRow, {
        props: { projectId: 7, manual: manualItem(overrides), onRequestDelete },
    });

    return onRequestDelete;
}

describe("features/manual/ManualListRow", () => {
    it("再生時間・状態バッジ・カテゴリ / 作成者 / 更新日を描画する", () => {
        renderRow();

        expect(screen.getByTestId("manual-duration-1")).toHaveTextContent("3:05");
        expect(screen.getByTestId("manual-status-1")).toHaveTextContent("公開済み");
        expect(screen.getByText(/準備作業 ・ 編集 花子 ・ 更新 2026-07-11 09:00/)).toBeInTheDocument();
    });

    it("duration_ms が null のときは「—」を表示する (0:00 と書かない)", () => {
        renderRow({ duration_ms: null });

        expect(screen.getByTestId("manual-duration-1")).toHaveTextContent("—");
    });

    it("downloadable=true のとき DL リンクを download endpoint へ出す", () => {
        renderRow();

        const link = screen.getByTestId("manual-download-1");
        expect(new URL(link.getAttribute("href") ?? "", "http://localhost").pathname).toBe(
            "/projects/7/manuals/1/download",
        );
    });

    it("DL は通常 anchor である (Inertia Link へ退行したら赤くなる)", () => {
        renderRow();

        const link = screen.getByTestId("manual-download-1");
        expect(link.tagName).toBe("A");
        // Inertia Link スタブが描画されるのはタイトルリンクの 1 本だけ (DL は素の <a>)
        expect(screen.getAllByTestId("inertia-link-stub")).toHaveLength(1);
    });

    it("downloadable=false のとき DL リンクを出さない (押せないボタンを置かない)", () => {
        renderRow({ downloadable: false });

        expect(screen.queryByTestId("manual-download-1")).toBeNull();
    });

    it("deletable=true のとき削除ボタンを出し、押すとその行で onRequestDelete が呼ばれる", async () => {
        const onRequestDelete = renderRow();

        await fireEvent.click(screen.getByTestId("manual-remove-1"));

        expect(onRequestDelete).toHaveBeenCalledTimes(1);
        expect(onRequestDelete.mock.calls[0][0]).toMatchObject({ id: 1, title: "ネジ締め作業" });
    });

    it("deletable=false のとき削除ボタンを出さない", () => {
        renderRow({ deletable: false });

        expect(screen.queryByTestId("manual-remove-1")).toBeNull();
    });
```

### tests/Feature/Manual/ManualRowDownloadableParityTest.php (抜粋)

```php
<?php

declare(strict_types=1);

use App\Enums\Manual\RenderKind;
use App\Enums\Manual\VideoManualStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\CurrentRenderArtifact;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/*
 * T182: 一覧の候補行 relation (VideoManual::latestSucceededRender) と
 * 受け取り対象の選択式 (CurrentRenderArtifact::currentSucceeded) の**世代定義が一致**すること、
 * および一覧の downloadable と download endpoint (302 / 404) の判断が一致すること。
 *
 * 両者の違いは 1 点だけである: relation は output_path を見ない (候補行を返す) ので、
 * 「受け取れるか」は呼び出し側が output_path を足して判断する。
 */

/**
 * 署名 URL を stub した上で組織・所有者・プロジェクトを用意する
 * (fake local disk は temporaryUrl を標準サポートしないため)。
 *
 * @return array{Organization, User, Project}
 */
function parityFixture(): array
{
    Storage::fake('s3');
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn (string $path): string => "https://signed.example/{$path}",
    );
    [$organization, $owner] = createOrganizationWithOwner();

    return [$organization, $owner, Project::factory()->forOrganization($organization)->create()];
}

test('succeeded が 2 世代あるとき両者とも最新の行を指す', function (): void {
    [, $owner, $project] = parityFixture();
    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
    RenderJob::factory()->forManual($manual)->succeeded('renders/old.mp4')->create();
    $newest = RenderJob::factory()->forManual($manual)->succeeded('renders/new.mp4')->create();

    $manual->refresh();

    expect($manual->latestSucceededRender?->id)->toBe($newest->id);
    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)?->id)->toBe($newest->id);

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('manuals.data.0.downloadable', true));
    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
        ->assertRedirect();
});

test('最新 succeeded の output_path が null なら旧世代へフォールバックしない (一覧 false / endpoint 404)', function (): void {
    [, $owner, $project] = parityFixture();
```
