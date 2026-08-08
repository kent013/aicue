# 対応マトリクス: design-review Round 4

## [Critical] M6: R1〜R3 の sync 除外を `driver === 'sync'` で判定している (fail-open)

- 判断: **対応する (指摘は正しい。自分の疑似コードの穴だった)**
- 根拠: `database-analysis.driver = sync` にすると、その接続は R1〜R3 を全部 skip する。
  R4 が見るのは `connections.sync` だけなので、**pin 済み接続を sync へ差し替える構成が通る**。
- 対応内容: 除外を **接続「名」** (`if ($name === 'sync') { continue; }`) に変更し、
  それ以外の参照接続には `driver === 'database'` を無条件で要求する形にした。
  テスト `pin 済み接続 (database-analysis) の driver が sync なら R1 違反になる` と
  mutation #19 (`database-analysis.driver` を `sync` に変える) を追加した。

## [Critical] M9: dispatcher clone 方式が `QueueManager` の接続キャッシュと不整合

- 判断: **対応する (指摘は正しい。Round 3 で採用した案を撤回する)**
- 根拠: `QueueManager` は解決済み queue connection をキャッシュし、connection は自分が持つ
  container 経由で event dispatcher を引く。swap 前に connection が生成済みなら clone 側の
  listener が捕捉できず、swap 中に生成された connection は capture 後も clone dispatcher を
  握り続けうる。
- 対応内容: Codex の提案どおり **元 dispatcher に listener を足し、`finally` で
  `$active = false` にして不活性化する**方式へ変更した。dispatcher の差し替えも
  既存 listener の削除も起きない。docblock に採らなかった 2 案
  (`Event::forget` / clone swap) とその理由を残した。
  自己テストを **Feature テスト (実 database queue 経由)** に格上げし、
  Codex の挙げた 4 点 + `only()` の filter を固定する。
  mutation #18 を「`$active = false;` を削る」へ変更した。

## [Suggestion] M1: ヘルパ docblock に固定値 `level >= 2` が残っている

- 判断: **対応する**
- 対応内容: docblock を「判定は **baseline + 1 以上**。固定値では判定しない
  (ネストの深さはテストの書き方で変わるため)」へ書き換えた。

## [Suggestion] M3: リスク欄に「戻り値は `mixed`」の古い表現が残っている

- 判断: **対応する**
- 対応内容: PHPStan 適合欄と同じ表現
  (「戻り値型を伝播できるが、解析結果が十分に具体化されない場合に備えて shape を明示する」) へ揃えた。

## [Suggestion] M7: `phpFilesUnder()` の入力契約を検査せよ

- 判断: **対応する**
- 対応内容: 「各入力が**絶対パス**かつ**存在するディレクトリ**であることを明示検査し、
  満たさなければ例外」を docblock ではなく**契約**として書き、
  負のテスト 2 本 (テスト 13 / 14) を追加した。理由も明記
  (「タイポで存在しないルートを渡したときに黙って 0 件を返すと、母集団 0 件 fail の意図が空洞化する」)。

## [Warning] 保証しないもの 13 番と 16 番が矛盾している

- 判断: **対応する**
- 対応内容: 13 番の主文を「**対象ジョブが実際に使う接続**が `database` driver かつ
  `after_commit=false` であることに依存する」に変え、
  「`queue.default='database'` が効くのは `onConnection` で pin されていないジョブだけで、
  pin 済みジョブには直接効かない (16 番と対応)」と位置づけ直した。

## [Warning] mutation 表に M6 の pin 済み接続 sync 化の変異が無い

- 判断: **対応する**
- 対応内容: mutation #19 として追加し、「sync 除外を接続名で行っていないと**落ちない**」ことを注記した。
