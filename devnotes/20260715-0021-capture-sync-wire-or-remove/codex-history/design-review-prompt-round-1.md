【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。競合(tebiki)と異なり標準作業を起点に AI が教材設計し撮影を指示する。v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告 2. PHPStan エラーの widen・baseline 化 3. dev DB への破壊操作
4. `response()->json()` 直書き 5. LLM 呼び出しの Prism 直呼び 6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()` 8. 必須条件未充足でボタンを disabled にする UI

【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】コマンド実行・ファイル書き込みは一切行わず、提供テキストの分析に集中。ファイル読み込みは許可。

---

【あなたの役割】
経験豊富な Web アプリケーションアーキテクトとして、Laravel + Svelte アプリの詳細設計をレビューする。

【前提環境】
PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / DTO + JsonResource / Laratrust RBAC。

【この設計の性質】
これは新機能ではなく「フロント未配線のデッドコード sync endpoint(照合専用・書き込みゼロ)を廃止する削除リファクタ」の詳細設計です。概念設計は 3 ラウンドで APPROVED 済み((B)廃止の妥当性・失敗モード反証・代替テスト担保・参照監査手順は概念設計で確定)。本レビューは詳細設計(施策一覧・変更ファイル・波及変更・テスト計画・実装モード)の実装レベルの妥当性に集中してください。

【レビュー観点】
1. コードの正確性(削除漏れ・dangling 参照・削除順序)
2. 既存コードとの整合性(命名・パターン)
3. PHPStan level 10 適合性(削除で未解決シンボルが残らないか)
4. テスト計画の網羅性(削除で失われる不変条件の補償、既存テストの green 維持、RefreshDatabase グローバル)
5. DTO/JsonResource パターン遵守(共有 DTO の巻き込み削除がないか)
6. Inertia Props vs API Response の使い分け(reconcile の manual スナップショットが Inertia reload と重複という論拠の妥当性)
7. 副作用・後退リスク(inventory / operations.md / doc / route の同時性で drift 0 を維持できるか)
8. 波及変更の網羅性(TS 型・Architecture inventory・bug-hunt operations・canonical doc が変更対象に入っているか)
9. セキュリティ(read-only route 削除で認可面が縮小するのみか。IDOR inventory の網羅性が維持されるか)
10. DESIGN.md 準拠(UI 変更なし → 対象外の確認)
11. Atomic Design 準拠(UI 変更なし → 対象外の確認)

特に検証してほしい点:
- 削除対象の閉じた参照集合(概念設計で列挙)に漏れ・巻き込みが無いか。共有 DTO(CaptureManualDetailData 等)を誤って削除していないか。
- `ProhibitsProtectedKeys` 系 Architecture テスト(MassAssignmentStrictModeTest / ValidationAttributeCoverageTest / FormRequestProhibitedKeyTest)が SyncCaptureTakesRequest 削除で fail しないという主張(動的走査ゆえハードコード期待なし)の妥当性。
- route / inventory / operations.md を「同時に」消すことで inventory-check の drift 0 を維持する、という同時性の要件が施策に正しく落ちているか。
- 実装モード standalone の妥当性。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類、Critical/Warning には修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: capture-sync-wire-or-remove

## 使命・制約(絶対遵守)

### アプリの使命(North Star)
**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、
そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化された
マニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。競合(tebiki)と異なり標準作業を起点に AI が
教材設計し撮影を指示する。熟練者の暗黙知を形式知へ変換する装置(SECI)。
v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項(AGENTS.md)
1. テストなしの実装完了報告 2. PHPStan エラーの widen・baseline 化 3. dev DB への破壊操作
4. `response()->json()` 直書き 5. LLM 呼び出しの Prism 直呼び 6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()` 8. 必須条件未充足でボタンを disabled にする UI

