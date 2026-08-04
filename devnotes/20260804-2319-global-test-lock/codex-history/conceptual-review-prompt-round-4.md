# Round 4: Round 3 指摘への対応

Round 3 の [Critical] 2 件・[Warning] 4 件すべてに対応しました。

- シグナル時のロック保持: 「保持期間 = 取得〜**子の終了後**」を契約化し、
  シグナル転送 → 子の待機 → sidecar 削除 → 解放 → 自死の順序を固定しました。
- bug-hunt 排他: TOCTOU の指摘を受け入れ、**非干渉保証を撤回**しました。
  ご提示の 2 案のうち後者 (best-effort へ格下げ + 保証撤回) を採り、
  スコープと保証を一致させています。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 3

## [Critical] INT/TERM 受信時の子プロセス処理が未定義 — 親だけ死ぬとロックが先に解放される
- 判断: **対応する**
- 根拠: 完全に正しい。fd 7 を子へ渡さない設計にしたことの裏返しで、
  子はロックを保持していない。親が先に自死するとロックだけ解放され、
  **旧レーンの子と次のレーンが同時に走る** = 排他が破れる。
  ロックの保持期間を「親の生存期間」と暗黙に考えていたのが誤りだった。
- 対応内容: 改善アイデアに項目 5 を新設し、シグナル時の順序を契約として固定:
  (1) 子 (またはその専用プロセスグループ) へ同シグナルを転送 → (2) 子の終了を待つ →
  (3) nonce 一致を確認して sidecar 削除 → (4) fd 7 を閉じてロック解放 →
  (5) trap 解除後、親も同シグナルで自死。
  併せて「**ロックの保持期間は取得〜子の終了後であり、親の生存期間ではない**」を明文化した。
  層 1 の検証項目に、ご提案どおり
  「INT/TERM 中も子が終了するまで第三のレーンが取得できない」
  「終了後に背景子が残らない」「親の終了コードが 128+signo」を追加。
  負のコントロールにも「INT/TERM で親が先に死に、子が生きたままロックが解放される」を追加した。

## [Critical] bug-hunt ポート listen 確認は TOCTOU があり機械的排他にならない
- 判断: **対応する (保証を撤回し、スコープと保証を一致させる)**
- 根拠: 正しい。「未使用を確認 → bug-hunt が listen 開始 → Browser lane 開始」の窓が開いており、
  さらに bug-hunt の起動フェーズによっては活動中でも listen していない瞬間がある。
  これを「機械的排他」「危険な経路を塞ぐ」と書いたのは誤り。
  提示された 2 案のうち、**後者 (best-effort へ格下げ + 非干渉保証の撤回)** を採る。
  前者 (両側参加の共有 interlock) は、bug-hunt の orchestrator と N 体の subagent worker に
  またがる security-sensitive なスクリプト (`env -i` ガード群・self-test 多数) の改造を意味し、
  「今必要なものだけ作る」に対して過大である。失敗モードが**偽赤に留まり偽グリーンにならない**ため、
  保証を降ろす方が費用対効果に見合うと判断した。
- 対応内容:
  - 方向 2 の記述を「**非干渉は保証しない (保証を撤回する)**」に書き換え、
    共有プロトコルが必要であること・それを採らない理由を明記。
  - ポート検査は **best-effort の pre-flight guard** へ格下げ。
    「起動時点で bug-hunt が既に走っている」という頻度の高いケースだけを捕まえること、
    **TOCTOU があり Browser lane 開始後の bug-hunt 起動・非 listen フェーズは捕まえられない**ことを
    設計本文に明記した。
  - 残余リスク節を「bug-hunt 併走問題は全体として残余リスクとして受容する」に統合し、
    受容根拠を「**失敗モードが偽赤であり偽グリーンではない / 沈黙して誤った緑を出す経路は無い**」に明確化。
  - スコープ外に「**bug-hunt 併走時の非干渉保証**」を明示追加し、
    将来の共有 interlock は選択肢として記録するに留めた。
  - 期待効果・限界宣言の列挙にも bug-hunt を加え、記述の整合を取った。

## [Warning] bug-hunt をスコープ外としながら機械的排他を保証するスコープ設定は成立しない
- 判断: **対応する** (上記 Critical と同一対応)
- 根拠: 正しい。保証とスコープのどちらかを合わせる必要があるという指摘に従い、保証の側を降ろした。
- 対応内容: 上記のとおり、bug-hunt 併走問題全体を残余リスクとして明示し、
  スコープ外リストにも非干渉保証を追加した。

## [Warning] ポート listen 検知に必要なコマンドと対応環境が前提に無い
- 判断: **対応する**
- 根拠: 妥当。`ss` / `lsof` / `netstat` は可用性も出力形式も異なり、暗黙依存になっていた。
- 対応内容: 検知手段を **bash の `/dev/tcp` のみ**に固定した (外部コマンド依存ゼロ)。
  bug-hunt は `127.0.0.1:801N` に明示 bind するため **IPv4 loopback のみ**を対象とし、
  IPv6 は対象外と明記。`/dev/tcp` が使えないシェル環境では**検査を skip して続行**する
  (guard であって保証ではないため、ここで止めない)。これらを設計本文に書いた。

