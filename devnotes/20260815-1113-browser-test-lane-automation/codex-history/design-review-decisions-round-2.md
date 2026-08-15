# 対応マトリクス: design-review Round 2

## [Critical] 施策 1: `classify_deps` が missing と認める終了コードが広すぎる

- 判断: **対応する** (指摘のとおり。特権経路へ誤って進む穴だった)
- 根拠: Playwright の正常な不足検出は `process.exitCode = 1` である。
  `!= 0` で受けると、途中まで marker を出して 2 / 126 / 137 で異常終了した場合を
  `missing` と誤認し、`--with-deps` (特権経路) へ進めてしまう。
  「終了コードと文言の一致時だけ確定 / 判定不能は拒否側」という設計に反していた。
- 対応内容: `[ "${code}" = "1" ]` に限定した。自己検査に T10b (`code=2` + missing marker) と
  T10c (`code=137` + missing marker) を追加し、どちらも `undeterminable` になることを固定する。
  ケース数の下限も 17 → 19 へ更新した。

## [Critical] 施策 6: 上記の穴が sandbox 契約で検出されない

- 判断: 対応する
- 対応内容: sandbox ケース S4b (missing 文言 + exit 2) と S4c (missing 文言 + exit 137) を追加。
  どちらも「`install` 系 0 回・**sudo 未起動**・exit 1・理由キー `undeterminable-deps`」を期待する。

## [Warning] 施策 2 / 6: `mkdir -p` の失敗も証跡退避の失敗として扱うべき

- 判断: 対応する
- 根拠: `cp` だけ守っても、退避先を作れないときに `set -e` でスクリプトごと落ち、
  Browser テスト本体の終了コードを失う。不変条件は
  「診断補助の失敗は合否を上書きしない」なので、経路を 1 つでも残せば破れる。
- 対応内容: `mkdir -p` も `if ! ... ; then 警告して return 0; fi` にした。
  契約テストに C14 を追加 — `storage/browser-test-artifacts` と同名の**ファイル**を置いて
  `mkdir -p` を失敗させ、全レーン成功のまま **exit 0** で終わり WARNING が出ることを検査する。

## [Warning] 施策 4: W20 が空白差分を見逃す

- 判断: 対応する
- 対応内容: `PLAYWRIGHT_INSTALL_PATTERN = /\bplaywright\s+install\b/` を定義して W20 で使う
  (施策 5 の PHP 側と同じ規則にそろえる)。負のコントロールに
  「空白を増やした `playwright   install`」を追加する。

## [Warning] 施策 4: W19 が artifact 名と保持日数を固定していない

- 判断: 対応する
- 対応内容: `name` が `browser-test-artifacts`、`retention-days` が `7` であることを検査に追加。
  負のコントロール (name / retention-days の欠落) も追加する。

## [Warning] 施策 7: 「docs / .gitignore は施策 5 の gate が担う」という記述が事実と違う

- 判断: **対応する** (記述が誤りだった)
- 根拠: 施策 5 が見るのは shell / JSON の scripts / Dockerfile の**実行経路**だけで、
  `docs/` も `.gitignore` も母集団に入らない。
- 対応内容: 2 つに分けて正しく書いた。
  1. `.gitignore` の `/storage/browser-test-artifacts/` は **機械検査する** (施策 6 の C15)。
     登録漏れは Browser テスト実行後の worktree を恒常的に dirty にし、
     `scripts/teardown-worktree.sh` の dirty チェックを常時失敗させる (実害がある)。
  2. `docs/testing-browser.md` の記述内容は **機械検査しない**と明記した。
     手順書がずれても動かなくなることはなく、導入の実体はスクリプト 1 本に寄っている
     (ずれても実害が出ない構造にすることで担保する)。誇張しない。

## [Suggestion] 施策 5: `json_decode()` 由来の `mixed` を段階的に narrow すること

- 判断: 対応する (実装時の指針として受ける)
- 対応内容: 詳細設計のコーディングルールに既記載の「外部から読んだ文字列は
  `Assert::string()` で narrow」に加え、JSON は `Assert::isArray()` →
  要素ごとに `Assert::string()` の順で段階的に narrow する
  (PHPStan level 10 で `mixed` の反復は通らない)。
