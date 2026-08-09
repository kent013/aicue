## Round 2 レビュー

### `scripts/bug-hunt-shard.sh`: APPROVE

[Suggestion] `(y7p)` は実際の zombie process を生成しているわけではなく、`group_member_counts` の観測結果を stub しています。ただし検証目的は「zombie-only と判定された後の `stop_shard_workers` の帰結」なので妥当です。pidfile の削除まで確認しており、受入条件 1 を呼び出し側まで固定できています。

`(y7q)` は受入条件 9・10を実挙動として固定できています。

- `require_orchestrator` を stub しており、gate による偽陽性を排除している
- `cmd_teardown` 本体を実行している
- 停止失敗 shard の dropdb 不実行を DB 名単位で確認している
- 別 shard の dropdb 実行を positive control にしている
- teardown の非ゼロと pidfile 保持を確認している

特に marker 全体が空かではなく、対象 shard の DB 名が含まれないことを検査している点が適切です。

`(y7r)` も再確認層の帰結を固定できています。pidfile の消滅は `stop_shard_workers` が停止成功まで進んだことを示す意味的イベントなので、呼び出し回数より安定した phase 切替です。mutation で `(y7r)` が赤くなることも、実効性の十分な裏付けになっています。

停止フェーズで `0 1 0` を返す理由も妥当です。stub した `kill -0` は実際の `setsid sleep` に対して成功するため、`0 0 0` では意図どおり fail-closed 条件に入ります。zombie を1件観測した状態にすることで「停止済みだが PGID は zombie として残る」という対象ケースを表現できています。

フィールド数検査も正しいです。配列化後に要素数を厳密に3へ固定し、その後で全要素を非負整数として検査しているため、空・不足・過剰・非数値のすべてが fail-closed になります。追加された `(y7m3)` も問題を直接固定しています。

### `scripts/setup-worktree.sh`: APPROVE

関数呼び出しが `if` の条件位置から外れ、`install` の失敗が top-level の `set -e` に伝播する形になっています。Round 1 の問題は実装上解消されています。

### `tests/Architecture/BughuntSelfTestExecutionTest.php`: APPROVE

変更なし。self-test の自動実行と外部 sandbox の所有権契約を引き続き固定しています。

### `tests/Architecture/BughuntOptimizeClearTaskInventoryTest.php`: APPROVE

変更なし。保証範囲も「集合変化の検出」に限定されており、誇張はありません。

### `tests/Architecture/BughuntRawDbCommandInventoryTest.php`: APPROVE

追加された6行が理由付き inventory に登録され、未知行と曖昧一致を引き続き拒否しています。テスト追加時に実際に赤くなったことも deny-by-default の実効性を裏付けています。

### `tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php`: REQUEST_CHANGES

[Warning] Round 1 で問題にした「コピー失敗を成功扱いしない」契約の回帰テストが追加されていません。実装は現在正しいものの、将来関数呼び出しが再び `if` 条件へ移されても、既存5件はすべて通る可能性があります。

修正案として、コピー元を存在させたうえでコピー先を失敗させ、実行結果が非ゼロになるケースを追加してください。関数単体だけでは top-level の `set -e` 配線を固定できないため、少なくとも本体側の呼び出しが条件式に置かれていないことも静的に検査するのが確実です。

## 全体判定: CHANGES_REQUESTED

今回の主眼である `(y7p)(y7q)(y7r)` とフィールド数検査は承認できます。残る変更要求は、`setup-worktree.sh` の失敗伝播をテストで不変条件として固定する1点です。