## [Warning] `tests/` に手を入れないという制約と Architecture テスト追加が矛盾
- 判断: **対応する**
- 根拠: 正しい。Round 2 で層 2 (Architecture テスト) を追加した際に、制約節を更新し忘れていた。
- 対応内容: 制約を「**既存のドメインテスト・DB テストには手を入れない** (新規 Architecture テストの
  追加は行う。これは禁止事項 1 への正しい対応であり矛盾しない)」に修正した。

## [Warning] UID 接尾辞の説明が前半と後半で矛盾
- 判断: **対応する**
- 根拠: 正しい。前半で「先取りを塞ぐ」と書き、後半で「接尾辞だけでは防げない」と訂正しており、
  読み手が根拠を取り違える。
- 対応内容: 前半の根拠を「**ユーザー間の通常運用上の衝突を分離する**」に限定し、
  悪意・事故によるパス先取りは **0700 ディレクトリの所有者・種別・mode 検証で fail-secure に検出する**
  と整理した。

## [Suggestion] 使命への位置づけ / H1・H2・H3 の効果と保証限界の限定
- 判断: **維持** (変更なし)


---

## 改訂後の概念設計 (全文)

# 概念設計: global-test-lock (テストレーンのグローバルロック)

- 出自: c2c 機能台帳 `global-test-lock` (origin: spirux:T1109/T1110、2026-08-04 オーナー裁定でテンプレ昇格承認済み)
- aicue の台帳ステータス: `reviewing` → 本設計で移植する

## 背景・課題

aicue の実装は必ず worktree (`.claude/worktrees/tasks/<task-id>`) で行う (AGENTS.md §worktree 運用ルール)。
複数の Claude セッションが worktree を並行運用するため、**同一マシン上で複数のテストレーンが同時に走る**
のが常態になっている。

### 実測した現状 (2026-08-04 時点)

| lane | エントリポイント | 排他機構 | スコープ | 取得方式 |
|---|---|---|---|---|
| Feature/Unit/Architecture | `composer test` → `scripts/run-test.sh` | flock fd 9 | `storage/framework/testing/test.lock` = **worktree-local** | `flock -n` (非ブロッキング・即 exit 1) |
| Browser | `composer test:browser` → `scripts/run-browser-test.sh` | flock fd 9 | 同上 (Feature lane と共有) | `flock -n` |
| JS (root) | `pnpm test` → `scripts/run-vitest.sh` | flock fd 9 | `${TMPDIR}/app-vitest-<sha256(WORKSPACE)>.lock` = **worktree ごとに別 key** | `flock -n` |
| JS (packages) | `pnpm test:packages` → `pnpm -F "./packages/*" test` | **なし** | — | — |

つまり **cross-worktree 排他はゼロ**であり、加えて全レーンが `flock -n` (待たずに即エラー終了) である。

### 何が本当に壊れるのか (思い込みでなく実査した結果)

「共有 PostgreSQL テスト DB を取り合う」という前提を検証した結果、**DB 名レベルの衝突は起きない**ことが分かった:
`Tests\Support\Ci\TestDatabaseEnv::pgsqlBaseDatabase()` が base 名を
`<slug>_test_<sha1(realpath(worktree))[0:8]>` で導出し、paratest が更に `_test_<token>` を付す
(`scripts/ci/ensure-test-db.php` / `drop-test-db.php` も同じ base に閉じている)。
したがって「DB 名の取り合い」は aicue には存在しない。**設計を建てる前にここを訂正しておく。**

実在するハザードは以下の 4 つで、**いずれも作用域はマシン全体 (正確には同一 UID) であり、
worktree でもクローンでもない**。この事実がロックのスコープを決める。

- **H1 (証明済み・破壊的): Browser lane の playwright 掃除がマシン全体スコープ**
  `scripts/run-browser-test.sh` の `cleanup_orphan_playwright()` は
  `pgrep -f "playwright/cli.js run-server"` で**マシン全体**を走査し、PPID=1 のものを kill する
  (起動時 + EXIT trap の 2 回)。worktree A が Browser lane を走らせている最中に
  B が Browser lane を起動すると、プラグイン側の後始末漏れで PPID=1 に再親付けされた
  A の run-server を B が巻き込んで殺す。worktree-local flock はこれを一切防げない。
  **実際に kill が通るのは同一 UID のプロセスのみ**なので、この破壊の作用域は「同一ユーザーのマシン全体」。

