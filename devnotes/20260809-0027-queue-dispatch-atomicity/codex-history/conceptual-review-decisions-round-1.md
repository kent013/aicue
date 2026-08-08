# 対応マトリクス: conceptual-review Round 1

## [Critical] `AutoRechargeTriggerJob` の `ShouldBeUnique` rollback ロック残留

- 判断: **対応する (設計変更。ただし Codex の提示した 3 択のいずれでもない第 4 の解)**
- 根拠:
  - Codex の指摘は正しい。`UniqueLock` は `PendingDispatch` (vendor:218) で **dispatch 呼び出し時**に
    取得され、rollback 時の解放は `Queue::enqueueUsing` の afterCommit 経路 (vendor:368-374) でしか
    行われない。tx 内 dispatch にすると rollback で `uniqueFor=30` 秒の抑止が残る。
  - しかし根本の誤りは「`AutoRechargeTriggerJob` を確定 1 の適用対象に入れたこと」の側にある。
    この job は **AG-127 の付随的副作用の定義に合致する**:
    - 実装コメントが「$tries = 1: 自動リトライしない (取りこぼしはリコンサイル (v) の管轄 —
      **二重課金面の安全側**)」と明記している = 失われる方が安全という設計判断が既にある
    - 喪失の回収経路が実在し、cron 化されている (`billing:reconcile-auto-recharge` を
      `everyFifteenMinutes` / `onOneServer` / `withoutOverlapping` + `onFailure` で report。
      routes/console.php:92-98)。`AutoRechargeService` の (v)「取りこぼし起票: enabled な org で
      閾値割れ・pending なし (job 消失の回収)」がまさにこの経路
    - `maybeCreateAttempt` が org 行ロック + pending 検査 + DB partial unique で結果の一回性を
      持っており、trigger job の投入は「起票のきっかけ」でしかない
- 対応内容: `AutoRechargeTriggerJob` を **AG-127 の除外対象**に変更する。除外の形は
  「tx 外 dispatch のまま + 失敗を観測へ」= `DB::afterCommit` を撤去し、`reserve()` の
  `DB::transaction()` を**抜けた直後**に dispatch する (afterCommit 温存ではない)。
  これにより unique lock 残留の窓も同時に消える。§3・§4・§6 を書き換えた。

## [Warning] `TicketLedgerService` の低残高通知を tx 内へ入れる方針

- 判断: **対応する (Critical と同じ形に揃える)**
- 根拠: Codex の指摘どおり「0 件 pin のために tx 内へ移す」は理由として不適切だった。
  `docs/architecture.md` が既に「配信保証は at-most-once、通知は補助チャネル、outbox 台帳は
  作らない」と明記しており、AG-127 の除外に該当する。
  また tx 内へ入れると organizations 行ロック (reserve の直列化点) の保持時間が伸びる。
- 対応内容: 低残高通知も **AG-127 の除外**とし、`reserve()` の `DB::transaction()` を
  抜けた直後に呼ぶ形へ変更。既存の `safely()` (catch + report) が失敗観測を担う。
  「reserve が外側 tx にネストされている場合は外側 tx の内側で書かれ、rollback で巻き戻る」
  = 既存 pin (`TicketBalanceLowNotificationTest:104`) は維持されることを設計に明記した。

## [Warning] sync driver 利用時の例外伝播差分が書かれていない

- 判断: **対応する**
- 根拠: 正しい。tx 内 dispatch + `sync.after_commit=true` にすると、job 本体は
  `Connection::commit()` の中 (after-commit callback) で走る。job が投げると
  **SQL COMMIT 済みなのに `commit()` が throw** し、`Connection::transaction()` の
  `handleCommitTransactionException` を通る。concurrency error と判定されると
  **業務クロージャが再実行されうる** (commit 済みなのに)。これは sync レーン固有で、
  本番 (database driver = jobs 行の INSERT のみ) には無い。
- 対応内容: §6 リスク表に R-10 を追加し、§8「保証しないもの」にも記載した。

## [Warning] 期待効果の表現が強い (connection 名一致は同一 tx の代理にすぎない)

- 判断: **対応する**
- 根拠: 正しい。guard が見るのは config の値であり、実 PDO の同一性ではない。
- 対応内容: §9 の効果を「本リポジトリの database queue 構成では」に限定し、
  §8 に「異なる PDO / connection resolver 差し替え / 別 DB サーバまでは保証しない」を追記した。

## [Warning] 検出器ごとに走査母集団を持つべき (0 件 fail が ShouldQueue 母集団に寄りすぎ)

- 判断: **対応する (良い指摘)**
- 根拠: 4 種検出のうち `DB::afterCommit` と config 検出は `ShouldQueue` 母集団と独立で、
  片方の走査だけが死んでも気付けない。
- 対応内容: §5-1 を検出器ごとの母集団 + 個別 0 件 fail に書き換えた。あわせて
  `ShouldQueueAfterCommit` の検出は文字列走査ではなく **`QueuedJobPopulation` に対する
  `implementsInterface()` のリフレクション判定**を主とする (中間 interface 経由・
  親クラス経由の実装まで拾えるため文字列走査より強い) 形に変更した。

## [Warning] スコープが大きい。2 つに分割すべき

- 判断: **一部反論・一部対応**
- 根拠 (反論):
  - AGENTS.md 思考原則 3「後方互換の並走を残さない。書き換えると決めたら同じ PR で旧実装を消す」。
    分割すると main に「afterCommit を撤去した経路と温存した経路」が並走する期間が生まれる。
  - AG-126 の到達基準は「残存 0 件 pin」であり、**全部直し終わるまで gate を有効化できない**。
    前段 PR では gate を無効か虚偽の期待値で入れることになり、
    「gate が嘘をつく」状態を自分で作ることになる (`QueueWorkerLeaseInvariantTest` の
    docblock が config を env 上書きさせない理由として挙げているのと同じ失敗形)。
  - 既存 4 契約の反転は、業務経路の移設と同時でないと「保護対象を消した」変更と区別できない。
- 対応内容 (一部受容): 分割はしないが、**PR 内の実装順序**を設計に明記した (§11-2)。
  1. M1 (config) + M6 (guard) + guard の単体テスト → 2. M9 の behavioral テストを **先に赤で置く**
  (思考原則 5 テストファースト) → 3. M2〜M5 の移設 → 4. M8 の反転 → 5. M7 の 0 件 pin 有効化 →
  6. M10 文書。各段でテストを回す。

## [Suggestion] `QueueDispatchAtomicityGuard` の config 読み出しは PHPStan level 10 向けに shape を明示

- 判断: **対応する (詳細設計で具体化)**
- 対応内容: guard は `config()` の生 array を `mixed` として受け、`is_string` / `is_bool` の
  narrowing を通してから判定する純関数にする。未知の型・キー欠落は **違反として報告**する
  (例外ではなく violations リストに載せる = `ProductionEnvGuard` と同じ流儀)。詳細設計 §施策 M6 に書く。

## [Suggestion] §8 に 3 項目追加

- 判断: **対応する**
- 対応内容: 「sync driver 利用時の job 例外伝播」「connection 名一致は同一 tx の代理検査にすぎない」
  「静的走査は facade alias / helper wrapper 経由の afterCommit を検出しない」を §8 に追記した。

## [Suggestion] 使命との整合性 / 型安全性

- 判断: **見送る (指摘なし。現状維持)**
