# Round 3: Round 2 の指摘への対応

Round 2 の [Critical] 1 件・[Warning] 6 件・[Suggestion] 1 件をすべて捌きました (反論・見送り 0 件)。

必須修正 3 点の対応:

1. **外側の置き場所もリポジトリ外へ fail-closed** — `withEnvironmentDirectory()` に
   絶対パス/実在/書き込み可の表明・`realpath()` 正規化・`BootProbeRunner::isInside()` による
   リポジトリ内の拒否・作成済みディレクトリの削除・実効 mode の検査を入れ、負例 **P-10d** を追加
2. **P-10c で中身のあるディレクトリの例外時削除を検査** — callback 内で `.env.probe` と
   下位ディレクトリの番兵を作ってから例外を投げる形へ
3. **S6 の名称・保証主張を字句参照 inventory へ狭め、恒久の正負例を追加** —
   ファイル名を `tests/Architecture/PhpBootProbeReferenceInventoryTest.php` へ改名し、
   冒頭説明を 3 種類の字句参照に限定。G-7 の見本表へ G-6 のトークン列判定の正例 1 + 負例 8、
   軸 A の接頭辞/打ち消し/接尾辞の負例 3 を追加

再レビューをお願いします。全体判定 (APPROVED / CHANGES_REQUESTED) を必ず明記してください。

---

# 対応マトリクス: design-review Round 2

## [Critical] S2: 外側の環境ファイル置き場が「必ずリポジトリ外」を強制していない

- 判断: **対応する**
- 根拠: 正しい。`withEnvironmentDirectory()` は任意の `$baseDirectory` を受け、内側の
  `BootProbeRunner` だけがリポジトリ外の fail-closed を持っていた。正典 v1 (5) は
  「リポジトリを汚さない」ことを求めており、外側だけ抜けているのは保証の穴である。
- 対応内容: `withEnvironmentDirectory()` に**内側と同じ fail-closed** を入れた。
  1. `$base` が絶対パス・実在ディレクトリ・書き込み可能であることを表明する
  2. 作成後に `realpath()` で正規化する
  3. **`BootProbeRunner::isInside(FakeClassCatalog::repoRoot(), $directory)` が真なら、
     callback を呼ぶ前に作成済みディレクトリを消してから例外にする**
  4. 負例 **P-10d** を追加 (リポジトリ内を渡すと本体を呼ばず、残骸も残さない)。
     「本体を呼ばない」ことは callback が触る番兵で決定的に測る
  判定に `BootProbeRunner::isInside()` を使うのは、内側と同じ境界規則
  (区切り文字を境界にする / 同一パスも配下と見る) を 2 か所で持たないためである。

## [Warning] S2: `chmod()` の結果を無視しており helper 単体の契約が成立しない

- 判断: 対応する
- 対応内容: helper 自身が **`chmod()` の成功と実効 mode (0700) を callback の前に検査**し、
  失敗時は**作成済みディレクトリを消してから**例外にする形へ直した
  (`run()` が後から測る `assertSafePermissions()` は環境ファイル込みの検査として残す)。

## [Warning] S4: P-10c が「空ディレクトリを消せる」ことしか測っていない

- 判断: 対応する
- 根拠: 正しい。制限時間超過の実際の状況では `.env.probe` が既に存在するので、
  「中身ごと再帰削除される」ことまで測らないと主張と距離がある。
- 対応内容: P-10c の callback 内で **`.env.probe` 相当のファイルと下位ディレクトリの中の番兵**を
  作ってから例外を投げる形へ変えた。これで「例外経路でも中身ごと消える」が単独で証明できる。

## [Warning] S6: gate 名と冒頭説明が実際の保証より広い

- 判断: 対応する
- 根拠: 正しい。`PhpChildProcessLaunchInventoryTest` / 「PHP の子プロセスを起こしうる箇所の全数申告」は
  `'php'` / `env php` / シェル経由 / 変数実行体を検出しない以上、名前が保証を誇張している。
  **機能の名前に立ち返れ**という思考原則にも反する。
- 対応内容: ファイル名を **`tests/Architecture/PhpBootProbeReferenceInventoryTest.php`** へ改め、
  冒頭説明を「**`PHP_BINARY` / `bootstrap/app.php` / 既存の子入口スクリプトという 3 種類の
  字句参照の全数申告**」に限定した。設計中の全ての参照 (施策一覧・軸 B/C の自己申告・
  乖離台帳の表・実装順) を新しい名前へ揃えた。

## [Warning] S6: G-6 のトークン列判定に恒久の正例・負例が無い

- 判断: 対応する
- 対応内容: G-7 の見本表へ提案された 6 件をすべて追加した —
  正例 `BootProbeRunner::run([])` / 負例 (未使用の `use`) / 負例 (コメント内) / 負例 (文字列内) /
  負例 `OtherBootProbeRunner::run(` / 負例 `BootProbeRunner::runner(`。

## [Warning] S6: 語彙の完全一致に対する接頭辞・接尾辞の負例が不足

- 判断: 対応する
- 対応内容: 軸 A へ `MY_PHP_BINARY` / `NOT_PHP_BINARY` / `PHP_BINARY_PATH` の負例を、
  G-6 へクラス名・メソッド名の接頭辞/接尾辞形の負例を、いずれも**恒久テスト**として追加した。

## [Warning] 横断: 実行時間の「既存テストだけの中央値」の測り方が未定義

- 判断: 対応する
- 対応内容: 比較コマンドを確定した — 実装前に `composer test -- --list-tests` 相当で
  **対象ファイル一覧を保存**し、実装後は **`--exclude-filter` で新規 2 テストファイル
  (`BootProbeRunnerTest` / `PhpBootProbeReferenceInventoryTest`) を除外して同じ集合**を走らせる。
  5% 超過時は**閾値を動かさず原因を報告する**方針を明記した (現方針の維持)。

## [Suggestion] 横断: ignored 生成物の比較を一覧 + ハッシュにする

- 判断: 対応する
- 対応内容: 「見比べる」を、`storage/logs/` / `storage/framework/views/` / `bootstrap/cache/` の
  **相対パス一覧と各ファイルの sha256 を走行前後で比較する**へ具体化した。

## [APPROVE] S1 / S3 / S5

- 判断: 対応不要 (Round 1 の対応が受け入れられた)


---

## 改訂後の詳細設計書 (全文)

# 詳細設計: boot-probe-runner-unification

家系の機能台帳 lctl の feature `subprocess-boot-probe-harness` (正典 aigenba / `canonical_version: v1`) への
aicue 追従。**アプリコード (`app/` / `routes/` / `config/` / `database/` / `bootstrap/`) は 1 バイトも変更しない**。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  （撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg /
> 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の
   **1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. **Artifact の使用**(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

> **本設計との関係**: 4 / 5 / 6 / 7 / 8 は該当なし (API も LLM も UI も触らない)。
> 1 は S6 の gate 新設と S2〜S4 のテスト計画で満たす。2 は「PHPStan のエラーが 0 のまま」で満たす
> (テストは aicue の解析対象外。後述)。3 は該当なし (DB を 1 度も張らない)。
> 9 は本設計フロー全体で守る (成果物は `devnotes/` 配下のファイルのみ)。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)。**ただし aicue の解析対象は `app` / `config` /
  `database` / `routes` でテストを含まない**。本設計はアプリコードを変更しないので
  「PHPStan のエラーが 0 のまま」であることが要件であり、**ハーネスが level 10 で検査されるとは主張しない**
  (`phpstan.neon` は触らない。理由は「乖離台帳の確認」の節)
