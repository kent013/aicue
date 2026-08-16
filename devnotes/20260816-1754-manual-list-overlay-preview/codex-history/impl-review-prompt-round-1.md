# T189 実装レビュー依頼 (Round 1)

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

Laravel 12 (PHP 8.4) + Svelte 5 (runes) + Inertia.js + TypeScript のコードレビュアーとして、TODO T189 の実装差分をレビューする。

### レビュー観点

1. **設計との一致性**: 詳細設計書 (下記) の施策 1〜6 が意図どおり実装されているか。設計から逸脱している箇所があれば、それが妥当な逸脱か。
2. **正確性**: 境界条件・null 安全・世代選択の規則 (最新 succeeded の output_path が NULL なら旧世代へフォールバックしない) が壊れていないか。props と endpoint の判断の非対称が生まれていないか。
3. **PHPStan level 10 適合性**: 型の widen / ignore が無いか。
4. **DTO / JsonResource パターン**: `response()->json()` 直書きが無いか。Inertia props は DTO 経由か。
5. **テスト網羅性**: 各施策にテストがあるか。テストが「壊れたら赤くなる」形になっているか (常時緑になる書き方をしていないか)。Factory 経由のデータ生成か。
6. **セキュリティ**: 秘匿境界 (署名 URL が props / HTML に載らない)、権限判定の所在、IDOR。
7. **DESIGN.md 準拠**: `/DESIGN.md` が design token の canonical source。color / radius / typography は token 経由で参照し hex 直書き (`#RRGGBB`) を増やしていないか。token 値を変更する diff は `resources/css/tokens.css` と同一 diff 内で同期しているか。
8. **Atomic Design 準拠**: `resources/js/components/` は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import。atom は単機能・状態を持たない。アイコンは `@lucide/svelte` のみで SVG 直書きを増やさない。

### 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記する

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
| 1 | 一覧向けの選択入口を Canonical に足す + 行 props の置換 (`downloadable` → `current_finished_render_job_id`) | `app/Services/Manual/CurrentRenderArtifact.php` / `app/DataTransferObjects/Manual/ManualListItemData.php` / `app/Http/Controllers/Projects/ProjectController.php` (PHPDoc) / `resources/js/types/manual.ts` | 高 |
| 2 | オーバーレイ再生 component の新設 | `resources/js/components/features/manual/ManualPreviewModal.svelte` (新規) | 高 |
| 3 | 行にプレビュー導線を追加 | `resources/js/components/features/manual/ManualListRow.svelte` | 高 |
| 4 | 一覧ページへのモーダル配線 | `resources/js/pages/Projects/Show.svelte` | 高 |
| 5 | テスト (Unit + Feature + Architecture) | `tests/Unit/Manual/CurrentRenderArtifactLoadedCandidateTest.php` (新規) / `tests/Feature/Projects/ProjectShowManualsTest.php` / `tests/Feature/Manual/ManualRowDownloadableParityTest.php` → `ManualRowFinishedVideoParityTest.php` (rename + 拡張) / `tests/Architecture/CurrentRenderArtifactInventoryTest.php` (選択の所在を固定するケース追加) / rename に伴う参照更新 `app/Models/VideoManual.php` docblock・`app/Support/Security/RenderArtifactSelectionInventory.php` の根拠文 | 高 |
| 6 | テスト (Vitest) | `tests/js/components/features/manual/ManualListRow.test.ts` / `ManualPreviewModal.test.ts` (新規) / `tests/js/pages/ProjectsShow.test.ts` | 高 |

**新設しないもの**: route / Controller / **新しい Service クラス** / Job / migration / config キー。
サーバ側の変更は **既存の Canonical Service (`CurrentRenderArtifact`) への一覧用入口の追加**と、
**DTO が運ぶ値 (bool → id) の変更**の 2 点だけである (成果物の選択規則そのものは現行のまま)。

---

## 施策 1: 一覧向けの選択入口を Canonical に足す + 行 props の置換

> **設計レビュー Round 1 [Critical] 反映**: 「いま受け取れる成果物はどれか」を**選ぶ**責務は
> `CurrentRenderArtifact` (Canonical) ただ 1 ファイルに置く (ドメイン規約 13 / T154)。
> bool を返していた間は「受け取れるか」の副次判定に見えたが、**id を返す瞬間に選択そのもの**になるため、
> DTO 側で `latestSucceededRender` + `output_path` を組み立てる形は採らない。
> `EagerLoadCandidate` 区分の enum docblock も「決定は Canonical に残る」と明言しており、
> 本施策はその明言に実装を合わせる変更でもある。

### 変更箇所

- `app/Services/Manual/CurrentRenderArtifact.php` (一覧向け入口 `fromLoadedRenderCandidate()` を追加 +
  「実体が残っている行だけを返す」規則を private helper に 1 本化)
- `app/DataTransferObjects/Manual/ManualListItemData.php` (コンストラクタ / `fromManual` / `toArray` の PHPDoc)
- `app/Http/Controllers/Projects/ProjectController.php` L149-158 (`manualRows()` の `@return` array shape)
- `app/Models/VideoManual.php` L116-128 (`latestSucceededRender()` の docblock。責務の移管を反映)
- `app/Support/Security/RenderArtifactSelectionInventory.php` (`Canonical` の根拠文 = 消費者 3 → 4)
- `resources/js/types/manual.ts` L119-127 (`ManualListItem`)

### Canonical 移管に伴う既存記述の更新 (レビュー Round 2 [Warning] 反映)

責務が移るので、**現行コードの説明文も同じ PR で直す** (説明と実装の食い違いを残さない):

- `app/Models/VideoManual.php` の `latestSucceededRender()` docblock —
  現行は「受け取れるかの決定は呼び出し側 (ManualListItemData) が `output_path !== null` を
  足して行う」と書いてあり、修正後は**事実誤認**になる。次の意味へ全面更新する:
  1. relation は**候補行だけ**を返す (`output_path` を見ない)
  2. 受け取れるかの決定 (`output_path` を含む) は `CurrentRenderArtifact` が行う
     (一覧向けの入口は `fromLoadedRenderCandidate()`)
  3. `ManualListItemData` が合成するのは **published 判定と ability 判定だけ**である
  4. 世代定義の一致を固定するテスト名を新名 (`ManualRowFinishedVideoParityTest`) にする
- `app/Support/Security/RenderArtifactSelectionInventory.php` の `Canonical` 根拠文 —
  現行の「playback / download / 詳細画面 props の 3 消費者」に**一覧行 props** が加わるので
  「playback / download / 詳細画面 props / 一覧行 props の 4 消費者」に更新する。
  同ファイルの `EagerLoadCandidate` 根拠文にある旧テスト名も新名へ差し替える
  (根拠は 30 文字以上を維持)。

### 目録 (T154) への影響

- `CurrentRenderArtifact.php` は既に区分 `Canonical` で登録済み。**メソッド追加は登録の変更を要さない**
  (走査は「succeeded 条件つきの直接クエリを持つファイル」の母集団で、同ファイル内の追加は Canonical の役割そのもの)。
- `ManualListItemData.php` は**母集団に入らない** (`JobStatus::Succeeded` / `'succeeded'` /
  `renderJobs(` / `RenderJob::` / `'render_jobs'` のいずれも持たない)。本施策後は `output_path` の
  参照も消えるため、判断の痕跡が DTO から無くなる。
