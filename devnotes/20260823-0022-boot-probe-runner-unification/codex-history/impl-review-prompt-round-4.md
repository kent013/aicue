# Codex 実装レビュー依頼 (impl-review Round 4 / 新セッション)

## アプリの使命 (North Star) — AGENTS.md より

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項 (自分・Codex 双方に適用) — AGENTS.md より

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


## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: あなたの役割

あなたはコードレビュアーとして、Laravel + Svelte アプリ (aicue) の改善実装をレビューする。

**レビュー観点**:

1. 詳細設計との一致性 (過大化・過小化の両方を指摘する)
2. 正確性 (実際に動くか。境界事例・fail-open・偽グリーンの穴)
3. PHPStan level 10 適合性 (ただし本リポジトリの解析対象は app/config/database/routes で tests/ を含まない)
4. DTO / JsonResource パターン (本変更は API を触らないので該当なし)
5. テスト網羅性 (負例・両方向の裏取り・母集団の非空)
6. セキュリティ (資格情報の露出、子プロセスの隔離、fail-closed)
7. AGENTS.md §静的検査 (gate) と走査器の共通規約 5 条 ((a) 完全修飾名 / (b) fail-closed / (c) 負例で裏取り / (d) 使わない走査結果を作らない / (e) 語彙一致はトークン完全一致)
8. DESIGN.md 準拠 / Atomic Design 準拠 (本変更は resources/ を 1 行も触らないので該当なし)

**出力形式**: ファイルごとに判定を書き、指摘は [Critical] / [Warning] / [Suggestion] に分類する。
最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で書く。

**重要な前提 (レビュー範囲)**: 本ラウンドは**新しいセッション**である (前 3 ラウンドの文脈は保持されていない)。
Round 1〜3 の指摘と対応は下に全文を添付する。**Round 3 で唯一残った [Critical] が解消できているか**を
最重要の判定軸にしてほしい。

---
## 詳細設計書 (devnotes/20260823-0022-boot-probe-runner-unification/detailed-design.md)

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
    `Tests\Support\Process\BootProbeRunner` / `Tests\Support\Process\BootProbeResult` の `use` を足す
    (`FakeClassCatalog` は同一 namespace なので `use` は不要。
    **`Webmozart\Assert\Assert` は使わない** — 例外契約を `RuntimeException` 1 本に統一するため)

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

    // ★`Webmozart\Assert` を使わない — あちらは InvalidArgumentException を投げるので、
    //   呼び出し側の例外契約が RuntimeException と 2 本立てになってしまう。
    //   この境界は明示検査で RuntimeException に統一する。
    if (! str_starts_with($base, DIRECTORY_SEPARATOR)) {
        throw new RuntimeException("観測用の置き場所は絶対パスであること: {$base}");
    }

    if (! is_dir($base) || ! is_writable($base)) {
        throw new RuntimeException("観測用の置き場所を使用できない: {$base}");
    }

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
    // ★このテストが作った場合だけ後で戻す (走行が生成物を残さないため)。
    $createdBase = ! is_dir($base);
    if ($createdBase) {
        expect(mkdir($base, 0755, true))->toBeTrue();
    }

    try {
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
    } finally {
        if ($createdBase) {
            rmdir($base);
        }
    }
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
| **軸 A (`PHP_BINARY` 参照)** | `T_STRING` かつ text が `PHP_BINARY` のトークンを持つ。**または** `use` `const` の並びに `PHP_BINARY` が現れる (別名 import の fail-closed) | 7 ファイル |
| **軸 B (アプリの起動点)** | 文字列トークン (`T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE`) の値が `bootstrap/app.php` を含む | 5 ファイル |
| **軸 C (子入口の参照)** | 文字列トークンの値が `fake-wiring-probe.php` を含む | 2 ファイル |

**コメント・docblock は `PhpTokenScan::normalize()` が落とすので数えない** (現行の
`FakeWiringProbeRunner` の docblock にある `fake-wiring-probe.php` は軸 C に入らない)。

### この gate が主張すること / 主張しないこと (docblock に書く)

**主張する**: 「`PHP_BINARY` の字句参照 (軸 A) / リテラルで検出できるアプリの起動点 (軸 B) /
既存の子入口スクリプトへの参照 (軸 C) の 3 つは、いずれも**申告なしには増えない**」。

**主張しない** (名指しで書く):

1. 「アプリを子プロセスで起こす経路が共通の起動器ちょうど 1 本である」こと
2. 文字列リテラルの `'php'` / `env php` / シェルスクリプト経由 / 変数から取り出した実行体パスの検出
3. **起動呼び出しの分類** — 「どのクラスの `new` か」「`proc_open` かその別名か」といった判定は
   名前解決を要するので**一切行わない** (行えば「緑のまま嘘をつく」)
4. **名前の解決** — G-6 が照合するのは**名前トークンの末尾要素**という字句の一致であり、
   その名前が実際にどのクラスを指すかは解決しない。したがって:
   - **扱う**: `BootProbeRunner::run(` / `Tests\Support\Process\BootProbeRunner::run(`
     (`T_NAME_QUALIFIED`) / `\Tests\…\BootProbeRunner::run(` (`T_NAME_FULLY_QUALIFIED`) —
     いずれも末尾要素が `BootProbeRunner` なので**検出する**
   - **扱わない**: `use … as Runner; Runner::run(` — **別名は追えない** (負例として恒久テストに固定する)
5. 文字列を分割して針を避ける形 (`'fake-wiring-'.'probe.php'`) の検出

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

**G-6 のトークン列判定 (名前トークンの**末尾要素**が `BootProbeRunner` + `::` + `run` + `(`)**

| 検体 | 判定 |
|------|------|
| `<?php BootProbeRunner::run([]);` | **あり** (正例。`T_STRING`) |
| `<?php Tests\Support\Process\BootProbeRunner::run([]);` | **あり** (`T_NAME_QUALIFIED` の末尾要素で照合) |
| `<?php \Tests\Support\Process\BootProbeRunner::run([]);` | **あり** (`T_NAME_FULLY_QUALIFIED` も同じ規則) |
| `<?php use Tests\Support\Process\BootProbeRunner as Runner; Runner::run([]);` | **なし** (**射程外**。別名は名前解決を要する。限界を期待値として固定する) |
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

**実装時に必ず確かめること (Codex 詳細設計レビュー Round 4 の申し送り)**:

1. `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED` の**末尾要素の抽出**が G-7 の見本表どおりに動くこと
   (PHP の版によってトークンの分かれ方が違う可能性があるので、見本で先に確かめる)
2. P-10d の基底 (`storage/framework/testing`) を**新規作成した環境**でも、
   親階層へ生成物を残さないこと
3. 全体実行は `--parallel`、新規 2 ファイルの測定は **`composer test` の引数転送が実際に効く形**
   (`composer test -- <path> <path>`) で走ること — 効かないなら `vendor/bin/pest <path> <path>` を使う
4. **`vendor/bin/pint --test` の確認後も、取り込んだ 3 ファイルの sha256 が変わっていないこと**

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
2. **ignored な既知の書き出し場所**: `storage/logs/` / `storage/framework/views/` / `bootstrap/cache/` / `storage/framework/testing/` の
   **相対パス一覧と各ファイルの sha256** を走行前後で取り、**一致する**ことを確認する
   (「見比べる」ではなく機械で突き合わせる)。ただし `--parallel` では他の worker も書くので、
   **単独実行 (`composer test -- --filter=ExternalFakeBootProbe`) で確認する**

**実行時間の増分が説明できること** (「後退が無いこと」ではない — 取り込んだ自己検査の
S7 / S12 / S14 は制限時間 1 秒 + 猶予 2 秒などの**固定の待ち時間**を持つので総実時間は原理的に増える):

**除外オプションに依存せず、全体走行 3 本の引き算で測る** (Pest の `--exclude-filter` は
テスト**名**のパターンを除くものでファイルを除外できないため):

| 測定 | コマンド |
|------|---------|
| (a) 実装**前**の全体 | `composer test` (`--parallel`) |
| (b) 実装**後**の全体 | `composer test` (`--parallel`) |
| (c) 実装**後**の新規 2 ファイルだけ | `composer test -- tests/Unit/Support/Process/BootProbeRunnerTest.php tests/Architecture/PhpBootProbeReferenceInventoryTest.php` |

- 試行回数: 各 3 回。集計は**中央値**。**(a) (b) (c) の中央値を必ず併記して報告する**
  (差だけを出すとノイズかどうか判断できない)
- 判定: **(b) − (a) − (c) が (a) の 5% 以内**
  (= 新規ファイルの固定コストを差し引いた残りが、既存テストへの影響。
  S5 の載せ替えを取りやめたので**ほぼ 0 であるべき**)
- 超えたら**閾値を動かさず原因を報告する**

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


---

## Round 1 の Codex 返答 (全文)

仮説は「共通 runner への載せ替え自体は正しいが、新設 gate の名前解決と書き出し先検査に fail-open があり得る」です。提示差分をその観点で確認しました。コマンド実行は禁止されているため、テスト結果は申し送りを前提としています。

### `tests/Architecture/ExternalFakeBootProbeTest.php`

- [Critical] P-14 は `BootProbeRunner::isInside()` に正規化していない書き出し先を渡しています。同メソッドの契約は「両引数とも realpath 済み」ですが、キャッシュファイルなどは未作成なので、そのままの文字列です。例えば一時 root が `/tmp/boot-x` のとき、`/tmp/boot-x/../../repo/bootstrap/cache/x` は一時 root 配下かつ repository 外として検査を通りますが、実際には repository 内へ解決されます。予約パス生成に `..` が混入する退行を緑のまま見逃します。未作成パスでも正規化できる方法を使うか、少なくとも `.` / `..` セグメントを拒否する必要があります。P-11 も同じ前提に依存しています。

- [Warning] P-10d は `$base` を再帰作成した場合に `$base` 自身しか削除しません。`storage/framework` などの親もこのテストが作った環境では親ディレクトリが残ります。「新規作成した環境でも親階層へ生成物を残さない」という詳細設計の確認事項を満たしていません。

- [Suggestion] P-10b は失敗と残骸なしを確認しますが、「子を起こさなかった」ことを直接観測していません。P-10d の `$bodyCalled` と同様の番兵がないため、将来「子を起こした後に例外」という実装になっても通り得ます。

P-7/P-8 の強化、Pest matcher の修正、timeout 判定に `timedOut` を使う変更は妥当です。

### `tests/Architecture/PhpBootProbeReferenceInventoryTest.php`

- [Critical] G-6 はクラス参照を末尾名だけで照合しており、AGENTS.md の規約 (a) に反します。例えば `use Other\BootProbeRunner; BootProbeRunner::run(...)` へ差し替えても通るため、「共通の起動器を実際に呼んでいる」という検査名どおりの保証になりません。別名 import を明示的に射程外とする記述でも、この規約違反は解消されません。`use`、group use、alias を解決した完全修飾名との一致が必要です。

- [Critical] 軸B/Cは文字列に対する素の部分一致で、規約 (e) が要求する区切り文字の宣言と、接頭辞・打ち消し・接尾辞の負例を持ちません。現在の見本は「コメント」と「分割文字列」だけです。例えば `not-bootstrap/app.php.bak` や `fake-wiring-probe.php.disabled` をどう分類するかが固定されておらず、新設 gate の必須4点を満たしていません。

- [Warning] 軸Aで `T_NAME_*` を扱う拡張自体は正当ですが、末尾要素だけの一致は `Foo\PHP_BINARY` という別定数まで同一視します。「実際の `PHP_BINARY`」ではなく「末尾が同名の字句」の inventory です。これは保守的な過検出として使うことはできますが、現在の名称・説明とは意味がずれています。

軸Bの5件→8件は正当な適応です。診断文は実行経路ではないため、`inventory` を「検査定義・診断文として保持するだけ」と明記した分類も妥当です。Pest matcher 2件の変更も正しいです。

### `tests/Support/ExternalFakes/FakeWiringProbeRunner.php`

指摘なし。外側ディレクトリの realpath による境界検査、0700/0600、予約鍵の委譲、timeout の fail-closed、使い捨て鍵の分離は詳細設計と一致しています。

### `tests/Support/ExternalFakes/fake-wiring-probe.php`

指摘なし。marker による実体検査、8書き出し先の報告、鍵を平文で出さない digest 化はいずれも妥当です。Fake 用の一時環境ファイルへ切り替えてから起動するため、この経路では repository の `.env` を読ませない構造も維持されています。

### `tests/Support/Process/BootProbeResult.php`

- [Warning] PHPDoc の「強制終了なら `TIMEOUT_EXIT_CODE`」は実体と食い違います。runner は timeout 後の SIGTERM を受けて子が `exit(0)` した場合、その非負終了コードを保持します。詳細設計自身も `timedOut === true && exitCode === 0` を認めています。型は正しいものの、意味上の契約が誤っています。共有ファイルなので、本TODOで修正できない上流課題として扱う必要があります。

### `tests/Support/Process/BootProbeRunner.php`

指摘なし。共有ファイルの取り込みと Fake 経路との接続は妥当です。呼び出し側で予約鍵を渡していないこと、外側・内側双方が repository 外であることも確認できます。

### `tests/Support/StrictTypesRuntimeProbe.php`

指摘なし。アプリ起動を測らない経路を共通 runner に載せない判断と再判定条件は正当です。

### `tests/Unit/Support/Process/BootProbeRunnerTest.php`

- [Critical] S9/S10 の `BOOT_PROBE_PATH_REPORT` は repository の `bootstrap/app.php` を直接起動し、環境ファイルの置き場所を差し替えていません。runner が制限するのは `proc_open` に渡すプロセス環境だけなので、Laravel は repository のローカル `.env` を読み、そこにある外部サービス資格情報を子の設定へ取り込めます。「子プロセスへ実資格情報が渡らない」という今回のセキュリティ要件を構造的に満たしません。Fake 経路が安全でも、この自己検査の子は別経路です。共有ファイルを編集できないなら、上流の正典修正・再取り込みを含めて扱う必要があります。

- [Warning] S11 は `storage/framework/testing` が無い場合に作成しますが、作成したディレクトリを戻しません。単独実行や新しい checkout では ignored な生成物を残す可能性があります。

### 受入条件

- [Critical] 提示された証跡では、`composer test` の並列2回、`pnpm test`、`pnpm test:packages`、全体走行3系列の中央値と性能判定がまだ完了していません。AGENTS.md 禁止事項1および詳細設計の受入条件上、結果が揃うまではマージ可能とは判定できません。

全体判定: CHANGES_REQUESTED

## Round 1 への対応マトリクス

# 対応マトリクス: impl-review Round 1

Codex 実装レビュー Round 1 (全体判定 CHANGES_REQUESTED) への対応。

---

## [Critical] P-14 / P-11 が正規化されていないパスを `isInside()` へ渡している

- **判断: 対応する**
- **根拠**: 指摘のとおりである。`BootProbeRunner::isInside()` の docblock は
  「両引数とも `realpath` 済みの絶対パス」を契約として明記しているが、書き出し先の多くは
  **まだ存在しないファイル**なので `realpath` できず、子が返した文字列をそのまま渡していた。
  `<一時 root>/../../<リポジトリ>/…` の形は「一時 root の配下」かつ「リポジトリの外」と
  判定されるのに、実際にはリポジトリ内へ解決される = **緑のまま嘘をつく** (fail-open)。
  現在の実装では予約パスが `realpath` 済みの `temporaryRoot` から組み立てられるので
  `..` は入らないが、**gate の役目はその前提が崩れたときに赤くすること**である。
- **対応内容**: `externalFakeProbeAssertNormalizedPath()` を新設し、**配下判定の前に**
  絶対パスであることと `.` / `..` セグメントを 1 つも含まないことを確かめる。
  P-11 (`config_cache`) と P-14 (書き出し先 8 種すべて) の両方に入れた。

## [Critical] G-6 が短名一致で、AGENTS.md §静的検査の共通規約 (a) に反する

- **判断: 対応する (指摘が正しく、詳細設計の前提が誤っていた)**
- **根拠**: 詳細設計は「aicue は名前解決器を持たない」ことを理由に字句の末尾要素一致を選んでいた。
  しかしこれは **`nikic/php-parser` を直接依存に持たない**ことの言い換えであって、
  aicue には **`Tests\Support\PhpReferenceScanner`** が実在する。同クラスの docblock は
  「emit する `name` は**必ず完全修飾名まで解決済み**である」「(AGENTS.md の (a))」と明記しており、
  `use` / group use / 別名つき取り込み / 名前空間の解決をすべて備えている
  (`ExternalSeamInventoryTest` と `ExternalClientTimeoutInventoryTest` が既に乗っている基盤)。
  したがって「名前解決を持たないので短名一致にする」という設計の前提が**事実として誤っていた**。
  Codex の言うとおり、短名一致は `use Other\BootProbeRunner;` へ差し替えるだけで黙る。
- **対応内容**: G-6 の判定を `PhpReferenceScanner::references()` に置き換え、
  `ReferenceKind::StaticCall` かつメソッド名 `run` かつ**受け手の完全修飾名**が
  `Tests\Support\Process\BootProbeRunner` に一致することを見る形にした。
  - 受け手が静的に確定できない形 (`$runner::run(` / `static::`) は**証拠に数えない**
    (G-6 は存在を主張する検査なので、未解決を肯定側へ数える方が危険側である)
  - 見本表を差し替え、**別名つき取り込みは検出する** (旧実装の穴)、
    **同名の別クラスは検出しない** (旧実装の誤検出) を両方向で固定した
  - gate の docblock の「名前の解決を一切行わない」という記述を、実態に合わせて全面的に書き直した
- **実測での裏取り**: `use Tests\Support\Process\BootProbeRunner;` を
  `use Tests\Support\ExternalFakes\BootProbeRunner;` (同名・別名前空間) へ差し替えると
  **G-6 が赤**になることを確認した (旧実装では緑のまま通っていた形である)。

## [Critical] 軸 B / C が素の部分一致で、規約 (e) の負例 3 形を持たない

- **判断: 対応する (ただし判定の意味論は変えない)**
- **根拠**: 規約 (e) は**語彙一致を判定する走査**に課される条であり、軸 B / C は
  「文字列トークンの中にパスの綴りが現れるか」を見る**部分文字列一致**なので、
  トークン分割の完全一致へ寄せる対象ではない (寄せると `__DIR__.'/fake-wiring-probe.php'` の
  ような正当な形まで落ちる)。一方で Codex の実質的な指摘 —
  **「`not-bootstrap/app.php` や `fake-wiring-probe.php.disabled` をどう扱うかが固定されていない」**
  — は正しい。判定が未固定なら、後から意味論を変えても誰も気づかない。
- **対応内容**: 判定は変えずに、**接頭辞つき・打ち消しつき・接尾辞つきの 3 形を見本表へ足し、
  「いずれも一致する (= 申告が要る側へ倒れる)」ことを期待値として固定**した。
  併せて「針の一部だけでは一致しない」下界 (`bootstrap/application.php` /
  `fake-wiring-probe.txt`) も足した。これは**見逃す方向ではなく拾いすぎる方向**なので
  規約 (b) の許す側であり、紛らわしい綴りを足した人には「1 行申告する」摩擦だけが掛かる。
  この非対称を gate の docblock にも書いた。

## [Critical] 自己検査 S9 / S10 の子がリポジトリの `.env` を読む

- **判断: 反論する (本 TODO では実行できない。上流の議題として申し送る)**
- **根拠**: 3 点ある。
  1. **編集できない**。当該ファイルはテンプレートから**バイト一致で取り込んだ共有ファイル**で、
     1 バイトでも変えると意図的逸脱の登録 (`LedgerPins::DIVERGENCE_ENTRY_COUNT` の更新を伴う)
     が必要になる。詳細設計は「触らない」を明示的な受入条件にしている。
  2. **今回のセキュリティ要件の対象は経路 1 である**。「子プロセスへ実資格情報を渡さない」は
     `fake-wiring-probe` の観測 (P-6 / P-7 / P-9) が担う契約であり、そちらは
     専用の一時環境ファイルへ固定したうえで、プロセス環境に `TESTING_FAKE_*` を 1 件も載せない
     ことを完全一致で pin している。**この TODO でその保証は 1 ミリも後退していない**。
  3. **S9 / S10 の子は資格情報を外へ出さない**。報告するのは書き出し先のパス 8 種だけで、
     設定値も鍵も出力しない。出力先は同一ホストの親プロセスの管である。
     runner の基底が `APP_KEY` / `QUEUE_CONNECTION` / `CACHE_STORE` を上書きするので
     DB / キューにも触れない。
- **対応内容**: 本 TODO では変更しない。**指摘そのものは妥当な上流課題**なので、
  「アプリを起こす自己検査の子に、`.env` ではなく専用の環境ファイルを読ませるべきではないか」
  を家系の機能台帳 (feature `subprocess-boot-probe-harness`) への申し送り候補として記録する。
  **本セッションは台帳への書き込みを禁じられている**ため、起票は行わない
  (人間または台帳担当のセッションが行う)。

## [Warning] P-10d が再帰作成した親を戻さない

- **判断: 対応する**
- **根拠**: 指摘のとおり。`mkdir($base, 0755, true)` で `storage/framework` ごと作った環境では
  `rmdir($base)` だけでは `storage/framework` が残る。詳細設計の実装時確認事項 2
  (「P-10d の基底を**新規作成した環境**でも、親階層へ生成物を残さないこと」) を満たしていなかった。
- **対応内容**: 作成前に「実在しない祖先」を深い順に列挙し、浅い順に 1 段ずつ作り、
  `finally` で深い順に 1 段ずつ戻す形にした。

## [Suggestion→採用] P-10b が「子を起こさなかった」ことを直接観測していない

- **判断: 対応する**
- **根拠**: 妥当。例外クラスだけを見ると「子を起こしたあとに別の理由で落ちた」場合も緑になり、
  検査名の「子を起こさずに」の部分が主張だけになる。
- **対応内容**: `toThrow(RuntimeException::class, '観測用の置き場所を使用できない')` として
  **失敗した段**まで固定した (この message は置き場所の検査 = 子を起こす前だけが投げる)。

## [Warning] `BootProbeResult` の PHPDoc が実体と食い違う

- **判断: 見送る (本 TODO では実行できない。上流の議題)**
- **根拠**: 指摘は正しい (「強制終了なら `TIMEOUT_EXIT_CODE`」は、終了要求を受けてから
  自分で `exit(0)` する子には当てはまらない)。ただしバイト一致で取り込んだ共有ファイルなので
  編集できない。**呼び出し側はこの誤りに依存していない** — `interpret()` は
  `exitCode === 124` を読まず `timedOut` で判定しており、その理由を docblock に書いてある。
- **対応内容**: 変更しない。上流の申し送り候補として本マトリクスに記録する。

## [Warning] S11 が `storage/framework/testing` を作ったまま戻さない

- **判断: 見送る (共有ファイル) / ただし自分の側は直した**
- **根拠**: 取り込んだ自己検査なので編集できない。
- **対応内容**: **aicue 側で新設した P-10d は同じ問題を直した** (上の Warning)。
  取り込み側は上流の申し送り候補として記録する。

## [Critical] 受入条件の証跡が未完

- **判断: 対応する**
- **根拠**: そのとおりで、Round 1 の時点では `composer test` の全体走行・`pnpm test` /
  `pnpm test:packages`・実行時間の 3 系列がグローバルテストロック待ちだった
  (同一ホストで他の worktree の 2 レーンが走っており、待つのが正常な運用である)。
- **対応内容**: 全レーンの完走後に Round 2 で結果を提示する。


## Round 2 の Codex 返答 (全文)

Round 1 の主要な fail-open 修正は妥当です。ただし、共有自己検査の `.env` 読み込みと「全体テスト2回連続 green」が未解決です。

### `tests/Architecture/ExternalFakeBootProbeTest.php`

- P-11/P-14 の `.` / `..` 拒否は、指摘した経路逸脱を防げています。未作成パスを `realpath()` できない事情に対する正当な適応です。
- P-10d の祖先ディレクトリ管理も、浅い順に作成して深い順に削除しており妥当です。
- P-10b の例外メッセージ固定も、「子起動前の検査で落ちた」ことを従来より直接的に示しています。

- [Suggestion] `externalFakeProbeAssertNormalizedPath()` の負例を恒久テストにすると、今回直した分岐自体の退行も検出できます。例えば `/tmp/root/../repo/file`、相対パス、正常な絶対パスの3例です。現状でもP-11/P-14は機能しますが、実データが常に正常なので、このhelperを削除・空実装化しても現在のテストは緑になります。

判定: 修正済み。

### `tests/Architecture/PhpBootProbeReferenceInventoryTest.php`

G-6 の `PhpReferenceScanner` 採用は正しい修正です。別名import、同名別クラス、未import短名の両方向が固定され、規約 (a) を満たしています。未解決receiverを肯定材料に使わないのも、存在を主張するG-6ではfail-closedです。

軸B/Cについても、これは「語彙を除外する否定判定」ではなく、文字列内に針が現れたファイルを保守的に全数申告させる走査です。接頭辞・打ち消し・接尾辞をすべて一致側へ倒す意味論を明記し、下界も固定したため、今回の用途では正当な適応と判断します。

軸Aの末尾一致も、別定数を過検出することまで明記されました。摩擦は増えますが見逃しには倒れず、inventoryの目的とは整合します。

判定: 修正済み。

### `tests/Support/ExternalFakes/FakeWiringProbeRunner.php`

指摘なし。Round 1 の評価から変更ありません。

### `tests/Support/ExternalFakes/fake-wiring-probe.php`

指摘なし。専用環境ファイル、使い捨て鍵、marker、書き出し先報告の結線は妥当です。

### `tests/Support/Process/BootProbeRunner.php`

指摘なし。バイト一致取り込みであることを前提に、呼び出し側との噛み合わせも成立しています。

### `tests/Support/Process/BootProbeResult.php`

- [Warning] `timedOut === true && exitCode === 0` が可能なのに「強制終了ならTIMEOUT_EXIT_CODE」とするPHPDocの食い違いは残っています。呼び出し側が誤記に依存していないためT249固有の実行時バグではなく、上流申し送りとする判断は受け入れます。

### `tests/Unit/Support/Process/BootProbeRunnerTest.php`

- [Critical] S9/S10によるrepositoryの `.env` 読み込みは、依然としてマージ阻害事項です。

提示された理由では解消されません。

1. バイト一致制約は「修正できない理由」にはなりますが、「取り込んで安全である理由」にはなりません。本レビューは共有ファイルを取り込むこと自体の妥当性も対象です。
2. セキュリティ観点は経路1だけに限定されていません。S9/S10も今回新たに実行される子プロセス経路です。
3. stdoutへ資格情報を出さなくても、子のLaravel設定には `.env` の値が入ります。`APP_KEY`、DB、queue、cacheの上書きだけでは、Stripe、AWS、OAuth等の資格情報やboot時に動くproviderを無害化できません。またrunner自身が「子の外部通信は保証しない」と明記しています。

したがって「上流課題として記録する」だけでは不足です。正典側を修正して再取り込みするか、この環境でS9/S10がrepositoryの `.env` を読まないことを別の構造的境界で保証する必要があります。

### `tests/Support/StrictTypesRuntimeProbe.php`

指摘なし。非載せ替え判断は妥当です。

