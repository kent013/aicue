【アプリの使命 (North Star)】
## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。
データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。設計の方向性が正しいと確認できてから行え。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

【system: 役割】
あなたは Laravel + Svelte アプリのコードレビュアーです。本 PR は TODO T052「capture.manuals.sync のフロント配線 or 廃止判断」で、詳細設計の判断=(B)廃止 に基づき sync endpoint 一式(route/Controller/Service/Request/Resource/DTO×3/Feature テスト/TS 型)を削除し、inventory・operations.md・canonical spec(doc/08・doc/10)を整合させる削除中心の変更です。
以下の観点でレビューしてください:
1. 設計との一致性(詳細設計の施策 0〜7 が過不足なく実装されているか)
2. 削除安全性(削除したシンボルへの dangling 参照が残っていないか。共有 DTO を巻き込み削除していないか)
3. PHPStan 適合性(未定義シンボル参照が残らないか)
4. IDOR inventory / bug-hunt operations / route:list の三者整合(drift 0)
5. canonical spec(doc)の整合(sync 固有記述のみ落ち、照合原則など共通概念が温存されているか)
6. テスト網羅性(削除された不変条件が代替経路で担保済みか。テストなし完了になっていないか)
7. セキュリティ(read-only route 削除で攻撃面が縮小するのみで新たな mass-assignment 経路等を作っていないか)

出力形式: ファイルごとに判定、指摘は Critical/Warning/Suggestion に分類。最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示してください。

---

【user: データ】

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
# 保持 DTO の参照が削除後も残ることの確証(共有 DTO 巻き込み削除の防止 — 必須チェック)
rg "CaptureManualDetailData|CaptureTakeData|TakeObjectStorage|UploadTicketCodec" app tests
php artisan route:list --path=sync    # 削除前に 1 本存在 → 削除後 0 本
# code-review-graph(MCP)で reconcile 呼出元が Controller のみであることを確認
```

**PR テンプレ記録項目(必須)**:
- 上記 grep 結果を PR 説明に貼付し、(a) sync trio の hit が予定済み削除・更新対象に閉じること、
  (b) 保持 DTO(`CaptureManualDetailData` 等)が `CaptureManualController` から参照され続けること、を明記する。

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
  **補足(将来レビュー向け)**: 当該 3 テストは特定の Request クラス名を固定期待として持たず、
  ディレクトリ走査で全 FormRequest を収集する方式のため、1 クラス削除で inventory 不整合 fail は起きない。

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

## 実装差分 (git diff)
```diff
diff --git a/.claude/skills/app-bug-hunt/operations.md b/.claude/skills/app-bug-hunt/operations.md
index 2b6e558..3f806f3 100644
--- a/.claude/skills/app-bug-hunt/operations.md
+++ b/.claude/skills/app-bug-hunt/operations.md
@@ -14,7 +14,6 @@ ## 操作一覧 (web セッション面)
 | POST | notifications/read-all | notifications.read-all | S6 | 通常 |
 | POST | notifications/{notification}/open | notifications.open | S6 | 通常 |
 | POST | notifications/{notification}/read | notifications.read | S6 | 通常 |
-| POST | app/projects/{project}/manuals/{manual}/sync | capture.manuals.sync | S3 | 通常 |
 | POST | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/adopt | capture.takes.adopt | S3 | 通常 |
 | DELETE | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take} | capture.takes.destroy | S3 | 通常 |
 | POST | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/downloaded | capture.takes.downloaded | S3 | 通常 |
