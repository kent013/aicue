## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本件固有の前提】
- 本件はアプリケーションコードではなく、テスト基盤 (Browser テストレーン) の導入自動化である
- リポジトリには CI workflow の構成を deny-by-default で固定する gate
  (tests/js/architecture/ci-workflow-inventory.test.ts) が既にあり、
  browser-tests job の uses と実行行は完全一致の allowlist で縛られている
- テストレーンの機構は「CI 環境変数を参照しない」ことが別の Architecture テストで機械強制されている

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: browser-test-lane-automation

## 背景・課題

本リポジトリの Browser テストレーン (pest-plugin-browser + Playwright) は、
**Chromium + WebKit の 2 レーン**を契約として持つ (AGENTS.md ドメイン規約 3 /
`docs/testing-browser.md` / `scripts/run-browser-test.sh`)。
レーンそのものは動いているが、**ブラウザ実体と OS 共有ライブラリの導入が
すべて人手の手順書**にとどまっている。

機能台帳 lctl の `browser-test-lane` (領域深掘り 2026-08-14 / 観測点 aicue@a5553b52eaed) は、
本リポジトリの未追従を **5 点**と記録している。3 巡連続で 1 点も着手されていない。

1. **導入スクリプトが無い**。`docs/testing-browser.md` が
   `pnpm exec playwright install chromium webkit` を手で叩くよう案内しているだけ
2. **事前確認 (preflight) が無い**。未導入のままレーンを起動すると、
   グローバルテストロックを取ってから
   "Host system is missing dependencies to run browsers" で WebKit レーンが全 fail する
3. **devcontainer の手順が自動化されていない**。
   「`sudo pnpm exec playwright install-deps webkit` を一度実行」という案内が文章で置かれているだけ
4. **CI に `~/.cache/ms-playwright` のキャッシュ段が無い** (家系 6 リポジトリのうち aicue 以外の 5 本にはある)
5. **CI の `browser-tests` job が失敗時の成果物収集段を持たない**
   (`.github/workflows/ci.yml` に `upload-artifact` が 1 件も無い。家系 6 本で aicue だけ)

4 と 5 は本セッションで HEAD を実測して確認した (`upload-artifact` 0 件 / `ms-playwright` 0 件)。

### なぜ放置できないか

- **WebKit レーンは飾りではない**。撮影 PWA の主戦場 iOS Safari に最も近い engine で、
  ログアウト後の Inertia 履歴からの PII 復元を止める唯一の自動回帰である
  (AGENTS.md ドメイン規約 3)。その WebKit こそが Linux で
  gstreamer / gtk-4 / libwoff2 等の OS 共有ライブラリを要求し、
  **導入漏れの実害を一身に受ける**レーンである。
- 家系の先行実装 (motivation) では「管理者権限が使えないと**黙って** OS ライブラリ無しの
  導入へ落ちる」という実害が観測されている。後になってブラウザが起動できず、
  原因の分かりにくい失敗になる。本リポジトリは導入経路そのものが無いので、
  同じ失敗が**手順書の読み落とし**という形で常時起こりうる。
- 導入経路が「手順書の文章」である限り、**手順書と実際に必要なものがずれても誰も気づかない**。
  実際、`docs/testing-browser.md` は WebKit の共有ライブラリを devcontainer の節でしか説明しておらず、
  CI (`ci.yml`) は `--with-deps` を付ける、という二重管理になっている。

## 改善アイデア

**ブラウザ導入の知識を `scripts/setup-browser-testing.sh` 1 本へ寄せ、
レーン起動・CI・開発環境初期化の 3 経路がすべてそこを通るようにする。**
そのうえで CI に「ブラウザ実体のキャッシュ」と「失敗時の証跡回収」を足す。

### 1. 導入スクリプト (`scripts/setup-browser-testing.sh`)

対象ブラウザ集合は **`chromium webkit`** に固定する
(`scripts/run-browser-test.sh` の既定レーンと 1 対 1。家系で唯一の 2 ブラウザ構成)。

判定を **3 つに分けて**行い、満たせないときは**特権を要する経路を起こす前に止める**
(黙って劣った導入へ落ちない):

| 判定 | 何を見るか |
|---|---|
| 権限 | `id -u` が 0 か、`sudo -n true` が通るか |
| 要求 | `playwright install-deps --dry-run` の出力 (不足ライブラリの有無) |
| 充足 | `playwright install --dry-run` が出す各ブラウザの導入先ディレクトリの実在 |

