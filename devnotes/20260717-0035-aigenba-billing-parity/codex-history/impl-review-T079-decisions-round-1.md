# 対応マトリクス: impl-review-T079 Round 1

対象: `devnotes/20260717-0035-aigenba-billing-parity/impl-review-T079-round-1.md`
(gpt-5.3-codex / reasoning=high / one-shot。総合判定 CHANGES_REQUESTED)

---

## [Critical] 同意上限額の算出が実課金上限を過小評価し、同意額を超えて課金可能

- **判断: 対応する (指摘は正当。再現を fixture で確認済み)**
- **根拠**:
  - `TicketVolumePriceSeeder::TIERS` は逐減単価 (`1→¥100 / 20→¥80 / 50→¥70 / …`) で、`DatabaseSeeder` に
    登録済み = **本番・テストとも実データ**。よって総額 `q × unit(q)` は **数量に対して単調ではない**
    (`48 × 80 = 3,840` > `50 × 70 = 3,500`)。
  - 設計書 P8a 本文 L194 の「`quantity <= max_count` が同意上限 invariant の根拠」および実装 docblock の
    「総額は数量に単調」は、この単価表の形状では**成立しない**。したがって「設計どおりだから問題ない」という
    反論は成立しない (設計の前提そのものが AI-CUE の実データで偽)。
  - **既定値 (threshold=5 / max=50) で到達する**: 真値残高 0〜4 → quantity 46〜49 → 1 段上の ¥80 が適用され
    最大 ¥3,920。同意画面 (`Onboarding/Checkout.svelte` L284-287) と請求ページ
    (`AutoRechargeCard.svelte` L368) は「1 回の自動購入の上限額 ¥3,500 (1 枚あたり ¥70)」と表示しており、
    **表示上限を超える課金**が起こり得た。消費者保護 (特商法・同意の実質) の観点でマージブロッカーという
    Codex の評価に同意する。
- **対応内容** (`app/Services/Billing/AutoRechargeService.php` `createAttemptLocked`):
  - `$tier = TicketVolumePrice::currentTierFor($quantity)` → **`currentTierFor($config->max_count)`**。
    適用単価を「同意した Max 枚の tier」に pin する。
  - これにより `実請求額 = quantity × unit(max) <= max_count × unit(max) = consented_max_amount` が
    **単価表の形状に依存せず無条件で成立**する (単調性の仮定が不要になる)。
  - 修正案の選択理由 (Codex の Suggestion「到達域の最大額を同意額にする」を採らなかった理由):
    (a) UI 実装 (`AutoRechargeCard.svelte` L97 のコメント「適用単価: Max 枚をまとめ買いした場合の tier 単価」/
    L368 の「1 枚あたり ¥70」表示) は**既に「Max 枚 tier の単価が適用される」と宣言している**。
    サーバをそれに合わせるのが表示・同意・実請求の 3 者一致の最短路であり、UI 文言も同意金額も変えずに済む。
    (b) 到達域の最大額を同意額に採ると、ユーザーに提示する上限額が (¥3,500 → ¥3,920) 悪化し、
    `reconsentRequiredFor` / `consentTermsFor` / UI 表示すべてに範囲計算 (tier 境界の探索) が波及する。
    (c) 本修正はユーザー有利 (部分補充でもまとめ買い単価) で、単価 floor 検証 (`currentTierFor` の Assert) も
    そのまま効く。
  - **設計からの逸脱**: 「aigenba verbatim」(移植元も `resolveVolumeTier($quantity)`) からの意図的逸脱 1 点。
    移植元由来の欠陥をそのまま持ち込むと AI-CUE の同意表示と実請求が矛盾するため、
    **設計が宣言した不変条件 (同意上限を超えない) を守る方向**へ倒した。レポートの deviations に記載する。
- **回帰テスト** (`tests/Feature/Billing/AutoRechargeServiceTest.php`。修正前に red を確認済み):
  - `部分補充でも 1 回の請求額が同意上限を超えない (適用単価は同意した Max 枚の tier で pin する)`
  - 空振り防止として **`currentTierFor(48)->unitAmount * 48 > consented_max_amount` を fixture 側で pin**
    (単価表が将来フラットになってテストが自明に通る状態を検出する)。

