# 概念設計: scenario-editing（シナリオ編集: document 一括保存・楽観ロック）

作成: 2026-07-11 / 対象アプリ: AI-CUE (/workspace)
改訂: Round 1 レビュー反映（共有ロック規約の明文化・階層/型変更の v1 禁止・2 段階 reconcile・
409 UX・実変更判定・DTO 一元化・conflict 判別 union）

## 背景・課題

AI-CUE の中核 UX は「SOP → AI がシナリオ（Cut 群）を設計 → ナビ撮影」だが、AI の生成結果は
そのままでは完成形にならない。編集者（project_admin）が PC 編集画面でシナリオ（手順 step /
急所 point の 2 階層ツリー）を修正・追加・並べ替えできることが、標準化されたマニュアル動画を
作る前提条件になる。

フェーズ1（devnotes/20260710-2137-aicue-domain-foundation）で Cut / Take のスキーマと Model は
Tier B（振る舞いなし）で先取り済み。現状は:

- `cuts` テーブル・`Cut` Model・`CutFactory` は存在するが、route / Controller / UI が無く外部到達不可
- `video_manuals.scenario_version`（楽観ロック用カラム）は存在するが未使用（常に 0）
- `Manuals/Edit.svelte` はメタデータ（title / category）編集のみ

本フィーチャで「シナリオの手動編集・保存」の器を実装する。AI 解析（自動生成）は後続フェーズで、
この保存経路（materialize 済み Cut 群）に合流する。

### なぜ per-row CRUD ではなく document 一括保存か（doc/09 §9.4 ★divergence）

シナリオ編集は「行追加/削除/並べ替え/手順削除で配下急所も削除」を伴う。1 行ずつの CRUD では
(a) 親子カスケード＋並べ替えの原子性が壊れる、(b) 編集途中の中間状態がサーバに漏れる。
よって **クライアントは作業コピーを編集し、「更新する」で document（cut 配列全体）を 1 回の
PUT で送信、サーバが 1 トランザクションで reconcile** する。Item 見本の per-row パターンからの
意図的逸脱として `docs/template-divergence.md` に登録する（logic-driven: 原子性要件）。

## 改善アイデア（何をどう変えるか）

### 1. ルートと画面

| メソッド | パス | 用途 |
|---|---|---|
| GET | `/projects/{project}/manuals/{manual}/edit`（既存） | シナリオ編集画面に拡張（メタ編集 + Cut ツリー編集） |
| PUT | `/projects/{project}/manuals/{manual}/scenario`（新設） | シナリオ document 一括保存 |

- 両ルートとも既存の `project.in-route-org` middleware 群 + `Route::scopeBindings()` グループ内
  （`{manual}` は `$project->manuals()` 経由解決、cross-org / cross-project は認可より前に 404）。
- PUT は `NestedRouteIdorDefenseTest` の inventory に登録（`projects.manuals.scenario.update`）。
- `edit` 画面の props に `scenario_version` と cuts ツリー（steps→points ネスト）を追加。

### 2. 保存 payload の構造（§10.8-5 protected キー遵守）

```jsonc
{
  "expected_version": 3,          // 楽観ロック（必須）
  "steps": [                       // 手順の配列（表示順）
    {
      "id": 12,                    // 既存 cut の照合用 id。新規行は null
      "scene": "…", "shot_type": "hiki", "shooting_point": null,
      "narration": "…", "subtitle_primary": null, "subtitle_secondary": "…",
      "material_type": null, "static_display_seconds": null,
      "points": [                  // この手順に付随する急所（表示順）
        { "id": null, "scene": "…", "shot_type": "yori", /* 本文フィールド同上 */ }
      ]
    }
  ]
}
```

- **`parent_cut_id` / `adopted_take_id` / `sort_order` / `type` は payload に含めない**。
  - `parent_cut_id`: サーバがネスト構造（points が属する step）から決定
  - `sort_order`: サーバが配列順から採番（親スコープ内 0..N-1 の連番、gap 除去）
  - `type`: ネスト位置から導出（トップレベル = step、points 配下 = point）— クライアントに
    送らせないことで構造と type の矛盾を原理的に排除
  - `adopted_take_id`: 採用は後続フェーズの `POST .../adopt` 専用（scenario payload から除外）
