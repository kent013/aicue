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

---

# system: 実装レビュアー

あなたは Laravel 12 + Svelte 5 (runes) + Inertia アプリの実装レビュアーである。
TODO T184「PC 側のテイク選択・採用画面」の実装差分を、以下の観点でレビューせよ。

## レビュー観点
1. **詳細設計との一致性** — 設計から逸脱している箇所があるか。逸脱に正当な理由があるか
2. **正確性** — 論理バグ・境界条件・競合状態・エラーハンドリング漏れ
3. **PHPStan level 10 適合性** — 型の緩め・暗黙 mixed・null 安全
4. **DTO / JsonResource パターン** — response()->json() 直書きが無いか、props の shape が DTO に集約されているか
5. **テスト網羅性** — 各施策にテストがあるか。不変条件が機械で固定されているか。テストが実装の写経になっていないか
6. **セキュリティ** — IDOR / テナント越境 / 認可順序 (層2 の 404 が層3 の 403 より前か) / 署名 URL や保存パスの漏洩
7. **DESIGN.md 準拠** — color / radius / typography は DS token 経由か。hex 直書き (#RRGGBB) を増やしていないか
8. **Atomic Design 準拠** — atoms → molecules → organisms → features/{domain} → templates → pages の単方向 import。
   atom は単機能・状態を持たない。アイコンは @lucide/svelte のみ (SVG 直書きを増やさない)

## 出力形式
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で明示する

---

# user

## 実装の概要

TODO T184 の 4 施策:
1. テイク選択・採用画面の新設 (route 1 本 + CutTakeController + TakeSelectionPageData/SelectableTakeData + pages/Manuals/Takes.svelte + features/manual の 3 コンポーネント + lib/capture/take-endpoints.ts)
2. 字幕 overlay の molecules 昇格と表示 ON/OFF (features/capture/SubtitleOverlay → molecules/SubtitleOverlay へ移設)
3. シナリオ編集画面の「動画」列 (VideoManualController::edit の takeSummaries props + CutTakeSummaryData + ScenarioEditor の videoCell)
4. PC ローカル動画のアップロード (createMemoryPendingStore + TakeFileUpload.svelte)

## 設計からの意図的な逸脱 (レビュー対象)

- **逸脱 1**: 詳細設計は「サムネイル生成は別タスク」を前提に TakeThumbnail を状態タイル固定にしていたが、
  設計執筆後に T183 (テイクのサムネイル生成) が main へマージ済みで、`capture.takes.thumbnail` (302) と
  `takes.thumbnail_path` が実在する。よって `SelectableTakeData` に `has_thumbnail` を足し、
  TakeThumbnail が画像/状態タイルを出し分けるようにした (差し替え点は設計どおり 1 コンポーネントに閉じている)。
- **逸脱 2**: 設計では TakeStrip だけを take-endpoints.ts に寄せる想定だったが、`lib/capture/upload-queue.ts` も
  同じ URL を組み立てていたため同時に寄せた (「URL 導出の唯一の場所」という設計意図に従った)。
- **逸脱 3**: 設計の `ring-2 ring-primary` ではなく `border-primary` を採用テイクの青枠に使った
  (既存 PricingPlanCard の強調枠と同じ表現に揃えるため)。選択中は `bg-primary-soft` で別途区別する。
- **逸脱 4**: `VideoManualController` が `with('adoptedTake')` を持つため `AdoptedTakeReferenceInventory` へ
  RelationWiring 区分で追加登録した (設計は DTO 側の 1 件しか想定していなかった)。

## 検証結果 (全 10 コマンド green)

- composer test: 5413 tests, 5411 passed, 0 failed, 2 skipped
- composer phpstan (level 10): No errors
- vendor/bin/pint --test: passed
- pnpm lint / typecheck / build: passed
- pnpm test: 143 files, 1619 tests passed
- pnpm typecheck:packages / build:packages / test:packages: passed (106 tests)
- scripts/bug-hunt-inventory-check.sh: exit 0 (画面 70 件 / 操作 79 件 一致)

## 詳細設計書

# 詳細設計: pc-take-selection-and-adoption (PC 側のテイク選択・採用画面)

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
5. LLM 呼び出しの Prism 直呼び(本タスクでは LLM 呼び出しを一切追加しない)
6. prompt 文字列のコード直書き(本タスク該当なし)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`。解析対象は app / config / database / routes）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- 新モデルは追加しない（Factory 追加も無い）
- **DTO + JsonResource** パターン（本タスクの新規 props は専用 DTO、書き込み応答は既存 Resource）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- `declare(strict_types=1)` + 日本語コメント。Controller は薄く（Service / DTO 委譲）

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex conceptual-review Round 4 で **APPROVED**）

### 概念設計からの差分（現行コードを読んで判明した修正）

| # | 概念設計 | 詳細設計 | 理由 |
|---|---|---|---|
| 1 | props に `outline`（cut の id/type 列）を載せ、frontend の `buildCutLabels` でラベルを導出する | **`outline` を廃止**し、`cut.label` をサーバで確定して載せる | `Services/Manual/CutSequencer::orderedWithLabels()` が「手順N / 急所N-M」の**サーバ側の既存導出元**として実在した（レンダの欠落ラベルとマニフェストが共用）。これを使えば導出元を増やさずに済む。`buildCutLabels` の signature 変更も不要になり、`lib/capture/cut-labels.ts` は無変更で済む |
| 2 | props のキー名は `cut.adopted_take_id` | **`cut.adopted` = `{ id, status } \| null`** | `ScenarioWritePathInventoryTest` 検出 4a/4b は、app/ 配下で識別子 `adopted_take_id` と配列キー `'adopted_take_id' => …` を deny-by-default で検出する（allowlist は 3 ファイルのみ、書き込み形と読み取りをトークンでは区別できないため）。表示のためだけに security gate の allowlist を広げるのは筋が悪い。読み取りは `$cut->adoptedTake` relation 経由にし、キー名から `_id` を外すことで**gate を一切緩めずに**同じ情報を出せる |

## 施策一覧

**4 施策すべてが完了条件**（順序は実装順）。

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | テイク選択・採用画面の新設 | `routes/web.php` / `app/Http/Controllers/Projects/CutTakeController.php`(新) / `app/DataTransferObjects/Manual/{TakeSelectionPageData,SelectableTakeData}.php`(新) / `app/Support/Security/AdoptedTakeReferenceInventory.php` / `resources/js/pages/Manuals/Takes.svelte`(新) / `resources/js/components/features/manual/{TakePickerList,TakePreviewPanel,TakeThumbnail}.svelte`(新) / `resources/js/lib/capture/take-endpoints.ts`(新) / `resources/js/components/features/capture/TakeStrip.svelte` / `resources/js/types/manual.ts` / `tests/Support/Routing/NestedRouteDefenseInventory.php` | P1 |
| 2 | 字幕 overlay の molecules 昇格と表示 ON/OFF | `resources/js/components/molecules/SubtitleOverlay.svelte`(移設先) / `resources/js/components/features/capture/SubtitleOverlay.svelte`(削除) / `resources/js/components/features/capture/CameraRecorder.svelte` / `tests/js/components/features/capture/SubtitleOverlay.test.ts`(移設) | P1 |
| 3 | シナリオ編集画面の「動画」列 | `app/Http/Controllers/Projects/VideoManualController.php` / `app/Support/Security/AdoptedTakeReferenceInventory.php` / `resources/js/components/features/manual/ScenarioEditor.svelte` / `resources/js/pages/Manuals/Edit.svelte` / `resources/js/types/manual.ts` | P1 |
| 4 | PC ローカル動画のアップロード | `resources/js/lib/capture/upload-queue.ts` / `resources/js/components/features/manual/TakeFileUpload.svelte`(新) / `resources/js/pages/Manuals/Takes.svelte` | P1 |

---

## 施策 1: テイク選択・採用画面の新設

### 変更箇所

- `routes/web.php` (L521-565 の業務 route `Route::scopeBindings()` group 内に 1 本追加)
- `app/Http/Controllers/Projects/CutTakeController.php` (新規)
- `app/DataTransferObjects/Manual/TakeSelectionPageData.php` (新規)
- `app/DataTransferObjects/Manual/SelectableTakeData.php` (新規)
- `app/Support/Security/AdoptedTakeReferenceInventory.php` (entry 追加)
- `tests/Support/Routing/NestedRouteDefenseInventory.php` (entry 追加)
- `resources/js/pages/Manuals/Takes.svelte` (新規)
- `resources/js/components/features/manual/TakePickerList.svelte` (新規)
- `resources/js/components/features/manual/TakePreviewPanel.svelte` (新規)
- `resources/js/components/features/manual/TakeThumbnail.svelte` (新規)
- `resources/js/lib/capture/take-endpoints.ts` (新規。URL 導出の単一化)
- `resources/js/components/features/capture/TakeStrip.svelte` (L86-88 の `takeUrl` を上記へ寄せる)
- `resources/js/types/manual.ts` (型追加)

### 権限境界（意図的な非対称。誤読しないこと）

| 対象 | 認可 | 撮影者 (project_member) | 編集者 (project_admin / org owner・admin) |
|---|---|---|---|
| **画面 route** `projects.manuals.cuts.takes.index` | `Gate::authorize('update', $manual)` = `VideoManualPolicy::update` → `ProjectPolicy::update` | **403** | 200 |
| **操作 API** `capture.takes.{upload-url,store,adopt,destroy,playback}` | `TakePolicy` → `ProjectPolicy::capture` | **可（従来どおり）** | 可 |

**PC 画面が編集者限定であることと、テイク操作 API が撮影者にも開いていることは別**である。
撮影者は PWA から従来どおり採用・削除できる（doc/10 §10.5 の確定仕様）。
この非対称は**テストで固定する**（テスト計画に「撮影者が `capture.takes.adopt` を叩ける」を含める。
この行が消えたら非対称が事故で壊れたと分かる）。
PC の操作まで編集者限定にしたい場合は API を分ける必要があるが、それは別タスクの議題である。

### 再利用する frontend helper（新設しない）

`resources/js/lib/capture/http.ts` に実装済みのものをそのまま使う（新しい fetch ラッパを作らない）:

| export | 挙動 |
|---|---|
| `captureFetch(url, init)` | `credentials: "same-origin"` / `Accept: application/json` / `X-Requested-With` / `X-XSRF-TOKEN` を常時付与。**419 は `/app/csrf-cookie` を取り直して 1 回だけ再送** |
| `captureJson(url, method, body?)` | 上記に `Content-Type: application/json` + JSON body |
| `extractErrorMessage(response)` | 422/409 等の body から `message` → `errors` の先頭を取り出す（無ければ既定文言） |

409 (`scenario_conflict` = rendering/analyzing) / 422 (not ready / DL 済み削除 / quota) は
**サーバ供給の文言をそのまま表示**する（UI 側で理由を再実装しない）。

### 波及変更

- **TypeScript 型定義**: `resources/js/types/manual.ts` に `SelectableTakeStatus` / `SelectableTake` /
  `TakeSelectionCut` / `TakeSelectionPageProps` を追加。**`types/capture.ts` は変更しない**
  （PC の shape は署名 URL の口を持たない別物。概念設計 D2）。
- **API Resource/DTO**: 新規 `TakeSelectionPageData` / `SelectableTakeData`。
  **既存 `CaptureCutData` / `CaptureTakeData` / `CaptureTakeResource` は無変更**
  （PC からの書き込み応答はこれらをそのまま受け取る）。
- **テストファイル**: `tests/Feature/Manual/TakeSelectionPageTest.php`(新) /
  `tests/Feature/Manual/PcTakeOperationTest.php`(新) /
  `tests/js/pages/ManualsTakes.test.ts`(新) / `tests/js/lib/capture/take-endpoints.test.ts`(新) /
  `tests/js/components/features/capture/TakeStrip.test.ts`(既存があれば URL 回帰を追加)。
- **目録**: `NestedRouteDefenseInventory`（未登録なら `NestedRouteIdorDefenseTest` が fail） /
  `AdoptedTakeReferenceInventory`（未登録なら `AdoptedReadyTakeCriterionInventoryTest` が fail）。
- **bug-hunt 目録**: `.claude/skills/app-bug-hunt/inventory/annotations.toml` に
  `projects.manuals.cuts.takes.index` の注釈を 1 行足して再生成。
- **ドキュメント**: `doc/10` / `docs/architecture.md` §撮影 PWA の運用契約 /
  `routes/web.php` の撮影 PWA group コメント。

### 現行コード

`routes/web.php` L521-565（業務 route の scopeBindings group。末尾は複製 route）:

```php
Route::scopeBindings()->group(function (): void {
    Route::get('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'show'])
        ->name('projects.manuals.show');
    // …（中略: edit / update / scenario.update / source-documents / analyze / jobs / render …）
    Route::post('/projects/{project}/manuals/{manual}/duplicate', [VideoManualController::class, 'duplicate'])
        ->name('projects.manuals.duplicate');
});
```

`resources/js/components/features/capture/TakeStrip.svelte` L86-88（URL 組み立ての現在の唯一の場所）:

```svelte
function takeUrl(take: CaptureTake, suffix = ""): string {
    return `/app/projects/${projectId}/manuals/${manualId}/cuts/${cut.id}/takes/${take.id}${suffix}`;
}
```

### 変更後コード

**(1) `routes/web.php`**（上記 group の末尾に追加）

```php
    // テイク選択・採用画面 (doc/04 「テイクのプレビュー / 選択画面」)。編集者のみ (撮影者は 403)。
    // **この GET は画面 props を返すだけ**で、採用・削除・アップロード・再生は
    // capture.takes.* を再利用する (テイク資源の API 面を 2 本にしない)。
    // {cut} は $manual->cuts() 経由 (scopeBindings) = cross-manual/cross-project は認可より前に 404。
    Route::get('/projects/{project}/manuals/{manual}/cuts/{cut}/takes', [CutTakeController::class, 'index'])
        ->name('projects.manuals.cuts.takes.index');
```

**(2) `app/Http/Controllers/Projects/CutTakeController.php`**（新規）

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\DataTransferObjects\Manual\TakeSelectionPageData;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Models\Cut;
use App\Models\Project;
use App\Models\VideoManual;
use App\Support\Seo\SeoManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * テイク選択・採用画面 (doc/04)。編集者がカットごとのテイクを見て採用を確定する面。
 *
 * nested route の URL 整合は 2 層 (認可より前に 404):
 * 1. {project} ∈ current org (project.in-current-org middleware + resolveOrganizationProject)
 * 2. {manual} ∈ {project}, {cut} ∈ {manual} (Route::scopeBindings())
 *
 * 本 controller は**読み取りのみ**である。採用・削除・アップロード・再生は
 * capture.takes.* (撮影 PWA と共用の API 面) が担い、cuts の採用テイク外部キーを書くのは
 * 従来どおり Capture/CaptureTakeService::adopt() だけである
 * (AGENTS.md ドメイン固有規約 1 / ScenarioWritePathInventoryTest 検出 4)。
 */
class CutTakeController extends Controller
{
    use ResolvesCurrentOrganization;

    /** テイク選択画面 (編集者のみ。撮影者は 403 = PWA 側に採用導線がある) */
    public function index(
        Request $request,
        Project $project,
        VideoManual $manual,
        Cut $cut,
        SeoManager $seo,
    ): Response {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual}∈{project}, {cut}∈{manual} は scopeBindings)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $manual); // VideoManualPolicy::update = 編集者

        $page = TakeSelectionPageData::fromCut($project, $manual, $cut);
        // 並行編集タブを判別できる動的固有名 (noindex 維持。既存 edit/show と同方針)
        $seo->setPrivateTitle($manual->title.' / '.$page->label.' のテイク選択');

        return Inertia::render('Manuals/Takes', $page->toArray());
    }
}
```

**(3) `app/DataTransferObjects/Manual/SelectableTakeData.php`**（新規）

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Models\Take;

/**
 * PC テイク選択画面が受け取るテイク 1 件の shape。
 * TS 側 types/manual.ts の SelectableTake と対で保守する。
 *
 * **署名 URL / video_path / thumbnail_path のスロットを構造として持たない**。
 * 撮影 PWA 用の CaptureTakeData は採用テイクへ署名 URL を載せる口を持つため、
 * 似ていても合流させない (概念設計 D2。「今は null だから安全」を作らない)。
 * 再生は capture.takes.playback (302 + no-store) 経由のみである。
 */
final readonly class SelectableTakeData
{
    public function __construct(
        public Take $take,
    ) {}

    public static function fromTake(Take $take): self
    {
        return new self($take);
    }

    /**
     * @return array{id: int, status: string, size_bytes: int, duration_ms: int|null,
     *   comment: string|null, captured_at: string|null, sort_order: int, downloaded: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->take->id,
            'status' => $this->take->status->value,
            'size_bytes' => $this->take->size_bytes,
            'duration_ms' => $this->take->duration_ms,
            'comment' => $this->take->comment,
            'captured_at' => $this->take->captured_at?->toIso8601String(),
            'sort_order' => $this->take->sort_order,
            // DL 済みテイクは削除できない (422)。理由を押下前に説明するために出す
            'downloaded' => $this->take->downloaded_at !== null,
        ];
    }
}
```

**(4) `app/DataTransferObjects/Manual/TakeSelectionPageData.php`**（新規）

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Models\Cut;
use App\Models\Project;
use App\Models\Take;
use App\Models\VideoManual;
use App\Services\Manual\CutSequencer;

/**
 * PC テイク選択画面 (Manuals/Takes) の Inertia props 全体。
 * TS 側 types/manual.ts の TakeSelectionPageProps と対で保守する。
 *
 * 表示ラベル (手順N / 急所N-M) は CutSequencer::orderedWithLabels() から取る
 * (レンダの欠落ラベル・マニフェストと同じ導出元。ラベル規則を増やさない)。
 * 採用テイクは `adopted` キーで出す — 採用テイク外部キーの識別子は
 * ScenarioWritePathInventoryTest 検出 4 の deny-by-default 走査対象であり、
 * 表示のために security gate の allowlist を広げないための命名である。
 */
final readonly class TakeSelectionPageData
{
    /** @param list<SelectableTakeData> $takes */
    public function __construct(
        public Project $project,
        public VideoManual $manual,
        public Cut $cut,
        public string $label,
        public array $takes,
    ) {}

    public static function fromCut(Project $project, VideoManual $manual, Cut $cut): self
    {
        // route binding 済みの $cut は relation 未ロードなので明示的に読む。
        // (本リポジトリは Model::preventLazyLoading() を有効化していないので落ちはしないが、
        //  暗黙の追加クエリを残さない)
        $cut->loadMissing('adoptedTake');

        // 見つからないのは「親を持たない急所」= データ異常のときだけ。
        // 画面タイトルを空にせず中立語へ倒す (静かに空にして異常を隠さない)
        $label = 'カット';
        foreach (CutSequencer::orderedWithLabels($manual) as $ordered) {
            if ($ordered->cut->id === $cut->id) {
                $label = $ordered->label;
                break;
            }
        }

        /** @var list<SelectableTakeData> $takes */
        $takes = $cut->takes()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (Take $take): SelectableTakeData => SelectableTakeData::fromTake($take))
            ->values()
            ->all();

        return new self($project, $manual, $cut, $label, $takes);
    }

    /**
     * @return array{project: array{id: int, name: string},
     *   manual: array{id: int, title: string, status: string},
     *   cut: array{id: int, type: string, label: string, scene: string, narration: string,
     *     subtitle_primary: string|null, subtitle_secondary: string,
     *     adopted: array{id: int, status: string}|null},
     *   takes: list<array{id: int, status: string, size_bytes: int, duration_ms: int|null,
     *     comment: string|null, captured_at: string|null, sort_order: int, downloaded: bool}>}
     */
    public function toArray(): array
    {
        $adopted = $this->cut->adoptedTake;

        return [
            'project' => ['id' => $this->project->id, 'name' => $this->project->name],
            'manual' => [
                'id' => $this->manual->id,
                'title' => $this->manual->title,
                // rendering / analyzing 中は採用が 409 になることの事前告知に使う
                'status' => $this->manual->status->value,
            ],
            'cut' => [
                'id' => $this->cut->id,
                'type' => $this->cut->type->value,
                'label' => $this->label,
                'scene' => $this->cut->scene,
                'narration' => $this->cut->narration,
                'subtitle_primary' => $this->cut->subtitle_primary,
                'subtitle_secondary' => $this->cut->subtitle_secondary,
                'adopted' => $adopted === null
                    ? null
                    : ['id' => $adopted->id, 'status' => $adopted->status->value],
            ],
            'takes' => array_map(
                static fn (SelectableTakeData $take): array => $take->toArray(),
                $this->takes,
            ),
        ];
    }
}
```

> **コメントでの語彙**: `app/` 配下の新規コメントでは採用テイク外部キーの識別子を直接書かない。
> `ScenarioWritePathInventoryTest` の走査は `token_get_all()` ベースで `T_STRING` /
> `T_CONSTANT_ENCAPSED_STRING` しか見ない（コメントは `T_COMMENT` / `T_DOC_COMMENT` なので
> 現状は検出されない）が、**コメントが走査対象にならないことに賭ける理由が無い**ため
> 「採用テイク外部キー」と言い換える。

**(5) `app/Support/Security/AdoptedTakeReferenceInventory.php`**（entry 追加）

```php
            'DataTransferObjects/Manual/TakeSelectionPageData.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'PC テイク選択画面が「今どれを採用しているか」を青枠で示すために'
                    .'採用テイクの id と status を読むだけで、ready 判定も充足判定もしない。'
                    .'レンダの充足判定 (AdoptedReadyTakeCoverage) とは意図的に統合しない。',
            ],
```

**(6) `tests/Support/Routing/NestedRouteDefenseInventory.php`**（業務 route ブロックへ追加）

```php
            // {cut} は $manual->cuts() 経由 (PC テイク選択画面)
            'projects.manuals.cuts.takes.index' => [...$project, 'manual' => $scoped, 'cut' => $scoped],
```

**(7) `resources/js/lib/capture/take-endpoints.ts`**（新規。URL 導出の唯一の場所）

```ts
/**
 * テイク API (capture.takes.*) の URL 導出。**規則をここ 1 箇所に置く**。
 *
 * この API 面は撮影 PWA (Capture/Show の TakeStrip) と PC 編集面
 * (Manuals/Takes) の**両方が叩く**。URL prefix が /app なのは歴史的経緯であり、
 * テイク資源の唯一の API 面である (doc/10 / docs/architecture.md §撮影 PWA の運用契約)。
 */
export interface TakeEndpointTarget {
    projectId: number;
    manualId: number;
    cutId: number;
}

/** カット配下のテイクコレクション URL (POST = 登録) */
export function cutTakesUrl({ projectId, manualId, cutId }: TakeEndpointTarget): string {
    return `/app/projects/${projectId}/manuals/${manualId}/cuts/${cutId}/takes`;
}

/** テイク単体の URL (suffix で /adopt /playback 等を足す) */
export function takeUrl(target: TakeEndpointTarget, takeId: number, suffix = ""): string {
    return `${cutTakesUrl(target)}/${takeId}${suffix}`;
}

/** presigned upload-url 発行 URL */
export function takeUploadUrlEndpoint(target: TakeEndpointTarget): string {
    return `${cutTakesUrl(target)}/upload-url`;
}
```

**(8) `resources/js/components/features/capture/TakeStrip.svelte`**（既存 `takeUrl` を寄せる）

```svelte
    import { takeUrl as buildTakeUrl } from "@/lib/capture/take-endpoints";
    // …
    function takeUrl(take: CaptureTake, suffix = ""): string {
        return buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, suffix);
    }
