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
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは Laravel + Svelte アプリの改善実装をレビューするコードレビュアーである。
本 diff は bug-hunt capture-manual グループ 3 finding (F-1-03 保護キー入口防御 / F-1-01 SOP 可視化 / F-1-02 撮影 PWA 離脱防止) の実装である。詳細設計書 (下記) との一致性を最優先に、以下の観点でレビューせよ:
- 設計との一致性 (施策1〜5。施策5 は Phase A 調査で「アプリ起因経路が再現できない」ため未実装=設計の条件付きスキップに合致するか)
- 正確性・退行 (adopt の FormRequest 差し替えで正常系が壊れないか / latestSourceDocument の最新決定規則 created_at max→id max / hasDocument と document の同一スナップショット導出)
- PHPStan level 10 適合 (型 widen・@phpstan-ignore なし)
- DTO / JsonResource / Inertia props パターン (response()->json() 直書きなし)
- テスト網羅性 (テストファースト・境界=cross-org 404/PII 非露出・負のコントロール)
- セキュリティ (tenant キー不信の入口防御 / cross-org 混入なし / filename の HTML 非解釈)
- DESIGN.md 準拠 (color/radius/typography は token 経由・hex 直書きを増やさない)
- Atomic Design 準拠 (component 層の責務・アイコンは Lucide)

出力形式: ファイルごとに判定し、指摘は [Critical]/[Warning]/[Suggestion] に分類。最後に全体判定を APPROVED か CHANGES_REQUESTED で明示せよ。

---

## 詳細設計書
# 詳細設計: bughunt-capture-manual

> **Codex 合議の実施状況**: 概念設計は gpt-5.6-terra と **2 ラウンドで APPROVED**。詳細設計は
> gpt-5.6-sol と **4 ラウンドで APPROVED** (Round1 CHANGES→Round4 APPROVED、Critical は全ラウンド 0)。
> Codex (`scripts/codex`) は全ラウンド正常稼働。

## 乖離台帳 (template-divergence) の確認
本設計の変更ファイルが `docs/template-fingerprints.json` のキーに在るかを確認した (共有ファイル判定)。
- 対象キーは 4 件のみで、本設計の変更ファイル (`CaptureTakeController` / `AdoptCaptureTakeRequest` /
  `VideoManualController` / `SourceDocumentSummaryData` / `Capture/Show.svelte` / `navigation-guard.ts` /
  `Manuals/Create|Show.svelte` / `SourceDocumentUpload.svelte` / `VideoManual` / `MassAssignmentProtectedKeys`)
  は**いずれも該当キーに無い**。採用時債務一覧 (`tests/Support/TemplateDivergence/adoption-debt.tsv`) にも
  該当パスは無い。よって **`docs/template-divergence.md` への登録追加・`LedgerPins.php` の件数更新は不要**。

bug-hunt (run 20260821-095643) capture-manual グループ 3 件 (F-1-02 High / F-1-01 Medium /
F-1-03 Medium) の詳細設計。概念設計 (`conceptual-design.md`) を正とし、Codex 概念レビュー
Round 2 の残存 Warning/Suggestion を本書に織り込む。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
AI-CUE は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を
生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。
- v1: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項
1. テストなしの実装完了報告 (不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び (窓口経由のみ) — 本設計は LLM を扱わない
6. prompt 文字列のコード直書き — 本設計は該当なし
7. 操作系 POST 応答での `redirect()->intended()` — 本設計は該当なし
8. 必須条件未充足を理由にボタンを disabled にする UI (押下時にエラー表示する)
9. Artifact ツールでの成果物公開を行わない

### コーディングルール
- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`)。**RefreshDatabase** + `--parallel` (`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止)。テストデータは Factory で生成。
- **DTO + JsonResource / Inertia props** パターン。アーリーリターン推奨。
- `composer fix` (Pint) / `pnpm lint:fix`。PHP 8.4 + Laravel 12 + Svelte 5 + Inertia + TypeScript。
- **JS テスト**: Vitest (jsdom + @testing-library/svelte)。新規 `tests/js/**/*.test.ts` は既存 glob
  (`scripts/test-inventory-config.ts` の root project) に自動包含されるため**新規ファイル追加だけなら
  inventory 追記は不要** (新しいディレクトリ/glob を足す場合のみ追記)。

## 概念設計リファレンス
`devnotes/20260821-1517-bughunt-capture-manual/conceptual-design.md`
(Codex 概念レビュー: `conceptual-review-round-1.md` / `conceptual-review-round-2.md` [APPROVED])

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | F-1-03: adopt に保護キー入口防御 (FormRequest) | `app/Http/Requests/Capture/AdoptCaptureTakeRequest.php` (新), `app/Http/Controllers/Capture/CaptureTakeController.php` | High (security) |
| 2 | F-1-01a: create のファイル選択名表示 | `resources/js/pages/Manuals/Create.svelte` | Medium |
| 3 | F-1-01b: show の登録済み SOP 現況表示 | `app/DataTransferObjects/Manual/SourceDocumentSummaryData.php` (新), `app/Models/VideoManual.php`, `app/Http/Controllers/Projects/VideoManualController.php`, `resources/js/types/manual.ts`, `resources/js/pages/Manuals/Show.svelte`, (必要なら `database/factories/SourceDocumentFactory.php` 新) | Medium |
| 4 | F-1-02 Phase A: 発生源の再現・分類 (回帰テスト化) | `tests/js/pages/CaptureShow.test.ts` ほか (調査 + テスト) | High |
| 5 | F-1-02 Phase B: 確認できたアプリ起因経路の是正 (条件付き) | `resources/js/lib/capture/navigation-guard.ts` (新, 条件付き), `resources/js/pages/Capture/Show.svelte` | High (条件付き) |

---

## 施策1: F-1-03 adopt に保護キー入口防御 (FormRequest)

### 変更箇所
- 新規: `app/Http/Requests/Capture/AdoptCaptureTakeRequest.php`
- 変更: `app/Http/Controllers/Capture/CaptureTakeController.php` `adopt()` の第 1 引数
  `Illuminate\Http\Request $request` → `AdoptCaptureTakeRequest $request` (L99)

### 波及変更
- TypeScript 型定義: なし (レスポンス形状 `CaptureCutResource` は不変)
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Capture/CaptureTakeManagementTest.php` に保護キーテストを追加

### 実行順序の確認 (最重要 — Codex Round1 [Warning] 反映)
**根拠は route group の記述順ではなく `bootstrap/app.php` の実効 priority list** (`SortedMiddleware` は
priority に載る middleware 間の相対順序のみ強制する)。実効順:
`SubstituteBindings` → `EnsureProjectBelongsToCurrentOrganization` (= `project.in-current-org`) →
`HandleInertiaRequests` → … → `RequireActiveSubscription` → `EnsureAccountNotPendingDeletion`。
- **テナント境界 404** は `SubstituteBindings` (不在 id / scopeBindings の親子不整合 → 404) と
  `EnsureProjectBelongsToCurrentOrganization` (cross-org → 404) で、**FormRequest 検証より前**に閉じる
  (AGENTS.md 不変条件 10「層 2 は binding の直後・FormRequest より前で閉じる」)。subscription の
  302 短絡や凍結 302 は**テナント境界 404 より後**に置かれている (存在オラクル防止)。
- 実測の正本は **`TenantBoundaryOrderingTest`** / `ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest`
  が解決後 middleware 列で固定している。**実装時に adopt route がこれらの母集団に含まれることを確認**する。
- FormRequest の `authorize()` は `true` を返す (認可は controller の `Gate::authorize('adopt')`)。よって
  順序は **404 (binding/mw) → 422 (FormRequest 保護キー) → 403 (Gate)**。これは本アプリの既存 capture
  書き込み経路と同一の正規順序であり、adopt をそこへ合流させるだけ (新しい順序を作らない)。
- よって cross-cut / cross-org に保護キーを混ぜても **404** が先に返り 422 にはならない (既存
  `StoreCaptureTakeRequest` も同 group で cross-org 404 を維持 = 実証済み)。

### Architecture gate への影響 (Codex Round1 [Warning] 反映 — 事実確認済み)
`MassAssignmentSafetyTest` は **app/Models の `$fillable` を走査する出口防御**であり FormRequest を
列挙しない。FormRequest 側の入口防御 (`ProhibitsProtectedKeys`) を deny-by-default で強制する inventory は
存在しない。よって **新 `AdoptCaptureTakeRequest` の inventory 登録は不要**。入口防御の有効性は下記
新 Feature テストで実証する (テストなし完了の禁止に対応)。

### 現行コード
```php
// CaptureTakeController.php
use Illuminate\Http\Request;
// ...
/** 採用 (adopted_take_id は VideoManual 行ロック tx 内でのみ書く) */
public function adopt(
    Request $request,
    Project $project,
    VideoManual $manual,
    Cut $cut,
    Take $take,
    CaptureTakeService $takes,
): CaptureCutResource {
    $organization = $this->resolveCurrentOrganization($request);
    $this->resolveOrganizationProject($organization, $project);
    Gate::authorize('adopt', $take);
    // ...
}
```

### 変更後コード
```php
// app/Http/Requests/Capture/AdoptCaptureTakeRequest.php (新規)
<?php

declare(strict_types=1);

namespace App\Http\Requests\Capture;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;

/**
 * テイク採用 (POST .../takes/{take}/adopt)。
 * adopt は body を一切使わない (採用対象は URL の {take})。保護キー
 * (adopted_take_id 等) の payload 混入は tenant キー不信の入口防御として 422 で拒否する
 * (defense-in-depth。bug-hunt F-1-03)。
 */
class AdoptCaptureTakeRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true; // 認可は controller の Gate::authorize (URL 整合 guard の後)
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        // body 入力は無い。保護キー混入だけを missing で拒否する (最小)。
        return $this->protectedKeyMissingRules();
    }
}
```

```php
// CaptureTakeController.php (変更点のみ)
use App\Http\Requests\Capture\AdoptCaptureTakeRequest;
// use Illuminate\Http\Request; は他メソッド (destroy/playback/thumbnail) が使うので残す

