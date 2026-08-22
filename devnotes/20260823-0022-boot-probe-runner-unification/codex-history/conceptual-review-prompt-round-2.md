# Round 2: Round 1 の指摘への対応

以下が Claude 側の対応マトリクスです。続いて改訂後の概念設計の全文を再掲します。
Round 1 の [Critical] 1 件・[Warning] 6 件・[Suggestion] 1 件をすべて捌きました
(反論 0 件。Critical は「静的走査では第 1 引数を解釈できない」という限界を認めたうえで、
機械で決定可能な軸を 2 本に増やす形で対応しています)。

再レビューをお願いします。全体判定 (APPROVED / CHANGES_REQUESTED) を必ず明記してください。

---

# 対応マトリクス: conceptual-review Round 1

## [Critical] 新設 inventory gate の分類が自己申告に留まり、機械で守れない

- 判断: **対応する (ただし一部は反論を添える)**
- 根拠: 指摘は正しい。ただし「`proc_open` の第 1 引数を静的に解釈して『実際に何を起こすか』を判定する」ことは
  変数・定数・連結を追えないので原理的に嘘になる (正典テンプレートの同型 gate も G-5 の冒頭でこの限界を
  自ら明記している)。したがって「分類を機械で裏取りする」のではなく、**機械で決定可能な軸を 2 本に増やして
  分類の逃げ道を塞ぐ**方向で対応する。
- 対応内容: 概念設計「C. 一元化を守る gate」を全面的に書き直し、以下を追加した。
  1. **軸 A (起動能力)**: `tests/` 配下で `PHP_BINARY` を参照するファイルの全数申告 (deny-by-default)。
     未登録の参照は必ず赤 = 3 本目の経路がレビューに必ず現れる
  2. **軸 B (アプリの起動点)**: `bootstrap/app.php` を子へ読み込ませる記述を持つファイルの全数申告。
     **「アプリを起こす」という分類そのものが機械で決定できる軸**である
  3. **交差の不変条件 (負例で固定)**: 軸 B の申告ファイルは**起動呼び出しトークン
     (`proc_open` / `new Process` / `Process::`) を 1 つも持たない**。したがって軸 B のファイルは
     「起こされる側」であり、それを起こせるのは軸 A の申告ファイルだけ。軸 A で
     「アプリを起こす」と申告できる entry は `BootProbeRunner.php` ちょうど 1 件に固定する
  4. **走査器の見本検査 (正例・負例)** と、**docblock で保証しないことを明記**する要件を受入条件に入れた

## [Warning] テストファースト (fail-first) の証跡が設計に無い

- 判断: 対応する
- 根拠: AGENTS.md 禁止事項 1 の趣旨そのもの。載せ替えは「主張を変えずに土台だけ替える」変更なので、
  赤を先に見ないと「gate が空振りしていても緑」を作れてしまう。
- 対応内容: 「実装順 (fail-first)」の節を新設し、各段で何が赤になるかを書いた。

## [Warning] バイト一致取り込みの互換性は手動の環境合成だけでは保証されない

- 判断: 対応する
- 根拠: 正しい。設計時の実測は「アプリが起動し書き出し先が退避される」ことまでしか示していない。
- 対応内容: 「受入条件」の節を新設し、`composer test` 全体に加えて取り込んだ自己検査 14 本 /
  `ExternalFakeBootProbeTest` / `StrictTypesDeclarationScannerTest` を通常のテストコマンドで
  緑にすることを受入条件に明記した。

## [Warning] 「リポジトリを 1 バイトも汚さない」の実証範囲が P-13 だけでは不十分

- 判断: 対応する (主張の縮小を含む)
- 根拠: 正しい。印が一時ディレクトリに現れることは `storage_path()` の退避しか示さない。
- 対応内容: (a) 主張を「**監視対象の既定書き出し先に 1 件も現れない**」へ縮小し、保証範囲を明記した。
  (b) 呼び出し側 gate に「子の起動前後でリポジトリ内の既定書き出し先の集合とタイムスタンプが
  変化しない」ことの実測を追加した (P-14)。

## [Warning] `FakeWiringProbeRunner` に残す責務と runner の 3 段合成の境界が曖昧

