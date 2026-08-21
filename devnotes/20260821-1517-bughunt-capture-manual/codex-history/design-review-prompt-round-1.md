【アプリの使命 (North Star) — AGENTS.md より】
AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。標準作業を起点に AI が教材設計し撮影を指示する。v1: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は Architecture/Feature テスト登録まで含めて実装済み)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. response()->json() の直書き(DTO / JsonResource / Inertia。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(窓口経由のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示)
9. Artifact ツールでの成果物公開を行わない

【セキュリティ不変条件(抜粋)】
- tenant キー不信 (ProhibitsProtectedKeys + MassAssignmentSafetyTest)
- 子は親に属する: nested route の不整合は認可より前に 404 (NestedRouteIdorDefenseTest)
- 層 2 (テナント境界=404) は層 3 (認可=403) より前。層 2 は binding の直後・FormRequest より前で閉じる

【思考原則】まず仮説を立てろ。ユーザー視点。データに真摯。先人の知恵(Laravel/Svelte 公式作法)。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。
【ツール使用制限】コマンド実行・ファイル書き込みは行わない。テキスト分析に集中。ファイル読み込みは許可。

---

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリ改善の詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia + TypeScript / PHPStan level 10 / Pest (RefreshDatabase + parallel) / DTO + JsonResource / Laratrust RBAC (Organization→Team→Project) / JS は Vitest。

【レビュー観点】
1. コードの正確性 (ロジック・エッジケース・null 安全)
2. 既存コードとの整合性 (命名・パターン・API)
3. PHPStan level 10 適合性 (型・generics・Assert)
4. テスト計画の網羅性 (各施策に Pest/Vitest、RefreshDatabase グローバル)
5. DTO/JsonResource パターン遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性 (TS 型・Resource・テスト)
9. セキュリティ (認可・入力バリデーション・OWASP・tenant 境界・実行順序)
10. DESIGN.md 準拠 (token 経由・hex 直書きを増やさない)
11. Atomic Design 準拠 (atoms/molecules/features の責務、Lucide アイコン)

【特に見てほしい論点】
- 施策1 の実行順序 (404 binding/mw → 422 FormRequest → 403 Gate) の主張は正しいか。FormRequest を adopt に足すことで cross-org/cross-cut の 404 が 422 に化ける危険はないか。テスト計画は順序を十分固定するか。
- 施策5 (F-1-02 Phase B) を「Phase A で経路が確認できた場合のみ実装する条件付き施策」とした判断は妥当か。許可リスト方式のガード + 明示遷移トークン (url+method+single-use) の設計に穴はないか。ハードビジットを保証対象外と明記する扱いは適切か。
- 施策3 の SOP メタ DTO / 最新 1 件の安定順序 (created_at DESC, id DESC) / 組織境界内 relation 取得 / PII 露出防止テストは十分か。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類、Critical/Warning には修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: bughunt-capture-manual

> **Codex 合議の実施状況**: 概念設計は gpt-5.6-terra と 2 ラウンドで APPROVED。詳細設計は
> gpt-5.6-sol と合議する。Codex (`scripts/codex`) は正常稼働している (概念設計フェーズで確認済み)。

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
| 3 | F-1-01b: show の登録済み SOP 現況表示 | `app/DataTransferObjects/Manual/SourceDocumentSummaryData.php` (新), `app/Models/VideoManual.php`, `app/Http/Controllers/Projects/VideoManualController.php`, `resources/js/types/manual.ts`, `resources/js/pages/Manuals/Show.svelte`, `resources/js/components/features/manual/SourceDocumentUpload.svelte` | Medium |
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

### 実行順序の確認 (最重要)
capture route group は `['require-active-subscription', 'project.in-current-org']` + `Route::scopeBindings()`。
- **テナント境界 404** は `project.in-current-org` middleware と scopeBindings による route-model binding で
  **FormRequest 検証より前**に閉じる (AGENTS.md セキュリティ不変条件 10「層 2 は binding の直後・
  FormRequest より前で閉じる」)。よって cross-cut / cross-org に保護キーを混ぜても **404** が先に返り、
  422 にはならない (既存 `StoreCaptureTakeRequest` も同 group で cross-org 404 を維持している = 実証済み)。
- FormRequest の `authorize()` は `true` を返す (認可は controller の `Gate::authorize('adopt')`)。よって
  順序は **404 (binding/mw) → 422 (FormRequest 保護キー) → 403 (Gate)**。これは本アプリの既存 capture
  書き込み経路と同一の正規順序であり、adopt をそこへ合流させるだけ (新しい順序を作らない)。

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
  `postJson(takePath(...,'/adopt'), ['adopted_take_id' => 999])->assertStatus(422)` かつ
  `$cut->fresh()->adopted_take_id` が本来の URL take id にならない (副作用が起きない) ことを確認。
- [ ] 追加 `adopt: 保護キー混入 + cross-cut/cross-org は (422 でなく) 404` —
  binding/mw が先に閉じることの回帰固定 (Codex Round2 [Warning] 実行順)。
  cross-cut: `takePath(project,manual,cutA,takeB,'/adopt')` に `['adopted_take_id'=>1]` → 404。
  cross-org: 別組織 owner で → 404。
- [ ] 追加 `adopt: 保護キー混入 + 非 project member は 422 (FormRequest が Gate より先)` —
  本アプリの正規順序 (FormRequest→Gate) を明示的に固定する。**期待値は実装の実順序に合わせる**
  (Codex Round2 の許容: 「既存アプリの正規順序に合わせて期待値を確定」)。まず実測し 422 を確認して固定。
- [ ] 既存 `adopt: ready テイクを採用でき adopted_take_id が反映される` (clean body) が引き続き 200 で green。
- [ ] `其他保護キー` (project_id / created_by / category_id) 混入も 422 になることを 1 ケースで代表確認。
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

<!-- file input の直後 -->
{#if selectedFileName !== null}
    <p class="mt-1 text-caption text-text-secondary" data-testid="manual-document-selected-name">
        選択したファイル: {selectedFileName}
    </p>
{/if}
```
- **文言**: 「選択したファイル」= まだ未送信であることが分かる表現 (Codex Round1 [Suggestion])。
- **DESIGN.md 準拠**: 既存 token (`text-caption` / `text-text-secondary`) を使い hex 直書きを増やさない。
  新規 atom/molecule は作らず既存の表示要素で済む (オーバーエンジニアリング回避)。

### PHPStan適合チェック
- N/A (フロントのみ)

### テスト計画 (Vitest `tests/js/pages/ManualsCreate.test.ts`)
- [ ] `ファイル選択後にファイル名が表示される` — `manual-document-input` に File を fireEvent.change で
  与え、`manual-document-selected-name` にファイル名が出ることを assert。
- [ ] `未選択時はファイル名表示が出ない` — 初期状態で `manual-document-selected-name` が存在しない。

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
- テストファイル: `tests/Feature/Manual/` に props 検証テスト、`tests/js/pages/ManualsShow.test.ts` に表示テスト

### DTO 契約 (Codex Round1/Round2 反映)
```php
// app/DataTransferObjects/Manual/SourceDocumentSummaryData.php (新規)
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Models\SourceDocument;

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
        return new self(
            name: $document->original_name,
            sizeBytes: $document->size_bytes,
            uploadedAt: $document->created_at->toIso8601String(),
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

### 「最新」の決定規則 (Codex Round2 [Warning])
同一時刻でも決定的になるよう **`created_at DESC, id DESC`** で 1 件に固定する。
```php
// app/Models/VideoManual.php
/**
 * 手順書パネルに出す「現在登録されている手順書」。追記型 immutable のため
 * 最新 (created_at DESC, id DESC で安定) の 1 件を指す。
 *
 * @return HasOne<SourceDocument, $this>
 */
public function latestSourceDocument(): HasOne
{
    return $this->hasOne(SourceDocument::class)->latest('created_at')->latest('id');
}
```
```php
// VideoManualController::show() の analysis props (組織境界内。$manual は既に解決済み)
'analysis' => [
    'job' => $analysisJob === null ? null : AnalysisJobData::fromJob($analysisJob, $manual)->toArray(),
    'hasDocument' => $manual->sourceDocuments()->exists(),
    'document' => ($doc = $manual->latestSourceDocument) === null
        ? null
        : SourceDocumentSummaryData::fromDocument($doc)->toArray(),
    'report' => $reports->build($manual)?->toArray(),
],
```
- `hasDocument` は互換のため残す (既存 UI 分岐が使用)。`document` は詳細表示専用。
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

### 表示 (Show.svelte 手順書パネル / SourceDocumentUpload.svelte)
- 手順書パネルに「現在登録されている手順書」ブロックを追加: ファイル名・サイズ (Svelte 側で
  KB/MB 整形)・アップロード日時 (Svelte 側でロケール整形)。`document === null` のときは
  「まだ手順書は登録されていません」を表示 (「差し替える」文言との矛盾を解消)。
- サイズ/日時整形は既存の整形 util があれば再利用、無ければ小さな純関数を helper に置く
  (表示文言は DTO に持たせない方針に沿う)。
- DESIGN.md: token 経由・Lucide アイコン (`FileText` 等) を使用、SVG 直書きしない。

### PHPStan適合チェック
- [x] DTO は `final readonly`、`toArray()` に shape 明示
- [x] `created_at` は `SourceDocument` の cast で `CarbonImmutable`/`Carbon` → `toIso8601String()` 可
      (null 非許容を確認。`@property` は `$size_bytes: int` / `original_name: string`)
- [x] `latestSourceDocument` relation の phpdoc generics (`HasOne<SourceDocument, $this>`)
- [x] `response()->json()` を使わず Inertia props に `toArray()` を載せるのみ

### テスト計画
**Pest (`tests/Feature/Manual/SourceDocumentSummaryPropsTest.php` 新規)** — テストファースト:
- [ ] `show: SOP 添付済みなら analysis.document に最新 1 件のメタが出る` — Factory で manual +
  複数 SourceDocument を作り、`created_at DESC, id DESC` の最新が name/sizeBytes/uploadedAt で載る。
- [ ] `show: 複数添付でも最新 1 件だけが公開される` (Codex Round2: 複数行を置く)。
- [ ] `show: SOP 未添付なら analysis.document は null かつ hasDocument=false`。
- [ ] `show: 他 manual / 他組織の SOP メタが混ざらない` — 別 manual に付けた SOP が当該 manual の
  props に現れない (relation 経由の境界固定。Codex Round1 [Warning] PII 露出)。
- [ ] Factory 経由でデータ生成 (手組み禁止)。`SourceDocumentFactory` が無ければ作成を施策に含める。

**Vitest (`tests/js/pages/ManualsShow.test.ts` 既存にケース追加)**:
- [ ] `document 有り: 手順書パネルにファイル名・サイズ・日時が出る`。
- [ ] `document null: 「まだ手順書は登録されていません」を表示し差し替え UI と矛盾しない`。

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
jsdom では実 Inertia のフルロードは再現できないため、**アプリ配線の回帰**を Vitest で固定する:
- [ ] `tests/js/pages/CaptureShow.test.ts`: 既存の `@inertiajs/svelte` モックを拡張し、
  `router.on` を spy 化。**通常フロー (カット選択・ファイル選択・アップロード)** で
  `router.visit`/`router.get` が呼ばれない (背景トラフィックは `router.reload({only:["manual"]})`
  = 現 URL 部分リロードのみ) ことを assert。
- [ ] **通常フロー**と**復帰性テスト**を分ける (Codex Round2 [Suggestion])。復帰性は施策5 or
  下記「ハードロードで失う状態」テストで扱う。

### 成果の記録
- アプリ起因経路が**在る**場合 → 施策5 Phase B へ (発火元根治 + 狭いガード)。
- アプリ起因経路が**無い**場合 → 「ハーネス多重実行が主因」と証拠付きで確定し、施策5 は実装せず、
  orchestrator へ「同一 run-id・同一 shard への bughunt-shard subagent 二重 fan-out を
  検出・失敗させる」ことを申し送る (won't-fix ではなく確定)。

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

### 是正の 2 本立て
1. **発火元の根治**: 特定した programmatic visit の発生源そのものを止める (握り潰さない)。
2. **回帰防止ガード** (狭く確定 — Codex Round2 [Warning] の 2 択のうち後者を採用):
   **Capture/Show が発行する visit を許可リスト化し、それ以外の `/app/` 外 programmatic visit を
   拒否する。** 許可リスト:
   - 現 URL への部分リロード (`reloadManual` = `router.reload({only:["manual"]})`)。
   - `/app/...` 内に留まる visit。
   - **明示遷移トークン**が立っている visit (下記)。
   サーバ応答後に発生する**ハードビジット (`window.location` / `409+X-Inertia-Location`) は
   ガードの保証対象外**と明記する (`before` で止められないため。「認証失効を判定して通す」といった
   実際には判定できない契約にしない — Codex Round2 [Warning])。

### 明示遷移トークン (Codex Round2 [Warning] 反映)
時間だけに依存しない。**遷移先 URL + HTTP method + 単一消費**を組み合わせる:
- リンク (`一覧へ戻る` / `マニュアル詳細へ`) の click ハンドラで、`{ url, method: "get" }` を
  1 回分だけセット。
- `before` ガードは、pending visit の url/method がトークンと一致するときだけ通し、**即座に破棄**
  (成否に関わらず single-use)。
- unmount 時にも必ず破棄。
- **代替の最小案**: PC 詳細リンク (`マニュアル詳細へ`) を撮影 PWA から**削除**できるなら、トークン機構
  自体が不要になり設計が小さくなる (Codex Round1/Round2 が推奨)。施策4 の結果と運用契約
  (`docs/architecture.md §撮影 PWA の運用契約`) を踏まえ、実装時に「削除」か「トークン経由で残す」を
  確定する。**既定方針は「PC 詳細リンクは残すが `一覧へ戻る` と同様に明示操作なので許可リスト対象、
  背景 visit だけを拒否」**とし、トークンは programmatic と user-click を区別する必要が出た場合のみ導入。

### helper (`resources/js/lib/capture/navigation-guard.ts` 新規, 条件付き)
```ts
/**
 * 撮影 PWA (Capture/Show) マウント中だけ、撮影画面が自ら起こし得ない
 * /app/ 外への programmatic Inertia visit を拒否する狭いガード。
 * ハードビジット (window.location / 409+X-Inertia-Location) は before で止められないため
 * 保証対象外 (docblock に明記)。判定のみを持ち、page が register/解除を配線する。
 */
export interface NavigationGuardOptions {
    /** pending visit の URL パスが撮影 PWA 内 (/app/) か */
    isInAppUrl: (url: string) => boolean;
    /** 明示操作トークン (url+method 一致で single-use) — 使う場合のみ */
    consumeExplicitIntent?: (url: string, method: string) => boolean;
}
// register(): router.on("before", handler) を張り、unsubscribe を返す。
```
- page 側は `onMount` で register、cleanup で解除 + トークン破棄。

### ハードロードで失う状態の保証 (Codex Round2 [Warning]。状態ごとに分ける)
| 状態 | 保証方針 |
|------|----------|
| キュー保存**前**の `<input type=file>` 選択 | ブラウザ安全境界上、自動復元不可 → **再選択を明確に案内**する (自動復元と混同しない) |
| キュー保存**後**のアップロード | IndexedDB から `resumeUploads` で再開 (既存 onMount 配線) |
| サーバ保存済み・未採用 take | 詳細 GET 再取得で一覧に再出現し採用操作へ戻せる (既存) |
| UI のみ (`selectedCutId` / 全画面ラッチ) | 安全な初期状態へ戻す (既存の初期化で足りる) |

「復帰導線」と「元状態の自動復元」を同義にしない (文言・テストで区別)。

### テスト計画
**Vitest (`tests/js/lib/capture/navigation-guard.test.ts` 新規)** — テストファースト:
- [ ] `/app/ 内 visit は通す`。
- [ ] `/app/ 外への programmatic visit (トークン無し) はキャンセル (event.preventDefault 相当)`。
- [ ] `明示トークン一致の /app/ 外 visit は 1 回だけ通し、2 回目はキャンセル (single-use)`。
- [ ] `method/url がトークンと不一致なら通さない`。
- [ ] `reloadManual 相当 (現 URL 部分リロード) を巻き込まない`。

**Vitest (`tests/js/pages/CaptureShow.test.ts`)**:
- [ ] `マウントで before ガードが register され、unmount で解除される`。
- [ ] 明示リンク押下由来 / 戻る進む / offline→online 復帰で `reloadManual`・正規遷移が阻害されない
  (Codex Round1 [Warning] 回帰)。

### リスク
- ガードが正常フロー (reloadManual / 明示リンク / 認証失効の正規離脱) を巻き込む後退。
  → 許可リスト方式 + 上記回帰テストで固定。認証失効等は `/app/` 外への正規遷移として通す
  (ガードで覆い隠さない)。
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

---

## 関連する現行コード (抜粋)

### CaptureTakeController::adopt (現行)
```php
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

        $adoptedCut = $takes->adopt($project, $manual, $cut, $take);
        $adoptedCut->load('takes'); // fromCut() の relationLoaded() 検査を満たすため必須

        return CaptureCutResource::make(CaptureCutData::fromCut($adoptedCut));
```
### ProhibitsProtectedKeys trait
```php
 *
 * 正当に受け取る必要があるキー (多態スコープ等) が生じた場合は、テンプレからの
 * 逸脱として docs/template-divergence.md に記録した上で except を使う。
 */
trait ProhibitsProtectedKeys
{
    /**
     * @param  list<string>  $except
     * @return array<string, list<string>>
     */
    protected function protectedKeyMissingRules(array $except = []): array
    {
        $rules = [];
        foreach (MassAssignmentProtectedKeys::all() as $key) {
            if (! in_array($key, $except, true)) {
                $rules[$key] = ['missing'];
            }
        }

        return $rules;
    }
}
```
### capture route group (middleware / scopeBindings)
```php
    Route::middleware(['require-active-subscription', 'project.in-current-org'])
        ->prefix('app')->as('capture.')->group(function (): void {
            // PWA エントリ (manifest start_url)。current org の先頭 project へ redirect
            Route::get('/', [CaptureManualController::class, 'home'])->name('home');
            // CSRF cookie 再発行 (419 リトライ用の軽量 GET。web group を通るだけで
            // XSRF-TOKEN cookie が更新される。204 = 仕様固定 endpoint、body なし)
            Route::get('/csrf-cookie', fn (): Response => response()->noContent())
                ->name('csrf-cookie');
            /*
            | 撮影 PWA のアカウント確認画面 (doc/05 §5.1 / §5.2)。表示名・ログイン ID
            | (= メールアドレス)・所属組織を省略なく読み、ログアウトするためだけの面。
            | **route parameter を持たない** — project のデータを 1 つも表示しないため、
            | project 配下 (/app/projects/{project}/account) には置かない
            | (親を持たせると nested route IDOR 目録と scopeBindings を負うだけで意味も歪む)。
            | 復路は capture.home 1 本 (start_url と同じ)。return_to / history.back() は使わない。
            | 変更操作は一切持たない (プロフィール変更・パスワード・2FA・退会は /settings の責務)。
            */
            Route::get('/account', CaptureAccountController::class)->name('account');
            Route::get('/projects/{project}/manuals', [CaptureManualController::class, 'index'])
                ->name('manuals.index');
            Route::scopeBindings()->group(function (): void {
                Route::get('/projects/{project}/manuals/{manual}', [CaptureManualController::class, 'show'])
                    ->name('manuals.show');
                Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/upload-url', [TakeUploadUrlController::class, 'store'])
                    ->name('takes.upload-url');
                Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes', [CaptureTakeController::class, 'store'])
                    ->name('takes.store');
                Route::patch('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}', [CaptureTakeController::class, 'update'])
                    ->name('takes.update');
                Route::delete('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}', [CaptureTakeController::class, 'destroy'])
                    ->name('takes.destroy');
                Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/adopt', [CaptureTakeController::class, 'adopt'])
                    ->name('takes.adopt');
                Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/downloaded', [CaptureTakeController::class, 'markDownloaded'])
                    ->name('takes.downloaded');
                Route::get('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback', [CaptureTakeController::class, 'playback'])
                    ->name('takes.playback');
                Route::get('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/thumbnail', [CaptureTakeController::class, 'thumbnail'])
                    ->name('takes.thumbnail');
```
### VideoManualController::show analysis props (現行)
```php
        return Inertia::render('Manuals/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'manual' => [
                'id' => $manual->id,
                'title' => $manual->title,
                'status' => $manual->status->value,
                'category' => $category === null
                    ? null
                    : ['id' => $category->id, 'name' => $category->name],
                'created_at' => $manual->created_at?->format('Y-m-d H:i') ?? '',
            ],
            // AI 解析パネル (最新 job + 手順書有無)。AnalysisJobData::toArray() と対
            'analysis' => [
                'job' => $analysisJob === null
                    ? null
                    : AnalysisJobData::fromJob($analysisJob, $manual)->toArray(),
                'hasDocument' => $manual->sourceDocuments()->exists(),
                // 生成結果の確認 (LLM の所見 + 現在の cuts への決定的検査)。null = 出す材料が無い。
                // 描画時点のスナップショットであり常に最新ではない (render.coverage と同じ性質)。
                'report' => $reports->build($manual)?->toArray(),
            ],
            // レンダパネル (最新 render job / 最新 preview job / 再生可能 preview / 完成動画)。RenderProps と対
            'render' => [
                'job' => $renderJob === null
                    ? null
                    : RenderJobData::fromJob($renderJob, $manual)->toArray(),
                'previewJob' => $previewJob === null
                    ? null
                    : RenderJobData::fromJob($previewJob, $manual)->toArray(),
                'playbackJob' => $playbackJob === null
                    ? null
                    : RenderJobData::fromJob($playbackJob, $manual)->toArray(),
                // 完成動画 (再生 + DL の唯一の出し分け根拠)。null = 出さない
                'finishedJob' => $finishedJob === null
                    ? null
                    : RenderJobData::fromJob($finishedJob, $manual)->toArray(),
                // 「使用できる採用テイクがない」カットの充足状況。render の 422 と**同じ述語**から出す
                // = 判断基準を 1 箇所に置く (bug-hunt F-1-01)。描画時点のスナップショットであり
                // 常に最新ではない (押下は止めないので詰みにはならない)。
                'coverage' => AdoptedReadyTakeCoverage::for($manual)->toProps(),
            ],
            'canManage' => $user->can('update', $manual),
            'categories' => $this->categoryOptions($project), // 複製ダイアログのカテゴリ選択肢 (既存 helper 再利用)
            // SOP アップロードの受理形式 (画像・スキャン SOP の OCR 対応)。
            // AcceptedSourceDocumentTypes が単一の情報源 (フラグに連動)
            'sourceDocumentAccept' => AcceptedSourceDocumentTypes::acceptAttribute(),
            'imageSourceDocumentsEnabled' => AcceptedSourceDocumentTypes::imagesEnabled(),
        ]);
    }
```
### SourceDocument model (fields)
```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SourceDocument (VideoManual 配下の SOP ファイル)。Tier B: schema 先取り。
 * route / Controller / UI は SOP アップロードフェーズで張る (それまで外部到達不可)。
 *
 * - video_manual_id は所有権キーのため $fillable 外 (relation 経由で代入)
 *
 * @property int $id
 * @property int $video_manual_id
 * @property string $file_path
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 * @property array<array-key, mixed>|null $extracted_json
 */
class SourceDocument extends Model
{
    /** @use HasFactory<SourceDocumentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'file_path',
        'original_name',
        'mime',
        'size_bytes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extracted_json' => 'array',
```
### 既存 adopt テスト (抜粋)
```php
// ---------- adopt ----------

test('adopt: ready テイクを採用でき adopted_take_id が反映される', function (): void {
    [, $owner, $project, $manual, $cut, $take] = takeManagementContext();

    $response = $this->actingAs($owner)->postJson(takePath($project, $manual, $cut, $take, '/adopt'));

    $response->assertOk();
    $response->assertJsonPath('adopted_take_id', $take->id);
    $response->assertJsonPath('id', $cut->id);
    expect($cut->fresh()?->adopted_take_id)->toBe($take->id);
});

test('adopt: cross-cut (cut B の take を cut A の URL で) は 404', function (): void {
    [, $owner, $project, $manual, $cut] = takeManagementContext();
    $cutB = Cut::factory()->forManual($manual)->create();
    $takeB = Take::factory()->forCut($cutB)->create();

    $this->actingAs($owner)
        ->postJson(takePath($project, $manual, $cut, $takeB, '/adopt'))
        ->assertNotFound();
    expect($cut->fresh()?->adopted_take_id)->toBeNull();
});

test('adopt: ready 前 (uploading/processing/failed) のテイクは 422', function (string $status): void {
    [, $owner, $project, $manual, $cut] = takeManagementContext();
    $take = Take::factory()->forCut($cut)->create(['status' => $status]);

    $this->actingAs($owner)->postJson(takePath($project, $manual, $cut, $take, '/adopt'))->assertStatus(422);
})->with(['uploading', 'processing', 'failed']);

test('adopt: manual が analyzing / rendering 中は 409 scenario_conflict', function (string $status): void {
    [, $owner, $project, $manual, $cut, $take] = takeManagementContext($status);

    $response = $this->actingAs($owner)->postJson(takePath($project, $manual, $cut, $take, '/adopt'));

    $response->assertStatus(409);
    $response->assertJsonPath('code', 'scenario_conflict');
})->with(['analyzing', 'rendering']);

test('adopt: cross-org は 404', function (): void {
    [, , $project, $manual, $cut, $take] = takeManagementContext();
    [, $otherOwner] = createOrganizationWithOwner();

    $this->actingAs($otherOwner)->postJson(takePath($project, $manual, $cut, $take, '/adopt'))->assertNotFound();
```
