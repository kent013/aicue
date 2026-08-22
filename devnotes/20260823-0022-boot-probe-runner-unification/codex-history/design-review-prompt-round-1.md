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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

（アプリの使命・禁止事項は上に挿入済み）

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 (ただし aicue の解析対象は app / config / database / routes で **tests を含まない**)
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）
11. Atomic Design準拠（UI/frontend 変更を含む場合）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【本件の追加文脈】
- 本件は家系の機能台帳 lctl の feature `subprocess-boot-probe-harness` (正典 v1) への aicue 追従設計である。
  正典 v1 の不変条件 6 点と、それぞれの担い手・検査の対応表を詳細設計の冒頭に置いている。
  追従設計なので「正典が求めることを漏らさない」ことと「正典が求めていないことをやらない」ことの両方が評価軸になる。
- 変更はすべて `tests/` 配下に閉じる。UI / frontend / API / DB は 1 バイトも触らないので観点 5・6・10・11 は該当しない。
- 概念設計は Codex (gpt-5.6-terra) の 5 ラウンドのレビューで APPROVED 済みである。
- 取り込む 3 ファイルは**バイト一致**が要件なので、その中身への修正提案は採れない
  (指摘があれば「取り込み自体を見直すべきか」の形で書いてほしい)。

---

## 詳細設計書

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
> 1 は S5 の gate 新設と S2〜S4 のテスト計画で満たす。2 は「PHPStan のエラーが 0 のまま」で満たす
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

## 正典 v1 の不変条件と、本設計での担い手 (全件の対応表)

| # | 正典 v1 の不変条件 (feature_yaml の boundary が正本) | 本設計での担い手 | 検査 |
|---|--------------------------------------------------|----------------|------|
| (1) | 子は `PHP_BINARY` で起こす (親と同じ実行体) | `BootProbeRunner::spawn()` が `proc_open([PHP_BINARY, ...$phpArguments], …)` (S1 でバイト一致取り込み) | 自己検査 S9 (子が報告する `PHP_BINARY` が親と一致) |
| (2) | 環境変数は 3 段 (許可一覧の継承 → ケース共通の基底 → ケース別上書き。ケース別が最後に効く)。開発者ローカルの env を入力集合から外す | `BootProbeRunner::composeEnv()` の `array_merge($inherited, baseEnv(), $caseEnv, $reserved)` | 自己検査 S1 / S2 / S3 / S4 + 呼び出し側 P-7 |
| (3) | 出力の管を非ブロッキングで逐次読み、制限時間超過は SIGTERM → 猶予 → SIGKILL、全ての管を閉じてから必ず `proc_close` | `BootProbeRunner::spawn()` / `readAvailable()` / `reclaim()` | 自己検査 S7 / S8 / S12 / S13 / S14 |
| (4) | 終了コードは実行中フラグが初めて false になった時点の非負値を保存し、`-1` や `proc_close` の戻り値で上書きしない。取れなければ 124 へ正規化 | `BootProbeRunner::spawn()` の `$exitCode` 確定と `TIMEOUT_EXIT_CODE` | 自己検査 S6 / S7 / S12 / S13 / S14 |
| (5) | 子の書き出し先を環境変数でリポジトリ外の一時ディレクトリへ逃がす + **その環境変数が実際に効いていること自体を gate が検査する** | runner の `RESERVED_ENV_KEYS` 7 キー + `createTemporaryRoot()` の fail-closed / **aicue 側の実働証明は S4 の P-13 (実体) と P-14 (向き)** | 自己検査 S4(c) / S9 / S10 / S11 + 呼び出し側 **P-13 / P-14** |
| (6) | runner 自身の自己検査を持つ (許可一覧の網羅性 / 上書きの適用順 / 終了コードの保持 / 制限時間の回収) | `tests/Unit/Support/Process/BootProbeRunnerTest.php` (S1 でバイト一致取り込み。14 本) | それ自体 |

**正典が含まないもの (boundary が明記。本設計もやらない)**: 子プロセスで何を観測するかという個別の主張 /
子を 2 本立てて合図で同期させる並行テスト / 静的走査そのもの / HTTP サーバーの常駐起動 / テストレーンの構成。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 共通 runner をテンプレートからバイト一致で取り込む | `tests/Support/Process/BootProbeRunner.php` / `tests/Support/Process/BootProbeResult.php` / `tests/Unit/Support/Process/BootProbeRunnerTest.php` (新規 3) | 高 |
| S2 | 経路 1 の起こし手を runner へ載せ替える | `tests/Support/ExternalFakes/FakeWiringProbeRunner.php` (変更) | 高 |
| S3 | 子入口スクリプトへ実働証明の観測点を足す | `tests/Support/ExternalFakes/fake-wiring-probe.php` (変更) | 高 |
| S4 | 呼び出し側 gate を新契約へ揃え、正典 (5) の実働証明を足す | `tests/Architecture/ExternalFakeBootProbeTest.php` (変更) | 高 |
| S5 | 経路 2 の起こし手を runner へ載せ替える | `tests/Support/StrictTypesRuntimeProbe.php` (変更) | 高 |
| S6 | 一元化に関する退行を検出する全数申告 gate を新設する | `tests/Architecture/PhpChildProcessLaunchInventoryTest.php` (新規 1) | 高 |

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

### 取り込みの手順 (実装者が機械で確かめられる形)

1. lctl の `get_source` で `laravel-claude-template:docs/template-fingerprints.json` を取得し、
   `entries` に上記 3 パスが登録されていることを確認する
2. 同じく `get_source` で 3 ファイルを取得し、**各ファイルの sha256 が台帳の記録値と一致**することを確認する。
   1 件でも食い違えばそこで止め、原因 (世代のずれ) を報告する
3. そのまま配置する。**Pint による整形も含めて 1 バイトも変えない**。
   `composer fix` が差分を出したら、それは「取り込み元が aicue の Pint 設定と食い違っている」という事実なので、
   **整形せずに報告する**

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
| 「`app` / `routes` / `config` / `database` / `bootstrap` へ持ち出すと**外部到達統制の subprocess 0 件 pin** に触れる (AGENTS.md セキュリティ不変条件 15)。同じ扱いの先例は `tests/Support/Architecture/GlobalUse/PhpLintOracle.php`」 | aicue の外部到達点の目録は **AGENTS.md セキュリティ不変条件 9** であり、`php -l` の真値取り出しは `tests/Support/GlobalUse/PhpLintOracle.php` (`Architecture/` が入らない) にある。**趣旨 (tests/ 専用であり app/ へ持ち出さない) は aicue でもそのまま成り立つ**ので、書き換えず取り込む |

**書き換えると共有パスの逸脱になる**ので、対応表は S6 の gate の docblock に置いて読み手の誤読を防ぐ。

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

- **`ext-pcntl` が無い環境**: S14 が `skip` になる (成功扱いにはならない)。段階的強制終了の実測が
  その環境では得られないことがテスト結果に出る。aicue の devcontainer / CI は Linux で pcntl を持つ
- **将来のテンプレート更新との追従遅れ**: 指紋台帳を再生成しない限り検査上の食い違いは生じない。
  再判定の契機は「指紋台帳の世代を上げるとき」であり、そのときに 3 パスも一緒に見直す

---

## S2: 経路 1 の起こし手を runner へ載せ替える

### 変更箇所

- ファイル: `tests/Support/ExternalFakes/FakeWiringProbeRunner.php` (全 301 行のうち L88-L147 の `run()` を中心に改修)

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
    'FAKE_WIRING_PROBE_ENV_DIR',
    'FAKE_WIRING_PROBE_ENV_FILE',
    'APP_CONFIG_CACHE',
];

public static function run(
    string $environment, bool $fakeExternals, bool $fakeStorage, bool $fakeLlm,
    ?string $baseDirectory = null, float $timeout = 120.0,
): array {
    // …一時ディレクトリ作成・環境ファイル書き出し・権限検査…
    $configCachePath = $directory.'/config-cache-absent.php';

    $process = new Process(
        ['env', '-i',
         'FAKE_WIRING_PROBE_ENV_DIR='.$directory,
         'FAKE_WIRING_PROBE_ENV_FILE='.self::ENV_FILE_NAME,
         'APP_CONFIG_CACHE='.$configCachePath,
         PHP_BINARY, self::probeScriptPath()],
        FakeClassCatalog::repoRoot(), null, null, $timeout,
    );
    $process->run();

    return [
        'exitCode' => $process->getExitCode() ?? -1,
        'output' => self::decode($process->getOutput()),
        // …
        'configCachePath' => $configCachePath,
        'configCacheExists' => file_exists($configCachePath),
    ];
}
```

### 変更後コード

```php
use Tests\Support\Process\BootProbeResult;
use Tests\Support\Process\BootProbeRunner;

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
 *
 * @var list<string>
 */
public const array CASE_ENV_KEYS = [
    'FAKE_WIRING_PROBE_ENV_DIR',
    'FAKE_WIRING_PROBE_ENV_FILE',
    'APP_KEY',
];

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
    $base = $baseDirectory ?? sys_get_temp_dir();
    $directory = $base.'/fake-wiring-probe-'.bin2hex(random_bytes(8));

    if (! mkdir($directory, 0700) || ! is_dir($directory)) {
        throw new RuntimeException("観測用の一時ディレクトリを作れない: {$directory}");
    }

    try {
        chmod($directory, 0700);

        $values = self::envFileValues($environment, $fakeExternals, $fakeStorage, $fakeLlm);
        $envFilePath = $directory.'/'.self::ENV_FILE_NAME;
        self::writeEnvFile($envFilePath, $values);

        $directoryMode = self::mode($directory);
        $envFileMode = self::mode($envFilePath);
        self::assertSafePermissions($directoryMode, $envFileMode);

        $caseEnv = self::caseEnvValues($directory);

        // 子の起こし方・回収・書き出し先の退避は共通 runner が持つ
        // (lctl feature: subprocess-boot-probe-harness の正典 v1 (1)〜(5))。
        $result = BootProbeRunner::run(
            [self::probeScriptPath()],
            $caseEnv,
            $timeoutSeconds,
        );

        return self::interpret($result, $values, $caseEnv, $directory, $directoryMode, $envFileMode);
    } finally {
        self::removeDirectory($directory);
    }
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

`envFileValues()` からは `APP_KEY` の行を削る (`ALLOWED_ENV_FILE_KEYS` からも外す)。
`decode()` / `writeEnvFile()` / `mode()` / `assertSafePermissions()` / `probeScriptPath()` /
`probeAppHost()` / `removeDirectory()` は**現行のまま**。`Symfony\Component\Process\Process` の
`use` は落ちる。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Architecture/ExternalFakeBootProbeTest.php` (S4 で扱う)。
  `FakeWiringProbeRunner` を参照する他のファイルは無い (設計時に実測: 参照は同 gate 1 本のみ)
- 削除される公開面: `ALLOWED_PROCESS_ENV_KEYS` (→ `CASE_ENV_KEYS` へ改称・意味変更)。
  参照元は P-7 のみなので S4 で追随する

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (完全な array shape を PHPDoc で固定)
- [x] null 安全 (`?? -1` のような黙った既定値を持たない。`timedOut` は必ず例外へ)
- [x] 配列返却について: **プロセス実行結果の境界は `BootProbeResult` に統一**した。
      子の JSON payload だけが `array<string, mixed>` として残り、それは呼び出し側 gate が
      各キーを検証してから使う (「無型の配列が 1 つも無い」とは主張しない)
- [x] Generics の型パラメータが正しい (`array<string, string>` / `list<string>`)

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

- ファイル: `tests/Support/ExternalFakes/fake-wiring-probe.php` (L46 の `bootstrap()` 直後へ追記 + 出力 JSON の拡張)

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
$app->make(Kernel::class)->bootstrap();

/*
 * ★正典 v1 (5) の**実働証明**の観測点 (lctl feature: subprocess-boot-probe-harness)。
 *   「書き出し先を環境変数で退避した」ことは、退避が**効いていなければ**既定の場所
 *   (リポジトリの storage/) へ書かれ、観測は緑のまま嘘になる。そこで
 *   Laravel の storage_path() 経由で印を 1 本置き、それが起動器の一時ディレクトリ配下に
 *   現れたことを呼び出し側 (P-13) が確かめる。
 *   置き場所 (storage/app/private) は起動器が事前に掘っている。
 */
$markerPath = $app->storagePath(BOOT_PROBE_MARKER_RELATIVE);
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

`BOOT_PROBE_MARKER_RELATIVE` は `FakeWiringProbeRunner` の公開定数
(`public const string MARKER_RELATIVE_PATH = 'app/private/fake-wiring-probe-marker.txt';`) を
子側でも使えるよう、probe が `Tests\Support\ExternalFakes\FakeWiringProbeRunner::MARKER_RELATIVE_PATH` を
直接参照する (vendor autoload は既に読み込み済みで、`Tests\` の autoload-dev も効く)。

> **`echo` を使わない**: AGENTS.md の禁止する文の規約により `fwrite(STDOUT, …)` で書く (現行と同じ)。

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: S4 が新しいキー (`write_targets` / `key_digests`) を読む

### PHPStan適合チェック

- 対象外 (`tests/` は aicue の解析対象に含まれない)。`Webmozart\Assert\Assert` による実行時検証は現行のまま

### テスト計画

S4 に集約する。

### リスク

- **`production` ケースでは印が書かれない**: bootstrap が `ProductionEnvGuard` で落ちるため。
  P-13 / P-14 は `fake` ケースだけで測る (P-4 の主張は変えない)
- **`storage/app/private` が掘られていること**への依存: runner の `createTemporaryRoot()` が
  6 つの下位を掘るうちの 1 つ。掘られなくなれば `file_put_contents` が false を返して**例外**になる
  (fail-closed。沈黙しない)

---

## S4: 呼び出し側 gate を新契約へ揃え、正典 (5) の実働証明を足す

### 変更箇所

- ファイル: `tests/Architecture/ExternalFakeBootProbeTest.php`
  - L7 の `use Symfony\Component\Process\Exception\ProcessTimedOutException;` を削除
  - `externalFakeProbeRun()` の戻り値 shape を更新
  - **P-7 / P-8 / P-11 を書き換え**、**P-10 を分割**、**P-13 / P-14 / P-15 を追加**
  - **P-1〜P-6 / P-9 / P-12 は 1 文字も変えない**

### 施策ごとの検査の対応表 (主張の増減を明示する)

| 検査 | 扱い | 内容 |
|------|------|------|
| P-1〜P-6 / P-9 / P-12 | **変更なし** | 偽物/本物の厳密一致・転送先ホスト・production の起動失敗・fail-closed・環境ファイルの許可集合・権限と負のコントロール・宣言の型 |
| P-7 | **書き換え (同じ主張を新しい土台で)** | 子が受け取ったプロセス環境の集合が `継承(実在分) + 基底 3 + ケース別 3 + 予約 7` と**完全一致**し、危険接頭辞が 1 件も無い |
| P-8 | **強化** | 起動側の配列ではなく、**子で実際に効いた** `app.key` / `ciphersweet` の digest が、生成した使い捨て値と一致し、親の設定値とは一致しない |
| P-10 | **分割** | P-10 = 正常終了・非ゼロ終了で環境ファイルの置き場所が残らない / **P-10b = 作れない置き場所では子を起こさずに失敗し残骸を残さない** |
| P-11 | **書き換え (同じ主張を新しい土台で)** | 書き出し先の退避先が runner の一時ディレクトリ配下で、設定キャッシュが**書かれていない** |
| P-13 | **追加 (正典 (5) の実働証明・実体)** | 子が `storage_path()` 経由で書いた印が `writtenRelativePaths` に現れる |
| P-14 | **追加 (正典 (5) の実働証明・向き)** | 子が解決した書き出し先 8 種が 1 件残らず一時ディレクトリ配下で、`base_path()` の外 |
| P-15 | **追加 (fail-closed の負例。子を起こさない)** | `interpret()` が `timedOut` / 空出力 / 非 JSON / 非配列 JSON で例外になる |

### 変更後コード (主要部)

```php
test('P-7 子が実際に受け取ったプロセス環境が 4 段の合成結果と完全一致する', function (): void {
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
    //     基底 3 とケース別 3 は**リテラルで pin する** (実装側の定数から組み立てるだけの
    //     検査にすると、実装と期待値を同時に変えたときに緑のまま通ってしまう)。
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
        BootProbeRunner::RESERVED_ENV_KEYS,
    )));
    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);

    // (c) 継承一覧そのものも pin する (runner 側で黙って広がらないように)。
    expect(BootProbeRunner::INHERITED_ENV_KEYS)->toBe(['PATH', 'HOME', 'TMPDIR']);
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
    // 書き込めない親を渡すと mkdir が失敗する = 子を 1 本も起こさない (起動前の fail-closed)。
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

