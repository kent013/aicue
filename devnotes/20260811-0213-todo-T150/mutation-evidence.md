# T150 mutation evidence

詳細設計 `devnotes/20260811-0146-state-aware-messaging/detailed-design.md` §テスト計画 F の
mutation 手順を 1 つずつ実施した記録。**入れた変異はすべて revert 済み**
(`git diff --stat` で施策の差分だけが残っていることを確認済み)。

**設計の予測と実測がずれた箇所は辻褄を合わせず、ずれとして記録する**。

| # | 変異 | 設計の予測 | 実測 | 判定 |
|---|---|---|---|---|
| 1 | `DashboardService` で `billingState:` を `OnboardingBillingState::ExpiredCheckout` 固定値にする | Feature 新規 1/4/5 と既存 3 本 (計 6) | **5 本 red** (下記) | 予測とずれ (下記) |
| 2 | `BILLING_CALLOUTS.no_subscription.body` を `expired_checkout` と同文言にする | vitest C 1 本目 + Browser E の (1)(2) 両方 | vitest **1 本 red**、Browser **1 本 red (line 59 = (1) で停止)** | 予測とずれ (下記) |
| 3 | `BILLING_CALLOUTS` から `pending_checkout` キーを削除 | `pnpm typecheck` が落ちる | **落ちなかった** (実装を移設して再実測 → 落ちるようになった) | **設計の誤り**。下記「発見」 |
| 4 | `billing.ts` の `"no_subscription"` を `"no_subscription_x"` に書換 | Architecture B 1 本目 | 1 本目のみ red (差分表示付き) | 予測どおり |
| 5 | B 2 本目の `NoSuchUnionName` を実在の `BillingStateValue` に差替 | B 2 本目 | 2 本目のみ red (`RuntimeException not thrown`) | 予測どおり |
| 6 | `fetchOptions` の `if (res.status === 429)` 行を削除 | vitest D の 1・3 本目 (2 本目=500 は緑のまま) | 1・3 本目のみ red、**500 の負のコントロールは緑のまま** | 予測どおり |
| 7 | `readErrorMessage` の 429 早期 return を削除 | vitest D の 4 本目 | 4 本目のみ red | 予測どおり |
| 8 | `BillingSummaryData::toArray()` の返り値に `'has_billing_access' => $this->billingState->grantsAccess()` を併記 | Feature 新規 1 本目の `->missing(...)` | **その 1 本だけ red** (30 passed / 1 failed) | 予測どおり |

---

## 変異 1 のずれ (予測 6 本 → 実測 5 本)

red になったのは:

1. `残高/容量: grant 済み残高・低残高フラグ・使用率が正しい` (`active_free_plan` 期待)
2. `Free (grandfathered) org: … billing_state=active_free_plan …`
3. `有償契約 + past_due org: billing_state=subscribed …`
4. `新規登録相当 (未契約) org: billing_state=no_subscription (F-2-01 再現)`
5. `live pending checkout org: billing_state=pending_checkout`

green のまま残ったのは **`expired_checkout` を期待する 2 本**
(`有償契約 + 支払い不健全 org: …expired_checkout…` / `expired checkout org: …expired_checkout…`)。
変異が `ExpiredCheckout` を固定値で返す以上、この 2 本が緑なのは論理的に正しい。
設計の「既存 3 本」という数え方が実際の母集団 (既存 4 本 + 新規 3 本 = 7、うち 2 本は
expired 期待で不感) と噛み合っていなかっただけで、**検出力の不足ではない**。
変異の位置が特定できるという意味では、むしろ 2 本が緑で残る方が情報量が多い。

## 変異 2 のずれ (Browser の (2) は評価されない)

Browser lane は 1 本のテストに 3 つの assert を直列に置いているため、
(1) 「プランの選択が必要」の assert が先に失敗して**そこでテストが終わる**
(実測: `tests/Browser/DashboardBillingCalloutTest.php:59` で失敗)。
(2) の `assertDontSee('お支払いが確認できないため')` は**同じ実行では評価されない**。

設計の「(1)(2) 両方が赤くなる」は 1 テスト = 1 失敗という pest の挙動を織り込んでいない。
検出そのものは効いている (この変異で Browser lane は確実に赤くなる) ので実装は変えていない。
「(2) が独立に効くこと」は別変異でしか示せないため、**ここでは示せていない**と記録する。

## 変異 3 で見つかった設計の誤り (実装を変更した)

設計は「`satisfies Record<BillingStateValue, …>` により state が増えたら `pnpm typecheck` が
落ちる」としていたが、`BILLING_CALLOUTS` を `Dashboard.svelte` の `<script>` に置いた状態で
`pending_checkout` キーを削除しても **`pnpm typecheck` は緑のまま通った**。

原因: `pnpm typecheck` は `tsc --noEmit` であり、`tsc` は `.svelte` を型検査しない
(`tsconfig.json` の `include` に `resources/js/**/*.svelte` はあるが、tsc 単体では
Svelte SFC をパースできず黙って対象外になる)。`svelte-check` は本リポジトリに無い。
`pnpm lint` (eslint) も `pnpm build` (Svelte コンパイラ) も落ちなかった
(実測: `LINT_RC=0` / `BUILD_RC=0`)。

つまり **page 内に置いた `satisfies` は一度も評価されない** = 「コンパイル時に守っている
つもり」の空振りだった。設計の「保証しないもの 7」が挙げる 3 層のうち 1 層が実在しない状態。

**対応**: `BILLING_CALLOUTS` を `resources/js/types/dashboard.ts` へ移した
(`types/manual.ts` の `VIDEO_MANUAL_STATUS_LABELS` / `CAPTURE_NAVIGABLE_BY_STATUS` が
「status 追加時のキー漏れをコンパイル時検出する」目的で同じ場所に置かれている先例に揃えた)。
移設後に同じ変異を再実測したところ、狙いどおり赤くなった:

```
resources/js/types/dashboard.ts(91,12): error TS1360:
  Property 'pending_checkout' is missing in type '…'
  but required in type 'Record<BillingStateValue, … | null>'.
TYPECHECK_RC=2
```

なお移設**前**の状態でも vitest は赤くなっていた
(`billing_state=pending_checkout で「プラン選択へ」callout が出る` の 1 本)。
これは今ある 5 状態すべてに vitest を書いてあるからで、**将来 enum に case が増えたときは
その case の vitest が存在しない**ため、移設しなければ描画漏れは無言で通っていた。
Architecture gate は `billing.ts` の更新までしか強制できず、`BILLING_CALLOUTS` の
キー追加までは強制しない。移設によって初めて設計が意図した 3 層になった。

これは設計からの逸脱だが、**設計が主張していた不変条件を実際に成立させるための変更**である
(Atomic Design 上は component 層を増やしておらず、page 内の分岐は `$derived` 1 行のまま)。
