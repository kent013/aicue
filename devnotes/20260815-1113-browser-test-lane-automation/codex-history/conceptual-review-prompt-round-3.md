# Round 3: 概念設計の修正版 (2 回目)

Round 2 の [Critical] (導入処理そのものの排他が失われた) は、
**導入専用ロック (二重確認つき) を `scripts/setup-browser-testing.sh` 自身が持つ**形で解消した。

`install-deps --dry-run` で不足を判別できるかという [Warning] については、
**pin されている playwright 1.61.1 の vendor 実装を実読して立証済み**である
(対応マトリクスに関数名・判定式・出力文言を記載した)。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 2

## [Critical] 自動導入をグローバルテストロックの外へ出したことで、導入処理そのものの排他が失われた

- 判断: **対応する** (指摘は正しい。実害がある)
- 根拠: 複数 worktree から同時に `composer test:browser` を起動すると、両者が
  グローバルテストロックの**手前**で同時に導入判定を行い、
  `~/.cache/ms-playwright` への並行書き込みと `apt-get` / `dpkg` のロック競合を起こしうる。
  dpkg は排他ロックを取るので、後発は "Could not get lock /var/lib/dpkg/lock-frontend" で落ちる。
- 対応内容: **導入専用のロックを `scripts/setup-browser-testing.sh` 自身が持つ**
  (レーンスクリプトではなくスクリプト側に置く = CI 経路と手動実行も同じ排他に乗る)。
  手順は指摘のとおりの二重確認にする:
  1. 充足確認はロック外 (充足していれば何も取らずに exit 0)
  2. 不足しているときだけ `flock` で `/tmp/browser-provisioning-<uid>.lock` をブロッキング取得
  3. 取得後に**再判定**
  4. それでも不足していれば導入
  5. 解放してから呼び出し元へ戻り、レーンはその後でグローバルテストロックへ進む
  - **グローバルテストロックとは統合しない**。目的 (ホスト共有資源への書き込みの排他) も
    粒度も違い、「似ているから」で 1 つにしない (思考原則 4)。
    数百 MB の取得中に全テストレーンを止めない、という当初の利点も保たれる。
  - 契約テスト用に `BROWSER_PROVISION_LOCK_DIR` の override を持たせる
    (既存 `GLOBAL_TEST_LOCK_DIR` と同じ「self-test 専用」の作法)。

## [Warning] `install-deps --dry-run` の出力で「不足」を判別できることが立証されていない

- 判断: **対応する** (立証を設計へ書き込む)
- 根拠: 指摘のとおり「解析できる」と「不足を正しく判別できる」は別。
  ただし本件は vendor 実装で**判別できることを確認済み**である。
  pin されている playwright 1.61.1 の `playwright-core/lib/coreBundle.js` の
  `reportMissingDependenciesLinux()` は、`apt-get install -s --no-install-recommends <libs>` を
  実行して出力の `^Inst (\S+) ` 行を数え、
  - 0 件 → `All system dependencies are installed.` を出力して exit 0
  - 1 件以上 → `Missing system dependencies (N):` + 一覧を出力して **exitCode = 1**
  と**明確に二分している**。つまり「必要パッケージ集合の表示」ではなく
  「不足しているものだけの表示」である。
- 対応内容: 詳細設計にこの根拠 (関数名・判定式・出力文言) を明記し、
  受入条件を Round 2 の指摘どおりの組み合わせ表 (充足済み Linux / 不足 Linux ×
  権限あり / なし × ブラウザ実体あり / なし) として `--self-test` に持たせる。
  実 CLI smoke には「充足済み環境で特権処理へ進まないこと」を含める。

## [Warning] 「充足していれば数秒で何もせず抜ける」は未検証の仮説

- 判断: 対応する
- 対応内容: これを**成功条件**として概念設計に明記し、契約テストの sandbox 実走で
  充足済み環境を作って次の 4 点を固定する: (a) `sudo` を起動しない
  (b) `install-deps` の実処理を起動しない (c) ブラウザの再取得を行わない (d) 正常終了する。
  スタブに呼び出し記録を書かせて検証する (既存 `run-browser-test.contract.test.ts` の
  pest スタブと同じ作法)。

## [Warning] レーン失敗後の証跡退避が実行されない可能性 (`set -e` で飛ぶ)

- 判断: 対応する
- 根拠: 既存実装は `global_test_lock_run ... || code=$?` で終了コードを受けているので
  現状でも飛ばないが、**契約として固定されていない**のは指摘のとおり。