test('P-11 書き出し先の退避先が一時ディレクトリ配下で、設定キャッシュは書かれていない', function (): void {
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

> **制限時間超過を実プロセスで測らない理由 (明記する)**: 子の起動は実測 **0.28〜0.32 秒**で、
> `BootProbeRunner::run()` の制限時間は `positive-int` (最小 1 秒) である。呼び出し側で実プロセスの
> 制限時間超過を再現するには観測用スクリプトへ「眠る分岐」を足すことになり、
> **観測の責務 (DB へ接続しない / container から解決する / 転送先を組み立てる / 終了コードを返す) を汚す**。
> 制限時間と段階的強制終了の**実プロセス実測は runner の自己検査 S7 / S12 / S14 が持つ** (子を 30 秒眠らせて
> 1 秒で落とす形)。呼び出し側で測るべきは `timedOut` の**解釈**であり、それは P-15 が決定的に測る。

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本ファイルのみ。`FakeWiringProbeRunner` の他の利用者は無い

### PHPStan適合チェック

- 対象外 (`tests/`)。ただし `output` の各キーは `expect()->toBeArray()` / `toBeString()` で
  型を確かめてから使う (現行の `externalFakeProbeResolved()` と同じ作法)

### テスト計画

- [x] バグ修正ではないので再現テストは不要。**載せ替え前に赤を作る**手順は「実装順 (fail-first)」に書く
- [x] 既存テスト `tests/Architecture/ExternalFakeBootProbeTest.php` の更新 (上表のとおり)
- [x] 新規: P-10b / P-13 / P-14 / P-15
- [x] 個別の `DatabaseTransactions` を使っていないことを確認 (本ファイルは DB を張らない)

### リスク

- **`root` で走らせると P-10b が `skip`**: 成功扱いにはならない (測れていないことがテスト結果に出る)
- **`write_targets` のキー集合を pin している**: 観測点を足したら赤になる = 意図しない拡張が黙って通らない

---

## S5: 経路 2 の起こし手を runner へ載せ替える

### 変更箇所

- ファイル: `tests/Support/StrictTypesRuntimeProbe.php` (L57-L75)

### 現行コード

```php
$process = new Process([PHP_BINARY, '-d', 'error_reporting=E_ALL', $path]);
$process->run();

if (! $process->isSuccessful()) {
    return false; // 読み込めないソース (Fatal / Parse error) は厳密化が成立しない
}

$output = trim($process->getOutput());
if ($output === 'STRICT-'.$nonce) {
    return true;
}
if ($output === 'WEAK-'.$nonce) {
    return false;
}

throw new RuntimeException(
    '実測用のプローブへ到達しませんでした (検体が自分で出力した / exit した可能性があります)。'
    ."出力: {$output}"
);
```

### 変更後コード

```php
use Tests\Support\Process\BootProbeResult;
use Tests\Support\Process\BootProbeRunner;

/** 検体 1 本あたりの制限時間 (秒)。検体は 1 行の関数呼び出しで、実測は 0.05 秒未満。 */
private const int TIMEOUT_SECONDS = 30;

// …strictTypesInEffect() の中…
// 子の起こし方・回収は共通 runner が持つ
// (lctl feature: subprocess-boot-probe-harness の正典 v1 (1)〜(4))。
$result = BootProbeRunner::run(
    ['-d', 'error_reporting=E_ALL', $path],
    timeoutSeconds: self::TIMEOUT_SECONDS,
);

return self::decide($result, $nonce);
```

```php
/**
 * 実測結果を判定へ翻訳する (**純関数**。子を起こさずに負例を測れる)。
 *
 * ★制限時間超過は「厳密化が成立しない (false)」ではなく**実測不能 (例外)**である。
 *   false へ落とすと「測れなかった」ことが沈黙し、判定器の下界という主張が崩れる (fail-open)。
 * ★判定には `timedOut` を使い、`exitCode === 124` を直接読まない。
 *
 * @throws RuntimeException 制限時間超過 / 検体がプローブと干渉して実測できないとき
 */
public static function decide(BootProbeResult $result, string $nonce): bool
{
    if ($result->timedOut) {
        throw new RuntimeException(
            '実測用の子プロセスが制限時間を超えて強制終了されました (実測不能)。'
            ."終了コード: {$result->exitCode} / 標準エラー: {$result->stderr}"
        );
    }

    if ($result->exitCode !== 0) {
        return false; // 読み込めないソース (Fatal / Parse error) は厳密化が成立しない
    }

    $output = trim($result->stdout);
    if ($output === 'STRICT-'.$nonce) {
        return true;
    }
    if ($output === 'WEAK-'.$nonce) {
        return false;
    }

    throw new RuntimeException(
        '実測用のプローブへ到達しませんでした (検体が自分で出力した / exit した可能性があります)。'
        ."出力: {$output}"
    );
}
```

`tempnam()` による検体の書き出しと `finally` の `@unlink($path)` は**現行のまま**。
`Symfony\Component\Process\Process` の `use` は落ちる。

> **`-d error_reporting=E_ALL` の位置**: runner は `[PHP_BINARY, ...$phpArguments]` の順に組み立てるので、
> `-d` は現行と同じくバイナリ直後に来る。**`-n` は付けない** (現行も付けていない)。

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php` に
  **`decide()` の負例 3 本を追加**する (下記)。既存 4 本の主張は変えない

### PHPStan適合チェック

- 対象外 (`tests/`)。`decide()` は `BootProbeResult` を受けて `bool` を返す (無型の値を扱わない)

### テスト計画

- [x] 既存テスト `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php` の 4 本は**主張を変えない**
      (検体表どおりの判定 / 実効性の下界 / 読み込めないソースは false / 検体自身の出力を材料にしない)
- [x] 新規 (子を起こさない負例):
  - `decide()`: `timedOut` の結果は**例外**になる (false へ落ちない = fail-open 防止)
  - `decide()`: `exitCode !== 0` は false を返す
  - `decide()`: 標識と一致しない出力は例外になる
- [x] 個別の `DatabaseTransactions` を使っていないことを確認 (本ファイルは DB を張らない)

### リスク

- **実行時間**: 検体は 23 本 + 直接呼び出し 4 本で、子プロセスは 1 本あたり 0.05 秒未満。
  runner が 1 回につき一時ディレクトリと下位 6 つを掘って消すが、プロセス生成に比べて無視できる。
  **受入条件で `composer test` の実時間を実装前後で比べる**
- **runner が `.env` を読ませる**: 検体は `bootstrap/app.php` を読み込まないので Dotenv は動かない。
  基底の `APP_KEY` などは検体の判定に影響しない (検体は関数 1 本を呼ぶだけ)

---

## S6: 一元化に関する退行を検出する全数申告 gate を新設する

### 変更箇所

- ファイル: `tests/Architecture/PhpChildProcessLaunchInventoryTest.php` (新規)

### なぜテンプレートと同じパス・同じ実装にしないか

| 論点 | 判断 |
|------|------|
| パス名 | テンプレートの `tests/Architecture/SubprocessProbeLaunchGateTest.php` は**テンプレートの指紋台帳に登録済みの共有パス**である (設計時に実測で確認)。そこへ aicue 固有の申告内容を置くと共有パスに食い違う内容が乗り、将来の指紋台帳の再生成で意図的逸脱の登録が必要になる。**aicue 既存の命名 (`FfmpegProcessLaunchInventoryTest`) に倣った固有名**を使う |
| 走査器 | テンプレートは `nikic/php-parser` の `NameResolver` を使うが、**aicue は php-parser を直接依存に持たず、アプリ・テストのどこでも使っていない** (vendor には larastan 経由で在るだけ。設計時に実測)。aicue の静的走査の基盤は `tests/Support/PhpTokenScan.php` (`token_get_all` の正規化) と `tests/Support/TrackedPhpSourceFiles.php` (git 追跡下の列挙) である。**aicue の既存の形に従う** |
| 走査対象 | テンプレートは `proc_open` の直呼びだけを見るが、**aicue の 2 経路はどちらも Symfony `Process` を使っていた**。`proc_open` だけを見る gate は aicue の退行の主要な形を素通しする。**`PHP_BINARY` の参照**を主軸にする |

### 3 つの軸 (いずれも `PhpTokenScan::normalize()` の上に建てる)

| 軸 | 判定 | 走査後の実測 (載せ替え後) |
|---|------|------------------------|
| **軸 A (起動能力)** | `T_STRING` かつ text が `PHP_BINARY` のトークンを持つ | 6 ファイル |
| **軸 B (アプリの起動点)** | 文字列トークン (`T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE`) の値が `bootstrap/app.php` を含む | 5 ファイル |
| **軸 C (子入口の参照)** | 文字列トークンの値が `fake-wiring-probe.php` を含む | 2 ファイル |

**コメント・docblock は `PhpTokenScan::normalize()` が落とすので数えない** (現行の
`FakeWiringProbeRunner` の docblock にある `fake-wiring-probe.php` は軸 C に入らない)。

### 起動呼び出しトークンの判定 (軸 B の交差不変条件で使う)

次のいずれかを持てば「起動呼び出しを持つ」と判定する (fail-closed 寄りに広く取る):

1. `T_STRING` が `proc_open` / `popen` / `shell_exec` / `passthru` / `system` のいずれかで、直後が `(`
2. `T_NEW` の直後の名前が `Process` で終わる
3. 名前 `Process` の直後が `T_DOUBLE_COLON`
4. **`use function proc_open` の並び** (別名 import による迂回を、import 側で fail-closed に捕まえる)

### 変更後コード (骨子)

```php
<?php

declare(strict_types=1);

use Tests\Support\PhpTokenScan;
use Tests\Support\TrackedPhpSourceFiles;

/*
| `tests/` 配下で「PHP の子プロセスを起こしうる箇所」と「アプリの起動点」の**全数申告** gate。
| lctl feature: subprocess-boot-probe-harness (正典 v1 の作法へ追従したあとの退行を検出する)。
|
| 子プロセスの起こし方が経路ごとにばらばらだと、制限時間・終了コードの保持・書き出し先の退避が
| 場所によって有ったり無かったりする。共通の起動器 (`Tests\Support\Process\BootProbeRunner`) を
| 置いた以上、直接の起動が増えるのは**設計判断**であり、申告なしに増えてはならない。
|
| **主張すること**: `PHP_BINARY` の明示参照 (軸 A) / リテラルで検出できるアプリの起動点 (軸 B) /
| 既存の子入口スクリプトへの参照 (軸 C) の 3 つは、いずれも**申告なしには増えない**。
|
| **主張しないこと**: 「アプリを子プロセスで起こす経路が共通の起動器ちょうど 1 本である」ことは
| **主張しない**。字句走査では次を原理的に追えないためである —
|   1. 文字列リテラルの 'php' を実行体にする形
|   2. `env php …` のように別コマンド経由で PHP を起こす形
|   3. シェルスクリプトを起こし、その中で PHP を起こす形
|   4. 実行体のパスを変数・設定から取り出す形
|   5. `proc_open` / `Process` の第 1 引数を静的に解釈すること (変数・定数・連結を追えない)。
|      可変関数名 (`$f = 'proc_open'; $f()`) も追わない
| **一元化そのものの証拠は載せ替えの実測であり、本 gate は退行の検出器である。**
|
| **取り込んだ起動器の docblock との対応** (取り込みはバイト一致なので書き換えない):
|   - 「AGENTS.md セキュリティ不変条件 15」→ aicue では**不変条件 9 (外部到達点の目録)** が相当する
|   - 「tests/Support/Architecture/GlobalUse/PhpLintOracle.php」→ aicue では
|     `tests/Support/GlobalUse/PhpLintOracle.php` (`Architecture/` が入らない)
|   - 趣旨 (`tests/` 専用であり `app/` へ持ち出さない) は aicue でもそのまま成り立つ
*/

/**
 * 軸 A: `tests/` 配下で `PHP_BINARY` を参照してよいファイルの全数申告 (deny-by-default)。
 *
 * entry は 4 つの欄を独立に持つ (「件数合わせの allowlist」へ流れないための構造):
 *  - `launches_app`: アプリを起こすと申告するか (**補助的な申告値**。実際の起動経路の
 *    全数性を表すものではない。「アプリを起こす」と申告する先が分散していないことだけを固定する)
 *  - `subject`: 何を起こすのか
 *  - `recovery`: 回収の担い手 (誰が制限時間と後始末を持つか)
 *  - `reason`: なぜ共通の起動器に載せないのか
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
            'recovery' => '起動器 (自己検査は起動器を通してしか子を起こさない)',
            'reason' => '起動器の自己検査であり、それ自身は子を起こさない (バイト一致で取り込んだファイルなので編集しない)',
        ],
        'tests/Support/GlobalUse/PhpLintOracle.php' => [
            'launches_app' => false,
            'subject' => '`php -l` を真値として取り出す (構文検査のみ。アプリは起こさない)',
            'recovery' => '同クラス (Symfony Process が管を読み切り、終了コードが null なら例外にする)',
            'reason' => 'アプリを起動しないので環境の 3 段合成も書き出し先の退避も要らない。'
                .'起動器に載せると無関係な前提 (Laravel の書き出し先 7 キー) が付く',
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
 *  - `child_entry`   : 子プロセスで読み込まれる入口 / 子へ渡す検体文字列
 *  - `in_process`    : 同一プロセスでのアプリ起動 (子プロセスではない)
 *  - `inventory`     : 検査定義としてパス文字列を保持するだけ
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
        'tests/Architecture/PhpChildProcessLaunchInventoryTest.php' => [
            'kind' => 'inventory',
            'reason' => '本 gate 自身。走査の針としてパス文字列を持つ (自分を走査対象から外さない)',
        ],
    ];
}

/**
 * 軸 C: 子入口スクリプトのパスを参照してよいファイルの全数申告。
 *
 * `reference_kind` は 2 値:
 *  - `runtime`   : 実行経路として子入口を起こす
 *  - `inventory` : 検査定義としてパス文字列を保持するだけ
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
        'tests/Architecture/PhpChildProcessLaunchInventoryTest.php' => [
            'reference_kind' => 'inventory',
            'reason' => '本 gate 自身。走査の針としてパス文字列を持つ (自分を走査対象から外さない)',
        ],
    ];
}
```

検査は 9 本:

| # | 検査 |
|---|------|
| G-1 | 軸 A: 実測と申告のファイル集合が完全一致する |
| G-2 | 軸 A: `launches_app: true` の entry は `tests/Support/Process/BootProbeRunner.php` ちょうど 1 件 |
| G-3 | 軸 A: `subject` / `recovery` / `reason` の 3 欄がいずれも空でない |
| G-4 | 軸 B: 実測と申告のファイル集合が完全一致し、`kind` が 3 値のいずれかである |
| G-5 | 軸 B: `kind: child_entry` のファイルは**起動呼び出しトークンを 1 つも持たない** (起こされる側である) |
| G-6 | 軸 C: 実測と申告のファイル集合が完全一致し、`reference_kind` が 2 値のいずれかである |
| G-7 | 軸 C: `reference_kind: runtime` はちょうど 1 件で、そのファイルは `BootProbeRunner` を参照している |
| G-8 | 走査が空振りしていない (走査根が実在し、3 軸の母集団がいずれも非空) |
| G-9 | 走査器の見本検査 (正例・負例。限界も期待値として固定する) |

G-9 の見本表 (`token_get_all` へ直接与える検体。ファイル走査を経由しない):

| 検体 | 軸 A | 軸 B | 軸 C | 起動呼び出し |
|------|-----|-----|-----|------------|
| `<?php $x = [PHP_BINARY];` | 1 | 0 | 0 | なし |
| `<?php // PHP_BINARY` (コメントのみ) | 0 | 0 | 0 | なし |
| `<?php $s = "PHP_BINARY";` (文字列のみ) | 0 | 0 | 0 | なし |
| `<?php require 'bootstrap/app.php';` | 0 | 1 | 0 | なし |
| `<?php $p = __DIR__.'/fake-wiring-probe.php';` | 0 | 0 | 1 | なし |
| `<?php proc_open("ls", [], $p);` | 0 | 0 | 0 | **あり** |
| `<?php new \Symfony\Component\Process\Process([]);` | 0 | 0 | 0 | **あり** |
| `<?php Process::run([]);` | 0 | 0 | 0 | **あり** |
| `<?php use function proc_open as launchProbe;` | 0 | 0 | 0 | **あり** (別名 import は import 側で捕まえる) |
| `<?php $f = "proc_open"; $f("ls", [], $p);` | 0 | 0 | 0 | **なし** (可変関数名は射程外。**限界を固定する**) |
| `<?php $a = 'fake-wiring-'."probe.php";` | 0 | 0 | **0** | なし (**文字列分割による迂回は射程外**。禁止事項として docblock に書く) |

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本ファイルのみ新規。**既存テストは 1 本も変更しない**

### PHPStan適合チェック

- 対象外 (`tests/`)。申告の shape は PHPDoc で固定し、`kind` / `reference_kind` は文字列リテラル型で閉じる

### テスト計画

- [x] 新規テスト: G-1 〜 G-9 (上表)
- [x] 負例が効くことの確認 (実装時に手で 1 度試す。**恒久のテストにはしない** — 実ファイルを
      一時的に汚す形になるため): 未登録ファイルへ `PHP_BINARY` を足すと G-1 が赤 /
      `child_entry` のファイルへ `new Process(` を足すと G-5 が赤
- [x] 個別の `DatabaseTransactions` を使っていないことを確認 (DB を張らない)

### リスク

- **軸 B の母集団が広い**: `bootstrap/app.php` は文字列で 5 ファイルに現れる。将来ここが増えるたびに
  申告が要る = 意図した摩擦である (それが gate の目的)。ただし**無関係な理由で増える**なら
  (例: 別の gate が同じ文字列を期待値に持つ)、`kind: inventory` として 1 行足すだけで済む
- **`--parallel` 安全**: 3 軸とも読み取りのみで、プロセスも DB も張らない
- **`TrackedPhpSourceFiles` は git 追跡下しか見ない**: 未追跡の新規ファイルは走査に入らない。
  gate が守る境界は commit / CI であり、そこでは必ず追跡下にある (同クラスの docblock が明記)

---

## 実装順 (fail-first)

| 段 | やること | ここで何が赤になるか |
|---|---------|-------------------|
| 1 | S1: 3 ファイルをバイト一致で取り込む | 自己検査 14 本が**その場で緑**になる (道具の契約は取り込み元で証明済み)。**ここが赤なら aicue との非互換が実在するので、ファイルを編集せずに報告する** |
| 2 | S6: gate を先に新設する。申告は**載せ替え後の姿**で書く | 軸 A の実測に旧 2 経路 (`FakeWiringProbeRunner.php` / `StrictTypesRuntimeProbe.php` が `PHP_BINARY` を直接持つ) が現れて G-1 が**赤**。軸 C も G-7 が**赤** (`runtime` の参照元がまだ `BootProbeRunner` を参照していない) |
| 3 | S4 の P-13 / P-14 / P-15 と P-8 の新契約を**先に書く** | 子がまだ印も `write_targets` も `key_digests` も返さないので**赤**。`interpret()` が無いので P-15 は**メソッド不在で赤** |
| 4 | S2 + S3 を実装する | 段 3 の赤が緑へ変わる。P-7 / P-10 / P-10b / P-11 もここで新契約へ揃える |
| 5 | S5 の `decide()` の負例を**先に書く** | `decide()` が旧実装に**存在しない**ので**赤**。「制限時間超過を例外にする」という観測だけでは旧実装 (`ProcessTimedOutException`) も通ってしまうので、赤を作るのは**変換関数の境界**にする |
| 6 | S5 を実装する | 段 5 の赤が緑へ変わる |
| 7 | S6 の申告を実測へ合わせる (旧 2 経路が軸 A から落ちる) | 段 2 の赤が緑へ変わる |
| 8 | `composer test` を `--parallel` で 2 回連続 / `composer phpstan` / `composer fix` | — |

## 受入条件

- `composer test` (`--parallel`) が **2 回連続で緑**。とくに次を**通常のテストコマンドで**緑にする
  (設計時の手動実測では代替しない):
  - `tests/Unit/Support/Process/BootProbeRunnerTest.php` (14 本)
  - `tests/Architecture/ExternalFakeBootProbeTest.php` (P-1〜P-15)
  - `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php` (既存 4 本 + 新規 3 本)
  - `tests/Architecture/PhpChildProcessLaunchInventoryTest.php` (G-1〜G-9)
- `composer phpstan` のエラーが **0 のまま** (アプリコードを変更しないので現状維持)
- `composer fix` (Pint) が**取り込んだ 3 ファイルに差分を出さない**。差分が出たら整形せずに報告する
- **生成物を残さないこと**: 作業開始時の `git ls-files --others --exclude-standard` の集合と、
  テスト走行後の同集合が**一致する**
- **実行時間の後退が無いこと**: `composer test` の実時間を実装前後で記録して比べる
  (S5 の載せ替えで子プロセス 1 本あたりの前後処理が増えるため)

## 乖離台帳の確認 (app-design Phase 3-0)

`docs/template-fingerprints.json` の `entries` (281 パス) と
`tests/Support/TemplateDivergence/adoption-debt.tsv` (171 件) を設計時に実測で確認した結果:

| 判定対象 | 指紋台帳のキーに在るか | 採用時債務に在るか | 本設計での扱い |
|---------|--------------------|-----------------|--------------|
| `tests/Support/Process/BootProbeRunner.php` / `BootProbeResult.php` / `tests/Unit/Support/Process/BootProbeRunnerTest.php` (取り込み 3 件) | **無い** (aicue が未受領のテンプレートパス) | 無い | **バイト一致で取り込む**。将来 指紋台帳を再生成しても記録値と一致して母集合に入り、**逸脱 0 件・債務 0 件**になる。今回は台帳を触らない (再生成は他パスの再観測を巻き込む世代操作であり別議題) |
| `tests/Architecture/SubprocessProbeLaunchGateTest.php` (テンプレートの同型 gate) | **無い** (aicue は未受領) | 無い | **このパスを使わない**。テンプレート側では**指紋台帳に登録済みの共有パス**なので、aicue 固有の申告内容を置くと将来の再生成で逸脱の登録が要る。aicue 既存の命名に倣った `tests/Architecture/PhpChildProcessLaunchInventoryTest.php` を使う |
| `tests/Support/ExternalFakes/FakeWiringProbeRunner.php` / `fake-wiring-probe.php` / `tests/Architecture/ExternalFakeBootProbeTest.php` / `tests/Support/StrictTypesRuntimeProbe.php` / `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php` (変更 5 件) | **無い** (いずれも aicue 固有のテスト支援コード) | 無い | 指紋機構の母集合外。**逸脱の登録は行わない** |
| `tests/Architecture/PhpChildProcessLaunchInventoryTest.php` (新規 1 件) | **無い** (テンプレートに存在しないパス) | 無い | 同上。テンプレート自身も「呼び出し側とドメイン結線部は各アプリの持ち物」という分類を採っている (共有パスに呼び出し側の見本テストを含めていない) ので、「テンプレートの形から外れた判断」ではない |
| `phpstan.neon` | **在る** | **在る** | **触らない**。債務パスは「変更したまま債務に残す」を選べず (突合 gate の `mutatedDebtPaths` が落ちる)、(1) 採用時の姿へ戻す / (2) テンプレートへ同期して債務から削る / (3) 意図的逸脱として登録して債務から削る の 3 択を迫られる。いずれも本 TODO の目的と無関係な重い操作なので、解析対象は現状 (`app` / `config` / `database` / `routes`) のまま据え置く |
| `tests/Architecture/NoNonCompoundGlobalUseTest.php` (軸 A で**申告するだけ**のファイル) | **在る** | **在る** | **1 行も変更しない**。本設計は当ファイルを gate の申告に載せるだけで、ファイル自体には触れない (触ると債務の 3 択を迫られる) |
| `docs/architecture.md` / `docs/template-divergence.md` | — | — | **触らない**。正典は文書を要求しておらず、道具の説明は各ファイルの docblock を正本にする |

- `LedgerPins::DIVERGENCE_ENTRY_COUNT` (36) / `FINGERPRINT_POPULATION_COUNT` (281) /
  `ADOPTION_DEBT_COUNT` (171) は**いずれも変更しない** (登録の追加・削除が無いため)
- **「登録するか迷ったら登録する」の原則との関係**: 本設計の新設・変更分は
  (a) 取り込む 3 本はテンプレートと**バイト一致**であり逸脱ではない、
  (b) 変更する 5 本と新設する 1 本は**テンプレートに無い aicue 固有の領域**への上積みである、
  (c) 共有パスへ食い違う内容を置く唯一の候補 (テンプレートの gate パス) は**意図的に避けた**、
  の 3 点から**逸脱を 1 件も作らない**。したがって登録の対象にならない

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 新規 4 本 (S1 の 3 / S6 の 1) + 既存 5 本の変更で、変更はすべて `tests/` 配下に閉じる。「実装順 (fail-first)」の 8 段に依存の鎖があり (取り込み → gate の赤 → 呼び出し側の赤 → 載せ替え → 申告の追随)、分割すると各段の赤を確認できない。`composer test` 全体と `--parallel` の 2 回実行まで含めて 1 本の worktree で完結する |
| 競合リスク | 低。`tests/Support/Process/` は新設ディレクトリで、変更する 5 本はいずれも本 feature 専用の狭い領域である。`docs/`・`app/`・台帳ファイル・`phpstan.neon` を触らないので、他 worktree の変更と行単位で衝突しない |

## スコープ外 (明示)

1. **アプリコード (`app/` / `routes/` / `config/` / `database/` / `bootstrap/`) の変更** — 1 バイトも触らない
2. **`docs/` の変更** — 正典は文書を要求していない。道具の説明は各ファイルの docblock を正本にする
3. **指紋台帳 (`docs/template-fingerprints.json`) の再生成と `LedgerPins` の件数更新** — 世代操作なので別議題
4. **`phpstan.neon` へのテストパスの追加** — 採用時債務パスなので触らない (上の表)
5. **観測対象の拡張** — 観測対象となる外部 fake の種類は増やさない。内訳:
   追加する (P-13 / P-14 = 正典 (5) の実働証明、P-15 = fail-closed の負例) /
   強化する (P-8 = 起動側の配列確認 → 子での実効値確認) /
   言い直すだけ (P-7 / P-11) / 分割するだけ (P-10) / 一切変えない (P-1〜P-6 / P-9 / P-12)
6. **`proc_open` を直呼びする既存 3 経路の載せ替え**
   (`tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` / `tests/Architecture/SkillsLockIgnoreCoverageTest.php` /
   `tests/Architecture/GitIndexNormalizationTest.php`) — `git` / シェルスクリプト / 別スクリプトの起動であり、
   **PHP の実行体でアプリを起こす経路ではない**。正典 (1) の射程外である
7. **`tests/Support/GlobalUse/PhpLintOracle.php` の載せ替え** — `php -l` を真値として取り出すだけで
   アプリを起動しない。起動器に載せると無関係な前提が付く。**gate の申告には残す** (理由の欄で説明する)
8. **子を 2 本立てて合図で同期させる並行テスト** — 別 feature (`process-concurrency-test-harness`)
9. **`tests/` 全域のプロセス起動 API の全数申告** — 実測すると**母集団は 25 ファイル**で、
   `Process::fake()` の単体テストや `git ls-files` の列挙まで含む。3 欄の申告を 25 件書くのは
   本 TODO の目的から外れた別作業である。
   **再判定の条件**: 本設計の gate が捕まえられない形 (文字列 `'php'` / `env php` / シェル経由 /
   変数の実行体パス) で子プロセスの起動が実際に足されたとき、または `tests/` のプロセス起動が
   別の理由で棚卸しの対象になったとき
10. **`docs/TODO.md` への登録** — `/app-todo-add` の責務


---

## 関連する現行コード

### tests/Support/ExternalFakes/FakeWiringProbeRunner.php (現行・全文)

```php
<?php

declare(strict_types=1);

namespace Tests\Support\ExternalFakes;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * 観測用スクリプト (fake-wiring-probe.php) を子プロセスで走らせる。
 *
 * 子の環境は**完全に作り直す** (親から引き継がない)。決め方は 3 段:
 * 1. プロセスの環境変数は `env -i` で空にしてから、必要な分だけを渡す
 *    (親のシェルに残った TESTING_FAKE_* に結果を左右されない。
 *     bug-hunt のスクリプトが DB 資格情報を遮断するときと同じ手である)
 * 2. 設定の出所は**専用の一時環境ファイル 1 つだけ**にする
 *    (`FAKE_WIRING_PROBE_ENV_DIR` / `…_FILE` で子へ渡し、子が
 *     `useEnvironmentPath()` / `loadEnvironmentFrom()` で固定する)。
 *     親のチェックアウトの `.env` / `.env.bughunt.local` は**読ませない**
 *     = 実 Stripe / 外部ログイン / S3 の資格情報は子の設定に 1 つも入らない
 * 3. 設定キャッシュを無効化する。`APP_CONFIG_CACHE` を**存在しない一時パス**へ向け、
 *    キャッシュ無しの起動として観測する (共有の bootstrap/cache を作ったり消したりしない =
 *    並列実行と衝突しない)
 *
 * ★**親の実鍵を複写しない**。`APP_KEY` / `CIPHERSWEET_KEY` は起動のたびに
 *   **使い捨ての値をその場で生成する** (観測は解決と経路の組み立てだけで、既存データの
 *   復号も DB 接続もしないため実鍵は要らない)。これで一時ファイルは秘密を 1 つも持たない。
 * ★それでも置き場所は保護する: 専用の一時ディレクトリを 0700 で作り、環境ファイルは
 *   作成時点から 0600 にする。起動前に権限を確かめ、0600 でなければ**子を起こさずに失敗させる**。
 *   後片付けは finally で行い、timeout・JSON の解釈失敗・Process の例外でも必ず通る。
 *
 * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
 * キャッシュ有りの起動は観測しない (キャッシュが古いときの挙動は本観測の範囲外で、
 * 本番混入防止は ProductionEnvGuard の二重判定が受け持つ)。
 */
final class FakeWiringProbeRunner
{
    /**
     * 一時環境ファイルに書いてよいキー (deny-by-default)。
     * 実資格情報のキーは 1 つも無く、鍵の 2 つは使い捨ての生成値である。
     *
     * @var list<string>
     */
    public const array ALLOWED_ENV_FILE_KEYS = [
        'APP_ENV', 'APP_KEY', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY',
        'TESTING_FAKE_EXTERNALS', 'TESTING_FAKE_STORAGE', 'TESTING_FAKE_LLM',
    ];

    /**
     * 子プロセスへ渡してよい**プロセス環境変数**のキー (上とは別物なので定数を分ける)。
     * `env -i` で空にしたうえでこの 3 つだけを載せる。
     *
     * ★この定数は「起動側が載せる分」の宣言であり、**子が実際に受け取った分**は
     *   probe が自分で観測して返す。両方を突き合わせて初めて `env -i` の退行が映る。
     *
     * @var list<string>
     */
    public const array ALLOWED_PROCESS_ENV_KEYS = [
        'FAKE_WIRING_PROBE_ENV_DIR',
        'FAKE_WIRING_PROBE_ENV_FILE',
        // 設定キャッシュを無効化する (存在しない絶対パスを一時ディレクトリ配下に指す)
        'APP_CONFIG_CACHE',
    ];

    /** 観測に使う自ホストの URL (実サーバは立てない。経路の組み立てにだけ使う) */
    private const string PROBE_APP_URL = 'http://127.0.0.1:65535';

    /** 環境ファイルの名前 (一時ディレクトリ内で固定) */
    private const string ENV_FILE_NAME = '.env.probe';

    /**
     * 観測を 1 回走らせる。
     *
     * @param  string|null  $baseDirectory  一時ディレクトリを作る親 (省略時は sys_get_temp_dir())
     * @return array{
     *     exitCode: int,
     *     output: array<string, mixed>,
     *     envFileValues: array<string, string>,
     *     directory: string,
     *     directoryMode: int,
     *     envFileMode: int,
     *     configCachePath: string,
     *     configCacheExists: bool,
     * }
     */
    public static function run(
        string $environment,
        bool $fakeExternals,
        bool $fakeStorage,
        bool $fakeLlm,
        ?string $baseDirectory = null,
        float $timeout = 120.0,
    ): array {
        $base = $baseDirectory ?? sys_get_temp_dir();
        $directory = $base.'/fake-wiring-probe-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700) || ! is_dir($directory)) {
            throw new RuntimeException("観測用の一時ディレクトリを作れない: {$directory}");
        }

        try {
            chmod($directory, 0700);

            $values = self::envFileValues($environment, $fakeExternals, $fakeStorage, $fakeLlm);
            $envFilePath = $directory.'/'.self::ENV_FILE_NAME;
            self::writeEnvFile($envFilePath, $values);

            $directoryMode = self::mode($directory);
            $envFileMode = self::mode($envFilePath);

            // 起動前に権限を確かめ、違えば子を起こさない (秘密を持たない設計だが置き場所は守る)。
            self::assertSafePermissions($directoryMode, $envFileMode);

            $configCachePath = $directory.'/config-cache-absent.php';

            $process = new Process(
                [
                    'env', '-i',
                    'FAKE_WIRING_PROBE_ENV_DIR='.$directory,
                    'FAKE_WIRING_PROBE_ENV_FILE='.self::ENV_FILE_NAME,
                    'APP_CONFIG_CACHE='.$configCachePath,
                    PHP_BINARY,
                    self::probeScriptPath(),
                ],
                FakeClassCatalog::repoRoot(),
                null,
                null,
                $timeout,
            );
            $process->run();

            return [
                'exitCode' => $process->getExitCode() ?? -1,
                'output' => self::decode($process->getOutput()),
                'envFileValues' => $values,
                'directory' => $directory,
                'directoryMode' => $directoryMode,
                'envFileMode' => $envFileMode,
                'configCachePath' => $configCachePath,
                'configCacheExists' => file_exists($configCachePath),
            ];
        } finally {
            self::removeDirectory($directory);
        }
    }

    /**
     * 一時環境ファイルへ書く内容 (許可キー以外は 1 つも作らない)。
     *
     * @return array<string, string>
     */
    public static function envFileValues(
        string $environment,
        bool $fakeExternals,
        bool $fakeStorage,
        bool $fakeLlm,
    ): array {
        // 実鍵は複写せず、起動のたびに使い捨ての値を生成する。
        // 形式は現行の設定が受理する形に合わせる (妥当性は「子が起動できたこと」自体が示す)。
        $values = [
            'APP_ENV' => $environment,
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_URL' => self::PROBE_APP_URL,
            'APP_DEBUG' => 'false',
            'CIPHERSWEET_KEY' => bin2hex(random_bytes(32)),
            'TESTING_FAKE_EXTERNALS' => $fakeExternals ? 'true' : 'false',
            'TESTING_FAKE_STORAGE' => $fakeStorage ? 'true' : 'false',
            'TESTING_FAKE_LLM' => $fakeLlm ? 'true' : 'false',
        ];

        foreach (array_keys($values) as $key) {
            if (! in_array($key, self::ALLOWED_ENV_FILE_KEYS, true)) {
                throw new RuntimeException("一時環境ファイルへ書けないキー: {$key}");
            }
        }

        return $values;
    }

    /**
     * 一時ディレクトリ 0700 / 環境ファイル 0600 でなければ例外にする (子を起こさない)。
     */
    public static function assertSafePermissions(int $directoryMode, int $envFileMode): void
    {
        if ($directoryMode !== 0700 || $envFileMode !== 0600) {
            throw new RuntimeException(
                '観測用の一時ファイルの権限が想定と違うため子プロセスを起こさない ('
                .sprintf('dir=%04o file=%04o', $directoryMode, $envFileMode).')'
            );
        }
    }

    /** 観測用スクリプトの絶対パス */
    public static function probeScriptPath(): string
    {
        return __DIR__.'/fake-wiring-probe.php';
    }

    /** 観測が組み立てる自ホストの host 部 (転送先の照合に使う) */
    public static function probeAppHost(): string
    {
        $host = parse_url(self::PROBE_APP_URL, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new RuntimeException('観測用 APP_URL から host を取り出せない');
        }

        return $host;
    }

    /**
     * @param  array<string, string>  $values
     */
    private static function writeEnvFile(string $path, array $values): void
    {
        // 'x' は既存ファイルがあれば失敗する (乗っ取られた置き場所へ書き足さない)。
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException("観測用の環境ファイルを作れない: {$path}");
        }

        // 中身を書く**前に**権限を絞る。
        chmod($path, 0600);

        $lines = '';
        foreach ($values as $key => $value) {
            $lines .= $key.'='.$value."\n";
        }

        // 書き切れなかった / 閉じられなかった環境ファイルで子を起こすと、
        // 「観測できたつもりで設定が欠けている」状態になる。fail-closed で止める。
        $written = fwrite($handle, $lines);
        $closed = fclose($handle);

        if ($written !== strlen($lines) || $closed === false) {
            throw new RuntimeException("観測用の環境ファイルを書き切れなかった: {$path}");
        }
    }

    private static function mode(string $path): int
    {
        clearstatcache(true, $path);
        $permissions = fileperms($path);

        return $permissions === false ? -1 : ($permissions & 0777);
    }

    /**
     * 子の出力を読む。**解釈できない出力は黙って通さず例外にする** (fail-closed)。
     *
     * 出力が空・JSON でない・配列でない、のいずれも「観測が成立していない」ことを意味する。
     * 中身を `raw_output` に詰めて返すと、後続の表明が別の理由で落ちて原因が隠れる。
     *
     * @return array<string, mixed>
     */
    private static function decode(string $output): array
    {
        if (trim($output) === '') {
            throw new RuntimeException('観測用スクリプトが何も出力しなかった (観測が成立していない)');
        }

        try {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                '観測用スクリプトの出力を JSON として読めない: '.$e->getMessage()."\n出力: ".$output,
                previous: $e
            );
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('観測用スクリプトの出力が配列ではない。出力: '.$output);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory.'/'.$entry;
            if (is_dir($path)) {
                self::removeDirectory($path);

                continue;
            }
            unlink($path);
        }

        rmdir($directory);
    }
}
```

### tests/Support/ExternalFakes/fake-wiring-probe.php (現行・全文)

```php
<?php

