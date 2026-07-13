【アプリの使命（North Star）】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】

1. テストなしの実装完了報告
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

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
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【補足コンテキスト（レビュー材料）】
本 finding は前回修正 T029 (`seo-tab-titles`) の取りこぼしで、T029 は同じ `config/seo.php` の `app_titles` に未登録 6 ルートの固有タイトルを追加した実績がある。現行 `config/seo.php` の `app_titles` には既に `dashboard` / `projects.categories.index` / `manage.users.index` / `organizations.api-keys.index` 等が「h1 見出しと一致させる」方針で登録済み。`SeoManager::resolvePrivateTitle()` は `config('seo.app_titles')[$routeName] ?? null` を返し、`resolveDocumentTitle()` は `SeoTitle::compose()` で `{固有名} | {site_name}` を合成する。`notifications.index` は `/notifications` にマップされ、対応画面 `resources/js/pages/Notifications/Index.svelte` の h1 は「通知」。`SeoManagerTest.php` には未登録 6 ルートを data-driven で固定する既存テストがある。

---

## 概念設計

（以下、conceptual-design.md の全文）

# 概念設計: notifications-tab-title

## 背景・課題

bug-hunt 回帰 run の finding **F-4-02 (Low)** は、前回修正 **T029 (`seo-tab-titles`)** の取りこぼしである。T029 は「認証配下アプリ画面のブラウザタブ title がサイト名のみになる」問題に対し、`config/seo.php` の `app_titles` へ未登録 6 ルートの固有タイトルを補ったが、その対象集合に **`notifications.index` (`/notifications`) を含めていなかった**。

このため、通知一覧画面 (`Notifications/Index.svelte`, h1「通知」) は `app_titles` 未登録のまま残り、`SeoManager::resolvePrivateTitle()` が `null` を返す → `SeoTitle::compose(null)` がサイト名のみを返す → ブラウザタブ title が **「AI-CUE」だけ**になる (画面固有名が出ない)。

- 影響度は Low。noindex のアプリ内画面であり SEO/クローラ影響はなく、機能不全でもない。
- ただし他の全アプリ画面はタブ title に固有名を出しており、通知画面のみ抜けているのは **一貫性の欠落**で、複数タブを開くユーザーの識別性を損なう。

## 改善アイデア

`config/seo.php` の `app_titles` マップに **`notifications.index` の固有タイトルエントリ 1 行**を追加する。文言は既存他ルートのスタイル (画面 h1 見出しと一致させる方針) に合わせ、`Notifications/Index.svelte` の h1「通知」と一致させて **`'notifications.index' => '通知'`** とする。

- 完成タイトルは `SeoTitle::compose('通知')` = **「通知 | AI-CUE」** (site_name は env 由来)。
- 解決経路・優先順位・描画は既存の `SeoManager` / `SeoComposer` / `HandleInertiaRequests` をそのまま使う (新規経路・新規コードなし、config データ 1 行の追加のみ)。

## 期待効果

- **使命への貢献**: 直接の機能価値ではないが、AI-CUE を業務で使う現場管理者が複数タブを開いて運用する際、タブ title で通知画面を識別できる。「思考ゼロ」で迷わず操作できる UX の地盤を、全画面で一貫させる。
- **具体的改善**: `/notifications` のタブ title が「AI-CUE」→「通知 | AI-CUE」になり、T029 が確立した「全アプリ画面が固有タブ title を持つ」不変条件の穴を塞ぐ。
- **回帰防止**: `SeoManagerTest` に `notifications.index` の固有 title を固定するケースを足し、同種の drift (エントリ欠落) を機械的に検出できるようにする。

## 実装方針（概要）

1. `config/seo.php` の `app_titles` 配列に、既存コメント様式に倣って `// 通知一覧 (notifications.index — Notifications/Index.svelte h1「通知」)` の注記付きで `'notifications.index' => '通知'` を追加する。
2. `tests/Feature/Seo/SeoManagerTest.php` に、`resolveDocumentTitle('notifications.index')` が「通知 | Acme」を返し、かつ実 config に固有名エントリが存在することを検証するケースを追加する。

## 制約・前提

- `notifications.index` は `route_classification` の full / minimal / excluded いずれにも属さず、認証配下の private (noindex) 画面である → `app_titles` fallback が正しい解決経路。
- 固有名は静的見出しで足りるため、controller の `setPrivateTitle()` 動的上書きは不要。
- HTTP レスポンス body を新たに作らないため、DTO/JsonResource パターン・`response()->json()` 禁止事項には抵触しない。
- PHPStan level 10: config データ追加のみで型面の変更なし。

## スコープ外

- `notifications.read-all` / `notifications.open` / `notifications.read` は POST 操作エンドポイントで HTML head を持たないため対象外。
- `SeoManager` / `SeoComposer` / `HandleInertiaRequests` のロジック変更・リファクタは行わない。
- 動的タイトル (未読件数のタブ表示等) は行わない。
- 他の未登録アプリ画面の全ルート棚卸しはスコープ外。
