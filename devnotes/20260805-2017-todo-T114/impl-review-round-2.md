`scripts/ci/drop-test-db.php`

[Warning] `--apply` の終了コード分岐がテストで固定されていません。`reports a partial result...` は `dropped !== targets` の確認だけで、実際に exit 1 を選ぶコードとの結合を検証していません。今回の元指摘はまさに「失敗しても exit 0」なので、終了コード判定を純関数へ抽出し、全成功→0／失敗・skip→1を直接テストしてください。

`$exec` 注入は、DROP SQL生成・guard・実運用境界が同ファイル内に留まっているため、「DROP責務を分散させない」設計方針に適合しています。entrypoint guardにも問題は見当たりません。

`scripts/ci/pgsql_test_conn.php`

指摘なし。`false` と例外の双方をbest-effort失敗として扱い、契約と実装が一致しました。

`tests/Unit/Ci/DropTestDbScriptTest.php`

上記Warning以外は妥当です。dev/bug-hunt/allowlist防御、executor継続、引数のfail-closed、LLM禁止文言を適切に固定しています。

`tests/Unit/Ci/TestDatabaseProvenanceTest.php`

指摘なし。`PDO::exec() === false` の回帰ケースが追加されています。

DESIGN.md / Atomic Design: 該当なし。

全体判定: CHANGES_REQUESTED