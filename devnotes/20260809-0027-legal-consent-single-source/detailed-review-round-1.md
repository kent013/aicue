**全体判定: APPROVED**

小粒な SSOT 化として妥当です。既存の値の意味を変えず、空版だけ fail-fast へ寄せる設計になっており、DTO/JsonResource/Inertia の境界にも余計な変更を入れていません。Architecture gate も「空振り green」を意識しており、この規模としては十分強いです。

**施策 1: LegalConsent 新設 — APPROVE**

指摘なし。`config()->string()` + `Assert::stringNotEmpty()` で未設定・非文字列・空文字を同じ `InvalidArgumentException` 系に寄せる判断は明確です。`@return non-empty-string` を PHPStan 実測で確認している点もよいです。

[Suggestion] コメントはやや厚いですが、新しい不変条件の入口なので許容範囲です。実装時に冗長と感じるなら、詳細背景は Architecture test 側へ寄せてもよいです。

**施策 2: 呼び出し側 3 本を正準形へ — APPROVE**

指摘なし。`CreateInquiryAction` の `$recipient` 側 `Assert` を残す判断も正しいです。既存 Feature テストの期待値を `LegalConsent::version()` に揃えない判断も妥当です。ここを揃えると、設計書の通りトートロジーになります。

[Suggestion] PR 説明に「空文字 env のみ 500 化」を書く方針は維持してください。これは後方互換差分としてレビュー時に一番見落とされやすい点です。

**施策 3: InquiryFactory の literal 撤去 — APPROVE**

指摘なし。Factory の `draft-1` literal は実装値と独立して腐るので、`LegalConsent::version()` に寄せるのは自然です。

[Suggestion] 将来、Factory を「DB レコードの過去版を作る用途」に使いたくなった場合は、default は SSOT のまま、state で明示的に過去版を与える形がよいです。本タスクでは不要です。

**施策 4: Architecture gate 新設 — APPROVE**

設計として approve です。G1/G2/G3/G4 の分離、billing 同意版を巻き込まない限定、exact-fit inventory、負のコントロール、母集団 floor はこのタスクのリスクに対して十分です。

[Warning] mutation 手順に `git checkout -- <file>` が書かれていますが、AGENTS.md の「既存変更を勝手に戻さない」運用とは衝突し得ます。  
修正案: 設計書上は「mutation 前に対象ファイルの差分が自分の変更だけであることを確認し、戻しは `git diff` を見ながら手で戻す。未コミットの他者変更がある場合は `git checkout --` を使わない」と明記してください。実装者向け手順として安全になります。

[Suggestion] `legalConsentLiteralEquals()` の `trim($literal, "'\"")` は現実上十分ですが、文字列の両端以外に同じ引用符が混ざるケースまで正確に扱うなら `stripcslashes(substr($literal, 1, -1))` 系になります。ただし本件では過剰なので、現設計のままでよいです。

**施策 5: Unit テスト新設 — APPROVE**

指摘なし。正常系・空文字・未設定の 3 本で `LegalConsent` の責務を十分に押さえています。`InvalidArgumentException` を global use しない点も既存 gate に沿っています。

[Suggestion] `config()->string()` の例外メッセージは Laravel 側実装に依存するため、未設定ケースでメッセージ一致を避けている判断は正しいです。

**残リスク**

保証しないものの記述が正直で、スコープ外の 3 点もオーナー判断を尊重できています。特に ProductionEnvGuard への本番 `draft-1` 拒否は、このタスクに入れると effort 4 を超えるため、今回は入れない判断で問題ありません。