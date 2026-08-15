## 再レビュー結果

Round 2 の主要指摘はほぼ解消されています。ただし、主キー同一性クエリの目録に未計上のクエリがあり、セキュリティ不変条件上このままでは承認できません。

### 施策 1: 回収の共通契約と語彙

**判定: APPROVE**

cadence、ロック期限、結果語彙、DTO の責務とテスト計画は整合しています。

### 施策 2: registry と sweeper

**判定: APPROVE**

PHPStan の正数保証、実効上限、例外継続、dry-run、公平性のテスト計画は十分です。

[Suggestion] `array_slice()` は契約違反を「検出」せず、黙って補正します。テスト名は「契約違反時も上限を防御する」としてください。実装側が `$pageSize` 以下を返す検査が本来の契約固定です。

### 施策 3: 入口コマンドと定期実行

**判定: APPROVE**

`InvalidArgumentException` により未指定と不正値が分離され、不正な `--limit` が無制限実行へ落ちる問題は解消されています。ロック期限超過時の並行実行も保証限界として正しく記述されています。

[Suggestion] PHPStan 適合チェックの `resolveStreams()` 戻り値がまだ `list<StuckWorkStream>|null` と記載されています。修正後設計では不正値を例外にしているため、`list<StuckWorkStream>` に直してください。

### 施策 4: 解析ジョブ stream

**判定: REQUEST_CHANGES**

[Warning] `failStaleJob()` の通知処理にある `AnalysisJob::query()->findOrFail($id)` は、新たなクラス起点の主キー同一性クエリです。しかし施策10の登録一覧には `lockStaleJob()` しかありません。このままでは `ModelDirectFetchInvariantTest` の未登録対象になります。

修正案: transaction の内部結果を `AnalysisJob|null` として受け取り、成功時はそのモデルインスタンスを使って通知してください。例えば private な処理がロック済みモデルを返し、commit 後に `$failedJob->refresh()` を通知へ渡せば、追加のクラス起点クエリを避けられます。現在の bool 公開契約は最後に `$failedJob !== null` へ変換すれば維持できます。

### 施策 5: レンダジョブ stream

**判定: REQUEST_CHANGES**

[Warning] 施策4と同型の通知実装にする場合、`RenderJob::query()->findOrFail($id)` も未登録の主キー同一性クエリになります。

修正案: 施策4と同様、transaction からロック済み `RenderJob|null` を内部的に返し、そのインスタンスで commit 後通知を行ってください。設計書にも通知時の再取得方法を明記してください。

### 施策 6: チケット予約 stream

**判定: APPROVE**

候補列挙とロック下再評価が同一の会計述語を使用し、競合も `Skipped` に閉じています。例外型を増やさない判断も妥当です。

### 施策 7: Stripe webhook stream

**判定: APPROVE**

既存のロック下再評価と世代 CAS を維持しつつ、共通 stream へ適切に移設されています。

### 施策 8: 撮影アップロード予約 stream

**判定: REQUEST_CHANGES**

[Critical] `releaseIfStillStale()` には主キー同一性クエリが2回あります。

1. CAS の `whereKey($id)->update(...)`
2. CAS 成功後の `whereKey($id)->first()`

`DirectFetchInventory` は既存例から分かるとおりクエリ出現単位でキーを持ちますが、施策10では同メソッドを1件として数え、全体を「5件登録」としています。2件目が未登録になる可能性が高く、deny-by-default のセキュリティ gate と一致しません。

修正案: `releaseIfStillStale()` 内の2クエリを別々の inventory entry として登録し、合計を6件にしてください。走査器が同一メソッド内の複数クエリをどう採番するかもキー例に明記してください。あるいは、ロック付き取得で述語を再評価してパスを取得し、同一 transaction 内で状態更新する形に変更すれば、主キー取得を1回へ集約できます。

### 施策 9: 目録 gate と撤去済み参照 gate

**判定: APPROVE**

撤去 gate の保証を宣言・旧クラス名・旧コマンド名に限定したことで、token 走査能力を誇張しない設計になっています。変異テストも妥当です。

### 施策 10: 目録・docs の波及更新

**判定: REQUEST_CHANGES**

[Critical] DirectFetch の登録母集団が不完全です。少なくとも施策8の2件目が欠け、施策4の通知再取得を現状どおり実装するならそれも追加対象です。レンダ側も同型実装なら同様です。

修正案: 実装予定コードに対して主キー同一性クエリを出現単位で再棚卸しし、各クエリを新分類へ登録するか、不要な再取得を排除してください。

[Warning] リスク欄が旧案のままです。`IdSuppliedByInternalCaller` と「列挙が同一クラス内」という説明は、新設した `IdFromRecoveryCandidateEnumeration` の設計と一致しません。

修正案: リスク欄を、新分類の呼び出し元集合検査が静的に解決可能な呼び出しだけを保証すること、動的呼び出しには沈黙することへ更新してください。テスト計画の `calledBy` 表記も `entryPoint` に統一してください。

## 全体判定

**CHANGES_REQUESTED**

回収ロジックと運用設計は承認可能な水準です。残件は主に `DirectFetchInventory` の完全性です。施策8の複数クエリと、解析・レンダ通知時の再取得を正確に整理すれば、次ラウンドでは全体承認が見込めます。