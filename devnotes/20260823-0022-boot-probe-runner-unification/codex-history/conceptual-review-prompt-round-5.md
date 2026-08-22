# Round 5: Round 4 の指摘への対応

Round 4 の [Warning] 2 件 (いずれも本文の内部矛盾) を、提案いただいた文言のとおりに直しました。
反論・見送りは 0 件です。

再レビューをお願いします。全体判定 (APPROVED / CHANGES_REQUESTED) を必ず明記してください。

---

# 対応マトリクス: conceptual-review Round 4

## [Warning] スコープ外 5 と D 節の「P-8 は主張を強める」が食い違う

- 判断: 対応する
- 根拠: 正しい。同じ文書内で「主張は増やさない」と「主張を強める」が併存していた。
- 対応内容: スコープ外 5 を「**観測対象**の拡張」へ改題し、内訳を 4 分類で書き分けた —
  追加する (P-13 / P-14 = 正典 (5) の実働証明) / 強化する (P-8 = 起動側の配列確認 → 子での実効値確認) /
  言い直すだけ (P-7 / P-11) / 一切変えない (P-1〜P-6 / P-9 / P-10 / P-12)。
  「観測対象となる外部 fake の種類は増やさない」ことをスコープ外の本体に据えた。

## [Warning] E 節の冒頭「無型の配列を漏らさない」と末尾の `output: array<string, mixed>` が矛盾

- 判断: 対応する
- 根拠: 正しい。冒頭が Round 2 以前の強い表現のまま残っていた。
- 対応内容: 冒頭を提案どおり限定した — 「プロセス実行結果の境界は `BootProbeResult` に統一する。
  子の JSON payload だけは `array<string, mixed>` として `interpret()` の中で受け、完全な array shape へ
  変換する。shape 内の `output` は呼び出し側 gate が各キーを検証してから使う」。
  併せて **`interpret()` の fail-closed を負例で固定する**要件を追加した
  (空出力 / JSON でない / トップレベルがスカラー / `timedOut` の 4 負例。いずれも子を起こさずに測る)。

## [Suggestion] 使命 / 禁止事項 / 実現可能性 / 期待効果 / リスク

- 判断: 対応不要 (前ラウンドまでの対応が受け入れられた)


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

### aicue の現状 (設計時に実読で実測。JST 2026-08-23 / UTC 2026-08-22)

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
4. **正典 (5) の実働証明を呼び出し側 gate に持たせる** — 2 方向で測る。
   - **実体 (P-13)**: 子が Laravel の `storage_path()` 経由で書いた印が、
     **一時ディレクトリ配下に実在する** (`BootProbeResult::$writtenRelativePaths` に現れる)
   - **向き (P-14)**: 子が起動後に解決した書き出し先の絶対パス
     (`storagePath()` / `getCachedConfigPath()` / `getCachedRoutesPath()` / `view.compiled` /
     `logging.channels.single.path`) が **1 件残らず `BootProbeResult::$temporaryRoot` の配下**であり
     **`base_path()` の外**である
   > **配下の判定は素の前方一致で書かない**。`/tmp/boot-probe-abc` の配下判定が
   > `/tmp/boot-probe-abc-evil` を取り違えるためである。取り込む runner が公開している
   > **`BootProbeRunner::isInside($root, $candidate)`** を使う — 区切り文字を境界にし、
   > 末尾の区切りを正規化し、両引数が絶対パスであることだけを表明する
   > (**存在しない予定パス (キャッシュの指し先) に `realpath()` を要求しない**)。
   > 境界そのものの負例 (`/repo` と `/repository` を取り違えない) は取り込む自己検査 S11 が既に固定している。
   > `base_path()` の外の判定は `! BootProbeRunner::isInside(realpath(base_path()), $path)` で書き、
   > **`base_path()` 自身との一致も「外ではない」に含める** (`isInside` は同一パスを true にする)
   > **リポジトリ側の before/after 差分は採らない**: `storage/logs/` /
   > `storage/framework/views/` / `bootstrap/cache/` は `--parallel` の**他 worker が実際に書く**ので、
   > 起動前後の差分比較はどう精密にしても偽陽性になる。「向き」+「実体」の 2 方向は決定的で並列安全であり、
   > 取り込む自己検査の S9 / S10 が採っている形と同じである。
   > **保証の範囲を誇張しない**: 主張できるのは「**Laravel が環境変数で受ける既知の書き出し先が
   > すべて退避されている**」までで、「リポジトリを 1 バイトも汚さない」という無制限の主張ではない。
   > 子が独自パスへ書けばこの観測には映らない (取り込む runner の docblock も同じ限界を明記している)
