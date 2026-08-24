【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び / prompt 文字列のコード直書き
6. 操作系 POST 応答での `redirect()->intended()`
7. **必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)**
セキュリティ不変条件: 子は親に属する(nested route 不整合は認可より前に 404) / cross-org 不可 / tenant キー不信 / 権限判定は laratrust_team_id 明示。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。
先人の知恵を探せ。Laravel/Svelte エコシステムに既存解があるなら使え。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: レビュアーの役割

あなたは Laravel + Svelte 5 のシニアコードレビュアーである。TODO T050「テイクのインラインプレビュー再生 + ナレ/字幕トグル」の実装差分をレビューする。以下の観点で判定せよ:

1. **設計との一致性**: 添付の詳細設計書 (S1〜S5) の意図どおり実装されているか。特に S1 の 302/no-store・状態秘匿 404・IDOR、S2 の video 完全 teardown・字幕 overlay、S4 の phase マシン (idle/recording/stopping) の排他契約。
2. **正確性**: 競合・再入・resource leak・promise の未処理 rejection・二重呼び出し。特に onCameraResume がちょうど 1 回か、recording→stopping で active=false を誤発火しないか、resumeAfterPreview の再入ガードと失敗後再試行。
3. **PHPStan 適合**: 型の widen や ignore がないか (level 10)。
4. **DTO/JsonResource パターン**: 302 リダイレクト応答が既存 render playback と同型で許容範囲か。
5. **テスト網羅性**: 施策ごとに正常系・異常系・セキュリティ (403/404/team 文脈) が検証されているか。テストが実装に追随しているか (テストなし実装がないか)。
6. **セキュリティ**: 認可より前の 404、preview ability の capture 委譲、非採用テイクへの署名 URL 発行が ready 限定 + capture 権限で保護されているか。
7. **DESIGN.md 準拠**: color/radius/typography は token 経由か、hex 直書きを増やしていないか。overlay 配色が surface/text ramp 由来か。
8. **Atomic Design 準拠**: features/capture が atoms/molecules/organisms の下層参照のみか (逆流なし)。アイコンは @lucide/svelte のみか。

出力形式: ファイルごとに判定、指摘を Critical / Warning / Suggestion に分類。最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示せよ。

---

## user: レビュー対象

### テスト結果 (worktree 内、全 green)
- `composer test`: 1728 passed, 2 skipped, 7089 assertions (Pest parallel)
- `composer phpstan`: No errors (level 10)
- `vendor/bin/pint --test`: passed
- `pnpm typecheck`: passed / `pnpm lint`: passed / `pnpm build`: passed
- `pnpm test` (vitest): 588 passed (73 files)。新規: TakePreviewDialog 9 / TakeStrip 追加 6 / CameraRecorder 追加 8 / TakePlaybackTest 15。

### design system 参照 (DESIGN.md token 抜粋)
- `surface: #FFFFFF` → tailwind `bg-surface`
- `text-secondary: #52525B` → `text-text-secondary`
- Modal overlay は `bg-text/50` (墨色 50%、黒 hex を使わない)。本体は `bg-surface border border-border rounded-lg`。
- 使用した atomic ディレクトリ: `resources/js/components/features/capture/` (TakePreviewDialog 新規, TakeStrip/CameraRecorder 改修), `pages/Capture/Show.svelte`。organisms/Modal.svelte を利用。
- overlay の字幕帯は `bg-surface/80 text-text` / ラベルは `text-text-secondary` を使用 (hex 直書きなし)。

### 詳細設計書 (detailed-design.md)

# 詳細設計: take-inline-preview（テイクのインラインプレビュー再生 + 字幕トグル）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

**v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告（不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び（`app/Prompts/` factory 経由のみ）
6. prompt 文字列のコード直書き（`resources/prompts/*.yaml`）
7. 操作系 POST 応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する。DESIGN.md）**

### セキュリティ不変条件（本設計に関わるもの）
- **子は親に属する**: nested route の不整合は**認可より前に 404**（`NestedRouteIdorDefenseTest` inventory 登録必須）。
- **cross-org 不可** / tenant キー不信 / 権限判定は `laratrust_team_id` 明示。

### コーディングルール
- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`、`--parallel`、`RefreshDatabase` グローバル）。テストデータは Factory。
- **DTO + JsonResource** パターン。Controller は薄く Service 委譲。
- フロントは Svelte 5 runes + DS token/ramp のみ（`DESIGN.md` canonical、ds-purity テスト）。
  component 階層は単方向 import。アイコンは `@lucide/svelte` のみ。
- 検証: `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`。

## 概念設計リファレンス

`devnotes/20260714-2126-take-inline-preview/conceptual-design.md`（conceptual-review Round 4 で **APPROVED**）。

### 仕様確定（doc/04・doc/05 精読結果）
- **再生対象**: テイク単体の生映像（合成プレビューは既存 render-jobs playback が担当）。
- **字幕トグル**: cut の `subtitle_primary` / `subtitle_secondary` を video 上に overlay 表示/非表示（v1 対象。データは既に payload 供給済み）。
- **ナレーション音声トグル**: **out-of-scope**（v1 は字幕のみ・TTS 後回し。切り替える合成音声が存在しない）。テイク生映像の録音音声は native `<video controls>` の音量/ミュートに委ねる。
- **字幕初期状態**: 撮影 PWA は **初期 ON**（doc/05 準拠。doc/04「初期オフ」は PC 編集画面向けで対象外）。

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | per-take プレビュー再生エンドポイント（302 署名 URL + no-store） | `routes/web.php`, `app/Http/Controllers/Capture/CaptureTakeController.php`, `app/Policies/TakePolicy.php`, `tests/Architecture/NestedRouteIdorDefenseTest.php` | High |
| S2 | `TakePreviewDialog.svelte`（インライン player + 字幕 overlay/トグル + 採用同居） | `resources/js/components/features/capture/TakePreviewDialog.svelte`（新規） | High |
| S3 | `TakeStrip.svelte` に再生ボタン + dialog 配線（採用同居・録画排他連携） | `resources/js/components/features/capture/TakeStrip.svelte` | High |
| S4 | 録画排他 / 資源解放の結合（CameraRecorder 停止・復帰 API + Capture/Show 配線） | `resources/js/components/features/capture/CameraRecorder.svelte`, `resources/js/pages/Capture/Show.svelte` | Medium |
| S5 | テスト（Pest Feature + vitest） | `tests/Feature/Capture/TakePlaybackTest.php`（新規）, `tests/js/**` | High |

**波及変更の要点**: payload（`CaptureCutData` / `types/capture.ts`）は**変更しない**（playback URL は route から組み立て、字幕データは既存キー）。よって `CaptureManualBrowsingTest` のキー契約に影響なし。TS 型変更なし。

---

## S1: per-take プレビュー再生エンドポイント

### 変更箇所
- `routes/web.php`（capture group, scopeBindings 内、L497 付近）: `takes.playback` GET を追加。
- `app/Http/Controllers/Capture/CaptureTakeController.php`: `playback()` メソッド追加。
- `app/Policies/TakePolicy.php`: `preview()` ability 追加。
- `app/Services/Capture/TakeObjectStorage.php`: 既存 `temporaryPlaybackUrl(string $path)` を再利用（変更なし）。
- `tests/Architecture/NestedRouteIdorDefenseTest.php`: inventory に `capture.takes.playback => $s` 追加。

### 波及変更
- TypeScript 型定義: **なし**（URL は既存 `takeUrl(take, "/playback")` で組み立て。payload 追加なし）。
- API Resource/DTO: **なし**（302 リダイレクト応答。Resource を介さない = 既存 `ManualRenderController::playback` と同型で許容される「再生用 302」）。
- テストファイル: `NestedRouteIdorDefenseTest`（inventory）+ 新規 `TakePlaybackTest`（S5）。

### Policy 登録前提（Codex R1-S1 Critical）
- `Take` → `TakePolicy` は Laravel 12 の **policy auto-discovery が既に有効**（既存 `Gate::authorize('adopt'|'update'|'delete'|'markDownloaded', $take)` が稼働している = 実証済み）。`preview` ability を追加するだけで解決される（明示登録の追加変更は不要）。
- 認可 Feature テスト（非 capture 403 / capture 302）を S5 で必須化する。

### playback URL を payload に戻さない理由（Codex R1 横断）
- 署名 URL はサーバが**都度発行**する（TTL 消費・トークン表面の局所化）。非採用テイクの署名 URL を Inertia payload に増やさず、再生時のみ 302 で発行する。よって `CaptureCutData` / TS 型は不変。

### 現行コード（参照モデル: ManualRenderController::playback）
```php
// URL 整合 guard → Gate::authorize('render', $manual) → 状態チェック →
return redirect()->away($storage->temporaryPlaybackUrl($renderJob->output_path));
```

### 変更後コード（新規 playback メソッド）
```php
use Illuminate\Http\RedirectResponse;
use App\Enums\Manual\TakeStatus;
use App\Services\Capture\TakeObjectStorage;

/**
 * テイク単体のプレビュー再生 (302 → S3 署名 URL)。撮影者/編集者 (capture ability)。
 * doc/04 テイクプレビュー / doc/05 個別再生。採用前テイクも再生できる (adopted 限定でない)。
 *
 * nested route 整合 (認可より前に 404):
 * 1. {project} ∈ current org (project.in-route-org middleware + resolveOrganizationProject)
 * 2. {manual}∈{project}, {cut}∈{manual}, {take}∈{cut} は Route::scopeBindings()
 *
 * 302 応答は Cache-Control: no-store, private (期限付き署名 URL の再利用防止)。
 * ※ これはアプリの 302 応答のみを制御し、リダイレクト先ストレージの動画本体の
 *   cache までは保証しない (動画本体の非キャッシュは v1 要件外)。
 */
public function playback(
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

    // 再生可能条件: ready のみ。uploading/processing/failed は 404 とし、
    // 内部状態 (処理中/失敗) を存在有無として漏らさない (状態秘匿。Codex R1-S1)
    if ($take->status !== TakeStatus::Ready) {
        abort(404);
    }

    // video_path は @property string (非 null カラム) = 型絞り込み問題なし
    return redirect()
        ->away($storage->temporaryPlaybackUrl($take->video_path))
        ->withHeaders(['Cache-Control' => 'no-store, private']);
}
```

**Cache-Control 挙動差の明文化（Codex R1-S1 Warning）**: 既存 `ManualRenderController::playback` は
Cache-Control を付けていない。take playback は**撮影 PWA の即時・反復再生**で署名 URL が
ブラウザ/中間層に再利用されるのを抑止するため `no-store, private` を付ける（take 側を厳格化）。
render 側への追随は本件スコープ外（別途 TODO 検討）。

**route 追加**（`routes/web.php` の scopeBindings group 内、`takes.downloaded` の隣）:
```php
Route::get('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback', [CaptureTakeController::class, 'playback'])
    ->name('takes.playback');
