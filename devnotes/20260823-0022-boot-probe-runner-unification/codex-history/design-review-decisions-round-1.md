# 対応マトリクス: design-review Round 1

## [Critical] S3: 未定義定数 `BOOT_PROBE_MARKER_RELATIVE` を参照している

- 判断: **対応する**
- 根拠: 設計内の不一致で、そのまま実装すると `Error` になり P-13 まで到達しない。
- 対応内容: `FakeWiringProbeRunner::MARKER_RELATIVE_PATH` を直接参照する形へ直し、
  `use Tests\Support\ExternalFakes\FakeWiringProbeRunner;` を変更一覧へ明記した。

## [Critical] S4: timeout 時に**外側**の環境ディレクトリが消えることの検査が失われる

- 判断: **対応する (指摘は正しい。P-15 では補えない)**
- 根拠: runner の S7 / S12 / S14 が測るのは runner 内部の `temporaryRoot` の回収であって、
  `FakeWiringProbeRunner` が作る `.env.probe` の置き場所の `finally` ではない。
  P-15 は `interpret()` を直接呼ぶだけなので `finally` を通らない。**保証の後退である。**
- 対応内容: 実プロセスに sleep 分岐を足さず、**注入の継ぎ目も足さずに**恒久テストにできる形として、
  外側の足場を**構造として切り出した**:
  `FakeWiringProbeRunner::withEnvironmentDirectory(?string $baseDirectory, callable $body): mixed`
  — 置き場所を 0700 で作り、`finally` で必ず消す。`run()` はこの中で本体を走らせる。
  新設の **P-10c** が「本体が例外を投げても置き場所が残らない」を決定的に測る。
  これは**プロセスの挙動を偽装する注入ではなく、後始末の足場そのものを直接呼ぶ**形なので、
  取り込み元の runner が避けた「注入の継ぎ目」には当たらない。
  timeout 経路が実際にこの `finally` を通ることは **P-15 (`interpret()` が `timedOut` で例外) と
  P-10c (本体の例外で置き場所が消える) の合成**で示す、と設計に明記した。

## [Critical] S5: `StrictTypesRuntimeProbe` は boot probe ではないのに runner へ載せている

- 判断: **対応する (指摘を受け入れ、載せ替えを取りやめる)**
- 根拠: 指摘の 3 点がいずれも正しい。
  1. 正典の boundary は「**起動順序**に由来する壊れ方を実測する」ことであり、
     `declare(strict_types=1)` の実効性は単一ファイルのコンパイル指令であってアプリの起動順序ではない
  2. **`PhpLintOracle` を載せ替えない理由と整合しない** — どちらも「PHP を子で起こすがアプリは起こさない」
     経路であり、片方だけ載せる根拠が無い
  3. 環境の意味が変わる (親環境の継承 → 許可一覧のみ)。23 検体が通ることは安全性の証明にならない
- **さらに決定的な根拠を台帳から確認した**: 正典テンプレート (laravel-claude-template) は
  子プロセス起動 4 経路のうち**アプリを起こす 3 本だけを載せ替え**、残る 1 本
  (`tests/Feature/Queue/QueueWorkerLeaseGuardTest.php`) は「**載せ替えない理由を docblock へ明記**」して
  残している。これが家系の標準的な捌き方である。aicue でアプリを起こす経路は**経路 1 の 1 本だけ**なので、
  経路 2 は同じ形で残すのが正典追従として正しい。
- 対応内容: **S5 を「経路 2 を載せ替える」から「経路 2 を載せ替えない理由を docblock へ明記する」へ差し替えた**
  (変更は docblock のみ)。併せて概念設計の「なぜ経路 2 も載せ替えるのか」の主張を撤回し、
  詳細設計に撤回の理由を残した。これによりスコープは縮み、`PhpLintOracle` との扱いも一貫する。

## [Critical] S6: 正典の boundary が「静的走査そのもの」を含まないのに gate を必須施策にしている

