# 対応マトリクス: conceptual-review Round 4

## [Critical] C2 のコードマージと「実データが保持期限に準拠していること」は別物

- 判断: **対応する (提案された 2 案のうち「privacy 文面を後続 PR へ分ける」を採用)**
- 根拠: 指摘が正しい。「コードが存在する」と「保持期限が実際に満たされている」は別である。
  C2 に文面と日次登録を同居させると、**デプロイした瞬間に規約が「最長 7 年」と宣言する一方、
  初回 purge はまだ 1 度も走っていない**。しかも本リポジトリには**デプロイ定義が無い**
  (AGENTS.md 明記) ため、順序を CI/CD で担保できない。
- 対応内容: **PR を 5 本にした (A → B → C1 → C2 → C3)**。
  - **C2**: ledger 畳み込み + 日次スケジュール登録 + `--apply` 運用開始。**文面はまだ書かない**。
  - **C3 (極小 PR)**: `privacy.blade.php` への文面追記 + 三者一致 gate
    (`BillingRetentionSingleSourceTest`) **のみ**。
  - **順序をマージ順で担保する**: C2 デプロイ → 日次が回る → horizon 0 を確認 → その後に C3 を出す。
    人手に残るのは「確認」だけで、「公開してから気づく」経路が構造的に存在しない。
  - **併せて有効化 runbook を C2 の完了条件にする** (`docs/billing-retention-runbook.md`):
    1. C1 の dry-run で target 別件数と想定外失敗を確認する
    2. C2 (ledger 畳み込み込み) を適用する
    3. `--apply` を実行する
    4. apply 後の horizon 検査で「期限超過件数 0 (fail-closed 分類を除く)」を確認する
    5. **4 が満たされて初めて C3 (文面公開) を出す**
    6. 日次 scheduler を継続運用へ移す
    - 失敗時の再実行方法と、**4 が満たされない限り C3 を出さない**条件を runbook に明記する。

## [Warning] 凍結 route 集合と allowlist の「exact-fit」が集合論的に矛盾

- 判断: **対応する (指摘が正しい)**
- 根拠: 凍結 middleware が付く全 route を `U`、通過を許す集合を `A` とすると `A ⊆ U` であり、
  `U` と `A` を完全一致させたら**全 route が通過対象になり凍結が成立しない**。
  Round 3 の書き方 (「母集団ごと exact-fit で照合」) は誤りだった。
- 対応内容: gate の契約を 5 点に分解した (§4-2)。
  1. **`A ⊆ U`** を検査する (allowlist に `U` 外の route 名を書けない)。
  2. enum の route 名が**実在し、凍結 middleware を実際に持つ**ことを検査する。
  3. **middleware が実際に bypass する集合と enum `A` が exact-fit** であることを検査する
     (実装と宣言の一致)。
  4. **`U - A` の route は予約中に遮断される**ことを behavioral に検査する (代表サンプルではなく全件)。
  5. **`U` に無名 route があれば fail** させる (名前で allowlist を書けないため。
     どうしても必要なら型付き exemption を要求する)。
  - 「未登録 route は fail」ではなく **「未登録 route は既定で遮断」**が deny-by-default の正しい契約である、
    という指摘をそのまま設計文へ書いた。

## [Warning] `Subscription` / `SubscriptionItem` の処理方式が未確定のまま C1 に含まれる

- 判断: **対応する (概念設計で方式を確定)**
- 根拠: 方式が決まらないと `BillingRetentionHorizonTest` の postcondition が定義できない、
  という指摘は正しい。
- 対応内容: 以下を確定した。
  - **方式は物理削除**。匿名化・スナップショット化は採らない (契約終了から 7 年が経過した
    subscription 行に、残しておく業務上の読み手が無い。ledger と違って残高の SoT でもない)。
  - **順序は子から親** (`subscription_items` → `subscriptions`)。
  - **`SubscriptionItem` 自身の起算点は親契約の `ends_at`** (子は独自の起算点を持たない)。
  - **他モデルから参照中の行は fail-closed で残す**。その分は
    **horizon postcondition の「0 件」から `failClosed` として分類除外**し、
    **件数を必ず report する** (黙って除外しない)。
  - **fail-closed が長期継続した場合の運用手順**を runbook に書く
    (参照元の特定 → 参照の解消 → 再実行。件数が単調増加しているときの初動)。

## [Warning] ledger reader 目録の `git grep` 抽出は取りこぼす

- 判断: **対応する**
- 根拠: 妥当。動的 relation / scope / table 名経由の query / DB facade は素の文字列走査で漏れる。
- 対応内容:
  - 走査入口を **4 つ**にした: モデル参照 (`TicketLedgerEntry`) / **table 名**
    (`'ticket_ledger_entries'`) / **relation 名** / **主要列名** (`delta` / `source` / `expires_at`)。
  - **正負 fixture と空振り検知**を同梱する (本リポジトリの gate 書式)。
  - **保証範囲を明記する**: 目録は「読んでいる場所を宣言なしに増やせない」ことしか保証しない。
    **最終保証は挙動テスト側**である — 総残高 / 利用可能残高 / 有効期限別残高 / source 別残高 /
    `debit`・`reserve`・`commit`・`release` の選択順序 / 外部キー・重複防止キー・監査表示 の
    **6 種を畳み込み前後で比較する**テストが本体で、目録はその網羅性を人手で見落とさないための補助である。

## [Suggestion] `BillingRetentionPurgeResultDto` に判定メソッドを持たせる

- 判断: **対応する**
- 対応内容: `hasUnexpectedFailures(): bool` を DTO に持たせ、**終了コード判定を 1 箇所へ閉じる**。
  Command 側が「業務上の保留 (fail-closed)」と「想定外失敗」を取り違える余地を無くす。

## [Suggestion] §8 で `inquiries / アクセスログ` が重複

- 判断: **対応する**
- 対応内容: 重複を削除した。

## [Suggestion] 使命整合 / 禁止事項 / 4 分割の妥当性 / 型安全性

- 判断: **見送る (追加対応なし)**
- 根拠: 肯定的評価。ただし分割数は上記 [Critical] 対応で 4 → 5 に増えた。
