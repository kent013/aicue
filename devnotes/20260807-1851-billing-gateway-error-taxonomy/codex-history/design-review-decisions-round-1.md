# 対応マトリクス: design-review Round 1

Codex 判定: **CHANGES_REQUESTED**（S1 のみ APPROVE、S2〜S7 は REQUEST_CHANGES）。
[Critical] 5 件 / [Warning] 7 件。**全件対応した**（反論なし）。
指摘の中心は「gate とテストの強度 = 保証したい性質と検査している性質のずれ」であり、正当。

## [Critical] S3: report message の検査が緩い（`fixture:` の否定だけでは通ってしまう）

- 判断: **対応する**
- 根拠: 正しい。`! str_contains(..., 'fixture:')` は「予期しない文字列が増えた」ことを検出できない。
  report message は集計語彙になりうるので、**形式を exact で固定**すべきである。
- 対応内容:
  - report 文言を `sprintf()` の**固定テンプレート**にした。
  - Feature テストの検査を `str_contains` から
    **`$reported->getMessage() === 期待文字列` の完全一致**へ変更した
    （invoice id / failure_class / error_class を組み立てて突き合わせる）。
  - 「構造化ログ側だけに分類を持たせる」案は採らない。T131 の合議で
    「トリアージに必要な情報（どの invoice が / どの種類の失敗か）は report 側にも要る」と
    確定しているため（蒸し返さない）。exact 一致で穴は閉じる。

## [Critical] S4: `LocalFailure` fixture の message 混入で意図せず赤くなる可能性

- 判断: **対応する（指摘より強い形に変える）**
- 根拠: 指摘の懸念自体は実測で外れる（`QueryException::formatMessage()` は
  previous の message を取り込むため、fixture の文字列は**確かに message に載る**）。
  だが、この確認の過程で**より深刻な穴**に気づいた:
  「ログに `'fixture:'` が含まれないこと」という negative assertion は、
  **fixture の message にその文字列が入っている保証が無いと空虚に green になる**。
  現設計はその保証を持っていなかった。
- 対応内容:
  - `GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER`（`'FIXTURE-EXTERNAL-MESSAGE'`）を定義し、
    **全 fixture の message にこのマーカーを必ず含める**。
  - gate に検査を追加: **全 fixture について
    `str_contains($throwable->getMessage(), EXTERNAL_MESSAGE_MARKER)` が true**
    （= negative assertion が空虚にならないことを機械で保証する）。
  - Feature / Unit の negative 検査はこのマーカーを使う（`'fixture:'` という素の文字列をやめる）。

## [Critical] S5: 「ソース上の `context(` 出現回数 == catchSites 数」は脆い

- 判断: **対応する（Codex の最低線 + メソッド単位へ）**
- 根拠: 正しい。ファイル全体の出現回数はコメント・別文脈でも数が合えば green になり、
  逆に helper 化すると false positive になる。**保証したい性質（各 catch で分類を出す）と
  検査している性質（ファイル内の総数）がずれている**。
- 対応内容:
  - `catchSites` を `list<string>` から **`array<string, int>`（メソッド名 => 期待呼び出し回数）**へ変更。
  - 検査を**メソッド単位**にした: `ReflectionMethod::getStartLine()/getEndLine()` で
    メソッド本体のソースを切り出し、(a) `catch (` を含むこと、
    (b) `GatewayFailureClassifier::context(` の出現回数が宣言値と一致すること を検査。
  - さらに **ファイル全体の出現回数 == 宣言値の合計** も併せて検査する
    （宣言外のメソッドに context() が生えたら赤くなる）。
  - **AST（nikic/php-parser）は採らない**。vendor には存在するが**直接依存ではなく
    transitive**（phpstan / nette 経由）であり、composer の解決次第で消えうるものに
    Architecture テストを依存させない（AGENTS.md 思考原則 1・2）。
    Reflection によるメソッド単位の切り出しで、指摘された脆さは解消する。

## [Critical] S5: `getMessage()` cap 0 を「クラス全体」に掛けると gateway 以外も禁止する

- 判断: **対応する（cap 0 を維持し、保証範囲として明記する）**
- 根拠: 指摘のとおり、保証したい対象は「gateway 例外の観測点」なのに検査はクラス全体。
  ただし `AutoRechargeService` に関しては**クラス全体で例外 message を載せない**ことが
  意図そのものである（gateway 以外の例外も外部由来を含みうる。
  catch 周辺だけに限定すると走査が再び脆くなる）。
