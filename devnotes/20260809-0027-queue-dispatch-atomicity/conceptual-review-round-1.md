全体判定: **CHANGES_REQUESTED**

設計方向は妥当です。特に「業務状態 commit 済みなのに queue 未投入」を潰す狙いは North Star に直結しています。ただし、いくつかの前提と検査境界がまだ設計として弱く、このまま実装に入ると “守っているつもりで守れていない” 状態が残り得ます。

**1. 使命との整合性**
[Suggestion] 使命への寄与は妥当です。分析・レンダ・PM 流用・削除系の未投入は、ユーザーに再操作や時間待ちを強いるため、「思考ゼロ・編集ゼロ」の穴を塞ぐ改善です。

**2. 禁止事項違反**
[Warning] `TicketLedgerService::reserve()` の `DB::afterCommit` 撤去で、通知 INSERT を tx 内に入れる方針は注意が必要です。通知失敗が業務 tx を abort させる可能性を受容すると書かれていますが、低残高通知は付随的副作用に近く、業務予約そのものを失敗させる設計に変わります。

修正提案: 「0 件 pin のために tx 内へ移す」ではなく、AG-127 の除外基準に従い、tx 外 dispatch/通知 + 失敗観測へ寄せられないか再検討してください。どうしても tx 内へ入れるなら、「低残高通知の失敗で reserve を失敗させる」ことを明示的な業務仕様として docs/Feature test に固定する必要があります。

**3. 実現可能性**
[Warning] `sync.after_commit => true` の結論自体は vendor 実測の根拠に照らして概ね妥当です。ただし影響範囲が “テストレーンだけ” のように読める箇所があります。実際には dev/local で `QUEUE_CONNECTION=sync` を使う場合も、tx 内 dispatch の例外発生タイミングやリクエストへの伝播が変わります。

修正提案: §3 または §6 に「dev/local で sync を使う場合の例外伝播差分」を明記してください。特に database worker では request に伝播しない job 例外が、sync では commit 後に request へ伝播し得る点を保証外または受容リスクに入れるべきです。

**4. 期待効果の妥当性**
[Warning] 「dispatch 喪失が起きない」と言い切れるのは database queue が同一 DB connection 名を使い、Laravel の queue insert が同一トランザクションに参加する場合に限られます。設計はその前提を置いていますが、§8 では「connection 名一致で代理する」としており、やや保証表現が強いです。

修正提案: 期待効果は「本リポジトリの database queue 構成では commit 済み・未投入窓を閉じる」に弱めるのが正確です。異なる PDO / 別プロセス / connection resolver 差し替えまでは保証しない、と §8 に追記してください。

**5. リスク**
[Critical] `AutoRechargeTriggerJob` の `ShouldBeUnique` rollback ロック残留を「30 秒の窓は受容」としていますが、これは入口排他が best-effort で保証を担わないというドメイン規約と衝突しやすいです。rollback で業務状態が戻ったのに再投入だけ抑止されるなら、再操作・reconcile までの遅延が発生します。

修正提案: この job だけは tx 内 dispatch の適用対象にする前に、unique lock の扱いを個別設計してください。選択肢は、`ShouldBeUnique` を外して永続状態遷移で重複排除する、`uniqueFor` の影響を behavioral test で固定する、または AG-127 相当の除外として reconcile 観測へ寄せる、のいずれかです。

**6. スコープの適切さ**
[Warning] 変更対象が広く、queue semantics、billing、notification、render/capture、architecture gate、docs まで同時に触ります。standalone は妥当ですが、単一 PR としてはまだ大きいです。

修正提案: 分割するなら main に中途半端な afterCommit 残存を入れない形で、まず「設計検査と起動時 guard + sync.after_commit のテスト意味論固定」、次に「業務 dispatch 移設 + 0 件 pin 有効化」に分けるのが現実的です。ただし 0 件 pin は後段で有効化する前提を devnotes に明記してください。

**7. 型安全性**
[Suggestion] DTO/JsonResource には直接触れない設計で、型安全性上の大きな懸念は見えません。新規 `QueueDispatchAtomicityGuard` は config array を読むため、PHPStan level 10 向けに shape を明示し、未知 connection / missing key を例外に倒す実装にしてください。

**特に確認依頼があった点**
[Warning] §5 の「検査が空振りしないことの保証」は方向性は良いですが、母集団 0 件 fail が `ShouldQueue` 実装数に寄りすぎています。`DB::afterCommit` や `config after_commit` の検出は ShouldQueue 母集団と独立です。

修正提案: 検出器ごとに走査母集団を持ってください。例: PHP app files 件数、Notification classes 件数、queue config file 存在、ShouldQueue classes 件数。それぞれ 0 件なら fail にすると、片方の検出器だけ死ぬ状態を防げます。

[Suggestion] AG-127 の除外線引きは概ね正しいです。ただし `TicketLedgerService::reserve()` は「queue dispatch ではないから tx 内へ移す」ではなく、「付随通知をどう扱うか」の設計判断として扱うべきです。

[Suggestion] §8 の保証しないものはよく書けています。追加するなら「sync driver 利用時の job 例外伝播」「connection 名一致は同一 tx の代理検査にすぎない」「静的走査は facade alias / helper wrapper 経由の afterCommit を検出しない」を入れると誇張が減ります。