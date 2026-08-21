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

`ProfileUpdatedResponse` を「このリクエストでメールが変更された場合」を検知して分岐させる。
判定は `$user->wasChanged('email')` (= 保存直後の Eloquent 変更追跡。`$request->user()` は action が
保存した同一インスタンスを memo 返しする)。メール変更時は `back()` (= `/settings` 経由で verified 302 に
flash を奪われる) ではなく、**`verification.notice` (`/email/verify`) へ直接 302 し、その 302 に
success flash を載せる**。`/email/verify` は `auth` のみ (verified ゲート配下ではない) ので中間 302 が
発生せず flash が生き残り、既存の `flash-to-toast` が「メールアドレスを変更しました。…認証を完了してください。」
トーストを出す。

- `wasChanged('email')` を条件にする理由: メール変更分岐のみが `email_verified_at` を null 化するため、
  これは「未認証化 = 認証導線が必要」と同値であり、かつ「操作事実 (email が変わった)」を状態からの推測でなく
  直接見る。将来 `/settings` 以外から未認証ユーザーが氏名だけ更新する経路が生まれても誤爆しない。

- メール以外 (氏名のみ) の更新は従来どおり `back()->with('success', 'プロフィールを更新しました。')` を維持。
- 着地画面 (`/email/verify`) はメール変更後に**現状でも必ず到達している画面**なので、遷移フローは不変。
  変わるのは「その画面で成功フィードバックが出る」ことと「無駄な `/settings` 描画ホップが消える」ことだけ。
- 中間ホップで flash が落ちる一般問題を `verified` 全体で直そうとはしない (影響過大)。当該操作の
  レスポンス経路にだけ、最終着地画面へ直接寄せる**局所修正**にとどめる。

### F-3-01

`AutoRechargeCard.svelte` で、範囲エラーの原因フィールドを特定した per-field のエラー文字列を導出し、
threshold / max の各 **`FormField` の `error` prop** に渡す。これにより `FormField` が既存機構で
(a) FormError の文言描画、(b) `invalid`(=`Boolean(error)`)→ children snippet → `Input` の `aria-invalid`、
(c) `aria-describedby`→errorId の配線、を一括で行う (DESIGN.md §FormField の canonical パターン)。
`FormField` 自体は改変せず既存 `error` prop を使うだけ。

- 従来 FormField の外に独立表示していた統合エラー `<p data-testid="auto-recharge-range-error">` は撤去する
  (FormField 内 FormError と文言が二重化するため)。原因フィールドを 1 つに限定 (threshold-first 短絡) するので
  同時に 2 欄が invalid にはならない。
  - `parsedThreshold===null` → threshold のみ / `parsedMax===null` → max のみ /
    `parsedMax<=parsedThreshold` → max のみ (文言「リチャージ後の残高は開始残高より大きい値」が指す欄)。
- 既存 JS テスト (range-error testId を参照) は「対象 spinbutton の `aria-invalid` 属性 + 文言 (getByText)」の
  assert に更新する (カバレッジは削除せず移設)。

## 期待効果

- **使命への貢献**: 使命は「専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れる」こと。
  そのための土台として、アカウント・課金まわりの操作は「やった結果が分かる」ことが前提になる。
  - F-4-01: メールという認証要素の変更という重要操作の成否を、ユーザーが確実に認識できる (信頼性・自己解決)。
  - F-3-01: 支援技術利用者を含む全ユーザーが、入力エラーの所在を把握できる (アクセシビリティ)。
- どちらも**フィードバックの是正**であり、成功しているのに伝わっていない情報を伝えるだけ。新機能ではない。

## 実装方針（概要）

| finding | 変更コンポーネント | 概要 |
|---|---|---|
| F-4-01 | `app/Http/Responses/Fortify/ProfileUpdatedResponse.php` | `wasChanged('email')` 検知時、`verification.notice` へ success flash 付き 302。氏名のみ・同一 email は従来 `back()`。expectsJson は JSON 200 維持。 |
| F-4-01 (テスト) | `tests/Feature/Auth/FortifyResponseTest.php` (または `ProfileEmailChangeRecentAuthTest.php`) | fresh メール変更→verification.notice+success flash / 氏名のみ→back+従来文言 / expectsJson→200 の回帰を固定。 |
| F-3-01 | `resources/js/components/features/billing/AutoRechargeCard.svelte` | per-field エラー文字列を各 `FormField` の `error` prop へ渡し `aria-invalid`/`aria-describedby` を付与。統合 `<p>` は撤去。 |
| F-3-01 (テスト) | `tests/js/components/features/billing/AutoRechargeCard.test.ts` | 既存 range-error 参照を aria-invalid + 文言 assert に更新し、原因フィールド限定を固定。 |

- どちらも既存パターン (flash → success キー → `flash-to-toast`、`FormField.error`→`invalid`→`Input.aria-invalid`) の
  正しい利用であり、新しい仕組みは導入しない。

## 制約・前提

- F-4-01 は Fortify contract bind (`ProfileInformationUpdatedResponseContract`) の実装差し替え済みクラスを
  さらに分岐させるだけ。`expectsJson` (XHR/API) は現状どおり JSON 200 を維持する (契約不変)。
- 判定 `$user->wasChanged('email')` の前提: `$request->user()` は当該リクエストで action
  (`UpdateUserProfileInformation`) が `save()` した同一 User インスタンスを memo 返しするため、保存直後の
  変更追跡が読める。メール変更分岐のみが email を変え email_verified_at を null 化するので、
  この判定は「認証導線が必要な状態変化」を操作事実として直接捉え、状態からの推測にならない。
- F-3-01 は共有 molecule `FormField` を**改変しない** (既存の `error` prop を使うだけ)。既存 JS テストは
  aria-invalid + 文言 assert へ更新する (削除せず移設)。
- 禁止事項 #8 (必須未充足で disabled にしない) を維持: 入力エラーは押下時に提示する現行契約を変えない。
- DESIGN.md / Atomic Design 準拠: 色は token (`text-danger` 等) 参照済み、`aria-invalid` は `Input` atom の
  既存機構。新規 hex・SVG 直書き・階層逆流はなし。

## スコープ外

- 素の `verified` (`EnsureEmailIsVerified`) 全体を flash-keep 対応にする一般化 (影響過大・当グループ対象外)。
- メール変更時の旧アドレス通知の有無 (shard-4 要確認-1)、パスワード未設定ユーザーの初期設定正常系
  (要確認-2)、その他グループ (F-1/F-2 系、F-3-02 idempotency) — 本設計では扱わない。
- オートリチャージの範囲エラー**メッセージ内容**やバリデーション仕様の変更 (aria の配線のみが対象)。
- サーバ側バリデーション (`max_count.gt` 等) の 422 応答仕様の変更 (クライアント表示の a11y のみが対象)。