declare(strict_types=1);

use App\Services\Auth\SocialiteDriverResolver;
use App\Support\ExternalFakes\ExternalFakeDeclaration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Webmozart\Assert\Assert;

/*
 * 別プロセスで「宣言した差し替えが実際に効いているか」を観測して JSON を書き出す。
 *
 * ★責務は 4 つだけ: DB へ接続しない / container から解決する /
 *   転送先 URL を組み立てて読む / 終了コードを返す。
 *   HTTP サーバもブラウザも起動しない。
 * ★禁止する文 (echo) を使わないため fwrite(STDOUT, …) で書く (AGENTS.md §禁止する文)。
 * ★読み込む環境ファイルを**専用の一時ファイルだけ**に固定する (親のチェックアウトの
 *   .env / .env.bughunt.local を読ませない = 実資格情報が子の設定へ入らない)。
 */

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../../bootstrap/app.php';

try {
    Assert::isInstanceOf($app, Application::class);

    // ★**Dotenv を読む前に**、子が実際に受け取ったプロセス環境を観測する。
    //   起動側が組み立てた配列を検査しても `env -i` を外した退行は映らない
    //   (組み立ては同じまま、親の環境だけが流れ込むため)。観測できるのは子だけである。
    $initialProcessEnvironment = getenv();
    Assert::isArray($initialProcessEnvironment);
    $processEnvironmentKeys = array_keys($initialProcessEnvironment);
    sort($processEnvironmentKeys);

    $environmentDirectory = getenv('FAKE_WIRING_PROBE_ENV_DIR');
    $environmentFile = getenv('FAKE_WIRING_PROBE_ENV_FILE');
    Assert::stringNotEmpty($environmentDirectory);
    Assert::stringNotEmpty($environmentFile);

    $app->useEnvironmentPath($environmentDirectory);
    $app->loadEnvironmentFrom($environmentFile);

    $app->make(Kernel::class)->bootstrap();

    $resolved = [];
    foreach (ExternalFakeDeclaration::swaps() as $swap) {
        $resolved[$swap->abstract] = $app->make($swap->abstract)::class;
    }

    // 外部ログインは「解決したクラス名」だけでは足りない。転送先が実際に自ホストへ
    // 閉じているかまで見る (クラス名が合っていても転送先を戻す退行を緑で通すため)。
    // ★転送先の組み立ては**偽物が有効なときだけ**行う。無効なときに呼ぶと本物の
    //   身元確認サービス向けの URL を組み立てることになり、観測の目的から外れる。
    $redirectHost = null;
    if (config(ExternalFakeDeclaration::EXTERNALS_FLAG) === true) {
        // 観測する外部ログインの種類は設定から取る (名前を写経しない)。
        $providers = config('template.social_providers');
        Assert::isArray($providers);
        $provider = array_key_first($providers);
        Assert::stringNotEmpty($provider);

        $target = $app->make(SocialiteDriverResolver::class)->driver($provider)->redirect()->getTargetUrl();
        $host = parse_url($target, PHP_URL_HOST);
        $redirectHost = is_string($host) ? $host : null;
    }

    fwrite(STDOUT, json_encode([
        'resolved' => $resolved,
        'redirect_host' => $redirectHost,
        'process_environment_keys' => $processEnvironmentKeys,
    ], JSON_THROW_ON_ERROR));

    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode(['error' => $e->getMessage()], JSON_THROW_ON_ERROR));

    exit(1);
}
```

### tests/Architecture/ExternalFakeBootProbeTest.php (現行・全文)

```php
<?php

