# 対応マトリクス: impl-review Round 1

全体判定: `CHANGES_REQUESTED` (Critical 0 / Warning 6 / Suggestion 0)

## [Warning] BillingCustomerSynchronizer: 移設の主契約 (tx level 観測) が無い
- 判断: **対応する**
- 根拠: 指摘のとおり。他 6 経路には tx level 観測を置いたのに、この経路だけ
  「afterCommit フラグを持たない」+「rollback で jobs 行が残らない」しか無かった。
  後者は設計自身が「移設の検出には使えない」と明記した補助テストであり、
  呼び出し元が tx 外へ移動しても赤くならない。
- 対応内容: `tests/Feature/Billing/BillingCustomerSynchronizerTest.php` に
  `実呼び出し元 (RenameOrganizationAction) 経由でも SyncBillingCustomerDetails は業務 tx の内側で投入される`
  を追加。実 caller (`RenameOrganizationAction::execute`) 経由で `JobQueueing` の
  `DB::transactionLevel()` を観測し `baseline + 1` 以上を assert する。
  = 呼び出し元が tx の外へ出た場合も赤くなる。

## [Warning] BillingCustomerSynchronizerTest: 反転後の主張を直接検証していない
- 判断: **対応する** (上と同一の対応)
- 根拠: 同上。
- 対応内容: 同上。

## [Warning] AutoRechargeAttemptUniquenessTest が queue を database に固定していない
- 判断: **対応する**
- 根拠: 妥当。`createAttemptLocked()` が同一 tx で `ExecuteAutoRechargeAttemptJob` を投入するように
  なった結果、sync レーン (after_commit=true) では commit 直後にインライン実行されうる。
  attempt が pending から動くと「pending 検査で no-op」を見ているつもりが別要因で緑になる。
- 対応内容: `beforeEach` で `config()->set('queue.default', 'database')` を固定し、
  1 件目が `Pending` のまま残っていることも assert に追加した。

## [Warning] TicketLowBalanceNotificationIsolationTest が fake channel の呼び出しを検証していない
- 判断: **対応する**
- 根拠: 妥当。「通知経路が全く走らない」「bind が効いていない」場合でも緑になる偽グリーンだった。
- 対応内容: `ThrowingDatabaseChannel::$calls` を追加し、`expect(...)->toBeGreaterThan(0)` で
  「実際に例外が起きて握られた」ことまで固定した。

## [Warning] D5 (既定値) が truthy 値 / constructor promotion を見ていない
- 判断: **対応する** (設計の `=== true` 指定を意図的に広げる。deviations に記録)
- 根拠: 指摘が正しい。vendor の `Queue::shouldDispatchAfterCommit()` は
  `isset($job->afterCommit)` で拾った値を**真偽値文脈**で評価するため、`1` / `'yes'` でも
  commit 後ずらしが起きる。また promoted property の既定値は `getDefaultProperties()` に
  現れないため、プロパティ宣言だけを見る実装ではすり抜ける。
  設計が `=== true` を指定した理由は「`Queueable` の既定値 `null` を偽陽性にしない」ことであり、
  `null` / `false` を除外したうえで残りを違反にすれば**その目的は保たれたまま**穴が閉じる。
- 対応内容: `detectAfterCommitProperty()` の判定を「`null` でも `false` でもない」へ広げ、
  constructor promotion (`ReflectionMethod::getParameters()` + `isPromoted()`) も見るようにした。
  負のコントロールを 2 本追加 (`= 1` の truthy / promoted `= true`)、
  偽陰性コントロール (null / false) は維持。

## [Warning] deferralCandidateClasses() 自体を片側へ潰す変異が検出できない (mutation #24)
- 判断: **一部対応する (完全には閉じられないことを明示する)**
- 根拠: 指摘は正しく、mutation-evidence にも実測として記録済み。ただし原因は
  「現状 `mailableClasses()` ⊆ `shouldQueueClasses()` (Mailable 2 クラスが `implements ShouldQueue`
  を併記している)」という**リポジトリの状態**であり、app/ にダミークラスを置けない以上、
  ブラックボックスでこの変異を赤くする方法が無い (置けば禁止事項の「不必要な複雑化」+
  本番コードへのテスト専用クラス混入になる)。
- 対応内容: (1) 和集合の生成を純関数 `mergeCandidateClasses(array, array)` へ切り出し、
  disjoint な 2 集合を食わせる負のコントロールで「和を取る意図」を固定済み (Round 1 前に対応済み)。
  (2) 追加で **trip-wire テスト**
  `母集団: Mailable が ShouldQueue を併記しているあいだ和集合は degenerate である`
  を新設した。包含が崩れた瞬間 (= ShouldQueue を実装しない Mailable が現れた瞬間) に赤くなり、
  失敗メッセージで「この時点から和集合が実効を持つので (1) merge 経由であること
  (2) D3/D5 の 0 件 pin が当該 Mailable を含めて緑であること を確認せよ」と指示する。
  = 被覆の穴を**不可視のまま放置せず、転換点で必ず人間の目に触れる**形にした。
