# 詳細設計レビュー Round 2 (修正版の再レビュー依頼)

Round 1 のご指摘への対応マトリクスと、修正後の詳細設計書全文を送ります。
判定 (APPROVE / REQUEST_CHANGES / 全体判定) をお願いします。

## 対応マトリクス

# 対応マトリクス: design-review Round 1

Codex 全体判定: **CHANGES_REQUESTED** (Critical 2 / Warning 6 / Suggestion 2)。

## [Critical] 施策 1: DTO が `latestSucceededRender` + `output_path` で id を決めるのは T154 違反
- 判断: **対応する**
- 根拠: 指摘が正しい。bool を返す間は「受け取れるかの副次判定」に見えたが、**id を返す瞬間に
  「どの行か」を選んでいる**。`RenderArtifactSelectionKind::EagerLoadCandidate` の docblock 自身が
  「決定は Canonical に残る」と書いており、実装をその明言へ合わせる変更でもある。
- 対応内容: `CurrentRenderArtifact` に**一覧向けの入口**
  `fromLoadedRenderCandidate(VideoManual): ?RenderJob` を追加し、
  「実体が残っている行だけを返す」規則を private `receivable()` に 1 本化した
  (`currentSucceeded()` も同じ helper を通す = 規則が 2 度書かれない)。
  DTO からは `output_path` / `latestSucceededRender` の参照が消え、残るのは Canonical が
  持たない責務 (published 判定 / ability 判定) だけになった。
  クエリは撃たない (eager load 済み relation を読むだけ) ので一覧のクエリ本数は不変。
  T154 目録の登録変更は不要 (Canonical は登録済み / DTO は母集団に入らない) ことも設計に明記した。

## [Critical] 施策 5: 「選択が Canonical 経由であること」を固定するテストが無い
- 判断: **対応する**
- 根拠: parity だけでは複製しても緑になる、というのは事実。ただし新しい目録を作るのは過剰なので、
  既存の T154 Architecture テストにケースを 1 本足す形にする。
- 対応内容: `tests/Architecture/CurrentRenderArtifactInventoryTest.php` に
  「一覧行 DTO は受け取り可否の規則を自前で持たず Canonical へ委譲する」を追加
  (`ManualListItemData.php` の token に `output_path` / `latestSucceededRender` が現れず、
  `CurrentRenderArtifact` が現れることを既存の `PhpTokenScan::normalize()` で検査)。
  **保証範囲を誇張しない**注記 (このファイル 1 本にしか効かない) も設計へ書いた。

## [Warning] 施策 1: `current_finished_render_job_id` は kind=render 専用であることが伝わりにくい
- 判断: **対応する**
- 根拠: 一覧から preview を返さないことは重要な契約で、テスト名に出しておく価値がある。
- 対応内容: Feature テスト名に kind=render を明示し、「preview の succeeded しか無い行は null」の
  ケースを維持することをテスト計画に明記した。加えて
  `fromLoadedRenderCandidate()` は **kind 引数を取らない** 設計にして
  (候補 relation が kind=render 固定)、「一覧から preview を選べる」誤読を型で消した。

## [Warning] 施策 2: `preload="metadata"` は開いた時点で playback へ GET する
- 判断: **対応する (仕様として引き受け、契約化する)**
- 根拠: 発行の契機はユーザーが「プレビュー」を押した 1 回だけで、一覧描画では 0 件。
  `preload="none"` にすると「プレビューを押したのに黒い箱が出てもう一度再生を押す」二度手間になり、
  導線の名前 (プレビュー) と挙動が食い違う。`RenderPanel` が `none` なのは
  「画面を開くたびに自動で走る」のを避けるためで前提が違う。
- 対応内容: 設計に「`preload="metadata"` の契約」節を追加し、Vitest で
  `preload="metadata"` を固定するケースを計画に足した。

