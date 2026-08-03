# システム指示

## アプリの使命（North Star）— AGENTS.md より

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 — AGENTS.md より

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

## 役割

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

【補足コンテキスト】
- 本件は bug-hunt finding F-11 (High) のバグ修正設計。スコープは「/user/confirm-password 直アクセスの 500 修正」のみに意図的に絞っている。
- 参照可能ファイル: /workspace/app/Providers/FortifyServiceProvider.php, /workspace/app/Http/Middleware/RequireRecentAuth.php, /workspace/app/Http/Controllers/Auth/ConfirmRecentAuthController.php, /workspace/config/fortify.php, /workspace/vendor/laravel/fortify/routes/routes.php, /workspace/vendor/laravel/fortify/src/Http/Responses/SimpleViewResponse.php, /workspace/tests/Feature/Auth/RecentAuthTest.php

---

## 概念設計

# 概念設計: bugfix-auth-confirm-password-500 (F-11)

## 背景・課題

bug-hunt (devnotes/20260712-075854-bug-hunt/shard-0/shard-report.md F-11, severity High) で、
認証済みユーザーが `GET /user/confirm-password` (Fortify の `password.confirm` ルート) に
直接アクセスすると 500 Internal Server Error になることが発見された。

### 根本原因 (再現・特定済み)

shard-report の推定 (「intended URL 等のセッション値未設定によるクラッシュ」) は**誤り**で、
実際はセッション状態に依存しない決定的なクラッシュである:

1. 本アプリは Fortify 生の step-up (`password.confirm`、password 限定・3h 窓) を廃し、
   generic recent-auth (15 分窓・password or 再SSO、`/recent-auth/confirm` +
   `RequireRecentAuth` middleware) に統一している
   (`config/fortify.php` L152-162、`app/Providers/FortifyServiceProvider.php` L107-108)。
   このため `Fortify::confirmPasswordView()` を意図的に登録していない。
2. しかし Fortify は `config('fortify.views') === true` の場合、feature フラグ
   (`twoFactorAuthentication.confirmPassword => false`) に**関係なく**
   `GET /user/confirm-password` (`password.confirm`) を無条件登録する
   (`vendor/laravel/fortify/routes/routes.php` L118-121。この feature フラグは
   2FA 管理ルートへの `password.confirm` middleware 適用可否のみを制御する)。
3. `ConfirmablePasswordController::show()` は `app(ConfirmPasswordViewResponse::class)` を
   解決するが、この contract は `Fortify::confirmPasswordView()` を呼んだときにのみ
   bind される (Fortify の `registerResponseBindings()` に default binding が**ない**)。
4. 結果、`BindingResolutionException: Target [Laravel\Fortify\Contracts\ConfirmPasswordViewResponse]
   is not instantiable` → 500。tinker での contract 解決により実挙動を確認済み。

## 改善アイデア

Fortify の公式拡張点 `Fortify::confirmPasswordView()` に **redirect を返す closure** を登録し、
`GET /user/confirm-password` への直アクセスを正規の step-up 画面
`route('recent-auth.confirm')` へ 302 で誘導する。

`Fortify::confirmPasswordView()` は callable を受け取れ、`SimpleViewResponse::toResponse()` は
callable の戻り値が Response ならそのまま返す (vendor 実装確認済み) ため、
`static fn (): RedirectResponse => redirect()->route('recent-auth.confirm')` の 1 行で成立する。

変更箇所は `app/Providers/FortifyServiceProvider.php` の `configureViews()`
(現在「confirmPasswordView は登録しない」とコメントしている箇所) のみ。

### 代替案と不採用理由

- **案B: この URL で Auth/ConfirmRecentAuth を直接 Inertia render する**
  → 同一画面が 2 URL に重複し、canonical URL (recent-auth/confirm) が曖昧になる。
  intended 復帰や画面仕様の変更時に 2 経路の追従が必要になり、思考原則 3
  (後方互換の並走を残さない) に反する。不採用。
- **案C: `Fortify::ignoreRoutes()` + 手動ルート再登録で route 自体を消す**
  → Fortify 登録ルート全体の再配線が必要でオーバーエンジニアリング
  (思考原則 2)。また既存の `password.confirm.store` (POST) / `password.confirmation`
  (GET status) の扱いも巻き込み、スコープが膨らむ。不採用。

## 期待効果

- 機微操作の再認証というセキュリティ中核導線から生エラー (500) を排除 (F-11 解消)。
  「専門知識ゼロの現場作業者でも使える」という使命に照らし、詰み画面をなくす。
- 何らかの経路 (ブックマーク・外部テンプレ由来のリンク・Laravel 標準の
  `password.confirm` middleware を将来使うコード) で `/user/confirm-password` に到達しても、
  ユーザーは正規の再認証画面 (password or 再SSO) に到達でき、SSO-only ユーザーも詰まない。

## 実装方針（概要）

1. **再現テスト先行** (テストファースト): `tests/Feature/Auth/RecentAuthTest.php` に
   「認証済みユーザーの `GET /user/confirm-password` が 500 にならず
   `recent-auth.confirm` へ 302 → 追従して 200 で Auth/ConfirmRecentAuth フォームが出る」
   テストを追加し、fail (500) を確認。
2. `FortifyServiceProvider::configureViews()` に
   `Fortify::confirmPasswordView(static fn (): RedirectResponse => redirect()->route('recent-auth.confirm'));`
   を追加 (既存コメントを「redirect 登録済み」の説明に更新)。
3. 既存の recent-auth / Fortify 系テスト (`RecentAuthTest` / `FortifyResponseTest` 等) が
   green のままであることを確認。

## 制約・前提

- Fortify の response contract 差し替えは既に本 Provider で多数実施しているパターンで、
  アーキテクチャと整合する (フレームワークのレンジ内)。
- `redirect()->route()` は closure 内でリクエスト時に評価されるため、boot 時の route 未解決問題はない。
- 直アクセス時は `url.intended` が未設定のため、再認証完了後は dashboard へ遷移する
  (`ConfirmRecentAuthController::confirmPassword()` の `redirect()->intended(route('dashboard'))`)。
  これは既存契約どおりで新規実装は不要。
- PHPStan level 10: closure に戻り値型 `RedirectResponse` を明示。

## スコープ外

- `POST /user/confirm-password` (`password.confirm.store`) と
  `GET /user/confirmed-password-status` (`password.confirmation`) の扱い。
  これらは 500 にならず (POST は password 検証のうえ Fortify 独自の
  `auth.password_confirmed_at` を stamp するのみで、本アプリの gate である
  `recent_auth_at` には影響しない)、本アプリのどのルートも `password.confirm`
  middleware を使っていないため実害がない。config/fortify.php の既存 TODO(template)
  (Fortify 2FA 管理ルートへの recent-auth 後付け配線) と併せて別タスクで棚卸しする。
- F-11 の「実際の詰みトリガー導線」(F-12 オーナー移譲 UI 欠落等) は別 finding。
- bughunt 環境固有の問題 (F-13 等) への対応。
