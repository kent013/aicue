# 検証: Codex が指摘した「バグ」は aigenba にも存在するのか

> ユーザー指摘:「指摘されたバグが aigenba に存在しているバグなのかどうかをちゃんと検証してください」
> 「基本的に全部揃える方向だよ。揃えてから問題を調整してもらいたい」

全 7 件を **aigenba の実コードで検証**した。結論: **7 件中 5 件は「私が aigenba から逸脱した独自実装」が原因**であり、
**aigenba 通りに揃えていれば発生しなかった**。私の「parity より良い設計」の持ち込みが、そのままバグの発生源になっていた。

## 検証結果

| # | 私が「バグ」と呼んだもの | aigenba に存在するか | 真因 |
|---|---|---|---|
| 1 | フェーズ順序（ゲート反転が導線より前）| **N/A**（aigenba は greenfield で段階移行が無い。steady state はゲート + 導線が併存） | **AI-CUE 固有の移行要件**（私の移行計画の問題。parity とは無関係） |
| 2 | `NoPlan` variant 欠落 | **存在しない** — `OnboardingBillingState` が `ActiveFreePlan`(free_plan_code=personal) と `NoSubscription` を**最初から分離**（`grantsAccess = Subscribed \|\| ActiveFreePlan`） | **私の D18**（「`OnboardingBillingState` を移植せず `EffectivePlan` を発明する」）が畳んだ |
| 3 | debt の二重回収 | **存在しない** — aigenba に **debt 概念自体が無い**（`balance()` は `max($monthly,0)` / `max($purchased,0)` で clamp） | **私が debt を発明した**（D19/D24/D27） |
| 4 | paid 判定の同期ラグ締め出し / 支払い不健全の素通し | **存在しない** — `state()` は **`plan_code` を一切見ない**。`$org->subscription('default')` + `deriveEntitlement($sub)->entitled` のみで判定 | **私が `plan_code` 依存の解決順を発明した**（D26） |
| 5 | `reserve()` が debt を無視 | **存在しない** — debt 概念が無いため（#3 の派生） | 同上（私の発明の派生） |
| 6 | attempt token の混同 | **N/A** — aigenba は ticket と subscription の checkout を**別テーブルで分離**済み | **私のパッチミス**（一括置換の事故） |
| 7 | Personal 非公開のままゲート反転 | **存在しない** — `PlanSeeder` に「Personal は…**`is_active=true` で公開する**」と明記 | **私の D10**（「personal/starter を `is_active=false` で seed」）が発明した |

## 根拠（実コード）

### #2 / #4: aigenba `BillingAccess::state()` — `plan_code` を見ない・free を別状態で持つ

```php
// /tmp/aigenba/app/Services/Billing/BillingAccess.php
public function state(Organization $org): OnboardingBillingState
{
    $sub = $org->subscription('default');
    $entitled = $sub instanceof Subscription
        && $this->subscriptions->deriveEntitlement($sub)->entitled;
    if ($entitled) return OnboardingBillingState::Subscribed;          // ← plan_code を見ない
    if ($org->free_plan_code === PersonalPlanService::FREE_PLAN_CODE)
        return OnboardingBillingState::ActiveFreePlan;                  // ← free は別状態
    if ($sub instanceof Subscription) return OnboardingBillingState::ExpiredCheckout;
    …
    return $hasExpired ? OnboardingBillingState::ExpiredCheckout : OnboardingBillingState::NoSubscription;
}
```
```php
// /tmp/aigenba/app/Enums/Billing/OnboardingBillingState.php
enum OnboardingBillingState: string {
    case NoSubscription; case PendingCheckout; case ExpiredCheckout; case Subscribed; case ActiveFreePlan;
    public function grantsAccess(): bool { return $this === self::Subscribed || $this === self::ActiveFreePlan; }
}
```
→ 私の「4 variant EffectivePlan」は **aigenba の 5 状態の劣化コピー**だった。`ActiveFreePlan` ≡ 私の
`GrandfatheredLegacyFreePlan`、`NoSubscription` ≡ 私の `NoPlan`。**最初から移植していれば #2 は存在しない**。
また `entitled` 判定が subscription 由来なので、**同期ラグ組織も支払い不健全組織も aigenba では正しく扱われる**（#4）。

**D18 の根拠が崩れていた**: 私は「AI-CUE に subscription checkout session テーブルが無く Pending/ExpiredCheckout を
表現できない = 移植対象が存在しない」と書いたが、**P9 でそのテーブルを追加する設計**だった。自分で前提を壊していた。

### #3 / #5: aigenba `TicketService::balance()` — clamp して debt を持たない

```php
return new TicketBalanceDto(
    monthlyRemaining: max($monthly, 0),      // ← clamp
    purchasedRemaining: max($purchased, 0),  // ← clamp
    …);
// reserve も同様: $availableMonthly = max($monthly - $reservedMonthly, 0);
```

### #7: aigenba `PlanSeeder` — Personal は公開

```
* - Personal は個人向け 1 席固定 (= 席を増やせない組織)・`is_active=true` で公開する
```

## 【訂正 2026-07-17】下記「aigenba に実在する問題」は **aigenba 側の実行検証で不成立と判定された**

私の再現手順が自己矛盾していた（消費優先 monthly → purchased のため、月次を持つ前提では purchased は消費されず
`-10` にならない。先方実行ログ: `ACTUAL = 0`）。「悪用の敷居が低い」も誇張だった（アプリ内に返金作成経路なし）。
詳細と先方の結論は `aigenba-handoff.md` の CLOSED 注記を参照。

