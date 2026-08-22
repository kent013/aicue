# アプリの使命 (North Star)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

# 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

# ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# あなたの役割

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

（アプリの使命・禁止事項は上に挿入済み）

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【本件の追加文脈】
本件は「家系の機能台帳 lctl の feature `subprocess-boot-probe-harness` (正典 v1) への aicue 追従設計」である。
正典の不変条件 6 点は概念設計の冒頭表がそのまま正本の写しである。追従設計なので、
「正典が求めていないことをやらない」ことと「正典が求めることを漏らさない」ことの両方が評価軸になる。
実装対象は `tests/` 配下のみで、`app/` は 1 バイトも変更しない。

---

## 概念設計

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
   書いた印が、**リポジトリではなく一時ディレクトリ配下に現れた**ことを実測する
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
| `tests/Architecture/ExternalFakeBootProbeTest.php` | P-7 / P-11 を新しい契約へ書き換え、P-13 (印が一時ディレクトリ配下に現れる) を追加。P-1〜P-6・P-8〜P-10・P-12 の**主張は変えない** |
| `tests/Support/StrictTypesRuntimeProbe.php` | `new Process([PHP_BINARY, …])` を `BootProbeRunner::run()` へ置換。制限時間超過を**実測不能の例外**へ変換する |

### C. 一元化を守る gate を 1 本新設する

`tests/Architecture/PhpChildProcessLaunchInventoryTest.php` (新規 1 ファイル)。
`tests/` 配下で `PHP_BINARY` を参照するファイルを deny-by-default で全数申告させ、
`subject` / `recovery` / `reason` の 3 欄を独立に持たせる (件数合わせの allowlist へ流れないため)。
最後に「アプリを起こすと申告した entry はちょうど 1 件 = `BootProbeRunner`」を固定する。

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

