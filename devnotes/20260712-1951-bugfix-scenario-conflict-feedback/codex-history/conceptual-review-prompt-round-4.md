Round 3 の指摘 (Warning 1 件 / Suggestion 2 件) にすべて対応しました。

1. [Warning] 改善アイデア F-02 1項の括弧書きを「加えて 2. の focus/scroll により、画面サイズや表示位置に依存しにくい知覚可能性を確保する」へ修正 (設計内部の矛盾を解消)
2. [Suggestion] 期待効果を「保存失敗を見落として離脱することによる編集内容喪失のリスクを下げる」へ正確化
3. [Suggestion] null 先処理 → switch の実装順は詳細設計の変更後コードで明記する

改訂後の概念設計全文を添付します。全体判定をお願いします。

---

## 概念設計 (Round 4 改訂版)
# 概念設計: scenario-conflict-feedback (bug-hunt F-02 / F-05 対応)

## 背景・課題

bug-hunt 2 回目走行 (`devnotes/20260712-191243-bug-hunt/shard-0/shard-report.md`) の finding 対応。

### F-02 (High): シナリオ保存 409 のフィードバックがユーザーに知覚されない

bug-hunt 報告: 「`PUT .../scenario => 409 Conflict`。console にエラーが出るのみで、画面上は
『未保存の変更があります』のまま変化なし。差分再取得を促すダイアログ/バナー等は一切表示されない」
(解析中ロック / 2 タブ楽観ロック競合の両パターンで再現)。

**コード調査の結果 (重要な前提)**: T002 (`ScenarioEditor.svelte`) は 409 ハンドラを既に実装済みである。

- `handleResponse()` は 409 + `code === "scenario_conflict"` で `conflict` state を立て、
  `Alert type="warning"` バナー (testId `scenario-conflict-banner`) に理由メッセージ
  (サーバ供給の `ScenarioConflictType::message()`) を表示する
- `version_mismatch` の場合は「サーバの最新を取得」CTA → ConfirmDialog → `router.reload` →
  `reseed()` (作業コピーのサーバ最新置換) まで実装済み
- 既存 Vitest 25 件 (409 バナー表示・reseed・無限 409 ループ防止を含む) は全て green

にもかかわらず bug-hunt (実ブラウザ・実ビルド) が「何も表示されない」と観測した原因は、
**機能の欠落ではなく知覚可能性 (perceivability) の欠落**と特定した:

1. **バナーの挿入位置がビューポート外**: 競合バナー / 汎用エラーはシナリオセクションの
   **最上部** (L485-508) に挿入されるが、「シナリオを更新」ボタンはフォームの**最下部**にある。
   手順 1 件でもフォームは約 800px 以上 (7 フィールド × step + points) あり、編集ページでは
   さらに上に「基本情報」カード (約 450px) が乗る。ボタン押下時のビューポート (720p) から
   バナーは確実に画面外で、スクロール誘導・フォーカス移動・トーストが一切ないため、
   ユーザー視点 (およびボタン付近のスクリーンショット) では「何も起きない」に見える
2. **成功と失敗の非対称**: 保存成功は `addToast("success", "シナリオを保存しました")` で
   ビューポート非依存のトーストが出るが、失敗 (409/422/403/汎用) はトーストなし
3. **403 の理由不明**: `handleResponse()` に 403 分岐がなく、セッション途中の権限剥奪などは
   汎用「保存に失敗しました。時間をおいて再度お試しください」に落ちる (誤誘導)

### F-05 (Low): 動画マニュアル関連画面の `<title>` が "AI-CUE" のみ

タイトルの単一経路は `SeoManager::resolveDocumentTitle()` (Blade `<title>` と Inertia 共有 prop
`title` が共有。SPA 遷移は `resources/js/lib/document-title.ts` が追従)。private 画面は
`config('seo.app_titles')[route]` または controller の `setPrivateTitle()` (動的固有名。
`projects.show` が参考実装) で固有名を供給するが、**manuals / capture 系 route が
`app_titles` に未登録かつ `setPrivateTitle` も未使用**のため、
`projects.manuals.create` / `projects.manuals.show` / `projects.manuals.edit` /
`capture.manuals.show` の 4 画面がサイト名のみになっている。

