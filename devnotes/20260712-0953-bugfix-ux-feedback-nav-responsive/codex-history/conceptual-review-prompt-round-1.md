# 概念設計レビュー依頼 (Round 1)

## アプリの使命 (North Star) — AGENTS.md より

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

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

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: bugfix-ux-feedback-nav-responsive

bug-hunt (devnotes/20260712-075854-bug-hunt/shard-0/shard-report.md) の Medium finding
F-03 / F-06 / F-08 / F-14 (H13) に対する UX 整備。スコープは UX/表示のみ (ドメインロジック変更なし)。

## 背景・課題

bug-hunt 実走で以下の UX 破綻が確認された:

1. **F-03 (Medium, H7)**: `/email/verify` の「認証メールを再送信」押下後、`POST /email/verification-notification` は
   302 で成功しているのに画面に成功フィードバックが一切出ない。ユーザーは再送信されたか判断できず連打を誘発する。
2. **F-06 (Medium, H7)**: `/forgot-password` の「リセットリンクを送信」押下後も同一パターンで無反応に見える。
   F-03 と根本原因を共有する。
3. **F-08 (Medium, H12)**: ヘッダーナビが画面によって不統一。`/dashboard` には「設定」「ログアウト」があるが、
   `/billing`・`/purchase-tickets`・`/manage/users` 等では「通知」しかなく、ロゴから dashboard に戻らないと
   設定/ログアウトに到達できない。
4. **F-14 (Medium, H13)**: `/manage/users` のメンバー一覧行 (バッジ2種 + 「2FA 解除」+ ロール select + 「削除」) が
   モバイル幅 375px で 93px の横スクロール (`scrollWidth 468 > clientWidth 375`) を起こす。

### 根本原因 (コード調査済み)

- **F-03**: Fortify 既定の `EmailVerificationNotificationController` は
  `back()->with('status', 'verification-link-sent')` を返すが、`HandleInertiaRequests::share()` の flash 共有は
  `success/error/info/warning` の 4 キーのみで `status` を共有しない (flash-to-toast も同 4 キーのみ消費)。
  → toast が出ない。
- **F-06**: 自前の `EnumerationSafePasswordResetLinkResponse` (Fortify contract bind 済み) が
  `back()->with('status', ...)` を返している。フロント (`ForgotPassword.svelte`) のコメントは
  「flash success は AuthLayout の ToastContainer 経由で表示される」と `success` キーを期待しており、
  バックエンドとキーが食い違っている。
  → 既に同種の修正前例あり: `TwoFactorDisabledResponse` は「flash-to-toast は status を意図的に gating
  するため web は success キーへ寄せる」と明記して `back()->with('success', ...)` に差し替え済み。
- **F-08**: `AppLayout.svelte` (認証済み画面共通テンプレート) のヘッダーは
  「通知ベル + `headerActions` snippet」構成で、「設定」「ログアウト」は snippet として
  **Dashboard.svelte だけが**注入している。他の AppLayout 利用ページ (24 ページ) は snippet を渡していないため
  ナビが欠落する。
- **F-14**: `Admin/Users.svelte` のメンバー行が
  `<li class="flex items-center justify-between gap-4">` + 操作ブロック `<div class="flex shrink-0 ...">` で、
  `flex-wrap` も縦積みフォールバックもなく `shrink-0` が幅を強制するため 375px で溢れる。
  招待中一覧の行も同型 (`shrink-0`) で同リスクがある。

## 改善アイデア

### 施策 A: 成功 flash の補完 (F-03 / F-06)

フレームワークのレンジ内 (Fortify Response contract bind) で成功 flash を `success` キーに揃える。
禁止事項 7 準拠 (`redirect()->intended()` は使わず `back()->with(...)` で完結)。

- **F-03**: `App\Http\Responses\Fortify\VerificationNotificationSentResponse` を新設し、
  `Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse` に bind する。
  web は `back()->with('success', '認証メールを再送信しました。')`、
  `expectsJson` は Fortify 既定互換の JSON 202 を維持する (既存 `TwoFactorDisabledResponse` と同型)。
- **F-06**: 既存 `EnumerationSafePasswordResetLinkResponse` の
  `back()->with('status', ...)` を `back()->with('success', ...)` に変更する。
  enumeration 抑止の不変条件 (user 在/不在で同一応答) は維持 (メッセージ・キーとも同一のまま)。

toast 表示側は既存機構 (`HandleInertiaRequests` の flash 共有 → `consumeFlash` → `ToastContainer`) を
そのまま使う。`/email/verify` は `AuthLayout`、`/forgot-password` も `AuthLayout` で
どちらも `ToastContainer` + `consumeFlash` 配線済みのためフロント変更は不要。

### 施策 B: ヘッダーナビの統一 (F-08)

