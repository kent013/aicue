# 対応マトリクス: impl-review Round 1

Codex 全体判定: **CHANGES_REQUESTED** ([Critical] 0 / [Warning] 5 / [Suggestion] 2)

## [Warning] 子の stderr が無加工で例外へ入り、秘密を CI ログへ残せる

- 判断: **対応する**
- 根拠: 妥当な指摘である。`getTraceAsString()` は文字列引数を 15 文字まで含めるため、
  plain API キーの先頭が CI ログへ残りうる。「秘密は成否にかかわらず消す」という保証が
  ファイル経路だけに閉じていて、診断経路が未防御だったのは設計の穴である。
- 対応内容: **2 段で塞ぐ**。
  1. 子 (`idempotency-claim-probe.php`) の総括 catch は **例外クラスと file:line だけ**を書き、
     `getMessage()` / `getTraceAsString()` を出さない (段 6/7 の専用 exit は元から
     キー名・DB 名しか出さないので据え置く)。
  2. 親 (`ConcurrencyProbeRunner`) が子の stderr を例外へ載せる前に
     **既知の秘密 5 種**(plain API キー / raw body / `APP_KEY` / `CIPHERSWEET_KEY` /
     `DB_PASSWORD`) を `[redacted:…]` へ置換する。子が PHP の fatal を吐いた場合も通る経路である。
  3. 群 4 へ **sentinel 検査**を追加し、投げられた例外の全文 (previous 連鎖を含む) に
     秘密が現れないことを固定する。

## [Warning] transaction の rollback 契約がテストされていない

- 判断: **対応する**
- 根拠: 指摘のとおり、既存テストは行を作る前に例外を投げていたので
  `DB::transaction()` を外しても緑のままだった。rollback は残留防止の唯一の仕組みなので、
  「効いていること」を固定しないのは検出力の主張として成立しない。
- 対応内容: callback の中で**実際に検体を作り**、主キーを外側へ控えてから例外を投げる形にし、
  別名接続から **8 表すべてで残留 0** を検査する。既定接続名の復帰と別名接続の
  disconnect + purge も同じテストで見る。

## [Warning] 設計逸脱の核心である cache driver の負例がない

- 判断: **対応する**
- 根拠: 正例が両項目とも `array` なので、`assertAppLocksDisabled()` から driver 側の検査が
  消えても緑のままだった。今回の逸脱 (「store 名と裏打ち driver の両方を見る」) の
  検出力そのものが裏取りされていない。
- 対応内容: 群 3 へ独立した負例 2 本 (store 名だけ違う / driver だけ違う) と正例 1 本を追加する。
- 備考: Codex は `cache_store_driver` への置き換え自体を **承認** した
  (「L3 目録と D 登録を広げる必要はない」)。逸脱の判断は据え置く。

## [Warning] 回収失敗が複合した場合、一部の危険が診断から消える

- 判断: **対応する**
- 根拠: `reap()` が秘密削除失敗で即 throw していたため、同時に停止未確認の子が残っていても
  child ID と workspace が報告されなかった。`workspaceModeUnsafe()` も元の原因を上書きしていた。
  「危険が 2 つあるのに 1 つしか見えない」形は診断として不十分である。
- 対応内容: 回収の失敗を**問題の一覧**として集め、**1 つの例外に全部載せる**
  (`reapFailed()`)。元の失敗は previous に畳んで捨てない。
  例外の型を 3 つに分けていたのをやめ、既存 3 factory は撤去する (後方互換の並走を残さない)。
  群 4-40 は env / input-a / input-b の**全対象**が例外に載ることを検査し、
  「秘密削除失敗 + 停止未確認 + mode 不正」の**複合ケース**を新設する。

## [Warning] 厳格パーサが encoder の生成不能な escape を受理する

- 判断: **対応する**
- 根拠: 「唯一の書式だけを受理し、phpdotenv と同じ規則で復号する」と docblock で宣言しながら、
  `/\\(.)/` は `\q` のような未知 escape も受理してバックスラッシュを落としていた。
  宣言と実装の食い違いである。
- 対応内容: 受理する escape を `\\` / `\"` / `\$` の **3 種だけ**に絞り、
  引用符の内側の**素の `$`** も拒否する (encoder は必ず escape するため、素の `$` は
  canonical でない = phpdotenv の変数展開と食い違う経路)。
  群 2 へ負例 3 形 (未知 escape / 素の `$` / キー重複 / 書式違反) を追加する。

## [Suggestion] `uri` は必須観測なのに一度も照合されない

- 判断: **対応する**
- 根拠: fail-closed schema に「集めるが誰も参照しない項目」を残すのは
  AGENTS.md の走査規約 (d)「集めた走査結果を判定に使わない形を作らない」と同じ悪さである。
- 対応内容: runner の受理条件へ「両子の `uri` が親の `uri` と一致する」を足す。

## [Suggestion] workspace 削除が symlink 先のディレクトリを再帰する

- 判断: **対応する**
- 根拠: 削除処理としては `is_link()` を先に見るのが素直で、コストも無い。
- 対応内容: `removeDirectory()` は symlink を**辿らずに unlink** する。