- **H2: PostgreSQL サーバという単一共有資源**
  DB 名は分かれても PostgreSQL インスタンスは 1 つ。`artisan test --parallel --processes=4` が
  worktree ごとに 4 本走り、それぞれが per-worker DB の CREATE + `migrate:fresh` を
  全マイグレーションに対して行う。接続数・IO・`pg_database` へのロックが積算され、
  遅延とタイムアウト由来の flake になる。作用域は PostgreSQL インスタンス = マシン全体。

- **H3: devcontainer の CPU / メモリ枯渇**
  Browser lane は Chromium + WebKit の 2 レーン契約 (`docs/testing-browser.md`、非交渉) で
  `memory_limit=1G` + 実ブラウザを起動する。ここに他 worktree の paratest 4 本と vitest
  (`maxWorkers: "50%"`) が重なるとタイムアウト由来の**偽赤**が出る。
  aicue は「緑を赤と誤報告する = レーンの信頼性が失われる」ことを既に非交渉の基準としている
  (run-browser-test.sh が `--parallel --processes=1` を既定にしている理由がまさにこれ)。
  作用域はマシン全体。

- **H4 (運用): `flock -n` が待たずに死ぬ**
  並列実装の**待ち合わせ**が目的なのに、後発は即 exit 1 になる。エージェントは
  「ロックに阻まれた」を失敗と解釈してリトライループを回すか、レーンを迂回する。
  排他機構が守るべき挙動を、機構自身が壊している。

## 改善アイデア

**「テストレーンは同一ユーザー・同一マシンで常に 1 本だけ」を単一のグローバルロックで保証し、
既存の worktree-local flock は同じ変更で削除する。**

1. `scripts/global-test-lock.sh` (source されるライブラリ) と
   `scripts/with-global-test-lock.sh` (exec ラッパ) を新設する。
2. ロックは **ブロッキング取得**。待っている間だけ 30 秒ごとに heartbeat を stderr に出す
   (LLM エージェントが「ハングした」と誤判断してプロセスを kill する事故を防ぐ)。
   heartbeat は**保持者の身元 (pid / 開始時刻 / lane / worktree)** を必ず含める。
3. **owner 再入ガード**で、ロック保持プロセスの子孫から再度呼ばれても deadlock しない。
4. ロック fd をテスト実行コマンドに**継承させない** (orphan 化した子が fd を握り続けて
   ロックが永久に解放されない事故を防ぐ。現 `run-test.sh` が `9>&-` で対処している問題と同種)。
   ただし「fd を子へ渡さない」と「コマンド実行中もロックを保持する」を両立させるには、
   **fd 7 を保持したままの親シェルが必要**である。したがって
   **ロック配下では `exec` を使わない** — 親が `"$@" 7>&-` で子を起動し、
   終了を待って終了コードをそのまま返す (`set -e` 下でも取りこぼさない制御構造にする)。
   `exec cmd 7>&-` はシェル自身を置換して fd 7 を閉じるため、テスト開始と同時に
   ロックが解放されてしまう (排他が成立しない)。これを設計の不変条件として固定する。
5. **シグナル受信時もロックは子の終了まで保持する**。子は fd 7 を持たないため、
   親が先に死ぬとロックだけ解放されて**旧レーンの子と次のレーンが同時に走る**。
   したがって INT / TERM 受信時の順序を契約として固定する:
   (1) 子 (またはその専用プロセスグループ) へ同じシグナルを転送 →
   (2) 子の終了を待つ → (3) nonce 一致を確認して sidecar を削除 →
   (4) fd 7 を閉じてロックを解放 → (5) trap 解除後、親も同シグナルで自死する。
   ロックの保持期間は「取得 〜 **子の終了後**」であり、親の生存期間ではない。
6. 4 レーン (`composer test` / `composer test:browser` / `pnpm test` / `pnpm test:packages`) を対象にする。
7. **worktree-local flock は残さず削除する** (後述)。

### aicue 固有の判断 1: スコープは「マシン全体 × UID 単位」、名前は slug 非依存の固定名

移植元は `/tmp/spirux-global-test.lock` という**マシン全体の固定名**である。aicue も
**マシン全体スコープを採る**が、名前は spirux 名も aicue 名も使わない。

- **なぜマシン全体か**: H1〜H3 の作用域が全てマシン全体だから。
  ロックの作用域は、守るべき資源の作用域と一致していなければならない。
  クローン単位 (`git rev-parse --git-common-dir` 由来のハッシュ等) に狭めると、
  同一マシン上の別クローンが H1 の kill と H2 の PostgreSQL 競合を素通りさせるため、
  対策の作用域が原因の作用域より狭くなり、H1 を「構造的に消した」と言えなくなる。
- **なぜ UID 単位か**: `kill` が実際に通るのは同一 UID のプロセスのみ = H1 の破壊半径は
  「同一 UID のマシン全体」で、それより広げても得るものがない。
  UID 接尾辞の役割は**ユーザー間の通常運用上の衝突を分離する**ことに限られる。
  悪意・事故によるパス先取りは接尾辞では防げないため、
  0700 ディレクトリの所有者・種別・mode 検証で **fail-secure に検出**する (後述)。
