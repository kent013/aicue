【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。「思考ゼロ・編集ゼロ」。v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告 2. PHPStan エラーの widen・baseline 化 3. dev DB 破壊操作 4. `response()->json()` 直書き（DTO/JsonResource/Inertia。仕様固定 endpoint のみ例外） 5. Prism 直呼び 6. prompt 直書き 7. 操作系 POST での `redirect()->intended()` 8. 必須条件未充足でボタン disabled（押下時エラー表示）

【セキュリティ不変条件】子は親に属する（nested 不整合は認可より前に 404、NestedRouteIdorDefenseTest 登録必須）/ cross-org 不可 / tenant キー不信 / 権限判定は laratrust_team_id 明示。

【思考原則】まず仮説。データに真摯。先人の知恵（Laravel/Svelte 既存解）。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】コマンド実行・ファイル書き込みは一切行わず、提供テキストの分析に集中。ファイル読み込みは許可。

---

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリの詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5(runes) + Inertia.js + TypeScript / PHPStan level 10 / Pest(RefreshDatabase グローバル・--parallel) / DTO + JsonResource / Laratrust RBAC。

【レビュー観点】
1. コードの正確性（ロジック・エッジケース・null 安全）
2. 既存コードとの整合性（命名・パターン・API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（各施策に Pest/vitest、RefreshDatabase グローバル準拠）
5. DTO/JsonResource パターン遵守（302 リダイレクトの扱いの妥当性を含む）
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TS 型定義・Resource・テストが変更対象に含まれるか）
9. セキュリティ（認可・IDOR・入力バリデーション・OWASP・署名 URL の扱い）
10. DESIGN.md 準拠（token 経由、hex 直書き回避）
11. Atomic Design 準拠（features/capture 配置、単方向 import、Lucide アイコン）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] 分類、Critical/Warning に修正案
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語

なお本設計は概念設計フェーズで gpt-5.4 レビューを 4 ラウンド行い APPROVED 済み（録画排他契約・video teardown・no-store/private・IDOR 各階層・署名 URL⇔対象 take 対応の固定などを反映済み）。詳細設計レベルの正確性・整合性を重点的に見てください。

---

