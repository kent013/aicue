## 再レビュー結果

Round 1 の主要な競合・誤回収問題は適切に修正されています。ただし、コマンド引数の異常系、滞留述語の単一正本、DirectFetch 分類に未解消の問題があります。

### 施策 1: 回収の共通契約と語彙

**判定: APPROVE**

`overlapExpiryMinutes()` と cadence の前提を型付き語彙へ置いた判断は妥当です。

[Suggestion] テストは「cadence より大きい」だけでなく、設計どおり `overlapExpiryMinutes() === cadenceMinutes() * 2` を固定してください。

### 施策 2: registry と sweeper

**判定: APPROVE**

`Assert::positiveInteger($pageSize)` により、PHPStan level 10 の型境界は解消されています。ページ送り、上限、dry-run、例外継続のテスト計画も十分です。

[Suggestion] 契約違反の stream が `$pageSize` より多く返すと実効上限を超過します。Architecture/Unit テストで各実装が要求件数以下を返すことを固定すると堅くなります。

### 施策 3: 入口コマンドと定期実行

**判定: REQUEST_CHANGES**

[Critical] `resolveLimit(): ?int` では、「未指定」と「不正値」を区別できません。提示された `handle()` は戻り値をそのまま sweeper に渡しており、`--limit=0` や非数値を `FAILURE` にする分岐がありません。実装次第では不正値が無制限実行へ落ちます。

修正案: 解析結果を DTO にするか、検証と取得を分離してください。例えば `resolveLimit()` は不正値で `InvalidArgumentException` を投げ、`handle()` でメッセージを出して `FAILURE` に変換します。少なくともシグネチャだけで「未指定・正常値・異常」を区別できる設計にしてください。

[Warning] `withoutOverlapping(cadence * 2)` は、正常な実行がその時間を超えた場合にもロックが失効し、同一 stream が並行実行されます。「長引いている間の重複起動を抑える」という説明は期限までしか成立しません。

修正案: docs に「実行時間が期限を超えると重複し得る」と保証限界を明記し、想定最大実行時間が期限未満であることを運用監視対象に含めてください。

### 施策 4: 解析ジョブ stream

**判定: REQUEST_CHANGES**

[Warning] 候補列挙の滞留述語は stream、ロック下の再評価述語は Service に重複しています。「同じ1つの式」という設計説明とコードが一致していません。将来片方だけ変更されると、今回防止する誤回収が再発します。

修正案: `AnalysisJobService::staleJobIds()` と private な述語適用メソッドへ集約し、stream は候補列挙も Service へ委譲してください。あるいはモデルの型付き local scope を両クエリで共有します。queued/running の両 arm が候補列挙とロック取得で一致する Architecture/Feature テストも追加してください。

### 施策 5: レンダジョブ stream

**判定: REQUEST_CHANGES**

[Warning] 施策4と同じく、queued/running の2種類の述語が stream と Service に複製されます。レンダは閾値も2本あるため、ドリフトの危険がさらに高いです。

修正案: 候補列挙とロック取得が同じ述語構築処理を使う形へ集約してください。preview/render、queued/running の直積を境界テストで固定する必要があります。

### 施策 6: チケット予約 stream

**判定: APPROVE**

候補列挙とロック下再評価を `TicketLedgerService` 内の同一述語へ集約したため、Round 1 の問題は解消されています。専用例外を撤回した判断も合理的です。

### 施策 7: Stripe webhook stream

**判定: APPROVE**

`recoverStuckEvent()` への改名、主キーによる claim、既存 CAS の維持に問題はありません。監視語彙の対応も明確です。

### 施策 8: 撮影アップロード予約 stream

**判定: APPROVE**

CAS を先行させることで、列挙後に completed へ進んだオブジェクトを削除する競合を避けています。`cleanup-failed` を手動確認対象として明記した点も十分です。

[Suggestion] CAS 更新後に `findReleased()` が null だった場合、S3 削除未実施を `Recovered` と数えます。この経路が正常に起こり得ないなら例外として観測し、起こり得るなら「孤児が残る可能性」を docs に追加してください。

### 施策 9: 目録 gate と撤去済み参照 gate

**判定: REQUEST_CHANGES**

[Warning] 「メソッド名をクラス名とセットで判定」の具体的な成立条件が不足しています。`$service->recoverStale()` のようなインスタンス呼び出しでは、PHP token だけから受信側クラスを確定できない場合があります。

修正案: `PhpReferenceScanner` が変数型を解決できる範囲を明記し、旧クラスを alias importした呼び出し、DI変数からの呼び出し、完全修飾 static 呼び出しの変異テストを追加してください。解決できない場合は、旧メソッド宣言の禁止と旧クラス・コマンドの禁止を正本にし、曖昧なメソッド呼び出し検査を保証事項に含めないでください。

### 施策 10: 目録・docs の波及更新

**判定: REQUEST_CHANGES**

[Critical] 提示された5件は `IdSuppliedByInternalCaller` の既存適用条件を満たしていません。条件は「`calledBy` で identity が解決済みモデルから確定していること」ですが、`failStaleJob()` 等は外部の stream から渡された生の `int $id` を private helper へ中継するだけです。目録 gate が stream 登録を確認しても、公開メソッドへの別呼び出しや ID provenance は証明しません。

修正案は次のいずれかです。

1. 候補列挙と処理を同じ Service 内の閉じた実行経路に再構成し、既存分類の条件を実際に満たす。
2. scheduler inventory 起点の ID を扱う専用の justification enum を新設し、公開入口の呼び出し元集合、HTTP request accessor 不在、対応する stream を deny-by-default gate で固定する。
3. `IdSuppliedByInternalCaller` の意味を変更する場合は全既存登録への影響を監査し、名称・docblock・Architecture テストを同時に更新する。

また、施策8のコード例は `recover()` 内で直接 `whereKey()` していますが、対応表では private `releaseIfStillStale()` に登録するとしています。実装形を private helper 経由に統一してください。

## 全体判定

**CHANGES_REQUESTED**

Round 1 の誤回収、撤去 gate の自己衝突、ロック期限の未指定は解消されています。実装前に最低限、`--limit` 異常値の表現、解析・レンダ述語の単一正本化、DirectFetch の正当な分類を確定する必要があります。