- 実行モードは環境変数 `BROWSER_TEST_DEPS` の `auto` (既定) / `force` の 2 値のみ。
  **未知の値は拒否側に倒す** (fail-closed)。
- 要求があるのに権限が無ければ **exit 1**。判定不能 (出力が想定形でない / 抽出 0 件) も **exit 1**。
- Linux 以外 (macOS ホスト直実行) では OS ライブラリの導入判定を行わない。
  Playwright が OS ライブラリ導入に対応するのは Linux / Windows だけであるため
  (vendor 実装で確認済み)。この分岐が無いと macOS では常に「判定不能」になって詰む。
- `--check` (導入せず判定だけ行う) と `--self-test` (判定関数を fixture で駆動する自己検査。
  実資源にも node にも触れない) を持つ。

**家系との意図的な差**: 先行実装 (spirux / motivation / aigenba) は `BROWSER_TEST_DEPS` に
`skip` を持ち「CI では skip を受理しない」という分岐を入れている。本リポジトリは採らない。
`GlobalTestLockInventoryTest` が示すとおり、当リポジトリには
**「CI を特別扱いしない = テストレーンの機構に `CI` 環境変数への参照を作らない」**という
明文の契約が既にある。`skip` を持たなければ `CI` 参照そのものが不要になるので、
2 値 (`auto` / `force`) に絞ることで契約と両立させる。

### 2. 事前確認 (preflight)

`scripts/run-browser-test.sh` が、**グローバルテストロックを取得する前**に
`bash scripts/setup-browser-testing.sh --check` を実行し、非ゼロなら導線を示して止まる。

ロック取得前に置くのは、既存の bug-hunt 併走 guard とまったく同じ理由である
(取得後に落とすと、先行レーンの終了を数分待ってから「導入されていません」と言うことになる)。

呼び出しは **source ではなく子プロセス実行**にする。当リポジトリは
`EXIT trap の所有者をライブラリ 1 箇所 (`scripts/global-test-lock.sh`) に固定する`
という契約を契約テストで機械強制しており (C7)、レーンスクリプトが別のスクリプトを
source すると、その契約とロック機構への `CI` 参照禁止の両方に触れうるため。

### 3. 開発環境初期化への接続

`composer setup` の最後に導入スクリプトを足す。`composer setup` は `init.sh` が呼ぶ
このリポジトリの初期化手順そのもの (`composer install` → `key:generate` → `migrate` →
`pnpm install` → `pnpm build`) であり、ブラウザ導入は `pnpm install` の後でしか成立しない。
これで「devcontainer では install-deps を一度手で実行する」という手順が消える。

導入できない環境では `composer setup` は**止まる**。黙って進むと、後で
「WebKit レーンが全 fail」という原因の分かりにくい失敗になるため
(motivation で観測された実害と同型)。

### 4. CI: ブラウザ実体のキャッシュ

`browser-tests` job に `~/.cache/ms-playwright` のキャッシュ段を足す。
キーは lockfile の hash とし、**部分一致の復元キーは持たない**
(古い版のブラウザを溜め込まないため)。

### 5. CI: 失敗時の成果物収集

`browser-tests` job の最後に、失敗時だけ動く成果物アップロード段を足す。

ここで**設計上の落とし穴が 1 つある**。pest-plugin-browser は失敗時のスクリーンショットを
`tests/Browser/Screenshots/` に書くが、**起動時に同ディレクトリを丸ごと消す**
(vendor 実装で確認済み)。本リポジトリのレーンは Chromium → WebKit の 2 回の pest 起動なので、
**Chromium レーンの証跡は WebKit レーンの起動で消える**。
そのままアップロード段を足しても、先に失敗する側 (Chromium) の証跡は空になる。

したがって `scripts/run-browser-test.sh` が**各レーンの終了直後に**証跡を
`storage/browser-test-artifacts/<lane>/` へ退避する。CI はそのディレクトリを収集する。

## 期待効果

- **使命への貢献**: 撮影 PWA (iOS Safari) の回帰を守る WebKit レーンが、
  「導入されていなかった」を理由に空回りしたり全 fail したりする経路を塞ぐ。
  台本作成・撮影判断・編集を肩代わりする画面群の回帰は Browser レーンが唯一の自動防波堤である。
