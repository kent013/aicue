# Round 2: Round 1 指摘への対応

Round 1 の全 [Critical] 3 件・[Warning] 6 件に対応し、概念設計を改訂しました。
対応判断は下記マトリクスの通りです。特に最大の差し戻し理由 (作用域の不一致) は、
ご指摘のとおり原因の作用域に合わせて **ロックをマシン全体 × UID 単位** に変更し、
クローン単位のキー導出 (git-common-dir) を全廃しました。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 1

## [Critical] clone 単位ロックでは H1 (machine-wide な playwright 掃除) を消せない
- 判断: **対応する**
- 根拠: 完全に正しい指摘。原因の作用域 (マシン全体の `pgrep`/`kill`) と対策の作用域 (クローン単位) が
  一致していなかった。そもそも H1 だけでなく H2 (PostgreSQL インスタンス) も H3 (CPU/メモリ) も
  作用域はマシン全体であり、クローン単位に狭める根拠は「slug をハードコードしたくない」という
  副次的都合しかなかった。名前の問題をスコープの問題にすり替えていた。
- 対応内容: ロックを **マシン全体 × UID 単位** (`${TMPDIR:-/tmp}/global-test-lane-$(id -u).lock`) に変更。
  `git rev-parse --git-common-dir` 由来のキー導出は全廃 (前提から `git` 依存も落ちた)。
  UID 単位にした根拠を 2 つ明記: (a) `kill` が通るのは同一 UID のみ = H1 の破壊半径と一致する、
  (b) 共有 `/tmp` の固定パスは他ユーザーが 0600 で先に作ると open 不能でハードエラーになる事故モードを塞ぐ。
  slug 非依存は「repo をまたいで共有されて正しいロックだから repo 識別子を入れる動機がない」という
  積極的な理由に置き換えた。提案 2 (掃除ロジックの絞り込み) は採らず、スコープ外に明記した
  (ロック作用域を破壊半径に一致させれば H1 は解消し、掃除側を触るのは今必要な最小ではないため)。

## [Critical] 「H1 が構造的に消える」は現設計では成立しない
- 判断: **対応する** (上記 Critical と同一原因)
- 根拠: 同上。
- 対応内容: スコープ修正により成立するようになった。期待効果の該当行を
  「掃除の破壊半径 (同一 UID のマシン全体) とロックの作用域が一致するため」と、成立根拠つきに書き換えた。

## [Critical] 無期限ブロッキングは hang と deadlock の切り分けができないと危険
- 判断: **対応する**
- 根拠: 妥当。タイムアウトを設けない方針を維持するなら、切り分け手段は設計側の義務。
- 対応内容: ロック取得直後に sidecar (`<lock>.owner`) へ `nonce / owner pid / 取得時刻 / lane 名 /
  worktree パス` を書き、解放時に削除する設計を追加。heartbeat は sidecar を読んで保持者の身元を
  必ず出す (待機秒数 + pid + lane + worktree + 取得時刻)。sidecar は**診断専用**で排他判定には
  使わない (正本は flock 一点) ことを明記。手動復旧手順は docs の runbook 節に書くことを実装方針に追加。

## [Warning] 再入ガードの成立条件が曖昧 (env フラグだけでは非保持の子が通る)
- 判断: **対応する**
- 根拠: 正しい。保持者終了後に生き残った子孫が env を持ち続ける穴を認識していなかった。
- 対応内容: 「aicue 固有の判断 4」を新設。再入許可条件を
  **「env の nonce が、現に存在する sidecar の nonce と一致するときだけ」** と定義した。
  nonce は取得ごとに新規生成、sidecar は保持中しか存在しないため、stale nonce は必ず不一致になり
  正しくブロッキング取得に回る。PID 意味論に依存しないので PID 再利用の穴もない
  (Codex 提案の「owner 情報を sidecar で検証」を、pid ではなく nonce で実現した形)。

## [Warning] テストファーストが設計本文に落ちていない (fail を先に確認する前提が無い)
- 判断: **対応する**
- 根拠: AGENTS.md 思考原則 5 の明文違反になりうる。
- 対応内容: 「テストファースト方針」節を新設。検証スイートを先に書き、**未変更ツリーに対して
  実行して fail を観測してから実装に入る**ことを明記し、未変更ツリーで確実に落ちる負のコントロールを
  5 つ列挙した (待機せず即エラー / 再入 deadlock / fd 継承 / heartbeat 無し / lane inventory 未ラップ)。

