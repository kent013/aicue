# 対応マトリクス: conceptual-review Round 1

Codex 全体判定: **APPROVED** (Critical 0 / Warning 5 / Suggestion 6)。
Warning は概念設計に反映済み。ラウンド 2 は行わない (APPROVED のため)。

## [Warning] 1. 期待効果の表現が広すぎる (公開面をスコープ外に残すなら「ゼロになる」は成立しない)
- 判断: **対応する**
- 根拠: 指摘のとおり。`Welcome` / `Guest/Pricing` の未検証ユーザー向け CTA は同 species の罠を残す。
- 対応内容: 「期待効果」を `Auth/VerifyEmail` / `AuthLayout` 採用ページに限定して書き直し、
  公開面は別 TODO であることを効果欄からも参照させた。

## [Warning] 3. continuation service に checkout 専用 bool API を足すと責務が混ざる
- 判断: **一部対応する**
- 根拠: service に UI 語彙 (`continuesToCheckout`) を持ち込まないのは正しい。
  一方で「presenter / view-model クラスを新設する」のは 1 行の写像に対して過剰 (思考原則 2)。
- 対応内容: service 側 API は継続の有無を表す `hasContinuation(): bool` (UI 語彙なし・`resolveUrl()` へ委譲 =
  membership 確認の単一出典を維持) とし、`continuesToCheckout` prop への写像は `verifyEmailView` の
  クロージャで行う。新クラスは作らない。

## [Warning] 5. ConfirmRecentAuth の離脱先を `/dashboard` 固定にすると元操作の文脈が切れる
- 判断: **反論する (ただし文言の提案は採用)**
- 根拠: step-up を満たさないまま元操作 (intended URL) へ戻しても `recent-auth` middleware が
  再び本画面へ送り返すだけで、無限往復に見える。「中止」導線の意味は「元操作をやめる」であり、
  ダッシュボード固定が意味と一致する。session の intended URL を UI に露出させると
  open-redirect の検査面 (`SameOriginPath` 相当) を新たに増やすことにもなる。
- 対応内容: 二段 fallback は入れない。文言を「この操作を中止してダッシュボードへ戻る」に確定し、
  概念設計に理由を明記した。

## [Warning] 6. 施策 A / B の acceptance criteria を分離すべき
- 判断: **対応する**
- 根拠: 1 TODO のままでも受け入れ条件は分離できる。実装レビュー時の抜け漏れ検出に効く。
- 対応内容: 詳細設計で施策 A / B ごとに独立した受け入れ条件 (DoD) 節を持たせる。

## [Warning] 7. 「prop が無い」だけでは型安全の担保として弱い
- 判断: **対応する**
- 根拠: prop 撤去の確認と描画分岐の確認は別の不変条件。
- 対応内容: (a) Feature テストで `continueUrl` の不在 + `continuesToCheckout` の true/false 値、
  (b) vitest で `continuesToCheckout` true/false の描画分岐 (説明文の有無・旧 testId の不在) を固定する。

## [Suggestion] VerifyEmail の説明文に onboarding 継続の意図まで書く
- 判断: 対応する。文言を「メール認証が完了すると、そのままプラン選択に進みます。」とする。

## [Suggestion] 旧 prop を残さないことをテストで固定
- 判断: 対応する (Warning 7 の対応に含む)。

## [Suggestion] Svelte 側も boolean 必須で受ける
- 判断: 対応する。`continuesToCheckout: boolean` を optional にせず宣言する
  (既定値も置かない = サーバが常に渡す契約を型で表明する)。

## [Suggestion] allowlist 例外の理由をテストコメントに残す
- 判断: 対応する。architecture テストの allowlist entry に `reason` を必須で持たせる
  (既存 `PAGECONTENT_ALLOWLIST` と同方式)。

## [Suggestion] テスト名・view-model 名に `continuesToCheckout` を明示 (Billing/Index の別 continueUrl と混同防止)
- 判断: 対応する。テスト名に prop 名を含める。

## [Suggestion] DB 変更・route 追加なしで閉じるのは適切 / 方向性は妥当
- 判断: 対応不要 (現状維持)。
