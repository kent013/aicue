# 対応マトリクス: design-review Round 3 (判定 CHANGES_REQUESTED)

## [Warning] F / J: `TooLarge` / `InvalidEncoding` を実際にどう発生させるかが未定義
- 判断: 対応する
- 根拠: 指摘のとおり。設計自身が「素の経路では到達しない最後の砦」と書いているのに、
  Feature テストが素の fixture で到達する前提になっていた (自己矛盾)。
- 対応内容: 施策 F に「拒否をどこから注入するか」の表を新設した。
  - `TooLarge`: そのテスト内でだけ `config()->set('llm-defense.max_untrusted_bytes', 50)` に
    下げ、`analysis_min_text_bytes` (100) を満たす通常テキストを窓口で拒否させる。
    Laravel はテストごとにアプリを作り直すため config は他テストへ漏れない。
    committed な config の大小関係は施策 H の gate が別に固定しているので保証は弱まらない。
  - `InvalidEncoding`: `SopTextExtractor` を継承した test double を `$this->app->instance()` で
    差し込む。**`ExtractedText` の不変条件は緩めない** — この値オブジェクトはもともと
    UTF-8 を検査しておらず、保証は抽出器側にある。差し込みは「抽出器の保証が失われたときに
    窓口が fail-closed で止める」という、この砦が守るべき状況そのものの再現である。
  - 合言葉の漏洩: `GuardedPromptInspector` で組み立て済み prompt から合言葉を読み、
    その値を含む応答を返す fake を仕込む (reflection の閉じ込め先を再利用)。
  - **本番用の脱出口 (テスト専用フラグ・分岐) は 1 つも作らない**ことを明記した。
  施策 J の項目 12 からもこの表を参照させた。

## [Warning] K: `docs/template-divergence.md` の不変条件 4 が実装より強い
- 判断: 対応する (後者の案を採る)
- 根拠: 実装が拒否するのは合言葉 `llm_canary` の上書きと変数名の書式だけである。
  予約 namespace は現時点で必要がない (思考原則 2)。
- 対応内容: 不変条件 4 を「窓口は合言葉の変数名 `llm_canary` の上書きを拒否し、
  変数名を `/\A[a-z][a-z0-9_]*\z/` に限る。**予約 namespace は作らない**」へ書き換えた。

## [Suggestion] A: 追認方法 (`llm_call_logs` の token だけでは足りない)
- 判断: 対応する
- 対応内容: 追認は `dev:pipeline-smoke` の fixture について**各段へ渡る文字列の実バイト数を
  測る**方法に変え、token 数は補助材料と位置づけた。本番コードへ入力バイト数の常時観測は
  入れない (今必要でない観測を増やさない)。

## [Suggestion] B: 未使用の `use Webmozart\Assert\Assert;`
- 判断: 対応する
- 対応内容: 施策 B のコード例から削除した (`preg_*` の失敗は専用例外で扱うため不要)。
