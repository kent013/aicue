# 対応マトリクス: impl-review Round 1

## [Warning] `EXTERNAL_SEAM_RULE_KINDS` の値集合が pin されていない (実質 Critical / 全体判定の根拠)

- 判断: **対応する**
- 根拠: 指摘は正しい。当初のテスト 4 は「キー集合の exact-fit + 各値が非空」までしか見ておらず、
  `http_facade_reference` へ `ExternalSeamKind::Mail` を**足す**改変では赤くならなかった。
  そのうえで `FxRateService` に `kind: Mail` の entry を足せばテスト 1(b) の残骸検出もすり抜ける。
  これは gate の主目的である「種別を登録者の言い値にしない」に直接開いた穴であり、
  「規則を減らす方向 (M7) は 別テストが受け止めるが、増やす方向は誰も受け止めていなかった」という
  非対称だった。
- 対応内容:
  - テスト名を `外部到達: 規則→種別表は規則 enum を exact-fit で覆い、値集合も pin される` へ変更し、
    Codex 提案どおり **enum value 化した期待表**を別宣言として置き、`EXTERNAL_SEAM_RULE_KINDS` と
    完全一致させる assert を追加した (規則の意味を広げるには 2 箇所を触らせる意図的な摩擦)。
  - mutation **M19**「`http_facade_reference` へ `Mail` を追加」を新設し、
    `EXTERNAL_SEAM_MUTATION_COVERAGE` / `EXTERNAL_SEAM_MUTATION_IDS` へ登録 (テスト 15 が同期を強制)。
  - M19 を実行し **テスト 4 のみが赤**になることを実測。副作用として M7 もテスト 4 で赤くなるため、
    mutation-evidence.md の M7 行と注 3 を実測どおり書き換えた
    (設計の予測「テスト 4 は赤にならない」は修正前の話であると明記した)。

## [Suggestion] group use 修正の回帰を抽出元 API (`ExternalClientBoundaryScanner`) 側にも置くべき

- 判断: **対応する**
- 根拠: 指摘のとおり、現状の group use 回帰は `ExternalSeamScannerTest` にしかなく、
  T126 が使う API (`ExternalClientBoundaryScanner::scan()`) 側の回帰としては間接的だった。
  抽出前の実装は docblock で「グループ use にも対応する」と書きながら
  `use Aws\{S3\S3Client, ...}` を `AwsS3\S3Client` と解決していた (= 検出漏れ) ため、
  この API 上で固定しておく価値がある。
- 対応内容: `tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php` の**末尾へ 1 本追加**
  (`グループ use を接頭辞ごと解決する`)。
  **既存 268 行は 1 行も変更していない** (`git diff` は 19 insertions / 0 deletions の純粋な追記。
  差分中の `-` 1 件は diff ヘッダ `--- a/...` である)。
  「既存テストを編集して通す」という禁じ手には当たらない (追加した test は新しい振る舞いの回帰であり、
  既存 assert を 1 つも緩めていない)。

## [Suggestion] Stripe 例外だけを import するファイルの無関係な `->stripe()` が adopted になる

- 判断: **対応する (テストで方針を明示)**
- 根拠: 指摘の事実認識は正しい。ただし挙動を変えるべきではない —
  抑制は**偽陰性の口**であり、迷ったら「採用して目録登録を要求する」側へ倒すのが正しい
  (抑制側へ倒すと gate が静かに効かなくなる)。現状 `suppressed` は 0 件であり、
  抑制が働いた瞬間にテスト 6 が赤くなる設計と整合している。
- 対応内容: `ExternalSeamScannerTest` へ
  `走査器: Stripe 例外だけを import するファイルの ->stripe() は fail-closed で採用する` を追加し、
  「これは意図した fail-closed であり、偽陽性が出たら**規則側で分離する** (entry 登録で黙らせない)」
  をコメントで明記した。挙動は変更していない。

## 反論・見送りはなし

Round 1 の指摘 3 件はすべて受け入れた。
