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
 * 1. {project} ∈ current org (project.in-current-org middleware + resolveOrganizationProject)
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
