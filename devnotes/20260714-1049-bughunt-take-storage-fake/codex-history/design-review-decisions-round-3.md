# 対応マトリクス: design-review Round 3

## [Critical] 施策3: 同一 key への並行 PUT で「object B + meta A」の torn state が再発
- 判断: 対応する
- 根拠: 単一 writer では正しいが、A/B が並行して sidecar 削除→rename→sidecar 作成すると、B の object 確定〜B の sidecar 作成の間に A の sidecar が観測され得る。checksum 固定で bytes は同一だが content_type meta が食い違う可能性。
- 対応内容: `promote()` の critical section（旧 sidecar 削除 → object rename → 新 sidecar 作成）全体を **key 単位の排他ロック（`flock(LOCK_EX)`）で直列化**する。ロックファイルは object とは別 namespace（`{root}/.locks/{sha1(key)}.lock`）に置き、`finally` で unlock/close を保証。単一 writer 中の HEAD は sidecar 不在期間 null の現行設計で問題なし。責務説明に「atomic なのは object rename のみ。object+sidecar 全体はロック + completion marker で整合」と明記。

## [Critical] 施策9: 同一 key 並行 writer の競合契約テストが必要
- 判断: 対応する
- 対応内容: (a) ロック保持中に別 promote がブロックする（直列化される）ことをロックファイル seam で検証。(b) 各確定区間で `head()` が `null` / (objectA,metaA) / (objectB,metaB) のいずれかのみを返し、**「objectB + metaA」を返さない**ことを状態遷移単位で固定。

## [Warning] 施策9: putenv/$_ENV/$_SERVER の変更がテスト後に漏れる
- 判断: 対応する
- 対応内容: helper で元値を保存し `afterEach`/`finally` で 3 箇所を復元。復元後に必要なら application を再生成。

## [Suggestion] 施策9: provider 統合テストの反対ケース
- 判断: 対応する
- 対応内容: `fake_storage=false` で実クラス（`TakeObjectStorage`/`RenderObjectStorage`）が解決され fake route が存在しないことを固定。