- 判断: 対応する
- 根拠: 正しい。`env -i` を外す以上、何が維持され何が意図的に変わるかを表で固定しないと安全性が
  暗黙になる。
- 対応内容: 「環境の 4 段の確定表」を新設した。**`TESTING_FAKE_*` はプロセス環境へ 1 件も載せず、
  0600 の環境ファイルの中だけに置く**という現行の設計を維持することを明記し (P-7 の危険接頭辞の
  禁止に `TESTING_FAKE_` を残す)、使い捨て鍵と環境ファイルの位置が子で効いていることの実測を
  受入条件に入れた。

## [Warning] 走査器の受入条件 (走査根・母集団非空・負例・限界の明記) が不足

- 判断: 対応する
- 根拠: 正しい。aicue の既存 gate (`FfmpegProcessLaunchInventoryTest` 等) も同じ形を持つ。
- 対応内容: Critical への対応と同じ節に、指摘された 5 項目をすべて受入条件として書いた。

## [Warning] `BootProbeResult` を唯一の結果境界にし、124 を通常失敗と区別する契約を明記せよ

- 判断: 対応する
- 根拠: 正しい。載せ替えで最も壊れやすいのは「制限時間超過が沈黙して false / 非ゼロ終了に化ける」経路である
  (現状は例外として上がるので気づける)。
- 対応内容: 「結果境界の契約」の節を新設した。runner の結果は `BootProbeResult` だけを受け取り、
  `timedOut === true` (= `exitCode` が `TIMEOUT_EXIT_CODE`) は**通常の非ゼロ終了と区別して例外にする**
  ことを両経路の契約として明記した (経路 2 は fail-open 防止、経路 1 は既存 P-10 の主張の維持)。

## [Suggestion] 使命への貢献は「信頼性の基盤整備」という位置付けに留めると正確

- 判断: 対応する
- 対応内容: 「期待効果」の冒頭に位置付けを 1 文で明記した。


---

## 改訂後の概念設計 (全文)

# 概念設計: boot-probe-runner-unification

家系の機能台帳 lctl の feature `subprocess-boot-probe-harness`
(正典 aigenba / `canonical_version: v1`) への aicue 追従。台帳の aicue セルは現在 `pending`。

## 背景・課題

### 正典 v1 が求めるもの (feature_yaml の boundary が正本。6 要素)

| # | 不変条件 |
|---|---------|
| (1) | 子は `PHP_BINARY` で起こす (親と同じ実行体。PHP の版ずれで観測が変わるのを防ぐ) |
| (2) | 子の環境変数は **3 段**で組み立てる — 許可一覧の継承 + ケース共通の基底 + ケース別上書き (ケース別が最後に効く)。開発者ローカルの環境変数を入力集合から外す。`proc_open` に環境変数の配列を渡すと子はその配列だけを受け取るので、ここが唯一の統制点になる |
| (3) | 出力の管を**非ブロッキングで逐次読み**、制限時間を超えたら SIGTERM → 猶予 → SIGKILL の順で落とし、全ての管を閉じてから必ず `proc_close` する (取り残しのプロセスを作らない) |
| (4) | 終了コードは実行中フラグが**初めて false になった時点の非負値**を保存し、以後の `-1` や `proc_close` の戻り値で上書きしない。強制終了で取れなければ専用コード **124** へ正規化する |
| (5) | 子が書き出すファイルの置き場所を環境変数で**リポジトリ外の一時ディレクトリ**へ逃がし、リポジトリを 1 バイトも汚さない。かつ、**その環境変数が実際に効いていること自体を gate が検査する** (効いていなければ既定の場所へ書かれ、観測は緑のまま嘘になる) |
| (6) | runner 自身の**自己検査**を持つ (許可一覧の網羅性 / 上書きの適用順 / 終了コードの保持 / 制限時間の回収) |

正典が**含まないもの**(boundary が明記): 子プロセスで何を観測するかという個別の主張 (差し替えた fake が
効いていることの実測は `external-fakes-wiring-gate`、経路キャッシュ下の middleware 残存は
`route-cache-safe-middleware-attach` が持つ) / 子を 2 本立てて合図で同期させる並行テスト
(`process-concurrency-test-harness`) / 静的走査 (`static-scanner-substrate`) / HTTP サーバーの常駐起動 /
テストレーンの構成。**ハーネスは主張を証明する道具であって主張ではない。**

