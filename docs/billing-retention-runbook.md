# 課金記録の保持期間 (7 年) 運用 runbook

> 対象コマンド: `php artisan billing:purge-retention-expired [--apply] [--target=...]`
> 設計: `devnotes/20260809-0908-account-deletion-grace/detailed-design.md` (PR-C1 / PR-C2)
> 保持年数の正本: `config/legal.php` の `billing_retention_years` (唯一の解決点は
> `App\Support\Legal\BillingRetention`)

## 1. これは何をするコマンドか

保持期限 (既定 **7 年**) を超えた課金記録を **target ごとに決着**させる。

| 決着の方式 | target | 何が起きるか |
|---|---|---|
| 物理削除 | `stripe_webhook_event` / `billing_checkout_session` / `ticket_checkout_session` / `ticket_auto_recharge_attempt` / `subscription_item` / `subscription` | 行が消える |
| **畳み込み** | `ticket_ledger_entry` | 行が消え、`(organization_id, source, expires_at)` ごとの **残高スナップショット 1 行** (`kind = carry_forward`) に置き換わる |

台帳 (`ticket_ledger_entries`) だけ方式が違うのは、**そこが残高の真実源**だからである。
古い行をそのまま消すと残高が変わる (= 利用者のチケットが増減する)。畳み込みは
**残高を 1 枚も変えずに個別取引の情報だけを落とす**操作で、
`tests/Feature/Billing/TicketLedgerCarryForwardTest.php` が残高保存を機械固定している。

**既定は dry-run**。`--apply` を付けたときだけ実処理が走る。

## 2. 出力の読み方

```
[apply] 保持期間 7 年 / 閾値 2019-08-10 11:45:00 以前の起算日時が期限超過
  stripe_webhook_event: expired=12 processed=12 fail_closed=0 unexpected_failures=0 remaining=0
  ...
  ticket_ledger_entry: expired=340 processed=340 fail_closed=0 unexpected_failures=0 remaining=0
合計: 決着 352 件 / 残存 (期限超過) 0 件 / fail-closed 0 件
horizon: OK (期限超過 0 件)
```

| 項目 | 意味 |
|---|---|
| `expired` | 起算済み (起算列が非 null) かつ保持期限を超えた件数 |
| `processed` | 実際に決着した件数 (削除 または 畳み込みで消えた行数) |
| `fail_closed` | **安全のため残した**件数。(a) 起算列が null で補助時計が古い異常、(b) 参照中で消せないもの |
| `unexpected_failures` | 想定外の失敗。**件数の 0 は信用できない**という印 |
| `remaining` | 決着後に残った期限超過の件数。**`unexpected_failures=0` のときだけ信用できる** (失敗した target は数えられず 0 で報告される) |
| `horizon:` | **規約 (最長 7 年) を満たしているか**の観測点。**OK / NG / 判定不能** の 3 値 (下記) |

`horizon:` の 3 値:

| 値 | 条件 | 意味 |
|---|---|---|
| `OK` | 失敗 0 件 かつ `remaining` 合計 0 | 規約を満たしている |
| `NG` | 失敗 0 件 かつ `remaining` 合計 > 0 | 期限超過が残っている (§5) |
| `判定不能` | `unexpected_failures > 0` の target が 1 件でもある | **満たしているか確認できていない**。失敗した target の件数は数えられず 0 で報告されるため、`remaining` 合計 0 を根拠に OK と読んではならない |

- **出力に PII は出さない** (organization id / メールアドレス / 金額 / Stripe 識別子を載せない)。
  調査で個別の行に降りる必要が出たら、コマンドの出力ではなく DB を直接見ること。
- **終了コードは 2 分類**。`unexpected_failures > 0` なら `FAILURE`、それ以外は `SUCCESS`。
  **`fail_closed` が残っていても SUCCESS である** (安全に残したのは異常ではない)。

> ⚠ **`fail_closed` は「安全に残した」であって「規約を満たした」ではない**。
> 規約が宣言した年数を満たしたと言えるのは `horizon: OK` (= `remaining` 合計 0) のときだけである。
> `fail_closed` を「対処済み」として扱わないこと。

## 3. 日次スケジュール

`routes/console.php` に登録済み:

```php
Schedule::command('billing:purge-retention-expired --apply')->daily()->onOneServer();
```

`onOneServer()` は **scheduler が動いていること + ロックを提供する cache driver** を前提にする
(既存の `billing:send-billing-reminders` / `idempotency:prune` と同じ前提)。

## 4. 有効化の手順 (PR-C2 デプロイ時に 1 回)

1. **PR-C1 の dry-run で棚卸しする** — `php artisan billing:purge-retention-expired`
   (target 別件数と `unexpected_failures` を確認する)
2. **PR-C2 をデプロイする** (台帳の畳み込みと `--apply` が入る)
3. **初回 apply を能動的に実行する** — `php artisan billing:purge-retention-expired --apply`
   - schedule は既に有効なので、これは「初回を見届ける」ための能動実行である
     (schedule を抑止する意味ではない。抑止機構は持たない)