- 専用 FormRequest `UpdateScenarioRequest`（`ProhibitsProtectedKeys`）。trait の `missing` ルールは
  トップレベルのみ張るため、**ネスト配列（`steps.*` / `steps.*.points.*`）にも保護キーの
  `missing`（送出で 422）を明示的に張る**。本文フィールドの型・文字数上限
  （`subtitle_primary` ≤100 は DB string(100) と一致）もここで検証。
- payload 内の cut id は**照合専用**。当該 manual に属さない id が混入したら **404**
  （tenant キー不信、存在オラクル封じ）。payload 内での id 重複は 422。
- 有界入力: steps 最大 100・points/step 最大 20（DoS/暴走 guard。上限は仕様確定まで config 定数）。
- **既存 cut の階層/型変更は v1 禁止**: 既存 id は「既存 type と一致する位置」にのみ出現できる
  （step の id が `points` 配下に現れる / point の id がトップレベルに現れるのは 422）。
  2 階層構造での暗黙の型変換（step→point 降格による配下 point の巻き添え等）を原理的に排除する。
  UI も階層をまたぐ移動を提供しない（▲▼ は同一スコープ内のみ）。急所を手順へ「移す」ときは
  削除 + 新規追加として表現する（後続フェーズで明示的な移動 UX を検討）。

### 3. 保存の並行制御（§10.8-2 楽観ロック + §10.8-6 レンダ中禁止）

#### シナリオ整合の共有不変条件（本フィーチャで明文化し、全後続フェーズを拘束する）

> **cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、
> 対象 VideoManual 行を `lockForUpdate()` で取得した同一トランザクション内で反映する。**

- 本フィーチャの `ScenarioService::save()` がこの規約の最初の準拠実装。
- 後続フェーズ（AI 解析 job の Cut materialize、RenderJob の状態遷移スナップショット、
  テイク採用 API）も同じ規約に従うことを設計契約として `docs/architecture.md`
  （+ AGENTS.md ドメイン固有規約）へ記録する。状態 guard（下記 2.）は
  「rendering/analyzing 中に保存経路が走らない」ための第一防衛で、共有行ロックは
  「job 側の書き込みと保存が絶対に交差しない」ための構造的防衛（二重防御）。

#### `ScenarioService::save()` のトランザクション手順

1. `$project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail()`
   — VideoManual 行ロックで manual 単位に直列化（親 relation 経由再解決で「子は親に属する」も維持）
2. **状態 guard**: `status=rendering` は 409（進行中レンダへの編集混入禁止）。
   `status=analyzing` も 409（AI 解析 job が完了時に Cut 群を materialize するため、
   並走編集は clobber される。§10.8-6 の趣旨を解析にも適用する本設計判断）
3. **バージョン照合**: `scenario_version !== expected_version` なら 409（保存しない）
4. **reconcile（2 段階 + 削除）**: 新規 step 配下の新規 point の親 id 払い出し順序を確定させる:
   - **段階 1 — step 群の確定**: 表示順に、既存 id は update（既存 type=step と位置の一致を
     検証済み）、id=null は relation 経由 create。ここで全 step の id が確定する
   - **段階 2 — point 群の確定**: 各 step 配下の points を表示順に update / create。
     `parent_cut_id` は段階 1 で確定した親 step の id を forceFill（サーバ導出）
   - **段階 3 — 削除**: payload に現れなかった既存 cut を delete（配下 Take は FK cascade で
     消える。re-parent 確定後に削除するので `parent_cut_id` nullOnDelete が残存行を汚さない）
   - `sort_order` は各段階で親スコープ内 0..N-1 を forceFill（gap 除去）
5. **成功時**: `scenario_version += 1`（§10.8-2 の確定契約どおり成功保存は常に +1）。
   **実変更**（create/delete の発生、既存 cut の `isDirty()`、`sort_order`/`parent_cut_id` の変更）
   があり `status=published` なら `ready` へ戻す（完成動画は要再合成）。実変更のない no-op 保存は
   published を維持する（正規化差分で不用意に巻き戻さない。判定はサーバ導出値込みの
   Eloquent dirty 検査 = 意味差分）