- `Models/VideoManual.php` (`EagerLoadCandidate`) の前提「`output_path` を参照しない」は不変。

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
// app/Services/Manual/CurrentRenderArtifact.php (現行全文)
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
// app/Services/Manual/CurrentRenderArtifact.php
/**
 * 「いま受け取れるレンダ成果物はどれか」の**唯一の選択式** (playback / download / 詳細画面 props /
 * 一覧行 props)。
 *
 * 入口は 2 つあるが**規則は 1 つ**である:
 *   - currentSucceeded()            … 1 件表示・endpoint 用 (クエリを 1 本撃つ)
 *   - fromLoadedRenderCandidate()   … 一覧用 (eager load 済みの候補行から選ぶ。クエリを撃たない)
 * どちらも「最新 succeeded の output_path が NULL なら**旧世代へフォールバックしない**」
 * という同じ規則 (private receivable()) を通る。
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

        return self::receivable($job);
    }

    /**
     * eager load 済みの候補行 (VideoManual::latestSucceededRender = kind=render ∧ succeeded の
     * 最新 1 行) から、現在受け取れる完成動画を選ぶ。**一覧専用の入口で追加クエリを撃たない**
     * (行数に比例したクエリを増やさないという一覧の前提を守るため)。
     * 候補 relation が kind=render 固定なので kind 引数は取らない
     * (取れるように見せると「一覧から preview を選べる」という誤読を生む)。
     *
     * **未ロードでの呼び出しは例外**にする (レビュー Round 2 [Warning])。
     * 名前が「ロード済みの候補行から」と約束している一方、素直に読むと lazy load で
     * 黙って N+1 になるためである。呼び出し側が eager load を外したら**その場で落ちる**
     * (一覧のクエリ数が行数に比例して増える退行を、遅い本番ではなくテストで検出する)。
     */
    public static function fromLoadedRenderCandidate(VideoManual $manual): ?RenderJob
    {
        Assert::true(
            $manual->relationLoaded('latestSucceededRender'),
            'latestSucceededRender を eager load してから呼ぶこと (一覧の N+1 防止)',
        );

        return self::receivable($manual->latestSucceededRender);
    }

    /**
     * 実体が残っている行だけを返す共通規則。
     * output_path が NULL の最新 succeeded は「生成に失敗した / 掃除された」であり、
     * 旧世代へフォールバックしない (削除済みオブジェクトの署名 URL を出さないため)。
     */
    private static function receivable(?RenderJob $job): ?RenderJob
    {
        if ($job === null || $job->output_path === null) {
            return null;
        }

        return $job;
    }
}
```

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

        // 「どの行か」の選択は **CurrentRenderArtifact ただ 1 箇所**に委ねる (T154)。
        // 一覧は eager load 済み候補から選ぶ入口を使う (行数に比例したクエリを撃たない)。
        // ここに残るのは Canonical が持たない責務 = published 判定と ability 判定だけである。
        // **ストレージ実体の存在確認ではない** (download / playback endpoint もしていない)。
        $currentFinishedRenderJobId = $abilities->canDownload && $isPublished
            ? CurrentRenderArtifact::fromLoadedRenderCandidate($manual)?->id
            : null;

        return new self(
            // …
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

- [x] 戻り値の型が明示されている (`fromLoadedRenderCandidate(): ?RenderJob` /
      `receivable(): ?RenderJob` / `fromManual(): self` / `toArray(): array{…}`)
- [x] null 安全: 選択は `?RenderJob` を返し、DTO 側は `?->id` で `int|null` を得る。
      **bool 変数越しの絞り込みに依存しない** (PHPStan は真偽変数から `!== null` を伝播しない)
- [x] DTO を返している (配列返却なし。`toArray()` は Inertia props 生成の末端で、shape を PHPDoc で固定)
- [x] Generics: 変更なし (`latestSucceededRender` は既存の `HasOne` 宣言、`@property-read RenderJob|null`)
- [x] `receivable()` は private static で副作用なし。`$job->output_path` は `string|null` (既存 pin と同型)
- [x] `Webmozart\Assert\Assert::true()` で eager load 前提を表明 (level 10 の型は変えないが、
      規約の「null 安全は Assert を使う」流儀に沿う。`relationLoaded()` は `bool` を返す)

### テスト計画

- [ ] 更新 `tests/Feature/Projects/ProjectShowManualsTest.php`
  - 旧 `downloadable は published × 現行世代の…` を
    `current_finished_render_job_id は published × 現行世代の succeeded **render (kind=render)** に output_path があるときだけ id を返す` に改題し、
    4 ケース (ok / stale=output_path null / **preview の succeeded しか無い** / 未 published) の期待値を `id` / `null` に更新
    (レビュー Round 1 [Warning] 反映: テスト名で kind=render を明示し、
    **preview kind を一覧から返さない**ケースを維持する)
  - 旧 `撮影者は downloadable / deletable ともに false…` を
    `撮影者は current_finished_render_job_id=null / deletable=false、編集者は id と deletable=true` に更新
  - **新規** `一覧の行 props に旧キー downloadable が残っていない` —
    `array_key_exists('downloadable', $row)` が false であることを固定 (置換の取り残しを赤くする)
- [ ] **新規 (Unit)** `tests/Unit/Manual/CurrentRenderArtifactLoadedCandidateTest.php`
  (レビュー Round 2 [Warning] 反映。メソッド名の契約を機械で固定する)
  - eager load 済み manual を渡すと候補行を返し、**追加クエリを 1 本も撃たない**。
    **観測区間を明示する** (レビュー Round 3 [Suggestion]): fixture 生成と
    `$manual->load('latestSucceededRender')` を**終えてから**カウンタを開始し、
    `fromLoadedRenderCandidate()` の呼び出しだけを測る
    (既存の query-count helper があればそれを使う。無ければ `DB::listen` を
    `ManualListQueryCountTest` と同じ流儀で用いる)
  - `output_path` が NULL の候補行では null を返す (旧世代へフォールバックしない)
  - **未ロードの manual を渡すと `InvalidArgumentException`** になる
    (`Webmozart\Assert\Assert::true()` が投げる型を明示して固定する = 「何かが落ちた」で終わらせない。
    レビュー Round 3 [Suggestion])
  - 実装側は `CurrentRenderArtifact.php` に `use Webmozart\Assert\Assert;` の追加が要る
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
    <!-- manual を条件に含めるのは型と意図を揃えるため (レビュー Round 1 [Suggestion])。
         playbackSrc が非 null なら manual も非 null だが、その含意を読み手と型検査に委ねない -->
    {#if manual !== null && playbackSrc !== null}
        <!-- svelte-ignore a11y_media_has_caption (完成動画の字幕は焼き込み済み) -->
        <!-- preload="none": ブラウザに事前取得しないよう指示し、意図しない先読みを抑制する
             (ヒントであって要求ゼロの保証ではない)。
             RenderPanel の完成動画と同じ値に揃える = 2 通りの流儀を作らない。
             尺は一覧行が duration_ms で既に出しているため、metadata を先読みしても
             操作回数は減らず、得られる情報も増えない (レビュー Round 2 の指摘どおり)。
             autoplay は付けない: 音声付き autoplay はブラウザポリシーで拒否される環境があり
             「押したのに再生されない」が環境依存で生まれるため、再生開始は標準 controls に委ねる -->
        <video
            controls
            preload="none"
            class="w-full rounded-md bg-neutral"
            src={playbackSrc}
            aria-label={`${manual.title} の完成動画`}
            data-testid="manual-preview-video"
        ></video>
    {/if}
</Modal>
```

#### `preload` の決着: `none` (レビュー Round 1 [Warning] → Round 2 [Warning] で訂正)

Round 1 では `metadata` を採り「二度手間を避ける」と説明したが、**これは誤りだった**。
`autoplay` を付けない以上、再生ボタンの押下は `metadata` でも `none` でも 1 回必要で、
操作回数は減らない。得られるはずの情報 (尺) も、一覧行が `duration_ms` で既に出している。

よって **`preload="none"` に決める**:

- ブラウザに**事前取得しないよう指示する** (意図しない先読みを抑制する)。
- 詳細画面 (`RenderPanel`) の完成動画と**同じ値**になり、2 通りの流儀を作らない。

**保証範囲を誇張しない (レビュー Round 3 [Warning])**: `preload` は**ヒント**であり、
`none` でもブラウザがネットワーク要求を一切出さないことまでは保証されない。
したがって本設計は「開いただけでは playback 要求が 0 件である」とは主張しない。
Vitest が固定するのも **`preload="none"` という指定が付いていること**だけである
(HTTP 要求数の証明ではない)。
「要求が 0 件であること」を不変条件として必要とするなら、再生操作まで `src` を設定しない
別設計が要るが、**今回はその強い保証を必要としない** — 署名 URL は 302 の先にあり、
受け取れる相手かは endpoint が毎回判定するため、先読みが起きても秘匿境界は動かない。

### PHPStan 適合チェック

- 対象外 (フロントエンド)。TypeScript 側は `pnpm typecheck` / `pnpm lint` で担保する
  (`playbackSrc` は `string | null` に推論され、`{#if}` で narrowing 済み)。

### テスト計画

- [ ] 新規 `tests/js/components/features/manual/ManualPreviewModal.test.ts`
  - `open=true` のとき `<video>` の `src` が
    `/projects/7/manuals/2/render-jobs/9/playback` になる
  - `<video>` が `controls` 属性を持つ (自前の再生制御を持たないことの契約)
  - `<video>` に `preload="none"` **の指定が付いている**こと
    (属性指定の固定であって、HTTP 要求が 0 件であることの証明ではない)
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
- **狭幅の見た目は Vitest では検出できない** (レビュー Round 1 [Suggestion])。
  Browser レーン (Chromium + WebKit の 2 レーン契約) はログアウト復元など**恒久回帰の検証**に
  用いており、スクリーンショット比較の基盤は持っていない。ボタン 1 つの追加のために
  レーンを増やす費用は見合わないため**本設計では足さない** (今必要なものだけ作る)。
  縦積みへ逃がすレイアウト規則は T182 で既に入っており、追加ボタンはその規則の内側に収まる。

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

### 閉じた後に対象行を保持することについて (レビュー Round 1 [Warning] への回答)

`previewManualTarget` は閉じても null に戻さない。理由:

- 描画されるのは `open === true` の間だけで、閉じている間は `<video>` ごと DOM に無い
  (Vitest の「閉じているとき video が無い」ケースがこれを固定する)。
- 開く操作は必ず `openPreviewManualDialog` を通り、そこで対象を**必ず**入れ替える
  (行の取り違えは構造的に起きない)。
- 絞り込み・ページ送りの部分再読込 (`only: ["manuals", "manualFilters"]`) 後に
  古い行オブジェクトが残っていても、**再生できるかの最終判断は endpoint 側**であり
  (旧世代 id は 404 / 権限が消えていれば 403)、props は秘匿境界ではなく表示制御である。
- 「閉じたら null に戻す」handler を足すのは、消せるリスクが無い分の追加配線になる
  (今必要なものだけ作る)。

### リスク

- **jsdom で bits-ui Dialog が描画できないのでは**: 既に
  `tests/js/components/organisms/Modal.test.ts` と `ConfirmDialog.test.ts` が同じ組み合わせで
  緑になっており、`Projects/Show.svelte` の既存 Modal (アイテム編集) もテスト済み。前提は成立している。
- **クリック直後の非同期描画**: bits-ui は open 反映が microtask をまたぐ場合があるため、
  アサーションは `waitFor` / `findBy*` で待つ (同ファイルの既存テストが `waitFor` を使用済み)。

---

## 施策 5: Feature テスト + 選択の所在を固定する Architecture テスト

### 変更箇所

- `tests/Unit/Manual/CurrentRenderArtifactLoadedCandidateTest.php` (**新規**。
  施策 1 で足す一覧向け入口の契約 = eager load 前提・実体判定・未ロード例外)
- `tests/Feature/Projects/ProjectShowManualsTest.php` (T182 ブロック L278 付近)
- `tests/Feature/Manual/ManualRowDownloadableParityTest.php`
  → `tests/Feature/Manual/ManualRowFinishedVideoParityTest.php` (rename + 拡張)
- `tests/Architecture/CurrentRenderArtifactInventoryTest.php` (ケース追加。**新しい目録は作らない**)

### 波及変更

- 既存テストの**削除はしない**。key の更新・改題・ケース追加のみ
  (rename はファイル名が概念名を含んでおり、置換後の名前と食い違うため)
- **rename に伴う参照更新 (追跡下のコード 2 箇所)**:
  - `app/Models/VideoManual.php` L124 の docblock
    「世代定義の一致は `ManualRowDownloadableParityTest` が固定する」
  - `app/Support/Security/RenderArtifactSelectionInventory.php` L48 の `EagerLoadCandidate` 根拠文
    (同名を含む。根拠は 30 文字以上を維持する)。
    同ファイルの `Canonical` 根拠文の「3 消費者 → 4 消費者」更新は施策 1 の担当 (二重に書かない)
  - `docs/TODO-closed.md` の T182 行にも旧名が出るが、**過去の記録なので書き換えない**
    (当時の事実を保つ。現行コードの参照だけを直す)