- **なぜ slug を名前に入れないか**: aicue は laravel-claude-template 派生であり、
  `AppNameHardcodeTest` が `scripts/` へのアプリ slug 直書きを禁じている。かつ既定 slug は `app`
  なので、slug 由来にすると派生アプリ間で `app-...lock` に化けて意図しない名前衝突を招く。
  そもそも**このロックは repo をまたいで共有されて正しい** (同一マシンの PostgreSQL と CPU は
  repo をまたいで 1 つ) ため、repo 識別子を名前に入れる動機自体がない。

→ **`/tmp/global-test-lane-<uid>.d/lock`** (ディレクトリを mode 0700 で作り、その中にロックファイルを置く)。
テンプレートから派生した全アプリが同じパスを共有し、同一ユーザーのテストレーンは常に 1 本になる。

基点を `${TMPDIR:-/tmp}` ではなく **`/tmp` に固定**する。`TMPDIR` はプロセスごとに異なりうるため、
基点に使うと同一 UID でもロックが分裂し「マシン全体」の保証が崩れる。
また、UID 接尾辞だけでは別ユーザーによる**予測可能パスの先取り**を防げないため、
`/tmp/global-test-lane-<uid>.d/` を 0700 で作成した直後に
**シンボリックリンクでないこと・ディレクトリであること・所有者が自分であること・mode が 0700 であること**
を検証し、1 つでも満たさなければ**明示エラーで停止する** (黙って排他なしに落ちるのは偽の安全になるため)。

検証スイート専用に `GLOBAL_TEST_LOCK_DIR` による基点上書きを設ける (自分自身のロックと衝突せずに
並行挙動を検証するために必須)。上書き時は stderr に警告を 1 行出し、
**lane スクリプトがこの変数を設定していないこと**を Architecture テストで deny-by-default に固定する。

> 実運用上、aicue は devcontainer 1 コンテナ = 1 クローン + その worktree 群なので、
> 「マシン全体 × UID」は事実上「このクローンの全 worktree」と一致する。
> マシン全体を選ぶコストはゼロで、bare-metal / 共有ホストのケースだけを追加で守る。

### aicue 固有の判断 2: worktree-local flock を残さない

移植元の boundary は「含まない: worktree-local flock (各 lane feature 側)」= 二重ロックを残す形だが、
aicue では**削除する**。グローバルロックのスコープ (同一 UID のマシン全体) は
worktree-local ロックのスコープ (単一 worktree) を**厳密に包含**するため、内側のロックは
1 つも新しい事象を防がない。残せば AGENTS.md 思考原則 3「後方互換の並走を残さない」に反し、
かつ有害な `flock -n` (H4) をそのまま温存することになる。

ただし削除が安全なのは「**公式 entrypoint を全て確実に包めている場合**」に限る。
そこで検証スイートに **lane inventory の deny-by-default 検査**を入れる:
`composer.json` / `package.json` のテストレーン相当スクリプトを機械的に列挙し、
グローバルロックを経由しないものが 1 つでもあれば fail させる。
新しいテストレーンが追加されたら検証が落ちて気づける。

テンプレート正典との意図的な差分として `docs/template-divergence.md` に記録する。

### aicue 固有の判断 3: heartbeat は「待機中のみ」+ 保持者の身元を出す

移植元は 30 秒 heartbeat を要件としている。その目的は「無出力の待機をエージェントがハングと誤認するのを防ぐ」
ことであり、**ロック保持中はテストランナー自身が出力する**ので heartbeat は不要である。
待機中のみに限定すると、非競合時 (CI や単独実行) の出力は完全に 0 行になり、CI ログを汚さない。

無期限ブロッキングを採る以上、**「詰まっている」と「壊れて永久に待っている」の切り分け**が
できなければならない。そこでロック取得直後に sidecar (`<lock>.owner`) へ
`nonce / owner pid / 取得時刻 / lane 名 / worktree パス` を書き、解放時に削除する。
待機側の heartbeat はこれを読んで
`waiting 90s for global test lane lock — held by pid 1234 (composer test:browser, .../tasks/T101) since 23:41:02`
の形で出す。sidecar の役割は正確には「**排他の正本ではないが、再入判定の正本**」である (判断 4)。
排他そのものの正本は flock 一点に保つ。sidecar には次の不変条件を課す:
同一ディレクトリ内の一時ファイルへ書いてから `mv` する**アトミック書き込み**、
読み取り時の**所有者・パーミッション検証**、そして**自分の nonce と一致するときだけ削除**。
手動復旧手順は `docs/testing-browser.md` の runbook 節に書く。

### aicue 固有の判断 4: 再入ガードは「nonce 一致」で成立させる

