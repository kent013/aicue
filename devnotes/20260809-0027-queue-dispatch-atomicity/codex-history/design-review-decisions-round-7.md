# 対応マトリクス: design-review 確認ラウンド (Round 7)

> Round 6 (one-shot 確認ラウンド) で残った Warning (D5 = `Queueable` の `$afterCommit`
> プロパティ経由の迂回) を反映した後、その反映の再レビューがラウンド上限で取れていなかった。
> 本ラウンドはその再レビューである (one-shot / `--ephemeral`)。

## 確認ラウンドの結果 (Codex 返答: `detailed-review-round-7.md`)

- **判定: CHANGES_REQUESTED**
- D5 の反映自体は「通常の `ShouldQueue` job / queued notification / queued listener については解消」
  と評価された。`getDefaultProperties()` による親クラス・trait 由来の既定値の可視性、
  `Queueable` trait の `$afterCommit = null` を `=== true` で誤検出しない設計も妥当と確認された。
- **新たな Critical は 0 件**。残った指摘は Warning 1 件 + Suggestion 1 件。

---

## [Warning] 非 `ShouldQueue` な Mailable の `$afterCommit` が D5 の母集団から漏れる

- 判断: **対応する (指摘は正しい。しかも本リポジトリでは仮想の穴ではない)**
- 根拠 (自分で vendor と app を実査した):
  - `vendor/laravel/framework/src/Illuminate/Mail/SendQueuedMailable.php` L67-71 —
    `$mailable instanceof ShouldQueueAfterCommit ? true : ($mailable->afterCommit ?? null)` を
    **wrapper job の `$afterCommit` へコピーする**。`Mailable` は `ShouldQueue` を実装
    していなくても `Mail::to(...)->queue()` / `Mail::queue()` でキューに載るため、
    非 `ShouldQueue` Mailable の `public $afterCommit = true;` は D1〜D5 のどれにも映らない。
  - **本リポジトリは現に `app/Actions/Inquiry/CreateInquiryAction.php` が
    `Mail::to(...)->queue(new InquiryReceivedMail(...))` を使っている**。
    現行 2 クラス (`InquiryReceivedMail` / `InquiryAcknowledgementMail`) は
    `implements ShouldQueue` を併記しているので今は母集団に入るが、
    **併記を外した瞬間に検出器から消える**。「0 件 pin」の主張が嘘になる。
  - 一方で **Notification / listener に同じ拡張は不要**であることも実査で確認した:
    `NotificationSender` L89 が `$notification instanceof ShouldQueue`、
    `Events\Dispatcher::handlerShouldBeQueued()` が同じく `ShouldQueue` を要求するため、
    キューに載る母集団は `shouldQueueClasses()` で尽きる (思考原則 2 — 到達不能な経路の
    ために母集団を広げない)。
- 対応内容:
  - `QueuedJobPopulation` に **`mailableClasses()` を 1 メソッド追加**
    (`app/` 配下の `Illuminate\Mail\Mailable` subclass を `ShouldQueue` の有無を問わず列挙。
    既存 `appPhpFiles()` / `classNameForPath()` を再利用)。
    **`shouldQueueClasses()` は変更しない** — 既存 2 gate
    (`QueuedJobLeaseInventoryTest` / `JobExecutionDedupInventoryTest`) の対称差テストを
    巻き添えで落とさないため。
  - `QueueDispatchDeferralInventory::deferralCandidateClasses()` を追加し、
    **D3 と D5(既定値) の母集団を `shouldQueueClasses()` ∪ `mailableClasses()`** にした。
  - テスト追加: 7b (Mailable 列挙 0 件 fail) / 7c (和集合の固定) /
    12b2 (`ShouldQueue` を実装しないダミー Mailable の `$afterCommit = true` を D5 が検出する)。
  - mutation 追加: #22 (`InquiryReceivedMail` から `ShouldQueue` を外して
    `$afterCommit = true` を足す → **母集団を戻すと落ちない**ことも同時に確認) /
    #24 (`deferralCandidateClasses()` を潰す)。
  - §保証しないもの 14c を追加 (母集団の外 = vendor / `app/` 外 / 動的生成クラスには沈黙する)。
  - M10 の AGENTS.md 追記案にも「`ShouldQueue` 実装だけでなく Mailable も」を明記。

## [Suggestion] `$job->afterCommit = true;` (外部からの代入) の負のコントロールが無い

- 判断: **対応する (コストが 1 テストで、検出器の契約を曖昧にしないため)**
- 対応内容: `detectAfterCommitAssignments()` の docblock に
  「判定は receiver を問わず `T_OBJECT_OPERATOR` + `afterCommit` + `=` + `true` の並びで行う」
  を明記し、テスト **12e** (`$job->afterCommit = true;` を D5(代入) が検出する) を追加した。

---

## 自己検証で見つけた追加の問題 (Codex 指摘外。同ラウンドで併せて修正)

### `ShouldHandleEventsAfterCommit` が D3 の interface 集合から漏れていた

- 根拠: `Events\Dispatcher::handlerShouldBeDispatchedAfterDatabaseTransactions()` (L607-612) は
  `ShouldQueueAfterCommit` **ではなく** `ShouldHandleEventsAfterCommit` を見る。
  ShouldQueue な listener に付けると**キュー投入そのもの**が commit 後へずれる。
  D3 が `ShouldQueueAfterCommit` しか見ないと、この宣言的迂回が丸ごと漏れる。
- 対応内容: D3 を `detectAfterCommitInterfaces()` に改め、**対象 interface を 2 つ**にした
  (新しい検出器を増やしたのではなく、既存リフレクション判定の対象が 1 つ増えただけ =
  思考原則 2 に反しない)。負のコントロール 11b と mutation #23 を追加。
  §保証しないもの 14c に「非 ShouldQueue listener の
  `ShouldHandleEventsAfterCommit` は同期ハンドラの遅延であってキュー投入ではないため対象外」を明記。
  現行 `app/` の使用は 0 件 (実査済み)。

### `$afterCommit` の既定値判定を `=== true` の厳密比較と明記

- 根拠: `Queueable` trait の `$afterCommit` 既定値は `null`。truthy 判定にすると
  **全 job が偽陽性**になる。Codex も同じ点を確認している。
- 対応内容: `detectAfterCommitProperty()` の docblock に厳密比較を明記し、
  偽陰性の負のコントロール **12f** (既定値 `null` / `false` は検出しない) を追加した。

### D5 docblock の文言ドリフト

- 「実行時代入は文字列走査」→「実行時代入は **token 走査**」へ修正
  (同 docblock の下段は既に token 走査と書いており自己矛盾していた)。

### M10 の「保証しないもの」が D5 / token 走査に追随していなかった

- 「検出は文字列パターン (D1/D2) とリフレクション (D3) の併用」のままだったため、
  「token 走査 (D1/D2/D5 の代入形) とリフレクション (D3/D5 の既定値) の併用」へ書き換えた。

---

## 次ラウンド

Round 8 (one-shot) で上記反映を再確認する。