```

**TakePolicy::preview**（既存 ability と同型 = capture へ委譲）:
```php
/** プレビュー再生: 撮影者 (project_member) 以上。採用前テイクも対象 */
public function preview(User $user, Take $take): bool
{
    return $this->captureVia($user, $take);
}
```

### PHPStan 適合チェック
- [x] 戻り値の型 `RedirectResponse` を明示
- [x] `video_path` は非 null `string`（`@property string $video_path`）→ `temporaryPlaybackUrl(string)` に null 問題なし
- [x] `$take->status !== TakeStatus::Ready` は enum 比較（型安全）
- [x] DTO/配列返却なし（再生用 302 は Resource 対象外、既存 render playback と同じ扱い）

### テスト計画（S5 に集約）
- 302 + `Cache-Control` に `no-store` と `private` 両 directive / 非 ready 404 / 非 capture 403 / IDOR 各 404 / 署名 URL が対象 take の `video_path` から生成。

### リスク
- 非採用テイクにも署名 URL を発行できるようになるが、`preview` ability（capture 権限）で保護され、`ready` 限定 + IDOR 防御。既存の adopted-only DL 経路には影響しない（別 route）。

---

## S2: TakePreviewDialog.svelte（新規）

### 変更箇所
- 新規 `resources/js/components/features/capture/TakePreviewDialog.svelte`。
- 既存 `Modal.svelte`（organism, `size="lg"`）をベースに、TakeCommentDialog と同じ配線パターン。

### 波及変更
- TypeScript 型定義: `CaptureCut` / `CaptureTake` を props に受ける（既存型、変更なし）。
- API Resource/DTO: なし。
- テストファイル: vitest（S5）。

### 設計（props と責務）
```ts
interface Props {
    open: boolean;                 // bindable
    take: CaptureTake | null;      // 再生対象 (null で閉)
    cut: CaptureCut;               // 字幕 (subtitle_primary/secondary) の供給元
    playbackUrl: string | null;    // takeUrl(take, "/playback")。親が組み立て
    adopting: boolean;             // 採用 XHR 中
    error: string | null;          // 採用失敗メッセージ (親の run() error を流用)
    onAdopt: () => void;           // 親の adopt() を呼ぶ
    onClose: () => void;           // 親: dialog close + 録画復帰
}
```

### 主要挙動
- **video**: `<video controls src={playbackUrl} class="w-full ...">`。構図確認のため幅いっぱい（`size="lg"` = max-w-2xl、モバイルは全幅）。`playsinline`。
- **字幕 overlay**: video を `relative` 包み、`absolute` の overlay を重畳。
  - `subtitle_primary`（非 null 時）: 上部の小ラベル。
  - `subtitle_secondary`: 下部の帯（背景半透明 = DS token の surface/overlay ramp、hex 直書き禁止）。
    overlay は装飾テキスト扱いとし **`aria-live="off"`**（読み上げ事故防止。Codex R1-S2）。
    token クラス例: `bg-surface/80 text-text-primary`（DESIGN.md の surface/text ramp 由来。hex 直書きしない）。
  - `let subtitlesOn = $state(true)`（初期 ON）。トグルボタン（Lucide `Captions` / `CaptionsOff`）で切替。`{#if subtitlesOn}` で overlay を出し分け。
- **採用ボタン**: dialog footer に「このテイクを採用する」（`onAdopt`）。`loading={adopting}`。
- **video 要素の条件生成と完全 teardown（Codex R2-S2 / R3-S2 Critical: 宣言的 src との競合回避 + 資源完全解放）**:
  `<video>` を **`open && take !== null` の時のみ**生成し、さらに `{#key take.id}` で take 変更時に
  要素ごと再生成する。`src` は宣言的バインド。teardown（デコード資源/接続の完全解放）は
  `$effect` の **cleanup** に寄せ、close / 採用成功で閉じる / take 差し替え / component 破棄を
  **同一 cleanup** で扱う。要素は open 中のみ存在するため、`removeAttribute("src")` + `load()` を
  しても再 open は新要素で宣言的 src と競合しない:
  ```svelte
  {#if open && take !== null}
      {#key take.id}
          <video bind:this={video} controls playsinline src={playbackUrl ?? undefined} class="w-full ..."></video>
      {/key}
  {/if}
  ```
  ```ts
  let video: HTMLVideoElement | undefined = $state();
  function teardownVideo(target: HTMLVideoElement): void { target.pause(); target.removeAttribute("src"); target.load(); }
  $effect(() => {
      if (!open || take === null || video === undefined) return;
      const target = video;                 // effect 実行時の要素を固定 (差し替え時に新要素を誤 teardown しない。R4-S2)
      return () => teardownVideo(target);   // close / 採用成功 / take 差し替え / 破棄で発火（資源完全解放）
  });
  ```
  これにより初回 mount で誤って teardown せず、close/差し替え時は**当該（旧）要素**の src 除去 + load() で
  デコーダ・ネットワーク接続まで解放する（新要素の src は保持）。
- **subtitlesOn の初期化**: `$effect` で `open` が true になった時に `subtitlesOn = true` にリセット（再オープン時の状態持ち越し防止）。

### PHPStan 適合チェック
- N/A（Svelte）。`pnpm typecheck`（svelte-check）で props 型を担保。

### テスト計画（S5）
- 再生ボタン→dialog open で video 表示（`src` が playbackUrl）。
- 字幕トグルで overlay 表示/非表示。
- 採用ボタンで `onAdopt` 呼び出し。
- 初回 open 後に video の `src` が残る（`{#key}` 初回で誤 teardown しない）。
- take 差し替え後、新 take の `src` で再生可能（`{#key take?.id}` で要素再生成）。
- dialog close / 採用成功で video が停止（effect cleanup の `pause`）。

### リスク
- 字幕は timed track でなく全編 overlay（cut 固定字幕）。構図確認用途と整合。多言語/VTT は out-of-scope。

---

## S3: TakeStrip.svelte（再生ボタン + dialog 配線）

### 変更箇所
- `resources/js/components/features/capture/TakeStrip.svelte`。

### 波及変更
- TypeScript 型定義: Props に録画排他連携用のフィールドを追加（S4 と対）。
- テストファイル: vitest（S5）。

### Props 追加（S4 連携）
```ts
interface Props {
    projectId: number;
    manualId: number;
    cut: CaptureCut;
    onChanged: () => void;
    captureActive: boolean;              // 追加: 撮影 active (recording|stopping) なら preview を開かずエラー
    onRequestCameraRelease: () => void;  // 追加: 撮影待機中の open で stream 解放
    onCameraResume: () => void;          // 追加: dialog close で stream 復帰
}
```

### 主要挙動
- **再生ボタン**: `take.status === "ready"` のテイクにのみ Lucide `Play` の ghost ボタンを追加（採用ボタンの隣）。
  - `status !== "ready"` は再生不可だが**ボタンを disabled にしない**（禁止事項8）。処理中テイクには再生ボタン自体を出さない（存在しないので押下不可）。※ 採用ボタンは既存どおり全テイクに存在。
  - **理由の可視化（Codex R1-S3 Warning）**: 非 ready 行には補助文言（例: uploading/processing は
    「アップロード処理中は再生できません」、failed は「アップロードに失敗しました」）を caption で表示し、
    再生ボタンが無い理由をユーザーに示す。
- **openPreview(take)**:
  ```ts
  function openPreview(take: CaptureTake): void {
      error = null;
      if (captureActive) {
          error = "撮影中はプレビューを再生できません。撮影を停止してからお試しください。";
          return; // 押下時エラー (dialog を開かない)。captureActive は recording|stopping を含む
      }
      previewTarget = take;
      onRequestCameraRelease();   // 撮影待機中の live stream を解放
      previewOpen = true;
  }
  ```
- **previewUrl**: `$derived(previewTarget ? takeUrl(previewTarget, "/playback") : null)`（既存 `takeUrl` ヘルパ踏襲）。
- **採用（dialog から）**: 既存 `adopt(take)` を呼ぶ。成功時（`run()` の onChanged 経由）に dialog を閉じる。
  - 実装: `adoptFromPreview()` = `await adopt(previewTarget)` の成功後に `previewOpen = false`。`run()` は失敗時 `error` を設定するので、それを dialog の error に流用。busy 状態は `busyTakeId === previewTarget.id`。
  - **採用失敗時（Codex R1-S3 Suggestion）**: dialog は開いたままエラー表示し、フォーカスを採用ボタンへ戻す（モバイル操作性。実装時チェック項目）。
- **dialog close**: `previewOpen = false` + `onCameraResume()`（録画復帰）。

### PHPStan 適合チェック
- N/A（Svelte）。`pnpm typecheck`。

### テスト計画（S5）
- ready テイクに再生ボタン表示、押下で dialog open。
- 撮影 active（`captureActive=true`、recording|stopping）押下で dialog を開かずエラー表示。
- `window.open` を呼ばない（preview は video element）。DL ボタンの window.open は据え置き（別テスト）。

### リスク
- 既存 DL ボタン（`downloadAndAck` の window.open）と併存。preview と DL は用途が別（preview=確認、DL=端末保存）。混同しないよう aria-label / アイコンを分離（Play vs Download）。

---

## S4: 録画排他 / 資源解放の結合

### 変更箇所
- `resources/js/components/features/capture/CameraRecorder.svelte`: 停止/復帰 API + 録画状態通知。
- `resources/js/pages/Capture/Show.svelte`: recorder 参照保持 + TakeStrip への配線。

### 波及変更
- TypeScript 型定義: CameraRecorder Props に `onCaptureActiveChange` 追加。TakeStrip Props（S3）へ配線。
- テストファイル: vitest（S5）。

### CameraRecorder の変更
1. **撮影 active の phase マシン（Codex R1/R2/R3/R4-S4 Critical）**: Props に
   **`onCaptureActiveChange?: (active: boolean) => void`**（旧 `onRecordingChange` を改名）を追加。
   内部 phase を **`"idle" | "recording" | "stopping"`** で持つ。外部へ公開する排他状態
   `active` は **`phase !== "idle"`**（recording と **stopping の両方**を含む）。これにより
   TakeStrip の preview 解禁条件（`!captureActive`）と CameraRecorder の解放拒否条件
   （`phase !== "idle"`）が一致し、**stopping 中に preview と MediaRecorder が同居しない**（R4-S4）。
   phase 遷移は単一 setter `setPhase(next)` を通し、`active` の変化時のみ発火:
   ```ts
   type Phase = "idle" | "recording" | "stopping";
   let phase: Phase = "idle";
   function setPhase(next: Phase): void {
       const wasActive = phase !== "idle"; phase = next; const isActive = phase !== "idle";
       if (wasActive !== isActive) onCaptureActiveChange?.(isActive);
   }
   // recorder.start() 成功直後: setPhase("recording")
   // 安全停止 (多重呼び出しガード)
   function safeStop(): void {
       if (phase !== "recording") return;   // 二重 stop 防止 (stopping/idle では no-op)
       setPhase("stopping");                 // active は true のまま維持 (idle 遷移で初めて false)
       if (recorder === null) { fatalStopCleanup(); return; } // 不整合: stopping 固定を防ぐ (R5 Suggestion)
       try { recorder.stop(); }             // → recorder.onstop へ
       catch { fatalStopCleanup(); }        // 停止不能時: UI 復旧不能を防ぐ
   }
   // recorder.onstop: 唯一の正常終了点。onCaptured reject/throw でも終了通知を保証 (R3/R4-S4)
   recorder.onstop = async () => {
       try { /* blob 生成 → await onCaptured */ }
       catch { /* 既存のローカルエラー表示経路へ渡す (未処理 rejection にしない。R4-S4) */ }
       finally { setPhase("idle"); }
   };
   recorder.onerror = () => safeStop();
   // stream の各 track.onended = () => safeStop();
   // stop() が投げた等の致命時: 資源解放 + idle へ (active=true 残置による復旧不能を防ぐ)
   function fatalStopCleanup(): void { setPhase("idle"); releaseCamera(); onCameraUnavailable("recorder_unsupported"); }
   ```
   - **start 例外/getUserMedia 失敗の catch 経路**: この時点で phase は "idle" のまま（`setPhase("recording")`
     は start 成功後のみ）。`finally` は既存どおり `starting` リセットのみ（**finally で idle 化しない** —
     成功時も走り開始直後に録画解除する事故を防ぐ。R2-S4 Critical）。
   これにより releaseForPreview のガードが MediaRecorder の active/stopping window と一致し、
   録画中・停止処理中の暗黙解放によるデータ破壊を防ぐ。
2. **プレビュー用の解放/復帰 API**（component export、Svelte 5 runes の `export function`）:
   ```ts
   let resuming = false;                         // 再入ガード (Codex R1-S4 Warning)
   let resumePromise: Promise<void> | null = null; // in-flight 共有

   // preview を開く間に呼ばれる。録画中/停止処理中は no-op (録画データを守る = 暗黙終了しない)
   export function releaseForPreview(): void {
       if (phase !== "idle") return;    // recording と stopping の両方で解放を拒否 (contract c。R3-S4)
       wasActiveBeforePreview = stream !== null; // 復帰要否を記録
       releaseCamera();                 // 既存: tracks.stop() + stream=null
   }
   // preview close 後に呼ばれる。解放前に live だった時のみ再取得。多重 close/open を再入防止
   export function resumeAfterPreview(): Promise<void> {
       if (resuming) return resumePromise ?? Promise.resolve(); // in-flight 共有
       if (!wasActiveBeforePreview || phase !== "idle") return Promise.resolve();
       resuming = true;
       // 取得成功後にのみ wasActiveBeforePreview を false 化 (失敗時は true のまま=再試行可能。R2-S4 Warning)
       resumePromise = acquirePreviewStream()
           .then(() => { wasActiveBeforePreview = false; })
           .finally(() => { resuming = false; resumePromise = null; });
       return resumePromise;
   }
   ```
   - `acquirePreviewStream()` は現行 `startRecording` 内の getUserMedia + `video.srcObject` 設定部分を抽出した private 関数（録画開始とプレビュー復帰で共用。エラー時は既存の classify → onCameraUnavailable / transient error 表示を踏襲）。復帰時は録画を開始しない（stream 復帰のみ）。
   - 録画中に `releaseForPreview` が no-op である点が **contract (c)「録画データを暗黙に終了・破棄しない」** の要。加えて TakeStrip 側（S3）で録画中は preview を開かない（contract (a)）ので二重防御。

### Capture/Show の変更
```ts
import type CameraRecorder from "@/components/features/capture/CameraRecorder.svelte";

let captureActive = $state(false);
// Svelte 5: component instance 型を import type で参照 (any 混入回避。Codex R1-S4 Suggestion)
let recorderRef = $state<CameraRecorder | null>(null); // bind:this

// CameraRecorder に onCaptureActiveChange と bind:this を配線
// <CameraRecorder bind:this={recorderRef} onCaptureActiveChange={(a) => (captureActive = a)} ... />

// TakeStrip へ
// captureActive={captureActive}
// onRequestCameraRelease={() => recorderRef?.releaseForPreview()}
// onCameraResume={() => void recorderRef?.resumeAfterPreview()}
```
- **fallback 経路**（`showRecorder === false` = CaptureFileFallback）: camera stream が無いため `captureActive=false`、`recorderRef=null`。TakeStrip の `onRequestCameraRelease` / `onCameraResume` は optional chaining で no-op。preview は常に開ける（資源競合が無い）。

### PHPStan 適合チェック
- N/A（Svelte）。`pnpm typecheck`。

### テスト計画（S5）
- 録画待機中 open → `releaseForPreview` で stream 解放、close → `resumeAfterPreview` で再取得。
- 録画中 open → `releaseForPreview` は no-op（録画終了/破棄処理を呼ばない）。
- `onCaptureActiveChange` が start（true）/ idle 到達（false）で正しく通知。stopping 中は true を維持。

### リスク
- モバイル Safari のメディア資源競合対策。`bind:this` による component 参照は Svelte 5 の標準。fallback 時は no-op で安全。
- getUserMedia の再取得はユーザー許可済み前提（初回録画で許可済み）。未許可のまま preview を開くケースは録画未開始 = `wasActiveBeforePreview=false` で resume no-op。

---

## S5: テスト

### Pest Feature（新規 `tests/Feature/Capture/TakePlaybackTest.php`）
`RefreshDatabase` グローバル適用（個別 `DatabaseTransactions` 禁止）。`FakeTakeObjectStorage` を bind。データは Factory（`Take::factory()`, `Cut::factory()`, `VideoManual::factory()`, `Project::factory()`）。

- [ ] 撮影者が ready テイクを GET playback → **302**、Location が対象 take の `video_path` から生成、`Cache-Control` に `no-store` **かつ** `private`。
- [ ] 署名 URL が**別 take の path を使わない**（FakeTakeObjectStorage の呼び出し引数 = 対象 take の `video_path` を assert）。
- [ ] 非 ready テイク（uploading/processing/failed）→ **404**。
- [ ] 非 capture ユーザー（権限なし）→ **403**。
- [ ] **team 文脈（Codex R1-S5 Critical / セキュリティ不変条件 laratrust_team_id 明示）**: 同一ユーザーが
      **別 team 文脈では 403 / 正しい team 文脈では 302**（team 切替を明示して権限判定が team scope で効くことを固定）。
- [ ] IDOR: project mismatch / manual mismatch / cut mismatch → **各 404**（認可より前）。cross-org → 404。
- [ ] **take mismatch（Codex R1-S5 Warning）**: `.../cuts/{cutA}/takes/{takeB}`（takeB は別 cut 所属）→ **404**。
- [ ] （Architecture）`NestedRouteIdorDefenseTest` が `capture.takes.playback` を inventory 分類済みで green。

### vitest（`tests/js/**`、TakePreviewDialog / TakeStrip / CameraRecorder）
- [ ] TakeStrip: ready テイクに再生ボタン、押下で dialog open（video の src = playback URL）。
- [ ] TakeStrip: `captureActive=true`（recording|stopping）で押下 → dialog を開かずエラー表示。
- [ ] TakeStrip: preview 操作で `window.open` を呼ばない。
- [ ] TakePreviewDialog: 字幕トグルで overlay 表示/非表示（subtitle_primary/secondary）。
- [ ] TakePreviewDialog: 初回 open 後に video の `src` が残る（Codex R2-S5）／take 差し替え後に新 `src` で再生可能。
- [ ] TakePreviewDialog: dialog close 経路・採用成功経路の**両方**で video が完全 teardown される（`pause` + `src` 除去 + `load`、要素破棄。Codex R3-S5）。
- [ ] S4 結合: 録画待機中 open → `releaseForPreview` 呼び出し / close → `resumeAfterPreview` 呼び出し / 録画中 open → release は no-op（録画終了処理を呼ばない）。
- [ ] S3/S4 結合回帰: dialog close 時に `onCameraResume` が**ちょうど 1 回**呼ばれる（Codex R1-S5 Suggestion）。
- [ ] CameraRecorder: `onerror` / track `onended` は `safeStop()`→`recorder.stop()` を呼び、**`onstop`（idle 到達）でのみ** `onCaptureActiveChange(false)`（Codex R2/R3/R4-S4: 録画中/停止中の暗黙 false 化をしない）。
- [ ] CameraRecorder: **recording→stopping 遷移では `onCaptureActiveChange(false)` が発火せず、idle 遷移で初めて false**（Codex R4-S5）。stopping 中は TakeStrip が preview を開かない。
- [ ] CameraRecorder: **`onstop` 内の finalize/onCaptured が reject/throw しても phase が idle に戻り撮影状態が解除**され、**既存エラー処理へ渡り未処理 rejection にならない**（Codex R3/R4-S5: try/catch/finally 保証）。
- [ ] CameraRecorder: **recording / stopping 中に camera 解放（releaseForPreview）が拒否される**（phase !== "idle" ガード。Codex R2/R3-S5）。
- [ ] CameraRecorder: **`safeStop()` 多重呼び出しで `recorder.stop()` が重複しない**（phase ガード。Codex R3-S5）。
- [ ] CameraRecorder: `resumeAfterPreview` の多重呼び出しで `getUserMedia` が二重発火しない（再入ガード）／**取得失敗後に再試行できる**（`wasActiveBeforePreview` が成功後のみ false。Codex R2-S4 Warning）。

### 実装順（テストファースト。Codex W2）
1. 失敗する Feature/vitest を先に追加（fail 確認）。
2. `NestedRouteIdorDefenseTest` inventory 更新。
3. 実装（S1→S4）。
4. `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` を全 green。

### 実装時チェック（Codex R1 横断）
- `TakePreviewDialog` が atoms/molecules へ逆流 import しないこと（`atomic-import-graph.test.ts` が強制。features/capture → organisms/atoms の下層参照のみ）。
- overlay 配色は DS token/ramp 経由（hex 直書き禁止、ds-purity テスト）。
- アイコンは `@lucide/svelte` のみ（`Play` / `Captions` / `CaptionsOff`）。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 既存の撮影 PWA（Capture/Show, TakeStrip, CameraRecorder）と CaptureTakeController に対する追記中心の変更。payload/DTO/TS 型の破壊的変更なし。新規 route/Policy ability/component の追加で、既存機能（adopt/DL/録画/アップロード）を書き換えない。 |
| 競合リスク | 低。CaptureTakeController への method 追加、routes/web.php への 1 行追加、TakePolicy への method 追加は他施策と非干渉。TakeStrip/CameraRecorder/Show の Props 追加は 3 ファイル間で閉じる（同一 PR で整合）。 |

## スコープ外（再掲）
- ナレーション音声トグル / TTS 音声再生（v1 は字幕のみ）。
- 合成（全体連結）プレビュー再生（既存 render-jobs playback）。
- timed caption(VTT) / 多言語字幕切替（PC 編集側の後続）。
- DL（端末保存）フローの window.open 置換（別用途のため温存）。
- doc/04（PC 編集画面）側の同等 UI（本件は撮影 PWA に限定）。
- ストレージ動画本体の cache 制御（302 の no-store のみ）。
- doc/04・doc/05 の字幕初期状態の記述差分解消（別 doc 更新 TODO）。


### 実装差分 (git diff HEAD)

```diff
diff --git a/app/Http/Controllers/Capture/CaptureTakeController.php b/app/Http/Controllers/Capture/CaptureTakeController.php
index 28dd23f..3ee4cad 100644
--- a/app/Http/Controllers/Capture/CaptureTakeController.php
+++ b/app/Http/Controllers/Capture/CaptureTakeController.php
@@ -6,6 +6,7 @@
 
 use App\DataTransferObjects\Capture\CaptureCutData;
 use App\DataTransferObjects\Capture\CaptureTakeData;
+use App\Enums\Manual\TakeStatus;
 use App\Http\Concerns\ResolvesCurrentOrganization;
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Capture\MarkTakeDownloadedRequest;
@@ -19,8 +20,10 @@
 use App\Models\User;
 use App\Models\VideoManual;
 use App\Services\Capture\CaptureTakeService;
+use App\Services\Capture\TakeObjectStorage;
 use App\Services\Capture\TakeRegistrationService;
 use Illuminate\Http\JsonResponse;
+use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Http\Response;
 use Illuminate\Support\Facades\Gate;
@@ -136,4 +139,41 @@ public function markDownloaded(
 
         return CaptureTakeResource::make(CaptureTakeData::fromTake($acked));
     }
+
+    /**
+     * テイク単体のプレビュー再生 (302 → S3 署名 URL)。撮影者/編集者 (capture ability)。
+     * doc/04 テイクプレビュー / doc/05 個別再生。採用前テイクも再生できる (adopted 限定でない)。
+     *
+     * nested route 整合 (認可より前に 404):
+     * 1. {project} ∈ current org (project.in-route-org middleware + resolveOrganizationProject)
+     * 2. {manual}∈{project}, {cut}∈{manual}, {take}∈{cut} は Route::scopeBindings()
+     *
+     * 302 応答は Cache-Control: no-store, private (期限付き署名 URL の再利用防止)。
+     * ※ これはアプリの 302 応答のみを制御し、リダイレクト先ストレージの動画本体の
+     *   cache までは保証しない (動画本体の非キャッシュは v1 要件外)。
+     */
+    public function playback(
+        Request $request,
+        Project $project,
+        VideoManual $manual,
+        Cut $cut,
+        Take $take,
+        TakeObjectStorage $storage,
+    ): RedirectResponse {
+        $organization = $this->resolveCurrentOrganization($request);
+        // URL 整合 guard: 認可より前に 404
+        $this->resolveOrganizationProject($organization, $project);
+        Gate::authorize('preview', $take);
+
+        // 再生可能条件: ready のみ。uploading/processing/failed は 404 とし、
+        // 内部状態 (処理中/失敗) を存在有無として漏らさない (状態秘匿)
+        if ($take->status !== TakeStatus::Ready) {
+            abort(404);
+        }
+
+        // video_path は @property string (非 null カラム) = 型絞り込み問題なし
+        return redirect()
+            ->away($storage->temporaryPlaybackUrl($take->video_path))
+            ->withHeaders(['Cache-Control' => 'no-store, private']);
+    }
 }
diff --git a/app/Policies/TakePolicy.php b/app/Policies/TakePolicy.php
index dc25cd6..cfc1a0f 100644
--- a/app/Policies/TakePolicy.php
+++ b/app/Policies/TakePolicy.php
@@ -45,6 +45,12 @@ public function markDownloaded(User $user, Take $take): bool
         return $this->captureVia($user, $take);
     }
 
+    /** プレビュー再生: 撮影者 (project_member) 以上。採用前テイクも対象 (doc/04・doc/05) */
+    public function preview(User $user, Take $take): bool
+    {
+        return $this->captureVia($user, $take);
+    }
+
     private function captureVia(User $user, Take $take): bool
     {
         $project = $take->cut?->videoManual?->project;
diff --git a/resources/js/components/features/capture/CameraRecorder.svelte b/resources/js/components/features/capture/CameraRecorder.svelte
index f2cfdc4..a15b14b 100644
--- a/resources/js/components/features/capture/CameraRecorder.svelte
+++ b/resources/js/components/features/capture/CameraRecorder.svelte
@@ -10,27 +10,74 @@
      * 録画不能な恒久失敗 (権限拒否・デバイス無し・API 不適合) は onCameraUnavailable で
      * 親に通知し、親がファイル選択フォールバックへ切り替える (doc/10 §10.8-3、F-03)。
      * 一時的失敗 (デバイス使用中等) のみローカルにエラー表示し再試行可能のまま残す。
+     *
+     * 撮影 active の phase マシン (T050 / S4): idle / recording / stopping。
+     * 外部へ公開する排他状態 active は phase !== "idle" (recording と stopping の両方)。
+     * これにより preview 解禁条件 (親: !captureActive) と camera 解放拒否条件 (phase !== "idle")
+     * が一致し、停止処理中に preview と MediaRecorder が同居しない。
      */
     interface Props {
-        onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void;
+        onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void | Promise<void>;
         /** カメラが恒久的に使えないと判明したときの通知 (親がフォールバックへ切替) */
         onCameraUnavailable: (reason: CameraUnavailableReason) => void;
+        /** 撮影 active (phase !== "idle") の変化通知。preview 排他制御に使う (T050) */
+        onCaptureActiveChange?: (active: boolean) => void;
     }
 
-    let { onCaptured, onCameraUnavailable }: Props = $props();
+    let { onCaptured, onCameraUnavailable, onCaptureActiveChange }: Props = $props();
+
+    type Phase = "idle" | "recording" | "stopping";
 
     let video: HTMLVideoElement | null = $state(null);
     let stream: MediaStream | null = null;
     let recorder: MediaRecorder | null = null;
     let chunks: Blob[] = [];
     let startedAt = 0;
-    let recording = $state(false);
+    let phase = $state<Phase>("idle");
     let error = $state<string | null>(null);
     /** 開始処理中の再入ガード (getUserMedia 待ち中の多重クリック防止。UI disabled は使わない) */
     let starting = false;
+    /** preview 解放前に live だったか (復帰要否) */
+    let wasActiveBeforePreview = false;
+    /** resumeAfterPreview の再入ガード (多重 close/open で getUserMedia を二重発火させない) */
+    let resuming = false;
+    let resumePromise: Promise<void> | null = null;
+
+    // phase 遷移は単一 setter を通し、active (phase !== "idle") の変化時のみ通知する。
+    function setPhase(next: Phase): void {
+        const wasActive = phase !== "idle";
+        phase = next;
+        const isActive = phase !== "idle";
+        if (wasActive !== isActive) onCaptureActiveChange?.(isActive);
+    }
+
+    // getUserMedia + video.srcObject 設定 (録画開始と preview 復帰で共用)。
+    // 成功 = true。失敗時は既存の classify → onCameraUnavailable / transient error 表示を踏襲。
+    async function acquirePreviewStream(): Promise<boolean> {
+        try {
+            stream ??= await navigator.mediaDevices.getUserMedia({
+                video: { facingMode: "environment" },
+                audio: true,
+            });
+        } catch (cause) {
+            const classified = classifyGetUserMediaError(cause);
+            if (classified.kind === "transient") {
+                error =
+                    "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。";
+                return false;
+            }
+            onCameraUnavailable(classified.reason);
+            return false;
+        }
+        if (video) {
+            video.srcObject = stream;
+            await video.play().catch(() => undefined);
+        }
+        return true;
+    }
 
     async function startRecording(): Promise<void> {
-        if (starting || recording) return; // 再入防止 (アーリーリターン。規約: disabled 禁止)
+        if (starting || phase !== "idle") return; // 再入防止 (アーリーリターン。規約: disabled 禁止)
         starting = true;
         try {
             error = null;
@@ -40,26 +87,9 @@
                 onCameraUnavailable("mime_unsupported");
                 return;
             }
-            try {
-                stream ??= await navigator.mediaDevices.getUserMedia({
-                    video: { facingMode: "environment" },
-                    audio: true,
-                });
-            } catch (cause) {
-                const classified = classifyGetUserMediaError(cause);
-                if (classified.kind === "transient") {
-                    // 一時系 (NotReadableError/AbortError): 再試行可能のままエラー表示
-                    error =
-                        "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。";
-                    return;
-                }
-                onCameraUnavailable(classified.reason);
-                return;
-            }
-            if (video) {
-                video.srcObject = stream;
-                await video.play().catch(() => undefined);
-            }
+            const acquired = await acquirePreviewStream();
+            if (!acquired) return;
+            if (stream === null) return; // 型絞り込み (acquired=true なら実質非 null)
             chunks = [];
             try {
                 recorder = new MediaRecorder(stream, { mimeType });
@@ -72,14 +102,25 @@
             recorder.ondataavailable = (event) => {
                 if (event.data.size > 0) chunks.push(event.data);
             };
-            recorder.onstop = () => {
-                const blob = new Blob(chunks, { type: mimeType });
-                const durationMs = Date.now() - startedAt;
-                recording = false;
-                if (blob.size > 0) {
-                    onCaptured(blob, mimeType, durationMs);
+            // 唯一の正常終了点 (idle への遷移)。onCaptured の reject/throw でも終了通知を保証する。
+            recorder.onstop = async () => {
+                try {
+                    const blob = new Blob(chunks, { type: mimeType });
+                    const durationMs = Date.now() - startedAt;
+                    if (blob.size > 0) {
+                        await onCaptured(blob, mimeType, durationMs);
+                    }
+                } catch {
+                    // 既存のローカルエラー表示経路へ渡す (未処理 rejection にしない)
+                    error = "撮影データの処理に失敗しました。もう一度お試しください。";
+                } finally {
+                    setPhase("idle");
                 }
             };
+            recorder.onerror = () => safeStop();
+            stream.getTracks().forEach((track) => {
+                track.onended = () => safeStop();
+            });
             startedAt = Date.now();
             try {
                 recorder.start();
@@ -91,14 +132,32 @@
                 onCameraUnavailable("recorder_unsupported");
                 return;
             }
-            recording = true;
+            setPhase("recording");
         } finally {
             starting = false;
         }
     }
 
-    function stopRecording(): void {
-        recorder?.stop();
+    // 安全停止 (多重呼び出しガード)。recording 以外では no-op (stopping/idle で重複 stop しない)。
+    function safeStop(): void {
+        if (phase !== "recording") return;
+        setPhase("stopping"); // active は true のまま維持 (idle 遷移で初めて false)
+        if (recorder === null) {
+            fatalStopCleanup(); // 不整合: stopping 固定を防ぐ
+            return;
+        }
+        try {
+            recorder.stop(); // → recorder.onstop へ
+        } catch {
+            fatalStopCleanup(); // 停止不能時: UI 復旧不能を防ぐ
+        }
+    }
+
+    // stop() が投げた等の致命時: 資源解放 + idle へ (active=true 残置による復旧不能を防ぐ)
+    function fatalStopCleanup(): void {
+        setPhase("idle");
+        releaseCamera();
+        onCameraUnavailable("recorder_unsupported");
     }
 
     function releaseCamera(): void {
@@ -106,6 +165,30 @@
         stream = null;
     }
 
+    // preview を開く間に呼ばれる。録画中/停止処理中は no-op (録画データを守る = 暗黙終了しない)。
+    export function releaseForPreview(): void {
+        if (phase !== "idle") return; // recording と stopping の両方で解放を拒否
+        wasActiveBeforePreview = stream !== null; // 復帰要否を記録
+        releaseCamera();
+    }
+
+    // preview close 後に呼ばれる。解放前に live だった時のみ再取得。多重 close/open を再入防止。
+    export function resumeAfterPreview(): Promise<void> {
+        if (resuming) return resumePromise ?? Promise.resolve(); // in-flight 共有
+        if (!wasActiveBeforePreview || phase !== "idle") return Promise.resolve();
+        resuming = true;
+        // 取得成功後にのみ wasActiveBeforePreview を false 化 (失敗時は true のまま=再試行可能)
+        resumePromise = acquirePreviewStream()
+            .then((ok) => {
+                if (ok) wasActiveBeforePreview = false;
+            })
+            .finally(() => {
+                resuming = false;
+                resumePromise = null;
+            });
+        return resumePromise;
+    }
+
     onDestroy(releaseCamera);
 </script>
 
@@ -120,16 +203,16 @@
         data-testid="camera-preview"
     ></video>
     <div class="flex items-center justify-center gap-3">
-        {#if recording}
-            <Button variant="danger" onclick={stopRecording} testId="stop-recording">
-                <Square class="size-4" aria-hidden="true" />
-                録画停止
-            </Button>
-        {:else}
+        {#if phase === "idle"}
             <Button variant="primary" onclick={startRecording} testId="start-recording">
                 <Circle class="size-4" aria-hidden="true" />
                 録画開始
             </Button>
+        {:else}
+            <Button variant="danger" onclick={safeStop} testId="stop-recording">
+                <Square class="size-4" aria-hidden="true" />
+                録画停止
+            </Button>
         {/if}
     </div>
     {#if error}
diff --git a/resources/js/components/features/capture/TakePreviewDialog.svelte b/resources/js/components/features/capture/TakePreviewDialog.svelte
new file mode 100644
index 0000000..a972497
--- /dev/null
+++ b/resources/js/components/features/capture/TakePreviewDialog.svelte
@@ -0,0 +1,153 @@
+<script lang="ts">
+    import { Captions, CaptionsOff } from "@lucide/svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Modal from "@/components/organisms/Modal.svelte";
+    import type { CaptureCut, CaptureTake } from "@/types/capture";
+
+    /**
+     * テイク単体のインラインプレビュー再生 (T050 / S2)。
+     * 生映像を native <video controls> で再生し、cut の固定字幕を overlay で重ねる。
+     * 採用ボタンを同居させ、確認しながらそのまま採用できる (doc/04・doc/05)。
+     * 字幕は timed track ではなく cut 固定字幕の全編 overlay (構図確認用途)。
+     */
+    interface Props {
+        open: boolean; // bindable
+        take: CaptureTake | null; // 再生対象 (null で閉)
+        cut: CaptureCut; // 字幕 (subtitle_primary/secondary) の供給元
+        playbackUrl: string | null; // takeUrl(take, "/playback")。親が組み立て
+        adopting: boolean; // 採用 XHR 中
+        error: string | null; // 採用失敗メッセージ (親の run() error を流用)
+        onAdopt: () => void; // 親の adopt() を呼ぶ
+        onClose: () => void; // 親: dialog close + 録画復帰
+    }
+
+    let {
+        open = $bindable(false),
+        take,
+        cut,
+        playbackUrl,
+        adopting,
+        error,
+        onAdopt,
+        onClose,
+    }: Props = $props();
+
+    let video: HTMLVideoElement | undefined = $state();
+    let subtitlesOn = $state(true);
+
+    // 再オープン時に字幕を初期 ON へ戻す (撮影 PWA は初期 ON。doc/05)。
+    $effect(() => {
+        if (open) {
+            subtitlesOn = true;
+        }
+    });
+
+    // video のデコード資源/ネットワーク接続を完全解放する。
+    function teardownVideo(target: HTMLVideoElement): void {
+        target.pause();
+        target.removeAttribute("src");
+        target.load();
+    }
+
+    // close / 採用成功で閉じる / take 差し替え / component 破棄を同一 cleanup で扱う。
+    // effect 実行時の要素を固定し、差し替え時に新要素を誤 teardown しない。
+    $effect(() => {
+        if (!open || take === null || video === undefined) return;
+        const target = video;
+        return () => teardownVideo(target);
+    });
+
+    // Modal の bind:open が true→false に遷移した時のみ親へ通知する
+    // (背景クリック / Esc / × / 閉じるボタン / 採用成功をすべて拾う)。
+    // 初期 mount の false では発火させない (wasOpen ガード)。
+    let wasOpen = false;
+    $effect(() => {
+        if (wasOpen && !open) {
+            onClose();
+        }
+        wasOpen = open;
+    });
+</script>
+
+<Modal bind:open title="テイクのプレビュー" size="lg" processing={adopting} testId="take-preview-dialog">
+    <div class="flex flex-col gap-3">
+        <div class="relative w-full overflow-hidden rounded-md bg-text/5">
+            {#if open && take !== null}
+                {#key take.id}
+                    <!-- svelte-ignore a11y_media_has_caption -->
+                    <video
+                        bind:this={video}
+                        controls
+                        playsinline
+                        src={playbackUrl ?? undefined}
+                        class="w-full"
+                        data-testid="take-preview-video"
+                    ></video>
+                {/key}
+            {/if}
+
+            {#if subtitlesOn}
+                <div class="pointer-events-none absolute inset-0 flex flex-col justify-between p-3">
+                    {#if cut.subtitle_primary !== null && cut.subtitle_primary !== ""}
+                        <span
+                            class="self-start rounded-sm bg-surface/80 px-2 py-1 text-caption text-text-secondary"
+                            aria-live="off"
+                            data-testid="take-preview-subtitle-primary"
+                        >
+                            {cut.subtitle_primary}
+                        </span>
+                    {:else}
+                        <span></span>
+                    {/if}
+                    {#if cut.subtitle_secondary !== ""}
+                        <span
+                            class="self-stretch rounded-sm bg-surface/80 px-2 py-1 text-body text-text"
+                            aria-live="off"
+                            data-testid="take-preview-subtitle-secondary"
+                        >
+                            {cut.subtitle_secondary}
+                        </span>
+                    {/if}
+                </div>
+            {/if}
+        </div>
+
+        <div class="flex items-center justify-between gap-2">
+            <Button
+                variant="ghost"
+                size="sm"
+                onclick={() => (subtitlesOn = !subtitlesOn)}
+                ariaExpanded={subtitlesOn}
+                testId="take-preview-subtitle-toggle"
+            >
+                {#if subtitlesOn}
+                    <Captions class="size-4" aria-hidden="true" />
+                    字幕を隠す
+                {:else}
+                    <CaptionsOff class="size-4" aria-hidden="true" />
+                    字幕を表示
+                {/if}
+            </Button>
+        </div>
+
+        {#if error}
+            <p class="text-caption text-danger" role="alert" data-testid="take-preview-error">
+                {error}
+            </p>
+        {/if}
+    </div>
+
+    {#snippet footer()}
+        <Button variant="neutral" onclick={() => (open = false)} testId="take-preview-close">
+            閉じる
+        </Button>
+        <Button
+            variant="primary"
+            loading={adopting}
+            onclick={onAdopt}
+            testId="take-preview-adopt"
+        >
+            このテイクを採用する
+        </Button>
+    {/snippet}
+</Modal>
diff --git a/resources/js/components/features/capture/TakeStrip.svelte b/resources/js/components/features/capture/TakeStrip.svelte
index 4248c3e..5552890 100644
--- a/resources/js/components/features/capture/TakeStrip.svelte
+++ b/resources/js/components/features/capture/TakeStrip.svelte
@@ -1,8 +1,9 @@
 <script lang="ts">
-    import { Check, ChevronDown, ChevronUp, Download, Pencil, Trash2 } from "@lucide/svelte";
+    import { Check, ChevronDown, ChevronUp, Download, Pencil, Play, Trash2 } from "@lucide/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import TakeCommentDialog from "@/components/features/capture/TakeCommentDialog.svelte";
+    import TakePreviewDialog from "@/components/features/capture/TakePreviewDialog.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
     import { captureJson, extractErrorMessage } from "@/lib/capture/http";
     import type { CaptureCut, CaptureTake } from "@/types/capture";
@@ -17,9 +18,23 @@
         manualId: number;
         cut: CaptureCut;
         onChanged: () => void;
+        /** 撮影 active (recording|stopping) なら preview を開かずエラー表示 (資源競合防止) */
+        captureActive?: boolean;
+        /** preview を開く直前に撮影待機中の live stream を解放させる (親: CameraRecorder) */
+        onRequestCameraRelease?: () => void;
+        /** preview close で撮影待機を復帰させる (親: CameraRecorder) */
+        onCameraResume?: () => void;
     }
 
-    let { projectId, manualId, cut, onChanged }: Props = $props();
+    let {
+        projectId,
+        manualId,
+        cut,
+        onChanged,
+        captureActive = false,
+        onRequestCameraRelease,
+        onCameraResume,
+    }: Props = $props();
 
     let error = $state<string | null>(null);
     let busyTakeId = $state<number | null>(null);
@@ -28,6 +43,11 @@
     let commentSaving = $state(false);
     let commentError = $state<string | null>(null);
 
+    // プレビュー再生ダイアログ (T050)。preview は video element での確認 (DL の window.open とは別用途)。
+    let previewTarget = $state<CaptureTake | null>(null);
+    let previewOpen = $state(false);
+    const previewUrl = $derived(previewTarget !== null ? takeUrl(previewTarget, "/playback") : null);
+
     // 削除確認ダイアログ。id をスナップショット保持し、ラベルは開いた時点の値で確定する
     // (親の再取得・並べ替えで参照内容がずれないように object 参照ではなく id + label を持つ)。
     let deleteTargetId = $state<number | null>(null);
@@ -85,6 +105,38 @@
     const move = (take: CaptureTake, position: number) =>
         run(take, () => captureJson(takeUrl(take), "PATCH", { position: Math.max(0, position) }));
 
+    // 再生ボタン押下: 撮影中はエラー表示して開かない (押下時エラー。disabled 禁止)。
+    function openPreview(take: CaptureTake): void {
+        error = null;
+        if (captureActive) {
+            // captureActive は recording|stopping を含む (撮影データ保護のため preview と同居させない)
+            error = "撮影中はプレビューを再生できません。撮影を停止してからお試しください。";
+            return;
+        }
+        previewTarget = take;
+        onRequestCameraRelease?.(); // 撮影待機中の live stream を解放
+        previewOpen = true;
+    }
+
+    // dialog が閉じた時 (背景クリック / Esc / × / 閉じるボタン / 採用成功) の単一クリーンアップ点。
+    // TakePreviewDialog が open の true→false 遷移でちょうど 1 回だけ呼ぶ (二重復帰防止)。
+    function handlePreviewClose(): void {
+        previewTarget = null;
+        onCameraResume?.(); // 録画待機を復帰
+    }
+
+    // dialog の採用ボタン: 既存 adopt を呼び、成功時のみ dialog を閉じる。
+    // previewOpen=false 遷移が handlePreviewClose を発火させる (復帰は 1 経路に集約)。
+    // run() は失敗時に error を設定するので dialog はそのまま (error を表示)。
+    async function adoptFromPreview(): Promise<void> {
+        const target = previewTarget;
+        if (target === null) return;
+        await adopt(target);
+        if (error === null) {
+            previewOpen = false;
+        }
+    }
+
     function openComment(take: CaptureTake): void {
         commentTarget = take;
         commentError = null;
@@ -182,8 +234,29 @@
                         ・{take.comment}
                     {/if}
                 </p>
+                {#if take.status !== "ready"}
+                    <p class="text-caption text-text-secondary" data-testid={`take-not-ready-${take.id}`}>
+                        {#if take.status === "failed"}
+                            アップロードに失敗しました。
+                        {:else}
+                            アップロード処理中は再生できません。
+                        {/if}
+                    </p>
+                {/if}
             </div>
             <div class="flex shrink-0 items-center gap-1">
+                {#if take.status === "ready"}
+                    <Button
+                        variant="ghost"
+                        size="sm"
+                        iconOnly
+                        ariaLabel="再生"
+                        onclick={() => openPreview(take)}
+                        testId={`take-preview-${take.id}`}
+                    >
+                        <Play class="size-4" aria-hidden="true" />
+                    </Button>
+                {/if}
                 <Button
                     variant="neutral"
                     size="sm"
@@ -234,6 +307,17 @@
     {/if}
 </div>
 
+<TakePreviewDialog
+    bind:open={previewOpen}
+    take={previewTarget}
+    {cut}
+    playbackUrl={previewUrl}
+    adopting={previewTarget !== null && busyTakeId === previewTarget.id}
+    {error}
+    onAdopt={adoptFromPreview}
+    onClose={handlePreviewClose}
+/>
+
 <TakeCommentDialog
     bind:open={commentDialogOpen}
     initial={commentTarget?.comment ?? ""}
diff --git a/resources/js/pages/Capture/Show.svelte b/resources/js/pages/Capture/Show.svelte
index 18212fd..586d744 100644
--- a/resources/js/pages/Capture/Show.svelte
+++ b/resources/js/pages/Capture/Show.svelte
@@ -4,6 +4,7 @@
     import { ArrowLeft } from "@lucide/svelte";
     import TextLink from "@/components/atoms/TextLink.svelte";
     import CameraRecorder from "@/components/features/capture/CameraRecorder.svelte";
+    import type CameraRecorderType from "@/components/features/capture/CameraRecorder.svelte";
     import CaptureFileFallback from "@/components/features/capture/CaptureFileFallback.svelte";
     import CutNavigator from "@/components/features/capture/CutNavigator.svelte";
     import TakeStrip from "@/components/features/capture/TakeStrip.svelte";
@@ -38,6 +39,9 @@
     const canRecord = typeof window !== "undefined" && supportsMediaRecorder();
     let cameraUnavailableReason = $state<CameraUnavailableReason | null>(null);
     const showRecorder = $derived(canRecord && cameraUnavailableReason === null);
+    // 撮影 active (recording|stopping) と recorder 参照 (preview の資源競合制御。T050 / S4)
+    let captureActive = $state(false);
+    let recorderRef = $state<CameraRecorderType | null>(null);
     // 実行時フォールバックの説明文 (reason で出し分け。静的 feature-detect 由来は
     // CaptureFileFallback 既存の説明文だけで足りるため notice なし)
     const fallbackNotice = $derived.by(() => {
@@ -180,9 +184,11 @@
 
                 {#if showRecorder}
                     <CameraRecorder
+                        bind:this={recorderRef}
                         onCaptured={(blob, mimeType, durationMs) =>
                             handleCaptured(blob, mimeType, durationMs)}
                         onCameraUnavailable={(reason) => (cameraUnavailableReason = reason)}
+                        onCaptureActiveChange={(active) => (captureActive = active)}
                     />
                 {:else}
                     {#if fallbackNotice !== null}
@@ -204,6 +210,9 @@
                     manualId={manual.id}
                     cut={selectedCut}
                     onChanged={reloadManual}
+                    {captureActive}
+                    onRequestCameraRelease={() => recorderRef?.releaseForPreview()}
+                    onCameraResume={() => void recorderRef?.resumeAfterPreview()}
                 />
             {/if}
         </section>
diff --git a/routes/web.php b/routes/web.php
index 91e054e..ef5cc3a 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -498,6 +498,8 @@
                     ->name('takes.adopt');
                 Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/downloaded', [CaptureTakeController::class, 'markDownloaded'])
                     ->name('takes.downloaded');
+                Route::get('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback', [CaptureTakeController::class, 'playback'])
+                    ->name('takes.playback');
             });
         });
 });
diff --git a/tests/Architecture/NestedRouteIdorDefenseTest.php b/tests/Architecture/NestedRouteIdorDefenseTest.php
index 9977c34..51cbb56 100644
--- a/tests/Architecture/NestedRouteIdorDefenseTest.php
+++ b/tests/Architecture/NestedRouteIdorDefenseTest.php
@@ -81,6 +81,7 @@ function nestedRouteIdorInventory(): array
         'capture.takes.destroy' => $s,
         'capture.takes.adopt' => $s,
         'capture.takes.downloaded' => $s,
+        'capture.takes.playback' => $s,
         // --- inline 親子整合 guard (authorize 前に 子∈親テナント を検査、不整合は 404) ---
         // OrganizationMemberController::resolveOrganizationMember (非 member は 404)
         'organizations.members.update' => $g,
diff --git a/tests/Feature/Capture/TakePlaybackTest.php b/tests/Feature/Capture/TakePlaybackTest.php
new file mode 100644
index 0000000..1bac597
--- /dev/null
+++ b/tests/Feature/Capture/TakePlaybackTest.php
@@ -0,0 +1,158 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\OrganizationRole;
+use App\Enums\ProjectRole;
+use App\Models\Cut;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\User;
+use App\Models\VideoManual;
+
+/*
+ * テイク単体プレビュー再生 (T050 / S1): GET .../takes/{take}/playback。
+ * ready テイクのみ 302 で S3 署名 URL へリダイレクト (Cache-Control: no-store, private)。
+ * 非 ready は 404 (状態秘匿) / 非 capture は 403 / IDOR は各 404。
+ */
+
+beforeEach(function (): void {
+    enableFakeStorage();
+});
+
+/**
+ * @return array{Organization, User, Project, VideoManual, Cut, Take}
+ */
+function takePlaybackContext(string $takeStatus = 'ready'): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create(['status' => $takeStatus]);
+
+    return [$organization, $owner, $project, $manual, $cut, $take];
+}
+
+function playbackPath(Project $project, VideoManual $manual, Cut $cut, Take $take): string
+{
+    return "/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/{$take->id}/playback";
+}
+
+test('撮影者が ready テイクを GET playback すると 302 で署名 URL へリダイレクトし no-store かつ private を返す', function (): void {
+    [, $owner, $project, $manual, $cut, $take] = takePlaybackContext();
+
+    $response = $this->actingAs($owner)->get(playbackPath($project, $manual, $cut, $take));
+
+    $response->assertStatus(302);
+    // 署名 URL は対象 take の video_path から生成される (別 take の path を使わない)
+    $location = $response->headers->get('Location');
+    expect($location)->not->toBeNull();
+    expect($location)->toContain(urlencode($take->video_path));
+
+    $cacheControl = $response->headers->get('Cache-Control');
+    expect($cacheControl)->toContain('no-store');
+    expect($cacheControl)->toContain('private');
+});
+
+test('署名 URL は別 take の path を使わない (対象 take の video_path が Location に載る)', function (): void {
+    [, $owner, $project, $manual, $cut, $take] = takePlaybackContext();
+    // 同カットに別 take を作る (混入検知)
+    $otherTake = Take::factory()->forCut($cut)->create(['status' => 'ready']);
+
+    $location = $this->actingAs($owner)
+        ->get(playbackPath($project, $manual, $cut, $take))
+        ->headers->get('Location');
+
+    expect($location)->toContain(urlencode($take->video_path));
+    expect($location)->not->toContain(urlencode($otherTake->video_path));
+});
+
+test('非 ready テイク (uploading/processing/failed) は 404 (状態秘匿)', function (string $status): void {
+    [, $owner, $project, $manual, $cut, $take] = takePlaybackContext($status);
+
+    $this->actingAs($owner)
+        ->get(playbackPath($project, $manual, $cut, $take))
+        ->assertNotFound();
+})->with(['uploading', 'processing', 'failed']);
+
+test('非 capture ユーザー (非 project member の org member) は 403', function (): void {
+    [$organization, , $project, $manual, $cut, $take] = takePlaybackContext();
+    $orgMember = attachOrganizationMember($organization);
+    $orgMember->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($orgMember)
+        ->get(playbackPath($project, $manual, $cut, $take))
+        ->assertForbidden();
+});
+
+test('team 文脈: role が別 team で付与されている間は 403 / 正しい team で付与されると 302', function (): void {
+    [$organization, , $project, $manual, $cut, $take] = takePlaybackContext();
+    // 別組織 (別 laratrust team) を用意し、そちらの team_id で Member ロールを付与する
+    [$otherOrg] = createOrganizationWithOwner('別組織');
+
+    $user = User::factory()->create();
+    $organization->users()->attach($user);
+    attachProjectMember($project, $user, ProjectRole::Member);
+    $user->forceFill(['current_organization_id' => $organization->id])->save();
+
+    // 誤った team 文脈 (otherOrg の team_id) でロールを付与 → 対象 org では role null = 403
+    $user->addRole(OrganizationRole::Member->value, $otherOrg->laratrust_team_id);
+    $this->actingAs($user)
+        ->get(playbackPath($project, $manual, $cut, $take))
+        ->assertForbidden();
+
+    // 正しい team 文脈で付与 → 302 (権限判定が team scope で効く)
+    $user->addRole(OrganizationRole::Member->value, $organization->laratrust_team_id);
+    $this->actingAs($user->fresh())
+        ->get(playbackPath($project, $manual, $cut, $take))
+        ->assertStatus(302);
+});
+
+test('IDOR: project mismatch は 404 (認可より前)', function (): void {
+    [$organization, $owner, , $manual, $cut, $take] = takePlaybackContext();
+    $otherProject = Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)
+        ->get(playbackPath($otherProject, $manual, $cut, $take))
+        ->assertNotFound();
+});
+
+test('IDOR: manual mismatch は 404', function (): void {
+    [, $owner, $project, , $cut, $take] = takePlaybackContext();
+    $otherManual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+
+    $this->actingAs($owner)
+        ->get(playbackPath($project, $otherManual, $cut, $take))
+        ->assertNotFound();
+});
+
+test('IDOR: cut mismatch は 404', function (): void {
+    [, $owner, $project, $manual, , $take] = takePlaybackContext();
+    $otherCut = Cut::factory()->forManual($manual)->create();
+
+    $this->actingAs($owner)
+        ->get(playbackPath($project, $manual, $otherCut, $take))
+        ->assertNotFound();
+});
+
+test('IDOR: take mismatch (別 cut 所属の take を別 cut の URL で) は 404', function (): void {
+    [, $owner, $project, $manual, $cut] = takePlaybackContext();
+    $cutB = Cut::factory()->forManual($manual)->create();
+    $takeB = Take::factory()->forCut($cutB)->create(['status' => 'ready']);
+
+    $this->actingAs($owner)
+        ->get(playbackPath($project, $manual, $cut, $takeB))
+        ->assertNotFound();
+});
+
+test('IDOR: cross-org は 404', function (): void {
+    [, , $project, $manual, $cut, $take] = takePlaybackContext();
+    [$otherOrg, $otherOwner] = createOrganizationWithOwner('別組織');
+    $otherOwner->forceFill(['current_organization_id' => $otherOrg->id])->save();
+
+    $this->actingAs($otherOwner)
+        ->get(playbackPath($project, $manual, $cut, $take))
+        ->assertNotFound();
+});
diff --git a/tests/js/components/features/capture/CameraRecorder.test.ts b/tests/js/components/features/capture/CameraRecorder.test.ts
index f9d1384..1d75342 100644
--- a/tests/js/components/features/capture/CameraRecorder.test.ts
+++ b/tests/js/components/features/capture/CameraRecorder.test.ts
@@ -16,9 +16,13 @@ class FakeMediaRecorder {
     }
     static shouldThrowOnConstruct = false;
     static shouldThrowOnStart = false;
+    /** false のとき stop() は onstop を自動発火せず、テストが手動で駆動する (stopping 観測用) */
+    static autoStop = true;
 
     ondataavailable: ((event: { data: Blob }) => void) | null = null;
     onstop: (() => void) | null = null;
+    onerror: (() => void) | null = null;
+    stopCalls = 0;
 
     constructor(
         public stream: unknown,
@@ -37,26 +41,53 @@ class FakeMediaRecorder {
     }
 
     stop(): void {
+        this.stopCalls += 1;
+        if (!FakeMediaRecorder.autoStop) return; // 手動駆動モード
+        this.ondataavailable?.({ data: new Blob(["frame"], { type: this.options.mimeType }) });
+        this.onstop?.();
+    }
+
+    /** 手動モードで onstop を駆動する (blob 生成 → onstop) */
+    fireStop(): void {
         this.ondataavailable?.({ data: new Blob(["frame"], { type: this.options.mimeType }) });
         this.onstop?.();
     }
 }
 
+/** 直近に構築された FakeMediaRecorder を捕捉する (onerror/onstop 手動駆動用) */
+let lastRecorder: FakeMediaRecorder | null = null;
+class TrackingFakeMediaRecorder extends FakeMediaRecorder {
+    constructor(stream: unknown, options: { mimeType: string }) {
+        super(stream, options);
+        lastRecorder = this;
+    }
+}
+
 const getUserMediaMock = vi.fn<() => Promise<MediaStream>>();
 
 /** getTracks() が stop spy 付き track を返す fake stream (解放検証用) */
-function fakeStream(): { stream: MediaStream; stop: ReturnType<typeof vi.fn> } {
+function fakeStream(): {
+    stream: MediaStream;
+    stop: ReturnType<typeof vi.fn>;
+    track: { stop: ReturnType<typeof vi.fn>; onended: (() => void) | null };
+} {
     const stop = vi.fn();
-    const stream = { getTracks: () => [{ stop }] } as unknown as MediaStream;
-    return { stream, stop };
+    const track: { stop: ReturnType<typeof vi.fn>; onended: (() => void) | null } = {
+        stop,
+        onended: null,
+    };
+    const stream = { getTracks: () => [track] } as unknown as MediaStream;
+    return { stream, stop, track };
 }
 
 beforeEach(() => {
     FakeMediaRecorder.supportedTypes = ["video/webm"];
     FakeMediaRecorder.shouldThrowOnConstruct = false;
     FakeMediaRecorder.shouldThrowOnStart = false;
+    FakeMediaRecorder.autoStop = true;
+    lastRecorder = null;
     getUserMediaMock.mockReset();
-    vi.stubGlobal("MediaRecorder", FakeMediaRecorder);
+    vi.stubGlobal("MediaRecorder", TrackingFakeMediaRecorder);
     vi.stubGlobal("navigator", {
         ...navigator,
         mediaDevices: { getUserMedia: getUserMediaMock },
@@ -210,4 +241,159 @@ describe("CameraRecorder", () => {
             expect(onCameraUnavailable).toHaveBeenCalledWith("permission_denied");
         });
     });
+
+    // ---- T050 / S4: 撮影 active phase マシン + preview 解放/復帰 ----
+
+    it("onCaptureActiveChange は start で true / idle 到達で false を通知する", async () => {
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+        const onCaptureActiveChange = vi.fn();
+
+        render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), onCaptureActiveChange },
+        });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenCalledWith(true));
+
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenCalledWith(false));
+    });
+
+    it("recording→stopping では false を発火せず、idle 到達で初めて false (stopping 中は active 維持)", async () => {
+        FakeMediaRecorder.autoStop = false; // stop() で onstop を自動発火させず stopping を観測する
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+        const onCaptureActiveChange = vi.fn();
+
+        render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), onCaptureActiveChange },
+        });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenLastCalledWith(true));
+
+        // 停止要求 → stopping (まだ idle でない = false を出さない)
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        expect(lastRecorder?.stopCalls).toBe(1);
+        expect(onCaptureActiveChange).not.toHaveBeenCalledWith(false);
+
+        // onstop 到達で初めて idle → false
+        lastRecorder?.fireStop();
+        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenLastCalledWith(false));
+    });
+
+    it("track.onended は safeStop→recorder.stop() を呼び、onstop(idle) でのみ false を出す", async () => {
+        FakeMediaRecorder.autoStop = false;
+        const { stream, track } = fakeStream();
+        getUserMediaMock.mockResolvedValue(stream);
+        const onCaptureActiveChange = vi.fn();
+
+        render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), onCaptureActiveChange },
+        });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(track.onended).not.toBeNull());
+
+        track.onended?.(); // デバイス側で track 終了 → safeStop
+        expect(lastRecorder?.stopCalls).toBe(1);
+        expect(onCaptureActiveChange).not.toHaveBeenCalledWith(false); // stopping 中はまだ
+
+        lastRecorder?.fireStop();
+        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenLastCalledWith(false));
+    });
+
+    it("onstop の onCaptured が reject しても idle に戻り、未処理 rejection にせずローカルエラー表示する", async () => {
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+        const onCaptured = vi.fn().mockRejectedValue(new Error("upload failed"));
+        const onCaptureActiveChange = vi.fn();
+
+        render(CameraRecorder, {
+            props: { onCaptured, onCameraUnavailable: vi.fn(), onCaptureActiveChange },
+        });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+
+        // idle へ戻り録画開始ボタンが復帰 (撮影状態が解除される)
+        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
+        expect(onCaptureActiveChange).toHaveBeenLastCalledWith(false);
+        expect(screen.getByRole("alert")).toHaveTextContent("撮影データの処理に失敗しました");
+    });
+
+    it("safeStop 多重呼び出しで recorder.stop() が重複しない (phase ガード)", async () => {
+        FakeMediaRecorder.autoStop = false;
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+
+        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() } });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
+
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        expect(lastRecorder?.stopCalls).toBe(1); // stopping 中の 2 度目は no-op
+    });
+
+    it("releaseForPreview: 待機中 (idle かつ stream あり) は stream を解放し、resumeAfterPreview で再取得", async () => {
+        const first = fakeStream();
+        const second = fakeStream();
+        getUserMediaMock.mockResolvedValueOnce(first.stream).mockResolvedValueOnce(second.stream);
+
+        const { component } = render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() },
+        });
+        // 待機 stream を得るため一度録画開始→停止で idle かつ stream 保持状態を作る
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
+
+        // preview を開く: idle かつ stream 保持 → 解放される
+        (component as unknown as { releaseForPreview: () => void }).releaseForPreview();
+        expect(first.stop).toHaveBeenCalled();
+
+        // preview close: 解放前 live だったので再取得する
+        await (component as unknown as { resumeAfterPreview: () => Promise<void> }).resumeAfterPreview();
+        expect(getUserMediaMock).toHaveBeenCalledTimes(2);
+    });
+
+    it("releaseForPreview: 録画中は no-op (録画データを暗黙終了しない)", async () => {
+        FakeMediaRecorder.autoStop = false;
+        const { stream, stop } = fakeStream();
+        getUserMediaMock.mockResolvedValue(stream);
+
+        const { component } = render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() },
+        });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
+
+        (component as unknown as { releaseForPreview: () => void }).releaseForPreview();
+        expect(stop).not.toHaveBeenCalled(); // 解放しない
+        expect(lastRecorder?.stopCalls).toBe(0); // 録画終了処理も呼ばない
+    });
+
+    it("resumeAfterPreview: 多重呼び出しで getUserMedia が二重発火しない / 失敗後は再試行できる", async () => {
+        const first = fakeStream();
+        getUserMediaMock.mockResolvedValueOnce(first.stream); // 初回録画で live に
+
+        const { component } = render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() },
+        });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
+
+        const ref = component as unknown as { releaseForPreview: () => void; resumeAfterPreview: () => Promise<void> };
+        ref.releaseForPreview();
+        expect(getUserMediaMock).toHaveBeenCalledTimes(1); // 録画開始の 1 回のみ
+
+        // 復帰取得が一時失敗 → wasActiveBeforePreview を保持 (再試行可能)
+        getUserMediaMock.mockRejectedValueOnce(new DOMException("busy", "NotReadableError"));
+        // 多重 close/open を模して 2 連続呼び出し (再入ガードで getUserMedia は 1 回)
+        await Promise.all([ref.resumeAfterPreview(), ref.resumeAfterPreview()]);
+        expect(getUserMediaMock).toHaveBeenCalledTimes(2);
+
+        // 再試行が成功する (wasActiveBeforePreview が false 化していない)
+        getUserMediaMock.mockResolvedValueOnce(fakeStream().stream);
+        await ref.resumeAfterPreview();
+        expect(getUserMediaMock).toHaveBeenCalledTimes(3);
+    });
 });
diff --git a/tests/js/components/features/capture/TakePreviewDialog.test.ts b/tests/js/components/features/capture/TakePreviewDialog.test.ts
new file mode 100644
index 0000000..987eb4a
--- /dev/null
+++ b/tests/js/components/features/capture/TakePreviewDialog.test.ts
@@ -0,0 +1,229 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+import TakePreviewDialog from "@/components/features/capture/TakePreviewDialog.svelte";
+import type { CaptureCut, CaptureTake } from "@/types/capture";
+
+/*
+ * TakePreviewDialog: テイク生映像を native <video controls> で再生し、
+ * cut 固定字幕を overlay で重ねる。字幕トグルと採用ボタンを同居させる。
+ * close / 採用成功 / take 差し替えで video を完全 teardown する (資源解放)。
+ */
+
+function makeTake(overrides: Partial<CaptureTake> = {}): CaptureTake {
+    return {
+        id: 10,
+        client_take_id: "01ARZ3NDEKTSV4RRFFQ69G5FAV",
+        status: "ready",
+        size_bytes: 1024 * 1024,
+        duration_ms: 4000,
+        comment: null,
+        captured_at: null,
+        sort_order: 0,
+        downloaded: false,
+        playback_url: null,
+        download_ack_token: null,
+        ...overrides,
+    };
+}
+
+function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
+    return {
+        id: 3,
+        type: "step",
+        parent_cut_id: null,
+        scene: "作業台を準備する",
+        shot_type: "hiki",
+        shooting_point: null,
+        narration: "作業台の準備を行います",
+        subtitle_primary: "STEP 1",
+        subtitle_secondary: "作業台を準備する",
+        adopted_take_id: null,
+        takes: [],
+        ...overrides,
+    };
+}
+
+beforeEach(() => {
+    // jsdom は HTMLMediaElement の再生系メソッドを未実装
+    vi.spyOn(HTMLMediaElement.prototype, "play").mockResolvedValue(undefined);
+    vi.spyOn(HTMLMediaElement.prototype, "pause").mockImplementation(() => undefined);
+    vi.spyOn(HTMLMediaElement.prototype, "load").mockImplementation(() => undefined);
+});
+
+afterEach(() => {
+    cleanup();
+    vi.restoreAllMocks();
+});
+
+describe("TakePreviewDialog", () => {
+    it("open + take で video を表示し src に playbackUrl を使う", async () => {
+        render(TakePreviewDialog, {
+            open: true,
+            take: makeTake(),
+            cut: makeCut(),
+            playbackUrl: "/app/projects/1/manuals/2/cuts/3/takes/10/playback",
+            adopting: false,
+            error: null,
+            onAdopt: vi.fn(),
+            onClose: vi.fn(),
+        });
+
+        const video = await screen.findByTestId("take-preview-video");
+        expect(video).toHaveAttribute("src", "/app/projects/1/manuals/2/cuts/3/takes/10/playback");
+    });
+
+    it("初回 open 後も video の src が残る (誤 teardown しない)", async () => {
+        render(TakePreviewDialog, {
+            open: true,
+            take: makeTake(),
+            cut: makeCut(),
+            playbackUrl: "/signed/take-10",
+            adopting: false,
+            error: null,
+            onAdopt: vi.fn(),
+            onClose: vi.fn(),
+        });
+
+        const video = await screen.findByTestId("take-preview-video");
+        // effect flush 後も src が残っている (初回 mount で cleanup を走らせない)
+        await waitFor(() => expect(video).toHaveAttribute("src", "/signed/take-10"));
+    });
+
+    it("字幕は初期 ON で overlay を表示し、トグルで非表示になる", async () => {
+        render(TakePreviewDialog, {
+            open: true,
+            take: makeTake(),
+            cut: makeCut(),
+            playbackUrl: "/signed",
+            adopting: false,
+            error: null,
+            onAdopt: vi.fn(),
+            onClose: vi.fn(),
+        });
+
+        expect(screen.getByTestId("take-preview-subtitle-primary")).toHaveTextContent("STEP 1");
+        expect(screen.getByTestId("take-preview-subtitle-secondary")).toHaveTextContent(
+            "作業台を準備する",
+        );
+
+        await fireEvent.click(screen.getByTestId("take-preview-subtitle-toggle"));
+
+        expect(screen.queryByTestId("take-preview-subtitle-primary")).not.toBeInTheDocument();
+        expect(screen.queryByTestId("take-preview-subtitle-secondary")).not.toBeInTheDocument();
+    });
+
+    it("字幕 overlay は aria-live=off (読み上げ事故防止)", async () => {
+        render(TakePreviewDialog, {
+            open: true,
+            take: makeTake(),
+            cut: makeCut(),
+            playbackUrl: "/signed",
+            adopting: false,
+            error: null,
+            onAdopt: vi.fn(),
+            onClose: vi.fn(),
+        });
+
+        expect(screen.getByTestId("take-preview-subtitle-secondary")).toHaveAttribute(
+            "aria-live",
+            "off",
+        );
+    });
+
+    it("採用ボタンで onAdopt を呼ぶ", async () => {
+        const onAdopt = vi.fn();
+        render(TakePreviewDialog, {
+            open: true,
+            take: makeTake(),
+            cut: makeCut(),
+            playbackUrl: "/signed",
+            adopting: false,
+            error: null,
+            onAdopt,
+            onClose: vi.fn(),
+        });
+
+        await fireEvent.click(screen.getByTestId("take-preview-adopt"));
+        expect(onAdopt).toHaveBeenCalledTimes(1);
+    });
+
+    it("採用エラーを role=alert で表示する", () => {
+        render(TakePreviewDialog, {
+            open: true,
+            take: makeTake(),
+            cut: makeCut(),
+            playbackUrl: "/signed",
+            adopting: false,
+            error: "採用に失敗しました。",
+            onAdopt: vi.fn(),
+            onClose: vi.fn(),
+        });
+
+        expect(screen.getByTestId("take-preview-error")).toHaveTextContent("採用に失敗しました。");
+    });
+
+    it("close 遷移 (open true→false) で video を teardown し onClose をちょうど 1 回呼ぶ", async () => {
+        const onClose = vi.fn();
+        const { rerender } = render(TakePreviewDialog, {
+            open: true,
+            take: makeTake(),
+            cut: makeCut(),
+            playbackUrl: "/signed",
+            adopting: false,
+            error: null,
+            onAdopt: vi.fn(),
+            onClose,
+        });
+
+        await screen.findByTestId("take-preview-video");
+        const pause = vi.spyOn(HTMLMediaElement.prototype, "pause");
+
+        await rerender({ open: false });
+
+        await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
+        // 要素が DOM から外れ、cleanup で pause される (資源解放)
+        expect(screen.queryByTestId("take-preview-video")).not.toBeInTheDocument();
+        expect(pause).toHaveBeenCalled();
+    });
+
+    it("mount 時 open=false では onClose を発火させない", async () => {
+        const onClose = vi.fn();
+        render(TakePreviewDialog, {
+            open: false,
+            take: null,
+            cut: makeCut(),
+            playbackUrl: null,
+            adopting: false,
+            error: null,
+            onAdopt: vi.fn(),
+            onClose,
+        });
+
+        await waitFor(() => expect(onClose).not.toHaveBeenCalled());
+    });
+
+    it("take 差し替えで新 take の src に更新される ({#key} で要素再生成)", async () => {
+        const { rerender } = render(TakePreviewDialog, {
+            open: true,
+            take: makeTake({ id: 10 }),
+            cut: makeCut(),
+            playbackUrl: "/signed/take-10",
+            adopting: false,
+            error: null,
+            onAdopt: vi.fn(),
+            onClose: vi.fn(),
+        });
+
+        const first = await screen.findByTestId("take-preview-video");
+        expect(first).toHaveAttribute("src", "/signed/take-10");
+
+        await rerender({ take: makeTake({ id: 20 }), playbackUrl: "/signed/take-20" });
+
+        await waitFor(() =>
+            expect(screen.getByTestId("take-preview-video")).toHaveAttribute(
+                "src",
+                "/signed/take-20",
+            ),
+        );
+    });
+});
diff --git a/tests/js/components/features/capture/TakeStrip.test.ts b/tests/js/components/features/capture/TakeStrip.test.ts
index 59873b4..e699961 100644
--- a/tests/js/components/features/capture/TakeStrip.test.ts
+++ b/tests/js/components/features/capture/TakeStrip.test.ts
@@ -55,12 +55,17 @@ beforeEach(() => {
     vi.stubGlobal("fetch", fetchMock);
     vi.stubGlobal("open", vi.fn());
     document.cookie = "XSRF-TOKEN=test-token";
+    // jsdom は HTMLMediaElement の再生系メソッドを未実装 (preview dialog の video 用)
+    vi.spyOn(HTMLMediaElement.prototype, "play").mockResolvedValue(undefined);
+    vi.spyOn(HTMLMediaElement.prototype, "pause").mockImplementation(() => undefined);
+    vi.spyOn(HTMLMediaElement.prototype, "load").mockImplementation(() => undefined);
 });
 
 afterEach(() => {
     cleanup();
     fetchMock.mockReset();
     vi.unstubAllGlobals();
+    vi.restoreAllMocks();
 });
 
 describe("TakeStrip", () => {
@@ -189,6 +194,118 @@ describe("TakeStrip", () => {
         });
     });
 
+    it("ready テイクに再生ボタンを表示し、押下で preview dialog が開く (video src = playback URL)", async () => {
+        render(TakeStrip, {
+            projectId: 1,
+            manualId: 2,
+            cut: makeCut([makeTake()]),
+            onChanged: vi.fn(),
+        });
+
+        await fireEvent.click(screen.getByTestId("take-preview-10"));
+
+        const video = await screen.findByTestId("take-preview-video");
+        expect(video).toHaveAttribute("src", "/app/projects/1/manuals/2/cuts/3/takes/10/playback");
+        expect(window.open).not.toHaveBeenCalled(); // preview は video element (DL の window.open とは別)
+    });
+
+    it("撮影 active 中は再生ボタン押下で dialog を開かずエラー表示する", async () => {
+        render(TakeStrip, {
+            projectId: 1,
+            manualId: 2,
+            cut: makeCut([makeTake()]),
+            onChanged: vi.fn(),
+            captureActive: true,
+            onRequestCameraRelease: vi.fn(),
+            onCameraResume: vi.fn(),
+        });
+
+        await fireEvent.click(screen.getByTestId("take-preview-10"));
+
+        expect(screen.queryByTestId("take-preview-video")).not.toBeInTheDocument();
+        expect(screen.getByTestId("take-strip-error").textContent).toContain(
+            "撮影中はプレビューを再生できません",
+        );
+    });
+
+    it("preview を開くと onRequestCameraRelease を呼ぶ (撮影待機 stream 解放)", async () => {
+        const onRequestCameraRelease = vi.fn();
+        render(TakeStrip, {
+            projectId: 1,
+            manualId: 2,
+            cut: makeCut([makeTake()]),
+            onChanged: vi.fn(),
+            captureActive: false,
+            onRequestCameraRelease,
+            onCameraResume: vi.fn(),
+        });
+
+        await fireEvent.click(screen.getByTestId("take-preview-10"));
+        expect(onRequestCameraRelease).toHaveBeenCalledTimes(1);
+    });
+
+    it("preview の閉じるボタンで dialog が閉じ onCameraResume がちょうど 1 回呼ばれる", async () => {
+        const onCameraResume = vi.fn();
+        render(TakeStrip, {
+            projectId: 1,
+            manualId: 2,
+            cut: makeCut([makeTake()]),
+            onChanged: vi.fn(),
+            captureActive: false,
+            onRequestCameraRelease: vi.fn(),
+            onCameraResume,
+        });
+
+        await fireEvent.click(screen.getByTestId("take-preview-10"));
+        await screen.findByTestId("take-preview-video");
+        await fireEvent.click(screen.getByTestId("take-preview-close"));
+
+        await waitFor(() =>
+            expect(screen.queryByTestId("take-preview-video")).not.toBeInTheDocument(),
+        );
+        expect(onCameraResume).toHaveBeenCalledTimes(1);
+    });
+
+    it("preview から採用すると POST .../adopt が飛び、成功で dialog が閉じ onCameraResume が 1 回", async () => {
+        fetchMock.mockResolvedValueOnce(jsonResponse(200, {}));
+        const onChanged = vi.fn();
+        const onCameraResume = vi.fn();
+        render(TakeStrip, {
+            projectId: 1,
+            manualId: 2,
+            cut: makeCut([makeTake()]),
+            onChanged,
+            captureActive: false,
+            onRequestCameraRelease: vi.fn(),
+            onCameraResume,
+        });
+
+        await fireEvent.click(screen.getByTestId("take-preview-10"));
+        await screen.findByTestId("take-preview-video");
+        await fireEvent.click(screen.getByTestId("take-preview-adopt"));
+
+        await waitFor(() => expect(onChanged).toHaveBeenCalled());
+        expect(fetchMock.mock.calls[0][0]).toBe("/app/projects/1/manuals/2/cuts/3/takes/10/adopt");
+        await waitFor(() =>
+            expect(screen.queryByTestId("take-preview-video")).not.toBeInTheDocument(),
+        );
+        expect(onCameraResume).toHaveBeenCalledTimes(1);
+    });
+
+    it("非 ready テイクには再生ボタンを出さず、理由の補助文言を表示する", () => {
+        render(TakeStrip, {
+            projectId: 1,
+            manualId: 2,
+            cut: makeCut([makeTake({ status: "processing" })]),
+            onChanged: vi.fn(),
+        });
+
+        expect(screen.queryByTestId("take-preview-10")).not.toBeInTheDocument();
+        expect(screen.getByTestId("take-not-ready-10")).toHaveTextContent(
+            "アップロード処理中は再生できません",
+        );
+    });
+
     it("採用中バッジと DL 済みバッジを表示する", () => {
         const take = makeTake({ downloaded: true });
         render(TakeStrip, {

```