単なる env フラグでの再入許可は、「ロックを実際には保持していない子孫」が素通りする穴になる
(保持者が終了した後も背景化した子孫は env を持ち続ける)。
そこで判断 3 の sidecar を再利用し、**再入が許されるのは
「env で受け取った nonce が、現に存在する sidecar の nonce と一致するとき」だけ**とする。
nonce は取得のたびに新規生成され、sidecar は保持中しか存在しない。
保持者が解放すれば sidecar は消え、次の保持者は別 nonce を書くため、
生き残った子孫の stale nonce は一致せず、正しくブロッキング取得に回る。
PID 意味論に依存しないので PID 再利用の穴もない。

## 対象 lane の棚卸し (composer.json / package.json を実査)

| lane | コマンド | 掴む資源 | 対象 | 理由 |
|---|---|---|---|---|
| Feature/Unit/Architecture | `composer test` | pgsql サーバ (paratest 4 本 × migrate:fresh)、CPU | **対象** | H2 / H3 / H4 |
| Browser | `composer test:browser` | pgsql サーバ、実ブラウザ 2 engine、in-process HTTP サーバ、**マシン全体の playwright 掃除** | **対象** | H1 / H2 / H3 |
| JS (root) | `pnpm test` | CPU (`maxWorkers: "50%"`)、jsdom メモリ。DB / 固定ポートは掴まない | **対象 (方針判断)** | H3。DB は掴まないが、Browser lane と同時に走ると CPU 枯渇でタイムアウト由来の偽赤を作る |
| JS (packages) | `pnpm test:packages` | CPU のみ (loopback は ephemeral port、fs は `mkdtemp` で hermetic — 実査済み) | **対象 (方針判断)** | H3。かつ**現在ロックが一切ない**唯一のレーンで、ここだけ穴を残す理由がない |

- `composer phpstan` / `pnpm lint` / `pnpm typecheck` / `pnpm build` は**対象外**
  (テスト DB もブラウザも掴まない。直列化しても得るものがない)。
- `packages/cli` は vitest 1 本のみ (`build` / `typecheck` / `lint` はテストレーンではない)。

### JS 2 レーンを含める判断の成功条件と見直し条件

JS レーンの包含は**安全性ではなく方針判断**である (DB もポートも掴まないため、
含めなくても壊れはしない)。採る理由は 2 つ:

1. H3 — 軽い JS レーンでも Browser lane と重なれば CPU を奪い、偽赤を作りうる。
2. 「どのレーンなら同時に走らせてよいか」をエージェントに判断させない。
   ルールが 1 つ (テストレーンは常に 1 本) であること自体に運用価値がある。

- **成功条件**: 4 レーンをコミットゲートとして通す 1 巡の総時間が、直列化前の
  「衝突込みの実効時間 (リトライ・偽赤の再走を含む)」を上回らないこと。
- **見直し条件**: 待ち時間が支配的になった (JS レーンが Browser lane 2 本の後ろで
  恒常的に数分待つ) と観測されたら、**lock class の分離** (DB/ブラウザ資源クラスと
  CPU のみクラス) を再検討する。今それを作らないのは「今必要なものだけ作る」に従うため。

## bug-hunt の扱い: **対象外** (明示的判断)

`scripts/bug-hunt-shard.sh` の隔離基盤 (DB `bug_hunt(_1..8)` / `:8010+N` / `env -i` の用途別 wrapper) は
グローバルロックの**対象にしない**。

1. **資源が構造的に交わらない**。DB 名前空間は `bug_hunt(_[1-8])?` の正規表現で hard-deny ガードされ、
   テストレーンの `app_test_<hash>` とは重ならない。ポートは `:8010..:8018` の固定割当で、
   テストレーンは in-process サーバ / ephemeral port しか使わない。
2. **bug-hunt 自身が排他機構を持つ**。shard i ↔ (DB, port, レポート dir) の 1:1 割当と
   orchestrator/worker の default-deny ガード、`.claude/bug-hunt.lock` の flock が並列安全性を担う。
3. **グローバルロックを被せると 8 並列が 1 直列に潰れ、機能の存在意義が消える**
   (AGENTS.md §bug-hunt が「意図的に隔離された並列実行基盤」と明記している)。
   bug-hunt の 1 run は数十分オーダーで、その間コミットゲートを全面停止させる副作用も釣り合わない。
4. **抜け穴は無い**: bug-hunt worktree の中で `composer test` を打てば、それは通常どおり
   `run-test.sh` 経由でグローバルロックを取る。除外するのは bug-hunt の shard 走行のみ。

### bug-hunt 併走時の残余リスク (受容する / 1 つだけ検証で固定する)

