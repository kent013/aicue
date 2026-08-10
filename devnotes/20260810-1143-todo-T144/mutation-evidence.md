# T144 (PR-C2) mutation 実測記録

> 手順: 1 変異ずつ適用 → 対象テストが**赤いこと**を実測 → 変異を戻す → 全体が緑に戻ることを確認。
> 詳細設計 §「共通: mutation で赤化を確認する手順」の M12 / M13 / M13b に加え、
> 本 PR 固有の観測点 (`--apply` / horizon / 目録 gate / 冪等キー衝突) を足した。

## 実測サマリ

| # | 変異 (実施後は戻した) | 赤くなったテスト | 結果 |
|---|---|---|---|
| MU1 (設計 M13b) | 畳み込みの group query から `where('organization_id', …)` を外す | `TicketLedgerCarryForwardTest`「検証 1〜4・7 残高が 1 枚も変わらない」/「group key は 3 つで組織を跨いで合算しない」 | **赤 2 本** |
| MU2 (設計 M13) | group query から `source` 条件を外す | 同「検証 1〜4・7」/「source が null の legacy 行は独立した group」 | **赤 2 本** |
| MU3 | group query から `expires_at` 条件を外す | **初回は緑のまま (検出できず)** → fixture 修正後に「検証 1〜4・7」が赤 | **下記参照** |
| MU4 | 繰越行に `stripe_checkout_session_id` を引き継がせる | 「繰越行は残高の粒度 3 つだけを引き継ぎ、取引追跡情報を 1 つも残さない」 | **赤 1 本** |
| MU5 (設計 M12 の裏) | registry から `TicketLedgerEntryPurger` を外す | `BillingRetentionHorizonTest`「母集団は全 target を含む」/ `BillingRetentionTargetInventoryTest`「検査 4 exact-fit」「検査 5 空振り検知」 | **赤 3 本** |
| MU6 | `app/Services/Billing/` に未登録の台帳 reader を 1 ファイル置く | `TicketLedgerReaderInventoryTest`「検査 1 exact-fit」「検査 3 空振り検知」「検査 6 負のコントロール」 | **赤 3 本** |
| MU7 | `--apply` を常に無視して dry-run にする | `BillingRetentionPurgeTest`「コマンドは --apply で実際に決着させ、horizon の観測点を出力する」 | **赤 1 本** |
| MU8 | `carried_forward_through` の単調性 (`max(閾値, 前回値)`) を外し常に閾値を返す | **初回は緑のまま (検出できず)** → テスト追加後に「閾値が過去へ戻っても後退しない」が赤 | **下記参照** |
| MU9 | horizon 行を常に `OK` にする | 「--apply でも決着できない記録が残れば horizon は NG」 | **赤 1 本** |
| MU10 | 繰越行 insert の `$inserted !== 1` fail-closed を外す | 「畳み込み済み group に古い行が後から入ったら fail-closed」 | **赤 1 本** |
| MU11 (Codex Round 1 の指摘対応) | 削除件数と集計件数の一致検査 (`$deleted !== $aggregate['rows']`) を外す | 「集計の後に古い行が割り込んだら fail-closed」 | **赤 1 本** |

変異を戻したあと `TicketLedgerCarryForwardTest` 15 本 / `BillingRetentionPurgeTest` 30 本 /
`BillingRetentionHorizonTest` / 両 Architecture gate (65 tests / 289 assertions) は
すべて緑に戻ることを実測した。`composer test` 全レーンも 4218 passed / 2 skipped で緑である。

---

## 設計の予測と実測がずれた点 (辻褄を合わせず記録する)

### ずれ 1: MU3 (group key から `expires_at` を落とす) が初回は**検出できなかった**

**予測**: 詳細設計 C2b の「検証 3 有効期限別残高」が赤くなるはず。

**実測**: 緑のまま通った。原因は**テスト fixture の不足**で、設計側の欠陥ではない。
初版の fixture は「同じ `source` の中で `expires_at` が 1 種類しかない」組織しか作っておらず、
group key から `expires_at` を落としても**分割のされ方が変わらなかった**。

