# 対応マトリクス: impl-review Round 1

Critical は 0 件。Warning 2 件と Suggestion 3 件を以下のとおり捌いた。

## [Warning] `runLlmEvidenceStage()` の帰属照合が設計より弱い (OR 畳み込み)

- 判断: **対応する** (指摘は正しい。実バグ)
- 根拠: 実装は `$attributed[$template] = true` を「1 行でも一致したら」立てていたため、
  同じ template に正しい行と壊れた行が混在すると pass していた。設計の
  「**成功行がすべて** `metadata_missing = false` ∧ 期待 organization / subject」を満たしていない。
  帰属が落ちる現実的な経路 (リトライ後の行だけ配線が抜ける等) を素通りさせる。
- 対応内容:
  - 畳み込みを **AND** にし、かつ**純関数へ切り出した**:
    `SmokeFailureClassifier::fullyAttributedTemplates(list<array{string, bool}>): list<string>`。
    DB 読み出しはコマンドに残し (設計の責務分割を維持)、集合演算だけを classifier に置いた
    = **DB なしの Unit テストで畳み込み規則を直接固定できる**。
  - 回帰テストを 4 件追加 (`SmokeFailureClassifierTest`):
    全一致 / **同一 template に正+誤が混在** / 誤が先に来る順序不変 / 観測 0 件。
    2 件目が指摘そのもの (OR 実装なら赤)。

## [Warning] `--json` が fail-secure 失敗時に機械可読出力にならない

- 判断: **対応する**
- 根拠: `--json` は「DTO `toArray()` → `json_encode` の 1 経路」が設計の契約であり、
  fail-secure 失敗だけ plain text になると機械側 (bug-hunt レーンの呼び出し元) が
  「出力が無い」と「失敗した」を区別できない。
- 対応内容: fail-secure 失敗を `preflight` 段として `recordStage()` し、
  通常の `finish()` を通す。`--json` は `failure_class=preflight` を含む DTO を返し、
  人間向けは段テーブルの detail に理由が出る。
  テスト追加: `fail-secure 失敗でも --json は DTO の 1 経路で機械可読出力を返す`。

## [Suggestion] preflight 表示に DB 名が出ていない

- 判断: **対応する**
- 根拠: 費用の防壁が「どの状態で」成立/不成立になったかを実行ログだけで読めるべき。
- 対応内容: `captureLaneContext()` を新設し、`env` / `db` / `fake_storage` / `fake_llm` を
  **実測値**として context に載せる (従来は `fake_storage=on` 等を決め打ちで出していた =
  fail-secure を通過した後にしか出ないとはいえ、期待値の写経だった)。
  fail-secure 失敗時にも context が出るようになったため、不成立の原因が 1 画面で読める。

## [Suggestion] `QueryException` catch が `resolveOrganization()` だけに限定されている

- 判断: **対応する**
- 根拠: 指摘どおり `users()` / `TicketLedgerService` / `DefaultProjectResolver` でも DB 例外は起きうる。
  `--json` 契約のための追加なら、DB を触る preflight 全体を同じ失敗 DTO に閉じるのが一貫する。
- 対応内容: DB を読む部分を `runDatabasePreflight()` に括り出し、呼び出し側の **1 箇所**で
  `QueryException` を捕まえる形にした。

## [Suggestion] DirectFetchInventory の justification 「対象は常に 1 組織」が強すぎる

- 判断: **対応する**
- 根拠: `--org` 省略時は eligible な組織を探索するため、文言が事実とずれている。
  目録の根拠は「実際に何をしているか」を正確に書くためのものなので、誇張は直す。
- 対応内容: 「`--org` 省略時は使い捨ての bug-hunt DB 内で条件を満たす組織を探索するが、
  最終的に触るのは選ばれた 1 組織だけで、組織を跨ぐ read/write は 1 箇所も無い」に修正。

## [Warning] 施策 9 (ドキュメント) が Round 1 の diff に含まれていなかった

- 判断: **対応する** (指摘は差分提示の不備。実装は存在する)
- 根拠: Round 1 の diff を `app/ tests/ database/ scripts/ resources/fixtures/` に絞ったため、
  `docs/architecture.md` / `AGENTS.md` / `.claude/skills/app-bug-hunt/SKILL.md` が未提示だった。
- 対応内容: Round 2 のプロンプトにドキュメント差分を全文添付する。

## [Verification] `pnpm test` / `pnpm build` / packages 系の省略

- 判断: **対応する** (省略をやめた)
- 根拠: 「全 green でコミット」が AGENTS.md の規約であり、UI 未変更を理由に自己判断で
  省略すると規約に対しては未完了になる。
- 対応内容: 全部実行した。`pnpm test` 130 files / 1299 tests passed、`pnpm build` 成功、
  `pnpm typecheck:packages` / `build:packages` / `test:packages` (10 files / 106 tests) すべて green。