5. **退行を検出する全数申告 gate を 1 本置く** — **一元化そのものは 2 と 3 の載せ替えの実測で確認する**。
   gate が固定するのは「`PHP_BINARY` の明示参照 / リテラルのアプリ起動点 / 既存の子入口への参照が、
   **申告なしには増えない**」ことだけである (「アプリ起動経路が runner 1 本である」ことは主張しない。
   理由は C 節)

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
定める。本設計が新たに立てる不変条件は
「**`PHP_BINARY` の明示参照 / リテラルのアプリ起動点 / 既存の子入口スクリプトへの参照は、
いずれも申告なしには増えない**」である。一度の載せ替えだけでは、次に**素直な書き方で**足される
3 本目を止められないからである。**テンプレートも同じ判断で `SubprocessProbeLaunchGateTest` を
新設している** (全数申告 4 エントリ)。

> **不変条件を「アプリ起動経路は runner 1 本」と定義しない**。字句走査ではその全数性を裏取りできず
> (C 節に列挙した 5 形は原理的に追えない)、裏取りできない主張を不変条件として掲げると
> 「gate が緑だから 1 本である」という嘘を作る。**一元化そのものは載せ替えの実測が示し、
> gate は退行の検出器に徹する**。

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
| `tests/Architecture/ExternalFakeBootProbeTest.php` | P-7 / P-8 / P-11 を新しい契約へ書き換え、P-13 (実体: 印が一時ディレクトリ配下に実在する) と P-14 (向き: 子が解決した書き出し先が 1 件残らず一時ディレクトリ配下) を追加 = 正典 (5) の実働証明。P-1〜P-6・P-9・P-10・P-12 の**主張は変えない** |
| `tests/Support/StrictTypesRuntimeProbe.php` | `new Process([PHP_BINARY, …])` を `BootProbeRunner::run()` へ置換。制限時間超過を**実測不能の例外**へ変換する |

### C. 一元化を守る gate を 1 本新設する

`tests/Architecture/PhpChildProcessLaunchInventoryTest.php` (新規 1 ファイル)。
走査器は aicue 既存の `tests/Support/PhpTokenScan.php` + `tests/Support/TrackedPhpSourceFiles.php` の上に建てる。

**分類を自己申告に留めないため、機械で決定可能な軸を 3 本持つ**:

| 軸 | 走査対象 | 何が決定できるか |
|---|---------|----------------|
| **軸 A (起動能力)** | `tests/` 配下で `PHP_BINARY` を参照するファイル | 「PHP の子を起こしうる箇所」の全数。未登録の参照は必ず赤 = **`PHP_BINARY` を使う素直な 3 本目がレビューに必ず現れる** |
| **軸 B (アプリの起動点)** | `tests/` 配下で `bootstrap/app.php` を子へ読み込ませる記述を持つファイル | 「**アプリを起こす**」という分類そのもの。文字列リテラルの実在で決まるので**自己申告ではない** |
| **軸 C (子入口の参照)** | 子入口スクリプト `tests/Support/ExternalFakes/fake-wiring-probe.php` のパス文字列を参照するファイル | 「**既存の子入口を、runner を通さない別経路から起こす**」抜け道。実測 1 件 (`FakeWiringProbeRunner.php`) で、そのファイルが `BootProbeRunner` を参照していることを併せて固定する |

**交差の不変条件**:

1. 軸 B の申告ファイルは、**起動呼び出しトークン (`proc_open` / `new Process` / `Process::` の静的呼び出し) を
   1 つも持たない**。= 軸 B のファイルは「起こされる側」(子の入口スクリプト、または自己検査の検体文字列) である
2. 軸 A で `launches_app: true` と申告できる entry は **`tests/Support/Process/BootProbeRunner.php` ちょうど 1 件**。
   > **この 1 件固定は補助的な申告値である**。実際の起動経路の全数性を表すものではなく
   > (字句走査では裏が取れない)、「アプリを起こす」と**申告する**先が分散していないことを固定するだけである
3. 軸 A の各 entry は `subject` / `recovery` / `reason` の 3 欄を独立に持つ (件数合わせの allowlist へ流れないため)
4. 軸 C の各 entry は `reference_kind` を持つ。値は `runtime` (実行経路として子入口を起こす) または
   `inventory` (検査定義として path 文字列を保持する) のちょうど 2 値で、
   **`runtime` はちょうど 1 件 = `FakeWiringProbeRunner.php`** であり、そのファイルは
   `BootProbeRunner` を参照していること
   > **gate 自身も軸 C の母集団に入る**。gate は inventory に子入口の完全なパス文字列を持つので、
   > 走査対象から自分を**除外しない** (除外は迂回口になる)。`reference_kind: inventory` として
   > 自らを申告し、`runtime` と分けて数える。**文字列を分割して検出を逃れることは禁止**する
   > (軸 C が防ぎたい迂回そのものだからである)。この禁止は走査器の見本検査で固定する

#### この gate が主張すること / 主張しないこと (検出力に主張を合わせる)

**主張する**: 「`PHP_BINARY` を明示する起動能力 (軸 A) / リテラルで検出できるアプリの起動点 (軸 B) /
既存の子入口スクリプトへの参照 (軸 C) の 3 つは、いずれも**申告なしには増えない**」。

**主張しない**: 「アプリを子プロセスで起こす経路が runner ちょうど 1 本である」ことは**主張しない**。
字句走査では次の形を原理的に追えないためである — これを docblock に名指しで書く:

1. 文字列リテラルの `'php'` を実行体にする形
2. `env php …` のように別コマンド経由で PHP を起こす形
3. シェルスクリプトを起こし、その中で PHP を起こす形
4. 実行体のパスを変数・設定から取り出す形
5. `proc_open` / `Process` の第 1 引数を静的に解釈すること (変数・定数・連結を追えないので、
   追おうとすると嘘になる)。可変関数名 (`$f = 'proc_open'; $f()`) も追わない

**一元化そのものの証拠は「載せ替えの実測」であって gate ではない**。gate は**退行の検出器**であり、
素直な書き方で足された 3 本目を確実に捕まえる境界を引くための道具である。

**受入条件 (走査器の健全性)**:

- 走査根 `tests/` が実在し、3 軸それぞれの母集団が非空であること (走査器が壊れて 0 件のまま緑になるのを防ぐ)
- 負例: 未登録の `PHP_BINARY` 参照を足すと赤になること
- 負例: 軸 B の申告ファイルへ起動呼び出しトークンを足すと赤になること (runner を迂回した直接起動の検出)
- 負例: 軸 C に未登録のファイルから子入口スクリプトのパスを参照すると赤になること
- 正例: コメント・文字列のみに `PHP_BINARY` / `proc_open` が現れる検体を**誤検出しない**こと
- 走査器の判定は**見本表 (正例・負例) で固定**し、限界 (可変関数名は射程外) も見本の期待値として書く
  (射程が黙って変わらないように、限界そのものを固定する)

### D. 環境の 4 段の確定表 (経路 1 で何が維持され、何が意図的に変わるか)

