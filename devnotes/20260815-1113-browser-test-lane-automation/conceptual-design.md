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

#### 自前で判定するのは「OS ライブラリを入れる必要があるか」だけにする

**ブラウザ実体が入っているかどうかは Playwright 自身に判断させる。**
実測 (本 devcontainer): `pnpm exec playwright install chromium webkit` は
**充足済みなら 0.5 秒・無出力・ダウンロード無しで exit 0** する
(内部の導入完了マーカーで冪等性を持っている)。
したがって自前の充足判定は作らない (先人の知恵を探せ)。

自前で判定するのは次の 2 つだけで、満たせないときは
**特権を要する経路を起こす前に止める** (黙って劣った導入へ落ちない):

| 判定 | 何を見るか | 値 |
|---|---|---|
| 要求 | `playwright install-deps --dry-run chromium webkit` の出力と終了コード | satisfied / missing / undeterminable |
| 権限 | `id -u` が 0 か、`sudo -n true` が通るか | root / sudo / none |

決定表:

| OS | 要求 | 権限 | 動作 |
|---|---|---|---|
| Linux | missing | root / sudo | `playwright install --with-deps chromium webkit` |
| Linux | missing | none | **exit 1** (特権経路を起こす前に止める) |
| Linux | satisfied | 問わない | `playwright install chromium webkit` |
| Linux | undeterminable | 問わない | **exit 1** (fail-closed) |
| Darwin | 判定しない | 問わない | `playwright install chromium webkit` |
| その他 | — | — | **exit 1** (サポート対象外) |

- **OS の分類は 3 つ**。`Linux` = OS ライブラリの判定を行う /
  `Darwin` = Playwright が OS ライブラリ導入に対応しないので判定しない /
  **それ以外 (Windows・MSYS 等) はサポート対象外として exit 1**。
  Windows 経路は当リポジトリの開発環境 (devcontainer + macOS ホスト) に存在しないので、
  中途半端に動くふりをしない。この分岐が無いと macOS では常に「判定不能」になって詰む。
- **モードは既定 (判定して導入する) と `--self-test` の 2 つだけ**にする。
  `--self-test` は判定関数を fixture で駆動する自己検査で、実資源にも node にも触れない。
  **未知のオプションは exit 2 で拒否**する。
- **環境変数による上書きは持たない**。家系の先行実装は `BROWSER_TEST_DEPS` に
  `auto` / `skip` / `force` を持ち「CI では skip を受理しない」という分岐を入れているが、
  当リポジトリは採らない。理由は 2 つ:
  (a) 上の決定表で `auto` 以外の用途が定義できない (思考原則 2)、
  (b) `skip` を持つと「CI では受理しない」分岐が要り、
  当リポジトリの明文の契約 (テストレーンの機構は `CI` 環境変数を参照しない。
  `GlobalTestLockInventoryTest`) と衝突する。
  判定不能で詰まったときの逃げ道は環境変数ではなく、
  **メッセージが提示する手動コマンドで原因を確認する**運用にする。

#### 要求判定の根拠 (立証)

`playwright install-deps --dry-run` は、pin されている playwright 1.61.1 で
`install-deps` handler → `Registry.installDeps(targets, dryRun)` →
`installDependenciesLinux(targets, dryRun)` → `reportMissingDependenciesLinux(libs)` とつながり、
**dry-run はここで打ち切られて `apt-get install` を組み立てる特権経路には到達しない**
(vendor 実読)。`reportMissingDependenciesLinux` は `apt-get install -s` の
`^Inst ` 行 (= いま不足しているもの) を数え、0 件なら
`All system dependencies are installed.` を出して終わり、1 件以上なら
`Missing system dependencies (N):` を出して終了コードを 1 にする。
実測でも充足済み環境で `All system dependencies are installed.` / exit 0 / 1.2 秒だった。

分類は**出力文言と終了コードの両方が一致したときだけ**確定させる
(CLI 自体の異常終了と「不足あり」を混同しない)。どちらか片方でも食い違えば
`undeterminable` = exit 1 とし、メッセージに **Playwright の版**と
**手で叩く確認コマンド**を出す。

