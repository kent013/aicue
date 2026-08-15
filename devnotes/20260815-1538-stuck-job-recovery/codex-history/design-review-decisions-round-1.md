# 対応マトリクス: design-review Round 1

## [Critical] 施策 4/5: `failJob()` は terminal しか見ておらず、滞留述語を行ロック下で再評価していない
- 判断: 対応する (指摘は正しく、しかも**現行実装の欠陥**を突いている)
- 根拠: 現行 `recoverStale()` は候補列挙後に `failJob()` を呼ぶだけで、
  「queued/running かつ閾値超過」の再評価をしていない。候補の列挙後に worker が進捗を
  書いた running ジョブを、正常に動いているのに失敗確定できる窓がある。
  裁定 AG-083 が「誤回収の防止」として名指ししている事故そのもので、起きてもエラーにならない
- 対応内容: `AnalysisJobService::failStaleJob(int $id, CarbonImmutable $sweptAt): bool` と
  `RenderJobService::failStaleJob(...)` を新設し、`whereKey` + 滞留述語 + `lockForUpdate()` で
  1 行取れたときだけ失敗確定する。`failJob()` は公開のまま残し、両者の本体を private の
  `failLockedJob()` に切り出して 1 つにする (ロック順・通知の at-most-once・予約解放を複製しない)。
  stream からモデルの取り直しは消え、id を渡すだけになった。
  「候補列挙後に進捗が進んだ running ジョブは Skipped になり failed にならない」を
  fail-first の新規テストとして各施策のテスト計画へ追加

## [Warning] 施策 6: `release()` は reserved しか見ず、TTL 超過の再評価をしない
- 判断: 対応する (あわせて概念設計で予定した専用例外を**取りやめ**、設計を単純にした)
- 根拠: 滞留述語を WHERE に入れて再評価すれば、競合した予約は 1 行も返らず false になるので、
  「競合を表す例外型」を新設する必要そのものが消える。作らずに済むものは作らない
- 対応内容: `TicketLedgerService::releaseExpiredReservation(int $id, CarbonImmutable $sweptAt): bool`
  を新設 (whereKey + 滞留述語 + lockForUpdate)。`release()` は公開のまま残し、本体を
  private `releaseLockedReservation()` に切り出して共有。会計の述語
  (`expiredMonthlyHoldCondition`) は台帳サービスの中に閉じたままにし、候補列挙の口
  `expiredReservationIds()` も台帳サービス側に置く (stream へ複製しない)。
  `ReservationNotReleasableException` は**新設しない**

## [Critical] 施策 7/9: 新設メソッド名 `recoverStaleEvent` が撤去 gate の literal と衝突する
- 判断: 対応する (両方の側で直す)
- 対応内容: (1) 新設メソッド名を `recoverStuckEvent()` に変更し、撤去対象の字面を含めない。
  (2) 撤去 gate を素の部分文字列照合にしない — 検出単位を「撤去したコマンド名 (完全一致)」
  「撤去したクラス名 (FQCN と短縮名)」「撤去したメソッドの宣言・呼び出し形
  (`function x(` / `->x(` / `::x(`) をクラス名とセットで判定」の 3 種に限る。
  走査の基盤は既存の `PhpReferenceScanner` / `PhpTokenScan` を使い自前の正規表現を作らない

## [Warning] 施策 3: `withoutOverlapping()` の既定 (24 時間) に依存すると回収が長時間止まる
- 判断: 対応する
- 対応内容: `RecoveryStream::overlapExpiryMinutes()` (= 実行間隔の 2 倍) を新設し、
  Schedule で明示する。目録 gate が「既定ではなくこの値であること」を検査する。
  理由 (異常終了で残ったロックが丸 1 日回収を止める) を enum の docblock に書く

## [Warning] 施策 2: `min()` の結果を PHPStan が `positive-int` に絞れない
- 判断: 対応する
- 対応内容: `Assert::positiveInteger($pageSize)` を置いて型を閉じる

## [Suggestion] 施策 1: `cron('*/N')` の前提として cadence が 60 の約数であること
- 判断: 対応する
- 対応内容: Unit テストに `60 % cadenceMinutes() === 0` を追加し、enum の docblock に前提を書く

## [Suggestion] 施策 3: `format()` の `%d%s` と空文字引数が無意味
- 判断: 対応する
- 対応内容: 削除した (監視語彙を固定する出力に無駄な揺れを残さない)

## [Suggestion] 施策 8: `cleanup-failed` は手動確認の対象だと運用側へ明示する
- 判断: 対応する
- 対応内容: docs の監視項目の説明に「この件数は手動確認の対象」と書く旨をテスト計画・
  リスク欄へ追記

## [Warning] 施策 9/10: `IdSuppliedByInternalCaller` の provenance が弱い
- 判断: 対応する
- 根拠: 滞留述語の再評価を Service へ寄せた結果、主キーのクエリは Service の private ヘルパに
  立つことになり、適用条件 (private + 引数由来 + request accessor 無し + calledBy 実在) を
  そのまま満たす形になった
- 対応内容: 登録先と `calledBy` の対応表を設計へ明記し、根拠文に
  「id は `<Stream>::candidateIds` が返した主キーで HTTP 入力を経由しない」
  「公開の口は掃引からのみ呼ばれ、その対応は目録 gate が stream キー単位で固定する」を
  必ず書くこととした

## [Warning] 施策 10: 監視語彙の変更がコード外の runbook / ログ検索に影響する
- 判断: 対応する
- 対応内容: `docs/architecture.md` に**旧語彙 → 新語彙の対応表**を残す
  (`replayed → recovered` / `retry-scheduled → deferred` /
  `moved-to-recovery-pending → escalated` / 旧 4 コマンドの件数出力 → `recovered`)

## [Suggestion] 施策 2: sweeper の長時間実行・異常終了時の挙動を docs に寄せる
- 判断: 対応する (上の `overlapExpiryMinutes` と同じ対応で満たす)
- 対応内容: ロックの有効期限と「取り残しは最大 2 周期で解ける」ことを
  `docs/architecture.md` §滞留回収の共通基盤 に書く