- **Pest** テストフレームワーク (`composer test`)
- **RefreshDatabase** + `--parallel` 並列実行 (`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止)。
  **本設計のテストは DB を 1 度も張らない**
- **テストデータは必ず Factory で生成** — 本設計はモデルを 1 つも使わないので該当なし
  (新モデルも Factory も追加しない)
- **DTO + JsonResource** パターン — 本設計は API を 1 本も増やさないので該当なし
- **アーリーリターン** 推奨 / `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- [`devnotes/20260823-0022-boot-probe-runner-unification/conceptual-design.md`](./conceptual-design.md)
  (Codex 概念設計レビュー Round 5 で APPROVED)
- **概念設計からの変更 1 件**: 「経路 2 (`StrictTypesRuntimeProbe`) も共通 runner へ載せ替える」という
  方針は**撤回した** (詳細設計レビュー Round 1 の [Critical] を受諾)。理由は S5 に書く。
  概念設計の当該節には撤回の注記を残してある。

## 正典 v1 の不変条件と、本設計での担い手 (全件の対応表)

| # | 正典 v1 の不変条件 (feature_yaml の boundary が正本) | 本設計での担い手 | 検査 |
|---|--------------------------------------------------|----------------|------|
| (1) | 子は `PHP_BINARY` で起こす (親と同じ実行体) | `BootProbeRunner::spawn()` の `proc_open([PHP_BINARY, ...$phpArguments], …)` (S1 でバイト一致取り込み) | 自己検査 S9 (子が報告する `PHP_BINARY` が親と一致) |
| (2) | 環境変数は 3 段 (許可一覧の継承 → ケース共通の基底 → ケース別上書き。ケース別が最後に効く)。開発者ローカルの env を入力集合から外す | `BootProbeRunner::composeEnv()` の `array_merge($inherited, baseEnv(), $caseEnv, $reserved)` | 自己検査 S1 / S2 / S3 / S4 + 呼び出し側 **P-7** |
| (3) | 出力の管を非ブロッキングで逐次読み、制限時間超過は SIGTERM → 猶予 → SIGKILL、全ての管を閉じてから必ず `proc_close` | `BootProbeRunner::spawn()` / `readAvailable()` / `reclaim()` | 自己検査 S7 / S8 / S12 / S13 / S14 |
| (4) | 終了コードは実行中フラグが初めて false になった時点の非負値を保存し、`-1` や `proc_close` の戻り値で上書きしない。取れなければ 124 へ正規化 | `BootProbeRunner::spawn()` の `$exitCode` 確定と `TIMEOUT_EXIT_CODE` | 自己検査 S6 / S7 / S12 / S13 / S14 + 呼び出し側 **P-15** (制限時間超過の解釈) |
| (5) | 子の書き出し先を環境変数でリポジトリ外の一時ディレクトリへ逃がす + **その環境変数が実際に効いていること自体を gate が検査する** | runner の `RESERVED_ENV_KEYS` 7 キー + `createTemporaryRoot()` の fail-closed / **aicue 側の実働証明は S4 の P-13 (実体) と P-14 (向き)** | 自己検査 S4(c) / S9 / S10 / S11 + 呼び出し側 **P-11 / P-13 / P-14** |
| (6) | runner 自身の自己検査を持つ (許可一覧の網羅性 / 上書きの適用順 / 終了コードの保持 / 制限時間の回収) | `tests/Unit/Support/Process/BootProbeRunnerTest.php` (S1 でバイト一致取り込み。14 本) | それ自体 |

**正典が含まないもの (boundary が明記。本設計もやらない)**: 子プロセスで何を観測するかという個別の主張 /
子を 2 本立てて合図で同期させる並行テスト / 静的走査の基盤そのもの (`static-scanner-substrate`) /
HTTP サーバーの常駐起動 / テストレーンの構成。

> **S6 (全数申告 gate) の位置付けを先に断っておく**: S6 は**正典 v1 の 6 不変条件のいずれでもない**。
> aicue 側の**上積み**である。根拠は 2 つ — (a) 正典テンプレートも本 feature の追従で同型の gate
> (`tests/Architecture/SubprocessProbeLaunchGateTest.php`) を新設しており、台帳の実装報告に
> 「新設 gate」として記録されている。(b) AGENTS.md 禁止事項 1 が「不変条件は対応する
> Architecture/Feature テストへの登録まで含めて実装済み」と定めるので、載せ替え一度きりでは規約を満たさない。
> **正典の boundary が除く「静的走査」は走査の基盤を持つ feature (`static-scanner-substrate`) を指しており、
> 追従の中で走査を使う gate を置くことを禁じてはいない** (テンプレートの先例がその読みを支持する)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 共通 runner をテンプレートからバイト一致で取り込む | `tests/Support/Process/BootProbeRunner.php` / `tests/Support/Process/BootProbeResult.php` / `tests/Unit/Support/Process/BootProbeRunnerTest.php` (新規 3) | 高 |
| S2 | 経路 1 の起こし手を runner へ載せ替える | `tests/Support/ExternalFakes/FakeWiringProbeRunner.php` (変更) | 高 |
| S3 | 子入口スクリプトへ実働証明の観測点を足す | `tests/Support/ExternalFakes/fake-wiring-probe.php` (変更) | 高 |
| S4 | 呼び出し側 gate を新契約へ揃え、正典 (5) の実働証明を足す | `tests/Architecture/ExternalFakeBootProbeTest.php` (変更) | 高 |
| S5 | 経路 2 を**載せ替えない**理由を docblock へ明記する | `tests/Support/StrictTypesRuntimeProbe.php` (docblock のみ変更) | 中 |
| S6 | 一元化に関する退行を検出する全数申告 gate を新設する | `tests/Architecture/PhpBootProbeReferenceInventoryTest.php` (新規 1) | 高 |

**アプリコード・`docs/`・`phpstan.neon`・指紋台帳・逸脱の登録簿は 1 行も触らない。**
既存テストの**削除・主張の後退は 0 件**である。

---

## S1: 共通 runner をテンプレートからバイト一致で取り込む

### 変更箇所 (すべて新規)

| パス | 取り込み元 | 設計時に実測した sha256 (テンプレート指紋台帳の記録値と一致) |
|------|-----------|--------------------------------------------------|
| `tests/Support/Process/BootProbeRunner.php` | `laravel-claude-template` 同パス | `bd21b337cc7e4327debba02a3ba46cb496f0a66f0980ccf08cb3847a18430162` |
| `tests/Support/Process/BootProbeResult.php` | 同 | `00b14167ebfa9710abdb36edf8989bb66350320ee191c3993debd06ed27902cb` |
| `tests/Unit/Support/Process/BootProbeRunnerTest.php` | 同 | `9db128d89629dc5f4cd891a2f22d063451e3e524480141ff05e7ad0aa261d014` |

### 取り込みの手順 (fail-first。ファイル内容は 1 バイトも変えない)

1. lctl の `get_source` で `laravel-claude-template:docs/template-fingerprints.json` を取得し、
   `entries` に上記 3 パスが登録されていることを確認する
2. 同じく `get_source` で 3 ファイルを取得し、**各ファイルの sha256 が台帳の記録値と一致**することを確認する。
   1 件でも食い違えばそこで止め、原因 (世代のずれ) を報告する
3. **自己検査 `tests/Unit/Support/Process/BootProbeRunnerTest.php` だけを先に配置し、
   `Tests\Support\Process\BootProbeRunner` が未定義で赤になることを確認する** (fail-first)。
   ファイル内容は変えずに実現できる
4. 実装 2 本 (`BootProbeRunner.php` / `BootProbeResult.php`) を配置し、自己検査 14 本が緑になることを確認する
5. **`vendor/bin/pint --test` で非破壊に整形の一致を確認する**。落ちたら
   「取り込み元が aicue の Pint 設定と食い違っている」という事実なので、**整形せずに報告して止まる**
   (`composer fix` は書き換えるので、この段では**実行しない**)
6. 配置後にもう一度 3 ファイルの sha256 を取り、手順 2 の値と一致することを確認する

> **なぜバイト一致に固執するか**: このパスは aicue の指紋台帳 (281 パス) に無い = 未受領のテンプレートパスである。
> バイト一致で入れれば、将来の指紋台帳の再生成で**記録値と一致して母集合に入り、逸脱 0 件・債務 0 件**になる。
> 1 バイトでも変えると意図的逸脱の登録 (`LedgerPins::DIVERGENCE_ENTRY_COUNT` の更新を伴う) が必要になる。

### 依存の確認 (実装前に満たされていること — 設計時に実読で確認済み)

| 依存 | aicue での実在 |
|------|--------------|
| `Webmozart\Assert\Assert` | `composer.json` の `require` に `webmozart/assert: ^2.4` |
| `FilesystemIterator` / `RecursiveDirectoryIterator` / `RecursiveIteratorIterator` / `SplFileInfo` | PHP 標準 (SPL) |
| `posix_kill` (自己検査 S12 / S14 が任意で使う) | 無ければ検査側が早期 return する形なので必須ではない |
| `pcntl_signal` (自己検査 S14) | 無ければ S14 は `skip` になる (**成功扱いにはならない**) |
| 名前空間 `Tests\Support\Process` の autoload | `composer.json` の `autoload-dev` の `Tests\` → `tests/` に含まれる |

### 取り込み先で通ることの事前実測 (設計時に実施済み)

自己検査 S9 / S10 は「アプリを子で起こし、書き出し先が一時ディレクトリを指し、
`storage/logs/laravel.log` がそこに実在する」ことを測る。runner と同じ環境合成を手で再現して実測した結果:

- 子は **exit 0**、標準エラーは空
- `storagePath()` / `getCachedConfigPath()` / `getCachedRoutesPath()` / `view.compiled` /
  `logging.channels.single.path` の**全てが一時ディレクトリ配下**
- 一時ディレクトリ配下に実際に書かれたのは `storage/logs/laravel.log` /
  `bootstrap-cache/services.php` / `bootstrap-cache/packages.php` の 3 件。**リポジトリ側は 0 件**
- 子が受け取ったプロセス環境は `PATH` / `HOME` / `TMPDIR` + 基底 3 + 予約 7 のみ

### 取り込んだ docblock と aicue の構成の齟齬 (1 か所。**書き換えない**)

| 取り込んだ記述 (`BootProbeRunner` の docblock 末尾) | aicue での実際 |
|---|---|
| 「`app` / `routes` / `config` / `database` / `bootstrap` へ持ち出すと**外部到達統制の subprocess 0 件 pin** に触れる (AGENTS.md セキュリティ不変条件 **15**)。同じ扱いの先例は `tests/Support/Architecture/GlobalUse/PhpLintOracle.php`」 | aicue の外部到達点の目録は **AGENTS.md セキュリティ不変条件 9** であり、`php -l` の真値取り出しは `tests/Support/GlobalUse/PhpLintOracle.php` (`Architecture/` が入らない) にある。**趣旨 (tests/ 専用であり app/ へ持ち出さない) は aicue でもそのまま成り立つ** |

**書き換えると共有パスの逸脱になる**ので、この訂正表は
**`tests/Support/ExternalFakes/FakeWiringProbeRunner.php` の docblock** (= runner を使う限り必ず存在する
aicue 所有のファイル) に置く。S6 の gate へ置くと、将来 S6 が消えたときに訂正も一緒に消えてしまう。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 自己検査 1 本が同時に入る。既存テストの変更は無し
- 指紋台帳 / 逸脱の登録簿: **変更しない** (理由は「乖離台帳の確認」の節)

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (取り込み元が `BootProbeResult` を返す)
- [x] null 安全 (`Webmozart\Assert\Assert` を全面的に使用)
- [x] DTO を返している (`BootProbeResult` readonly)
- [x] Generics の型パラメータが正しい (`list<non-empty-string>` / `array<non-empty-string, string>`)
- 注: **aicue の PHPStan 解析対象に `tests` は含まれない**ので、これは「取り込み元が level 10 を通っている」
  ことの確認であり、aicue で level 10 が回るという主張ではない

### テスト計画

- [x] 新規テスト (取り込み): `tests/Unit/Support/Process/BootProbeRunnerTest.php` の S1〜S14 —
  親 env の非漏洩 / 継承規則 / ケース別上書きの勝ち / env 集合の完全一致 / 予約鍵の拒否 / 終了コードの保持 /
  制限時間と強制終了 / 大量出力で詰まらない / 書き出し先の向きと実体 / 起動前 fail-closed の残骸なし /
  管を閉じた子の回収 / 終了後の読み切りと上限 / 段階的強制終了
- [x] 既存テストの更新: なし
- [x] 個別の `DatabaseTransactions` を使っていないことを確認 (取り込み元も使っていない)

### リスク

- **`ext-pcntl` が無い環境**: S14 が `skip` になる (成功扱いにはならない)。aicue の devcontainer / CI は
  Linux で pcntl を持つ
- **将来のテンプレート更新との追従遅れ**: 指紋台帳を再生成しない限り検査上の食い違いは生じない。
  再判定の契機は「指紋台帳の世代を上げるとき」であり、そのときに 3 パスも一緒に見直す

---

## S2: 経路 1 の起こし手を runner へ載せ替える

### 変更箇所

- ファイル: `tests/Support/ExternalFakes/FakeWiringProbeRunner.php` (全 301 行)
  - **クラス docblock を全面的に書き直す** (下記「docblock の書き直し要件」)
  - `ALLOWED_ENV_FILE_KEYS` から `APP_KEY` を外す
  - `ALLOWED_PROCESS_ENV_KEYS` を `CASE_ENV_KEYS` へ改称・意味変更
  - `MARKER_RELATIVE_PATH` を新設
  - `run()` を `withEnvironmentDirectory()` + `interpret()` の構成へ組み替える
  - `Symfony\Component\Process\Process` の `use` を落とし、
    `Webmozart\Assert\Assert` / `Tests\Support\Process\BootProbeRunner` /
    `Tests\Support\Process\BootProbeResult` の `use` を足す
    (`FakeClassCatalog` は同一 namespace なので `use` は不要)

### 責務の再分割 (何を残し、何を委ねるか)

| 責務 | 載せ替え後の担い手 |
|------|------------------|
| 一時ディレクトリ 0700 / 環境ファイル 0600 の作成と権限の事前検査 | **`FakeWiringProbeRunner` に残す** (正典より締まった aicue 固有の強化) |
| 使い捨て鍵の生成 (`APP_KEY` / `CIPHERSWEET_KEY`) | **残す** |
| 環境ファイルの許可キーの deny-by-default | **残す** (ただし `APP_KEY` を外す。理由は下) |
| 子の起こし方 (実行体・環境の 3 段合成・作業ディレクトリ) | **`BootProbeRunner` へ委ねる** |
| 出力の逐次読み・制限時間・段階的強制終了・`proc_close` | **`BootProbeRunner` へ委ねる** |
| 書き出し先の退避 (7 キー) | **`BootProbeRunner` へ委ねる** (従来は `APP_CONFIG_CACHE` 1 キーのみ) |
| 子の出力の解釈 (fail-closed) | **残す** (`interpret()` として純関数に切り出す) |

### docblock の書き直し要件 (現行の説明は載せ替え後に**事実でなくなる**)

現行 docblock は「子の環境は `env -i` で空にしてから 3 件だけを載せる」と書いており、載せ替え後は嘘になる。
次の 5 点を書く:

1. **環境は 4 段**である — 継承 (`PATH` / `HOME` / `TMPDIR`) → 基底 (`APP_KEY` / `QUEUE_CONNECTION` /
   `CACHE_STORE`) → ケース別 (本クラスが渡す 3 件) → 予約 (書き出し先 7 キー。runner が決める)。
   統制点は `proc_open` へ渡す環境配列であり、開発者ローカルの env はここで締め出される
2. **鍵の置き場所が 2 つに分かれる** — `APP_KEY` は**ケース別上書き**、`CIPHERSWEET_KEY` は**環境ファイル**。
   理由 (Laravel の環境変数リポジトリは immutable でプロセス環境を上書きしない) まで書く
3. **一時ディレクトリが 2 つある** — 外側 (本クラスが作る環境ファイルの置き場。0700) と
   内側 (runner が作る書き出し先の退避先)。どちらもリポジトリ外で、どちらも必ず消える
4. **設定キャッシュの退避先は runner の予約鍵**であり、本クラスからは渡せない (渡すと例外)
5. **取り込んだ `BootProbeRunner` の docblock の訂正表** (S1 の表。不変条件番号 15 → 9 /
   `PhpLintOracle` のパス)。取り込みはバイト一致なので向こうは直せない

### 環境の 4 段の確定表 (実装者向けの正本)

| 段 | 出所 | 中身 |
|---|------|------|
| 1. 継承 | `BootProbeRunner::INHERITED_ENV_KEYS` | `PATH` / `HOME` / `TMPDIR` (親に非空で在るときだけ) |
| 2. 基底 | `BootProbeRunner::baseEnv()` | `APP_KEY` / `QUEUE_CONNECTION=database` / `CACHE_STORE=array` |
| 3. ケース別 | `FakeWiringProbeRunner::CASE_ENV_KEYS` | `FAKE_WIRING_PROBE_ENV_DIR` / `FAKE_WIRING_PROBE_ENV_FILE` / **`APP_KEY` (使い捨て)** |
| 4. 予約 | `BootProbeRunner::RESERVED_ENV_KEYS` | `LARAVEL_STORAGE_PATH` / `VIEW_COMPILED_PATH` / `APP_CONFIG_CACHE` / `APP_ROUTES_CACHE` / `APP_SERVICES_CACHE` / `APP_PACKAGES_CACHE` / `APP_EVENTS_CACHE` |

> **`APP_KEY` をケース別へ置く理由 (設計時に子プロセスで実測して確定した)**:
> Laravel の `Env::getRepository()` は **immutable** で、**プロセス環境に既に在る値を Dotenv は上書きしない**。
> runner の基底が `APP_KEY` を載せる以上、0600 の環境ファイルに書いた使い捨て鍵は**無視される**。
> 実測 — 環境ファイルに `APP_KEY=base64:ZmlsZS1r…` / プロセス環境に `APP_KEY=base64:cnVubmVy…` を置くと
> `config('app.key')` は**プロセス環境側**になった。同じ実測で、プロセス環境に無い `CIPHERSWEET_KEY` は
> **環境ファイルの値が効いた**。よって `APP_KEY` はケース別上書きへ移し、`CIPHERSWEET_KEY` は環境ファイルに残す。

> **`APP_CONFIG_CACHE` を渡してはならない**: runner の予約鍵なので、渡すと `run()` が例外にする。
> 退避は runner が一時ディレクトリ配下 (`bootstrap-cache/config.php`) へ向けて行う。

### 現行コード (抜粋)

```php
public const array ALLOWED_ENV_FILE_KEYS = [
    'APP_ENV', 'APP_KEY', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY',
    'TESTING_FAKE_EXTERNALS', 'TESTING_FAKE_STORAGE', 'TESTING_FAKE_LLM',
];

public const array ALLOWED_PROCESS_ENV_KEYS = [
    'FAKE_WIRING_PROBE_ENV_DIR', 'FAKE_WIRING_PROBE_ENV_FILE', 'APP_CONFIG_CACHE',
];

public static function run(
    string $environment, bool $fakeExternals, bool $fakeStorage, bool $fakeLlm,
    ?string $baseDirectory = null, float $timeout = 120.0,
): array {
    $base = $baseDirectory ?? sys_get_temp_dir();
    $directory = $base.'/fake-wiring-probe-'.bin2hex(random_bytes(8));
    if (! mkdir($directory, 0700) || ! is_dir($directory)) { /* 例外 */ }

    try {
        // …環境ファイル書き出し・権限検査…
        $configCachePath = $directory.'/config-cache-absent.php';
        $process = new Process(
            ['env', '-i', /* 3 キー */, PHP_BINARY, self::probeScriptPath()],
            FakeClassCatalog::repoRoot(), null, null, $timeout,
        );
        $process->run();

        return [
            'exitCode' => $process->getExitCode() ?? -1,
            'output' => self::decode($process->getOutput()),
            // …
        ];
    } finally {
        self::removeDirectory($directory);
    }
}
```

### 変更後コード

```php
use Tests\Support\Process\BootProbeResult;
use Tests\Support\Process\BootProbeRunner;

/**
 * 子が実働証明の印を書く先 (`storage_path()` からの相対パス)。
 *
 * ★正典 v1 (5) の実働証明の観測点。退避が効いていなければ印はリポジトリ側へ落ち、
 *   起動器の `writtenRelativePaths` に現れない = P-13 が赤になる。
 */
public const string MARKER_RELATIVE_PATH = 'app/private/fake-wiring-probe-marker.txt';

/**
 * 一時環境ファイルに書いてよいキー (deny-by-default)。
 *
 * ★`APP_KEY` は**ここに置けない**。Laravel の環境変数リポジトリは immutable で、
 *   プロセス環境に既に在る値を Dotenv は上書きしない。BootProbeRunner の基底が
 *   `APP_KEY` を載せる以上、ここへ書いても無視される (設計時に子プロセスで実測)。
 *   使い捨て `APP_KEY` は CASE_ENV_KEYS 側 (ケース別上書き) が運ぶ。
 *
 * @var list<string>
 */
public const array ALLOWED_ENV_FILE_KEYS = [
    'APP_ENV', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY',
    'TESTING_FAKE_EXTERNALS', 'TESTING_FAKE_STORAGE', 'TESTING_FAKE_LLM',
];

/**
 * BootProbeRunner へ渡す**ケース別上書き**のキー (正典 v1 (2) の第 3 段)。
 *
 * ★`TESTING_FAKE_*` はここに**無い**。偽物の宣言はプロセス環境へ 1 件も載せず、
 *   0600 の環境ファイルの中だけに置く (P-7 の危険接頭辞の禁止をそのまま維持する)。
 * ★`APP_CONFIG_CACHE` ほかの書き出し先は runner の**予約鍵**なので渡さない (渡すと例外)。
 * ★この一覧は P-7 がリテラルで完全一致 pin する (増やすと赤になる)。
 *
 * @var list<string>
 */
public const array CASE_ENV_KEYS = [
    'FAKE_WIRING_PROBE_ENV_DIR',
    'FAKE_WIRING_PROBE_ENV_FILE',
    'APP_KEY',
];

/**
 * 環境ファイルの置き場所を 0700 で用意し、**本体がどう終わっても必ず消す**足場。
 *
 * ★`run()` の `finally` をここへ切り出したのは、**後始末そのものを検査から直接呼べるように**
 *   するためである (P-10c)。制限時間超過の経路は「`interpret()` が例外を投げる」(P-15) と
 *   「本体が例外を投げれば中身ごと消える」(P-10c) の合成で覆う。
 *   **プロセスの挙動を偽装する注入の継ぎ目ではない** — 起こし方も回収も BootProbeRunner のままである。
 *
 * ★**リポジトリの中には作らない** (正典 v1 (5) の fail-closed)。内側の退避先は
 *   BootProbeRunner が同じ検査を持つが、外側 (この環境ファイルの置き場) にも同じ境界が要る。
 *   判定は BootProbeRunner::isInside() を使う (境界規則を 2 か所で持たない)。
 * ★権限は callback を呼ぶ**前に**実効値で確かめる。どの失敗でも作った置き場所を消してから投げる。
 *
 * @template T
 * @param  callable(string): T  $body  引数は作った置き場所の絶対パス
 * @return T
 */
public static function withEnvironmentDirectory(?string $baseDirectory, callable $body): mixed
{
    $base = $baseDirectory ?? sys_get_temp_dir();

    Assert::startsWith($base, DIRECTORY_SEPARATOR, '観測用の置き場所は絶対パスであること');
    Assert::directory($base);
    Assert::writable($base);

    $created = rtrim($base, DIRECTORY_SEPARATOR).'/fake-wiring-probe-'.bin2hex(random_bytes(8));

    if (! mkdir($created, 0700) || ! is_dir($created)) {
        throw new RuntimeException("観測用の一時ディレクトリを作れない: {$created}");
    }

    try {
        $directory = realpath($created);
        if (! is_string($directory) || $directory === '') {
            throw new RuntimeException("観測用の一時ディレクトリを正規化できない: {$created}");
        }

        // 正典 (5) の fail-closed。ここを緩めると環境ファイルがリポジトリへ落ちる。
        // ★両辺とも realpath 済みで比べる (FakeClassCatalog::repoRoot() は dirname() の結果で
        //   正規化されていないため、symlink 越しだと素の比較が取り違える)。
        $repositoryRoot = realpath(FakeClassCatalog::repoRoot());
        if (! is_string($repositoryRoot) || $repositoryRoot === '') {
            throw new RuntimeException('リポジトリ root を正規化できない');
        }

        if (BootProbeRunner::isInside($repositoryRoot, $directory)) {
            throw new RuntimeException(
                "観測用の一時ディレクトリがリポジトリ内にある: {$directory}"
            );
        }

        // 実効の権限で確かめる (chmod の戻り値だけでは umask 等の影響を捕まえられない)。
        if (! chmod($directory, 0700) || self::mode($directory) !== 0700) {
            throw new RuntimeException("観測用の一時ディレクトリを 0700 にできない: {$directory}");
        }

        return $body($directory);
    } finally {
        self::removeDirectory($created);
    }
}

/**
 * 観測を 1 回走らせる。
 *
 * @param  positive-int  $timeoutSeconds
 * @return array{
 *     exitCode: int,
 *     output: array<string, mixed>,
 *     envFileValues: array<string, string>,
 *     caseEnvValues: array<string, string>,
 *     directory: string,
 *     directoryMode: int,
 *     envFileMode: int,
 *     temporaryRoot: string,
 *     writtenRelativePaths: list<string>,
 * }
 */
public static function run(
    string $environment,
    bool $fakeExternals,
    bool $fakeStorage,
    bool $fakeLlm,
    ?string $baseDirectory = null,
    int $timeoutSeconds = 120,
): array {
    // 置き場所の作成・リポジトリ外の fail-closed・0700 の確認・後片付けは helper が持つ。
    return self::withEnvironmentDirectory(
        $baseDirectory,
        static function (string $directory) use ($environment, $fakeExternals, $fakeStorage, $fakeLlm, $timeoutSeconds): array {
            $values = self::envFileValues($environment, $fakeExternals, $fakeStorage, $fakeLlm);
            $envFilePath = $directory.'/'.self::ENV_FILE_NAME;
            self::writeEnvFile($envFilePath, $values);

            $directoryMode = self::mode($directory);
            $envFileMode = self::mode($envFilePath);
            self::assertSafePermissions($directoryMode, $envFileMode);

            $caseEnv = self::caseEnvValues($directory);

            // 子の起こし方・回収・書き出し先の退避は共通 runner が持つ
            // (lctl feature: subprocess-boot-probe-harness の正典 v1 (1)〜(5))。
            $result = BootProbeRunner::run([self::probeScriptPath()], $caseEnv, $timeoutSeconds);

            return self::interpret($result, $values, $caseEnv, $directory, $directoryMode, $envFileMode);
        },
    );
}

/**
 * ケース別上書きの中身 (使い捨て鍵はここで作る)。
 *
 * @return array<string, string>
 */
public static function caseEnvValues(string $directory): array
{
    $values = [
        'FAKE_WIRING_PROBE_ENV_DIR' => $directory,
        'FAKE_WIRING_PROBE_ENV_FILE' => self::ENV_FILE_NAME,
        // 実鍵は複写せず、起動のたびに使い捨ての値を生成する。
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    ];

    foreach (array_keys($values) as $key) {
        if (! in_array($key, self::CASE_ENV_KEYS, true)) {
            throw new RuntimeException("ケース別上書きに置けないキー: {$key}");
        }
    }

    return $values;
}

/**
 * runner の結果を観測結果へ翻訳する (**純関数**。子を起こさずに負例を測れる)。
 *
 * ★fail-closed を 4 つ持つ:
 *   1. 制限時間超過 (`timedOut`) は**通常の非ゼロ終了と区別して例外**にする。
 *      false や非ゼロ終了へ落とすと「観測できなかった」ことが沈黙する (fail-open)
 *   2. 出力が空 → 例外 (観測が成立していない)
 *   3. JSON として読めない → 例外
 *   4. トップレベルが配列でない → 例外
 * ★判定には `timedOut` を使い、`exitCode === 124` を直接読まない
 *   (終了要求を受けてから自分で `exit(0)` する子は `timedOut` かつ `exitCode === 0` になりうる)。
 *
 * @param  array<string, string>  $envFileValues
 * @param  array<string, string>  $caseEnv
 * @return array{
 *     exitCode: int, output: array<string, mixed>, envFileValues: array<string, string>,
 *     caseEnvValues: array<string, string>, directory: string, directoryMode: int,
 *     envFileMode: int, temporaryRoot: string, writtenRelativePaths: list<string>,
 * }
 */
public static function interpret(
    BootProbeResult $result,
    array $envFileValues,
    array $caseEnv,
    string $directory,
    int $directoryMode,
    int $envFileMode,
): array {
    if ($result->timedOut) {
        throw new RuntimeException(
            '観測用の子プロセスが制限時間を超えて強制終了された (観測が成立していない)。'
            ."終了コード: {$result->exitCode} / 標準エラー: ".$result->stderr
        );
    }

    return [
        'exitCode' => $result->exitCode,
        'output' => self::decode($result->stdout),
        'envFileValues' => $envFileValues,
        'caseEnvValues' => $caseEnv,
        'directory' => $directory,
        'directoryMode' => $directoryMode,
        'envFileMode' => $envFileMode,
        'temporaryRoot' => $result->temporaryRoot,
        'writtenRelativePaths' => $result->writtenRelativePaths,
    ];
}
```

`envFileValues()` からは `APP_KEY` の行を削る。
`decode()` / `writeEnvFile()` / `mode()` / `assertSafePermissions()` / `probeScriptPath()` /
`probeAppHost()` / `removeDirectory()` は**現行のまま**。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Architecture/ExternalFakeBootProbeTest.php` (S4 で扱う)。
  `FakeWiringProbeRunner` を参照する他のファイルは無い (設計時に実測: 参照は同 gate 1 本のみ)
- 削除される公開面: `ALLOWED_PROCESS_ENV_KEYS` (→ `CASE_ENV_KEYS`)。参照元は P-7 のみなので S4 で追随する

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (完全な array shape を PHPDoc で固定)
- [x] null 安全 (`?? -1` のような黙った既定値を持たない。`timedOut` は必ず例外へ)
- [x] 配列返却について: **プロセス実行結果の境界は `BootProbeResult` に統一**した。
      子の JSON payload だけが `array<string, mixed>` として残り、それは呼び出し側 gate が
      各キーを検証してから使う (「無型の配列が 1 つも無い」とは主張しない)
- [x] Generics の型パラメータが正しい (`array<string, string>` / `list<string>` /
      `withEnvironmentDirectory()` は `@template T` で戻り値を素通しする)

### テスト計画

S4 に集約する (呼び出し側 gate が唯一の利用者であるため)。

### リスク

- **`float $timeout` → `int $timeoutSeconds` の型変更**: `BootProbeRunner::run()` が `positive-int` を要求するため。
  影響を受ける呼び出しは P-10 の `0.01` 1 か所だけで、S4 で扱う
- **観測が変わらないことの確認**: 基底の `QUEUE_CONNECTION=database` / `CACHE_STORE=array` が
  観測 (container の解決結果・転送先 host) を変えないこと。**P-1 / P-2 / P-3 が主張を変えずに
  緑のままであること**がその実測になる

---

## S3: 子入口スクリプトへ実働証明の観測点を足す

### 変更箇所

- ファイル: `tests/Support/ExternalFakes/fake-wiring-probe.php`
  - `use Tests\Support\ExternalFakes\FakeWiringProbeRunner;` を**足す**
  - 先頭コメントの「責務は 4 つだけ」を**書き直す** (下記)
  - `bootstrap()` 直後に実働証明の印を書く
  - 出力 JSON へ `write_targets` / `key_digests` を足す

### 先頭コメントの書き直し要件

現行は「責務は 4 つだけ (DB へ接続しない / container から解決する / 転送先 URL を組み立てて読む /
終了コードを返す)」と書いているが、観測点が増えるので事実でなくなる。**責務 6 つ**へ改める:

1. DB へ接続しない
2. container から解決する
3. 転送先 URL を組み立てて読む (**偽物が有効なときだけ**)
4. **実働証明の印を `storage_path()` 経由で 1 本書く** (正典 v1 (5))
5. **起動しきったアプリが解決した書き出し先 8 種と、効いた鍵 2 種の digest を報告する**
6. 終了コードを返す

**観測しないもの**: HTTP サーバもブラウザも起動しない / 設定キャッシュ**有り**の起動は観測しない /
外部へ 1 度も通信しない (転送先は組み立てて URL を読むだけ)。

### 現行コード (抜粋)

```php
$app->make(Kernel::class)->bootstrap();

$resolved = [];
foreach (ExternalFakeDeclaration::swaps() as $swap) {
    $resolved[$swap->abstract] = $app->make($swap->abstract)::class;
}
// …
fwrite(STDOUT, json_encode([
    'resolved' => $resolved,
    'redirect_host' => $redirectHost,
    'process_environment_keys' => $processEnvironmentKeys,
], JSON_THROW_ON_ERROR));
```

### 変更後コード

```php
use Tests\Support\ExternalFakes\FakeWiringProbeRunner;

// …

$app->make(Kernel::class)->bootstrap();

/*
 * ★正典 v1 (5) の**実働証明**の観測点 (lctl feature: subprocess-boot-probe-harness)。
 *   「書き出し先を環境変数で退避した」ことは、退避が**効いていなければ**既定の場所
 *   (リポジトリの storage/) へ書かれ、観測は緑のまま嘘になる。そこで
 *   Laravel の storage_path() 経由で印を 1 本置き、それが起動器の一時ディレクトリ配下に
 *   現れたことを呼び出し側 (P-13) が確かめる。
 *   置き場所 (storage/app/private) は起動器が事前に掘っている。
 */
$markerPath = $app->storagePath(FakeWiringProbeRunner::MARKER_RELATIVE_PATH);
if (file_put_contents($markerPath, 'fake-wiring-probe') === false) {
    throw new RuntimeException("観測の印を書けない: {$markerPath}");
}

// …resolved / redirectHost の観測 (現行のまま) …

fwrite(STDOUT, json_encode([
    'resolved' => $resolved,
    'redirect_host' => $redirectHost,
    'process_environment_keys' => $processEnvironmentKeys,
    // ★P-14 (向き): 起動しきったアプリが解決した書き出し先。呼び出し側が
    //   「1 件残らず一時ディレクトリ配下で、リポジトリの外」であることを確かめる。
    'write_targets' => [
        'storage' => $app->storagePath(),
        'config_cache' => $app->getCachedConfigPath(),
        'routes_cache' => $app->getCachedRoutesPath(),
        'services_cache' => $app->getCachedServicesPath(),
        'packages_cache' => $app->getCachedPackagesPath(),
        'events_cache' => $app->getCachedEventsPath(),
        'view_compiled' => (string) config('view.compiled'),
        'log_path' => (string) config('logging.channels.single.path'),
    ],
    // ★P-8 (使い捨て鍵が子で効いたこと)。鍵そのものは出力しない (テスト出力へ鍵を流さない)。
    'key_digests' => [
        'app' => hash('sha256', (string) config('app.key')),
        'ciphersweet' => hash('sha256', (string) config('ciphersweet.providers.string.key')),
    ],
], JSON_THROW_ON_ERROR));
```

> **`echo` を使わない**: AGENTS.md の禁止する文の規約により `fwrite(STDOUT, …)` で書く (現行と同じ)。
> **例外は既存の `catch (Throwable $e)` が拾う**ので、印が書けなければ JSON の `error` として出て
> 非ゼロ終了になる (沈黙しない)。

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: S4 が新しいキー (`write_targets` / `key_digests`) を読む

### PHPStan適合チェック

- 対象外 (`tests/` は aicue の解析対象に含まれない)。`Webmozart\Assert\Assert` による実行時検証は現行のまま

### テスト計画

S4 に集約する。

### リスク

- **`production` ケースでは印が書かれない**: bootstrap が `ProductionEnvGuard` で落ちるため
  (印を書く行は `bootstrap()` の後に置く)。P-13 / P-14 / P-8 は `fake` ケースだけで測る
- **`storage/app/private` が掘られていること**への依存: runner の `createTemporaryRoot()` が
  掘る 6 つの下位のうちの 1 つ。掘られなくなれば `file_put_contents` が false を返して**例外**になる
  (fail-closed。沈黙しない)

---

## S4: 呼び出し側 gate を新契約へ揃え、正典 (5) の実働証明を足す

### 変更箇所

- ファイル: `tests/Architecture/ExternalFakeBootProbeTest.php`
  - L7 の `use Symfony\Component\Process\Exception\ProcessTimedOutException;` を削除し、
    `use Tests\Support\Process\BootProbeResult;` / `use Tests\Support\Process\BootProbeRunner;` を足す
  - **先頭 docblock を書き直す** (現行の「`env -i` で空にし、鍵 2 つは環境ファイルの使い捨て値」は
    載せ替え後に事実でなくなる。4 段の環境合成 / 鍵の置き場所が 2 つに分かれること / 書き出し先 7 キーの
    退避 / 実働証明を P-13・P-14 が持つこと、へ改める)
  - `externalFakeProbeRun()` の戻り値 shape を更新
  - **P-7 / P-8 / P-11 を書き換え**、**P-10 を 4 本へ分割**、**P-13 / P-14 / P-15 を追加**
  - **P-1〜P-6 / P-9 / P-12 は 1 文字も変えない**

### 検査ごとの扱い (主張の増減を明示する)

| 検査 | 扱い | 内容 |
|------|------|------|
| P-1〜P-6 / P-9 / P-12 | **変更なし** | 偽物/本物の厳密一致・転送先ホスト・production の起動失敗・fail-closed・環境ファイルの許可集合・権限と負のコントロール・宣言の型 |
| P-7 | **書き換え (同じ主張を新しい土台で) + 定数の pin を追加** | 子が受け取ったプロセス環境の集合が `継承(実在分) + 基底 3 + ケース別 3 + 予約 7` と**完全一致**し、危険接頭辞が 1 件も無い。**併せて `INHERITED_ENV_KEYS` / `RESERVED_ENV_KEYS` / `CASE_ENV_KEYS` をリテラルで完全一致 pin する** |
| P-8 | **強化** | 起動側の配列ではなく、**子で実際に効いた** `app.key` / `ciphersweet` の digest が、生成した使い捨て値と一致し、親の設定値とは一致しない |
| P-10 | **分割 (4 本)** | P-10 = 正常終了・非ゼロ終了で置き場所が残らない / P-10b = 作れない置き場所では子を起こさずに失敗し残骸なし / **P-10c = 本体が例外を投げても中身ごと消える** / **P-10d = リポジトリ内の置き場所は本体を呼ばずに拒否し残骸なし** |
| P-11 | **書き換え (同じ主張を新しい土台で)** | 設定キャッシュの退避先が runner の一時ディレクトリ配下で、**書かれていない** |
| P-13 | **追加 (正典 (5) の実働証明・実体)** | 子が `storage_path()` 経由で書いた印が `writtenRelativePaths` に現れる |
| P-14 | **追加 (正典 (5) の実働証明・向き)** | 子が解決した書き出し先 8 種が 1 件残らず一時ディレクトリ配下で、`base_path()` の外 |
| P-15 | **追加 (fail-closed の負例。子を起こさない)** | `interpret()` が `timedOut` / 空出力 / 非 JSON / 非配列 JSON で例外になる |

> **制限時間超過の後始末をどう覆うか (Round 1 の [Critical] への回答)**:
> 旧 P-10 は「timeout でも**外側**の置き場所が消える」ことを実 timeout (0.01 秒) で測っていた。
> `BootProbeRunner` の制限時間は `positive-int` (最小 1 秒) で、子の起動は実測 **0.28〜0.32 秒**なので、
> 呼び出し側から実 timeout を再現するには観測用スクリプトへ「眠る分岐」を足すことになり、
> **観測の責務を汚す**。そこで**後始末の足場そのもの (`withEnvironmentDirectory()`) を直接呼ぶ** P-10c を置く。
> timeout 経路がこの `finally` を通ることは、
> **P-15 (`interpret()` が `timedOut` で例外を投げる)** と **P-10c (本体の例外で置き場所が消える)** の
> **合成**で示す。制限時間と段階的強制終了そのものの実プロセス実測は、取り込んだ自己検査
> S7 / S12 / S14 が持つ (子を 30 秒眠らせて 1 秒で落とす形)。

### 変更後コード (主要部)

```php
test('P-7 子が実際に受け取ったプロセス環境が 4 段の合成結果と完全一致する', function (): void {
    // (0) 4 段の定数そのものをリテラルで pin する。実装側の定数から期待値を組み立てるだけだと、
    //     実装と期待値を同時に変えたときに緑のまま通ってしまう。
    expect(BootProbeRunner::INHERITED_ENV_KEYS)->toBe(['PATH', 'HOME', 'TMPDIR'])
        ->and(BootProbeRunner::RESERVED_ENV_KEYS)->toBe([
            'LARAVEL_STORAGE_PATH',
            'VIEW_COMPILED_PATH',
            'APP_CONFIG_CACHE',
            'APP_ROUTES_CACHE',
            'APP_SERVICES_CACHE',
            'APP_PACKAGES_CACHE',
            'APP_EVENTS_CACHE',
        ])
        ->and(FakeWiringProbeRunner::CASE_ENV_KEYS)->toBe([
            'FAKE_WIRING_PROBE_ENV_DIR',
            'FAKE_WIRING_PROBE_ENV_FILE',
            'APP_KEY',
        ]);

    $run = externalFakeProbeRun('fake');
    $keys = $run['output']['process_environment_keys'] ?? null;
    expect($keys)->toBeArray();
    /** @var list<mixed> $keys */
    $actual = array_map(static fn (mixed $key): string => (string) $key, $keys);

    // (a) 危険な接頭辞が 1 件も無いこと (env -i の時代からの主張をそのまま維持する)。
    //     TESTING_FAKE_* は**プロセス環境へ載せない** (0600 の環境ファイルの中だけに置く)。
    foreach (['DB_', 'PG', 'AWS_', 'STRIPE_', 'TESTING_FAKE_', 'GOOGLE_'] as $prefix) {
        $leaked = array_values(array_filter(
            $actual,
            static fn (string $key): bool => str_starts_with($key, $prefix)
        ));
        expect($leaked)->toBe([], "禁止する接頭辞 {$prefix} のキーが子へ流れている");
    }

    // (b) 集合の完全一致 (deny-by-default)。「以下」ではないので 1 本足しただけで赤くなる。
    $inherited = array_values(array_filter(
        ['PATH', 'HOME', 'TMPDIR'],
        static function (string $key): bool {
            $value = getenv($key);

            return is_string($value) && $value !== '';
        },
    ));
    $expected = array_values(array_unique(array_merge(
        $inherited,
        ['APP_KEY', 'QUEUE_CONNECTION', 'CACHE_STORE'],
        ['FAKE_WIRING_PROBE_ENV_DIR', 'FAKE_WIRING_PROBE_ENV_FILE', 'APP_KEY'],
        ['LARAVEL_STORAGE_PATH', 'VIEW_COMPILED_PATH', 'APP_CONFIG_CACHE',
            'APP_ROUTES_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_EVENTS_CACHE'],
    )));
    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

test('P-8 使い捨て鍵が子で実際に効き、親の設定値の複写ではない', function (): void {
    $run = externalFakeProbeRun('fake');

    $digests = $run['output']['key_digests'] ?? null;
    expect($digests)->toBeArray();
    /** @var array<string, mixed> $digests */

    // (a) 子で効いた APP_KEY が、起動側が生成した使い捨て値と一致する
    expect($digests['app'] ?? null)->toBe(hash('sha256', $run['caseEnvValues']['APP_KEY']));
    // (b) 子で効いた CIPHERSWEET_KEY が、環境ファイルへ書いた使い捨て値と一致する
    expect($digests['ciphersweet'] ?? null)->toBe(hash('sha256', $run['envFileValues']['CIPHERSWEET_KEY']));
    // (c) いずれも親の設定値の複写ではない
    expect($digests['app'])->not->toBe(hash('sha256', (string) config('app.key')))
        ->and($digests['ciphersweet'])
        ->not->toBe(hash('sha256', (string) config('ciphersweet.providers.string.key')));
});

test('P-10 正常終了・非ゼロ終了のいずれでも環境ファイルの置き場所が残らない', function (): void {
    foreach (['fake', 'real', 'production'] as $case) {
        $run = externalFakeProbeRun($case);

        expect(is_dir($run['directory']))->toBeFalse("一時ディレクトリが残っている: {$case}")
            ->and(array_values(array_diff(scandir($run['baseDirectory']) ?: [], ['.', '..'])))
            ->toBe([], "一時ディレクトリの親に残骸がある: {$case}");
    }
});

test('P-10b 作れない置き場所では子を起こさずに失敗し、残骸を残さない', function (): void {
    $base = sys_get_temp_dir().'/fake-wiring-probe-readonly-'.bin2hex(random_bytes(6));
    expect(mkdir($base, 0500))->toBeTrue();

    try {
        expect(fn (): array => FakeWiringProbeRunner::run('bughunt.local', true, true, false, $base))
            ->toThrow(RuntimeException::class);

        expect(array_values(array_diff(scandir($base) ?: [], ['.', '..'])))->toBe([]);
    } finally {
        rmdir($base);
    }
})->skip(
    // root で走ると 0500 でも書けてしまい、負のコントロールが成立しない。
    // **成功扱いにはしない** — 測れていないことをテスト結果に出す。
    fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0,
    'root では書き込み権限の負のコントロールを作れない',
);

test('P-10c 本体が例外を投げても置き場所が中身ごと消える (制限時間超過の後始末)', function (): void {
    // 制限時間超過は interpret() が例外にする (P-15)。その例外が外側の finally を通ることを
    // ここで決定的に測る (実 timeout を作るには子を 1 秒以上眠らせる必要があり、
    // それは観測用スクリプトの責務を汚すので採らない)。
    // ★空のディレクトリではなく**中身のある**状態で測る — 実際の制限時間超過では
    //   .env.probe が既に書かれているので、再帰削除まで示さないと主張と距離がある。
    $base = sys_get_temp_dir().'/fake-wiring-probe-base-'.bin2hex(random_bytes(6));
    expect(mkdir($base, 0700))->toBeTrue();

    $created = null;

    try {
        expect(function () use ($base, &$created): mixed {
            return FakeWiringProbeRunner::withEnvironmentDirectory(
                $base,
                static function (string $directory) use (&$created): mixed {
                    $created = $directory;

                    // 実際の走行と同じく環境ファイルを置き、さらに下位ディレクトリの中にも番兵を置く。
                    expect(file_put_contents($directory.'/.env.probe', "APP_ENV=x\n"))->not->toBeFalse();
                    expect(mkdir($directory.'/nested', 0700))->toBeTrue();
                    expect(file_put_contents($directory.'/nested/sentinel.txt', 'x'))->not->toBeFalse();

                    throw new RuntimeException('本体の失敗');
                },
            );
        })->toThrow(RuntimeException::class);

        // 置き場所は作られ (= 検査が空振りしていない)、中身ごと消えている。
        expect($created)->toBeString()
            ->and(is_dir((string) $created))->toBeFalse('置き場所が残っている')
            ->and(array_values(array_diff(scandir($base) ?: [], ['.', '..'])))->toBe([]);
    } finally {
        rmdir($base);
    }
});

test('P-10d リポジトリ内の置き場所は本体を呼ばずに拒否し、残骸を残さない', function (): void {
    // 正典 v1 (5) の fail-closed を**外側**でも測る (内側は取り込んだ自己検査 S11 が持つ)。
    $base = base_path('storage/framework/testing');
    if (! is_dir($base)) {
        mkdir($base, 0755, true);
    }

    $before = glob($base.'/fake-wiring-probe-*');
    expect($before)->toBeArray();

    $bodyCalled = false;

    expect(function () use ($base, &$bodyCalled): mixed {
        return FakeWiringProbeRunner::withEnvironmentDirectory(
            $base,
            static function (string $directory) use (&$bodyCalled): mixed {
                $bodyCalled = true;

                return $directory;
            },
        );
    })->toThrow(RuntimeException::class);

    expect($bodyCalled)->toBeFalse('リポジトリ内なのに本体が呼ばれた')
        ->and(glob($base.'/fake-wiring-probe-*'))->toBe($before, '拒否経路が残骸を残している');
});

test('P-11 設定キャッシュの退避先が一時ディレクトリ配下で、書かれていない', function (): void {
    $run = externalFakeProbeRun('fake');

    $targets = $run['output']['write_targets'] ?? null;
    expect($targets)->toBeArray();
    /** @var array<string, mixed> $targets */
    $configCache = $targets['config_cache'] ?? null;
    expect($configCache)->toBeString();
    /** @var string $configCache */

    expect(BootProbeRunner::isInside($run['temporaryRoot'], $configCache))->toBeTrue()
        // 設定キャッシュ**無し**の起動を観測している (書かれていたら前提が崩れている)。
        ->and($run['writtenRelativePaths'])->not->toContain('bootstrap-cache/config.php');
});

test('P-13 実働証明(実体): 子が storage_path() 経由で書いた印が一時ディレクトリ配下に現れる', function (): void {
    $run = externalFakeProbeRun('fake');

    expect($run['writtenRelativePaths'])
        ->toContain('storage/'.FakeWiringProbeRunner::MARKER_RELATIVE_PATH);
});

test('P-14 実働証明(向き): 子が解決した書き出し先が 1 件残らず一時ディレクトリ配下でリポジトリの外', function (): void {
    $run = externalFakeProbeRun('fake');

    $targets = $run['output']['write_targets'] ?? null;
    expect($targets)->toBeArray();
    /** @var array<string, mixed> $targets */

    $repositoryRoot = realpath(base_path());
    expect($repositoryRoot)->toBeString();
    /** @var string $repositoryRoot */

    $expectedKeys = ['storage', 'config_cache', 'routes_cache', 'services_cache',
        'packages_cache', 'events_cache', 'view_compiled', 'log_path'];
    expect(array_keys($targets))->toBe($expectedKeys, '観測点の集合が変わっている');

    foreach ($expectedKeys as $key) {
        $path = $targets[$key];
        expect($path)->toBeString();
        /** @var string $path */

        // 区切り文字を境界にした配下判定 (素の前方一致は /a と /ab を取り違える)。
        // isInside は同一パスも true にするので、base_path() 自身も「外ではない」に入る。
        expect(BootProbeRunner::isInside($run['temporaryRoot'], $path))
            ->toBeTrue("書き出し先 {$key} が一時ディレクトリの外を指している: {$path}")
            ->and(BootProbeRunner::isInside($repositoryRoot, $path))
            ->toBeFalse("書き出し先 {$key} がリポジトリ側を指している: {$path}");
    }
});

test('P-15 fail-closed: interpret() は観測が成立していない結果を沈黙させない', function (): void {
    $make = static fn (string $stdout, bool $timedOut, int $exitCode): BootProbeResult
        => new BootProbeResult(
            stdout: $stdout, stderr: '', exitCode: $exitCode, timedOut: $timedOut,
            elapsedSeconds: 0.1, temporaryRoot: '/tmp/boot-probe-x',
            writtenRelativePaths: [], pid: 1,
        );

    $call = static fn (BootProbeResult $result): array => FakeWiringProbeRunner::interpret(
        $result, [], [], '/tmp/dir', 0700, 0600,
    );

    // (a) 制限時間超過は通常の非ゼロ終了と区別して例外にする (fail-open 防止)
    expect(fn (): array => $call($make('{"resolved":{}}', true, 124)))->toThrow(RuntimeException::class);
    // (b) 空出力 / (c) JSON でない / (d) トップレベルが配列でない
    expect(fn (): array => $call($make('', false, 0)))->toThrow(RuntimeException::class);
    expect(fn (): array => $call($make('not json', false, 0)))->toThrow(RuntimeException::class);
    expect(fn (): array => $call($make('"scalar"', false, 0)))->toThrow(RuntimeException::class);
});
```

`externalFakeProbeRun()` の shape 更新 (`configCachePath` / `configCacheExists` を落とし、
`caseEnvValues` / `temporaryRoot` / `writtenRelativePaths` を足す) と `afterAll` の後片付けは現行のまま。

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本ファイルのみ。`FakeWiringProbeRunner` の他の利用者は無い

### PHPStan適合チェック

- 対象外 (`tests/`)。ただし `output` の各キーは `expect()->toBeArray()` / `toBeString()` で
  型を確かめてから使う (現行の `externalFakeProbeResolved()` と同じ作法)

### テスト計画

- [x] バグ修正ではないので再現テストは不要。**載せ替え前に赤を作る**手順は「実装順 (fail-first)」に書く
- [x] 既存テスト `tests/Architecture/ExternalFakeBootProbeTest.php` の更新 (上表のとおり)
- [x] 新規: P-10b / P-10c / P-10d / P-13 / P-14 / P-15
- [x] 個別の `DatabaseTransactions` を使っていないことを確認 (本ファイルは DB を張らない)

### リスク

- **`root` で走らせると P-10b が `skip`**: 成功扱いにはならない (測れていないことがテスト結果に出る)
- **`write_targets` のキー集合を pin している**: 観測点を足したら赤になる = 意図しない拡張が黙って通らない

---

## S5: 経路 2 を**載せ替えない**理由を docblock へ明記する

### 判断とその根拠 (概念設計からの変更点)

概念設計は「経路 2 (`StrictTypesRuntimeProbe`) も共通 runner へ載せ替える」としていたが、**撤回する**。

| 根拠 | 内容 |
|------|------|
| 正典の boundary | 正典が測るのは「**起動順序**に由来する壊れ方」である。`declare(strict_types=1)` の実効性は単一ファイルのコンパイル指令であり、アプリの起動順序ではない |
| 無関係な前提が付く | runner に載せると Laravel 固有の基底環境 (`QUEUE_CONNECTION` / `CACHE_STORE`)・書き出し先 7 キーの予約・一時ディレクトリの構築・作業ディレクトリのリポジトリ root 固定という、検体の判定に無関係な前提が付く |
| `PhpLintOracle` との一貫性 | `tests/Support/GlobalUse/PhpLintOracle.php` も「PHP を子で起こすがアプリは起こさない」経路で、載せ替えない。片方だけ載せる根拠が無い |
| 意味が変わる | 現行の Symfony `Process` は親環境を継承するが、載せ替えると許可一覧のみになる。23 検体が通ることは、この意味変更が安全である証明にならない |
| **家系の先例** | **正典テンプレートは子プロセス起動 4 経路のうちアプリを起こす 3 本だけを載せ替え、残る 1 本 (`tests/Feature/Queue/QueueWorkerLeaseGuardTest.php`) は「載せ替えない理由を docblock へ明記」して残している** |

aicue で**アプリを起こす経路は経路 1 の 1 本だけ**なので、テンプレートと同じ捌き方で経路 2 を残す。

### 変更箇所

- ファイル: `tests/Support/StrictTypesRuntimeProbe.php` — **クラス docblock に 1 節足すだけ**。
  実装コードは 1 行も変えない

```php
 * ★**共通の起動器 (Tests\Support\Process\BootProbeRunner) には載せない**
 *   (lctl feature: subprocess-boot-probe-harness)。あちらが測るのは「**起動順序**に由来する
 *   壊れ方」であり、本クラスが測るのは単一ファイルのコンパイル指令が効くかである。
 *   載せるとアプリ起動用の基底環境・書き出し先 7 キーの予約・一時ディレクトリの構築という
 *   検体の判定に無関係な前提が付く。同じ理由で `tests/Support/GlobalUse/PhpLintOracle.php`
 *   (`php -l` の真値取り出し) も載せていない。
 *   ★回収は Symfony の Process に委ねる (既定の制限時間つきで、超過すれば例外になる)。
```

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし / テストファイル: なし (実装を変えないため)

### PHPStan適合チェック

- 対象外 (`tests/`)。コード変更が無いので現状維持

### テスト計画

- [x] 既存テスト `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php` の 4 本は**主張も実装も変えない**
- [x] 新規テストなし (docblock のみの変更のため)。**この判断そのものは S6 の軸 A の申告
      (`launches_app: false` + 理由) として機械に登録される** = AGENTS.md 禁止事項 1 を満たす

### リスク

- なし (実行されるコードを変更しない)

---

## S6: 一元化に関する退行を検出する全数申告 gate を新設する

### 位置付け (再掲)

**正典 v1 の 6 不変条件のいずれでもない。aicue 側の上積みである。**
根拠は (a) 正典テンプレートも同型の gate を本 feature の追従で新設している、
(b) AGENTS.md 禁止事項 1 が不変条件のテスト登録を要求している、の 2 点。

### 変更箇所

- ファイル: `tests/Architecture/PhpBootProbeReferenceInventoryTest.php` (新規)

### なぜテンプレートと同じパス・同じ実装にしないか

| 論点 | 判断 |
|------|------|
| パス名 | テンプレートの `tests/Architecture/SubprocessProbeLaunchGateTest.php` は**テンプレートの指紋台帳に登録済みの共有パス**である (設計時に実測)。そこへ aicue 固有の申告内容を置くと共有パスに食い違う内容が乗り、将来の指紋台帳の再生成で意図的逸脱の登録が必要になる。**aicue 既存の命名 (`FfmpegProcessLaunchInventoryTest`) に倣った固有名**を使う |
| 走査器 | テンプレートは `nikic/php-parser` の `NameResolver` を使うが、**aicue は php-parser を直接依存に持たず、アプリ・テストのどこでも使っていない** (vendor には larastan 経由で在るだけ。設計時に実測)。aicue の静的走査の基盤は `tests/Support/PhpTokenScan.php` (`token_get_all` の正規化) と `tests/Support/TrackedPhpSourceFiles.php` (git 追跡下の列挙) である |
| 走査対象 | **名前解決を要する判定 (どのクラスの `new` か / `proc_open` の別名 import か) を一切しない**。字句走査で決定できるのは「定数 `PHP_BINARY` を参照しているか」「文字列に特定のパスが現れるか」までであり、そこに主張を閉じる |

### 3 つの軸 (いずれも `PhpTokenScan::normalize()` の上に建てる)

| 軸 | 判定 | 走査後の実測 (載せ替え後) |
|---|------|------------------------|
| **軸 A (起動能力)** | `T_STRING` かつ text が `PHP_BINARY` のトークンを持つ。**または** `use` `const` の並びに `PHP_BINARY` が現れる (別名 import の fail-closed) | 7 ファイル |
| **軸 B (アプリの起動点)** | 文字列トークン (`T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE`) の値が `bootstrap/app.php` を含む | 5 ファイル |
| **軸 C (子入口の参照)** | 文字列トークンの値が `fake-wiring-probe.php` を含む | 2 ファイル |

**コメント・docblock は `PhpTokenScan::normalize()` が落とすので数えない** (現行の
`FakeWiringProbeRunner` の docblock にある `fake-wiring-probe.php` は軸 C に入らない)。

### この gate が主張すること / 主張しないこと (docblock に書く)

**主張する**: 「`PHP_BINARY` の明示参照 (軸 A) / リテラルで検出できるアプリの起動点 (軸 B) /
既存の子入口スクリプトへの参照 (軸 C) の 3 つは、いずれも**申告なしには増えない**」。

**主張しない** (名指しで書く):

1. 「アプリを子プロセスで起こす経路が共通の起動器ちょうど 1 本である」こと
2. 文字列リテラルの `'php'` / `env php` / シェルスクリプト経由 / 変数から取り出した実行体パスの検出
3. **どのクラス・どの関数を呼んでいるかの判定** — aicue の走査基盤は字句走査であり**名前解決器を持たない**。
   `use … as` の別名・完全修飾名・group use・可変関数名はいずれも追えない。
   したがって本 gate は**起動呼び出しの分類を一切行わない** (行えば「緑のまま嘘をつく」)
4. 文字列を分割して針を避ける形 (`'fake-wiring-'.'probe.php'`) の検出

**一元化そのものの証拠は S2〜S4 の載せ替えの実測であり、本 gate は退行の検出器である。**

### 変更後コード (骨子)

```php
<?php

declare(strict_types=1);

use Tests\Support\PhpTokenScan;
use Tests\Support\TrackedPhpSourceFiles;

/*
| `tests/` 配下の**3 種類の字句参照**の全数申告 inventory —
|   (A) 定数 `PHP_BINARY` の参照 / (B) 文字列 `bootstrap/app.php` の参照 /
|   (C) 文字列 `fake-wiring-probe.php` (既存の子入口) の参照。
| lctl feature: subprocess-boot-probe-harness (正典 v1 の作法へ追従したあとの退行を検出する)。
| **本 gate は正典 v1 の 6 不変条件ではなく aicue 側の上積みである** (根拠: 正典テンプレートの
| 同型 gate と AGENTS.md 禁止事項 1)。
|
| **名前のとおり、これは「起動の全数」ではなく「参照の全数」の inventory である。**
| 「PHP の子プロセスを起こしうる箇所を漏れなく数える」ことは**していない** (下記の主張しないこと)。
|
| 【主張すること】【主張しないこと】は上表のとおり (docblock へ逐語で書く)。
*/

/**
 * 軸 A: `tests/` 配下で `PHP_BINARY` を参照してよいファイルの全数申告 (deny-by-default)。
 *
 * entry は 4 つの欄を独立に持つ (「件数合わせの allowlist」へ流れないための構造):
 *  - `launches_app`: アプリを起こすと申告するか (**補助的な申告値**。実際の起動経路の
 *    全数性を表すものではなく、「アプリを起こす」と申告する先が分散していないことだけを固定する)
 *  - `subject` / `recovery` / `reason`
 *
 * @return array<string, array{launches_app: bool, subject: non-empty-string, recovery: non-empty-string, reason: non-empty-string}>
 */
function phpBinaryReferenceInventory(): array
{
    return [
        'tests/Support/Process/BootProbeRunner.php' => [
            'launches_app' => true,
            'subject' => 'アプリを子プロセスで起こして起動順序を測る (PHP_BINARY)',
            'recovery' => '本クラス自身 (制限時間・段階的強制終了・終了コードの保持・一時ディレクトリの後片付け)',
            'reason' => '共通の起動器そのもの (lctl feature: subprocess-boot-probe-harness)',
        ],
        'tests/Unit/Support/Process/BootProbeRunnerTest.php' => [
            'launches_app' => false,
            'subject' => '起動器の自己検査。参照は期待値の比較と、子へ渡す検体文字列の中だけである',
            'recovery' => '起動器 (本ファイルは直接の起動 API を持たず、BootProbeRunner 経由でのみ子を起こす)',
            'reason' => 'バイト一致で取り込んだ共有ファイルなので編集しない。起動器を通してしか子を起こさない',
        ],
        'tests/Support/StrictTypesRuntimeProbe.php' => [
            'launches_app' => false,
            'subject' => '検体 PHP を子で読み込み declare(strict_types=1) の実効性を測る。アプリは起こさない',
            'recovery' => 'Symfony の Process (既定の制限時間つきで、超過すれば例外になる)',
            'reason' => '起動順序ではなく単一ファイルのコンパイル指令を測る層である。起動器に載せると '
                .'Laravel 固有の基底環境・書き出し先 7 キーの予約という無関係な前提が付く '
                .'(同じ理由で PhpLintOracle も載せていない)',
        ],
        'tests/Support/GlobalUse/PhpLintOracle.php' => [
            'launches_app' => false,
            'subject' => '`php -l` を真値として取り出す (構文検査のみ。アプリは起こさない)',
            'recovery' => '同クラス (Symfony Process が管を読み切り、終了コードが null なら例外にする)',
            'reason' => 'アプリを起動しないので環境の 3 段合成も書き出し先の退避も要らない',
        ],
        'tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php' => [
            'launches_app' => false,
            'subject' => 'テスト DB の用意スクリプトを起こす (DB へは接続しない)。アプリは起こさない',
            'recovery' => '同ファイルの helper (管を読み切って proc_close する)',
            'reason' => 'アプリの起動順序ではなくスクリプトの契約を測る層である '
                .'(lctl feature: php-test-pgsql-lane 側の関心事。本 feature とは distinct_from の関係)',
        ],
        'tests/Architecture/NoNonCompoundGlobalUseTest.php' => [
            'launches_app' => false,
            'subject' => '診断メッセージへ実行体のパスを載せるだけ (子は起こさない)',
            'recovery' => '該当なし (起動しない)',
            'reason' => '起動は PhpLintOracle が行い、本ファイルは失敗時の診断に PHP_BINARY を印字するだけである',
        ],
        'tests/Feature/Console/PipelineSmokeCommandTest.php' => [
            'launches_app' => false,
            'subject' => 'ffmpeg の代役として設定値へ実行体のパスを入れるだけ (テストから子は起こさない)',
            'recovery' => '該当なし (起動するのはアプリ側の合成経路であり、本 feature の射程外)',
            'reason' => 'アプリの起動順序を測る経路ではない (ffmpeg 起動の統制は '
                .'tests/Architecture/FfmpegProcessLaunchInventoryTest.php が持つ)',
        ],
    ];
}

/**
 * 軸 B: `tests/` 配下でアプリの起動点 (`bootstrap/app.php`) を参照してよいファイルの全数申告。
 *
 * `kind` は 3 値:
 *  - `child_entry` : 子プロセスで読み込まれる入口 / 子へ渡す検体文字列
 *  - `in_process`  : 同一プロセスでのアプリ起動 (子プロセスではない)
 *  - `inventory`   : 検査定義としてパス文字列を保持するだけ
 *
 * @return array<string, array{kind: 'child_entry'|'in_process'|'inventory', reason: non-empty-string}>
 */
function appBootEntryReferenceInventory(): array
{
    return [
        'tests/Support/ExternalFakes/fake-wiring-probe.php' => [
            'kind' => 'child_entry',
            'reason' => '偽の外部サービスの配線を実起動で観測する子入口。起こすのは共通の起動器である',
        ],
        'tests/Unit/Support/Process/BootProbeRunnerTest.php' => [
            'kind' => 'child_entry',
            'reason' => '起動器の自己検査が子へ渡す検体文字列 (`-r` のソース) の中にある',
        ],
        'tests/TestCase.php' => [
            'kind' => 'in_process',
            'reason' => 'テスト本体のアプリ生成 (同一プロセス)。子プロセスではない',
        ],
        'tests/Architecture/CacheGuardWiringGateTest.php' => [
            'kind' => 'inventory',
            'reason' => 'TestCase の結線を字句で固定する検査が、期待するトークン列としてパス文字列を持つ',
        ],
        'tests/Architecture/PhpBootProbeReferenceInventoryTest.php' => [
            'kind' => 'inventory',
            'reason' => '本 gate 自身。走査の針としてパス文字列を持つ (自分を走査対象から外さない)',
        ],
    ];
}

/**
 * 軸 C: 子入口スクリプトのパスを参照してよいファイルの全数申告。
 *
 * `reference_kind` は 2 値: `runtime` (実行経路として子入口を起こす) / `inventory` (検査定義)。
 *
 * @return array<string, array{reference_kind: 'runtime'|'inventory', reason: non-empty-string}>
 */
function childEntryReferenceInventory(): array
{
    return [
        'tests/Support/ExternalFakes/FakeWiringProbeRunner.php' => [
            'reference_kind' => 'runtime',
            'reason' => '子入口を起こす唯一の呼び出し元。起こし方と回収は BootProbeRunner に委ねる',
        ],
        'tests/Architecture/PhpBootProbeReferenceInventoryTest.php' => [
            'reference_kind' => 'inventory',
            'reason' => '本 gate 自身。走査の針としてパス文字列を持つ (自分を走査対象から外さない)',
        ],
    ];
}
```

検査は **7 本**:

| # | 検査 |
|---|------|
| G-1 | 軸 A: 実測と申告のファイル集合が完全一致する |
| G-2 | 軸 A: `launches_app: true` の entry は `tests/Support/Process/BootProbeRunner.php` ちょうど 1 件 |
| G-3 | 軸 A: `subject` / `recovery` / `reason` の 3 欄がいずれも空でない |
| G-4 | 軸 B: 実測と申告のファイル集合が完全一致し、`kind` が 3 値のいずれかである |
| G-5 | 軸 C: 実測と申告のファイル集合が完全一致し、`reference_kind` が 2 値のいずれかである |
| G-6 | 軸 C: `reference_kind: runtime` はちょうど 1 件で、そのファイルは**トークン列 `BootProbeRunner` `::` `run` `(`** を持つ (未使用の `use` では通らない) |
| G-7 | 走査が空振りしていない (走査根が実在し、3 軸の母集団がいずれも非空) + 走査器の見本検査 |

G-7 の見本表 (`token_get_all` へ直接与える検体。ファイル走査を経由しない。**すべて恒久のテスト**):

**3 軸の判定**

| 検体 | 軸 A | 軸 B | 軸 C |
|------|-----|-----|-----|
| `<?php $x = [PHP_BINARY];` | 1 | 0 | 0 |
| `<?php // PHP_BINARY` (コメントのみ) | 0 | 0 | 0 |
| `<?php $s = "PHP_BINARY";` (文字列のみ) | 0 | 0 | 0 |
| `<?php use const PHP_BINARY as Runtime; $x = Runtime;` | **1** (fail-closed) | 0 | 0 |
| `<?php $x = MY_PHP_BINARY;` (接頭辞) | **0** | 0 | 0 |
| `<?php $x = NOT_PHP_BINARY;` (打ち消し) | **0** | 0 | 0 |
| `<?php $x = PHP_BINARY_PATH;` (接尾辞) | **0** | 0 | 0 |
| `<?php require 'bootstrap/app.php';` | 0 | 1 | 0 |
| `<?php // require bootstrap/app.php` (コメントのみ) | 0 | 0 | 0 |
| `<?php $p = __DIR__.'/fake-wiring-probe.php';` | 0 | 0 | 1 |
| `<?php $a = 'fake-wiring-'."probe.php";` | 0 | 0 | **0** (**射程外**。限界を期待値として固定する) |

**G-6 のトークン列判定 (`BootProbeRunner` `::` `run` `(`)**

| 検体 | 判定 |
|------|------|
| `<?php BootProbeRunner::run([]);` | **あり** (正例) |
| `<?php Tests\Support\Process\BootProbeRunner::run([]);` | **あり** (末尾の名前で照合する) |
| `<?php use Tests\Support\Process\BootProbeRunner;` (未使用の import のみ) | **なし** |
| `<?php // BootProbeRunner::run(` (コメントのみ) | **なし** |
| `<?php $s = "BootProbeRunner::run(";` (文字列のみ) | **なし** |
| `<?php OtherBootProbeRunner::run([]);` (接頭辞つきクラス名) | **なし** |
| `<?php BootProbeRunnerX::run([]);` (接尾辞つきクラス名) | **なし** |
| `<?php BootProbeRunner::runner([]);` (接尾辞つきメソッド名) | **なし** |
| `<?php BootProbeRunner::RUN;` (定数参照) | **なし** |

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本ファイルのみ新規。**既存テストは 1 本も変更しない**

### PHPStan適合チェック

- 対象外 (`tests/`)。申告の shape は PHPDoc で固定し、`kind` / `reference_kind` は文字列リテラル型で閉じる

### テスト計画

- [x] 新規テスト: G-1 〜 G-7 (上表)
- [x] 負例が効くことの確認 (実装時に手で 1 度試す。**恒久のテストにはしない** — 実ファイルを
      一時的に汚す形になるため): 未登録ファイルへ `PHP_BINARY` を足すと G-1 が赤 /
      `runtime` のファイルから `BootProbeRunner::run(` を消すと G-6 が赤
- [x] 走査器の見本検査 (G-7) は**恒久のテスト**として持つ (実ファイルを汚さない)
- [x] 個別の `DatabaseTransactions` を使っていないことを確認 (DB を張らない)

### リスク

- **`TrackedPhpSourceFiles` は git 追跡下しか見ない**: **新規 4 ファイルは `git add` するまで走査に入らない**。
  実装時にこれを落とすと G-1 が実測 0 件寄りになったまま緑に見える。
  **実装順の段 8 で `git add` 後にもう一度全体を走らせる**ことを手順に入れる
- **軸 B の母集団が広い**: `bootstrap/app.php` は文字列で 5 ファイルに現れる。将来増えるたびに申告が要る =
  意図した摩擦である。無関係な理由で増えるなら `kind: inventory` として 1 行足すだけで済む
- **`--parallel` 安全**: 3 軸とも読み取りのみで、プロセスも DB も張らない

---

## 実装順 (fail-first)

| 段 | やること | ここで何が赤になるか |
|---|---------|-------------------|
| 1 | S1: **自己検査 1 本だけ**を配置する | `Tests\Support\Process\BootProbeRunner` が未定義で**赤** |
| 2 | S1: 実装 2 本を配置する | 段 1 の赤が緑へ。`vendor/bin/pint --test` で非破壊確認 (落ちたら整形せず報告して止まる) |
| 3 | S6: gate を新設する。申告は**載せ替え後の姿**で書く | 軸 A の実測に旧 `FakeWiringProbeRunner.php` (`PHP_BINARY` を直接持つ) が現れて G-1 が**赤**。軸 C も G-6 が**赤** (`runtime` の参照元がまだ `BootProbeRunner::run(` を持たない) |
| 4 | S4: P-13 / P-14 / P-15 / P-10c と P-7 の定数 pin・P-8 の新契約を**先に書く** | 子がまだ印も `write_targets` も `key_digests` も返さないので**赤**。`interpret()` / `withEnvironmentDirectory()` / `CASE_ENV_KEYS` / `MARKER_RELATIVE_PATH` が無いので**未定義で赤** |
| 5 | S2 + S3 を実装する | 段 4 の赤が緑へ。P-7 / P-10 / P-10b / P-11 もここで新契約へ揃える |
| 6 | S5: `StrictTypesRuntimeProbe` の docblock に「載せ替えない理由」を足す | (赤は生じない。判断の登録は段 3 の軸 A の申告が担う) |
| 7 | S6 の申告を実測へ合わせる (旧 `FakeWiringProbeRunner` が軸 A から落ちる) | 段 3 の赤が緑へ |
| 8 | **新規 4 ファイルを `git add` してから**全体を走らせる | `TrackedPhpSourceFiles` が新規ファイルを見るようになる。ここで G-1 / G-4 / G-5 の集合一致を最終確認する |
| 9 | 受入条件の全コマンドを走らせる | — |

## 受入条件

**AGENTS.md L336-338 の検証コマンドを全件緑にする** (PHP のみの変更でも全件が規約である):

| コマンド | 期待 |
|---------|------|
| `composer test` | 緑。**`--parallel` で 2 回連続**走らせる |
| `composer phpstan` | エラー **0 のまま** (アプリコードを変更しないので現状維持) |
| `vendor/bin/pint --test` | 緑。**取り込んだ 3 ファイルに差分が出たら整形せず報告して止まる** |
| `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` | 緑 (本設計は JS を触らないので現状維持の確認) |
| `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` | 緑 (同上) |

個別に緑を確認するテスト (通常のテストコマンドで。設計時の手動実測では代替しない):

- `tests/Unit/Support/Process/BootProbeRunnerTest.php` (S1〜S14)
- `tests/Architecture/ExternalFakeBootProbeTest.php` (P-1〜P-15。P-10 は 4 本)
- `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php` (既存 4 本。**主張も実装も変えない**)
- `tests/Architecture/PhpBootProbeReferenceInventoryTest.php` (G-1〜G-7)

**取り込みの同一性**: 配置後の 3 ファイルの sha256 が、取得時の値
(`bd21b337…` / `00b14167…` / `9db128d8…`) と一致する。

**生成物を残さないこと** (2 本立て):

1. **追跡下**: 意図した新規 4 ファイルを `git add` した直後を基準に、テスト走行前後で
   `git status --porcelain` が**変化しない**
2. **ignored な既知の書き出し場所**: `storage/logs/` / `storage/framework/views/` / `bootstrap/cache/` の
   **相対パス一覧と各ファイルの sha256** を走行前後で取り、**一致する**ことを確認する
   (「見比べる」ではなく機械で突き合わせる)。ただし `--parallel` では他の worker も書くので、
   **単独実行 (`composer test -- --filter=ExternalFakeBootProbe`) で確認する**

**実行時間の増分が説明できること** (「後退が無いこと」ではない — 取り込んだ自己検査の
S7 / S12 / S14 は制限時間 1 秒 + 猶予 2 秒などの**固定の待ち時間**を持つので総実時間は原理的に増える):

- **比較の集合を先に固定する**: 実装**前**に `composer test` の対象一覧を保存する。実装**後**は
  **新規 2 テストファイル (`BootProbeRunnerTest` / `PhpBootProbeReferenceInventoryTest`) を
  `--exclude-filter` で除外**し、**同じ集合**を走らせる
- 試行回数: 各 3 回。集計は**中央値**
- 分けて報告する: (a) **除外した集合** (= 既存テスト) の中央値の変化
  (S5 の載せ替えを取りやめたので**ほぼ 0 であるべき**)、
  (b) 新規 2 ファイルの単独実行時間
- 許容増分: **(a) が 5% 以内**。超えたら**閾値を動かさず原因を報告する**
  ((b) は固定コストなので上限を置かない)

## 乖離台帳の確認 (app-design Phase 3-0)

`docs/template-fingerprints.json` の `entries` (281 パス) と
`tests/Support/TemplateDivergence/adoption-debt.tsv` (171 件) を設計時に実測で確認した結果:

| 判定対象 | 指紋台帳のキーに在るか | 採用時債務に在るか | 本設計での扱い |
|---------|--------------------|-----------------|--------------|
| `tests/Support/Process/BootProbeRunner.php` / `BootProbeResult.php` / `tests/Unit/Support/Process/BootProbeRunnerTest.php` (取り込み 3 件) | **無い** (aicue が未受領のテンプレートパス) | 無い | **バイト一致で取り込む**。将来 指紋台帳を再生成しても記録値と一致して母集合に入り、**逸脱 0 件・債務 0 件**になる。今回は台帳を触らない (再生成は他パスの再観測を巻き込む世代操作であり別議題) |
| `tests/Architecture/SubprocessProbeLaunchGateTest.php` (テンプレートの同型 gate) | **無い** (aicue は未受領) | 無い | **このパスを使わない**。テンプレート側では**指紋台帳に登録済みの共有パス**なので、aicue 固有の申告内容を置くと将来の再生成で逸脱の登録が要る。aicue 既存の命名に倣った `tests/Architecture/PhpBootProbeReferenceInventoryTest.php` を使う |
| `tests/Support/ExternalFakes/FakeWiringProbeRunner.php` / `fake-wiring-probe.php` / `tests/Architecture/ExternalFakeBootProbeTest.php` / `tests/Support/StrictTypesRuntimeProbe.php` (変更 4 件) | **無い** (いずれも aicue 固有のテスト支援コード) | 無い | 指紋機構の母集合外。**逸脱の登録は行わない** |
| `tests/Architecture/PhpBootProbeReferenceInventoryTest.php` (新規 1 件) | **無い** (テンプレートに存在しないパス) | 無い | 同上。テンプレート自身も「呼び出し側とドメイン結線部は各アプリの持ち物」という分類を採っているので、「テンプレートの形から外れた判断」ではない |
| `phpstan.neon` | **在る** | **在る** | **触らない**。債務パスは「変更したまま債務に残す」を選べず (突合 gate の `mutatedDebtPaths` が落ちる)、(1) 採用時の姿へ戻す / (2) テンプレートへ同期して債務から削る / (3) 意図的逸脱として登録して債務から削る の 3 択を迫られる。いずれも本 TODO の目的と無関係な重い操作なので、解析対象は現状のまま据え置く |
| `tests/Architecture/NoNonCompoundGlobalUseTest.php` (軸 A で**申告するだけ**のファイル) | **在る** | **在る** | **1 行も変更しない**。gate の申告に載せるだけでファイル自体には触れない (触ると債務の 3 択を迫られる) |
| `tests/TestCase.php` / `tests/Architecture/CacheGuardWiringGateTest.php` (軸 B で**申告するだけ**) | 設計時の実測では**無い** | 無い | **1 行も変更しない** (申告に載せるだけ) |
| `docs/architecture.md` / `docs/template-divergence.md` | — | — | **触らない**。正典は文書を要求しておらず、道具の説明は各ファイルの docblock を正本にする |

- `LedgerPins::DIVERGENCE_ENTRY_COUNT` (36) / `FINGERPRINT_POPULATION_COUNT` (281) /
  `ADOPTION_DEBT_COUNT` (171) は**いずれも変更しない** (登録の追加・削除が無いため)
- **「登録するか迷ったら登録する」の原則との関係**: 本設計の新設・変更分は
  (a) 取り込む 3 本はテンプレートと**バイト一致**であり逸脱ではない、
  (b) 変更する 4 本と新設する 1 本は**テンプレートに無い aicue 固有の領域**への上積みである、
  (c) 共有パスへ食い違う内容を置く唯一の候補 (テンプレートの gate パス) は**意図的に避けた**、
  の 3 点から**逸脱を 1 件も作らない**。したがって登録の対象にならない

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 新規 4 本 (S1 の 3 / S6 の 1) + 既存 4 本の変更で、変更はすべて `tests/` 配下に閉じる。「実装順 (fail-first)」の 9 段に依存の鎖があり (取り込み → gate の赤 → 呼び出し側の赤 → 載せ替え → 申告の追随 → `git add` 後の再走)、分割すると各段の赤を確認できない。`composer test` 全体と `--parallel` の 2 回実行まで含めて 1 本の worktree で完結する |
| 競合リスク | 低。`tests/Support/Process/` は新設ディレクトリで、変更する 4 本はいずれも本 feature 専用の狭い領域である。`docs/`・`app/`・台帳ファイル・`phpstan.neon` を触らないので、他 worktree の変更と行単位で衝突しない |

## スコープ外 (明示)

1. **アプリコード (`app/` / `routes/` / `config/` / `database/` / `bootstrap/`) の変更** — 1 バイトも触らない
2. **`docs/` の変更** — 正典は文書を要求していない。道具の説明は各ファイルの docblock を正本にする
3. **指紋台帳 (`docs/template-fingerprints.json`) の再生成と `LedgerPins` の件数更新** — 世代操作なので別議題
4. **`phpstan.neon` へのテストパスの追加** — 採用時債務パスなので触らない (上の表)
5. **観測対象の拡張** — 観測対象となる外部 fake の種類は増やさない。内訳:
   追加する (P-13 / P-14 = 正典 (5) の実働証明、P-15 / P-10c = fail-closed と後始末の負例) /
   強化する (P-8 = 起動側の配列確認 → 子での実効値確認) /
   言い直すだけ (P-7 / P-11) / 分割するだけ (P-10) / 一切変えない (P-1〜P-6 / P-9 / P-12)
6. **`tests/Support/StrictTypesRuntimeProbe.php` の共通 runner への載せ替え** — アプリを起こさない経路であり
   正典の boundary の外。**載せ替えない理由を docblock と gate の申告に残す** (S5)。
   **再判定の条件**: 当該経路がアプリの起動を伴うようになったとき、または「アプリを起こさない PHP 子プロセス」の
   回収規約を家系が別 feature として立てたとき
7. **`tests/Support/GlobalUse/PhpLintOracle.php` の載せ替え** — 同上 (`php -l` はアプリを起動しない)
8. **`proc_open` を直呼びする既存 3 経路の載せ替え**
   (`tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` / `tests/Architecture/SkillsLockIgnoreCoverageTest.php` /
   `tests/Architecture/GitIndexNormalizationTest.php`) — `git` / シェルスクリプト / 別スクリプトの起動であり、
   **PHP の実行体でアプリを起こす経路ではない**
9. **子を 2 本立てて合図で同期させる並行テスト** — 別 feature (`process-concurrency-test-harness`)
10. **`tests/` 全域のプロセス起動 API の全数申告** — 実測すると**母集団は 25 ファイル**で、
    `Process::fake()` の単体テストや `git ls-files` の列挙まで含む。3 欄の申告を 25 件書くのは
    本 TODO の目的から外れた別作業である。**加えて aicue は名前解決器を持たない**ので、
    字句走査で起動呼び出しを分類すると誤検出と見逃しの両方が出る。
    **再判定の条件**: 本設計の gate が捕まえられない形で子プロセスの起動が実際に足されたとき、
    または `nikic/php-parser` が aicue の直接依存に入ったとき
11. **`docs/TODO.md` への登録** — `/app-todo-add` の責務

