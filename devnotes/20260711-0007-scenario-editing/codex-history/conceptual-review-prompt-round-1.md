# 使命・禁止事項・セキュリティ不変条件（AGENTS.md より）

## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件（アプリ都合で緩めない）

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない(`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**(`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【前提資料（必要ならファイル読み込み可）】
- 確定仕様: /workspace/doc/10_実装仕様.md（特に §10.3 / §10.8-2 / §10.8-5 / §10.8-6）
- 方式設計: /workspace/doc/09_詳細実装設計.md §9.4（シナリオ編集の document 単位保存 ★divergence）
- UI 仕様: /workspace/doc/04_PCサイト機能仕様.md（動画シナリオ画面 / シナリオ編集画面）
- 既存見本: /workspace/app/Services/Manual/{CategoryService,VideoManualService}.php,
  /workspace/app/Http/Controllers/Projects/VideoManualController.php,
  /workspace/app/Models/{VideoManual,Cut}.php, /workspace/routes/web.php,
  /workspace/tests/Architecture/NestedRouteIdorDefenseTest.php

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: scenario-editing（シナリオ編集: document 一括保存・楽観ロック）

作成: 2026-07-11 / 対象アプリ: AI-CUE (/workspace)

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

### 3. 保存の並行制御（§10.8-2 楽観ロック + §10.8-6 レンダ中禁止）

`ScenarioService::save()` が 1 トランザクションで:

1. `$project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail()`
   — VideoManual 行ロックで manual 単位に直列化（親 relation 経由再解決で「子は親に属する」も維持）
2. **状態 guard**: `status=rendering` は 409（進行中レンダへの編集混入禁止）。
   `status=analyzing` も 409（AI 解析 job が完了時に Cut 群を materialize するため、
   並走編集は clobber される。§10.8-6 の趣旨を解析にも適用する本設計判断）
3. **バージョン照合**: `scenario_version !== expected_version` なら 409（保存しない）
4. **reconcile**: 既存 id は update、id=null は relation 経由 create、payload に無い既存 cut は
   delete（配下 Take は FK cascade で消える）。`parent_cut_id` / `sort_order` / `type` は
   forceFill でサーバ導出値を明示代入。更新→作成→削除の順（re-parent を先に確定してから削除し、
   `parent_cut_id` の nullOnDelete が残存行を汚さないように）
5. **成功時**: `scenario_version += 1`。cut 集合に実変更があり `status=published` なら
   `ready` へ戻す（完成動画は要再合成）

## 4. レスポンス形式（409 契約と Inertia の両立）

doc/10 §10.8-2 は「version 不一致 → **409 Conflict**、UI は差分再取得を促す」を確定契約とする。
Inertia のフォーム送信は 422/redirect 前提で 409 を表現できない（非 Inertia 応答はモーダル化）。
よって **PUT .../scenario は同一オリジン XHR（web guard・セッション + CSRF）で、応答は
JsonResource** とする:

- 成功: `ScenarioResource`（新 `scenario_version` + 保存後の cuts ツリー = クライアントが id を
  取り込み再編集を継続できる）
- 409: conflict 種別（`version_mismatch` / `rendering` / `analyzing`）を持つ JSON
  （JsonResource 経由。`response()->json()` 直書き禁止に従う）
- 422: バリデーション（Laravel 標準の JSON エラー形式）
- 404: 異物 cut id / cross-org / cross-project（存在を漏らさない）

`response()->json()` 直書き禁止は遵守（DTO/JsonResource 経由）。認可は `Gate::authorize('update', $manual)`
（編集者 = project_admin / org 管理者のみ。撮影者 project_member は read のみ — 既存
`VideoManualPolicy::update` の親委譲をそのまま使い、新 ability は増やさない）。

### 5. フロントエンド（Svelte 5 runes）

- `Manuals/Edit.svelte` をシナリオ編集画面に拡張（doc/10 §10.3 の「GET .../edit = シナリオ編集画面」
  に一致）。メタ編集フォーム（既存 PATCH）とシナリオエディタは独立した保存単位として同居。
- Cut ツリー編集 UI は `components/features/manual/ScenarioEditor.svelte`（features/{domain} 層）:
  - props の cuts から**作業コピー**（`$state`）を生成し、行追加（step/point）・削除
    （step 削除で配下 point も削除 + 確認ダイアログ）・▲▼ 並べ替え・本文セル編集を
    クライアント state で行う
  - dirty 判定 → 未保存離脱警告（beforeunload + Inertia before イベント）
  - 「更新する」で fetch PUT（`X-XSRF-TOKEN` 付与）。409 は「他の編集と競合しました。
    再読み込みしてください」バナー + 再読み込みアクション、422 は行別エラー表示
  - ボタン disabled 禁止（rendering 中などは押下時にサーバ 409 → エラー表示）。DS token のみ、
    アイコンは `@lucide/svelte`（Plus / Trash2 / ChevronUp / ChevronDown 等）
- Inertia props は typed array（PHP 側 PHPDoc）+ TS interface（`types/manual.ts` に
  `ScenarioStep` / `ScenarioPoint` / status 等を追加）で対保守。
- **v1 スコープ**: 並べ替えは ▲▼ ボタン（D&D は後続）、Undo/Redo なし
  （doc/09 §9.9 フェーズ計画「Undo/Redo なしの素の編集から」に一致）。

## 期待効果

- **使命への貢献**: 「AI が設計したシナリオを現場の編集者が仕上げる」編集ループが成立し、
  台本作成ハードルの肩代わり（思考ゼロ）に直結する。後続の AI 解析・撮影 PWA・レンダは
  すべてこの Cut 編集基盤の上に載る（AI 生成は本保存経路と同じ materialize 規約に合流）。
- **安全性**: 楽観ロック（409）で後勝ち破壊を防止、protected キー不信・照合 id 404・
  レンダ中保存禁止をセキュリティ不変条件と同じ機械強制（Architecture/Feature テスト）に載せる。
- **具体的改善**: 編集者が空シナリオ（自作シナリオ経路）からでも手動でマニュアル動画の
  台本を組み立て可能になる（doc/04「自作シナリオ」ユースケースの成立）。

## 実装方針（概要）

| レイヤ | 変更 |
|---|---|
| routes/web.php | `PUT .../manuals/{manual}/scenario`（scopeBindings グループ内） |
| Controller | `Projects\ManualScenarioController::update`（薄い: guard → authorize → Service → Resource）。`VideoManualController::edit` に cuts / scenario_version props 追加 |
| FormRequest | `UpdateScenarioRequest`（ProhibitsProtectedKeys + ネスト保護キー missing + 本文検証 + expected_version 必須） |
| Service | `Manual\ScenarioService::save()`（行ロック → 状態/version guard → reconcile → version+1 → published→ready） |
| DTO/Resource | 保存結果の `ScenarioResource`・409 conflict resource。edit props 用の typed array 生成 |
| Policy | 追加なし（`VideoManualPolicy::update` を再利用） |
| Svelte | `Manuals/Edit.svelte` 拡張 + `features/manual/ScenarioEditor.svelte` 新設 + `types/manual.ts` 型追加 |
| テスト | Feature（楽観ロック 409 / rendering 409 / protected キー 422 / 異物 id 404 / published→ready / 権限 403 / 並べ替え / ネスト materialize）+ `NestedRouteIdorDefenseTest` 登録 + Vitest |
| ドキュメント | `docs/template-divergence.md` に document 保存の逸脱登録、`docs/architecture.md` 追記 |

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
