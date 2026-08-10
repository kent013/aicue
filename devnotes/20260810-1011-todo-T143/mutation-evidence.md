# T143 (PR-C1) mutation evidence

詳細設計 §「共通: mutation で赤化を確認する手順」のうち **C1 に関係する 5 件** (M10 / M11 / M12 / M16 / M26) を実施した。
1 変異ずつ適用 → 対象テストが赤いことを実測 → 変異を戻す → 全体が緑に戻ることを確認 (`git diff` に残っていないことも確認済み)。

> **設計の予測と実測がずれた点は辻褄を合わせずそのまま記録する** (M10 / M12)。

## M10: 目録から `Subscription` を外す (母集団の分類漏れ)

**設計の変異**: `BillingRetentionTarget` から `Subscription` case を削る。

**実施した変異 (代替形)**: `app/Models/Billing/MutationProbe.php` (未分類の課金モデル) を新設する。

**代替した理由**: enum の case を削ると `SubscriptionPurger::target()` / 各 `match` 腕が
未定義定数を参照して **PHP の fatal error** になる。fatal は「gate が検出した」ことの証拠にならない
(gate を消しても同じ赤になる)。検出したい性質は「**母集団に分類漏れがあると赤くなる**」であり、
未分類モデルの追加はその性質を直接突く同値の変異である。

**実測 (赤)**: `tests/Architecture/BillingRetentionTargetInventoryTest.php` が 3 本失敗。

- 検査 1 (分類漏れ): `保持期間の分類が無い課金モデルを検出しました … App\Models\Billing\MutationProbe`
- 検査 5 (空振り検知): `Failed asserting that actual size 15 matches expected size 14.`
- 負のコントロール: 未分類一覧に probe が混ざり期待値と不一致

## M11: `Subscription` の起算列を `ends_at` → `created_at` に変える

**実測 (赤)**: Feature 6 本失敗。設計の予測 (「継続中は何年経っても対象外」テスト) を含む。

- `継続中の契約 (ends_at が null) は何年前に作られていても対象外かつ異常でもない` ← 設計の予測どおり
- `終了済み契約は ends_at で判定され、明細が無ければ消える`
- `明細が残っている期限超過の契約は fail-closed で残り、件数が報告される`
- `明細は親の ends_at で判定され、子 → 親の順に消える`
- `dry-run コマンドは 1 行も消さず target 別の件数を報告する`
- `負のコントロール: purge 後に古い記録を作ると horizon が満たされなくなる`

## M12: `TicketLedgerEntry` を C1 の horizon 対象に入れる

**実施した変異**: `BillingRetentionTarget::isPendingCarryForward()` を `return false;` にする。

**実測 (赤)**: 2 本失敗。

- `tests/Architecture/BillingRetentionTargetInventoryTest.php` 検査 4:
  `purger を持つべき target と実装が一致しません (isPendingCarryForward() の target を除く)。`
- `tests/Feature/Billing/BillingRetentionHorizonTest.php`
  `C1 の母集団は畳み込み待ちの台帳を含まない (C2 で自動的に加わる)`

**設計の予測とのズレ (記録)**: 設計は「horizon (期限超過が残る)」が赤くなると予測していたが、
実装では **horizon の母集団を registry (実装済み purger) から導出**しているため、
`postcondition` テストは赤くならなかった。代わりに「purger 実装 ⇔ 目録の exact-fit」検査が赤くなる。
帰結は同じ (畳み込み未実装のまま台帳を対象扱いにすると必ず赤くなる) が、**赤くなる場所が違う**。
母集団を registry から導出した理由は、C2 で `isPendingCarryForward()` が false になったときに
**テスト側の除外を書き足す必要がない** (外し忘れが構造的に起きない) ためである。

## M16: `isPublicationReady()` から `failClosed === 0` を外す

**実測 (赤)**: `tests/Unit/DataTransferObjects/Billing/BillingRetentionPurgeResultDtoTest.php`
`fail-closed が残っていれば公開できない (安全に残した = 規約準拠ではない)` が失敗。

**補足 (記録)**: Feature 側の `明細が残っている期限超過の契約は fail-closed で残り…` は
**赤くならなかった** — この fixture では `expiredRemaining` も 1 になるため、
`failClosed` の条件を外しても `isPublicationReady()` は false のままだからである。
公開条件のうち `failClosed` 単独の寄与を固定しているのは DTO の単体テストだけであり、
その 1 本が変異の唯一の検出点である (だから消してはならない)。

## M26: `StripeWebhookEvent` の `anomalyClockColumn()` を null にする

**実測 (赤)**: `tests/Feature/Billing/BillingRetentionPurgeTest.php`
`起算列が null で補助時計が閾値より古い行は fail-closed (消さずに計上する)`
の dataset `stripe_webhook_event` が失敗 (他の 3 target は緑のまま = 変異した target だけが落ちる)。

## 変異の撤収確認

- `app/Models/Billing/MutationProbe.php` を削除済み (`ls app/Models/Billing/` に存在しない)
- `git diff` に変異の残骸なし (`BillingRetentionTarget.php` の差分は PHPStan 対応の docblock のみ、
  `BillingRetentionPurgeResultDto.php` は差分なし)
- 撤収後に対象テスト群が全 green に戻ることを確認
