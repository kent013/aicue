【アプリの使命 (North Star) — AGENTS.md より】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

参考: 対象コードは /workspace 配下 (resources/js/components/features/manual/ScenarioEditor.svelte, app/Support/Seo/SeoManager.php, config/seo.php 等) を読み込み可能。

---

## 概念設計
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

### F-02: 保存失敗フィードバックの知覚可能性を回復する (フロントのみ、保存ロジックは既存維持)

1. **フィードバック表示位置を操作点の直近へ移動**: 競合バナー (`scenario-conflict-banner`) と
   汎用エラー (`scenario-generic-error`) を、セクション最上部から
   **「シナリオを更新」ボタン直上**に移設する。押下時のビューポートに必ず入る位置で、
   フォーム長に依存しない (form error summary をアクション行に隣接させるパターン)
2. **フォーカス移動 + scrollIntoView**: 失敗表示時にアラート wrapper (`tabindex="-1"`) へ
   `focus()` + `scrollIntoView()` する。支援技術には role=status/alert の aria-live に加えて
   フォーカス移動で確実に通知され、視覚的にもボタンより下に一部はみ出すケースを救済する
3. **403 分岐の追加**: `handleResponse()` に 403 を追加し
   「この操作を行う権限がありません。ページを再読み込みして状態を確認してください。」を表示する
   (analyzing/rendering ロックは 409 でサーバ供給メッセージが既に理由明示済み。
   バナータイトル・CTA (`version_mismatch` のみ再取得導線) の既存構造は変更しない)

トーストの追加は**行わない**: 失敗は「持続表示 + 理由 + 再取得 CTA」が必要で、
バナーが操作点直近に出る以上トーストは冗長。error トーストは自動消去されず
再試行のたびに堆積する管理問題もある (成功=トースト/失敗=インライン持続表示、で役割分担)。

禁止事項 8 (disabled 禁止) は既存実装が準拠済み (`save()` 冒頭の `if (saving) return` ガード、
ボタンは押下可能のまま)。本設計でも変更しない。

### F-05: 4 画面へ固有タイトルを供給する (既存 SeoManager 経路に乗る)

1. `config/seo.php` の `app_titles` に静的固有名を追加:
   - `'projects.manuals.create' => '動画マニュアルの作成'`
   - `'projects.manuals.edit' => '動画マニュアルの編集'` (projects.edit の静的規約と平仄)
2. 動的固有名は `projects.show` の参考実装 (`setPrivateTitle`) を踏襲:
   - `VideoManualController::show()` → `$seo->setPrivateTitle($manual->title)`
   - `CaptureManualController::show()` → `$seo->setPrivateTitle($manual->title.' の撮影')`
     (撮影 PWA であることをタブ上で判別可能にする)

## 期待効果

- **使命への貢献**: シナリオ編集は「AI が設計した台本を現場が確定させる」中核ジャーニー (S3)。
  保存失敗を知覚できずに編集内容を失う事故 (データロス) を防ぎ、
  「思考ゼロ」で使える信頼性を回復する
- F-02: 409 (解析中ロック / 楽観ロック競合) 時に、理由と再取得導線が**押下地点のビューポート内**
  に必ず表示される。story S3 の期待「409 は差分再取得を促す」を実ブラウザでも満たす
- F-05: 4 画面のタブ/履歴/ブックマーク判別性が他画面と揃う

## 実装方針（概要）

| 対象 | 変更 |
|------|------|
| `resources/js/components/features/manual/ScenarioEditor.svelte` | アラート 2 種をアクション行直上へ移設 + focus/scrollIntoView + 403 分岐 |
| `config/seo.php` | `app_titles` に manuals.create / manuals.edit を追加 |
| `app/Http/Controllers/Projects/VideoManualController.php` | `show()` に `setPrivateTitle($manual->title)` |
| `app/Http/Controllers/Capture/CaptureManualController.php` | `show()` に `setPrivateTitle(...' の撮影')` |
| `tests/js/components/features/manual/ScenarioEditor.test.ts` | 追加: 409 表示位置/フォーカス、analyzing 理由文言、403 文言。既存 25 件は testId 不変で維持 |
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
