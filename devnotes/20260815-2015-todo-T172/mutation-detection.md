# T172 台帳の変異検出 (空振りしていないことの確認)

詳細設計 施策 4「リスク」欄が求めた確認。台帳テスト
`tests/Architecture/ClaudeHooksWiringTest.php` に対し、守るべき対象を 1 つずつ壊して
**実際に赤くなること**を確かめた (壊した後は毎回もとへ戻し、最後に緑を再確認した)。

実行コマンド: `vendor/bin/pest tests/Architecture/ClaudeHooksWiringTest.php`

| # | 壊したもの | 結果 | 落ちた検査 |
|---|---|---|---|
| 基準 | (無変更) | 70 passed / 0 failed | — |
| M1 | `.claude/settings.json` の `timeout` を 30 → 31 | 69 passed / 1 failed | S05/S06 (起動文字列・timeout の完全一致) |
| M2 | 同 `matcher` を `Write\|Edit` → `Write` | 69 passed / 1 failed | S05/S06 |
| M3 | 見本ファイル `.claude/settings.bughunt-hook.example.json` を復活 | 69 passed / 1 failed | S08 (見本方式の非復活) |
| M4 | 検索パス安全化ブロックを 2 本のうち片方だけ変更 | 69 passed / 1 failed | S10 (byte 一致 + 先頭配置) |
| 復帰 | すべて元に戻す | 70 passed / 0 failed | — |

4 種類すべてで**狙った検査だけ**が落ちた = 台帳は空振りしていない。

## 手順上の注意 (次に同じ確認をする人へ)

新規追加ファイル (まだ `HEAD` に無いファイル) を壊した後に
`git restore --staged --worktree <path>` で戻そうとすると、`HEAD` に復元元が無いため
**ファイルごと消える**。変異検出では対象ファイルを退避ディレクトリへ複製しておき、
複製から書き戻すこと (本記録の実行はこの方法で行った)。