### 選択の所在を固定するテスト (レビュー Round 1 [Critical] 反映)

parity テストだけでは「DTO 側に選択式を複製しても緑のまま」になる。
そこで **T154 の既存 Architecture テストにケースを 1 本足す**:

```php
// tests/Architecture/CurrentRenderArtifactInventoryTest.php (追加ケース)
// 読み込みは同ファイルの既存 helper RenderArtifactSelectionScanner::tokensOf() を使う
// (file_get_contents の string|false を自前で捌かない = PHPStan level 10 安全。
//  レビュー Round 2 [Warning] 反映)。
test('一覧行 DTO は成果物行の選択を Canonical へ委譲する', function (): void {
    $tokens = RenderArtifactSelectionScanner::tokensOf(
        'DataTransferObjects/Manual/ManualListItemData.php'
    );
    $texts = array_column($tokens, 'text');

    // 「どの行か」「実体が残っているか」の規則を DTO へ書き戻したら赤くなる
    expect($texts)->not->toContain('output_path');
    expect($texts)->not->toContain('latestSucceededRender');
    // 選択は Canonical 経由であること (委譲の実在)
    expect($texts)->toContain('CurrentRenderArtifact');
});
```

テスト名は**検査していることだけ**を言う (レビュー Round 2 [Suggestion])。
禁じているのは「成果物行の選択を DTO に書き戻すこと」であって、
**ability / published の判定は DTO に残る** (Canonical が持たない責務なので当然に残る)。

**保証範囲を誇張しない**: これが閉じるのは**この 1 ファイル**についてだけである。
別ファイルへ同義式を切り出す経路・動的呼び出し・文字列変数経由には沈黙する
(既存の目録テストと同じ限界であり、fail-first は parity テストが担う)。

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
    // レビュー Round 1 [Warning] 反映: **権限だけで結論が決まるデータ**にする。
    // published + 現行世代 succeeded + output_path あり = 編集者なら 302 になる状態を用意し、
    // 撮影者だと 403 になることを見る (404 と混ざらない = 層 2 の 404 ではないことが確定する)。
    [$organization, $owner, $project] = parityFixture();
    // 撮影者の用意は既存テストと同じ helper 経路 (tests/Pest.php)
    $shooter = attachOrganizationMember($organization);
    $shooter->forceFill(['current_organization_id' => $organization->id])->save();
    attachProjectMember($project, $shooter, ProjectRole::Member);
    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
    $job = RenderJob::factory()->forManual($manual)->succeeded('renders/ok.mp4')->create();

    // 編集者は 302 (データ側は「受け取れる」状態であることの対照)
    $this->actingAs($owner)
        ->get("/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback")
        ->assertRedirect();

    $rows = $this->actingAs($shooter)->get("/projects/{$project->id}")
        ->inertiaPage()['props']['manuals']['data'];
    expect($rows[0]['current_finished_render_job_id'])->toBeNull();

    $this->actingAs($shooter)
        ->get("/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback")
        ->assertForbidden();
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

- [ ] `composer test -- --filter=CurrentRenderArtifactLoadedCandidate`
- [ ] `composer test -- --filter=ManualRowFinishedVideoParity`
- [ ] `composer test -- --filter=ProjectShowManuals`
- [ ] `composer test -- --filter=CurrentRenderArtifactInventory` (選択の所在 + 既存 exact-fit)
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
    // fixture の既定に依存させない (レビュー Round 1 [Warning] 反映)。
    // 「3 つとも出ている状態」をこのテスト自身が宣言する
    renderRow({ current_finished_render_job_id: 9, deletable: true });

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
| 判断根拠 | 変更は既存ファイルの局所編集 + **新規 3 本** (component 1 本 `ManualPreviewModal.svelte` / Vitest 1 本 `ManualPreviewModal.test.ts` / Unit テスト 1 本 `CurrentRenderArtifactLoadedCandidateTest.php`) で、DB・route・**新規 Service** を作らない (既存 Canonical Service へ一覧用入口を 1 つ足すだけ)。施策 1 (props 置換) → 施策 2/3/4 (UI) → 施策 5/6 (テスト) の順に段階検証でき、各段で `composer test` / `pnpm test` を回せる。standalone にするほどの構造変更が無い |
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

## 実装差分 (git diff HEAD)