## [Warning] worktree-local flock 削除が安全なのは公式 entrypoint を全て包めた場合に限る
- 判断: **対応する**
- 根拠: 妥当。削除の安全性が「包み漏れゼロ」に依存していることを設計に書いていなかった。
- 対応内容: 「判断 2」に **lane inventory の deny-by-default 検査**を追加。
  `composer.json` / `package.json` のテストレーン相当スクリプトを機械的に列挙し、
  グローバルロックを経由しないものが 1 つでもあれば検証 fail とする。新レーン追加時に気づける。

## [Warning] 「H3 の論拠が効かない」(bug-hunt) は言い過ぎ
- 判断: **対応する**
- 根拠: 正しい。bug-hunt 側が timing assertion を持たなくても、併走する Browser lane 側には
  CPU/メモリ競合として効く。除外判断の結論は変えないが、根拠の書き方が不正確だった。
- 対応内容: 当該の根拠 4 を削除し、「bug-hunt 併走時の残余リスク」節を新設して
  CPU/PostgreSQL 競合を**受容する残余リスク**として明記 (対処コスト = bug-hunt の並列性喪失が
  見合わないため)。運用上の注意書き (bug-hunt 走行中に Browser lane を回さない) を docs に残す方針も追加。
  加えて、除外根拠 3 に「bug-hunt の 1 run は数十分オーダーでその間コミットゲートを全面停止させる」
  という時間軸の理由を補強した。

## [Warning] `pnpm test:packages` まで含めるのは運用ポリシー。成功条件と見直し条件が必要
- 判断: **対応する**
- 根拠: 妥当。JS 2 レーンの包含が「安全性」ではなく「方針判断」であることを明示すべき。
- 対応内容: 「JS 2 レーンを含める判断の成功条件と見直し条件」節を新設。
  成功条件 (4 レーン 1 巡の総時間が衝突込みの実効時間を上回らない) と
  見直し条件 (待ち時間が支配的になったら lock class 分離を再検討) を書き、
  今 lock class を作らない理由を「今必要なものだけ作る」に紐付けた。スコープ外にも追記。

## [Warning] 期待効果の書き方が強すぎる (flake がなくなる / 赤は本物だけ)
- 判断: **対応する**
- 根拠: 妥当。守備範囲を超えた主張は、後で「効かなかった」と誤って結論される。
- 対応内容: 期待効果を「テストレーン同士の競合分については解消する」に下げ、
  bug-hunt 併走・他ユーザー・flock 不在ホストを残余リスクとして併記。
  末尾に「本設計が保証するのは同一 UID のマシン上でテストレーンが同時に 2 本走らないことだけ」
  という限界宣言を追加した。

## [Suggestion] shell 側の不変条件 (`set -euo pipefail` / 厳格 quoting / trap 多重登録回避) を明記
- 判断: **対応する** (低コストかつ実装時の取りこぼしを防ぐ)
- 対応内容: 実装方針表の `scripts/global-test-lock.sh` 行に実装不変条件として明記。
  trap は EXIT に加え INT / TERM も登録することを併記。

## [Suggestion] `git rev-parse --git-common-dir` を key ソースにする判断は妥当
- 判断: **見送る (前提が消滅)**
- 根拠: Critical 対応でマシン全体スコープに変更したため、キー導出自体が不要になった。
  依存前提から `git` が落ちて実装が単純化した。

## [Suggestion] `CI=true` バイパスを作らない判断は健全 / phpstan 等の除外は妥当 / bug-hunt 除外の筋は通る
- 判断: **維持** (変更なし。bug-hunt の residual risk 記載のみ上記 Warning で対応済み)

## [Suggestion] 追加で自主的に加えた検証項目
- Browser lane の `pgrep -f "playwright/cli.js run-server"` と bug-hunt の `playwright-cli kill-all`
  (`@playwright/cli`) が**別プロセス名前空間であること**を、偶然でなく不変条件として
  検証スイートの 1 ケースで固定する。bug-hunt を非ロック対象にする以上、この境界だけは機械で守る必要がある。
