# 対応マトリクス: design-review Round 2

## [Critical] S2: 外側の環境ファイル置き場が「必ずリポジトリ外」を強制していない

- 判断: **対応する**
- 根拠: 正しい。`withEnvironmentDirectory()` は任意の `$baseDirectory` を受け、内側の
  `BootProbeRunner` だけがリポジトリ外の fail-closed を持っていた。正典 v1 (5) は
  「リポジトリを汚さない」ことを求めており、外側だけ抜けているのは保証の穴である。
- 対応内容: `withEnvironmentDirectory()` に**内側と同じ fail-closed** を入れた。
  1. `$base` が絶対パス・実在ディレクトリ・書き込み可能であることを表明する
  2. 作成後に `realpath()` で正規化する
  3. **`BootProbeRunner::isInside(FakeClassCatalog::repoRoot(), $directory)` が真なら、
     callback を呼ぶ前に作成済みディレクトリを消してから例外にする**
  4. 負例 **P-10d** を追加 (リポジトリ内を渡すと本体を呼ばず、残骸も残さない)。
     「本体を呼ばない」ことは callback が触る番兵で決定的に測る
  判定に `BootProbeRunner::isInside()` を使うのは、内側と同じ境界規則
  (区切り文字を境界にする / 同一パスも配下と見る) を 2 か所で持たないためである。

## [Warning] S2: `chmod()` の結果を無視しており helper 単体の契約が成立しない

- 判断: 対応する
- 対応内容: helper 自身が **`chmod()` の成功と実効 mode (0700) を callback の前に検査**し、
  失敗時は**作成済みディレクトリを消してから**例外にする形へ直した
  (`run()` が後から測る `assertSafePermissions()` は環境ファイル込みの検査として残す)。

## [Warning] S4: P-10c が「空ディレクトリを消せる」ことしか測っていない

- 判断: 対応する
- 根拠: 正しい。制限時間超過の実際の状況では `.env.probe` が既に存在するので、
  「中身ごと再帰削除される」ことまで測らないと主張と距離がある。
- 対応内容: P-10c の callback 内で **`.env.probe` 相当のファイルと下位ディレクトリの中の番兵**を
  作ってから例外を投げる形へ変えた。これで「例外経路でも中身ごと消える」が単独で証明できる。

## [Warning] S6: gate 名と冒頭説明が実際の保証より広い

- 判断: 対応する
- 根拠: 正しい。`PhpChildProcessLaunchInventoryTest` / 「PHP の子プロセスを起こしうる箇所の全数申告」は
  `'php'` / `env php` / シェル経由 / 変数実行体を検出しない以上、名前が保証を誇張している。
  **機能の名前に立ち返れ**という思考原則にも反する。
- 対応内容: ファイル名を **`tests/Architecture/PhpBootProbeReferenceInventoryTest.php`** へ改め、
  冒頭説明を「**`PHP_BINARY` / `bootstrap/app.php` / 既存の子入口スクリプトという 3 種類の
  字句参照の全数申告**」に限定した。設計中の全ての参照 (施策一覧・軸 B/C の自己申告・
  乖離台帳の表・実装順) を新しい名前へ揃えた。

## [Warning] S6: G-6 のトークン列判定に恒久の正例・負例が無い

- 判断: 対応する
- 対応内容: G-7 の見本表へ提案された 6 件をすべて追加した —
  正例 `BootProbeRunner::run([])` / 負例 (未使用の `use`) / 負例 (コメント内) / 負例 (文字列内) /
  負例 `OtherBootProbeRunner::run(` / 負例 `BootProbeRunner::runner(`。

## [Warning] S6: 語彙の完全一致に対する接頭辞・接尾辞の負例が不足

- 判断: 対応する
- 対応内容: 軸 A へ `MY_PHP_BINARY` / `NOT_PHP_BINARY` / `PHP_BINARY_PATH` の負例を、
  G-6 へクラス名・メソッド名の接頭辞/接尾辞形の負例を、いずれも**恒久テスト**として追加した。

## [Warning] 横断: 実行時間の「既存テストだけの中央値」の測り方が未定義

- 判断: 対応する
- 対応内容: 比較コマンドを確定した — 実装前に `composer test -- --list-tests` 相当で
  **対象ファイル一覧を保存**し、実装後は **`--exclude-filter` で新規 2 テストファイル
  (`BootProbeRunnerTest` / `PhpBootProbeReferenceInventoryTest`) を除外して同じ集合**を走らせる。
  5% 超過時は**閾値を動かさず原因を報告する**方針を明記した (現方針の維持)。

## [Suggestion] 横断: ignored 生成物の比較を一覧 + ハッシュにする

- 判断: 対応する
- 対応内容: 「見比べる」を、`storage/logs/` / `storage/framework/views/` / `bootstrap/cache/` の
  **相対パス一覧と各ファイルの sha256 を走行前後で比較する**へ具体化した。

## [APPROVE] S1 / S3 / S5

- 判断: 対応不要 (Round 1 の対応が受け入れられた)
