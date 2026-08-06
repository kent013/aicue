全体判定: **CHANGES_REQUESTED**

**1. 使命との整合性**
[Suggestion] キュー二重実行の窓を閉じる改善であり、AI-CUE の「標準化されたマニュアル動画」を安定生成する基盤品質には貢献する。ただしユーザー価値に直結する機能改善ではなく、信頼性の下支えとして位置づけるのが妥当。

**2. 禁止事項違反**
[Warning] テストファースト方針は明記されているが、概念設計内で「値を先に決める」比重がやや強い。特に `database=60` / `database-media=240` は「十分」と断じているが、実測または既存タイムアウト設定との照合が弱い。  
修正提案: 実装前の失敗テストに加え、既存ジョブの外部 I/O 上限、HTTP client timeout、Stripe/Object Storage の SDK timeout を確認対象として明記する。

**3. 実現可能性**
[Critical] `QueuedJobLeaseInventoryTest` の「接続宣言の裏取り」が `onConnection('リテラル')` だけを対象にしている点は不十分。Laravel では queue connection は `onConnection()` 以外に、ジョブの `$connection`、Mail/Notification の接続指定、Notification の `viaConnections()` など複数の経路で決まり得る。現状の設計だと、実際の接続指定を見落として誤検出または検査漏れになる可能性がある。  
修正提案: inventory の主目的を「実行時接続の完全推論」ではなく「明示的な運用接続の宣言」に寄せる。裏取りは `onConnection()` 限定ではなく、少なくとも `$connection` / `viaConnections()` / Mail・Notification の既存パターンを調査して、対応範囲外の指定方法は deny-by-default で落とす設計にする。

[Warning] `mprocs.yaml` の全 database 接続集合と dev worker 集合の完全一致は強い制約。将来「dev では起動しない専用接続」を追加する場合に過剰に落ちる。  
修正提案: 完全一致を採るなら「database driver 接続は dev worker を必ず持つ」というアーキテクチャ規約として明文化する。そうでなければ、理由付き inventory で dev worker 不要接続を明示除外できるようにする。

**4. 期待効果の妥当性**
[Warning] 規則 1 の gate は二重取得リスク低減に有効。ただし本番/ステージングの worker 定義がリポジトリ外である以上、改善効果は dev / bug-hunt / repo 内規約に限定される。  
修正提案: 設計本文で「本番二重実行を直接 gate するものではない」と明記し、`docs/architecture.md` への追記内容を「運用側 supervisor は timeout < retry_after を満たすこと」だけでなく、具体値表まで含める。

**5. リスク**
[Critical] `database` の worker timeout を 60 秒にする判断は、Billing/Mail/Notification が全て 60 秒以内に安全に終わる前提に依存している。特に課金系ジョブは外部 API・DB transaction・再試行状態機械が絡むため、60 秒 kill による中途半端な状態遷移のリスク評価が不足している。  
修正提案: `database` 接続に載る 14 ジョブを inventory 化し、各ジョブについて「60 秒 kill されても retry/failed/idempotency が成立するか」を確認する。問題がある場合は timeout を上げるのではなく、ジョブ側の処理境界や HTTP timeout を整理してから値を確定する。

[Warning] `queue:listen` は各ジョブ実行ごとに framework を起動し直すため、`queue:work` と運用特性が異なる。設計上は同じ規則で扱えるが、手動確認やログ期待値は `queue:listen` 前提で書く必要がある。  
修正提案: 手動確認の観点に「mprocs は `queue:listen` である」ことを明記し、将来 `queue:work` に変えた場合も gate が同じ意味を保つことを確認対象に入れる。

**6. スコープの適切さ**
[Suggestion] bug-hunt worker まで含めるのは妥当。`pail` を理由付き除外にするのも適切。  
[Warning] 規則 2 inventory は本 feature の欠落を閉じる目的として妥当だが、全 `ShouldQueue` の接続分類まで入るため、規則 1 より変更範囲が広い。  
修正提案: 実装 PR では施策 3 と施策 4 の失敗理由・修正理由を明確に分ける。レビュー可能性を上げるなら、テスト名・fixture・inventory コメントも「規則 1」「規則 2」で分離する。

**7. 型安全性**
[Warning] Reflection とソース正規表現の併用は PHPStan level 10 で実装可能だが、`getDefaultProperties()['timeout']` は未定義キー・型混在・継承値の扱いを慎重に書かないと型が崩れやすい。  
修正提案: timeout 読み取りを小さな private helper に閉じ、戻り値を `int|null` に正規化する。非 int / 0 / 負数 / 動的 timeout は明示的に fail させる。

結論として、方向性は妥当ですが、**接続推論の網羅性**と **60 秒へ下げる影響評価**が未確定です。この 2 点を設計に織り込めば承認可能です。