## [Warning] 施策 3: 別経路から `onRequestPreview` が呼ばれたときモーダルが null id を握り潰すだけ
- 判断: **対応する (計画済みテストの必須化)**
- 根拠: 指摘どおり現状のままでよい。守りは「モーダル側でも id が null なら video を描画しない」。
- 対応内容: 該当 Vitest ケースを必須として明記 (既に計画済み。文言を「必須」に更新)。

## [Warning] 施策 4: 閉じた後も `previewManualTarget` を保持する
- 判断: **一部対応 (テスト維持で受ける。reset handler は見送る)**
- 根拠: 描画は `open === true` の間だけで、開く操作は必ず対象を入れ替える。
  部分再読込後に古い行が残っても、最終判断は endpoint (旧世代 404 / 権限喪失 403) であり
  props は秘匿境界ではない。reset 配線は消せるリスクが無い分の追加。
- 対応内容: 設計に「閉じた後に対象行を保持することについて」節を追加して根拠を残し、
  「open=false では video が DOM から消える」Vitest ケースを維持する。

## [Warning] 施策 5: 撮影者ケースは 403 と 404 が混ざらないデータにすべき
- 判断: **対応する**
- 根拠: playback は「nested 404 → authorize 403 → published/現行世代 404」の順なので、
  データが不備だと 403 を見たつもりで 404 を見る。
- 対応内容: 撮影者ケースを「published + 現行世代 succeeded + output_path あり
  (= 編集者なら 302 になる状態)」で組み、編集者の 302 を対照として同じテストに置いた。

## [Warning] 施策 6: disabled 禁止テストが fixture 既定に依存する
- 判断: **対応する**
- 対応内容: 当該ケースで `current_finished_render_job_id: 9, deletable: true` を明示するようにした。

## [Suggestion] 施策 2: `{#if manual !== null && playbackSrc !== null}` にして型と意図を揃える
- 判断: **対応する**
- 対応内容: 条件を 2 つに分け、`aria-label` を `manual.title` (optional chain 無し) にした。

## [Suggestion] 施策 3: 狭幅の見た目は Playwright で 1 ケース見ると安心
- 判断: **見送る (根拠を設計に残す)**
- 根拠: Browser レーンは Chromium + WebKit の 2 レーン契約で、ログアウト復元など**恒久回帰**の
  検証に使っている。スクリーンショット比較の基盤は持っておらず、「窮屈さ」を機械判定できない。
  ボタン 1 つの追加のためにレーンを増やす費用は見合わない (思考原則: 今必要なものだけ作る)。
  縦積みへ逃がすレイアウト規則は T182 で導入済みで、追加ボタンはその内側に収まる。
- 対応内容: 施策 3 のリスク節にこの判断と根拠を明記した。

## 追加で自発的に直した点 (レビュー指摘外)
- rename (`ManualRowDownloadableParityTest` → `ManualRowFinishedVideoParityTest`) に伴い、
  **追跡下のコード 2 箇所**の参照を更新する必要があることを波及変更へ追加した:
  `app/Models/VideoManual.php` の docblock と
  `app/Support/Security/RenderArtifactSelectionInventory.php` の `EagerLoadCandidate` 根拠文。
  `docs/TODO-closed.md` の T182 行は**過去の記録なので書き換えない**ことも明記した。


---

## 修正後の詳細設計書 (全文)

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
| 5 | テスト (Feature + Architecture) | `tests/Feature/Projects/ProjectShowManualsTest.php` / `tests/Feature/Manual/ManualRowDownloadableParityTest.php` → `ManualRowFinishedVideoParityTest.php` (rename + 拡張) / `tests/Architecture/CurrentRenderArtifactInventoryTest.php` (選択の所在を固定するケース追加) / rename に伴う参照更新 `app/Models/VideoManual.php` docblock・`app/Support/Security/RenderArtifactSelectionInventory.php` の根拠文 | 高 |
| 6 | テスト (Vitest) | `tests/js/components/features/manual/ManualListRow.test.ts` / `ManualPreviewModal.test.ts` (新規) / `tests/js/pages/ProjectsShow.test.ts` | 高 |