**ただし検証の副産物として、監査が見落としていた最大の差分が判明した**:

| | aigenba | AI-CUE 現行 |
|---|---|---|
| 月次チケット付与 | **全 tier `included_monthly_tickets = 0` = 廃止**（施策8/v3「チケットは都度購入 / オートリチャージ」） | **生きている**（`monthly_ticket_grant`: free 10 / standard 100、`invoice.paid` で発火） |

**課金モデルの根本が違う**。aigenba =「サブスク + チケットは都度購入/裏チャージ」、AI-CUE =「サブスク + 月次付与 + スポット購入」。
先方の「clamp は no-op だから対応不要」という論拠は **「月次付与が廃止済み」という前提に立つため、AI-CUE には成立しない**。
**月次付与を残したまま per-source clamp だけ移植すると、aigenba では死んでいる経路が AI-CUE では生きる**。
→ **clamp の移植と月次付与の廃止はセットでしか成立しない**（D28）。

## （不成立）逆方向の発見: aigenba に実在する問題（揃えると継承する）

**clamp による「タダ乗り経路」は aigenba に実在する。**
`clawbackPurchasedByPaymentIntent()` は購入枚数を上限に負の ledger 行を計上するが、**既に消費済みだと
`purchased` が負に沈み、`balance()` の `max(…,0)` で債務が消える**（表示からも `reserve()` の与信からも）。
結果、buy → consume → refund で**ハウスが損をする**。

- **AI-CUE の現行はむしろ厳格**（単一 int 残高で負を引きずる。`TicketRefundClawbackTest:147` が `-2` を期待）。
- **方針（揃えてから調整）に従い、まず aigenba に揃える**（clamp を移植し debt 概念を持たない）。
  `TicketRefundClawbackTest:147` の期待は **`-2` → `0`** へ更新し、**意図的な挙動変更**として記録する。
- **タダ乗り経路は「aigenba にも存在する既知の問題」として parity 後に調整する別 TODO**（AI-CUE 単独の逸脱として
  先回り実装しない = 私が debt を発明して 2 件のバグを作った失敗の再発を防ぐ）。

## コスト見積もり（U1 の判断根拠。「合わない可能性」で止めず実測した）

| | AI-CUE 実測 | aigenba |
|---|---|---|
| 1 単位の消費 | 解析 **1 枚**（`manual.analysis_ticket_cost=1`）+ レンダ **3 枚**（`render_ticket_cost=3`）= **マニュアル 1 本 4 枚** | 1 encounter = 1 枚 |
| signup grant | **10 枚**（`billing.signup_grant_tickets`）= 2.5 本 | **10 枚**（同一） |
| 低残高閾値 | **`ticket_low_balance_threshold = 5`（既存）** | `auto_recharge.default_threshold = 5` |
| 月次付与 | free/personal **10**（2.5 本）/ standard **100**（25 本） | — |
| 単価 | 1枚100円 / 20枚80 / 50枚70 / 100枚65 / 500枚50（floor 50） | — |

**判定: aigenba の既定値（threshold 5 / max 50）をそのまま採用して問題ない。**
- **閾値 5 は AI-CUE 自身が既に採用している値**（`ticket_low_balance_threshold=5`）と**完全一致**。齟齬なし。
  最大単発コスト（レンダ 3 枚）に対しても、閾値 5 は補充実行中に 1 ジョブ分の余裕を残す。
- **上限 50 枚 = マニュアル 12.5 本 / 50枚 tier で約 3,500 円 / standard 月次付与（100）の半分**。妥当な範囲。
- → **U1 は「aigenba 既定値を採用」で確定**（人判断待ちから外す）。「可変コストだから合わないかもしれない」は
  実測せずに言っていた憶測だった。

## この検証から確定する方針転換

**私が持ち込んだ「parity より良い設計」を全て撤回し、aigenba verbatim へ戻す。**

| 撤回する私の発明 | 戻す先（aigenba verbatim） |
|---|---|
| D18 / D23（`EffectivePlan` 4 variant の新設） | **`OnboardingBillingState`（5 状態）+ `BillingAccess::state()` をそのまま移植** |
| D26（`plan_code` 依存の解決順） | **`subscription('default')` + `deriveEntitlement()` で判定**（`plan_code` を見ない） |
| D19 / D24 / D27（debt 保全・数式） | **`max(…, 0)` の clamp をそのまま移植**（debt 概念を持たない） |
| D10（personal/starter を `is_active=false` で seed） | **`is_active=true` で公開**（`PlanSeeder` verbatim） |
| U1（既定値の再検討） | **aigenba 既定値（5 / 50 / 1000 / 3）を採用** |

**維持する逸脱（根拠が「AGENTS.md の不変条件」であり、私の設計判断ではないもの）**:
- reserve は amount ベース（AI-CUE は可変コスト消費。不変条件ではないが**ドメイン要件**。Codex も妥当と判定）
- reserve→commit/release の 2 フェーズ維持（AGENTS.md 不変条件 #7。**aigenba も同じなので実は逸脱ですらない**）
- disabled ボタンを移植しない（AGENTS.md 禁止事項 #8）
- 請求先 PII は email + name 両方 CipherSweet（AGENTS.md 不変条件 #6）
