# 対応マトリクス: design-review Round 2

Codex 判定: **CHANGES_REQUESTED**（S1/S2/S3/S4/S7 は APPROVE、S5/S6 が REQUEST_CHANGES）。
[Critical] 1 / [Warning] 2。**全件そのまま採用**した（反論なし）。

## [Critical] S5 検査 17c は Architecture テスト自身を検出して必ず失敗する

- 判断: **対応する**
- 根拠: 完全に正しい。旧 API 名のリテラル（`'failOnTerminate'` /
  `'failOnResolveSubscriptionPaymentMethod'`）は**検査コード自身に書かれる**ため、
  `tests/` 全体を走査すれば必ず自分自身が hit する。
  既存 gate（`JobExecutionDedupInventoryTest` の固定 event literal 検査）が
  `ExternalCallKind.php` を「正本」として除外しているのと同じ構造を、書き忘れていた。
- 対応内容: 検査 17c を
  「**本 gate ファイル自身（= リテラルの正本）を除く** `tests/` 配下の PHP ファイルで 0 件」
  に修正し、「除外しないと検査コード自身が hit して必ず失敗する」という理由も
  gate の保証範囲コメントへ明記した。

## [Warning] S5 検査 13 の「除外名前空間に具象例外 0 件」は定義上自明

- 判断: **対応する**
- 根拠: 正しい。母集団が直下 `*.php` だけなら `OAuth/` は最初から母集団に入らず、
  この要求は自明で検査の意味が無い。さらに OAuth 配下には**実際に具象例外が存在する**ため、
  要求すると「除外する」という設計意図そのものと矛盾する
  （こちらは Round 1 の Warning への対応を作りすぎていた）。
- 対応内容: 検査 13 を Codex の提示どおり 4 本に分解した。
  - 13a 実サブディレクトリ集合 == 除外宣言のキー集合（SDK 追加で赤くなる = 非自明）
  - 13b 除外理由が 30 文字以上
  - 13c 直下母集団の各クラスが除外名前空間に属さない（集合の非交差）
  - 13d 走査結果が代表クラスを含む（縮み検出）
  「OAuth 配下に具象例外が 0 件」は**要求しない**ことを、理由付きで設計へ明記した。

## [Warning] S6 独立期待値表は 24 entry ではなく 23 entry

- 判断: **対応する**
- 根拠: 正しい。内訳は Stripe 12 + Cashier 8 + 非 vendor 3 = **23**。
  `UnknownApiErrorException` は `conditionalClasses()` 側なので `directMap()` には入らない。
  概念設計の「vendor 21」は条件付き 1 件を含む vendor 母集団の数であり、
  それを詳細設計の期待値表の件数と混同していた。
- 対応内容: コメントを「全 entry（Stripe 12 + Cashier 8 + 非 vendor 3 = 23。
  `UnknownApiErrorException` は conditionalClasses 側）」へ訂正し、併せて
  **件数を固定定数で持たない**（正本はキー集合一致の検査）ことを明記した
  （件数を別に持つと片方だけ直したときに嘘の安心を与える）。
