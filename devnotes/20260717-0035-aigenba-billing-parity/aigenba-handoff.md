# aigenba への引き継ぎ: チケット会計で返金債務が回収されない経路

> ## 【CLOSED / 2026-07-17】aigenba 側で実行検証され、**指摘は不成立**と判定された。**対応しない**（先方決定）。
> **本レポートの誤り（こちらの非）**:
> 1. **再現手順が自己矛盾していた**。消費優先は monthly → purchased のため、前提の「月次 10 枚を持つ」を満たす限り
>    消費は全て monthly から引かれ、`purchased` は `+10` のまま残る → 返金 `-10` で **`0`**。`-10` にはならない。
>    先方の実行ログ: `REPORT CLAIMS purchased_raw = -10, ACTUAL = 0`。step 5 の「availableMonthly は 10 のまま」も、
>    step 2 で monthly を消費している以上ありえない。**コード読解だけで断定し、手順の内部矛盾に気づけなかった**。
> 2. **「悪用の敷居が低い」は誇張**。aigenba にアプリ内の返金作成経路は存在せず（`Refund::create` 相当 0 件）、
>    顧客は自力で返金を発生させられない。現実的な経路は「運営が消費済み枚数を確認せず過大返金する」オペミスのみ。
>
> **先方の結論と根拠**: **aigenba は月次付与を廃止済み**（施策8/v3: 全 tier `included_monthly_tickets = 0`。
> チケットは都度購入 / オートリチャージ）。`CreditSource::PlanMonthly` で行が入るのは **signup grant の 10 枚のみ**
> （30 日期限・T998 で org 生涯 1 回）。消費優先 monthly → purchased のため **purchased が消費される時点で signup 分は
> 必ず尽きており、債務発生後に新しい PlanMonthly が届く経路が無い**。よって **source 別 clamp は現行モデルでは実質 no-op** で、
> 負残高の org は `availableTrueBalance = 0` でブロックされる（= こちらが望んだ厳格な回収そのもの）。
> 運用ルールで回避（返金時は消費済み枚数を確認し未消費分のみ返金）。**将来 `included_monthly_tickets > 0` を復活させても無視**する方針。
>
> **AI-CUE 側への影響（重要）**: 先方の「到達不能」論拠は **「月次付与が廃止済み」という前提に立つ**。
> **AI-CUE は月次付与が生きている**（`monthly_ticket_grant`: free 10 / standard 100、`invoice.paid` で発火）ため、
> **この前提は AI-CUE には成立しない**。月次付与を残したまま per-source clamp だけ移植すると、
> **aigenba では死んでいる経路が AI-CUE では生きる**（月次が債務の逃げ道になる）。
> → **clamp の移植と月次付与の廃止はセットでしか成立しない**。parity 方針に従い **AI-CUE も月次付与を廃止する**
> （`PlanSeeder.monthly_ticket_grant` を全 tier 0。`grantMonthlyTickets` は既存 guard `monthly_ticket_grant <= 0` で
> 抜けるため、aigenba の `if ($count < 1) return;` と同形になる）。
>
> **先方からの確認事項**: verbatim 移植で問題なし・往復は発生しない・`TicketRefundClawbackTest` の `-2` → `0` 更新も可。
> `refundedTicketCount()` の `intdiv` floor は常にユーザー有利側で一貫（実害なし）。負行の `expires_at = null` は確認どおり。

> 経緯: AI-CUE の決済ドメインを aigenba へ全面 parity させる作業（`devnotes/20260717-0035-aigenba-billing-parity/`）の中で、
> aigenba の `TicketService` を実コード読解した際に見つけたもの。**AI-CUE 側では aigenba verbatim で移植する**（先回りで
> 独自修正しない）。**aigenba 側で修正されたら、その修正を AI-CUE へ取り込む**。
>
> 検証環境: aigenba working copy（`app/Services/Billing/TicketService.php`）。実行による再現はしておらず、**コード読解による
> 指摘**である。再現手順は下記に書いたので、そちらで検証してほしい。

## 要約

**買い切りチケットを「購入 → 消費 → 返金」された場合、`purchased` 残高が負に沈むが、
その債務が (1) UI/DTO から見えず、(2) 月次クレジットの利用可能枠を減らさず、(3) 同一 source の再購入がない限り
永久に回収されない。** 結果としてハウスが損をし、かつ**運用側から検知できない**。

## 該当コード

### 1. 残高集計は負を許す（clamp しない）

```php
// app/Services/Billing/TicketService.php:1045-1054
private function sumBalance(int $orgId, CreditSource $source, CarbonImmutable $now): int
{
    return (int) TicketLedgerEntry::query()
        ->where('organization_id', $orgId)
        ->where('source', $source->value)
        ->where(function ($q) use ($now): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
        })
        ->sum('amount');          // ← 負の clawback 行を含むため負になりうる
}
```

### 2. 表示は per-source で clamp する（債務が消える）

```php
// app/Services/Billing/TicketService.php:312-342 balance()
return new TicketBalanceDto(
    monthlyRemaining: max($monthly, 0),        // ← 負を 0 に潰す
    purchasedRemaining: max($purchased, 0),    // ← 負を 0 に潰す
    …
);
```

### 3. 与信も per-source で clamp する（債務が月次に波及しない）

