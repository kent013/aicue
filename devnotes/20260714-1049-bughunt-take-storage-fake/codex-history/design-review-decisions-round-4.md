# 対応マトリクス: design-review Round 4

## [Critical] head() がロックを取らず reader が異世代 object/meta を組み合わせる
- 判断: 対応する
- 対応内容: `withKeyLock` を `$operation`（LOCK_EX/LOCK_SH）+ ジェネリック戻り値に一般化。`head()` を **LOCK_SH** 下で object 確認・sidecar 確認/読込・size 取得まで一括実行。promote の LOCK_EX と排他され、exists→get 間の削除による例外も防止。

## [Warning] delete() がロック外で promote と競合し不安定
- 判断: 対応する
- 対応内容: `delete()` を同一 key の **LOCK_EX** 下で object+sidecar 削除に変更。

## [Warning] GET は head() 後にロック解放してから本文を読むため世代ずれの可能性
- 判断: 対応する（制約を明記し、テストスコープを限定）
- 根拠: take key は予約ごとの一意 ULID で実フロー上「同一 key 並行上書き」は起きない。playback/download は登録確定済み・以後不変の object を読むため世代ずれは発生しない。
- 対応内容: 「強整合は登録時 HEAD まで、GET 配信中の共有ロック保持は要求しない」を設計に明記（emulator の防御的堅牢化として key ロックは promote/head/delete に限定）。

## [Critical] 並行契約テストに reader/writer 競合を追加
- 判断: 対応する
- 対応内容: `FakeObjectStoreConcurrencyTest` に「writer を head() の各 filesystem 操作へ割り込ませても null か同一世代 metadata のみ」「delete×promote 競合で不整合が出ない」を追加。