### aicue の現状 (2026-08-23 実測)

台帳の旧 note「テスト配下に子プロセスでアプリを起動する経路が 0 件」は**失効している**。
現在、テスト配下で `PHP_BINARY` の子を起こす経路は 2 本ある。どちらも本 feature を目的とした
TODO の産物ではない副産物である。

| 経路 | 実体 | 何をするか |
|------|------|-----------|
| 経路 1 | `tests/Support/ExternalFakes/FakeWiringProbeRunner.php` → `tests/Support/ExternalFakes/fake-wiring-probe.php` (呼び出し側 gate は `tests/Architecture/ExternalFakeBootProbeTest.php` の P-1〜P-12) | 子で `bootstrap/app.php` を読み込み**アプリを起動しきってから** container の解決結果を観測する |
| 経路 2 | `tests/Support/StrictTypesRuntimeProbe.php` (呼び出し側は `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php`) | 検体 PHP にプローブを追記して子で読み込み、`declare(strict_types=1)` が**実際に効くか**を実測する |

**正典 6 要素との突合 (実読による)**:

| # | 経路 1 | 経路 2 |
|---|--------|--------|
| (1) | 満たす (`PHP_BINARY`) | 満たす (`PHP_BINARY`) |
| (2) | **満たさない**。`env -i` で空にしてから 3 件だけを載せる形。正典より締まってはいるが 3 段の形ではなく、部品として共有されていない | **満たさない**。環境の統制を一切持たない (Symfony `Process` の既定 = 親の環境をそのまま継承) |
| (3) | **満たさない**。Symfony `Process` へ委ねており、自前の非ブロッキング逐次読みも段階的強制終了も無い | **満たさない** (同上) |
| (4) | **満たさない**。`$process->getExitCode() ?? -1` で、強制終了時の専用値への正規化が無い。制限時間超過は `ProcessTimedOutException` として上がる | **満たさない**。`isSuccessful()` の真偽だけを見る |
| (5) | 満たす。一時ディレクトリ 0700 / 環境ファイル 0600、`APP_CONFIG_CACHE` は一時ディレクトリ配下の存在しない絶対パス | **満たさない**。検体は `sys_get_temp_dir()` へ置くがアプリを起動しないので退避の対象が無い |
| (6) | 満たす (P-6〜P-11 が起こし手自身の契約を固定する) | **持たない** |

**判定**: 正典が求めるのは「共通規約と、それを体現する**再利用可能な runner**」である。aicue は
用途ごとに独立した起こし手が 2 本並んでいるだけで、共通の規約も共用も無い。これが台帳の
`pending` の理由であり、本設計が埋める穴である。

### 追従の足場が家系に既に在る (本設計の中心的な発見)

aicue の親テンプレート **laravel-claude-template は本 feature を v1 で実装済み**である
(台帳セル: `implemented` / `version: v1` / 観測点 `laravel-claude-template@9184d84`)。
lctl の `get_source` で正典の実体を実読し、**テンプレート自身の指紋台帳の記録値と全件一致**することを
設計時に実測した:

| パス | 実測 sha256 | テンプレート指紋台帳の記録値 |
|------|------------|--------------------------|
| `tests/Support/Process/BootProbeRunner.php` | `bd21b337cc7e4327debba02a3ba46cb496f0a66f0980ccf08cb3847a18430162` | 一致 |
| `tests/Support/Process/BootProbeResult.php` | `00b14167ebfa9710abdb36edf8989bb66350320ee191c3993debd06ed27902cb` | 一致 |
| `tests/Unit/Support/Process/BootProbeRunnerTest.php` | `9db128d89629dc5f4cd891a2f22d063451e3e524480141ff05e7ad0aa261d014` | 一致 |

さらに **aicue の指紋台帳 (`docs/template-fingerprints.json` / 281 パス) にこの 3 パスは 1 件も無い**
(= aicue が未受領のテンプレートパスであり、採用時債務にも無い)。したがって
**バイト一致で取り込めば逸脱を 1 件も作らない**。

