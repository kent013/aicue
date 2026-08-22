# 対応マトリクス: impl-review Round 2

## [Critical] `HelpRepository`: 親要素 (祖先) が symlink の経路が残る

- 判断: **対応する**
- 根拠: 指摘は正しい。`is_link($this->root)` は**最終要素しか見ない**ので、
  `holder/docs -> /outside` という**親要素**の symlink があると
  `holder/docs/help` は通常ディレクトリのまま `realpath()` が `/outside/help` を返す。
  以降の「置き場の内側か」の検査も生成物ディレクトリの一致検査も**全部その外側を
  正当な root として通す**。これは Round 1 で脅威モデルから外した「操作中の差し替え」
  ではなく、**操作開始前から成立する静止状態の抜け道**であり、外すべき理由が無い。
- 対応内容: Codex の提案どおり**信頼する anchor を明示する**形にした。
  - **契約**: 置き場は **canonical path** として渡す。`rootReal()` は
    `realpath($root) === $root` を毎回検査する。これは最終要素だけでなく
    **起点から置き場までの経路のどの要素も symlink でない**ことを意味する
    (1 つの検査で祖先も閉じる)。
  - **anchor**: 配線 (`AppServiceProvider::canonicalPathUnder()`) が
    `realpath(base_path())` を起点にし、**配下の相対部分は正規化しない**
    (正規化すると経路の symlink が畳まれて検査が意味を失う)。
    これにより「作業ツリー全体が symlink の先にある」形は**拒まない**
    (Codex が懸念した偽陽性を作らない)。
  - 負例を追加: **親要素が symlink で最終要素は通常ディレクトリ**の形を作り、
    `sections()` / `generatedArtifactPaths()` / `writeGenerated()` / `read()` の
    4 経路すべてが止まり、外部ファイルが 1 バイトも変化しないことを固定した。
    テスト内で `is_link($root) === false` を先に assert して、
    **旧実装の分岐では素通りする形であること**を明示している (負例が空振りしない)。

## [Critical] `writeGenerated()` の docblock が縮小した保証と矛盾する

- 判断: **対応する**
- 根拠: 「作成の途中で入れ替えられた形を残さない」は取り消せることを含意しており、
  事後検出しかできない実装に対して過大である。Round 1 で保証を狭めた以上、
  この 1 行が残っていると文書全体の信頼が落ちる。
- 対応内容: 「**入れ替えを検出する**ためであり、書かれてしまった内容を取り消せる
  という意味ではない」に書き換えた。

## [Warning] `McpToolScanner`: 同じ祖先 symlink 経路が残る

- 判断: **対応する**
- 根拠: `HelpRepository` と同じ形。片方だけ閉じると規約が場当たりになる。
- 対応内容: 走査根も **canonical path 契約**にし (`realpath($root) === $root`)、
  配線を `canonicalPathUnder('app/Mcp/Tools')` に揃えた。
  親要素 symlink の負例を追加した (同じく `is_link()` が false であることを先に assert)。

## [Warning] `tests/Feature/Help/HelpRepositoryTest.php` / `tests/Unit/Architecture/McpToolScannerTest.php`

- 判断: **対応する**
- 対応内容: 上記 2 件で親要素 symlink の負例を追加。既存の最終要素 symlink の負例は
  新しい検査で止まるようになったので、期待する文言を
  `canonical path ではありません` へ更新した (分岐は 1 本に統合されている)。

## [Warning] `docs/help-system.md` の symlink の保証範囲が曖昧

- 判断: **対応する**
- 対応内容: 「置き場が symlink であってはならない」を
  「**信頼する起点から置き場までの経路に symlink があってはならない**」へ書き換え、
  最終要素だけでなく途中の要素も含むこと、走査根にも同じ契約が効くこと、
  作業ツリー全体が symlink の先にある形は拒まないことを明記した。
  「保証しないもの」の TOCTOU の項も、静止状態で守る範囲を経路まで含めて言い直した。

## 解消済みと判定された項目 (Round 2 で OK)

- `McpToolMetadata` の最上位 schema pin / `McpToolReferenceGeneratorTest` の分岐固有検査 /
  `McpToolReferenceGenerator` のパラメータ名拒否 — いずれも Round 2 で OK 判定。追加変更なし。