4. **apply 後の horizon を確認する** — 出力の `horizon: OK (期限超過 0 件)`
   - **`fail_closed` を含めて 0 件**であることを確認する (分類を問わない)
5. **4 が満たされて初めて PR-C3 (規約文面の公開) を出す**
6. 日次 scheduler を**継続監視へ移す** (§6)

### PR-C3 のチェックリスト (必須)

C3 の PR 説明に **初回 apply の出力の証跡**を貼ること:

- [ ] target 別件数 (`expired` / `processed`)
- [ ] `fail_closed` = 0
- [ ] `unexpected_failures` = 0
- [ ] `horizon: OK (期限超過 0 件)`

証跡が無いまま C3 を出すと「規約が宣言した年数を実処理が満たしていない状態で文面を公開する」
ことになる。これは利用者から見て検証不能な形の規約違反である。

## 5. `fail_closed` が続くときの解消手順

`fail_closed` は 2 種類ある。**まずどちらかを切り分ける**。

### (a) 起算列が null で補助時計が古い (起算不能の異常)

例: `processed_at IS NULL` のまま 7 年経った webhook 記録。
「取引が決着していない記録を、決着したことにして捨てない」ため消していない。

1. 当該 target の行を DB で確認する (`processed_at IS NULL AND created_at <= 閾値`)
2. **なぜ起算されなかったか**を特定する (処理の取りこぼし / 例外で終わった / 手動投入)
3. 起算列を正しい値で埋めるか、業務上「決着済み」と判断できるなら記録として決着させる
4. 再実行して `fail_closed` が減ることを確認する

### (b) 参照中で消せない (子が残っている)

例: 明細 (`subscription_items`) が残っている `subscriptions`。
FK は cascade なので DELETE 自体は成功するが、それは**子 purger が決着させられなかった行を
件数報告を経由せず道連れにする**ことを意味するので、残して報告する側を採っている。

1. 子 target の `fail_closed` / `unexpected_failures` を先に見る (原因は子側にあることが多い)
2. 子を決着させてから親を再実行する (registry の実行順は **子 → 親**。入れ替えないこと)

### 件数が単調増加しているときの初動

`fail_closed` が日々増えている = **新しい異常が継続的に生まれている**ということである
(過去の残骸ではない)。

1. 増加している target を 1 つに絞る (`--target=` の dry-run)
2. 直近で追加された行の生成経路を特定する (webhook の失敗 / 決済フローの中断)
3. **保持期間の処理ではなく生成側の不具合**として扱う。purge 側の閾値や分類を緩めて
   件数を減らさないこと (fail-open になる)

## 6. 監視対象

**本コマンドの終了コードと出力の `horizon:` 行**を監視対象に登録する。

- `FAILURE` (= `unexpected_failures > 0`) … 件数報告そのものが信用できない状態。即調査
  (このとき `horizon:` は `判定不能` になる。**`OK` は出ない**)
- `horizon: NG` が**継続** … 規約 (/privacy が宣言する最長 7 年) を満たせていない状態
- `horizon: 判定不能` … 規約を満たしているか**確認できていない**状態。`NG` と同等以上に扱う
  (「満たしていないと分かっている」より悪い = 何件残っているかも分からない)
- `fail_closed` の**継続・増加** … 正常成功として扱わない (§5)

## 7. 台帳の畳み込みで**失われるもの** (誇張しない)

畳み込み後、7 年より古い台帳行については以下が**復元できない**。これは不具合ではなく
保持期間の意味そのものだが、依存している機構があるので明記する。

- **原取引の識別子** — 説明 / `stripe_checkout_session_id` / `stripe_invoice_id` /
  `payment_intent_id` / `purchase_amount` / `reservation_id` / 個別の `created_at`
- **返金逆仕訳** (`clawbackPurchasedByPaymentIntent`) は畳み込まれた購入行を引けない
  (7 年より古い決済への遅延返金は現実には起きないが、「引ける」とは言えない)
- **消費の冪等キー** (`consume:{reservationId}`) が消えるため、7 年前の予約を今 commit すると
  二重計上を防げない (予約 TTL は 30 分であり到達しない)
- **signup grant の部分 UNIQUE index** (`idempotency_key LIKE 'signup_grant:%'`) は
  畳み込まれた行を守らない。ただし「org 生涯 1 回」の**正本は
  `organizations.signup_tickets_granted_at` の条件付き UPDATE** であり、これは畳み込みの
  対象外なので不変条件そのものは維持される (index は保険であって正本ではない)
- **合計 0 の group は繰越行を作らない**ため、その group の `expires_at` が台帳から消える。
  「未失効の monthly が完全に消費済み」という組み合わせでのみ `nearestMonthlyExpiry` の
  探索結果が変わる (**残高は不変**。既知窓としてテストで固定してある)

## 8. 関連

- `docs/account-deletion-runbook.md` — 退会 (アカウント削除) の運用
- `docs/inquiry-deletion-runbook.md` — 問い合わせの保持期間 (別概念・別所有者)
- `docs/architecture.md` §課金記録の保持期間 (7 年) の決着
