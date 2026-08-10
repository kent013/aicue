# 対応マトリクス: impl-review Round 2

Critical 0 件。Warning 4 件 (うち 1 件はドキュメント 2 ファイルにまたがる同一指摘) を**すべて対応**した。

## [Warning] 確認拒否時に `--json` が DTO 1 経路を通らない

- 判断: **対応する**
- 根拠: 指摘どおり。`--json` の契約を「コマンド全体」で言うなら、成功・失敗・中止の 3 経路が
  同じ出力関数を通らなければ嘘になる。
- 対応内容:
  - `finish()` を `buildResult()` (DTO 組み立て) + `emit()` (出力) に分解し、
    確認拒否は `abort()` → `buildResult(aborted: true)` → `emit()` を通す。終了コードは `INVALID` のまま。
  - 中止理由は `context['aborted']` に載せ、人間向けは `RESULT: ABORT` と出す。
  - **`--json` の契約を「stdout 全体が JSON」ではなく「結果 JSON は最終行に 1 行」と明記した**
    (`--force` なしだと `ConfirmableTrait` が人間向けの確認 UI を先に描くため。
     bug-hunt レーンの導線は常に `--force` を付けるのでその経路では JSON だけが出る)。
    誇張しないため signature のヘルプ文と `emit()` の docblock の両方に書いた。
  - テスト追加: `確認を拒否したときも --json は DTO の 1 経路で機械可読出力を返す`
    (exit 2 / `passed=false` / `context.aborted` / **preflight 段だけが記録され業務経路は 0 件**)。
    テストは `Artisan::call` に **`--no-interaction`** を渡す — これが無いと確認プロンプトが
    実 TTY を掴んで入力待ちで**止まる**ことを実測した (parallel 実行のワーカーで全体が固まった)。
    非対話の既定は「no」なので、このケースは同時に
    「**`--force` 無しの非対話実行は必ず中止される**」= 費用の防壁の behavioral な固定にもなっている。

## [Warning] `captureLaneContext()` が fail-secure 不成立後にも依存を解決している

- 判断: **対応する**
- 根拠: 指摘のとおり、出力の都合で「4 条件を通過する前に依存を解決しない」を崩していた。
  さらに DB 接続の構築が失敗すると、直前に直したはずの fail-secure の JSON 出力が例外で失われる。
- 対応内容: `failSecureBlocker()` + `captureLaneContext()` を **`evaluateFailSecure()` 1 本**へ統合し、
  `array{?string, array<string,string>}` を返す形にした。
  - 観測は**条件を 1 つ通過するごとに 1 つだけ**取る。未到達の条件は `unknown` のまま出す。
  - 例: env が不成立なら `db` / `fake_storage` / `fake_llm` は `unknown` のままで、
    `DB::connection()` も `FakeStorageGate` も**解決しない**。
  - DB 名の判定は `BughuntDatabaseGuard::matches($name)` (名前だけを見る純関数 = SSOT) を使い、
    観測値 (実際の DB 名) を同時に context へ出す。
  - テスト側も `context['env']` が実測値であることを固定した。

## [Warning] `AGENTS.md` / `docs/architecture.md` の帰属規約が `ExampleSummaryPrompt` の exempt と矛盾

- 判断: **対応する**
- 根拠: 「すべての factory が必須引数」と読める書き方は事実と違う。保証範囲を誇張しない規律に反する。
- 対応内容:
  - AGENTS.md: 「**実行経路を持つ** prompt factory は必須引数で受ける。帰属の対象を持たない見本
    (`ExampleSummaryPrompt`) は inventory へ**帰属キーを空配列で exempt 登録**する
    (deny-by-default なので exempt にする操作がレビューで必ず見える)」に修正。
  - docs/architecture.md: 同じ境界に加えて「**適用範囲を誇張しない**」節を足し、
    「全 factory が必須引数を持つ」のではなく「**inventory に帰属キーを登録した factory が
    必須引数を持つ**」と明記した。

## [Suggestion] `runDatabasePreflight()` への集約と `QueryException` の一括捕捉

- 判断: **そのまま維持** (Codex も妥当と評価)
- 根拠: 業務処理の失敗を握り潰す範囲には広がっておらず、preflight の機械可読契約を強化している。
