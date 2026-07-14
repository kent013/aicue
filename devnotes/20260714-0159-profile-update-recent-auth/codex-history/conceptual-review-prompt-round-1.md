# アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

# セキュリティ不変条件（抜粋・関連）
- tenant キー不信 / 子は親に属する(404 先行) / cross-org 不可 / 権限判定は laratrust_team_id 明示 /
  PII(email/name)は CipherSweet、検索は whereBlind()。

【思考原則 — 全議論に適用】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性
2. 禁止事項違反
3. 実現可能性（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性
5. リスク（重大な副作用・後退）
6. スコープの適切さ
7. 型安全性（DTO/JsonResource、PHPStan level 10）

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【補足コンテキスト（現行コードの要点）】
- `RequireRecentAuth` middleware (alias `recent-auth`): session の `recent_auth_at` が
  `config('auth.recent_auth_timeout')` (既定 900s) 以内かを `RecentAuthWindow::isFresh` で判定。
  stale 時、XHR/Inertia mutation は 409 + `RecentAuthRequiredResource`(JsonResource) + redirect、
  通常遷移は 302 で `recent-auth.confirm` へ (intended 保持)。
- `StampRecentAuthOnLogin` listener: web guard の fresh login のみ `recent_auth_at` を stamp。
  `viaRemember()===true` (remember-me 自動復元) は fail-closed で stamp しない。
- `UpdateUserProfileInformation` action は既にメール変更時に旧アドレスへ
  `EmailChangedSecurityNotification` を送信し `email_verified_at` を null 化済み。
- `FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()` が booted callback で
  Fortify 登録ルート (two-factor 系) に `recent-auth` を後付け append する既存パターンがある。
- `RecentAuthRouteTest` (Architecture) が allowlist の付与漏れを CI で検出。
  `routeHasRecentAuth` は `str_starts_with($middleware, 'recent-auth')` で判定。
- email は CipherSweet 暗号化。`$request->user()->email` は透過復号される。
- client には `resources/js/lib/recent-auth.ts` (`withRecentAuth` precheck) と
  `RecentAuthModal.svelte` があり、account 削除で precheck→モーダル→再開の二層 UX を実装済み。

## 概念設計

# 概念設計: profile-update-recent-auth

## 背景・課題

bug-hunt 回帰 run の finding **F-4-01 (High / authz_bypass、人間トリアージで Critical 格上げ検討)**。
前回修正 **T023 (2FA recent-auth)** の取りこぼし。

`user-profile-information.update` (氏名・メールアドレス変更 / `PUT /user/profile-information`) が
recent-auth (step-up 再認証) で保護されていない。

- `app/Providers/FortifyServiceProvider.php` の `RECENT_AUTH_ROUTE_NAMES` は
  two-factor 系 3 ルートのみ。`user-profile-information.update`
  (`vendor/laravel/fortify/routes/routes.php:105-107`) には recent-auth も
  current_password 確認も無い (対照的に `user-password.update` は `current_password` を要求)。
- fresh credential login は `StampRecentAuthOnLogin` listener が `recent_auth_at` を stamp するが、
  **remember-token 自動再ログイン (`viaRemember()===true`) は fail-closed で stamp しない**。
  つまり stale セッション (remember-me 復元) では `recent_auth_at` が未 stamp。
- 結果: セッション/remember-token を窃取した攻撃者が、**パスワード不知のまま**登録メールアドレスを
  差し替え、その後「パスワードを忘れた」で新アドレスにリセットメールを受信して完全なアカウント乗っ取りが可能。

### 現状で既に手当て済みの部分 (重要)

修正方針 (2)「旧アドレスへの通知」は **既に実装済み**である
(`app/Actions/Fortify/UpdateUserProfileInformation.php`)。
メール変更成功時に旧アドレスへ `EmailChangedSecurityNotification` を on-demand 送信し、
`email_verified_at` を null 化して新アドレスの再検証を要求する。よって本設計の主眼は
**未対応の (1) recent-auth 配線**であり、(2) は既存挙動を回帰テストで固定するに留める
(新アドレス非開示の設計方針は維持。ワンクリック変更取り消しリンクは別スコープ)。

## 改善アイデア

`user-profile-information.update` を recent-auth allowlist に組み込み、
**メールアドレス変更を伴う場合に step-up 再認証を要求**する。

氏名のみの変更 (メールアドレス不変) は乗っ取りベクタではなく、日常的で無害な操作のため、
**条件付き (email 変更時のみ) recent-auth** を採用する。無条件化すると stale セッションでの
氏名変更まで毎回 step-up を要求し、UX を不必要に劣化させるため。