diff --git a/app/DataTransferObjects/Capture/CaptureSyncInput.php b/app/DataTransferObjects/Capture/CaptureSyncInput.php
deleted file mode 100644
index e8da6ae..0000000
--- a/app/DataTransferObjects/Capture/CaptureSyncInput.php
+++ /dev/null
@@ -1,43 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace App\DataTransferObjects\Capture;
-
-use Webmozart\Assert\Assert;
-
-/**
- * sync リクエストの検証済み入力 (概念設計 D8。Service は連想配列を受けない)。
- */
-final readonly class CaptureSyncInput
-{
-    /**
-     * @param  list<ClientTakeFingerprint>  $fingerprints
-     */
-    public function __construct(
-        public array $fingerprints,
-    ) {}
-
-    /**
-     * FormRequest の validated 配列 → 型確定 (mixed を Assert で確定する唯一の境界)。
-     *
-     * @param  array<array-key, mixed>  $takes
-     */
-    public static function fromValidated(array $takes): self
-    {
-        $fingerprints = [];
-        foreach ($takes as $row) {
-            Assert::isArray($row);
-            Assert::keyExists($row, 'cut');
-            Assert::integer($row['cut']);
-            Assert::keyExists($row, 'client_take_id');
-            Assert::stringNotEmpty($row['client_take_id']);
-            $fingerprints[] = new ClientTakeFingerprint(
-                cutId: $row['cut'],
-                clientTakeId: strtoupper($row['client_take_id']),
-            );
-        }
-
-        return new self($fingerprints);
-    }
-}
diff --git a/app/DataTransferObjects/Capture/CaptureSyncResultData.php b/app/DataTransferObjects/Capture/CaptureSyncResultData.php
deleted file mode 100644
index fe793aa..0000000
--- a/app/DataTransferObjects/Capture/CaptureSyncResultData.php
+++ /dev/null
@@ -1,33 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace App\DataTransferObjects\Capture;
-
-/**
- * sync 応答 (未登録 fingerprint + サーバ状態スナップショット。概念設計 D8)。
- */
-final readonly class CaptureSyncResultData
-{
-    /**
-     * @param  list<ClientTakeFingerprint>  $pendingUpload
-     */
-    public function __construct(
-        public array $pendingUpload,
-        public CaptureManualDetailData $manual,
-    ) {}
-
-    /**
-     * @return array{pending_upload: list<array{cut: int, client_take_id: string}>, manual: array{id: int, title: string, status: string, cuts: list<array<string, mixed>>}}
-     */
-    public function toArray(): array
-    {
-        return [
-            'pending_upload' => array_map(
-                static fn (ClientTakeFingerprint $fingerprint): array => $fingerprint->toArray(),
-                $this->pendingUpload,
-            ),
-            'manual' => $this->manual->toArray(),
-        ];
-    }
-}
diff --git a/app/DataTransferObjects/Capture/ClientTakeFingerprint.php b/app/DataTransferObjects/Capture/ClientTakeFingerprint.php
deleted file mode 100644
index 1509616..0000000
--- a/app/DataTransferObjects/Capture/ClientTakeFingerprint.php
+++ /dev/null
@@ -1,27 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace App\DataTransferObjects\Capture;
-
-/**
- * 端末が保持するテイクの照合キー (cut id + client_take_id)。照合専用 (代入に使わない)。
- */
-final readonly class ClientTakeFingerprint
-{
-    public function __construct(
-        public int $cutId,
-        public string $clientTakeId,
-    ) {}
-
-    /**
-     * @return array{cut: int, client_take_id: string}
-     */
-    public function toArray(): array
-    {
-        return [
-            'cut' => $this->cutId,
-            'client_take_id' => $this->clientTakeId,
-        ];
-    }
-}
diff --git a/app/Http/Controllers/Capture/CaptureSyncController.php b/app/Http/Controllers/Capture/CaptureSyncController.php
deleted file mode 100644
index 1e9f28c..0000000
--- a/app/Http/Controllers/Capture/CaptureSyncController.php
+++ /dev/null
@@ -1,42 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace App\Http\Controllers\Capture;
-
-use App\Http\Concerns\ResolvesCurrentOrganization;
-use App\Http\Controllers\Controller;
-use App\Http\Requests\Capture\SyncCaptureTakesRequest;
-use App\Http\Resources\Capture\CaptureSyncResultResource;
-use App\Models\Project;
-use App\Models\User;
-use App\Models\VideoManual;
-use App\Services\Capture\CaptureSyncService;
-use Illuminate\Support\Facades\Gate;
-use Webmozart\Assert\Assert;
-
-/**
- * 一括同期 (照合専用。doc/10 §10.3)。同一オリジン XHR (JSON 応答)。書き込みしない。
- */
-class CaptureSyncController extends Controller
-{
-    use ResolvesCurrentOrganization;
-
-    public function store(
-        SyncCaptureTakesRequest $request,
-        Project $project,
-        VideoManual $manual,
-        CaptureSyncService $sync,
-    ): CaptureSyncResultResource {
-        $organization = $this->resolveCurrentOrganization($request);
-        $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
-        Gate::authorize('view', $manual);
-
-        $user = $request->user();
-        Assert::isInstanceOf($user, User::class);
-
-        return CaptureSyncResultResource::make(
-            $sync->reconcile($user, $manual, $request->toCaptureSyncInput()),
-        );
-    }
-}
diff --git a/app/Http/Requests/Capture/SyncCaptureTakesRequest.php b/app/Http/Requests/Capture/SyncCaptureTakesRequest.php
deleted file mode 100644
index 2021ecf..0000000
--- a/app/Http/Requests/Capture/SyncCaptureTakesRequest.php
+++ /dev/null
@@ -1,46 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace App\Http\Requests\Capture;
-
-use App\DataTransferObjects\Capture\CaptureSyncInput;
-use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
-use Illuminate\Foundation\Http\FormRequest;
-use Webmozart\Assert\Assert;
-
-/**
- * 一括同期 (POST .../manuals/{manual}/sync)。照合専用 payload (概念設計 D8)。
- * 入力名は保護キー cut_id と別名の `cut` (Category の `category` 入力名と同じ境界規約)。
- * ネスト位置の保護キー直送 (takes.*.cut_id) も 422。
- */
-class SyncCaptureTakesRequest extends FormRequest
-{
-    use ProhibitsProtectedKeys;
-
-    public function authorize(): bool
-    {
-        return true; // 認可は controller の Gate::authorize (URL 整合 guard の後)
-    }
-
-    /**
-     * @return array<string, list<mixed>>
-     */
-    public function rules(): array
-    {
-        return array_merge([
-            'takes' => ['present', 'array', 'max:500'],
-            'takes.*.cut' => ['required', 'integer'],
-            'takes.*.client_take_id' => ['required', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/i'],
-            'takes.*.cut_id' => ['missing'], // ネスト位置の保護キー直送も 422
-        ], $this->protectedKeyMissingRules());
-    }
-
-    public function toCaptureSyncInput(): CaptureSyncInput
-    {
-        $takes = $this->validated('takes');
-        Assert::isArray($takes);
-
-        return CaptureSyncInput::fromValidated($takes);
-    }
-}
diff --git a/app/Http/Resources/Capture/CaptureSyncResultResource.php b/app/Http/Resources/Capture/CaptureSyncResultResource.php
deleted file mode 100644
index 44021c9..0000000
--- a/app/Http/Resources/Capture/CaptureSyncResultResource.php
+++ /dev/null
@@ -1,28 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace App\Http\Resources\Capture;
-
-use App\DataTransferObjects\Capture\CaptureSyncResultData;
-use Illuminate\Http\Request;
-use Illuminate\Http\Resources\Json\JsonResource;
-
-/**
- * sync 応答 ({ pending_upload, manual })。TS 側 types/capture.ts の SyncResult と対で保守。
- *
- * @property-read CaptureSyncResultData $resource
- */
-final class CaptureSyncResultResource extends JsonResource
-{
-    /** @var string|null */
-    public static $wrap = null;
-
-    /**
-     * @return array<string, mixed>
-     */
-    public function toArray(Request $request): array
-    {
-        return $this->resource->toArray();
-    }
-}
diff --git a/app/Services/Capture/CaptureSyncService.php b/app/Services/Capture/CaptureSyncService.php
deleted file mode 100644
index cba6463..0000000
--- a/app/Services/Capture/CaptureSyncService.php
+++ /dev/null
@@ -1,56 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace App\Services\Capture;
-
-use App\DataTransferObjects\Capture\CaptureManualDetailData;
-use App\DataTransferObjects\Capture\CaptureSyncInput;
-use App\DataTransferObjects\Capture\CaptureSyncResultData;
-use App\DataTransferObjects\Capture\ClientTakeFingerprint;
-use App\Models\Cut;
-use App\Models\Take;
-use App\Models\User;
-use App\Models\VideoManual;
-use Illuminate\Database\Eloquent\ModelNotFoundException;
-
-/**
- * 一括同期 (doc/10 §10.3 / 概念設計 D8)。**読み取り専用** — 本エンドポイントは何も書かない。
- * payload の cut/client_take_id は照合専用: manual の relation 集合と突き合わせ、
- * (a) manual に属さない cut 参照 → 404 (存在を漏らさない)
- * (b) サーバ未登録の fingerprint → pending_upload として返す (クライアントが D2-D4 経路で送信)
- * (c) 登録済み fingerprint → 現在のサーバ状態を返す (冪等: 何度呼んでも同じ)
- */
-class CaptureSyncService
-{
-    public function __construct(
-        private readonly TakeObjectStorage $storage,
-        private readonly UploadTicketCodec $codec,
-    ) {}
-
-    public function reconcile(User $user, VideoManual $manual, CaptureSyncInput $input): CaptureSyncResultData
-    {
-        $cutIds = $manual->cuts()->pluck('id');
-        foreach ($input->fingerprints as $fingerprint) {
-            if (! $cutIds->contains($fingerprint->cutId)) {
-                // manual に属さない cut 参照は照合不一致 = 404 (tenant キー不信。代入には使わない)
-                throw (new ModelNotFoundException)->setModel(Cut::class, [$fingerprint->cutId]);
-            }
-        }
-
-        $existing = Take::query()
-            ->whereIn('cut_id', $cutIds)
-            ->get(['id', 'cut_id', 'client_take_id'])
-            ->keyBy(static fn (Take $take): string => $take->cut_id.':'.$take->client_take_id);
-
-        $pendingUpload = array_values(array_filter(
-            $input->fingerprints,
-            static fn (ClientTakeFingerprint $fingerprint): bool => ! $existing->has($fingerprint->cutId.':'.$fingerprint->clientTakeId),
-        ));
-
-        return new CaptureSyncResultData(
-            pendingUpload: $pendingUpload, // 新規テイクのみ送信 (doc/05 §5.3)
-            manual: CaptureManualDetailData::fromManual($manual, $user, $this->storage, $this->codec),
-        );
-    }
-}
diff --git "a/doc/08_\343\202\267\343\202\271\343\203\206\343\203\240\343\202\242\343\203\274\343\202\255\343\203\206\343\202\257\343\203\201\343\203\243\350\250\255\350\250\210.md" "b/doc/08_\343\202\267\343\202\271\343\203\206\343\203\240\343\202\242\343\203\274\343\202\255\343\203\206\343\202\257\343\203\201\343\203\243\350\250\255\350\250\210.md"
index c947c54..2bed300 100644
--- "a/doc/08_\343\202\267\343\202\271\343\203\206\343\203\240\343\202\242\343\203\274\343\202\255\343\203\206\343\202\257\343\203\201\343\203\243\350\250\255\350\250\210.md"
+++ "b/doc/08_\343\202\267\343\202\271\343\203\206\343\203\240\343\202\242\343\203\274\343\202\255\343\203\206\343\202\257\343\203\201\343\203\243\350\250\255\350\250\210.md"
@@ -152,7 +152,6 @@ ### スマホアプリ（iPhone ネイティブ）＝ REST API v1 の主要ク
 | POST | `.../manuals/{manual}/takes/upload-url` | **S3 presigned PUT URL 発行**（大容量は直 S3 アップロード） |
 | POST | `.../cuts/{cut}/takes` | テイク登録（S3 キー + メタデータ） |
 | PATCH/DELETE | `.../takes/{take}` | 並べ替え・コメント・削除 |
-| POST | `.../manuals/{manual}/sync` | 撮影データ一括同期（新規テイクのみ。DL 済みは除外） |
 
 > **大容量メディアの原則**: テイク動画はアプリサーバを経由させず **S3 presigned URL で直アップロード**。API はメタデータ登録のみ扱う。ダウンロード（同期時の採用テイク取得）も `temporaryUrl()`（署名付き期限付き URL）。
 
@@ -248,7 +247,7 @@ ## 8.10 実装ロードマップ（Item 見本をトレースする順）
 | 1. ドメイン骨格 | `Category` → `VideoManual` → `Cut` → `Take` を **Item 見本手順で**追加（マイグレーション/Model/FormRequest/Policy/Factory/nested route/テスト）。保護キー追記。Web CRUD |
 | 2. AI パイプライン | prompt YAML 群（sop-extract/work-decomposition/scenario-generation）+ `AnalysisJob` queue + `ai_analysis` チケット。PC の「書類解析→シナリオ生成」導線 |
 | 3. メディア基盤 | S3 prefix 設計 + presigned upload/download + `max_storage_bytes` Quota。SourceDocument/Take のファイル参照 |
-| 4. スマホ API | REST v1（manuals/takes/sync）+ OAuth（PKCE）+ 同期の重複排除ロジック（[05章 §5.3](05_スマホアプリ機能仕様.md#53-同期の要点重複防止ロジック)） |
+| 4. スマホ API | REST v1（manuals/takes）+ OAuth（PKCE）+ 同期の重複排除ロジック（[05章 §5.3](05_スマホアプリ機能仕様.md#53-同期の要点重複防止ロジック)） |
 | 5. 合成パイプライン | ffmpeg ワーカー + TTS（SSRF pin）+ 字幕焼き込み + `RenderJob` + `video_render` チケット。プレビュー |
 | 6. 課金・多言語・MCP | プラン/Quota/チケット確定、`feature_multilang`、MCP read tools（任意） |
 | 各フェーズ | `composer test` / `phpstan` / `pint` / `pnpm lint,typecheck,test,build` 全 green でコミット。逸脱は `template-divergence.md` に記録 |
diff --git "a/doc/10_\345\256\237\350\243\205\344\273\225\346\247\230.md" "b/doc/10_\345\256\237\350\243\205\344\273\225\346\247\230.md"
index 1a411b6..9f48c36 100644
--- "a/doc/10_\345\256\237\350\243\205\344\273\225\346\247\230.md"
+++ "b/doc/10_\345\256\237\350\243\205\344\273\225\346\247\230.md"
@@ -175,7 +175,6 @@ ### 撮影 PWA（同一オリジン・セッション認証。データは JSON/
 | POST | `.../cuts/{cut}/takes` | テイク登録（署名チケット検証 + HeadObject）。`(cut_id, client_take_id)` 冪等 |
 | PATCH/DELETE | `.../takes/{take}` | 並べ替え・コメント・削除（DL 済みは削除不可） |
 | POST | `.../cuts/{cut}/takes/{take}/adopt` | 採用 |
-| POST | `.../manuals/{manual}/sync` | 一括同期（新規テイクのみ） |
 
 > 全 nested route を `NestedRouteIdorDefenseTest` inventory に登録（子∈親を認可前 404）。書き込みは Idempotency 配線（API 経路）/ dirty 保存（Inertia 経路）。
 
@@ -331,7 +330,7 @@ ### 10.8-7 アップロード署名チケットは「検証専用」（Critical/
 - 署名対象に `content_type` と `size`（必要なら `md5`）も含め、`POST .../takes` で `HeadObject` の ContentLength/ContentType と照合。不一致は削除・拒否。
 
 ### 10.8-8 その他（Warning・採用）
-- **sync API の payload ID は照合専用**（サーバは nested relation から解決）。
+- **一括同期は D9 即時アップロード方式へ吸収され sync endpoint は廃止**（照合専用の payload-ID 原則は scenario PUT §10.8-5 / upload ticket §10.8-7 に残る）。
 - **analyze/render の Idempotency**: 同一 (manual, 操作種別) で同時 in-flight は 1 つ。`failed` のときのみ再トリガー可。
 - **Category 削除**: FK `onDelete('set null')` を明示（未分類化）。
 - **job 進捗ポーリング**: `GET .../jobs/{job}` に `ETag`/`Last-Modified` を付け HTTP キャッシュで負荷軽減（任意）。
diff --git a/resources/js/types/capture.ts b/resources/js/types/capture.ts
index 75a33a3..ca3fb30 100644
--- a/resources/js/types/capture.ts
+++ b/resources/js/types/capture.ts
@@ -63,12 +63,6 @@ export interface UploadTicket {
     expires_at: string;
 }
 
-/** POST .../sync の応答 (CaptureSyncResultResource と対) */
-export interface SyncResult {
-    pending_upload: { cut: number; client_take_id: string }[];
-    manual: CaptureManualDetail;
-}
-
 /** 422 quota 超過ボディ (QuotaExceededResource と対) */
 export interface QuotaExceededBody {
     code: "quota_exceeded";
diff --git a/routes/web.php b/routes/web.php
index 0da2c2d..2d52ee8 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -8,7 +8,6 @@
 use App\Http\Controllers\Billing\BillingController;
 use App\Http\Controllers\Billing\TicketPurchaseController;
 use App\Http\Controllers\Capture\CaptureManualController;
-use App\Http\Controllers\Capture\CaptureSyncController;
 use App\Http\Controllers\Capture\CaptureTakeController;
 use App\Http\Controllers\Capture\TakeUploadUrlController;
 use App\Http\Controllers\ContactController;
@@ -488,8 +487,6 @@
             Route::scopeBindings()->group(function (): void {
                 Route::get('/projects/{project}/manuals/{manual}', [CaptureManualController::class, 'show'])
                     ->name('manuals.show');
-                Route::post('/projects/{project}/manuals/{manual}/sync', [CaptureSyncController::class, 'store'])
-                    ->name('manuals.sync');
                 Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes/upload-url', [TakeUploadUrlController::class, 'store'])
                     ->name('takes.upload-url');
                 Route::post('/projects/{project}/manuals/{manual}/cuts/{cut}/takes', [CaptureTakeController::class, 'store'])
diff --git a/tests/Architecture/NestedRouteIdorDefenseTest.php b/tests/Architecture/NestedRouteIdorDefenseTest.php
index 608b92f..984687f 100644
--- a/tests/Architecture/NestedRouteIdorDefenseTest.php
+++ b/tests/Architecture/NestedRouteIdorDefenseTest.php
@@ -75,7 +75,6 @@ function nestedRouteIdorInventory(): array
         // scopeBindings + 各書き込み Service の tx 内連鎖再解決 (二重防御)。
         // {project} ∈ current org は project.in-current-org middleware + inline guard の 2 層
         'capture.manuals.show' => $s,
-        'capture.manuals.sync' => $s,
         'capture.takes.upload-url' => $s,
         'capture.takes.store' => $s,
         'capture.takes.update' => $s,
diff --git a/tests/Feature/Capture/CaptureSyncTest.php b/tests/Feature/Capture/CaptureSyncTest.php
deleted file mode 100644
index 0e6bbd9..0000000
--- a/tests/Feature/Capture/CaptureSyncTest.php
+++ /dev/null
@@ -1,123 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-use App\Models\Cut;
-use App\Models\Organization;
-use App\Models\Project;
-use App\Models\Take;
-use App\Models\User;
-use App\Models\VideoManual;
-use Illuminate\Support\Str;
-
-/*
- * 一括同期 (施策8): 照合専用 (書き込みゼロ)。未登録 fingerprint を pending_upload で返す。
- * POST /app/projects/{project}/manuals/{manual}/sync
- */
-
-/**
- * @return array{Organization, User, Project, VideoManual, Cut}
- */
-function syncContext(): array
-{
-    [$organization, $owner] = createOrganizationWithOwner();
-    $project = Project::factory()->forOrganization($organization)->create();
-    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
-    $cut = Cut::factory()->forManual($manual)->create();
-
-    return [$organization, $owner, $project, $manual, $cut];
-}
-
-function syncPath(Project $project, VideoManual $manual): string
-{
-    return "/app/projects/{$project->id}/manuals/{$manual->id}/sync";
-}
-
-test('未登録 fingerprint のみ pending_upload で返り、登録済みは返らない', function (): void {
-    [, $owner, $project, $manual, $cut] = syncContext();
-    $registered = Take::factory()->forCut($cut)->create();
-    $unregisteredId = (string) Str::ulid();
-
-    $response = $this->actingAs($owner)->postJson(syncPath($project, $manual), [
-        'takes' => [
-            ['cut' => $cut->id, 'client_take_id' => $registered->client_take_id],
-            ['cut' => $cut->id, 'client_take_id' => $unregisteredId],
-        ],
-    ]);
-
-    $response->assertOk();
-    $response->assertJsonCount(1, 'pending_upload');
-    $response->assertJsonPath('pending_upload.0.cut', $cut->id);
-    $response->assertJsonPath('pending_upload.0.client_take_id', $unregisteredId);
-    $response->assertJsonPath('manual.id', $manual->id);
-});
-
-test('冪等: 同 payload 連続 2 回で同一応答・DB 書き込みゼロ', function (): void {
-    [, $owner, $project, $manual, $cut] = syncContext();
-    Take::factory()->forCut($cut)->create();
-    $payload = ['takes' => [['cut' => $cut->id, 'client_take_id' => (string) Str::ulid()]]];
-
-    $first = $this->actingAs($owner)->postJson(syncPath($project, $manual), $payload);
-    $second = $this->actingAs($owner)->postJson(syncPath($project, $manual), $payload);
-
-    $first->assertOk();
-    $second->assertOk();
-    expect($second->json('pending_upload'))->toBe($first->json('pending_upload'));
-    $this->assertDatabaseCount('takes', 1);
-    $this->assertDatabaseCount('take_upload_reservations', 0);
-});
-
-test('他 manual の cut id 混入は 404 (tenant キー不信・存在を漏らさない)', function (): void {
-    [, $owner, $project, $manual] = syncContext();
-    $otherManual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
-    $foreignCut = Cut::factory()->forManual($otherManual)->create();
-
-    $this->actingAs($owner)->postJson(syncPath($project, $manual), [
-        'takes' => [['cut' => $foreignCut->id, 'client_take_id' => (string) Str::ulid()]],
-    ])->assertNotFound();
-});
-
-test('ネスト位置の保護キー直送 (takes.*.cut_id) は 422', function (): void {
-    [, $owner, $project, $manual, $cut] = syncContext();
-
-    $response = $this->actingAs($owner)->postJson(syncPath($project, $manual), [
-        'takes' => [['cut' => $cut->id, 'cut_id' => $cut->id, 'client_take_id' => (string) Str::ulid()]],
-    ]);
-
-    $response->assertStatus(422);
-    $response->assertJsonValidationErrors(['takes.0.cut_id']);
-});
-
-test('空 takes 配列は全量スナップショットのみ返す', function (): void {
-    [, $owner, $project, $manual, $cut] = syncContext();
-    Take::factory()->forCut($cut)->create();
-
-    $response = $this->actingAs($owner)->postJson(syncPath($project, $manual), ['takes' => []]);
-
-    $response->assertOk();
-    $response->assertJsonCount(0, 'pending_upload');
-    $response->assertJsonCount(1, 'manual.cuts');
-    $response->assertJsonCount(1, 'manual.cuts.0.takes');
-});
-
-test('cross-org 404 / org member (読み取り権限) は同期可', function (): void {
-    [$organization, , $project, $manual] = syncContext();
-
-    [, $otherOwner] = createOrganizationWithOwner();
-    $this->actingAs($otherOwner)->postJson(syncPath($project, $manual), ['takes' => []])->assertNotFound();
-
-    $orgMember = attachOrganizationMember($organization);
-    $orgMember->forceFill(['current_organization_id' => $organization->id])->save();
-    $this->actingAs($orgMember)->postJson(syncPath($project, $manual), ['takes' => []])->assertOk();
-});
-
-test('500 件超の takes は 422 (分割送信を促す)', function (): void {
-    [, $owner, $project, $manual, $cut] = syncContext();
-    $takes = [];
-    for ($i = 0; $i < 501; $i++) {
-        $takes[] = ['cut' => $cut->id, 'client_take_id' => (string) Str::ulid()];
-    }
-
-    $this->actingAs($owner)->postJson(syncPath($project, $manual), ['takes' => $takes])
-        ->assertStatus(422);
-});
```

## テスト結果
- composer test (Pest, --parallel, RefreshDatabase): 1752 tests, 1750 passed, 2 skipped, 0 failed (exit 0)
- composer phpstan (level 10): No errors
- vendor/bin/pint --test: passed
- pnpm lint / typecheck: passed
- pnpm test (vitest): 76 files, 650 passed
- pnpm build: built OK
- php artisan route:list --path=sync: 0 件 (route 消失を確認)
- scripts/bug-hunt-inventory-check.sh: sync の drift 無し (残る 2 件 capture.takes.playback / projects.manuals.duplicate は main 既存の scope 外 drift)

## 参照監査 (施策 0)
- sync trio (route名/パス/クラス名/SyncResult) の全 hit が予定済み削除・更新対象に閉じることを確認済み。
- 保持 DTO (CaptureManualDetailData / CaptureTakeData / TakeObjectStorage / UploadTicketCodec) は CaptureManualController / CaptureTakeService 等から参照され続ける (削除対象外) ことを確認済み。
