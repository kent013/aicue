**前提**
- ご指定どおり、提示された差分テキストのみを対象にレビューしました（コマンド実行・書き込みなし）。

**ファイル別レビュー**

- `app/Providers/FakeExternalsServiceProvider.php`
  - 判定: 設計施策 3 に概ね一致。
  - [Warning] `boot()` で `install()` のみ行い `uninstall()` を持たないため、`APP_ENV=bughunt.local` の**同一長寿命プロセス内**で将来的に別用途処理を混在させた場合に static `Prompt::$fake` が残留する設計依存が残る（現状の運用前提では許容だが、明示的な運用ガード文書化があると安全）。
  - [Suggestion] `boot()` のコメントに「同一プロセスで bughunt 以外を扱わない前提」を1行追記すると、将来の誤用防止に有効。

- `app/Services/AI/Testing/CannedPromptResponses.php`
  - 判定: 施策 1/2 に一致。signature 解決、0件/複数件 fail-fast、DTO 通過用 canned は妥当。
  - [Suggestion] `forMessages()` 内で `map()` を複数回呼ぶため、ローカル変数へ一度保持すると読みやすさと微小効率が改善。

- `app/Services/AI/Testing/CannedPromptFake.php`
  - 判定: 施策 1 に一致。`latestRecordedMessages()` の record 契約依存も設計どおり。
  - [Warning] `latestRecordedMessages()` は `is_array($last)` のみで `$last['messages']` へアクセスしており、vendor 側 record 形状が崩れた際に PHP notice/型崩れ余地がある。現状テストで守れているが、`isset($last['messages']) && is_array(...)` の防御があるとより堅牢。
  - [Suggestion] 例外文に「vendor record schema changed?」等のヒントを加えると調査性向上。

- `app/Services/AI/Testing/CannedPromptFakeRegistrar.php`
  - 判定: 施策 1 に一致。旧 API 封じ込めの意図も明確。
  - 指摘なし。

- `tests/Pest.php`
  - 判定: 施策 4 に一致。Browser lane の改名追随と shared registrar 化は適切。
  - 指摘なし。

- `tests/Feature/Llm/CannedPromptResponsesTest.php`
  - 判定: 施策 5-1/5-2/5-3 を広く満たす。
  - [Warning] `captureMessages()` の `Assert::count($recorded, 1)` は「この helper 内で必ず fresh fake へ入替」の前提に依存。将来 helper が再利用拡張されると壊れやすいので、関数名やコメントで「single-execution 専用」をさらに強調すると安全。
  - [Suggestion] 未登録(0件)テストで例外メッセージの `(none)` も検証すると fail-fast 契約がより固定される。

- `tests/Feature/Llm/CannedAnalysisPipelineTest.php`
  - 判定: 施策 5-5 に一致。E2E で 3段実行・materialize・fake record 件数確認ができている。
  - [Suggestion] 可能なら `Http::assertNothingSent()` 相当を追加すると「外部 HTTP 未到達」の意図がより直接的に固定できる。

- `tests/Feature/Providers/FakeExternalsServiceProviderTest.php`
  - 判定: 施策 5-4 に一致。4環境条件（bughunt.local/testing/local/flag=false）を網羅し、afterEach で static cleanup も適切。
  - [Suggestion] `env=bughunt.local` ケースで `Prompt::getFake()->recorded()` 件数まで見ると、boot 後の実際の fake 経路確認がさらに明確。

- `docs/testing-browser.md`, `phpunit.browser.xml`
  - 判定: rename と挙動説明の更新として妥当。
  - 指摘なし。

- 旧クラス削除 (`BrowserCannedResponses.php`, `BrowserPromptFake.php`, `BrowserPromptFakeRegistrar.php`)
  - 判定: 施策 7（並走排除）に一致。alias 残置なしの方針に合致。
  - 指摘なし。

**観点別総評**
- 設計一致性: 高い（施策 1〜5 をほぼ忠実に実装）。
- 正確性: signature 解決・fail-fast・環境 allowlist は要件を満たす。
- PHPStan L10: 差分上は概ね適合（`mixed` 露出は実質抑制）。
- 禁止事項違反: 該当なし（`response()->json()` 直書き等なし）。
- テスト網羅: 十分に強い。主保証・fail-fast・provider 条件・E2E を押さえている。
- セキュリティ: bughunt 限定 fake 配線と testing/local 除外の意図は妥当。静的残留リスクは運用前提依存。
- 後方互換並走: 解消済み。

**全体判定**
- **APPROVED**