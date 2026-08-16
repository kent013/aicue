# 対応マトリクス: conceptual-review Round 5

## [Warning] 観点2: 「最初の `-i` より前」では ffprobe (位置引数) の配置検査が空振りする
- 判断: **対応する (指摘のとおり)**
- 根拠: `ffprobe` は入力を `-i` ではなく位置引数で受ける
  (`ffprobe -v error -show_entries format=duration -of ... {path}`)。`-i` を基準にすると
  ffprobe のコマンドでは条件が常に真になり、検査が意味を失う。
- 対応内容: 配置契約を **「実行バイナリの直後」** に統一する
  (`argv[1] === '-max_alloc'` / `argv[2] === 値の文字列`)。
  Unit テストは 2 クラスの**全コマンド** (ffmpeg 5 本 + ffprobe 2 本 + サムネイル抽出 1 本) に対して
  この 2 つの添字を固定する。Architecture テストの母集団に **ffprobe の位置引数形式も含める**ことを明記した。

## [Warning] 観点7: config 値 (int) を Process 引数へ渡す際の型変換が未記載
- 判断: **対応する**
- 対応内容: `config()->integer()` で受けた `int` を **`(string)` で明示変換**してから配列へ入れる。
  コマンド配列は `list<string>` を維持する (PHPStan level 10)。設定契約の表へ「引数の型」行として追記した。

## [Suggestion] 観点 1 / 3 / 4 / 5 / 6
- 判断: 反映不要 (肯定的評価)
