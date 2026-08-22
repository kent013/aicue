# Round 4 — Round 3 の指摘への対応

Warning 1 件 (「スコープ外」表の `registry_version` 行に残っていた旧表現) に対応しました。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 3

Codex 判定: CHANGES_REQUESTED / Critical 0 / Warning 1 / Suggestion 7

## [Warning] 「スコープ外」表の registry_version 行に旧表現「(陳腐化の検知)」が残っている
- 判断: **対応する**
- 根拠: 指摘が正しい。Round 2 で本文 (「保証しないもの」節と必須ケース表) は直したが、
  「スコープ外」表の 1 セルを直し忘れていた。本文と矛盾する記述が 1 か所でも残ると、
  そこだけを読んだ実装者が「陳腐化は機械で見ている」と誤解する。
  台帳の記述原則 (保証範囲は 1 か所に書く / 2 か所に書くと必ず食い違う) にも反する。
- 対応内容: 該当セルを
  「登録簿の版は `classificationRegistryVersion()` で読めるため、
  **新設 gate の中で pin し、将来のパッケージ更新で同梱の登録簿が変化したときの
  レビュー入口とする**。★これは IANA 登録簿に対する陳腐化の検知ではない
  (外部の登録簿と突き合わせない)」へ書き換えた。

## [Suggestion] 使命 / 禁止事項 / lock の許容単位 / 3 段手順 / リスクの限定 / 18 ケース / 型安全性
- 判断: **見送る** (いずれも肯定の評であり対応不要)


---

## 修正後の該当箇所 (「スコープ外」節の全文)

## スコープ外

| 項目 | 理由 |
|---|---|
| 第二層 (`SsrfPinPackageContractTest` — 導入した版が実際に何を備えるかの契約検査) の新設 | 正典が「**第二層は t0 の必須要素ではない**」と明示している (台帳の 2026-08-18 夕 第 2 ラウンド)。保有は laravel-claude-template と aigenba の 2 本の先行分。本追従の target_version にも含まれない。過大化させない |
| `config/ssrf-pin.php` への `registry_version` の pin (spirux はやっている) | pin 値 5 つ維持の不変条件に反し、かつ同ファイルは**採用時債務パス**なので触ると債務の整理が連鎖する。登録簿の版は `classificationRegistryVersion()` で読めるため、**新設 gate の中で pin し、将来のパッケージ更新で同梱の登録簿が変化したときのレビュー入口とする**。★これは IANA 登録簿に対する陳腐化の検知ではない (外部の登録簿と突き合わせない) |
| `config/ssrf-pin.php` への `max_body_bytes` の明示 | aicue は `PinnedHttpClient` を 1 か所も使っていない。`mergeConfigFrom` で package 既定 1 MiB が入るので実効挙動に差が無く、上と同じ債務パスの問題がある |
| `PinnedHttpClient` への取得の一本化 | 本 feature の boundary が「呼び出し側は各機能側」と切っている。加えて `PinnedResponse` が本文を返せる形になったのは v0.3 以降で、`SnsCertificateFetcher` を書き換える判断は `mail-ses-suppression` 側の管轄 (aicue:T229 の裁定 AG-199 で「inspect → fetch」を採る判断が既に済んでいる) |
| `docs/ses-mail-runbook.md` の 403 切り分け表の文言更新 (「private IP へ解決されていないか」→ 拒否区分の広がり) | 同ファイルは**指紋台帳 + 採用時債務パス**である。現行の記述は誤りではなく (private IP は依然その一例) 網羅的でないだけなので、債務の整理を伴う変更に見合わない。再判定の条件: 同ファイルが別の理由で債務から外れたとき |
| `docs/architecture.md` の SNS 節 | 「DNS 解決失敗のみ 503・他は 403」という記述は v0.4.1 でもそのまま正しい (`NotGloballyReachable` は 403 側に落ちる)。更新すべき事実が無い |
| 家系全体の版の扱い / 正典の版を 1 つ上げるかの判断 (AG-003b の settle 論点) | aicue 1 リポジトリ分の安全追従であり、settle の代行はしない。他リポジトリの追従も本設計の対象外 |
| aigenba の gate 名の割れ (`SsrfPinBoundaryTest` 名の統一) | aicue には既に正典と同名の gate がある。他リポジトリの話 |
| TypeScript 側の URL 安全性判定 | 本 feature の boundary が明示的に除外 (`capture-core-package` の管轄)。aicue に該当実装は無い |


---

本文の「保証しないもの」節と必須ケース表の記述はいずれも Round 2 で改訂済みで、
これで 3 か所の表現が一致しました。他に残る懸念があれば挙げてください。
無ければ全体判定 APPROVED をお願いします。