---

## [Warning] 「カード未登録時の設定保存」が `disabled_reason=user` を常に立て、事前同意済みの自動有効化待ちを潰す

- **判断: 対応する**
- **根拠**: 指摘のとおり。`recordPreConsent` (D29(i)) で `disabled_reason=null` の事前同意行を作った後、
  請求ページで「設定を保存」(`handleSaveDraft` = `enabled=false` の upsert) を押しただけで `User` が刻まれ、
  `autoEnableEligible` が false になる。カード登録完了時の自動有効化 (オンボーディングで
  「カード登録が完了すると、オートリチャージが自動で有効になります」と約束している導線) が黙って死ぬ。
  設計 P8a 本文は「`recordPreConsent` は `disabled_reason` を消さない」までしか規定しておらず、
  **非遷移の保存で `User` を刻むことは設計要求ではない**。
- **対応内容**: `updateSettings` の `else` を `elseif ($config?->enabled === true)` へ。
  **稼働中 → 停止の遷移時のみ** `User` を刻み、非遷移の保存では既存理由 (`payment_failures` 等) を保つ。
- **回帰テスト** (`tests/Feature/Billing/AutoRechargePreConsentTest.php`。修正前に red を確認済み):
  - `事前同意後に enabled=false の設定保存をしても自動有効化の適格性は失われない`
  - `稼働中からの停止は disabled_reason=user を立てる (自動有効化で勝手に復活しない)`
    ← 逆方向の不変条件も同時に pin し、「停止の意思を尊重する」fail-closed が緩まないことを固定。

---

## [Warning] 自動停止通知を DB トランザクション内で送っている

- **判断: 対応する**
- **根拠**: 指摘のとおり、`transitionToTerminal` の TX 内で `notifyDisabled` を呼ぶと、通知系の例外で
  **invoice 終端済みなのに attempt が pending に戻る**。収束先が (iv) 期限切れ終端ではなく
  (`void/deleted` → `canceled`) 経路に変わり、`failure_count` の加算と自動停止も巻き戻る。
  他経路 (`applySetupCompletion`) は既に「通知失敗で確定済み状態を巻き戻さない」設計 (`report()` で握る) に
  なっており、ここだけ規約が破れていた。
- **対応内容**: 停止対象 org を TX 外の変数へ退避し、**commit 後**に `notifyDisabled` を送る
  (`Throwable` は `report()` で握る = `applySetupCompletion` と同一規約)。dedup は attempt 単位のまま。
- **テスト**: 既存の
  `連続失敗で自動無効化される (max_failures 到達で disabled_reason=payment_failures + 通知)` が
  遷移・通知の両方を引き続き固定している (green)。

---

## [Suggestion] 同意上限額/再同意判定/UI 表示を「到達し得る quantity 全域の最大請求額」で統一

- **判断: 見送る (Critical の対応で問題が消えるため)**
- **根拠**: 適用単価を Max 枚 tier に pin した結果、到達域 `q ∈ [max-threshold+1, max]` の**全点で**
  `q × unit(max) <= max × unit(max) = consented_max_amount` が成立する。範囲探索を導入しても
  同意額は変わらず (上限は常に `q = max`)、計算量と UI 文言の悪化だけが残る。

## [Suggestion] Critical を固定する回帰テストの追加

- **判断: 対応する** (上記 Critical の対応内容に記載。`AutoRechargeCard.test.ts` の ¥3,500 期待値は
  UI の計算 (Max 枚 tier 単価) が**正**になったため変更不要。JS テスト 31 件 green を確認)。

---

## 検証 (修正後・全て再実行)

| コマンド | 結果 |
|---|---|
| `composer test` | passed — 2356 tests / 2354 passed / 2 skipped / 0 failed / 9382 assertions |
| `composer phpstan` | `[OK] No errors` (level 10 / 726 files。`phpstan.neon` 無変更・`@phpstan-ignore` 追加なし) |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` / `pnpm typecheck` | 出力なし・exit 0 |
| `vitest` (AutoRechargeCard / OnboardingCheckout) | 2 files / 31 tests passed |
| `pnpm build` | built in 20.88s |