```diff
diff --git a/app/DataTransferObjects/Manual/ManualListItemData.php b/app/DataTransferObjects/Manual/ManualListItemData.php
index b652580..7d894fd 100644
--- a/app/DataTransferObjects/Manual/ManualListItemData.php
+++ b/app/DataTransferObjects/Manual/ManualListItemData.php
@@ -6,6 +6,7 @@
 
 use App\Enums\Manual\VideoManualStatus;
 use App\Models\VideoManual;
+use App\Services\Manual\CurrentRenderArtifact;
 use App\Services\Manual\ManualRowAbilities;
 
 /**
@@ -20,7 +21,12 @@
      * @param  ManualListRefData|null  $category  null = 未分類
      * @param  ManualListRefData|null  $creator  null = 退会/削除で解決不可
      * @param  int|null  $durationMs  いま公開されている完成動画の長さ (ms)。null = 未確定
-     * @param  bool  $downloadable  download endpoint が 302 を返す条件と 1 対 1
+     * @param  int|null  $currentFinishedRenderJobId  いま受け取れる完成動画 (kind=render) の
+     *                                                render job id。**null = 受け取れない**。非 null であることは download endpoint が
+     *                                                302 を返す条件と 1 対 1 (download ability × published × 現行世代の succeeded render に
+     *                                                output_path がある)。値は再生 endpoint
+     *                                                `projects.manuals.render-jobs.playback` のパスにそのまま使える
+     *                                                (完成動画の再生条件は download と完全同一 = ドメイン規約 13 / T154)
      */
     public function __construct(
         public int $id,
@@ -31,7 +37,7 @@ public function __construct(
         public string $createdAt,
         public string $updatedAt,
         public ?int $durationMs,
-        public bool $downloadable,
+        public ?int $currentFinishedRenderJobId,
         public bool $deletable,
     ) {}
 
@@ -46,15 +52,13 @@ public static function fromManual(VideoManual $manual, ManualRowAbilities $abili
         // 最新シナリオと対応しない古い尺なので出さない。
         $durationMs = $isPublished ? $manual->total_length_ms : null;
 
-        // 受け取れる完成動画: download ability × published × 現行世代の succeeded render に
-        // output_path がある。**ストレージ実体の存在確認ではない** (それは download endpoint も
-        // していない。ここは endpoint が 302 を返す条件と同じものを見ているだけ)。
-        // 世代の選び方は CurrentRenderArtifact と同一 (latestSucceededRender の docblock 参照)。
-        $currentRender = $manual->latestSucceededRender;
-        $downloadable = $abilities->canDownload
-            && $isPublished
-            && $currentRender !== null
-            && $currentRender->output_path !== null;
+        // 「どの行か」の選択は **CurrentRenderArtifact ただ 1 箇所**に委ねる (T154)。
+        // 一覧は eager load 済み候補から選ぶ入口を使う (行数に比例したクエリを撃たない)。
+        // ここに残るのは Canonical が持たない責務 = published 判定と ability 判定だけである。
+        // **ストレージ実体の存在確認ではない** (download / playback endpoint もしていない)。
+        $currentFinishedRenderJobId = $abilities->canDownload && $isPublished
+            ? CurrentRenderArtifact::fromLoadedRenderCandidate($manual)?->id
+            : null;
 
         return new self(
             id: $manual->id,
@@ -65,7 +69,7 @@ public static function fromManual(VideoManual $manual, ManualRowAbilities $abili
             createdAt: $manual->created_at?->format('Y-m-d H:i') ?? '',
             updatedAt: $manual->updated_at?->format('Y-m-d H:i') ?? '',
             durationMs: $durationMs,
-            downloadable: $downloadable,
+            currentFinishedRenderJobId: $currentFinishedRenderJobId,
             deletable: $abilities->canDelete,
         );
     }
@@ -75,7 +79,7 @@ public static function fromManual(VideoManual $manual, ManualRowAbilities $abili
      *   category: array{id: int, name: string}|null,
      *   creator: array{id: int, name: string}|null,
      *   created_at: string, updated_at: string,
-     *   duration_ms: int|null, downloadable: bool, deletable: bool}
+     *   duration_ms: int|null, current_finished_render_job_id: int|null, deletable: bool}
      */
     public function toArray(): array
     {
@@ -88,7 +92,7 @@ public function toArray(): array
             'created_at' => $this->createdAt,
             'updated_at' => $this->updatedAt,
             'duration_ms' => $this->durationMs,
-            'downloadable' => $this->downloadable,
+            'current_finished_render_job_id' => $this->currentFinishedRenderJobId,
             'deletable' => $this->deletable,
         ];
     }
diff --git a/app/Http/Controllers/Projects/ProjectController.php b/app/Http/Controllers/Projects/ProjectController.php
index 1842fe9..e515cb4 100644
--- a/app/Http/Controllers/Projects/ProjectController.php
+++ b/app/Http/Controllers/Projects/ProjectController.php
@@ -153,7 +153,7 @@ public function show(Request $request, Project $project, SeoManager $seo): Respo
      *     category: array{id: int, name: string}|null,
      *     creator: array{id: int, name: string}|null,
      *     created_at: string, updated_at: string,
-     *     duration_ms: int|null, downloadable: bool, deletable: bool}>,
+     *     duration_ms: int|null, current_finished_render_job_id: int|null, deletable: bool}>,
      *   meta: array{current_page: int, last_page: int, per_page: int, total: int}
      * }
      */
diff --git a/app/Models/VideoManual.php b/app/Models/VideoManual.php
index aba9352..5a1c143 100644
--- a/app/Models/VideoManual.php
+++ b/app/Models/VideoManual.php
@@ -119,9 +119,11 @@ public function renderJobs(): HasMany
      * 世代の選び方は `CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)` と
      * **同一**である (同 manual・同 kind の最新 succeeded。旧世代へフォールバックしない)。
      * 違いは 1 点だけで、**こちらは受け取れるかを判断しない** — `output_path` を見ないため
-     * NULL (掃除済み) の行も返す。受け取れるかの決定は呼び出し側 (ManualListItemData) が
-     * `output_path !== null` を足して行い、両者が同じ行を指すことは
-     * `ManualRowDownloadableParityTest` が固定する。
+     * NULL (掃除済み) の行も返す。受け取れるかの決定 (`output_path` を含む) は
+     * `CurrentRenderArtifact` が行い、一覧向けの入口は
+     * `CurrentRenderArtifact::fromLoadedRenderCandidate($manual)` である
+     * (`ManualListItemData` が合成するのは published 判定と ability 判定だけ)。
+     * 候補行と選択式が同じ行を指すことは `ManualRowFinishedVideoParityTest` が固定する。
      *
      * 一覧が行ごとに `currentSucceeded()` を呼ぶと N+1 になるため、eager load できる形を用意する
      * (`ManualListQueryCountTest` がクエリ数の行数非依存を固定する)。
diff --git a/app/Services/Manual/CurrentRenderArtifact.php b/app/Services/Manual/CurrentRenderArtifact.php
index 3670171..c7da0f5 100644
--- a/app/Services/Manual/CurrentRenderArtifact.php
+++ b/app/Services/Manual/CurrentRenderArtifact.php
@@ -8,9 +8,16 @@
 use App\Enums\Manual\RenderKind;
 use App\Models\RenderJob;
 use App\Models\VideoManual;
+use Webmozart\Assert\Assert;
 
 /**
- * 「いま受け取れるレンダ成果物はどれか」の**唯一の選択式** (playback / download / 詳細画面 props)。
+ * 「いま受け取れるレンダ成果物はどれか」の**唯一の選択式**
+ * (playback / download / 詳細画面 props / 一覧行 props)。
+ *
+ * 入口は 2 つあるが**規則は 1 つ**である:
+ *   - currentSucceeded()            … 1 件表示・endpoint 用 (クエリを 1 本撃つ)
+ *   - fromLoadedRenderCandidate()   … 一覧用 (eager load 済みの候補行から選ぶ。クエリを撃たない)
+ * どちらも private receivable() を通る。
  *
  * 定義は保持ポリシー (RenderJobService::newerSucceededExists / DeleteRenderOutputsJob) と
  * **同じ世代定義**である: 実体が残るのは「同 manual・同 kind の最新 succeeded」だけなので、
@@ -31,8 +38,40 @@ public static function currentSucceeded(VideoManual $manual, RenderKind $kind):
             ->latest('id')
             ->first();
 
+        return self::receivable($job);
+    }
+
+    /**
+     * eager load 済みの候補行 (VideoManual::latestSucceededRender = kind=render ∧ succeeded の
+     * 最新 1 行) から、現在受け取れる完成動画を選ぶ。**一覧専用の入口で追加クエリを撃たない**
+     * (行数に比例したクエリを増やさないという一覧の前提を守るため)。
+     * 候補 relation が kind=render 固定なので kind 引数は取らない
+     * (取れるように見せると「一覧から preview を選べる」という誤読を生む)。
+     *
+     * **未ロードでの呼び出しは例外**にする。名前が「ロード済みの候補行から」と約束している一方、
+     * 素直に読むと lazy load で黙って N+1 になるためである。呼び出し側が eager load を外したら
+     * **その場で落ちる** (一覧のクエリ数が行数に比例して増える退行を、遅い本番ではなく
+     * テストで検出する)。
+     */
+    public static function fromLoadedRenderCandidate(VideoManual $manual): ?RenderJob
+    {
+        Assert::true(
+            $manual->relationLoaded('latestSucceededRender'),
+            'latestSucceededRender を eager load してから呼ぶこと (一覧の N+1 防止)',
+        );
+
+        return self::receivable($manual->latestSucceededRender);
+    }
+
+    /**
+     * 実体が残っている行だけを返す共通規則。
+     * output_path が NULL の最新 succeeded は「生成に失敗した / 掃除された」であり、
+     * 旧世代へフォールバックしない (削除済みオブジェクトの署名 URL を出さないため)。
+     */
+    private static function receivable(?RenderJob $job): ?RenderJob
+    {
         if ($job === null || $job->output_path === null) {
-            return null; // 旧世代へフォールバックしない (実体が無い可能性がある)
+            return null;
         }
 
         return $job;
diff --git a/app/Support/Security/RenderArtifactSelectionInventory.php b/app/Support/Security/RenderArtifactSelectionInventory.php
index 4b9dc4a..5f1020f 100644
--- a/app/Support/Security/RenderArtifactSelectionInventory.php
+++ b/app/Support/Security/RenderArtifactSelectionInventory.php
@@ -33,8 +33,8 @@ public static function entries(): array
         return [
             'Services/Manual/CurrentRenderArtifact.php' => [
                 'kind' => RenderArtifactSelectionKind::Canonical,
-                'rationale' => '「いま受け取れる成果物はどれか」の唯一の選択式。playback / download / 詳細画面 props の'
-                    .'3 消費者が同じ行を指すための場所であり、保持ポリシーと同じ世代定義を持つ。',
+                'rationale' => '「いま受け取れる成果物はどれか」の唯一の選択式。playback / download / 詳細画面 props /'
+                    .'一覧行 props の 4 消費者が同じ行を指すための場所であり、保持ポリシーと同じ世代定義を持つ。',
             ],
             'Services/Manual/RenderJobService.php' => [
                 'kind' => RenderArtifactSelectionKind::SupersessionCriterion,
@@ -45,7 +45,7 @@ public static function entries(): array
                 'kind' => RenderArtifactSelectionKind::EagerLoadCandidate,
                 'rationale' => 'latestSucceededRender() は一覧が eager load する候補行の relation であり、'
                     .'output_path を見ないため受け取れるかを判断しない (決定は Canonical に残る)。'
-                    .'世代定義の一致は ManualRowDownloadableParityTest が固定する。',
+                    .'世代定義の一致は ManualRowFinishedVideoParityTest が固定する。',
             ],
             'Services/Manual/RenderPipeline.php' => [
                 'kind' => RenderArtifactSelectionKind::SupersessionCriterion,
diff --git a/resources/js/components/features/manual/ManualListRow.svelte b/resources/js/components/features/manual/ManualListRow.svelte
index 59b9027..fbcdec5 100644
--- a/resources/js/components/features/manual/ManualListRow.svelte
+++ b/resources/js/components/features/manual/ManualListRow.svelte
@@ -1,5 +1,5 @@
 <script lang="ts">
-    import { Download, Trash2 } from "@lucide/svelte";
+    import { Download, Play, Trash2 } from "@lucide/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import TextLink from "@/components/atoms/TextLink.svelte";
@@ -8,22 +8,28 @@
     import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";
 
     /**
-     * 動画マニュアル一覧の 1 行 (doc/04: 状態 / タイトル / カテゴリ / 再生時間 / 更新日 / DL / 削除)。
+     * 動画マニュアル一覧の 1 行 (doc/04: 状態 / タイトル / カテゴリ / 再生時間 / 更新日 /
+     * プレビュー / DL / 削除)。
      *
      * 表示の出し分けは**サーバが決めた行 props だけ**で行う
-     * (downloadable / deletable。published も ability も UI 側で再判定しない)。
-     * 削除の実行は一覧ページが持つ (この component は確認ダイアログを開く要求を上へ返すだけ)。
+     * (current_finished_render_job_id / deletable。published も ability も UI 側で再判定しない)。
+     * プレビューと DL は**同じ props 1 本**で出し分ける (再生条件は download と完全同一 = T154)。
+     * 実行は一覧ページが持つ (この component は要求を上へ返すだけ)。
      */
     interface Props {
         projectId: number;
         manual: ManualListItem;
+        /** プレビュー (オーバーレイ再生) を開く要求 */
+        onRequestPreview: (manual: ManualListItem) => void;
         /** 削除確認ダイアログを開く要求 */
         onRequestDelete: (manual: ManualListItem) => void;
     }
 
-    let { projectId, manual, onRequestDelete }: Props = $props();
+    let { projectId, manual, onRequestPreview, onRequestDelete }: Props = $props();
 
     const durationLabel = $derived(formatDurationMs(manual.duration_ms));
+    /** 受け取れる完成動画があるか (プレビュー / DL の唯一の出し分け根拠) */
+    const finishedRenderJobId = $derived(manual.current_finished_render_job_id);
 </script>
 
 <!-- 狭い画面では縦積み (操作群を次行へ逃がす)、sm 以上で現行と同じ横並びに戻す。
@@ -57,11 +63,23 @@
         <Badge tone={STATUS_TONES[manual.status]} testId={`manual-status-${manual.id}`}>
             {VIDEO_MANUAL_STATUS_LABELS[manual.status]}
         </Badge>
-        {#if manual.downloadable}
-            <!-- 受け取れるとサーバが判断した行にだけ出す。押せない (disabled) ボタンは作らない。
-                 出ていない行の理由は状態バッジと再生時間「—」が語り、書き出しの CTA は
-                 詳細画面 (RenderPanel) が唯一持つ。
-                 素の <a> (inertia なし) = 非 Inertia 遷移。成功時は attachment 応答のため
+        <!-- 受け取れるとサーバが判断した行にだけ出す。押せない (disabled) ボタンは作らない。
+             出ていない行の理由は状態バッジと再生時間「—」が語り、書き出しの CTA は
+             詳細画面 (RenderPanel) が唯一持つ。
+             プレビューと DL は同じ条件 (playback の完成動画条件 = download 条件) なので
+             同じ枝に置く = 2 つの条件を持たない。 -->
+        {#if finishedRenderJobId !== null}
+            <Button
+                variant="ghost"
+                size="sm"
+                onclick={() => onRequestPreview(manual)}
+                ariaLabel={`${manual.title} の完成動画をプレビュー`}
+                testId={`manual-preview-${manual.id}`}
+            >
+                <Play class="size-4" />
+                プレビュー
+            </Button>
+            <!-- 素の <a> (inertia なし) = 非 Inertia 遷移。成功時は attachment 応答のため
                  画面は遷移しない。 -->
             <Button
                 variant="ghost"
diff --git a/resources/js/components/features/manual/ManualPreviewModal.svelte b/resources/js/components/features/manual/ManualPreviewModal.svelte
new file mode 100644
index 0000000..f91d84b
--- /dev/null
+++ b/resources/js/components/features/manual/ManualPreviewModal.svelte
@@ -0,0 +1,61 @@
+<script lang="ts">
+    import Modal from "@/components/organisms/Modal.svelte";
+    import type { ManualListItem } from "@/types/manual";
+
+    /**
+     * 動画一覧からの完成動画プレビュー (doc/04 一覧ページ「プレビュー（オーバーレイ）」)。
+     *
+     * - 再生/停止/音量/全画面はブラウザ標準の `video controls` が担う (自前の再生制御は作らない)
+     * - **言語切替は持たない**。v1 は字幕のみ・`locale=ja` 固定で、切り替える対象の成果物が
+     *   1 つも無い。多言語が入る日に一覧・詳細・DL をまとめて設計する
+     * - src は**同一オリジンのアプリ route** (302 で S3 署名 URL へ飛ぶ)。署名 URL は props にも
+     *   HTML にも現れないため、認証済み画面の 3 枚セット (no-store / bfcache 秘匿 /
+     *   Inertia history 暗号化) の前提を変えない
+     * - 描画するのは**サーバが再生可と判断した行だけ** (current_finished_render_job_id が非 null)。
+     *   published も権限も UI 側で再判定しない
+     */
+    interface Props {
+        projectId: number;
+        /** 再生対象の行。null = 未選択 (open=false のときのみ) */
+        manual: ManualListItem | null;
+        /** 開閉状態 (bindable)。呼び出し側が $state で保持し bind:open する */
+        open: boolean;
+    }
+
+    let { projectId, manual, open = $bindable(false) }: Props = $props();
+
+    /**
+     * 再生 URL。**行 props の id からのみ**組み立てる (status や権限から導出しない)。
+     * 閉じている間は Modal が中身を DOM に載せないため、署名 URL の発行要求は
+     * オーバーレイを開いたときだけ起きる。
+     */
+    const playbackSrc = $derived(
+        manual === null || manual.current_finished_render_job_id === null
+            ? null
+            : `/projects/${projectId}/manuals/${manual.id}/render-jobs/` +
+              `${manual.current_finished_render_job_id}/playback`,
+    );
+</script>
+
+<Modal bind:open title={manual?.title ?? "プレビュー"} size="lg" testId="manual-preview-modal">
+    <!-- manual を条件に含めるのは型と意図を揃えるため。
+         playbackSrc が非 null なら manual も非 null だが、その含意を読み手と型検査に委ねない -->
+    {#if manual !== null && playbackSrc !== null}
+        <!-- svelte-ignore a11y_media_has_caption (完成動画の字幕は焼き込み済み) -->
+        <!-- preload="none": ブラウザに事前取得しないよう指示し、意図しない先読みを抑制する
+             (ヒントであって要求ゼロの保証ではない)。
+             RenderPanel の完成動画と同じ値に揃える = 2 通りの流儀を作らない。
+             尺は一覧行が duration_ms で既に出しているため、metadata を先読みしても
+             操作回数は減らず、得られる情報も増えない。
+             autoplay は付けない: 音声付き autoplay はブラウザポリシーで拒否される環境があり
+             「押したのに再生されない」が環境依存で生まれるため、再生開始は標準 controls に委ねる -->
+        <video
+            controls
+            preload="none"
+            class="w-full rounded-md bg-neutral"
+            src={playbackSrc}
+            aria-label={`${manual.title} の完成動画`}
+            data-testid="manual-preview-video"
+        ></video>
+    {/if}
+</Modal>
diff --git a/resources/js/pages/Projects/Show.svelte b/resources/js/pages/Projects/Show.svelte
index 267c60e..1e9220e 100644
--- a/resources/js/pages/Projects/Show.svelte
+++ b/resources/js/pages/Projects/Show.svelte
@@ -16,6 +16,7 @@
     import Pagination from "@/components/molecules/Pagination.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
     import ManualListRow from "@/components/features/manual/ManualListRow.svelte";
+    import ManualPreviewModal from "@/components/features/manual/ManualPreviewModal.svelte";
     import Modal from "@/components/organisms/Modal.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import PageContainer from "@/components/templates/PageContainer.svelte";
@@ -130,6 +131,18 @@
         });
     }
 
+    /* ---- 動画マニュアル: 行内プレビュー (オーバーレイ再生) ---- */
+    // モーダルは**ページに 1 つ**だけ持つ (行ごとに Dialog を作らない = 行内削除と同じ流儀)。
+    // 対象行を state に持つので、閉じた後も最後に開いた行が残るが、開く操作は必ず
+    // openPreviewManualDialog を通るため取り違えは起きない。
+    let previewManualTarget = $state<ManualListItem | null>(null);
+    let previewManualDialogOpen = $state(false);
+
+    function openPreviewManualDialog(manual: ManualListItem): void {
+        previewManualTarget = manual;
+        previewManualDialogOpen = true;
+    }
+
     /* ---- 動画マニュアル: 行内削除 (ConfirmDialog → destroy) ---- */
     let removeManualTarget = $state<ManualListItem | null>(null);
     let removeManualDialogOpen = $state(false);
@@ -470,6 +483,7 @@
                             <ManualListRow
                                 projectId={project.id}
                                 {manual}
+                                onRequestPreview={openPreviewManualDialog}
                                 onRequestDelete={openRemoveManualDialog}
                             />
                         {/each}
@@ -796,6 +810,14 @@
             testId="remove-item-dialog"
         />
 
+        <!-- 完成動画のオーバーレイ再生 (doc/04 一覧ページの「プレビュー」)。
+             ページに 1 つだけ置き、対象行を差し替えて使い回す -->
+        <ManualPreviewModal
+            bind:open={previewManualDialogOpen}
+            projectId={project.id}
+            manual={previewManualTarget}
+        />
+
         <ConfirmDialog
             bind:open={removeManualDialogOpen}
             title="動画マニュアル削除"
diff --git a/resources/js/types/manual.ts b/resources/js/types/manual.ts
index 5c9091b..50f9d68 100644
--- a/resources/js/types/manual.ts
+++ b/resources/js/types/manual.ts
@@ -118,12 +118,15 @@ export interface ManualListItem {
      */
     duration_ms: number | null;
     /**
-     * 完成動画を受け取れるか。サーバが「download ability × published ×
-     * 現行世代の succeeded render に output_path がある」を判定した結果そのもので、
-     * **UI 側で条件を再判定しない**。download endpoint が 302 を返す条件と 1 対 1
-     * (描画時点のスナップショットであり、ストレージ実体の存在確認ではない)。
+     * いま受け取れる完成動画 (kind=render) の render job id。**null = 受け取れない**。
+     * サーバが「download ability × published × 現行世代の succeeded render に output_path がある」を
+     * 判定した結果そのもので、**UI 側で条件を再判定しない**。
+     * 非 null は download endpoint が 302 を返す条件と 1 対 1 であり、
+     * 値は再生 endpoint `/projects/{p}/manuals/{m}/render-jobs/{id}/playback` にそのまま使う
+     * (完成動画の再生条件は download と完全同一)。
+     * 描画時点のスナップショットであり、ストレージ実体の存在確認ではない。
      */
-    downloadable: boolean;
+    current_finished_render_job_id: number | null;
     /** 削除できるか (サーバの delete ability 判定結果。撮影者は false) */
     deletable: boolean;
 }
diff --git a/tests/Architecture/CurrentRenderArtifactInventoryTest.php b/tests/Architecture/CurrentRenderArtifactInventoryTest.php
index 063f686..9b5dbe7 100644
--- a/tests/Architecture/CurrentRenderArtifactInventoryTest.php
+++ b/tests/Architecture/CurrentRenderArtifactInventoryTest.php
@@ -462,6 +462,29 @@ public static function phpFiles(string $dir): array
     // 別ファイルへ移した候補 relation は捉えない (母集団の検査は ケース 2 が担う)。
 });
 
+test('一覧行 DTO は成果物行の選択を Canonical へ委譲する', function (): void {
+    // T189: 一覧が bool ではなく render job id を運ぶようになったため、DTO 側に
+    // 「どの行か」「実体が残っているか」の規則を書き戻すと選択式が 2 箇所になる。
+    // 禁じているのは**成果物行の選択**であって、ability / published の判定は DTO に残る
+    // (Canonical が持たない責務なので当然に残る)。
+    //
+    // **保証範囲を誇張しない**: 閉じるのはこの 1 ファイルについてだけである。
+    // 別ファイルへ同義式を切り出す経路・動的呼び出し・文字列変数経由には沈黙する
+    // (fail-first は behavioral な parity テストが担う)。
+    $tokens = RenderArtifactSelectionScanner::tokensOf(
+        'DataTransferObjects/Manual/ManualListItemData.php',
+    );
+    $texts = array_column($tokens, 'text');
+
+    // toContain は追加引数を「もう 1 つの needle」として扱うため、説明文は toBeTrue/toBeFalse 側に置く
+    expect(in_array('output_path', $texts, true))->toBeFalse(
+        'ManualListItemData が output_path を参照しました (実体判定は Canonical の責務です)');
+    expect(in_array('latestSucceededRender', $texts, true))->toBeFalse(
+        'ManualListItemData が候補行 relation を直接読みました (選択は Canonical へ委譲してください)');
+    expect(in_array('CurrentRenderArtifact', $texts, true))->toBeTrue(
+        'ManualListItemData が Canonical へ委譲していません (選択式が複製されている可能性があります)');
+});
+
 test('scanner 自己検証: EagerLoadCandidate の前提検査 (output_path / 個数 / 宣言名)', function (): void {
     $propertyAccess = PhpTokenScan::normalize('<?php $p = $job->output_path;');
     $literal = PhpTokenScan::normalize("<?php \$q->whereNotNull('output_path');");
diff --git a/tests/Feature/Manual/ManualRowDownloadableParityTest.php b/tests/Feature/Manual/ManualRowFinishedVideoParityTest.php
similarity index 58%
rename from tests/Feature/Manual/ManualRowDownloadableParityTest.php
rename to tests/Feature/Manual/ManualRowFinishedVideoParityTest.php
index e59d4a3..0decc76 100644
--- a/tests/Feature/Manual/ManualRowDownloadableParityTest.php
+++ b/tests/Feature/Manual/ManualRowFinishedVideoParityTest.php
@@ -4,6 +4,7 @@
 
 use App\Enums\Manual\RenderKind;
 use App\Enums\Manual\VideoManualStatus;
+use App\Enums\ProjectRole;
 use App\Models\Organization;
 use App\Models\Project;
 use App\Models\RenderJob;
@@ -14,12 +15,13 @@
 use Inertia\Testing\AssertableInertia;
 
 /*
- * T182: 一覧の候補行 relation (VideoManual::latestSucceededRender) と
+ * T182 + T189: 一覧の候補行 relation (VideoManual::latestSucceededRender) と
  * 受け取り対象の選択式 (CurrentRenderArtifact::currentSucceeded) の**世代定義が一致**すること、
- * および一覧の downloadable と download endpoint (302 / 404) の判断が一致すること。
+ * および一覧の current_finished_render_job_id と受け取り口 2 本
+ * (download の 302/404 / playback の 302/404/403) の判断が一致すること。
  *
  * 両者の違いは 1 点だけである: relation は output_path を見ない (候補行を返す) ので、
- * 「受け取れるか」は呼び出し側が output_path を足して判断する。
+ * 「受け取れるか」の決定は選択式 (CurrentRenderArtifact) が持つ。
  */
 
 /**
@@ -39,7 +41,7 @@ function parityFixture(): array
     return [$organization, $owner, Project::factory()->forOrganization($organization)->create()];
 }
 
-test('succeeded が 2 世代あるとき両者とも最新の行を指す', function (): void {
+test('succeeded が 2 世代あるとき両者とも最新の行を指し、一覧の id で再生できる', function (): void {
     [, $owner, $project] = parityFixture();
     $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
     RenderJob::factory()->forManual($manual)->succeeded('renders/old.mp4')->create();
@@ -50,13 +52,34 @@ function parityFixture(): array
     expect($manual->latestSucceededRender?->id)->toBe($newest->id);
     expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)?->id)->toBe($newest->id);
 
-    $this->actingAs($owner)->get("/projects/{$project->id}")
-        ->assertInertia(fn (AssertableInertia $page) => $page->where('manuals.data.0.downloadable', true));
+    $rows = $this->actingAs($owner)->get("/projects/{$project->id}")
+        ->inertiaPage()['props']['manuals']['data'];
+    // 一覧が返す id = 受け取り口が受け付ける id (props と endpoint の非対称を作らない)
+    expect($rows[0]['current_finished_render_job_id'])->toBe($newest->id);
+
+    $this->actingAs($owner)
+        ->get("/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$newest->id}/playback")
+        ->assertRedirect();
     $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
         ->assertRedirect();
 });
 
-test('最新 succeeded の output_path が null なら旧世代へフォールバックしない (一覧 false / endpoint 404)', function (): void {
+test('旧世代の render job id を直叩きすると playback は 404 (一覧はその id を返さない)', function (): void {
+    [, $owner, $project] = parityFixture();
+    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
+    $old = RenderJob::factory()->forManual($manual)->succeeded('renders/old.mp4')->create();
+    $newest = RenderJob::factory()->forManual($manual)->succeeded('renders/new.mp4')->create();
+
+    $rows = $this->actingAs($owner)->get("/projects/{$project->id}")
+        ->inertiaPage()['props']['manuals']['data'];
+    expect($rows[0]['current_finished_render_job_id'])->toBe($newest->id);
+
+    $this->actingAs($owner)
+        ->get("/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$old->id}/playback")
+        ->assertNotFound();
+});
+
+test('最新 succeeded の output_path が null なら旧世代へフォールバックしない (一覧 null / endpoint 404)', function (): void {
     [, $owner, $project] = parityFixture();
     $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
     RenderJob::factory()->forManual($manual)->succeeded('renders/old.mp4')->create();
@@ -70,7 +93,8 @@ function parityFixture(): array
     expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render))->toBeNull();
 
     $this->actingAs($owner)->get("/projects/{$project->id}")
-        ->assertInertia(fn (AssertableInertia $page) => $page->where('manuals.data.0.downloadable', false));
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->where('manuals.data.0.current_finished_render_job_id', null));
     $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
         ->assertNotFound();
 });
@@ -86,7 +110,8 @@ function parityFixture(): array
     expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render))->toBeNull();
 
     $this->actingAs($owner)->get("/projects/{$project->id}")
-        ->assertInertia(fn (AssertableInertia $page) => $page->where('manuals.data.0.downloadable', false));
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->where('manuals.data.0.current_finished_render_job_id', null));
     $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
         ->assertNotFound();
 });
@@ -103,12 +128,13 @@ function parityFixture(): array
     expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render))->toBeNull();
 
     $this->actingAs($owner)->get("/projects/{$project->id}")
-        ->assertInertia(fn (AssertableInertia $page) => $page->where('manuals.data.0.downloadable', false));
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->where('manuals.data.0.current_finished_render_job_id', null));
     $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
         ->assertNotFound();
 });
 
-test('published でない行は succeeded render があっても一覧 false / endpoint 404 (公開状態の一致)', function (): void {
+test('published でない行は succeeded render があっても一覧 null / endpoint 404 (公開状態の一致)', function (): void {
     [, $owner, $project] = parityFixture();
     $manual = VideoManual::factory()->forProject($project)->create([
         'status' => VideoManualStatus::Ready->value,
@@ -125,8 +151,33 @@ function parityFixture(): array
 
     $this->actingAs($owner)->get("/projects/{$project->id}")
         ->assertInertia(fn (AssertableInertia $page) => $page
-            ->where('manuals.data.0.downloadable', false)
+            ->where('manuals.data.0.current_finished_render_job_id', null)
             ->where('manuals.data.0.duration_ms', null));
     $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
         ->assertNotFound();
 });
+
+test('撮影者は一覧 id が null で playback も 403 (props と endpoint が同じ結論を出す)', function (): void {
+    // **権限だけで結論が決まるデータ**にする。published + 現行世代 succeeded + output_path あり =
+    // 編集者なら 302 になる状態を用意し、撮影者だと 403 になることを見る
+    // (404 と混ざらない = 層 2 の 404 ではないことが確定する)。
+    [$organization, $owner, $project] = parityFixture();
+    $shooter = attachOrganizationMember($organization);
+    $shooter->forceFill(['current_organization_id' => $organization->id])->save();
+    attachProjectMember($project, $shooter, ProjectRole::Member);
+    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
+    $job = RenderJob::factory()->forManual($manual)->succeeded('renders/ok.mp4')->create();
+
+    // 編集者は 302 (データ側は「受け取れる」状態であることの対照)
+    $this->actingAs($owner)
+        ->get("/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback")
+        ->assertRedirect();
+
+    $rows = $this->actingAs($shooter)->get("/projects/{$project->id}")
+        ->inertiaPage()['props']['manuals']['data'];
+    expect($rows[0]['current_finished_render_job_id'])->toBeNull();
+
+    $this->actingAs($shooter)
+        ->get("/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback")
+        ->assertForbidden();
+});
diff --git a/tests/Feature/Projects/ProjectShowManualsTest.php b/tests/Feature/Projects/ProjectShowManualsTest.php
index 448fd6e..083e1d4 100644
--- a/tests/Feature/Projects/ProjectShowManualsTest.php
+++ b/tests/Feature/Projects/ProjectShowManualsTest.php
@@ -275,8 +275,8 @@
 });
 
 /*
- * T182: 行の再生時間 (duration_ms) と行内操作の可否 (downloadable / deletable)、
- * 範囲外ページの丸め、q の 200 文字上限。
+ * T182 + T189: 行の再生時間 (duration_ms) と行内操作の可否
+ * (current_finished_render_job_id / deletable)、範囲外ページの丸め、q の 200 文字上限。
  */
 
 test('duration_ms は published の総尺のみ供給する (それ以外は null)', function (): void {
@@ -303,12 +303,12 @@
     expect($byId[$ready->id]['duration_ms'])->toBeNull();
 });
 
-test('downloadable は published × 現行世代の succeeded render (output_path あり) のときだけ true', function (): void {
+test('current_finished_render_job_id は published × 現行世代の succeeded render (kind=render / output_path あり) のときだけ id を返す', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     $project = Project::factory()->forOrganization($organization)->create();
 
     $ok = VideoManual::factory()->forProject($project)->published(60_000)->create(['title' => '受取可']);
-    RenderJob::factory()->forManual($ok)->succeeded('renders/ok.mp4')->create();
+    $okJob = RenderJob::factory()->forManual($ok)->succeeded('renders/ok.mp4')->create();
 
     // 最新 succeeded の実体が消えている (掃除済み) → 旧世代へフォールバックしない
     $stale = VideoManual::factory()->forProject($project)->published(60_000)->create(['title' => '実体なし']);
@@ -330,29 +330,41 @@
         ->inertiaPage()['props']['manuals']['data'];
     $byId = array_column($rows, null, 'id');
 
-    expect($byId[$ok->id]['downloadable'])->toBeTrue();
-    expect($byId[$stale->id]['downloadable'])->toBeFalse();
-    expect($byId[$previewOnly->id]['downloadable'])->toBeFalse();
-    expect($byId[$notPublished->id]['downloadable'])->toBeFalse();
+    expect($byId[$ok->id]['current_finished_render_job_id'])->toBe($okJob->id);
+    expect($byId[$stale->id]['current_finished_render_job_id'])->toBeNull();
+    expect($byId[$previewOnly->id]['current_finished_render_job_id'])->toBeNull();
+    expect($byId[$notPublished->id]['current_finished_render_job_id'])->toBeNull();
 });
 
-test('撮影者は downloadable / deletable ともに false、編集者は deletable=true', function (): void {
+test('一覧の行 props に旧キー downloadable が残っていない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    VideoManual::factory()->forProject($project)->published(60_000)->create();
+
+    $rows = $this->actingAs($owner)->get("/projects/{$project->id}")
+        ->inertiaPage()['props']['manuals']['data'];
+
+    expect($rows[0])->toHaveKey('current_finished_render_job_id');
+    expect(array_key_exists('downloadable', $rows[0]))->toBeFalse();
+});
+
+test('撮影者は current_finished_render_job_id=null / deletable=false、編集者は id と deletable=true', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     $member = attachOrganizationMember($organization);
     $member->forceFill(['current_organization_id' => $organization->id])->save();
     $project = Project::factory()->forOrganization($organization)->create();
     attachProjectMember($project, $member, ProjectRole::Member);
     $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
-    RenderJob::factory()->forManual($manual)->succeeded('renders/ok.mp4')->create();
+    $job = RenderJob::factory()->forManual($manual)->succeeded('renders/ok.mp4')->create();
 
     $this->actingAs($member)->get("/projects/{$project->id}")
         ->assertInertia(fn (Assert $page) => $page
-            ->where('manuals.data.0.downloadable', false)
+            ->where('manuals.data.0.current_finished_render_job_id', null)
             ->where('manuals.data.0.deletable', false));
 
     $this->actingAs($owner)->get("/projects/{$project->id}")
         ->assertInertia(fn (Assert $page) => $page
-            ->where('manuals.data.0.downloadable', true)
+            ->where('manuals.data.0.current_finished_render_job_id', $job->id)
             ->where('manuals.data.0.deletable', true));
 });
 
diff --git a/tests/Unit/Manual/CurrentRenderArtifactLoadedCandidateTest.php b/tests/Unit/Manual/CurrentRenderArtifactLoadedCandidateTest.php
new file mode 100644
index 0000000..c5a4fe8
--- /dev/null
+++ b/tests/Unit/Manual/CurrentRenderArtifactLoadedCandidateTest.php
@@ -0,0 +1,65 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\RenderJob;
+use App\Models\VideoManual;
+use App\Services\Manual\CurrentRenderArtifact;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * T189: 一覧向けの入口 CurrentRenderArtifact::fromLoadedRenderCandidate() の契約。
+ *
+ * currentSucceeded() と**同じ規則** (最新 succeeded の output_path が NULL なら
+ * 旧世代へフォールバックしない) を、**eager load 済みの候補行**に対して適用する。
+ * 一覧は行数に比例したクエリを撃たないため、この入口は追加クエリを 1 本も出さず、
+ * 未ロードで呼ばれたら黙って lazy load せずに落ちる。
+ */
+
+test('eager load 済みの候補行を返し、追加クエリを 1 本も撃たない', function (): void {
+    $manual = VideoManual::factory()->create();
+    $newest = RenderJob::factory()->forManual($manual)->succeeded('renders/new.mp4')->create();
+
+    // 観測区間は fromLoadedRenderCandidate() の呼び出しだけにする
+    // (fixture 生成と load はカウンタ開始前に終わらせる)
+    $manual->load('latestSucceededRender');
+
+    DB::enableQueryLog();
+    DB::flushQueryLog();
+    $selected = CurrentRenderArtifact::fromLoadedRenderCandidate($manual);
+    $log = DB::getQueryLog();
+    DB::disableQueryLog();
+
+    expect($selected?->id)->toBe($newest->id);
+    expect($log)->toBe([], '一覧向けの入口が追加クエリを撃ちました (行数に比例した N+1 になります)');
+});
+
+test('候補行の output_path が NULL なら null を返す (旧世代へフォールバックしない)', function (): void {
+    $manual = VideoManual::factory()->create();
+    RenderJob::factory()->forManual($manual)->succeeded('renders/old.mp4')->create();
+    $stale = RenderJob::factory()->forManual($manual)->succeeded('renders/new.mp4')
+        ->state(fn (): array => ['output_path' => null])->create();
+
+    $manual->load('latestSucceededRender');
+
+    // 候補行 relation は output_path を見ないので stale 行を返す (判断は選択式が持つ)
+    expect($manual->latestSucceededRender?->id)->toBe($stale->id);
+    expect(CurrentRenderArtifact::fromLoadedRenderCandidate($manual))->toBeNull();
+});
+
+test('候補行が無い manual では null を返す', function (): void {
+    $manual = VideoManual::factory()->create();
+    $manual->load('latestSucceededRender');
+
+    expect(CurrentRenderArtifact::fromLoadedRenderCandidate($manual))->toBeNull();
+});
+
+test('未ロードの manual を渡すと InvalidArgumentException になる (黙って lazy load しない)', function (): void {
+    $manual = VideoManual::factory()->create();
+    RenderJob::factory()->forManual($manual)->succeeded('renders/new.mp4')->create();
+
+    // relation を load していない = 一覧の eager load が外れた状態
+    expect($manual->relationLoaded('latestSucceededRender'))->toBeFalse();
+
+    CurrentRenderArtifact::fromLoadedRenderCandidate($manual);
+})->throws(InvalidArgumentException::class);
diff --git a/tests/js/components/features/manual/ManualListRow.test.ts b/tests/js/components/features/manual/ManualListRow.test.ts
index f7789ee..d7f69e1 100644
--- a/tests/js/components/features/manual/ManualListRow.test.ts
+++ b/tests/js/components/features/manual/ManualListRow.test.ts
@@ -8,10 +8,11 @@ import type { ManualListItem } from "@/types/manual";
  *
  * 固定する契約:
  * - 再生時間はサーバの duration_ms をそのまま整形する (未確定は「—」)
- * - DL / 削除の導線はサーバが決めた downloadable / deletable **だけ**で出し分ける
- *   (UI 側で published や権限を再判定しない)
+ * - プレビュー / DL / 削除の導線はサーバが決めた current_finished_render_job_id / deletable
+ *   **だけ**で出し分ける (UI 側で published や権限を再判定しない)
+ * - プレビューと DL は同じ props 1 本で出し分ける (再生条件は download と完全同一 = T154)
  * - DL は**通常 anchor** (非 Inertia 遷移)。Inertia の Link へ退行したら赤くなる
- * - どちらの導線も disabled を持たない (禁止事項 8 の回帰封じ)
+ * - どの導線も disabled を持たない (禁止事項 8 の回帰封じ)
  *
  * Inertia の `Link` は素の <a href> として描画され判別できる属性を持たないため、
  * 既存のスタブ (tests/js/support/InertiaLinkStub.svelte) へ差し替えて
@@ -31,18 +32,22 @@ function manualItem(overrides: Partial<ManualListItem> = {}): ManualListItem {
         created_at: "2026-07-10 12:00",
         updated_at: "2026-07-11 09:00",
         duration_ms: 185_000,
-        downloadable: true,
+        current_finished_render_job_id: 9,
         deletable: true,
         ...overrides,
     };
 }
 
-function renderRow(overrides: Partial<ManualListItem> = {}, onRequestDelete = vi.fn()) {
+function renderRow(
+    overrides: Partial<ManualListItem> = {},
+    onRequestPreview = vi.fn(),
+    onRequestDelete = vi.fn(),
+) {
     render(ManualListRow, {
-        props: { projectId: 7, manual: manualItem(overrides), onRequestDelete },
+        props: { projectId: 7, manual: manualItem(overrides), onRequestPreview, onRequestDelete },
     });
 
-    return onRequestDelete;
+    return { onRequestPreview, onRequestDelete };
 }
 
 describe("features/manual/ManualListRow", () => {
@@ -60,7 +65,7 @@ describe("features/manual/ManualListRow", () => {
         expect(screen.getByTestId("manual-duration-1")).toHaveTextContent("—");
     });
 
-    it("downloadable=true のとき DL リンクを download endpoint へ出す", () => {
+    it("受け取れる行では DL リンクを download endpoint へ出す", () => {
         renderRow();
 
         const link = screen.getByTestId("manual-download-1");
@@ -78,14 +83,24 @@ describe("features/manual/ManualListRow", () => {
         expect(screen.getAllByTestId("inertia-link-stub")).toHaveLength(1);
     });
 
-    it("downloadable=false のとき DL リンクを出さない (押せないボタンを置かない)", () => {
-        renderRow({ downloadable: false });
+    it("current_finished_render_job_id=null のときプレビュー / DL のどちらも出さない (押せないボタンを置かない)", () => {
+        renderRow({ current_finished_render_job_id: null });
 
+        expect(screen.queryByTestId("manual-preview-1")).toBeNull();
         expect(screen.queryByTestId("manual-download-1")).toBeNull();
     });
 
+    it("受け取れる行ではプレビューを押すとその行で onRequestPreview が呼ばれる", async () => {
+        const { onRequestPreview } = renderRow();
+
+        await fireEvent.click(screen.getByTestId("manual-preview-1"));
+
+        expect(onRequestPreview).toHaveBeenCalledTimes(1);
+        expect(onRequestPreview.mock.calls[0][0]).toMatchObject({ id: 1, title: "ネジ締め作業" });
+    });
+
     it("deletable=true のとき削除ボタンを出し、押すとその行で onRequestDelete が呼ばれる", async () => {
-        const onRequestDelete = renderRow();
+        const { onRequestDelete } = renderRow();
 
         await fireEvent.click(screen.getByTestId("manual-remove-1"));
 
@@ -99,11 +114,13 @@ describe("features/manual/ManualListRow", () => {
         expect(screen.queryByTestId("manual-remove-1")).toBeNull();
     });
 
-    it("DL / 削除のいずれも disabled を持たない (禁止事項 8)", () => {
-        renderRow();
+    it("プレビュー / DL / 削除のどれも disabled を持たない (禁止事項 8)", () => {
+        // fixture の既定に依存させない。「3 つとも出ている状態」をこのテスト自身が宣言する
+        renderRow({ current_finished_render_job_id: 9, deletable: true });
 
-        expect(screen.getByTestId("manual-download-1")).not.toBeDisabled();
-        expect(screen.getByTestId("manual-remove-1")).not.toBeDisabled();
+        for (const testId of ["manual-preview-1", "manual-download-1", "manual-remove-1"]) {
+            expect(screen.getByTestId(testId)).not.toBeDisabled();
+        }
     });
 
     it("長いタイトルでも省略スタイルが当たっている (jsdom は実寸を計算しないためスタイル契約まで)", () => {
diff --git a/tests/js/components/features/manual/ManualPreviewModal.test.ts b/tests/js/components/features/manual/ManualPreviewModal.test.ts
new file mode 100644
index 0000000..438b3c6
--- /dev/null
+++ b/tests/js/components/features/manual/ManualPreviewModal.test.ts
@@ -0,0 +1,73 @@
+import { describe, expect, it } from "vitest";
+import { render, screen } from "@testing-library/svelte";
+import ManualPreviewModal from "@/components/features/manual/ManualPreviewModal.svelte";
+import type { ManualListItem } from "@/types/manual";
+
+/*
+ * 動画一覧からの完成動画プレビュー (T189)。
+ *
+ * 固定する契約:
+ * - src は行 props の id から組み立てた playback endpoint (同一オリジンのアプリ route)
+ * - 再生制御はブラウザ標準の controls に委ねる (自前の再生 UI を持たない)
+ * - preload="none" の**指定が付いている**こと (HTTP 要求が 0 件であることの証明ではない)
+ * - 閉じている間 / サーバが不可と判断した行では <video> を描画しない
+ */
+
+function item(overrides: Partial<ManualListItem> = {}): ManualListItem {
+    return {
+        id: 2,
+        title: "洗浄手順",
+        status: "published",
+        category: null,
+        creator: null,
+        created_at: "2026-07-10 13:00",
+        updated_at: "2026-07-11 10:00",
+        duration_ms: 185_000,
+        current_finished_render_job_id: 9,
+        deletable: true,
+        ...overrides,
+    };
+}
+
+describe("features/manual/ManualPreviewModal", () => {
+    it("開いているとき playback endpoint を src に持つ video を描画する", async () => {
+        render(ManualPreviewModal, { props: { projectId: 7, manual: item(), open: true } });
+
+        const video = await screen.findByTestId("manual-preview-video");
+        expect(video.getAttribute("src")).toBe("/projects/7/manuals/2/render-jobs/9/playback");
+        expect(video).toHaveAttribute("controls");
+    });
+
+    it("video に preload=none の指定が付いている (先読みを抑制する指示)", async () => {
+        render(ManualPreviewModal, { props: { projectId: 7, manual: item(), open: true } });
+
+        const video = await screen.findByTestId("manual-preview-video");
+        expect(video).toHaveAttribute("preload", "none");
+    });
+
+    it("見出しに対象行のタイトルが出る", async () => {
+        render(ManualPreviewModal, { props: { projectId: 7, manual: item(), open: true } });
+
+        const modal = await screen.findByTestId("manual-preview-modal");
+        expect(modal).toHaveTextContent("洗浄手順");
+    });
+
+    it("閉じているとき video を描画しない (署名 URL 要求を出さない)", () => {
+        render(ManualPreviewModal, { props: { projectId: 7, manual: item(), open: false } });
+
+        expect(screen.queryByTestId("manual-preview-video")).toBeNull();
+    });
+
+    it("受け取れない行 (id=null) では video を描画しない", async () => {
+        render(ManualPreviewModal, {
+            props: {
+                projectId: 7,
+                manual: item({ current_finished_render_job_id: null }),
+                open: true,
+            },
+        });
+
+        await screen.findByTestId("manual-preview-modal");
+        expect(screen.queryByTestId("manual-preview-video")).toBeNull();
+    });
+});
diff --git a/tests/js/pages/ProjectsShow.test.ts b/tests/js/pages/ProjectsShow.test.ts
index f742d95..989fefb 100644
--- a/tests/js/pages/ProjectsShow.test.ts
+++ b/tests/js/pages/ProjectsShow.test.ts
@@ -23,7 +23,7 @@ const manualsFixture: ManualListItem[] = [
         created_at: "2026-07-10 12:00",
         updated_at: "2026-07-11 09:00",
         duration_ms: null,
-        downloadable: false,
+        current_finished_render_job_id: null,
         deletable: true,
     },
     {
@@ -35,7 +35,7 @@ const manualsFixture: ManualListItem[] = [
         created_at: "2026-07-10 13:00",
         updated_at: "2026-07-11 10:00",
         duration_ms: 185_000,
-        downloadable: true,
+        current_finished_render_job_id: 9,
         deletable: true,
     },
 ];
@@ -300,19 +300,30 @@ describe("Projects/Show 動画マニュアルの行内操作", () => {
         vi.restoreAllMocks();
     });
 
-    it("行の再生時間と DL 導線をサーバの props どおりに出し分ける", () => {
+    it("行の再生時間とプレビュー / DL 導線をサーバの props どおりに出し分ける", () => {
         render(Show, { props: baseProps });
 
         // duration_ms=null の行は「—」、値のある行は整形して出す
         expect(screen.getByTestId("manual-duration-1")).toHaveTextContent("—");
         expect(screen.getByTestId("manual-duration-2")).toHaveTextContent("3:05");
-        // downloadable の行にだけ DL 導線が出る
+        // 受け取れる行にだけプレビュー / DL 導線が出る
+        expect(screen.queryByTestId("manual-preview-1")).toBeNull();
         expect(screen.queryByTestId("manual-download-1")).toBeNull();
+        expect(screen.getByTestId("manual-preview-2")).toBeInTheDocument();
         expect(screen.getByTestId("manual-download-2").getAttribute("href")).toMatch(
             /\/projects\/1\/manuals\/2\/download$/,
         );
     });
 
+    it("プレビューを押すとオーバーレイが開き、その行の playback URL の video が出る", async () => {
+        render(Show, { props: baseProps });
+
+        await fireEvent.click(screen.getByTestId("manual-preview-2"));
+
+        const video = await screen.findByTestId("manual-preview-video");
+        expect(video.getAttribute("src")).toBe("/projects/1/manuals/2/render-jobs/9/playback");
+    });
+
     it("deletable=false の行には削除導線を出さない", () => {
         render(Show, {
             props: {
```

