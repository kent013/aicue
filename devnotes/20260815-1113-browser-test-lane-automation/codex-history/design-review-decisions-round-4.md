# 対応マトリクス: design-review Round 4

## [Warning] 施策 2: `cp -R` 失敗分岐に対応する動的契約が無い

- 判断: **対応する** (指摘のとおり。`mkdir` 側だけでは不変条件を固定できない)
- 根拠: `mkdir -p` の失敗と `cp -R` の失敗は**別の分岐**なので、片方だけ検査していると
  もう片方が削除・破損しても緑のままになる。守りたい不変条件は
  「退避 (作成と複製の両方) の失敗が合否を上書きしない」である。
- 対応内容: C14 を 2 ケースに分割した。
  - **C14a**: `mkdir -p` を失敗させる (pest スタブが初期化後に同名の通常ファイルを作る)。
    WARNING が出て、最終終了コードが pest の `exit 23` のまま。
  - **C14b**: sandbox の `PATH` に条件付き `cp` スタブを置き、退避の複製だけ非ゼロにする。
    期待は C14a と同じ。権限で失敗させる fixture は root 環境で成立しないため、
    既存 sandbox の作法どおりスタブで作る (指摘の助言を採る)。

## [Warning] 施策 6: `tests/js/support/shell-contract.test.ts` が変更ファイル一覧から漏れている

- 判断: 対応する
- 対応内容: 施策一覧・変更箇所・波及変更の 3 箇所に明記した。変更ファイルは 4 本:
  `tests/js/support/shell-contract.ts` / `tests/js/support/shell-contract.test.ts` /
  `scripts/setup-browser-testing.contract.test.ts` / `scripts/run-browser-test.contract.test.ts`。
  あわせて「`shell-contract.test.ts` は vitest の include (`tests/js/**/*.test.ts`) に入るので
  書いたのに走らない状態にはならない」ことも明記した。
  テスト ID 一覧と実装順序も C14a / C14b へ同期した。

## [Suggestion] 施策 1: 特権に関するリスク記述は「pin された現行版で保証する」に限定すべき

- 判断: 対応する
- 対応内容: 「pin されている 1.61.1 では特権を要しないことを実読・実測で確認した。
  本スクリプト由来のパスワード待ちは起きない。ただし Playwright 内部が別の方法で
  特権を取りに行くようになった場合まで保証はできない」と書き換えた (誇張しない)。
