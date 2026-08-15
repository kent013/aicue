# 対応マトリクス: conceptual-review Round 1

## [Critical] §2/§7 scan 出力の型契約 (DTO) が無い

- 判断: **対応する (範囲を絞って)**
- 根拠: 標準出力の JSON は後段 Python の入力契約になるので、配列の場を PHPStan level 10 で固定する価値がある。
  ただし DTO を 4 つに割ると、実体が「1 回の走査結果」しかないものを型で細分するだけになる (思考原則 2)。
- 対応内容: `app/DataTransferObjects/Bughunt/InventoryScanData.php` と `InventoryRouteData.php` の **2 つ**を置く。
  抽出条件 (環境) と画面題名は scan DTO のスカラー / 連想配列として持ち、専用 DTO は作らない。
  Command は DTO を組み立てて `toArray()` を JSON 化するだけにする。

## [Critical] §5 注釈の定義域を 1 route = 1 注釈にすると画面/操作の非対称を表せない

- 判断: **一部対応 + 実測に基づく反論**
- 根拠: 実測 (`php artisan route:list --json`、APP_ENV=local) で **GET と非 GET を併せ持つ web 面 route は 0 件**
  (GET|HEAD 72 / POST 57 / DELETE 17 / PATCH 10 / PUT 3)。表の所属は method 集合から排他的に決まるので、
  1 route = 1 注釈で `debug.login` (画面) と `debug.login-as` (操作) の非対称は表現できる。
  scope を 2 つ持たせると、常に片方が「その route には無い側」になり空欄の意味を運用で覚えることになる。
- 対応内容: 所属規則を「**非 GET メソッドを 1 つでも持てば操作表、GET/HEAD のみなら画面表**」と明文化して pin する
  (現行シェルは先頭 method で判定しており、GET|POST の route は画面表にだけ載る = 書き込み操作が分母から落ちる)。
  併せ持つ route が現れたときに画面側の分母へは載らないことを「保証しないもの」に明記する。

## [Warning] §2 production 禁止の実装境界が曖昧

- 判断: 対応する
- 根拠: 母集合が環境依存なのは debug 4 route の登録条件 (`app()->isLocal() || app()->runningUnitTests()`) だけである
  (routes/ と Providers を全数確認)。環境名そのものではなく**この述語**が母集合を決める。
- 対応内容: Command は同じ述語が成り立たない環境では非 0 終了する。生成物のヘッダには環境名ではなく
  **抽出条件のラベル**を書く (local 実行と Pest 実行で同じ母集合になるので、ラベルも同一になり偽ドリフトが出ない)。
  Python 側は scan JSON の抽出条件フィールドを再検証し、不一致なら exit 2。

## [Warning] §3 抽出ソースを route:list の出力 parse にしない

- 判断: 対応する
- 対応内容: Command 内で `Illuminate\Routing\Router` の `getRoutes()` と `gatherRouteMiddleware()` から
  method / uri / name / action / middleware を構造データとして取る。人間向け出力は parse しない。

## [Warning] §3 画面題名の対応規則が未定義

- 判断: 対応する
- 対応内容: 題名は `config('seo.app_titles')` の route 名キーを引き、無ければ空欄にする。
  **題名の欠落は drift にしない** (SEO 設定は目録の従属物ではない)。この非対称を設計に明記する。

## [Warning] §4 「行の書き忘れは構造的に起こらない」は強すぎる

- 判断: 対応する
- 対応内容: 「実装から抽出できた route については、生成物への行の追加漏れが byte 比較で検出できる」に弱める。
  抽出対象から外れる面 (api / 管理画面 / MCP) には沈黙することを併記する。

## [Warning] §4 対象外理由の品質を見ていない

- 判断: 対応する
- 根拠: 本リポジトリの他の目録 (throttle / job 重複 / 外部到達点) はすべて **30 文字以上の根拠**を要求している。
- 対応内容: 区分が `外` / `終` の注釈は 30 文字以上の理由を必須にし、満たさなければ drift (exit 3)。

## [Warning] §5 inventory.json をコミットする意義が弱い

- 判断: 対応する (**生成物から外す**)
- 根拠: 読み手がいない。`correlate.py` が読むのは `operations.md` の name 列であり、JSON は誰も読まない。
- 対応内容: 生成物は `screens.md` / `operations.md` の 2 つだけにする。機械事実は生成・検査の実行中にだけ存在する。

## [Warning] §5 generate と check で段 3 の扱いが違うはず

- 判断: 対応する
- 対応内容: `generate` は段 1 / 2 / 4 を通してから書く。`check` は段 1 / 2 / 3 / 4 を通し 1 バイトも書かない。

## [Warning] §6 段 4 (機能カタログの参照整合) の責務が広がる

- 判断: 一部対応 (同一 PR に残す)
- 根拠: 3 列を家系標準へ寄せない逸脱を登録する以上、「揃えている不変条件」を機械で持つ必要がある。
  段 4 がその唯一の機械的保証であり、別 PR に切ると逸脱だけが先に入る。
- 対応内容: 独立関数 + 独立テストとして書き、`check` から呼ぶ。**カタログの網羅性は見ない**ことを明記する。

## [Warning] §7 Python 側の契約検証

- 判断: 対応する
- 対応内容: 自己テストで固定するケースを 7 つ列挙する (欠落キー / 未知語彙 / 未注釈 / 残置注釈 /
  空母集合 / 抽出条件不一致 / 生成物 byte 不一致、および想定外例外の exit 2)。

## [Warning] §1 使命への貢献の書き方が広い

- 判断: 対応する
- 対応内容: 「探索的バグハントの分母の信頼性向上を通じた**間接的・補助的**貢献」と限定して書く。
