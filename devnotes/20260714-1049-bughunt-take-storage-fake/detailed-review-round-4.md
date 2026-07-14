## 施策別判定

- 施策1: **APPROVE**
- 施策2: **APPROVE**
- 施策3: **REQUEST_CHANGES**
- 施策4〜8: **APPROVE**
- 施策9: **REQUEST_CHANGES**

## 残る指摘

- [Critical] writer同士は直列化されましたが、`head()` がロックを取得しないため、説明している「readerは常に整合した組だけを見る」は成立しません。

  例:

  1. `head()` が旧sidecarの存在を確認
  2. writerがロック内でsidecar削除・object更新・新sidecar作成
  3. `head()` が旧metaまたは新metaと、別世代のobject sizeを組み合わせる

  sidecarの `exists()` と `get()` の間で削除されれば、一時的な例外にもなります。

  **修正案:** ロック処理を共有・排他対応にし、`head()` のobject確認、sidecar確認・読込、size取得全体を同じkeyの `LOCK_SH` で囲んでください。`promote()` は `LOCK_EX` のままです。

- [Warning] `delete()` もロック外なので、`promote()` と競合するとsidecarだけが残る、または削除後にPUTが確定するなど結果が不安定です。

  **修正案:** objectとsidecarの削除全体を同じkeyの `LOCK_EX` で囲んでください。許容する競合結果もテストで固定します。

- [Warning] GETは `head()` 後にロックを解放してから `BinaryFileResponse` がファイルを読むため、並行上書き時にはヘッダ世代と本文世代がずれる可能性があります。

  **修正案:** fake用途として許容するなら、その制約を明記してテスト対象を登録時のHEAD整合までに限定してください。S3同等性を要求するなら、GET送信中も共有ロックを保持できる配信方式が必要です。

- [Critical] `FakeObjectStoreConcurrencyTest` はwriter間だけでなく、`head()` の各filesystem操作へwriterを割り込ませるreader/writer競合を検証してください。結果は `null` または同一世代のmetadataに限定します。

## 全体判定

**CHANGES_REQUESTED**

writerロックの方向性は正しいです。`head()` の共有ロックと `delete()` の排他ロックを加えれば、設計承認可能な状態です。