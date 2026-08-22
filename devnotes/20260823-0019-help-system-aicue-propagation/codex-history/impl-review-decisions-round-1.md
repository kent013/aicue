# 対応マトリクス: impl-review Round 1

## [Critical] `HelpRepository`: root symlink と 検査・I/O 間の TOCTOU

- 判断: **対応する (2 つに分割)**
- 根拠:
  - **root symlink は素直な穴である**。`docs/help` 自体が置き場の外への symlink だと
    `realpath()` がその外側を canonical root として返すので、`readResolved()` の
    「置き場の内側か」検査も `writeGenerated()` の実体検査も**全部通ってしまう**。
    静止状態で成立する穴であり、既存の負例が 1 つも押さえていない。塞ぐ。
  - **TOCTOU は塞げない**。PHP には `openat(2)` / `O_NOFOLLOW` に相当する API が無く、
    「検査してから開く」以外の書き方が存在しない。ここで「descriptor-relative な
    no-follow 操作へまとめる」ことはできないので、**保証を実装に合わせて狭める**
    (Codex 自身が示した 2 択のうち後者)。誇張した保証を docblock に残すほうが有害である。
- 対応内容:
  1. `rootReal()` を新設し、**置き場の最終要素が symlink なら例外**にした。
     読み取り (`read` / `readManifest` / `generatedArtifactPaths`) と書き込み
     (`writeGenerated`) の**すべて**がこの 1 か所を通る。
  2. 書き込み後の再検査に**封じ込めの検査**を足した — 書いた実体の `realpath()` が
     生成物ディレクトリ直下と一致しなければ例外 (取り消せないが「起きたことに気付く」)。
  3. `HelpRepository` の docblock と `docs/help-system.md` の「保証しないもの」に
     **TOCTOU を防がないこと**を明記した (何を守り何を守らないかを 1 行で言う)。
  4. 負例を追加: 置き場そのものが外部への symlink のとき `sections()` /
     `generatedArtifactPaths()` / `writeGenerated()` が止まり、外部ファイルが変化しないこと。

## [Critical] `McpToolMetadata`: 最上位の schema drift がパラメータ 0 件へ fail-open

- 判断: **対応する**
- 根拠: 正典 I14 は「vendor のメタデータの形が変われば**生成は止まる**(静かに欠けない)」である。
  `properties` が別のキー名へ変わった場合に「パラメータ 0 件」として緑で通るのは、
  I14 が名指しで防ごうとしている失敗の形そのものである。指摘は正しい。
- 対応内容: 最上位の形を pin した。`type` が `'object'` であることを要求し、
  最上位のキーは `type` / `properties` / `required` の 3 つに限る (未知のキーは例外)。
  vendor 更新で無害なキーが増えても止まるが、**止まるのが正しい側**である
  (例外に直し方を書いてある)。負例 3 種を追加した。

## [Warning] 想定外形状の dataset が分岐固有の文言を裏取りしていない

- 判断: **対応する**
- 根拠: 詳細設計のテスト計画が「メッセージへの要求は負例の種類ごとに分ける
  (一律の曖昧な assert を置かない)」と明記している。現状は設計違反であり、
  分岐が入れ替わっても緑になる (検出力の主張が崩れる)。
- 対応内容: dataset に**分岐固有の文言**の列を足し、各ケースでその文言を要求するようにした。

## [Warning] `McpToolScanner`: 走査根の symlink を受理する

- 判断: **対応する**
- 根拠: `HelpRepository` の root と同じ形の穴である。走査根は first-party の固定パスなので
  symlink を許す理由が無い。片方だけ塞ぐと規約が場当たりになる。
- 対応内容: 走査根の最終要素が symlink なら例外にし、負例を追加した。

## [Warning] `McpToolReferenceGenerator`: パラメータ名が無害化されていない

- 判断: **対応する (ただし無害化ではなく拒否)**
- 根拠: 指摘の事実 (名前に `|` / backtick / 改行が入ると表が壊れる) は正しい。
  ただし**説明文と名前では扱いを変えるべきである**。説明文は人が書いた散文なので
  表示用に無害化してよいが、名前は first-party の schema のキー = 識別子であり、
  静かに別名へ書き換えると**生成物の名前と実装の名前がずれる** (この機構の目的そのものを壊す)。
  backtick は code span の中では逆斜線で逃がせないので、そもそも無害化できない。
- 対応内容: 表を壊す文字を含む名前は**例外で止める** (直し方を例外に書く)。負例 2 種を追加した。

## [Warning] `docs/help-system.md` の保証が実装より強い

- 判断: **対応する**
- 根拠: 上の 2 つの Critical への対応で実装側の保証範囲が確定したので、文書を実装に合わせる。
- 対応内容: 「保証しないもの」に TOCTOU を明記し、置き場・走査根の symlink 拒否と
  最上位 schema の pin を規約として書いた。

## [Warning] 負例の不足 (root symlink / 走査根 symlink / 最上位 drift)

- 判断: **対応する**
- 対応内容: 上の各項目で追加済み (合計 8 本の負例を追加)。
