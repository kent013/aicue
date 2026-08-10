# 対応マトリクス: conceptual-review Round 6 (確認ラウンド / one-shot)

> セッション合議は 5 ラウンドで上限に達したため、以降は one-shot (`--ephemeral`) の確認ラウンド。

## [Critical] §3 と §6 に `failClosed` を除外する記述が残っており文書内で矛盾している

- 判断: **対応する (指摘が正しい。純粋な修正漏れ)**
- 根拠: §4-3c と台帳報告条件は「`failClosed` を含む」に直っていたが、
  §3 の観測できる成功条件 (d) と §6 の公開順序 手順 4 に古い括弧書きが残っていた。
  **実装者が古い条件を採用して `failClosed > 0` のまま C3 を公開する**失敗シナリオが実在する。
- 対応内容: 2 箇所とも **「`failClosed` を含む。分類を問わない」**へ修正した。
  文書全体を `grep` して「除く」系の表現が 0 件であることを確認済み。

## [Warning] 凍結中 allowlist の課金系が namespace 単位で広すぎる

- 判断: **対応する (指摘が正しい)**
- 根拠: `billing.*` と書くと購入 (`billing.tickets.checkout`)・新規契約 (`billing.checkout`)・
  自動チャージ有効化 (`billing.auto-recharge.setup`) まで通ってしまい、
  **「新しい課金状態を増やす操作」が予約中に可能**になる。凍結の意味が薄くなる。
- 対応内容: allowlist を **route 名の exact case だけ**に書き換えた (§4-2 の表)。
  課金系で通すのは 3 本だけ:
  - `billing.index` (請求状況の確認と Portal 導線)
  - `billing.portal` (**解約・支払い方法更新の本体** = 生きた課金責務の解消手段)
  - `billing.auto-recharge.update` (自動購入を**停止**する手段。放置すると予約中も課金が続く)
  そして **明示的に不可にするもの**を列挙し、behavioral テストで固定することにした
  (`billing.checkout` / `billing.plan.change` / `billing.tickets.*` /
  `billing.auto-recharge.setup` / `onboarding.*` ほか)。
  gate の検査項目に **「enum は wildcard を持たない (exact case のみ)」**を 6 点目として追加した。
  併せて「未契約組織のユーザーが 2 hop (課金ゲート → onboarding → 凍結 → `/settings`) で
  取消 UI に着き、行き止まりが生じない」ことを明記した。

## [Warning] UI 主導線が本当に予約へ移ることを完了条件に含めるべき

- 判断: **対応する**
- 対応内容: 「即時削除が主ボタンにならないこと / 予約導線が primary であること」を
  **Browser もしくは component テストの対象**に入れることを完了条件に明記した (口約束にしない)。
  条件未充足時は `disabled` にせず押下時にエラーを出す (禁止事項 8) 点も併記した。

## [Warning] `deleteAccount()` の `ValidationException` を Command がどう分類するか曖昧

- 判断: **対応する**
- 対応内容: `ValidationException` は **「業務上の保留」に分類する** (想定外失敗にしない) と確定し、
  **Command の終了コードテストで固定する** ことを施策に入れた
  (`ValidationException` だけなら `SUCCESS`、それ以外の例外が 1 件でもあれば `FAILURE`)。

## [Warning] C3 の事前条件を機械的に見落とさない工夫が弱い

- 判断: **対応する (新しいデプロイ機構は作らない)**
- 対応内容: runbook に **「C3 チェックリスト」**を置き、**初回 apply の出力
  (target 別件数 / `failClosed` = 0 / 想定外失敗 = 0) の証跡を C3 の PR 説明へ貼る**ことを
  必須項目にした。デプロイ基盤は作らない (AGENTS.md / 思考原則 2)。

## [Suggestion] `isPublicationReady()` に必要な全条件を DTO 内で表現する

- 判断: **対応する**
- 対応内容: `BillingRetentionPurgeResultDto` に **「purge 後に残った期限超過件数」**を
  6 つ目のフィールドとして追加し、
  `isPublicationReady() === (failClosed === 0 && unexpectedFailures === 0 && expiredRemaining === 0)`
  と定義した。公開判定に必要な全条件が DTO 内で閉じる。

## [Suggestion] メール通知の再予約 fixture

- 判断: **対応する (詳細設計の必須テストとして記録)**
- 対応内容: 「再予約時に古い job が送られない」fixture を詳細設計のテスト計画へ必ず入れる。

## [Warning] 凍結 middleware を group 全体に付ける実装難度

- 判断: **対応済み (追加変更なし)**
- 根拠: 指摘自身が「gate で全件検査する方向性は妥当」と述べており、
  懸念の本体 (allowlist が広い) は上の [Warning] 対応で解消した。