public function adopt(
    AdoptCaptureTakeRequest $request,   // ← 差し替え
    Project $project,
    VideoManual $manual,
    Cut $cut,
    Take $take,
    CaptureTakeService $takes,
): CaptureCutResource {
    // 本文は不変 ($request は FormRequest = Request のサブ型なので resolveCurrentOrganization もそのまま)
    $organization = $this->resolveCurrentOrganization($request);
    $this->resolveOrganizationProject($organization, $project);
    Gate::authorize('adopt', $take);
    // ...
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている (`CaptureCutResource`、変更なし)
- [x] `rules()` の戻り値 phpdoc は既存 sibling と同一形式
- [x] `resolveCurrentOrganization(Request $request)` に FormRequest を渡せる (継承関係)
- [x] DTO/Resource は不変

### テスト計画 (`tests/Feature/Capture/CaptureTakeManagementTest.php`)
**テストファースト**: 保護キー 422 を期待する新規テストを先に書き fail を確認 → FormRequest 実装で green。
- [ ] 新規 `adopt: 保護キー adopted_take_id 混入は 422 (正しくネスト・認可済み)` —
  `postJson(takePath(...,'/adopt'), ['adopted_take_id' => 999])` →
  `assertStatus(422)` かつ **`assertJsonValidationErrors('adopted_take_id')`** かつ
  `$cut->fresh()->adopted_take_id === null` (副作用が起きない) を明示 (Codex Round1 [Suggestion])。
- [ ] `adopt: 全保護キーを dataset で 422` — `MassAssignmentProtectedKeys::all()` を dataset 化し、
  各キー単体混入が 422 になることを固定 (保護キー集合の増加に自動追従。Codex Round1 [Suggestion])。
  **現状の保護キーは全てトップレベルキー**である前提を dataset のコメント/テスト名に明示する
  (将来ドット記法キーが増えたら Laravel の入力構造に合わせて payload を組む。Codex Round2 [Suggestion])。
- [ ] `adopt: 保護キー混入 + cross-cut/cross-org は (422 でなく) 404` —
  binding/mw が先に閉じることの回帰固定。cross-cut: `takePath(project,manual,cutA,takeB,'/adopt')` に
  `['adopted_take_id'=>1]` → 404。cross-org: 別組織 owner で → 404。
- [ ] `adopt: 保護キー混入 + 非 project member は 422 (FormRequest が Gate より先)` —
  本アプリの正規順序 (FormRequest→Gate) を固定する。**期待値は実装の実順序に合わせる** (Codex Round1
  許容)。cross-org + subscription 不成立 + 保護キーでも 404 になることは `TenantBoundaryOrderingTest`
  が固定済みのため新規 Architecture テストは足さず、その名を根拠として記す (Codex Round1 [Warning])。
- [ ] 既存 `adopt: ready テイクを採用でき adopted_take_id が反映される` (clean body) が引き続き 200 で green。
- [ ] `DatabaseTransactions` を個別使用していないこと (RefreshDatabase グローバル)。

### リスク
- FormRequest 差し替えで adopt の正常系が壊れないか → body を使わない操作なので `rules()` は
  保護キー missing のみ。clean payload (空 body) は全ルール通過。既存正常系テストで担保。
- Architecture テスト `MassAssignmentSafetyTest` / `ControllerAuthorizationGateTest` /
  `NestedRouteIdorDefenseTest` は adopt を既に対象にしている可能性 → FormRequest 追加で
  inventory の期待が変わらないか実装時に green を確認 (route 自体は不変なので影響なしの見込み)。

---

## 施策2: F-1-01a create のファイル選択名表示

### 変更箇所
- `resources/js/pages/Manuals/Create.svelte`: `onFileChange` で選んだファイル名を state に持ち、
  file input 近傍に「選択したファイル: {name}」を表示する。純フロント (サーバ・props 変更なし)。

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/ManualsCreate.test.ts` (既存) にケース追加

### 変更後コード (要点)
```svelte
<script lang="ts">
    // 既存 form (useForm) に加え、表示用の選択ファイル名を派生させる
    let selectedFileName = $state<string | null>(null);

    function onFileChange(event: Event): void {
        const input = event.currentTarget as HTMLInputElement;
        const file = input.files?.[0] ?? null;
        form.document = file;
        selectedFileName = file?.name ?? null;
    }
</script>

<!-- file input の直後。aria-live で選択を補助技術へ通知 (Codex Round1 [Suggestion]) -->
{#if selectedFileName !== null}
    <p
        class="mt-1 text-caption text-text-secondary"
        aria-live="polite"
        data-testid="manual-document-selected-name"
    >
        選択したファイル: {selectedFileName}
    </p>
{/if}
```
- **文言**: 「選択したファイル」= まだ未送信であることが分かる表現 (Codex Round1 [Suggestion])。
- **DESIGN.md 準拠**: 既存 token (`text-caption` / `text-text-secondary`) を使い hex 直書きを増やさない。
  新規 atom/molecule は作らず既存の表示要素で済む (オーバーエンジニアリング回避)。
- create は成功時に別画面 (`Manuals/Show`) へ遷移するため `selectedFileName` の掃除は不要だが、
  もし同一画面に残る送信経路 (`form.reset()` 等) が将来入るなら `selectedFileName` も同時に消す
  (Codex Round2 [Suggestion])。

### PHPStan適合チェック
- N/A (フロントのみ)

### テスト計画 (Vitest `tests/js/pages/ManualsCreate.test.ts`)
- [ ] `ファイル選択後にファイル名が表示される` — `manual-document-input` に File を fireEvent.change で
  与え、`manual-document-selected-name` にファイル名が出ることを assert。
- [ ] `未選択時はファイル名表示が出ない` — 初期状態で `manual-document-selected-name` が存在しない。
- [ ] `別ファイルを再選択すると表示名が置き換わる` (Codex Round1 [Suggestion])。
- [ ] `選択を解除 (files 空) すると表示が消える` (Codex Round1 [Suggestion])。

### リスク
- 既存 form の submit 経路は不変 (表示用 state を足すだけ)。低リスク。

---

## 施策3: F-1-01b show の登録済み SOP 現況表示

### 変更箇所
- 新規 `app/DataTransferObjects/Manual/SourceDocumentSummaryData.php`
- `app/Models/VideoManual.php`: 最新 1 件を安定順序で引く relation `latestSourceDocument` を追加
- `app/Http/Controllers/Projects/VideoManualController.php` `show()`: `analysis` props に
  `document` (最新 SOP の DTO or null) を追加 (L160-165 付近)
- `resources/js/types/manual.ts`: `AnalysisProps` に `document` を追加
- `resources/js/pages/Manuals/Show.svelte` / `SourceDocumentUpload.svelte`: 現況を表示

### 波及変更
- TypeScript 型定義: `AnalysisProps` に `document: SourceDocumentSummaryProps | null` を追加 (**必須**)
- API Resource/DTO: `SourceDocumentSummaryData` 新設
- **表示は `Manuals/Show.svelte` の手順書パネル側に置き、`SourceDocumentUpload.svelte` の props 契約は
  変更しない** (Codex Round1 [Warning] 回避 — component 契約の波及を発生させない)。
- テストファイル: `tests/Feature/Manual/` に props 検証テスト、`tests/js/pages/ManualsShow.test.ts` に表示テスト
- Factory: `SourceDocumentFactory` が無ければ新設 (テストデータ手組み禁止)。`SourceDocumentSummaryPropsTest`
  で使用。

### DTO 契約 (Codex Round1/Round2 反映)
```php
// app/DataTransferObjects/Manual/SourceDocumentSummaryData.php (新規)
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Models\SourceDocument;
use Webmozart\Assert\Assert;

/**
 * 手順書 (SOP) パネルに出す「現在登録されている手順書」1 件の現況。
 * TS 側 types/manual.ts の SourceDocumentSummaryProps と対で保守。
 *
 * - name は SourceDocument.original_name (業務情報・PII を含み得るため、当該 manual に
 *   属する最新 1 件のみを組織境界内 relation 経由で解決したものだけを載せる)。
 * - 表示整形 (サイズ単位・日時) は Svelte 側で行う。DTO に表示文言を混ぜない。
 */
final readonly class SourceDocumentSummaryData
{
    public function __construct(
        public string $name,
        public int $sizeBytes,
        /** ISO 8601 (タイムゾーン付き) 文字列。表示整形はフロント */
        public string $uploadedAt,
    ) {}

    public static function fromDocument(SourceDocument $document): self
    {
        // created_at は timestamps 由来で Larastan は nullable と評価し得る。
        // 握り潰し (?-> ?? '') はせず non-null を明示検査してから変換する (Codex Round1 [Warning])。
        $uploadedAt = $document->created_at;
        Assert::notNull($uploadedAt, 'source_documents.created_at は非 null (timestamps)');

        return new self(
            name: $document->original_name,
            sizeBytes: $document->size_bytes,
            uploadedAt: $uploadedAt->toIso8601String(),
        );
    }

    /**
     * @return array{name: string, sizeBytes: int, uploadedAt: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'sizeBytes' => $this->sizeBytes,
            'uploadedAt' => $this->uploadedAt,
        ];
    }
}
```

### 「最新」の決定規則 (Codex Round1/Round2 [Warning])
same time でも決定的になるよう **one-of-many relation (`ofMany`)** で `created_at` max → tie-break `id` max に
固定する (`->latest()->latest()` は eager 時に全件取得→照合になり弱い、と指摘)。
```php
// app/Models/VideoManual.php
/**
 * 手順書パネルに出す「現在登録されている手順書」。追記型 immutable のため
 * 最新 (created_at max、同時刻は id max で安定) の 1 件を指す one-of-many relation。
 *
 * @return HasOne<SourceDocument, $this>
 */
public function latestSourceDocument(): HasOne
{
    return $this->hasOne(SourceDocument::class)->ofMany([
        'created_at' => 'max',
        'id' => 'max',
    ]);
}
```
```php
// VideoManualController::show() の analysis props (組織境界内。$manual は既に解決済み)
// ★ hasDocument と document は同一スナップショットから作る (同時アップロードでの食い違い防止。Codex Round1)
$document = $manual->latestSourceDocument; // 単一 manual 表示なので N+1 なし (with() を使う場合も ofMany の後)

'analysis' => [
    'job' => $analysisJob === null ? null : AnalysisJobData::fromJob($analysisJob, $manual)->toArray(),
    'hasDocument' => $document !== null,
    'document' => $document === null
        ? null
        : SourceDocumentSummaryData::fromDocument($document)->toArray(),
    'report' => $reports->build($manual)?->toArray(),
],
```
- `hasDocument` は互換のため残すが、**`document` と同じ `$document` から導出**し、`sourceDocuments()->exists()` の
  別クエリはやめる (食い違いの余地を消す)。
- 認可・組織境界は既存の `resolveOrganizationProject` + `Gate::authorize('view',$manual)` の内側。
  relation 経由 (`$manual->latestSourceDocument`) なので他組織・他 manual の行は構造的に混ざらない。

### TS 型 (`resources/js/types/manual.ts`)
```ts
/** PHP: App\DataTransferObjects\Manual\SourceDocumentSummaryData と対 */
export interface SourceDocumentSummaryProps {
    name: string;
    sizeBytes: number;
    /** ISO 8601 (TZ 付き)。表示整形はフロント */
    uploadedAt: string;
}

export interface AnalysisProps {
    job: AnalysisJobProps | null;
    hasDocument: boolean;
    /** 現在登録されている手順書 (最新 1 件)。null = 未添付 */
    document: SourceDocumentSummaryProps | null;
    report: ScenarioReportProps | null;
}
```

### 表示 (`Manuals/Show.svelte` 手順書パネル内。SourceDocumentUpload は不変)
- 手順書パネル (Show.svelte) に「現在登録されている手順書」ブロックを追加: ファイル名・サイズ
  (Svelte 側で KB/MB 整形)・アップロード日時 (Svelte 側でロケール整形)。`analysis.document === null` の
  ときは「まだ手順書は登録されていません」を表示 (「差し替える」文言との矛盾を解消)。
- **`SourceDocumentUpload.svelte` の props (`hasDocument` 等) は変更しない** — 表示は親の Show.svelte が
  担い、子 component 契約と全呼び出し元への波及を避ける (Codex Round1 [Warning])。
- サイズ/日時整形は既存の整形 util があれば再利用、無ければ小さな純関数を helper に置く
  (表示文言は DTO に持たせない方針に沿う)。ファイル名は Svelte の既定エスケープでテキスト表示
  (`{@html}` は使わない → `<script>` 等を含む名でも HTML 解釈されない)。
- DESIGN.md: token 経由・Lucide アイコン (`FileText` 等) を使用、SVG 直書きしない。

### PHPStan適合チェック
- [x] DTO は `final readonly`、`toArray()` に shape 明示
- [x] `created_at` の型: `Assert::notNull()` 後も Larastan が日時型まで確定できない場合は型を緩めず
      `Assert::isInstanceOf($uploadedAt, \Carbon\CarbonInterface::class)` で正確に絞り込む
      (Codex Round2 [Suggestion])。`@property` は `$size_bytes: int` / `original_name: string`
- [x] `latestSourceDocument` relation の phpdoc generics (`HasOne<SourceDocument, $this>`)
- [x] `response()->json()` を使わず Inertia props に `toArray()` を載せるのみ

### 日時・ロケールの表示契約 (Codex Round2 [Suggestion])
DTO の `uploadedAt` は ISO 8601 (TZ 付き) 固定。表示整形 (locale / timezone) は Svelte 側で行うが、
本アプリは Inertia SSR 未配線 (capture の landscape 判定が SSR 非前提であることと同根) のため
Node/ブラウザの timezone 差による hydration ずれは発生しない。SSR を将来入れる場合は locale/timezone を
明示指定して hydration 差分を避ける、と実装メモに残す。

### テスト計画
**Pest (`tests/Feature/Manual/SourceDocumentSummaryPropsTest.php` 新規)** — テストファースト。
安定順序は 2 ケースに分ける (Codex Round1 [Warning]):
- [ ] `show: created_at が異なるとき新しい日時の SOP が document に載る`。
- [ ] `show: created_at が同一のとき id が大きい SOP が document に載る` (tie-break の固定)。
- [ ] `show: SOP 添付済みなら document に name/sizeBytes/uploadedAt が載る`。
- [ ] `show: SOP 未添付なら document=null かつ hasDocument=false`。
- [ ] `show: hasDocument === (document !== null) が常に成り立つ` (Codex Round1 [Suggestion] 不変条件)。

PII 露出防止は境界を分ける (Codex Round1 [Warning]):
- [ ] `同一組織・別 manual の sentinel filename が当該 manual の analysis.document に出ない`。
- [ ] `別組織の manual/SOP が現在の props に混ざらない`。
- [ ] `別組織 manual を直接 show すると 404` (既存の境界だが本 finding の DTO 追加で退行しないことを固定)。
- [ ] `<script> を含む filename が Svelte でテキスト表示され HTML 解釈されない` (Vitest 側で確認)。
- [ ] Factory 経由でデータ生成 (手組み禁止)。`SourceDocumentFactory` が無ければ作成を施策に含める。

**Vitest (`tests/js/pages/ManualsShow.test.ts` 既存にケース追加)**:
- [ ] `document 有り: 手順書パネルにファイル名・サイズ・日時が出る`。
- [ ] `document null: 「まだ手順書は登録されていません」を表示し差し替え UI と矛盾しない`。
- [ ] `filename に <script> を含む document でも HTML として解釈されずテキスト表示される`。

### リスク
- クエリ増: `latestSourceDocument` の eager/lazy 1 クエリ増。`CaptureManualDetailQueryCountTest` 相当の
  クエリ数固定テストが Manuals/Show にあれば期待値更新が必要 → 実装時に確認 (`with('latestSourceDocument')`
  で eager load して N+1 を避ける)。
- `original_name` に PII/業務情報 → 既存認可の内側のみで露出。Feature テストで境界固定。

---

## 施策4: F-1-02 Phase A 発生源の再現・分類 (必須成果)

### 目的
多重実行ノイズを排したクリーン単一セッションで「撮影 PWA の**アプリ自コード**が `/app/` 外への
遷移を起こすか」を確定し、回帰テストとして残す。**原因が確認できなければ施策5 (Phase B の恒久
ガード) は実装しない** (Codex Round2 総括の判断基準)。

### 調査手順 (実装の最初に実施し、結果を実装 devnotes に記録)
1. **遷移種別の分類 (Codex Round2 [Warning])**: 観測時に以下を必ず区別して記録する。
   - アセット version 不一致による `409`: **現在 URL** のハードリロード
   - アプリが明示する `Inertia::location()`: `X-Inertia-Location` **ヘッダ実値**の URL へハードビジット
   - `window.location` / ハーネス操作: Inertia 外の document navigation
   ステータスコードだけでなく **`X-Inertia-Location` の実値**を記録する。
2. **記録手段の範囲 (Codex Round2 [Suggestion])**: 既存の playwright ハーネス
   (`scripts/run-browser-test.sh`) で取得できる範囲に限定する — request の `resourceType`
   (`document` vs `xhr`/`fetch`)、URL、response の `X-Inertia` / `X-Inertia-Location` ヘッダ。
   ブラウザ内部 initiator の厳密取得 (CDP) には依存しない (取れる範囲で initiator を補助記録)。
   `beforeunload` は補助観測に格下げ。
3. サーバ側 `CaptureManualController::show` が render のみで redirect を持たないこと、capture コードに
   `window.location`/`router.visit`/`router.get` が無いこと (概念設計の一次調査) を再確認。

### 回帰テスト (Vitest。ハーネス走行に依存しない決定的テスト)
jsdom では実 Inertia のフルロードは再現できないため、**アプリ配線の回帰**を Vitest で固定する。
**個別メソッド (`router.visit`/`router.get`) の呼出有無ではなく、`before` event に現れた visit の
url/method を判定する** (`<Link>` / form helper / `router.post` 等の別経路を見逃さない。Codex Round1 [Warning]):
- **空振り防止 (Codex Round2 [Warning])**: 「イベント 0 件で green」を避ける。mock router の
  **全 visit 入口 (`reload`/`visit`/`get`/`post` と、mock する場合の `<Link>` クリック) を共通の
  before-event emitter へ通す**ように配線し、以下を満たす:
  - [ ] 通常フローで**現 URL への reload イベントが最低 1 件観測される**ことを assert (母集団非空を固定)。
  - [ ] 通常フローで観測された visit の destination が現 URL への部分リロードのみで、
    許可されない destination を含まないことを assert。
  - [ ] **負のコントロール**: 禁止 destination を合成入力として emitter に流し、判定器が確実に
    検出する (「違反ゼロ」と「母集団ゼロ」を区別。空振り検査)。
- [ ] **通常フロー**と**復帰性テスト**を分ける (Codex Round1 [Suggestion])。復帰性は施策5 の
  「ハードロードで失う状態」テストで扱う。

### 証拠の正本 (Codex Round1 [Suggestion])
`CaptureManualController::show` が render のみでも、認証 / subscription / Inertia middleware は
controller の前後で redirect / 409 を生成し得る。したがって**証拠の正本は controller 本体ではなく
ネットワーク上の最終 response** とする。Playwright ハーネスで document/XHR とレスポンスヘッダを実観測する。

### 成果の記録 (3 分岐。Codex Round1 [Warning] — 非再現でハーネス主因を断定しない)
- (a) **アプリ起因経路を観測した**: 発火元を特定し、施策5 Phase B の実施可否を判断する。
- (b) **二重 fan-out を実観測し、問題との時系列対応も取れた**: ハーネス起因と確定し、orchestrator へ
  「同一 run-id・同一 shard への bughunt-shard subagent 二重 fan-out を検出・失敗させる」ことを申し送る。
- (c) **どちらも観測できない**: 「調査範囲ではアプリ起因を再現できず、原因未確定」と記録する。
  施策5 は実装しないが、**ハーネス起因とは断定しない**。回帰テスト (通常フローで外部 destination が
  発生しない) は恒久的に残す。

### テスト計画
上記 Vitest 回帰テスト。Pest は該当なし (サーバ側は redirect を持たないことの確認のみで、
既存 `CaptureManualBrowsingTest` が show の 200 render を固定済み。必要なら
「show は redirect でなく Inertia render を返す」1 ケースを追加)。

### リスク
- Phase A で原因が確定できない (再現しない) 可能性 → その場合も「アプリ起因経路は再現せず」を
  結論として記録し、回帰テスト (通常フローで外部 visit が起きない) は恒久的に残す。

---

## 施策5: F-1-02 Phase B 確認できたアプリ起因経路の是正 (条件付き)

> **前提**: 施策4 Phase A で「Capture/Show が自ら起こす `/app/` 外への programmatic Inertia visit」が
> 確認できた場合のみ実装する。確認できなければ本施策はスキップ (Codex Round2 総括)。

### まず「ガードを入れない」選択を検討する (Codex Round1 [Suggestion] / 思考原則2)
Phase A で**発火元を特定して除去でき、かつ同種の別経路が存在する証拠が無い**なら、
**包括ガードは実装しない** (「1 件のバグ確認で必ず包括ガードを足す」は過大)。ガードを足すのは
**複数経路・再発リスクが確認された回帰防止**に限る。以下はガードを入れると決めた場合の設計。

### 是正の 2 本立て
1. **発火元の根治**: 特定した programmatic visit の発生源そのものを止める (握り潰さない)。
2. **回帰防止ガード** (狭く確定 — 許可リスト方式): **Capture/Show が発行する visit を許可リスト化し、
   それ以外の `/app/` 外 programmatic visit を拒否する。** 許可リスト:
   - 現 URL への部分リロード (`reloadManual` = `router.reload({only:["manual"]})`)。
   - `/app/...` 内に留まる visit。
   - **明示遷移トークン**が立っている visit (下記)。

### 保証範囲の限定 (Codex Round1 [Warning] — 矛盾を除去)
`router.on("before")` は**ページ全体のグローバル listener** であり layout / 共有 component / `<Link>` の
visit も捕捉する。したがって:
- **本ガードは UX 継続性のための回帰防止であって、セキュリティ境界ではない**と明記する
  (テナント/認可境界は middleware + Gate が担う。ここでは重複防御を主張しない)。
- **保証しないもの**: server response 後に発生する**ハードビジット** (`window.location` /
  `409 + X-Inertia-Location` / ブラウザ back/forward = popstate) は `before` で止められないため対象外
  (妨げもしない)。
- **認証失効等の扱い**: 「認証失効を before 時点で判定して通す」という**実際には判定できない契約は作らない**
  (Codex Round1 [Warning] の矛盾指摘)。client-side の programmatic な認証離脱を許す必要があるなら、
  それは**明示 intent として列挙**する (一般例外として無条件に通さない)。ハードビジット経由の
  認証離脱 (サーバ 302→Inertia location) は上記のとおり対象外なのでそもそも妨げない。

### 明示遷移トークン: 同期 visit wrapper に閉じる (Codex Round1 [Warning] — stale intent 除去)
click ハンドラで先にトークンを立てると、modifier click / preventDefault / Link 中断で visit が
発生せずトークンが残り、後続 visit を誤許可する。よって**トークン設定と visit を同期 1 操作に集約**する:
```ts
// Capture/Show 側の明示遷移専用関数 (時間依存でなく url+method+single-use)
function visitExplicitly(url: URL, method: "get"): void {
    pendingIntent = canonicalize(url, method);
    try {
        router.visit(url, { method });
    } finally {
        pendingIntent = null; // 成否に関わらず即破棄 = single-use
    }
}
```
- `before` ガードは pending visit が `pendingIntent` と canonical 一致するときのみ通し、一致・不一致とも
  **その場で破棄**する (「一致するまで残す」はしない)。`<Link>` の click 順序に依存しない。
- unmount 時にも `pendingIntent` を破棄。
- **`visitExplicitly` は「`router.visit()` が返る前に before が同期発火し intent を消費する」ことに依存する。**
  単純な mock だけでは導入済み Inertia 版の実契約を固定できない (Codex Round2 [Warning])。よって:
  - **第一候補 (最小案)**: 外向きの明示遷移は**素の native anchor (`<a href>` 通常遷移)** にして、
    トークン機構自体を不要にする。PC 詳細リンク (`マニュアル詳細へ`) を撮影 PWA から**削除**できれば
    さらに小さくなる (Codex 推奨)。運用契約 (`docs/architecture.md §撮影 PWA の運用契約`) と Phase A の
    結果を踏まえ、実装時に「リンク削除」/「native anchor」/「wrapper 経由」を確定する。
  - **wrapper を残す場合**: before が同期発火して intent を消費した後に `router.visit()` が戻る契約を
    テストで固定し、mock は**実イベント順を再現**する。**非同期発火させた場合に誤許可しない**ことも
    負例で確認する。

### helper (`resources/js/lib/capture/navigation-guard.ts` 新規, 条件付き)
URL 判定は**文字列 prefix でなく `URL` 正規化**で行う (Codex Round1 [Warning])。visit の url 型は
`string | URL` (Inertia 公式) なので**独自に string へ狭めず、導入済みバージョンの event 型をそのまま使う**。
```ts
/**
 * 撮影 PWA (Capture/Show) マウント中だけ、撮影画面が自ら起こし得ない
 * /app/ 外への programmatic Inertia visit を拒否する狭い「UX 回帰防止」ガード。
 * これはセキュリティ境界ではない (テナント/認可は middleware + Gate が担う)。
 * ハードビジット (window.location / 409+X-Inertia-Location / popstate) は before で
 * 止められないため保証対象外 (docblock に明記)。
 */

/** /app/ 内 URL か。origin 一致 + 正規化 pathname で判定 (prefix 文字列一致にしない)。
 *  ★ new URL() 失敗 (malformed) は throw させず in-app でない = 拒否側に倒す (Codex Round2 [Warning]) */
export function isInAppUrl(value: string | URL): boolean {
    let url: URL;
    try {
        url = new URL(value, window.location.href);
    } catch {
        return false; // 解析不能は in-app ではない (許可リスト方式なので拒否側へ)
    }
    if (url.origin !== window.location.origin) return false; // //evil/app, https://evil/app を弾く
    return url.pathname === "/app" || url.pathname.startsWith("/app/"); // /app.evil を弾く
}

/** url+method の canonical キー (完全一致契約: origin+pathname+search+hash)。
 *  **例外を外へ漏らさず失敗は null で返す** (before handler が止まらない) */
export function canonicalize(value: string | URL, method: string): string | null {
    try {
        const url = new URL(value, window.location.href);
        // 「URL 完全一致」契約に合わせ hash まで含める (Codex Round3 [Warning])
        return `${method.toLowerCase()} ${url.origin}${url.pathname}${url.search}${url.hash}`;
    } catch {
        return null;
    }
}

// before handler の許可判定 (★ null === null を許可に使わない。Codex Round3 [Warning]):
//   const visitKey = canonicalize(visit.url, visit.method);
//   const explicitlyAllowed =
//       visitKey !== null && pendingIntent !== null && visitKey === pendingIntent;
// intent 生成側 (visitExplicitly) も canonicalize が null なら intent を立てず visit しない。

// register(guard): router.on("before", handler) を張り、unsubscribe を返す。
// handler は event.detail.visit の公式型 (url: string|URL, method) をそのまま読む。
```
- **負例テスト**: `https://evil.example/app/...` / `//evil.example/app/...` / `/app.evil/...` を
  in-app と誤判定しないこと。**malformed URL / 異常 scheme / dot-segment (`/app/../x`) の正規化**も
  テストに含め、解析失敗は拒否側に倒れることを固定する (Codex Round2 [Warning])。
- page 側は `onMount` で register、cleanup で解除 + `pendingIntent` 破棄。

### ハードロードで失う状態の保証 (Codex Round2 [Warning]。状態ごとに分ける)
| 状態 | 保証方針 | 対応テスト (Codex Round1 [Warning] — 各行にテスト) |
|------|----------|------|
| キュー保存**前**の `<input type=file>` 選択 | 自動復元不可 → **再選択を明確に案内** | 再選択案内の表示要素を実装対象に追加し、Vitest で表示を固定 |
| キュー保存**後**のアップロード | IDB から `resumeUploads` で再開 | onMount で `resumeUploads` が呼ばれ**二重 enqueue しない** Vitest |
| サーバ保存済み・未採用 take | 詳細 GET 再取得で再出現し採用へ戻せる | 再 GET の props/resource に未採用 take が再出現する Pest Feature |
| UI のみ (`selectedCutId` / 全画面ラッチ) | 安全な初期状態へ戻す | 再 mount で安全初期値になる component Vitest |

「復帰導線」と「元状態の自動復元」を同義にしない (文言・テストで区別)。

### テスト計画
**Vitest (`tests/js/lib/capture/navigation-guard.test.ts` 新規)** — テストファースト:
- [ ] `/app/ 内 visit は通す`。
- [ ] `/app/ 外への programmatic visit (トークン無し) はキャンセル (event.preventDefault 相当)`。
- [ ] `明示トークン一致の /app/ 外 visit は 1 回だけ通し、2 回目はキャンセル (single-use)`。
- [ ] `method/url がトークンと不一致なら通さない`。
- [ ] `reloadManual 相当 (現 URL 部分リロード) を巻き込まない`。
- [ ] `URL 正規化の負例` — `https://evil.example/app/x` / `//evil.example/app/x` / `/app.evil/x` を
  in-app と誤判定しない (origin + 正規化 pathname 判定)。
- [ ] `visitExplicitly の finally で pendingIntent が必ず破棄される` (visit が例外/中断でも stale しない)。
- [ ] `canonicalize 失敗 (null) を許可判定に使わない` (Codex Round3 [Warning]) —
  malformed URL かつ `pendingIntent === null` は拒否 / intent 生成側の canonicalize が null なら
  intent を立てず visit を許可しない / `null === null` を許可に使わない。
- [ ] `canonical キーは hash まで含む` — search/hash 違いを別遷移として区別する (完全一致契約)。

**Vitest (`tests/js/pages/CaptureShow.test.ts`)**:
- [ ] `マウントで before ガードが register され、unmount で解除される`。
- [ ] 明示リンク押下由来 / 戻る進む / offline→online 復帰で `reloadManual`・正規遷移が阻害されない
  (Codex Round1 [Warning] 回帰)。

### リスク
- ガードが正常フロー (reloadManual / 明示リンク) を巻き込む後退 → 許可リスト方式 + 回帰テストで固定。
- **認証失効の扱い (本文の限定契約と整合。Codex Round2 [Warning])**:
  サーバ応答後の認証失効に伴うハードビジット (302→Inertia location / `X-Inertia-Location`) は
  **ガード対象外であり妨げない**。client-side の programmatic visit は、**認証失効を推測して一般許可せず、
  明示 intent に登録した経路だけを許可する** (判定不能な認証失効を一般例外にしない)。
- **条件付き施策**: Phase A で経路が確認できなければ実装しない (過大回避)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 3 finding は互いに独立で影響ファイルの重複が少ない (施策1=Capture Request/Controller、施策2/3=Manuals + DTO、施策4/5=Capture/Show + lib)。Capture/Show は施策4/5 で共有するが同一 finding 内。既存 main への小さめの積み上げで完結し、大規模リファクタや後方互換並走を伴わない。standalone にするほどの独立世界は不要。 |
| 競合リスク | 施策4→5 は同一 finding 内で順序依存 (Phase A の結果で Phase B 実施可否が決まる)。施策1/2/3 は相互非干渉。`resources/js/types/manual.ts` と `VideoManualController::show` は施策3 のみが触る。 |

## 使命・禁止事項チェック (最終)
- 全施策が使命に寄与: F-1-02=ナビ撮影の連続性、F-1-01=SOP 起点の確信、F-1-03=tenant キー不信の防御。
- 禁止事項: `response()->json()` 直書き無し (Inertia props + DTO)。テストなし完了無し (各施策に
  Pest/Vitest を先行)。既存テスト削除・上書き無し (追加のみ)。過大な案を避け Phase B は条件付き。
- コーディングルール: PHPStan level 10 / RefreshDatabase グローバル / Factory 生成 / DTO 契約 /
  DESIGN.md token & Lucide / Atomic Design の責務。


## 実装差分 (git diff)
```diff
diff --git a/app/DataTransferObjects/Manual/SourceDocumentSummaryData.php b/app/DataTransferObjects/Manual/SourceDocumentSummaryData.php
new file mode 100644
index 00000000..8100203c
--- /dev/null
+++ b/app/DataTransferObjects/Manual/SourceDocumentSummaryData.php
@@ -0,0 +1,53 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Manual;
+
+use App\Models\SourceDocument;
+use Carbon\CarbonInterface;
+use Webmozart\Assert\Assert;
+
+/**
+ * 手順書 (SOP) パネルに出す「現在登録されている手順書」1 件の現況。
+ * TS 側 types/manual.ts の SourceDocumentSummaryProps と対で保守。
+ *
+ * - name は SourceDocument.original_name (業務情報・PII を含み得るため、当該 manual に
+ *   属する最新 1 件のみを組織境界内 relation 経由で解決したものだけを載せる)。
+ * - 表示整形 (サイズ単位・日時) は Svelte 側で行う。DTO に表示文言を混ぜない。
+ */
+final readonly class SourceDocumentSummaryData
+{
+    public function __construct(
+        public string $name,
+        public int $sizeBytes,
+        /** ISO 8601 (タイムゾーン付き) 文字列。表示整形はフロント */
+        public string $uploadedAt,
+    ) {}
+
+    public static function fromDocument(SourceDocument $document): self
+    {
+        // created_at は timestamps 由来で Larastan は nullable と評価し得る。
+        // 握り潰し (?-> ?? '') はせず non-null を明示検査してから変換する。
+        $uploadedAt = $document->created_at;
+        Assert::isInstanceOf($uploadedAt, CarbonInterface::class, 'source_documents.created_at は非 null (timestamps)');
+
+        return new self(
+            name: $document->original_name,
+            sizeBytes: $document->size_bytes,
+            uploadedAt: $uploadedAt->toIso8601String(),
+        );
+    }
+
+    /**
+     * @return array{name: string, sizeBytes: int, uploadedAt: string}
+     */
+    public function toArray(): array
+    {
+        return [
+            'name' => $this->name,
+            'sizeBytes' => $this->sizeBytes,
+            'uploadedAt' => $this->uploadedAt,
+        ];
+    }
+}
diff --git a/app/Http/Controllers/Capture/CaptureTakeController.php b/app/Http/Controllers/Capture/CaptureTakeController.php
index 696f7ad1..b8701c26 100644
--- a/app/Http/Controllers/Capture/CaptureTakeController.php
+++ b/app/Http/Controllers/Capture/CaptureTakeController.php
@@ -9,6 +9,7 @@
 use App\Enums\Manual\TakeStatus;
 use App\Http\Concerns\ResolvesCurrentOrganization;
 use App\Http\Controllers\Controller;
+use App\Http\Requests\Capture\AdoptCaptureTakeRequest;
 use App\Http\Requests\Capture\MarkTakeDownloadedRequest;
 use App\Http\Requests\Capture\StoreCaptureTakeRequest;
 use App\Http\Requests\Capture\UpdateCaptureTakeRequest;
@@ -97,7 +98,7 @@ public function destroy(
 
     /** 採用 (adopted_take_id は VideoManual 行ロック tx 内でのみ書く) */
     public function adopt(
-        Request $request,
+        AdoptCaptureTakeRequest $request,
         Project $project,
         VideoManual $manual,
         Cut $cut,
diff --git a/app/Http/Controllers/Projects/VideoManualController.php b/app/Http/Controllers/Projects/VideoManualController.php
index 27d10e7e..1da8d26c 100644
--- a/app/Http/Controllers/Projects/VideoManualController.php
+++ b/app/Http/Controllers/Projects/VideoManualController.php
@@ -9,6 +9,7 @@
 use App\DataTransferObjects\Manual\ManualListQuery;
 use App\DataTransferObjects\Manual\RenderJobData;
 use App\DataTransferObjects\Manual\ScenarioDocumentData;
+use App\DataTransferObjects\Manual\SourceDocumentSummaryData;
 use App\Enums\Manual\RenderKind;
 use App\Enums\Manual\VideoManualStatus;
 use App\Http\Concerns\ResolvesCurrentOrganization;
@@ -126,6 +127,10 @@ public function show(
         $category = $manual->category;
 
         // stale な失敗 (失敗確定後に scenario 保存が成立) は job=null で抑制する (T032 / F-1-1)
+        // 現在登録されている手順書 (最新 1 件)。hasDocument / document を同一行から導出する。
+        // 単一 manual の表示なので N+1 は無い (行ロード 1 クエリ)。
+        $sourceDocument = $manual->latestSourceDocument;
+
         $analysisJob = $manuals->displayAnalysisJob($manual);
         $renderJob = $manuals->displayRenderJob($manual);
         $previewJob = $manuals->displayPreviewJob($manual);
@@ -156,12 +161,19 @@ public function show(
                     : ['id' => $category->id, 'name' => $category->name],
                 'created_at' => $manual->created_at?->format('Y-m-d H:i') ?? '',
             ],
-            // AI 解析パネル (最新 job + 手順書有無)。AnalysisJobData::toArray() と対
+            // AI 解析パネル (最新 job + 手順書現況)。AnalysisJobData::toArray() と対
+            // hasDocument と document は同一スナップショット ($sourceDocument) から作る
+            // (同時アップロードでの食い違いを構造的に消す)。
             'analysis' => [
                 'job' => $analysisJob === null
                     ? null
                     : AnalysisJobData::fromJob($analysisJob, $manual)->toArray(),
-                'hasDocument' => $manual->sourceDocuments()->exists(),
+                'hasDocument' => $sourceDocument !== null,
+                // 現在登録されている手順書 (最新 1 件)。null = 未添付。
+                // relation 経由なので他組織・他 manual の行は構造的に混ざらない。
+                'document' => $sourceDocument === null
+                    ? null
+                    : SourceDocumentSummaryData::fromDocument($sourceDocument)->toArray(),
                 // 生成結果の確認 (LLM の所見 + 現在の cuts への決定的検査)。null = 出す材料が無い。
                 // 描画時点のスナップショットであり常に最新ではない (render.coverage と同じ性質)。
                 'report' => $reports->build($manual)?->toArray(),
diff --git a/app/Http/Requests/Capture/AdoptCaptureTakeRequest.php b/app/Http/Requests/Capture/AdoptCaptureTakeRequest.php
new file mode 100644
index 00000000..2267c941
--- /dev/null
+++ b/app/Http/Requests/Capture/AdoptCaptureTakeRequest.php
@@ -0,0 +1,33 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Requests\Capture;
+
+use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use Illuminate\Foundation\Http\FormRequest;
+
+/**
+ * テイク採用 (POST .../takes/{take}/adopt)。adopt は body を一切使わない
+ * (採用対象は URL の {take})。保護キー (adopted_take_id 等) の payload 混入は
+ * tenant キー不信の入口防御として 422 で拒否する (defense-in-depth。bug-hunt F-1-03)。
+ */
+class AdoptCaptureTakeRequest extends FormRequest
+{
+    use ProhibitsProtectedKeys;
+
+    public function authorize(): bool
+    {
+        return true; // 認可は controller の Gate::authorize (URL 整合 guard の後)
+    }
+
+    /**
+     * body 入力は無い。保護キー混入だけを missing で拒否する (最小)。
+     *
+     * @return array<string, list<mixed>>
+     */
+    public function rules(): array
+    {
+        return $this->protectedKeyMissingRules();
+    }
+}
diff --git a/app/Models/VideoManual.php b/app/Models/VideoManual.php
index 1b71c7ac..e2a4c4b6 100644
--- a/app/Models/VideoManual.php
+++ b/app/Models/VideoManual.php
@@ -86,6 +86,21 @@ public function sourceDocuments(): HasMany
         return $this->hasMany(SourceDocument::class);
     }
 
+    /**
+     * 手順書パネルに出す「現在登録されている手順書」。追記型 immutable のため
+     * 最新 (created_at max、同時刻は id max で安定) の 1 件を指す one-of-many relation。
+     * (`->latest()->latest()` は eager 時に全件取得→照合になり弱いため ofMany を使う)
+     *
+     * @return HasOne<SourceDocument, $this>
+     */
+    public function latestSourceDocument(): HasOne
+    {
+        return $this->hasOne(SourceDocument::class)->ofMany([
+            'created_at' => 'max',
+            'id' => 'max',
+        ]);
+    }
+
     /**
      * @return HasMany<Cut, $this>
      */
diff --git a/resources/js/pages/Manuals/Create.svelte b/resources/js/pages/Manuals/Create.svelte
index 80296d4f..987febb9 100644
--- a/resources/js/pages/Manuals/Create.svelte
+++ b/resources/js/pages/Manuals/Create.svelte
@@ -51,9 +51,14 @@
         document: null,
     });
 
+    // 選択済みファイル名の表示用 state (まだ未送信であることが分かる文言で示す)
+    let selectedFileName = $state<string | null>(null);
+
     function onFileChange(event: Event): void {
         const input = event.currentTarget as HTMLInputElement;
-        form.document = input.files?.[0] ?? null;
+        const file = input.files?.[0] ?? null;
+        form.document = file;
+        selectedFileName = file?.name ?? null;
     }
 
     function submit(event: SubmitEvent): void {
@@ -127,6 +132,15 @@
                                 class="block w-full text-body text-text file:mr-3 file:rounded-md file:border file:border-border file:bg-surface file:px-3 file:py-1.5 file:text-caption file:text-text"
                                 data-testid="manual-document-input"
                             />
+                            {#if selectedFileName !== null}
+                                <p
+                                    class="mt-1 text-caption text-text-secondary"
+                                    aria-live="polite"
+                                    data-testid="manual-document-selected-name"
+                                >
+                                    選択したファイル: {selectedFileName}
+                                </p>
+                            {/if}
                         {/snippet}
                     </FormField>
                     <div class="flex items-center gap-2">
diff --git a/resources/js/pages/Manuals/Show.svelte b/resources/js/pages/Manuals/Show.svelte
index 43a0a0f4..a0a8b9fc 100644
--- a/resources/js/pages/Manuals/Show.svelte
+++ b/resources/js/pages/Manuals/Show.svelte
@@ -1,6 +1,8 @@
 <script lang="ts">
     import { page, router } from "@inertiajs/svelte";
-    import { BookOpen, Camera } from "@lucide/svelte";
+    import { BookOpen, Camera, FileText } from "@lucide/svelte";
+    import { formatBytes } from "@/lib/format-bytes";
+    import { formatDateTime } from "@/lib/date-format";
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
@@ -168,6 +170,31 @@
                     <p class="mt-2 text-caption text-text-secondary">
                         PDF / Excel / テキストの手順書をアップロードできます。差し替えた場合は最新のファイルが解析対象になります。
                     </p>
+                    <!-- 現在登録されている手順書の現況 (差し替え文言との矛盾を解消) -->
+                    <div class="mt-4" data-testid="source-document-current">
+                        {#if analysis.document !== null}
+                            <div class="flex items-start gap-2 rounded-md border border-border bg-surface p-3">
+                                <FileText class="mt-0.5 size-4 shrink-0 text-text-secondary" />
+                                <div class="min-w-0">
+                                    <p
+                                        class="truncate text-body text-text"
+                                        data-testid="source-document-name"
+                                    >
+                                        {analysis.document.name}
+                                    </p>
+                                    <p class="text-caption text-text-secondary">
+                                        {formatBytes(analysis.document.sizeBytes)} ・ {formatDateTime(
+                                            analysis.document.uploadedAt,
+                                        )}
+                                    </p>
+                                </div>
+                            </div>
+                        {:else}
+                            <p class="text-caption text-text-secondary" data-testid="source-document-empty">
+                                まだ手順書は登録されていません。
+                            </p>
+                        {/if}
+                    </div>
                     <div class="mt-4">
                         <SourceDocumentUpload
                             projectId={project.id}
diff --git a/resources/js/types/manual.ts b/resources/js/types/manual.ts
index 428809c1..eecfa259 100644
--- a/resources/js/types/manual.ts
+++ b/resources/js/types/manual.ts
@@ -273,10 +273,20 @@ export interface ScenarioReportProps {
     }[];
 }
 
+/** PHP: App\DataTransferObjects\Manual\SourceDocumentSummaryData と対 */
+export interface SourceDocumentSummaryProps {
+    name: string;
+    sizeBytes: number;
+    /** ISO 8601 (TZ 付き)。表示整形はフロント */
+    uploadedAt: string;
+}
+
 /** PHP: VideoManualController::show の analysis props と対 */
 export interface AnalysisProps {
     job: AnalysisJobProps | null;
     hasDocument: boolean;
+    /** 現在登録されている手順書 (最新 1 件)。null = 未添付 */
+    document: SourceDocumentSummaryProps | null;
     /** null = 出す材料が無い (cuts も所見も無い) */
     report: ScenarioReportProps | null;
 }
diff --git a/tests/Architecture/CurrentRenderArtifactInventoryTest.php b/tests/Architecture/CurrentRenderArtifactInventoryTest.php
index 0dda8e1b..b2b45989 100644
--- a/tests/Architecture/CurrentRenderArtifactInventoryTest.php
+++ b/tests/Architecture/CurrentRenderArtifactInventoryTest.php
@@ -452,25 +452,33 @@ public static function phpFiles(string $dir): array
         'succeeded 条件が 2 つ以上あります。候補行 relation が増えた可能性があるため区分を再審査してください');
 
     // ofMany( / hasOne( の件数は**ファイル単位の粗い代理検査**である。T198 で
-    // 一覧カードの代表サムネイル候補 (coverCut。cuts が対象で render_jobs とは無関係) が
-    // 同じ形の relation として増えたため、現在値は 2 本ちょうどである。
-    // 完全一致で pin してあるので、3 本目が増えても 1 本に減っても赤くなる。
-    // **どちらが成果物側かは (c) の名前 pin が固定する**ので、代理検査が 2 になっても
-    // 「2 本目の成果物選択式を足せない」という不変条件の検出力は落ちていない。
-    expect(RenderArtifactSelectionScanner::countCalls($tokens, 'ofMany'))->toBe(2,
-        'ofMany( が 2 回ではありません (one-of-many relation の本数が増減しています。'
+    // 一覧カードの代表サムネイル候補 (coverCut。cuts が対象で render_jobs とは無関係) が、
+    // T238 で手順書パネルの現況候補 (latestSourceDocument。source_documents が対象で
+    // render_jobs とは無関係) が、いずれも同じ形の relation として増えたため、
+    // 現在値は 3 本ちょうどである。完全一致で pin してあるので、4 本目が増えても減っても赤くなる。
+    // **どちらが成果物側かは (c) の名前 pin が固定する**ので、代理検査が 3 になっても
+    // 「2 本目の成果物選択式を足せない」という不変条件の検出力は落ちていない
+    // (成果物側の増加は (b) の succeeded 条件件数 === 1 が捉える。latestSourceDocument は
+    // render_jobs を 1 バイトも見ず succeeded 条件を持たないため成果物側ではない)。
+    expect(RenderArtifactSelectionScanner::countCalls($tokens, 'ofMany'))->toBe(3,
+        'ofMany( が 3 回ではありません (one-of-many relation の本数が増減しています。'
         .'成果物側が増えたのか別概念が増えたのかを名前で確かめて区分を再審査してください)');
-    expect(RenderArtifactSelectionScanner::countCalls($tokens, 'hasOne'))->toBe(2,
-        'hasOne( が 2 回ではありません (one-of-many relation の本数が増減しています)');
+    expect(RenderArtifactSelectionScanner::countCalls($tokens, 'hasOne'))->toBe(3,
+        'hasOne( が 3 回ではありません (one-of-many relation の本数が増減しています)');
 
     // (c) 候補行の名前と対象種別を pin する (rename / kind 変更は再審査の合図)
     expect(RenderArtifactSelectionScanner::declaresFunction($tokens, 'latestSucceededRender'))->toBeTrue(
         '候補行 relation latestSucceededRender() が見つかりません (rename したら目録と parity テストを見直すこと)');
-    // 成果物と無関係な 2 本目 (T198 の代表サムネイル候補) も名前で pin する =
-    // (b) の件数 2 の内訳が「成果物 1 本 + coverCut 1 本」であることを機械で固定する。
+    // 成果物と無関係な 2・3 本目 (T198 の代表サムネイル候補 / T238 の手順書現況候補) も
+    // 名前で pin する = ofMany/hasOne の件数 3 の内訳が
+    // 「成果物 1 本 (latestSucceededRender) + coverCut 1 本 + latestSourceDocument 1 本」で
+    // あることを機械で固定する。
     expect(RenderArtifactSelectionScanner::declaresFunction($tokens, 'coverCut'))->toBeTrue(
         '代表サムネイル候補 relation coverCut() が見つかりません。'
-        .'(b) の件数 2 の内訳が変わっているため、成果物側が増えていないかを再審査してください');
+        .'ofMany/hasOne の件数 3 の内訳が変わっているため、成果物側が増えていないかを再審査してください');
+    expect(RenderArtifactSelectionScanner::declaresFunction($tokens, 'latestSourceDocument'))->toBeTrue(
+        '手順書現況候補 relation latestSourceDocument() が見つかりません (source_documents 対象・'
+        .'render_jobs 非参照)。ofMany/hasOne の件数 3 の内訳が変わっているため再審査してください');
     expect(RenderArtifactSelectionScanner::countEnumCaseReferences($tokens, 'RenderKind', 'Render'))->toBe(1,
         '候補行が見る種別 (RenderKind::Render) の参照数が変わりました (preview を混ぜていないか再審査)');
 
diff --git a/tests/Feature/Capture/CaptureTakeManagementTest.php b/tests/Feature/Capture/CaptureTakeManagementTest.php
index 23caa991..84fc7934 100644
--- a/tests/Feature/Capture/CaptureTakeManagementTest.php
+++ b/tests/Feature/Capture/CaptureTakeManagementTest.php
@@ -14,6 +14,7 @@
 use App\Models\User;
 use App\Models\VideoManual;
 use App\Services\Capture\UploadTicketCodec;
+use App\Support\Security\MassAssignmentProtectedKeys;
 use Illuminate\Support\Facades\Queue;
 
 /*
@@ -97,6 +98,69 @@ function sealAckToken(Take $take, User $user, ?int $expiresAt = null): string
     $this->actingAs($otherOwner)->postJson(takePath($project, $manual, $cut, $take, '/adopt'))->assertNotFound();
 });
 
+// ---------- adopt: 保護キー入口防御 (F-1-03) ----------
+
+test('adopt: 保護キー adopted_take_id 混入は 422 (副作用なし)', function (): void {
+    [, $owner, $project, $manual, $cut, $take] = takeManagementContext();
+
+    $this->actingAs($owner)
+        ->postJson(takePath($project, $manual, $cut, $take, '/adopt'), ['adopted_take_id' => 999])
+        ->assertStatus(422)
+        ->assertJsonValidationErrors('adopted_take_id');
+
+    // 保護キー混入は入口で拒否され、採用の副作用が一切起きないこと
+    expect($cut->fresh()?->adopted_take_id)->toBeNull();
+});
+
+// 保護キー集合の増加に自動追従する。現状の保護キーは全てトップレベルキーである前提
+// (将来ドット記法キーが増えたら Laravel の入力構造に合わせて payload を組む)。
+test('adopt: 全保護キー単体混入は 422', function (string $protectedKey): void {
+    [, $owner, $project, $manual, $cut, $take] = takeManagementContext();
+
+    $this->actingAs($owner)
+        ->postJson(takePath($project, $manual, $cut, $take, '/adopt'), [$protectedKey => 1])
+        ->assertStatus(422)
+        ->assertJsonValidationErrors($protectedKey);
+
+    expect($cut->fresh()?->adopted_take_id)->toBeNull();
+})->with(MassAssignmentProtectedKeys::all());
+
+test('adopt: 保護キー混入 + cross-cut は (422 でなく) 404', function (): void {
+    [, $owner, $project, $manual, $cut] = takeManagementContext();
+    $cutB = Cut::factory()->forManual($manual)->create();
+    $takeB = Take::factory()->forCut($cutB)->create();
+
+    // binding (scopeBindings) が FormRequest より前に閉じるため 404 が先に返る
+    $this->actingAs($owner)
+        ->postJson(takePath($project, $manual, $cut, $takeB, '/adopt'), ['adopted_take_id' => 1])
+        ->assertNotFound();
+    expect($cut->fresh()?->adopted_take_id)->toBeNull();
+});
+
+test('adopt: 保護キー混入 + cross-org は (422 でなく) 404', function (): void {
+    [, , $project, $manual, $cut, $take] = takeManagementContext();
+    [, $otherOwner] = createOrganizationWithOwner();
+
+    // EnsureProjectBelongsToCurrentOrganization がテナント境界 404 を FormRequest より前に返す
+    $this->actingAs($otherOwner)
+        ->postJson(takePath($project, $manual, $cut, $take, '/adopt'), ['adopted_take_id' => 1])
+        ->assertNotFound();
+});
+
+test('adopt: 保護キー混入 + 非 project member は 422 (FormRequest が Gate より先)', function (): void {
+    [$organization, , $project, $manual, $cut, $take] = takeManagementContext();
+    $outsider = attachOrganizationMember($organization);
+    $outsider->forceFill(['current_organization_id' => $organization->id])->save();
+
+    // 本アプリの正規順序 (404 → 422 FormRequest → 403 Gate)。保護キーは Gate より前の
+    // FormRequest で 422 になる (存在オラクルにならない範囲=同一組織内)。
+    $this->actingAs($outsider)
+        ->postJson(takePath($project, $manual, $cut, $take, '/adopt'), ['adopted_take_id' => 1])
+        ->assertStatus(422)
+        ->assertJsonValidationErrors('adopted_take_id');
+    expect($cut->fresh()?->adopted_take_id)->toBeNull();
+});
+
 // ---------- PATCH (comment / position) ----------
 
 test('PATCH: comment を更新できる (null 送信でクリア)', function (): void {
diff --git a/tests/Feature/Manual/SourceDocumentSummaryPropsTest.php b/tests/Feature/Manual/SourceDocumentSummaryPropsTest.php
new file mode 100644
index 00000000..89310ee1
--- /dev/null
+++ b/tests/Feature/Manual/SourceDocumentSummaryPropsTest.php
@@ -0,0 +1,131 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\SourceDocument;
+use App\Models\User;
+use App\Models\VideoManual;
+use Illuminate\Testing\TestResponse;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * F-1-01b: Manuals/Show の analysis.document (現在登録されている手順書の現況)。
+ * 「最新」の決定規則 (created_at max → tie-break id max) と PII 境界を固定する。
+ */
+
+/**
+ * @return array{Organization, User, Project, VideoManual}
+ */
+function summaryPropsContext(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'draft']);
+
+    return [$organization, $owner, $project, $manual];
+}
+
+function showManual(User $actor, Project $project, VideoManual $manual): TestResponse
+{
+    return test()->actingAs($actor)->get("/projects/{$project->id}/manuals/{$manual->id}");
+}
+
+test('show: created_at が異なるとき新しい日時の SOP が document に載る', function (): void {
+    [, $owner, $project, $manual] = summaryPropsContext();
+    SourceDocument::factory()->forManual($manual)->create([
+        'original_name' => 'old.pdf',
+        'created_at' => now()->subDay(),
+    ]);
+    $newer = SourceDocument::factory()->forManual($manual)->create([
+        'original_name' => 'new.pdf',
+        'created_at' => now(),
+    ]);
+
+    showManual($owner, $project, $manual)
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Manuals/Show')
+            ->where('analysis.document.name', 'new.pdf')
+            ->where('analysis.hasDocument', true)
+        );
+    expect($newer->original_name)->toBe('new.pdf');
+});
+
+test('show: created_at が同一のとき id が大きい SOP が document に載る', function (): void {
+    [, $owner, $project, $manual] = summaryPropsContext();
+    $sameTime = now();
+    SourceDocument::factory()->forManual($manual)->create([
+        'original_name' => 'first.pdf',
+        'created_at' => $sameTime,
+    ]);
+    SourceDocument::factory()->forManual($manual)->create([
+        'original_name' => 'second.pdf',
+        'created_at' => $sameTime,
+    ]);
+
+    showManual($owner, $project, $manual)
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('analysis.document.name', 'second.pdf')
+        );
+});
+
+test('show: SOP 添付済みなら document に name/sizeBytes/uploadedAt が載る', function (): void {
+    [, $owner, $project, $manual] = summaryPropsContext();
+    SourceDocument::factory()->forManual($manual)->create([
+        'original_name' => '作業手順.pdf',
+        'size_bytes' => 12345,
+    ]);
+
+    showManual($owner, $project, $manual)
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('analysis.document.name', '作業手順.pdf')
+            ->where('analysis.document.sizeBytes', 12345)
+            ->has('analysis.document.uploadedAt')
+        );
+});
+
+test('show: SOP 未添付なら document=null かつ hasDocument=false', function (): void {
+    [, $owner, $project, $manual] = summaryPropsContext();
+
+    showManual($owner, $project, $manual)
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('analysis.document', null)
+            ->where('analysis.hasDocument', false)
+        );
+});
+
+test('show: hasDocument === (document !== null) が常に成り立つ (添付あり)', function (): void {
+    [, $owner, $project, $manual] = summaryPropsContext();
+    SourceDocument::factory()->forManual($manual)->create();
+
+    $response = showManual($owner, $project, $manual);
+    $response->assertInertia(function (Assert $page): void {
+        $document = $page->toArray()['props']['analysis']['document'] ?? null;
+        $hasDocument = $page->toArray()['props']['analysis']['hasDocument'] ?? null;
+        expect($hasDocument)->toBe($document !== null);
+    });
+});
+
+test('show: 同一組織・別 manual の SOP は当該 manual の analysis.document に出ない', function (): void {
+    [$organization, $owner, $project, $manual] = summaryPropsContext();
+    $otherManual = VideoManual::factory()->forProject($project)->create(['status' => 'draft']);
+    SourceDocument::factory()->forManual($otherManual)->create(['original_name' => 'sentinel-other-manual.pdf']);
+
+    showManual($owner, $project, $manual)
+        ->assertInertia(fn (Assert $page) => $page->where('analysis.document', null));
+});
+
+test('show: 別組織の manual/SOP が現在の props に混ざらない (別組織 manual は 404)', function (): void {
+    [, , , $manual] = summaryPropsContext();
+    SourceDocument::factory()->forManual($manual)->create(['original_name' => 'sentinel-cross-org.pdf']);
+
+    [$otherOrg, $otherOwner] = createOrganizationWithOwner();
+    $otherProject = Project::factory()->forOrganization($otherOrg)->create();
+
+    // 別組織 owner が別組織の project 経由で当該 manual を直接 show → cross-org 404
+    test()->actingAs($otherOwner)
+        ->get("/projects/{$otherProject->id}/manuals/{$manual->id}")
+        ->assertNotFound();
+});
diff --git a/tests/js/pages/CaptureShow.test.ts b/tests/js/pages/CaptureShow.test.ts
index 8a44a86d..1024ae89 100644
--- a/tests/js/pages/CaptureShow.test.ts
+++ b/tests/js/pages/CaptureShow.test.ts
@@ -21,6 +21,9 @@ import {
 
 const {
     routerReloadMock,
+    routerVisitMock,
+    routerGetMock,
+    routerPostMock,
     enqueueMock,
     resumeMock,
     autoDownloadRunMock,
@@ -28,6 +31,11 @@ const {
     pendingSeed,
 } = vi.hoisted(() => ({
     routerReloadMock: vi.fn(),
+    // F-1-02: 撮影 PWA の /app 離脱防止。programmatic な明示遷移入口 (visit/get/post) を
+    // 記録し、通常フローでこれらが /app 外へ向けて呼ばれないことを固定する。
+    routerVisitMock: vi.fn(),
+    routerGetMock: vi.fn(),
+    routerPostMock: vi.fn(),
     enqueueMock: vi.fn(),
     resumeMock: vi.fn(),
     autoDownloadRunMock: vi.fn(),
@@ -48,7 +56,12 @@ vi.mock("@/lib/capture/panel-navigation", async (importOriginal) => ({
 
 vi.mock("@inertiajs/svelte", async (importOriginal) => ({
     ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
-    router: { reload: routerReloadMock },
+    router: {
+        reload: routerReloadMock,
+        visit: routerVisitMock,
+        get: routerGetMock,
+        post: routerPostMock,
+    },
 }));
 
 // jsdom に indexedDB が無いため in-memory PendingStore に差し替える
@@ -180,6 +193,9 @@ beforeEach(() => {
     routerReloadMock.mockImplementation((options: { onFinish?: () => void }) => {
         options.onFinish?.();
     });
+    routerVisitMock.mockReset();
+    routerGetMock.mockReset();
+    routerPostMock.mockReset();
     enqueueMock.mockReset();
     resumeMock.mockReset();
     resumeMock.mockResolvedValue([]);
@@ -650,6 +666,75 @@ describe("Capture/Show サムネイル反映の配線 (T183)", () => {
     });
 });
 
+/*
+ * 撮影 PWA の /app 離脱防止 (bug-hunt F-1-02 Phase A の回帰固定)。
+ *
+ * Phase A 調査 (devnotes 実装メモに記録) の結論: Capture/Show の**アプリ自コード**が
+ * 起こす programmatic navigation は `router.reload({only:['manual']})` (現 URL の部分リロード)
+ * のみで、/app 外への programmatic Inertia visit (router.visit/get/post) は存在しない。
+ * /app 外への遷移は利用者がクリックする明示リンク (Inertia <Link href="/projects/...">。
+ * PC 詳細への復路 = T155。docs/architecture.md §撮影 PWA の運用契約) だけである。
+ *
+ * よって恒久ガード (施策5 Phase B) は実装せず (再現できないものへ包括ガードを足さない。
+ * AGENTS.md 思考原則 2)、本ブロックが「通常フローで /app 外への programmatic visit が
+ * 発生しない」ことを回帰として固定する。
+ */
+describe("Capture/Show の /app 離脱防止 (F-1-02)", () => {
+    /**
+     * 判定器 (負のコントロールで検出力を裏取りする)。visit の destination が
+     * 現在オリジンの /app 配下でなければ「/app 外 programmatic visit」とみなす。
+     */
+    function isExternalProgrammaticDestination(url: unknown): boolean {
+        if (typeof url !== "string") return true; // 解析不能は外部側に倒す (許可リスト方式)
+        let parsed: URL;
+        try {
+            parsed = new URL(url, window.location.href);
+        } catch {
+            return true;
+        }
+        if (parsed.origin !== window.location.origin) return true;
+        return !(parsed.pathname === "/app" || parsed.pathname.startsWith("/app/"));
+    }
+
+    it("負のコントロール: 判定器は /app 外 destination を検出する", () => {
+        expect(isExternalProgrammaticDestination("/projects/1/manuals/5")).toBe(true);
+        expect(isExternalProgrammaticDestination("https://evil.example/app/x")).toBe(true);
+        expect(isExternalProgrammaticDestination("/app.evil/x")).toBe(true);
+        // 現 URL 配下は外部ではない (許可)
+        expect(isExternalProgrammaticDestination("/app/projects/1/manuals/5")).toBe(false);
+    });
+
+    it("通常フロー (キュー再開 → reload) で /app 外への programmatic visit が発生しない", async () => {
+        stubCameraSupported(false);
+        // 母集団非空を保証する: uploaded を返して現 URL への reload を確実に 1 回起こす
+        resumeMock.mockResolvedValue([{ status: "uploaded", clientTakeId: "q1" }]);
+
+        render(CaptureShow, { props: baseProps });
+        await fireEvent(window, new Event("online"));
+
+        // 母集団非空: 現 URL への部分リロードが最低 1 件観測される
+        await vi.waitFor(() => {
+            expect(routerReloadMock).toHaveBeenCalled();
+        });
+        // reload は url を持たない = 現 URL 固定 (only:['manual'] の部分リロード)
+        for (const call of routerReloadMock.mock.calls) {
+            const options = (call[0] ?? {}) as { url?: unknown };
+            expect(options.url).toBeUndefined();
+        }
+
+        // /app 外への programmatic visit (visit/get/post) は 1 件も発生しない
+        const programmaticCalls = [
+            ...routerVisitMock.mock.calls,
+            ...routerGetMock.mock.calls,
+            ...routerPostMock.mock.calls,
+        ];
+        const externalCalls = programmaticCalls.filter((call) =>
+            isExternalProgrammaticDestination(call[0]),
+        );
+        expect(externalCalls).toEqual([]);
+    });
+});
+
 /*
  * 横持ち全画面撮影の**ページ配線** (T186 施策 D)。
  *
diff --git a/tests/js/pages/ManualsCreate.test.ts b/tests/js/pages/ManualsCreate.test.ts
index 6abd03b1..32f61df1 100644
--- a/tests/js/pages/ManualsCreate.test.ts
+++ b/tests/js/pages/ManualsCreate.test.ts
@@ -166,6 +166,57 @@ describe("Manuals/Create", () => {
         expect(screen.queryByText("タイトルを入力してください")).toBeNull();
     });
 
+    it("ファイル選択後に選択したファイル名が表示される", async () => {
+        render(Create, { props: baseProps });
+
+        expect(screen.queryByTestId("manual-document-selected-name")).toBeNull();
+
+        const input = screen.getByTestId("manual-document-input");
+        const file = new File(["x"], "手順書.pdf", { type: "application/pdf" });
+        await fireEvent.change(input, { target: { files: [file] } });
+
+        const name = screen.getByTestId("manual-document-selected-name");
+        expect(name).toHaveTextContent("選択したファイル: 手順書.pdf");
+    });
+
+    it("未選択時はファイル名表示が出ない", () => {
+        render(Create, { props: baseProps });
+
+        expect(screen.queryByTestId("manual-document-selected-name")).toBeNull();
+    });
+
+    it("別ファイルを再選択すると表示名が置き換わる", async () => {
+        render(Create, { props: baseProps });
+
+        const input = screen.getByTestId("manual-document-input");
+        await fireEvent.change(input, {
+            target: { files: [new File(["a"], "first.pdf", { type: "application/pdf" })] },
+        });
+        expect(screen.getByTestId("manual-document-selected-name")).toHaveTextContent(
+            "選択したファイル: first.pdf",
+        );
+
+        await fireEvent.change(input, {
+            target: { files: [new File(["b"], "second.pdf", { type: "application/pdf" })] },
+        });
+        expect(screen.getByTestId("manual-document-selected-name")).toHaveTextContent(
+            "選択したファイル: second.pdf",
+        );
+    });
+
+    it("選択を解除 (files 空) すると表示が消える", async () => {
+        render(Create, { props: baseProps });
+
+        const input = screen.getByTestId("manual-document-input");
+        await fireEvent.change(input, {
+            target: { files: [new File(["a"], "first.pdf", { type: "application/pdf" })] },
+        });
+        expect(screen.getByTestId("manual-document-selected-name")).toBeInTheDocument();
+
+        await fireEvent.change(input, { target: { files: [] } });
+        expect(screen.queryByTestId("manual-document-selected-name")).toBeNull();
+    });
+
     it("タイトルエラーが無いとき oninput は clearErrors を呼ばない", async () => {
         setupForm();
         render(Create, { props: baseProps });
diff --git a/tests/js/pages/ManualsShow.test.ts b/tests/js/pages/ManualsShow.test.ts
index af71f611..e88af8f8 100644
--- a/tests/js/pages/ManualsShow.test.ts
+++ b/tests/js/pages/ManualsShow.test.ts
@@ -12,7 +12,7 @@ const baseProps = {
         category: { id: 2, name: "仕上げ" },
         created_at: "2026-07-10 12:00",
     },
-    analysis: { job: null, hasDocument: false, report: null },
+    analysis: { job: null, hasDocument: false, document: null, report: null },
     render: {
         job: null,
         previewJob: null,
@@ -146,6 +146,7 @@ describe("Manuals/Show", () => {
                         manual_status: "analyzing" as VideoManualStatus,
                     },
                     hasDocument: true,
+                    document: null,
                     report: null,
                 },
             },
@@ -182,6 +183,62 @@ describe("Manuals/Show", () => {
         expect(screen.getByTestId("scenario-counts")).toHaveTextContent("手順 2");
     });
 
+    // --- F-1-01b: 現在登録されている手順書 (SOP) の現況表示 ---
+
+    it("document 有り: 手順書パネルにファイル名・サイズ・日時が出る", () => {
+        render(Show, {
+            props: {
+                ...baseProps,
+                analysis: {
+                    ...baseProps.analysis,
+                    hasDocument: true,
+                    document: {
+                        name: "作業手順.pdf",
+                        sizeBytes: 1024 * 1024 * 2,
+                        uploadedAt: "2026-07-10T12:00:00+09:00",
+                    },
+                },
+            },
+        });
+
+        expect(screen.getByTestId("source-document-name")).toHaveTextContent("作業手順.pdf");
+        const current = screen.getByTestId("source-document-current");
+        expect(current).toHaveTextContent("2.0 MB");
+        expect(screen.queryByTestId("source-document-empty")).toBeNull();
+    });
+
+    it("document null: 「まだ手順書は登録されていません」を表示し差し替え UI と矛盾しない", () => {
+        render(Show, { props: baseProps });
+
+        expect(screen.getByTestId("source-document-empty")).toHaveTextContent(
+            "まだ手順書は登録されていません",
+        );
+        expect(screen.queryByTestId("source-document-name")).toBeNull();
+    });
+
+    it("filename に <script> を含む document でも HTML として解釈されずテキスト表示される", () => {
+        const evil = "<script>alert(1)</script>.pdf";
+        render(Show, {
+            props: {
+                ...baseProps,
+                analysis: {
+                    ...baseProps.analysis,
+                    hasDocument: true,
+                    document: {
+                        name: evil,
+                        sizeBytes: 100,
+                        uploadedAt: "2026-07-10T12:00:00+09:00",
+                    },
+                },
+            },
+        });
+
+        const nameEl = screen.getByTestId("source-document-name");
+        // Svelte の既定エスケープでテキストとして描画され、実 script 要素は生成されない
+        expect(nameEl.textContent).toBe(evil);
+        expect(nameEl.querySelector("script")).toBeNull();
+    });
+
     // --- T148 (bug-hunt F-1-01): render props の配線 ---
 
     it("D-9: render.coverage と render.playbackJob が RenderPanel へ渡る", () => {

```

## テスト結果
- composer test: 実装後、全 green を確認 (施策1 CaptureTakeManagementTest 49 / 施策3 SourceDocumentSummaryPropsTest 7 / 施策4 CaptureShow.test.ts 53)。
- Architecture gate CurrentRenderArtifactInventoryTest ケース8: 新 relation latestSourceDocument で ofMany/hasOne の file 単位代理件数が 2→3 になったため件数 pin と名前 pin を更新 (成果物側の増加は succeeded 条件件数 ===1 が捉え、latestSourceDocument は render_jobs 非参照・succeeded 条件なしのため成果物側ではないことを再審査済み)。
- composer phpstan: No errors。vendor/bin/pint --test: passed。
- pnpm lint / typecheck / build / typecheck:packages / build:packages / test (2365) / test:packages (106): 全 green。

## design system 参照 (DESIGN.md 抜粋 + 触れた atomic ディレクトリ)
本 diff が触れた resources/js は既存 token のみを使用:
- text-caption (12px, ラベル/補助情報/日時), text-text (本文/入力値), text-text-secondary (補助), bg-surface, border-border, text-primary。いずれも DESIGN.md 定義の token。hex 直書きは無い。
- アイコンは @lucide/svelte の FileText を使用 (SVG 直書きなし)。
- pages 層 (Manuals/Create.svelte, Manuals/Show.svelte) のみ変更。新規 atom/molecule/organism は作らず既存表示要素で構成 (オーバーエンジニアリング回避)。SourceDocumentUpload.svelte の props 契約は変更していない。