- **bug-hunt 併走問題は全体として残余リスクとして受容する**。bug-hunt はロック規約に
  参加しないため、非干渉も性能も保証されない:
  - Feature / JS レーンとの CPU / PostgreSQL 競合による性能劣化。
  - Browser lane との browser 回収の相互干渉 (方向 2)。
  受容できる根拠は**失敗モードが偽赤 (テストがエラーになる) であり、偽グリーンではない**こと。
  緑を赤と誤報告するのは aicue の非交渉基準に反するが、bug-hunt を同時に起動するのは
  エージェントの明示的な操作であり、pre-flight guard が典型ケースを捕まえた上で
  なお併走させた場合に限られる。**沈黙して誤った緑を出す経路は無い**。
- **ブラウザ回収の相互干渉 (方向ごとに扱いを分ける)**:
  Browser lane は `pgrep -f "playwright/cli.js run-server"` (pest-plugin-browser 同梱 Playwright) を、
  bug-hunt は `playwright-cli kill-all` (`@playwright/cli`) を撃つ。
  **「プロセス名パターンが互いにマッチしないこと」を検証しても非干渉の証明にはならない**
  (`kill-all` が何を列挙して落とすかは、こちらの `pgrep` パターンとは独立に決まる。
  当該環境に `@playwright/cli` は未導入で、実装契約をこちらから確認できない)。
  したがって方向ごとに扱いを分ける:
  - **方向 1 (Browser lane → bug-hunt)**: こちらが制御できる。
    `cleanup_orphan_playwright()` のパターンを
    **pest-plugin-browser 同梱 Playwright の install パスに固定**する
    (`node_modules/playwright*/cli.js run-server` に相当する形へ限定し、
    `@playwright/cli` のプロセスに構造的にマッチしないようにする)。
    検証スイートで「`@playwright/cli` 相当の cmdline がこのパターンに掛からないこと」を
    負のコントロールとして固定する。
  - **方向 2 (bug-hunt → Browser lane)**: **非干渉は保証しない (保証を撤回する)**。
    保証するには両側が参加する共有プロトコル (bug-hunt が活動期間中に同ロックを
    共有モードで保持し、Browser lane が排他取得する) が必要で、
    それは bug-hunt 側 — orchestrator と N 体の subagent worker にまたがる
    security-sensitive なスクリプト — の改造を意味する。
    本件のスコープに対して過大であり、**保証とスコープを一致させるために保証の側を降ろす**。
    残るのは **best-effort の pre-flight guard** である:
    `run-browser-test.sh` の起動時に bughunt ポート (`127.0.0.1:8010..8018`) への
    接続可否を調べ、繋がるものがあれば明確な指示つきで fail-fast する。
    - **TOCTOU がある**ことを設計として明記する。「起動時点で bug-hunt が既に走っている」
      という**実際に起きる頻度の高いケース**だけを捕まえる。
      Browser lane 開始後に bug-hunt が起動する経路、および bug-hunt が
      listen していない起動フェーズにいる経路は捕まえられない。
    - **検知手段は bash の `/dev/tcp` のみ**を使う (`ss` / `lsof` / `netstat` の
      可用性と出力形式の差に依存しない)。bug-hunt は `127.0.0.1:801N` に明示 bind するため
      **IPv4 loopback のみ**を見れば十分で、IPv6 は対象外とする。
      `/dev/tcp` が使えないシェル環境では**検査を skip して続行**する
      (guard であって保証ではないので、ここで止めない)。
    - 将来 bug-hunt 側に共有 interlock を入れる選択肢は残す。採らない理由
      (スコープ過大 / 失敗モードが偽赤に留まる) を記録として残す。
- **(スコープ外の観測)** bug-hunt 自身の `.claude/bug-hunt.lock` は worktree-local なので、
  別 worktree からの bug-hunt 同時起動は `playwright-cli kill-all` で相互破壊しうる。
  本設計の対象外だが、同種の課題として `docs/template-divergence.md` に観測を残す。

## CI の扱い: **特別扱いしない** (ロックは掛かるが常に無競合)

`.github/workflows/ci.yml` は `php` / `frontend` の 2 job で、それぞれ独立した ubuntu-latest コンテナ、
1 コンテナ 1 テスト実行、worktree なし、`/tmp` は新品。したがってロックは**必ず即座に取得でき、
実質 no-op** である。

- **`CI=true` によるバイパス分岐を作らない**。バイパスは「正しさが最も要求される場所に、
  ローカルでは一度も実行されないコードパス」を増やす。単一経路にしておけば、
  CI が検証しているものと開発者が走らせるものが同一になる。
- 判断 3 (heartbeat は待機中のみ) により、CI では heartbeat が 1 行も出ない。
- コストは `flock` システムコール 1 回。有害性なし。

## flock(1) 不在環境 (素の macOS) の方針: **既存方針を踏襲 (排他なしで実行)**

既存 3 スクリプトはいずれも `command -v flock` で分岐し、不在なら排他せず実行する。
グローバルロックも同じにする。ただし現状は完全に無言で skip するため、
**stderr に 1 行の警告を出す**ようにする (挙動は変えず、保護が効いていないことを可視化する)。
aicue の一次開発環境は devcontainer (util-linux 2.41 の flock を確認済み) と CI (ubuntu) であり、
どちらでも排他は有効。