「設定」「ログアウト」を Dashboard の page-local snippet から `AppLayout.svelte` 本体へ昇格し、
ログイン中 (`auth.user != null`、通知ベルと同じゲート) は全 AppLayout ページで常設する。

- `AppLayout.svelte`: 通知ベルの隣に「設定」`TextLink` (/settings) と「ログアウト」ghost Button
  (POST /logout、二重送信ガード付き) を追加。`headerActions` snippet はページ固有の追加アクション用として存置。
- `Dashboard.svelte`: 重複する snippet (設定/ログアウト) と logout ロジックを削除。
- ゲスト到達がある `invitations.accept` (AppLayout 利用・auth 任意) では auth.user が無いので出ない
  (通知ベルと同じ挙動で一貫)。

### 施策 C: manage.users のレスポンシブ対応 (F-14)

`Admin/Users.svelte` のメンバー行・招待行を、モバイル幅では縦積み・広い幅では現行の横並びに切り替える
(DS token の範囲 = Tailwind レイアウトユーティリティのみ。色/角丸/タイポは変更しない)。

- メンバー行 `<li>`: `flex-col items-stretch gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4`
- メンバー操作ブロック: `shrink-0` を外し `flex flex-wrap items-center gap-2` (要素単位で折り返し可能に)
- 招待行も同型の縦積みフォールバックを適用

## 期待効果

- **使命への貢献**: 「専門知識ゼロの現場作業者」が対象ユーザーであるため、操作結果が分からない・
  ログアウト導線が消える・スマホで画面が溢れる、という基礎 UX の破綻は North Star (思考ゼロで使える) を
  直接毀損する。3 施策はいずれもその足元を直す。
- F-03/F-06: メール送信系操作の成否が toast で即座に分かり、連打・不安・二重送信を抑止。
- F-08: 全業務画面から設定/ログアウトへ 1 クリックで到達可能になり、ナビの一貫性が回復。
- F-14: スマホからのメンバー管理 (現場管理者の主要環境) で横スクロールが消える。

## 実装方針（概要）

| finding | 変更ファイル | 変更内容 |
|---------|------------|---------|
| F-03 | `app/Http/Responses/Fortify/VerificationNotificationSentResponse.php` (新規) / `app/Providers/FortifyServiceProvider.php` | contract bind 追加。web=success flash、JSON=既定互換 |
| F-06 | `app/Http/Responses/Fortify/EnumerationSafePasswordResetLinkResponse.php` | flash キー `status`→`success` (メッセージ不変) |
| F-08 | `resources/js/components/templates/AppLayout.svelte` / `resources/js/pages/Dashboard.svelte` | 設定/ログアウトをレイアウト常設化、Dashboard の重複 snippet 削除 |
| F-14 | `resources/js/pages/Admin/Users.svelte` | メンバー行・招待行の縦積みフォールバック + flex-wrap |

テスト:

- **Feature (Pest)**: 認証メール再送で `success` flash が載ること / forgot-password が user 在/不在とも
  同一の `success` flash であること (既存 `FortifyResponseTest` の `status` アサーションを更新)
- **Vitest**: AppLayout がログイン中に設定リンク (/settings) とログアウトボタンを描画すること
  (auth 無しでは出ないこと) / Dashboard から重複ナビが消えたことの回帰 /
  Admin/Users のメンバー行がモバイル縦積みクラス (`flex-col` + `sm:flex-row`) と操作ブロック `flex-wrap` を
  持つこと (jsdom はレイアウト計算をしないため、クラス不変条件を横スクロール回避のプロキシとして検証)

## 制約・前提

- 禁止事項 7: 操作系 POST は `back()->with(...)` で完結 (`redirect()->intended()` 不使用) — 施策 A は準拠
- 禁止事項 8: disabled ボタン UI 禁止 — 変更なし (既存の loading ガードのみ)
- DS token/ramp のみ (DESIGN.md canonical、ds-purity テスト)。アイコン追加時は `@lucide/svelte` のみ
- atomic 階層 (templates は pages から利用) を逆流しない — AppLayout への昇格は templates 層内で完結
- Fortify contract bind は既存パターン (`FortifyServiceProvider::register()` + `app/Http/Responses/Fortify/`) を踏襲
- enumeration 抑止 (F-06 応答の user 在/不在同一性) は維持する
- PHPStan level 10 / Pint / eslint / typecheck 全 green

## スコープ外

- F-02 (バリデーション未翻訳)・F-05/F-07 (課金/プロジェクト作成詰み)・F-11 (confirm-password 500) 等の
  他 finding (別設計 20260712-0925/0926/0927 で対応)
- TODO 登録・実装 (本 Phase は設計のみ)
- 通知センター/サイドバー等ヘッダーの機能拡張 (Phase 2 拡張点はコメントどおり温存)
- manage.users 以外の画面の網羅的レスポンシブ監査 (bug-hunt で問題が確認されたのは manage.users のみ)
