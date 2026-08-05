# 対応マトリクス: impl-review Round 1

## [Critical] audit-gate.ts: error-bearing JSON が空コンテナだと 0 件で通る

- 判断: **対応する**
- 根拠: 妥当な指摘。`{"advisories":{},"error":{"code":"ENETUNREACH"}}` は
  「有効 JSON + 期待コンテナあり」なので shape 検査を通過し、normalizer が 0 件を返す。
  fail-closed gate の目的 (取得失敗を「安全」と読み替えない) から見て明確な穴。
  composer の空配列を許容した以上、error-bearing output の拒否とセットでないと
  「空コンテナ = 真の 0 件」という前提そのものが成立しない。
- 対応内容: `assertAuditSourceShape` の冒頭で top-level `error` / `errors` を検査し、
  **非空なら throw** する。空 (null / `{}` / `[]`) は「エラー無しの明示」として通し偽赤を避ける。
  負のコントロールを 2 本追加:
  - error-bearing (pnpm object / composer 空配列 / pip errors 配列) → throw
  - 空 error フィールド → throw しない
  既存の「pnpm ネットワークエラー形」テストは error 検査が先に発火するため
  期待メッセージを追従させ、あわせて「error シグナル無しで advisories 欠落」の
  ケースを別テストとして残した (shape 検査自体が空振りしていないことの保証)。

## [Warning] ci.yml: on.schedule は workflow 全体を起動する

- 判断: **対応する**
- 根拠: 指摘が正しい。`on.schedule` は job 単位ではなく workflow 単位のトリガーなので、
  実装したままでは nightly に `php` / `frontend` / `browser-tests` も走る。
  docs (review-checklist §6) は「nightly は supply-chain gate の先行検知」と書いており、
  **記述と実体が食い違う**。設計意図は「無関係な PR のクリティカルパス外で advisory を
  先に検知する」ことであって全 CI の夜間実行ではない。
- 対応内容: `php` / `frontend` / `browser-tests` に
  `if: github.event_name != 'schedule'` を付与。あわせて inventory gate に **W15** を追加し、
  (a) 3 job が schedule から除外されていること、(b) **supply-chain-audit には `if` が無いこと**
  (= gate を nightly から外す退行を止める) を deny-by-default で固定した。
  docs にもこの構成を明記。

## [Warning] run-browser-test.contract.test.ts: 呼び出し元環境のレーン変数が漏れる

- 判断: **対応する**
- 根拠: 妥当。開発者が `BROWSER_TEST_LANES` を export していると
  「既定は chromium webkit の 2 レーン・直列」という契約検査が環境依存で偽赤になる。
  既定値を検証するテストが環境に依存するのは検出器として不健全。
- 対応内容: sandbox 実行の子 env から `BROWSER_TEST_LANES` / `BROWSER_TEST_PROCESSES` を
  明示 `delete` し、注入は `options.env` 経由の明示指定のみに限定した (C8 のみ使用)。

## [Warning] global-test-lock.sh の潜在 race を別タスク化すべき

- 判断: **見送る (本バッチでは対応しない。報告に含める)**
- 根拠: レビュアー自身も「T104 の範囲では受け入れ可能」としている。
  `global-test-lock.sh` は T099 の契約ファイルであり、本バッチ (CI レーン統合) の
  スコープ外。スコープを広げるより、事実を報告して TODO 化の判断を委ねる方が適切。
- 対応内容: スタブ側の `sleep 0.1` とその理由をコード内コメントに明記済み。
  最終報告で「global-test-lock.sh に sub-millisecond 子プロセスでの race が残る」ことを
  既知事項として申し送る。

## [Suggestion] C5 の PPID 検査が反転条件でも通る

- 判断: **対応する**
- 根拠: 指摘どおり。`[ "${ppid}" != "1" ]` は「orphan **以外**を kill する」真逆の実装だが、
  素朴な `= "1"` 検査では素通りする。orphan 掃除は実走検証しない方針なので、
  静的検査の空振り耐性は重要。
- 対応内容: 正規表現を `!=` を除外する形へ修正し、反転を明示的に違反として検出。
  負のコントロール (PPID 判定を反転した改変ソース) を 1 本追加した。

## [Suggestion] audit-gate.test.ts の load() が一時ディレクトリを残す

- 判断: **対応する**
- 根拠: 妥当。修正コストがゼロで、テストの後始末として正しい。
- 対応内容: `unlinkSync(tmp)` を `rmSync(dir, { recursive: true, force: true })` へ変更。