### コーディングルール
- **PHPStan level 10** 必須(`composer phpstan`) / **Pest**(`composer test`)
- **RefreshDatabase** + `--parallel`(`tests/Pest.php` グローバル適用、個別 `DatabaseTransactions` 禁止)
- テストデータは Factory 生成 / DTO + JsonResource / アーリーリターン
- フォーマット: `composer fix`(Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス
`devnotes/20260715-0021-capture-sync-wire-or-remove/conceptual-design.md`(APPROVED Round 3)

**判断: (B) 廃止**。`capture.manuals.sync` → `CaptureSyncController` → `CaptureSyncService::reconcile`
(読み取り専用の照合)はフロント未配線のデッドコードで、即時アップロード方式(D9)+ client_take_id 冪等 +
IndexedDB PendingStore が「新規のみ送信・二重防止」を既に代替している。route/Controller/Service と専用
DTO/Request/Resource/テスト/TS 型を削除し、inventory・operations.md・canonical spec(doc/08・doc/10)を整合させる。
(A) 配線は差分検出 UI の新設で過剰実装(v1 スコープ外)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 0 | 参照監査(削除安全性の確証。三系統) | (読み取りのみ) | 必須先行 |
| 1 | sync バックエンド一式の削除 | Controller / Service / Request / Resource / DTO×3 | High |
| 2 | route 定義と import の削除 | `routes/web.php` | High |
| 3 | IDOR inventory から sync 除去 | `tests/Architecture/NestedRouteIdorDefenseTest.php` | High |
| 4 | bug-hunt 操作分母から sync 除去 | `.claude/skills/app-bug-hunt/operations.md` | High |
| 5 | canonical spec の整合 | `doc/10_実装仕様.md` / `doc/08_システムアーキテクチャ設計.md` | Medium |
| 6 | TS 型 `SyncResult` の削除 | `resources/js/types/capture.ts` | High |
| 7 | Feature テスト削除 + 全検証 green | `tests/Feature/Capture/CaptureSyncTest.php` 削除 | High |

削除・更新対象は概念設計「削除安全性の検証」で列挙した閉じた参照集合と一致
(全リポジトリ横断 grep で確認済み: routes/web.php・CaptureSyncTest.php・CaptureSyncService.php・
NestedRouteIdorDefenseTest.php・CaptureSyncInput/ResultData/ClientTakeFingerprint・SyncCaptureTakesRequest.php・
CaptureSyncResultResource.php・types/capture.ts・CaptureSyncController.php)。

---

## 施策 0: 参照監査(削除安全性の確証)

### 内容
削除の前に、概念設計「削除安全性の検証」節の三系統監査を実行し、hit が予定済み削除・更新対象に閉じることを確定する。
sync trio 外の **未記載プロダクションコード** からの参照が 1 件でも出たら **削除中止・再設計**(Critical)。
未記載のテスト・文書・ツールからの参照は評価して削除/更新一覧に追加してから実装。

```
rg "capture\.manuals\.sync|manuals/\{manual\}/sync"
rg "CaptureSyncController|CaptureSyncService|SyncCaptureTakesRequest|CaptureSyncResultResource|CaptureSyncInput|CaptureSyncResultData|ClientTakeFingerprint"
rg "SyncResult" resources/js
php artisan route:list --path=sync    # 削除前に 1 本存在 → 削除後 0 本
# code-review-graph(MCP)で reconcile 呼出元が Controller のみであることを確認
```

### 波及変更
- なし(読み取りのみ)。

### PHPStan 適合チェック
- 対象外(コード変更なし)。

### テスト計画
- 監査結果を実装 PR の記述に残す(手順のトレーサビリティ)。

### リスク
- 監査を飛ばして削除すると「使われているコードの削除」= 最大リスク。施策 1 以降の前提ゲートとする。

---

## 施策 1: sync バックエンド一式の削除

### 変更箇所(全ファイル削除)
- `app/Http/Controllers/Capture/CaptureSyncController.php`
- `app/Services/Capture/CaptureSyncService.php`
- `app/Http/Requests/Capture/SyncCaptureTakesRequest.php`
- `app/Http/Resources/Capture/CaptureSyncResultResource.php`
- `app/DataTransferObjects/Capture/CaptureSyncInput.php`
- `app/DataTransferObjects/Capture/CaptureSyncResultData.php`
- `app/DataTransferObjects/Capture/ClientTakeFingerprint.php`

### 波及変更
- TypeScript 型定義: `SyncResult`(施策 6 で削除)
- API Resource/DTO: 上記 DTO/Resource 自体が削除対象。**共有 DTO は削除しない**
  (`CaptureManualDetailData` / `CaptureTakeData` / `TakeObjectStorage` / `UploadTicketCodec` は
  `CaptureManualController` の show/index が使用)。
- テストファイル: `CaptureSyncTest.php`(施策 7)。他の Capture Feature テストは影響なし。
- Architecture inventory: `NestedRouteIdorDefenseTest`(施策 3)。
  `ProhibitsProtectedKeys` 系(`MassAssignmentStrictModeTest`/`ValidationAttributeCoverageTest`/
  `FormRequestProhibitedKeyTest`)は FormRequest を動的走査するため、`SyncCaptureTakesRequest` 削除で
  走査対象から自然に外れるだけ(ハードコード期待なし = 監査で確認済み)。

### 現行コード(要旨)
`CaptureSyncController::store` が `SyncCaptureTakesRequest` を受け、URL 整合 guard(`resolveOrganizationProject`)+
`Gate::authorize('view', $manual)` の後に `CaptureSyncService::reconcile` を呼び `CaptureSyncResultResource` を返す。
`reconcile` は cut 集合照合(不一致 404)+ 未登録 fingerprint を `pendingUpload` 抽出 + `CaptureManualDetailData` を返す
(書き込みゼロ)。

### 変更後コード
- 全ファイル削除(置換なし)。

### PHPStan 適合チェック
- [x] 削除により未使用 import / 未定義参照が残らない(施策 2・6 と同一 PR で解消)
- [x] 共有 DTO の型参照は保持側 Controller で不変

### テスト計画
- [x] `composer phpstan`(level 10)green — 削除後に未解決シンボルが無いこと
- [x] `composer test` green(`CaptureSyncTest.php` 削除後、他 Capture テストは不変で通る)

### リスク
- 共有 DTO の巻き込み削除。→ 監査(施策 0)+「保持リスト」明示で防止。

---

## 施策 2: route 定義と import の削除

### 変更箇所
- `routes/web.php` L11: `use App\Http\Controllers\Capture\CaptureSyncController;` 削除
- `routes/web.php` L491-492: `Route::post('/projects/{project}/manuals/{manual}/sync', …)->name('manuals.sync');` 削除

### 波及変更
- テストファイル: `NestedRouteIdorDefenseTest`(施策 3)。route:list からも当該 route が消える。
- inventory-check: operations.md(施策 4)と同時に消すことで forward/reverse 差分 0 を維持。

### PHPStan 適合チェック
- [x] 削除した import が他で使われていないこと(Controller 削除と同一 PR)

### テスト計画
- [x] `php artisan route:list --path=sync` が 0 件
- [x] route 定義を参照する Feature テスト(旧 CaptureSyncTest 以外)が無いことを確認済み

### リスク
- scopeBindings グループ内の 1 行削除で隣接 route(takes.*)に影響しないこと。→ 前後は独立行、影響なし。

---

## 施策 3: IDOR inventory から sync 除去

### 変更箇所
- `tests/Architecture/NestedRouteIdorDefenseTest.php` L78: `'capture.manuals.sync' => $s,` 削除

### 波及変更
- なし(inventory 1 行削除)。`NestedRouteIdorDefenseTest` は route:list の 2+param route を
  inventory と突き合わせる。route(施策 2)と inventory を同時に消せば整合。

### PHPStan 適合チェック
- 対象外(PHP 配列要素削除)。

### テスト計画
- [x] `composer test -- --filter=NestedRouteIdorDefense` green(route 除去と同期して inventory drift 0)

### リスク
- inventory だけ消して route を残す/逆、で test fail。→ 施策 2 と同一 PR・同時変更。

---

## 施策 4: bug-hunt 操作分母から sync 除去

### 変更箇所
- `.claude/skills/app-bug-hunt/operations.md` L17:
  `| POST | app/projects/{project}/manuals/{manual}/sync | capture.manuals.sync | S3 | 通常 |` 削除

### 波及変更
- なし。`scripts/bug-hunt-inventory-check.sh` は `route:list` と operations.md の差分検出。
  route(施策 2)と本行を同時削除で forward(新規 route 検知)・reverse(消失 route 検知)とも drift 0。

### PHPStan 適合チェック
- 対象外(Markdown)。

### テスト計画
- [x] `scripts/bug-hunt-inventory-check.sh` が drift 0(operations.md と route:list が一致)

### リスク
- operations.md だけ消して route を残すと reverse 検知で fail。→ 施策 2 と同時。

---

## 施策 5: canonical spec の整合(doc)

### 変更箇所
- `doc/10_実装仕様.md`
  - §10.3 route 表 L178: `| POST | .../manuals/{manual}/sync | 一括同期(新規テイクのみ) |` 行削除
  - §10.8-8 L334: `- **sync API の payload ID は照合専用**(サーバは nested relation から解決)。` を削除し、
    「一括同期は D9 即時アップロード方式へ吸収され sync endpoint は廃止(照合専用の payload-ID 原則は
    scenario PUT §10.8-5 / upload ticket §10.8-7 に残る)」旨の注記に置換
- `doc/08_システムアーキテクチャ設計.md`
  - route 表 L155: sync 行削除
  - L251: 「REST v1(manuals/takes/sync)」→「REST v1(manuals/takes)」

### 波及変更
- なし(doc のみ)。canonical spec と実装の drift 解消が目的。

### PHPStan 適合チェック
- 対象外。

### テスト計画
- [x] doc 内に sync への dangling 参照(§5.3 の「アップロード時: 新規のみ送信」は D9 で成立するため残す)が
      残らないことを目視 + `grep -rn "sync\|一括同期" doc/` で確認(削除後 hit が意図した箇所のみ)。

### リスク
- 過度な doc 書き換えによるスコープ膨張。→ sync 固有の記述のみ落とし、照合原則など共通概念は温存。

---

## 施策 6: TS 型 `SyncResult` の削除

### 変更箇所
- `resources/js/types/capture.ts` L66-70: `/** POST .../sync の応答 … */ export interface SyncResult {…}` 削除

### 波及変更
- TypeScript 型定義: `SyncResult` を import している箇所は無い(監査で確認: hit は定義のみ)。
- 他の capture 型(`CaptureManualDetail` 等)は保持。

### PHPStan 適合チェック
- 対象外(TS)。

### テスト計画
- [x] `pnpm typecheck` green(未使用 export 削除で型エラーが出ないこと)
- [x] `pnpm lint` green / `pnpm build` green

### リスク
- なし(未参照 export の削除)。

---

## 施策 7: Feature テスト削除 + 全検証 green

### 変更箇所
- `tests/Feature/Capture/CaptureSyncTest.php` 削除

### 波及変更
- なし。削除により担保していた不変条件(照合専用 endpoint の振る舞い)は endpoint 廃止で消滅するため
  補強不要(概念設計「廃止前に検証・補強する代替経路の不変条件」で (3)新規必要=無し を確定済み)。

### PHPStan 適合チェック
- 対象外(テスト削除)。

### テスト計画(最終ゲート — 全 green でコミット)
- [x] `composer test`(Pest, `--parallel`, RefreshDatabase グローバル)
- [x] `composer phpstan`(level 10)
- [x] `vendor/bin/pint --test`
- [x] `pnpm lint` / `pnpm typecheck` / `pnpm test`(vitest。upload-queue / CaptureShow 等の代替経路テストは不変で green)/ `pnpm build`
- [x] `scripts/bug-hunt-inventory-check.sh`(operations.md drift 0)
- [x] 個別 `DatabaseTransactions` を新規に使っていないこと(削除のみのため該当なし)

### リスク
- テスト削除が「テストなし完了」に見えないこと。→ 削除するのは廃止 endpoint 専用テストのみ。
  代替経路は既存テストで担保済み(概念設計の分類表 (1) 群)。新たな不変条件は生じない。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | route/Controller/Service/DTO/Request/Resource/inventory/operations/doc/TS を横断削除する 1 まとまりの変更で、他施策との分割は route と inventory・operations の同時性(drift 0 維持)を壊すため不可分。単一 PR で一括実施する。 |
| 競合リスク | ギャップ #6(自動 DL)実装が同じ Capture 領域(Show.svelte / manual スナップショット)に触れる可能性。ただし本削除は sync 経路のみで Show.svelte を変更しないため、#6 と論理的競合はない。routes/web.php・types/capture.ts の行編集で軽微な物理コンフリクトの可能性 → 先行マージ推奨。 |

## セキュリティ・使命チェック(最終確認)
- 使命: 撮影同期を D9 一本へ統一し運用負債を除去(直接機能追加ではないが設計一貫性に寄与)。
- 禁止事項: いずれにも抵触しない(削除中心。`response()->json()` 直書き等を増やさない)。
- セキュリティ不変条件: read-only route の削除で認可面が縮小。新たな攻撃面・mass-assignment 経路を作らない。
  `NestedRouteIdorDefenseTest` inventory は route と同時更新で整合(IDOR 防御の網羅性を維持)。


---

## 関連する現行コード(抜粋)

### routes/web.php(該当箇所)
```php
use App\Http\Controllers\Capture\CaptureSyncController; // L11
// ... L491-492(scopeBindings グループ内)
Route::post('/projects/{project}/manuals/{manual}/sync', [CaptureSyncController::class, 'store'])
    ->name('manuals.sync');
```

### app/Http/Controllers/Capture/CaptureSyncController.php
```php
class CaptureSyncController extends Controller {
    use ResolvesCurrentOrganization;
    public function store(SyncCaptureTakesRequest $request, Project $project, VideoManual $manual, CaptureSyncService $sync): CaptureSyncResultResource {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
        Gate::authorize('view', $manual);
        $user = $request->user(); Assert::isInstanceOf($user, User::class);
        return CaptureSyncResultResource::make($sync->reconcile($user, $manual, $request->toCaptureSyncInput()));
    }
}
```

### app/Services/Capture/CaptureSyncService.php(reconcile 要旨)
読み取り専用。cut 集合照合(不一致 404)→ 未登録 fingerprint を pendingUpload 抽出 → CaptureManualDetailData を返す(書き込みゼロ)。共有 `CaptureManualDetailData::fromManual($manual, $user, $this->storage, $this->codec)` を使用。

### tests/Architecture/NestedRouteIdorDefenseTest.php(L74-85 抜粋)
```php
'capture.manuals.show' => $s,
'capture.manuals.sync' => $s,   // ← 削除対象
'capture.takes.upload-url' => $s,
// ... 他 capture.takes.* は保持
```

### .claude/skills/app-bug-hunt/operations.md(L17)
```
| POST | app/projects/{project}/manuals/{manual}/sync | capture.manuals.sync | S3 | 通常 |
```

### resources/js/types/capture.ts(L66-70)
```ts
/** POST .../sync の応答 (CaptureSyncResultResource と対) */
export interface SyncResult {
    pending_upload: { cut: number; client_take_id: string }[];
    manual: CaptureManualDetail;
}
```
（レビュアーは必要に応じて実ファイルを読んで裏取りしてよい。特に共有 DTO 保持の正しさ、
tests/js/lib/capture/upload-queue.test.ts / tests/js/pages/CaptureShow.test.ts / tests/Feature/Capture/TakeRegistrationTest.php の代替担保、
scripts/bug-hunt-inventory-check.sh の drift 検出方式。）
