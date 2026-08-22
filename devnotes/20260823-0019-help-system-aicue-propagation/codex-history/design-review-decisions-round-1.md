# 対応マトリクス: design-review Round 1

Codex (gpt-5.6-sol / high) 全体判定: CHANGES_REQUESTED
内訳: Critical 2 / Warning 6 / Suggestion 2
施策判定: S1 APPROVE / S2・S3・S4・S5・S6 REQUEST_CHANGES

## [Critical] S2・S5 — 生成先が symlink だと `docs/help/` の外へ書き込める

- 判断: **対応する**
- 根拠: 正当な指摘であり、設計の穴である。`absolutePathFor()` は**字句検査しかしていない**のに
  `HelpBuildService::build()` がその戻り値へ直接 `file_put_contents()` していた。
  `_generated` が外部ディレクトリへの symlink なら `is_dir()` を通過して外部へ書ける。
  これは自分で書いた docblock (「パスを組み立てるたびに字句の検査と実体の検査をやり直す」= I12) に
  反しており、**書き込み経路にだけ実体検査が無い**という非対称は言い訳できない。
- 対応内容:
  - `absolutePathFor()` を **public API から削除**した (未検査の絶対パスを外へ出す口を消す)。
  - `HelpRepository::writeGenerated(HelpSection, string): void` を新設し、
    **書き込みそのものを Repository に閉じ込めた**。`HelpBuildService` は絶対パスを見ない。
  - `writeGenerated()` の検査順: 字句検査 → root の実体解決 → `_generated` が symlink でないこと →
    未作成なら**非再帰の `mkdir`** で直下に作る (階層を作れない) → 生成物ディレクトリの realpath が
    `realpath(root)/_generated` と**完全一致**すること → 対象が symlink でないこと →
    既存実体が通常ファイルであること → 書き込み → **書き込み後にもう一度**
    symlink でない通常ファイルであることを再検査。
  - 実体解決の共通処理を `resolveRealDirectory()` に切り出した。

## [Critical] S5 — 「例外も終了コード 1 に畳む」を `catch (RuntimeException)` で実装できていない

- 判断: **対応する**
- 根拠: 正当な指摘。`Webmozart\Assert` は `InvalidArgumentException` を投げ、
  container 解決失敗は `BindingResolutionException`、型不整合は `TypeError` (`Error` 系) である。
  いずれも `RuntimeException` ではないので素通りし、**I8 (終了コードは 0 と 1 の 2 値だけ)** が
  成立しない。自分の設計文が主張していることを実装が満たしていない。
- 対応内容: `catch (Throwable $e)` へ変更した。あわせて
  `HelpBuildCommandTest` に「Registry の binding を誤った型へ差し替えて
  `Assert::isInstanceOf()` の `InvalidArgumentException` を起こしても終了コード 1」の負例を追加した
  (`HelpManifestException` だけの負例ではこの欠陥を検出できない、という指摘に同意)。

## [Warning] S2 — `generatedArtifactPaths()` が symlink / FIFO を通常の生成物候補として扱う

- 判断: **対応する**
- 根拠: 正当。`.md` で終わる symlink は `is_dir()` を通らないので Orphan として静かに返る。
  「通常ファイルでない実体は例外」と自分で書いた規約に反する。
- 対応内容: `_generated` 自体の `is_link()` を拒否し、各 entry について
  `is_link() || ! is_file()` を例外にし、realpath が root 境界の内側にあることも確認する。
  負例 (symlink / FIFO / ディレクトリ) を `HelpRepositoryTest` に追加した。

## [Warning] S2 — `schema_version` が宣言されているのに検証されない (fail-open)

- 判断: **対応する**
- 根拠: 正当。宣言だけして読まない値は、将来の schema 変更を旧コードが誤読する fail-open になる。
  「宣言はするが検査しない」は本リポジトリの他の台帳 (`LedgerPins` 等) の作法とも合わない。
- 対応内容: top-level が list でない配列であること、`schema_version` が
  **整数 1 と厳密一致** (`===`。文字列 `"1"` を弾く) することを必須にした。
  欠落・型違い・未知バージョンの負例を追加した。

## [Warning] S3 — 同じ `generator` を複数 section が参照しても「完全一致」になる

- 判断: **対応する**
- 根拠: 正当。`$declared[$key] = true` で重複が畳まれるため、集合としては一致してしまう。
  `HelpGenerator::generate()` は section 引数を持たないので
  「1 generator が複数 artifact を出す」意味自体が定義されていない (同じ内容が 2 か所に書かれる)。
  **I10 の「完全一致」を集合一致へ弱めていた**という指摘は正しい。
