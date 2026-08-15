# 対応マトリクス: conceptual-review Round 3

## [Critical] `install-deps --dry-run` が `reportMissingDependenciesLinux()` へ到達することが立証されていない

- 判断: **立証する。あわせて設計を単純化して、依存する解析を 1 つ減らす**
- 根拠 (1) call path — pin されている playwright 1.61.1 の
  `playwright-core/lib/coreBundle.js` を実読した。3 段でつながっている:
  1. `install-deps` コマンドの handler:
     `await registry.installDeps(registry.resolveBrowsers(args, {}), !!options.dryRun);`
  2. `Registry.installDeps(executablesToInstallDeps, dryRun)` が host platform で分岐し、
     Linux では `installDependenciesLinux(targets, dryRun)` を呼ぶ
  3. `installDependenciesLinux(targets, dryRun)` の冒頭で必要ライブラリ集合を組み立て、
     `if (dryRun) { await reportMissingDependenciesLinux(uniqueLibraries); return; }`
     — **dry-run はここで打ち切られ、`apt-get update` / `apt-get install` を組み立てる
     以降の行 (特権経路) には到達しない**
  4. `reportMissingDependenciesLinux(packages)` は
     `apt-get install -s --no-install-recommends <packages>` を実行し、
     出力の `^Inst (\S+) ` 行 (= これから入れる = **いま不足している**もの) を数える。
     0 件なら `All system dependencies are installed.` を出力して終わり、
     1 件以上なら `Missing system dependencies (N):` + 一覧を出力して `process.exitCode = 1`。
     つまり「必要パッケージ集合の表示」ではなく **不足分だけの表示**である。
- 根拠 (2) 実測 — 本 devcontainer (Linux / Debian) で実行した:

  ```
  $ pnpm exec playwright install-deps --dry-run chromium webkit
  All system dependencies are installed.
  (exit 0 / 1.2 秒)
  ```

  充足済み環境で「不足あり」とは出ず、特権処理も起動していない。
- 対応内容: 上記 call path と実測値を詳細設計へ根拠として明記する。
  分類は次の 3 値で、**出力文言と終了コードの両方が一致したときだけ**確定させる
  (CLI 自体の異常終了と「不足あり」を混同しない):
  | 分類 | 条件 |
  |---|---|
  | satisfied | exit 0 かつ出力に `All system dependencies are installed.` |
  | missing | exit 非 0 かつ出力に `Missing system dependencies` |
  | undeterminable | 上記以外すべて (出力が空 / 文言と終了コードが食い違う / CLI が異常終了) |
  `undeterminable` は **exit 1** (fail-closed) とし、メッセージに Playwright の版と
  手で叩く確認コマンドを出す。

## [Warning] ブラウザの充足を「導入先ディレクトリの実在」で判定するのは不十分

- 判断: **対応する (この判定を丸ごと捨てる)**
- 根拠: 実測で 2 つ分かった。
  1. `pnpm exec playwright install chromium webkit` は**充足済みなら 0.5 秒・無出力・
     ダウンロード無しで exit 0** する。**冪等性は Playwright 自身が持っている**
     (内部の導入完了マーカーで判定している)。自前の充足判定は要らなかった。
  2. `install --dry-run chromium webkit` が列挙するのは **4 件**
     (chromium / ffmpeg / chromium_headless_shell / webkit) であり、
     「抽出件数が対象ブラウザ数 (2) と一致すること」という当初の境界検査は**そもそも誤り**だった。
- 対応内容: `install --dry-run` の出力解析を**設計から削除**する。
  導入は毎回 `pnpm exec playwright install [--with-deps] chromium webkit` を呼ぶだけにし、
  「入っているかどうか」の判断は Playwright に委ねる (先人の知恵を探せ)。
  自前で判定するのは **OS ライブラリの要求だけ**になる。
  これに伴い導入専用ロックの二重確認も不要になり、
  「ロックを取る → 判定 → 導入 → 解放」の一本道になる (実測で保持は 2 秒前後)。

## [Warning] `BROWSER_TEST_DEPS=force` の意味が定義されていない

- 判断: **対応する (`force` ごと環境変数を削除する)**
- 根拠: 指摘のとおり用途が定義できない。上の単純化で `auto` しか残らないので、
  環境変数そのものが不要になる (思考原則 2)。
- 対応内容: `BROWSER_TEST_DEPS` を設計から削除する。
  モードは **既定と `--self-test` の 2 つだけ**、未知のオプションは exit 2 で拒否。
  判定不能で詰まったときの逃げ道は環境変数ではなく、
  **メッセージが提示する手動コマンドで原因を確認する**という運用にする
  (当リポジトリの対象環境は devcontainer (Debian) / CI (ubuntu) / macOS ホストの 3 つで、
  いずれも apt もしくは Darwin 分岐で決着する。逃げ道が要ると分かってから作る)。

## [Warning] 成功条件は実 CLI smoke で確かめるべき (stub は自分の想定しか確認できない)

- 判断: 対応する
- 対応内容: 契約テストに実 CLI smoke を置く。pin された Playwright に対して
  `install-deps --dry-run chromium webkit` を実行し、**分類器が `undeterminable` に落ちないこと**
  (= satisfied か missing のどちらかに確定すること) と、**文言と終了コードが対応していること**を
  検査する。ubuntu runner では WebKit の共有ライブラリが未導入で `missing` になりうるので、
  「satisfied であること」は要求しない (環境依存の偽赤を作らない)。
  Linux 以外では**理由を出力して skip** する (silent skip にしない)。

## [Warning] `/tmp/browser-provisioning-<uid>.lock` の排他範囲

- 判断: 対応する (保証範囲の記述を正す)
- 対応内容: 「同一ホスト」ではなく **「同一 UID かつ同一 lock ディレクトリ名前空間
  (= 同じ `/tmp` を共有するプロセス群)」**と書く。別コンテナが同じ dpkg を触る構成では
  排他にならないことを明記する (既存のグローバルテストロックが
  「同一 UID・同一マシン」と書いているのと同じ、誇張しない書き方)。

## [Suggestion] `BROWSER_PROVISION_LOCK_DIR` が契約を弱める抜け道にならないようにする

- 判断: 対応する
- 対応内容: `GLOBAL_TEST_LOCK_DIR` と同じ扱いにする。すなわち
  **スクリプト自身が代入しないこと**を契約テストの静的検査で固定する
  (参照は既定値つき展開 1 箇所のみ許可)。テストハーネスが env で渡すことは対象外、
  という既存の線引きをそのまま踏襲する。

## [Suggestion] 台帳の完了条件の読み替えは台帳へ書き戻すべき

- 判断: 対応する
- 対応内容: 概念設計に「台帳への書き戻し」節を設け、未追従点 3 の完了条件を
  「初回の Browser レーン起動時に手作業なしで導入される」へ読み替えたこと、
  および `install --dry-run` 解析を捨てた理由を `handover` として残す方針を書く。

## [Suggestion] シェル側の抽出値も境界検査すべき

- 判断: **見送る (前提が消えた)**
- 根拠: `install --dry-run` の出力解析そのものを削除したため、
  抽出値 (パス) の絶対性・重複・空文字を検査する対象が無くなった。
  残る解析は「決まった 2 つの文言のどちらが出たか」だけで、
  上の 3 値分類がその境界検査になっている。