- **失敗の原因が読めるようになる**: 「ブラウザが無い」と「テストが壊れた」を、
  ロックを取る前の 1 行のメッセージで切り分けられる。
- **CI の実行時間短縮と診断可能性**: ブラウザ実体 (数百 MB) の再取得が消え、
  失敗時にはスクリーンショットが残る。今は CI が赤くなっても手元で再現するしかない。
- **台帳の未追従 5 点がすべて解消**し、家系の正典 t2 形に追いつく。

## 実装方針 (概要)

| # | 変更対象 | 内容 |
|---|---|---|
| 1 | `scripts/setup-browser-testing.sh` (新規) | 導入の単一情報源。権限 / 要求 / 充足の 3 判定 + `--check` + `--self-test` |
| 2 | `scripts/run-browser-test.sh` | ロック取得前の preflight。レーン終了ごとの証跡退避 |
| 3 | `composer.json` (`scripts.setup`) | 末尾に導入スクリプトを追加 |
| 4 | `.github/workflows/ci.yml` | `browser-tests` に cache 段 / 導入スクリプト呼び出し / 失敗時 upload 段 |
| 5 | `tests/js/architecture/ci-workflow-inventory.test.ts` | allowlist の登録と新規検査 (W18 / W19) |
| 6 | `scripts/setup-browser-testing.contract.test.ts` (新規) | 導入スクリプトの契約テスト |
| 7 | `scripts/run-browser-test.contract.test.ts` | preflight と証跡退避の契約を追加 |
| 8 | `tests/Architecture/BrowserProvisioningEntrypointTest.php` (新規) | 導入経路の一元化を deny-by-default で固定 |
| 9 | `docs/testing-browser.md` / `scripts/README.md` / `.gitignore` | 手順書の書き換えと台帳追記 |

## 制約・前提

- **`tests/js/architecture/ci-workflow-inventory.test.ts` との突き合わせが必須**。
  同 gate は `browser-tests` job の `uses` (W14a) と実行行 (W14b) を**完全一致の allowlist**で
  固定している。`actions/cache` / `actions/upload-artifact` の追加と、導入コマンド行の差し替えは
  **allowlist への登録を伴う**。これは gate の設計どおりの手続きであって迂回ではない。
- 同 gate の `actionName()` は `uses` から版を落として名前だけで突合するため、
  **allowlist に版を書かない**。ただし**存在しない版を ci.yml に書けば CI は即 fail する**ので、
  実装時に `actions/cache` / `actions/upload-artifact` の現行 major を確認すること。
- W12 (トリガー集合の完全一致) / W15 (job-level `if` の不在) / W17 (schedule の不在) には
  **一切触れない**。失敗時アップロードは **step-level の `if: failure()`** であり、
  W15 が見る job-level `if` とは別物である (gate の実装で確認済み)。
- W13 (`continue-on-error` の不在) にも触れない。soft-fail は使わない。
- `scripts/` へスクリプトを追加するので `scripts/README.md` への追記が必須
  (`tests/Architecture/ScriptsReadmeInventoryTest.php` が全数を機械強制。
  `scripts/**/*.test.ts` も走査対象なので、契約テストファイルの行も要る)。
- `GlobalTestLockInventoryTest` は `scripts/run-browser-test.sh` に
  **`CI` 環境変数への参照を禁じている**。preflight の追加でこれを破らない。
- `composer.json` の `scripts.setup` は同 gate の検査対象外である
  (対象は `test` / `test:*` のみ。実装で確認済み)。

## スコープ外

- **家系正典 t2 のうち「導入一元化の既定拒否 gate」の重量級版** (motivation は 2000 行超)。
  本設計は施策 8 で、`git ls-files` を母集団に「`playwright install` の記述を持つファイルが
  導入スクリプトとその契約テストに限られる」ことだけを検査する軽量版を置く。
  今必要なのは「導入経路が 2 つに増えたら落ちる」ことであって、
  YAML / Dockerfile / markdown の構造解析器を自前で持つことではない (思考原則 2)。
- **`--self-test` を CI の `php` job へ独立した段として足すこと**。
  自己検査は契約テスト経由で `frontend` job の `pnpm test` から必ず走るため、
  CI の段を増やす必要は無い。
- Browser テスト自体の追加・変更。レーンの中身は本設計の対象ではない。
- bfcache 実機受入確認 (T161 で別途着地済み) との連動。
