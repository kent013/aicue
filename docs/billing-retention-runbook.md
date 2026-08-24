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
| **畳み込み** | `ticket_ledger_entry` | **判定は 2 段**。既に失効した行は繰越に含めず物理削除し、まだ残高に寄与する行だけが `(organization_id, source, expires_at)` ごとの **残高スナップショット 1 行** (`kind = carry_forward`) に置き換わる |

台帳 (`ticket_ledger_entries`) だけ方式が違うのは、**そこが残高の真実源**だからである。
古い行をそのまま消すと残高が変わる (= 利用者のチケットが増減する)。畳み込みは
**残高を 1 枚も変えずに個別取引の情報だけを落とす**操作で、
`tests/Feature/Billing/TicketLedgerCarryForwardTest.php` が残高保存を機械固定している。
繰越行の `created_at` は**畳み込んだ行の最大 `created_at`** (集約の基準時刻) なので、
繰越行は次回も保持期限以前に留まり**集約単位ごとに 1 行へ収束する**。
**母集団は退会 (論理削除) 済み組織の台帳も含む** (課金記録の保持義務は退会より寿命が長い)。

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
| `expired` | 起算済み (起算列が非 null) かつ保持期限を超えた**決着対象**の件数 |
| `processed` | 実際に決着した件数 (**決着対象のうち消えた行数**。台帳の畳み込みが再集約のために消して作り直した寄与中の繰越行は数えない) |
| `fail_closed` | **安全のため残した**件数。(a) 起算列が null で補助時計が古い異常、(b) 参照中で消せないもの |
| `unexpected_failures` | 想定外の失敗。**件数の 0 は信用できない**という印 |
| `remaining` | 決着後に残った**決着対象**の件数。**`unexpected_failures=0` のときだけ信用できる** (失敗した target は数えられず 0 で報告される) |
| `horizon:` | **規約 (最長 7 年) を満たしているか**の観測点。**OK / NG / 判定不能** の 3 値 (下記) |

`horizon:` の 3 値:

| 値 | 条件 | 意味 |
|---|---|---|
| `OK` | 失敗 0 件 かつ `remaining` 合計 0 | 規約を満たしている |
| `NG` | 失敗 0 件 かつ `remaining` 合計 > 0 | 期限超過が残っている (§5) |
| `判定不能` | `unexpected_failures > 0` の target が 1 件でもある | **満たしているか確認できていない**。失敗した target の件数は数えられず 0 で報告されるため、`remaining` 合計 0 を根拠に OK と読んではならない |

> **「決着対象」の語の正本は `App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto` の
> docblock** である (本書には写さない。2 か所に書くと必ず食い違う)。要点だけ言えば、
> **いま継続状態を表している集約レコードは含まない** — 台帳では
> `kind = carry_forward` の繰越行のうち**まだ残高に寄与しているもの**だけが対象外であり、
> **失効した繰越行は決着対象に含まれる** (残高に寄与しなくなった時点で物理削除の対象である)。
> 他の 6 target は集約レコードを持たないので実効値は変わらない。
>
> **想定外の失敗が 0 件で、かつ実行中に決着対象が増えていない**なら
> **`expired = processed + remaining`** が成り立つ (失敗した単位は巻き戻るので
> `remaining` 側に残る)。崩れていたら **(a) 決着対象の定義と実処理のずれ** か
> **(b) 実行中に新しい期限超過レコードが commit された** (台帳の追記経路の一部は
> 保持処理と排他しない) のどちらかである。**(a) と断定しないこと**。

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
- **失効済みの明細は繰越にも残らず物理削除される** (正典 v1 の第 2 段の寄与判定)。
  失効した窓は集約の単位として残らないので、**繰越行は集約単位ごとに 1 行へ収束する**
  (v0 は失効済みの窓ごとに繰越行が増え続けていた)。失効時刻そのものの記録は失われる
  — 残高に 1 枚も寄与しない情報であり、保持期限の決着として消えるのが正しい

## 7b. 申し送り (繰越行の保持分類) — **オーナー / 法務の確認待ち**

**状態: 未確認 (2026-08-24 時点)。決定主体はオーナー (必要に応じて法務)。**
エージェントは確認を行えないため、ここに申し送りとして記録する。

**技術設計上の分類**: 繰越行は「取引関係書類」ではなく
**継続中の契約に紐づく現在残高**として扱っている。**これは設計上の分類であり、
法的分類ではない**。プライバシー文面 (`/privacy`) との最終的な整合は
オーナー / 法務の確認を**実装・リリースの前提条件**とする。

**機械で固定していること**:

- **繰越行は取引の明細を 1 列も持たない** (列分類 5 条。
  `TicketLedgerCarryForwardService::VALUED_COLUMNS` / `NULL_COLUMNS` が正本で、
  「両者の和 == 実スキーマの全列」を deny-by-default で突き合わせる。
  表に列を足したら必ずどちらかへ分類することになる)
