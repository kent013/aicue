# 対応マトリクス: design-review Round 6

Round 6 は**確認ラウンド**。Round 5 の [Critical] 1 件 / [Warning] 2 件 / [Suggestion] 1 件は
**すべて解消**と判定された。ただし対応が持ち込んだ新規 [Critical] が 1 件出たため、
これに対応して Round 7 (上限) を回す。

## Round 5 指摘の判定結果 (Codex)

| 指摘 | 判定 |
|---|---|
| S4 [Critical] Stripe preflight の配置を赤化できるテストシームが無い | 解消 |
| S4 [Warning] 新設メソッドの列挙 | 解消 |
| S7 [Warning] Stripe の「配置を保証する」主張 | 解消 |
| S6 [Suggestion] 期待集合の重複検査 | 解消 |

## [Critical] (新規) `preflight 2 の canceled 分岐 (既作成 invoice の終端)` に決定論的な注入点が無い

- 判断: **対応する (設計変更)**
- 根拠: 妥当。自分で追っても同じ結論になる:
  - `duringCreateInvoice` は `createAutoRechargeInvoice()` の内側 = **attach より前**に発火するため、
    再現できるのは `attach 0 行` の競合だけ。
  - 一方 `FakeAttemptOwnershipPreflight` が「DB を触らず false を返すだけ」の fake だと、
    行は Pending のままなので `terminateInvoiceAfterOwnershipLost()` は `Canceled` 限定の
    early return に落ち、**canceled 分岐 (invoice を終端する) が 1 度も実行されない**。
  - 実際 Round 6 直前の設計はこの矛盾を「placement テストでは `terminated === []` を期待する」
    と書いて回避しており、canceled 分岐の behavioral 固定が抜けていた。
- 対応内容: Codex 提案どおり、fake の責務を「**判定の差し替え**」から
  「**競合の注入**」へ変える (本番コードは 1 行も変えない):
  - `FakeAttemptOwnershipPreflight` を
    `$terminalizeAt: list<ExternalCallKind>` / `$terminalStatus: AutoRechargeAttemptStatus` /
    `$calls: list<string>` を持つシームにし、
    **該当 checkpoint に到達したら attempt 行を条件付き UPDATE で terminal 化してから
    `parent::stillPending()` へ委譲する**。
  - これで判定・`refresh()`・所有権喪失ログは**常に本番実装が実行**される
    (fake が verdict を騙らないので、テストが実装から乖離しない)。
  - 得られる決定論的インターリーブ:
    - `terminalizeAt=[StripeInvoiceCreate]` → 冒頭 guard 通過 → preflight 1 直前に canceled →
      **invoice を作らない**。preflight 1 を削除すると行が Pending のままなので invoice が
      作成され赤化する (**M16**)。
    - `terminalizeAt=[StripeInvoicePay]` → create → **attach 1 行** → preflight 2 直前に canceled →
      pay を抑止し、**`terminateInvoice` が 1 回呼ばれる**。preflight 2 を削除すると
      terminal 化自体が起きず pay が走って赤化する (**M17**)。
      = Round 5 が要求した 2 つのインターリーブと、新規 Critical の canceled 分岐が
      **同じ 1 本のシーム**で成立する。
    - `terminalStatus` を `Failed` / `Paid` に切り替えれば、後始末の非終端分岐
      (二重終端の抑止 / void 不可) も同じシームで固定できる。
  - 抑止セクションにあった `preflight 2: paid のとき` / `failed のとき` の 2 ケースは
    配置セクションへ**移動**した (同じことを 2 通りの再現手段で書かない。
    AGENTS.md 思考原則 2 / 3)。
  - `FakeAutoRechargeGateway::$duringCreateInvoice` は**残す** — こちらは
    「create 成功と attach の間」という別の競合点 (attach 0 行) の担当で、
    preflight シームでは再現できない。

## 反論・見送り

- なし (今ラウンドで反論した指摘は無い)。