| 段 | 出所 | 中身 | `env -i` 版からの変化 |
|---|------|------|---------------------|
| 1. 継承 | `BootProbeRunner::INHERITED_ENV_KEYS` | `PATH` / `HOME` / `TMPDIR` (親に非空で在るときだけ) | **増える**。ただし許可一覧であり、`DB_*` / `AWS_*` / `STRIPE_*` / `TESTING_FAKE_*` / `GOOGLE_*` は 1 件も通らない |
| 2. 基底 | runner の `baseEnv()` | `APP_KEY` / `QUEUE_CONNECTION=database` / `CACHE_STORE=array` | **増える**。いずれも hermetic な既定で、観測 (container の解決結果・転送先 host) を変えない |
| 3. ケース別 | `FakeWiringProbeRunner` が渡す | `FAKE_WIRING_PROBE_ENV_DIR` / `FAKE_WIRING_PROBE_ENV_FILE` / **使い捨て `APP_KEY`** | ケース別が最後に効く (正典 (2) の順序) |
| 4. 予約 | runner が一時ディレクトリから導く | 書き出し先 7 キー (`LARAVEL_STORAGE_PATH` ほか) | **増える**。従来の `APP_CONFIG_CACHE` 1 件の退避が 7 件へ広がる = 正典 (5) の強化 |

> **使い捨て `APP_KEY` をケース別上書きへ置く理由 (設計時に実測して確定した)**:
> Laravel の `Env::getRepository()` は **immutable** で、**プロセス環境に既に在る値を Dotenv は上書きしない**。
> runner の基底が `APP_KEY` を載せる以上、0600 の環境ファイルに書いた使い捨て鍵は**無視される**。
> 実測 — 環境ファイルに `APP_KEY=base64:ZmlsZS1r…`、プロセス環境に `APP_KEY=base64:cnVubmVy…` を置いて
> 子を起こすと `config('app.key')` は**プロセス環境側**になった。同じ実測で、プロセス環境に無い
> `CIPHERSWEET_KEY` は環境ファイルの値が効いた。
> よって **`APP_KEY` は環境ファイルの許可キーから外し**、ケース別上書きとして runner へ渡す。
> `CIPHERSWEET_KEY` は runner の基底にも予約鍵にも無いので**環境ファイルのままで効く**。

**維持する安全性 (主張を落とさない)**:

- `TESTING_FAKE_EXTERNALS` / `_STORAGE` / `_LLM` は**プロセス環境へ 1 件も載せない**。
  0700 のディレクトリに 0600 で置いた環境ファイルの中だけに置き、子が `loadEnvironmentFrom()` で固定する
- したがって P-7 の「危険な接頭辞 (`DB_` / `PG` / `AWS_` / `STRIPE_` / `TESTING_FAKE_` / `GOOGLE_`) が
  子のプロセス環境に 1 件も無い」という主張は**そのまま維持できる**
- **P-8 は主張を強める**: 従来は「起動側が組み立てた配列の鍵が親の設定値の複写でない」ことしか見ていなかった。
  載せ替え後は「**子が実際に観測した `APP_KEY` が、生成した使い捨て値と一致し、親の `config('app.key')` とは
  一致しない**」を測る (使い捨て鍵が子で効いたことまで示す)。`CIPHERSWEET_KEY` も同様に子側の観測で測る
- 子が受け取ったプロセス環境の**集合そのもの**を子自身が持ち帰り、
  `継承(実在分) + 基底 3 + ケース別 3 + 予約 7` と**完全一致**することを P-7 が測る (deny-by-default の維持)

### E. 結果境界の契約

- **プロセス実行結果の境界は `BootProbeResult` に統一する** (readonly。`stdout` / `stderr` / `exitCode` /
  `timedOut` / `elapsedSeconds` / `temporaryRoot` / `writtenRelativePaths` / `pid`)。
  子の JSON payload だけは `array<string, mixed>` として **`interpret()` の中で受け**、
  完全な array shape へ変換する。shape 内の `output` は**呼び出し側 gate が各キーを検証してから使う**
  (現行の `externalFakeProbeResolved()` が既に採っている形をそのまま保つ)