---

## design system 参照 (diff が resources/js を含むため)

### 触れた atomic ディレクトリ

- `resources/js/components/atoms/Button.svelte` (既存・変更なし。`variant="ghost" / size="sm" / ariaLabel / testId / href / onclick` を持つ)
- `resources/js/components/organisms/Modal.svelte` (既存・変更なし。bits-ui `Dialog` ベース。`open` は bindable、`size: "sm"|"md"|"lg"` → `max-w-md|max-w-lg|max-w-2xl`、`title` / `testId` / `children` snippet。閉じると中身を DOM から外す)
- `resources/js/components/features/manual/ManualListRow.svelte` (変更)
- `resources/js/components/features/manual/ManualPreviewModal.svelte` (新規。features 層から organisms/Modal を import)
- `resources/js/pages/Projects/Show.svelte` (変更。pages から features を import)

### 参考: 既存の完成動画描画 (`features/manual/RenderPanel.svelte`。今回変更していない)

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

### DESIGN.md の関連 token (抜粋)

- 色は semantic token のみ: `bg-surface` / `bg-neutral` / `text-text` / `text-text-secondary` / `border-border` / `bg-primary` 等。hex 直書き禁止。
- 角丸: カード・モーダルは `rounded-lg`、その他の面は `rounded-md`、小要素は `rounded-sm`。
- typography: `text-h3` / `text-body` / `text-caption` 等の ramp のみ。
- 押せないボタン (disabled) を必須条件未充足の理由で作らない (禁止事項 8)。

