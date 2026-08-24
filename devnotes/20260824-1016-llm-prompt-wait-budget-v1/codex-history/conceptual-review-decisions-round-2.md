# 対応マトリクス: conceptual-review Round 2

## [Warning] 「API は 1 つだけ公開」と 3 メソッド列挙が矛盾。`evaluate()` の公開は第 4 の経路を作る (観点 3)
- 判断: 対応する (指摘より強い形にする)
- 根拠: 指摘のとおり矛盾していた。さらに `read()` が
  `array{timeout, violations}` を返す形も、`['timeout']` だけ読んで violations を捨てる
  第 4 の経路を作れる。今回直している事故 (3 実装への分裂) の再発経路そのものである。
- 対応内容: 公開面を**用途の違う 2 口**へ削り、どちらも**検査済みの結果しか返さない**形にした。
  - `violations(string $absolutePath, string $label): list<string>` (gate 用)
  - `requirePositive(string $absolutePath, string $label): int` (仕様値との突合用。違反があれば例外)
  `evaluate()` は **private** にした。9 類型の自己テストは**見本ファイル**を読ませるので
  純関数を公開する必要が無い。保証の言い方も
  「公開メソッドが 1 本」ではなく「**待ち予算の妥当性を判定する実装が 1 クラスに 1 つ**」へ狭めた。
  加えて `requirePositive()` が `violations()` と同じ private 判定を通ることを明記し、
  「gate は落とすが突合は通る」食い違いが構造的に起きないことを設計条件にした。

## [Warning] `parseOrFail()` 委譲時の例外契約が未確定 (観点 3)
- 判断: 対応する
- 根拠: 指摘のとおり記述が曖昧だった。実装を読み直して確定させた —
  `PromptYaml::parseOrFail()` は内部で `catch (Throwable)` して違反へ変換し `null` を返すので、
  委譲するだけで違反として返る (例外は漏れない)。一方、`Yaml::parseFile()` は
  **ファイル不在も `ParseException`** にするため (`vendor/symfony/yaml/Yaml.php` の
  `@throws ParseException If the file could not be read`)、委譲だけでは
  「不在」と「構文が壊れている」が同じラベルに畳まれる。
- 対応内容: §失敗の分類 を新設し、3 段の表で確定させた。
  - 段 1: `is_file()` を**自前で 1 行判定**し「ファイルが無い」を独立ラベルにする
    (走査由来のパスでは起きないが、`requirePositive()` は名前から組んだパスを受けるので
    prompt の改名で現実に起きる)。
  - 段 2: parse 不能 / 非 map は共有ヘルパの既存 2 ラベルをそのまま使う。
  - **自前の `catch` は書かない**ことを明記 (無差別な `Throwable` 捕捉は
    テストコード自身のバグを契約違反へ潰すため)。vendor の例外文面は pin しない。

## [Warning] 「解決不能形」1 件では read() の fail-closed 分岐の裏取りが足りない (観点 4)
- 判断: 対応する
- 根拠: 指摘のとおり。ファイル不在 / parse 不能 / root 非 map は別分岐であり、
  1 件で「解決不能形は落ちる」と主張するのは AGENTS.md の
  「検出力の主張は根拠を同じ行に併記する」に反する。
- 対応内容: 自己テストの内訳を 3 群へ分けて明記した。
  - `violations()`: 9 類型 (ラベル集合照合) + 正例 1 本
  - `violations()` の解決不能形: **不在 / parse 不能 / 非 map をそれぞれ別に**赤くする
  - `requirePositive()`: 違反時の例外 + 正常時の正整数返却
    (下の層が集めた違反を上の口が無視する形も塞ぐ)

## [Warning] `evaluate(array<string, mixed> $parsed, ...)` は PHP のネイティブ型表記ではない (観点 7)
- 判断: 対応する
- 根拠: 事実。実装契約として誤解が残る。
- 対応内容: シグネチャは `evaluate(array $parsed, string $label): array` と書き、
  shape は直前の PHPDoc (`@param array<string, mixed>` /
  `@return array{timeout: int|null, violations: list<string>}`) で宣言する、と明記した。

## [Suggestion] 使命 / 禁止事項 / 到達証明の包含 / 役割分担 / スコープ / PROMPT_NAMES / 型の扱い
- 判断: 見送る (現設計を維持)
- 根拠: いずれも現設計を肯定する指摘。