- 対応内容: 「レーン実行 → 終了コード保存 → 証跡退避 → 次レーン → 最終判定」の順序を
  契約テストで固定する (失敗レーンでも退避が起きることを sandbox 実走で確認する)。

## [Warning] `storage/browser-test-artifacts/` の前回実行分をいつ消すかが未定義

- 判断: 対応する
- 対応内容: **グローバルテストロック取得後・レーンループ前**に当該ディレクトリだけを
  作り直す。ロック取得後なので並行する別実行の証跡を消すことはない
  (ロックが同一 UID・同一マシンで直列化している)。この位置も契約テストで固定する。

## [Warning] 実装方針の表に削除したはずの `--check` が残っている (設計の自己矛盾)

- 判断: 対応する
- 対応内容: 表から `--check` を削除。あわせて**未知オプションを拒否する**ことを
  契約テストで固定し、旧仕様の復活を防ぐ。

## [Warning] 「レーン起動・CI・開発環境初期化の 3 経路」という旧設計の文言が残っている

- 判断: 対応する
- 対応内容: 「レーン起動と CI の 2 経路」に修正。あわせて台帳の未追従点 3 の完了条件を
  「devcontainer 初期化で自動導入」ではなく
  **「初回 Browser レーン起動時に手作業なしで導入される」**へ読み替えたことを明記する。

## [Suggestion] 「Browser レーンが唯一の自動防波堤」は言い過ぎ

- 判断: 対応する
- 対応内容: 「実ブラウザ固有の挙動に対する自動回帰」に限定して書き直す。

## [Suggestion] cache key に runner.os / architecture を含める

- 判断: 対応する
- 対応内容: キーを `${{ runner.os }}-${{ runner.arch }}-ms-playwright-${{ hashFiles('pnpm-lock.yaml') }}` にする。

## [Suggestion] 外部コマンド出力を無検査で `list<string>` とみなさない

- 判断: 対応する
- 対応内容: 追加する PHP Architecture テストはファイル読み取りのみで外部コマンドを起動しない。
  読み取り結果は `Assert::string()` で narrow してから走査する (既存テストと同じ作法)。
  シェル側の解析も「抽出件数が対象ブラウザ数と一致しなければ判定不能」で境界検査する。

---

## 修正後の概念設計 (全文)

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
  ログアウト後の Inertia 履歴からの PII 復元を**実ブラウザで**止める唯一の自動回帰である
  (AGENTS.md ドメイン規約 3。Feature / Architecture / JS の各テストも回帰を防いでいるので、
  「唯一の防波堤」なのは**実ブラウザ固有の挙動**についてである)。その WebKit こそが Linux で
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
レーン起動と CI の 2 経路がどちらもそこを通るようにする。**
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
- **OS の分類は 3 つ**にする。`Linux` = OS ライブラリの判定を行う /
  `Darwin` = Playwright が OS ライブラリ導入に対応しないので判定しない (ブラウザ実体だけ導入) /
  **それ以外 (Windows・MSYS 等) はサポート対象外として exit 1**。
  Playwright が OS ライブラリ導入に対応するのは Linux / Windows だけで、
  Windows 経路は当リポジトリの開発環境 (devcontainer + macOS ホスト) に存在しないため、
  中途半端に動くふりをしない。この分岐が無いと macOS では常に「判定不能」になって詰む。
- **モードは既定 (判定して導入する) と `--self-test` の 2 つだけ**にする。
  `--self-test` は判定関数を fixture で駆動する自己検査で、実資源にも node にも触れない。
  「判定だけ行う」モードは呼び出し元が 1 つも無いので持たない (思考原則 2)。

**出力解析の脆さへの備え**: 判定は Playwright CLI の出力書式に依存する。書式は安定 API ではないので、
(a) 契約テストで**実 Playwright に対する smoke** を持ち (実際に `install --dry-run` を走らせ、
解析器が対象ブラウザ 2 件の導入先を取り出せることを確認する)、乖離が `pnpm test` で赤くなるようにする。
(b) 判定不能時のメッセージに **Playwright の版**と**手で叩く確認コマンド**を出す。
fail-closed 自体は「黙って劣った導入へ落ちない」という本設計の中核なので緩めない。