```php
// app/Services/Billing/TicketService.php:391-395 reserve()
$availableMonthly   = max($monthly   - $reservedMonthly,   0);
$availablePurchased = max($purchased - $reservedPurchased, 0);   // ← 負は 0 扱い
…
if ($availableMonthly + $availablePurchased < $amount) { /* 不足 */ }
```

### 4. clawback は購入枚数を上限に負の行を計上する（ここは正しい）

```php
// app/Services/Billing/TicketService.php:863 clawbackPurchasedByPaymentIntent()
//   → refundedTicketCount(amount, count, amountRefunded) で「逆仕訳すべき累積枚数」を出し、
//     既逆仕訳との差分だけ負の ledger 行を計上（累積差分・冪等）。上限は purchase->count。
```

## 再現手順（未実行。要検証）

前提: org は月次付与 `PlanMonthly` を持つ（例 10 枚）。

1. `Purchased` を 10 枚購入 → `purchased_raw = +10`
2. 10 encounter を消費し commit（消費優先で `Purchased` から引かれるケース）→ `purchased_raw = 0`
3. 当該 charge を Stripe で全額返金 → `charge.refunded` webhook → `clawbackPurchasedByPaymentIntent()` が
   `-10` の ledger 行を計上 → **`purchased_raw = -10`**
4. `balance()` → `purchasedRemaining = max(-10, 0) = **0**`（**債務が UI からも DTO からも消える**）
5. `reserve()` → `availablePurchased = max(-10 - 0, 0) = **0**`。
   **`availableMonthly` は 10 のまま**なので、**月次クレジット 10 枚をそのまま使い切れる**
6. その org が二度と `Purchased` を買わなければ、**`-10` は永久に回収されない**

**期待していた挙動**: 消費済みチケットの返金で生じた債務が、以後の付与/購入から回収されるか、
少なくとも運用側に可視化されること。

## 影響

| 観点 | 内容 |
|---|---|
| 金銭 | 「購入 → 消費 → 返金」で**役務がタダになる**。返金は Stripe 側の操作だけで成立するため、悪用の敷居が低い |
| 検知 | `balance()` が clamp するため **UI・DTO・サポート画面のいずれからも負債が見えない**。`ticket_ledger_entries` を直接 SQL で見るしかない |
| 回収 | 同一 `Purchased` source の再購入時のみ `sumBalance` の生合計で相殺される（= **買い直した人だけが債務を払う**。逃げた人は払わない） |
| 波及 | source 別 clamp のため、**月次クレジットの与信には一切効かない**。月次だけで回る org は債務があっても無制限に使える |

## 参考: AI-CUE 現行実装との差（どちらが厳格か）

AI-CUE は現行、残高を**単一 int**（`SUM(未失効 delta) − SUM(reserved)`）で持ち、**source 別 clamp をしていない**。
そのため**債務が総額に波及し、月次クレジットの与信からも差し引かれる**（= aigenba より厳格に回収する）。
現行テスト `tests/Feature/Billing/TicketRefundClawbackTest.php:147` は購入→消費→全額返金後の残高に **`-2`** を期待している。

**AI-CUE 側の対応方針（決定済み）**: parity を優先し、**aigenba verbatim（per-source clamp）へ寄せる**。
上記テストの期待は **`-2` → `0`** へ更新し、**意図的な挙動変更**として記録する。**aigenba 側で本件が修正されたら、
その修正を AI-CUE へ取り込む**（AI-CUE 単独で先回り修正はしない）。

## 修正の選択肢（aigenba 側の判断材料。優劣は付けない）

1. **債務を DTO に出す**: `TicketBalanceDto` に `debt`（正数）を足し、表示・サポートから見えるようにする。
   与信ロジックは据え置き（可視化のみ）。最小変更だが回収はしない。
2. **与信を source 横断で相殺する**: 表示は clamp のまま、`reserve()` の与信計算だけ
   `availableTrueBalance = max(monthlyPositive + purchasedPositive - debtAmount, 0)` のように債務控除後にする。
   月次からも回収される（= AI-CUE 現行に近い挙動）。
   - 注意: **付与時に grant の `delta` を減らす形で相殺しないこと**。`sumBalance` が生合計なので、
     付与額を減らすと**台帳合計と二重に相殺されて過剰回収**になる（AI-CUE 側の設計レビューで実際に踏んだ罠）。
     相殺するなら**残高計算側で一度だけ**。
3. **書き込み側で負を作らない**: clawback 時に残高が不足する分は ledger を負にせず、
   `receivable`（未収）として別テーブルに退避し、以後の付与/購入時に相殺する。会計としては最も素直だが変更は最大。

## 併せて確認してほしい点（未検証・低確度）

- `refundedTicketCount()` は `amountRefunded >= amount` で全枚数を返す。**部分返金の按分は `intdiv` で floor** される
  （`min($count, intdiv($amountRefunded * $count, $amount))`）。端数の切り捨てが常にユーザー有利側か、
  意図どおりかは確認の価値があるかもしれない。
- ~~負の clawback 行が失効して債務が消える可能性~~ → **確認済み・問題なし**。`insertLedgerIgnoreDuplicate([… 'amount' => -$delta,
  'expires_at' => null …])` で**負行は `expires_at = null` 固定**のため、`sumBalance` の未失効フィルタで落ちることはない
  （債務は失効しない）。