つまり aicue は「正典の作法を自力で再発明する」必要がない。**家系で既に検査を通った道具を
バイト一致で取り込み、aicue 固有の観測 (何を測るか) だけを載せ替える**のが最小である。

### 取り込み先で正典どおり動くことの事前実測 (設計時に実施)

取り込む自己検査 `BootProbeRunnerTest.php` の S9 / S10 は「アプリを子で起こして書き出し先が
一時ディレクトリを指すこと」「`storage/logs/laravel.log` が一時ディレクトリ配下に実在すること」を
測る。これが aicue で成立するかは取り込みの前提なので、**runner と同じ環境合成を手で再現して
実測した**:

- 子は正常終了 (exit 0)、標準エラーは空
- `storagePath()` / `getCachedConfigPath()` / `getCachedRoutesPath()` / `view.compiled` /
  `logging.channels.single.path` の**全てが一時ディレクトリ配下**を指した
- 一時ディレクトリ配下に実際に書かれたのは `storage/logs/laravel.log` /
  `bootstrap-cache/services.php` / `bootstrap-cache/packages.php` の 3 件で、
  **リポジトリ側には 1 バイトも書かれなかった**
- 子が受け取ったプロセス環境は継承 3 (`PATH` / `HOME` / `TMPDIR`) + 基底 3 + 予約 7 のみ

= **正典 (5) が aicue でそのまま実働する**ことを取り込み前に確認済みである。

## 改善アイデア

**アプリコード (`app/`) は 1 バイトも変更しない**。既存の観測の主張も変えない。そのうえで:

1. **共通 runner をテンプレートからバイト一致で取り込む** (要素 1〜4、および 6 の骨格)
2. **経路 1 を共通 runner へ載せ替える** — `FakeWiringProbeRunner` は「aicue 固有の環境ファイルを
   0600 で組み立てる」役に専念し、**子の起こし方と回収は runner へ委ねる**
3. **経路 2 を共通 runner へ載せ替える** — `StrictTypesRuntimeProbe` は「検体にプローブを追記して
   標識を読む」役に専念する
4. **正典 (5) の実働証明を呼び出し側 gate に持たせる** — 子が Laravel の `storage_path()` 経由で
   書いた印が、**リポジトリではなく一時ディレクトリ配下に現れた**ことを実測する。
   併せて、子の起動前後で**監視対象の既定書き出し先**
   (`storage/logs/` / `storage/framework/views/` / `bootstrap/cache/`) の
   エントリ集合とタイムスタンプが変化しないことを実測する。
   > **保証の範囲を誇張しない**: 主張できるのは「**監視対象の既定書き出し先に 1 件も現れない**」までで
   > あって、「リポジトリを 1 バイトも汚さない」という無制限の主張ではない。子が独自パスへ書けば
   > この観測には映らない (取り込む runner の docblock も同じ限界を明記している)
5. **一元化を退行から守る全数申告 gate を 1 本置く** — `tests/` 配下で `PHP_BINARY` を参照する
   ファイルを deny-by-default で申告させ、「**アプリを起こす経路は共通 runner ちょうど 1 本**」を固定する

### なぜ「経路 2 も載せ替える」のか (正典の合格条件そのもの)

正典が aigenba を `implemented` と認めた根拠は「**無関係な 2 領域が同じ回収規約を共有した**」ことである
(fake 配線 / 経路キャッシュ)。aicue の 2 経路は「外部差し替えの配線観測」と「厳密な型検査の実効性」で、
**同じく無関係な 2 領域**である。両方を 1 本の runner へ載せて初めて aicue は正典の合格条件に届く。
片方だけなら「起こし手が 2 本並ぶ」現状が 1.5 本になるだけで、`pending` の理由は解消しない。

経路 2 はアプリを起動しないが、正典の要素は (1)(3)(4) を含めて**そのまま利益になる** —
現状は制限時間超過が例外として上がるだけで専用値への正規化が無く、`isSuccessful()` の真偽しか見ない。
runner に載せると「制限時間超過 = 実測不能」を**沈黙させずに例外へ変換できる** (fail-open 防止)。

### なぜ「全数申告 gate」を置くのか