- **判定には `timedOut` を使い、`exitCode === 124` を直接読まない**。両者は厳密には同値ではない
  (終了要求を受け取ってから自分で `exit(0)` する子は `timedOut === true` かつ `exitCode === 0` になりうる)。
  取り込む `BootProbeResult` はこの同値を構築時に表明しないので、**呼び出し側が `timedOut` を見る**契約にする
  (runner 側の対の固定は取り込む自己検査 S7 / S12 / S14 が既に持っている)
- **`timedOut === true` は、通常の非ゼロ終了と区別して例外にする**。
  - 経路 1: 従来 `ProcessTimedOutException` を投げていた (P-10 が主張)。載せ替え後は
    `FakeWiringProbeRunner` が `RuntimeException` へ変換して主張を維持する
  - 経路 2: 従来は制限時間超過が例外だった。載せ替え後に「非ゼロ終了 = 厳密化が成立しない = false」へ
    落とすと**沈黙する fail-open** になる。制限時間超過は必ず例外にする
- **判定を純粋な変換関数として切り出す** (`BootProbeResult` → 判定)。子を起こさずに分岐を測れる形にする:
  - `StrictTypesRuntimeProbe::decide(BootProbeResult $result, string $nonce): bool`
    (`timedOut` なら例外 / `exitCode !== 0` なら false / 標識不一致なら例外 / `STRICT-nonce` なら true)
  - `FakeWiringProbeRunner::interpret(BootProbeResult $result, …)`
    (`timedOut` なら例外 / 出力が JSON でなければ例外)
- **型付けの範囲を正確に書く**: `interpret()` の戻り値は既存の**完全な array shape**
  (`array{exitCode: int, output: array<string, mixed>, envFileValues: array<string, string>, …}`) を
  PHPDoc で固定する。ただし `output` は**子が書いた JSON** なので `array<string, mixed>` にならざるを得ない。
  したがって主張は「**無型の値が漏れるのは子の JSON という 1 か所だけで、そこは呼び出し側 gate が
  各キーごとに型を確かめてから使う**」であって、「無型の配列が 1 つも無い」ではない
- **`interpret()` は不正な構造を返さない (fail-closed)**: JSON デコード直後に
  (a) 出力が空でないこと (b) JSON として解釈できること (c) トップレベルが配列であること を確かめ、
  いずれかを満たさなければ**例外にする**。現行の `decode()` が既に持つ 3 つの fail-closed をそのまま保つ。
  **負例で固定する**: 空出力 / JSON でない出力 / トップレベルがスカラーの JSON / `timedOut` の結果 —
  いずれも例外になること (子を起こさずに `BootProbeResult` を組み立てて測る)

### F. 実装順 (fail-first)

| 段 | やること | ここで何が赤になるか |
|---|---------|-------------------|
| 1 | 3 ファイルをバイト一致で取り込む | 自己検査 14 本が**その場で緑**になる (道具の契約は取り込み元で証明済み)。ここが赤なら aicue との非互換が実在するので、**編集せずに報告する** |
| 2 | gate を先に新設する (載せ替え前)。申告は**載せ替え後の姿**で書く | 軸 A の実測に旧 2 経路 (`FakeWiringProbeRunner.php` / `StrictTypesRuntimeProbe.php` が `PHP_BINARY` を直接持つ) が現れ、**申告集合と一致せず赤**になる。軸 C も `runtime` の参照元が `BootProbeRunner` を参照していないので赤 = gate が実際に効いていることの証拠 |
| 3 | 経路 1 を載せ替える + P-7 / P-8 / P-11 を新契約へ / P-13・P-14 を追加 | P-13 (印が一時ディレクトリ配下に現れる) を**先に書くと赤**になる (子がまだ印を書かない)。P-8 の新契約 (子が観測した使い捨て `APP_KEY`) も、子が `APP_KEY` を持ち帰らないうちは赤 |
| 4 | 経路 2 を載せ替える | **純粋な変換関数の負例を先に書くと赤**になる — `StrictTypesRuntimeProbe::decide()` / `FakeWiringProbeRunner::interpret()` は旧実装に**存在しない**ので、メソッド不在で落ちる。「制限時間超過を例外にする」という観測だけでは旧実装 (`ProcessTimedOutException`) も通ってしまうので、**赤を作るのは変換関数の境界**にする |
| 5 | gate の申告を実測へ合わせる | 段 2 の赤が緑へ変わる |
| 6 | `composer test` を `--parallel` で 2 回連続 | — |