## 期待効果

- **使命への貢献**: aicue の使命 (SOP → シナリオ → ナビ撮影 → 動画) を実現する速度は、
  複数 worktree の並行実装が壊れずに回るかに直結する。テストレーンの相互破壊と偽赤は、
  エージェントに「存在しないバグの調査」をさせて実装スループットを直接削る。
- **H1 が構造的に消える (本ロック規約に参加するテストレーン間に限る)**:
  掃除の破壊半径 (同一 UID のマシン全体) とロックの作用域が一致するため、
  Browser lane 同士が同時に存在しなくなる。
  ただし同一 UID の**別ツール・本規約を未移植のリポジトリ・bug-hunt** は同じロックを取らないため、
  それらからの Playwright プロセスへの干渉は防げない (best-effort guard のみ)。
- **H2 / H3 由来の flake が、テストレーン同士の競合分については解消する**
  (bug-hunt 併走・他ユーザー・flock 不在ホストは残余リスクとして残る)。
- **H4 が消える**: 後発レーンは「失敗」ではなく「待機」になる。エージェントのリトライループと
  レーン迂回がなくなる。
- ロック機構が 3 種類 (worktree-local test.lock / vitest workspace lock / なし) から **1 種類**になる。

> 主張の限界を明示する: 本設計が保証するのは
> 「**本ロック規約を採用したテストレーンが、同一 UID のマシン上で同時に 2 本走らない**」
> ことだけである。「flake がゼロになる」「赤は必ず本物」とは主張しない。
> 規約に参加しないプロセス (未移植リポジトリ、手打ちの `vendor/bin/pest`、bug-hunt、他ツール) は対象外。

## 実装方針 (概要)

| 変更対象 | 変更内容 |
|---|---|
| `scripts/global-test-lock.sh` (新規) | source されるライブラリ。lock path 導出 → nonce 再入判定 → ブロッキング取得 (待機中のみ heartbeat) → sidecar 書込 → EXIT/INT/TERM trap で解放。fd 7 を使う (既存 lane の fd 9 と衝突させない)。実装不変条件: `set -euo pipefail` 前提 / 厳格 quoting / **cleanup の冪等化** (INT・TERM 処理後の EXIT で二重実行しても安全。sidecar は自分の nonce と一致するときだけ削除) / INT・TERM は **trap を解除してから同シグナルで自死**し終了コード契約 (128+signo) を守る / **`exec` 禁止 (fd 7 保持)** |
| `scripts/with-global-test-lock.sh` (新規) | 上記を source し、**`exec` せず** `"$@" 7>&-` で子を起動して待ち、終了コードを引き継ぐ薄いラッパ。ラップ用のシェルスクリプトを持たない `pnpm test:packages` 用 |
| `scripts/run-test.sh` | worktree-local flock ブロック (L16-25) を削除 → `source scripts/global-test-lock.sh`。実行行の `9>&-` を `7>&-` に |
| `scripts/run-browser-test.sh` | 同上 (L43-52 削除)。pest 実行の `9>&-` を `7>&-` に |
| `scripts/run-vitest.sh` | workspace-hash flock ブロック (L13-27) を削除 → source。**既存の `exec` を廃止**し `pnpm exec vitest run "$@" 7>&-` + 終了コード引き継ぎ |
| `package.json` | `test:packages` を `with-global-test-lock.sh` 経由に |
| `docs/testing-browser.md` / `docs/worktree-isolation-strategy.md` / `scripts/README.md` | ロックの説明を更新 (worktree-local flock の記述は削除) + 手動復旧 runbook |
| `docs/template-divergence.md` | 正典 boundary との差分 (worktree-local flock を残さない / 固定名の付け方 / heartbeat は待機中のみ / 再入は nonce) を記録 |
| `scripts/verify-global-test-lock.sh` (新規・恒久) | 並行挙動の検証スイート (ブロッキング / heartbeat / 再入 / fd 非継承 / 解放 / 終了コード / flock 不在)。`scripts/README.md` の台帳に追記する |
| `tests/Architecture/GlobalTestLockInventoryTest.php` (新規) | 構造的不変条件を恒久固定する Pest Architecture テスト (下記) |
| `.github/workflows/ci.yml` | `php` job に `bash scripts/verify-global-test-lock.sh` のステップを 1 つ追加 (並行挙動の恒久ゲート) |

## 検証の恒久化とテストファースト方針

AGENTS.md 禁止事項 1 は「不変条件は対応する Architecture/Feature テストへの登録まで含めて実装済み」
と定めている。`devnotes/` は恒久的な回帰境界ではない (消えても誰も気づけない) ため、
検証は**恒久資産として 2 層**に置く。