- bug-hunt 自身の `.claude/bug-hunt.lock` が worktree-local であるため別 worktree からの
  同時 bug-hunt が `playwright-cli kill-all` で相互破壊しうる点を、**スコープ外の観測**として記録する
  (本設計では触らないが、次に触る人への申し送りとして `docs/template-divergence.md` に残す)。


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
5. 4 レーン (`composer test` / `composer test:browser` / `pnpm test` / `pnpm test:packages`) を対象にする。
6. **worktree-local flock は残さず削除する** (後述)。

### aicue 固有の判断 1: スコープは「マシン全体 × UID 単位」、名前は slug 非依存の固定名

移植元は `/tmp/spirux-global-test.lock` という**マシン全体の固定名**である。aicue も
**マシン全体スコープを採る**が、名前は spirux 名も aicue 名も使わない。

- **なぜマシン全体か**: H1〜H3 の作用域が全てマシン全体だから。
  ロックの作用域は、守るべき資源の作用域と一致していなければならない。
  クローン単位 (`git rev-parse --git-common-dir` 由来のハッシュ等) に狭めると、
  同一マシン上の別クローンが H1 の kill と H2 の PostgreSQL 競合を素通りさせるため、
  対策の作用域が原因の作用域より狭くなり、H1 を「構造的に消した」と言えなくなる。
- **なぜ UID 単位か**: `kill` が実際に通るのは同一 UID のプロセスのみ = H1 の破壊半径は
  「同一 UID のマシン全体」で、それより広げても得るものがない。加えて、共有 `/tmp` 上の
  固定パスは他ユーザーが先に 0600 で作ると open できず**ハードエラーになる**という
  実在の事故モードがあり、UID 接尾辞はこれも同時に塞ぐ。
- **なぜ slug を名前に入れないか**: aicue は laravel-claude-template 派生であり、
  `AppNameHardcodeTest` が `scripts/` へのアプリ slug 直書きを禁じている。かつ既定 slug は `app`
  なので、slug 由来にすると派生アプリ間で `app-...lock` に化けて意図しない名前衝突を招く。
  そもそも**このロックは repo をまたいで共有されて正しい** (同一マシンの PostgreSQL と CPU は
  repo をまたいで 1 つ) ため、repo 識別子を名前に入れる動機自体がない。

→ **`${TMPDIR:-/tmp}/global-test-lane-$(id -u).lock`**。
テンプレートから派生した全アプリが同じ名前を共有し、同一ユーザーのテストレーンは常に 1 本になる。

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
の形で出す。sidecar は**診断専用**であり、排他判定には一切使わない (正本は flock 一点に保つ)。
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

- **CPU / PostgreSQL 競合**: bug-hunt 走行中にテストレーンが走ると双方が遅くなり、
  Browser lane では偽赤を誘発しうる。**受容する** (誤結果を生む証拠がなく、
  対処コスト = bug-hunt の並列性喪失が見合わない)。運用上は
  「bug-hunt 走行中に Browser lane を回さない」を docs に注意書きとして残す。
- **ブラウザ回収の相互干渉 (検証で固定する)**: Browser lane は
  `pgrep -f "playwright/cli.js run-server"` (pest-plugin-browser 同梱 Playwright) を、
  bug-hunt は `playwright-cli kill-all` (`@playwright/cli`) を撃つ。
  両者は別ツール・別プロセス名前空間だが、**それが偶然でなく不変条件であること**を
  検証スイートの 1 ケースで固定する (パターンの互いの非マッチを機械検査)。
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
- **H1 が構造的に消える**: 掃除の破壊半径 (同一 UID のマシン全体) とロックの作用域が一致するため、
  Browser lane 同士が同時に存在しなくなる。
- **H2 / H3 由来の flake が、テストレーン同士の競合分については解消する**
  (bug-hunt 併走・他ユーザー・flock 不在ホストは残余リスクとして残る)。
- **H4 が消える**: 後発レーンは「失敗」ではなく「待機」になる。エージェントのリトライループと
  レーン迂回がなくなる。
- ロック機構が 3 種類 (worktree-local test.lock / vitest workspace lock / なし) から **1 種類**になる。

> 主張の限界を明示する: 本設計が保証するのは「**同一 UID のマシン上でテストレーンが同時に 2 本走らない**」
> ことだけである。「flake がゼロになる」「赤は必ず本物」とは主張しない。

## 実装方針 (概要)