AGENTS.md 禁止事項 1 は「不変条件は対応する Architecture/Feature テストへの登録まで含めて実装済み」と
定める。本設計が新たに立てる不変条件は「**アプリを子プロセスで起こす経路は共通 runner 1 本に閉じる**」で
あり、一度の載せ替えだけでは次に書かれる 3 本目を止められない。**テンプレートも同じ判断で
`SubprocessProbeLaunchGateTest` を新設している** (全数申告 4 エントリ)。

ただし aicue は走査基盤がテンプレートと違う (後述) ため、**gate は aicue 固有の名前・aicue 固有の
走査器で新設する**。

## 期待効果

> **位置付け**: 本改善はユーザー機能の直接の改善ではなく、**信頼性の基盤整備**である。
> 使命への貢献は「本番の LLM / オブジェクトストア / 決済へ誤って到達しないことを守る観測が、
> 嘘をつかないようにする」という間接の経路で成立する。効果の主張はこの位置付けに留める。

- **使命への貢献**: aicue の外部差し替え (`TESTING_FAKE_EXTERNALS` / `_STORAGE` / `_LLM`) は、
  現場作業者の撮影・LLM 生成・オブジェクトストア保存という**本番の実費と実データに直結する経路**を
  テスト中だけ偽物へ差し替える機構である。その差し替えが「起動しきったアプリで本当に効いているか」を
  測る観測が、**取り残しの子プロセス・取りこぼした終了コード・リポジトリを汚す書き出し**で
  嘘をつかないことは、`本番の LLM / S3 / Stripe を誤って叩かない`という土台の信頼性そのものである
- **観測の嘘を減らす**: 現状は制限時間超過が例外として上がるだけで、「落ちなかった子」が
  CI に取り残される経路が閉じていない。段階的強制終了と `proc_close` の一体回収でこれが閉じる
- **家系への追従**: 台帳の aicue セルが `pending` → `implemented (v1)` へ進む足場になる。
  併せて「起こし手が 2 本並ぶだけ」という判定理由が実測で解消する
- **再発明の回避** (思考原則「先人の知恵を探せ」): 家系で 3 リポジトリの実装レビューを通った
  道具をバイト一致で受け取る。aicue が自作するのは**証明したい中身だけ**になる

## 実装方針（概要）

### A. テンプレートからバイト一致で取り込む (3 ファイル・すべて新規)

| パス | 正典 v1 の要素 |
|------|---------------|
| `tests/Support/Process/BootProbeRunner.php` | (1)(2)(3)(4)(5) |
| `tests/Support/Process/BootProbeResult.php` | 観測結果の型 (終了コード・所要時間・一時ディレクトリ・書かれた相対パス・pid) |
| `tests/Unit/Support/Process/BootProbeRunnerTest.php` | (6) 自己検査 14 本 |

**1 バイトも変えない** (Pint による整形を含む)。取り込み時に各ファイルの sha256 が
テンプレート指紋台帳の記録値と一致することを実装者が機械で確かめる。

### B. 既存 2 経路を載せ替える (既存 4 ファイルの変更)

| ファイル | 変更の骨子 |
|---------|-----------|
| `tests/Support/ExternalFakes/FakeWiringProbeRunner.php` | `new Process(['env','-i',…])` を `BootProbeRunner::run()` へ置換。環境ファイルの 0700/0600 と使い捨て鍵の生成は**そのまま残す** (正典より締まった aicue 固有の強化)。`APP_CONFIG_CACHE` は runner の予約鍵になるので**渡さない** (渡すと例外) |
| `tests/Support/ExternalFakes/fake-wiring-probe.php` | Dotenv 前のプロセス環境の観測は**そのまま残す**。加えて、起動後に `storage_path()` 経由で**印のファイルを 1 本書く** (正典 (5) の実働証明の観測点) |
| `tests/Architecture/ExternalFakeBootProbeTest.php` | P-7 / P-11 を新しい契約へ書き換え、P-13 (印が一時ディレクトリ配下に現れる = 正典 (5) の実働証明) と P-14 (**子の起動前後でリポジトリ内の既定書き出し先に変化が無い**) を追加。P-1〜P-6・P-8〜P-10・P-12 の**主張は変えない** |
| `tests/Support/StrictTypesRuntimeProbe.php` | `new Process([PHP_BINARY, …])` を `BootProbeRunner::run()` へ置換。制限時間超過を**実測不能の例外**へ変換する |

