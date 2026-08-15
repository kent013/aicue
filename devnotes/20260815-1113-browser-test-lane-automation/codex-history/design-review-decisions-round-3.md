# 対応マトリクス: design-review Round 3

## [Warning] 施策 2: C14 の失敗条件が成立しない (初期化で消える)

- 判断: **対応する** (指摘のとおり。fixture が成立していなかった)
- 根拠: スクリプトはレーンループの前に `rm -rf "${ARTIFACT_DIR}"` するので、
  実行前に置いた同名ファイルは消えてしまい `mkdir -p` が成功する。
- 対応内容: 失敗条件を **pest スタブに作らせる**順序へ変更した。
  (1) screenshot を書く → (2) `storage/browser-test-artifacts` を通常ファイルとして作る →
  (3) 指定の終了コードで終わる。
  さらに検査を強化し、**pest スタブを `exit 23` にして最終終了コードが 23 のまま**であることを
  確認する (0 で確認しても「結果を上書きしない」ことの証明にならないという指摘を採る)。

## [Warning] 施策 4: W20 は shell の行継続で迂回できる

- 判断: 対応する
- 対応内容: `normalizeShellContinuations()` (`\` + 改行 + 先頭空白 → 空白 1 個) を
  純関数として置き、**照合の前に畳む**。負のコントロールに行継続の形と、
  この関数自身の保証 (通常の改行は残すこと) を追加する。

## [Warning] 施策 5: `scripts/` の走査が `glob()` では再帰的にならない

- 判断: 対応する
- 対応内容: `RecursiveDirectoryIterator` + `RecursiveIteratorIterator` で再帰走査する
  (既存 `ScriptsReadmeInventoryTest::scriptsDirectoryFiles()` と同じ道具・同じ理由)。
  負のコントロールに `scripts/tools/foo.sh` を追加する。

## [Warning] 施策 5: shell / Dockerfile の行継続も行単位検査を迂回する

- 判断: 対応する
- 対応内容: `browserProvisioningCodeLines()` を
  「コメント除去 → **行継続の畳み込み**」の順に行う純関数へ変更する
  (順序を逆にすると継続行の途中の `#` の扱いが変わって取りこぼす)。
  負のコントロールを shell と Dockerfile の両方に置く。

## [Warning] 施策 6: 設計書内のテスト件数と対象 ID が更新しきれていない

- 判断: 対応する
- 対応内容: 範囲表現をやめて実際の採番と一致させた。
  - 層 1: ケース数の下限を **19**
  - 層 3: **S1 / S2 / S3 / S4 / S4b / S4c / S5 / S6 / S7 の 9 ケース**と明示列挙
  - テスト計画・実装順序: **C9 / C10 / C11 / C12 / C13 / C14 / C15 (欠番なし)** と明示
