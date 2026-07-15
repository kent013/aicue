【アプリの使命 (North Star) — AGENTS.md より】

AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より (設計判断に直結する核)】
1. テストなしの実装完了報告(不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. response()->json() の直書き(DTO / JsonResource / Inertia。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI

【セキュリティ不変条件 (アプリ都合で緩めない)】
tenant キー不信 / 子は親に属する (認可より前に 404) / cross-org 不可 / untrusted 文字列は UserInput 型経由 / 権限判定は laratrust_team_id 明示 / PII は CipherSweet / 課金の冪等性 / 外部 URL 取得は SSRF 検査経由。

【思考原則 — 全議論に適用】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ (Laravel/Svelte エコシステム)。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Web アプリケーション (Laravel + Svelte) の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命 (North Star) に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か (Laravel 12 + Svelte 5 + Inertia.js)
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか (特にセキュリティヘッダの緩和は攻撃面を広げないか)
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下は devnotes/20260715-1122-capture-permissions-policy/conceptual-design.md の内容）

# 概念設計: capture-permissions-policy

## 背景・課題

bug-hunt run 20260715-084108 F-1-04 (High / 実質 Critical)。本番でも撮影カメラをブロックする設定バグ。

### 事実 (コードで確定)
- config/security.php の permissions_policy 既定値:
  `geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")`
- app/Http/Middleware/SecurityHeaders.php はこれを全 web レスポンスに常時送出する (.well-known/oauth-* の metadata subset だけ例外)。
- 撮影 PWA は /app/* (ルート group prefix('app')->as('capture.')、routes/web.php L477-505) で、この group も web middleware group 内にあり SecurityHeaders が適用される。
- Permissions-Policy の camera=() は空 allowlist = self すら不許可。したがって同一オリジンの実カメラでも getUserMedia({video}) がブラウザの Permissions-Policy 層でブロックされ、撮影 (中核機能) が起動できない。

## 改善アイデア

撮影 PWA (capture ルート = /app/* の web レスポンス) に限り Permissions-Policy の camera / microphone を (self) に緩める。同一オリジンの PWA のみ許可 (v1 = 撮影 = PWA・同一オリジン・セッション認証)。
- capture ルート以外は camera=(), microphone=() を維持。
- payment=(self stripe) / geolocation=() 等の他ディレクティブは capture でも維持。
- CSP・HSTS・X-Frame-Options 等の他ヘッダは不変。

### 実装方針 (概要)
1. config/security.php に capture 専用の Permissions-Policy 値 (capture_permissions_policy) を追加。既定値 `geolocation=(), microphone=(self), camera=(self), payment=(self "https://js.stripe.com")`。env 上書き可 (SECURITY_CAPTURE_PERMISSIONS_POLICY)、null/空文字で非送出の contract を踏襲。
2. SecurityHeaders middleware で、ルート名が capture. で始まるか ($request->route()?->getName()) を判定し、その場合のみ capture 用の値を送る。SecurityHeaders は web group の append に登録され $next($request) 実行後に走る = ルート解決済みのため route 名を安全に参照できる。
3. 分岐のみ追加。null/空 opt-out ロジックは共通のまま。

## 期待効果
- 本番の実機・実カメラで getUserMedia({video}) がブロックされなくなり PWA ナビ撮影 (中核機能) が起動できる。撮影不能という致命障害を解消。
- capture 以外は camera=() 維持で攻撃面を広げない。

## 制約・前提
- v1 スコープ: 撮影は PWA・同一オリジン・セッション認証。(self) で十分。cross-origin allowlist は導入しない。
- 既存 contract (env 駆動、null/空で opt-out) を capture 側でも踏襲。
- route 未解決 (例 /app 配下の 404) では route 名 null → 既定 (厳格) 値にフォールバック = fail-secure。撮影実ページ (capture.manuals.show 等) はマッチ済みルートで route 名を持つため正しく緩和。
- PHPStan L10: config 値は is_string() narrow してから使う (既存 permissions_policy と同じ流儀)。

## スコープ外
- cross-origin での撮影許可。
- CSP / HSTS / その他ヘッダ変更。
- production:preflight による Permissions-Policy 値検証追加。
- microphone の実利用可否そのもの (camera と対で (self) に揃える)。