- 対応内容: `HelpRepository::sections()` で**非 null の `generatorKey` の重複を例外**にした
  (manifest の読み取り段で閉じるほうが、台帳側の比較より早く落ちる)。
  `HelpGeneratorRegistryTest` に「同じ generator key を 2 section が参照したら赤」の負例を追加した。

## [Warning] S4 — 走査したファイルと Reflection が解決したクラスの実体が同じか確認していない

- 判断: **対応する**
- 根拠: 正当。`class_exists()` は Composer autoload から**別のファイル**をロードしうる。
  一時 root に置いた fixture を走査しているつもりで、既にロード済みの本物のクラスを見てしまうと、
  走査器の自己検査 (負例) が空振りする。**検出力を主張する側の責任**として塞ぐべきである。
- 対応内容: `ReflectionClass::getFileName()` を取り、`realpath()` が走査中のファイルの
  `realpath()` と**完全一致**することを要求する。一致しなければ例外 (直し方つき)。
  「同名クラスが別ファイルから既にロードされている」負例を `McpToolScannerTest` に追加した。

## [Warning] S4 — vendor メタデータの形が変わっても静かに正規化される経路がある

- 判断: **対応する**
- 根拠: 正当。`required` が associative でも通る / union `type` が associative でも値だけ連結する /
  `required` に空文字や `properties` に無い名前があっても無視する /
  `required` があるのに `properties` が欠けるとパラメータ 0 件になる — いずれも
  **I14「形が変われば生成は止まる。静かに欠けない」に反する**。
- 対応内容: `required` と配列型 `type` に `array_is_list()` を要求し、
  `required` の各要素を非空文字列に限定し、重複を拒否し、
  **`required` の全要素が `properties` に存在すること**を検証し、
  **`required` があるのに `properties` が無い形**を例外にした。負例を追加した。

## [Suggestion] S4 — Population test の床値 4 と現行 4 クラスの個別 pin は正典の要件を超えている

- 判断: **対応する** (指摘どおり過大なので削る)
- 根拠: 同意する。`AGENTS.md` の走査規約 3 が要求するのは
  「母集団が空でないこと / 走査根がそれぞれ生きていること」までである。
  床値 4 と個別クラス名の pin は、**将来ツールを正当に 1 本廃止しただけで赤くなる**。
  正典が求めていない拘束を足すのは、本件の大前提 (「正典 v1 に忠実な最小」) に反する。
  母集団の非空は `McpToolScanner` 自身の契約 (0 件で例外) と、
  3 集合の一致相手である `ToolName::cases()` (first-party の enum) が既に支えている。
- 対応内容: 床値 `>= 4` と代表クラス名 4 本の pin を**削除**し、
  「走査根が生きていること」+「母集団が非空であること」だけを残した。

## [Suggestion] S1 — 実装モード欄の新規ファイル数が一致していない

- 判断: **対応する**
- 根拠: 単純な数え間違い。実数は app/Services/Help 13 + Console 1 + docs 3 + tests 7 = **24 本**。
- 対応内容: 「新規 24 本 (Service 13 / Console 1 / docs 3 / tests 7)、変更 1 本」へ訂正した。

## [Warning] S6 — 上記の欠陥を検出する負例が不足している

- 判断: **対応する**
- 根拠: Codex が挙げた追加テストは、いずれも**新機能ではなく、既に設計が主張している
  I8・I10・I12・I14 と走査器共通規約の検出力を成立させるための負例**である。
  テストなしの実装完了を禁じている以上、主張と検査は同じ PR で揃える。
- 対応内容: S6 の表と各施策のテスト計画へ、指摘された 6 群の負例をすべて追加した
  (schema_version 3 種 / `_generated` symlink / `.md` symlink・FIFO /
  symlink 経由の root 外書き込み拒否と外部ファイル不変 / generator key 重複 /
  Reflection ファイル不一致 / associative required・union type / 空文字・重複・未知の required 名 /
  `RuntimeException` 以外の `Throwable` でも終了コード 1 / build が root 外を書き換えないこと)。

## スコープが広がっていないことの確認

追加したのは **既存 API の検査強化・書き込み経路の内包・負例の追加**だけで、
新しい機能・新しい生成器・新しい面は 1 つも足していない。
むしろ Population test の過剰 pin を削って**縮めている**。