具体的には、既存の generic recent-auth 機構 (`RequireRecentAuth` middleware /
`RecentAuthWindow` / `ConfirmRecentAuthController` / client `recent-auth.ts`) を
**そのまま再利用**し、送信 email が現在の email と異なる場合のみ `RequireRecentAuth` に委譲する
薄い条件付き middleware `RequireRecentAuthOnEmailChange` (alias `recent-auth.on-email-change`) を
新設して `user-profile-information.update` に後付け配線する。

## 期待効果

- **使命への貢献**: セキュリティ不変条件 (認証要素変更前の step-up) を profile 更新経路に拡張し、
  アカウント乗っ取り (メール差し替え→パスワードリセット) の主要ベクタを塞ぐ。使命の前提である
  「現場作業者が安心して使えるアプリ」の信頼基盤を守る。
- **具体的改善**:
  - stale セッション (remember-me 復元) からのメールアドレス変更が step-up 無しでは 409/302 で遮断される。
  - 氏名のみ変更の既存 UX (fresh でなくても即保存) は温存され、後退しない。
  - 既存の旧アドレス通知 + 新アドレス再検証は回帰テストで固定される。

## 実装方針（概要）

1. **条件付き middleware 新設**: `app/Http/Middleware/RequireRecentAuthOnEmailChange.php`。
   `$request->input('email')` が `$request->user()->email` (CipherSweet で透過復号) と一致する場合は
   `$next($request)` で素通し、異なる場合は `app(RequireRecentAuth::class)->handle($request, $next)` に
   委譲する (409/302・intended 保持・dropped_mutation flag 等の既存ロジックを完全再利用)。
   - fail-closed 比較: email が「同一」と判定できる時のみゲートを外す。判定不能・型不正・欠落時は
     ゲートを掛ける方向へ倒す。
   - 送信 email と action 側 (`UpdateUserProfileInformation`) の比較は同一の
     `$request->input('email')` を source とし、判定ドリフト (middleware=同一 / action=変更 の
     bypass) を作らない。
2. **alias 登録**: `bootstrap/app.php` の `$middleware->alias([...])` に
   `'recent-auth.on-email-change' => RequireRecentAuthOnEmailChange::class` を追加。
3. **route 後付け配線**: `FortifyServiceProvider` の booted callback を拡張し、
   `user-profile-information.update` に `recent-auth.on-email-change` を idempotent に append する
   (既存 `attachRecentAuthToSensitiveRoutes` と同じ後付けパターン。Fortify がルートを boot 内で
   登録するため booted で名前解決)。
4. **Architecture テスト更新**: `tests/Architecture/RecentAuthRouteTest.php` の
   `recentAuthRequiredRouteNames()` に `user-profile-information.update` を追加
   (`routeHasRecentAuth` は `str_starts_with($m, 'recent-auth')` で条件付き alias も検出する)。
5. **client 側 UX (Settings/Index.svelte)**: profile 送信を、email が変更されている時のみ
   `guardWithRecentAuth` (既存 `withRecentAuth` precheck) でラップする。氏名のみ変更は従来通り即 put。
   サーバ側 middleware が最終ゲート、client precheck は UX 補助 (account 削除と同じ二層構造)。
6. **回帰テスト**: 既存の旧アドレス通知・新アドレス再検証を Feature テストで固定 (未固定なら新設)。

## 制約・前提

- 既存 recent-auth 機構 (middleware / window / satisfier / client helper) を再利用し、新機軸を作らない
  (AGENTS 思考原則「フレームワークのレンジ内」「今必要なものだけ作る」)。
- email は CipherSweet 暗号化カラム。middleware での現在 email 参照は認証済み `$request->user()->email`
  の透過復号で足りる (blind index 検索は不要)。
- `response()->json()` 直書き禁止 → 委譲先 `RequireRecentAuth` は既に
  `RecentAuthRequiredResource` (JsonResource) を使用。新 middleware は独自 JSON を返さない。
- Inertia PUT (`useForm.put`) は非 GET mutation のため、stale 時は既存ロジックで 409 + redirect JSON
  を返す (Inertia GET の 302 replay とは別扱い。RequireRecentAuth の既存分岐に従う)。
- 認証要素変更後の `RecentAuthState::clear()` (鮮度失効) は今回のゲート追加 (変更「前」の step-up)
  とは別関心のため本スコープ外 (現状も UpdateUserPassword は clear していない。将来別途)。

## スコープ外

- メールアドレス変更のワンクリック取り消し (undo) リンク付き通知 (brief の「可能なら」)。
- 氏名変更への無条件 step-up。
- 新アドレスへの確認リンク方式 (double opt-in) への切り替え。
- `RecentAuthState::clear()` による変更後の鮮度失効。
- 他の Fortify 経路 (`user-password.update` は current_password で既に保護済) の見直し。