### 4. レスポンス形式（409 契約と Inertia の両立）

doc/10 §10.8-2 は「version 不一致 → **409 Conflict**、UI は差分再取得を促す」を確定契約とする。
Inertia のフォーム送信は 422/redirect 前提で 409 を表現できない（非 Inertia 応答はモーダル化）。
よって **PUT .../scenario は同一オリジン XHR（web guard・セッション + CSRF）で、応答は
JsonResource** とする:

- 成功: `ScenarioResource`（新 `scenario_version` + 保存後の cuts ツリー = クライアントが id を
  取り込み再編集を継続できる）
- 409: conflict 種別を **PHP backed enum（`ScenarioConflictType`: `version_mismatch` /
  `rendering` / `analyzing`）+ TS discriminated union** で判別可能にした JSON
  （既存の `RecentAuthRequiredDto::CODE` 409 契約と同じ「code 厳格一致」方式。
  JsonResource 経由で `response()->json()` 直書き禁止に従う）
- 422: バリデーション（Laravel 標準の JSON エラー形式）
- 404: 異物 cut id / cross-org / cross-project（存在を漏らさない）

**shape の一元化（PHPStan level 10）**: edit 画面の Inertia props（cuts ツリー）と保存成功応答は
同一の `ScenarioDocumentData` DTO（steps→points のネスト構造 + scenario_version）から生成し、
shape を 1 箇所で固定する。TS 側 interface（`types/manual.ts`）はこの DTO と対で保守。

`response()->json()` 直書き禁止は遵守（DTO/JsonResource 経由）。認可は `Gate::authorize('update', $manual)`
（編集者 = project_admin / org 管理者のみ。撮影者 project_member は read のみ — 既存
`VideoManualPolicy::update` の親委譲をそのまま使い、新 ability は増やさない）。

### 5. フロントエンド（Svelte 5 runes）

- `Manuals/Edit.svelte` をシナリオ編集画面に拡張（doc/10 §10.3 の「GET .../edit = シナリオ編集画面」
  に一致）。メタ編集フォーム（既存 PATCH）とシナリオエディタは**保存単位を完全分離**して同居:
  「基本情報を保存」（メタ）と「シナリオを更新」（document PUT）を独立セクション・独立ボタン・
  独立 dirty 判定で表示し、保存単位の混同を防ぐ。
- Cut ツリー編集 UI は `components/features/manual/ScenarioEditor.svelte`（features/{domain} 層）:
  - props の cuts から**作業コピー**（`$state`）を生成し、行追加（step/point）・削除
    （step 削除で配下 point も削除 + 確認ダイアログ）・▲▼ 並べ替え（同一スコープ内のみ）・
    本文セル編集をクライアント state で行う
  - **空シナリオの初期導線**: cuts が空（自作シナリオ経路）のときは EmptyState +
    「最初の手順を追加」ボタンを表示（doc/04「自作シナリオ」ユースケースの成立点）
  - dirty 判定 → 未保存離脱警告（beforeunload + Inertia before イベント）
  - 「シナリオを更新」で fetch PUT（`X-XSRF-TOKEN` 付与）。**409 時はローカル作業コピーを
    破棄しない**: conflict バナー（種別ごとの文言）を表示し、「サーバの最新を取得」は
    確認ダイアログで編集内容の破棄を明示同意させてから reload する（黙って編集内容を失わない）。
    422 は行別エラー表示
  - ボタン disabled 禁止（rendering 中などは押下時にサーバ 409 → エラー表示）。DS token のみ、
    アイコンは `@lucide/svelte`（Plus / Trash2 / ChevronUp / ChevronDown 等）
- Inertia props は typed array（PHP 側 PHPDoc）+ TS interface（`types/manual.ts` に
  `ScenarioStep` / `ScenarioPoint` / status 等を追加）で対保守。
- **v1 スコープ**: 並べ替えは ▲▼ ボタン（D&D は後続）、Undo/Redo なし
  （doc/09 §9.9 フェーズ計画「Undo/Redo なしの素の編集から」に一致）。