## 改善アイデア

**優先度**: F-02 (High) が主目的 (S3 中核ジャーニーのデータロス防止 = North Star 直結)。
F-05 (Low) は「撮影・編集の迷いを減らす補助的改善」として同梱するが、
実装コミットは F-02 / F-05 で分離し、レビューと回帰切り分けを容易にする。

### F-02: 保存失敗フィードバックの知覚可能性を回復する (フロントのみ、保存ロジックは既存維持)

1. **フィードバック表示位置を操作点の直近へ移動**: 競合バナー (`scenario-conflict-banner`) と
   汎用エラー (`scenario-generic-error`) を、セクション最上部から
   **「シナリオを更新」ボタン直上**に移設する。押下地点の近傍に表示され、フォーム長に
   依存しない (form error summary をアクション行に隣接させるパターン)。加えて 2. の
   focus/scroll により、画面サイズや表示位置に依存しにくい知覚可能性を確保する
2. **フォーカス移動 + scrollIntoView**: 保存失敗の状態確定後、`await tick()` で DOM 反映を
   待ってからアラート wrapper (`tabindex="-1"`) へ **`focus({ preventScroll: true })` →
   `scrollIntoView({ block: "nearest" })` の順**で呼ぶ (focus 既定のスクロールを抑止し、
   スクロール制御を scrollIntoView に一本化する。Vitest は呼び出し順も検証する)。
   発火は「保存試行が失敗で完了した時」(全 kind 共通の単一処理) の明示呼び出しに限定
   ($effect での state 監視にしない = 同一エラーの再描画・無関係な再レンダで再発火しない。
   `block: "nearest"` により完全可視ならスクロールは原則発生せず、連続失敗時のジャンプを
   起こしにくい)。支援技術には role=status/alert の aria-live に加えフォーカス移動で確実に
   通知され、視覚的にもボタンより下にはみ出すケースを救済する
3. **失敗フィードバック state の判別可能 union 化 + 網羅性の型固定**: `conflict` /
   `genericError` の 2 本の state を `saveFailure: { kind: "conflict"; body:
   ScenarioConflictBody } | { kind: "forbidden" } | { kind: "generic"; message: string } |
   null` の discriminated union に統合する。テンプレートの `{#if}` 分岐に直接依存させず、
   **表示モデル (アラート type / タイトル / 本文 / CTA 有無) を `$derived` の
   `switch (saveFailure.kind)` で導出し、`default` で `assertNever(saveFailure)`** して
   kind 追加時の表示漏れをコンパイルエラーに固定する
   (`ScenarioConflictBody`・409 応答 shape は既存のまま据え置き)
4. **403 分岐の追加**: `handleResponse()` に 403 (`kind: "forbidden"`) を追加し
   「この操作を行う権限がありません。ページを再読み込みして状態を確認してください。」を
   表示する (v1 の導線は再読み込み案内に留めるが、union の分岐として独立させるため
   将来の再ログイン導線追加が局所変更で済む)。analyzing/rendering ロックは 409 で
   サーバ供給メッセージが既に理由明示済み。バナータイトル・CTA (`version_mismatch` のみ
   再取得導線) の既存構造は変更しない

トーストの追加は**行わない**: 失敗は「持続表示 + 理由 + 再取得 CTA」が必要で、
バナーが操作点直近に出る以上トーストは冗長。error トーストは自動消去されず
再試行のたびに堆積する管理問題もある (成功=トースト/失敗=インライン持続表示、で役割分担)。

禁止事項 8 (disabled 禁止) は既存実装が準拠済み (`save()` 冒頭の `if (saving) return` ガード、
ボタンは押下可能のまま)。本設計でも変更しない。

### F-05: 4 画面へ固有タイトルを供給する (既存 SeoManager 経路に乗る)