**導入そのものの排他 (導入専用ロック)**: 判定はロックの外で行い、**不足しているときだけ**
`flock` で `/tmp/browser-provisioning-<uid>.lock` をブロッキング取得し、
**取得後にもう一度判定してから**導入する (二重確認)。
複数 worktree から同時に Browser レーンを起動すると、両者が
`~/.cache/ms-playwright` へ並行書き込みし、Linux では `apt-get` / `dpkg` のロックが競合するため
(dpkg は排他ロックを取るので後発が落ちる)。
**グローバルテストロックとは統合しない** — 目的 (ホスト共有資源への書き込みの排他) も粒度も違い、
「似ているから」で 1 つにしない (思考原則 4)。分けることで、数百 MB の取得中に
全テストレーンを止めずに済むという利点も保たれる。
契約テスト用に `BROWSER_PROVISION_LOCK_DIR` の override を持つ
(既存 `GLOBAL_TEST_LOCK_DIR` と同じ「self-test 専用」の作法)。

**成功条件 (仮説として明示する)**: 充足済みの環境では、このスクリプトは
(a) `sudo` を起動せず (b) `install-deps` の実処理を起動せず (c) ブラウザを再取得せず
(d) 数秒で正常終了する。これは契約テストで固定する
(スタブに呼び出しを記録させ、記録が空であることを検査する)。

**家系との意図的な差**: 先行実装 (spirux / motivation / aigenba) は `BROWSER_TEST_DEPS` に
`skip` を持ち「CI では skip を受理しない」という分岐を入れている。本リポジトリは採らない。
`GlobalTestLockInventoryTest` が示すとおり、当リポジトリには
**「CI を特別扱いしない = テストレーンの機構に `CI` 環境変数への参照を作らない」**という
明文の契約が既にある。`skip` を持たなければ `CI` 参照そのものが不要になるので、
2 値 (`auto` / `force`) に絞ることで契約と両立させる。

### 2. 事前確認 (preflight) — レーン起動そのものを導入の入口にする

`scripts/run-browser-test.sh` が、**グローバルテストロックを取得する前**に
`bash scripts/setup-browser-testing.sh` を実行する。
充足していれば数秒で何もせず抜け、不足していればその場で導入し、
権限が無ければ特権を要する経路を起こす前に導線を示して止まる。

- **ロック取得前**に置くのは、既存の bug-hunt 併走 guard とまったく同じ理由である
  (取得後に落とすと、先行レーンの終了を数分待ってから「導入されていません」と言うことになる)。
  数百 MB の取得中にグローバルテストロックを保持し続けないという意味もある。
- 呼び出しは **source ではなく子プロセス実行**にする。当リポジトリは
  「EXIT trap の所有者をライブラリ 1 箇所 (`scripts/global-test-lock.sh`) に固定する」
  という契約を契約テストで機械強制しており (C7)、レーンスクリプトが別のスクリプトを
  source すると、その契約とロック機構への `CI` 参照禁止の両方に触れうるため。
- 取得を始める前に「ブラウザ実体が未導入なので導入する」と 1 行出す
  (数百 MB の取得が無言で始まらないようにする)。

### 3. 開発環境の手順から手作業を消す

上記 2 によって、**手で `sudo pnpm exec playwright install-deps webkit` を叩く手順は消える**。
`composer test:browser` を実行すれば、必要なものが必要なときに入る。

**台帳の未追従点 3 の完了条件を読み替える**: 「devcontainer の初期化フックで自動導入する」ではなく
**「初回の Browser レーン起動時に、手作業なしで導入される」**を達成条件とする
(どちらも「手順書の文章だけ」という現状を解消するが、後者は Browser テストを使わない作業に
影響しない)。台帳へ書き戻すときはこの読み替えを明記する。

`composer.json` の `scripts.setup` には**手を入れない**。アプリ本体の初期化と
Browser テスト基盤の初期化を結合すると、Browser テストを走らせない作業でも
初期化が止まる (概念設計レビュー Round 1 の [Critical])。
Browser レーンを起動する経路と CI だけを導入の入口にする。

`docs/testing-browser.md` の §前提 は、手動 install の案内から
「初回の `composer test:browser` が自動で導入する。導入だけ先に済ませたいときは
`bash scripts/setup-browser-testing.sh`」へ書き換える。

### 4. CI: ブラウザ実体のキャッシュ

`browser-tests` job に `actions/cache@v4` で `~/.cache/ms-playwright` のキャッシュ段を足す。
キーは `${{ runner.os }}-${{ runner.arch }}-ms-playwright-${{ hashFiles('pnpm-lock.yaml') }}`
とし、**部分一致の復元キー (`restore-keys`) は持たない**
(古い版のブラウザを溜め込まないため)。
あわせて CI の導入コマンド行を、生の `pnpm exec playwright install --with-deps chromium webkit` から
`bash scripts/setup-browser-testing.sh` へ差し替える (導入の知識を 1 箇所に寄せる)。

### 5. CI: 失敗時の成果物収集