**層 1 — 並行挙動の検証スイート `scripts/verify-global-test-lock.sh` (恒久・`scripts/README.md` 台帳に登録)**
ブロッキング待機・heartbeat・再入・fd 非継承・保持期間・解放・終了コード・flock 不在に加え、
**シグナル契約**を検証する:
- INT / TERM を親に送ったとき、**子が終了するまで第三のレーンがロックを取得できない**こと
- 終了後に**背景に子プロセスが残らない**こと
- 親の終了コードが 128+signo になること

いずれも**プロセスを実際に走らせないと観測できない**性質である。
自分自身の実ロックと衝突しないよう、スイートは常に `GLOBAL_TEST_LOCK_DIR` を `mktemp -d` に向けて走る。
CI (`php` job) に 1 ステップ追加して恒久ゲート化する。

**層 2 — 構造的不変条件の Architecture テスト `tests/Architecture/GlobalTestLockInventoryTest.php` (Pest)**
ファイル読み取りだけで判定できる不変条件を deny-by-default で固定する:
1. `composer.json` / `package.json` のテストレーン相当スクリプトが**全て**グローバルロックを経由すること
   (新レーン追加時に落ちる)
2. 旧 worktree-local flock (`storage/framework/testing/test.lock` / `app-vitest-*.lock`) が
   どのスクリプトにも残っていないこと
3. `scripts/verify-global-test-lock.sh` が存在し実行可能であること
4. lane スクリプトが `GLOBAL_TEST_LOCK_DIR` を設定していないこと (自己バイパス禁止)
5. ロック配下で `exec` を使っていないこと (fd 7 保持の不変条件)

> **層 2 が層 1 を実行してはならない**: Architecture テストは `composer test` の内側
> = グローバルロック保持中に走る。そこから並行挙動スイートを起動すると自分自身と競合する。
> 層の分離はこの理由により非交渉とする。

**テストファースト**: 層 1・層 2 を先に書き、**未変更ツリーに対して実行して fail を観測してから**
実装に入る (AGENTS.md 思考原則 5)。未変更ツリーで確実に落ちる負のコントロール:

- 別 worktree からの 2 本目の lane が**待機せず即エラーになる** (H4 / cross-worktree 排他ゼロ)
- 再入時に deadlock する / 再入ガードが存在しない
- ロック fd がテスト実行コマンドに継承される
- `exec` によりコマンド実行中にロックが解放されている (run-vitest.sh)
- 待機中に heartbeat が出ない
- `pnpm test:packages` がロックを一切経由しない (lane inventory)
- bughunt ポート listen 中でも Browser lane が起動できてしまう
- INT / TERM で親が先に死に、子が生きたままロックが解放される

## 制約・前提

- bash + `flock(1)` + `shasum` 相当のみに依存する (PHP / Laravel boot / git を要求しない。
  `pnpm test` レーンは PHP が無くても動く必要がある)。
- **既存のドメインテスト・DB テストには手を入れない** (新規 Architecture テストの追加は行う。
  これは禁止事項 1 への正しい対応であり、上記制約と矛盾しない)。
  DB 名決定ロジック (`TestDatabaseEnv`) も変更しない。
- 検証は Pest ではなく shell の検証スイートで行う (対象がシェルスクリプトの並行挙動であり、
  PHP プロセス内からは fd 継承・ブロッキング待機・シグナルを正しく観測できない)。
  AGENTS.md 禁止事項 1 の「テストなしの実装完了」は本検証スイートで満たす。
- 本機能はテンプレート昇格対象。aicue 側の実装は slug 非依存を保ち、テンプレートへ還流可能な形にする。

## スコープ外

- bug-hunt 基盤 (`scripts/bug-hunt-shard.sh` 等) への変更 (自身の worktree-local lock、
  `playwright-cli kill-all` の対象絞り込みを含む)。
- `composer phpstan` / `pnpm lint` / `pnpm typecheck` / `pnpm build` のラップ。
- CI ワークフローの構造変更 (job 分割・並列化など。検証スイート 1 ステップの追加のみ行う)。
- テスト DB 命名・provision ロジック (`TestDatabaseEnv` / `ensure-test-db.php` / `drop-test-db.php`)。
- テストレーン以外 (bug-hunt shard 走行・手打ちの `vendor/bin/pest` 等) への規約適用の強制。
- **bug-hunt 併走時の非干渉保証** (best-effort guard のみ提供し、保証はしない)。
  bug-hunt 側への共有 interlock 追加は将来の選択肢として記録するに留める。
- ロック待ち時間の上限設定・タイムアウト (待つことが目的なので上限を設けない。
  代わりに sidecar + heartbeat で切り分け可能にする)。
- lock class の分離 (DB/ブラウザクラス vs CPU クラス)。見直し条件に到達したら再検討する。
- c2c 台帳への `status_reported` 追記 (実装・push 完了後の別作業)。


---

再レビューをお願いします。全体判定 (APPROVED / CHANGES_REQUESTED) と、
残る [Critical] / [Warning] があれば修正提案つきで指摘してください。