## 詳細設計書

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

    // 再生可能条件: ready のみ (uploading/processing/failed は 404)
    if ($take->status !== TakeStatus::Ready) {
        abort(404);
    }

    // video_path は @property string (非 null カラム) = 型絞り込み問題なし
    return redirect()
        ->away($storage->temporaryPlaybackUrl($take->video_path))
        ->withHeaders(['Cache-Control' => 'no-store, private']);
}
```

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
  - `let subtitlesOn = $state(true)`（初期 ON）。トグルボタン（Lucide `Captions` / `CaptionsOff`）で切替。`{#if subtitlesOn}` で overlay を出し分け。
- **採用ボタン**: dialog footer に「このテイクを採用する」（`onAdopt`）。`loading={adopting}`。
- **video teardown（単一関数）**: `teardownVideo()` = `video.pause(); video.removeAttribute("src"); video.load();`
  - dialog が閉じる時（`open` が false へ / onClose）と、**採用成功で親が閉じる時**の両経路で必ず呼ぶ（`$effect` で `open===false` を検知して teardown、テスト容易化のため単一関数に集約）。
- **subtitlesOn の初期化**: `$effect` で `open` が true になった時に `subtitlesOn = true` にリセット（再オープン時の状態持ち越し防止）。

### PHPStan 適合チェック
- N/A（Svelte）。`pnpm typecheck`（svelte-check）で props 型を担保。

### テスト計画（S5）
- 再生ボタン→dialog open で video 表示（`src` が playbackUrl）。
- 字幕トグルで overlay 表示/非表示。
- 採用ボタンで `onAdopt` 呼び出し。
- dialog close で `teardownVideo`（pause + src 除去 + load）。

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
    recordingInProgress: boolean;        // 追加: 録画中なら preview を開かずエラー
    onRequestCameraRelease: () => void;  // 追加: 録画待機中の open で stream 解放
    onCameraResume: () => void;          // 追加: dialog close で stream 復帰
}
```

### 主要挙動
- **再生ボタン**: `take.status === "ready"` のテイクにのみ Lucide `Play` の ghost ボタンを追加（採用ボタンの隣）。
  - `status !== "ready"` は再生不可だが**ボタンを disabled にしない**（禁止事項8）。処理中テイクには再生ボタン自体を出さない（存在しないので押下不可）。※ 採用ボタンは既存どおり全テイクに存在。
- **openPreview(take)**:
  ```ts
  function openPreview(take: CaptureTake): void {
      error = null;
      if (recordingInProgress) {
          error = "録画中はプレビューを再生できません。録画を停止してからお試しください。";
          return; // 押下時エラー (dialog を開かない)
      }
      previewTarget = take;
      onRequestCameraRelease();   // 録画待機中の live stream を解放
      previewOpen = true;
  }
  ```
- **previewUrl**: `$derived(previewTarget ? takeUrl(previewTarget, "/playback") : null)`（既存 `takeUrl` ヘルパ踏襲）。
- **採用（dialog から）**: 既存 `adopt(take)` を呼ぶ。成功時（`run()` の onChanged 経由）に dialog を閉じる。
  - 実装: `adoptFromPreview()` = `await adopt(previewTarget)` の成功後に `previewOpen = false`。`run()` は失敗時 `error` を設定するので、それを dialog の error に流用。busy 状態は `busyTakeId === previewTarget.id`。
- **dialog close**: `previewOpen = false` + `onCameraResume()`（録画復帰）。

### PHPStan 適合チェック
- N/A（Svelte）。`pnpm typecheck`。

### テスト計画（S5）
- ready テイクに再生ボタン表示、押下で dialog open。
- 録画中（`recordingInProgress=true`）押下で dialog を開かずエラー表示。
- `window.open` を呼ばない（preview は video element）。DL ボタンの window.open は据え置き（別テスト）。

### リスク
- 既存 DL ボタン（`downloadAndAck` の window.open）と併存。preview と DL は用途が別（preview=確認、DL=端末保存）。混同しないよう aria-label / アイコンを分離（Play vs Download）。

---

## S4: 録画排他 / 資源解放の結合

### 変更箇所
- `resources/js/components/features/capture/CameraRecorder.svelte`: 停止/復帰 API + 録画状態通知。
- `resources/js/pages/Capture/Show.svelte`: recorder 参照保持 + TakeStrip への配線。

### 波及変更
- TypeScript 型定義: CameraRecorder Props に `onRecordingChange` 追加。TakeStrip Props（S3）へ配線。
- テストファイル: vitest（S5）。

### CameraRecorder の変更
1. **録画状態通知**: Props に `onRecordingChange?: (recording: boolean) => void` を追加。`recording` の変化点（start 成功時 true / `onstop` で false）で呼ぶ。
2. **プレビュー用の解放/復帰 API**（component export、Svelte 5 runes の `export function`）:
   ```ts
   // preview を開く間に呼ばれる。録画中は no-op (録画データを守る = 暗黙終了しない)
   export function releaseForPreview(): void {
       if (recording) return;           // 録画中は解放しない (contract c)
       wasActiveBeforePreview = stream !== null; // 復帰要否を記録
       releaseCamera();                 // 既存: tracks.stop() + stream=null
   }
   // preview close 後に呼ばれる。解放前に live だった時のみ再取得
   export async function resumeAfterPreview(): Promise<void> {
       if (!wasActiveBeforePreview || recording) return;
       wasActiveBeforePreview = false;
       await acquirePreviewStream();     // getUserMedia → video.srcObject (既存ロジックを関数抽出)
   }
   ```
   - `acquirePreviewStream()` は現行 `startRecording` 内の getUserMedia + `video.srcObject` 設定部分を抽出した private 関数（録画開始とプレビュー復帰で共用。エラー時は既存の classify → onCameraUnavailable / transient error 表示を踏襲）。
   - 録画中に `releaseForPreview` が no-op である点が **contract (c)「録画データを暗黙に終了・破棄しない」** の要。加えて TakeStrip 側（S3）で録画中は preview を開かない（contract (a)）ので二重防御。

### Capture/Show の変更
```ts
let recording = $state(false);
let recorderRef = $state<ReturnType<typeof CameraRecorder> | null>(null); // bind:this