`browser-tests` job の最後に、失敗時だけ動く成果物アップロード段
(`actions/upload-artifact@v4` + step-level `if: failure()`) を足す。

ここで**設計上の落とし穴が 1 つある**。pest-plugin-browser は失敗時のスクリーンショットを
`tests/Browser/Screenshots/` に書くが、**起動時に同ディレクトリを丸ごと消す**
(vendor 実装で確認済み)。本リポジトリのレーンは Chromium → WebKit の 2 回の pest 起動なので、
**Chromium レーンの証跡は WebKit レーンの起動で消える**。
そのままアップロード段を足しても、先に失敗する側 (Chromium) の証跡は空になる。

したがって `scripts/run-browser-test.sh` が**各レーンの終了直後に**証跡を
`storage/browser-test-artifacts/<lane>/` へ退避する。CI はそのディレクトリを収集する。

順序を契約として固定する:

1. **グローバルテストロック取得後・レーンループ前**に `storage/browser-test-artifacts/` を作り直す
   (前回実行の残骸を今回の失敗としてアップロードしない。ロック取得後なので、
   並行する別実行の証跡を消すことはない)
2. レーン実行 → **終了コードを保存** → 証跡退避 → 次レーン
3. 全レーン終了後に最終判定 (どれかが失敗していれば非ゼロ)

失敗したレーンでも退避が起きること (`set -e` 相当で飛ばないこと) を契約テストで固定する。

## 期待効果

- **使命への貢献**: 撮影 PWA (iOS Safari) の回帰を守る WebKit レーンが、
  「導入されていなかった」を理由に空回りしたり全 fail したりする経路を塞ぐ。
  台本作成・撮影判断・編集を肩代わりする画面群について、
  **実ブラウザ固有の挙動**を見る自動回帰は Browser レーンにしかない。
- **失敗の原因が読めるようになる**: 「ブラウザが無い」と「テストが壊れた」を、
  ロックを取る前の 1 行のメッセージで切り分けられる。
- **CI の実行時間短縮と診断可能性**: **`pnpm-lock.yaml` が変わらない限り**
  ブラウザ実体 (数百 MB) の再取得が消える (lockfile 更新時と初回は従来どおり取得する)。
  失敗時にはスクリーンショットが残る。今は CI が赤くなっても手元で再現するしかない。
- **台帳の未追従 5 点がすべて解消**し、家系の正典 t2 形に追いつく。

## 実装方針 (概要)

| # | 変更対象 | 内容 |
|---|---|---|
| 1 | `scripts/setup-browser-testing.sh` (新規) | 導入の単一情報源。権限 / 要求 / 充足の 3 判定 + 導入専用ロック + `--self-test` (既定モードと 2 つだけ。未知オプションは拒否) |
| 2 | `scripts/run-browser-test.sh` | ロック取得前の preflight (= 自動導入)。レーン終了ごとの証跡退避 |
| 3 | (`composer.json` は触らない) | 手作業の消滅は施策 2 が担う |
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
  **allowlist の粒度 (完全一致) は維持する** — glob 化・部分一致化・正規表現化はしない。
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
- 追加する PHP Architecture テストは、走査対象と allowlist を **型付きの `const` 配列**
  (`array<string, string>` / `list<string>`) で持ち、純関数の戻り値を `list<string>` に固定する
  (`mixed` を増やさない = PHPStan level 10 を素通しできる形にする)。

## スコープ外

- **家系正典 t2 のうち「導入一元化の既定拒否 gate」の重量級版** (motivation は 2000 行超)。
  本設計は施策 8 で、`git ls-files` を母集団に「`playwright install` の記述を持つファイルが
  導入スクリプトとその契約テストに限られる」ことだけを検査する軽量版を置く。
  今必要なのは「導入経路が 2 つに増えたら落ちる」ことであって、
  YAML / Dockerfile / markdown の構造解析器を自前で持つことではない (思考原則 2)。
- **`composer setup` / `init.sh` / `.devcontainer/devcontainer.json` への接続**。
  レーン起動時の自動導入で手作業は消えるため、初期化手順を触る必要が無い
  (概念設計レビュー Round 1 の [Critical] を受けた判断)。
- **`--self-test` を CI の `php` job へ独立した段として足すこと**。
  自己検査は契約テスト経由で `frontend` job の `pnpm test` から必ず走るため、
  CI の段を増やす必要は無い。
- Browser テスト自体の追加・変更。レーンの中身は本設計の対象ではない。
- bfcache 実機受入確認 (T161 で別途着地済み) との連動。