- 判断: **一部反論する (gate は残す)。ただし位置付けを明記し、規模を大幅に縮める**
- 反論の根拠: 正典 boundary が除いている「静的走査 (`static-scanner-substrate`)」は
  **走査の基盤そのものを持つ feature** を指しており、本 feature の追従で gate を置くことを禁じてはいない。
  実際、**正典テンプレートは本 feature の追従で `tests/Architecture/SubprocessProbeLaunchGateTest.php` を
  新設しており、台帳の status_reported にその実体が「新設 gate」として記録されている**。
  加えて AGENTS.md 禁止事項 1 が「不変条件は対応する Architecture/Feature テストへの登録まで含めて実装済み」と
  定めるので、載せ替え一度きりでは規約を満たさない。
- ただし指摘の趣旨 (正典の 6 不変条件ではない) は正しいので、**位置付けを設計に明記した**:
  「S6 は正典 v1 の 6 不変条件のいずれでもない。**aicue 側の上積み**であり、
  正典テンプレートの同型の判断と AGENTS.md 禁止事項 1 を根拠とする」。
- 対応内容: 位置付けの明記に加え、次の Critical への対応で規模を大幅に縮めた。

## [Critical] S6: 名前解決を持たない字句走査では起動呼び出しの判定が破綻する

- 判断: **対応する (指摘は正しい。破綻する判定を設計から削る)**
- 根拠: 指摘のとおり `use … as Worker` / FQN / group use を追えず、逆に末尾が `Process` の無関係な
  クラスを誤検出する。**aicue は php-parser を直接依存に持たない**ので名前解決器が無く、
  正しく判定できない検査を置くのは「緑のまま嘘をつく」形そのものである。
- 対応内容: **起動呼び出しトークンの判定を設計から全面的に削除した**。これに伴い:
  - 軸 B の交差不変条件 (旧 G-5「`child_entry` のファイルは起動呼び出しを持たない」) を**削除**
  - 旧 G-7 を「`BootProbeRunner` を参照している」から
    **「トークン列 `BootProbeRunner` `::` `run` `(` を持つ」**へ強化 (未使用 `use` では通らない)
  - 名前解決を要する迂回 (`use … as` / FQN / group use / 可変関数名) は
    **「保証しないこと」として docblock に名指しで書く**
  - 併せて `use const PHP_BINARY as …` による軸 A の迂回を **fail-closed で捕まえる**規則を足した
    (`use` `const` の並びに `PHP_BINARY` が現れたら、そのファイルは軸 A に数える)
  - 検査は 9 本から **7 本**へ減った

## [Warning] S1: fail-first になっていない (3 ファイル同時配置で最初から緑)

- 判断: 対応する
- 対応内容: 実装順を「**自己検査 1 本を先に置いてクラス未定義で赤を確認 → 実装 2 本を置いて緑**」へ変えた。
  ファイル内容は 1 バイトも変えずに実現できる。

## [Warning] S1: `composer fix` は書き換えるので「整形せず報告」と両立しない

- 判断: 対応する
- 対応内容: **`vendor/bin/pint --test` で非破壊に確認**し、通った場合だけ `composer fix` を実行する形へ変えた
  (AGENTS.md の検証コマンド一覧にも `vendor/bin/pint --test` が入っている)。
  取り込み前後の sha256 再確認も受入条件へ足した。

## [Warning] S1: 取り込む docblock の訂正情報の置き場所

- 判断: 対応する
- 根拠: 訂正表を S6 の docblock に置くと、S6 が将来消えたときに訂正も消える。
- 対応内容: 訂正表 (不変条件番号 15 → 9 / `PhpLintOracle` のパス) を
  **`tests/Support/ExternalFakes/FakeWiringProbeRunner.php` の docblock**へ移した
  (runner を使う限り必ず存在する aicue 所有のファイルである)。

## [Warning] S2: クラス docblock が変更後の実装と矛盾する