// CameraRecorder に onRecordingChange と bind:this を配線
// <CameraRecorder bind:this={recorderRef} onRecordingChange={(r) => (recording = r)} ... />

// TakeStrip へ
// recordingInProgress={recording}
// onRequestCameraRelease={() => recorderRef?.releaseForPreview()}
// onCameraResume={() => void recorderRef?.resumeAfterPreview()}
```
- **fallback 経路**（`showRecorder === false` = CaptureFileFallback）: camera stream が無いため `recording=false`、`recorderRef=null`。TakeStrip の `onRequestCameraRelease` / `onCameraResume` は optional chaining で no-op。preview は常に開ける（資源競合が無い）。

### PHPStan 適合チェック
- N/A（Svelte）。`pnpm typecheck`。

### テスト計画（S5）
- 録画待機中 open → `releaseForPreview` で stream 解放、close → `resumeAfterPreview` で再取得。
- 録画中 open → `releaseForPreview` は no-op（録画終了/破棄処理を呼ばない）。
- `onRecordingChange` が start/stop で正しく通知。

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
- [ ] IDOR: project mismatch / manual mismatch / cut mismatch → **各 404**（認可より前）。cross-org → 404。
- [ ] （Architecture）`NestedRouteIdorDefenseTest` が `capture.takes.playback` を inventory 分類済みで green。

### vitest（`tests/js/**`、TakePreviewDialog / TakeStrip / CameraRecorder）
- [ ] TakeStrip: ready テイクに再生ボタン、押下で dialog open（video の src = playback URL）。
- [ ] TakeStrip: `recordingInProgress=true` で押下 → dialog を開かずエラー表示。
- [ ] TakeStrip: preview 操作で `window.open` を呼ばない。
- [ ] TakePreviewDialog: 字幕トグルで overlay 表示/非表示（subtitle_primary/secondary）。
- [ ] TakePreviewDialog: dialog close 経路・採用成功経路の**両方**で `teardownVideo`（pause + src 除去 + load）が呼ばれる。
- [ ] S4 結合: 録画待機中 open → `releaseForPreview` 呼び出し / close → `resumeAfterPreview` 呼び出し / 録画中 open → release は no-op（録画終了処理を呼ばない）。

### 実装順（テストファースト。Codex W2）
1. 失敗する Feature/vitest を先に追加（fail 確認）。
2. `NestedRouteIdorDefenseTest` inventory 更新。
3. 実装（S1→S4）。
4. `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` を全 green。

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


---

## 関連する現行コード（抜粋）

### app/Http/Controllers/Capture/CaptureTakeController.php（既存メソッド群。playback を追加する対象）
```php
class CaptureTakeController extends Controller
{
    use ResolvesCurrentOrganization;

    public function adopt(Request $request, Project $project, VideoManual $manual, Cut $cut, Take $take, CaptureTakeService $takes): CaptureCutResource {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('adopt', $take);
        $adoptedCut = $takes->adopt($project, $manual, $cut, $take);
        return CaptureCutResource::make(CaptureCutData::fromCut($adoptedCut));
    }
    // update/destroy/markDownloaded も同様に resolveOrganizationProject → Gate::authorize パターン
}
```

### app/Http/Controllers/Projects/ManualRenderController.php::playback（参照モデル。302 パターン）
```php
public function playback(Request $request, Project $project, VideoManual $manual, RenderJob $renderJob, RenderObjectStorage $storage): RedirectResponse {
    $organization = $this->resolveCurrentOrganization($request);
    $this->resolveOrganizationProject($organization, $project);
    if ($renderJob->video_manual_id !== $manual->id) { abort(404); }
    Gate::authorize('render', $manual);
    if ($renderJob->kind !== RenderKind::Preview || $renderJob->status !== JobStatus::Succeeded || $renderJob->output_path === null || ! $this->isLatestSucceededPreview($manual, $renderJob)) { abort(404); }
    return redirect()->away($storage->temporaryPlaybackUrl($renderJob->output_path));
}
```
※ 既存 render playback は Cache-Control を付けていない（本設計で take playback には no-store,private を付与する）。

### app/Policies/TakePolicy.php（全 ability を ProjectPolicy::capture へ委譲）
```php
class TakePolicy {
    public function __construct(private readonly ProjectPolicy $projectPolicy) {}
    public function update(User $user, Take $take): bool { return $this->captureVia($user, $take); }
    public function adopt(User $user, Take $take): bool { return $this->captureVia($user, $take); }
    public function markDownloaded(User $user, Take $take): bool { return $this->captureVia($user, $take); }
    private function captureVia(User $user, Take $take): bool {
        $project = $take->cut?->videoManual?->project;
        return $project !== null && $this->projectPolicy->capture($user, $project);
    }
}
```

### app/Services/Capture/TakeObjectStorage.php（署名 URL。再利用）
```php
public function temporaryPlaybackUrl(string $path): string {
    return Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes')));
}
```

### app/Models/Take.php（プロパティ抜粋）
```php
// @property string $video_path      (非 null)
// @property string|null $thumbnail_path
// @property TakeStatus $status      (Uploading/Processing/Ready/Failed)
```

### routes/web.php（capture group, scopeBindings。takes.playback を追加する箇所）
```php
Route::middleware(['require-active-subscription', 'project.in-route-org'])->prefix('app')->as('capture.')->group(function (): void {
    Route::scopeBindings()->group(function (): void {
        Route::get('/projects/{project}/manuals/{manual}', [CaptureManualController::class, 'show'])->name('manuals.show');
        // ... takes.upload-url / store / update / destroy / adopt / downloaded
        Route::post('.../takes/{take}/adopt', [CaptureTakeController::class, 'adopt'])->name('takes.adopt');
        Route::post('.../takes/{take}/downloaded', [CaptureTakeController::class, 'markDownloaded'])->name('takes.downloaded');
    });
});
```

### resources/js/components/features/capture/TakeStrip.svelte（既存。takeUrl ヘルパ / downloadAndAck の window.open）
```ts
function takeUrl(take: CaptureTake, suffix = ""): string {
    return `/app/projects/${projectId}/manuals/${manualId}/cuts/${cut.id}/takes/${take.id}${suffix}`;
}
const adopt = (take) => run(take, () => captureJson(takeUrl(take, "/adopt"), "POST"));
async function downloadAndAck(take) { // DL ボタン: 別タブで採用テイク署名 URL を開く (据え置き)
    window.open(take.playback_url, "_blank", "noopener");
    await run(take, () => captureJson(takeUrl(take, "/downloaded"), "POST", { ack_token: take.download_ack_token }));
}
```

### resources/js/components/features/capture/CameraRecorder.svelte（既存。startRecording 内で getUserMedia → video.srcObject、releaseCamera で tracks.stop。stream は startRecording 後 live のまま保持され onstop で tracks を止めない）
```ts
let stream: MediaStream | null = null;
let recording = $state(false);
async function startRecording() { /* stream ??= getUserMedia({video:{facingMode:"environment"},audio:true}); video.srcObject = stream; recorder=new MediaRecorder(stream); recorder.start(); recording=true; */ }
function releaseCamera() { stream?.getTracks().forEach(t => t.stop()); stream = null; }
onDestroy(releaseCamera);
```

### types/capture.ts（既存。CaptureCut は subtitle_primary/secondary を持つ。CaptureTake.status/playback_url。payload 変更なし）
```ts
export interface CaptureCut { id:number; ...; subtitle_primary: string|null; subtitle_secondary: string; adopted_take_id: number|null; takes: CaptureTake[]; }
export interface CaptureTake { id:number; status:"uploading"|"processing"|"ready"|"failed"; playback_url:string|null; download_ack_token:string|null; ... }
```

### tests/Architecture/NestedRouteIdorDefenseTest.php（inventory。capture.takes.* が $s=ScopeBindings で登録済み。capture.takes.playback を追加する）
```php
'capture.takes.adopt' => $s,
'capture.takes.downloaded' => $s,
// 追加予定: 'capture.takes.playback' => $s,
```