declare(strict_types=1);

use App\Support\ExternalFakes\ExternalFakeBinding;
use App\Support\ExternalFakes\ExternalFakeDeclaration;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Tests\Support\ExternalFakes\FakeWiringProbeRunner;

/*
 * 別プロセスで「宣言した差し替えが実際に効いているか」を実測する
 * (c2c: external-fakes-wiring-gate 柱 2)。
 *
 * in-process の実証 (ExternalFakeWiringInvariantTest) は provider を手で再実走させるため、
 * 「実際の起動 (遅延読み込み provider・設定の解決順) でも効いているか」までは示せない。
 * ここでは子プロセスを起こし、起動しきったアプリの container から解決して観測する。
 *
 * ★子プロセスへ実際の外部資格情報を渡さない。プロセスの環境変数は `env -i` で空にし、
 *   設定は専用の一時環境ファイル 1 つだけから読む。書いてよいキーに外部サービスの
 *   資格情報は 1 つも無く、鍵の 2 つは使い捨ての生成値である (P-6 / P-7 / P-8)。
 *
 * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
 * キャッシュが古いときの本番事故は ProductionEnvGuard の二重判定が受け持つ。
 */

/**
 * 一時ディレクトリの親の登録簿 (走行後の後片付けに使う)。
 *
 * @return list<string>
 */
