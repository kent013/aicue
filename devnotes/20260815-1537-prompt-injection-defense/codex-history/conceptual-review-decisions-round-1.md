# 対応マトリクス: conceptual-review Round 1

全体判定は Round 1 で APPROVED。以下は指摘の捌き方 (詳細設計へ持ち込む条件を確定させる)。

## [Warning] 禁止事項 5 / PromptGuardrail の保証を縮めない (観点 2)
- 判断: 対応する
- 根拠: 「Prompt::load の呼び出しは app/Prompts/ に限る」を窓口 1 ファイルへ**縮小**するのは
  保証の強化であり縮小ではない。ただし縮小の向きを取り違えると穴になるため明示が要る。
- 対応内容: 概念設計に「`Prompt::load()` の許可先を `PromptDefense` ただ 1 ファイルへ縮小」と
  「帰属 metadata が `GuardedPrompt` 経由でも維持されることを inventory で固定」を明記。
  詳細設計の施策 F/H でテストまで書く。

## [Warning] GuardedPrompt に脱出口を作らない (観点 3)
- 判断: 対応する
- 根拠: vendor prompt 型を返す public メソッドがあると応答検査を迂回できる (正典 t1 の (b))。
- 対応内容: 公開面を `executeSync()` のみに固定し、gate で公開メソッド集合を完全一致 pin する。
  `withMetadata()` 相当は窓口の引数として受け、`GuardedPrompt` の外へ vendor 型を出さない。

## [Warning] カナリアの効能を誇張しない (観点 1・4)
- 判断: 対応する
- 根拠: AGENTS.md の書き方の規律 (保証範囲を誇張しない) と一致する。
- 対応内容: 「乗っ取りを防ぐ」ではなく「迂回経路を構造で塞ぎ、system prompt 漏洩を
  fail-closed で観測する」と書き直す。「保証しないもの」を詳細設計と docs/architecture.md に置く。

## [Warning] 無害化は何を拒否/除去/保持するかを明文化する (観点 5)
- 判断: 対応する
- 根拠: SOP は PDF/xlsx 由来で、除去が本文の意味を黙って変える危険が実在する。
- 対応内容: 詳細設計で「保持 = 改行・タブ・通常の空白」「除去 = 双方向制御文字と
  ゼロ幅・BOM」「拒否 = 長さ超過」の 3 分類を表で固定し、除去は**件数をログに残す**。
  切り詰め (truncate) はしない (黙って内容が変わるため)。

## [Warning] 窓口の長さ上限の値と失敗分類 (観点 5)
- 判断: 対応する
- 根拠: 2・3 段目の中間 JSON は SOP 本文と母集団が違う。値そのものより
  「どちらの上限で落ちたかが利用者に伝わるか」が重要という指摘は正しい。
- 対応内容: 窓口上限は「SOP 上限 (150,000) < 窓口上限」かつ「中間 JSON の実効上限
  (max_tokens 16,000 × UTF-8 で最大 4 bytes/token = 64,000 bytes 相当) を上回る」ことを
  根拠として置き、gate で `>= analysis_max_text_bytes` を pin する。窓口で落ちたときの
  ユーザー向け文言は既存の `tooLarge()` を再利用する (新語を作らない)。

## [Warning] 窓口引数の型安全性 (観点 7)
- 判断: 対応する
- 根拠: PHPStan level 10 で `array` の shape が緩むと窓口の意味が薄れる。
- 対応内容: `array<string, string>` を PHPDoc で明示し、空 key / カナリア予約 key との衝突 /
  未知の予約 key を窓口が拒否する (テストあり)。DTO 化まではしない (キー名は YAML 由来の
  自由集合であり、value object を挟む価値がない = オーバーエンジニアリング回避)。

## [Warning] inventory の役割変更で保証を曖昧にしない (観点 7)
- 判断: 対応する (一部反論)
- 根拠: inventory の deny-by-default 分類・untrusted 変数名・帰属キーは**維持**する。
  「別テストで PromptDefense が UserInput 化した値だけを vendor に渡すことを確認」も
  窓口 gate + 実行時テストで行う。ただし「窓口 gate と inventory で同じことを 2 回宣言する」形は
  避ける (思考原則 4)。役割分担を詳細設計に表で書く。
- 対応内容: 宣言 = 窓口 / 構造 = 窓口 gate / 組み立て結果の確認 = inventory、と 3 者を分ける。

## [Suggestion] canned response との互換を feature test で固定 (観点 3)
- 判断: 対応する
- 根拠: カナリア混入で canned 解決が壊れるとテスト基盤ごと信用を失う。
- 対応内容: 施策 I に「カナリア混入後も `CannedPromptResponses` が 4 YAML すべてを
  一意解決する」テストを含める。

## [Suggestion] template-divergence への固定条件 (trusted 入口)
- 判断: 対応する
- 根拠: 逸脱を「強化側」として説明可能にするための条件が具体的で妥当。
- 対応内容: 提示された 4 条件をそのまま `docs/template-divergence.md` の
  「保証し続ける不変条件」として登録する (施策 J)。
