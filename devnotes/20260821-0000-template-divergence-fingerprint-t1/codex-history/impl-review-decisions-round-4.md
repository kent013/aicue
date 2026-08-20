# 対応マトリクス: impl-review Round 4

## [Critical] 一覧の削除経路が非 regular な残置と読み取り失敗を「不在」と誤認する

- 判断: **対応する**
- 根拠: 指摘のとおりで、これは **Round 3 の私の修正が持ち込んだ fail-open** である。
  `$reader(...) !== false` を存在判定に使ったのが誤りで、production の reader は
  `is_file($path) ? file_get_contents($path) : false` なので、
  壊れた symlink・ディレクトリ・読み取り不能な通常ファイルはすべて false を返す
  = 「不在」と誤認して削除せずに成功する。さらに元から不在でも
  `debtInventoryRemoved = true` になっていた。
  引退後の安定状態を作るための修正が、逆に**残置を成功扱いにする経路**を開けていた。
- 対応内容: 読み取り結果を存在判定に使うのをやめ、指摘された 3 段へ分けた。
  1. 削除前の `InventoryPresence` を取る
  2. 存在する場合だけ削除を試み、削除器が false を返したら例外
  3. **削除後に `InventoryPresence` を取り直し、`Absent` でなければ例外** (fail-closed)
  `debtInventoryRemoved` は**実際に存在したパスを削除したときだけ** true にする。
  presence の解決と削除は注入できるようにし、負例で 6 形を固定した (下記)。

## [Warning] 新しい削除分岐の負例が足りない

- 判断: **対応する**
- 根拠: 正常な通常ファイルの削除と安定した不在しか見ていなかった。
  指摘のとおり、現在の実装 (Round 4 以前) では壊れた symlink とディレクトリが
  残ったまま成功する負例が実際に再現できる。
- 対応内容: dataset を 6 形にした —
  通常ファイル (削除される) / symlink (リンクだけ消えて Absent になる) /
  壊れた symlink (残るので例外) / ディレクトリ (残るので例外) /
  削除器が false を返す (例外) / 元から不在 (削除しないので `debtInventoryRemoved` は false)。
  **一時 root の実ファイルシステム上に本物の symlink・ディレクトリを作って**再現する
  (作り物の presence を渡すのではなく、production の削除器の実際の挙動を通す)。

## [Warning] `RegularFileReader` の「読み取りが失敗した」分岐に負例が無い

- 判断: **対応する**
- 根拠: 指摘が正しい。symlink と壊れた symlink はどちらも最初の分岐へ入るので、
  `file_get_contents()` が false を返す最後の分岐は 1 度も通っていなかった。
  docblock が保証対象に挙げている分岐なので (c) の対象である。
- 対応内容: 読み取り関数を注入できる形にした
  (`read(string $path, string $label, ?callable $reader = null)`。既定は `file_get_contents`)。
  regular file の判定を通った後に読み取りが false を返す負例を独立して足した。
  既定の読み取り器が使われることも正例で押さえた。

## [Warning] `LedgerPins::ADOPTION_DEBT_DIVERGENCE_ID` の docblock が旧設計のまま

- 判断: **対応する**
- 根拠: 「債務が 0 件になったらこの登録ごと消す」は Round 4 で確定した設計
  (「登録は一覧クラスの説明として残す」) と**正反対**である。
- 対応内容: 「引退時に外すのは対象パスの 1 行だけで、登録そのものは残る」へ書き換えた。

## [Warning] gate 側にも旧設計の記述が残っている

- 判断: **対応する**
- 根拠: `fingerprintDebtRetired()` の docblock (「引退後は一覧ファイルも登録も
  残っていてはならない」) と F12 のコメント (「どちらも残っていてはならない」) が
  実装と逆である。保証範囲の説明がコードと逆向きなのは最も避けたい形である。
- 対応内容: どちらも「一覧のパスと対象パスの 1 行は消えるが、登録そのものは残る」へ直した。

## [Suggestion] `retired` / `debtInventoryRemoved` / `newlyRetired` を分ける

- 判断: **対応する**
- 根拠: 指摘のとおり `retired` は「生成結果が 0 件」なので、安定した引退状態で
  再実行するたびに「0 件になった」と案内が出る。`debtInventoryRemoved` も
  常に true だったので「生成器が取り除いた」と嘘を表示していた。
- 対応内容: 報告を 3 つに分けた。
  `retired` = 生成結果が 0 件 / `debtInventoryRemoved` = 今回実際に削除した /
  `newlyRetired` = 既存債務が非空から 0 件へ遷移した。
  CLI の案内 (pin と対象パスを直せ) は **`newlyRetired` のときだけ**出し、
  「生成器が取り除いた」の 1 行は `debtInventoryRemoved` のときだけ出す。