### C. 一元化を守る gate を 1 本新設する

`tests/Architecture/PhpChildProcessLaunchInventoryTest.php` (新規 1 ファイル)。
走査器は aicue 既存の `tests/Support/PhpTokenScan.php` + `tests/Support/TrackedPhpSourceFiles.php` の上に建てる。

**分類を自己申告に留めないため、機械で決定可能な軸を 2 本持つ**:

| 軸 | 走査対象 | 何が決定できるか |
|---|---------|----------------|
| **軸 A (起動能力)** | `tests/` 配下で `PHP_BINARY` を参照するファイル | 「PHP の子を起こしうる箇所」の全数。未登録の参照は必ず赤 = **3 本目の経路がレビューに必ず現れる** |
| **軸 B (アプリの起動点)** | `tests/` 配下で `bootstrap/app.php` を子へ読み込ませる記述を持つファイル | 「**アプリを起こす**」という分類そのもの。ここは文字列リテラルの実在で決まるので**自己申告ではない** |

**交差の不変条件 (これが Critical への回答)**:

1. 軸 B の申告ファイルは、**起動呼び出しトークン (`proc_open` / `new Process` / `Process::` の静的呼び出し) を
   1 つも持たない**。= 軸 B のファイルは「起こされる側」(子の入口スクリプト、または自己検査の検体文字列) である
2. したがって軸 B のファイルを起こせるのは軸 A の申告ファイルだけであり、軸 A で
   `launches_app: true` と申告できる entry は **`tests/Support/Process/BootProbeRunner.php` ちょうど 1 件**に固定する
3. 軸 A の各 entry は `subject` / `recovery` / `reason` の 3 欄を独立に持つ (件数合わせの allowlist へ流れないため)

**この gate が保証しないこと (docblock に明記する)**: 静的走査は `proc_open` / `Process` の第 1 引数を
解釈しない (変数・定数・連結を追えないので、追おうとすると嘘になる)。可変関数名 (`$f = 'proc_open'; $f()`) も
追わない。したがって「そのファイルが**実際に**何を起こすか」は見ていない。守るのは
「**起こしうる箇所とアプリの起動点が、いずれも申告なしには増えない**」という境界である。
正典テンプレートの同型 gate も同じ限界を自ら明記している。

**受入条件 (走査器の健全性)**:

- 走査根 `tests/` が実在し、母集団が非空であること (走査器が壊れて 0 件のまま緑になるのを防ぐ)
- 負例: 未登録の `PHP_BINARY` 参照を足すと赤になること
- 負例: 軸 B の申告ファイルへ起動呼び出しトークンを足すと赤になること (runner を迂回した直接起動の検出)
- 正例: コメント・文字列のみに `PHP_BINARY` / `proc_open` が現れる検体を**誤検出しない**こと
- 走査器の判定は**見本表 (正例・負例) で固定**し、限界 (可変関数名は射程外) も見本の期待値として書く

### D. 環境の 4 段の確定表 (経路 1 で何が維持され、何が意図的に変わるか)

| 段 | 出所 | 中身 | `env -i` 版からの変化 |
|---|------|------|---------------------|
| 1. 継承 | `BootProbeRunner::INHERITED_ENV_KEYS` | `PATH` / `HOME` / `TMPDIR` (親に非空で在るときだけ) | **増える**。ただし許可一覧であり、`DB_*` / `AWS_*` / `STRIPE_*` / `TESTING_FAKE_*` / `GOOGLE_*` は 1 件も通らない |
| 2. 基底 | runner の `baseEnv()` | `APP_KEY` / `QUEUE_CONNECTION=database` / `CACHE_STORE=array` | **増える**。いずれも hermetic な既定で、観測 (container の解決結果・転送先 host) を変えない |
| 3. ケース別 | `FakeWiringProbeRunner` が渡す | `FAKE_WIRING_PROBE_ENV_DIR` / `FAKE_WIRING_PROBE_ENV_FILE` | 維持 |
| 4. 予約 | runner が一時ディレクトリから導く | 書き出し先 7 キー (`LARAVEL_STORAGE_PATH` ほか) | **増える**。従来の `APP_CONFIG_CACHE` 1 件の退避が 7 件へ広がる = 正典 (5) の強化 |