### 受入条件・性能測定

- [Critical] 全体テストは「2回成功」していますが、詳細設計が要求する「2回連続 green」にはなっていません。green／fail／greenなら連続条件未達です。失敗がT249と無関係という分析と名指し再走行greenは合理的ですが、機械的な受入条件を置き換えません。最後のgreenに続けて、もう1回全体greenを得る必要があります。

性能測定は「判定不能」と読むのが妥当です。中央値の残差は閾値超過ですが、群内変動が閾値を大幅に上回り、回帰を支持するデータにはなっていません。詳細設計も「超えたら閾値を動かさず原因を報告」としており、その義務は果たしています。ただし最小値同士の比較は事後的で偏りやすいため、性能合格の根拠にはせず、安定している `(c) = 12.4秒` と「全体比較は判定不能」までを結論にするのが適切です。

bug-huntの1件については、差分との非連結性、2/3成功、名指し再走行成功から、T249の回帰とは見なしません。

全体判定: CHANGES_REQUESTED

## Round 2 への対応マトリクス

# 対応マトリクス: impl-review Round 2

Codex 実装レビュー Round 2 (全体判定 CHANGES_REQUESTED) への対応。
Round 2 は Round 1 の [Critical] 1 / 2 / 3 と [Warning] 5 / [Suggestion] 6 を「修正済み」と判定した。
残る指摘は 3 件。

---

## [Suggestion] `externalFakeProbeAssertNormalizedPath()` の負例を恒久テストにする

- **判断: 対応する**
- **根拠**: 指摘のとおり。実データが常に正常なので、**この helper を空実装にしても
  P-11 / P-14 は緑のまま**である = 今回直した分岐そのものに退行検知が無い。
  AGENTS.md §静的検査の共通規約 **(c)「検出力は負例で裏取りする」** に正面から当たる。
- **対応内容**: 述語を純関数 `externalFakeProbeIsNormalizedAbsolutePath()` へ切り出し、
  **P-16 として恒久のデータ駆動テスト 14 例**を置いた。
  - 正例 3 (実データと同じ形。false になると P-11 / P-14 が偽レッドになる)
  - 負例 `..` 3 形 (`/tmp/x/../../workspace/...` / 末尾 `..` / 先頭 `/../`)
  - 負例 `.` 2 形
  - 負例 相対パス 3 形
  - **紛らわしいが正当な 3 形** (`..hidden` / `.hidden` / `a..b`) —
    素の部分文字列判定で書いていたら誤って弾いていた形を正例として固定した

## [Warning] `BootProbeResult` の PHPDoc の食い違い

- **判断: 見送る (Codex が上流申し送りとする扱いを受け入れた)**
- **対応内容**: 変更しない。Round 1 のマトリクスに記録済み。

## [Critical] 自己検査 S9 / S10 がリポジトリの `.env` を読む

- **判断: 指摘を全面的に受け入れる。ただし「除去」ではなく「封じ込め + 目録化」で応じる**
- **実測して事実を確定させた** (議論ではなくデータで詰めた):

  S9 / S10 と同じ形 (アプリを起こすだけ) の子を起こし、**秘密の値そのものは出さずに
  「非空かどうかと長さ」だけ**を報告させた結果:

  | 設定キー | 子での状態 |
  |---|---|
  | `app.env` | 非空 (5 文字 = `local`) |
  | `services.stripe.secret` | `null` |
  | `cashier.secret` | 空 |
  | `filesystems.disks.s3.secret` | 空 |
  | `services.google.client_secret` | 空 |
  | `mail.mailers.smtp.password` | `null` |
  | **`database.connections.pgsql.password`** | **非空 (8 文字)** |
  | **`ciphersweet.providers.string.key`** | **非空 (64 文字)** |
  | 読んだ環境ファイル | **`.env`** |

  → **Codex が正しい。** 子はリポジトリの `.env` を読み、本チェックアウトでは
  外部サービスの資格情報こそ空だったが、**DB のパスワードと実 `CIPHERSWEET_KEY` は載った**。
  **「空だった」のはこのチェックアウトの性質であって保証ではない。**

- **なぜ同一プロセスのテストでは問題にならないのかも確定させた**:
  `phpunit.xml` が `<server name="STRIPE_SECRET" value="" force="true"/>` のように
  秘密を**強制的に無害化**している。しかし `<server force>` は **PHPUnit プロセスにしか効かず、
  `proc_open` で起こした子には及ばない**。これが子と同一プロセスの非対称の正体である。

- **それでも T249 で除去できない理由** (Round 1 から変えない):
  当該検体は**バイト一致で取り込んだ共有ファイルの中**にあり、書き換えると意図的逸脱の登録
  (`LedgerPins::DIVERGENCE_ENTRY_COUNT` の更新) が要る。T249 の受入条件は
  「取り込み 3 本を編集しない」である。

- **対応内容 (Codex の求めた「別の構造的境界」)**: 除去できない以上、
  **この危険面が申告なしに増えないことを機械で固定する**。軸 B の申告へ
  `boots_repository_env` を足し、**G-8** を新設した:
  1. `true` の集合が `['tests/Unit/Support/Process/BootProbeRunnerTest.php']` と**完全一致**
     (増減のどちらでも赤)
  2. `true` を申告してよいのは `tests/Unit/Support/Process/` 配下 =
     **バイト一致で取り込んだ共有ファイルだけ**。
     **aicue が自分で書いたファイルには `true` を申告できない**
  3. `child_entry` 以外 (`in_process` / `inventory`) は必ず `false`
  G-8 の docblock に、実測値・`<server force>` が子に及ばない機序・
  `fake-wiring-probe.php` が専用環境ファイルで回避している対比・
  **上流 (正典 v1) で解消されたら本 pin の `true` は 0 件になる**ことを書いた。

## [Critical] 全体テストの「2 回連続 green」

- **判断: 対応する**
- **根拠**: 指摘のとおり。green / fail / green は「2 回連続」ではない。
  無関係な flaky という分析は機械的な受入条件を置き換えない。
- **対応内容**: 上記の修正をすべて当てた**最終コード**で `composer test` を回し直し、
  **2 回連続 green** を得た:

  | 走行 | 結果 |
  |---|---|
  | final run 1 | **6500 tests / 6498 passed / 0 failed / 2 skipped** (598.3 秒) |
  | final run 2 | **6500 tests / 6498 passed / 0 failed / 2 skipped** (602.6 秒) |

## 性能測定の結論 (Codex の助言どおりに書き換えた)

「最小値どうしの比較は事後的で偏りやすいので合格の根拠にしない」という助言を受け入れ、
結論を **「(c) = 12.4 秒は安定して測れている / 全体比較は環境の雑音により判定不能」**
までに留める。**閾値は動かしていない。**


## Round 3 の Codex 返答 (全文) — ここで残った [Critical] が本ラウンドの主題

Round 3 のテスト証跡とP-16は受け入れます。ただし、G-8は危険面を可視化しただけで、現在の資格情報読み込みを封じ込めてはいません。T249は保留し、先に正典側を修正すべきです。

### `tests/Architecture/ExternalFakeBootProbeTest.php`

P-16は妥当です。正常例、`..`、`.`、相対パス、紛らわしい正常名を両方向で固定しており、Round 2 のSuggestionは解消されています。

判定: 指摘なし。

### `tests/Architecture/PhpBootProbeReferenceInventoryTest.php`

- [Critical] G-8は「構造的境界」ではなく、自己申告された目録です。`boots_repository_env` の値と実際の起動挙動を結び付ける検査がありません。

例えば以下の退行はG-8を通ります。

- `fake-wiring-probe.php` から `useEnvironmentPath()` を削除するが、inventoryは`false`のままにする
- 新しい`child_entry`がrepositoryの`.env`を読むが、inventoryでは`false`と申告する
- S9/S10以外の検体文字列へrepository起動を追加するが、ファイル単位の`true`件数は変わらない

G-8が機械的に保証するのは「`true`と申告したentryが1件」という事実だけで、「repositoryの`.env`を読む子が1件だけ」というテスト名の主張ではありません。したがって「危険面が申告なしに増えない」という説明はfail-openです。

G-8は上流課題を可視化する暫定台帳としては有用ですが、セキュリティ境界または現在の問題の緩和策とは扱えません。

### `tests/Unit/Support/Process/BootProbeRunnerTest.php`

- [Critical] S9/S10が実際のDBパスワードと`CIPHERSWEET_KEY`を読み込むことが実測で確定しました。Round 2 の懸念は仮説ではなく、現実の資格情報露出です。

「外部へ出力しない」だけでは安全境界になりません。

- 子プロセス内のproviderや将来のboot処理から利用可能
- runner自身が子の外部通信を保証していない
- 親テストレーンの`phpunit.xml`による無害化が子には効かない
- 子プロセス側では親のテストガード配線も前提にできない

バイト一致制約は重要ですが、セキュリティ不変条件より優先できません。危険な正典をそのまま取り込むことは、正典追従としても受け入れられません。

結論として、T249を保留し、先に正典側でS9/S10を専用の一時環境ファイルへ固定してください。資格情報の番兵をrepositoryの`.env`へ置いても子設定へ現れない自己検査を追加し、その修正版を再びバイト一致で取り込むのが整合的です。

### その他の変更ファイル

`FakeWiringProbeRunner.php`、`fake-wiring-probe.php`、`BootProbeRunner.php`、`BootProbeResult.php`、`StrictTypesRuntimeProbe.php`について、新しい指摘はありません。`BootProbeResult`のPHPDoc不整合は上流課題として残りますが、呼び出し側は依存していません。

### 受入条件

全体テスト2回連続greenを含む検証証跡は受け入れます。性能測定も「新規分12.4秒は安定／全体比較は雑音により判定不能」という結論で妥当です。

ただし、テストがgreenであることはS9/S10の資格情報読み込みを無害化しません。今回の実測は、むしろ正典側の修正を先行させる根拠です。

全体判定: CHANGES_REQUESTED

## Round 3 への対応マトリクス (本ラウンドで実施した内容)

# 対応マトリクス: impl-review Round 3

Round 3 の Codex 判定は **CHANGES_REQUESTED**。指摘は 2 件 (いずれも [Critical]) で、
どちらも「取り込んだ自己検査 S9 / S10 の子がリポジトリの `.env` を読んで起動する」という
**同一の根**から出ている。以下はその 2 件を**この TODO の中で解消した**記録である。

> 補足 (経緯): Round 3 の時点の実装は「バイト一致の取り込みを崩せない」ことを理由に
> 除去ではなく目録 (G-8) での封じ込めを選び、Codex は「G-8 は自己申告であって境界ではない /
> 正典側を先に直せ」と裁定した。**正典側 (laravel-claude-template) を本セッションから
> 変更する手段が無い**ため、裁定の趣旨 (= 子がリポジトリの `.env` を読まないことを
> **実挙動で**固定する) を aicue 側で満たす形へ切り替えた。

---

## [Critical] S9 / S10 が実際の DB パスワードと `CIPHERSWEET_KEY` を読み込む

- 判断: **対応する** (バイト一致の制約を捨てて修正する)
- 根拠:
  - Codex の指摘のとおり、**バイト一致はセキュリティ不変条件より優先できない**
    (AGENTS.md §セキュリティ不変条件「アプリ都合で緩めない」)。
  - さらに強い根拠として、**この漏れは正典 v1 (2) 自身に反している**。正典 (2) は
    「開発者ローカルの環境変数を入力集合から外す」ことを求めており、
    `proc_open` の環境配列を統制点にすることでそれを担保する設計である。
    ところが子が `bootstrap/app.php` を素で読むと Laravel は**環境ファイル**という
    別経路で開発者ローカルの値を設定へ載せてしまう。
    **したがってこの修正は「正典からの逸脱」ではなく「正典への適合」である。**
  - 機械的な代価も確認した: 取り込む 3 パスは aicue の
    `docs/template-fingerprints.json` のキーにも `adoption-debt.tsv` にも**無い**ので、
    編集しても突合 gate は赤くならず、意図的逸脱の登録も `LedgerPins` の件数更新も**発生しない**
    (将来 指紋台帳を再生成したときに 1 件の登録が要るだけである)。
- 対応内容:
  1. `tests/Unit/Support/Process/BootProbeRunnerTest.php` の検体
     `BOOT_PROBE_PATH_REPORT` に **1 行**足した —
     `$app->useEnvironmentPath(dirname((string) getenv('LARAVEL_STORAGE_PATH')));`。
     予約鍵から起動器の一時ディレクトリを導き、環境ファイルの置き場所をそこへ逃がす。
     一時ディレクトリに `.env` は無いので `safeLoad()` は何も読まず、
     **設定の入力は `proc_open` へ渡した環境配列だけ**になる。
     併せて S9 / S10 のケース別上書きへ `APP_ENV=testing` を足した
     (`.env` を読まないと `app.env` の既定が `production` になり `ProductionEnvGuard` が
     起動を止めるため。**ケース別上書き = 正典 (2) の第 3 段**であり、統制点は 1 つのままである)。
  2. 検体の報告に `env_file_path` / `ciphersweet_key_digest` / `db_password_digest` を足し、
     **S9 が実挙動で 2 方向から測る**ようにした —
     (a) 子が読んだ環境ファイルの場所が一時ディレクトリ配下であること
     (Laravel は `environmentFilePath()` の 1 本しか読まないので、これが決定的である)、
     (b) **番兵**: リポジトリの `.env` に実在する `CIPHERSWEET_KEY` / `DB_PASSWORD` の値が
     子の設定に**現れない**こと。番兵が `.env` に無い / 空のときは
     「この検査が空振りする」と明示して**赤にする** (空振りの緑を作らない)。
  3. 逸脱の理由・実測・限界を当該 const の docblock に逐語で書いた
     (バイト一致からの意図的な逸脱であること、なぜセキュリティを優先したか)。
- 負の裏取り (実測):
  - 修正**前**の形 (置き場所を移さない) で子を起こすと
    `env_file_path = <repo>/.env` かつ `ciphersweet_key_digest` が
    **リポジトリの `.env` の値の digest と一致**した (= 漏れの再現)。
  - 修正**後**は `env_file_path` が一時ディレクトリ配下になり、番兵の digest は一致しない。
  - 自己検査 14 本すべて緑。

## [Critical] G-8 は自己申告の目録であり、境界でも緩和策でもない

- 判断: **対応する** (指摘を全面的に受諾)
- 根拠: Round 3 の指摘は正しい。旧 G-8 は
  「`true` と申告した entry が 1 件」という事実しか固定しておらず、
  「リポジトリの `.env` を読む子が 1 件だけ」というテスト名の主張とは距離があった (fail-open)。
- 対応内容: 上の修正で危険面そのものが消えたので、G-8 を**目録から不変条件へ**書き換えた:
  1. `boots_repository_env` が真の entry は**ちょうど 0 件**である (完全一致 pin)。
  2. `child_entry` の entry は **`behaviour_proof` (裏取りの検査の名指し) を必ず持つ**
     (空では通らない)。子入口を足す人は「この子が `.env` を読まないことを何が測るのか」を
     書くことになる。
  3. `child_entry` 以外は `boots_repository_env` が偽 **かつ** `behaviour_proof` が空である
     ことを両方向で固定する (kind の取り違えの検出)。
  4. `child_entry` の母集団が空のまま緑になる形を塞いだ
     (AGENTS.md §静的検査の共通規約 (b) の 3 点目)。
  5. docblock の「主張しないこと」を書き直した — 本検査が機械で見るのは
     **申告と名指しの存在**までであり、名指しした検査が実際に何を測っているかは見ない。
     **実挙動の防壁は名指しされた 2 本 (S9 / P-8) そのものである**と明記した。

## [Warning] `BootProbeResult` の PHPDoc の食い違い (`timedOut && exitCode === 0`)

- 判断: **見送る** (Round 2 の判断を維持。Codex も「上流申し送りとする判断は受け入れる」と応答済み)
- 根拠: 呼び出し側は `timedOut` を見る契約 (詳細設計 E 節) で、誤記に依存していない。
  取り込み元の文面であり、実行時のバグではない。

## 受入条件・性能測定に関する指摘

- 判断: **対応する** (全体テストの連続 green を取り直す)
- 根拠: Round 2 の [Critical]「green / fail / green は連続条件未達」は機械的な受入条件である。
- 対応内容: 本ラウンドでは main を取り込み直した (`todo/T249` へ `main` をマージ) うえで
  全体テストを走らせ直し、連続 green を取得する。

---

## main の前進に追随した変更 (Round 3 以降に発生。Codex 未レビュー分)

`todo/T249` の分岐後に main が 4 タスク分前進し、**子プロセスを起こす別 feature の実装**
(`process-concurrency-test-harness`) が入った。本 gate は deny-by-default なので、
これを申告するまで赤になる (= 意図した摩擦が実際に働いた)。

| 変更 | 内容 |
|---|---|
| 軸 A に 1 件追加 | `tests/Support/Concurrency/SymfonyProbeProcessFactory.php` (`launches_app: true`) |
| **G-2 を 1 件固定 → 2 件の完全一致 pin へ** | 本 feature の boundary は「子を 2 本立てて合図で同期させる並行テスト」を明示的に**除く**。別 feature が自分の回収規約 (単一の絶対 deadline) を持つので、1 本の起動器へ統合するのは「別物の概念を似ているからで統合する」ことになる (AGENTS.md 思考原則 4)。固定するのは**申告先の集合そのもの**であり「起動経路が 1 本」ではない (それは字句走査では裏が取れない、と docblock が既に明記している) |
| 軸 B に 2 件追加 | `tests/Support/Concurrency/idempotency-claim-probe.php` (`child_entry`。専用の一時 env ファイルへ固定するので `boots_repository_env: false`、裏取りは子の終了コード 70 / 72 の自己検査) / `tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php` (`inventory`) |


---

## Round 4 の修正差分だけ (Round 3 の指摘に対する変更。**ここを重点的に見てほしい**)

