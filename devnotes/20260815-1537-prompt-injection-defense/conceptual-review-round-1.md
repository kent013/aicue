全体判定: **APPROVED**

概念設計としては妥当です。AG-028 の「保証内容で揃える」という条件に沿っており、aicue の実運用 LLM 経路が SOP 起点である以上、使命への貢献も本質的です。trusted 入口を作らない判断も、現時点で trusted 変数が 0 件なら妥当です。入口を先に作る方が「trusted に逃がす」誤用経路を増やすため、aicue では作らない方が強い設計です。

## 1. 使命との整合性

[Suggestion] 期待効果の書き方は少しだけ補正した方がよいです。  
カナリアは「プロンプト漏洩の観測」には効きますが、「インジェクション成功そのものの完全検出」ではありません。使命への貢献は十分ありますが、文書では「乗っ取りを防ぐ」より「迂回経路を塞ぎ、漏洩を fail-closed に観測する」と表現した方が正確です。

## 2. 禁止事項違反

[Warning] `PromptDefense::load()` 内で `Prompt::load()` する設計自体は問題ありませんが、禁止事項 5 の「Prism 直呼び禁止」と `PromptGuardrailTest` の保証内容を縮めないようにする必要があります。  
修正提案: `Prompt::load()` を許可する唯一の場所を `PromptDefense` に限定し、`app/Prompts/*Prompt.php` からの `Prompt::load()` 直呼びを gate で禁止してください。あわせて `withMetadata($context->toMetadata())` が `GuardedPrompt` 経由でも必ず維持されることをテストで固定してください。

## 3. 実現可能性

[Warning] `GuardedPrompt` が vendor prompt 型を public に返さない方針は良いですが、既存 factory が使っている fluent API や実行オプションをどう包むかが実装上の要点です。  
修正提案: `GuardedPrompt` は「必要な実行面だけ」を公開し、metadata / model / timeout / JSON 指定など既存 4 factory が必要とする設定を constructor 内で完結させる設計にしてください。vendor prompt を取り出す `inner()` のような脱出口は作らない方がよいです。

[Suggestion] `Prompt::fake()` / canned response との互換は、前提に置くだけでなく fixture 付きの feature test で固定するとよいです。カナリア混入で canned 解決が壊れるとテスト全体の信頼性が落ちます。

## 4. 期待効果の妥当性

[Warning] 応答カナリアは「system prompt や防御指示をそのまま吐いた」ケースには有効ですが、JSON として妥当な悪性シナリオを返すケースは止められません。  
修正提案: 期待効果から「LLM 出力内容の安全審査」を明確に外している点は維持しつつ、カナリアの効能を「漏洩検知・異常観測」に限定して記述してください。過大な安全保証に見せないことが重要です。

## 5. リスク

[Warning] `UntrustedTextSanitizer` が制御文字・不可視文字を「除去」する場合、SOP 本文や中間 JSON の意味を黙って変えるリスクがあります。特に PDF 抽出由来の文字は、不可視に見えてもレイアウトや単語分割に関わる場合があります。  
修正提案: 何を拒否し、何を除去し、何を保持するかを allowlist/denylist として明文化してください。少なくとも bidi 制御文字は除去でよい一方、長さ超過は切り詰めではなく拒否が妥当です。除去件数を内部ログに残す設計も検討してください。

[Warning] `max_untrusted_bytes >= analysis_max_text_bytes` の gate は良いですが、2 段目・3 段目の LLM 中間 JSON は SOP 本文より膨らむ可能性があります。  
修正提案: 窓口上限は SOP 上限との大小だけでなく、「中間 JSON が通常運用で到達しうるサイズ」を踏まえた値にしてください。ここは閾値チューニングではなく、失敗時の分類とユーザー文言が重要です。

## 6. スコープの適切さ

[Suggestion] スコープは適切です。ffmpeg、MCP write tool、内容安全審査を外している判断も妥当です。今回の目的は AG-028 t1 追従であり、別レイヤーの安全審査まで混ぜると設計がぼやけます。

## 7. 型安全性

[Warning] `PromptDefense::load(string $template, array $untrusted)` は概念上は十分ですが、PHPStan level 10 を考えると array shape が弱くなりやすいです。  
修正提案: PHPDoc で `array<string, string>` を明示し、必要なら専用 DTO または value object を使ってください。少なくとも非文字列値、空 key、canary key との衝突を拒否するテストは必要です。

[Warning] `PromptUntrustedInputContractTest` の役割変更は妥当ですが、「型検査を behavioral 確認に読み替える」だけだと保証が曖昧になりやすいです。  
修正提案: inventory は factory ごとの untrusted 変数名と帰属メタデータを引き続き deny-by-default で固定し、別テストで `PromptDefense` が最終的に `UserInput` 化した値だけを vendor に渡すことを確認してください。

## trusted 入口を作らない判断

[Suggestion] **妥当です。** 現時点で trusted 変数が 0 件なら、`trusted` 引数を作らない方が設計として強いです。  
ただし、将来 trusted が必要になった時に「生 string を一時的に untrusted に混ぜる」運用にならないよう、`docs/template-divergence.md` に次を固定するとよいです。

- 現在の YAML 変数はすべて untrusted または canary のみ
- trusted 入口は存在しない
- trusted 変数を追加する PR は、入口・字句 gate・inventory を同時に追加する
- `PromptDefense` は未知の予約 key や canary key の上書きを拒否する

この条件なら、テンプレートからの逸脱ではなく、aicue の現状に合わせた強化側の実装として説明できます。