**維持する安全性 (主張を落とさない)**:

- `TESTING_FAKE_EXTERNALS` / `_STORAGE` / `_LLM` は**プロセス環境へ 1 件も載せない**。
  0700 のディレクトリに 0600 で置いた環境ファイルの中だけに置き、子が `loadEnvironmentFrom()` で固定する
- したがって P-7 の「危険な接頭辞 (`DB_` / `PG` / `AWS_` / `STRIPE_` / `TESTING_FAKE_` / `GOOGLE_`) が
  子のプロセス環境に 1 件も無い」という主張は**そのまま維持できる**
- `APP_KEY` / `CIPHERSWEET_KEY` が親の設定値の複写でないこと (P-8) も維持する。
  runner の基底が渡す `APP_KEY` は環境ファイルの `APP_KEY` に**負ける**わけではない —
  子は環境ファイルを `loadEnvironmentFrom()` で読むので、Dotenv は既存のプロセス環境を上書きしない。
  **この優先順位は実装時に実測で確かめ、P-8 の主張が保てる形を選ぶ** (詳細設計の検証事項)
- 子が受け取ったプロセス環境の**集合そのもの**を子自身が持ち帰り、
  `継承(実在分) + 基底 3 + ケース別 2 + 予約 7` と**完全一致**することを P-7 が測る (deny-by-default の維持)

### E. 結果境界の契約

- runner の結果は `BootProbeResult` (readonly。`stdout` / `stderr` / `exitCode` / `timedOut` /
  `elapsedSeconds` / `temporaryRoot` / `writtenRelativePaths` / `pid`) **だけ**を受け取る。
  `mixed` や無型の配列を呼び出し側へ漏らさない
- **`timedOut === true` (= `exitCode` が `BootProbeRunner::TIMEOUT_EXIT_CODE` = 124) は、
  通常の非ゼロ終了と区別して例外にする**。
  - 経路 1: 従来 `ProcessTimedOutException` を投げていた (P-10 が主張)。載せ替え後は
    `FakeWiringProbeRunner` が `RuntimeException` へ変換して主張を維持する
  - 経路 2: 従来は制限時間超過が例外だった。載せ替え後に「非ゼロ終了 = 厳密化が成立しない = false」へ
    落とすと**沈黙する fail-open** になる。124 は必ず例外にする

### F. 実装順 (fail-first)

| 段 | やること | ここで何が赤になるか |
|---|---------|-------------------|
| 1 | 3 ファイルをバイト一致で取り込む | 自己検査 14 本が**その場で緑**になる (道具の契約は取り込み元で証明済み)。ここが赤なら aicue との非互換が実在するので、**編集せずに報告する** |
| 2 | gate を先に新設する (載せ替え前) | 軸 A に `FakeWiringProbeRunner.php` / `StrictTypesRuntimeProbe.php` が現れ、`launches_app` の 1 件固定に**違反して赤**になる = gate が実際に効いていることの証拠 |
| 3 | 経路 1 を載せ替える + P-7 / P-11 を新契約へ / P-13・P-14 を追加 | P-13 (印が一時ディレクトリ配下に現れる) を**先に書くと赤**になる (子がまだ印を書かない) |
| 4 | 経路 2 を載せ替える | 制限時間超過の例外化を**先に書くと赤**になる (旧実装は `ProcessTimedOutException` を投げる) |
| 5 | gate の申告を実測へ合わせる | 段 2 の赤が緑へ変わる |
| 6 | `composer test` を `--parallel` で 2 回連続 | — |

### G. 受入条件

- `composer test` (`--parallel`) が 2 回連続で緑。取り込んだ自己検査 14 本 /
  `tests/Architecture/ExternalFakeBootProbeTest.php` / `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php` /
  新設 gate を**通常のテストコマンドで**緑にする (設計時の手動実測では代替しない)
- `composer phpstan` のエラーが 0 のまま (アプリコードを変更しないので現状維持)
- `composer fix` (Pint) が**取り込んだ 3 ファイルに差分を出さない**こと。差分が出たら
  「取り込み元が aicue の Pint 設定と食い違っている」という事実なので、**整形せずに報告する**