1. `config/seo.php` の `app_titles` に静的固有名を追加:
   - `'projects.manuals.create' => '動画マニュアルの作成'` (作成フォームは対象実体が
     未存在のため静的で十分)
2. 動的固有名は `projects.show` の参考実装 (`setPrivateTitle`) を踏襲:
   - `VideoManualController::show()` → `$seo->setPrivateTitle($manual->title)`
   - `VideoManualController::edit()` → `$seo->setPrivateTitle($manual->title.' の編集')`
     (静的「動画マニュアルの編集」では複数 manual を並行編集するタブの判別ができないため
     動的に寄せる。`title` カラムは NOT NULL・必須バリデーション済みで null 安全)
   - `CaptureManualController::show()` → `$seo->setPrivateTitle($manual->title.' の撮影')`
     (撮影 PWA であることをタブ上で判別可能にする)

## 期待効果

- **使命への貢献**: シナリオ編集は「AI が設計した台本を現場が確定させる」中核ジャーニー (S3)。
  保存失敗を見落として離脱することによる編集内容喪失のリスクを下げ、
  「思考ゼロ」で使える信頼性を回復する
- F-02: 409 (解析中ロック / 楽観ロック競合) 時に、理由と再取得導線を**操作点近傍に表示し、
  フォーカスおよび必要最小限のスクロールによって知覚可能性を高める**。
  story S3 の期待「409 は差分再取得を促す」を実ブラウザでも満たす
- F-05: 4 画面のタブ/履歴/ブックマーク判別性が他画面と揃う

## 実装方針（概要）

| 対象 | 変更 |
|------|------|
| `resources/js/components/features/manual/ScenarioEditor.svelte` | アラート 2 種をアクション行直上へ移設 + tick 後 focus/scrollIntoView + 失敗 state の union 化 + 403 分岐 |
| `config/seo.php` | `app_titles` に `projects.manuals.create` を追加 |
| `app/Http/Controllers/Projects/VideoManualController.php` | `show()` / `edit()` に `setPrivateTitle` (動的固有名) |
| `app/Http/Controllers/Capture/CaptureManualController.php` | `show()` に `setPrivateTitle(...' の撮影')` |
| `tests/js/components/features/manual/ScenarioEditor.test.ts` | 追加: 全 kind (conflict/forbidden/generic) の表示網羅 + 各分岐でのフォーカス/スクロール (focus→scrollIntoView の呼び出し順含む)、analyzing 理由文言、403 文言。既存 25 件は testId 不変で維持 |
| `tests/Feature/Projects/ManualPageTitleTest.php` (新規) | 4 画面の `<title>` / Inertia 共有 prop `title` 検証 (SeoHeadCompositionTest のパターン) |

- バックエンドの保存ロジック (`ScenarioService` / `ScenarioConflictException` / 409 契約) は無変更
- 409 応答 shape (`{code, conflict_type, message, current_version}`)・TS 型 (`ScenarioConflictBody`)
  も無変更 (波及なし)

## 制約・前提

- DS token / Alert・Button atom / Lucide のみ使用 (ds-purity・svg-inline-allowlist 準拠)。
  atomic 階層も現状 (features/manual 内で atoms/molecules/organisms を組む) を維持
- `SeoManager` は request-scoped 束縛。`setPrivateTitle` は noindex を維持したままタイトルのみ
  上書きする既存契約 (SeoManagerTest / SeoHeadCompositionTest が固定)
- 既存テスト (ScenarioEditor.test.ts 25 件 / ScenarioUpdateTest / SeoHeadCompositionTest) を
  壊さない。testId・409 契約・reseed 挙動は不変

## スコープ外

- 保存ロジック / 楽観ロック機構そのもの (T002 のまま)
- 409 時の自動マージ・差分表示 UI (v1 は「破棄して最新を取得」の明示同意リロードまで)
- 422 行別エラーの表示位置改善 (行内表示は既存。必要なら別 finding として扱う)
- F-01 (queue worker) / F-03 (カメラフォールバック) / F-04 (seeder) など他 finding
- capture.manuals.index など 4 画面以外のタイトル整備 (finding 対象外)