## 期待効果

- **使命への貢献**: AI 生成シナリオ（後続フェーズ）を**業務投入可能な品質まで短時間で整える
  編集基盤**が成立する。台本作成ハードルの完全な「思考ゼロ」化は AI 解析との合わせ技で達成する
  もので、本フィーチャ単体の主張は「破綻なく仕上げる器」に留める。後続の AI 解析・撮影 PWA・
  レンダはすべてこの Cut 編集基盤の上に載る（AI 生成は本保存経路と同じ materialize 規約に合流）。
  成功指標: シナリオ編集の完了（保存成功）率・競合による編集内容喪失ゼロ。
- **安全性**: 楽観ロック（409）で後勝ち破壊を防止、protected キー不信・照合 id 404・
  レンダ中保存禁止をセキュリティ不変条件と同じ機械強制（Architecture/Feature テスト）に載せる。
- **具体的改善**: 編集者が空シナリオ（自作シナリオ経路）からでも手動でマニュアル動画の
  台本を組み立て可能になる（doc/04「自作シナリオ」ユースケースの成立）。

## 実装方針（概要）

| レイヤ | 変更 |
|---|---|
| routes/web.php | `PUT .../manuals/{manual}/scenario`（scopeBindings グループ内） |
| Controller | `Projects\ManualScenarioController::update`（薄い: guard → authorize → Service → Resource）。`VideoManualController::edit` に cuts / scenario_version props 追加 |
| FormRequest | `UpdateScenarioRequest`（ProhibitsProtectedKeys + ネスト保護キー missing + 本文検証 + expected_version 必須 + 階層/型変更禁止の検証） |
| Service | `Manual\ScenarioService::save()`（行ロック → 状態/version guard → 2 段階 reconcile → version+1 → 実変更時のみ published→ready） |
| DTO/Resource | `ScenarioDocumentData` DTO（edit props / 保存成功応答の共通 shape）+ `ScenarioResource` + `ScenarioConflictType` enum + 409 conflict resource |
| Policy | 追加なし（`VideoManualPolicy::update` を再利用） |
| Svelte | `Manuals/Edit.svelte` 拡張 + `features/manual/ScenarioEditor.svelte` 新設 + `types/manual.ts` 型追加 + `lib/csrf.ts` 抽出（RecentAuthModal の局所 csrfToken を共通化） |
| テスト | **テストファースト（fail を確認してから実装）**。Feature（楽観ロック 409 / rendering・analyzing 409 / protected キー 422 / 異物 id 404 / 階層変更 422 / published→ready と no-op 維持 / 権限 403 / 並べ替え / ネスト materialize）+ `NestedRouteIdorDefenseTest` 登録（`projects.manuals.scenario.update` 追加。GET `projects.manuals.edit` は登録済みを確認）+ Service 境界テスト + Vitest |
| ドキュメント | `docs/template-divergence.md` に document 保存の逸脱登録、`docs/architecture.md` にシナリオ整合の共有ロック規約を記録 |

## 制約・前提

- 既存フェーズ1規約を踏襲: Project/Manual 行ロック直列化、relation 経由 create、保護キーは
  forceFill 明示代入、`EnsureProjectBelongsToRouteOrganization` + inline guard の 2 層 404、
  Policy 親委譲、`declare(strict_types=1)` + 日本語コメント、PHPStan level 10、Pest +
  RefreshDatabase（グローバル）、DS token / atomic import 階層。
- `cuts` スキーマ・`Cut` Model・enum（CutType/ShotType/MaterialType）は既存のまま変更しない。
  `scenario_version` カラムも既存（マイグレーション追加なし）。
- 新規テーブルなし・新規 Model なし（Factory 追加不要。CutFactory は既存）。

## スコープ外（後続フェーズ）

- AI 解析による自動生成（本フィーチャは手動編集/保存の器）
- テイク採用 API（`adopted_take_id` の変更経路）・テイク列の表示/選択 UI
- レンダ / プレビュー / 別名保存（複製）/ 多言語シナリオ
- 撮影 PWA
- D&D 並べ替え・Undo/Redo（v1 は ▲▼ ボタンの素の編集）