**対処**: `seedCarryForwardLedger()` に「同じ `source` (monthly) で失効時刻だけが違う group」を
2 つ置いた (`$expiredMonthly` と `$otherExpiry`)。これで MU3 は「検証 1〜4・7」で赤くなる。

> 教訓: 「group key の要素を落とす」変異は、**その要素が実際に 2 値以上ある fixture** でしか
> 検出できない。group key を持つ検査には値の分散を fixture 要件として書く必要がある。

### ずれ 2: MU8 (`carried_forward_through` の単調性) が初回は**検出できなかった**

**予測**: 「繰越行はさらに畳み込める (単調に進む)」テストが赤くなるはず。

**実測**: 緑のまま通った。既存テストは「1 回目より 2 回目の閾値が新しい」順でしか回しておらず、
`max(閾値, 前回値)` の `前回値` 側の枝を 1 度も踏んでいなかった (閾値が単調増加する限り
`max` は常に閾値を返すため、単調性は自動的に成立してしまう)。

**対処**: 「保持年数を延ばして**閾値が過去へ動いた**」ケースのテストを追加した
(`閾値が過去へ戻っても carried_forward_through は後退しない`)。これで MU8 が赤くなる。

### ずれ 3: 冪等キーの第 4 要素は「集約終端」ではなく「その実行の閾値」にした

**設計の記述**: `carry_forward:{orgId}:{source}:{expiresAt}:{through(UTC)}`。

**実測で判明した不整合**: 上のずれ 2 の対処 (閾値が過去へ戻る再畳み込み) を実装すると、
`through = max(閾値, 前回値)` は**前回と同じ値**になる。キーが `through` 由来だと
**前回の繰越行とキーが衝突**し、insertOrIgnore が 0 を返して fail-closed に落ちる
(= その group は二度と畳み込めない)。

**対処**: キーの第 4 要素を**その実行の閾値**にした。冪等の単位は「同じ入力で同じ実行をしたか」
なので、入力である閾値で決めるのが正しい。`carried_forward_through` (列) は集約終端として
単調性を別に保つ。両者は普段一致し、保持年数を延ばしたときだけ食い違う。
形 (`carry_forward:{orgId}:{source}:{expiresAt}:{時刻}`)・null の明示トークン・UTC 正規化は
設計どおりで、テストが固定している。

### ずれ 4: 繰越行の `description` を null にできない

**設計の記述**: 「取引追跡列はすべて null: `description` / `reservation_id` /
`stripe_checkout_session_id` / `payment_intent_id` / `purchase_amount` / `granted_at`」。

**実測**: `ticket_ledger_entries.description` は **NOT NULL** である
(`2026_06_11_091400_create_ticket_tables.php` を実読)。設計の「実カラムを migration 実読で
確認済み」という記述と食い違う。

**対処**: 列を nullable へ変えるのではなく、**取引追跡情報を一切含まない固定文言**
(`保持期間の繰越 (残高スナップショット)`) を入れた。原取引の説明は残らないため
「個別取引が復元不能」という要件は満たす。テストは「繰越行の description / idempotency_key に
原取引の識別子が含まれない」ことを固定している。

### ずれ 5: horizon の「purger 書き忘れ」を捕まえるのは horizon の postcondition ではない

**設計の記述** (C1d): 「target を 1 つ足して purger を書き忘れたときに赤くなるのは horizon 側だけ」。

**実測**: MU5 (registry から purger を外す) で `BillingRetentionHorizonTest` の
**postcondition テストは緑のまま**だった。postcondition は「登録済み purger を回した結果」しか
見ないため、登録から消えた target は母集団からも消える (自己充足してしまう)。
赤くなったのは同ファイルの「母集団は全 target を含む」検査と、
`BillingRetentionTargetInventoryTest` の exact-fit / 空振り検知だった。

**対処**: 実装は変えず、事実を記録する。C1 が置いた「registry の target 集合 == enum の
target 集合」検査 (本 PR で `isPendingCarryForward()` 除外を撤去して全 target 一致にした) が
実質的な検出点であり、機能としては塞がっている。設計の説明文だけが実装と一致していない。

---

## 変異を戻したことの確認

```
git status --short   # 変異の残骸が無いことを確認
vendor/bin/pest tests/Feature/Billing/TicketLedgerCarryForwardTest.php   # 15 passed
```