```

**(9) `resources/js/types/manual.ts`**（型追加）

```ts
/** PHP: App\Enums\Manual\TakeStatus と値集合を一致させる (literal union) */
export type SelectableTakeStatus = "uploading" | "processing" | "ready" | "failed";

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
    size_bytes: number;
    duration_ms: number | null;
    comment: string | null;
    captured_at: string | null;
    sort_order: number;
    downloaded: boolean;
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
    adopted: { id: number; status: SelectableTakeStatus } | null;
}
```

**(10) `resources/js/pages/Manuals/Takes.svelte`**（新規。配線のみ。判断は子コンポーネント）

```svelte
<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import { ArrowLeft, Film } from "@lucide/svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import TakePickerList from "@/components/features/manual/TakePickerList.svelte";
    import TakePreviewPanel from "@/components/features/manual/TakePreviewPanel.svelte";
    import TakeFileUpload from "@/components/features/manual/TakeFileUpload.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { SelectableTake, TakeSelectionCut, VideoManualStatus } from "@/types/manual";

    /**
     * テイク選択・採用画面 (doc/04)。左 = テイク一覧、中央 = プレビュー + 採用。
     * 採用・削除・アップロードは capture.takes.* (PWA と共用の API 面) を叩き、
     * 成功したら partial reload で cut と takes を取り直す。
     */
    interface Props {
        project: { id: number; name: string };
        manual: { id: number; title: string; status: VideoManualStatus };
        cut: TakeSelectionCut;
        takes: SelectableTake[];
    }

    let { project, manual, cut, takes }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    // 選択中テイク: 既定は採用テイク、無ければ先頭 (id で持ち、reload 後も追随させる)
    let selectedTakeId = $state<number | null>(null);
    const selectedTake = $derived(
        takes.find((take) => take.id === selectedTakeId) ??
            takes.find((take) => take.id === cut.adopted?.id) ??
            takes[0] ??
            null,
    );

    /** 採用・削除・アップロード成功後の再取得 (cut と takes は別のトップレベル props) */
    function refresh(): void {
        router.reload({ only: ["cut", "takes"] });
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeaderSection
            title={`${cut.label} のテイク選択`}
            description={cut.scene}
            icon={Film}
            testId="take-selection-heading"
        >
            <TextLink href={`/projects/${project.id}/manuals/${manual.id}/edit`}>
                <ArrowLeft class="inline size-3" aria-hidden="true" />
                シナリオ編集へ戻る
            </TextLink>
        </PageHeaderSection>
        <PageContent>
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[20rem_minmax(0,1fr)]">
                <TakePickerList
                    {takes}
                    adoptedTakeId={cut.adopted?.id ?? null}
                    selectedTakeId={selectedTake?.id ?? null}
                    onSelect={(id) => (selectedTakeId = id)}
                    projectId={project.id}
                    manualId={manual.id}
                    cutId={cut.id}
                    onChanged={refresh}
                />
                <div class="flex min-w-0 flex-col gap-4">
                    <TakePreviewPanel
                        take={selectedTake}
                        {cut}
                        manualStatus={manual.status}
                        projectId={project.id}
                        manualId={manual.id}
                        onChanged={refresh}
                    />
                    <TakeFileUpload
                        projectId={project.id}
                        manualId={manual.id}
                        cutId={cut.id}
                        onUploaded={refresh}
                    />
                </div>
            </div>
        </PageContent>
    </PageContainer>
</AppLayout>
```

**(11) `TakePreviewPanel.svelte`**（中央プレビュー + 採用。要点のみ）

```svelte
    import { captureJson, extractErrorMessage } from "@/lib/capture/http";
    import { takeUrl as buildTakeUrl } from "@/lib/capture/take-endpoints";

    // 再生は 302 経由 (署名 URL を props に載せない)。ready 以外はサーバが 404 を返すため
    // src を張らず、<video> 自体を描かない (無駄な要素とネットワーク要求を出さない)
    const playbackUrl = $derived(
        take !== null && take.status === "ready"
            ? buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, "/playback")
            : null,
    );

    // 押下は常に受ける (disabled にしない。AGENTS.md 禁止事項 8)
    async function adopt(): Promise<void> {
        error = null;
        if (take === null) { error = "テイクを選択してください。"; return; }
        if (!TAKE_ADOPTABLE_BY_STATUS[take.status]) {
            error = `${TAKE_STATUS_LABELS[take.status]}のテイクは採用できません。`;
            return;
        }
        busy = true;
        try {
            const res = await captureJson(
                buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, "/adopt"), "POST");
            if (!res.ok) { error = await extractErrorMessage(res); return; } // 409/422 はサーバ文言
            onChanged();
        } catch {
            error = "通信に失敗しました。ネットワークを確認してください。";
        } finally {
            busy = false;
        }
    }
```

`playbackUrl === null` のときは `<video>` を描かず、`TakeThumbnail` の状態タイルと
「このテイクはまだ再生できません（{状態ラベル}）」を出す。

- 採用テイクの視覚的区別（要件の「青枠」）は `TakePickerList` の各タイルに
  `ring-2 ring-primary`（DS token 経由）を当てる。hex 直書きはしない。
- 削除は `ConfirmDialog`（organisms）で「この操作は取り消せません。動画は完全に削除されます。」を出し、
  確定時のみ `DELETE`。DL 済み (422) は押下後にサーバ文言を表示する。

**(12) `TakeThumbnail.svelte`**（サムネイル未生成時のフォールバック）

```svelte
    /**
     * テイクのタイル。**サムネイル生成は別タスク**のため、現在は状態タイルを描く。
     * サムネイルが入る時点で `thumbnailUrl` prop を足し、この中身だけを差し替える
     * (差し替え点をこの 1 コンポーネントに閉じる)。
     */
    interface Props {
        index: number;
        status: SelectableTakeStatus;
        durationMs: number | null;
        adopted: boolean;
    }
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`index(): Response` / `toArray(): array{...}` / `fromCut(): self`）
- [x] null 安全（`$cut->adoptedTake` は null 合体で分岐、`captured_at?->toIso8601String()`。
      `label` は `'カット'` で初期化済み＝未初期化アクセスも空文字も出ない）
- [x] DTO を返している（Inertia props は `TakeSelectionPageData::toArray()` のみ。生配列の直組みなし）
- [x] Generics の型パラメータが正しい（`@param list<SelectableTakeData> $takes` /
      `->map(...)->values()->all()` で `list<>` を確定）
- [x] `response()->json()` を使わない（Inertia render のみ）
- [x] `declare(strict_types=1)` + 日本語コメント

### テスト計画

- [ ] 新規 `tests/Feature/Manual/TakeSelectionPageTest.php`
  - 編集者 (project_admin / org admin) は 200 で `Manuals/Takes` が描画され、props の
    `cut.label` が「手順1」形式で入る
  - **撮影者 (project_member) は 403**（PWA 側に採用導線がある＝詰まないことをコメントで明示）
  - cross-org / cross-project / cross-manual / cross-cut は**すべて 404**（403 ではない）
  - **props に `playback_url` / `video_path` / `thumbnail_path` / `download_ack_token` の
    いずれのキーも現れない**（shape 契約の機械化）
  - takes は `sort_order` 昇順、`downloaded` が DL 済みで true
  - **step の cut は `手順1`、point の cut は `急所1-1` のラベルになる**（ラベル導出の固定）
  - `require-active-subscription` 未充足の組織は onboarding へ遮断される
- [ ] 新規 `tests/Feature/Manual/PcTakeOperationTest.php`
  - 編集者が `capture.takes.adopt` / `destroy` / `upload-url` / `store` / `playback` を実行できる
    （PC 導線でも認可が通ることの固定＝概念設計 D2 の読み替えの機械化）
  - **撮影者 (project_member) も `capture.takes.adopt` を実行できる**
    （画面は 403 だが API は開いている、という意図的な非対称の固定。
    この test が消えたら非対称が事故で壊れたと分かる）
  - `rendering` / `analyzing` 中の adopt は 409、`ready` でないテイクの adopt は 422、
    DL 済みテイクの削除は 422
- [ ] 既存 `tests/Architecture/NestedRouteIdorDefenseTest` / `TenantBoundaryOrderingTest` が
      inventory 追加で green（登録漏れなら fail することを一度確認してから登録する＝テストファースト）
- [ ] 既存 `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest` が entry 追加で green
- [ ] 既存 `tests/Architecture/ScenarioWritePathInventoryTest` が**無変更で** green
      （新しい書き込み経路を作っていないことの裏取り）
- [ ] 新規 `tests/js/lib/capture/take-endpoints.test.ts`（3 関数の URL 組み立て）
- [ ] 新規 `tests/js/pages/ManualsTakes.test.ts`
  - 採用テイクのタイルに青枠クラスが付く / 非採用には付かない
  - `processing` テイクの「採用する」押下でエラー文言が出る（**要素は disabled でない**）
  - 削除は確認ダイアログを経てから DELETE が飛ぶ（復元不可の文言を含む）
  - 採用成功後に `router.reload({ only: ["cut", "takes"] })` が呼ばれる
  - サムネイル未生成時に状態タイル（テイク番号 + 状態ラベル）が描画される
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **URL 空間の読み替え**: `/app/*` が PWA 専用でなくなる。ドキュメント 3 箇所と
  Feature テストで固定するが、**将来 `/app` に PWA 固有 middleware（例: SW 前提の
  no-store 強化）を足すと PC 面にも掛かる**。足すときは本設計を読み直すこと。
- **撮影者が PC 画面で 403 を見る**: 撮影者に本画面の URL が共有されると 403 に着地する。
  詰みではない（PWA に採用導線がある）が、403 画面から撮影ナビへの導線は無い。
  今回は導線を足さない（過剰。403 は共有事故の場合にしか起きない）。
- **採用状態のスナップショット性**: 他タブ・PWA からの採用変更は再取得まで反映されない
  （既存 `coverage` props と同じ性質）。採用は最終的にサーバの行ロックで直列化される。

---

## 施策 2: 字幕 overlay の molecules 昇格と表示 ON/OFF

### 変更箇所

- `resources/js/components/features/capture/SubtitleOverlay.svelte` → **削除**
- `resources/js/components/molecules/SubtitleOverlay.svelte` → **新設（移設先）**
- `resources/js/components/features/capture/CameraRecorder.svelte` (L16 import / L508 使用箇所)
- `tests/js/components/features/capture/SubtitleOverlay.test.ts` →
  `tests/js/components/molecules/SubtitleOverlay.test.ts` へ移設
- `resources/js/components/features/manual/TakePreviewPanel.svelte`（新規利用側）

### 波及変更

- **TypeScript 型定義**: props 型を `CaptureCut["subtitle_primary"]` 参照から
  素の `string | null` / `string` に一般化（`types/capture.ts` への依存を外す。
  molecules が features 側の型に依存し続けるのは階層違反ではないが、
  共有部品としては不要な結合なので切る）。
- **API Resource/DTO**: なし
- **テストファイル**: 上記の移設（アサーションは変更しない＝振る舞いを変えていないことの確認）

### 現行コード

`resources/js/components/features/capture/SubtitleOverlay.svelte`（抜粋）:

```svelte
<script lang="ts">
    import type { CaptureCut } from "@/types/capture";
    interface Props {
        primary: CaptureCut["subtitle_primary"];
        secondary: CaptureCut["subtitle_secondary"];
        visible: boolean;
    }
    let { primary, secondary, visible }: Props = $props();
```

`CameraRecorder.svelte` L16:

```svelte
    import SubtitleOverlay from "@/components/features/capture/SubtitleOverlay.svelte";
```

### 変更後コード

`resources/js/components/molecules/SubtitleOverlay.svelte`（移設 + 型の一般化。描画部は不変）:

```svelte
<script lang="ts">
    /**
     * 映像へ重畳する字幕 overlay (焼込ではない DOM overlay)。
     * primary=上部帯 (名称・数値) / secondary=下部メイン。位置は AssSubtitleWriter (ASS) と一致。
     *
     * 利用者は 2 つ:
     * - 撮影中カメラプレビューの字幕ガイド (features/capture/CameraRecorder)
     * - PC テイク選択画面のプレビュー字幕表示 ON/OFF (features/manual/TakePreviewPanel)
     * features の domain 間横参照を作らないため molecules に置く (複製しない)。
     */
    interface Props {
        primary: string | null;
        secondary: string;
        visible: boolean;
    }
    let { primary, secondary, visible }: Props = $props();
    // 以下 (hasPrimary / hasSecondary / shown と markup) は移設前と完全に同一
```

`CameraRecorder.svelte`:

```svelte
    import SubtitleOverlay from "@/components/molecules/SubtitleOverlay.svelte";
```

`TakePreviewPanel.svelte`（表示 ON/OFF。**初期は両方オフ**）:

```svelte
    // doc/04 「プレビューにナレーション/字幕を ON/OFF (初期は両方オフ)」
    // v1 は TTS 非実装のため、ナレーションは**原稿テキストの表示**の切替である
    // (音声は再生しない。概念設計 D6)。ラベルにも「原稿」と書き、音が出ると誤解させない。
    let showSubtitles = $state(false);
    let showNarrationScript = $state(false);
```

```svelte
    {#if playbackUrl !== null}
        <div class="relative">
            <video src={playbackUrl} controls class="w-full rounded-md bg-text"
                   aria-label={`${cut.label} のテイク ${index + 1}`}></video>
            <SubtitleOverlay
                primary={cut.subtitle_primary}
                secondary={cut.subtitle_secondary}
                visible={showSubtitles}
            />
        </div>
    {:else}
        <!-- ready 以外 / 未選択: <video> を作らない (サーバは 404 を返すため要求も出さない) -->
        <TakeThumbnail ... />
        <p class="text-caption text-text-secondary" data-testid="take-not-playable">
            {take === null
                ? "左の一覧からテイクを選ぶと再生できます。"
                : `このテイクはまだ再生できません（${TAKE_STATUS_LABELS[take.status]}）。`}
        </p>
    {/if}
    <Checkbox bind:checked={showSubtitles} label="字幕を表示" testId="toggle-subtitles" />
    <Checkbox bind:checked={showNarrationScript} label="ナレーション原稿を表示"
              testId="toggle-narration-script" />
    {#if showNarrationScript}
        <p class="text-body text-text-secondary" data-testid="narration-script">{cut.narration}</p>
    {/if}
```

### PHPStan 適合チェック

- 本施策は frontend のみ（PHP 変更なし）。`pnpm typecheck` / `pnpm lint` / ds-purity /
  `atomic-import-graph` テストが検査対象。

### テスト計画

- [ ] 既存 `SubtitleOverlay.test.ts` を `tests/js/components/molecules/` へ移設し、
      **アサーションを変えずに** green（移設で振る舞いが変わっていないことの確認）
- [ ] 既存 `tests/js/architecture/atomic-import-graph.test.ts` が green
      （features/manual → molecules は順方向。features 間の横参照は発生しない）
- [ ] 新規 `ManualsTakes.test.ts` に:
  - 初期状態で字幕 overlay が**出ていない** / ナレーション原稿が**出ていない**
  - 「字幕を表示」を ON にすると `subtitle-primary` / `subtitle-secondary` が出る
  - 「ナレーション原稿を表示」を ON にすると `narration-script` が出る
  - **音声再生に関する要素・文言を出さない**（TTS 非実装の読み替えの固定）
- [ ] 既存 `CaptureShow.test.ts` / CameraRecorder 系テストが import 変更後も green

### リスク

- 移設で `CameraRecorder` の字幕ガイドが壊れると**撮影の主要導線**に影響する。
  描画部を 1 文字も変えず、既存テストをそのまま通すことで担保する。
- 型の一般化により `CaptureCut` の subtitle 型が将来変わっても overlay は追随しない。
  overlay 側は `string | null` / `string` を契約として持つ（現行と同じ）。

---

## 施策 3: シナリオ編集画面の「動画」列

### 変更箇所

- `app/Http/Controllers/Projects/VideoManualController.php`（`edit()` に props 追加 + private helper）
- `app/DataTransferObjects/Manual/CutTakeSummaryData.php`（新規）
- `app/Support/Security/AdoptedTakeReferenceInventory.php`（entry 追加）
- `resources/js/pages/Manuals/Edit.svelte`（props 受け取り → `ScenarioEditor` へ中継）
- `resources/js/components/features/manual/ScenarioEditor.svelte`（動画セルの追加）
- `resources/js/types/manual.ts`（`CutTakeSummary` 追加）

### 波及変更

- **TypeScript 型定義**: `CutTakeSummary` を追加し、`Manuals/Edit.svelte` の `Props` と
  `ScenarioEditor` の `Props` に `takeSummaries: CutTakeSummary[]` を足す。
- **API Resource/DTO**: `CutTakeSummaryData`（新規）。
  「本タスクの新規 props は専用 DTO」という自分の規約に例外を作らない
  （3 フィールドでも DTO のコストはほぼゼロで、shape の置き場が 1 つに定まる）。
- **テストファイル**: `tests/Feature/Manual/ScenarioVideoColumnTest.php`(新) /
  `tests/js/pages/ManualsEdit.test.ts`(既存に追加)。

### 現行コード

`app/Http/Controllers/Projects/VideoManualController.php` L192-217:

```php
    /** 編集フォーム (メタデータ = title / category + シナリオ document) */
    public function edit(Request $request, Project $project, VideoManual $manual, SeoManager $seo): Response
    {
        // …（略）
        return Inertia::render('Manuals/Edit', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'manual' => [/* … */],
            'categories' => $this->categoryOptions($project),
            'scenario' => ScenarioDocumentData::fromManual($manual)->toArray(),
        ]);
    }
```

`resources/js/components/features/manual/ScenarioEditor.svelte` L40-46 / L880-882:

```svelte
    interface Props {
        projectId: number;
        manualId: number;
        scenario: ScenarioDocument;
    }
    let { projectId, manualId, scenario }: Props = $props();
```

```svelte
                        <div class="mt-3">
                            {@render rowFields(step, `steps.${stepIndex}`, `step-${stepIndex}`)}
                        </div>
```

### 変更後コード

**(1) `VideoManualController::edit`**

```php
        return Inertia::render('Manuals/Edit', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'manual' => [/* 既存のまま */],
            'categories' => $this->categoryOptions($project),
            'scenario' => ScenarioDocumentData::fromManual($manual)->toArray(),
            // 動画列 (カットごとのテイク要約)。描画時点のスナップショットであり常に最新ではない
            // (採用は他タブ / 撮影 PWA からも起きる。判断はサーバの行ロックが直列化する)
            'takeSummaries' => $this->takeSummaries($manual),
        ]);
```

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

> `use App\DataTransferObjects\Manual\CutTakeSummaryData;` と `use App\Models\Cut;` を import に追加する。