| 変更対象 | 変更内容 |
|---|---|
| `scripts/global-test-lock.sh` (新規) | source されるライブラリ。lock path 導出 → nonce 再入判定 → ブロッキング取得 (待機中のみ heartbeat) → sidecar 書込 → EXIT/INT/TERM trap で解放。fd 7 を使う (既存 lane の fd 9 と衝突させない)。`set -euo pipefail` 前提・厳格 quoting・trap 多重登録回避を実装不変条件として明記 |
| `scripts/with-global-test-lock.sh` (新規) | 上記を source し `exec "$@" 7>&-` する薄いラッパ。ラップ用のシェルスクリプトを持たない `pnpm test:packages` 用 |
| `scripts/run-test.sh` | worktree-local flock ブロック (L16-25) を削除 → `source scripts/global-test-lock.sh`。実行行の `9>&-` を `7>&-` に |
| `scripts/run-browser-test.sh` | 同上 (L43-52 削除)。pest 実行の `9>&-` を `7>&-` に |
| `scripts/run-vitest.sh` | workspace-hash flock ブロック (L13-27) を削除 → source。`exec pnpm exec vitest run "$@" 7>&-` |
| `package.json` | `test:packages` を `with-global-test-lock.sh` 経由に |
| `docs/testing-browser.md` / `docs/worktree-isolation-strategy.md` / `scripts/README.md` | ロックの説明を更新 (worktree-local flock の記述は削除) + 手動復旧 runbook |
| `docs/template-divergence.md` | 正典 boundary との差分 (worktree-local flock を残さない / 固定名の付け方 / heartbeat は待機中のみ / 再入は nonce) を記録 |
| `devnotes/{dir}/verify-global-test-lock.sh` (新規) | 検証スイート。一時スクリプトなので devnotes に置く (AGENTS.md) |

## テストファースト方針

AGENTS.md 思考原則 5「テストファースト。fail を確認してから実装に入る」に従い、
**`verify-global-test-lock.sh` を先に書き、未変更ツリーに対して実行して fail を観測してから**
実装に入る。未変更ツリーで確実に落ちる負のコントロールを最低限以下に置く:

- 別 worktree からの 2 本目の lane が**待機せず即エラーになる** (H4 / cross-worktree 排他ゼロ)
- 再入時に deadlock する / 再入ガードが存在しない
- ロック fd がテスト実行コマンドに継承される
- 待機中に heartbeat が出ない
- `pnpm test:packages` がロックを一切経由しない (lane inventory)

## 制約・前提

- bash + `flock(1)` + `shasum` 相当のみに依存する (PHP / Laravel boot / git を要求しない。
  `pnpm test` レーンは PHP が無くても動く必要がある)。
- テストコード (`tests/`) には手を入れない。DB 名決定ロジック (`TestDatabaseEnv`) も変更しない。
- 検証は Pest ではなく shell の検証スイートで行う (対象がシェルスクリプトの並行挙動であり、
  PHP プロセス内からは fd 継承・ブロッキング待機・シグナルを正しく観測できない)。
  AGENTS.md 禁止事項 1 の「テストなしの実装完了」は本検証スイートで満たす。
- 本機能はテンプレート昇格対象。aicue 側の実装は slug 非依存を保ち、テンプレートへ還流可能な形にする。

## スコープ外

- bug-hunt 基盤 (`scripts/bug-hunt-shard.sh` 等) への変更 (自身の worktree-local lock を含む)。
- `cleanup_orphan_playwright()` の掃除範囲そのものの絞り込み
  (ロック作用域を破壊半径に一致させることで H1 は解消するため、今は不要)。
- `composer phpstan` / `pnpm lint` / `pnpm typecheck` / `pnpm build` のラップ。
- CI ワークフロー (`.github/workflows/ci.yml`) の変更。
- テスト DB 命名・provision ロジック (`TestDatabaseEnv` / `ensure-test-db.php` / `drop-test-db.php`)。
- ロック待ち時間の上限設定・タイムアウト (待つことが目的なので上限を設けない。
  代わりに sidecar + heartbeat で切り分け可能にする)。
- lock class の分離 (DB/ブラウザクラス vs CPU クラス)。見直し条件に到達したら再検討する。
- c2c 台帳への `status_reported` 追記 (実装・push 完了後の別作業)。


---

再レビューをお願いします。全体判定 (APPROVED / CHANGES_REQUESTED) と、
残る [Critical] / [Warning] があれば修正提案つきで指摘してください。