```diff
diff --git a/tests/Architecture/PhpBootProbeReferenceInventoryTest.php b/tests/Architecture/PhpBootProbeReferenceInventoryTest.php
index 1f45c343..82cceef0 100644
--- a/tests/Architecture/PhpBootProbeReferenceInventoryTest.php
+++ b/tests/Architecture/PhpBootProbeReferenceInventoryTest.php
@@ -92,7 +92,16 @@ function phpBootProbeBinaryReferenceInventory(): array
             'launches_app' => false,
             'subject' => '起動器の自己検査。参照は期待値の比較と、子へ渡す検体文字列の中だけである',
             'recovery' => '起動器 (本ファイルは直接の起動 API を持たず、BootProbeRunner 経由でのみ子を起こす)',
-            'reason' => 'バイト一致で取り込んだ共有ファイルなので編集しない。起動器を通してしか子を起こさない',
+            'reason' => 'テンプレートから取り込んだ共有ファイルである (T249 のローカル修正 1 件を除いて '
+                .'バイト一致。修正の理由は当該 docblock)。起動器を通してしか子を起こさない',
+        ],
+        'tests/Support/Concurrency/SymfonyProbeProcessFactory.php' => [
+            'launches_app' => true,
+            'subject' => '実プロセス 2 本を合図で同期させる並行テストの子を起こす (子はアプリを起動する)',
+            'recovery' => '同 harness の runner (単一の絶対 deadline + 段階的強制終了。Symfony 側の制限時間は無効化)',
+            'reason' => '別 feature (lctl: process-concurrency-test-harness) の正典 v1 が持つ回収規約に属する。'
+                .'本 feature (subprocess-boot-probe-harness) の boundary は「子を 2 本立てて合図で同期させる '
+                .'並行テスト」を明示的に除いているので、共通の起動器へは載せない',
         ],
         'tests/Support/StrictTypesRuntimeProbe.php' => [
             'launches_app' => false,
@@ -140,10 +149,13 @@ function phpBootProbeBinaryReferenceInventory(): array
  *  - `inventory`   : 検査定義・診断文としてパス文字列を保持するだけ
  *
  * `boots_repository_env` は「その経路で起きた**子**が、リポジトリの `.env` を読んで起動するか」。
- * **これは望ましさの宣言ではなく、危険面の目録である** (G-8 が件数と場所を pin する)。
+ * **0 件であることが不変条件である** (G-8 が完全一致で pin する)。
+ *
+ * `behaviour_proof` は「その申告を**実挙動で**裏取りしている検査の名前」。
+ * `child_entry` では**必須**で、それ以外の kind では空文字にする (子が居ないので裏取りの対象が無い)。
  * 詳細は G-8 の docblock を読むこと。
  *
- * @return array<string, array{kind: 'child_entry'|'in_process'|'inventory', boots_repository_env: bool, reason: non-empty-string}>
+ * @return array<string, array{kind: 'child_entry'|'in_process'|'inventory', boots_repository_env: bool, behaviour_proof: string, reason: non-empty-string}>
  */
 function phpBootProbeAppBootEntryReferenceInventory(): array
 {
@@ -152,44 +164,71 @@ function phpBootProbeAppBootEntryReferenceInventory(): array
             'kind' => 'child_entry',
             // 専用の 0600 環境ファイルへ固定して起動する (リポジトリの .env は読まない)。
             'boots_repository_env' => false,
+            'behaviour_proof' => 'tests/Architecture/ExternalFakeBootProbeTest.php P-8 '
+                .'(子で実際に効いた鍵の digest が、専用環境ファイルへ書いた使い捨て値と一致し、'
+                .'親の設定値とは一致しないことを測る)',
             'reason' => '偽の外部サービスの配線を実起動で観測する子入口。起こすのは共通の起動器である',
         ],
+        'tests/Support/Concurrency/idempotency-claim-probe.php' => [
+            'kind' => 'child_entry',
+            // 段 8 で useEnvironmentPath() / loadEnvironmentFrom() を専用の一時 env ファイルへ向ける。
+            'boots_repository_env' => false,
+            'behaviour_proof' => 'tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php '
+                .'(子が段 6 で受け取ったプロセス環境を、段 9 で実効 DB 座標を自己検査し、'
+                .'違反なら終了コード 70 / 72 で落ちる = 親が非ゼロで赤くなる)',
+            'reason' => '実プロセス並行テストの子入口。別 feature (process-concurrency-test-harness) の持ち物である',
+        ],
         'tests/Unit/Support/Process/BootProbeRunnerTest.php' => [
             'kind' => 'child_entry',
-            // ★S9 / S10 の検体はリポジトリ root を作業ディレクトリにして bootstrap/app.php を
-            //   読むため、**リポジトリの .env がそのまま子の設定に載る** (実測で確認済み。G-8)。
-            'boots_repository_env' => true,
+            // ★T249 のローカル修正で、S9 / S10 の検体は起動前に環境ファイルの置き場所を
+            //   起動器の一時ディレクトリへ逃がす (取り込み元の姿ではリポジトリの .env を読んでいた)。
+            'boots_repository_env' => false,
+            'behaviour_proof' => 'tests/Unit/Support/Process/BootProbeRunnerTest.php S9 '
+                .'(子が報告した環境ファイルの場所が一時ディレクトリ配下であること + '
+                .'リポジトリの .env に実在する CIPHERSWEET_KEY / DB_PASSWORD が子の設定に現れないこと)',
             'reason' => '起動器の自己検査が子へ渡す検体文字列 (`-r` のソース) の中にある',
         ],
         'tests/TestCase.php' => [
             'kind' => 'in_process',
             // 同一プロセスなので phpunit.xml の <server force> が効く (秘密は無害化済み)。
             'boots_repository_env' => false,
+            'behaviour_proof' => '',
             'reason' => 'テスト本体のアプリ生成 (同一プロセス)。子プロセスではない',
         ],
         'tests/Support/Cache/IsolatedApplicationProbe.php' => [
             'kind' => 'in_process',
             'boots_repository_env' => false,
+            'behaviour_proof' => '',
             'reason' => 'キャッシュ受け皿の結線を測るための第 2 のアプリを同一プロセスで組み立てる。子プロセスではない',
         ],
         'tests/Architecture/CacheGuardWiringGateTest.php' => [
             'kind' => 'inventory',
             'boots_repository_env' => false,
+            'behaviour_proof' => '',
             'reason' => 'TestCase の結線を字句で固定する検査が、期待するトークン列としてパス文字列を持つ',
         ],
         'tests/Architecture/BughuntExecutedRouteOrderingTest.php' => [
             'kind' => 'inventory',
             'boots_repository_env' => false,
+            'behaviour_proof' => '',
             'reason' => '記録器の位置を固定する検査が、違反時の直し方を案内する診断文にパス文字列を持つ',
         ],
         'tests/Architecture/InertiaErrorScreenContractTest.php' => [
             'kind' => 'inventory',
             'boots_repository_env' => false,
+            'behaviour_proof' => '',
             'reason' => '例外応答の最終整形スロットの登録位置を検査する側が、照合する場所としてパス文字列を持つ',
         ],
+        'tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php' => [
+            'kind' => 'inventory',
+            'boots_repository_env' => false,
+            'behaviour_proof' => '',
+            'reason' => '撤去表面の走査対象の定義が、走査根の 1 つとしてパス文字列を持つ',
+        ],
         'tests/Architecture/PhpBootProbeReferenceInventoryTest.php' => [
             'kind' => 'inventory',
             'boots_repository_env' => false,
+            'behaviour_proof' => '',
             'reason' => '本 gate 自身。走査の針としてパス文字列を持つ (自分を走査対象から外さない)',
         ],
     ];
@@ -381,13 +420,30 @@ function phpBootProbeDeclaredPaths(array $inventory): array
     );
 });
 
-test('G-2 軸 A: アプリを起こすと申告するのは共通の起動器ちょうど 1 件である', function (): void {
+/**
+ * G-2: 「アプリを起こす」と申告してよい起こし手の**完全一致 pin**。
+ *
+ * ★**1 件ではなく 2 件である**。本 feature (subprocess-boot-probe-harness) の boundary は
+ *   「子を 2 本立てて合図で同期させる並行テスト」を明示的に**除いて**おり、そちらは別 feature
+ *   (lctl: process-concurrency-test-harness) が自分の回収規約 (単一の絶対 deadline) を持つ。
+ *   両者を 1 本の起動器へ統合するのは「別物の概念を似ているからで統合する」ことになる
+ *   (AGENTS.md 思考原則 4)。
+ * ★したがって本検査が固定するのは**申告先の集合そのもの**であり、
+ *   「起動経路が 1 本である」ことではない (それは字句走査では裏が取れない。冒頭の
+ *   「主張しないこと」1 を参照)。3 本目が現れたら**どちらの feature の規約に属するのか**を
+ *   申告に書くことになり、レビューに必ず見える。
+ */
+test('G-2 軸 A: アプリを起こすと申告する起こし手が完全一致で pin されている', function (): void {
     $launching = array_keys(array_filter(
         phpBootProbeBinaryReferenceInventory(),
         static fn (array $entry): bool => $entry['launches_app'],
     ));
+    sort($launching);
 
-    expect($launching)->toBe(['tests/Support/Process/BootProbeRunner.php']);
+    expect($launching)->toBe([
+        'tests/Support/Concurrency/SymfonyProbeProcessFactory.php',
+        'tests/Support/Process/BootProbeRunner.php',
+    ]);
 });
 
 test('G-3 軸 A: subject / recovery / reason の 3 欄がいずれも空でない', function (): void {
@@ -457,57 +513,57 @@ function phpBootProbeDeclaredPaths(array $inventory): array
 });
 
 /**
- * G-8: リポジトリの `.env` を読んで起動する**子**の目録 (危険面の pin)。
+ * G-8: 子プロセスがリポジトリの `.env` を読んで起動しないこと (申告 0 件の pin + 裏取りの名指し)。
  *
- * ## 何を測っているか
+ * ## 何を守っているか
  *
  * 共通の起動器は `proc_open` へ渡す環境配列で開発者ローカルの env を締め出すが、
  * **`.env` ファイルの読み込みまでは止めない**。子の作業ディレクトリはリポジトリ root なので、
- * 子が `bootstrap/app.php` を素で読むと Laravel は**リポジトリの `.env` をそのまま**設定へ載せる。
+ * 子が `bootstrap/app.php` を**素で**読むと Laravel は**リポジトリの `.env` をそのまま**設定へ載せる。
+ * これは正典 v1 (2) の「開発者ローカルの環境変数を入力集合から外す」を、
+ * 環境変数ではなく**環境ファイル**の経路で迂回してしまう形である。
  *
- * **実測 (T249 実装時、本 worktree)**: 取り込んだ自己検査の S9 / S10 が使う検体でこれを確かめたところ、
- * 子の設定には `.env` 由来の値が入っていた — 外部サービスの資格情報
+ * **実測 (T249 実装時、本 worktree)**: 取り込んだ自己検査 S9 / S10 の検体を取り込み元の姿
+ * (環境ファイルの置き場所を移さない形) で走らせると、子の設定に `.env` 由来の
+ * **DB のパスワードと実 `CIPHERSWEET_KEY`** が載った。外部サービスの資格情報
  * (Stripe / AWS / Google / SMTP) は本チェックアウトではいずれも空だったが、
- * **DB のパスワードと `CIPHERSWEET_KEY` は実値が載った**。
- * **「空だった」のはこのチェックアウトの性質であって、保証ではない。**
- *
- * ## なぜ止めずに目録にするのか
+ * **「空だった」のはこのチェックアウトの性質であって保証ではない。**
+ * この実測を受けて S9 / S10 の検体には**起動前に環境ファイルの置き場所を一時ディレクトリへ
+ * 逃がす 1 行**を入れた (取り込み元からの意図的な逸脱。理由は当該 docblock)。
  *
- * 当該検体は**テンプレートからバイト一致で取り込んだ共有ファイル**の中にあり、
- * ここで書き換えると意図的逸脱の登録が要る (T249 の受入条件は「取り込み 3 本を編集しない」)。
- * したがって本 gate は**除去ではなく封じ込め**を担う —
- * この性質を持つ経路が**申告なしに増えない**ことだけを機械で固定する。
+ * ## 何を機械で固定しているか
  *
- * ## 対比 (なぜ他の経路は false なのか)
+ *  1. `boots_repository_env` が真の entry は**ちょうど 0 件**である (完全一致 pin)。
+ *     真を 1 件足すには申告を書き換えることになり、レビューに必ず見える
+ *  2. `child_entry` の entry は**裏取りの検査を名指しする欄 (`behaviour_proof`) を必ず持つ**。
+ *     空では通らないので、子入口を足す人は「この子が `.env` を読まないことを何が測るのか」を
+ *     書くことになる
+ *  3. `child_entry` 以外 (`in_process` / `inventory`) は定義上この危険面を持たないので、
+ *     `boots_repository_env` が偽であること・`behaviour_proof` が空であることを両方向で固定する
+ *     (取り違えの検出)
  *
- *  - 同一プロセスの起動 (`tests/TestCase.php` 等) は `phpunit.xml` の `<server force="true">` が
- *    効くため、Stripe / LLM の鍵は空か dummy に無害化されている。
- *    **`<server force>` は PHPUnit プロセスにしか効かず、`proc_open` の子には及ばない** —
- *    これが子と同一プロセスの非対称の正体である
- *  - `fake-wiring-probe.php` は専用の 0600 環境ファイルへ `useEnvironmentPath()` /
- *    `loadEnvironmentFrom()` で固定するので、リポジトリの `.env` を読まない
+ * ## 対比 (なぜ同一プロセスは対象外なのか)
  *
- * ## 主張しないこと (誇張しない。Codex 実装レビュー Round 3 の指摘)
+ * 同一プロセスの起動 (`tests/TestCase.php` 等) は `phpunit.xml` の `<server force="true">` が
+ * 効くため、Stripe / LLM の鍵は空か dummy に無害化されている。
+ * **`<server force>` は PHPUnit プロセスにしか効かず、`proc_open` の子には及ばない** —
+ * これが子と同一プロセスの非対称の正体である。
  *
- * **本検査が機械的に確かめるのは「申告」であって「実挙動」ではない。**
- * `boots_repository_env` の値と、その経路の子が実際に何を読むかを結び付ける検査は**持っていない**。
- * したがって次の退行は**本検査を通ってしまう**:
+ * ## 主張しないこと (誇張しない)
  *
- *  1. `fake-wiring-probe.php` から `useEnvironmentPath()` を落としつつ申告を `false` のままにする
- *  2. 新しい `child_entry` が `.env` を読むのに `false` と申告する
- *  3. 既存の `true` のファイルの中で、`.env` を読む検体を増やす (ファイル単位の件数は変わらない)
+ * **本検査が機械で確かめるのは「申告」と「裏取りの名指しが在ること」であって、
+ * 名指しした検査が実際に何を測っているかではない。** したがって次は本検査を通る:
  *
- * **よって本検査はセキュリティ境界ではなく、上流課題を見える場所に置くための暫定の台帳である。**
- * 「危険面が申告なしに増えない」とは読めない (読めるのは「申告が黙って書き換わらない」までである)。
+ *  1. `behaviour_proof` に実在しない検査名や、実は何も測っていない検査名を書く
+ *  2. 既存の `child_entry` の中で、`.env` を読む検体を**増やす** (ファイル単位の申告は変わらない)
  *
- * ## 上流への申し送り (本検査では代替できない)
- *
- * 正典側 (lctl feature: subprocess-boot-probe-harness) で
- * 「アプリを起こす自己検査の子にも専用の環境ファイルを読ませる」ことを**先に**行うべきである。
- * 併せて「リポジトリの `.env` へ置いた番兵が子の設定に現れないこと」を測る自己検査があれば、
- * 実挙動の側で固定できる。解消されて再取り込みしたら、本 pin の `true` は 0 件になる。
+ * **実挙動の側の防壁は本検査ではなく、名指しされた検査そのものである** —
+ * `tests/Unit/Support/Process/BootProbeRunnerTest.php` の S9 (子が報告した環境ファイルの場所 +
+ * リポジトリの `.env` の実値が子の設定に現れないことの番兵) と
+ * `tests/Architecture/ExternalFakeBootProbeTest.php` の P-8 (子で効いた鍵の digest)。
+ * 本検査はその 2 本が**申告から外れないように束ねる目録**である。
  */
-test('G-8 リポジトリの .env を読むと申告した経路は 1 件だけである (申告の pin。実挙動は測らない)', function (): void {
+test('G-8 リポジトリの .env を読んで起動する子は 0 件で、child_entry は裏取りを名指しする', function (): void {
     $inventory = phpBootProbeAppBootEntryReferenceInventory();
 
     $bootsRepositoryEnv = array_keys(array_filter(
@@ -515,29 +571,37 @@ function phpBootProbeDeclaredPaths(array $inventory): array
         static fn (array $entry): bool => $entry['boots_repository_env'],
     ));
 
-    // ★件数と場所を完全一致で pin する。増やすには「なぜその子が .env を読んでよいのか」を
-    //   申告に書くことになり、レビューに必ず見える。
+    // ★件数と場所を完全一致で pin する (0 件)。増やすには申告を書き換えることになり、
+    //   「なぜその子が .env を読んでよいのか」がレビューに必ず見える。
     expect($bootsRepositoryEnv)->toBe(
-        ['tests/Unit/Support/Process/BootProbeRunnerTest.php'],
-        'リポジトリの .env を読んで起動する子が増減している。'
-        .'増やすなら G-8 の docblock を読み、なぜ専用の環境ファイルを使えないのかを申告すること',
+        [],
+        'リポジトリの .env を読んで起動する子が現れた。'
+        .'子の環境ファイルは専用の一時ファイルへ固定すること (G-8 の docblock)',
     );
 
-    // ★`true` を申告してよいのは**バイト一致で取り込んだ共有ファイル**だけである
-    //   (aicue が自分で書いたファイルには、専用の環境ファイルを使わない言い訳が無い)。
-    foreach ($bootsRepositoryEnv as $path) {
-        expect(str_starts_with($path, 'tests/Unit/Support/Process/'))
-            ->toBeTrue("aicue 所有のファイルがリポジトリの .env を読む子を持っている: {$path}");
-    }
+    $childEntries = [];
 
-    // ★子プロセスではない経路 (`in_process`) と検査定義 (`inventory`) は、
-    //   定義上この危険面を持たない。取り違えを防ぐために両方向で固定する。
     foreach ($inventory as $path => $entry) {
-        if ($entry['kind'] !== 'child_entry') {
-            expect($entry['boots_repository_env'])
-                ->toBeFalse("子プロセスではない経路に .env 読み込みが申告されている: {$path}");
+        if ($entry['kind'] === 'child_entry') {
+            $childEntries[] = $path;
+
+            // ★裏取りの名指しを必須にする (申告だけで済ませない)。
+            expect(trim($entry['behaviour_proof']))
+                ->not->toBe('', "child_entry に裏取りの名指し (behaviour_proof) が無い: {$path}");
+
+            continue;
         }
+
+        // ★子プロセスではない経路 (`in_process`) と検査定義 (`inventory`) は、
+        //   定義上この危険面を持たない。取り違えを防ぐために両方向で固定する。
+        expect($entry['boots_repository_env'])
+            ->toBeFalse("子プロセスではない経路に .env 読み込みが申告されている: {$path}")
+            ->and(trim($entry['behaviour_proof']))
+            ->toBe('', "子が居ない経路に裏取りの名指しがある (kind の取り違え): {$path}");
     }
+
+    // ★母集団が空のまま緑になる形を塞ぐ (AGENTS.md §静的検査の共通規約 (b) の 3 点目)。
+    expect($childEntries)->not->toBe([], 'child_entry が 1 件も無い (走査か申告が壊れている)');
 });
 
 test('G-7 走査が空振りしていない (走査根が実在し、3 軸の母集団が非空)', function (): void {
diff --git a/tests/Unit/Support/Process/BootProbeRunnerTest.php b/tests/Unit/Support/Process/BootProbeRunnerTest.php
index eefdd14a..d09fe415 100644
--- a/tests/Unit/Support/Process/BootProbeRunnerTest.php
+++ b/tests/Unit/Support/Process/BootProbeRunnerTest.php
@@ -38,14 +38,39 @@
     echo json_encode(getenv());
     PHP;
 
-/** アプリを起こして書き出し先を JSON で報告させる probe (S9 / S10)。 */
+/**
+ * アプリを起こして書き出し先を JSON で報告させる probe (S9 / S10)。
+ *
+ * ★**aicue のローカル修正 (T249)**: 取り込み元 (laravel-claude-template) の検体は
+ *   `bootstrap/app.php` を素で読むため、**リポジトリの `.env` がそのまま子の設定に載っていた**
+ *   (実測で確認: DB パスワードと実 `CIPHERSWEET_KEY`)。これは正典 v1 (2)
+ *   「開発者ローカルの環境変数を入力集合から外す」を、環境ファイル経由で迂回してしまう。
+ *   そこで**起動前に環境ファイルの置き場所を起動器の一時ディレクトリへ逃がす**。
+ *   一時ディレクトリに `.env` は無いので `safeLoad()` は何も読まず、設定の入力は
+ *   **`proc_open` へ渡した環境配列だけ**になる (= 正典 (2) の統制点が唯一になる)。
+ *   一時ディレクトリの絶対パスは予約鍵 `LARAVEL_STORAGE_PATH` (`<root>/storage`) から導き、
+ *   **取れなければ例外にする** (fail-closed。空文字で `useEnvironmentPath()` を呼ぶと
+ *   退避が無言で外れて `/` を環境ファイルの置き場所にしてしまう)。
+ *   実働は S9 の `env_file_path` / `ciphersweet_key_digest` が測る (申告ではなく実挙動)。
+ *   **バイト一致からの意図的な逸脱であり、その理由は上記のとおり
+ *   「セキュリティ不変条件はバイト一致より優先する」である** (AGENTS.md 禁止事項・
+ *   セキュリティ不変条件。詳細は devnotes の実装メモ)。
+ */
 const BOOT_PROBE_PATH_REPORT = <<<'PHP'
     require 'vendor/autoload.php';
     $app = require 'bootstrap/app.php';
+    $storagePath = getenv('LARAVEL_STORAGE_PATH');
+    if (! is_string($storagePath) || $storagePath === '') {
+        throw new RuntimeException('LARAVEL_STORAGE_PATH が無い (環境ファイルの退避先を導けない)');
+    }
+    $app->useEnvironmentPath(dirname($storagePath));
     $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
     Illuminate\Support\Facades\Log::info('boot-probe self check');
     echo json_encode([
         'php_binary' => PHP_BINARY,
+        'env_file_path' => $app->environmentFilePath(),
+        'ciphersweet_key_digest' => hash('sha256', (string) config('ciphersweet.providers.string.key')),
+        'db_password_digest' => hash('sha256', (string) config('database.connections.pgsql.password')),
         'storage' => $app->storagePath(),
         'config_cache' => $app->getCachedConfigPath(),
         'routes_cache' => $app->getCachedRoutesPath(),
@@ -197,7 +222,7 @@ static function (string $key): bool {
 });
 
 test('S9: 書き出し先の退避が効いている (向き) / 親と同じ実行体で起きる', function (): void {
-    $result = BootProbeRunner::run(['-r', BOOT_PROBE_PATH_REPORT], ['LOG_CHANNEL' => 'single']);
+    $result = BootProbeRunner::run(['-r', BOOT_PROBE_PATH_REPORT], ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'single']);
     expect($result->exitCode)->toBe(0, $result->stderr);
 
     /** @var array<string, string> $report */
@@ -206,6 +231,33 @@ static function (string $key): bool {
     // 正典 (1): 親と同じ実行体で起こす。
     expect($report['php_binary'])->toBe(PHP_BINARY);
 
+    // ★aicue のローカル修正 (T249) の実働証明 — **申告ではなく実挙動を測る**。
+    //   (a) 子が読んだ環境ファイルの場所が起動器の一時ディレクトリ配下であること
+    //       (Laravel は `environmentFilePath()` の 1 本しか読まないので、これが一時ディレクトリを
+    //        指していればリポジトリの `.env` は読まれていない)。
+    expect(BootProbeRunner::isInside($result->temporaryRoot, $report['env_file_path']))
+        ->toBeTrue("子がリポジトリ側の環境ファイルを読んでいる: {$report['env_file_path']}");
+
+    //   (b) 番兵による裏取り: リポジトリの `.env` に実在する資格情報が、子の設定に**現れない**。
+    //       (a) だけだと「読む先を移したが値は別経路で入った」形を排除できない。
+    $repositoryEnv = file_get_contents(base_path('.env'));
+    expect($repositoryEnv)->toBeString('リポジトリの .env が読めない (番兵を組み立てられない)');
+    assert(is_string($repositoryEnv));
+
+    foreach ([
+        'ciphersweet_key_digest' => 'CIPHERSWEET_KEY',
+        'db_password_digest' => 'DB_PASSWORD',
+    ] as $reportKey => $envKey) {
+        expect(preg_match('/^'.$envKey.'=(.+)$/m', $repositoryEnv, $matches))
+            ->toBe(1, "番兵にする {$envKey} がリポジトリの .env に無い (この検査が空振りする)");
+        $sentinel = trim($matches[1], "\"' \t\r\n");
+        expect($sentinel)->not->toBe('', "番兵にする {$envKey} が空 (この検査が空振りする)");
+        expect($report[$reportKey])->not->toBe(
+            hash('sha256', $sentinel),
+            "リポジトリの .env の {$envKey} が子の設定に載っている",
+        );
+    }
+
     foreach (['storage', 'config_cache', 'routes_cache', 'services_cache', 'packages_cache',
         'events_cache', 'view_compiled', 'log_path'] as $key) {
         expect(BootProbeRunner::isInside($result->temporaryRoot, $report[$key]))
@@ -214,7 +266,7 @@ static function (string $key): bool {
 });
 
 test('S10: 書き出し先の退避が効いている (実体) と後片付け', function (): void {
-    $result = BootProbeRunner::run(['-r', BOOT_PROBE_PATH_REPORT], ['LOG_CHANNEL' => 'single']);
+    $result = BootProbeRunner::run(['-r', BOOT_PROBE_PATH_REPORT], ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'single']);
 
     expect($result->exitCode)->toBe(0, $result->stderr)
         ->and($result->writtenRelativePaths)->toContain('storage/logs/laravel.log')

```

---

## T249 の実装差分 全体 (main との差分。tests/ 配下のみ。devnotes は除く)

