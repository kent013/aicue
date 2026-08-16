# Round 6: Round 5 指摘への対応 (最終確認)

Warning 2 件を、指摘どおりに対応しました。全文の再送は行わず、変更した箇所だけを示します。

## 対応マトリクス

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


---

## 変更後の該当箇所 (概念設計より抜粋)

  - 緩和 1: **app/ から起動する ffmpeg / ffprobe プロセスすべてに `-max_alloc` を付ける**
    (1 回の heap 確保の上限)。超過は ffmpeg が非 0 終了するので、既存の失敗経路
    (`TakeThumbnailExtractionException` / `RenderCompositionException` → `failJob`) に
    そのまま収束する = **新しい失敗様式を作らない**。
    「静止画を入力に取る経路にだけ付ける」形は**採らない** — 線引きが誤りやすく
    (`planTakeStill()` の 2 段目はサーバ生成 PNG を読むが、その PNG は 1 段目が
    **入力と同じ画素数**で書き出したものである)、新しい ffmpeg 経路が増えたときに付け忘れる。
    分岐を持たない 1 つの不変条件にした方が、実装も検査も簡単で安全である。

    | 項目 | 決定 |
    |---|---|
    | config キー | `manual.ffmpeg_max_alloc_bytes` |
    | 値 | `536_870_912` (512 MiB)。バイト単位の正整数 |
    | 取得 | `config()->integer()` のみ (未型付けの `config()` 値をコマンド配列へ流さない = PHPStan level 10) |
    | `env()` | **持たせない** (運用で変える値ではない) |
    | 引数位置 | **実行バイナリの直後**に統一する (`argv[1] = '-max_alloc'` / `argv[2] = 値の文字列`) |
    | 引数の型 | `config()->integer()` で受けた `int` を **`(string)` で明示変換**してから配列へ入れる (`list<string>` を維持 = PHPStan level 10) |
    | 適用範囲 | `FfmpegTakeThumbnailExtractor` / `FfmpegVideoComposer` の**全コマンド** (抽出 / ループ / 動画 / プレースホルダ / concat / ffprobe) |

    「最初の `-i` より前」ではなく**バイナリ直後**にするのは、`ffprobe` が入力を
    `-i` ではなく**位置引数**で受けるためである (`-i` を基準にすると ffprobe だけ検査が空振りする)。

    ```text
    ffmpeg  -max_alloc 536870912 -y ... -i input ...
    ffprobe -max_alloc 536870912 -v error ... input
    ```

    値の根拠 (誤検知と防御のバランス):
    - 止めたいもの: 20000×20000 の PNG = 4 億画素 ≒ **1.6 GB** の 1 回確保 → 止まる
    - 通したいもの: 48MP のスマホ写真 (8064×6048 ≒ 195 MB) / 4K 動画フレーム (≒ 33 MB) → 通る
    - 正規経路のクライアントは長辺 1920 へ再エンコードして送るので、実値はさらに小さい
  - 緩和 2 (既存): `Process::timeout()` (サムネイル 60 秒 / レンダ encode 600 秒・probe 60 秒)、
    `GenerateTakeThumbnailJob` の `tries=3` + backoff、`RunManualRender` の `tries=1`。
    タイムアウト時に `failJob` へ収束し**後続ジョブが処理可能である**ことをテストで固定する。
  - 緩和 1 を守る検査 (deny-by-default):
    1. 上記 2 クラスの**すべての** `Process` 起動引数について、
       **`argv[1] === '-max_alloc'` かつ `argv[2] === (string) config 値`** であることを Unit テストで固定する
       (ffmpeg の 5 コマンド = 静止画抽出 / 静止画ループ / 動画クリップ / プレースホルダ / concat、
       ffprobe の 2 コマンド = duration / audio streams、サムネイル抽出の 1 コマンド。
       **位置引数形式の ffprobe も母集団に含む**)。
    2. Architecture テストで**母集団を固定**する: `app/` 配下で
       `config('manual.render_ffmpeg_binary')` / `render_ffprobe_binary` を `Process` へ渡しているファイルを走査し、
       **現行 2 ファイルを完全一致で pin** する (増減のどちらでも赤 = 3 本目が生えたら必ずレビューに載る)。
    3. `ConfigHardeningTest` に値を完全一致で pin する。


---

他の節に変更はありません。この 2 点で解消していれば、全体判定を APPROVED としてください。