**出力書式は安定 API ではない**ので、契約テストに
**pin された実 Playwright に対する smoke** を置き、
分類器が `undeterminable` に落ちない (satisfied か missing に確定する) ことを検査する。
乖離が起きれば `pnpm test` が赤くなる。

#### 導入そのものの排他 (導入専用ロック)

`flock` で `/tmp/browser-provisioning-<uid>.lock` をブロッキング取得してから
判定と導入を行い、終わったら解放する。複数 worktree から同時に Browser レーンを起動すると、
両者が `~/.cache/ms-playwright` へ並行書き込みし、Linux では `apt-get` / `dpkg` の
ロックが競合するため (dpkg は排他ロックを取るので後発が落ちる)。
充足済みなら保持時間は 2 秒前後 (実測 0.5 秒 + 1.2 秒) なので、
判定をロック外に出す二重確認は作らない。

- **保証範囲を誇張しない**: 排他が効くのは **同一 UID かつ同一 lock ディレクトリ名前空間
  (= 同じ `/tmp` を共有するプロセス群)** だけである。別コンテナが同じ dpkg を触る構成では
  排他にならない (既存のグローバルテストロックが「同一 UID・同一マシン」と書いているのと同じ性質)。
- **グローバルテストロックとは統合しない** — 目的 (ホスト共有資源への書き込みの排他) も
  粒度も違い、「似ているから」で 1 つにしない (思考原則 4)。分けることで、
  数百 MB の取得中に全テストレーンを止めずに済むという利点も保たれる。
- 契約テスト用に `BROWSER_PROVISION_LOCK_DIR` の override を持つ。
  `GLOBAL_TEST_LOCK_DIR` と同じ扱いで、**スクリプト自身がこの変数へ代入しないこと**を
  契約テストの静的検査で固定する (抜け道にしない)。

**成功条件 (仮説として明示する)**: 充足済みの環境では、このスクリプトは
(a) `sudo` を起動せず (b) `--with-deps` を付けず (c) ブラウザを再取得せず (d) 正常終了する。
スタブに呼び出しを記録させる契約テストで固定する。
**実行時間 (実測 2 秒前後) は観測値であって契約にしない** —
時間の assertion は環境差で偽赤になる。

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
- 開始時に「ブラウザ実体と OS ライブラリを確認します」と 1 行出す。
  **「未導入なので導入する」とは書かない** — 事前の充足判定を持たない設計なので、
  実際に取得が起きるかどうかはこの時点で確定していない (断定しない)。
  取得が実際に始まれば Playwright 自身が進捗を出す。

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
| 1 | `scripts/setup-browser-testing.sh` (新規) | 導入の単一情報源。要求 / 権限の 2 判定 + 導入専用ロック + `--self-test` (モードは既定と自己検査の 2 つだけ。未知オプションは拒否) |
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

## 台帳 (lctl) への書き戻し

実装後に `browser-test-lane` へ `status_reported` と `handover` を追記する。
**「既存の要件をそのまま達成した」とは書かない**。次の 2 点を申し送りとして残す:

1. 未追従点 3 の完了条件を「devcontainer 初期化で自動導入」から
   **「初回の Browser レーン起動時に手作業なしで導入される」**へ読み替えたこと、およびその理由
   (アプリ本体の初期化と Browser テスト基盤の初期化を結合しないため)。
2. `playwright install` は**充足済みなら 0.5 秒・無出力・ダウンロード無しで終わる**ので、
   自前の充足判定 (`install --dry-run` の出力解析) は要らないこと。
   家系の先行実装はこの解析を持っているが、
   `install --dry-run` が列挙するのは対象ブラウザ数ではなく**実行可能ファイル数**
   (chromium / ffmpeg / chromium_headless_shell / webkit の 4 件) なので、
   件数を対象ブラウザ数と突き合わせる検査は誤りである。

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
