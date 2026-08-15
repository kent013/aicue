# 対応マトリクス: conceptual-review Round 1

## [Critical] `composer setup` の末尾で導入を必須化するのは過大 (権限の無い環境でアプリ初期化が失敗する)

- 判断: **対応する** (指摘のとおり。設計を変更した)
- 根拠: アプリ本体の初期化と Browser テスト基盤の初期化を結合すると、Browser テストを走らせない
  作業でも初期化が止まる。これは導入自動化の目的に対して副作用が大きい。
- 対応内容: `composer.json` は**一切触らない**ことにした。代わりに
  **レーン起動そのものを導入の入口にする** — `scripts/run-browser-test.sh` が
  グローバルテストロックを取る前に `bash scripts/setup-browser-testing.sh` を呼び、
  不足があればその場で導入し、権限が無ければ特権経路を起こす前に止める。
  - これで「手で `sudo pnpm exec playwright install-deps webkit` を叩く」手順が**完全に消える**
    (台帳の未追従点 3 が本当の意味で解消する)。判定だけを行う `--check` モードは
    呼び出し元が 1 つも無くなるので**持たない** (思考原則 2)。
  - 導入は**ロック取得前**に行うので、数百 MB の取得中にグローバルテストロックを
    保持し続けることはない。

## [Warning] `composer setup` を失敗させる設計は結合が強すぎる (観点 1 / 6 の同趣旨指摘)

- 判断: 対応する (上記 Critical と同一の対応で解消)
- 根拠・対応内容: 同上。スコープを「Browser レーンを起動する経路」と「CI」に限定した。

## [Warning] Playwright CLI の出力解析に依存するのは脆い。判定不能で fail-closed にすると Playwright 更新で突然止まる

- 判断: **対応する**
- 根拠: 出力書式は安定 API ではないという指摘は妥当。ただし fail-closed 自体は
  「黙って劣った導入へ落ちない」という本設計の中核なので緩めない。**乖離を早期に検出する**方向で対応する。
- 対応内容:
  1. 契約テストに **実 Playwright に対する smoke** を追加する
     (`pnpm exec playwright install --dry-run chromium webkit` を実行し、
     解析器が 2 件の導入先を取り出せることを確認する)。解析器と実 CLI の乖離が
     `pnpm test` (CI の `frontend` job) で赤くなる。
  2. 判定不能時のメッセージに **Playwright の版**と**手で叩く確認コマンド**を出す。
  3. fixture 固定 (`--self-test`) と実 CLI smoke (契約テスト) の 2 層にする。

## [Warning] Windows の扱いが曖昧

- 判断: 対応する
- 対応内容: `uname -s` の分類を `Linux` / `Darwin` / その他の 3 分類にし、
  **OS ライブラリの導入判定を行うのは `Linux` のみ**、`Darwin` は判定不要、
  その他 (Windows / MSYS 等) は**サポート対象外として exit 1** と明記する。
  中途半端に動くふりをしない。

## [Warning] CI の action の major を設計に固定すべき

- 判断: 対応する
- 対応内容: `actions/cache@v4` / `actions/upload-artifact@v4` と設計に明記する。
  gate の `actionName()` は版を落として突合するので gate は版に沈黙するが、
  **存在しない版を書けば CI が即 fail する**ため、実装時に現行 major の実在を確認する旨も残す。

## [Warning] allowlist を「緩める」のではなく完全一致で登録すること

- 判断: 対応する
- 対応内容: 概念設計に「既存 allowlist の粒度 (完全一致) を維持し、
  glob 化・部分一致化・正規表現化しない」と明記する。

## [Suggestion] 期待効果 (CI 実行時間短縮) は lockfile 不変時に限定して書くべき

- 判断: 対応する
- 対応内容: 期待効果の記述を「lockfile が変わらない限り」に限定した。

## [Suggestion] PHP Architecture テストで mixed を増やさない

- 判断: 対応する
- 対応内容: 詳細設計で、走査対象と allowlist を `const` の型付き配列 (`array<string, string>` /
  `list<string>`) で持ち、純関数の戻り値を `list<string>` に固定する方針を明記する。

## [Suggestion] 軽量 gate 方針は妥当 / 成果物のレーン別退避は具体的で良い

- 判断: 見送る (肯定的評価につき変更なし)