function externalFakeProbeBaseDirectories(?string $add = null): array
{
    /** @var list<string> $bases */
    static $bases = [];

    if ($add !== null) {
        $bases[] = $add;
    }

    return $bases;
}

afterAll(function (): void {
    foreach (externalFakeProbeBaseDirectories() as $base) {
        if (is_dir($base)) {
            @rmdir($base);
        }
    }
});

/**
 * 観測を 1 回だけ走らせて使い回す (子プロセスの起動は高価なため)。
 *
 * 一時ディレクトリの親をケースごとに用意し、走行後に空であることを P-10 が確かめる。
 *
 * @return array{
 *     exitCode: int,
 *     output: array<string, mixed>,
 *     envFileValues: array<string, string>,
 *     directory: string,
 *     directoryMode: int,
 *     envFileMode: int,
 *     configCachePath: string,
 *     configCacheExists: bool,
 *     baseDirectory: string,
 * }
 */
function externalFakeProbeRun(string $case): array
{
    /** @var array<string, array<string, mixed>> $cache */
    static $cache = [];

    if (! array_key_exists($case, $cache)) {
        $base = sys_get_temp_dir().'/fake-wiring-probe-base-'.bin2hex(random_bytes(6));
        if (! mkdir($base, 0700) || ! is_dir($base)) {
            throw new RuntimeException("観測用の親ディレクトリを作れない: {$base}");
        }
        externalFakeProbeBaseDirectories($base);

        $result = match ($case) {
            // 偽物側: storage も含めて宣言の全件を偽物にする
            'fake' => FakeWiringProbeRunner::run('bughunt.local', true, true, false, $base),
            // 対照: フラグを全部落とすと本物が解決される
            'real' => FakeWiringProbeRunner::run('bughunt.local', false, false, false, $base),
            // 対照: production はフラグが立っていると起動そのものが失敗する
            'production' => FakeWiringProbeRunner::run('production', true, false, false, $base),
            default => throw new InvalidArgumentException("未知の観測ケース: {$case}"),
        };

        $cache[$case] = [...$result, 'baseDirectory' => $base];
    }

    /** @var array{exitCode: int, output: array<string, mixed>, envFileValues: array<string, string>, directory: string, directoryMode: int, envFileMode: int, configCachePath: string, configCacheExists: bool, baseDirectory: string} $entry */
    $entry = $cache[$case];

    return $entry;
}

/**
 * 観測結果の `resolved` を「解決キー => 実際に解決されたクラス」として取り出す。
 *
 * @param  array<string, mixed>  $output
 * @return array<string, string>
 */
function externalFakeProbeResolved(array $output): array
{
    $resolved = $output['resolved'] ?? null;
    expect($resolved)->toBeArray('観測結果に resolved が無い: '.json_encode($output));

    /** @var array<string, mixed> $resolved */
    $result = [];
    foreach ($resolved as $abstract => $class) {
        expect($class)->toBeString();
        /** @var string $class */
        $result[(string) $abstract] = $class;
    }

    return $result;
}

test('P-1 実測: bughunt.local + フラグ有効なら宣言の全件が偽物のクラスで厳密一致する', function (): void {
    $run = externalFakeProbeRun('fake');

    expect($run['exitCode'])->toBe(0, '観測が失敗した: '.json_encode($run['output']));

    $expected = [];
    foreach (ExternalFakeDeclaration::swaps() as $swap) {
        $expected[$swap->abstract] = $swap->fake;
    }

    expect(externalFakeProbeResolved($run['output']))->toBe($expected);
});

test('P-2 実測: 外部ログインの転送先ホストが自ホストである (実 IdP でない)', function (): void {
    $run = externalFakeProbeRun('fake');

    expect($run['output']['redirect_host'] ?? null)->toBe(FakeWiringProbeRunner::probeAppHost());
});

test('P-3 対照: フラグ無効なら宣言の全件が本物のクラスで厳密一致する', function (): void {
    $run = externalFakeProbeRun('real');

    expect($run['exitCode'])->toBe(0, '観測が失敗した: '.json_encode($run['output']));

    $expected = [];
    foreach (ExternalFakeDeclaration::swaps() as $swap) {
        $expected[$swap->abstract] = $swap->real;
    }

    // 転送先は偽物が有効なときだけ観測する (本物向けの URL を組み立てない)。
    // `??` は null を「不在」と同一視するため array_key_exists で存在を先に確かめる。
    expect(externalFakeProbeResolved($run['output']))->toBe($expected)
        ->and(array_key_exists('redirect_host', $run['output']))->toBeTrue()
        ->and($run['output']['redirect_host'])->toBeNull();
});

test('P-4 対照: production + フラグ有効は起動が失敗し、出力にフラグ名が現れる', function (): void {
    $run = externalFakeProbeRun('production');

    // (a) 順序に依存しない表明
    expect($run['exitCode'])->not->toBe(0);

    // (b) 順序に依存する表明。AppServiceProvider::boot() は ProductionEnvGuard::enforce() を
    //     最初に呼ぶため、他の起動時検査より先にこの違反が出る。
    //     落ちたら「起動時検査の順序が変わった可能性」を疑うこと。
    $error = $run['output']['error'] ?? '';
    expect($error)->toBeString();
    /** @var string $error */
    expect(str_contains($error, 'TESTING_FAKE_EXTERNALS'))
        ->toBeTrue('起動時検査の順序が変わった可能性がある (出力: '.$error.')');
});

test('P-5 fail-closed: 宣言集合も観測結果も空でない', function (): void {
    expect(ExternalFakeDeclaration::swaps())->not->toBeEmpty()
        ->and(externalFakeProbeResolved(externalFakeProbeRun('fake')['output']))->not->toBeEmpty();
});

test('P-6 一時環境ファイルのキー集合が許可集合の部分集合である', function (): void {
    $keys = array_keys(externalFakeProbeRun('fake')['envFileValues']);

    expect($keys)->not->toBeEmpty()
        ->and(array_values(array_diff($keys, FakeWiringProbeRunner::ALLOWED_ENV_FILE_KEYS)))->toBe([]);
});

