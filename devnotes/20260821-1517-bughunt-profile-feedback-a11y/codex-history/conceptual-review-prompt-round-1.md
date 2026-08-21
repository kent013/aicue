## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(app/Prompts の factory → 窓口 (PromptDefense) → 実行単位 (GuardedPrompt) の 1 本道のみ)
6. prompt 文字列のコード直書き(resources/prompts/*.yaml に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

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

【補足コンテキスト（コードから確認済みの事実）】
- `ProfileUpdatedResponse::toResponse` は現状: `expectsJson()` なら `JsonResponse('',200)`、それ以外は `back()->with('success','プロフィールを更新しました。')`。
- `/settings` は `['auth','verified','not-pending-deletion']` グループ配下。`verified` エイリアスは Laravel 素の `EnsureEmailIsVerified`（flash を keep しない）。
- `/email/verify` = route 名 `verification.notice`（`auth` のみ、verified 配下ではない）。VerifyEmail 画面は AuthLayout を使い `consumeFlash` でトースト化する。
- `UpdateUserProfileInformation::update` はメール変更時のみ `email_verified_at` を null 化し `sendEmailVerificationNotification()` を呼ぶ。氏名のみ変更では null 化しない。
- 既存テスト `ProfileEmailChangeRecentAuthTest::3` は「fresh + email 変更で Location が `recent-auth.confirm` でない」ことを assert（`verification.notice` への redirect は通る）。
- `AutoRechargeCard.svelte`: FormField は `error` prop から `invalid=Boolean(error)` を snippet に渡し、`Input` の `aria-invalid` はその `invalid` に連動。現状 threshold/max の FormField に `error` を渡していないため常に false。範囲エラーは FormField 外の独立 `<p data-testid="auto-recharge-range-error" aria-live="polite">` で表示。既存 JS テストがこの testId を参照。

---

## 概念設計

（以下は devnotes/20260821-1517-bughunt-profile-feedback-a11y/conceptual-design.md の全文）

# 概念設計: bughunt-profile-feedback-a11y

bug-hunt run 20260821-095643 の「profile-feedback-a11y」グループ (F-4-01 / F-3-01) への改善設計。
いずれも**操作は成功しているのにユーザー (支援技術含む) が結果を認識できない**フィードバック欠落の是正であり、
機能そのものの追加・変更ではない。

## 背景・課題

### F-4-01 (Medium, H7): recent-auth 経由のメール変更成功後に成功フィードバックが出ない

- 再現: stale remember-me セッション → `/settings` でメール変更 → 本人確認モーダル (recent-auth) で
  再認証 → `/email/verify` へ遷移。この間「メールアドレスを変更しました」等の成功トースト/flash が一度も出ない。
  操作自体は成功している (変更は効いており `/email/verify` へ到達する)。
- 通常のプロフィール更新では `ProfileUpdatedResponse` が `back()->with('success', 'プロフィールを更新しました。')`
  を返し、`flash-to-toast` がトーストを出す。本件はメール変更に固有。
- **根本原因 (調査で特定)**: メール変更時 `UpdateUserProfileInformation` が `email_verified_at` を null 化する。
  その後 `ProfileUpdatedResponse` は `back()` (= 遷移元 `/settings` へ 302) を返す。`/settings` は
  `['auth','verified','not-pending-deletion']` グループ配下のため、Laravel 素の `verified`
  (`EnsureEmailIsVerified`) が「未認証」を検出し `verification.notice` (`/email/verify`) へ**もう一段 302**
  する。素の `verified` は flash を keep しない (`FlashNotificationRelay::relayTo` を呼ぶのは
  `verified.or-back` / 課金ゲート / 削除保留ゲートだけ) ため、`back()` に載せた `success` flash が
  この中間ホップで**期限切れ廃棄**され、`/email/verify` には届かない。
- 結果: ユーザーは「本人確認 → 突然メール認証画面」という遷移だけを見せられ、直前のメール変更が
  成功したのか失敗したのかを画面から判断できない (再試行・問い合わせといった不要行動を誘発しうる)。

### F-3-01 (Low, H12/a11y): オートリチャージの範囲エラーで対象 spinbutton に aria-invalid が付かない

- 再現: `/billing` オートリチャージで「開始残高 ≧ 補充後残高」の不正入力 → エラー文言は正しく出るが、
  対象の spinbutton (`max_count` / `threshold_count`) に `aria-invalid` が付かない。他フォームは一貫して付く。
- **根本原因 (調査で特定)**: `AutoRechargeCard.svelte` は範囲エラーを FormField の外の独立した `<p>`
  (`auto-recharge-range-error`, `aria-live="polite"`) で表示している。`FormField` は `error` prop から
  `invalid` (= `Boolean(error)`) を導出して children snippet に渡し、`Input` の `aria-invalid` はその
  `invalid` に連動するが、当コンポーネントは threshold/max の FormField に `error` を渡していないため
  `invalid` が常に false のまま = spinbutton に `aria-invalid` が付かない。
- 結果: スクリーンリーダー等の支援技術利用者が、どの入力欄がエラーかを補助的に把握しづらい
  (視覚的な文言自体は出るため致命的ではない = Low)。

## 改善アイデア

### F-4-01

`ProfileUpdatedResponse` を「メール変更でユーザーが未認証状態になった場合」を検知して分岐させる。
未認証なら `back()` (= `/settings` 経由で verified 302 に flash を奪われる) ではなく、
**`verification.notice` (`/email/verify`) へ直接 302 し、その 302 に success flash を載せる**。
`/email/verify` は `auth` のみ (verified ゲート配下ではない) ので中間 302 が発生せず flash が生き残り、
既存の `flash-to-toast` が「メールアドレスを変更しました。…認証を完了してください。」トーストを出す。

- メール以外 (氏名のみ) の更新は従来どおり `back()->with('success', 'プロフィールを更新しました。')` を維持。
- 着地画面 (`/email/verify`) はメール変更後に**現状でも必ず到達している画面**なので、遷移フローは不変。
  変わるのは「その画面で成功フィードバックが出る」ことと「無駄な `/settings` 描画ホップが消える」ことだけ。
- 中間ホップで flash が落ちる一般問題を `verified` 全体で直そうとはしない (影響過大)。当該操作の
  レスポンス経路にだけ、最終着地画面へ直接寄せる**局所修正**にとどめる。

### F-3-01

`AutoRechargeCard.svelte` で、範囲エラーの原因フィールドを特定する per-field の invalid 判定を導出し、
threshold / max の各 `Input` の `error` prop (= `aria-invalid` の源) に流し込む。既存の統合エラー `<p>`
(`auto-recharge-range-error`) と単一メッセージ UX・既存テスト契約は維持しつつ、
不正フィールドの spinbutton に `aria-invalid="true"` を付ける。合わせて `aria-describedby` を統合エラー
`<p>` の id へ結び、支援技術が「どの欄の・何のエラーか」を辿れるようにする (a11y の実質を満たす)。

## 期待効果

- **使命への貢献**: 使命は「専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れる」こと。
  そのための土台として、アカウント・課金まわりの操作は「やった結果が分かる」ことが前提になる。
  - F-4-01: メールという認証要素の変更という重要操作の成否を、ユーザーが確実に認識できる (信頼性・自己解決)。
  - F-3-01: 支援技術利用者を含む全ユーザーが、入力エラーの所在を把握できる (アクセシビリティ)。
- どちらも**フィードバックの是正**であり、成功しているのに伝わっていない情報を伝えるだけ。新機能ではない。

## 実装方針（概要）

| finding | 変更コンポーネント | 概要 |
|---|---|---|
| F-4-01 | `app/Http/Responses/Fortify/ProfileUpdatedResponse.php` | 未認証 (メール変更で `email_verified_at` null) 検知時、`verification.notice` へ success flash 付き 302。氏名のみは従来 `back()`。 |
| F-3-01 | `resources/js/components/features/billing/AutoRechargeCard.svelte` | per-field invalid を各 spinbutton の `error` prop へ配線し `aria-invalid` を付与。`aria-describedby` を統合エラー `<p>` へ結ぶ。 |

- どちらも既存パターン (flash → success キー → `flash-to-toast`、`Input` の `error`→`aria-invalid`) の
  正しい利用であり、新しい仕組みは導入しない。

## 制約・前提

- F-4-01 は Fortify contract bind (`ProfileInformationUpdatedResponseContract`) の実装差し替え済みクラスを
  さらに分岐させるだけ。`expectsJson` (XHR/API) は現状どおり JSON 200 を維持する (契約不変)。
- 「未認証 ⇒ メール変更」の含意: `/settings` は `verified` グループ配下なので、フォームを開けた時点で
  ユーザーは必ず verified。更新後に未認証になるのは `UpdateUserProfileInformation` の**メール変更分岐のみ**が
  `email_verified_at` を null 化する経路に限られる。よって「更新後 `!hasVerifiedEmail()`」は
  「今まさにメールを変更した」と実質同値であり、この判定で誤爆しない。
- F-3-01 は既存の統合エラー `<p>` と testId (`auto-recharge-range-error`) を維持し、既存 JS テストを壊さない。
  共有 molecule `FormField` は変更しない (`Input` の `error` prop へ直接 per-field boolean を渡す)。
- 禁止事項 #8 (必須未充足で disabled にしない) を維持: 入力エラーは押下時に提示する現行契約を変えない。
- DESIGN.md / Atomic Design 準拠: 色は token (`text-danger` 等) 参照済み、`aria-invalid` は `Input` atom の
  既存機構。新規 hex・SVG 直書き・階層逆流はなし。

## スコープ外

- 素の `verified` (`EnsureEmailIsVerified`) 全体を flash-keep 対応にする一般化 (影響過大・当グループ対象外)。
- メール変更時の旧アドレス通知の有無 (shard-4 要確認-1)、パスワード未設定ユーザーの初期設定正常系
  (要確認-2)、その他グループ (F-1/F-2 系、F-3-02 idempotency) — 本設計では扱わない。
- オートリチャージの範囲エラー**メッセージ内容**やバリデーション仕様の変更 (aria の配線のみが対象)。
- サーバ側バリデーション (`max_count.gt` 等) の 422 応答仕様の変更 (クライアント表示の a11y のみが対象)。
