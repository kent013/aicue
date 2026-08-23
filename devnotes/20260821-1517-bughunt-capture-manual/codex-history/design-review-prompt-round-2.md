## Round 2: 詳細設計の改訂を報告します

Round 1 の指摘 (施策1 の Warning、施策3/4/5 の REQUEST_CHANGES) への対応マトリクスと改訂後の詳細設計を提示します。全体判定と残る指摘を示してください。

### 対応の要点
- 施策1: 実行順序の根拠を bootstrap/app.php の実効 priority list に置換 (SubstituteBindings → EnsureProjectBelongsToRouteOrganization → HandleInertiaRequests → … → RequireActiveSubscription → EnsureAccountNotPendingDeletion)、TenantBoundaryOrderingTest 等を正本として引用・adopt route の母集団包含を実装時確認。MassAssignmentSafetyTest は Models の $fillable を走査する出口防御で FormRequest を列挙しない=新 Request の inventory 登録不要と事実確認。テストは assertJsonValidationErrors + adopted_take_id null + MassAssignmentProtectedKeys::all() の dataset 化。
- 施策3: relation を ofMany(created_at max, id max) に変更。hasDocument と document を同一 $document スナップショットから導出。created_at は Assert::notNull で non-null 確定後に toIso8601String。安定順序テストを 2 ケース (created_at 差 / 同時刻 id 大) に分割。PII テストを境界分割 (別 manual sentinel 非露出 / 別組織非混入 / 別組織 show 404 / <script> filename のテキスト表示)。表示は Show.svelte 側に置き SourceDocumentUpload の props は不変。hasDocument===(document!==null) 不変条件テスト。
- 施策4: 非再現でハーネス主因を断定しない 3 分岐に変更。判定は before event の url/method (個別メソッド数でない)。409 は X-Inertia-Location 実値を必須証拠。証拠の正本はネットワーク最終 response。
- 施策5: まず「発火元除去でき別経路の証拠が無ければ包括ガードを実装しない」選択を明記 (過大回避)。トークンは visitExplicitly の同期 wrapper (try/finally で single-use、stale 除去)。visit.url は string|URL の公式型を使い string へ狭めない。/app/ 判定は URL 正規化 + origin 一致 + 正規化 pathname (負例 https://evil/app, //evil/app, /app.evil を弾く) + method 小文字化。before は global listener でセキュリティ境界でないと明記、保証しないもの (ハードビジット/popstate) と認証失効の扱い (判定不能を一般例外にしない) を限定。失う状態表の各行に対応テストを付与。

---

# 対応マトリクス: design-review Round 1

## 施策1 (APPROVE, Warning あり)

### [Warning] 実行順序の根拠を route group 記述順でなく bootstrap/app.php の実効 priority に
- 判断: 対応する
- 根拠: 正しい。SortedMiddleware は priority list で相対順序を強制する。宣言順は証拠にならない。
- 対応: 施策1 に priority list の実効順 (SubstituteBindings → EnsureProjectBelongsToRouteOrganization →
  HandleInertiaRequests → … → RequireActiveSubscription → EnsureAccountNotPendingDeletion) を明記。
  テナント境界 404 が subscription/凍結の 302 短絡より前であることを `TenantBoundaryOrderingTest` /
  `ProjectRouteCurrentOrgGuardTest` が固定している旨を引用。adopt route が母集団に含まれることを実装時に確認。

### [Warning] cross-org + subscription 不成立 + 保護キーでも 404 のテスト
- 判断: 対応する
- 対応: テスト計画に「cross-org (別組織 owner) で保護キー混入 → 404」を追加済み。subscription 短絡順は
  `TenantBoundaryOrderingTest` が固定しているので新規 Architecture テストは足さず、その名を明記。

### [Warning] MassAssignmentSafetyTest が AdoptCaptureTakeRequest を自動検出するか / inventory 登録要否
- 判断: 対応する (事実確認済み)
- 根拠: `MassAssignmentSafetyTest` は app/Models の $fillable を走査する**出口防御**であり、FormRequest を
  列挙しない。FormRequest 側の入口防御を強制する deny-by-default な inventory は無い。
- 対応: 「新 FormRequest の inventory 登録は不要。入口防御は新 Feature テストで実証」と設計に明記。

### [Suggestion] assertJsonValidationErrors + adopted_take_id null / dataset 化
- 判断: 対応する
- 対応: 保護キーテストを `assertJsonValidationErrors('adopted_take_id')` + `$cut->fresh()->adopted_take_id`
  不変で固定。`MassAssignmentProtectedKeys::all()` の dataset 化で保護キー集合の増加に追従。

## 施策2 (APPROVE)
### [Suggestion] 空選択で表示消去 / 再選択で置換 / aria-live
- 判断: 対応する (安価)
- 対応: 「空選択で消える」「再選択で置換」テストを追加。`aria-live="polite"` を付与。

## 施策3 (REQUEST_CHANGES)

### [Warning] latest relation を ofMany(created_at, id) に
- 判断: 対応する
- 対応: `hasOne(SourceDocument::class)->ofMany(['created_at'=>'max','id'=>'max'])` に変更。

### [Warning] hasDocument と document を同一スナップショットから生成 (食い違い防止)
- 判断: 対応する
- 対応: `$document = $manual->latestSourceDocument;` を 1 回解決し、`hasDocument => $document !== null` と
  `document => ...` を同じ結果から作る。

### [Warning] created_at の null 安全性
- 判断: 対応する
- 対応: DTO 生成時に `Assert::notNull($document->created_at)` で non-null を確定してから toIso8601String()。
  `?-> ?? ''` の握り潰しはしない。UI 契約は non-null (日時省略は許容しない)。

### [Warning] 安定順序テストは created_at 異なる / 同一 (id 大が勝つ) の 2 ケースに分ける
- 判断: 対応する
- 対応: テスト計画を 2 ケースに分割。

### [Warning] PII 露出テストの境界分割
- 判断: 対応する
- 対応: (1) 同一組織・別 manual の sentinel が出ない (2) 別組織の manual/SOP が混ざらない
  (3) 別組織 manual の直接 show は 404 (4) `<script>` を含む filename が Svelte でテキスト表示 (HTML 非解釈)。

### [Warning] SourceDocumentUpload の props 契約と全呼び出し元
- 判断: 対応する (設計変更で回避)
- 対応: **表示は Show.svelte の手順書パネル側に置き、`SourceDocumentUpload.svelte` の props は変えない**
  ことを明記。これにより component 契約の波及が消える。

### [Suggestion] hasDocument === (document !== null) を不変条件テスト
- 判断: 対応する
- 対応: Feature テストに 1 ケース追加。

## 施策4 (REQUEST_CHANGES)

### [Warning] 非再現からハーネス主因を確定しない (3 分岐)
- 判断: 対応する
- 対応: 結論を 3 分岐に。(a) アプリ経路観測→Phase B 可否判断 (b) 二重 fan-out を実観測し時系列も一致
  →ハーネス確定 (c) どちらも観測できず→「調査範囲では再現せず・原因未確定」(ハーネス断定しない・Phase B 実装しない)。

### [Warning] router.visit/get 個別メソッド数でなく before event の url/method を判定
- 判断: 対応する
- 対応: 「通常操作から許可されない destination が発生しない」を before event の url/method で判定する
  Vitest 回帰に変更。Playwright は document/XHR + response ヘッダの実観測、と役割分離。

### [Warning] 409 は X-Inertia-Location 実値を必須証拠に
- 判断: 対応する (既に記載、明確化)
- 対応: asset mismatch と Inertia::location() の両 409 を X-Inertia-Location 実値で区別する旨を強調。

### [Suggestion] 最終 response を証拠の正本に (controller 本体でなくネットワーク)
- 判断: 対応する
- 対応: 認証/subscription/Inertia middleware が controller 前後で redirect/409 を生成し得る点を明記し、
  ネットワーク上の最終 response を正本にする。

## 施策5 (REQUEST_CHANGES)

### [Warning] click handler 先行トークンは stale 化する → 同期 visit wrapper に閉じる
- 判断: 対応する
- 対応: `visitExplicitly(url, method)` に token 設定 + `router.visit` + `finally` で破棄を同期 1 操作に集約。
  「一致するまで残す」を撤回し single-use を try/finally で保証。`<Link>` の click 順に依存しない。

### [Warning] visit.url は string|URL。公式 event 型を使う
- 判断: 対応する
- 対応: `event.detail.visit` の公式型をそのまま使い string へ狭めない。helper は URL 正規化を内部で行う。

### [Warning] /app/ 判定は prefix でなく URL 正規化 + origin + pathname + method
- 判断: 対応する
- 対応: `new URL(value, window.location.href)` で正規化、`origin === location.origin` かつ
  `pathname === '/app' || pathname.startsWith('/app/')`、method 小文字化、トークンは canonical 完全一致。
  負例 (`https://evil/app/...`, `//evil/app/...`, `/app.evil/...`) をテストに含める。

### [Warning] before は global listener。セキュリティ境界でないと明記 + Capture/Show の visit 源を inventory
- 判断: 対応する
- 対応: 「本ガードは UX 継続性の回帰防止であってセキュリティ境界ではない」と明記。Capture/Show 内の
  visit 発生源 (明示リンク 2 本 / ログアウト / reloadManual) を列挙。

### [Warning] 「認証失効は通す」と「トークン無し /app/ 外は拒否」の矛盾
- 判断: 対応する
- 対応: 保証を限定。(1) server response 後のハードビジット (409+X-Inertia-Location 等) は対象外で妨げない
  (2) client-side programmatic な認証離脱を許すなら明示 intent として列挙 (3) 判定不能な認証失効を
  一般例外として許可しない。矛盾する「認証失効を判定して通す」記述を削除。

### [Warning] 失う状態表に対応するテストを各行に
- 判断: 対応する
- 対応: (a) pre-queue file=再選択案内の実装 + 表示テスト (b) queued=resumeUploads 呼出 + 二重 enqueue しない
  (c) 未採用 take=再 GET で再出現 Feature (d) UI-only=再 mount で安全初期値 component テスト。

### [Suggestion] 発火元除去できて他経路の証拠が無ければ global guard を実装しない選択も残す
- 判断: 対応する
- 根拠: 思考原則 2 (今必要なものだけ)。過大回避。
- 対応: 施策5 に「発火元を除去でき同種別経路の証拠が無ければ、包括ガードは実装しない」判断を明記。
  ガードは「複数経路・再発リスクが確認された場合の回帰防止」に限る。

---

## 改訂後の詳細設計 (全文)

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

### 実行順序の確認 (最重要 — Codex Round1 [Warning] 反映)
**根拠は route group の記述順ではなく `bootstrap/app.php` の実効 priority list** (`SortedMiddleware` は
priority に載る middleware 間の相対順序のみ強制する)。実効順:
`SubstituteBindings` → `EnsureProjectBelongsToRouteOrganization` (= `project.in-route-org`) →
`HandleInertiaRequests` → … → `RequireActiveSubscription` → `EnsureAccountNotPendingDeletion`。
- **テナント境界 404** は `SubstituteBindings` (不在 id / scopeBindings の親子不整合 → 404) と
  `EnsureProjectBelongsToRouteOrganization` (cross-org → 404) で、**FormRequest 検証より前**に閉じる
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
- [x] `created_at` は `SourceDocument` の cast で `CarbonImmutable`/`Carbon` → `toIso8601String()` 可
      (null 非許容を確認。`@property` は `$size_bytes: int` / `original_name: string`)
- [x] `latestSourceDocument` relation の phpdoc generics (`HasOne<SourceDocument, $this>`)
- [x] `response()->json()` を使わず Inertia props に `toArray()` を載せるのみ

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
- [ ] `tests/js/pages/CaptureShow.test.ts`: 既存の `@inertiajs/svelte` モックを拡張し `router.on` を
  spy 化。**通常フロー (カット選択・ファイル選択・アップロード)** で before に現れる visit が
  「現 URL への部分リロード (`router.reload({only:["manual"]})`) 以外の destination を含まない」ことを assert。
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
- **最小案の優先検討**: PC 詳細リンク (`マニュアル詳細へ`) を撮影 PWA から**削除**できれば、明示トークンは
  不要になり設計が最小化する (Codex 推奨)。運用契約 (`docs/architecture.md §撮影 PWA の運用契約`) と
  Phase A の結果を踏まえ、実装時に「リンク削除」/「wrapper 経由で残す」を確定する。

### helper (`resources/js/lib/capture/navigation-guard.ts` 新規, 条件付き)
URL 判定は**文字列 prefix でなく `URL` 正規化**で行う (Codex Round1 [Warning])。visit の url 型は
`string | URL` (Inertia 公式) なので**独自に string へ狭めず、導入済みバージョンの event 型をそのまま使う**。
```ts
/**
 * 撮影 PWA (Capture/Show) マウント中だけ、撮影画面が自ら起こし得ない
 * /app/ 外への programmatic Inertia visit を拒否する狭い「UX 回帰防止」ガード。
 * これはセキュリティ境界ではない (テナント/認可は middleware + Gate が担う)。
 * ハードビジット (window.location / 409+X-Inertia-Location / popstace) は before で
 * 止められないため保証対象外 (docblock に明記)。
 */

/** /app/ 内 URL か。origin 一致 + 正規化 pathname で判定 (prefix 文字列一致にしない) */
export function isInAppUrl(value: string | URL): boolean {
    const url = new URL(value, window.location.href);
    if (url.origin !== window.location.origin) return false; // //evil/app, https://evil/app を弾く
    return url.pathname === "/app" || url.pathname.startsWith("/app/"); // /app.evil を弾く
}

/** url+method の canonical キー (origin + pathname + search[+hash])。method は小文字化 */
export function canonicalize(value: string | URL, method: string): string { /* ... */ return ""; }

// register(guard): router.on("before", handler) を張り、unsubscribe を返す。
// handler は event.detail.visit の公式型 (url: string|URL, method) をそのまま読む。
```
- **負例テスト**: `https://evil.example/app/...` / `//evil.example/app/...` / `/app.evil/...` を
  in-app と誤判定しないこと。
- page 側は `onMount` で register、cleanup で解除 + `pendingIntent` 破棄。

### ハードロードで失う状態の保証 (Codex Round2 [Warning]。状態ごとに分ける)
| 状態 | 保証方針 |
|------|----------|
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