**(2) `app/DataTransferObjects/Manual/CutTakeSummaryData.php`**（新規）

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Models\Cut;
use Webmozart\Assert\Assert;

/**
 * シナリオ編集画面「動画」列の 1 カット分。
 * TS 側 types/manual.ts の CutTakeSummary と対で保守する。
 *
 * 採用テイクは `adopted` キーで返す — 採用テイク外部キーの識別子は
 * ScenarioWritePathInventoryTest 検出 4 の deny-by-default 走査対象であり、
 * 表示のために security gate の allowlist を広げないための命名である。
 * 読み取りは adoptedTake relation 経由で行う。
 */
final readonly class CutTakeSummaryData
{
    public function __construct(
        public int $cutId,
        public int $takesCount,
        public ?int $adoptedTakeId,
        public ?string $adoptedTakeStatus,
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
            adoptedTakeId: $adopted?->id,
            adoptedTakeStatus: $adopted?->status->value,
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
            'adopted' => $this->adoptedTakeId === null || $this->adoptedTakeStatus === null
                ? null
                : ['id' => $this->adoptedTakeId, 'status' => $this->adoptedTakeStatus],
        ];
    }
}
```

**(3) `AdoptedTakeReferenceInventory`**（entry 追加。`adoptedTake` を触るのは DTO 側）

```php
            'DataTransferObjects/Manual/CutTakeSummaryData.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'シナリオ編集画面の動画列が、カットごとに採用テイクの id と status を'
                    .'表示するために読むだけで ready 判定はしない。レンダの充足判定'
                    .'(AdoptedReadyTakeCoverage) とは基準が違うため意図的に統合しない。',
            ],
```

**(4) `types/manual.ts`**

```ts
/** PHP: CutTakeSummaryData と対 (動画列の 1 カット分) */
export interface CutTakeSummary {
    cut_id: number;
    takes_count: number;
    adopted: { id: number; status: SelectableTakeStatus } | null;
}
```

**(5) `ScenarioEditor.svelte`**（Props 追加 + 動画セル）

```svelte
    interface Props {
        projectId: number;
        manualId: number;
        scenario: ScenarioDocument;
        /** 動画列 (カットごとのテイク要約)。未保存行 (id=null) には対応する要約が無い */
        takeSummaries: CutTakeSummary[];
    }
    let { projectId, manualId, scenario, takeSummaries }: Props = $props();

    /** cut_id → 要約の索引 (行ごとの線形探索を避ける) */
    const summaryByCutId = $derived(
        new Map(takeSummaries.map((summary) => [summary.cut_id, summary])),
    );