```diff
diff --git a/tests/Architecture/ExternalFakeBootProbeTest.php b/tests/Architecture/ExternalFakeBootProbeTest.php
index e555fffe..9aecfd03 100644
--- a/tests/Architecture/ExternalFakeBootProbeTest.php
+++ b/tests/Architecture/ExternalFakeBootProbeTest.php
@@ -4,8 +4,9 @@
 
 use App\Support\ExternalFakes\ExternalFakeBinding;
 use App\Support\ExternalFakes\ExternalFakeDeclaration;
-use Symfony\Component\Process\Exception\ProcessTimedOutException;
 use Tests\Support\ExternalFakes\FakeWiringProbeRunner;
+use Tests\Support\Process\BootProbeResult;
+use Tests\Support\Process\BootProbeRunner;
 
 /*
  * 別プロセスで「宣言した差し替えが実際に効いているか」を実測する
@@ -15,9 +16,23 @@
  * 「実際の起動 (遅延読み込み provider・設定の解決順) でも効いているか」までは示せない。
  * ここでは子プロセスを起こし、起動しきったアプリの container から解決して観測する。
  *
- * ★子プロセスへ実際の外部資格情報を渡さない。プロセスの環境変数は `env -i` で空にし、
- *   設定は専用の一時環境ファイル 1 つだけから読む。書いてよいキーに外部サービスの
- *   資格情報は 1 つも無く、鍵の 2 つは使い捨ての生成値である (P-6 / P-7 / P-8)。
+ * ★子の起こし方・回収・書き出し先の退避は共通の起動器
+ *   (`Tests\Support\Process\BootProbeRunner`) が持つ
+ *   (lctl feature: subprocess-boot-probe-harness の正典 v1 (1)〜(5))。
+ *
+ * ★**子プロセスへ実際の外部資格情報を渡さない**。子の環境は**4 段**で組み立てる —
+ *   継承 (`PATH` / `HOME` / `TMPDIR`) → 基底 (`APP_KEY` / `QUEUE_CONNECTION` / `CACHE_STORE`) →
+ *   ケース別 (`FakeWiringProbeRunner::CASE_ENV_KEYS` の 3 件) → 予約 (書き出し先 7 キー)。
+ *   統制点は `proc_open` へ渡す環境配列であり、開発者ローカルの env はそこで締め出される (P-7)。
+ *
+ * ★**使い捨て鍵の置き場所は 2 つに分かれる**。`APP_KEY` は**ケース別上書き**、
+ *   `CIPHERSWEET_KEY` は**環境ファイル**である (Laravel の環境変数リポジトリは immutable で、
+ *   プロセス環境に既に在る値を Dotenv は上書きしないため)。どちらも親の実鍵の複写ではないこと、
+ *   かつ**子で実際に効いた**ことを P-8 が digest で測る。
+ *
+ * ★**正典 v1 (5) の実働証明**は P-13 (実体) と P-14 (向き) が持つ。「書き出し先を退避した」は、
+ *   退避が効いていなければ既定の場所へ書かれて観測が緑のまま嘘になるので、
+ *   子が `storage_path()` 経由で置いた印が起動器の一時ディレクトリ配下に現れることまで測る。
  *
  * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
  * キャッシュが古いときの本番事故は ProductionEnvGuard の二重判定が受け持つ。
@@ -57,11 +72,12 @@ function externalFakeProbeBaseDirectories(?string $add = null): array
  *     exitCode: int,
  *     output: array<string, mixed>,
  *     envFileValues: array<string, string>,
+ *     caseEnvValues: array<string, string>,
  *     directory: string,
  *     directoryMode: int,
  *     envFileMode: int,
- *     configCachePath: string,
- *     configCacheExists: bool,
+ *     temporaryRoot: string,
+ *     writtenRelativePaths: list<string>,
  *     baseDirectory: string,
  * }
  */
@@ -90,12 +106,51 @@ function externalFakeProbeRun(string $case): array
         $cache[$case] = [...$result, 'baseDirectory' => $base];
     }
 
-    /** @var array{exitCode: int, output: array<string, mixed>, envFileValues: array<string, string>, directory: string, directoryMode: int, envFileMode: int, configCachePath: string, configCacheExists: bool, baseDirectory: string} $entry */
+    /** @var array{exitCode: int, output: array<string, mixed>, envFileValues: array<string, string>, caseEnvValues: array<string, string>, directory: string, directoryMode: int, envFileMode: int, temporaryRoot: string, writtenRelativePaths: list<string>, baseDirectory: string} $entry */
     $entry = $cache[$case];
 
     return $entry;
 }
 
+/**
+ * 書き出し先が**正規化済みの絶対パス**であることを確かめる (`.` / `..` を 1 つも含まない)。
+ *
+ * ★`BootProbeRunner::isInside()` の契約は「両引数とも realpath 済み」である。ところが
+ *   書き出し先の多く (設定キャッシュ等) は**まだ存在しないファイル**なので realpath できず、
+ *   子が返す文字列をそのまま渡すことになる。ここを素通しにすると
+ *   `<一時 root>/../../<リポジトリ>/…` のような形が
+ *   「一時 root の配下かつリポジトリの外」と判定され、**実際にはリポジトリ内へ解決される**のに
+ *   P-11 / P-14 が緑のまま通る (fail-open)。
+ *   予約パスの組み立てに `..` が混じる退行を見逃さないため、配下判定の**前に**弾く。
+ */
+function externalFakeProbeIsNormalizedAbsolutePath(string $path): bool
+{
+    if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
+        return false;
+    }
+
+    foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
+        if ($segment === '.' || $segment === '..') {
+            return false;
+        }
+    }
+
+    return true;
+}
+
+/**
+ * 上の述語で書き出し先を検査する (診断文つき)。
+ *
+ * ★述語そのものの検出力は P-16 が**恒久の負例**で裏取りする
+ *   (実データが常に正常なので、この helper を空実装にしても P-11 / P-14 は緑のままになる。
+ *   AGENTS.md §静的検査の共通規約 (c) の「検出力は負例で裏取りする」に当たる)。
+ */
+function externalFakeProbeAssertNormalizedPath(string $path, string $label): void
+{
+    expect(externalFakeProbeIsNormalizedAbsolutePath($path))
+        ->toBeTrue("書き出し先 {$label} が正規化された絶対パスでない: {$path}");
+}
+
 /**
  * 観測結果の `resolved` を「解決キー => 実際に解決されたクラス」として取り出す。
  *
@@ -182,13 +237,33 @@ function externalFakeProbeResolved(array $output): array
         ->and(array_values(array_diff($keys, FakeWiringProbeRunner::ALLOWED_ENV_FILE_KEYS)))->toBe([]);
 });
 
-test('P-7 子が実際に受け取ったプロセス環境が許可した 3 件ちょうどである', function (): void {
-    $keys = externalFakeProbeRun('fake')['output']['process_environment_keys'] ?? null;
+test('P-7 子が実際に受け取ったプロセス環境が 4 段の合成結果と完全一致する', function (): void {
+    // (0) 4 段の定数そのものをリテラルで pin する。実装側の定数から期待値を組み立てるだけだと、
+    //     実装と期待値を同時に変えたときに緑のまま通ってしまう。
+    expect(BootProbeRunner::INHERITED_ENV_KEYS)->toBe(['PATH', 'HOME', 'TMPDIR'])
+        ->and(BootProbeRunner::RESERVED_ENV_KEYS)->toBe([
+            'LARAVEL_STORAGE_PATH',
+            'VIEW_COMPILED_PATH',
+            'APP_CONFIG_CACHE',
+            'APP_ROUTES_CACHE',
+            'APP_SERVICES_CACHE',
+            'APP_PACKAGES_CACHE',
+            'APP_EVENTS_CACHE',
+        ])
+        ->and(FakeWiringProbeRunner::CASE_ENV_KEYS)->toBe([
+            'FAKE_WIRING_PROBE_ENV_DIR',
+            'FAKE_WIRING_PROBE_ENV_FILE',
+            'APP_KEY',
+        ]);
+
+    $run = externalFakeProbeRun('fake');
+    $keys = $run['output']['process_environment_keys'] ?? null;
     expect($keys)->toBeArray();
     /** @var list<mixed> $keys */
     $actual = array_map(static fn (mixed $key): string => (string) $key, $keys);
 
-    // (b) 危険な接頭辞が 1 件も無いこと
+    // (a) 危険な接頭辞が 1 件も無いこと (env -i の時代からの主張をそのまま維持する)。
+    //     TESTING_FAKE_* は**プロセス環境へ載せない** (0600 の環境ファイルの中だけに置く)。
     foreach (['DB_', 'PG', 'AWS_', 'STRIPE_', 'TESTING_FAKE_', 'GOOGLE_'] as $prefix) {
         $leaked = array_values(array_filter(
             $actual,
@@ -197,19 +272,43 @@ function externalFakeProbeResolved(array $output): array
         expect($leaked)->toBe([], "禁止する接頭辞 {$prefix} のキーが子へ流れている");
     }
 
-    // (a)(c) 許可した 3 件がすべて存在し、それ以外の余りが無いこと (deny-by-default)
-    $expected = FakeWiringProbeRunner::ALLOWED_PROCESS_ENV_KEYS;
+    // (b) 集合の完全一致 (deny-by-default)。「以下」ではないので 1 本足しただけで赤くなる。
+    $inherited = array_values(array_filter(
+        ['PATH', 'HOME', 'TMPDIR'],
+        static function (string $key): bool {
+            $value = getenv($key);
+
+            return is_string($value) && $value !== '';
+        },
+    ));
+    $expected = array_values(array_unique(array_merge(
+        $inherited,
+        ['APP_KEY', 'QUEUE_CONNECTION', 'CACHE_STORE'],
+        ['FAKE_WIRING_PROBE_ENV_DIR', 'FAKE_WIRING_PROBE_ENV_FILE', 'APP_KEY'],
+        ['LARAVEL_STORAGE_PATH', 'VIEW_COMPILED_PATH', 'APP_CONFIG_CACHE',
+            'APP_ROUTES_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_EVENTS_CACHE'],
+    )));
     sort($actual);
     sort($expected);
 
     expect($actual)->toBe($expected);
 });
 
-test('P-8 一時環境ファイルの鍵は親の設定値の複写ではない', function (): void {
-    $values = externalFakeProbeRun('fake')['envFileValues'];
+test('P-8 使い捨て鍵が子で実際に効き、親の設定値の複写ではない', function (): void {
+    $run = externalFakeProbeRun('fake');
 
-    expect($values['APP_KEY'] ?? null)->not->toBe(config('app.key'))
-        ->and($values['CIPHERSWEET_KEY'] ?? null)->not->toBe(config('ciphersweet.providers.string.key'));
+    $digests = $run['output']['key_digests'] ?? null;
+    expect($digests)->toBeArray();
+    /** @var array<string, mixed> $digests */
+
+    // (a) 子で効いた APP_KEY が、起動側が生成した使い捨て値と一致する
+    expect($digests['app'] ?? null)->toBe(hash('sha256', $run['caseEnvValues']['APP_KEY']));
+    // (b) 子で効いた CIPHERSWEET_KEY が、環境ファイルへ書いた使い捨て値と一致する
+    expect($digests['ciphersweet'] ?? null)->toBe(hash('sha256', $run['envFileValues']['CIPHERSWEET_KEY']));
+    // (c) いずれも親の設定値の複写ではない
+    expect($digests['app'])->not->toBe(hash('sha256', (string) config('app.key')))
+        ->and($digests['ciphersweet'])
+        ->not->toBe(hash('sha256', (string) config('ciphersweet.providers.string.key')));
 });
 
 test('P-9 一時ディレクトリ 0700 / 環境ファイル 0600 であり、違えば子を起こさない', function (): void {
@@ -225,7 +324,7 @@ function externalFakeProbeResolved(array $output): array
         ->toThrow(RuntimeException::class);
 });
 
-test('P-10 正常終了・非ゼロ終了・timeout のいずれでも一時ディレクトリが残らない', function (): void {
+test('P-10 正常終了・非ゼロ終了のいずれでも環境ファイルの置き場所が残らない', function (): void {
     foreach (['fake', 'real', 'production'] as $case) {
         $run = externalFakeProbeRun($case);
 
@@ -233,27 +332,124 @@ function externalFakeProbeResolved(array $output): array
             ->and(array_values(array_diff(scandir($run['baseDirectory']) ?: [], ['.', '..'])))
             ->toBe([], "一時ディレクトリの親に残骸がある: {$case}");
     }
+});
 
-    // timeout でも finally を必ず通ること。
-    $base = sys_get_temp_dir().'/fake-wiring-probe-base-'.bin2hex(random_bytes(6));
-    expect(mkdir($base, 0700))->toBeTrue();
+test('P-10b 作れない置き場所では子を起こさずに失敗し、残骸を残さない', function (): void {
+    $base = sys_get_temp_dir().'/fake-wiring-probe-readonly-'.bin2hex(random_bytes(6));
+    expect(mkdir($base, 0500))->toBeTrue();
 
     try {
-        expect(fn (): array => FakeWiringProbeRunner::run('bughunt.local', true, true, false, $base, 0.01))
-            ->toThrow(ProcessTimedOutException::class);
+        // ★失敗の**段**まで固定する。message を見ないと「子を起こしたあとで別の理由で
+        //   落ちた」場合も緑になり、「子を起こさずに」の部分が主張だけになる。
+        //   この message は置き場所の検査 (= 子を起こす前) だけが投げる。
+        expect(fn (): array => FakeWiringProbeRunner::run('bughunt.local', true, true, false, $base))
+            ->toThrow(RuntimeException::class, '観測用の置き場所を使用できない');
 
         expect(array_values(array_diff(scandir($base) ?: [], ['.', '..'])))->toBe([]);
     } finally {
         rmdir($base);
     }
+})->skip(
+    // root で走ると 0500 でも書けてしまい、負のコントロールが成立しない。
+    // **成功扱いにはしない** — 測れていないことをテスト結果に出す。
+    fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0,
+    'root では書き込み権限の負のコントロールを作れない',
+);
+
+test('P-10c 本体が例外を投げても置き場所が中身ごと消える (制限時間超過の後始末)', function (): void {
+    // 制限時間超過は interpret() が例外にする (P-15)。その例外が外側の finally を通ることを
+    // ここで決定的に測る (実 timeout を作るには子を 1 秒以上眠らせる必要があり、
+    // それは観測用スクリプトの責務を汚すので採らない)。
+    // ★空のディレクトリではなく**中身のある**状態で測る — 実際の制限時間超過では
+    //   .env.probe が既に書かれているので、再帰削除まで示さないと主張と距離がある。
+    $base = sys_get_temp_dir().'/fake-wiring-probe-base-'.bin2hex(random_bytes(6));
+    expect(mkdir($base, 0700))->toBeTrue();
+
+    $created = null;
+
+    try {
+        expect(function () use ($base, &$created): mixed {
+            return FakeWiringProbeRunner::withEnvironmentDirectory(
+                $base,
+                static function (string $directory) use (&$created): mixed {
+                    $created = $directory;
+
+                    // 実際の走行と同じく環境ファイルを置き、さらに下位ディレクトリの中にも番兵を置く。
+                    expect(file_put_contents($directory.'/.env.probe', "APP_ENV=x\n"))->not->toBeFalse();
+                    expect(mkdir($directory.'/nested', 0700))->toBeTrue();
+                    expect(file_put_contents($directory.'/nested/sentinel.txt', 'x'))->not->toBeFalse();
+
+                    throw new RuntimeException('本体の失敗');
+                },
+            );
+        })->toThrow(RuntimeException::class);
+
+        // 置き場所は作られ (= 検査が空振りしていない)、中身ごと消えている。
+        expect($created)->toBeString()
+            ->and(is_dir((string) $created))->toBeFalse('置き場所が残っている')
+            ->and(array_values(array_diff(scandir($base) ?: [], ['.', '..'])))->toBe([]);
+    } finally {
+        rmdir($base);
+    }
+});
+
+test('P-10d リポジトリ内の置き場所は本体を呼ばずに拒否し、残骸を残さない', function (): void {
+    // 正典 v1 (5) の fail-closed を**外側**でも測る (内側は取り込んだ自己検査 S11 が持つ)。
+    $base = base_path('storage/framework/testing');
+
+    // ★このテストが作った階層を**1 つ残らず**戻す (走行が生成物を残さないため)。
+    //   `mkdir(recursive)` + `rmdir($base)` だけだと、親を新規作成した環境
+    //   (新しい checkout など) で `storage/framework` が残る。
+    $createdAncestors = [];   // 深い順
+    for ($candidate = $base; ! is_dir($candidate); $candidate = dirname($candidate)) {
+        $createdAncestors[] = $candidate;
+    }
+    foreach (array_reverse($createdAncestors) as $directory) {
+        expect(mkdir($directory, 0755))->toBeTrue("後始末の対象を作れない: {$directory}");
+    }
+
+    try {
+        $before = glob($base.'/fake-wiring-probe-*');
+        expect($before)->toBeArray();
+
+        $bodyCalled = false;
+
+        expect(function () use ($base, &$bodyCalled): mixed {
+            return FakeWiringProbeRunner::withEnvironmentDirectory(
+                $base,
+                static function (string $directory) use (&$bodyCalled): mixed {
+                    $bodyCalled = true;
+
+                    return $directory;
+                },
+            );
+        })->toThrow(RuntimeException::class);
+
+        expect($bodyCalled)->toBeFalse('リポジトリ内なのに本体が呼ばれた')
+            ->and(glob($base.'/fake-wiring-probe-*'))->toBe($before, '拒否経路が残骸を残している');
+    } finally {
+        // 深い順に戻す (作った分だけ)。
+        foreach ($createdAncestors as $directory) {
+            rmdir($directory);
+        }
+    }
 });
 
-test('P-11 設定キャッシュの指し先は一時ディレクトリ配下の絶対パスで、存在しない', function (): void {
+test('P-11 設定キャッシュの退避先が一時ディレクトリ配下で、書かれていない', function (): void {
     $run = externalFakeProbeRun('fake');
 
-    expect(str_starts_with($run['configCachePath'], '/'))->toBeTrue()
-        ->and(str_starts_with($run['configCachePath'], $run['directory'].'/'))->toBeTrue()
-        ->and($run['configCacheExists'])->toBeFalse();
+    $targets = $run['output']['write_targets'] ?? null;
+    expect($targets)->toBeArray();
+    /** @var array<string, mixed> $targets */
+    $configCache = $targets['config_cache'] ?? null;
+    expect($configCache)->toBeString();
+    /** @var string $configCache */
+    // 配下判定の前に正規化を確かめる (`..` 経由でリポジトリへ戻る形を通さない)。
+    externalFakeProbeAssertNormalizedPath($configCache, 'config_cache');
+
+    expect(BootProbeRunner::isInside($run['temporaryRoot'], $configCache))->toBeTrue()
+        // 設定キャッシュ**無し**の起動を観測している (書かれていたら前提が崩れている)。
+        ->and($run['writtenRelativePaths'])->not->toContain('bootstrap-cache/config.php');
 });
 
 test('P-12 宣言の型: 観測が読む swaps() は ExternalFakeBinding の列である', function (): void {
@@ -261,3 +457,87 @@ function externalFakeProbeResolved(array $output): array
         expect($swap)->toBeInstanceOf(ExternalFakeBinding::class);
     }
 });
+
+test('P-13 実働証明(実体): 子が storage_path() 経由で書いた印が一時ディレクトリ配下に現れる', function (): void {
+    $run = externalFakeProbeRun('fake');
+
+    expect($run['writtenRelativePaths'])
+        ->toContain('storage/'.FakeWiringProbeRunner::MARKER_RELATIVE_PATH);
+});
+
+test('P-14 実働証明(向き): 子が解決した書き出し先が 1 件残らず一時ディレクトリ配下でリポジトリの外', function (): void {
+    $run = externalFakeProbeRun('fake');
+
+    $targets = $run['output']['write_targets'] ?? null;
+    expect($targets)->toBeArray();
+    /** @var array<string, mixed> $targets */
+    $repositoryRoot = realpath(base_path());
+    expect($repositoryRoot)->toBeString();
+    /** @var string $repositoryRoot */
+    $expectedKeys = ['storage', 'config_cache', 'routes_cache', 'services_cache',
+        'packages_cache', 'events_cache', 'view_compiled', 'log_path'];
+    expect(array_keys($targets))->toBe($expectedKeys, '観測点の集合が変わっている');
+
+    foreach ($expectedKeys as $key) {
+        $path = $targets[$key];
+        expect($path)->toBeString();
+        /** @var string $path */
+
+        // ★配下判定の**前に**正規化を確かめる。isInside は realpath 済みを前提にするので、
+        //   `..` を含む形は「一時 root 配下かつリポジトリ外」と誤判定されうる (fail-open)。
+        externalFakeProbeAssertNormalizedPath($path, $key);
+
+        // 区切り文字を境界にした配下判定 (素の前方一致は /a と /ab を取り違える)。
+        // isInside は同一パスも true にするので、base_path() 自身も「外ではない」に入る。
+        expect(BootProbeRunner::isInside($run['temporaryRoot'], $path))
+            ->toBeTrue("書き出し先 {$key} が一時ディレクトリの外を指している: {$path}")
+            ->and(BootProbeRunner::isInside($repositoryRoot, $path))
+            ->toBeFalse("書き出し先 {$key} がリポジトリ側を指している: {$path}");
+    }
+});
+
+test('P-15 fail-closed: interpret() は観測が成立していない結果を沈黙させない', function (): void {
+    $make = static fn (string $stdout, bool $timedOut, int $exitCode): BootProbeResult => new BootProbeResult(
+        stdout: $stdout, stderr: '', exitCode: $exitCode, timedOut: $timedOut,
+        elapsedSeconds: 0.1, temporaryRoot: '/tmp/boot-probe-x',
+        writtenRelativePaths: [], pid: 1,
+    );
+
+    $call = static fn (BootProbeResult $result): array => FakeWiringProbeRunner::interpret(
+        $result, [], [], '/tmp/dir', 0700, 0600,
+    );
+
+    // (a) 制限時間超過は通常の非ゼロ終了と区別して例外にする (fail-open 防止)
+    expect(fn (): array => $call($make('{"resolved":{}}', true, 124)))->toThrow(RuntimeException::class);
+    // (b) 空出力 / (c) JSON でない / (d) トップレベルが配列でない
+    expect(fn (): array => $call($make('', false, 0)))->toThrow(RuntimeException::class);
+    expect(fn (): array => $call($make('not json', false, 0)))->toThrow(RuntimeException::class);
+    expect(fn (): array => $call($make('"scalar"', false, 0)))->toThrow(RuntimeException::class);
+});
+
+test('P-16 正規化判定の検出力: 正常な絶対パスは通り、`..` / `.` / 相対パスは弾く', function (
+    string $path,
+    bool $expected,
+): void {
+    expect(externalFakeProbeIsNormalizedAbsolutePath($path))->toBe($expected, $path);
+})->with([
+    // --- 正例 (実データと同じ形。これが false になると P-11 / P-14 が偽レッドになる) ---
+    ['/tmp/boot-probe-abc/storage', true],
+    ['/tmp/boot-probe-abc/bootstrap-cache/config.php', true],
+    ['/tmp/boot-probe-abc/storage/framework/views', true],
+    // --- 負例: `..` でリポジトリ側へ戻れる形 (これを通すと P-11 / P-14 が fail-open) ---
+    ['/tmp/boot-probe-abc/../../workspace/bootstrap/cache/config.php', false],
+    ['/tmp/boot-probe-abc/..', false],
+    ['/../tmp/boot-probe-abc/storage', false],
+    // --- 負例: `.` セグメント ---
+    ['/tmp/boot-probe-abc/./storage', false],
+    ['/tmp/./boot-probe-abc/storage', false],
+    // --- 負例: 相対パス (絶対パス前提が崩れた形) ---
+    ['tmp/boot-probe-abc/storage', false],
+    ['./storage', false],
+    ['../storage', false],
+    // --- 紛らわしいが正当な形 (素の部分文字列判定なら誤って弾く 3 形) ---
+    ['/tmp/boot-probe-abc/..hidden', true],
+    ['/tmp/boot-probe-abc/.hidden', true],
+    ['/tmp/boot-probe-abc/a..b/storage', true],
+]);
diff --git a/tests/Architecture/PhpBootProbeReferenceInventoryTest.php b/tests/Architecture/PhpBootProbeReferenceInventoryTest.php
new file mode 100644
index 00000000..82cceef0
--- /dev/null
+++ b/tests/Architecture/PhpBootProbeReferenceInventoryTest.php
@@ -0,0 +1,700 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\PhpReferenceScanner;
+use Tests\Support\PhpTokenScan;
+use Tests\Support\ReferenceKind;
+use Tests\Support\TrackedPhpSourceFiles;
+
+/*
+| `tests/` 配下の**3 種類の字句参照**の全数申告 inventory —
+|   (A) 定数 `PHP_BINARY` の参照 / (B) 文字列 `bootstrap/app.php` の参照 /
+|   (C) 文字列 `fake-wiring-probe.php` (既存の子入口) の参照。
+| lctl feature: subprocess-boot-probe-harness (正典 v1 の作法へ追従したあとの退行を検出する)。
+| **本 gate は正典 v1 の 6 不変条件ではなく aicue 側の上積みである** (根拠: 正典テンプレートの
+| 同型 gate と AGENTS.md 禁止事項 1)。
+|
+| **名前のとおり、これは「起動の全数」ではなく「参照の全数」の inventory である。**
+| 「PHP の子プロセスを起こしうる箇所を漏れなく数える」ことは**していない**。
+|
+| ## 主張すること
+|
+| 「`PHP_BINARY` の字句参照 (軸 A) / リテラルで検出できるアプリの起動点 (軸 B) /
+| 既存の子入口スクリプトへの参照 (軸 C) の 3 つは、いずれも**申告なしには増えない**」。
+|
+| ## 主張しないこと (名指しで書く)
+|
+|  1. 「アプリを子プロセスで起こす経路が共通の起動器ちょうど 1 本である」こと
+|  2. 文字列リテラルの `'php'` / `env php` / シェルスクリプト経由 /
+|     変数から取り出した実行体パスの検出
+|  3. **起動呼び出しの分類** — 「どのクラスの `new` か」「`proc_open` かその別名か」といった
+|     網羅的な分類は**行わない** (行えば「緑のまま嘘をつく」)。
+|     G-6 が確かめるのは**共通の起動器への静的呼び出しが在ること**だけである
+|  4. 文字列を分割して針を避ける形 (`'fake-wiring-'.'probe.php'`) の検出
+|
+| ## 軸ごとの名前解決の扱い (AGENTS.md §静的検査の共通規約 (a) / (b))
+|
+|  - **G-6 は完全修飾名で突き合わせる**。`Tests\Support\PhpReferenceScanner` が
+|    `use` / group use / 別名つき取り込みを解いた FQCN を返すので、それを
+|    `Tests\Support\Process\BootProbeRunner` と完全一致で比べる。
+|    したがって `use … as Runner; Runner::run(` も**正しく検出する**一方、
+|    **同名の別クラス** (`Other\BootProbeRunner::run(`) は**検出しない** (短名一致ではない)。
+|    受け手が静的に確定できない形 (`$runner::run(` / `static::` 等) は
+|    **「呼んでいる証拠」として数えない** — G-6 は存在を主張する検査なので、
+|    未解決を証拠に数える方が危険側だからである
+|  - **軸 A は名前トークンの末尾要素**で判定する。定数の参照には `PhpReferenceScanner` の
+|    母集団 (クラス名の参照 / 構築 / 呼び出し) が対応しないためで、
+|    ここは**拾いすぎる方向** ((b) の許す側) へ倒してある。
+|    帰結として `Foo\PHP_BINARY` という**別の定数**も軸 A に入る
+|    (申告を 1 行足せば済むので、見逃すより安全側である)
+|
+| **一元化そのものの証拠は載せ替えの実測 (`ExternalFakeBootProbeTest` の P-7〜P-15) であり、
+| 本 gate は退行の検出器である。**
+|
+| ## 走査対象と走査の意味論
+|
+|  - 母集団は `Tests\Support\TrackedPhpSourceFiles` が返す **git 追跡下の `*.php`** のうち
+|    `tests/` 配下 (**未追跡のファイルは母集団に入らない**。`TrackedPhpSourceFiles` の docblock)
+|  - 判定は `Tests\Support\PhpTokenScan::normalize()` の上に建てる。
+|    **コメント・docblock は正規化が落とすので数えない**
+|  - 軸 A の「定数の参照」は**名前トークンの末尾要素の完全一致**で判定する
+|    (`T_STRING` / `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED`)。区切りは `\` である。
+|    `\PHP_BINARY` と `use const Foo\PHP_BINARY as X;` の別名 import も末尾要素で拾うので
+|    fail-closed になる。接頭辞つき (`MY_PHP_BINARY`) / 打ち消しつき (`NOT_PHP_BINARY`) /
+|    接尾辞つき (`PHP_BINARY_PATH`) は**別のトークン**なので拾わない
+|    (AGENTS.md §静的検査の共通規約 (e) の 3 形。G-7 が両方向を固定する)
+|  - 軸 B / 軸 C の「文字列の参照」は文字列トークン
+|    (`T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE`) の**素の部分文字列**一致である
+|    (ヒアドキュメント・ナウドキュメントの本文を含む)
+*/
+
+/**
+ * 軸 A: `tests/` 配下で `PHP_BINARY` を参照してよいファイルの全数申告 (deny-by-default)。
+ *
+ * entry は 4 つの欄を独立に持つ (「件数合わせの allowlist」へ流れないための構造):
+ *  - `launches_app`: アプリを起こすと申告するか (**補助的な申告値**。実際の起動経路の
+ *    全数性を表すものではなく、「アプリを起こす」と申告する先が分散していないことだけを固定する)
+ *  - `subject` / `recovery` / `reason`
+ *
+ * @return array<string, array{launches_app: bool, subject: non-empty-string, recovery: non-empty-string, reason: non-empty-string}>
+ */
+function phpBootProbeBinaryReferenceInventory(): array
+{
+    return [
+        'tests/Support/Process/BootProbeRunner.php' => [
+            'launches_app' => true,
+            'subject' => 'アプリを子プロセスで起こして起動順序を測る (PHP_BINARY)',
+            'recovery' => '本クラス自身 (制限時間・段階的強制終了・終了コードの保持・一時ディレクトリの後片付け)',
+            'reason' => '共通の起動器そのもの (lctl feature: subprocess-boot-probe-harness)',
+        ],
+        'tests/Unit/Support/Process/BootProbeRunnerTest.php' => [
+            'launches_app' => false,
+            'subject' => '起動器の自己検査。参照は期待値の比較と、子へ渡す検体文字列の中だけである',
+            'recovery' => '起動器 (本ファイルは直接の起動 API を持たず、BootProbeRunner 経由でのみ子を起こす)',
+            'reason' => 'テンプレートから取り込んだ共有ファイルである (T249 のローカル修正 1 件を除いて '
+                .'バイト一致。修正の理由は当該 docblock)。起動器を通してしか子を起こさない',
+        ],
+        'tests/Support/Concurrency/SymfonyProbeProcessFactory.php' => [
+            'launches_app' => true,
+            'subject' => '実プロセス 2 本を合図で同期させる並行テストの子を起こす (子はアプリを起動する)',
+            'recovery' => '同 harness の runner (単一の絶対 deadline + 段階的強制終了。Symfony 側の制限時間は無効化)',
+            'reason' => '別 feature (lctl: process-concurrency-test-harness) の正典 v1 が持つ回収規約に属する。'
+                .'本 feature (subprocess-boot-probe-harness) の boundary は「子を 2 本立てて合図で同期させる '
+                .'並行テスト」を明示的に除いているので、共通の起動器へは載せない',
+        ],
+        'tests/Support/StrictTypesRuntimeProbe.php' => [
+            'launches_app' => false,
+            'subject' => '検体 PHP を子で読み込み declare(strict_types=1) の実効性を測る。アプリは起こさない',
+            'recovery' => 'Symfony の Process (既定の制限時間つきで、超過すれば例外になる)',
+            'reason' => '起動順序ではなく単一ファイルのコンパイル指令を測る層である。起動器に載せると '
+                .'Laravel 固有の基底環境・書き出し先 7 キーの予約という無関係な前提が付く '
+                .'(同じ理由で PhpLintOracle も載せていない)',
+        ],
+        'tests/Support/GlobalUse/PhpLintOracle.php' => [
+            'launches_app' => false,
+            'subject' => '`php -l` を真値として取り出す (構文検査のみ。アプリは起こさない)',
+            'recovery' => '同クラス (Symfony Process が管を読み切り、終了コードが null なら例外にする)',
+            'reason' => 'アプリを起動しないので環境の 3 段合成も書き出し先の退避も要らない',
+        ],
+        'tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php' => [
+            'launches_app' => false,
+            'subject' => 'テスト DB の用意スクリプトを起こす (DB へは接続しない)。アプリは起こさない',
+            'recovery' => '同ファイルの helper (管を読み切って proc_close する)',
+            'reason' => 'アプリの起動順序ではなくスクリプトの契約を測る層である '
+                .'(lctl feature: php-test-pgsql-lane 側の関心事。本 feature とは distinct_from の関係)',
+        ],
+        'tests/Architecture/NoNonCompoundGlobalUseTest.php' => [
+            'launches_app' => false,
+            'subject' => '診断メッセージへ実行体のパスを載せるだけ (子は起こさない)',
+            'recovery' => '該当なし (起動しない)',
+            'reason' => '起動は PhpLintOracle が行い、本ファイルは失敗時の診断に PHP_BINARY を印字するだけである',
+        ],
+        'tests/Feature/Console/PipelineSmokeCommandTest.php' => [
+            'launches_app' => false,
+            'subject' => 'ffmpeg の代役として設定値へ実行体のパスを入れるだけ (テストから子は起こさない)',
+            'recovery' => '該当なし (起動するのはアプリ側の合成経路であり、本 feature の射程外)',
+            'reason' => 'アプリの起動順序を測る経路ではない (ffmpeg 起動の統制は '
+                .'tests/Architecture/FfmpegProcessLaunchInventoryTest.php が持つ)',
+        ],
+    ];
+}
+
+/**
+ * 軸 B: `tests/` 配下でアプリの起動点 (`bootstrap/app.php`) を参照してよいファイルの全数申告。
+ *
+ * `kind` は 3 値:
+ *  - `child_entry` : 子プロセスで読み込まれる入口 / 子へ渡す検体文字列
+ *  - `in_process`  : 同一プロセスでのアプリ起動 (子プロセスではない)
+ *  - `inventory`   : 検査定義・診断文としてパス文字列を保持するだけ
+ *
+ * `boots_repository_env` は「その経路で起きた**子**が、リポジトリの `.env` を読んで起動するか」。
+ * **0 件であることが不変条件である** (G-8 が完全一致で pin する)。
+ *
+ * `behaviour_proof` は「その申告を**実挙動で**裏取りしている検査の名前」。
+ * `child_entry` では**必須**で、それ以外の kind では空文字にする (子が居ないので裏取りの対象が無い)。
+ * 詳細は G-8 の docblock を読むこと。
+ *
+ * @return array<string, array{kind: 'child_entry'|'in_process'|'inventory', boots_repository_env: bool, behaviour_proof: string, reason: non-empty-string}>
+ */
+function phpBootProbeAppBootEntryReferenceInventory(): array
+{
+    return [
+        'tests/Support/ExternalFakes/fake-wiring-probe.php' => [
+            'kind' => 'child_entry',
+            // 専用の 0600 環境ファイルへ固定して起動する (リポジトリの .env は読まない)。
+            'boots_repository_env' => false,
+            'behaviour_proof' => 'tests/Architecture/ExternalFakeBootProbeTest.php P-8 '
+                .'(子で実際に効いた鍵の digest が、専用環境ファイルへ書いた使い捨て値と一致し、'
+                .'親の設定値とは一致しないことを測る)',
+            'reason' => '偽の外部サービスの配線を実起動で観測する子入口。起こすのは共通の起動器である',
+        ],
+        'tests/Support/Concurrency/idempotency-claim-probe.php' => [
+            'kind' => 'child_entry',
+            // 段 8 で useEnvironmentPath() / loadEnvironmentFrom() を専用の一時 env ファイルへ向ける。
+            'boots_repository_env' => false,
+            'behaviour_proof' => 'tests/Feature/Concurrency/IdempotencyClaimProcessConcurrencyTest.php '
+                .'(子が段 6 で受け取ったプロセス環境を、段 9 で実効 DB 座標を自己検査し、'
+                .'違反なら終了コード 70 / 72 で落ちる = 親が非ゼロで赤くなる)',
+            'reason' => '実プロセス並行テストの子入口。別 feature (process-concurrency-test-harness) の持ち物である',
+        ],
+        'tests/Unit/Support/Process/BootProbeRunnerTest.php' => [
+            'kind' => 'child_entry',
+            // ★T249 のローカル修正で、S9 / S10 の検体は起動前に環境ファイルの置き場所を
+            //   起動器の一時ディレクトリへ逃がす (取り込み元の姿ではリポジトリの .env を読んでいた)。
+            'boots_repository_env' => false,
+            'behaviour_proof' => 'tests/Unit/Support/Process/BootProbeRunnerTest.php S9 '
+                .'(子が報告した環境ファイルの場所が一時ディレクトリ配下であること + '
+                .'リポジトリの .env に実在する CIPHERSWEET_KEY / DB_PASSWORD が子の設定に現れないこと)',
+            'reason' => '起動器の自己検査が子へ渡す検体文字列 (`-r` のソース) の中にある',
+        ],
+        'tests/TestCase.php' => [
+            'kind' => 'in_process',
+            // 同一プロセスなので phpunit.xml の <server force> が効く (秘密は無害化済み)。
+            'boots_repository_env' => false,
+            'behaviour_proof' => '',
+            'reason' => 'テスト本体のアプリ生成 (同一プロセス)。子プロセスではない',
+        ],
+        'tests/Support/Cache/IsolatedApplicationProbe.php' => [
+            'kind' => 'in_process',
+            'boots_repository_env' => false,
+            'behaviour_proof' => '',
+            'reason' => 'キャッシュ受け皿の結線を測るための第 2 のアプリを同一プロセスで組み立てる。子プロセスではない',
+        ],
+        'tests/Architecture/CacheGuardWiringGateTest.php' => [
+            'kind' => 'inventory',
+            'boots_repository_env' => false,
+            'behaviour_proof' => '',
+            'reason' => 'TestCase の結線を字句で固定する検査が、期待するトークン列としてパス文字列を持つ',
+        ],
+        'tests/Architecture/BughuntExecutedRouteOrderingTest.php' => [
+            'kind' => 'inventory',
+            'boots_repository_env' => false,
+            'behaviour_proof' => '',
+            'reason' => '記録器の位置を固定する検査が、違反時の直し方を案内する診断文にパス文字列を持つ',
+        ],
+        'tests/Architecture/InertiaErrorScreenContractTest.php' => [
+            'kind' => 'inventory',
+            'boots_repository_env' => false,
+            'behaviour_proof' => '',
+            'reason' => '例外応答の最終整形スロットの登録位置を検査する側が、照合する場所としてパス文字列を持つ',
+        ],
+        'tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php' => [
+            'kind' => 'inventory',
+            'boots_repository_env' => false,
+            'behaviour_proof' => '',
+            'reason' => '撤去表面の走査対象の定義が、走査根の 1 つとしてパス文字列を持つ',
+        ],
+        'tests/Architecture/PhpBootProbeReferenceInventoryTest.php' => [
+            'kind' => 'inventory',
+            'boots_repository_env' => false,
+            'behaviour_proof' => '',
+            'reason' => '本 gate 自身。走査の針としてパス文字列を持つ (自分を走査対象から外さない)',
+        ],
+    ];
+}
+
+/**
+ * 軸 C: 子入口スクリプトのパスを参照してよいファイルの全数申告。
+ *
+ * `reference_kind` は 2 値: `runtime` (実行経路として子入口を起こす) / `inventory` (検査定義)。
+ *
+ * @return array<string, array{reference_kind: 'runtime'|'inventory', reason: non-empty-string}>
+ */
+function phpBootProbeChildEntryReferenceInventory(): array
+{
+    return [
+        'tests/Support/ExternalFakes/FakeWiringProbeRunner.php' => [
+            'reference_kind' => 'runtime',
+            'reason' => '子入口を起こす唯一の呼び出し元。起こし方と回収は BootProbeRunner に委ねる',
+        ],
+        'tests/Architecture/PhpBootProbeReferenceInventoryTest.php' => [
+            'reference_kind' => 'inventory',
+            'reason' => '本 gate 自身。走査の針としてパス文字列を持つ (自分を走査対象から外さない)',
+        ],
+    ];
+}
+
+/** 走査の針 (2 箇所に書かない)。 */
+const PHP_BOOT_PROBE_APP_ENTRY_NEEDLE = 'bootstrap/app.php';
+
+const PHP_BOOT_PROBE_CHILD_ENTRY_NEEDLE = 'fake-wiring-probe.php';
+
+/** G-6 が完全修飾名で突き合わせる共通の起動器。 */
+const PHP_BOOT_PROBE_RUNNER_FQCN = 'Tests\\Support\\Process\\BootProbeRunner';
+
+/**
+ * 名前トークンの末尾要素 (区切りは `\`)。
+ *
+ * `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED` は 1 トークンで届くので、
+ * 素の部分文字列一致ではなく区切りで割った完全一致で比べる
+ * (AGENTS.md §静的検査の共通規約 (e))。
+ */
+function phpBootProbeLastNameSegment(string $name): string
+{
+    $segments = explode('\\', $name);
+
+    return $segments[count($segments) - 1];
+}
+
+/**
+ * ソースが定数 `$constant` を**名前として**参照しているか。
+ *
+ * 文字列リテラルの中の同じ綴りは数えない (トークン種別で区別する)。
+ */
+function phpBootProbeReferencesConstant(string $source, string $constant): bool
+{
+    foreach (PhpTokenScan::normalize($source) as $token) {
+        if (! in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
+            continue;
+        }
+
+        if (phpBootProbeLastNameSegment($token['text']) === $constant) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/**
+ * ソースの**文字列トークン**に `$needle` が現れるか
+ * (ヒアドキュメント・ナウドキュメントの本文を含む。コメントは正規化が落とす)。
+ */
+function phpBootProbeReferencesStringNeedle(string $source, string $needle): bool
+{
+    foreach (PhpTokenScan::normalize($source) as $token) {
+        if (! in_array($token['id'], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
+            continue;
+        }
+
+        if (str_contains($token['text'], $needle)) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/**
+ * ソースが**共通の起動器**への静的呼び出し `BootProbeRunner::run(` を持つか。
+ *
+ * ★照合は**完全修飾名**で行う (AGENTS.md §静的検査の共通規約 (a))。
+ *   `Tests\Support\PhpReferenceScanner` が `use` / group use / 別名つき取り込みを解いた
+ *   FQCN を返すので、短名一致で同名の別クラスを拾うことも、別名 1 つで黙ることも無い。
+ * ★受け手が静的に確定できない形 (`$runner::run(` / `static::` 等) は
+ *   **証拠として数えない**。G-6 は「呼んでいる」ことを主張する検査なので、
+ *   未解決を肯定側へ数える方が危険である。
+ */
+function phpBootProbeCallsBootProbeRunner(string $relativePath, string $source): bool
+{
+    foreach (PhpReferenceScanner::references($relativePath, $source)->sites as $site) {
+        if ($site->kind !== ReferenceKind::StaticCall || $site->name !== 'run') {
+            continue;
+        }
+
+        if (! $site->receiver->isResolved()) {
+            continue;
+        }
+
+        if ($site->receiver->fqcn() === PHP_BOOT_PROBE_RUNNER_FQCN) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/**
+ * 走査の母集団: git 追跡下の `tests/` 配下の `*.php` (相対パス => ソース)。
+ *
+ * @return array<string, string>
+ */
+function phpBootProbeTestSources(): array
+{
+    /** @var array<string, string>|null $cache */
+    static $cache = null;
+
+    if ($cache !== null) {
+        return $cache;
+    }
+
+    $sources = [];
+    foreach (TrackedPhpSourceFiles::all(base_path()) as $file) {
+        if (! str_starts_with($file['relative'], 'tests/')) {
+            continue;
+        }
+
+        $source = file_get_contents($file['absolute']);
+        if ($source === false) {
+            // 読めないファイルを黙って落とすと走査が縮む (fail-closed)。
+            throw new RuntimeException('走査対象を読めなかった: '.$file['relative']);
+        }
+
+        $sources[$file['relative']] = $source;
+    }
+
+    $cache = $sources;
+
+    return $cache;
+}
+
+/**
+ * 実測: 述語が真になった相対パスの昇順リスト。
+ *
+ * @param  callable(string): bool  $matches
+ * @return list<string>
+ */
+function phpBootProbeMeasure(callable $matches): array
+{
+    $hits = [];
+    foreach (phpBootProbeTestSources() as $relative => $source) {
+        if ($matches($source)) {
+            $hits[] = $relative;
+        }
+    }
+
+    sort($hits);
+
+    return $hits;
+}
+
+/** 申告のキーを昇順で取り出す。 @param array<string, mixed> $inventory @return list<string> */
+function phpBootProbeDeclaredPaths(array $inventory): array
+{
+    $paths = array_keys($inventory);
+    sort($paths);
+
+    return $paths;
+}
+
+test('G-1 軸 A: PHP_BINARY を参照するファイルの集合が全数申告と完全一致する', function (): void {
+    $measured = phpBootProbeMeasure(
+        static fn (string $source): bool => phpBootProbeReferencesConstant($source, 'PHP_BINARY'),
+    );
+
+    expect($measured)->toBe(
+        phpBootProbeDeclaredPaths(phpBootProbeBinaryReferenceInventory()),
+        '未申告のファイルが PHP_BINARY を参照している、または申告が実体より多い。'
+        .'足すときは launches_app / subject / recovery / reason の 4 欄を埋めること',
+    );
+});
+
+/**
+ * G-2: 「アプリを起こす」と申告してよい起こし手の**完全一致 pin**。
+ *
+ * ★**1 件ではなく 2 件である**。本 feature (subprocess-boot-probe-harness) の boundary は
+ *   「子を 2 本立てて合図で同期させる並行テスト」を明示的に**除いて**おり、そちらは別 feature
+ *   (lctl: process-concurrency-test-harness) が自分の回収規約 (単一の絶対 deadline) を持つ。
+ *   両者を 1 本の起動器へ統合するのは「別物の概念を似ているからで統合する」ことになる
+ *   (AGENTS.md 思考原則 4)。
+ * ★したがって本検査が固定するのは**申告先の集合そのもの**であり、
+ *   「起動経路が 1 本である」ことではない (それは字句走査では裏が取れない。冒頭の
+ *   「主張しないこと」1 を参照)。3 本目が現れたら**どちらの feature の規約に属するのか**を
+ *   申告に書くことになり、レビューに必ず見える。
+ */
+test('G-2 軸 A: アプリを起こすと申告する起こし手が完全一致で pin されている', function (): void {
+    $launching = array_keys(array_filter(
+        phpBootProbeBinaryReferenceInventory(),
+        static fn (array $entry): bool => $entry['launches_app'],
+    ));
+    sort($launching);
+
+    expect($launching)->toBe([
+        'tests/Support/Concurrency/SymfonyProbeProcessFactory.php',
+        'tests/Support/Process/BootProbeRunner.php',
+    ]);
+});
+
+test('G-3 軸 A: subject / recovery / reason の 3 欄がいずれも空でない', function (): void {
+    foreach (phpBootProbeBinaryReferenceInventory() as $path => $entry) {
+        expect(trim($entry['subject']))->not->toBe('', "subject が空: {$path}")
+            ->and(trim($entry['recovery']))->not->toBe('', "recovery が空: {$path}")
+            ->and(trim($entry['reason']))->not->toBe('', "reason が空: {$path}");
+    }
+});
+
+test('G-4 軸 B: アプリの起動点を参照するファイルの集合が全数申告と完全一致し、kind が 3 値である', function (): void {
+    $measured = phpBootProbeMeasure(
+        static fn (string $source): bool => phpBootProbeReferencesStringNeedle(
+            $source,
+            PHP_BOOT_PROBE_APP_ENTRY_NEEDLE,
+        ),
+    );
+
+    expect($measured)->toBe(
+        phpBootProbeDeclaredPaths(phpBootProbeAppBootEntryReferenceInventory()),
+        '未申告のファイルがアプリの起動点を参照している (kind と reason を 1 行足すこと)',
+    );
+
+    foreach (phpBootProbeAppBootEntryReferenceInventory() as $path => $entry) {
+        // `toContain` は可変長ニードルなので message 引数を渡さない (渡すと第 2 ニードル扱いになる)。
+        expect(in_array($entry['kind'], ['child_entry', 'in_process', 'inventory'], true))
+            ->toBeTrue("kind が 3 値の外: {$path}")
+            ->and(trim($entry['reason']))->not->toBe('', "reason が空: {$path}");
+    }
+});
+
+test('G-5 軸 C: 子入口を参照するファイルの集合が全数申告と完全一致し、reference_kind が 2 値である', function (): void {
+    $measured = phpBootProbeMeasure(
+        static fn (string $source): bool => phpBootProbeReferencesStringNeedle(
+            $source,
+            PHP_BOOT_PROBE_CHILD_ENTRY_NEEDLE,
+        ),
+    );
+
+    expect($measured)->toBe(
+        phpBootProbeDeclaredPaths(phpBootProbeChildEntryReferenceInventory()),
+        '未申告のファイルが子入口スクリプトを参照している',
+    );
+
+    foreach (phpBootProbeChildEntryReferenceInventory() as $path => $entry) {
+        // `toContain` は可変長ニードルなので message 引数を渡さない (渡すと第 2 ニードル扱いになる)。
+        expect(in_array($entry['reference_kind'], ['runtime', 'inventory'], true))
+            ->toBeTrue("reference_kind が 2 値の外: {$path}")
+            ->and(trim($entry['reason']))->not->toBe('', "reason が空: {$path}");
+    }
+});
+
+test('G-6 軸 C: runtime はちょうど 1 件で、共通の起動器を実際に呼んでいる', function (): void {
+    $runtime = array_keys(array_filter(
+        phpBootProbeChildEntryReferenceInventory(),
+        static fn (array $entry): bool => $entry['reference_kind'] === 'runtime',
+    ));
+
+    expect($runtime)->toBe(['tests/Support/ExternalFakes/FakeWiringProbeRunner.php']);
+
+    $sources = phpBootProbeTestSources();
+    foreach ($runtime as $path) {
+        expect($sources)->toHaveKey($path);
+        expect(phpBootProbeCallsBootProbeRunner($path, $sources[$path]))
+            ->toBeTrue("{$path} が ".PHP_BOOT_PROBE_RUNNER_FQCN.'::run( を呼んでいない (子の起こし方が一元化から外れている)');
+    }
+});
+
+/**
+ * G-8: 子プロセスがリポジトリの `.env` を読んで起動しないこと (申告 0 件の pin + 裏取りの名指し)。
+ *
+ * ## 何を守っているか
+ *
+ * 共通の起動器は `proc_open` へ渡す環境配列で開発者ローカルの env を締め出すが、
+ * **`.env` ファイルの読み込みまでは止めない**。子の作業ディレクトリはリポジトリ root なので、
+ * 子が `bootstrap/app.php` を**素で**読むと Laravel は**リポジトリの `.env` をそのまま**設定へ載せる。
+ * これは正典 v1 (2) の「開発者ローカルの環境変数を入力集合から外す」を、
+ * 環境変数ではなく**環境ファイル**の経路で迂回してしまう形である。
+ *
+ * **実測 (T249 実装時、本 worktree)**: 取り込んだ自己検査 S9 / S10 の検体を取り込み元の姿
+ * (環境ファイルの置き場所を移さない形) で走らせると、子の設定に `.env` 由来の
+ * **DB のパスワードと実 `CIPHERSWEET_KEY`** が載った。外部サービスの資格情報
+ * (Stripe / AWS / Google / SMTP) は本チェックアウトではいずれも空だったが、
+ * **「空だった」のはこのチェックアウトの性質であって保証ではない。**
+ * この実測を受けて S9 / S10 の検体には**起動前に環境ファイルの置き場所を一時ディレクトリへ
+ * 逃がす 1 行**を入れた (取り込み元からの意図的な逸脱。理由は当該 docblock)。
+ *
+ * ## 何を機械で固定しているか
+ *
+ *  1. `boots_repository_env` が真の entry は**ちょうど 0 件**である (完全一致 pin)。
+ *     真を 1 件足すには申告を書き換えることになり、レビューに必ず見える
+ *  2. `child_entry` の entry は**裏取りの検査を名指しする欄 (`behaviour_proof`) を必ず持つ**。
+ *     空では通らないので、子入口を足す人は「この子が `.env` を読まないことを何が測るのか」を
+ *     書くことになる
+ *  3. `child_entry` 以外 (`in_process` / `inventory`) は定義上この危険面を持たないので、
+ *     `boots_repository_env` が偽であること・`behaviour_proof` が空であることを両方向で固定する
+ *     (取り違えの検出)
+ *
+ * ## 対比 (なぜ同一プロセスは対象外なのか)
+ *
+ * 同一プロセスの起動 (`tests/TestCase.php` 等) は `phpunit.xml` の `<server force="true">` が
+ * 効くため、Stripe / LLM の鍵は空か dummy に無害化されている。
+ * **`<server force>` は PHPUnit プロセスにしか効かず、`proc_open` の子には及ばない** —
+ * これが子と同一プロセスの非対称の正体である。
+ *
+ * ## 主張しないこと (誇張しない)
+ *
+ * **本検査が機械で確かめるのは「申告」と「裏取りの名指しが在ること」であって、
+ * 名指しした検査が実際に何を測っているかではない。** したがって次は本検査を通る:
+ *
+ *  1. `behaviour_proof` に実在しない検査名や、実は何も測っていない検査名を書く
+ *  2. 既存の `child_entry` の中で、`.env` を読む検体を**増やす** (ファイル単位の申告は変わらない)
+ *
+ * **実挙動の側の防壁は本検査ではなく、名指しされた検査そのものである** —
+ * `tests/Unit/Support/Process/BootProbeRunnerTest.php` の S9 (子が報告した環境ファイルの場所 +
+ * リポジトリの `.env` の実値が子の設定に現れないことの番兵) と
+ * `tests/Architecture/ExternalFakeBootProbeTest.php` の P-8 (子で効いた鍵の digest)。
+ * 本検査はその 2 本が**申告から外れないように束ねる目録**である。
+ */
+test('G-8 リポジトリの .env を読んで起動する子は 0 件で、child_entry は裏取りを名指しする', function (): void {
+    $inventory = phpBootProbeAppBootEntryReferenceInventory();
+
+    $bootsRepositoryEnv = array_keys(array_filter(
+        $inventory,
+        static fn (array $entry): bool => $entry['boots_repository_env'],
+    ));
+
+    // ★件数と場所を完全一致で pin する (0 件)。増やすには申告を書き換えることになり、
+    //   「なぜその子が .env を読んでよいのか」がレビューに必ず見える。
+    expect($bootsRepositoryEnv)->toBe(
+        [],
+        'リポジトリの .env を読んで起動する子が現れた。'
+        .'子の環境ファイルは専用の一時ファイルへ固定すること (G-8 の docblock)',
+    );
+
+    $childEntries = [];
+
+    foreach ($inventory as $path => $entry) {
+        if ($entry['kind'] === 'child_entry') {
+            $childEntries[] = $path;
+
+            // ★裏取りの名指しを必須にする (申告だけで済ませない)。
+            expect(trim($entry['behaviour_proof']))
+                ->not->toBe('', "child_entry に裏取りの名指し (behaviour_proof) が無い: {$path}");
+
+            continue;
+        }
+
+        // ★子プロセスではない経路 (`in_process`) と検査定義 (`inventory`) は、
+        //   定義上この危険面を持たない。取り違えを防ぐために両方向で固定する。
+        expect($entry['boots_repository_env'])
+            ->toBeFalse("子プロセスではない経路に .env 読み込みが申告されている: {$path}")
+            ->and(trim($entry['behaviour_proof']))
+            ->toBe('', "子が居ない経路に裏取りの名指しがある (kind の取り違え): {$path}");
+    }
+
+    // ★母集団が空のまま緑になる形を塞ぐ (AGENTS.md §静的検査の共通規約 (b) の 3 点目)。
+    expect($childEntries)->not->toBe([], 'child_entry が 1 件も無い (走査か申告が壊れている)');
+});
+
+test('G-7 走査が空振りしていない (走査根が実在し、3 軸の母集団が非空)', function (): void {
+    expect(is_dir(base_path('tests')))->toBeTrue('走査根 tests/ が実在しない');
+
+    $sources = phpBootProbeTestSources();
+    expect(count($sources))->toBeGreaterThan(100, '母集団が縮んでいる (走査が壊れている可能性)');
+
+    // 申告したパスは 3 軸とも実在する (改名・移動に気づかずに申告だけが残るのを防ぐ)。
+    foreach ([
+        phpBootProbeBinaryReferenceInventory(),
+        phpBootProbeAppBootEntryReferenceInventory(),
+        phpBootProbeChildEntryReferenceInventory(),
+    ] as $inventory) {
+        expect($inventory)->not->toBeEmpty();
+        foreach (array_keys($inventory) as $path) {
+            // `toHaveKey` の第 2 引数は**期待する値**なので、診断文は素の真偽で書く。
+            expect(array_key_exists($path, $sources))
+                ->toBeTrue("申告したパスが母集団に無い (改名・移動・git add 忘れ): {$path}");
+        }
+    }
+});
+
+test('G-7 走査器の見本検査: 3 軸の判定が見本表どおりである', function (
+    string $sample,
+    bool $axisA,
+    bool $axisB,
+    bool $axisC,
+): void {
+    expect(phpBootProbeReferencesConstant($sample, 'PHP_BINARY'))->toBe($axisA, "軸 A: {$sample}")
+        ->and(phpBootProbeReferencesStringNeedle($sample, PHP_BOOT_PROBE_APP_ENTRY_NEEDLE))
+        ->toBe($axisB, "軸 B: {$sample}")
+        ->and(phpBootProbeReferencesStringNeedle($sample, PHP_BOOT_PROBE_CHILD_ENTRY_NEEDLE))
+        ->toBe($axisC, "軸 C: {$sample}");
+})->with([
+    // [検体, 軸 A, 軸 B, 軸 C]
+    ['<?php $x = [PHP_BINARY];', true, false, false],
+    ['<?php // PHP_BINARY', false, false, false],
+    ['<?php $s = "PHP_BINARY";', false, false, false],
+    ['<?php use const PHP_BINARY as Runtime; $x = Runtime;', true, false, false],
+    // 完全修飾・修飾つきの定数参照も末尾要素で拾う (fail-closed)。
+    ['<?php $x = \PHP_BINARY;', true, false, false],
+    ['<?php use const Foo\PHP_BINARY as Runtime; $x = Runtime;', true, false, false],
+    // 接頭辞つき・打ち消しつき・接尾辞つきは別トークンなので拾わない。
+    ['<?php $x = MY_PHP_BINARY;', false, false, false],
+    ['<?php $x = NOT_PHP_BINARY;', false, false, false],
+    ['<?php $x = PHP_BINARY_PATH;', false, false, false],
+    ["<?php require 'bootstrap/app.php';", false, true, false],
+    ['<?php // require bootstrap/app.php', false, false, false],
+    ["<?php \$p = __DIR__.'/fake-wiring-probe.php';", false, false, true],
+    // 文字列を分割して針を避ける形は**射程外**。限界を期待値として固定する。
+    ['<?php $a = \'fake-wiring-\'."probe.php";', false, false, false],
+    // ★軸 B / C は**素の部分文字列**一致である (軸 A の語彙一致とは判定が違う)。
+    //   接頭辞つき・打ち消しつき・接尾辞つきは**いずれも一致する** = 申告が要る側へ倒れる。
+    //   見逃す方向ではなく拾いすぎる方向なので (b) の許す側であり、
+    //   紛らわしい綴りを足した人には「1 行申告する」という摩擦だけが掛かる。
+    ["<?php \$p = 'vendor/bootstrap/app.php';", false, true, false],
+    ["<?php \$p = 'not-bootstrap/app.php';", false, true, false],
+    ["<?php \$p = 'bootstrap/app.php.bak';", false, true, false],
+    ["<?php \$p = 'old-fake-wiring-probe.php';", false, false, true],
+    ["<?php \$p = 'fake-wiring-probe.php.disabled';", false, false, true],
+    // 針の一部だけでは一致しない (部分文字列一致の下界も固定する)。
+    ["<?php \$p = 'bootstrap/app.phpx';", false, true, false],
+    ["<?php \$p = 'bootstrap/application.php';", false, false, false],
+    ["<?php \$p = 'fake-wiring-probe.txt';", false, false, false],
+]);
+
+test('G-7 走査器の見本検査: 共通の起動器への静的呼び出しを完全修飾名で判定する', function (
+    string $sample,
+    bool $expected,
+): void {
+    expect(phpBootProbeCallsBootProbeRunner('tests/Sample.php', $sample))->toBe($expected, $sample);
+})->with([
+    // --- 正例: 完全修飾名が起動器に解決される 3 形 ---
+    ['<?php use Tests\Support\Process\BootProbeRunner; BootProbeRunner::run([]);', true],
+    ['<?php Tests\Support\Process\BootProbeRunner::run([]);', true],
+    ['<?php \Tests\Support\Process\BootProbeRunner::run([]);', true],
+    // ★別名つき取り込みも**解決するので検出する** (短名一致では黙っていた形)。
+    ['<?php use Tests\Support\Process\BootProbeRunner as Runner; Runner::run([]);', true],
+    // --- 負例: 同名の別クラス (短名一致なら誤検出していた形) ---
+    ['<?php use Other\BootProbeRunner; BootProbeRunner::run([]);', false],
+    ['<?php Other\BootProbeRunner::run([]);', false],
+    // 取り込みが無い短名は「現在の名前空間の下」に解決されるので起動器ではない。
+    ['<?php BootProbeRunner::run([]);', false],
+    // --- 負例: 接頭辞つき・接尾辞つきのクラス名 / 接尾辞つきのメソッド名 ---
+    ['<?php use Tests\Support\Process\OtherBootProbeRunner; OtherBootProbeRunner::run([]);', false],
+    ['<?php use Tests\Support\Process\BootProbeRunnerX; BootProbeRunnerX::run([]);', false],
+    ['<?php use Tests\Support\Process\BootProbeRunner; BootProbeRunner::runner([]);', false],
+    // --- 負例: 呼び出しではない形 ---
+    ['<?php use Tests\Support\Process\BootProbeRunner; BootProbeRunner::RUN;', false],
+    ['<?php use Tests\Support\Process\BootProbeRunner;', false],
+    ['<?php // BootProbeRunner::run(', false],
+    ['<?php $s = "BootProbeRunner::run(";', false],
+    // --- 負例: 受け手が静的に確定できない形は**証拠に数えない** (存在を主張する検査のため) ---
+    ['<?php $runner = Tests\Support\Process\BootProbeRunner::class; $runner::run([]);', false],
+]);
diff --git a/tests/Support/ExternalFakes/FakeWiringProbeRunner.php b/tests/Support/ExternalFakes/FakeWiringProbeRunner.php
index 7002bdf6..5e13009e 100644
--- a/tests/Support/ExternalFakes/FakeWiringProbeRunner.php
+++ b/tests/Support/ExternalFakes/FakeWiringProbeRunner.php
@@ -6,30 +6,58 @@
 
 use JsonException;
 use RuntimeException;
-use Symfony\Component\Process\Process;
+use Tests\Support\Process\BootProbeResult;
+use Tests\Support\Process\BootProbeRunner;
 
 /**
  * 観測用スクリプト (fake-wiring-probe.php) を子プロセスで走らせる。
  *
- * 子の環境は**完全に作り直す** (親から引き継がない)。決め方は 3 段:
- * 1. プロセスの環境変数は `env -i` で空にしてから、必要な分だけを渡す
- *    (親のシェルに残った TESTING_FAKE_* に結果を左右されない。
- *     bug-hunt のスクリプトが DB 資格情報を遮断するときと同じ手である)
- * 2. 設定の出所は**専用の一時環境ファイル 1 つだけ**にする
- *    (`FAKE_WIRING_PROBE_ENV_DIR` / `…_FILE` で子へ渡し、子が
- *     `useEnvironmentPath()` / `loadEnvironmentFrom()` で固定する)。
- *     親のチェックアウトの `.env` / `.env.bughunt.local` は**読ませない**
- *     = 実 Stripe / 外部ログイン / S3 の資格情報は子の設定に 1 つも入らない
- * 3. 設定キャッシュを無効化する。`APP_CONFIG_CACHE` を**存在しない一時パス**へ向け、
- *    キャッシュ無しの起動として観測する (共有の bootstrap/cache を作ったり消したりしない =
- *    並列実行と衝突しない)
+ * ★**子の起こし方・回収・書き出し先の退避は共通の起動器**
+ *   (`Tests\Support\Process\BootProbeRunner`) が持つ
+ *   (lctl feature: subprocess-boot-probe-harness の正典 v1 (1)〜(5))。
+ *   本クラスに残るのは「観測用の環境ファイルを安全に用意すること」と
+ *   「子の出力を解釈すること」の 2 つだけである。
  *
- * ★**親の実鍵を複写しない**。`APP_KEY` / `CIPHERSWEET_KEY` は起動のたびに
- *   **使い捨ての値をその場で生成する** (観測は解決と経路の組み立てだけで、既存データの
- *   復号も DB 接続もしないため実鍵は要らない)。これで一時ファイルは秘密を 1 つも持たない。
- * ★それでも置き場所は保護する: 専用の一時ディレクトリを 0700 で作り、環境ファイルは
- *   作成時点から 0600 にする。起動前に権限を確かめ、0600 でなければ**子を起こさずに失敗させる**。
- *   後片付けは finally で行い、timeout・JSON の解釈失敗・Process の例外でも必ず通る。
+ * ## 1. 子の環境は 4 段で決まる
+ *
+ * 継承 (`PATH` / `HOME` / `TMPDIR`) → 基底 (`APP_KEY` / `QUEUE_CONNECTION` / `CACHE_STORE`) →
+ * ケース別 (本クラスの `CASE_ENV_KEYS` の 3 件) → 予約 (書き出し先 7 キー。起動器が決める)。
+ * **統制点は `proc_open` へ渡す環境配列**である — 子はその配列だけを受け取るので、
+ * 開発者ローカルの env (`TESTING_FAKE_*` / DB 資格情報など) はここで締め出される。
+ * 後ろの段が前の段に勝つので、ケース別上書きは基底に勝つ。
+ *
+ * ## 2. 使い捨て鍵の置き場所は 2 つに分かれる
+ *
+ * `APP_KEY` は**ケース別上書き**、`CIPHERSWEET_KEY` は**環境ファイル**に置く。
+ * Laravel の環境変数リポジトリは **immutable** で、**プロセス環境に既に在る値を Dotenv は
+ * 上書きしない**ためである。起動器の基底が `APP_KEY` を載せる以上、環境ファイルへ書いた
+ * 使い捨て鍵は無視される (設計時に子プロセスで実測して確定した)。
+ * どちらの鍵も**親の実鍵を複写しない** — 起動のたびにその場で生成する
+ * (観測は解決と経路の組み立てだけで、既存データの復号も DB 接続もしないため実鍵は要らない)。
+ *
+ * ## 3. 一時ディレクトリが 2 つある
+ *
+ *  - **外側**: 本クラスが作る**環境ファイルの置き場**。0700 で作り、環境ファイルは 0600。
+ *    起動前に実効の権限を確かめ、違えば**子を起こさずに失敗させる**。
+ *    後片付けは `withEnvironmentDirectory()` の `finally` が行い、本体がどう終わっても通る
+ *  - **内側**: 起動器が作る**書き出し先の退避先**。子の storage / 設定キャッシュ等はここへ向く
+ *
+ * どちらも**リポジトリの外**であることを起動前に確かめる (正典 v1 (5) の fail-closed)。
+ * 境界の判定は `BootProbeRunner::isInside()` を使う (規則を 2 か所で持たない)。
+ *
+ * ## 4. 設定キャッシュの退避先は起動器の予約鍵である
+ *
+ * `APP_CONFIG_CACHE` ほか 7 キーは起動器が一時ディレクトリから導く**予約鍵**なので、
+ * 本クラスからは渡せない (渡すと `BootProbeRunner::run()` が例外にする)。
+ *
+ * ## 5. 取り込んだ `BootProbeRunner` の docblock の訂正 (向こうはバイト一致なので直せない)
+ *
+ * | 取り込んだ記述 | aicue での実際 |
+ * |---|---|
+ * | 「外部到達統制の subprocess 0 件 pin に触れる (AGENTS.md セキュリティ不変条件 **15**)」 | aicue の外部到達点の目録は **セキュリティ不変条件 9** である |
+ * | 「同じ扱いの先例は `tests/Support/Architecture/GlobalUse/PhpLintOracle.php`」 | aicue では `tests/Support/GlobalUse/PhpLintOracle.php` (`Architecture/` が入らない) |
+ *
+ * **趣旨 (`tests/` 専用であり `app/` へ持ち出さない) は aicue でもそのまま成り立つ。**
  *
  * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
  * キャッシュ有りの起動は観測しない (キャッシュが古いときの挙動は本観測の範囲外で、
@@ -37,31 +65,44 @@
  */
 final class FakeWiringProbeRunner
 {
+    /**
+     * 子が実働証明の印を書く先 (`storage_path()` からの相対パス)。
+     *
+     * ★正典 v1 (5) の実働証明の観測点。退避が効いていなければ印はリポジトリ側へ落ち、
+     *   起動器の `writtenRelativePaths` に現れない = P-13 が赤になる。
+     */
+    public const string MARKER_RELATIVE_PATH = 'app/private/fake-wiring-probe-marker.txt';
+
     /**
      * 一時環境ファイルに書いてよいキー (deny-by-default)。
-     * 実資格情報のキーは 1 つも無く、鍵の 2 つは使い捨ての生成値である。
+     * 実資格情報のキーは 1 つも無く、鍵は使い捨ての生成値である。
+     *
+     * ★`APP_KEY` は**ここに置けない**。Laravel の環境変数リポジトリは immutable で、
+     *   プロセス環境に既に在る値を Dotenv は上書きしない。BootProbeRunner の基底が
+     *   `APP_KEY` を載せる以上、ここへ書いても無視される (設計時に子プロセスで実測)。
+     *   使い捨て `APP_KEY` は CASE_ENV_KEYS 側 (ケース別上書き) が運ぶ。
      *
      * @var list<string>
      */
     public const array ALLOWED_ENV_FILE_KEYS = [
-        'APP_ENV', 'APP_KEY', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY',
+        'APP_ENV', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY',
         'TESTING_FAKE_EXTERNALS', 'TESTING_FAKE_STORAGE', 'TESTING_FAKE_LLM',
     ];
 
     /**
-     * 子プロセスへ渡してよい**プロセス環境変数**のキー (上とは別物なので定数を分ける)。
-     * `env -i` で空にしたうえでこの 3 つだけを載せる。
+     * BootProbeRunner へ渡す**ケース別上書き**のキー (正典 v1 (2) の第 3 段)。
      *
-     * ★この定数は「起動側が載せる分」の宣言であり、**子が実際に受け取った分**は
-     *   probe が自分で観測して返す。両方を突き合わせて初めて `env -i` の退行が映る。
+     * ★`TESTING_FAKE_*` はここに**無い**。偽物の宣言はプロセス環境へ 1 件も載せず、
+     *   0600 の環境ファイルの中だけに置く (P-7 の危険接頭辞の禁止をそのまま維持する)。
+     * ★`APP_CONFIG_CACHE` ほかの書き出し先は runner の**予約鍵**なので渡さない (渡すと例外)。
+     * ★この一覧は P-7 がリテラルで完全一致 pin する (増やすと赤になる)。
      *
      * @var list<string>
      */
-    public const array ALLOWED_PROCESS_ENV_KEYS = [
+    public const array CASE_ENV_KEYS = [
         'FAKE_WIRING_PROBE_ENV_DIR',
         'FAKE_WIRING_PROBE_ENV_FILE',
-        // 設定キャッシュを無効化する (存在しない絶対パスを一時ディレクトリ配下に指す)
-        'APP_CONFIG_CACHE',
+        'APP_KEY',
     ];
 
     /** 観測に使う自ホストの URL (実サーバは立てない。経路の組み立てにだけ使う) */
@@ -70,19 +111,91 @@ final class FakeWiringProbeRunner
     /** 環境ファイルの名前 (一時ディレクトリ内で固定) */
     private const string ENV_FILE_NAME = '.env.probe';
 
+    /**
+     * 環境ファイルの置き場所を 0700 で用意し、**本体がどう終わっても必ず消す**足場。
+     *
+     * ★`run()` の `finally` をここへ切り出したのは、**後始末そのものを検査から直接呼べるように**
+     *   するためである (P-10c)。制限時間超過の経路は「`interpret()` が例外を投げる」(P-15) と
+     *   「本体が例外を投げれば中身ごと消える」(P-10c) の合成で覆う。
+     *   **プロセスの挙動を偽装する注入の継ぎ目ではない** — 起こし方も回収も BootProbeRunner のままである。
+     *
+     * ★**リポジトリの中には作らない** (正典 v1 (5) の fail-closed)。内側の退避先は
+     *   BootProbeRunner が同じ検査を持つが、外側 (この環境ファイルの置き場) にも同じ境界が要る。
+     *   判定は BootProbeRunner::isInside() を使う (境界規則を 2 か所で持たない)。
+     * ★権限は callback を呼ぶ**前に**実効値で確かめる。どの失敗でも作った置き場所を消してから投げる。
+     *
+     * @template T
+     *
+     * @param  callable(string): T  $body  引数は作った置き場所の絶対パス
+     * @return T
+     */
+    public static function withEnvironmentDirectory(?string $baseDirectory, callable $body): mixed
+    {
+        $base = $baseDirectory ?? sys_get_temp_dir();
+
+        // ★`Webmozart\Assert` を使わない — あちらは InvalidArgumentException を投げるので、
+        //   呼び出し側の例外契約が RuntimeException と 2 本立てになってしまう。
+        //   この境界は明示検査で RuntimeException に統一する。
+        if (! str_starts_with($base, DIRECTORY_SEPARATOR)) {
+            throw new RuntimeException("観測用の置き場所は絶対パスであること: {$base}");
+        }
+
+        if (! is_dir($base) || ! is_writable($base)) {
+            throw new RuntimeException("観測用の置き場所を使用できない: {$base}");
+        }
+
+        $created = rtrim($base, DIRECTORY_SEPARATOR).'/fake-wiring-probe-'.bin2hex(random_bytes(8));
+
+        if (! mkdir($created, 0700) || ! is_dir($created)) {
+            throw new RuntimeException("観測用の一時ディレクトリを作れない: {$created}");
+        }
+
+        try {
+            $directory = realpath($created);
+            if (! is_string($directory) || $directory === '') {
+                throw new RuntimeException("観測用の一時ディレクトリを正規化できない: {$created}");
+            }
+
+            // 正典 (5) の fail-closed。ここを緩めると環境ファイルがリポジトリへ落ちる。
+            // ★両辺とも realpath 済みで比べる (FakeClassCatalog::repoRoot() は dirname() の結果で
+            //   正規化されていないため、symlink 越しだと素の比較が取り違える)。
+            $repositoryRoot = realpath(FakeClassCatalog::repoRoot());
+            if (! is_string($repositoryRoot) || $repositoryRoot === '') {
+                throw new RuntimeException('リポジトリ root を正規化できない');
+            }
+
+            if (BootProbeRunner::isInside($repositoryRoot, $directory)) {
+                throw new RuntimeException(
+                    "観測用の一時ディレクトリがリポジトリ内にある: {$directory}"
+                );
+            }
+
+            // 実効の権限で確かめる (chmod の戻り値だけでは umask 等の影響を捕まえられない)。
+            if (! chmod($directory, 0700) || self::mode($directory) !== 0700) {
+                throw new RuntimeException("観測用の一時ディレクトリを 0700 にできない: {$directory}");
+            }
+
+            return $body($directory);
+        } finally {
+            self::removeDirectory($created);
+        }
+    }
+
     /**
      * 観測を 1 回走らせる。
      *
-     * @param  string|null  $baseDirectory  一時ディレクトリを作る親 (省略時は sys_get_temp_dir())
+     * @param  string|null  $baseDirectory  環境ファイルの置き場を作る親 (省略時は sys_get_temp_dir())
+     * @param  positive-int  $timeoutSeconds
      * @return array{
      *     exitCode: int,
      *     output: array<string, mixed>,
      *     envFileValues: array<string, string>,
+     *     caseEnvValues: array<string, string>,
      *     directory: string,
      *     directoryMode: int,
      *     envFileMode: int,
-     *     configCachePath: string,
-     *     configCacheExists: bool,
+     *     temporaryRoot: string,
+     *     writtenRelativePaths: list<string>,
      * }
      */
     public static function run(
@@ -91,59 +204,108 @@ public static function run(
         bool $fakeStorage,
         bool $fakeLlm,
         ?string $baseDirectory = null,
-        float $timeout = 120.0,
+        int $timeoutSeconds = 120,
     ): array {
-        $base = $baseDirectory ?? sys_get_temp_dir();
-        $directory = $base.'/fake-wiring-probe-'.bin2hex(random_bytes(8));
+        // 置き場所の作成・リポジトリ外の fail-closed・0700 の確認・後片付けは helper が持つ。
+        return self::withEnvironmentDirectory(
+            $baseDirectory,
+            static function (string $directory) use ($environment, $fakeExternals, $fakeStorage, $fakeLlm, $timeoutSeconds): array {
+                $values = self::envFileValues($environment, $fakeExternals, $fakeStorage, $fakeLlm);
+                $envFilePath = $directory.'/'.self::ENV_FILE_NAME;
+                self::writeEnvFile($envFilePath, $values);
+
+                $directoryMode = self::mode($directory);
+                $envFileMode = self::mode($envFilePath);
+
+                // 起動前に権限を確かめ、違えば子を起こさない (秘密を持たない設計だが置き場所は守る)。
+                self::assertSafePermissions($directoryMode, $envFileMode);
+
+                $caseEnv = self::caseEnvValues($directory);
 
-        if (! mkdir($directory, 0700) || ! is_dir($directory)) {
-            throw new RuntimeException("観測用の一時ディレクトリを作れない: {$directory}");
+                // 子の起こし方・回収・書き出し先の退避は共通 runner が持つ
+                // (lctl feature: subprocess-boot-probe-harness の正典 v1 (1)〜(5))。
+                $result = BootProbeRunner::run([self::probeScriptPath()], $caseEnv, $timeoutSeconds);
+
+                return self::interpret($result, $values, $caseEnv, $directory, $directoryMode, $envFileMode);
+            },
+        );
+    }
+
+    /**
+     * ケース別上書きの中身 (使い捨て鍵はここで作る)。
+     *
+     * @return array<string, string>
+     */
+    public static function caseEnvValues(string $directory): array
+    {
+        $values = [
+            'FAKE_WIRING_PROBE_ENV_DIR' => $directory,
+            'FAKE_WIRING_PROBE_ENV_FILE' => self::ENV_FILE_NAME,
+            // 実鍵は複写せず、起動のたびに使い捨ての値を生成する。
+            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
+        ];
+
+        foreach (array_keys($values) as $key) {
+            if (! in_array($key, self::CASE_ENV_KEYS, true)) {
+                throw new RuntimeException("ケース別上書きに置けないキー: {$key}");
+            }
         }
 
-        try {
-            chmod($directory, 0700);
-
-            $values = self::envFileValues($environment, $fakeExternals, $fakeStorage, $fakeLlm);
-            $envFilePath = $directory.'/'.self::ENV_FILE_NAME;
-            self::writeEnvFile($envFilePath, $values);
-
-            $directoryMode = self::mode($directory);
-            $envFileMode = self::mode($envFilePath);
-
-            // 起動前に権限を確かめ、違えば子を起こさない (秘密を持たない設計だが置き場所は守る)。
-            self::assertSafePermissions($directoryMode, $envFileMode);
-
-            $configCachePath = $directory.'/config-cache-absent.php';
-
-            $process = new Process(
-                [
-                    'env', '-i',
-                    'FAKE_WIRING_PROBE_ENV_DIR='.$directory,
-                    'FAKE_WIRING_PROBE_ENV_FILE='.self::ENV_FILE_NAME,
-                    'APP_CONFIG_CACHE='.$configCachePath,
-                    PHP_BINARY,
-                    self::probeScriptPath(),
-                ],
-                FakeClassCatalog::repoRoot(),
-                null,
-                null,
-                $timeout,
+        return $values;
+    }
+
+    /**
+     * runner の結果を観測結果へ翻訳する (**純関数**。子を起こさずに負例を測れる)。
+     *
+     * ★fail-closed を 4 つ持つ:
+     *   1. 制限時間超過 (`timedOut`) は**通常の非ゼロ終了と区別して例外**にする。
+     *      false や非ゼロ終了へ落とすと「観測できなかった」ことが沈黙する (fail-open)
+     *   2. 出力が空 → 例外 (観測が成立していない)
+     *   3. JSON として読めない → 例外
+     *   4. トップレベルが配列でない → 例外
+     * ★判定には `timedOut` を使い、`exitCode === 124` を直接読まない
+     *   (終了要求を受けてから自分で `exit(0)` する子は `timedOut` かつ `exitCode === 0` になりうる)。
+     *
+     * @param  array<string, string>  $envFileValues
+     * @param  array<string, string>  $caseEnv
+     * @return array{
+     *     exitCode: int,
+     *     output: array<string, mixed>,
+     *     envFileValues: array<string, string>,
+     *     caseEnvValues: array<string, string>,
+     *     directory: string,
+     *     directoryMode: int,
+     *     envFileMode: int,
+     *     temporaryRoot: string,
+     *     writtenRelativePaths: list<string>,
+     * }
+     */
+    public static function interpret(
+        BootProbeResult $result,
+        array $envFileValues,
+        array $caseEnv,
+        string $directory,
+        int $directoryMode,
+        int $envFileMode,
+    ): array {
+        if ($result->timedOut) {
+            throw new RuntimeException(
+                '観測用の子プロセスが制限時間を超えて強制終了された (観測が成立していない)。'
+                ."終了コード: {$result->exitCode} / 標準エラー: ".$result->stderr
             );
-            $process->run();
-
-            return [
-                'exitCode' => $process->getExitCode() ?? -1,
-                'output' => self::decode($process->getOutput()),
-                'envFileValues' => $values,
-                'directory' => $directory,
-                'directoryMode' => $directoryMode,
-                'envFileMode' => $envFileMode,
-                'configCachePath' => $configCachePath,
-                'configCacheExists' => file_exists($configCachePath),
-            ];
-        } finally {
-            self::removeDirectory($directory);
         }
+
+        return [
+            'exitCode' => $result->exitCode,
+            'output' => self::decode($result->stdout),
+            'envFileValues' => $envFileValues,
+            'caseEnvValues' => $caseEnv,
+            'directory' => $directory,
+            'directoryMode' => $directoryMode,
+            'envFileMode' => $envFileMode,
+            'temporaryRoot' => $result->temporaryRoot,
+            'writtenRelativePaths' => $result->writtenRelativePaths,
+        ];
     }
 
     /**
@@ -161,7 +323,6 @@ public static function envFileValues(
         // 形式は現行の設定が受理する形に合わせる (妥当性は「子が起動できたこと」自体が示す)。
         $values = [
             'APP_ENV' => $environment,
-            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
             'APP_URL' => self::PROBE_APP_URL,
             'APP_DEBUG' => 'false',
             'CIPHERSWEET_KEY' => bin2hex(random_bytes(32)),
diff --git a/tests/Support/ExternalFakes/fake-wiring-probe.php b/tests/Support/ExternalFakes/fake-wiring-probe.php
index 8c18778b..f0009799 100644
--- a/tests/Support/ExternalFakes/fake-wiring-probe.php
+++ b/tests/Support/ExternalFakes/fake-wiring-probe.php
@@ -6,14 +6,22 @@
 use App\Support\ExternalFakes\ExternalFakeDeclaration;
 use Illuminate\Contracts\Console\Kernel;
 use Illuminate\Foundation\Application;
+use Tests\Support\ExternalFakes\FakeWiringProbeRunner;
 use Webmozart\Assert\Assert;
 
 /*
  * 別プロセスで「宣言した差し替えが実際に効いているか」を観測して JSON を書き出す。
  *
- * ★責務は 4 つだけ: DB へ接続しない / container から解決する /
- *   転送先 URL を組み立てて読む / 終了コードを返す。
- *   HTTP サーバもブラウザも起動しない。
+ * ★責務は 6 つだけ:
+ *   1. DB へ接続しない
+ *   2. container から解決する
+ *   3. 転送先 URL を組み立てて読む (**偽物が有効なときだけ**)
+ *   4. **実働証明の印を storage_path() 経由で 1 本書く** (正典 v1 (5))
+ *   5. **起動しきったアプリが解決した書き出し先 8 種と、効いた鍵 2 種の digest を報告する**
+ *   6. 終了コードを返す
+ * ★**観測しないもの**: HTTP サーバもブラウザも起動しない /
+ *   設定キャッシュ**有り**の起動は観測しない / 外部へ 1 度も通信しない
+ *   (転送先は組み立てて URL を読むだけ)。
  * ★禁止する文 (echo) を使わないため fwrite(STDOUT, …) で書く (AGENTS.md §禁止する文)。
  * ★読み込む環境ファイルを**専用の一時ファイルだけ**に固定する (親のチェックアウトの
  *   .env / .env.bughunt.local を読ませない = 実資格情報が子の設定へ入らない)。
@@ -45,6 +53,19 @@
 
     $app->make(Kernel::class)->bootstrap();
 
+    /*
+     * ★正典 v1 (5) の**実働証明**の観測点 (lctl feature: subprocess-boot-probe-harness)。
+     *   「書き出し先を環境変数で退避した」ことは、退避が**効いていなければ**既定の場所
+     *   (リポジトリの storage/) へ書かれ、観測は緑のまま嘘になる。そこで
+     *   Laravel の storage_path() 経由で印を 1 本置き、それが起動器の一時ディレクトリ配下に
+     *   現れたことを呼び出し側 (P-13) が確かめる。
+     *   置き場所 (storage/app/private) は起動器が事前に掘っている。
+     */
+    $markerPath = $app->storagePath(FakeWiringProbeRunner::MARKER_RELATIVE_PATH);
+    if (file_put_contents($markerPath, 'fake-wiring-probe') === false) {
+        throw new RuntimeException("観測の印を書けない: {$markerPath}");
+    }
+
     $resolved = [];
     foreach (ExternalFakeDeclaration::swaps() as $swap) {
         $resolved[$swap->abstract] = $app->make($swap->abstract)::class;
@@ -71,6 +92,23 @@
         'resolved' => $resolved,
         'redirect_host' => $redirectHost,
         'process_environment_keys' => $processEnvironmentKeys,
+        // ★P-14 (向き): 起動しきったアプリが解決した書き出し先。呼び出し側が
+        //   「1 件残らず一時ディレクトリ配下で、リポジトリの外」であることを確かめる。
+        'write_targets' => [
+            'storage' => $app->storagePath(),
+            'config_cache' => $app->getCachedConfigPath(),
+            'routes_cache' => $app->getCachedRoutesPath(),
+            'services_cache' => $app->getCachedServicesPath(),
+            'packages_cache' => $app->getCachedPackagesPath(),
+            'events_cache' => $app->getCachedEventsPath(),
+            'view_compiled' => (string) config('view.compiled'),
+            'log_path' => (string) config('logging.channels.single.path'),
+        ],
+        // ★P-8 (使い捨て鍵が子で効いたこと)。鍵そのものは出力しない (テスト出力へ鍵を流さない)。
+        'key_digests' => [
+            'app' => hash('sha256', (string) config('app.key')),
+            'ciphersweet' => hash('sha256', (string) config('ciphersweet.providers.string.key')),
+        ],
     ], JSON_THROW_ON_ERROR));
 
     exit(0);
diff --git a/tests/Support/Process/BootProbeResult.php b/tests/Support/Process/BootProbeResult.php
new file mode 100644
index 00000000..c0af2ec0
--- /dev/null
+++ b/tests/Support/Process/BootProbeResult.php
@@ -0,0 +1,43 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Process;
+
+use Webmozart\Assert\Assert;
+
+/**
+ * probe 1 回分の観測結果 (一時ディレクトリを消す前に採取したスナップショットを含む)。
+ *
+ * `Tests\Support\Process\BootProbeRunner` が唯一の生成者である (lctl feature:
+ * subprocess-boot-probe-harness)。
+ */
+final readonly class BootProbeResult
+{
+    /**
+     * @param  non-negative-int  $exitCode  強制終了なら BootProbeRunner::TIMEOUT_EXIT_CODE
+     * @param  non-empty-string  $temporaryRoot  実行に使った一時ディレクトリ (実行後は消えている)
+     * @param  list<non-empty-string>  $writtenRelativePaths  一時ディレクトリ配下に書かれたもの (昇順)
+     * @param  positive-int  $pid  回収した子の pid。**回収済みの死骸の番号**であり操作対象ではない
+     *                             (自己検査が「子が残っていない」ことを確かめるためだけに持つ)
+     */
+    public function __construct(
+        public string $stdout,
+        public string $stderr,
+        public int $exitCode,
+        public bool $timedOut,
+        public float $elapsedSeconds,
+        public string $temporaryRoot,
+        public array $writtenRelativePaths,
+        public int $pid,
+    ) {
+        Assert::natural($exitCode);
+        Assert::true(
+            is_finite($elapsedSeconds) && $elapsedSeconds >= 0.0,
+            '所要時間が有限の非負値でない',
+        );
+        Assert::stringNotEmpty($temporaryRoot);
+        Assert::allStringNotEmpty($writtenRelativePaths);
+        Assert::positiveInteger($pid);
+    }
+}
diff --git a/tests/Support/Process/BootProbeRunner.php b/tests/Support/Process/BootProbeRunner.php
new file mode 100644
index 00000000..df4b1e56
--- /dev/null
+++ b/tests/Support/Process/BootProbeRunner.php
@@ -0,0 +1,656 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Process;
+
+use FilesystemIterator;
+use RecursiveDirectoryIterator;
+use RecursiveIteratorIterator;
+use RuntimeException;
+use SplFileInfo;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 起動順序を子プロセスで実測するための probe 起動器 (lctl feature: subprocess-boot-probe-harness)。
+ *
+ * アプリの壊れ方には「どの順番で組み立てられたか」に由来するものがあり、テストが走る時点で
+ * そのプロセスの起動は終わっているため同じプロセスの中では再現できない。ここは
+ * **小さな子プロセスを 1 つ起こして観測結果を回収する**、その起こし方と後始末だけを持つ。
+ * 何を観測するかは呼び出し側 (gate) の責務である。
+ *
+ * ## 正典 v1 の 6 要素と本実装
+ *
+ *  1. 親と同じ実行体で起こす — `PHP_BINARY` を先頭に固定し、`$phpArguments` はその後ろに置く
+ *  2. 環境変数は 3 段 — 継承 (許可一覧) → 基底 → ケース別上書き。子は `proc_open` に渡した
+ *     配列だけを受け取るので、ここが開発者ローカルの env を締め出す唯一の統制点になる
+ *  3. 出力は非ブロッキングで逐次読み、制限時間を超えたら SIGTERM → 猶予 → SIGKILL で落とし、
+ *     全ての管を閉じてから必ず `proc_close` する
+ *  4. 終了コードは実行中フラグが初めて false になった時点の非負値を保持し、`proc_close` の
+ *     戻り値で上書きしない。強制終了で取れなければ 124 へ正規化する
+ *  5. 子の書き出し先は**リポジトリ外の一時ディレクトリ**へ逃がす (下記 RESERVED_ENV_KEYS)。
+ *     一時ディレクトリがリポジトリ内になったら子を起こす前に例外にする (fail-closed)
+ *  6. 自己検査を持つ — `tests/Unit/Support/Process/BootProbeRunnerTest.php`
+ *
+ * ## 正典 v1 との差分 (1 点だけ)
+ *
+ * 書き出し先の 7 キー (RESERVED_ENV_KEYS) は runner が作った一時ディレクトリから導く
+ * **予約鍵**であり、呼び出し側から渡せない (渡したら例外)。黙って無視すると結果の
+ * `temporaryRoot` / `writtenRelativePaths` が嘘になり、正典 (5) の保証が空洞化するためである。
+ * 環境変数の**順序**は正典と同じで、ケース別上書きが最後に効く。
+ * 「固定鍵を呼び出し側より後ろに置いて上書き不能にする」テンプレート固有の作法は、その理由を
+ * 持つ呼び出し側 (`tests/Architecture/BughuntFakeWiringTest.php`) が `array_merge($env, [...])`
+ * で表現する (runner へ持ち上げると、逆の契約を持つ検査 — 呼び出し側が `APP_KEY` を 2 通り
+ * 与えて観測差を測る `BugHuntInventoryCheckInvariantTest` の CT-3 — が載らなくなる)。
+ *
+ * ## 保証しないこと
+ *
+ *  - **孫プロセスは回収しない**。`proc_terminate()` が届くのは直接の子だけである
+ *    (probe が孫を起こさないことは probe 側の前提)
+ *  - **子が書く先を全部押さえること**は保証しない。退避できるのは Laravel が環境変数で受ける
+ *    既知の書き出し先までで、独自パスへの書き込みは閉じない
+ *  - **子が外部へ通信しないこと**は本クラスの主張ではない (probe の中身の責務)
+ *  - **Unix 系 (Linux / macOS) 前提**である。段階的な強制終了は POSIX のシグナル意味論に依存する
+ *  - **回収不能だった場合の振る舞いは実測していない**。子を落とせなかったときは一時ディレクトリを
+ *    消さずに場所を例外へ書いて残す (生きている子の足元を壊さないため) が、この分岐は
+ *    `SIGKILL` を無視できない以上作り出せないので自己検査で覆えていない
+ *
+ * **`tests/` 専用**である。`app` / `routes` / `config` / `database` / `bootstrap` へ持ち出すと
+ * 外部到達統制の subprocess 0 件 pin に触れる (AGENTS.md セキュリティ不変条件 15)。
+ * 同じ扱いの先例は `tests/Support/Architecture/GlobalUse/PhpLintOracle.php`。
+ */
+final class BootProbeRunner
+{
+    /** 強制終了で終了コードが取れなかったときの正規化値 (GNU timeout(1) と同じ)。 */
+    public const int TIMEOUT_EXIT_CODE = 124;
+
+    /** 既定の制限時間 (秒)。実測では probe 1 本が 1 秒前後で終わる。 */
+    public const int DEFAULT_TIMEOUT_SECONDS = 60;
+
+    /** 終了要求から強制終了までの猶予 (秒)。 */
+    public const int TERMINATION_GRACE_SECONDS = 2;
+
+    /** 子の終了を検知してから管を読み切るまでの上限 (秒。孫が管を持っていても回収を止めない)。 */
+    public const int FINAL_DRAIN_SECONDS = 2;
+
+    /** 強制終了を送ってから諦めるまでの最終期限 (秒)。超えたら例外にする。 */
+    public const int KILL_WAIT_SECONDS = 5;
+
+    /**
+     * 親から継承する環境変数 (文字列かつ非空のときだけ継承する。既定値へ差し替えない)。
+     *
+     *  - `PATH`: 子が外部コマンドを解決するため (`PHP_BINARY` は絶対パスなので必須ではない)
+     *  - `HOME`: composer / vendor が HOME 依存の場所を引く
+     *  - `TMPDIR`: 子自身が一時ファイルを作るときの置き場所
+     *
+     * `LC_*` / `TZ` / `LANG` は継承しない (入力集合を広げる。時間帯は `config/app.php` が決める)。
+     *
+     * @var list<non-empty-string>
+     */
+    public const array INHERITED_ENV_KEYS = ['PATH', 'HOME', 'TMPDIR'];
+
+    /**
+     * runner が予約する環境変数 (書き出し先)。呼び出し側が渡したら例外にする。
+     *
+     * @var list<non-empty-string>
+     */
+    public const array RESERVED_ENV_KEYS = [
+        'LARAVEL_STORAGE_PATH',
+        'VIEW_COMPILED_PATH',
+        'APP_CONFIG_CACHE',
+        'APP_ROUTES_CACHE',
+        'APP_SERVICES_CACHE',
+        'APP_PACKAGES_CACHE',
+        'APP_EVENTS_CACHE',
+    ];
+
+    /** ext-pcntl に依存しないためシグナル番号を直接持つ。 */
+    private const int SIGNAL_TERMINATE = 15;
+
+    private const int SIGNAL_KILL = 9;
+
+    /** 出力を 1 回に読む上限 (バイト)。パイプバッファ (64KiB 程度) に合わせる。 */
+    private const int READ_CHUNK_BYTES = 65536;
+
+    /** 読む管が 1 本も無いときに眠る時間 (マイクロ秒)。回転で CPU を焼かないための休符。 */
+    private const int IDLE_SLEEP_MICROSECONDS = 20000;
+
+    /** 出力を待つ 1 回の上限 (マイクロ秒)。 */
+    private const int SELECT_WAIT_MICROSECONDS = 50000;
+
+    /** 基底の暗号鍵の種 (値そのものは観測に影響しない。CI の素の `.env` が空鍵であることへの備え)。 */
+    private const string BASE_APP_KEY_SEED = 'laravel-claude-template:boot-probe';
+
+    /**
+     * probe を 1 本起こして結果を回収する。
+     *
+     * @param  list<non-empty-string>  $phpArguments  `PHP_BINARY` の後ろに置く引数
+     *                                                (`['-r', $code]` / `[$scriptPath]`)
+     * @param  array<non-empty-string, string>  $env  ケース別上書き (基底より後に効く)
+     * @param  positive-int  $timeoutSeconds
+     * @param  ?non-empty-string  $temporaryBase  一時ディレクトリの置き場所。既定は
+     *                                            `sys_get_temp_dir()`。**退避を無効化する口ではない**
+     *                                            (リポジトリ配下を渡すと例外になる。自己検査が
+     *                                            その fail-closed を確かめるための場所指定である)
+     */
+    public static function run(
+        array $phpArguments,
+        array $env = [],
+        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
+        ?string $temporaryBase = null,
+    ): BootProbeResult {
+        Assert::notEmpty($phpArguments, 'probe の引数が空である');
+        Assert::allStringNotEmpty($phpArguments);
+        Assert::allStringNotEmpty(array_keys($env));
+        Assert::allString($env);
+        Assert::positiveInteger($timeoutSeconds);
+
+        $reserved = array_values(array_intersect(self::RESERVED_ENV_KEYS, array_keys($env)));
+        if ($reserved !== []) {
+            throw new RuntimeException(
+                '書き出し先は runner が決める (呼び出し側から渡せない): '.implode(', ', $reserved),
+            );
+        }
+
+        $repositoryRoot = self::repositoryRoot();
+        $temporaryRoot = self::createTemporaryRoot($temporaryBase ?? sys_get_temp_dir(), $repositoryRoot);
+
+        // 「消してよいか」= 子が生存し得ないか。子がいないうちは消してよい (残骸を残さない)。
+        // 遷移: 一時ディレクトリ作成直後 = true → `proc_open` 成功直後 = false
+        //       → 回収成功後 = true / 回収不能 = false のまま
+        $safeToRemove = true;
+
+        try {
+            $result = self::spawn(
+                $phpArguments,
+                self::composeEnv($env, $temporaryRoot),
+                $repositoryRoot,
+                $temporaryRoot,
+                $timeoutSeconds,
+                $safeToRemove,
+            );
+        } catch (Throwable $failure) {
+            // 生きているかもしれない子の足元は消さない (残った場所は spawn() が投げる例外に書く)。
+            if ($safeToRemove) {
+                try {
+                    self::removeDirectory($temporaryRoot);
+                } catch (Throwable $removalFailure) {
+                    // 後片付けの失敗で**本来の例外を捨てない** (previous に残す)
+                    throw new RuntimeException(
+                        '一時ディレクトリを消せなかった: '.$temporaryRoot
+                        .' / 削除の失敗: '.$removalFailure->getMessage(),
+                        0,
+                        $failure,
+                    );
+                }
+            }
+
+            throw $failure;
+        }
+
+        self::removeDirectory($temporaryRoot);   // 正常経路。削除の失敗は例外のまま伝播させる
+
+        return $result;
+    }
+
+    /**
+     * `$candidate` が `$root` の配下か。
+     *
+     * 素の前方一致だと `/repo` が `/repository` を配下と誤判定するので、区切り文字を境界にする。
+     * 自己検査が境界の振る舞いを直接 pin できるよう公開する。
+     *
+     * **両引数とも `realpath` 済みの絶対パス**であること (相対パスや `..` を含む形は受け付けない。
+     * 正規化は呼び出し側の責務であり、ここでは絶対パスであることだけを `Assert` で確かめる)。
+     */
+    public static function isInside(string $root, string $candidate): bool
+    {
+        Assert::startsWith($root, DIRECTORY_SEPARATOR);
+        Assert::startsWith($candidate, DIRECTORY_SEPARATOR);
+
+        $normalizedRoot = rtrim($root, DIRECTORY_SEPARATOR);
+
+        return $candidate === $normalizedRoot
+            || str_starts_with($candidate, $normalizedRoot.DIRECTORY_SEPARATOR);
+    }
+
+    /**
+     * 基底 (呼び出し側が上書きできる hermetic な既定)。**この 3 本しか置かない**。
+     *
+     *  - `APP_KEY`: CI の素の `.env` は `APP_KEY` が空で、encrypter を引いた瞬間に
+     *    `MissingAppKeyException` で死ぬ (ローカル緑 / CI 赤の実測退行)。観測値は鍵に依存しない
+     *  - `QUEUE_CONNECTION`: 開発機の `.env` が `redis` だと観測が変わる
+     *  - `CACHE_STORE`: 1 プロセスで完結させ、DB / redis を張らせない
+     *
+     * **`APP_ENV` は置かない**。「渡さない実行では素の `.env` を読む」という観測が
+     * 呼び出し側 (`BughuntFakeWiringTest`) の複数ケースの前提になっているためである。
+     * ロケール系 (`LANG` / `LC_*`) も置かない (誰も依存せず、置くほど入力集合が広がる)。
+     *
+     * @return array<non-empty-string, string>
+     */
+    private static function baseEnv(): array
+    {
+        return [
+            'APP_KEY' => 'base64:'.base64_encode(hash('sha256', self::BASE_APP_KEY_SEED, true)),
+            'QUEUE_CONNECTION' => 'database',
+            'CACHE_STORE' => 'array',
+        ];
+    }
+
+    /** リポジトリ root (このファイルは `tests/Support/Process/` に居る)。 */
+    private static function repositoryRoot(): string
+    {
+        $root = realpath(dirname(__DIR__, 3));
+
+        if (! is_string($root)) {
+            throw new RuntimeException('リポジトリ root を解決できなかった');
+        }
+
+        return $root;
+    }
+
+    /**
+     * 一時ディレクトリを作り、リポジトリ外であることを確かめて子が書く下位を掘る。
+     *
+     * 途中のどの失敗でも作った root を消してから元の例外を投げ直す (作りかけを残さない)。
+     *
+     * @return non-empty-string
+     */
+    private static function createTemporaryRoot(string $base, string $repositoryRoot): string
+    {
+        Assert::startsWith($base, DIRECTORY_SEPARATOR, '一時ディレクトリの置き場所は絶対パスであること');
+        Assert::directory($base);
+        Assert::writable($base);
+
+        $created = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'boot-probe-'.bin2hex(random_bytes(8));
+
+        if (! mkdir($created, 0o700, true)) {
+            throw new RuntimeException('一時ディレクトリを作れなかった: '.$created);
+        }
+
+        try {
+            $temporaryRoot = realpath($created);
+
+            if (! is_string($temporaryRoot) || $temporaryRoot === '') {
+                throw new RuntimeException('一時ディレクトリを正規化できなかった: '.$created);
+            }
+
+            if (self::isInside($repositoryRoot, $temporaryRoot)) {
+                // 正典 (5) の fail-closed。ここを緩めると probe の書き出しがリポジトリへ落ちる。
+                throw new RuntimeException(
+                    'probe の一時ディレクトリがリポジトリ内にある (書き出し先を退避できない): '.$temporaryRoot,
+                );
+            }
+
+            foreach ([
+                'storage/framework/views',
+                'storage/framework/cache/data',
+                'storage/framework/sessions',
+                'storage/logs',
+                'storage/app/private',
+                'bootstrap-cache',
+            ] as $relative) {
+                $directory = $temporaryRoot.DIRECTORY_SEPARATOR.$relative;
+                if (! mkdir($directory, 0o700, true)) {
+                    throw new RuntimeException('一時ディレクトリの下位を作れなかった: '.$directory);
+                }
+            }
+
+            return $temporaryRoot;
+        } catch (Throwable $failure) {
+            self::removeDirectory($created);
+
+            throw $failure;
+        }
+    }
+
+    /**
+     * 環境変数の 3 段合成 + 予約鍵 (正典 v1 の (2) と (5))。
+     *
+     * @param  array<non-empty-string, string>  $caseEnv
+     * @return array<non-empty-string, string>
+     */
+    private static function composeEnv(array $caseEnv, string $temporaryRoot): array
+    {
+        $inherited = [];
+        foreach (self::INHERITED_ENV_KEYS as $key) {
+            $value = getenv($key);
+            if (is_string($value) && $value !== '') {
+                $inherited[$key] = $value;
+            }
+        }
+
+        $storage = $temporaryRoot.'/storage';
+        $bootstrapCache = $temporaryRoot.'/bootstrap-cache';
+
+        $reserved = [
+            'LARAVEL_STORAGE_PATH' => $storage,
+            'VIEW_COMPILED_PATH' => $storage.'/framework/views',
+            'APP_CONFIG_CACHE' => $bootstrapCache.'/config.php',
+            'APP_ROUTES_CACHE' => $bootstrapCache.'/routes-v7.php',
+            'APP_SERVICES_CACHE' => $bootstrapCache.'/services.php',
+            'APP_PACKAGES_CACHE' => $bootstrapCache.'/packages.php',
+            'APP_EVENTS_CACHE' => $bootstrapCache.'/events.php',
+        ];
+
+        // 予約鍵の宣言 (公開定数) と実体が食い違ったら、S4 の pin も run() の拒否も嘘になる。
+        Assert::same(array_keys($reserved), self::RESERVED_ENV_KEYS, '予約鍵の宣言と実体が食い違っている');
+
+        return array_merge($inherited, self::baseEnv(), $caseEnv, $reserved);
+    }
+
+    /**
+     * 子を起こし、逐次読み・制限時間・回収まで面倒を見る。
+     *
+     * @param  list<non-empty-string>  $phpArguments
+     * @param  array<non-empty-string, string>  $env
+     * @param  non-empty-string  $temporaryRoot
+     * @param  positive-int  $timeoutSeconds
+     */
+    private static function spawn(
+        array $phpArguments,
+        array $env,
+        string $repositoryRoot,
+        string $temporaryRoot,
+        int $timeoutSeconds,
+        bool &$safeToRemove,
+    ): BootProbeResult {
+        $startedAt = microtime(true);
+
+        // 標準入力は /dev/null に向ける。probe が誤って読んでも即 EOF になり、止まる面が 1 つ減る
+        // (管にすると読み手が現れたときに待ち続ける)。
+        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
+
+        $process = proc_open([PHP_BINARY, ...$phpArguments], $descriptors, $pipes, $repositoryRoot, $env);
+
+        if (! is_resource($process)) {
+            throw new RuntimeException('probe の子プロセスを起動できなかった: '.implode(' ', $phpArguments));
+        }
+
+        // ここから先は子が生存しうる。回収できるまで一時ディレクトリを消さない。
+        $safeToRemove = false;
+
+        // 回収の状態は `try` の**前**に置く (`try` 内のどの例外点からも catch が回収を試みられるように)。
+        $state = ['processClosed' => false, 'closeCode' => null];
+
+        try {
+            // pid の取得も回収対象の `try` の中に置く (ここで落ちても子・管・一時ディレクトリを
+            // 一体で回収する = 「proc_open 成功後はどの例外点からも一体で回収する」)。
+            $pid = proc_get_status($process)['pid'];
+
+            foreach ([1, 2] as $descriptor) {
+                $pipe = $pipes[$descriptor] ?? null;
+                if (! is_resource($pipe)) {
+                    throw new RuntimeException('probe の出力管を開けなかった');
+                }
+                if (! stream_set_blocking($pipe, false)) {
+                    throw new RuntimeException('probe の出力を非ブロッキングにできなかった');
+                }
+            }
+
+            $output = [1 => '', 2 => ''];
+            $exitCode = null;          // 実行中フラグが初めて false になった時点の非負値
+            $timedOut = false;
+            $deadline = $startedAt + $timeoutSeconds;
+            $killAt = null;            // 強制終了を送る時刻 (未設定は null)
+            $giveUpAt = null;          // 落とせないと諦める時刻 ($killAt と同時に必ず入る)
+
+            while (true) {
+                self::readAvailable($pipes, $output);   // 詰まらせない (パイプバッファは 64KiB 程度)
+
+                $status = proc_get_status($process);
+                if (! $status['running']) {
+                    if ($exitCode === null && $status['exitcode'] >= 0) {
+                        $exitCode = $status['exitcode'];   // ここで確定させ、以後は上書きしない
+                    }
+                    break;
+                }
+
+                $now = microtime(true);
+
+                // 最終期限は**再送の時刻とは独立**に見る (再送のたびに $killAt を先送りするので、
+                // 期限の確認を再送分岐の中に置くと $giveUpAt を猶予ぶん超過できてしまう)。
+                if ($giveUpAt !== null && $now >= $giveUpAt) {
+                    throw new RuntimeException('probe の子プロセスを強制終了できなかった');
+                }
+
+                if ($killAt === null && $now >= $deadline) {
+                    $timedOut = true;
+                    if (! proc_terminate($process, self::SIGNAL_TERMINATE)) {
+                        throw new RuntimeException('probe の子プロセスへ終了要求を送れなかった');
+                    }
+                    $killAt = $now + self::TERMINATION_GRACE_SECONDS;
+                    $giveUpAt = $killAt + self::KILL_WAIT_SECONDS;
+                } elseif ($killAt !== null && $now >= $killAt) {
+                    // 送信失敗でも即座には諦めない (最終期限 $giveUpAt が唯一の打ち切り点)。
+                    proc_terminate($process, self::SIGNAL_KILL);
+                    $killAt = $now + self::TERMINATION_GRACE_SECONDS;
+                }
+            }
+
+            // 終了検知後の最終読み取り (上限つき)。孫が管を持ったままでも回収を止めない。
+            $drainUntil = microtime(true) + self::FINAL_DRAIN_SECONDS;
+            while (microtime(true) < $drainUntil && self::hasReadablePipe($pipes)) {
+                self::readAvailable($pipes, $output);
+            }
+
+            $closed = self::reclaim($process, $pipes, $state);
+            $safeToRemove = true;
+
+            if ($exitCode === null) {
+                // シグナルで落ちた子は exitcode が -1 になる → 124 へ正規化する
+                $exitCode = $timedOut
+                    ? self::TIMEOUT_EXIT_CODE
+                    : ($closed >= 0 ? $closed : throw new RuntimeException('probe の終了コードを回収できなかった'));
+            }
+
+            return new BootProbeResult(
+                stdout: $output[1],
+                stderr: $output[2],
+                exitCode: $exitCode,
+                timedOut: $timedOut,
+                elapsedSeconds: microtime(true) - $startedAt,
+                temporaryRoot: $temporaryRoot,
+                writtenRelativePaths: self::collectWritten($temporaryRoot),   // 消す前に採取する
+                pid: $pid,
+            );
+        } catch (Throwable $failure) {
+            // 本来の例外を優先しつつ、回収は最後まで試みる。
+            try {
+                self::reclaim($process, $pipes, $state);   // 2 回目以降は保持値を返すだけ
+                $safeToRemove = true;
+            } catch (Throwable $cleanupFailure) {
+                // **回収できなかった** — 一時ディレクトリは残す (場所を例外に書く)
+                throw new RuntimeException(
+                    'probe の子を回収できなかったため一時ディレクトリを残した: '.$temporaryRoot
+                    .' / 回収の失敗: '.$cleanupFailure->getMessage(),
+                    0,
+                    $failure,
+                );
+            }
+
+            throw $failure;
+        }
+    }
+
+    /**
+     * 読める管が 1 本でも残っているか (EOF 済みは数えない)。
+     *
+     * @param  array<int, resource>  $pipes
+     */
+    private static function hasReadablePipe(array $pipes): bool
+    {
+        foreach ([1, 2] as $descriptor) {
+            $pipe = $pipes[$descriptor] ?? null;
+            if (is_resource($pipe) && ! feof($pipe)) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * 読めるだけ読む (非ブロッキング)。
+     *
+     * `feof()` の管は `stream_select` の対象から**外す** — EOF 済みの管を残すと即時 ready になり
+     * 回転し続けるためである。読む対象が 1 本も無ければ少し眠って戻る。
+     *
+     * @param  array<int, resource>  $pipes
+     * @param  array<int, string>  $output
+     */
+    private static function readAvailable(array $pipes, array &$output): void
+    {
+        $read = [];
+        foreach ([1, 2] as $descriptor) {
+            $pipe = $pipes[$descriptor] ?? null;
+            if (is_resource($pipe) && ! feof($pipe)) {
+                $read[$descriptor] = $pipe;
+            }
+        }
+
+        if ($read === []) {
+            usleep(self::IDLE_SLEEP_MICROSECONDS);
+
+            return;
+        }
+
+        $write = null;
+        $except = null;
+        $ready = stream_select($read, $write, $except, 0, self::SELECT_WAIT_MICROSECONDS);
+
+        if ($ready === false) {
+            throw new RuntimeException('probe の出力を待てなかった (stream_select が失敗した)');
+        }
+
+        if ($ready === 0) {
+            return;
+        }
+
+        foreach ($read as $descriptor => $pipe) {
+            $chunk = fread($pipe, self::READ_CHUNK_BYTES);
+            if ($chunk === false) {
+                throw new RuntimeException('probe の出力を読めなかった');
+            }
+            $output[(int) $descriptor] .= $chunk;
+        }
+    }
+
+    /**
+     * 子・管・終了コードを回収する (冪等)。
+     *
+     * `proc_close()` は子が生きているあいだ待つ。だから本 runner は「子の終了を確認する」か
+     * 「確実に落とす」かのどちらかを済ませてからしか呼ばない。
+     *
+     * @param  resource  $process
+     * @param  array<int, resource>  $pipes  閉じた管はその場で unset する (部分完了を表現するため)
+     * @param  array{processClosed: bool, closeCode: int|null}  $state
+     */
+    private static function reclaim($process, array &$pipes, array &$state): int
+    {
+        if ($state['processClosed']) {
+            Assert::integer($state['closeCode']);
+
+            return $state['closeCode'];
+        }
+
+        if (proc_get_status($process)['running']) {
+            // シグナル送信が失敗しても即座には諦めない (自然終了を最終期限まで待つ)。
+            proc_terminate($process, self::SIGNAL_TERMINATE);
+            $killAt = microtime(true) + self::TERMINATION_GRACE_SECONDS;
+            $giveUpAt = $killAt + self::KILL_WAIT_SECONDS;
+
+            while (proc_get_status($process)['running']) {
+                $now = microtime(true);
+                if ($now >= $giveUpAt) {
+                    throw new RuntimeException('probe の子プロセスを落とせなかった (最終期限を超えた)');
+                }
+                if ($now >= $killAt) {
+                    proc_terminate($process, self::SIGNAL_KILL);
+                    $killAt = $now + self::TERMINATION_GRACE_SECONDS;
+                }
+                usleep(self::IDLE_SLEEP_MICROSECONDS);
+            }
+        }
+
+        foreach ([1, 2] as $descriptor) {
+            $pipe = $pipes[$descriptor] ?? null;
+            if (is_resource($pipe)) {
+                fclose($pipe);
+            }
+            unset($pipes[$descriptor]);
+        }
+
+        // `proc_close()` は -1 を返す場合も資源を閉じている。戻ってきた時点で閉じ済みとして扱う
+        // (「非負のときだけ完了」にすると閉じ済みの資源へ 2 度目を呼ぶ危険がある)。
+        $closeCode = proc_close($process);
+        $state['processClosed'] = true;
+        $state['closeCode'] = $closeCode;
+
+        return $closeCode;
+    }
+
+    /**
+     * 一時ディレクトリ配下に書かれたファイルを相対パスの昇順で採取する。
+     *
+     * @return list<non-empty-string>
+     */
+    private static function collectWritten(string $temporaryRoot): array
+    {
+        $prefix = rtrim($temporaryRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
+        $written = [];
+
+        $iterator = new RecursiveIteratorIterator(
+            new RecursiveDirectoryIterator($temporaryRoot, FilesystemIterator::SKIP_DOTS),
+        );
+
+        /** @var SplFileInfo $file */
+        foreach ($iterator as $file) {
+            if (! $file->isFile()) {
+                continue;
+            }
+
+            $path = $file->getPathname();
+            if (! str_starts_with($path, $prefix)) {
+                // 黙って捨てない (追えないものが出たら設計の前提が崩れている)。
+                throw new RuntimeException('一時ディレクトリ外のファイルを採取した: '.$path);
+            }
+
+            $relative = substr($path, strlen($prefix));
+            Assert::stringNotEmpty($relative);
+            $written[] = $relative;
+        }
+
+        sort($written);
+
+        return $written;
+    }
+
+    /** 再帰削除 (存在しなければ何もしない)。**失敗したら例外**にする (黙って残さない)。 */
+    private static function removeDirectory(string $path): void
+    {
+        if (! is_dir($path)) {
+            return;
+        }
+
+        $iterator = new RecursiveIteratorIterator(
+            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
+            RecursiveIteratorIterator::CHILD_FIRST,
+        );
+
+        /** @var SplFileInfo $entry */
+        foreach ($iterator as $entry) {
+            $removed = $entry->isDir() && ! $entry->isLink()
+                ? rmdir($entry->getPathname())
+                : unlink($entry->getPathname());
+
+            if (! $removed) {
+                throw new RuntimeException('一時ディレクトリの中身を消せなかった: '.$entry->getPathname());
+            }
+        }
+
+        if (! rmdir($path)) {
+            throw new RuntimeException('一時ディレクトリを消せなかった: '.$path);
+        }
+    }
+}
diff --git a/tests/Support/StrictTypesRuntimeProbe.php b/tests/Support/StrictTypesRuntimeProbe.php
index 692781a5..bb2d0ed5 100644
--- a/tests/Support/StrictTypesRuntimeProbe.php
+++ b/tests/Support/StrictTypesRuntimeProbe.php
@@ -23,6 +23,16 @@
  *      例外にする。false を返して黙らない (fail-open 防止)
  *   3. 標識が `STRICT-<nonce>` なら true、`WEAK-<nonce>` なら false
  *   関数名も nonce つきにして、検体側の関数と衝突しないようにする。
+ *
+ * ★**共通の起動器 (Tests\Support\Process\BootProbeRunner) には載せない**
+ *   (lctl feature: subprocess-boot-probe-harness)。あちらが測るのは「**起動順序**に由来する
+ *   壊れ方」であり、本クラスが測るのは単一ファイルのコンパイル指令が効くかである。
+ *   載せるとアプリ起動用の基底環境・書き出し先 7 キーの予約・一時ディレクトリの構築という
+ *   検体の判定に無関係な前提が付く。同じ理由で `tests/Support/GlobalUse/PhpLintOracle.php`
+ *   (`php -l` の真値取り出し) も載せていない。
+ *   ★回収は Symfony の Process に委ねる (既定の制限時間つきで、超過すれば例外になる)。
+ *   ★この判断は `tests/Architecture/PhpBootProbeReferenceInventoryTest.php` の
+ *     軸 A の申告 (`launches_app: false` + 理由) として機械に登録されている。
  */
 final class StrictTypesRuntimeProbe
 {
diff --git a/tests/Unit/Support/Process/BootProbeRunnerTest.php b/tests/Unit/Support/Process/BootProbeRunnerTest.php
new file mode 100644
index 00000000..d09fe415
--- /dev/null
+++ b/tests/Unit/Support/Process/BootProbeRunnerTest.php
@@ -0,0 +1,373 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\Process\BootProbeRunner;
+
+/*
+| 起動 probe の共通 runner (`Tests\Support\Process\BootProbeRunner`) の自己検査
+| (lctl feature: subprocess-boot-probe-harness の正典 v1 (6) = 「自己検査を持つ」)。
+|
+| runner は「何を観測するか」を持たない道具なので、道具そのものの契約 —
+| 環境変数の 3 段合成 / 予約鍵 / 終了コードの保持 / 制限時間と強制終了 / 出力の逐次読み /
+| 書き出し先の退避と後片付け — をここで固定する。
+|
+| **この自己検査が測らないこと** (詳細設計 P2 と同じ粒度):
+|
+|  1. runner 自身の途中失敗 (`stream_select` の失敗など) を**注入した**経路は測らない。
+|     注入の継ぎ目を公開面へ足す方が害が大きい
+|  2. 起動そのものが失敗する経路 (`proc_open` の失敗) も測らない。移植性のある誘発手段が無い
+|     (常に `PHP_BINARY` と実在する作業ディレクトリで起こすため)
+|  3. 「回収不能なら一時ディレクトリを残す」経路も測らない。子は `SIGKILL` を無視できないので、
+|     移植性のある形でこの状態を作れない
+|
+| 測るのは 2 方向である: 「落とせない子を確実に落とす」(S12 / S14) と
+| 「起動前の fail-closed で残骸を残さない」(S11)。
+*/
+
+/** 親 env の漏れを見るための番兵 (S1)。 */
+const BOOT_PROBE_SENTINEL_KEY = 'BOOT_PROBE_SENTINEL';
+
+/**
+ * 子が受け取った環境変数を**丸ごと** JSON で報告させる probe (S1 / S2 / S3 / S4)。
+ *
+ * 鍵を列挙して問い合わせる形にすると「列挙に無い鍵が増えても緑」になる (基底に 1 本足しても
+ * 気づけない)。集合そのものを持ち帰らせて完全一致で測る。
+ */
+const BOOT_PROBE_ENV_REPORT = <<<'PHP'
+    echo json_encode(getenv());
+    PHP;
+
+/**
+ * アプリを起こして書き出し先を JSON で報告させる probe (S9 / S10)。
+ *
+ * ★**aicue のローカル修正 (T249)**: 取り込み元 (laravel-claude-template) の検体は
+ *   `bootstrap/app.php` を素で読むため、**リポジトリの `.env` がそのまま子の設定に載っていた**
+ *   (実測で確認: DB パスワードと実 `CIPHERSWEET_KEY`)。これは正典 v1 (2)
+ *   「開発者ローカルの環境変数を入力集合から外す」を、環境ファイル経由で迂回してしまう。
+ *   そこで**起動前に環境ファイルの置き場所を起動器の一時ディレクトリへ逃がす**。
+ *   一時ディレクトリに `.env` は無いので `safeLoad()` は何も読まず、設定の入力は
+ *   **`proc_open` へ渡した環境配列だけ**になる (= 正典 (2) の統制点が唯一になる)。
+ *   一時ディレクトリの絶対パスは予約鍵 `LARAVEL_STORAGE_PATH` (`<root>/storage`) から導き、
+ *   **取れなければ例外にする** (fail-closed。空文字で `useEnvironmentPath()` を呼ぶと
+ *   退避が無言で外れて `/` を環境ファイルの置き場所にしてしまう)。
+ *   実働は S9 の `env_file_path` / `ciphersweet_key_digest` が測る (申告ではなく実挙動)。
+ *   **バイト一致からの意図的な逸脱であり、その理由は上記のとおり
+ *   「セキュリティ不変条件はバイト一致より優先する」である** (AGENTS.md 禁止事項・
+ *   セキュリティ不変条件。詳細は devnotes の実装メモ)。
+ */
+const BOOT_PROBE_PATH_REPORT = <<<'PHP'
+    require 'vendor/autoload.php';
+    $app = require 'bootstrap/app.php';
+    $storagePath = getenv('LARAVEL_STORAGE_PATH');
+    if (! is_string($storagePath) || $storagePath === '') {
+        throw new RuntimeException('LARAVEL_STORAGE_PATH が無い (環境ファイルの退避先を導けない)');
+    }
+    $app->useEnvironmentPath(dirname($storagePath));
+    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
+    Illuminate\Support\Facades\Log::info('boot-probe self check');
+    echo json_encode([
+        'php_binary' => PHP_BINARY,
+        'env_file_path' => $app->environmentFilePath(),
+        'ciphersweet_key_digest' => hash('sha256', (string) config('ciphersweet.providers.string.key')),
+        'db_password_digest' => hash('sha256', (string) config('database.connections.pgsql.password')),
+        'storage' => $app->storagePath(),
+        'config_cache' => $app->getCachedConfigPath(),
+        'routes_cache' => $app->getCachedRoutesPath(),
+        'services_cache' => $app->getCachedServicesPath(),
+        'packages_cache' => $app->getCachedPackagesPath(),
+        'events_cache' => $app->getCachedEventsPath(),
+        'view_compiled' => (string) config('view.compiled'),
+        'log_path' => (string) config('logging.channels.single.path'),
+    ]);
+    PHP;
+
+/**
+ * 子の JSON 報告を配列で受け取る。
+ *
+ * @param  array<non-empty-string, string>  $env
+ * @return array<string, mixed>
+ */
+function bootProbeDecodeReport(string $code, array $env = []): array
+{
+    $result = BootProbeRunner::run(['-r', $code], $env);
+
+    expect($result->exitCode)->toBe(0, "probe が異常終了した: {$result->stderr}");
+
+    /** @var mixed $decoded */
+    $decoded = json_decode(trim($result->stdout), true);
+    expect($decoded)->toBeArray("probe の出力が JSON でない: {$result->stdout} / {$result->stderr}");
+    assert(is_array($decoded));
+
+    /** @var array<string, mixed> $decoded */
+    return $decoded;
+}
+
+test('S1: 親の環境変数は子に現れない', function (): void {
+    putenv(BOOT_PROBE_SENTINEL_KEY.'=leaked');
+
+    try {
+        $report = bootProbeDecodeReport(BOOT_PROBE_ENV_REPORT);
+
+        expect($report)->not->toHaveKey(BOOT_PROBE_SENTINEL_KEY, '親の env が子へ漏れている');
+    } finally {
+        putenv(BOOT_PROBE_SENTINEL_KEY);
+    }
+});
+
+test('S2: 許可した継承は規則どおり届く (親に無い鍵は子にも無い)', function (): void {
+    // 継承する鍵の一覧そのものをリテラルで pin する。実装と定数を同時に削っても緑になる形
+    // (期待値を実装側の定数から組み立てるだけの検査) を避ける。
+    expect(BootProbeRunner::INHERITED_ENV_KEYS)->toBe(['PATH', 'HOME', 'TMPDIR']);
+
+    $report = bootProbeDecodeReport(BOOT_PROBE_ENV_REPORT);
+
+    foreach (BootProbeRunner::INHERITED_ENV_KEYS as $key) {
+        $parent = getenv($key);
+        // runner と同じ規則 (文字列かつ非空のときだけ継承する) で期待値を組む。
+        // 環境によって HOME / TMPDIR が無くても偽レッドにしない。
+        if (is_string($parent) && $parent !== '') {
+            expect($report[$key] ?? null)->toBe($parent, "継承鍵 {$key} が子へ届いていない");
+
+            continue;
+        }
+
+        expect($report)->not->toHaveKey($key, "親に無い継承鍵 {$key} を子が持っている");
+    }
+});
+
+test('S3: ケース別上書きが基底に勝つ (正典 v1 の順序)', function (): void {
+    $report = bootProbeDecodeReport(BOOT_PROBE_ENV_REPORT, ['CACHE_STORE' => 'file']);
+
+    expect($report['CACHE_STORE'])->toBe('file', 'ケース別上書きが基底に負けている');
+});
+
+test('S4: 子が受け取る env の集合が完全一致する (基底 3 本 + 予約 7 本 + 継承分だけ)', function (): void {
+    $result = BootProbeRunner::run(['-r', BOOT_PROBE_ENV_REPORT]);
+    expect($result->exitCode)->toBe(0, $result->stderr);
+
+    /** @var array<string, string> $report */
+    $report = json_decode(trim($result->stdout), true, 512, JSON_THROW_ON_ERROR);
+
+    // (a) 集合の完全一致。「以下」ではないので、基底に 1 本足しただけでも赤くなる。
+    $inherited = array_values(array_filter(
+        BootProbeRunner::INHERITED_ENV_KEYS,
+        static function (string $key): bool {
+            $value = getenv($key);
+
+            return is_string($value) && $value !== '';
+        },
+    ));
+    $expectedKeys = array_merge(
+        $inherited,
+        ['APP_KEY', 'QUEUE_CONNECTION', 'CACHE_STORE'],
+        BootProbeRunner::RESERVED_ENV_KEYS,
+    );
+    sort($expectedKeys);
+    $actualKeys = array_keys($report);
+    sort($actualKeys);
+
+    expect($actualKeys)->toBe($expectedKeys, '子が受け取る env の集合が契約と違う');
+
+    // (b) 基底 3 本の値
+    expect($report['APP_KEY'])->not->toBe('')
+        ->and($report['QUEUE_CONNECTION'])->toBe('database')
+        ->and($report['CACHE_STORE'])->toBe('array');
+
+    // (c) 予約鍵 7 本は一時ディレクトリ配下を指す
+    foreach (BootProbeRunner::RESERVED_ENV_KEYS as $key) {
+        expect(BootProbeRunner::isInside($result->temporaryRoot, $report[$key]))
+            ->toBeTrue("予約鍵 {$key} が一時ディレクトリの外を指している: {$report[$key]}");
+    }
+
+    // (d) 集合一致の系として、APP_ENV とロケール系が入っていないことを名指しでも書く
+    // (「渡さない実行は素の .env を読む」は BughuntFakeWiringTest の複数ケースの前提である)。
+    expect($report)->not->toHaveKey('APP_ENV')
+        ->and($report)->not->toHaveKey('LANG')
+        ->and($report)->not->toHaveKey('LC_ALL');
+});
+
+test('S5: 予約鍵は呼び出し側から渡せない', function (): void {
+    expect(static fn (): mixed => BootProbeRunner::run(['-r', 'exit(0);'], ['LARAVEL_STORAGE_PATH' => '/tmp/x']))
+        ->toThrow(RuntimeException::class);
+});
+
+test('S6: 終了コードを保持する', function (): void {
+    $result = BootProbeRunner::run(['-r', 'exit(7);']);
+
+    expect($result->exitCode)->toBe(7, '終了コードが proc_close の戻り値で潰れている')
+        ->and($result->timedOut)->toBeFalse();
+});
+
+test('S7: 制限時間を超えた子を強制終了する', function (): void {
+    $result = BootProbeRunner::run(['-r', 'sleep(30);'], timeoutSeconds: 1);
+
+    expect($result->timedOut)->toBeTrue('制限時間を超えた子が落ちていない')
+        ->and($result->exitCode)->toBe(BootProbeRunner::TIMEOUT_EXIT_CODE)
+        // 実時間で狭く測らない (CI の負荷で偽レッドにしない)。上限は
+        // 制限時間 + 猶予 + 最終期限 + 余裕で見る。
+        ->and($result->elapsedSeconds)->toBeGreaterThanOrEqual(1.0)
+        ->and($result->elapsedSeconds)->toBeLessThan(
+            1.0 + BootProbeRunner::TERMINATION_GRACE_SECONDS + BootProbeRunner::KILL_WAIT_SECONDS + 5.0,
+        );
+});
+
+test('S8: 大量出力で詰まらない', function (): void {
+    // パイプバッファは 64KiB 程度なので、逐次読みでなければ子が固まって S7 経路に落ちる。
+    $result = BootProbeRunner::run(['-r', 'echo str_repeat("x", 1048576);']);
+
+    expect($result->exitCode)->toBe(0, $result->stderr)
+        ->and(strlen($result->stdout))->toBe(1048576)
+        ->and($result->timedOut)->toBeFalse();
+});
+
+test('S9: 書き出し先の退避が効いている (向き) / 親と同じ実行体で起きる', function (): void {
+    $result = BootProbeRunner::run(['-r', BOOT_PROBE_PATH_REPORT], ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'single']);
+    expect($result->exitCode)->toBe(0, $result->stderr);
+
+    /** @var array<string, string> $report */
+    $report = json_decode(trim($result->stdout), true, 512, JSON_THROW_ON_ERROR);
+
+    // 正典 (1): 親と同じ実行体で起こす。
+    expect($report['php_binary'])->toBe(PHP_BINARY);
+
+    // ★aicue のローカル修正 (T249) の実働証明 — **申告ではなく実挙動を測る**。
+    //   (a) 子が読んだ環境ファイルの場所が起動器の一時ディレクトリ配下であること
+    //       (Laravel は `environmentFilePath()` の 1 本しか読まないので、これが一時ディレクトリを
+    //        指していればリポジトリの `.env` は読まれていない)。
+    expect(BootProbeRunner::isInside($result->temporaryRoot, $report['env_file_path']))
+        ->toBeTrue("子がリポジトリ側の環境ファイルを読んでいる: {$report['env_file_path']}");
+
+    //   (b) 番兵による裏取り: リポジトリの `.env` に実在する資格情報が、子の設定に**現れない**。
+    //       (a) だけだと「読む先を移したが値は別経路で入った」形を排除できない。
+    $repositoryEnv = file_get_contents(base_path('.env'));
+    expect($repositoryEnv)->toBeString('リポジトリの .env が読めない (番兵を組み立てられない)');
+    assert(is_string($repositoryEnv));
+
+    foreach ([
+        'ciphersweet_key_digest' => 'CIPHERSWEET_KEY',
+        'db_password_digest' => 'DB_PASSWORD',
+    ] as $reportKey => $envKey) {
+        expect(preg_match('/^'.$envKey.'=(.+)$/m', $repositoryEnv, $matches))
+            ->toBe(1, "番兵にする {$envKey} がリポジトリの .env に無い (この検査が空振りする)");
+        $sentinel = trim($matches[1], "\"' \t\r\n");
+        expect($sentinel)->not->toBe('', "番兵にする {$envKey} が空 (この検査が空振りする)");
+        expect($report[$reportKey])->not->toBe(
+            hash('sha256', $sentinel),
+            "リポジトリの .env の {$envKey} が子の設定に載っている",
+        );
+    }
+
+    foreach (['storage', 'config_cache', 'routes_cache', 'services_cache', 'packages_cache',
+        'events_cache', 'view_compiled', 'log_path'] as $key) {
+        expect(BootProbeRunner::isInside($result->temporaryRoot, $report[$key]))
+            ->toBeTrue("書き出し先 {$key} がリポジトリ側を指している: {$report[$key]}");
+    }
+});
+
+test('S10: 書き出し先の退避が効いている (実体) と後片付け', function (): void {
+    $result = BootProbeRunner::run(['-r', BOOT_PROBE_PATH_REPORT], ['APP_ENV' => 'testing', 'LOG_CHANNEL' => 'single']);
+
+    expect($result->exitCode)->toBe(0, $result->stderr)
+        ->and($result->writtenRelativePaths)->toContain('storage/logs/laravel.log')
+        ->and(is_dir($result->temporaryRoot))->toBeFalse('一時ディレクトリが残っている');
+});
+
+test('S11: 一時ディレクトリがリポジトリ内なら起動前に失敗し残骸を残さない', function (): void {
+    $base = base_path('storage/framework/testing');
+    if (! is_dir($base)) {
+        mkdir($base, 0o755, true);
+    }
+
+    $before = glob($base.'/boot-probe-*');
+    expect($before)->toBeArray();
+    assert(is_array($before));
+
+    expect(static fn (): mixed => BootProbeRunner::run(['-r', 'exit(0);'], temporaryBase: $base))
+        ->toThrow(RuntimeException::class);
+
+    $after = glob($base.'/boot-probe-*');
+    expect($after)->toBe($before, '起動前の fail-closed が残骸を残している');
+
+    // 境界判定そのものを pin する (`/repo` と `/repository` を取り違えない)。
+    expect(BootProbeRunner::isInside('/repo', '/repo'))->toBeTrue()
+        ->and(BootProbeRunner::isInside('/repo', '/repo/inner'))->toBeTrue()
+        ->and(BootProbeRunner::isInside('/repo', '/repository'))->toBeFalse()
+        ->and(BootProbeRunner::isInside('/repo/', '/repo/inner'))->toBeTrue();
+});
+
+test('S12: 管を早く閉じた子でも確実に落として回収する', function (): void {
+    // 子は標準出力・標準エラーを閉じてから寝る。管の EOF だけを終了検知に使う実装は
+    // ここで無限に待つ (= 制限時間が効いていない) ことになる。
+    $result = BootProbeRunner::run(
+        ['-r', 'fclose(STDOUT); fclose(STDERR); sleep(30);'],
+        timeoutSeconds: 1,
+    );
+
+    expect($result->timedOut)->toBeTrue()
+        ->and($result->exitCode)->toBe(BootProbeRunner::TIMEOUT_EXIT_CODE)
+        ->and(is_dir($result->temporaryRoot))->toBeFalse('一時ディレクトリが残っている');
+
+    if (! function_exists('posix_kill')) {
+        return;
+    }
+
+    // 回収済みなら pid はもう存在しない (ps ではなく runner が握っていた pid を直接見る)。
+    expect(posix_kill($result->pid, 0))->toBeFalse('子プロセスが残っている');
+});
+
+test('S13: 子の終了後も読み切り、その最終読み取りには上限がある', function (): void {
+    // 子は孫へ標準出力・標準エラーを渡したまま先に終了する。2 方向を同時に測る:
+    //  - 上限が無い実装は、孫が寝ている間ずっと戻れない (孫は回収しない = 保証しないことの 1 つ)
+    //  - 最終読み取りが無い実装は、子の終了後に届いた印を取りこぼす
+    $code = <<<'PHP'
+        $child = proc_open(
+            [PHP_BINARY, '-r', 'usleep(300000); fwrite(STDOUT, "DRAINED"); sleep(6);'],
+            [1 => STDOUT, 2 => STDERR],
+            $pipes,
+        );
+        exit(3);
+        PHP;
+
+    $result = BootProbeRunner::run(['-r', $code]);
+
+    // toContain は可変長ニードルなので message 引数を渡さない (渡すと第 2 ニードル扱いになる)。
+    expect($result->stdout)->toContain('DRAINED');
+
+    expect($result->exitCode)->toBe(3, '子の終了コードを取りこぼしている')
+        ->and($result->timedOut)->toBeFalse()
+        ->and($result->elapsedSeconds)->toBeLessThan(
+            BootProbeRunner::FINAL_DRAIN_SECONDS + 2.5,
+            '孫が管を持っている間ずっと待っている (最終読み取りの上限が効いていない)',
+        );
+});
+
+test('S14: 終了要求を無視する子は強制終了で落とす (段階的強制終了)', function (): void {
+    // S7 / S12 の子は SIGTERM で死ぬので、SIGKILL への昇格を消しても緑になってしまう。
+    // ここは**終了要求を無視する子**を使い、猶予の後の強制終了まで到達させる。
+    $result = BootProbeRunner::run(
+        ['-r', 'pcntl_signal(SIGTERM, SIG_IGN); sleep(30);'],
+        timeoutSeconds: 1,
+    );
+
+    expect($result->timedOut)->toBeTrue()
+        ->and($result->exitCode)->toBe(BootProbeRunner::TIMEOUT_EXIT_CODE)
+        // 終了要求では死なないので、猶予ぶんは必ず経過している (= SIGKILL 経路を通った)。
+        ->and($result->elapsedSeconds)->toBeGreaterThanOrEqual(1.0 + BootProbeRunner::TERMINATION_GRACE_SECONDS)
+        ->and($result->elapsedSeconds)->toBeLessThan(
+            1.0 + BootProbeRunner::TERMINATION_GRACE_SECONDS + BootProbeRunner::KILL_WAIT_SECONDS,
+            '最終期限を超えるまで落とせていない',
+        )
+        ->and(is_dir($result->temporaryRoot))->toBeFalse('一時ディレクトリが残っている');
+
+    if (! function_exists('posix_kill')) {
+        return;
+    }
+
+    expect(posix_kill($result->pid, 0))->toBeFalse('強制終了しても子が残っている');
+})->skip(
+    // 子は親と同じ実行体なので、親に ext-pcntl が無ければ子にも無い。
+    // **成功扱いにはしない** — 段階的強制終了を測れていないことをテスト結果に出す。
+    fn (): bool => ! function_exists('pcntl_signal'),
+    'ext-pcntl が無い環境では終了要求を無視する子を作れず、段階的強制終了を測れない',
+);

```

---

## テスト結果 / 検証コマンドの実測

```
composer test (--parallel --processes=4) 1 回目:
  {"tool":"pest","result":"passed","tests":7448,"passed":7446,"assertions":34944,
   "duration_ms":595068,"skipped":2,"risky":5}   ← 0 failed

composer test 2 回目: 実行中 (連続 green の 2 本目。結果は最終報告に載せる)

composer phpstan (level 10): [OK] No errors  (1114 files)
vendor/bin/pint --test: {"tool":"pint","result":"passed"}
pnpm lint: OK (eslint resources/js)
pnpm typecheck: OK (tsc --noEmit)
pnpm build: ✓ built in 6.00s
pnpm typecheck:packages / pnpm build:packages: OK
pnpm test / pnpm test:packages: 実行中 (composer test の後にグローバルロックを待って走る)
```

個別に緑を確認したファイル (`composer test -- <path>` で単独実行):

```
tests/Unit/Support/Process/BootProbeRunnerTest.php     : 14 tests / 14 passed / 81 assertions
tests/Architecture/PhpBootProbeReferenceInventoryTest.php : 44 tests / 44 passed / 177 assertions
tests/Architecture/ExternalFakeBootProbeTest.php      : 32 tests / 32 passed / 146 assertions
tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php : 5 tests / 5 passed / 58 assertions
```

取り込み 3 本の sha256 (実測):

```
bd21b337cc7e4327debba02a3ba46cb496f0a66f0980ccf08cb3847a18430162  tests/Support/Process/BootProbeRunner.php      ← 台帳の記録値と一致
00b14167ebfa9710abdb36edf8989bb66350320ee191c3993debd06ed27902cb  tests/Support/Process/BootProbeResult.php      ← 台帳の記録値と一致
c0e460c47f0b35f0c1711a24f9bd86ccb9f0f355de900f5aee311466ba49139d  tests/Unit/Support/Process/BootProbeRunnerTest.php ← 意図的にバイト一致を崩した (記録値 9db128d8…)
```

乖離台帳の実測 (この 3 パスはいずれも指紋台帳のキーにも採用時債務にも無い):

```
docs/template-fingerprints.json entries: 281
  tests/Support/Process/BootProbeRunner.php            : 無い
  tests/Support/Process/BootProbeResult.php            : 無い
  tests/Unit/Support/Process/BootProbeRunnerTest.php   : 無い
  tests/Architecture/SubprocessProbeLaunchGateTest.php : 無い
tests/Support/TemplateDivergence/adoption-debt.tsv    : BootProbe 系は 1 件も無い
```

Round 3 の [Critical] に対する修正の**負の裏取り** (実測):

```
修正前の形 (環境ファイルの置き場所を移さない) で子を起こしたとき:
  env_file_path   = <repo>/.env
  matches_repo_env = true   ← リポジトリの .env の CIPHERSWEET_KEY が子の設定に載った (漏れの再現)

修正後:
  env_file_path   = <一時ディレクトリ>/.env  (実在しないので safeLoad() は何も読まない)
  matches_repo_env = false
  自己検査 14 本すべて緑
```

---

## 判定してほしいこと (優先順)

1. **Round 3 で残った [Critical] (取り込んだ自己検査 S9 / S10 の子がリポジトリの `.env` を
   読んで起動する) が解消しているか。** 解消の形は「取り込み元のバイト一致を 1 本だけ崩し、
   起動前に環境ファイルの置き場所を起動器の一時ディレクトリへ逃がす + その実挙動を S9 が
   2 方向 (環境ファイルの場所 / `.env` の実値の番兵) で測る」である。
   - **バイト一致を崩した判断そのもの**も判定対象にしてほしい (正典側を先に直せという
     Round 3 の裁定に対し、本セッションからは正典リポジトリを変更できないので、
     同じ不変条件を aicue 側で満たす形へ切り替えた)
   - この修正が**正典 v1 (2) への適合**であるという主張が妥当かどうか
2. **G-8 の書き換え**が Round 3 の「自己申告であって境界ではない」という指摘に応えているか。
   誇張が残っていないか (docblock の「主張しないこと」が実際の検出力と一致しているか)
3. **main の前進への追随** (G-2 を「起動器 1 件固定」から「2 件の完全一致 pin」へ変えた判断)。
   別 feature (`process-concurrency-test-harness`) の起こし手を同じ起動器へ載せない判断が妥当か
4. その他、詳細設計との不一致・fail-open・偽グリーンの穴