### G. 受入条件

- `composer test` (`--parallel`) が 2 回連続で緑。取り込んだ自己検査 14 本 /
  `tests/Architecture/ExternalFakeBootProbeTest.php` / `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php` /
  新設 gate を**通常のテストコマンドで**緑にする (設計時の手動実測では代替しない)
- `composer phpstan` のエラーが 0 のまま (アプリコードを変更しないので現状維持)
- `composer fix` (Pint) が**取り込んだ 3 ファイルに差分を出さない**こと。差分が出たら
  「取り込み元が aicue の Pint 設定と食い違っている」という事実なので、**整形せずに報告する**
- **生成物を残さないこと**: 作業開始時の `git ls-files --others --exclude-standard` の集合と、
  テスト走行後の同集合が**一致する** (実装で追加した未コミットの新規ファイルは開始時の集合に含まれるので
  偽陽性にならない。見たいのは「走行が生成物を残したか」だけである)

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
5. **観測対象の拡張** — 「何を観測するか」は別 feature の持ち物である
   (`external-fakes-wiring-gate`)。**観測対象となる外部 fake の種類は本設計で増やさない**。
   本設計が触るのは観測の**土台**だけであり、内訳は次のとおり:
   - **追加する**: P-13・P-14 — 正典 (5) が gate に要求する実働証明 (書き出し先の退避が効いていること自体)
   - **強化する**: P-8 — 使い捨て鍵の契約を「起動側が組み立てた配列の確認」から
     「**子で実際に効いた値の確認**」へ (新しい観測対象ではなく既存主張の検証強化)
   - **言い直すだけ**: P-7 / P-11 — 同じ主張を新しい土台の上で表現し直す
   - **一切変えない**: P-1〜P-6 / P-9 / P-10 / P-12
6. **`proc_open` を直呼びする既存 3 経路の載せ替え** (`tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` /
   `tests/Architecture/SkillsLockIgnoreCoverageTest.php` / `tests/Architecture/GitIndexNormalizationTest.php`) —
   いずれも `git` / シェルスクリプト / 別スクリプトの起動であり **PHP の実行体でアプリを起こす経路ではない**。
   正典 (1) の射程外である。gate はこれらを「PHP を起こさない」として申告に含めない
7. **`tests/Support/GlobalUse/PhpLintOracle.php` の載せ替え** — `php -l` を真値として取り出すだけで
   アプリを起動しない。`-n` 相当の最小起動に無関係な前提 (環境 3 段合成・書き出し先の退避) が付くので
   載せない。**gate の申告には残す** (理由の欄で説明する)
8. **子を 2 本立てて合図で同期させる並行テスト** — 別 feature (`process-concurrency-test-harness`。
   aicue では別 TODO で追従設計済み)
9. **`tests/` 全域のプロセス起動 API の全数申告** (`proc_open` / Symfony `Process` /
   `Illuminate\Support\Facades\Process` / シェル起動) — 実測すると**母集団は 25 ファイル**で、
   `Process::fake()` の単体テストや `git ls-files` の列挙まで含む。3 欄の申告を 25 件書くのは
   本 TODO の目的から外れた別作業であり、禁止事項の「やたらに複雑な案」に当たる。
   **再判定の条件**: 本設計の gate が捕まえられない形 (文字列 `'php'` / `env php` / シェル経由 /
   変数の実行体パス) で子プロセスの起動が実際に足されたとき、または `tests/` のプロセス起動が
   別の理由で棚卸しの対象になったとき
10. **`docs/TODO.md` への登録** — `/app-todo-add` の責務

