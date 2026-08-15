全体判定: **CHANGES_REQUESTED**

**1. 使命との整合性**
- [Warning] 方向性は North Star に整合します。解析・撮影アップロード・レンダ・課金予約の滞留は、現場作業者が「撮って終わる」体験を止めるため、回収基盤の標準化は本質的に貢献します。
  修正提案: 使命への貢献は「全回収を統合すること」ではなく、「動画作成パイプラインの滞留を運用上検知・回復できること」と定義し、billing 系を含める理由を quota/予約枠の解放として明記してください。

**2. 禁止事項違反**
- [Critical] `work:recover-stuck` が既定 dry-run になる設計はよい一方、Schedule 側が `--apply` を付け忘れると本番回収が完全に止まります。これは「滞留回収」機能の silent failure です。
  修正提案: Architecture テストで、定期 Schedule に登録された `work:recover-stuck` は全 stream について必ず `--apply` 付きであることを固定してください。手動実行だけ既定 dry-run にする設計にするべきです。

- [Warning] 「撤去済み参照の再流入を止める gate」が docs 全体を対象にすると、devnotes や履歴ドキュメント内の正当な過去記録まで落とす可能性があります。
  修正提案: 対象を実行コード、テストの現行 inventory、運用 docs の正本に限定し、`devnotes/` や closed history は除外または明示 allowlist 化してください。

**3. 実現可能性**
- [Warning] Laravel 12 + Svelte 5 + Inertia.js 上の実現可能性は高いです。ただし今回は主に Console / Service / Architecture test の変更で、Svelte/Inertia への影響はほぼありません。
  修正提案: 実装方針ではフロント影響なし、API surface 変更なし、Console/Service 層の置換であることを明記してください。

- [Warning] `candidateIds()` が「主キーだけを返す」だけでは、大量滞留時に全 ID をメモリへ載せる実装を防げません。
  修正提案: 契約に `limit`、安定順序、chunk/lazy collection のいずれかを含め、sweeper 側で「候補列挙も上限内」にしてください。

**4. 期待効果の妥当性**
- [Critical] 「cron の失敗が全 stream で運用アラートに載る」は期待効果にありますが、実装方針に通知の具体策がありません。
  修正提案: `Schedule::onFailure()` / 既存通知基盤 / ログ監視対象のどれを正本にするかを設計に追加し、全 scheduled stream が失敗通知へ接続されていることを Architecture または Feature test で固定してください。

- [Warning] dry-run の価値は妥当ですが、stream ごとの「dry-run で何を数えるか」が曖昧です。特に webhook のように outcome が複数ある stream は、候補数だけでは運用判断に足りない可能性があります。
  修正提案: dry-run は副作用なしで `candidate` / `would_recover` / `would_skip` まで返すのか、単純な候補数だけなのかを stream 契約で定義してください。

**5. リスク**
- [Critical] `TicketLedgerService::release()` は不成立時に `LogicException` とあるため、候補列挙後に別プロセスが処理済みにしただけで sweeper 全体が失敗扱いになる恐れがあります。
  修正提案: stream の `recover(id)` は競合による述語不成立を例外ではなく `NOOP` / `SKIPPED_NOT_STALE` の outcome として返す契約にしてください。真の不変条件違反だけ例外に分けるべきです。

- [Warning] 撮影アップロード予約の `(b) 未登録 S3 オブジェクト削除` は DB 内の滞留回収より外部副作用が強く、dry-run・再試行・部分失敗の扱いが難しいです。
  修正提案: stream に入れる場合は、候補 ID の正本を DB に限定し、S3 削除の失敗時 outcome と再実行時の冪等性を Feature test で固定してください。

**6. スコープの適切さ**
- [Warning] 5 本の同時寄せ替え、旧実装撤去、upload sweeper の責務分割、目録 gate、撤去 gate、通知統一を 1 PR に入れるのはやや大きいです。ただし「並走を残さない」原則があるため、分割しすぎると逆に危険です。
  修正提案: PR は 1 本でも、実装順を fail-first test → 共通基盤 → 低リスク stream → upload 分割 → 旧入口撤去 → schedule/alert gate の順に固定してください。

- [Suggestion] `capture:purge-upload-reservations` の新設は妥当ですが、これは滞留回収ではなく保持期間決着なので、今回の主目的から外れやすいです。既存 `sweep()` 解体に必要な最小限として扱うのがよいです。

**7. 型安全性**
- [Warning] outcome を「結果の種類ごとの件数」とする方針はよいですが、自由文字列にすると PHPStan level 10 と運用集計の両方で弱くなります。
  修正提案: `RecoveryOutcome` enum と `RecoveryStreamResultData` のような typed DTO を使い、command 出力・ログ・通知は DTO 経由にしてください。

- [Suggestion] `StuckWorkStream` は generic を使わず `int|string $id` に逃げると型が緩みます。各 stream が対象モデルの ID 型を明示できないなら、少なくとも `non-empty-string|positive-int` 相当の validation を stream 境界で持たせるとよいです。

結論として、設計の方向性は妥当です。ただし scheduled 実行の `--apply` 固定、失敗通知の具体化、競合時 outcome、候補列挙の上限、upload/S3 削除の副作用設計が未確定のため、このまま詳細設計へ進めるには危険があります。