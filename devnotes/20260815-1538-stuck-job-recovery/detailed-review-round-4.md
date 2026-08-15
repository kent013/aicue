## 再レビュー結果

Round 3 の DirectFetch 件数不整合と通知時の再取得は解消されています。一方、新しい DirectFetch 分類がアップロード stream の構造には適用できず、監視対象にも欠落があります。

### 施策 1: 回収の共通契約と語彙

**判定: APPROVE**

契約、enum、DTO、cadence とロック期限のテスト計画に未解消事項はありません。

### 施策 2: registry と sweeper

**判定: APPROVE**

ページ送り、実効上限、防御的切り詰め、例外継続、dry-run の責務が明確です。契約検査と防御処理も区別されています。

### 施策 3: 入口コマンドと定期実行

**判定: REQUEST_CHANGES**

[Warning] Schedule コメントの必須監視対象に `deferred` と `cleanup-failed` がありません。特に webhook の処理失敗は内部で `report()` されて `Deferred` を返し、sweeper の `failures` は増えません。そのため `errors=0` でも回収が失敗し続ける状態があります。

修正案: 必須監視対象へ次を追加してください。

- `deferred > 0` の継続: webhook 再処理が失敗し続けている
- `cleanup-failed > 0`: S3 孤児削除の手動確認が必要

コマンド出力を固定するテストでも、この2語彙が欠落しないことを確認してください。

### 施策 4: 解析ジョブ stream

**判定: APPROVE**

述語の単一正本化、行ロック下の再評価、ロック済みモデルによる commit 後通知まで整合しています。`refresh()` はインスタンス起点であり、今回対象とするクラス起点の DirectFetch を増やしません。

### 施策 5: レンダジョブ stream

**判定: APPROVE**

解析と同じ構造で誤回収を防止し、kind・状態・境界のテストも網羅されています。

### 施策 6: チケット予約 stream

**判定: APPROVE**

会計述語を台帳 Service 内に維持し、候補列挙とロック取得で共有する設計に問題はありません。

### 施策 7: Stripe webhook stream

**判定: APPROVE**

ロック下の再評価、世代 CAS、結果語彙の変換は妥当です。既存の replay safety も維持されています。

### 施策 8: 撮影アップロード予約 stream

**判定: REQUEST_CHANGES**

[Critical] 新分類の適用条件と、この stream の呼び出し構造が一致しません。

登録案では次の構造です。

```text
StuckWorkRecoverySweeper::sweep()
  -> StaleUploadReservationStream::recover()       entryPoint
     -> StaleUploadReservationStream::releaseIfStillStale()
```

しかし `IdFromRecoveryCandidateEnumeration` は「entryPoint の呼び出し元が申告 stream の `candidateIds()` / `recover()` だけ」と要求しています。アップロードの entryPoint は `recover()` 自身なので、その呼び出し元は sweeper であり、申告 stream のメソッドではありません。このエントリは設計された gate を通りません。

修正案は次のいずれかです。

1. 新分類に2つの明示的な形を定義する。
   - ドメイン Service 型: `Service entryPoint <- Stream::recover`
   - stream 内完結型: `Stream::recover -> private helper` かつ stream が registry と回収目録に登録済み
2. アップロード処理をドメイン Service に移し、他4本と同じ `Service entryPoint <- Stream::recover` に統一する。

前者が変更範囲の小さい修正です。2形は enum を増やさず、名前付きコンストラクタの discriminator などで機械的に区別できます。

### 施策 9: 目録 gate と撤去済み参照 gate

**判定: APPROVE**

撤去 gate の保証範囲は現実的に限定され、変異テストも十分です。施策8で新分類の適用形を修正した場合、その stream 内完結型についても変異テストを追加してください。

### 施策 10: 目録・docs の波及更新

**判定: REQUEST_CHANGES**

[Critical] `StaleUploadReservationStream::releaseIfStillStale` の登録が、現在記載された `IdFromRecoveryCandidateEnumeration` の適用条件を満たしません。上記の stream 内完結型を分類仕様と `ModelDirectFetchInvariantTest` に追加してください。

[Warning] 新分類は「呼び出し元集合をすべて機械検査する」と宣言する一方、リスク欄では変数経由の呼び出しに沈黙するとしています。保証表現が矛盾しています。

修正案: 「静的に解決できた呼び出し元集合が期待集合と一致する」だけでは deny-by-default になりません。少なくとも entryPoint のメソッド名呼び出しを全件列挙し、型を解決できない呼び出しが1件でもあれば fail するか、理由付き exemption を要求してください。それができない場合は「すべて機械検査する」という表現を削り、保証限界を Architecture テストの docblock に明記してください。

[Warning] `docs/architecture.md` の監視対象一覧が `errors` / `limit-reached` / `escalated` の3つだけで、旧 `retry-scheduled` の後継である `deferred` と、手動確認対象の `cleanup-failed` が抜けています。

修正案: 必須監視対象を少なくとも5つにしてください。

```text
errors / limit-reached / deferred / escalated / cleanup-failed
```

## 全体判定

**CHANGES_REQUESTED**

回収処理本体は承認可能です。残る実装前の必須修正は、アップロード stream に適用できる DirectFetch 分類形の追加と、`deferred`・`cleanup-failed` を必須監視対象へ戻すことです。