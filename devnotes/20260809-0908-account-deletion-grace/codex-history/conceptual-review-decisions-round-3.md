# 対応マトリクス: conceptual-review Round 3

## [Critical] C1 単独では公開規約が「最長 7 年」を宣言する一方で ledger 処理が未了

- 判断: **対応する (提案 2 を採用)**
- 根拠: 指摘が正しい。`pending_carry_forward` は**開発者に対して**未了を可視化するが、
  **利用者に対しては**規約と実処理の不一致がそのまま公開される。
  「各 PR 単独で完結する」「後方互換の並走を残さない」という自分の説明とも矛盾していた。
- 対応内容: **C1 を「非公開の基盤整備 PR」に位置づけ直した**。
  - **C1 に入れるもの**: `config/legal.php` の値 / `BillingRetention` SSOT /
    `BillingRetentionTarget` / `BillingRetentionExclusion` / `BillingRetentionPurger` 実装 /
    ledger 畳み込み以外の全 target の purge ロジック / 単体・境界テスト /
    `billing:purge-retention-expired` **コマンドの定義 (dry-run のみ到達可能)**。
  - **C1 に入れないもの**: **`privacy.blade.php` への文面追記** /
    **`routes/console.php` の日次スケジュール登録** / **`--apply` の運用開始**。
  - **C2 で 3 つを同時に有効化する**: ledger 畳み込みの実装 + 文面追記 + 日次スケジュール登録。
    **公開宣言と実処理が同じマージで揃う**。
  - 帰結として **C1 マージ時点の main は「規約は何も宣言していない / 実処理も動いていない」**という
    一貫した状態になる (現状と同じ状態 + 未使用の基盤)。C2 マージ時点で
    「規約が宣言し、実処理が全 target を閉じている」一貫した状態へ 1 歩で遷移する。
  - §4-3 の三者一致 gate も C2 で入る (文面が無い時点で「文面と config の一致」は検査できない)。

## [Warning] 常に SUCCESS を返すと障害検知が死ぬ

- 判断: **対応する**
- 根拠: 妥当。全件で DB 障害が起きても成功終了すると、scheduler の失敗通知も終了コード監視も機能しない。
- 対応内容: **2 分類にした** (`account:purge-deletion-requests` と
  `billing:purge-retention-expired` の**両方**に適用):
  - **業務上の保留** (退会ブロッカー / fail-closed で残した行) = 正常。`SUCCESS`。
  - **想定外例外** (インフラ障害 / 不変条件違反) = 1 件でも記録したら**最後まで継続したうえで
    `FAILURE`**。
  - 走査は最後まで続ける (1 件目で止めない) が、終了コードは分類の結果で決める。
  - `report()` の成功に依存しない (終了コードが独立した検知経路になる)。

## [Warning] 畳み込みの不変条件を残高 3 メソッドに限定するのは不足

- 判断: **対応する**
- 根拠: 妥当。`source` / `expires_at` / 作成順 / 原取引参照を使う reader があれば、
  `SUM(delta)` が同じでも挙動は変わる。
- 対応内容: **`TicketLedgerEntry` の全 reader を目録化する**ことを C2 の前提条件にした。
  検証対象として指摘の 6 点 (総残高 / 利用可能残高 / 有効期限別残高 / source 別残高 /
  debit・reserve・commit・release の選択順序 / 外部キー・重複防止キー・監査表示) を明記した。
  reader 目録は `git grep TicketLedgerEntry` の機械抽出と exact-fit で照合する
  (「読んでいる場所を人間が数える」形にしない)。
  **畳み込み行の `source` は元 `source` を引き継ぐ** (出所別残高が変わらないため)。
  `expires_at` も引き継ぐ (有効期限別残高が変わらないため) — したがって畳み込みの粒度は
  **`(source, expires_at)` の組ごと**になる。これは §4-3b に既に書いた粒度と一致する。

## [Warning] horizon test が運用状態まで保証するように読める

- 判断: **対応する**
- 根拠: 妥当。テストが保証できるのは fixture に対する postcondition だけである。
- 対応内容: `BillingRetentionHorizonTest` の保証を
  **「purger 実行後に、起算済み・期限超過の行が 0 件になること (postcondition)」**に限定した。
  本番で日次処理が止まっていないことは**保証しない**と明記し、その責務を
  「Command の件数報告 + `FAILURE` 終了コード + scheduler 運用 (`docs/architecture.md` の監視対象)」へ
  分離した。

## [Warning] 繰越行の性質 (取引記録か残高スナップショットか) が曖昧

- 判断: **対応する (指摘 4 点をすべて採用)**
- 根拠: 妥当。新しい `created_at` を付けた繰越行が「取引記録」のままだと、7 年後に再び畳み込まれて
  保持時計が永久に更新され続け、規約との対応が崩れる。
- 対応内容: 繰越行を **「取引記録ではなく現在残高のスナップショット」**と定義した。
  - 原取引 ID / 説明 / 個別日時 / `stripe_invoice_id` などの**取引追跡情報を引き継がない**
    (引き継ぐと「消したはずの取引情報」が残る)。
  - `carried_forward_through` (この繰越が集約した期間の終端) を型付きで持つ。
  - **繰越行の再畳み込みを許す** (残高スナップショット同士の合算は情報を増やさないため)。
  - **個別取引情報が復元不能であることをテストで固定する** (畳み込み後に
    原取引の識別子が 1 つも残っていないこと)。

## [Warning] `Subscription` / `SubscriptionItem` の削除順序と参照整合性が未確定

- 判断: **対応する (安全境界を概念設計で固定し、方式は詳細設計へ)**
- 対応内容: 概念設計に安全境界 3 点を固定した。
  - **子から親** (`subscription_items` → `subscriptions`) の順で処理する。
  - **期限到達判定は親契約の `ends_at`** (子の日時では判定しない)。
  - **他モデルから参照中なら fail-closed + `report()`** (参照を壊してまで消さない)。

## [Warning] PR 数の記述が不整合 (§2 が 3 / §6 が 4 / §9 が 3)

- 判断: **対応する**
- 対応内容: 全箇所を **A → B → C1 → C2 の 4 PR** に統一した。

## [Suggestion] `BillingRetentionPurgeResultDto` の項目

- 判断: **対応する**
- 対応内容: DTO の項目を明示した — target 識別子 / 対象件数 / 削除または畳み込み件数 /
  fail-closed で残した件数 / 想定外失敗件数。**`array<string, mixed>` や任意メタデータ領域は持たせない**。

## [Suggestion] 期待効果 / privacy 4 点検査 / enum のメタデータ限定

- 判断: **見送る (追加対応なし)**
- 根拠: 肯定的評価のため設計変更不要。