- 判断: 対応する
- 対応内容: `env -i` の 3 キーという説明を捨て、**4 段の環境合成 / 鍵の配置 (`APP_KEY` はケース別・
  `CIPHERSWEET_KEY` は環境ファイル) / 一時ディレクトリが 2 つある構図 (外側 = 環境ファイル置き場、
  内側 = runner の書き出し先) / 設定キャッシュの退避先**を書き直す、と変更一覧に明記した。

## [Warning] S2: `caseEnvValues()` の許可検査では `CASE_ENV_KEYS` の増加を検出できない

- 判断: 対応する
- 対応内容: P-7 で **`CASE_ENV_KEYS` そのものを 3 キーのリテラルと完全一致**で pin する検査を足した。

## [Warning] S3: 「責務は 4 つだけ」というコメントが事実でなくなる

- 判断: 対応する
- 対応内容: probe の責務一覧を 6 つ (DB へ接続しない / container から解決する / 転送先を組み立てて読む /
  **実働証明の印を 1 本書く** / **書き出し先と鍵の digest を報告する** / 終了コードを返す) へ改め、
  「観測しないもの」も更新すると明記した。

## [Warning] S4: P-7 が予約 7 キーを実装側の定数から流用しており「1 本足したら赤」が成立しない

- 判断: 対応する
- 対応内容: `BootProbeRunner::RESERVED_ENV_KEYS` を**7 キーのリテラルと完全一致**で独立に pin する検査を
  P-7 へ足した (提案されたコードのとおり)。

## [Warning] S4: `ExternalFakeBootProbeTest` の先頭 docblock が旧説明のまま

- 判断: 対応する
- 対応内容: 先頭 docblock を P-7 / P-8 の新契約に合わせて書き直す、と変更一覧に明記した。

## [Warning] S5: `@throws` に timeout を含める

- 判断: **対応不要になった** (S5 の載せ替えを取りやめたため、`strictTypesInEffect()` の例外契約は現行のまま)

## [Warning] S6: `BootProbeRunnerTest.php` の申告理由が不正確

- 判断: 対応する
- 対応内容: 「それ自身は子を起こさない」→
  **「直接の起動 API を持たず、`BootProbeRunner` 経由でのみ子を起こす」**へ直した。

## [Warning] 横断: AGENTS.md の検証コマンドが受入条件に不足

- 判断: 対応する
- 根拠: AGENTS.md L336-338 が検証コマンドを列挙している。PHP のみの変更でも全件緑が規約である。
- 対応内容: 受入条件へ `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
  `pnpm build:packages` / `pnpm test:packages` の**全件**を書いた。

## [Warning] 横断: 「実行時間の後退が無いこと」は達成条件として成立しない

- 判断: 対応する
- 根拠: 正しい。取り込む自己検査は S7 / S12 / S14 だけで固定の待ち時間 (制限時間 1 秒 + 猶予 2 秒ほか) を持つので、
  `composer test` の総実時間は**原理的に増える**。
- 対応内容: 条件を「後退が無いこと」から「**増分が説明できること**」へ変えた。
  比較対象・試行回数・集計方法・許容増分を固定し、
  **既存テストの中央値**と**新規自己検査の追加コスト**を分けて報告する形にした
  (S5 の載せ替えを取りやめたので、既存テストの実行時間へ効く変更はほぼ無い)。

## [Warning] 横断: 未追跡集合の完全一致は新規ファイルと両立しない / ignored を見ない

- 判断: 対応する
- 対応内容: 基準時点を「**意図した新規ファイルを `git add` で index へ登録した直後**」に変え、
  検証は (a) 走行前後の `git status --porcelain` の一致、(b) ignored な既知の書き出し場所
  (`storage/logs` / `storage/framework/views` / `bootstrap/cache`) を**別に**確認する、の 2 本立てにした。
  併せて **`TrackedPhpSourceFiles` は git 追跡下しか見ないので、新規 4 ファイルは `git add` するまで
  gate の走査に入らない**という実装上の注意を明記した (これを落とすと G-1 が空振りしたまま緑になる)。