**新設しないもの**: route / Controller / Service / Job / migration / config キー。
サーバ側の変更は **DTO 1 ファイルの値の作り方だけ**である (判定式は現行のまま)。

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
- `resources/js/types/manual.ts` L119-127 (`ManualListItem`)

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
     * (行数に比例した権限/クエリを増やさないという一覧の前提を守るため)。
     * 候補 relation が kind=render 固定なので kind 引数は取らない
     * (取れるように見せると「一覧から preview を選べる」という誤読を生む)。
     */
    public static function fromLoadedRenderCandidate(VideoManual $manual): ?RenderJob
    {
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
            aria-label={`${manual.title} の完成動画`}
            data-testid="manual-preview-video"
        ></video>
    {/if}
</Modal>
```

#### `preload="metadata"` の契約 (レビュー Round 1 [Warning] 反映)

**開いた時点で playback route へ 1 回 GET が飛び、署名 URL が 1 本発行される**ことを
仕様として引き受ける。理由:

- 発行の契機は**ユーザーが「プレビュー」を押した 1 回だけ**である。一覧に 10 行あっても、
  開かない限り発行は 0 件 (行の描画では 1 件も発行しない)。
- 詳細画面 (`RenderPanel`) が `preload="none"` なのは、**画面を開くたびに**自動で走るのを
  避けるためで前提が違う。ここでは「開く = 見たい」であり、`none` にすると
  「プレビューを押したのに黒い箱が出て、もう一度再生を押す」二度手間になる。
- コスト面: 署名 URL 発行はアプリ内の計算 (S3 API 呼び出しではない)。
  実体の GET は再生時にどのみち発生する。

この契約は Vitest で `preload="metadata"` を固定して、後から無自覚に変わらないようにする。

### PHPStan 適合チェック

- 対象外 (フロントエンド)。TypeScript 側は `pnpm typecheck` / `pnpm lint` で担保する
  (`playbackSrc` は `string | null` に推論され、`{#if}` で narrowing 済み)。

### テスト計画

- [ ] 新規 `tests/js/components/features/manual/ManualPreviewModal.test.ts`
  - `open=true` のとき `<video>` の `src` が
    `/projects/7/manuals/2/render-jobs/9/playback` になる
  - `<video>` が `controls` 属性を持つ (自前の再生制御を持たないことの契約)
  - `<video>` の `preload` が `metadata` である (開いた時点で playback を要求する契約の固定)
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
    (同名を含む。根拠は 30 文字以上を維持する)
  - `docs/TODO-closed.md` の T182 行にも旧名が出るが、**過去の記録なので書き換えない**
    (当時の事実を保つ。現行コードの参照だけを直す)

### 選択の所在を固定するテスト (レビュー Round 1 [Critical] 反映)

parity テストだけでは「DTO 側に選択式を複製しても緑のまま」になる。
そこで **T154 の既存 Architecture テストにケースを 1 本足す**:

```php
// tests/Architecture/CurrentRenderArtifactInventoryTest.php (追加ケース)
test('一覧行 DTO は受け取り可否の規則を自前で持たず Canonical へ委譲する', function (): void {
    $tokens = PhpTokenScan::normalize(
        file_get_contents(base_path('app/DataTransferObjects/Manual/ManualListItemData.php'))
    );
    $texts = array_column($tokens, 'text');

    // 「どの行か」「実体が残っているか」の規則を DTO へ書き戻したら赤くなる
    expect($texts)->not->toContain('output_path');
    expect($texts)->not->toContain('latestSucceededRender');
    // 選択は Canonical 経由であること (委譲の実在)
    expect($texts)->toContain('CurrentRenderArtifact');
});
```

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

## 参考: T154 の目録と区分 enum (現行コード。今回の Critical 対応の判断根拠)

### app/Support/Security/RenderArtifactSelectionInventory.php

```php
<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Enums\Security\RenderArtifactSelectionKind;

/**
 * `render_jobs` に対する succeeded 条件つきの直接クエリを持つ app/ 配下ファイルの目録
 * (deny-by-default。T154)。
 *
 * 守る不変条件:
 *   app/ 配下で render_jobs に succeeded 条件つきの直接クエリを書いてよいファイルは
 *   本目録に登録されたものだけである。そのうち「**受け取り対象を 1 件選ぶ**」区分
 *   (`Canonical`) は `Services/Manual/CurrentRenderArtifact.php` ちょうど 1 ファイルに限る。
 *
 * 強制は `tests/Architecture/CurrentRenderArtifactInventoryTest.php`
 * (exact-fit: 未登録の直接クエリも、実体を失った stale entry も fail させる)。
 *
 * **保証しないもの**: gate が閉じるのはファイル粒度の直接クエリだけである
 * (同一登録ファイル内のメソッド追加・別 helper 経由・動的呼び出しには沈黙する)。
 */
final class RenderArtifactSelectionInventory
{
    /**
     * app/ 相対パス => [区分, 根拠 (30 文字以上)]。
     *
     * @return array<string, array{kind: RenderArtifactSelectionKind, rationale: string}>
     */
    public static function entries(): array
    {
        return [
            'Services/Manual/CurrentRenderArtifact.php' => [
                'kind' => RenderArtifactSelectionKind::Canonical,
                'rationale' => '「いま受け取れる成果物はどれか」の唯一の選択式。playback / download / 詳細画面 props の'
                    .'3 消費者が同じ行を指すための場所であり、保持ポリシーと同じ世代定義を持つ。',
            ],
            'Services/Manual/RenderJobService.php' => [
                'kind' => RenderArtifactSelectionKind::SupersessionCriterion,
                'rationale' => 'newerSucceededExists() は「より新しい succeeded が在るか」の世代交代判定であり、'
                    .'受け取り対象を 1 件選ぶ式ではない (削除 job と reconcile の前提条件)。',
            ],
            'Models/VideoManual.php' => [
                'kind' => RenderArtifactSelectionKind::EagerLoadCandidate,
                'rationale' => 'latestSucceededRender() は一覧が eager load する候補行の relation であり、'
                    .'output_path を見ないため受け取れるかを判断しない (決定は Canonical に残る)。'
                    .'世代定義の一致は ManualRowDownloadableParityTest が固定する。',
            ],
            'Services/Manual/RenderPipeline.php' => [
                'kind' => RenderArtifactSelectionKind::SupersessionCriterion,
                'rationale' => 'finalize が自分より古い succeeded 行を集めて削除 job を投入するための収集であり、'
                    .'最新 1 件を選ぶ式ではない (id の大小比較のみで latest を使わない)。',
            ],
        ];
    }
}
```

### app/Enums/Security/RenderArtifactSelectionKind.php

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * `render_jobs` に対する succeeded 条件つきの直接クエリを持つ app/ 配下ファイルの区分 (T154)。
 *
 * 守る不変条件:
 *   「いま受け取れるレンダ成果物はどれか」を **1 件選ぶ**式を書いてよいのは
 *   `Services/Manual/CurrentRenderArtifact.php` ただ 1 ファイルである。
 *
 * 区分は「統合してよい」の意味ではなく、**何のために succeeded 行を引いているか**の記録である。
 * 強制は `tests/Architecture/CurrentRenderArtifactInventoryTest.php` (deny-by-default + exact-fit)。
 */
enum RenderArtifactSelectionKind: string
{
    /** 受け取り対象を 1 件選ぶ選択式の実体。**1 ファイルのみ** */
    case Canonical = 'canonical';

    /**
     * 世代交代の判定 (より新しい succeeded が在るか / 旧世代の収集)。
     * **選択ではない** — id の大小比較だけで「どれを受け取るか」を決めない。
     */
    case SupersessionCriterion = 'supersession_criterion';

    /**
     * 一覧が eager load する**候補行**の relation (最新 succeeded 1 行)。
     * **受け取れるかを判断しない** — `output_path` を見ないため、
     * 「受け取れる成果物はどれか」の決定は Canonical に残る
     * (両者が同じ行を指すことは behavioral な parity テストが固定する)。
     */
    case EagerLoadCandidate = 'eager_load_candidate';
}
```

### tests/Architecture/CurrentRenderArtifactInventoryTest.php (検出仕様の docblock 抜粋 L1-45)

```php
<?php

declare(strict_types=1);

use App\Enums\Security\RenderArtifactSelectionKind;
use App\Support\Security\RenderArtifactSelectionInventory;
use Tests\Support\PhpTokenScan;

/*
 * レンダ成果物の選択式の単一化 (T154) の deny-by-default 目録。
 *
 * 不変条件:
 *   app/ 配下で **render_jobs に対する succeeded 条件つきの直接クエリ**を書いてよいファイルは
 *   `RenderArtifactSelectionInventory` に登録されたものだけである (exact-fit)。
 *   そのうち「**受け取り対象を 1 件選ぶ**」区分 (Canonical) は
 *   `Services/Manual/CurrentRenderArtifact.php` ちょうど 1 ファイルに限る。
 *
 * 走査は PhpTokenScan::normalize() ベース (コメント / docblock 内の出現は数えない)。
 *
 * 検出 (母集団): 同一ファイル内に次の 2 群が**同居**するもの
 *   1. status 群: `JobStatus::Succeeded` の参照 **または** 文字列リテラル 'succeeded'
 *   2. query 根 群: `renderJobs(` / `RenderJob::` (静的呼び出し全般) /
 *      文字列リテラル 'render_jobs' (DB::table('render_jobs') 経路)
 *
 * 免除区分 (SupersessionCriterion) には**機械検査される前提**が付く:
 *   `latest(` / `orderByDesc(` を 1 度も持たず、かつ `where('id', '>' | '<', …)` の
 *   **連続 token 列**を持つ (= 世代の大小比較であって最新 1 件の選択ではない)。
 *   前提が崩れた瞬間に区分ごと再審査になる。
 *
 * 免除区分 (EagerLoadCandidate = 一覧が eager load する候補行の relation) の前提:
 *   `output_path` を 1 度も参照しない (= 受け取れるかを判断しない。決定は Canonical に残る)。
 *   候補行と Canonical が同じ行を指すことは behavioral な parity テストの担当で、
 *   ここが固定するのは「判断を持ち込んでいない」ことだけである。
 *
 * 保証しないもの (誇張しない):
 * - 閉じるのは基本的に**ファイル粒度**の直接クエリである。登録済みファイル内でメソッドを増やして
 *   選択式を書く経路は、**EagerLoadCandidate だけ**個数 pin (succeeded 条件 / `ofMany(` /
 *   `hasOne(` / `RenderKind::Render` が各 1) で赤くなるが、**他の区分では検出しない**
 *   (fail-first は behavioral テストが担う)
 * - 文字列変数経由 (`$s = 'suc'.'ceeded'`)・動的呼び出し・別ファイルへ切り出した同義式・
 *   repository を挟む間接経路には**沈黙する**
 * - succeeded 条件を伴わない別基準の選択 (表示用の最新 job など) は母集団に入らない
 * - 走査根は app/ のみ (routes/ / config/ は見ない)
 */
final class RenderArtifactSelectionScanner
```

### app/Models/VideoManual.php (latestSucceededRender 抜粋 L115-143)

```php

    /**
     * 「いま受け取れる完成動画」の**候補行** (kind=render の最新 succeeded 1 行)。
     *
     * 世代の選び方は `CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)` と
     * **同一**である (同 manual・同 kind の最新 succeeded。旧世代へフォールバックしない)。
     * 違いは 1 点だけで、**こちらは受け取れるかを判断しない** — `output_path` を見ないため
     * NULL (掃除済み) の行も返す。受け取れるかの決定は呼び出し側 (ManualListItemData) が
     * `output_path !== null` を足して行い、両者が同じ行を指すことは
     * `ManualRowDownloadableParityTest` が固定する。
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