test('P-7 子が実際に受け取ったプロセス環境が許可した 3 件ちょうどである', function (): void {
    $keys = externalFakeProbeRun('fake')['output']['process_environment_keys'] ?? null;
    expect($keys)->toBeArray();
    /** @var list<mixed> $keys */
    $actual = array_map(static fn (mixed $key): string => (string) $key, $keys);

    // (b) 危険な接頭辞が 1 件も無いこと
    foreach (['DB_', 'PG', 'AWS_', 'STRIPE_', 'TESTING_FAKE_', 'GOOGLE_'] as $prefix) {
        $leaked = array_values(array_filter(
            $actual,
            static fn (string $key): bool => str_starts_with($key, $prefix)
        ));
        expect($leaked)->toBe([], "禁止する接頭辞 {$prefix} のキーが子へ流れている");
    }

    // (a)(c) 許可した 3 件がすべて存在し、それ以外の余りが無いこと (deny-by-default)
    $expected = FakeWiringProbeRunner::ALLOWED_PROCESS_ENV_KEYS;
    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

test('P-8 一時環境ファイルの鍵は親の設定値の複写ではない', function (): void {
    $values = externalFakeProbeRun('fake')['envFileValues'];

    expect($values['APP_KEY'] ?? null)->not->toBe(config('app.key'))
        ->and($values['CIPHERSWEET_KEY'] ?? null)->not->toBe(config('ciphersweet.providers.string.key'));
});

test('P-9 一時ディレクトリ 0700 / 環境ファイル 0600 であり、違えば子を起こさない', function (): void {
    $run = externalFakeProbeRun('fake');

    expect($run['directoryMode'])->toBe(0700)
        ->and($run['envFileMode'])->toBe(0600);

    // 権限が緩い状態では子を起こさずに失敗すること (負のコントロール)。
    expect(fn () => FakeWiringProbeRunner::assertSafePermissions(0755, 0600))
        ->toThrow(RuntimeException::class);
    expect(fn () => FakeWiringProbeRunner::assertSafePermissions(0700, 0644))
        ->toThrow(RuntimeException::class);
});

test('P-10 正常終了・非ゼロ終了・timeout のいずれでも一時ディレクトリが残らない', function (): void {
    foreach (['fake', 'real', 'production'] as $case) {
        $run = externalFakeProbeRun($case);

        expect(is_dir($run['directory']))->toBeFalse("一時ディレクトリが残っている: {$case}")
            ->and(array_values(array_diff(scandir($run['baseDirectory']) ?: [], ['.', '..'])))
            ->toBe([], "一時ディレクトリの親に残骸がある: {$case}");
    }

    // timeout でも finally を必ず通ること。
    $base = sys_get_temp_dir().'/fake-wiring-probe-base-'.bin2hex(random_bytes(6));
    expect(mkdir($base, 0700))->toBeTrue();

    try {
        expect(fn (): array => FakeWiringProbeRunner::run('bughunt.local', true, true, false, $base, 0.01))
            ->toThrow(ProcessTimedOutException::class);

        expect(array_values(array_diff(scandir($base) ?: [], ['.', '..'])))->toBe([]);
    } finally {
        rmdir($base);
    }
});

test('P-11 設定キャッシュの指し先は一時ディレクトリ配下の絶対パスで、存在しない', function (): void {
    $run = externalFakeProbeRun('fake');

    expect(str_starts_with($run['configCachePath'], '/'))->toBeTrue()
        ->and(str_starts_with($run['configCachePath'], $run['directory'].'/'))->toBeTrue()
        ->and($run['configCacheExists'])->toBeFalse();
});

test('P-12 宣言の型: 観測が読む swaps() は ExternalFakeBinding の列である', function (): void {
    foreach (ExternalFakeDeclaration::swaps() as $swap) {
        expect($swap)->toBeInstanceOf(ExternalFakeBinding::class);
    }
});
```

### tests/Support/StrictTypesRuntimeProbe.php (現行・全文)

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * 「その PHP ソースで厳密な型検査が実際に効くか」を**別プロセスで実測**する。
 *
 * ★判定器の自己検査で使う。表に書いた真値が PHP の版で変わっても、
 *   実測との突き合わせがあれば「判定器が実効性の下界である」ことは崩れない。
 * ★書き込み先はシステムの一時ディレクトリで、リポジトリ内には何も残さない。
 *
 * ★**検体自身の出力や制御フローを判定材料にしない**。判定は
 *   「追記したプローブに到達し、その場で観測した結果」だけを見る:
 *   1. 終了コードが 0 でない (Fatal / Parse error で読み込めない) → 厳密化は成立しない = false
 *   2. 終了コードが 0 なら、標準出力は **nonce つきの標識と完全一致**していなければならない。
 *      一致しない場合 (検体が自分で出力した / `exit` や `__halt_compiler()` で
 *      プローブへ到達しなかった / `?>` の後ろとして素通しされた) は**実測不能**として
 *      例外にする。false を返して黙らない (fail-open 防止)
 *   3. 標識が `STRICT-<nonce>` なら true、`WEAK-<nonce>` なら false
 *   関数名も nonce つきにして、検体側の関数と衝突しないようにする。
 */
final class StrictTypesRuntimeProbe
{
    /**
     * @param  string  $phpSource  判定器へ渡すのと**同一の完全な PHP ソース**
     * @return bool 厳密化が実際に効いたか
     *
     * @throws RuntimeException 検体がプローブと干渉して実測できないとき
     */
    public static function strictTypesInEffect(string $phpSource): bool
    {
        // tempnam() は実ファイルを作る。拡張子を足すと**元のファイルが残る**ため、
        // 戻り値のパスへそのまま書く (php は拡張子に関係なく実行できる)。
        $path = tempnam(sys_get_temp_dir(), 'strict-probe-');
        if ($path === false) {
            throw new RuntimeException('実測用の一時ファイルを作れませんでした');
        }

        $nonce = bin2hex(random_bytes(8));
        $probe = <<<PHP

            function probe_{$nonce}(int \$value): int { return \$value; }
            try { probe_{$nonce}("1"); echo 'WEAK-{$nonce}'; }
            catch (\\TypeError \$e) { echo 'STRICT-{$nonce}'; }
            PHP;

        try {
            if (file_put_contents($path, rtrim($phpSource, "\n")."\n".$probe) === false) {
                throw new RuntimeException("実測用の一時ファイルを書けませんでした: {$path}");
            }

            $process = new Process([PHP_BINARY, '-d', 'error_reporting=E_ALL', $path]);
            $process->run();

            if (! $process->isSuccessful()) {
                return false; // 読み込めないソース (Fatal / Parse error) は厳密化が成立しない
            }

            $output = trim($process->getOutput());
            if ($output === 'STRICT-'.$nonce) {
                return true;
            }
            if ($output === 'WEAK-'.$nonce) {
                return false;
            }

            throw new RuntimeException(
                '実測用のプローブへ到達しませんでした (検体が自分で出力した / exit した可能性があります)。'
                ."出力: {$output}"
            );
        } finally {
            @unlink($path);
        }
    }
}
```

### tests/Support/PhpTokenScan.php (走査基盤・全文)

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * PHP ソースの静的走査で共有する `token_get_all()` の正規化 (純関数)。
 *
 * ★同じ正規化を 2 本持たない。`QueuedJobLeaseInventoryTest` (既存) と
 *   `ExternalClientBoundaryScanner` (T126) の両方がここを使う。
 * ★Pest のファイルスコープ関数はテストファイル間で衝突しうるため、
 *   `Tests\Support\QueueLeaseConfig` と同じくクラスの static メソッドへ集約する。
 */
final class PhpTokenScan
{
    /**
     * `token_get_all()` を「空白・コメントを除いた添字連番のリスト」へ正規化する。
     *
     * 単一文字トークン (`{` / `}` / `;` など) は `id => null` で表現し、
     * 行番号は直前トークンの行を引き継ぐ (単一文字トークンは行情報を持たないため)。
     *
     * @return list<array{id: int|null, text: string, line: int}>
     */
    public static function normalize(string $phpSource): array
    {
        $normalized = [];
        foreach (token_get_all($phpSource) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $normalized[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];

                continue;
            }

            $line = $normalized === [] ? 0 : $normalized[count($normalized) - 1]['line'];
            $normalized[] = ['id' => null, 'text' => $token, 'line' => $line];
        }

        return $normalized;
    }
}
```

### tests/Support/TrackedPhpSourceFiles.php (走査基盤・全文)

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * git 追跡下の PHP ソースファイル (blade を除く) を列挙する純関数。
 *
 * ★同じ列挙を 2 本持たない。`NoNonCompoundGlobalUseTest` (既存) と
 *   `StrictTypesDeclarationGateTest` の両方がここを使う。
 * ★git 管理下に限ることで vendor/ node_modules/ .claude/worktrees/ storage/ を
 *   **自動的に**除外できる (明示 exclude リストを保守しなくてよい)。
 * ★`*.blade.php` は**規則の段階で母集団に入れない**。blade はテンプレートであり
 *   先頭が PHP コードではない (PHP としては `<?php` より前に出力が始まる) ため、
 *   PHP ソースファイルに課す規約の対象にならない。免除ではなく対象外である。
 * ★**保証しないもの**: (a) 未追跡 (git add 前) のファイルは列挙されない。
 *   gate が守る境界は commit / CI であり、そこでは必ず追跡下にある。
 *   (b) 拡張子が `.php` でない PHP ファイル (`artisan` など) は列挙されない。
 *   (c) git が無い環境では**沈黙して空を返さず例外にする** (fail-open 防止)。
 * ★利用側は「自分が期待する母集団」を必ず pin すること (床値 + 代表パス)。
 *   共用したことで一方の都合の変更が他方の走査域を黙って変えるのを防ぐ。
 */
final class TrackedPhpSourceFiles
{
    /**
     * @param  string  $root  git worktree の root (絶対パス)
     * @return list<array{absolute: string, relative: string}> relative の昇順
     */
    public static function all(string $root): array
    {
        $process = new Process(['git', 'ls-files', '-z', '--', '*.php'], $root);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
                .$process->getErrorOutput()
            );
        }

        $files = [];
        foreach (explode("\0", $process->getOutput()) as $relative) {
            if ($relative === '' || str_ends_with($relative, '.blade.php')) {
                continue;
            }
            $absolute = $root.'/'.$relative;
            if (! is_file($absolute)) {
                continue; // 削除済みだが index に残っている等
            }
            $files[] = ['absolute' => $absolute, 'relative' => $relative];
        }

        usort($files, fn (array $a, array $b): int => strcmp($a['relative'], $b['relative']));

        return $files;
    }
}
```

### 取り込む laravel-claude-template:tests/Support/Process/BootProbeRunner.php (全文)

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Process;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * 起動順序を子プロセスで実測するための probe 起動器 (lctl feature: subprocess-boot-probe-harness)。
 *
 * アプリの壊れ方には「どの順番で組み立てられたか」に由来するものがあり、テストが走る時点で
 * そのプロセスの起動は終わっているため同じプロセスの中では再現できない。ここは
 * **小さな子プロセスを 1 つ起こして観測結果を回収する**、その起こし方と後始末だけを持つ。
 * 何を観測するかは呼び出し側 (gate) の責務である。
 *
 * ## 正典 v1 の 6 要素と本実装
 *
 *  1. 親と同じ実行体で起こす — `PHP_BINARY` を先頭に固定し、`$phpArguments` はその後ろに置く
 *  2. 環境変数は 3 段 — 継承 (許可一覧) → 基底 → ケース別上書き。子は `proc_open` に渡した
 *     配列だけを受け取るので、ここが開発者ローカルの env を締め出す唯一の統制点になる
 *  3. 出力は非ブロッキングで逐次読み、制限時間を超えたら SIGTERM → 猶予 → SIGKILL で落とし、
 *     全ての管を閉じてから必ず `proc_close` する
 *  4. 終了コードは実行中フラグが初めて false になった時点の非負値を保持し、`proc_close` の
 *     戻り値で上書きしない。強制終了で取れなければ 124 へ正規化する
 *  5. 子の書き出し先は**リポジトリ外の一時ディレクトリ**へ逃がす (下記 RESERVED_ENV_KEYS)。
 *     一時ディレクトリがリポジトリ内になったら子を起こす前に例外にする (fail-closed)
 *  6. 自己検査を持つ — `tests/Unit/Support/Process/BootProbeRunnerTest.php`
 *
 * ## 正典 v1 との差分 (1 点だけ)
 *
 * 書き出し先の 7 キー (RESERVED_ENV_KEYS) は runner が作った一時ディレクトリから導く
 * **予約鍵**であり、呼び出し側から渡せない (渡したら例外)。黙って無視すると結果の
 * `temporaryRoot` / `writtenRelativePaths` が嘘になり、正典 (5) の保証が空洞化するためである。
 * 環境変数の**順序**は正典と同じで、ケース別上書きが最後に効く。
 * 「固定鍵を呼び出し側より後ろに置いて上書き不能にする」テンプレート固有の作法は、その理由を
 * 持つ呼び出し側 (`tests/Architecture/BughuntFakeWiringTest.php`) が `array_merge($env, [...])`
 * で表現する (runner へ持ち上げると、逆の契約を持つ検査 — 呼び出し側が `APP_KEY` を 2 通り
 * 与えて観測差を測る `BugHuntInventoryCheckInvariantTest` の CT-3 — が載らなくなる)。
 *
 * ## 保証しないこと
 *
 *  - **孫プロセスは回収しない**。`proc_terminate()` が届くのは直接の子だけである
 *    (probe が孫を起こさないことは probe 側の前提)
 *  - **子が書く先を全部押さえること**は保証しない。退避できるのは Laravel が環境変数で受ける
 *    既知の書き出し先までで、独自パスへの書き込みは閉じない
 *  - **子が外部へ通信しないこと**は本クラスの主張ではない (probe の中身の責務)
 *  - **Unix 系 (Linux / macOS) 前提**である。段階的な強制終了は POSIX のシグナル意味論に依存する
 *  - **回収不能だった場合の振る舞いは実測していない**。子を落とせなかったときは一時ディレクトリを
 *    消さずに場所を例外へ書いて残す (生きている子の足元を壊さないため) が、この分岐は
 *    `SIGKILL` を無視できない以上作り出せないので自己検査で覆えていない
 *
 * **`tests/` 専用**である。`app` / `routes` / `config` / `database` / `bootstrap` へ持ち出すと
 * 外部到達統制の subprocess 0 件 pin に触れる (AGENTS.md セキュリティ不変条件 15)。
 * 同じ扱いの先例は `tests/Support/Architecture/GlobalUse/PhpLintOracle.php`。
 */
final class BootProbeRunner
{
    /** 強制終了で終了コードが取れなかったときの正規化値 (GNU timeout(1) と同じ)。 */
    public const int TIMEOUT_EXIT_CODE = 124;

    /** 既定の制限時間 (秒)。実測では probe 1 本が 1 秒前後で終わる。 */
    public const int DEFAULT_TIMEOUT_SECONDS = 60;

    /** 終了要求から強制終了までの猶予 (秒)。 */
    public const int TERMINATION_GRACE_SECONDS = 2;

    /** 子の終了を検知してから管を読み切るまでの上限 (秒。孫が管を持っていても回収を止めない)。 */
    public const int FINAL_DRAIN_SECONDS = 2;

    /** 強制終了を送ってから諦めるまでの最終期限 (秒)。超えたら例外にする。 */
    public const int KILL_WAIT_SECONDS = 5;

    /**
     * 親から継承する環境変数 (文字列かつ非空のときだけ継承する。既定値へ差し替えない)。
     *
     *  - `PATH`: 子が外部コマンドを解決するため (`PHP_BINARY` は絶対パスなので必須ではない)
     *  - `HOME`: composer / vendor が HOME 依存の場所を引く
     *  - `TMPDIR`: 子自身が一時ファイルを作るときの置き場所
     *
     * `LC_*` / `TZ` / `LANG` は継承しない (入力集合を広げる。時間帯は `config/app.php` が決める)。
     *
     * @var list<non-empty-string>
     */
    public const array INHERITED_ENV_KEYS = ['PATH', 'HOME', 'TMPDIR'];

    /**
     * runner が予約する環境変数 (書き出し先)。呼び出し側が渡したら例外にする。
     *
     * @var list<non-empty-string>
     */
    public const array RESERVED_ENV_KEYS = [
        'LARAVEL_STORAGE_PATH',
        'VIEW_COMPILED_PATH',
        'APP_CONFIG_CACHE',
        'APP_ROUTES_CACHE',
        'APP_SERVICES_CACHE',
        'APP_PACKAGES_CACHE',
        'APP_EVENTS_CACHE',
    ];

    /** ext-pcntl に依存しないためシグナル番号を直接持つ。 */
    private const int SIGNAL_TERMINATE = 15;

    private const int SIGNAL_KILL = 9;

    /** 出力を 1 回に読む上限 (バイト)。パイプバッファ (64KiB 程度) に合わせる。 */
    private const int READ_CHUNK_BYTES = 65536;

    /** 読む管が 1 本も無いときに眠る時間 (マイクロ秒)。回転で CPU を焼かないための休符。 */
    private const int IDLE_SLEEP_MICROSECONDS = 20000;

    /** 出力を待つ 1 回の上限 (マイクロ秒)。 */
    private const int SELECT_WAIT_MICROSECONDS = 50000;

    /** 基底の暗号鍵の種 (値そのものは観測に影響しない。CI の素の `.env` が空鍵であることへの備え)。 */
    private const string BASE_APP_KEY_SEED = 'laravel-claude-template:boot-probe';

    /**
     * probe を 1 本起こして結果を回収する。
     *
     * @param  list<non-empty-string>  $phpArguments  `PHP_BINARY` の後ろに置く引数
     *                                                (`['-r', $code]` / `[$scriptPath]`)
     * @param  array<non-empty-string, string>  $env  ケース別上書き (基底より後に効く)
     * @param  positive-int  $timeoutSeconds
     * @param  ?non-empty-string  $temporaryBase  一時ディレクトリの置き場所。既定は
     *                                            `sys_get_temp_dir()`。**退避を無効化する口ではない**
     *                                            (リポジトリ配下を渡すと例外になる。自己検査が
     *                                            その fail-closed を確かめるための場所指定である)
     */
    public static function run(
        array $phpArguments,
        array $env = [],
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?string $temporaryBase = null,
    ): BootProbeResult {
        Assert::notEmpty($phpArguments, 'probe の引数が空である');
        Assert::allStringNotEmpty($phpArguments);
        Assert::allStringNotEmpty(array_keys($env));
        Assert::allString($env);
        Assert::positiveInteger($timeoutSeconds);

        $reserved = array_values(array_intersect(self::RESERVED_ENV_KEYS, array_keys($env)));
        if ($reserved !== []) {
            throw new RuntimeException(
                '書き出し先は runner が決める (呼び出し側から渡せない): '.implode(', ', $reserved),
            );
        }

        $repositoryRoot = self::repositoryRoot();
        $temporaryRoot = self::createTemporaryRoot($temporaryBase ?? sys_get_temp_dir(), $repositoryRoot);

        // 「消してよいか」= 子が生存し得ないか。子がいないうちは消してよい (残骸を残さない)。
        // 遷移: 一時ディレクトリ作成直後 = true → `proc_open` 成功直後 = false
        //       → 回収成功後 = true / 回収不能 = false のまま
        $safeToRemove = true;

        try {
            $result = self::spawn(
                $phpArguments,
                self::composeEnv($env, $temporaryRoot),
                $repositoryRoot,
                $temporaryRoot,
                $timeoutSeconds,
                $safeToRemove,
            );
        } catch (Throwable $failure) {
            // 生きているかもしれない子の足元は消さない (残った場所は spawn() が投げる例外に書く)。
            if ($safeToRemove) {
                try {
                    self::removeDirectory($temporaryRoot);
                } catch (Throwable $removalFailure) {
                    // 後片付けの失敗で**本来の例外を捨てない** (previous に残す)
                    throw new RuntimeException(
                        '一時ディレクトリを消せなかった: '.$temporaryRoot
                        .' / 削除の失敗: '.$removalFailure->getMessage(),
                        0,
                        $failure,
                    );
                }
            }

            throw $failure;
        }

        self::removeDirectory($temporaryRoot);   // 正常経路。削除の失敗は例外のまま伝播させる

        return $result;
    }

    /**
     * `$candidate` が `$root` の配下か。
     *
     * 素の前方一致だと `/repo` が `/repository` を配下と誤判定するので、区切り文字を境界にする。
     * 自己検査が境界の振る舞いを直接 pin できるよう公開する。
     *
     * **両引数とも `realpath` 済みの絶対パス**であること (相対パスや `..` を含む形は受け付けない。
     * 正規化は呼び出し側の責務であり、ここでは絶対パスであることだけを `Assert` で確かめる)。
     */
    public static function isInside(string $root, string $candidate): bool
    {
        Assert::startsWith($root, DIRECTORY_SEPARATOR);
        Assert::startsWith($candidate, DIRECTORY_SEPARATOR);

        $normalizedRoot = rtrim($root, DIRECTORY_SEPARATOR);

        return $candidate === $normalizedRoot
            || str_starts_with($candidate, $normalizedRoot.DIRECTORY_SEPARATOR);
    }

    /**
     * 基底 (呼び出し側が上書きできる hermetic な既定)。**この 3 本しか置かない**。
     *
     *  - `APP_KEY`: CI の素の `.env` は `APP_KEY` が空で、encrypter を引いた瞬間に
     *    `MissingAppKeyException` で死ぬ (ローカル緑 / CI 赤の実測退行)。観測値は鍵に依存しない
     *  - `QUEUE_CONNECTION`: 開発機の `.env` が `redis` だと観測が変わる
     *  - `CACHE_STORE`: 1 プロセスで完結させ、DB / redis を張らせない
     *
     * **`APP_ENV` は置かない**。「渡さない実行では素の `.env` を読む」という観測が
     * 呼び出し側 (`BughuntFakeWiringTest`) の複数ケースの前提になっているためである。
     * ロケール系 (`LANG` / `LC_*`) も置かない (誰も依存せず、置くほど入力集合が広がる)。
     *
     * @return array<non-empty-string, string>
     */
    private static function baseEnv(): array
    {
        return [
            'APP_KEY' => 'base64:'.base64_encode(hash('sha256', self::BASE_APP_KEY_SEED, true)),
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'array',
        ];
    }

    /** リポジトリ root (このファイルは `tests/Support/Process/` に居る)。 */
    private static function repositoryRoot(): string
    {
        $root = realpath(dirname(__DIR__, 3));

        if (! is_string($root)) {
            throw new RuntimeException('リポジトリ root を解決できなかった');
        }

        return $root;
    }

    /**
     * 一時ディレクトリを作り、リポジトリ外であることを確かめて子が書く下位を掘る。
     *
     * 途中のどの失敗でも作った root を消してから元の例外を投げ直す (作りかけを残さない)。
     *
     * @return non-empty-string
     */
    private static function createTemporaryRoot(string $base, string $repositoryRoot): string
    {
        Assert::startsWith($base, DIRECTORY_SEPARATOR, '一時ディレクトリの置き場所は絶対パスであること');
        Assert::directory($base);
        Assert::writable($base);

        $created = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'boot-probe-'.bin2hex(random_bytes(8));

        if (! mkdir($created, 0o700, true)) {
            throw new RuntimeException('一時ディレクトリを作れなかった: '.$created);
        }

        try {
            $temporaryRoot = realpath($created);

            if (! is_string($temporaryRoot) || $temporaryRoot === '') {
                throw new RuntimeException('一時ディレクトリを正規化できなかった: '.$created);
            }

            if (self::isInside($repositoryRoot, $temporaryRoot)) {
                // 正典 (5) の fail-closed。ここを緩めると probe の書き出しがリポジトリへ落ちる。
                throw new RuntimeException(
                    'probe の一時ディレクトリがリポジトリ内にある (書き出し先を退避できない): '.$temporaryRoot,
                );
            }

            foreach ([
                'storage/framework/views',
                'storage/framework/cache/data',
                'storage/framework/sessions',
                'storage/logs',
                'storage/app/private',
                'bootstrap-cache',
            ] as $relative) {
                $directory = $temporaryRoot.DIRECTORY_SEPARATOR.$relative;
                if (! mkdir($directory, 0o700, true)) {
                    throw new RuntimeException('一時ディレクトリの下位を作れなかった: '.$directory);
                }
            }

            return $temporaryRoot;
        } catch (Throwable $failure) {
            self::removeDirectory($created);

            throw $failure;
        }
    }

    /**
     * 環境変数の 3 段合成 + 予約鍵 (正典 v1 の (2) と (5))。
     *
     * @param  array<non-empty-string, string>  $caseEnv
     * @return array<non-empty-string, string>
     */
    private static function composeEnv(array $caseEnv, string $temporaryRoot): array
    {
        $inherited = [];
        foreach (self::INHERITED_ENV_KEYS as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $inherited[$key] = $value;
            }
        }

        $storage = $temporaryRoot.'/storage';
        $bootstrapCache = $temporaryRoot.'/bootstrap-cache';

        $reserved = [
            'LARAVEL_STORAGE_PATH' => $storage,
            'VIEW_COMPILED_PATH' => $storage.'/framework/views',
            'APP_CONFIG_CACHE' => $bootstrapCache.'/config.php',
            'APP_ROUTES_CACHE' => $bootstrapCache.'/routes-v7.php',
            'APP_SERVICES_CACHE' => $bootstrapCache.'/services.php',
            'APP_PACKAGES_CACHE' => $bootstrapCache.'/packages.php',
            'APP_EVENTS_CACHE' => $bootstrapCache.'/events.php',
        ];

        // 予約鍵の宣言 (公開定数) と実体が食い違ったら、S4 の pin も run() の拒否も嘘になる。
        Assert::same(array_keys($reserved), self::RESERVED_ENV_KEYS, '予約鍵の宣言と実体が食い違っている');

        return array_merge($inherited, self::baseEnv(), $caseEnv, $reserved);
    }

    /**
     * 子を起こし、逐次読み・制限時間・回収まで面倒を見る。
     *
     * @param  list<non-empty-string>  $phpArguments
     * @param  array<non-empty-string, string>  $env
     * @param  non-empty-string  $temporaryRoot
     * @param  positive-int  $timeoutSeconds
     */
    private static function spawn(
        array $phpArguments,
        array $env,
        string $repositoryRoot,
        string $temporaryRoot,
        int $timeoutSeconds,
        bool &$safeToRemove,
    ): BootProbeResult {
        $startedAt = microtime(true);

        // 標準入力は /dev/null に向ける。probe が誤って読んでも即 EOF になり、止まる面が 1 つ減る
        // (管にすると読み手が現れたときに待ち続ける)。
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open([PHP_BINARY, ...$phpArguments], $descriptors, $pipes, $repositoryRoot, $env);

        if (! is_resource($process)) {
            throw new RuntimeException('probe の子プロセスを起動できなかった: '.implode(' ', $phpArguments));
        }

        // ここから先は子が生存しうる。回収できるまで一時ディレクトリを消さない。
        $safeToRemove = false;

        // 回収の状態は `try` の**前**に置く (`try` 内のどの例外点からも catch が回収を試みられるように)。
        $state = ['processClosed' => false, 'closeCode' => null];

        try {
            // pid の取得も回収対象の `try` の中に置く (ここで落ちても子・管・一時ディレクトリを
            // 一体で回収する = 「proc_open 成功後はどの例外点からも一体で回収する」)。
            $pid = proc_get_status($process)['pid'];

            foreach ([1, 2] as $descriptor) {
                $pipe = $pipes[$descriptor] ?? null;
                if (! is_resource($pipe)) {
                    throw new RuntimeException('probe の出力管を開けなかった');
                }
                if (! stream_set_blocking($pipe, false)) {
                    throw new RuntimeException('probe の出力を非ブロッキングにできなかった');
                }
            }

            $output = [1 => '', 2 => ''];
            $exitCode = null;          // 実行中フラグが初めて false になった時点の非負値
            $timedOut = false;
            $deadline = $startedAt + $timeoutSeconds;
            $killAt = null;            // 強制終了を送る時刻 (未設定は null)
            $giveUpAt = null;          // 落とせないと諦める時刻 ($killAt と同時に必ず入る)

            while (true) {
                self::readAvailable($pipes, $output);   // 詰まらせない (パイプバッファは 64KiB 程度)

                $status = proc_get_status($process);
                if (! $status['running']) {
                    if ($exitCode === null && $status['exitcode'] >= 0) {
                        $exitCode = $status['exitcode'];   // ここで確定させ、以後は上書きしない
                    }
                    break;
                }

                $now = microtime(true);

                // 最終期限は**再送の時刻とは独立**に見る (再送のたびに $killAt を先送りするので、
                // 期限の確認を再送分岐の中に置くと $giveUpAt を猶予ぶん超過できてしまう)。
                if ($giveUpAt !== null && $now >= $giveUpAt) {
                    throw new RuntimeException('probe の子プロセスを強制終了できなかった');
                }

                if ($killAt === null && $now >= $deadline) {
                    $timedOut = true;
                    if (! proc_terminate($process, self::SIGNAL_TERMINATE)) {
                        throw new RuntimeException('probe の子プロセスへ終了要求を送れなかった');
                    }
                    $killAt = $now + self::TERMINATION_GRACE_SECONDS;
                    $giveUpAt = $killAt + self::KILL_WAIT_SECONDS;
                } elseif ($killAt !== null && $now >= $killAt) {
                    // 送信失敗でも即座には諦めない (最終期限 $giveUpAt が唯一の打ち切り点)。
                    proc_terminate($process, self::SIGNAL_KILL);
                    $killAt = $now + self::TERMINATION_GRACE_SECONDS;
                }
            }

            // 終了検知後の最終読み取り (上限つき)。孫が管を持ったままでも回収を止めない。
            $drainUntil = microtime(true) + self::FINAL_DRAIN_SECONDS;
            while (microtime(true) < $drainUntil && self::hasReadablePipe($pipes)) {
                self::readAvailable($pipes, $output);
            }

            $closed = self::reclaim($process, $pipes, $state);
            $safeToRemove = true;

            if ($exitCode === null) {
                // シグナルで落ちた子は exitcode が -1 になる → 124 へ正規化する
                $exitCode = $timedOut
                    ? self::TIMEOUT_EXIT_CODE
                    : ($closed >= 0 ? $closed : throw new RuntimeException('probe の終了コードを回収できなかった'));
            }

            return new BootProbeResult(
                stdout: $output[1],
                stderr: $output[2],
                exitCode: $exitCode,
                timedOut: $timedOut,
                elapsedSeconds: microtime(true) - $startedAt,
                temporaryRoot: $temporaryRoot,
                writtenRelativePaths: self::collectWritten($temporaryRoot),   // 消す前に採取する
                pid: $pid,
            );
        } catch (Throwable $failure) {
            // 本来の例外を優先しつつ、回収は最後まで試みる。
            try {
                self::reclaim($process, $pipes, $state);   // 2 回目以降は保持値を返すだけ
                $safeToRemove = true;
            } catch (Throwable $cleanupFailure) {
                // **回収できなかった** — 一時ディレクトリは残す (場所を例外に書く)
                throw new RuntimeException(
                    'probe の子を回収できなかったため一時ディレクトリを残した: '.$temporaryRoot
                    .' / 回収の失敗: '.$cleanupFailure->getMessage(),
                    0,
                    $failure,
                );
            }

            throw $failure;
        }
    }

    /**
     * 読める管が 1 本でも残っているか (EOF 済みは数えない)。
     *
     * @param  array<int, resource>  $pipes
     */
    private static function hasReadablePipe(array $pipes): bool
    {
        foreach ([1, 2] as $descriptor) {
            $pipe = $pipes[$descriptor] ?? null;
            if (is_resource($pipe) && ! feof($pipe)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 読めるだけ読む (非ブロッキング)。
     *
     * `feof()` の管は `stream_select` の対象から**外す** — EOF 済みの管を残すと即時 ready になり
     * 回転し続けるためである。読む対象が 1 本も無ければ少し眠って戻る。
     *
     * @param  array<int, resource>  $pipes
     * @param  array<int, string>  $output
     */
    private static function readAvailable(array $pipes, array &$output): void
    {
        $read = [];
        foreach ([1, 2] as $descriptor) {
            $pipe = $pipes[$descriptor] ?? null;
            if (is_resource($pipe) && ! feof($pipe)) {
                $read[$descriptor] = $pipe;
            }
        }

        if ($read === []) {
            usleep(self::IDLE_SLEEP_MICROSECONDS);

            return;
        }

        $write = null;
        $except = null;
        $ready = stream_select($read, $write, $except, 0, self::SELECT_WAIT_MICROSECONDS);

        if ($ready === false) {
            throw new RuntimeException('probe の出力を待てなかった (stream_select が失敗した)');
        }

        if ($ready === 0) {
            return;
        }

        foreach ($read as $descriptor => $pipe) {
            $chunk = fread($pipe, self::READ_CHUNK_BYTES);
            if ($chunk === false) {
                throw new RuntimeException('probe の出力を読めなかった');
            }
            $output[(int) $descriptor] .= $chunk;
        }
    }

    /**
     * 子・管・終了コードを回収する (冪等)。
     *
     * `proc_close()` は子が生きているあいだ待つ。だから本 runner は「子の終了を確認する」か
     * 「確実に落とす」かのどちらかを済ませてからしか呼ばない。
     *
     * @param  resource  $process
     * @param  array<int, resource>  $pipes  閉じた管はその場で unset する (部分完了を表現するため)
     * @param  array{processClosed: bool, closeCode: int|null}  $state
     */
    private static function reclaim($process, array &$pipes, array &$state): int
    {
        if ($state['processClosed']) {
            Assert::integer($state['closeCode']);

            return $state['closeCode'];
        }

        if (proc_get_status($process)['running']) {
            // シグナル送信が失敗しても即座には諦めない (自然終了を最終期限まで待つ)。
            proc_terminate($process, self::SIGNAL_TERMINATE);
            $killAt = microtime(true) + self::TERMINATION_GRACE_SECONDS;
            $giveUpAt = $killAt + self::KILL_WAIT_SECONDS;

            while (proc_get_status($process)['running']) {
                $now = microtime(true);
                if ($now >= $giveUpAt) {
                    throw new RuntimeException('probe の子プロセスを落とせなかった (最終期限を超えた)');
                }
                if ($now >= $killAt) {
                    proc_terminate($process, self::SIGNAL_KILL);
                    $killAt = $now + self::TERMINATION_GRACE_SECONDS;
                }
                usleep(self::IDLE_SLEEP_MICROSECONDS);
            }
        }

        foreach ([1, 2] as $descriptor) {
            $pipe = $pipes[$descriptor] ?? null;
            if (is_resource($pipe)) {
                fclose($pipe);
            }
            unset($pipes[$descriptor]);
        }

        // `proc_close()` は -1 を返す場合も資源を閉じている。戻ってきた時点で閉じ済みとして扱う
        // (「非負のときだけ完了」にすると閉じ済みの資源へ 2 度目を呼ぶ危険がある)。
        $closeCode = proc_close($process);
        $state['processClosed'] = true;
        $state['closeCode'] = $closeCode;

        return $closeCode;
    }

    /**
     * 一時ディレクトリ配下に書かれたファイルを相対パスの昇順で採取する。
     *
     * @return list<non-empty-string>
     */
    private static function collectWritten(string $temporaryRoot): array
    {
        $prefix = rtrim($temporaryRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $written = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporaryRoot, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if (! str_starts_with($path, $prefix)) {
                // 黙って捨てない (追えないものが出たら設計の前提が崩れている)。
                throw new RuntimeException('一時ディレクトリ外のファイルを採取した: '.$path);
            }

            $relative = substr($path, strlen($prefix));
            Assert::stringNotEmpty($relative);
            $written[] = $relative;
        }

        sort($written);

        return $written;
    }

    /** 再帰削除 (存在しなければ何もしない)。**失敗したら例外**にする (黙って残さない)。 */
    private static function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $removed = $entry->isDir() && ! $entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());

            if (! $removed) {
                throw new RuntimeException('一時ディレクトリの中身を消せなかった: '.$entry->getPathname());
            }
        }

        if (! rmdir($path)) {
            throw new RuntimeException('一時ディレクトリを消せなかった: '.$path);
        }
    }
}
```

### 取り込む laravel-claude-template:tests/Support/Process/BootProbeResult.php (全文)

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Process;

use Webmozart\Assert\Assert;

/**
 * probe 1 回分の観測結果 (一時ディレクトリを消す前に採取したスナップショットを含む)。
 *
 * `Tests\Support\Process\BootProbeRunner` が唯一の生成者である (lctl feature:
 * subprocess-boot-probe-harness)。
 */
final readonly class BootProbeResult
{
    /**
     * @param  non-negative-int  $exitCode  強制終了なら BootProbeRunner::TIMEOUT_EXIT_CODE
     * @param  non-empty-string  $temporaryRoot  実行に使った一時ディレクトリ (実行後は消えている)
     * @param  list<non-empty-string>  $writtenRelativePaths  一時ディレクトリ配下に書かれたもの (昇順)
     * @param  positive-int  $pid  回収した子の pid。**回収済みの死骸の番号**であり操作対象ではない
     *                             (自己検査が「子が残っていない」ことを確かめるためだけに持つ)
     */
    public function __construct(
        public string $stdout,
        public string $stderr,
        public int $exitCode,
        public bool $timedOut,
        public float $elapsedSeconds,
        public string $temporaryRoot,
        public array $writtenRelativePaths,
        public int $pid,
    ) {
        Assert::natural($exitCode);
        Assert::true(
            is_finite($elapsedSeconds) && $elapsedSeconds >= 0.0,
            '所要時間が有限の非負値でない',
        );
        Assert::stringNotEmpty($temporaryRoot);
        Assert::allStringNotEmpty($writtenRelativePaths);
        Assert::positiveInteger($pid);
    }
}
```