- **収束**: 同じ閾値で 2 回実行しても繰越行は増えない
- **有界性**: 失効済みの窓を N 個置いても畳み込み後の行数が N に依存しない

**機械で固定していないこと**: **法的分類**。データの形は固定できるが、
その形が「取引関係書類等」に当たるかどうかは固定できない。

**確認事項 (4 点)**:

1. 「契約終了」と `Organization` 行の削除 (論理 / 物理) のタイミング差をどう扱うか
2. 契約終了後も `Organization` を残す場合、繰越行をいつまで持つか
3. 集約済みの `delta` / `source` / `expires_at` / `created_at` が「取引関係書類等」に当たるか
4. 契約終了後に残高そのものを保持する必要があるか

**実態**: `Organization` は `SoftDeletes` で `app/` 配下に `forceDelete` の呼び出しは
1 件も無い。したがって `ticket_ledger_entries.organization_id` の
`cascadeOnDelete` は通常運用では発火せず、**繰越行は退会後も残る**。
これは `docs/template-divergence.md` D23 が宣言した「課金記録の保持義務は退会より寿命が長い」
という設計そのものであるが、**「契約終了で消える」という説明は成り立たない**。
なお D23 の不変条件は v0 の実装では守られていなかった (退会組織の台帳が畳まれず、
`horizon` が恒久的に NG になる経路が実在した)。T259 でこれを是正し、
`tests/Feature/Billing/TicketLedgerCarryForwardTest.php` の N12〜N14 が機械で受ける。
**`/privacy` の文面は T259 の PR では変更していない** (新しい法的主張をしない)。

**再判定条件**:

- 法務が台帳の行そのものを取引関係書類と判定したとき
- 繰越行へ取引情報を載せる要件が出たとき

**許容されないと判定された場合の退路**: 繰越行にも保持期限を課す =
残高を台帳とは別の表で持つ再設計になる。これは本 feature の射程外であり、
先回りして作らない (AGENTS.md 思考原則 2)。

## 7c. `carried_forward_through` 撤去のデプロイ順序 (**この節が順序の正本**)

正典 v1 では繰越行の `created_at` が集約の基準時刻なので、集約終端の専用列
`carried_forward_through` は役割を失う。T259 で drop migration
(`2026_08_24_100000_drop_carried_forward_through_from_ticket_ledger_entries`) を足した。

- **順序は「新コード → drop migration」に固定する**。逆順 (drop 先行) にすると、
  まだ動いている旧コードが `MAX(carried_forward_through)` を SELECT し、
  繰越行の INSERT で同列に書き込むため `Undefined column` で落ちる。
- **drop 後に旧コードへ単純 rollback できない**。戻すなら**先に `down()` で列を戻してから**
  旧コードへ戻す。
- `down()` は列を戻すが**値は復元しない** (旧形の意味を持つ値を作れないため、すべて null)。
  既存の繰越行は「終端が未記録」として扱われる。さらに **v1 が作った繰越行は
  `idempotency_key` が null** なので、旧コードへ戻して同じ集約キーを再処理したときの挙動は
  旧状態と同一にならない — 「**列の値が戻らない**」だけでなく
  「**アプリケーションの状態の意味も完全には復元されない**」。
- migration 先行が避けられない基盤なら maintenance window か手順の変更が要る。
  **本リポジトリにデプロイ定義は無い**ので、現状この手順書が唯一の担保である。
- 列を足した migration (`2026_08_10_114500`) は**消さない** (消すと新規環境で drop が失敗する)。
- **v0 形の繰越行のデータ移行は置いていない**。台帳表を作った migration は `2026_06_11_091400` で、
  保持期限は 7 年なので、**通常のアプリ経路では** `created_at <= now - 7 年` を満たす行が生まれず、
  v0 の畳み込みが繰越行を作れるのは **2033-06-11 以降**である。
  **手動投入・DB 復元・古い `created_at` を持つ移行データは保証外**である
  (それらで v0 形の行が入っている環境は下記の自己修復に委ねるか、先に棚卸しすること)。
  仮に人為的に v0 形の繰越行 (`created_at` が実行時刻 / `idempotency_key` が非 NULL /
  旧固定文言) がある環境があっても、v1 は繰越行を集約キーの削除対象に含めるので、
  その行が保持期限以前に入った時点で新形へ合算され**自己修復する** (残高は常に保存される)。
  ただし**それまでの間は同じ集約キーに v0 行と v1 行が並存しうる** —
  「集約単位ごとに 1 行へ収束」「繰越行の `idempotency_key` は NULL」は
  **v1 が作った行についての不変条件**であり、旧環境の残置行には遡及しない。
  2033 年より前に本番でこれらの条件を監視するなら、先に v0 行の棚卸しをすること。

## 8. 関連

- `docs/account-deletion-runbook.md` — 退会 (アカウント削除) の運用
- `docs/inquiry-deletion-runbook.md` — 問い合わせの保持期間 (別概念・別所有者)
- `docs/architecture.md` §課金記録の保持期間 (7 年) の決着