- 走行後にリポジトリ内に新しい未追跡ファイルが 1 件も無いこと (`git status --porcelain` が空)

## 制約・前提

- **走査基盤がテンプレートと違う**: テンプレートの gate は `nikic/php-parser` の `NameResolver` を使うが、
  **aicue は php-parser を直接依存に持たず、アプリ・テストのどこでも使っていない** (vendor には
  larastan 経由で在るだけ)。aicue の静的走査の基盤は `tests/Support/PhpTokenScan.php`
  (`token_get_all` の正規化) と `tests/Support/TrackedPhpSourceFiles.php` (git 追跡下の列挙) である。
  gate はこの 2 本の上に建てる = **aicue の既存の形に従う** (`tests/Architecture/FfmpegProcessLaunchInventoryTest.php`
  という同型の先例が既に在る)
- **gate の名前を正典と揃えない**: テンプレートの `tests/Architecture/SubprocessProbeLaunchGateTest.php` は
  テンプレートの指紋台帳に登録済みの**共有パス**である。そこへ aicue 固有の申告内容を置くと
  「共有パスに食い違う内容」になり、将来の指紋台帳の再生成で意図的逸脱の登録が必要になる。
  aicue 既存の命名 (`FfmpegProcessLaunchInventoryTest`) に倣った固有名を使う
- **PHPStan の対象外**: aicue の解析対象は `app` / `config` / `database` / `routes` でテストを含まない。
  本設計はアプリコードを変更しないので「PHPStan エラーが 0 のまま」が要件であり、
  ハーネスが level 10 で検査されるとは主張しない (`phpstan.neon` は採用時債務パスなので触らない)
- **Unix 系前提**: 段階的な強制終了は POSIX のシグナル意味論に依存する (取り込む runner の docblock が
  自ら明記している)。aicue の CI / devcontainer はいずれも Linux である
- **`RefreshDatabase` / `--parallel`**: 本設計のテストはいずれも DB を使わない。個別の
  `DatabaseTransactions` は使わない
- **一時ディレクトリの排他**: runner は `sys_get_temp_dir()` 配下に `boot-probe-<16 桁の乱数>` を掘るので
  `--parallel` の worker 間で衝突しない

## スコープ外

1. **アプリコード (`app/` / `routes/` / `config/` / `database/` / `bootstrap/`) の変更** — 1 バイトも触らない
2. **`docs/` の変更** — 正典は文書を要求していない。道具の説明は各ファイルの docblock を正本にする
3. **指紋台帳 (`docs/template-fingerprints.json`) の再生成と `LedgerPins` の件数更新** — 本設計は
   逸脱を 1 件も作らないので登録の増減が無い。再生成は他パスの再観測を巻き込む世代操作であり別議題
4. **`phpstan.neon` へのテストパスの追加** — 採用時債務パスなので触らない
5. **観測の中身の拡張** — 「何を観測するか」は別 feature の持ち物である
   (`external-fakes-wiring-gate`)。P-1〜P-12 の主張は本設計で増やさない
   (P-13 だけは正典 (5) が gate に要求する実働証明なので例外)
6. **`proc_open` を直呼びする既存 3 経路の載せ替え** (`tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` /
   `tests/Architecture/SkillsLockIgnoreCoverageTest.php` / `tests/Architecture/GitIndexNormalizationTest.php`) —
   いずれも `git` / シェルスクリプト / 別スクリプトの起動であり **PHP の実行体でアプリを起こす経路ではない**。
   正典 (1) の射程外である。gate はこれらを「PHP を起こさない」として申告に含めない
7. **`tests/Support/GlobalUse/PhpLintOracle.php` の載せ替え** — `php -l` を真値として取り出すだけで
   アプリを起動しない。`-n` 相当の最小起動に無関係な前提 (環境 3 段合成・書き出し先の退避) が付くので
   載せない。**gate の申告には残す** (理由の欄で説明する)
8. **子を 2 本立てて合図で同期させる並行テスト** — 別 feature (`process-concurrency-test-harness`。
   aicue では別 TODO で追従設計済み)
9. **`docs/TODO.md` への登録** — `/app-todo-add` の責務