---

## テスト結果

すべて green (worktree `.claude/worktrees/tasks/T189` で実行):

- `composer test`: 5424 tests / **5422 passed** / 2 skipped / 0 failed (assertions 23371)
- `composer phpstan`: level 10 **No errors** (964 files)
- `vendor/bin/pint --test`: passed
- `pnpm lint`: passed (eslint)
- `pnpm typecheck`: passed (tsc --noEmit)
- `pnpm test`: 150 files / **1801 passed** / 0 failed (vitest)
- `pnpm build`: 成功
- `pnpm typecheck:packages` / `pnpm build:packages`: 成功
- `pnpm test:packages`: 10 files / 106 passed

新規・更新テストの内訳:

- `tests/Unit/Manual/CurrentRenderArtifactLoadedCandidateTest.php` (新規 4 ケース): eager load 済み候補行から選ぶ / 追加クエリ 0 本 / output_path=NULL なら null / 未ロードで `InvalidArgumentException`
- `tests/Feature/Manual/ManualRowFinishedVideoParityTest.php` (rename + 拡張 7 ケース): 一覧 id と playback / download の 302・404・403 の一致、旧世代 id 直叩きは 404、撮影者は一覧 null かつ playback 403
- `tests/Feature/Projects/ProjectShowManualsTest.php` (更新 + 新規 1 ケース): 4 状態の id / null、旧キー `downloadable` の不在、撮影者 / 編集者の差
- `tests/Architecture/CurrentRenderArtifactInventoryTest.php` (追加 1 ケース): 一覧行 DTO が `output_path` / `latestSucceededRender` を持たず `CurrentRenderArtifact` へ委譲していること
- `tests/js/components/features/manual/ManualPreviewModal.test.ts` (新規 5 ケース) / `ManualListRow.test.ts` (更新) / `tests/js/pages/ProjectsShow.test.ts` (更新 + 新規 1 ケース)

## 補足 (レビュー時の前提)

- `RenderArtifactSelectionInventory` は T154 の deny-by-default 目録。`CurrentRenderArtifact.php` は区分 `Canonical` で登録済みで、同ファイルへのメソッド追加は登録の変更を要さない (走査母集団はファイル粒度)。
- `Webmozart\Assert\Assert::true()` は `InvalidArgumentException` を投げる。
- 一覧のクエリ数が行数に比例しないことは既存の `ManualListQueryCountTest` が固定しており、本差分でも緑のままである。