```

```svelte
{#snippet videoCell(cutId: number | null)}
    <!-- 動画列 (doc/04)。未保存行はリンクを出さず、押せるのに詰むボタンを作らない。
         行 Card の中に角丸カードを入れ子にせず、区切り線で段を分ける -->
    <div class="mt-3 border-t border-border pt-3" data-testid="video-cell">
        <p class="text-caption text-text-secondary">動画</p>
        {#if cutId === null}
            <p class="mt-1 text-caption text-text-secondary" data-testid="video-cell-unsaved">
                「シナリオを更新」で保存すると、このカットに動画を登録できます。
            </p>
        {:else}
            {@const summary = summaryByCutId.get(cutId)}
            <p class="mt-1 text-caption" data-testid="video-cell-count">
                テイク {summary?.takes_count ?? 0} 件
                {#if summary?.adopted}
                    <Badge tone="primary">採用済み</Badge>
                {/if}
            </p>
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
        {/if}
    </div>
{/snippet}
```

呼び出しは手順行・急所行の `rowFields` 直後に置く:

```svelte
                        <div class="mt-3">
                            {@render rowFields(step, `steps.${stepIndex}`, `step-${stepIndex}`)}
                        </div>
                        {@render videoCell(step.id)}
```

（急所行も `rowFields` 直後に `{@render videoCell(point.id)}` を置く）

**(6) `Manuals/Edit.svelte`**

```svelte
    interface Props {
        // …既存
        takeSummaries: CutTakeSummary[];
    }
    // …
    <ScenarioEditor {scenario} {takeSummaries} projectId={project.id} manualId={manual.id} />
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`takeSummaries(): array` + `@return list<array{...}>`、
      `CutTakeSummaryData::fromCut(): self` / `toArray(): array{...}`）
- [x] null 安全（`withCount` の結果は `getAttribute` + `Assert::integer`（既存
      `CaptureManualSummaryData` と同じ手法）、`adoptedTake` は `?->` と null 合体で分岐）
- [x] DTO を返している（props の shape は `CutTakeSummaryData` に集約。生配列の直組みなし）
- [x] Generics の型パラメータが正しい（`array_values(...->all())` で `list<>` を確定）

### テスト計画

- [ ] 新規 `tests/Feature/Manual/ScenarioVideoColumnTest.php`
  - `edit` の props に `takeSummaries` が含まれ、カット数分の行が `sort_order` 順で並ぶ
  - 採用テイクのあるカットは `adopted.id` / `adopted.status` が入り、無ければ `null`
  - **`takeSummaries` のキーに `adopted_take_id` が現れない**（gate 回避の命名の固定）
  - **cut を増やしてもクエリ本数が増えない**（`DB::listen` でカウントし、
    cut 3 件と 30 件で同数であることを assert。本数の完全一致では固定しない）
- [ ] 既存 `tests/js/pages/ManualsEdit.test.ts` に追加
  - 保存済み行には「テイクを選択」/「ファイルの選択」リンクが出る（href が新 route）
  - **未保存行（手順を追加した直後）にはリンクが出ず、保存を促す文言が出る**
  - 採用済みカットには「採用済み」バッジが出る
- [ ] 既存 `tests/Architecture/ScenarioWritePathInventoryTest` が green
      （`VideoManualController` に `adopted_take_id` を持ち込んでいないことの確認）
- [ ] 既存 `AdoptedReadyTakeCriterionInventoryTest` が entry 追加で green

### リスク

- **未保存編集からの遷移**: 動画セルのリンクを押すと `ScenarioEditor` の dirty 離脱確認
  （`router.on("before")`）が発火する。**これは正しい保護なので抑止しない**が、
  「編集途中にテイクを見たい」動線では毎回確認が出る。頻度が問題になった場合の対処は
  別タスク（例: セルからの遷移前に自動保存を提案する）とし、今回は作らない。
- **props 肥大**: cut 数 × 4 フィールド。100 カットでも数 KB で許容範囲。
- **スナップショット性**: 撮影 PWA 側での採用は編集画面をリロードするまで反映されない。

---

## 施策 4: PC ローカル動画のアップロード

### 変更箇所

- `resources/js/lib/capture/upload-queue.ts`（`createMemoryPendingStore()` を export 追加）
- `resources/js/components/features/manual/TakeFileUpload.svelte`（新規）
- `resources/js/pages/Manuals/Takes.svelte`（配線。施策 1 の (10) に含む）

### 波及変更

- **TypeScript 型定義**: なし（既存 `PendingStore` / `UploadOutcome` を使う）
- **API Resource/DTO**: なし（`capture.takes.upload-url` / `capture.takes.store` を再利用）
- **テストファイル**: `tests/js/lib/capture/upload-queue.test.ts`（既存に memory store のテストを追加） /
  `tests/js/pages/ManualsTakes.test.ts`（アップロード導線）

### 現行コード

`resources/js/lib/capture/upload-queue.ts`（抜粋）— `PendingStore` は注入で受ける:

```ts
export interface PendingStore {
    put(item: PendingUpload): Promise<void>;
    delete(clientTakeId: string): Promise<void>;
    list(): Promise<PendingUpload[]>;
}
// …
export class UploadQueue {
    constructor(options: UploadQueueOptions) { this.store = options.store; /* … */ }
    async enqueue(item: PendingUpload): Promise<UploadOutcome> { /* オンラインなら即時アップロード */ }
}
```

`resources/js/components/features/capture/CaptureFileFallback.svelte`（PC では描画されない）:

```svelte
    <input type="file" accept="video/*" capture="environment" class="hidden" onchange={handleChange} />
```

### 変更後コード

**(1) `upload-queue.ts`**（メモリ実装を追加。既存クラスは無変更）

```ts
/**
 * インスタンス生存中だけ保持する PendingStore (PC 面用)。
 * PC にはオフライン撮影の要件が無く、ページ遷移で失われてよい。
 * 撮影 PWA は従来どおり IndexedDB 実装 (lib/capture/idb.ts) を使う。
 */
export function createMemoryPendingStore(): PendingStore {
    const items = new Map<string, PendingUpload>();
    return {
        put: async (item) => { items.set(item.clientTakeId, item); },
        delete: async (clientTakeId) => { items.delete(clientTakeId); },
        list: async () => [...items.values()],
    };
}
```

**(2) `TakeFileUpload.svelte`**（新規）

```svelte
<script lang="ts">
    import { Upload } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import {
        createMemoryPendingStore,
        generateClientTakeId,
        UploadQueue,
    } from "@/lib/capture/upload-queue";

    /**
     * PC ローカル動画の追加アップロード (doc/04)。
     * 既存の presigned フロー (upload-url → S3 PUT → POST takes) を UploadQueue ごと再利用する
     * (アップロード実装を 2 本にしない)。MediaRecorder の有無に依存しない file input を使い、
     * capture 属性は付けない (PC ではファイルダイアログを開く)。
     */
    interface Props {
        projectId: number;
        manualId: number;
        cutId: number;
        onUploaded: () => void;
    }
    let { projectId, manualId, cutId, onUploaded }: Props = $props();

    // store を自前で保持するのは、queued (オフライン等) の Blob を PC 側に残さないため
    const store = createMemoryPendingStore();
    const queue = new UploadQueue({ store });
    let input: HTMLInputElement | null = $state(null);
    let uploading = $state(false);
    let error = $state<string | null>(null);

    /**
     * 尺の**事前チェック** (doc/04 「尺は 1 分まで」)。
     * これは保証ではない — サーバは尺を強制せず、duration_ms はクライアント申告値である。
     * metadata を読めない形式では判定自体が働かない。
     * 真の尺による拒否はエンコード段 (別タスク) の担当である。
     */
    const MAX_DURATION_MS = 60_000;

    /**
     * メタデータから尺を読む。読めなければ null を返し**事前チェックを行わない** (詰ませない)。
     * loadedmetadata / error / timeout(3s) の 3 経路をすべて閉じ、Object URL は必ず revoke する。
     */
    function readDurationMs(file: File): Promise<number | null> {
        return new Promise((resolve) => {
            const url = URL.createObjectURL(file);
            const video = document.createElement("video");
            let settled = false;
            const finish = (value: number | null): void => {
                if (settled) return;
                settled = true;
                clearTimeout(timer);
                video.onloadedmetadata = null;
                video.onerror = null;
                video.removeAttribute("src");
                URL.revokeObjectURL(url); // 経路によらず必ず解放する
                resolve(value);
            };
            const timer = setTimeout(() => finish(null), 3_000);
            video.preload = "metadata";
            video.onloadedmetadata = () =>
                finish(Number.isFinite(video.duration) ? Math.round(video.duration * 1000) : null);
            video.onerror = () => finish(null);
            video.src = url;
        });
    }

    async function handleChange(): Promise<void> {
        error = null;
        const file = input?.files?.[0];
        // どの経路を通っても input を空に戻す (同じファイルの再選択で change が出ない問題を避ける)
        try {
            if (!file) return;
            if (!file.type.startsWith("video/")) {
                error = "動画ファイルを選択してください。";
                return;
            }
            const durationMs = await readDurationMs(file);
            // 押下は受けてからエラーを出す (disabled にしない。AGENTS.md 禁止事項 8)。
            // 断定形にしない = サーバ強制ではないため「登録できません」とは書かない
            if (durationMs !== null && durationMs > MAX_DURATION_MS) {
                error = "動画の長さが 1 分を超えています。1 分以内に切り出してからアップロードしてください。";
                return; // upload-url を呼ばない = quota を消費しない
            }
            uploading = true;
            const clientTakeId = generateClientTakeId();
            const outcome = await queue.enqueue({
                clientTakeId,
                projectId, manualId, cutId,
                blob: file,
                contentType: file.type.split(";")[0],
                durationMs,
                capturedAt: new Date().toISOString(),
            });
            if (outcome.status === "uploaded") { onUploaded(); return; }
            if (outcome.status === "quota_exceeded") { error = outcome.message; return; }
            // queued = オフライン等。PC は保持しない方針なので Blob を捨ててから理由を出す
            await store.delete(outcome.clientTakeId);
            error = "アップロードできませんでした。接続を確認して再度お試しください。";
        } catch {
            // ネットワーク断 / presigned PUT の例外 / metadata 読み取りの reject。
            // 無反応にしない (即時アップロード経路は store.put() を通らないので Blob も残らない)
            error = "アップロードできませんでした。接続を確認して再度お試しください。";
        } finally {
            uploading = false;
            if (input) input.value = "";
        }
    }
</script>
```

- **`content_type` の制約**: サーバは `config('capture.allowed_video_content_types')`
  (`video/mp4` / `video/webm` / `video/quicktime`) 以外を 422 にする。
  クライアントで先回りの allowlist は持たない（設定の二重管理を作らない）。
  422 のサーバ文言をそのまま出す。
- **`size_bytes` の上限**: 同じく `config('capture.max_take_bytes')`（500 MiB）で
  サーバが 422。クライアントでは判定しない。

### PHPStan 適合チェック

- 本施策は frontend のみ（PHP 変更なし）。既存 `TakeUploadUrlController` /
  `TakeRegistrationService` は無変更。

### テスト計画

- [ ] 既存 `tests/js/lib/capture/upload-queue.test.ts` に追加
  - `createMemoryPendingStore()` が `put` / `delete` / `list` の契約を満たす
  - オフライン時の `enqueue` が `queued` を返し、`list()` に載る（既存クラスの振る舞い不変の確認）
- [ ] 新規 `tests/js/pages/ManualsTakes.test.ts` に追加
  - 動画以外のファイル選択でエラー文言（アップロードを開始しない）
  - 61 秒の動画で**事前チェック**のエラー文言（**upload-url を呼ばない** = quota を消費しない）。
    テスト名も「事前チェック」と書き、「1 分超は登録できない」という保証の名前にしない
  - **尺を読めない（metadata error / timeout）ファイルは事前チェックを飛ばして
    アップロードに進む**（読めないことで詰ませない）
  - `queued`（オフライン）のとき `store.delete()` が呼ばれ、Blob が残らない
  - **`enqueue()` が throw したとき**: エラー文言が表示され、`input.value` が空に戻り、
    `store.list()` が空のまま（無反応にならないことの固定）
  - 成功時に `router.reload({ only: ["cut", "takes"] })` が呼ばれる
  - 422 `quota_exceeded` のサーバ文言がそのまま表示される
  - どの経路でも `input.value` が空に戻る（同じファイルの再選択が効く）
- [ ] 既存 `tests/Feature/Capture/TakeUploadUrlTest.php` /
      `TakeRegistrationTest.php` が **無変更で** green（サーバ側を触っていないことの確認）
- [ ] 施策 1 の `PcTakeOperationTest` が編集者からの `upload-url` / `store` 成功を固定

### リスク

- **尺の制限は保証ではない（重要）**: 判定はクライアントの `loadedmetadata` だけであり、
  (a) metadata を読めない形式、(b) timeout、(c) 改竄された `duration_ms` のいずれでも
  1 分超の動画が登録される。**サーバは尺を強制しない。**
  したがって設計・UI 文言・テスト名のいずれでも
  **「1 分を超える動画は登録できない」とは書かない**（UI は事実の提示に留める）。
  真の尺による拒否は、エンコード段が入る将来タスクが ffprobe 等で行う。
- **メモリ store の取りこぼし**: アップロード中にページを離れると失われる。
  PC ではオフライン保持の要件が無いため受容する（PWA 側は従来どおり IndexedDB）。
  `queued` になった Blob は即 `store.delete()` するので、メモリに溜まり続けることはない。
- **quota の予約**: `upload-url` を呼んだ時点で容量が予約され、PUT 失敗時は予約が残る。
  既存の掃除 cron（`stale_verifying_minutes` / 期限切れ pending の release）が回収する。
  UI からの release 操作は作らない。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 4 施策は 1 つの画面を成立させるための縦串であり、単体では完成しない（施策 1 の画面が無ければ施策 2・4 の置き場が無く、施策 3 のリンク先も無い）。また施策 2 は `features/capture` の既存ファイルを移設するため、撮影 PWA を触る他タスクと同時進行させると衝突しやすい。1 つの worktree で通しで実装し、`AGENTS.md` の検証コマンド全 10 本（`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`）を green にしてからマージする |
| 競合リスク | **中**。(a) `routes/web.php` の業務 group（他タスクも route を足す位置）、(b) `resources/js/types/manual.ts`（型追加が集中する）、(c) `AdoptedTakeReferenceInventory` / `NestedRouteDefenseInventory`（目録は他タスクも触る）。いずれも追記のみで、意味的な競合ではない。**サムネイル生成タスクとは `SelectableTakeData` / `TakeThumbnail.svelte` で接触する**ため、どちらが先にマージされても後発が `thumbnail_url` を 1 フィールド足すだけで済むよう、差し替え点を `TakeThumbnail.svelte` 1 コンポーネントに閉じてある |

### 実装順序（依存順）

1. **施策 1**（route + Controller + DTO + 目録登録 + 画面の骨格）
   — 先に Feature テストを書いて fail を確認してから実装する（テストファースト）
2. **施策 2**（`SubtitleOverlay` 移設 → プレビューの字幕 / ナレーション原稿 ON/OFF）
3. **施策 3**（動画列と遷移導線。施策 1 の route が無いとリンク先が無い）
4. **施策 4**（アップロード。施策 1 の画面が置き場）
5. bug-hunt 目録の再生成 + ドキュメント 3 箇所の更新

### 完了条件チェック

- [ ] `composer test` / `composer phpstan` / `vendor/bin/pint --test`
- [ ] `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`
- [ ] `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
      （AGENTS.md の検証コマンド節が正本。本タスクは `packages/` を触らないが、
      コミット前は全レーン green が条件）
- [ ] `ScenarioWritePathInventoryTest` が**無変更で** green（新しい書き込み経路を作っていない）
- [ ] `NestedRouteIdorDefenseTest` / `TenantBoundaryOrderingTest` /
      `AdoptedReadyTakeCriterionInventoryTest` / `atomic-import-graph` が green
- [ ] `scripts/bug-hunt-inventory-check.sh` が exit 0
- [ ] `doc/10` / `docs/architecture.md` / `routes/web.php` コメントの 3 箇所を更新済み

## 実装差分 (git diff HEAD -- app/ resources/ tests/ routes/)

```diff
diff --git a/app/DataTransferObjects/Manual/CutTakeSummaryData.php b/app/DataTransferObjects/Manual/CutTakeSummaryData.php
new file mode 100644
index 0000000..a7aaed5
--- /dev/null
+++ b/app/DataTransferObjects/Manual/CutTakeSummaryData.php
@@ -0,0 +1,57 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Manual;
+
+use App\Models\Cut;
+use Webmozart\Assert\Assert;
+
+/**
+ * シナリオ編集画面「動画」列の 1 カット分。
+ * TS 側 types/manual.ts の CutTakeSummary と対で保守する。
+ *
+ * 採用テイクは `adopted` キーで返す — 採用テイク外部キーの識別子は
+ * ScenarioWritePathInventoryTest 検出 4 の deny-by-default 走査対象であり、
+ * 表示のために security gate の allowlist を広げないための命名である。
+ * 読み取りは adoptedTake relation 経由で行う。
+ */
+final readonly class CutTakeSummaryData
+{
+    public function __construct(
+        public int $cutId,
+        public int $takesCount,
+        public ?int $adoptedId,
+        public ?string $adoptedStatus,
+    ) {}
+
+    /** withCount('takes') + with('adoptedTake') 済みの cut から生成する */
+    public static function fromCut(Cut $cut): self
+    {
+        $takesCount = $cut->getAttribute('takes_count');
+        Assert::integer($takesCount, 'withCount(takes) 済みの cut を渡してください');
+        $adopted = $cut->adoptedTake;
+
+        return new self(
+            cutId: $cut->id,
+            takesCount: $takesCount,
+            adoptedId: $adopted?->id,
+            adoptedStatus: $adopted?->status->value,
+        );
+    }
+
+    /**
+     * @return array{cut_id: int, takes_count: int, adopted: array{id: int, status: string}|null}
+     */
+    public function toArray(): array
+    {
+        return [
+            'cut_id' => $this->cutId,
+            'takes_count' => $this->takesCount,
+            // id と status は同時に決まる (両方 null か両方非 null)
+            'adopted' => $this->adoptedId === null || $this->adoptedStatus === null
+                ? null
+                : ['id' => $this->adoptedId, 'status' => $this->adoptedStatus],
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Manual/SelectableTakeData.php b/app/DataTransferObjects/Manual/SelectableTakeData.php
new file mode 100644
index 0000000..4804123
--- /dev/null
+++ b/app/DataTransferObjects/Manual/SelectableTakeData.php
@@ -0,0 +1,51 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Manual;
+
+use App\Models\Take;
+
+/**
+ * PC テイク選択画面が受け取るテイク 1 件の shape。
+ * TS 側 types/manual.ts の SelectableTake と対で保守する。
+ *
+ * **署名 URL / 保存パスのスロットを構造として持たない**。
+ * 撮影 PWA 用の CaptureTakeData は採用テイクへ署名 URL を載せる口を持つため、
+ * 似ていても合流させない (概念設計 D2。「今は null だから安全」を作らない)。
+ * 再生は capture.takes.playback (302 + no-store)、サムネイル取得は
+ * capture.takes.thumbnail 経由のみである。
+ */
+final readonly class SelectableTakeData
+{
+    public function __construct(
+        public Take $take,
+    ) {}
+
+    public static function fromTake(Take $take): self
+    {
+        return new self($take);
+    }
+
+    /**
+     * @return array{id: int, status: string, size_bytes: int, duration_ms: int|null,
+     *   comment: string|null, captured_at: string|null, sort_order: int, downloaded: bool,
+     *   has_thumbnail: bool}
+     */
+    public function toArray(): array
+    {
+        return [
+            'id' => $this->take->id,
+            'status' => $this->take->status->value,
+            'size_bytes' => $this->take->size_bytes,
+            'duration_ms' => $this->take->duration_ms,
+            'comment' => $this->take->comment,
+            'captured_at' => $this->take->captured_at?->toIso8601String(),
+            'sort_order' => $this->take->sort_order,
+            // DL 済みテイクは削除できない (422)。理由を押下前に説明するために出す
+            'downloaded' => $this->take->downloaded_at !== null,
+            // サムネイル生成は非同期。true のときだけ画像 URL を張る (撮影 PWA と同じ判断)
+            'has_thumbnail' => $this->take->thumbnail_path !== null,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Manual/TakeSelectionPageData.php b/app/DataTransferObjects/Manual/TakeSelectionPageData.php
new file mode 100644
index 0000000..c8daf06
--- /dev/null
+++ b/app/DataTransferObjects/Manual/TakeSelectionPageData.php
@@ -0,0 +1,102 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Manual;
+
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\VideoManual;
+use App\Services\Manual\CutSequencer;
+
+/**
+ * PC テイク選択画面 (Manuals/Takes) の Inertia props 全体。
+ * TS 側 types/manual.ts の TakeSelectionPageProps と対で保守する。
+ *
+ * 表示ラベル (手順N / 急所N-M) は CutSequencer::orderedWithLabels() から取る
+ * (レンダの欠落ラベル・マニフェストと同じ導出元。ラベル規則を増やさない)。
+ * 採用テイクは `adopted` キーで出す — 採用テイク外部キーの識別子は
+ * ScenarioWritePathInventoryTest 検出 4 の deny-by-default 走査対象であり、
+ * 表示のために security gate の allowlist を広げないための命名である。
+ */
+final readonly class TakeSelectionPageData
+{
+    /** @param list<SelectableTakeData> $takes */
+    public function __construct(
+        public Project $project,
+        public VideoManual $manual,
+        public Cut $cut,
+        public string $label,
+        public array $takes,
+    ) {}
+
+    public static function fromCut(Project $project, VideoManual $manual, Cut $cut): self
+    {
+        // route binding 済みの $cut は relation 未ロードなので明示的に読む
+        // (暗黙の追加クエリを残さない)。
+        $cut->loadMissing('adoptedTake');
+
+        // 見つからないのは「親を持たない急所」= データ異常のときだけ。
+        // 画面タイトルを空にせず中立語へ倒す (静かに空にして異常を隠さない)
+        $label = 'カット';
+        foreach (CutSequencer::orderedWithLabels($manual) as $ordered) {
+            if ($ordered->cut->id === $cut->id) {
+                $label = $ordered->label;
+                break;
+            }
+        }
+
+        /** @var list<SelectableTakeData> $takes */
+        $takes = $cut->takes()
+            ->orderBy('sort_order')
+            ->orderBy('id')
+            ->get()
+            ->map(static fn (Take $take): SelectableTakeData => SelectableTakeData::fromTake($take))
+            ->values()
+            ->all();
+
+        return new self($project, $manual, $cut, $label, $takes);
+    }
+
+    /**
+     * @return array{project: array{id: int, name: string},
+     *   manual: array{id: int, title: string, status: string},
+     *   cut: array{id: int, type: string, label: string, scene: string, narration: string,
+     *     subtitle_primary: string|null, subtitle_secondary: string,
+     *     adopted: array{id: int, status: string}|null},
+     *   takes: list<array{id: int, status: string, size_bytes: int, duration_ms: int|null,
+     *     comment: string|null, captured_at: string|null, sort_order: int, downloaded: bool,
+     *     has_thumbnail: bool}>}
+     */
+    public function toArray(): array
+    {
+        $adopted = $this->cut->adoptedTake;
+
+        return [
+            'project' => ['id' => $this->project->id, 'name' => $this->project->name],
+            'manual' => [
+                'id' => $this->manual->id,
+                'title' => $this->manual->title,
+                // rendering / analyzing 中は採用が 409 になることの事前告知に使う
+                'status' => $this->manual->status->value,
+            ],
+            'cut' => [
+                'id' => $this->cut->id,
+                'type' => $this->cut->type->value,
+                'label' => $this->label,
+                'scene' => $this->cut->scene,
+                'narration' => $this->cut->narration,
+                'subtitle_primary' => $this->cut->subtitle_primary,
+                'subtitle_secondary' => $this->cut->subtitle_secondary,
+                'adopted' => $adopted === null
+                    ? null
+                    : ['id' => $adopted->id, 'status' => $adopted->status->value],
+            ],
+            'takes' => array_map(
+                static fn (SelectableTakeData $take): array => $take->toArray(),
+                $this->takes,
+            ),
+        ];
+    }
+}
diff --git a/app/Http/Controllers/Projects/CutTakeController.php b/app/Http/Controllers/Projects/CutTakeController.php
new file mode 100644
index 0000000..b4ffd12
--- /dev/null
+++ b/app/Http/Controllers/Projects/CutTakeController.php
@@ -0,0 +1,54 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Projects;
+
+use App\DataTransferObjects\Manual\TakeSelectionPageData;
+use App\Http\Concerns\ResolvesCurrentOrganization;
+use App\Http\Controllers\Controller;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\VideoManual;
+use App\Support\Seo\SeoManager;
+use Illuminate\Http\Request;
+use Illuminate\Support\Facades\Gate;
+use Inertia\Inertia;
+use Inertia\Response;
+
+/**
+ * テイク選択・採用画面 (doc/04)。編集者がカットごとのテイクを見て採用を確定する面。
+ *
+ * nested route の URL 整合は 2 層 (認可より前に 404):
+ * 1. {project} ∈ current org (project.in-current-org middleware + resolveOrganizationProject)
+ * 2. {manual} ∈ {project}, {cut} ∈ {manual} (Route::scopeBindings())
+ *
+ * 本 controller は**読み取りのみ**である。採用・削除・アップロード・再生は
+ * capture.takes.* (撮影 PWA と共用の API 面) が担い、cuts の採用テイク外部キーを書くのは
+ * 従来どおり Capture/CaptureTakeService::adopt() だけである
+ * (AGENTS.md ドメイン固有規約 1 / ScenarioWritePathInventoryTest 検出 4)。
+ */
+class CutTakeController extends Controller
+{
+    use ResolvesCurrentOrganization;
+
+    /** テイク選択画面 (編集者のみ。撮影者は 403 = PWA 側に採用導線がある) */
+    public function index(
+        Request $request,
+        Project $project,
+        VideoManual $manual,
+        Cut $cut,
+        SeoManager $seo,
+    ): Response {
+        $organization = $this->resolveCurrentOrganization($request);
+        // URL 整合 guard: 認可より前に 404 ({manual}∈{project}, {cut}∈{manual} は scopeBindings)
+        $this->resolveOrganizationProject($organization, $project);
+        Gate::authorize('update', $manual); // VideoManualPolicy::update = 編集者
+
+        $page = TakeSelectionPageData::fromCut($project, $manual, $cut);
+        // 並行編集タブを判別できる動的固有名 (noindex 維持。既存 edit/show と同方針)
+        $seo->setPrivateTitle($manual->title.' / '.$page->label.' のテイク選択');
+
+        return Inertia::render('Manuals/Takes', $page->toArray());
+    }
+}
diff --git a/app/Http/Controllers/Projects/VideoManualController.php b/app/Http/Controllers/Projects/VideoManualController.php
index 3e0c199..3dc31bf 100644
--- a/app/Http/Controllers/Projects/VideoManualController.php
+++ b/app/Http/Controllers/Projects/VideoManualController.php
@@ -5,6 +5,7 @@
 namespace App\Http\Controllers\Projects;
 
 use App\DataTransferObjects\Manual\AnalysisJobData;
+use App\DataTransferObjects\Manual\CutTakeSummaryData;
 use App\DataTransferObjects\Manual\ManualListQuery;
 use App\DataTransferObjects\Manual\RenderJobData;
 use App\DataTransferObjects\Manual\ScenarioDocumentData;
@@ -16,6 +17,7 @@
 use App\Http\Requests\Projects\StoreVideoManualRequest;
 use App\Http\Requests\Projects\UpdateVideoManualRequest;
 use App\Models\Category;
+use App\Models\Cut;
 use App\Models\Project;
 use App\Models\User;
 use App\Models\VideoManual;
@@ -214,9 +216,33 @@ public function edit(Request $request, Project $project, VideoManual $manual, Se
             'categories' => $this->categoryOptions($project),
             // シナリオ document (保存成功応答 ScenarioResource と同一 shape)
             'scenario' => ScenarioDocumentData::fromManual($manual)->toArray(),
+            // 動画列 (カットごとのテイク要約)。描画時点のスナップショットであり常に最新ではない
+            // (採用は他タブ / 撮影 PWA からも起きる。判断はサーバの行ロックが直列化する)
+            'takeSummaries' => $this->takeSummaries($manual),
         ]);
     }
 
+    /**
+     * 動画列用のカット別テイク要約。
+     *
+     * cut 件数に依存しない**定数本のクエリ**で取る (withCount は cuts の SELECT に畳まれ、
+     * adoptedTake は eager load の 1 本。cut ごとの追加クエリ = N+1 を作らない)。
+     * 並びは CutSequencer と同じ (sort_order, id) にする (同値 sort_order で揺れないため)。
+     *
+     * @return list<array{cut_id: int, takes_count: int, adopted: array{id: int, status: string}|null}>
+     */
+    private function takeSummaries(VideoManual $manual): array
+    {
+        return array_values($manual->cuts()
+            ->withCount('takes')
+            ->with('adoptedTake')
+            ->orderBy('sort_order')
+            ->orderBy('id')
+            ->get()
+            ->map(static fn (Cut $cut): array => CutTakeSummaryData::fromCut($cut)->toArray())
+            ->all());
+    }
+
     /** メタデータ更新 (title / category)。category null は未分類化 */
     public function update(UpdateVideoManualRequest $request, Project $project, VideoManual $manual, VideoManualService $manuals): RedirectResponse
     {
diff --git a/app/Support/Security/AdoptedTakeReferenceInventory.php b/app/Support/Security/AdoptedTakeReferenceInventory.php
index 7f33c86..443c9ec 100644
--- a/app/Support/Security/AdoptedTakeReferenceInventory.php
+++ b/app/Support/Security/AdoptedTakeReferenceInventory.php
@@ -56,6 +56,24 @@ public static function entries(): array
                 'rationale' => '撮影ナビの表示用に採用テイクの実体を読むだけで ready 判定はしない。'
                     .'撮影中の端末に「今どれを採用しているか」を見せる別概念の面である。',
             ],
+            'DataTransferObjects/Manual/CutTakeSummaryData.php' => [
+                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
+                'rationale' => 'シナリオ編集画面の動画列が、カットごとに採用テイクの id と status を'
+                    .'表示するために読むだけで ready 判定はしない。レンダの充足判定'
+                    .'(AdoptedReadyTakeCoverage) とは基準が違うため意図的に統合しない。',
+            ],
+            'DataTransferObjects/Manual/TakeSelectionPageData.php' => [
+                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
+                'rationale' => 'PC テイク選択画面が「今どれを採用しているか」を示すために'
+                    .'採用テイクの id と status を読むだけで、ready 判定も充足判定もしない。'
+                    .'レンダの充足判定 (AdoptedReadyTakeCoverage) とは意図的に統合しない。',
+            ],
+            'Http/Controllers/Projects/VideoManualController.php' => [
+                'kind' => AdoptedTakeReferenceKind::RelationWiring,
+                'rationale' => 'シナリオ編集画面の動画列を N+1 なしで取るため with(adoptedTake) の'
+                    .'eager load を張るだけで、判定も読み取りも持たない。値の取り出しは'
+                    .'CutTakeSummaryData 側にあり、そちらが別基準として登録済みである。',
+            ],
             'Http/Controllers/Capture/CaptureManualController.php' => [
                 'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                 'rationale' => 'whereHas(adoptedTake) による採用済みカット数の集計。'
diff --git a/resources/js/components/features/capture/CameraRecorder.svelte b/resources/js/components/features/capture/CameraRecorder.svelte
index 847ae4d..020b254 100644
--- a/resources/js/components/features/capture/CameraRecorder.svelte
+++ b/resources/js/components/features/capture/CameraRecorder.svelte
@@ -13,7 +13,7 @@
     } from "@lucide/svelte";
     import Button from "@/components/atoms/Button.svelte";
     import GridOverlay from "@/components/features/capture/GridOverlay.svelte";
-    import SubtitleOverlay from "@/components/features/capture/SubtitleOverlay.svelte";
+    import SubtitleOverlay from "@/components/molecules/SubtitleOverlay.svelte";
     import {
         classifyGetUserMediaError,
         formatElapsed,
diff --git a/resources/js/components/features/capture/TakeStrip.svelte b/resources/js/components/features/capture/TakeStrip.svelte
index df684cc..6df1920 100644
--- a/resources/js/components/features/capture/TakeStrip.svelte
+++ b/resources/js/components/features/capture/TakeStrip.svelte
@@ -6,6 +6,7 @@
     import TakePreviewDialog from "@/components/features/capture/TakePreviewDialog.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
     import { captureJson, extractErrorMessage } from "@/lib/capture/http";
+    import { takeUrl as buildTakeUrl } from "@/lib/capture/take-endpoints";
     import type { CaptureCut, CaptureTake } from "@/types/capture";
 
     /**
@@ -82,8 +83,9 @@
         deleteLabel = ""; // 再オープン時の古い文言混入を防ぐ (design-review S1 Suggestion)
     }
 
+    // URL 規則は lib/capture/take-endpoints に 1 箇所化してある (PC 編集面と共用の API 面)
     function takeUrl(take: CaptureTake, suffix = ""): string {
-        return `/app/projects/${projectId}/manuals/${manualId}/cuts/${cut.id}/takes/${take.id}${suffix}`;
+        return buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, suffix);
     }
 
     async function run(take: CaptureTake, action: () => Promise<Response>): Promise<void> {
diff --git a/resources/js/components/features/manual/ScenarioEditor.svelte b/resources/js/components/features/manual/ScenarioEditor.svelte
index 72fc02b..83942e1 100644
--- a/resources/js/components/features/manual/ScenarioEditor.svelte
+++ b/resources/js/components/features/manual/ScenarioEditor.svelte
@@ -5,6 +5,7 @@
         Check,
         ChevronDown,
         ChevronUp,
+        Film,
         ListPlus,
         Plus,
         Redo2,
@@ -12,6 +13,7 @@
         Undo2,
     } from "@lucide/svelte";
     import Alert from "@/components/atoms/Alert.svelte";
+    import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
     import Input from "@/components/atoms/Input.svelte";
@@ -24,6 +26,7 @@
     import { boundHistory, parseHistorySnapshot, pushHistory } from "@/lib/manual/scenario-history";
     import { addToast } from "@/lib/stores/toast";
     import type {
+        CutTakeSummary,
         DraftPoint,
         DraftStep,
         ScenarioConflictBody,
@@ -41,9 +44,16 @@
         projectId: number;
         manualId: number;
         scenario: ScenarioDocument;
+        /** 動画列 (カットごとのテイク要約)。未保存行 (id=null) には対応する要約が無い */
+        takeSummaries: CutTakeSummary[];
     }
 
-    let { projectId, manualId, scenario }: Props = $props();
+    let { projectId, manualId, scenario, takeSummaries }: Props = $props();
+
+    /** cut_id → 要約の索引 (行ごとの線形探索を避ける) */
+    const summaryByCutId = $derived(
+        new Map(takeSummaries.map((summary) => [summary.cut_id, summary])),
+    );
 
     // インスタンス内カウンタ (instance script 宣言 = コンポーネントインスタンスごとに独立)。
     // 採番値は履歴文字列に保存され undo/redo で round-trip する。
@@ -819,6 +829,39 @@
     </div>
 {/snippet}
 
+{#snippet videoCell(cutId: number | null, testIdSuffix: string)}
+    <!-- 動画列 (doc/04)。未保存行はリンクを出さず、押せるのに詰むボタンを作らない。
+         行 Card の中に角丸カードを入れ子にせず、区切り線で段を分ける -->
+    <div class="mt-3 border-t border-border pt-3" data-testid={`video-cell-${testIdSuffix}`}>
+        <p class="text-caption text-text-secondary">動画</p>
+        {#if cutId === null}
+            <p class="mt-1 text-caption text-text-secondary" data-testid="video-cell-unsaved">
+                「シナリオを更新」で保存すると、このカットに動画を登録できます。
+            </p>
+        {:else}
+            {@const summary = summaryByCutId.get(cutId)}
+            <p class="mt-1 flex items-center gap-2 text-caption text-text">
+                <span data-testid="video-cell-count">テイク {summary?.takes_count ?? 0} 件</span>
+                {#if summary?.adopted}
+                    <Badge tone="primary" testId="video-cell-adopted">採用済み</Badge>
+                {/if}
+            </p>
+            <div class="mt-2">
+                <Button
+                    variant="neutral"
+                    size="sm"
+                    href={`/projects/${projectId}/manuals/${manualId}/cuts/${cutId}/takes`}
+                    inertia
+                    testId="video-cell-link"
+                >
+                    <Film class="size-4" aria-hidden="true" />
+                    {summary && summary.takes_count > 0 ? "テイクを選択" : "ファイルの選択"}
+                </Button>
+            </div>
+        {/if}
+    </div>
+{/snippet}
+
 <section
     aria-label="シナリオ編集"
     onfocusin={onEditorFocusIn}
@@ -880,6 +923,7 @@
                         <div class="mt-3">
                             {@render rowFields(step, `steps.${stepIndex}`, `step-${stepIndex}`)}
                         </div>
+                        {@render videoCell(step.id, `step-${stepIndex}`)}
 
                         {#if step.points.length > 0}
                             <ol class="mt-4 flex flex-col gap-3 border-l-2 border-border pl-4">
@@ -929,6 +973,10 @@
                                                 `point-${stepIndex}-${pointIndex}`,
                                             )}
                                         </div>
+                                        {@render videoCell(
+                                            point.id,
+                                            `point-${stepIndex}-${pointIndex}`,
+                                        )}
                                     </li>
                                 {/each}
                             </ol>
diff --git a/resources/js/components/features/manual/TakeFileUpload.svelte b/resources/js/components/features/manual/TakeFileUpload.svelte
new file mode 100644
index 0000000..fa36e7b
--- /dev/null
+++ b/resources/js/components/features/manual/TakeFileUpload.svelte
@@ -0,0 +1,155 @@
+<script lang="ts">
+    import { Upload } from "@lucide/svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Card from "@/components/atoms/Card.svelte";
+    import {
+        createMemoryPendingStore,
+        generateClientTakeId,
+        UploadQueue,
+    } from "@/lib/capture/upload-queue";
+
+    /**
+     * PC ローカル動画の追加アップロード (doc/04)。
+     * 既存の presigned フロー (upload-url → S3 PUT → POST takes) を UploadQueue ごと再利用する
+     * (アップロード実装を 2 本にしない)。MediaRecorder の有無に依存しない file input を使い、
+     * capture 属性は付けない (PC ではファイルダイアログを開く)。
+     */
+    interface Props {
+        projectId: number;
+        manualId: number;
+        cutId: number;
+        onUploaded: () => void;
+    }
+
+    let { projectId, manualId, cutId, onUploaded }: Props = $props();
+
+    // store を自前で保持するのは、queued (オフライン等) の Blob を PC 側に残さないため
+    const store = createMemoryPendingStore();
+    const queue = new UploadQueue({ store });
+    let input: HTMLInputElement | null = $state(null);
+    let uploading = $state(false);
+    let error = $state<string | null>(null);
+
+    /**
+     * 尺の**事前チェック** (doc/04 「尺は 1 分まで」)。
+     * これは保証ではない — サーバは尺を強制せず、duration_ms はクライアント申告値である。
+     * metadata を読めない形式では判定自体が働かない。
+     * 真の尺による拒否はエンコード段 (別タスク) の担当である。
+     */
+    const MAX_DURATION_MS = 60_000;
+
+    /**
+     * メタデータから尺を読む。読めなければ null を返し**事前チェックを行わない** (詰ませない)。
+     * loadedmetadata / error / timeout(3s) の 3 経路をすべて閉じ、Object URL は必ず revoke する。
+     */
+    function readDurationMs(file: File): Promise<number | null> {
+        return new Promise((resolve) => {
+            const url = URL.createObjectURL(file);
+            const video = document.createElement("video");
+            let settled = false;
+            const finish = (value: number | null): void => {
+                if (settled) return;
+                settled = true;
+                clearTimeout(timer);
+                video.onloadedmetadata = null;
+                video.onerror = null;
+                video.removeAttribute("src");
+                URL.revokeObjectURL(url); // 経路によらず必ず解放する
+                resolve(value);
+            };
+            const timer = setTimeout(() => finish(null), 3_000);
+            video.preload = "metadata";
+            video.onloadedmetadata = () =>
+                finish(Number.isFinite(video.duration) ? Math.round(video.duration * 1000) : null);
+            video.onerror = () => finish(null);
+            video.src = url;
+        });
+    }
+
+    async function handleChange(): Promise<void> {
+        error = null;
+        const file = input?.files?.[0];
+        // どの経路を通っても input を空に戻す (同じファイルの再選択で change が出ない問題を避ける)
+        try {
+            if (!file) return;
+            if (!file.type.startsWith("video/")) {
+                error = "動画ファイルを選択してください。";
+                return;
+            }
+            const durationMs = await readDurationMs(file);
+            // 押下は受けてからエラーを出す (disabled にしない)。
+            // 断定形にしない = サーバ強制ではないため「登録できません」とは書かない
+            if (durationMs !== null && durationMs > MAX_DURATION_MS) {
+                error =
+                    "動画の長さが 1 分を超えています。1 分以内に切り出してからアップロードしてください。";
+                return; // upload-url を呼ばない = quota を消費しない
+            }
+            uploading = true;
+            const clientTakeId = generateClientTakeId();
+            const outcome = await queue.enqueue({
+                clientTakeId,
+                projectId,
+                manualId,
+                cutId,
+                blob: file,
+                contentType: file.type.split(";")[0],
+                durationMs,
+                capturedAt: new Date().toISOString(),
+            });
+            if (outcome.status === "uploaded") {
+                onUploaded();
+                return;
+            }
+            if (outcome.status === "quota_exceeded") {
+                error = outcome.message; // 422 のサーバ文言をそのまま出す
+                return;
+            }
+            // queued = オフライン等。PC は保持しない方針なので Blob を捨ててから理由を出す
+            await store.delete(outcome.clientTakeId);
+            error = "アップロードできませんでした。接続を確認して再度お試しください。";
+        } catch {
+            // ネットワーク断 / presigned PUT の例外 / metadata 読み取りの reject。
+            // 無反応にしない (即時アップロード経路は store.put() を通らないので Blob も残らない)
+            error = "アップロードできませんでした。接続を確認して再度お試しください。";
+        } finally {
+            uploading = false;
+            if (input) input.value = "";
+        }
+    }
+</script>
+
+<Card padding="md" testId="take-file-upload">
+    <h2 class="text-body font-medium text-text">動画ファイルを追加</h2>
+    <p class="mt-1 text-caption text-text-secondary">
+        PC にある動画を、このカットのテイクとして追加できます (1 分以内が目安です)。
+    </p>
+    <!--
+      file input は視覚的に隠し、押下導線は Button atom に寄せる
+      (DESIGN.md: 素の input を画面に置かない)。capture 属性は付けない = PC では
+      ファイルダイアログが開く。
+    -->
+    <input
+        bind:this={input}
+        type="file"
+        accept="video/*"
+        class="hidden"
+        onchange={handleChange}
+        data-testid="take-file-input"
+    />
+    <div class="mt-3">
+        <Button
+            variant="neutral"
+            loading={uploading}
+            onclick={() => input?.click()}
+            testId="take-file-select"
+        >
+            <Upload class="size-4" aria-hidden="true" />
+            動画ファイルを選ぶ
+        </Button>
+    </div>
+    {#if error}
+        <p class="mt-2 text-caption text-danger" role="alert" data-testid="take-upload-error">
+            {error}
+        </p>
+    {/if}
+</Card>
diff --git a/resources/js/components/features/manual/TakePickerList.svelte b/resources/js/components/features/manual/TakePickerList.svelte
new file mode 100644
index 0000000..2e303d8
--- /dev/null
+++ b/resources/js/components/features/manual/TakePickerList.svelte
@@ -0,0 +1,166 @@
+<script lang="ts">
+    import { Trash2 } from "@lucide/svelte";
+    import Badge from "@/components/atoms/Badge.svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import TakeThumbnail from "@/components/features/manual/TakeThumbnail.svelte";
+    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
+    import { captureJson, extractErrorMessage } from "@/lib/capture/http";
+    import { takeUrl as buildTakeUrl } from "@/lib/capture/take-endpoints";
+    import { formatBytes } from "@/lib/format-bytes";
+    import { TAKE_STATUS_LABELS, type SelectableTake } from "@/types/manual";
+
+    /**
+     * テイク一覧 (PC テイク選択画面の左ペイン)。選択とテイク削除を担う。
+     * 採用の確定はプレビュー側 (TakePreviewPanel) が行う。
+     * 削除は撮影 PWA と同じ capture.takes.destroy を叩き、DL 済み (422) の理由は
+     * サーバ供給の文言をそのまま表示する (UI 側で理由を再実装しない)。
+     */
+    interface Props {
+        takes: SelectableTake[];
+        /** 現在の採用テイク id (青枠の対象) */
+        adoptedTakeId: number | null;
+        selectedTakeId: number | null;
+        onSelect: (takeId: number) => void;
+        projectId: number;
+        manualId: number;
+        cutId: number;
+        /** 削除成功後の再取得 */
+        onChanged: () => void;
+    }
+
+    let {
+        takes,
+        adoptedTakeId,
+        selectedTakeId,
+        onSelect,
+        projectId,
+        manualId,
+        cutId,
+        onChanged,
+    }: Props = $props();
+
+    let error = $state<string | null>(null);
+    let busyTakeId = $state<number | null>(null);
+
+    // 削除確認: id と表示ラベルをスナップショット保持する (再取得で参照内容がずれないため)
+    let deleteTargetId = $state<number | null>(null);
+    let deleteLabel = $state("");
+    let deleteDialogOpen = $state(false);
+
+    function thumbnailUrl(take: SelectableTake): string | null {
+        return take.has_thumbnail
+            ? buildTakeUrl({ projectId, manualId, cutId }, take.id, "/thumbnail")
+            : null;
+    }
+
+    function requestDelete(take: SelectableTake, index: number): void {
+        error = null;
+        deleteTargetId = take.id;
+        deleteLabel = `テイク ${index + 1}`;
+        deleteDialogOpen = true;
+    }
+
+    /** 「削除する」確定時のみ DELETE を送る (押下は常に受け、422 はサーバ文言を表示する) */
+    async function confirmDelete(): Promise<void> {
+        const id = deleteTargetId;
+        if (id === null) return;
+        busyTakeId = id;
+        try {
+            const response = await captureJson(
+                buildTakeUrl({ projectId, manualId, cutId }, id),
+                "DELETE",
+            );
+            if (!response.ok) {
+                error = await extractErrorMessage(response);
+                return;
+            }
+            onChanged();
+        } catch {
+            error = "通信に失敗しました。ネットワークを確認してください。";
+        } finally {
+            busyTakeId = null;
+            deleteDialogOpen = false;
+            deleteTargetId = null;
+            deleteLabel = "";
+        }
+    }
+</script>
+
+<div class="flex flex-col gap-2" data-testid="take-picker-list">
+    {#if takes.length === 0}
+        <p class="text-caption text-text-secondary" data-testid="take-picker-empty">
+            このカットにはまだ動画がありません。スマホで撮影するか、下のフォームから動画ファイルを追加してください。
+        </p>
+    {/if}
+    <ul class="flex flex-col gap-2">
+        {#each takes as take, index (take.id)}
+            <li
+                class="flex items-start gap-2 rounded-md border p-2 {adoptedTakeId === take.id
+                    ? 'border-primary'
+                    : 'border-border'} {selectedTakeId === take.id ? 'bg-primary-soft' : 'bg-surface'}"
+                data-testid={`take-tile-${take.id}`}
+            >
+                <button
+                    type="button"
+                    class="flex min-w-0 flex-1 items-start gap-2 text-left"
+                    aria-current={selectedTakeId === take.id ? "true" : undefined}
+                    onclick={() => onSelect(take.id)}
+                    data-testid={`take-select-${take.id}`}
+                >
+                    <TakeThumbnail
+                        {index}
+                        status={take.status}
+                        durationMs={take.duration_ms}
+                        thumbnailUrl={thumbnailUrl(take)}
+                        testId={`take-thumbnail-${take.id}`}
+                    />
+                    <span class="flex min-w-0 flex-col gap-1">
+                        <span class="flex flex-wrap items-center gap-1 text-body text-text">
+                            テイク {index + 1}
+                            {#if adoptedTakeId === take.id}
+                                <Badge tone="primary" testId={`take-adopted-${take.id}`}>採用中</Badge>
+                            {/if}
+                            {#if take.downloaded}
+                                <Badge tone="neutral">DL 済み</Badge>
+                            {/if}
+                        </span>
+                        <span class="text-caption text-text-secondary">
+                            {TAKE_STATUS_LABELS[take.status]}・{formatBytes(take.size_bytes)}
+                            {#if take.duration_ms !== null}
+                                ・{Math.round(take.duration_ms / 1000)} 秒
+                            {/if}
+                        </span>
+                        {#if take.comment}
+                            <span class="text-caption text-text-secondary">{take.comment}</span>
+                        {/if}
+                    </span>
+                </button>
+                <Button
+                    variant="danger-ghost"
+                    size="sm"
+                    iconOnly
+                    ariaLabel={`テイク ${index + 1} を削除`}
+                    loading={busyTakeId === take.id}
+                    onclick={() => requestDelete(take, index)}
+                    testId={`take-delete-${take.id}`}
+                >
+                    <Trash2 class="size-4" aria-hidden="true" />
+                </Button>
+            </li>
+        {/each}
+    </ul>
+    {#if error}
+        <p class="text-caption text-danger" role="alert" data-testid="take-picker-error">{error}</p>
+    {/if}
+</div>
+
+<ConfirmDialog
+    bind:open={deleteDialogOpen}
+    title="テイクの削除"
+    message={`${deleteLabel}を削除しますか？ この操作は取り消せません。動画は完全に削除されます。`}
+    confirmLabel="削除する"
+    confirmVariant="danger"
+    processing={deleteTargetId !== null && busyTakeId === deleteTargetId}
+    onConfirm={confirmDelete}
+    testId="take-delete-dialog"
+/>
diff --git a/resources/js/components/features/manual/TakePreviewPanel.svelte b/resources/js/components/features/manual/TakePreviewPanel.svelte
new file mode 100644
index 0000000..546d001
--- /dev/null
+++ b/resources/js/components/features/manual/TakePreviewPanel.svelte
@@ -0,0 +1,182 @@
+<script lang="ts">
+    import { Check } from "@lucide/svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Card from "@/components/atoms/Card.svelte";
+    import Checkbox from "@/components/atoms/Checkbox.svelte";
+    import TakeThumbnail from "@/components/features/manual/TakeThumbnail.svelte";
+    import SubtitleOverlay from "@/components/molecules/SubtitleOverlay.svelte";
+    import { captureJson, extractErrorMessage } from "@/lib/capture/http";
+    import { takeUrl as buildTakeUrl } from "@/lib/capture/take-endpoints";
+    import {
+        TAKE_ADOPTABLE_BY_STATUS,
+        TAKE_STATUS_LABELS,
+        type SelectableTake,
+        type TakeSelectionCut,
+        type VideoManualStatus,
+    } from "@/types/manual";
+
+    /**
+     * 選択中テイクのプレビューと採用 (PC テイク選択画面の中央ペイン)。
+     * 再生は capture.takes.playback (302 + no-store) 経由で、署名 URL を props に載せない。
+     * 採用は撮影 PWA と同じ capture.takes.adopt を叩き、409 (書き出し中/解析中) や
+     * 422 (未処理テイク) はサーバ供給の文言をそのまま表示する。
+     */
+    interface Props {
+        take: SelectableTake | null;
+        /** 一覧内の 0 始まり位置 (表示は +1)。未選択なら null */
+        takeIndex: number | null;
+        cut: TakeSelectionCut;
+        /** 書き出し中 / 解析中は採用が 409 になることの事前告知に使う */
+        manualStatus: VideoManualStatus;
+        projectId: number;
+        manualId: number;
+        onChanged: () => void;
+    }
+
+    let { take, takeIndex, cut, manualStatus, projectId, manualId, onChanged }: Props = $props();
+
+    // doc/04 「プレビューにナレーション/字幕を ON/OFF (初期は両方オフ)」。
+    // v1 は TTS 非実装のため、ナレーションは**原稿テキストの表示**の切替である
+    // (音声は再生しない)。ラベルにも「原稿」と書き、音が出ると誤解させない。
+    let showSubtitles = $state(false);
+    let showNarrationScript = $state(false);
+
+    let error = $state<string | null>(null);
+    let busy = $state(false);
+
+    // ready 以外はサーバが 404 を返すため src を張らず <video> 自体を描かない
+    // (無駄な要素とネットワーク要求を出さない)
+    const playbackUrl = $derived(
+        take !== null && take.status === "ready"
+            ? buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, "/playback")
+            : null,
+    );
+
+    const thumbnailUrl = $derived(
+        take !== null && take.has_thumbnail
+            ? buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, "/thumbnail")
+            : null,
+    );
+
+    const busyStatusNotice = $derived(
+        manualStatus === "rendering"
+            ? "書き出し中は採用を確定できません。完了後にもう一度お試しください。"
+            : manualStatus === "analyzing"
+              ? "AI 解析中は採用を確定できません。完了後にもう一度お試しください。"
+              : null,
+    );
+
+    /** 採用の確定。押下は常に受け、条件未充足はエラー表示で返す (disabled にしない) */
+    async function adopt(): Promise<void> {
+        error = null;
+        if (take === null) {
+            error = "左の一覧からテイクを選んでください。";
+            return;
+        }
+        if (!TAKE_ADOPTABLE_BY_STATUS[take.status]) {
+            error = `「${TAKE_STATUS_LABELS[take.status]}」のテイクは採用できません。`;
+            return;
+        }
+        busy = true;
+        try {
+            const response = await captureJson(
+                buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, "/adopt"),
+                "POST",
+            );
+            if (!response.ok) {
+                // 409 (書き出し中/解析中) / 422 (未処理) はサーバ供給の文言をそのまま出す
+                error = await extractErrorMessage(response);
+                return;
+            }
+            onChanged();
+        } catch {
+            error = "通信に失敗しました。ネットワークを確認してください。";
+        } finally {
+            busy = false;
+        }
+    }
+</script>
+
+<Card padding="md" testId="take-preview-panel">
+    <div class="relative w-full overflow-hidden rounded-md bg-text/5">
+        {#if playbackUrl !== null && take !== null}
+            {#key take.id}
+                <!-- svelte-ignore a11y_media_has_caption -->
+                <video
+                    controls
+                    playsinline
+                    src={playbackUrl}
+                    class="w-full"
+                    aria-label={`${cut.label} のテイク ${(takeIndex ?? 0) + 1}`}
+                    data-testid="take-preview-video"
+                ></video>
+            {/key}
+            <SubtitleOverlay
+                primary={cut.subtitle_primary}
+                secondary={cut.subtitle_secondary}
+                visible={showSubtitles}
+            />
+        {:else}
+            <TakeThumbnail
+                index={takeIndex ?? 0}
+                status={take?.status ?? "processing"}
+                durationMs={take?.duration_ms ?? null}
+                thumbnailUrl={take === null ? null : thumbnailUrl}
+                size="lg"
+                testId="take-preview-placeholder"
+            />
+        {/if}
+    </div>
+
+    {#if playbackUrl === null}
+        <p class="mt-2 text-caption text-text-secondary" data-testid="take-not-playable">
+            {take === null
+                ? "左の一覧からテイクを選ぶと再生できます。"
+                : `このテイクはまだ再生できません（${TAKE_STATUS_LABELS[take.status]}）。`}
+        </p>
+    {/if}
+
+    <div class="mt-3 flex flex-col gap-2">
+        <Checkbox
+            id="take-preview-subtitles"
+            bind:checked={showSubtitles}
+            label="字幕を表示"
+            testId="toggle-subtitles"
+        />
+        <Checkbox
+            id="take-preview-narration"
+            bind:checked={showNarrationScript}
+            label="ナレーション原稿を表示"
+            testId="toggle-narration-script"
+        />
+        {#if showNarrationScript}
+            <p class="text-body text-text-secondary" data-testid="narration-script">
+                {cut.narration}
+            </p>
+        {/if}
+    </div>
+
+    {#if busyStatusNotice !== null}
+        <p class="mt-3 text-caption text-text-secondary" data-testid="take-adopt-status-notice">
+            {busyStatusNotice}
+        </p>
+    {/if}
+
+    <div class="mt-3 flex items-center gap-2">
+        <Button variant="primary" loading={busy} onclick={adopt} testId="take-adopt">
+            <Check class="size-4" aria-hidden="true" />
+            このテイクを採用する
+        </Button>
+        {#if take !== null && cut.adopted?.id === take.id}
+            <span class="text-caption text-text-secondary" data-testid="take-already-adopted">
+                このテイクを採用中です。
+            </span>
+        {/if}
+    </div>
+
+    {#if error}
+        <p class="mt-2 text-caption text-danger" role="alert" data-testid="take-preview-error">
+            {error}
+        </p>
+    {/if}
+</Card>
diff --git a/resources/js/components/features/manual/TakeThumbnail.svelte b/resources/js/components/features/manual/TakeThumbnail.svelte
new file mode 100644
index 0000000..e8f4752
--- /dev/null
+++ b/resources/js/components/features/manual/TakeThumbnail.svelte
@@ -0,0 +1,49 @@
+<script lang="ts">
+    import { Film } from "@lucide/svelte";
+    import { TAKE_STATUS_LABELS, type SelectableTakeStatus } from "@/types/manual";
+
+    /**
+     * テイクのタイル。サムネイル生成は非同期なので、録画直後・生成失敗・過去分のテイクは
+     * has_thumbnail=false になる。その場合は**同じ寸法の状態タイル**を描き、枠の大きさを
+     * 変えない (生成完了後の再取得で同じ枠が画像へ置き換わる = レイアウトが跳ねない)。
+     * 表示差し替え点をこの 1 コンポーネントに閉じている。
+     */
+    interface Props {
+        /** 一覧内の 0 始まり位置 (表示は +1) */
+        index: number;
+        status: SelectableTakeStatus;
+        durationMs: number | null;
+        /** 生成済みサムネイルの URL (未生成は null) */
+        thumbnailUrl: string | null;
+        /** sm = 一覧タイル / lg = プレビュー枠の代替 */
+        size?: "sm" | "lg";
+        testId?: string;
+    }
+
+    let { index, status, durationMs, thumbnailUrl, size = "sm", testId }: Props = $props();
+
+    const boxClass = $derived(size === "sm" ? "size-16" : "aspect-video w-full");
+    const seconds = $derived(durationMs === null ? null : Math.round(durationMs / 1000));
+</script>
+
+{#if thumbnailUrl !== null}
+    <img
+        src={thumbnailUrl}
+        alt=""
+        loading="lazy"
+        decoding="async"
+        class="{boxClass} shrink-0 rounded-md border border-border object-cover"
+        data-testid={testId}
+    />
+{:else}
+    <div
+        class="{boxClass} flex shrink-0 flex-col items-center justify-center gap-1 rounded-md border border-border bg-neutral text-text-secondary"
+        data-testid={testId}
+    >
+        <Film class="size-4" aria-hidden="true" />
+        <span class="text-caption">テイク {index + 1}</span>
+        <span class="text-caption">
+            {TAKE_STATUS_LABELS[status]}{#if seconds !== null}・{seconds} 秒{/if}
+        </span>
+    </div>
+{/if}
diff --git a/resources/js/components/features/capture/SubtitleOverlay.svelte b/resources/js/components/molecules/SubtitleOverlay.svelte
similarity index 75%
rename from resources/js/components/features/capture/SubtitleOverlay.svelte
rename to resources/js/components/molecules/SubtitleOverlay.svelte
index 62f1804..42a870e 100644
--- a/resources/js/components/features/capture/SubtitleOverlay.svelte
+++ b/resources/js/components/molecules/SubtitleOverlay.svelte
@@ -1,15 +1,18 @@
 <script lang="ts">
-    import type { CaptureCut } from "@/types/capture";
-
     /**
-     * 撮影中カメラプレビューへ重畳する字幕ガイド (doc/05 §5.2 の字幕重畳要件)。
-     * 焼込ではなく撮影ガイド overlay: MediaRecorder が録る MediaStream には含まれない。
+     * 映像へ重畳する字幕 overlay (doc/05 §5.2 の字幕重畳要件)。
+     * 焼込ではなく DOM overlay: MediaRecorder が録る MediaStream には含まれない。
      * primary=上部帯 (名称・数値) / secondary=下部メイン。位置は AssSubtitleWriter (ASS) と一致。
      * 位置・占有領域の確認用であり全文確認用ではない (長文は line-clamp で省略)。
+     *
+     * 利用者は 2 つ:
+     * - 撮影中カメラプレビューの字幕ガイド (features/capture/CameraRecorder)
+     * - PC テイク選択画面のプレビュー字幕表示 ON/OFF (features/manual/TakePreviewPanel)
+     * features の domain 間横参照を作らないため molecules に置く (複製しない)。
      */
     interface Props {
-        primary: CaptureCut["subtitle_primary"];
-        secondary: CaptureCut["subtitle_secondary"];
+        primary: string | null;
+        secondary: string;
         visible: boolean;
     }
 
diff --git a/resources/js/lib/capture/take-endpoints.ts b/resources/js/lib/capture/take-endpoints.ts
new file mode 100644
index 0000000..ff30749
--- /dev/null
+++ b/resources/js/lib/capture/take-endpoints.ts
@@ -0,0 +1,27 @@
+/**
+ * テイク API (capture.takes.*) の URL 導出。**規則をここ 1 箇所に置く**。
+ *
+ * この API 面は撮影 PWA (Capture/Show の TakeStrip) と PC 編集面
+ * (Manuals/Takes) の**両方が叩く**。URL prefix が /app なのは歴史的経緯であり、
+ * テイク資源の唯一の API 面である (doc/10 / docs/architecture.md §撮影 PWA の運用契約)。
+ */
+export interface TakeEndpointTarget {
+    projectId: number;
+    manualId: number;
+    cutId: number;
+}
+
+/** カット配下のテイクコレクション URL (POST = 登録) */
+export function cutTakesUrl({ projectId, manualId, cutId }: TakeEndpointTarget): string {
+    return `/app/projects/${projectId}/manuals/${manualId}/cuts/${cutId}/takes`;
+}
+
+/** テイク単体の URL (suffix で /adopt /playback 等を足す) */
+export function takeUrl(target: TakeEndpointTarget, takeId: number, suffix = ""): string {
+    return `${cutTakesUrl(target)}/${takeId}${suffix}`;
+}
+
+/** presigned upload-url 発行 URL */
+export function takeUploadUrlEndpoint(target: TakeEndpointTarget): string {
+    return `${cutTakesUrl(target)}/upload-url`;
+}
diff --git a/resources/js/lib/capture/upload-queue.ts b/resources/js/lib/capture/upload-queue.ts
index febcf92..c6c4759 100644
--- a/resources/js/lib/capture/upload-queue.ts
+++ b/resources/js/lib/capture/upload-queue.ts
@@ -1,4 +1,5 @@
 import { captureFetch } from "@/lib/capture/http";
+import { cutTakesUrl, takeUploadUrlEndpoint } from "@/lib/capture/take-endpoints";
 import type { CaptureConflictBody, QuotaExceededBody, UploadTicket } from "@/types/capture";
 
 /**
@@ -28,6 +29,25 @@ export interface PendingStore {
     list(): Promise<PendingUpload[]>;
 }
 
+/**
+ * インスタンス生存中だけ保持する PendingStore (PC 面用)。
+ * PC にはオフライン撮影の要件が無く、ページ遷移で失われてよい。
+ * 撮影 PWA は従来どおり IndexedDB 実装 (lib/capture/idb.ts) を使う。
+ */
+export function createMemoryPendingStore(): PendingStore {
+    const items = new Map<string, PendingUpload>();
+
+    return {
+        put: async (item) => {
+            items.set(item.clientTakeId, item);
+        },
+        delete: async (clientTakeId) => {
+            items.delete(clientTakeId);
+        },
+        list: async () => [...items.values()],
+    };
+}
+
 export type UploadOutcome =
     | { status: "uploaded"; clientTakeId: string }
     | { status: "queued"; clientTakeId: string; reason: string }
@@ -165,10 +185,11 @@ export class UploadQueue {
 
     /** upload-url → S3 PUT → POST takes の 3 段 (D2-D4 経路) */
     private async upload(item: PendingUpload): Promise<void> {
-        const base = `/app/projects/${item.projectId}/manuals/${item.manualId}/cuts/${item.cutId}/takes`;
+        const target = { projectId: item.projectId, manualId: item.manualId, cutId: item.cutId };
+        const base = cutTakesUrl(target);
         const checksum = await computeChecksumBase64(item.blob);
 
-        const ticketResponse = await this.fetcher(`${base}/upload-url`, {
+        const ticketResponse = await this.fetcher(takeUploadUrlEndpoint(target), {
             method: "POST",
             headers: { "Content-Type": "application/json" },
             body: JSON.stringify({
diff --git a/resources/js/pages/Manuals/Edit.svelte b/resources/js/pages/Manuals/Edit.svelte
index eb084a4..8e11eae 100644
--- a/resources/js/pages/Manuals/Edit.svelte
+++ b/resources/js/pages/Manuals/Edit.svelte
@@ -12,7 +12,12 @@
     import PageContainer from "@/components/templates/PageContainer.svelte";
     import PageContent from "@/components/templates/PageContent.svelte";
     import type { SharedProps } from "@/lib/shared-props";
-    import type { CategoryOption, ScenarioDocument, VideoManualStatus } from "@/types/manual";
+    import type {
+        CategoryOption,
+        CutTakeSummary,
+        ScenarioDocument,
+        VideoManualStatus,
+    } from "@/types/manual";
     import { isCaptureNavigable } from "@/types/manual";
 
     /**
@@ -32,9 +37,11 @@
         };
         categories: CategoryOption[];
         scenario: ScenarioDocument;
+        /** 動画列 (カットごとのテイク要約)。描画時点のスナップショット */
+        takeSummaries: CutTakeSummary[];
     }
 
-    let { project, manual, categories, scenario }: Props = $props();
+    let { project, manual, categories, scenario, takeSummaries }: Props = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
@@ -129,7 +136,12 @@
             <p class="mt-1 text-caption text-text-secondary">
                 手順と急所のカットを編集し、「シナリオを更新」でまとめて保存します。
             </p>
-            <ScenarioEditor {scenario} projectId={project.id} manualId={manual.id} />
+            <ScenarioEditor
+                {scenario}
+                {takeSummaries}
+                projectId={project.id}
+                manualId={manual.id}
+            />
         </div>
         </PageContent>
     </PageContainer>
diff --git a/resources/js/pages/Manuals/Takes.svelte b/resources/js/pages/Manuals/Takes.svelte
new file mode 100644
index 0000000..1837409
--- /dev/null
+++ b/resources/js/pages/Manuals/Takes.svelte
@@ -0,0 +1,88 @@
+<script lang="ts">
+    import { page, router } from "@inertiajs/svelte";
+    import { ArrowLeft, Film } from "@lucide/svelte";
+    import TextLink from "@/components/atoms/TextLink.svelte";
+    import TakeFileUpload from "@/components/features/manual/TakeFileUpload.svelte";
+    import TakePickerList from "@/components/features/manual/TakePickerList.svelte";
+    import TakePreviewPanel from "@/components/features/manual/TakePreviewPanel.svelte";
+    import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
+    import AppLayout from "@/components/templates/AppLayout.svelte";
+    import PageContainer from "@/components/templates/PageContainer.svelte";
+    import PageContent from "@/components/templates/PageContent.svelte";
+    import type { SharedProps } from "@/lib/shared-props";
+    import type { TakeSelectionPageProps } from "@/types/manual";
+
+    /**
+     * テイク選択・採用画面 (doc/04)。左 = テイク一覧、中央 = プレビュー + 採用。
+     * 採用・削除・アップロードは capture.takes.* (撮影 PWA と共用の API 面) を叩き、
+     * 成功したら partial reload で cut と takes を取り直す。
+     */
+    let { project, manual, cut, takes }: TakeSelectionPageProps = $props();
+
+    const shared = $derived(page.props as unknown as SharedProps);
+    const appName = $derived(shared.appName ?? "");
+
+    // 選択中テイク: 既定は採用テイク、無ければ先頭 (id で持ち、reload 後も追随させる)
+    let selectedTakeId = $state<number | null>(null);
+    const selectedTake = $derived(
+        takes.find((take) => take.id === selectedTakeId) ??
+            takes.find((take) => take.id === cut.adopted?.id) ??
+            takes[0] ??
+            null,
+    );
+    const selectedIndex = $derived(
+        selectedTake === null ? null : takes.findIndex((take) => take.id === selectedTake.id),
+    );
+
+    /** 採用・削除・アップロード成功後の再取得 (cut と takes は別のトップレベル props) */
+    function refresh(): void {
+        router.reload({ only: ["cut", "takes"] });
+    }
+</script>
+
+<AppLayout {appName}>
+    <PageContainer>
+        <PageHeaderSection
+            title={`${cut.label} のテイク選択`}
+            description={cut.scene}
+            icon={Film}
+            testId="take-selection-heading"
+        >
+            <TextLink href={`/projects/${project.id}/manuals/${manual.id}/edit`}>
+                <ArrowLeft class="inline size-3" aria-hidden="true" />
+                シナリオ編集へ戻る
+            </TextLink>
+        </PageHeaderSection>
+        <PageContent>
+            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[20rem_minmax(0,1fr)]">
+                <TakePickerList
+                    {takes}
+                    adoptedTakeId={cut.adopted?.id ?? null}
+                    selectedTakeId={selectedTake?.id ?? null}
+                    onSelect={(id) => (selectedTakeId = id)}
+                    projectId={project.id}
+                    manualId={manual.id}
+                    cutId={cut.id}
+                    onChanged={refresh}
+                />
+                <div class="flex min-w-0 flex-col gap-4">
+                    <TakePreviewPanel
+                        take={selectedTake}
+                        takeIndex={selectedIndex}
+                        {cut}
+                        manualStatus={manual.status}
+                        projectId={project.id}
+                        manualId={manual.id}
+                        onChanged={refresh}
+                    />
+                    <TakeFileUpload
+                        projectId={project.id}
+                        manualId={manual.id}
+                        cutId={cut.id}
+                        onUploaded={refresh}
+                    />
+                </div>
+            </div>
+        </PageContent>
+    </PageContainer>
+</AppLayout>
diff --git a/resources/js/types/manual.ts b/resources/js/types/manual.ts
index 8b2bec2..5c9091b 100644
--- a/resources/js/types/manual.ts
+++ b/resources/js/types/manual.ts
@@ -320,3 +320,70 @@ export interface ScenarioConflictBody {
     message: string;
     current_version: number;
 }
+
+/**
+ * PC テイク選択画面 (Manuals/Takes) の型。PHP 側 App\DataTransferObjects\Manual\
+ * {TakeSelectionPageData, SelectableTakeData, CutTakeSummaryData} と対で保守する。
+ * 撮影 PWA の types/capture.ts とは**別 shape** (PC は署名 URL の口を持たない)。
+ */
+
+/** PHP: App\Enums\Manual\TakeStatus と値集合を一致させる (literal union) */
+export type SelectableTakeStatus = "uploading" | "processing" | "ready" | "failed";
+
+/** テイクの状態ラベル (UI 共通)。satisfies でキー漏れをコンパイル時検出する */
+export const TAKE_STATUS_LABELS = {
+    uploading: "アップロード中",
+    processing: "処理中",
+    ready: "使用できます",
+    failed: "失敗",
+} as const satisfies Record<SelectableTakeStatus, string>;
+
+/** 採用できる状態か (サーバ CaptureTakeService::adopt の ready 条件と一致させる) */
+export const TAKE_ADOPTABLE_BY_STATUS = {
+    uploading: false,
+    processing: false,
+    ready: true,
+    failed: false,
+} as const satisfies Record<SelectableTakeStatus, boolean>;
+
+/** PHP: SelectableTakeData と対 */
+export interface SelectableTake {
+    id: number;
+    status: SelectableTakeStatus;
+    size_bytes: number;
+    duration_ms: number | null;
+    comment: string | null;
+    captured_at: string | null;
+    sort_order: number;
+    /** DL 済み (削除できない。押下前に理由を説明するために出す) */
+    downloaded: boolean;
+    /** サムネイル生成済みか。true のときだけ GET .../takes/{id}/thumbnail を表示に使う */
+    has_thumbnail: boolean;
+}
+
+/** PHP: TakeSelectionPageData の cut キーと対 */
+export interface TakeSelectionCut {
+    id: number;
+    type: "step" | "point";
+    label: string;
+    scene: string;
+    narration: string;
+    subtitle_primary: string | null;
+    subtitle_secondary: string;
+    adopted: { id: number; status: SelectableTakeStatus } | null;
+}
+
+/** PHP: TakeSelectionPageData::toArray() 全体と対 (Manuals/Takes の props) */
+export interface TakeSelectionPageProps {
+    project: { id: number; name: string };
+    manual: { id: number; title: string; status: VideoManualStatus };
+    cut: TakeSelectionCut;
+    takes: SelectableTake[];
+}
+
+/** PHP: CutTakeSummaryData と対 (シナリオ編集画面「動画」列の 1 カット分) */
+export interface CutTakeSummary {
+    cut_id: number;
+    takes_count: number;
+    adopted: { id: number; status: SelectableTakeStatus } | null;
+}
diff --git a/routes/web.php b/routes/web.php
index 7bb4477..edd343d 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -32,6 +32,7 @@
 use App\Http\Controllers\Organizations\OrganizationOwnershipController;
 use App\Http\Controllers\Organizations\OrganizationSwitchController;
 use App\Http\Controllers\Projects\CategoryController;
+use App\Http\Controllers\Projects\CutTakeController;
 use App\Http\Controllers\Projects\ItemController;
 use App\Http\Controllers\Projects\ManualAnalysisController;
 use App\Http\Controllers\Projects\ManualDownloadController;
@@ -562,6 +563,12 @@
             // {manual} は $project->manuals() 経由 (scopeBindings) = cross-manual/cross-project は 404。
             Route::post('/projects/{project}/manuals/{manual}/duplicate', [VideoManualController::class, 'duplicate'])
                 ->name('projects.manuals.duplicate');
+            // テイク選択・採用画面 (doc/04 「テイクのプレビュー / 選択画面」)。編集者のみ (撮影者は 403)。
+            // **この GET は画面 props を返すだけ**で、採用・削除・アップロード・再生は
+            // capture.takes.* を再利用する (テイク資源の API 面を 2 本にしない)。
+            // {cut} は $manual->cuts() 経由 (scopeBindings) = cross-manual/cross-project は認可より前に 404。
+            Route::get('/projects/{project}/manuals/{manual}/cuts/{cut}/takes', [CutTakeController::class, 'index'])
+                ->name('projects.manuals.cuts.takes.index');
         });
 
         /*
@@ -590,6 +597,13 @@
     | tx 内で $project->manuals()->…->cuts()->…->takes() の連鎖再解決 (firstOrFail) を必須とし、
     | 推論が外れても cross-parent は 404 に落ちる。挙動担保は各エンドポイントの
     | cross-org/project/manual/cut 404 Feature テスト。
+    | ★**takes.* は PC 編集面とも共用である (T184)**。PC のテイク選択・採用画面
+    | (projects.manuals.cuts.takes.index) は画面 props を返す GET を業務 group 側に 1 本持つだけで、
+    | 採用・削除・アップロード・再生・サムネイルはここの takes.* をそのまま叩く
+    | (テイク資源の API 面を 2 本にしない)。よって この prefix は「撮影 PWA 専用」を意味しない —
+    | ここへ PWA 固有の middleware を足すと PC 面にも掛かる。
+    | 認可は意図的に非対称で、画面は編集者限定 (撮影者 403) / takes.* は撮影者にも開く
+    | (doc/10 §10.5)。固定は tests/Feature/Manual/PcTakeOperationTest.php。
     */
     Route::middleware(['require-active-subscription', 'project.in-current-org'])
         ->prefix('app')->as('capture.')->group(function (): void {
diff --git a/tests/Feature/Manual/PcTakeOperationTest.php b/tests/Feature/Manual/PcTakeOperationTest.php
new file mode 100644
index 0000000..ce05827
--- /dev/null
+++ b/tests/Feature/Manual/PcTakeOperationTest.php
@@ -0,0 +1,158 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Capture\PresignedUploadData;
+use App\Enums\Manual\TakeStatus;
+use App\Enums\ProjectRole;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\VideoManual;
+use App\Services\Capture\TakeObjectStorage;
+use Carbon\CarbonImmutable;
+use Illuminate\Support\Str;
+
+/*
+ * PC テイク選択画面からのテイク操作。
+ *
+ * PC 面は**新しい API 面を持たない** — 採用・削除・アップロード・再生はすべて
+ * 撮影 PWA と共用の capture.takes.* を叩く。本テストが固定するのは 2 つ:
+ *
+ *   1. 編集者 (org owner / project_admin) が capture.takes.* を実行できること
+ *      (PC 導線でも認可が通る)
+ *   2. **撮影者 (project_member) も capture.takes.adopt を実行できること**
+ *      = 画面 (projects.manuals.cuts.takes.index) は 403 だが API は開いている、という
+ *      意図的な非対称。**この test が消えたら非対称が事故で壊れたと分かる**
+ *      (撮影者の採用は doc/10 §10.5 の確定仕様)。
+ */
+
+function pcTakePath(Project $project, VideoManual $manual, Cut $cut, ?Take $take = null, string $suffix = ''): string
+{
+    $base = "/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes";
+
+    return $take === null ? $base.$suffix : "{$base}/{$take->id}{$suffix}";
+}
+
+test('編集者 (org owner) は adopt を実行でき、採用が反映される', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create();
+
+    $this->actingAs($owner)
+        ->postJson(pcTakePath($project, $manual, $cut, $take, '/adopt'))
+        ->assertOk();
+
+    expect($cut->fresh()?->adopted_take_id)->toBe($take->id);
+});
+
+test('編集者 (project_admin) も adopt / destroy を実行できる', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $editor = attachOrganizationMember($organization);
+    attachProjectMember($project, $editor, ProjectRole::Admin);
+    $editor->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create();
+
+    $this->actingAs($editor)
+        ->postJson(pcTakePath($project, $manual, $cut, $take, '/adopt'))
+        ->assertOk();
+    $this->actingAs($editor)
+        ->deleteJson(pcTakePath($project, $manual, $cut, $take))
+        ->assertNoContent();
+
+    expect(Take::query()->whereKey($take->id)->exists())->toBeFalse();
+});
+
+test('編集者は presigned upload-url を発行できる (PC からのファイル追加の入口)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+
+    // S3 は叩かない (presign は fake 値を返す container mock に差し替える)
+    $storage = Mockery::mock(TakeObjectStorage::class);
+    $storage->shouldReceive('presignUpload')->andReturn(new PresignedUploadData(
+        url: 'https://s3.fake.test/bucket/key?X-Amz-Signature=sig',
+        headers: ['Content-Type' => 'video/mp4', 'x-amz-checksum-sha256' => 'fake='],
+        expiresAt: CarbonImmutable::now()->addMinutes(30),
+    ));
+    app()->instance(TakeObjectStorage::class, $storage);
+
+    $this->actingAs($owner)
+        ->postJson(pcTakePath($project, $manual, $cut, null, '/upload-url'), [
+            'client_take_id' => (string) Str::ulid(),
+            'size_bytes' => 1_000_000,
+            'content_type' => 'video/mp4',
+            'checksum_sha256' => base64_encode(hash('sha256', 'blob', true)),
+        ])
+        ->assertOk()
+        ->assertJsonStructure(['upload_url', 'headers', 'ticket', 'client_take_id', 'expires_at']);
+});
+
+test('**撮影者 (project_member) も adopt を実行できる** (画面は 403 だが API は開いている)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $shooter = attachOrganizationMember($organization);
+    attachProjectMember($project, $shooter, ProjectRole::Member);
+    $shooter->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create();
+
+    // 画面は編集者限定 (403)
+    $this->actingAs($shooter)
+        ->get("/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes")
+        ->assertForbidden();
+
+    // API は撮影者にも開いている (PWA の採用導線。doc/10 §10.5)
+    $this->actingAs($shooter)
+        ->postJson(pcTakePath($project, $manual, $cut, $take, '/adopt'))
+        ->assertOk();
+
+    expect($cut->fresh()?->adopted_take_id)->toBe($take->id);
+});
+
+test('rendering 中の adopt は 409 (画面の事前告知と同じ理由)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'rendering']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create();
+
+    $this->actingAs($owner)
+        ->postJson(pcTakePath($project, $manual, $cut, $take, '/adopt'))
+        ->assertStatus(409);
+});
+
+test('ready でないテイクの adopt は 422', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create(['status' => TakeStatus::Processing->value]);
+
+    $this->actingAs($owner)
+        ->postJson(pcTakePath($project, $manual, $cut, $take, '/adopt'))
+        ->assertStatus(422);
+});
+
+test('DL 済みテイクの削除は 422 (画面はサーバ文言をそのまま出す)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->downloaded()->create();
+
+    $this->actingAs($owner)
+        ->deleteJson(pcTakePath($project, $manual, $cut, $take))
+        ->assertStatus(422);
+
+    expect(Take::query()->whereKey($take->id)->exists())->toBeTrue();
+});
diff --git a/tests/Feature/Manual/ScenarioVideoColumnTest.php b/tests/Feature/Manual/ScenarioVideoColumnTest.php
new file mode 100644
index 0000000..8b7bea8
--- /dev/null
+++ b/tests/Feature/Manual/ScenarioVideoColumnTest.php
@@ -0,0 +1,91 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\VideoManual;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * シナリオ編集画面 (projects.manuals.edit) の「動画」列 props (takeSummaries)。
+ * 描画時点のスナップショットであり常に最新ではない (採用は撮影 PWA からも起きる)。
+ * 採用テイクは `adopted` キーで返す = 採用テイク外部キーの識別子を props に出さない。
+ */
+
+test('takeSummaries に全カット分の要約が sort_order 順で載る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $second = Cut::factory()->forManual($manual)->withSortOrder(2)->create();
+    $first = Cut::factory()->forManual($manual)->withSortOrder(1)->create();
+    Take::factory()->forCut($first)->create();
+    Take::factory()->forCut($first)->create(['sort_order' => 1]);
+
+    $this->actingAs($owner)
+        ->get("/projects/{$project->id}/manuals/{$manual->id}/edit")
+        ->assertInertia(fn ($page) => $page
+            ->where('takeSummaries.0.cut_id', $first->id)
+            ->where('takeSummaries.0.takes_count', 2)
+            ->where('takeSummaries.0.adopted', null)
+            ->where('takeSummaries.1.cut_id', $second->id)
+            ->where('takeSummaries.1.takes_count', 0));
+});
+
+test('採用テイクのあるカットは adopted.id / adopted.status が入る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create();
+    $cut->forceFill(['adopted_take_id' => $take->id])->save();
+
+    $this->actingAs($owner)
+        ->get("/projects/{$project->id}/manuals/{$manual->id}/edit")
+        ->assertInertia(fn ($page) => $page
+            ->where('takeSummaries.0.adopted.id', $take->id)
+            ->where('takeSummaries.0.adopted.status', 'ready'));
+});
+
+test('takeSummaries のキーに採用テイク外部キーの識別子が現れない (gate 回避の命名の固定)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create();
+    $cut->forceFill(['adopted_take_id' => $take->id])->save();
+
+    $response = $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/edit");
+
+    $summaries = json_encode($response->viewData('page')['props']['takeSummaries']);
+    expect($summaries)->toBeString()->not->toContain('adopted_take_id');
+});
+
+test('cut を増やしてもクエリ本数が増えない (N+1 を作らない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $count = function (int $cuts) use ($project, $owner): int {
+        $manual = VideoManual::factory()->forProject($project)->create();
+        for ($i = 0; $i < $cuts; $i++) {
+            $cut = Cut::factory()->forManual($manual)->withSortOrder($i)->create();
+            $take = Take::factory()->forCut($cut)->create();
+            $cut->forceFill(['adopted_take_id' => $take->id])->save();
+        }
+
+        DB::flushQueryLog();
+        DB::enableQueryLog();
+        $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/edit")->assertOk();
+        $queries = count(DB::getQueryLog());
+        DB::disableQueryLog();
+
+        return $queries;
+    };
+
+    $small = $count(3);
+    $large = $count(30);
+
+    // 本数の完全一致では固定しない (無関係な最適化で赤くしない)。増えないことだけを見る
+    expect($large)->toBeLessThanOrEqual($small);
+});
diff --git a/tests/Feature/Manual/TakeSelectionPageTest.php b/tests/Feature/Manual/TakeSelectionPageTest.php
new file mode 100644
index 0000000..2e2b355
--- /dev/null
+++ b/tests/Feature/Manual/TakeSelectionPageTest.php
@@ -0,0 +1,175 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\TakeStatus;
+use App\Enums\ProjectRole;
+use App\Models\Cut;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\User;
+use App\Models\VideoManual;
+
+/*
+ * テイク選択・採用画面 (GET /projects/{project}/manuals/{manual}/cuts/{cut}/takes)。
+ * 読み取り専用の画面 props のみを返し、採用・削除・アップロード・再生は
+ * capture.takes.* (撮影 PWA と共用の API 面) が担う。
+ *
+ * 権限境界は**意図的な非対称**である:
+ *   - 本画面は編集者のみ (VideoManualPolicy::update)。撮影者は 403
+ *   - テイク操作 API は撮影者にも開いている (PcTakeOperationTest が固定する)
+ */
+
+/**
+ * @return array{Organization, User, Project, VideoManual, Cut}
+ */
+function takeSelectionContext(string $manualStatus = 'ready'): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => $manualStatus]);
+    $cut = Cut::factory()->forManual($manual)->withSortOrder(0)->create();
+
+    return [$organization, $owner, $project, $manual, $cut];
+}
+
+function takeSelectionPath(Project $project, VideoManual $manual, Cut $cut): string
+{
+    return "/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes";
+}
+
+test('org owner (編集者) は 200 で Manuals/Takes を受け取り cut.label が 手順1 になる', function (): void {
+    [, $owner, $project, $manual, $cut] = takeSelectionContext();
+
+    $response = $this->actingAs($owner)->get(takeSelectionPath($project, $manual, $cut));
+
+    $response->assertOk();
+    $response->assertInertia(fn ($page) => $page
+        ->component('Manuals/Takes')
+        ->where('project.id', $project->id)
+        ->where('manual.id', $manual->id)
+        ->where('manual.status', 'ready')
+        ->where('cut.id', $cut->id)
+        ->where('cut.type', 'step')
+        ->where('cut.label', '手順1')
+        ->where('cut.adopted', null)
+        ->where('takes', []));
+});
+
+test('point の cut は 急所1-1 のラベルになる (CutSequencer と同じ導出元)', function (): void {
+    [, $owner, $project, $manual, $step] = takeSelectionContext();
+    $point = Cut::factory()->asPointOf($step)->withSortOrder(0)->create();
+
+    $this->actingAs($owner)
+        ->get(takeSelectionPath($project, $manual, $point))
+        ->assertInertia(fn ($page) => $page->where('cut.label', '急所1-1'));
+});
+
+test('親を持たない急所 (データ異常) でも画面は開き中立ラベルへ倒れる', function (): void {
+    [, $owner, $project, $manual] = takeSelectionContext();
+    // parent_cut_id が null の point は CutSequencer の列に現れない
+    $orphan = Cut::factory()->forManual($manual)->create(['type' => 'point']);
+
+    $this->actingAs($owner)
+        ->get(takeSelectionPath($project, $manual, $orphan))
+        ->assertOk()
+        ->assertInertia(fn ($page) => $page->where('cut.label', 'カット'));
+});
+
+test('project_admin (編集者) も 200 で閲覧できる', function (): void {
+    [$organization, , $project, $manual, $cut] = takeSelectionContext();
+    $editor = attachOrganizationMember($organization);
+    attachProjectMember($project, $editor, ProjectRole::Admin);
+    $editor->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($editor)->get(takeSelectionPath($project, $manual, $cut))->assertOk();
+});
+
+test('project_member (撮影者) は 403 (PWA 側に採用導線があるため詰みではない)', function (): void {
+    [$organization, , $project, $manual, $cut] = takeSelectionContext();
+    $shooter = attachOrganizationMember($organization);
+    attachProjectMember($project, $shooter, ProjectRole::Member);
+    $shooter->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($shooter)->get(takeSelectionPath($project, $manual, $cut))->assertForbidden();
+});
+
+test('cross-org の {project} は認可より前に 404', function (): void {
+    [, $ownerA] = createOrganizationWithOwner('組織A');
+    [$orgB, $ownerB] = createOrganizationWithOwner('組織B');
+    $projectB = Project::factory()->forOrganization($orgB)->create();
+    $manualB = VideoManual::factory()->forProject($projectB)->create();
+    $cutB = Cut::factory()->forManual($manualB)->create();
+    expect($ownerB)->not->toBeNull();
+
+    $this->actingAs($ownerA)->get(takeSelectionPath($projectB, $manualB, $cutB))->assertNotFound();
+});
+
+test('cross-project の {manual} は 404', function (): void {
+    [$organization, $owner, , $manual, $cut] = takeSelectionContext();
+    $otherProject = Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)->get(takeSelectionPath($otherProject, $manual, $cut))->assertNotFound();
+});
+
+test('cross-manual の {cut} は 404', function (): void {
+    [, $owner, $project, , $cut] = takeSelectionContext();
+    $otherManual = VideoManual::factory()->forProject($project)->create();
+
+    $this->actingAs($owner)->get(takeSelectionPath($project, $otherManual, $cut))->assertNotFound();
+});
+
+test('takes は sort_order 昇順で並び downloaded / has_thumbnail が反映される', function (): void {
+    [, $owner, $project, $manual, $cut] = takeSelectionContext();
+    $second = Take::factory()->forCut($cut)->downloaded()->create(['sort_order' => 1]);
+    $first = Take::factory()->forCut($cut)->withThumbnail()->create(['sort_order' => 0]);
+
+    $this->actingAs($owner)
+        ->get(takeSelectionPath($project, $manual, $cut))
+        ->assertInertia(fn ($page) => $page
+            ->where('takes.0.id', $first->id)
+            ->where('takes.0.downloaded', false)
+            ->where('takes.0.has_thumbnail', true)
+            ->where('takes.1.id', $second->id)
+            ->where('takes.1.downloaded', true)
+            ->where('takes.1.has_thumbnail', false));
+});
+
+test('採用テイクは cut.adopted に id と status で載る', function (): void {
+    [, $owner, $project, $manual, $cut] = takeSelectionContext();
+    $take = Take::factory()->forCut($cut)->create(['status' => TakeStatus::Ready->value]);
+    $cut->forceFill(['adopted_take_id' => $take->id])->save();
+
+    $this->actingAs($owner)
+        ->get(takeSelectionPath($project, $manual, $cut))
+        ->assertInertia(fn ($page) => $page
+            ->where('cut.adopted.id', $take->id)
+            ->where('cut.adopted.status', 'ready'));
+});
+
+test('props に署名 URL / 保存パス / ACK トークンのスロットが一切現れない', function (): void {
+    [, $owner, $project, $manual, $cut] = takeSelectionContext();
+    Take::factory()->forCut($cut)->withThumbnail()->create();
+
+    $response = $this->actingAs($owner)->get(takeSelectionPath($project, $manual, $cut));
+
+    $response->assertOk();
+    $props = $response->viewData('page')['props'];
+    $encoded = json_encode($props, JSON_UNESCAPED_UNICODE);
+    expect($encoded)->toBeString();
+    foreach (['playback_url', 'video_path', 'thumbnail_path', 'download_ack_token', 'adopted_take_id'] as $forbidden) {
+        expect($encoded)->not->toContain($forbidden);
+    }
+});
+
+test('未契約組織は onboarding へ遮断される (課金ゲートの中にある)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('未契約組織', grandfatherFreePlan: false);
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $cut = Cut::factory()->forManual($manual)->create();
+
+    $this->actingAs($owner)
+        ->get(takeSelectionPath($project, $manual, $cut))
+        ->assertRedirect(route('onboarding.checkout'));
+});
diff --git a/tests/Support/Routing/NestedRouteDefenseInventory.php b/tests/Support/Routing/NestedRouteDefenseInventory.php
index a0ad06c..6e84648 100644
--- a/tests/Support/Routing/NestedRouteDefenseInventory.php
+++ b/tests/Support/Routing/NestedRouteDefenseInventory.php
@@ -86,6 +86,8 @@ public static function inventory(): array
             'projects.manuals.update' => [...$project, 'manual' => $scoped],
             'projects.manuals.destroy' => [...$project, 'manual' => $scoped],
             'projects.manuals.duplicate' => [...$project, 'manual' => $scoped],
+            // {cut} は $manual->cuts() 経由 (PC テイク選択画面)
+            'projects.manuals.cuts.takes.index' => [...$project, 'manual' => $scoped, 'cut' => $scoped],
             'projects.manuals.scenario.update' => [...$project, 'manual' => $scoped],
             'projects.manuals.source-documents.store' => [...$project, 'manual' => $scoped],
             'projects.manuals.analyze' => [...$project, 'manual' => $scoped],
diff --git a/tests/js/components/features/manual/ScenarioEditor.test.ts b/tests/js/components/features/manual/ScenarioEditor.test.ts
index 4a84036..6359d42 100644
--- a/tests/js/components/features/manual/ScenarioEditor.test.ts
+++ b/tests/js/components/features/manual/ScenarioEditor.test.ts
@@ -27,7 +27,10 @@ const { routerReloadMock, routerOnMock } = vi.hoisted(() => ({
     routerOnMock: vi.fn((..._args: unknown[]) => () => {}),
 }));
 
-vi.mock("@inertiajs/svelte", () => ({
+// 動画列が Inertia Link (Button href + inertia) を描画するため、
+// router 以外の実 export (Link 等) は本物を残す
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
     router: {
         reload: routerReloadMock,
         on: routerOnMock,
@@ -106,7 +109,8 @@ function makeDocument(): ScenarioDocument {
     };
 }
 
-const baseProps = { projectId: 1, manualId: 5 };
+// 動画列 (takeSummaries) は既定で空 = 保存済み行でも「テイク 0 件」表示になる
+const baseProps = { projectId: 1, manualId: 5, takeSummaries: [] };
 
 /** fetch Response の最小スタブ */
 function jsonResponse(status: number, body: unknown): Response {
diff --git a/tests/js/components/features/capture/SubtitleOverlay.test.ts b/tests/js/components/molecules/SubtitleOverlay.test.ts
similarity index 98%
rename from tests/js/components/features/capture/SubtitleOverlay.test.ts
rename to tests/js/components/molecules/SubtitleOverlay.test.ts
index 58c005b..76fa99b 100644
--- a/tests/js/components/features/capture/SubtitleOverlay.test.ts
+++ b/tests/js/components/molecules/SubtitleOverlay.test.ts
@@ -1,6 +1,6 @@
 import { afterEach, describe, expect, it } from "vitest";
 import { cleanup, render, screen } from "@testing-library/svelte";
-import SubtitleOverlay from "@/components/features/capture/SubtitleOverlay.svelte";
+import SubtitleOverlay from "@/components/molecules/SubtitleOverlay.svelte";
 
 /*
  * SubtitleOverlay: 撮影中プレビューへ重畳する字幕ガイド (焼込ではない DOM overlay)。
diff --git a/tests/js/lib/capture/take-endpoints.test.ts b/tests/js/lib/capture/take-endpoints.test.ts
new file mode 100644
index 0000000..314bcf0
--- /dev/null
+++ b/tests/js/lib/capture/take-endpoints.test.ts
@@ -0,0 +1,42 @@
+import { describe, expect, it } from "vitest";
+import {
+    cutTakesUrl,
+    takeUploadUrlEndpoint,
+    takeUrl,
+} from "@/lib/capture/take-endpoints";
+
+/*
+ * テイク API の URL 導出。撮影 PWA (TakeStrip / UploadQueue) と PC 編集面
+ * (Manuals/Takes) が**同じ 1 箇所**から URL を作ることを固定する。
+ * prefix が /app なのは歴史的経緯であり、テイク資源の唯一の API 面である。
+ */
+
+const target = { projectId: 7, manualId: 12, cutId: 34 };
+
+describe("take-endpoints", () => {
+    it("cutTakesUrl はカット配下のテイクコレクション URL を返す", () => {
+        expect(cutTakesUrl(target)).toBe("/app/projects/7/manuals/12/cuts/34/takes");
+    });
+
+    it("takeUrl は suffix なしでテイク単体 URL を返す", () => {
+        expect(takeUrl(target, 56)).toBe("/app/projects/7/manuals/12/cuts/34/takes/56");
+    });
+
+    it("takeUrl は suffix (/adopt /playback /thumbnail) を末尾に足す", () => {
+        expect(takeUrl(target, 56, "/adopt")).toBe(
+            "/app/projects/7/manuals/12/cuts/34/takes/56/adopt",
+        );
+        expect(takeUrl(target, 56, "/playback")).toBe(
+            "/app/projects/7/manuals/12/cuts/34/takes/56/playback",
+        );
+        expect(takeUrl(target, 56, "/thumbnail")).toBe(
+            "/app/projects/7/manuals/12/cuts/34/takes/56/thumbnail",
+        );
+    });
+
+    it("takeUploadUrlEndpoint は presigned 発行 URL を返す", () => {
+        expect(takeUploadUrlEndpoint(target)).toBe(
+            "/app/projects/7/manuals/12/cuts/34/takes/upload-url",
+        );
+    });
+});
diff --git a/tests/js/lib/capture/upload-queue.test.ts b/tests/js/lib/capture/upload-queue.test.ts
index b683470..552ed5f 100644
--- a/tests/js/lib/capture/upload-queue.test.ts
+++ b/tests/js/lib/capture/upload-queue.test.ts
@@ -1,6 +1,7 @@
 import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
 import {
     computeChecksumBase64,
+    createMemoryPendingStore,
     generateClientTakeId,
     UploadQueue,
     type PendingStore,
@@ -221,3 +222,30 @@ describe("UploadQueue", () => {
         expect(store.items.size).toBe(1);
     });
 });
+
+describe("createMemoryPendingStore", () => {
+    it("put / list / delete の PendingStore 契約を満たす", async () => {
+        const store = createMemoryPendingStore();
+        const item = pendingItem();
+
+        expect(await store.list()).toEqual([]);
+        await store.put(item);
+        expect(await store.list()).toEqual([item]);
+        // 同じ clientTakeId の put は置き換え (重複しない)
+        await store.put(item);
+        expect(await store.list()).toHaveLength(1);
+        await store.delete(item.clientTakeId);
+        expect(await store.list()).toEqual([]);
+    });
+
+    it("オフラインの enqueue は queued になり list() に載る (既存クラスの振る舞い不変)", async () => {
+        const store = createMemoryPendingStore();
+        const queue = new UploadQueue({ store, fetcher: vi.fn(), isOnline: () => false });
+        const item = pendingItem();
+
+        const outcome = await queue.enqueue(item);
+
+        expect(outcome.status).toBe("queued");
+        expect(await store.list()).toHaveLength(1);
+    });
+});
diff --git a/tests/js/pages/ManualsEdit.test.ts b/tests/js/pages/ManualsEdit.test.ts
index c5f23fc..19c7f61 100644
--- a/tests/js/pages/ManualsEdit.test.ts
+++ b/tests/js/pages/ManualsEdit.test.ts
@@ -1,5 +1,5 @@
-import { describe, expect, it } from "vitest";
-import { render, screen } from "@testing-library/svelte";
+import { afterEach, describe, expect, it } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
 import Edit from "@/pages/Manuals/Edit.svelte";
 import type { ScenarioDocument } from "@/types/manual";
 
@@ -8,6 +8,10 @@ const scenario: ScenarioDocument = {
     steps: [],
 };
 
+afterEach(() => {
+    cleanup();
+});
+
 const baseProps = {
     project: { id: 1, name: "サンプルプロジェクト" },
     manual: { id: 5, title: "ネジ締め作業", category: 2, status: "draft" as const },
@@ -16,6 +20,7 @@ const baseProps = {
         { id: 2, name: "仕上げ" },
     ],
     scenario,
+    takeSummaries: [],
 };
 
 describe("Manuals/Edit", () => {
@@ -76,3 +81,81 @@ describe("Manuals/Edit", () => {
         expect(screen.queryByTestId("capture-manual-link")).toBeNull();
     });
 });
+
+/*
+ * 動画列 (doc/04)。保存済みカットだけがテイク選択画面へのリンクを持ち、
+ * 未保存行 (id=null) はリンクを出さずに保存を促す (押せるのに詰むボタンを作らない)。
+ */
+describe("Manuals/Edit — 動画列", () => {
+    const savedScenario: ScenarioDocument = {
+        scenario_version: 3,
+        steps: [
+            {
+                id: 41,
+                scene: "工具を準備する",
+                shot_type: "hiki",
+                shooting_point: null,
+                narration: "工具を準備します。",
+                subtitle_primary: null,
+                subtitle_secondary: "工具を準備する",
+                material_type: null,
+                static_display_seconds: null,
+                points: [],
+            },
+        ],
+    };
+
+    it("保存済み行にテイク選択画面へのリンクと件数が出る", () => {
+        render(Edit, {
+            props: {
+                ...baseProps,
+                scenario: savedScenario,
+                takeSummaries: [{ cut_id: 41, takes_count: 2, adopted: null }],
+            },
+        });
+
+        expect(screen.getByTestId("video-cell-count")).toHaveTextContent("テイク 2 件");
+        const link = screen.getByTestId("video-cell-link");
+        expect(link).toHaveTextContent("テイクを選択");
+        expect(link.getAttribute("href")).toMatch(
+            /^https?:\/\/[^/]+\/projects\/1\/manuals\/5\/cuts\/41\/takes$/,
+        );
+    });
+
+    it("テイク 0 件のカットは「ファイルの選択」を出す (導線は消さない)", () => {
+        render(Edit, {
+            props: {
+                ...baseProps,
+                scenario: savedScenario,
+                takeSummaries: [{ cut_id: 41, takes_count: 0, adopted: null }],
+            },
+        });
+
+        expect(screen.getByTestId("video-cell-link")).toHaveTextContent("ファイルの選択");
+    });
+
+    it("採用済みカットには「採用済み」バッジが出る", () => {
+        render(Edit, {
+            props: {
+                ...baseProps,
+                scenario: savedScenario,
+                takeSummaries: [
+                    { cut_id: 41, takes_count: 2, adopted: { id: 9, status: "ready" as const } },
+                ],
+            },
+        });
+
+        expect(screen.getByTestId("video-cell-adopted")).toHaveTextContent("採用済み");
+    });
+
+    it("未保存行 (手順を追加した直後) にはリンクが出ず、保存を促す文言が出る", async () => {
+        render(Edit, { props: { ...baseProps, scenario: { scenario_version: 0, steps: [] } } });
+
+        await fireEvent.click(screen.getByRole("button", { name: "最初の手順を追加" }));
+
+        expect(await screen.findByTestId("video-cell-unsaved")).toHaveTextContent(
+            "「シナリオを更新」で保存すると",
+        );
+        expect(screen.queryByTestId("video-cell-link")).toBeNull();
+    });
+});
diff --git a/tests/js/pages/ManualsTakes.test.ts b/tests/js/pages/ManualsTakes.test.ts
new file mode 100644
index 0000000..11e9e42
--- /dev/null
+++ b/tests/js/pages/ManualsTakes.test.ts
@@ -0,0 +1,454 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+import Takes from "@/pages/Manuals/Takes.svelte";
+import type { SelectableTake, TakeSelectionCut } from "@/types/manual";
+
+/*
+ * PC テイク選択・採用画面 (Manuals/Takes)。
+ * - 採用中テイクは青枠 (border-primary) で区別する
+ * - 採用できない状態のテイクでも押下は受け、押してからエラーを出す (disabled にしない)
+ * - 削除は確認ダイアログを経てから DELETE を送る
+ * - 字幕 / ナレーション原稿は初期オフの表示切替 (v1 は TTS 非実装 = 音は出さない)
+ * - ローカル動画の追加は既存 presigned フロー (UploadQueue) を再利用する
+ */
+
+const { routerReloadMock, enqueueMock, memoryStore, storeDeleteSpy } = vi.hoisted(() => {
+    const items = new Map<string, unknown>();
+    const storeDeleteSpy = vi.fn();
+
+    return {
+        routerReloadMock: vi.fn(),
+        enqueueMock: vi.fn(),
+        storeDeleteSpy,
+        memoryStore: {
+            put: async (item: { clientTakeId: string }) => {
+                items.set(item.clientTakeId, item);
+            },
+            delete: async (id: string) => {
+                storeDeleteSpy(id);
+                items.delete(id);
+            },
+            list: async () => [...items.values()],
+        },
+    };
+});
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { reload: routerReloadMock },
+}));
+
+// UploadQueue は enqueue spy 付き stub に差し替える (HTTP 経路は upload-queue.test.ts が担う)。
+// PendingStore は delete を観測できる memory 実装に差し替え、queued の Blob 破棄を固定する。
+vi.mock("@/lib/capture/upload-queue", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@/lib/capture/upload-queue")>()),
+    createMemoryPendingStore: () => memoryStore,
+    UploadQueue: class {
+        quotaMessage: string | null = null;
+        enqueue = enqueueMock;
+    },
+}));
+
+const fetchMock = vi.fn();
+
+function take(overrides: Partial<SelectableTake> = {}): SelectableTake {
+    return {
+        id: 101,
+        status: "ready",
+        size_bytes: 2 * 1024 * 1024,
+        duration_ms: 12_000,
+        comment: null,
+        captured_at: null,
+        sort_order: 0,
+        downloaded: false,
+        has_thumbnail: false,
+        ...overrides,
+    };
+}
+
+const cut: TakeSelectionCut = {
+    id: 34,
+    type: "step",
+    label: "手順1",
+    scene: "工具を準備する",
+    narration: "はじめに工具を準備します。",
+    subtitle_primary: "トルク 12N・m",
+    subtitle_secondary: "工具を準備する",
+    adopted: null,
+};
+
+function baseProps(overrides: Record<string, unknown> = {}) {
+    return {
+        project: { id: 7, name: "現場A" },
+        manual: { id: 12, title: "ネジ締め作業", status: "ready" as const },
+        cut,
+        takes: [take()],
+        ...overrides,
+    };
+}
+
+function jsonResponse(status: number, body: unknown = {}): Response {
+    return new Response(JSON.stringify(body), {
+        status,
+        headers: { "Content-Type": "application/json" },
+    });
+}
+
+beforeEach(() => {
+    vi.stubGlobal("fetch", fetchMock);
+    fetchMock.mockResolvedValue(jsonResponse(200, { id: 34 }));
+    // jsdom は Object URL API を持たないため、静的メソッドだけを差し替える
+    // (URL 自体を stub すると Inertia Link の URL 構築が壊れる)
+    Object.defineProperty(URL, "createObjectURL", {
+        configurable: true,
+        value: vi.fn(() => "blob:take"),
+    });
+    Object.defineProperty(URL, "revokeObjectURL", { configurable: true, value: vi.fn() });
+});
+
+afterEach(() => {
+    cleanup();
+    fetchMock.mockReset();
+    enqueueMock.mockReset();
+    routerReloadMock.mockReset();
+    storeDeleteSpy.mockReset();
+    vi.unstubAllGlobals();
+    vi.restoreAllMocks();
+});
+
+describe("Manuals/Takes — テイクの選択と採用", () => {
+    it("カットのラベルと場面が見出しに出る", () => {
+        render(Takes, { props: baseProps() });
+
+        expect(screen.getByRole("heading", { name: "手順1 のテイク選択" })).toBeInTheDocument();
+        expect(screen.getByText("工具を準備する")).toBeInTheDocument();
+    });
+
+    it("採用中テイクのタイルに青枠 (border-primary) が付き、非採用には付かない", () => {
+        const adopted = take({ id: 101 });
+        const other = take({ id: 202, sort_order: 1 });
+        render(Takes, {
+            props: baseProps({
+                takes: [adopted, other],
+                cut: { ...cut, adopted: { id: adopted.id, status: "ready" as const } },
+            }),
+        });
+
+        expect(screen.getByTestId("take-tile-101").className).toContain("border-primary");
+        expect(screen.getByTestId("take-tile-202").className).not.toContain("border-primary");
+        expect(screen.getByTestId("take-adopted-101")).toHaveTextContent("採用中");
+    });
+
+    it("サムネイル未生成のテイクは状態タイル (テイク番号 + 状態) を描画する", () => {
+        render(Takes, { props: baseProps({ takes: [take({ has_thumbnail: false })] }) });
+
+        const tile = screen.getByTestId("take-thumbnail-101");
+        expect(tile.tagName).toBe("DIV");
+        expect(tile).toHaveTextContent("テイク 1");
+        expect(tile).toHaveTextContent("使用できます");
+    });
+
+    it("サムネイル生成済みなら thumbnail endpoint の img を描画する", () => {
+        render(Takes, { props: baseProps({ takes: [take({ has_thumbnail: true })] }) });
+
+        const img = screen.getByTestId("take-thumbnail-101");
+        expect(img.tagName).toBe("IMG");
+        expect(img).toHaveAttribute(
+            "src",
+            "/app/projects/7/manuals/12/cuts/34/takes/101/thumbnail",
+        );
+    });
+
+    it("ready のテイクは playback 経由の video を描画する (署名 URL を props に載せない)", () => {
+        render(Takes, { props: baseProps() });
+
+        expect(screen.getByTestId("take-preview-video")).toHaveAttribute(
+            "src",
+            "/app/projects/7/manuals/12/cuts/34/takes/101/playback",
+        );
+    });
+
+    it("processing のテイクは video を描かず、採用押下でエラーを出す (要素は disabled でない)", async () => {
+        render(Takes, { props: baseProps({ takes: [take({ status: "processing" })] }) });
+
+        expect(screen.queryByTestId("take-preview-video")).not.toBeInTheDocument();
+        expect(screen.getByTestId("take-not-playable")).toHaveTextContent("まだ再生できません");
+
+        const button = screen.getByTestId("take-adopt");
+        expect(button).not.toBeDisabled();
+        await fireEvent.click(button);
+
+        expect(await screen.findByTestId("take-preview-error")).toHaveTextContent(
+            "「処理中」のテイクは採用できません。",
+        );
+        expect(fetchMock).not.toHaveBeenCalled();
+    });
+
+    it("採用成功で adopt へ POST し cut と takes だけを再取得する", async () => {
+        render(Takes, { props: baseProps() });
+
+        await fireEvent.click(screen.getByTestId("take-adopt"));
+
+        await waitFor(() => expect(routerReloadMock).toHaveBeenCalledWith({ only: ["cut", "takes"] }));
+        expect(fetchMock.mock.calls[0][0]).toBe(
+            "/app/projects/7/manuals/12/cuts/34/takes/101/adopt",
+        );
+        expect(fetchMock.mock.calls[0][1].method).toBe("POST");
+    });
+
+    it("採用失敗 (409) はサーバ供給の文言をそのまま表示する", async () => {
+        fetchMock.mockResolvedValue(
+            jsonResponse(409, { code: "scenario_conflict", message: "書き出し中のため変更できません。" }),
+        );
+        render(Takes, { props: baseProps() });
+
+        await fireEvent.click(screen.getByTestId("take-adopt"));
+
+        expect(await screen.findByTestId("take-preview-error")).toHaveTextContent(
+            "書き出し中のため変更できません。",
+        );
+        expect(routerReloadMock).not.toHaveBeenCalled();
+    });
+
+    it("書き出し中の manual は採用が 409 になることを押す前に告知する (ボタンは押せる)", () => {
+        render(Takes, { props: baseProps({ manual: { id: 12, title: "x", status: "rendering" } }) });
+
+        expect(screen.getByTestId("take-adopt-status-notice")).toHaveTextContent("書き出し中");
+        expect(screen.getByTestId("take-adopt")).not.toBeDisabled();
+    });
+
+    it("削除は確認ダイアログを経てから DELETE を送る (復元不可の文言を含む)", async () => {
+        render(Takes, { props: baseProps() });
+
+        await fireEvent.click(screen.getByTestId("take-delete-101"));
+
+        expect(await screen.findByText(/この操作は取り消せません/)).toBeInTheDocument();
+        expect(fetchMock).not.toHaveBeenCalled();
+
+        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+
+        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
+        expect(fetchMock.mock.calls[0][0]).toBe("/app/projects/7/manuals/12/cuts/34/takes/101");
+        expect(fetchMock.mock.calls[0][1].method).toBe("DELETE");
+        await waitFor(() => expect(routerReloadMock).toHaveBeenCalledWith({ only: ["cut", "takes"] }));
+    });
+
+    it("DL 済みテイクの削除 422 はサーバ文言を表示する", async () => {
+        fetchMock.mockResolvedValue(
+            jsonResponse(422, { message: "ダウンロード済みのテイクは削除できません。" }),
+        );
+        render(Takes, { props: baseProps({ takes: [take({ downloaded: true })] }) });
+
+        await fireEvent.click(screen.getByTestId("take-delete-101"));
+        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+
+        expect(await screen.findByTestId("take-picker-error")).toHaveTextContent(
+            "ダウンロード済みのテイクは削除できません。",
+        );
+    });
+
+    it("テイクが 0 件でも詰まず、撮影/追加を促す案内を出す", () => {
+        render(Takes, { props: baseProps({ takes: [] }) });
+
+        expect(screen.getByTestId("take-picker-empty")).toBeInTheDocument();
+        expect(screen.getByTestId("take-not-playable")).toHaveTextContent(
+            "左の一覧からテイクを選ぶと再生できます。",
+        );
+    });
+});
+
+describe("Manuals/Takes — 字幕 / ナレーション原稿の表示切替", () => {
+    it("初期状態では字幕 overlay もナレーション原稿も出ていない", () => {
+        render(Takes, { props: baseProps() });
+
+        expect(screen.queryByTestId("subtitle-overlay")).not.toBeInTheDocument();
+        expect(screen.queryByTestId("narration-script")).not.toBeInTheDocument();
+    });
+
+    it("「字幕を表示」で overlay の primary / secondary が出る", async () => {
+        render(Takes, { props: baseProps() });
+
+        await fireEvent.click(screen.getByTestId("toggle-subtitles"));
+
+        expect(await screen.findByTestId("subtitle-overlay")).toBeInTheDocument();
+        expect(screen.getByTestId("subtitle-primary")).toHaveTextContent("トルク 12N・m");
+        expect(screen.getByTestId("subtitle-secondary")).toHaveTextContent("工具を準備する");
+    });
+
+    it("「ナレーション原稿を表示」で原稿テキストが出る (音声は再生しない)", async () => {
+        render(Takes, { props: baseProps() });
+
+        // v1 は TTS 非実装。ラベルは「原稿」であって「再生」ではない
+        expect(screen.getByText("ナレーション原稿を表示")).toBeInTheDocument();
+        expect(screen.queryByText(/ナレーションを再生/)).not.toBeInTheDocument();
+        expect(screen.queryByRole("button", { name: /音声/ })).not.toBeInTheDocument();
+
+        await fireEvent.click(screen.getByTestId("toggle-narration-script"));
+
+        expect(await screen.findByTestId("narration-script")).toHaveTextContent(
+            "はじめに工具を準備します。",
+        );
+    });
+});
+
+describe("Manuals/Takes — PC ローカル動画のアップロード", () => {
+    /** metadata 読み取りの結果を差し替える (jsdom は video の loadedmetadata を発火しない) */
+    function stubVideoMetadata(outcome: number | "error" | "silent"): void {
+        const createElement = document.createElement.bind(document);
+        vi.spyOn(document, "createElement").mockImplementation(((tag: string) => {
+            const element = createElement(tag);
+            if (tag !== "video") return element;
+            const video = element as HTMLVideoElement;
+            Object.defineProperty(video, "duration", {
+                configurable: true,
+                get: () => (typeof outcome === "number" ? outcome : NaN),
+            });
+            Object.defineProperty(video, "src", {
+                configurable: true,
+                get: () => "",
+                set: () => {
+                    if (outcome === "silent") return; // timeout 経路
+                    queueMicrotask(() => {
+                        if (outcome === "error") {
+                            video.onerror?.(new Event("error"));
+                            return;
+                        }
+                        video.onloadedmetadata?.(new Event("loadedmetadata"));
+                    });
+                },
+            });
+            return video;
+        }) as typeof document.createElement);
+    }
+
+    function videoFile(type = "video/mp4"): File {
+        return new File(["bytes"], "take.mp4", { type });
+    }
+
+    async function selectFile(file: File): Promise<HTMLInputElement> {
+        const input = screen.getByTestId("take-file-input") as HTMLInputElement;
+        Object.defineProperty(input, "files", { configurable: true, value: [file] });
+        await fireEvent.change(input);
+
+        return input;
+    }
+
+    it("動画以外のファイルはエラー文言を出し、アップロードを開始しない", async () => {
+        render(Takes, { props: baseProps() });
+
+        const input = await selectFile(new File(["x"], "memo.txt", { type: "text/plain" }));
+
+        expect(await screen.findByTestId("take-upload-error")).toHaveTextContent(
+            "動画ファイルを選択してください。",
+        );
+        expect(enqueueMock).not.toHaveBeenCalled();
+        expect(input.value).toBe("");
+    });
+
+    it("61 秒の動画は事前チェックで止まり upload-url を呼ばない (quota を消費しない)", async () => {
+        stubVideoMetadata(61);
+        render(Takes, { props: baseProps() });
+
+        await selectFile(videoFile());
+
+        expect(await screen.findByTestId("take-upload-error")).toHaveTextContent(
+            "動画の長さが 1 分を超えています。",
+        );
+        expect(enqueueMock).not.toHaveBeenCalled();
+    });
+
+    it("尺を読めない (metadata error) ファイルは事前チェックを飛ばしてアップロードに進む", async () => {
+        stubVideoMetadata("error");
+        enqueueMock.mockResolvedValue({ status: "uploaded", clientTakeId: "A" });
+        render(Takes, { props: baseProps() });
+
+        await selectFile(videoFile());
+
+        await waitFor(() => expect(enqueueMock).toHaveBeenCalled());
+        expect(enqueueMock.mock.calls[0][0].durationMs).toBeNull();
+        await waitFor(() => expect(routerReloadMock).toHaveBeenCalledWith({ only: ["cut", "takes"] }));
+    });
+
+    it("尺を読めない (timeout) ファイルも事前チェックを飛ばして進む", async () => {
+        vi.useFakeTimers();
+        try {
+            stubVideoMetadata("silent");
+            enqueueMock.mockResolvedValue({ status: "uploaded", clientTakeId: "A" });
+            render(Takes, { props: baseProps() });
+
+            const input = screen.getByTestId("take-file-input") as HTMLInputElement;
+            Object.defineProperty(input, "files", { configurable: true, value: [videoFile()] });
+            void fireEvent.change(input);
+
+            await vi.advanceTimersByTimeAsync(3_000);
+
+            expect(enqueueMock).toHaveBeenCalled();
+            expect(enqueueMock.mock.calls[0][0].durationMs).toBeNull();
+        } finally {
+            vi.useRealTimers();
+        }
+    });
+
+    it("アップロード成功で cut と takes を再取得し、input が空に戻る", async () => {
+        stubVideoMetadata(20);
+        enqueueMock.mockResolvedValue({ status: "uploaded", clientTakeId: "A" });
+        render(Takes, { props: baseProps() });
+
+        const input = await selectFile(videoFile());
+
+        await waitFor(() => expect(routerReloadMock).toHaveBeenCalledWith({ only: ["cut", "takes"] }));
+        expect(enqueueMock.mock.calls[0][0]).toMatchObject({
+            projectId: 7,
+            manualId: 12,
+            cutId: 34,
+            durationMs: 20_000,
+            contentType: "video/mp4",
+        });
+        await waitFor(() => expect(input.value).toBe(""));
+    });
+
+    it("422 quota_exceeded はサーバ文言をそのまま表示する", async () => {
+        stubVideoMetadata(20);
+        enqueueMock.mockResolvedValue({
+            status: "quota_exceeded",
+            clientTakeId: "A",
+            message: "保存容量の上限に達しています。",
+        });
+        render(Takes, { props: baseProps() });
+
+        await selectFile(videoFile());
+
+        expect(await screen.findByTestId("take-upload-error")).toHaveTextContent(
+            "保存容量の上限に達しています。",
+        );
+        expect(routerReloadMock).not.toHaveBeenCalled();
+    });
+
+    it("queued (オフライン等) は Blob を捨ててから理由を出す", async () => {
+        stubVideoMetadata(20);
+        enqueueMock.mockResolvedValue({ status: "queued", clientTakeId: "A", reason: "offline" });
+        render(Takes, { props: baseProps() });
+
+        await selectFile(videoFile());
+
+        await waitFor(() => expect(storeDeleteSpy).toHaveBeenCalledWith("A"));
+        expect(await screen.findByTestId("take-upload-error")).toHaveTextContent(
+            "アップロードできませんでした。",
+        );
+        expect(await memoryStore.list()).toEqual([]);
+    });
+
+    it("enqueue が throw しても無反応にならず、input が空に戻り Blob も残らない", async () => {
+        stubVideoMetadata(20);
+        enqueueMock.mockRejectedValue(new Error("network down"));
+        render(Takes, { props: baseProps() });
+
+        const input = await selectFile(videoFile());
+
+        expect(await screen.findByTestId("take-upload-error")).toHaveTextContent(
+            "アップロードできませんでした。",
+        );
+        await waitFor(() => expect(input.value).toBe(""));
+        expect(await memoryStore.list()).toEqual([]);
+    });
+});
```

## design system 参照 (DESIGN.md の token 抜粋)

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

### 触れた atomic ディレクトリ
```
resources/js/components/atoms:
Alert.svelte
Avatar.svelte
Badge.svelte
Badge.types.ts
Button.svelte
Button.types.ts
Card.svelte
Checkbox.svelte
FormError.svelte
Input.svelte
Select.svelte
Spinner.svelte
TextLink.svelte
TextLink.types.ts
Textarea.svelte
Toggle.svelte
Toggle.types.ts
icons
input-state.ts

resources/js/components/features/manual:
AnalysisPanel.svelte
DuplicateManualDialog.svelte
ManualListRow.svelte
RenderPanel.svelte
ScenarioEditor.svelte
SourceDocumentUpload.svelte
TakeFileUpload.svelte
TakePickerList.svelte
TakePreviewPanel.svelte
TakeThumbnail.svelte
insufficient-tickets.ts

resources/js/components/molecules:
ApiKeyTabNav.svelte
Breadcrumb.svelte
CodeSnippet.svelte
DangerZone.svelte
Divider.svelte
EmptyState.svelte
FormField.svelte
NotificationBell.svelte
PageHeader.svelte
PageHeaderSection.svelte
Pagination.svelte
PasswordInput.svelte
PendingInvitationsNotice.svelte
PricingPlanCard.svelte
PricingPlanCard.types.ts
RecentAuthRecoveryNotice.svelte
StatCard.svelte
SubtitleOverlay.svelte
Tabs.svelte

resources/js/pages/Manuals:
Create.svelte
Edit.svelte
Show.svelte
Takes.svelte
```
