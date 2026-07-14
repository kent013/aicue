【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。方向性が正しいと確認できてから調整せよ。

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
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【補足コンテキスト（既存実装）】
- `SeoManager::resolvePrivateTitle(?string $routeName)`: `$this->privateTitle` (setPrivateTitle 動的上書き) を最優先し、なければ `config('seo.app_titles')[$routeName] ?? null` を返す。
- `SeoManager::resolveDocumentTitle()`: 上記固有名を `SeoTitle::compose()` で `{固有名}{separator}{site_name}` に合成 (未登録は null → サイト名のみ)。noindex は SeoComposer/SeoRenderer 側で別途維持される。
- 既存テスト `tests/Feature/Seo/SeoManagerTest.php` に「private 経路は app_titles を合成し、未登録 route はサイト名のみ」を検証するケースが既にある。
- 対象 6 ルートはいずれも静的な h1 見出しで足りる (動的固有名 setPrivateTitle は不要) ことを画面確認済み。

---

## 概念設計

# 概念設計: seo-tab-titles

## 背景・課題

bug-hunt finding F-L2 (Low)。config/seo.php の app_titles マップ (認証配下アプリ画面の per-page ブラウザタブ title の route 既定) に未登録の 6 ルートで、`<title>` がサイト名 "AI-CUE" のみになる。結果、これらの画面を開いたブラウザタブ・履歴・ブックマークが全て同一文字列になり、複数タブを開いた作業者・スクリーンリーダー利用者が画面を識別できない (アクセシビリティ / UX 破綻)。

対象ルート (いずれも resolvePrivateTitle が app_titles[route] ?? null で未登録 → compose(null) = サイト名のみ):
- projects.categories.index → カテゴリ管理
- manage.users.index → ユーザー管理
- organizations.api-keys.index → API キー
- organizations.api-keys.sessions.index → 接続セッション
- organizations.onboarding.cli → CLI 導入ガイド
- organizations.onboarding.mcp → MCP 導入ガイド

## 改善アイデア

config/seo.php の app_titles に上記 6 ルートの固有タイトルを追加する。文言は各画面の h1 見出しに一致させ、既存 app_titles の簡潔な名詞スタイル (例: 'ダッシュボード', 'プロジェクト', 'セキュリティ設定') に揃える。追加により resolvePrivateTitle が固有名を返し、`カテゴリ管理 | AI-CUE` のように per-page title が描画される (noindex 維持)。

## 期待効果
- 使命への貢献 (間接): 現場作業者が迷わず操作できる導線の一部。タブ/履歴/スクリーンリーダーで画面識別できることは「思考ゼロ」の運用体験を支える基本的アクセシビリティ。
- 6 ルートで一意なブラウザタブ title 付与、複数タブ運用・履歴・ブックマークで識別可能に。
- スクリーンリーダーが title を読み上げ、視覚障害者の画面識別が可能に。
- 既存の SEO 不変条件 (noindex 維持・canonical/og を漏らさない) は一切変えない。

## 実装方針（概要）
- 単一ファイル変更: config/seo.php の app_titles 配列に 6 エントリ追記のみ。
- ロジック変更なし。SeoManager / SeoComposer / HandleInertiaRequests は既存経路がそのまま固有名を拾う。
- テスト: SeoManager が 6 ルート名で `{固有名} | {site_name}` を返すことを検証する Feature テスト (SeoManagerTest.php に追加、既存書式踏襲)。既存テストは削除・上書きしない。

## 制約・前提
- title 文言は各画面の h1 見出しに一致させる。
- app_titles は route 既定の fallback。対象 6 ルートはいずれも静的見出しで足りるため config 追記で十分 (controller 変更不要)。
- PHPStan L10 / Pest / DTO パターンには無関係 (config 値と assertion のみ、response()->json() 不使用)。

## スコープ外
- 6 ルート以外の app_titles 網羅性監査。
- app_titles 全 route 網羅を強制する drift-guard テストの新設。
- 動的タイトル (setPrivateTitle) を要する画面の見直し。
- title 文言の英語化 / i18n。