### 取り込む laravel-claude-template:tests/Unit/Support/Process/BootProbeRunnerTest.php (全文)

```php
<?php

declare(strict_types=1);

use Tests\Support\Process\BootProbeRunner;

/*
| 起動 probe の共通 runner (`Tests\Support\Process\BootProbeRunner`) の自己検査
| (lctl feature: subprocess-boot-probe-harness の正典 v1 (6) = 「自己検査を持つ」)。
|
| runner は「何を観測するか」を持たない道具なので、道具そのものの契約 —
| 環境変数の 3 段合成 / 予約鍵 / 終了コードの保持 / 制限時間と強制終了 / 出力の逐次読み /
| 書き出し先の退避と後片付け — をここで固定する。
|
| **この自己検査が測らないこと** (詳細設計 P2 と同じ粒度):
|
|  1. runner 自身の途中失敗 (`stream_select` の失敗など) を**注入した**経路は測らない。
|     注入の継ぎ目を公開面へ足す方が害が大きい
|  2. 起動そのものが失敗する経路 (`proc_open` の失敗) も測らない。移植性のある誘発手段が無い
|     (常に `PHP_BINARY` と実在する作業ディレクトリで起こすため)
|  3. 「回収不能なら一時ディレクトリを残す」経路も測らない。子は `SIGKILL` を無視できないので、
|     移植性のある形でこの状態を作れない
|
| 測るのは 2 方向である: 「落とせない子を確実に落とす」(S12 / S14) と
| 「起動前の fail-closed で残骸を残さない」(S11)。
*/

/** 親 env の漏れを見るための番兵 (S1)。 */
const BOOT_PROBE_SENTINEL_KEY = 'BOOT_PROBE_SENTINEL';

/**
 * 子が受け取った環境変数を**丸ごと** JSON で報告させる probe (S1 / S2 / S3 / S4)。
 *
 * 鍵を列挙して問い合わせる形にすると「列挙に無い鍵が増えても緑」になる (基底に 1 本足しても
 * 気づけない)。集合そのものを持ち帰らせて完全一致で測る。
 */
const BOOT_PROBE_ENV_REPORT = <<<'PHP'
    echo json_encode(getenv());
    PHP;

/** アプリを起こして書き出し先を JSON で報告させる probe (S9 / S10)。 */
const BOOT_PROBE_PATH_REPORT = <<<'PHP'
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\Log::info('boot-probe self check');
    echo json_encode([
        'php_binary' => PHP_BINARY,
        'storage' => $app->storagePath(),
        'config_cache' => $app->getCachedConfigPath(),
        'routes_cache' => $app->getCachedRoutesPath(),
        'services_cache' => $app->getCachedServicesPath(),
        'packages_cache' => $app->getCachedPackagesPath(),
        'events_cache' => $app->getCachedEventsPath(),
        'view_compiled' => (string) config('view.compiled'),
        'log_path' => (string) config('logging.channels.single.path'),
    ]);
    PHP;

/**
 * 子の JSON 報告を配列で受け取る。
 *
 * @param  array<non-empty-string, string>  $env
 * @return array<string, mixed>
 */
function bootProbeDecodeReport(string $code, array $env = []): array
{
    $result = BootProbeRunner::run(['-r', $code], $env);

    expect($result->exitCode)->toBe(0, "probe が異常終了した: {$result->stderr}");

    /** @var mixed $decoded */
    $decoded = json_decode(trim($result->stdout), true);
    expect($decoded)->toBeArray("probe の出力が JSON でない: {$result->stdout} / {$result->stderr}");
    assert(is_array($decoded));

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

test('S1: 親の環境変数は子に現れない', function (): void {
    putenv(BOOT_PROBE_SENTINEL_KEY.'=leaked');

    try {
        $report = bootProbeDecodeReport(BOOT_PROBE_ENV_REPORT);

        expect($report)->not->toHaveKey(BOOT_PROBE_SENTINEL_KEY, '親の env が子へ漏れている');
    } finally {
        putenv(BOOT_PROBE_SENTINEL_KEY);
    }
});

test('S2: 許可した継承は規則どおり届く (親に無い鍵は子にも無い)', function (): void {
    // 継承する鍵の一覧そのものをリテラルで pin する。実装と定数を同時に削っても緑になる形
    // (期待値を実装側の定数から組み立てるだけの検査) を避ける。
    expect(BootProbeRunner::INHERITED_ENV_KEYS)->toBe(['PATH', 'HOME', 'TMPDIR']);

    $report = bootProbeDecodeReport(BOOT_PROBE_ENV_REPORT);

    foreach (BootProbeRunner::INHERITED_ENV_KEYS as $key) {
        $parent = getenv($key);
        // runner と同じ規則 (文字列かつ非空のときだけ継承する) で期待値を組む。
        // 環境によって HOME / TMPDIR が無くても偽レッドにしない。
        if (is_string($parent) && $parent !== '') {
            expect($report[$key] ?? null)->toBe($parent, "継承鍵 {$key} が子へ届いていない");

            continue;
        }

        expect($report)->not->toHaveKey($key, "親に無い継承鍵 {$key} を子が持っている");
    }
});

test('S3: ケース別上書きが基底に勝つ (正典 v1 の順序)', function (): void {
    $report = bootProbeDecodeReport(BOOT_PROBE_ENV_REPORT, ['CACHE_STORE' => 'file']);

    expect($report['CACHE_STORE'])->toBe('file', 'ケース別上書きが基底に負けている');
});

test('S4: 子が受け取る env の集合が完全一致する (基底 3 本 + 予約 7 本 + 継承分だけ)', function (): void {
    $result = BootProbeRunner::run(['-r', BOOT_PROBE_ENV_REPORT]);
    expect($result->exitCode)->toBe(0, $result->stderr);

    /** @var array<string, string> $report */
    $report = json_decode(trim($result->stdout), true, 512, JSON_THROW_ON_ERROR);

    // (a) 集合の完全一致。「以下」ではないので、基底に 1 本足しただけでも赤くなる。
    $inherited = array_values(array_filter(
        BootProbeRunner::INHERITED_ENV_KEYS,
        static function (string $key): bool {
            $value = getenv($key);

            return is_string($value) && $value !== '';
        },
    ));
    $expectedKeys = array_merge(
        $inherited,
        ['APP_KEY', 'QUEUE_CONNECTION', 'CACHE_STORE'],
        BootProbeRunner::RESERVED_ENV_KEYS,
    );
    sort($expectedKeys);
    $actualKeys = array_keys($report);
    sort($actualKeys);

    expect($actualKeys)->toBe($expectedKeys, '子が受け取る env の集合が契約と違う');

    // (b) 基底 3 本の値
    expect($report['APP_KEY'])->not->toBe('')
        ->and($report['QUEUE_CONNECTION'])->toBe('database')
        ->and($report['CACHE_STORE'])->toBe('array');

    // (c) 予約鍵 7 本は一時ディレクトリ配下を指す
    foreach (BootProbeRunner::RESERVED_ENV_KEYS as $key) {
        expect(BootProbeRunner::isInside($result->temporaryRoot, $report[$key]))
            ->toBeTrue("予約鍵 {$key} が一時ディレクトリの外を指している: {$report[$key]}");
    }

    // (d) 集合一致の系として、APP_ENV とロケール系が入っていないことを名指しでも書く
    // (「渡さない実行は素の .env を読む」は BughuntFakeWiringTest の複数ケースの前提である)。
    expect($report)->not->toHaveKey('APP_ENV')
        ->and($report)->not->toHaveKey('LANG')
        ->and($report)->not->toHaveKey('LC_ALL');
});

test('S5: 予約鍵は呼び出し側から渡せない', function (): void {
    expect(static fn (): mixed => BootProbeRunner::run(['-r', 'exit(0);'], ['LARAVEL_STORAGE_PATH' => '/tmp/x']))
        ->toThrow(RuntimeException::class);
});

test('S6: 終了コードを保持する', function (): void {
    $result = BootProbeRunner::run(['-r', 'exit(7);']);

    expect($result->exitCode)->toBe(7, '終了コードが proc_close の戻り値で潰れている')
        ->and($result->timedOut)->toBeFalse();
});

test('S7: 制限時間を超えた子を強制終了する', function (): void {
    $result = BootProbeRunner::run(['-r', 'sleep(30);'], timeoutSeconds: 1);

    expect($result->timedOut)->toBeTrue('制限時間を超えた子が落ちていない')
        ->and($result->exitCode)->toBe(BootProbeRunner::TIMEOUT_EXIT_CODE)
        // 実時間で狭く測らない (CI の負荷で偽レッドにしない)。上限は
        // 制限時間 + 猶予 + 最終期限 + 余裕で見る。
        ->and($result->elapsedSeconds)->toBeGreaterThanOrEqual(1.0)
        ->and($result->elapsedSeconds)->toBeLessThan(
            1.0 + BootProbeRunner::TERMINATION_GRACE_SECONDS + BootProbeRunner::KILL_WAIT_SECONDS + 5.0,
        );
});

test('S8: 大量出力で詰まらない', function (): void {
    // パイプバッファは 64KiB 程度なので、逐次読みでなければ子が固まって S7 経路に落ちる。
    $result = BootProbeRunner::run(['-r', 'echo str_repeat("x", 1048576);']);

    expect($result->exitCode)->toBe(0, $result->stderr)
        ->and(strlen($result->stdout))->toBe(1048576)
        ->and($result->timedOut)->toBeFalse();
});

test('S9: 書き出し先の退避が効いている (向き) / 親と同じ実行体で起きる', function (): void {
    $result = BootProbeRunner::run(['-r', BOOT_PROBE_PATH_REPORT], ['LOG_CHANNEL' => 'single']);
    expect($result->exitCode)->toBe(0, $result->stderr);

    /** @var array<string, string> $report */
    $report = json_decode(trim($result->stdout), true, 512, JSON_THROW_ON_ERROR);

    // 正典 (1): 親と同じ実行体で起こす。
    expect($report['php_binary'])->toBe(PHP_BINARY);

    foreach (['storage', 'config_cache', 'routes_cache', 'services_cache', 'packages_cache',
        'events_cache', 'view_compiled', 'log_path'] as $key) {
        expect(BootProbeRunner::isInside($result->temporaryRoot, $report[$key]))
            ->toBeTrue("書き出し先 {$key} がリポジトリ側を指している: {$report[$key]}");
    }
});

test('S10: 書き出し先の退避が効いている (実体) と後片付け', function (): void {
    $result = BootProbeRunner::run(['-r', BOOT_PROBE_PATH_REPORT], ['LOG_CHANNEL' => 'single']);

    expect($result->exitCode)->toBe(0, $result->stderr)
        ->and($result->writtenRelativePaths)->toContain('storage/logs/laravel.log')
        ->and(is_dir($result->temporaryRoot))->toBeFalse('一時ディレクトリが残っている');
});

test('S11: 一時ディレクトリがリポジトリ内なら起動前に失敗し残骸を残さない', function (): void {
    $base = base_path('storage/framework/testing');
    if (! is_dir($base)) {
        mkdir($base, 0o755, true);
    }

    $before = glob($base.'/boot-probe-*');
    expect($before)->toBeArray();
    assert(is_array($before));

    expect(static fn (): mixed => BootProbeRunner::run(['-r', 'exit(0);'], temporaryBase: $base))
        ->toThrow(RuntimeException::class);

    $after = glob($base.'/boot-probe-*');
    expect($after)->toBe($before, '起動前の fail-closed が残骸を残している');

    // 境界判定そのものを pin する (`/repo` と `/repository` を取り違えない)。
    expect(BootProbeRunner::isInside('/repo', '/repo'))->toBeTrue()
        ->and(BootProbeRunner::isInside('/repo', '/repo/inner'))->toBeTrue()
        ->and(BootProbeRunner::isInside('/repo', '/repository'))->toBeFalse()
        ->and(BootProbeRunner::isInside('/repo/', '/repo/inner'))->toBeTrue();
});

test('S12: 管を早く閉じた子でも確実に落として回収する', function (): void {
    // 子は標準出力・標準エラーを閉じてから寝る。管の EOF だけを終了検知に使う実装は
    // ここで無限に待つ (= 制限時間が効いていない) ことになる。
    $result = BootProbeRunner::run(
        ['-r', 'fclose(STDOUT); fclose(STDERR); sleep(30);'],
        timeoutSeconds: 1,
    );

    expect($result->timedOut)->toBeTrue()
        ->and($result->exitCode)->toBe(BootProbeRunner::TIMEOUT_EXIT_CODE)
        ->and(is_dir($result->temporaryRoot))->toBeFalse('一時ディレクトリが残っている');

    if (! function_exists('posix_kill')) {
        return;
    }

    // 回収済みなら pid はもう存在しない (ps ではなく runner が握っていた pid を直接見る)。
    expect(posix_kill($result->pid, 0))->toBeFalse('子プロセスが残っている');
});

test('S13: 子の終了後も読み切り、その最終読み取りには上限がある', function (): void {
    // 子は孫へ標準出力・標準エラーを渡したまま先に終了する。2 方向を同時に測る:
    //  - 上限が無い実装は、孫が寝ている間ずっと戻れない (孫は回収しない = 保証しないことの 1 つ)
    //  - 最終読み取りが無い実装は、子の終了後に届いた印を取りこぼす
    $code = <<<'PHP'
        $child = proc_open(
            [PHP_BINARY, '-r', 'usleep(300000); fwrite(STDOUT, "DRAINED"); sleep(6);'],
            [1 => STDOUT, 2 => STDERR],
            $pipes,
        );
        exit(3);
        PHP;

    $result = BootProbeRunner::run(['-r', $code]);

    // toContain は可変長ニードルなので message 引数を渡さない (渡すと第 2 ニードル扱いになる)。
    expect($result->stdout)->toContain('DRAINED');

    expect($result->exitCode)->toBe(3, '子の終了コードを取りこぼしている')
        ->and($result->timedOut)->toBeFalse()
        ->and($result->elapsedSeconds)->toBeLessThan(
            BootProbeRunner::FINAL_DRAIN_SECONDS + 2.5,
            '孫が管を持っている間ずっと待っている (最終読み取りの上限が効いていない)',
        );
});

test('S14: 終了要求を無視する子は強制終了で落とす (段階的強制終了)', function (): void {
    // S7 / S12 の子は SIGTERM で死ぬので、SIGKILL への昇格を消しても緑になってしまう。
    // ここは**終了要求を無視する子**を使い、猶予の後の強制終了まで到達させる。
    $result = BootProbeRunner::run(
        ['-r', 'pcntl_signal(SIGTERM, SIG_IGN); sleep(30);'],
        timeoutSeconds: 1,
    );

    expect($result->timedOut)->toBeTrue()
        ->and($result->exitCode)->toBe(BootProbeRunner::TIMEOUT_EXIT_CODE)
        // 終了要求では死なないので、猶予ぶんは必ず経過している (= SIGKILL 経路を通った)。
        ->and($result->elapsedSeconds)->toBeGreaterThanOrEqual(1.0 + BootProbeRunner::TERMINATION_GRACE_SECONDS)
        ->and($result->elapsedSeconds)->toBeLessThan(
            1.0 + BootProbeRunner::TERMINATION_GRACE_SECONDS + BootProbeRunner::KILL_WAIT_SECONDS,
            '最終期限を超えるまで落とせていない',
        )
        ->and(is_dir($result->temporaryRoot))->toBeFalse('一時ディレクトリが残っている');

    if (! function_exists('posix_kill')) {
        return;
    }

    expect(posix_kill($result->pid, 0))->toBeFalse('強制終了しても子が残っている');
})->skip(
    // 子は親と同じ実行体なので、親に ext-pcntl が無ければ子にも無い。
    // **成功扱いにはしない** — 段階的強制終了を測れていないことをテスト結果に出す。
    fn (): bool => ! function_exists('pcntl_signal'),
    'ext-pcntl が無い環境では終了要求を無視する子を作れず、段階的強制終了を測れない',
);
```

