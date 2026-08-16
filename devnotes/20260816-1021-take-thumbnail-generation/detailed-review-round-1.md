**全体判定: CHANGES_REQUESTED**

設計の方向性は概ね妥当ですが、実装前に直すべき整合性・競合時挙動の穴があります。特に S4 / S10 は、設計本文とテスト期待が食い違っています。

| 施策 | 判定 |
|---|---|
| S1 | REQUEST_CHANGES |
| S2 | APPROVE |
| S3 | APPROVE |
| S4 | REQUEST_CHANGES |
| S5 | REQUEST_CHANGES |
| S6 | APPROVE |
| S7 | APPROVE |
| S8 | REQUEST_CHANGES |
| S9 | APPROVE |
| S10 | REQUEST_CHANGES |
| S11 | REQUEST_CHANGES |

**指摘**

[Warning] S1: `thumbnail_path` を「サーバ生成値なので `$fillable` に足さない」と説明していますが、提示された現行 `Take::$fillable` には既に `thumbnail_path` が含まれています。  
修正案: 方針をどちらかに揃えてください。サーバ生成値として扱うなら `thumbnail_path` も `$fillable` から外し、既存の代入箇所は `forceFill()` / relation 経由に寄せる。既存互換で残すなら、設計書の「サーバ生成値なので fillable 外」という説明を撤回し、リスクを明記する。

[Suggestion] S1: `thumbnail_size_bytes` は DB 追加だけでなく `casts()` に `'thumbnail_size_bytes' => 'integer'` を追加する方が、DTO・Resource・PHPStan の読み取り型が安定します。

[Warning] S4: preflight のテスト計画が実装案と矛盾しています。  
「抽出中に別ワーカーが `thumbnail_path` を埋める → PUT は行われるが UPDATE は 0 行」とありますが、実装では PUT 直前の `stillEligible()` が `thumbnail_path !== null` を検出するため、PUT は行われないはずです。  
修正案: このケースの期待値は「upload されない」に変更してください。UPDATE 0 行の競合を固定したいなら、`upload()` fake のコールバックで preflight 後・UPDATE 前に DB を変更する別テストを用意してください。

[Warning] S4: `stillEligible()` は再取得した `$fresh` を使いますが、その後の `thumbnailKeyFor($take)` は最初に取得した古い `$take` の relation を使います。実害は小さい設計に見えますが、preflight の意味を強くするなら同じスナップショットを使うべきです。  
修正案: `stillEligible()` を `Take|null` を返すメソッドにし、PUT と key 生成に `$fresh` を使う、または `run()` 側で preflight 後に再取得済みモデルを受け取る形にする。

[Warning] S5: 「同一 tx 内投入」は、`database-media` queue がアプリ DB と同じ DB connection / transaction に乗ることが前提です。設計書ではそこが明示されていません。  
修正案: `database-media` の queue connection が同一 DB connection を使い、`after_commit` に依存しないことを設計に追記してください。計画済みの実 `jobs` 表テストで、rollback 時に job が残らないことも固定するとよいです。

[Warning] S8: `has_thumbnail` が `thumbnail_path !== null` だけだと、endpoint の公開条件とズレます。endpoint は `status === ready` も要求するため、異常・過去データで UI が 404 画像を取りに行く可能性があります。  
修正案: DTO は endpoint と同じ述語にしてください。

```php
'has_thumbnail' => $this->take->status === TakeStatus::Ready
    && $this->take->thumbnail_path !== null,
```

[Warning] S10: `reloadManual()` の single-flight が `reloading` 中に `Promise.resolve()` を返すため、呼び出し側は実際の reload 完了を待てません。scheduler が試行回数だけ消費し、最新 `manual` を見ないまま次へ進む可能性があります。  
修正案: in-flight の Promise を保持し、並行呼び出しには同じ Promise を返してください。

[Warning] S10 / S11: scheduler の試行回数は集合全体で管理され、2 本目の `watch()` で `attempt = 0` に戻ります。そのため「最大 4 回・約 29 秒」は個々のテイクにも画面全体にも厳密には成立しません。  
修正案: 保証したい単位を決めてください。個々のテイクを有界にするなら `client_take_id` ごとに attempt/deadline を持つ。集合単位でリセットを許すなら、S11 の保証しないものから「最大 4 回・約 29 秒」という表現を外す。

[Suggestion] S2: `readStream()` / `writeStream()` の fake と実 S3 の contract は良い設計です。実装時は `ContentType` の option 名だけでなく、実 adapter 側で metadata に反映されることを薄い統合テストで確認してください。

[Suggestion] S3: `-protocol_whitelist file` を新設側だけに入れる判断は妥当です。既存 render 経路との差分は、今回のスコープ外として docs に残す現在の方針で問題ありません。

[Suggestion] S7: endpoint は `playback()` と同じ認可・404 順序に揃っており妥当です。`has_thumbnail` 修正後は UI からの通常 404 も減らせます。

**まとめ**

設計の骨格、DTO/Resource 方針、S3 surface inventory、queue job の薄い殻、UI の DS token / Lucide 前提は良いです。変更要求は主に「説明と実装の不一致」「競合テストの期待値」「自動 refresh の single-flight と有界性」です。ここを直せば、実装に進める設計になります。