- 対応内容:
  - gate の docblock と S5 の「保証するもの」に
    **「観測目録クラスでは gateway 観測以外でも例外 message を載せない（クラス全体の設計制約）」**
    と明記した。
  - `rawMessageCap` は entry の宣言値（現在 0）なので、将来正当な必要が出たら
    **cap の変更が必ず差分に現れる**（黙って足せる枠を作らない）。

## [Critical] S7: `AGENTS.md` の番号衝突・重複追記

- 判断: **対応する**
- 対応内容: 実装手順に
  「**既存末尾へ 7 として追加。既存 1〜6 は renumber しない。
  同趣旨の項目が既にあれば追記ではなく更新**」を明記した。

## [Warning] S2: `getHttpStatus()` の戻り値を `is_int()` で narrowing せよ

- 判断: **対応する**
- 対応内容: `$status !== null && $status >= 500` → **`is_int($status) && $status >= 500`** に変更。
  vendor の PHPDoc（戻り型宣言なし）の揺れに強くなる。

## [Warning] S2: `SignatureVerificationException` を表に入れる根拠が薄く見える

- 判断: **対応する（コメントを追記）**
- 対応内容: `directMap()` の docblock に
  「**vendor 全件分類 gate のため、gateway 経路で通常発生しない Stripe 例外も
  観測語彙上は分類する**（分類は『もし来たら何と呼ぶか』の宣言であり、
  『来る』という主張ではない）」と明記した。

## [Warning] S3: 成功時も 2 キーを null で出す変更が集計条件を変える

- 判断: **対応する**
- 対応内容: S7 の docs 更新内容に「**成功時は両キー null**」を明記。
  Feature テストに **cleanup event の成功・失敗両方でキー集合を固定**する検査を追加した。

## [Warning] S4: 旧プロパティ名の残存を gate で拾えていない

- 判断: **対応する**
- 対応内容: gate に「`failOnTerminate` / `failOnResolveSubscriptionPaymentMethod` の
  文字列が `tests/` 配下に 0 件」の残存検査を追加した（思考原則 3 の機械化）。

## [Warning] S5: サブ名前空間の扱い

- 判断: **対応する**
- 対応内容: `Stripe\Exception\` のサブディレクトリが `['OAuth']` ちょうどであることに加え、
  **OAuth を母集団から外す理由を宣言済み定数 + 根拠**として置き、
  「OAuth 以外のサブ名前空間に具象例外が 0 件」を明示的に検査する形にした。

## [Warning] S6: `directMap()` を dataset にすると誤分類を検出しない

- 判断: **対応する（重要な指摘）**
- 根拠: 正しい。期待値と実装が同一ソースなら、写像を間違えても常に green になる。
  既存 gate（`JobExecutionDedupInventoryTest` の「目録」と「期待値 map」の二重宣言）と
  同じ作法にすべきだった。
- 対応内容: Unit テストに **独立した期待値表（24 entry を手書きで再宣言）**を置き、
  (a) `classify(実インスタンス) === 期待 case`、
  (b) `array_keys(期待値表) == array_keys(directMap())`（書き忘れ検出）
  の 2 本立てにした。

## [Warning] S6: `json_encode(...)->not->toContain()` は array shape の検査として過剰

- 判断: **対応する**
- 対応内容: `context()` の検査を
  **キー集合の完全一致 + 各値の完全一致**へ寄せた（それ以外の値が入り得ないので
  マーカー非含有は自明になる）。ログ context 全体の検査には
  マーカー非含有（再帰的に文字列化）を残すが、こちらは**マーカーの存在保証**とセットで意味を持つ。

## [Warning] S7: docs の「アプリのどこにも残らない」が強すぎる

- 判断: **対応する**
- 根拠: 正しい。T131 Round 4 で 1 度直したのと同じ種類の誇張が、
  経路を広げたことで再発しかけていた（`report()` の stack trace / vendor 側ログ /
  queue 伝播はスコープ外）。
- 対応内容: 文言を
  「**この cleanup 経路で本サービスが出す構造化ログと report message には残らない**」へ弱めた。
