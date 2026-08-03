**全体判定**

`CHANGES_REQUESTED`

主要な論点は 2 つです。`120s` の出所特定と「Prism 直呼びを避けてストリーミングを却下する」判断は妥当ですが、`480s / 900s / 1560s / 1680s / 1800s` の時間 budget 連鎖がまだ閉じておらず、さらに `40 token/s` を設計不変条件として固定する根拠が不足しています。この 2 点を詰めない限り、会計不変条件と queue 安全性の論証が弱いです。

**1. 使命との整合性**

- `[Warning]` 方向性自体は North Star に合っています。`SOP -> AI 解析 -> カット設計` の起点が 120s で落ちるなら、ここを直す優先度は高いです。
  修正提案: 期待効果の記述を少し弱めて、「完走率を改善する」が主効果であり、「有意味な解析結果の保証」は別 defect（文字化け）解消後に初めて言える、と明記してください。

**2. 禁止事項違反**

- `[Suggestion]` 現時点の概念設計には明確な禁止事項違反は見当たりません。特にストリーミング化を却下した理由は、`Prism` 直呼び禁止との整合が取れています。
- `[Suggestion]` 「フロント変更不要」「DTO / JsonResource 変更なし」は妥当です。実装時も `response()->json()` 直書きに逃げないことを明記しておくと安全です。

**3. 実現可能性**

- `[Critical]` 時間 budget の算術がまだ自己完結していません。本文では「deadline は pipeline 開始時刻から 900s」としつつ、worst-case を `900 + 480 + 180 = 1560` としています。deadline が pipeline 全体を覆うなら `+180` は二重計上ですし、逆に `+180` を別枠で足すなら deadline の起点定義が違います。ここが曖昧だと `job timeout < retry_after < reservation TTL` の証明が崩れます。
  修正提案: deadline の開始点と終了点を 1 つに固定し、その同じ時計で `extract / 3 段 LLM / finalize / failJob / lock 待ち` まで含めた wall-clock 上限を再計算してください。式と実装コメントと Architecture test を同一モデルに揃えるべきです。
- `[Critical]` `16000 / 40 token/s + 60s = 480s` は、設計上のハード不変条件にするには根拠が弱いです。観測事実として示されているのは「120s では足りない」だけで、「40 token/s を下回る応答は失敗扱いでよい」まではまだ証明されていません。
  修正提案: `40 token/s` を CI で固定する前に、少なくとも対象 provider・対象 prompt の実測分布（p50/p95/p99）か、採用する運用 SLO を明示してください。難しければ exact 値 pin ではなく、「各 budget の順序関係」と「上限の一貫性」だけを invariant に落とす方が安全です。
- `[Warning]` `retry_after = 1680s` は `job timeout = 1560s` に対して余白が 120s しかなく、database queue の再可視化や worker 終了遅延を考えるとやや薄いです。
  修正提案: 120s で十分な理由を明記するか、TTL 1800s を超えない範囲で余白をもう少し厚くしてください。

**4. 期待効果の妥当性**

- `[Warning]` 「現実的サイズの SOP で AI 解析が完走できる」は合理的ですが、「290KB PDF の finding をこれで解消できる」とまでは言えません。同じ本文で文字化けが確定しているため、完走しても無意味な出力になる可能性があります。
  修正提案: 本施策の成功条件を「timeout 起因失敗の解消」に限定し、文字化け defect は blocking follow-up として同時起票する前提にしてください。
- `[Suggestion]` `120s` の出所特定は説得力があります。ここは docs/test に「`resources/prompts/*.yaml` の `client_options.timeout` が `config('prism.request_timeout')` を上書きする」と明文化しておくのが有効です。

**5. リスク**

- `[Warning]` retryable 例外集合の切り分けがまだ少し粗いです。`ConnectionException` と `PrismProviderOverloadedException(529)` だけでは、provider 側の `500/502/503/504` や read timeout が別型で上がる場合を取りこぼす可能性があります。
  修正提案: 実装前に「Prism + Anthropic で transient がどの例外型に写像されるか」の対応表を作り、retriable / non-retriable をテストで固定してください。
- `[Warning]` 429 を非 retry にする判断自体は理解できますが、「時間をおいて再実行」で本当に十分かは運用前提次第です。高頻度に 429 が出る環境では UX が悪化します。
  修正提案: 今回は fail-fast のままでもよいですが、少なくとも `429` を timeout/deadline と同じ文言に混ぜず、別理由として表示する設計にしてください。
- `[Suggestion]` 「ジョブ再配送での retry を採らない」は正しいです。チケット 2 フェーズと stale 回復の直列化点を跨がない、という設計判断は筋が通っています。

**6. スコープの適切さ**

- `[Warning]` 「timeout 是正 + bounded retry」にスコープを絞る方針は適切ですが、3 本の prompt を一律 `480s` に上げるのは少し粗いです。短い段にも同じ ceiling を与えるため、worker 占有を必要以上に伸ばす可能性があります。
  修正提案: 3 段とも `max_tokens=16000` だから同一 ceiling でよい、という明示根拠を補うか、段ごとの上限を分けるかを決めてください。
- `[Suggestion]` ストリーミング化を scope 外に置く判断は妥当です。現時点では over-engineering ではなく、禁止事項回避として正しい却下です。

**7. 型安全性**

- `[Warning]` 方針上は DTO/JsonResource パターンを壊していませんが、`config()` 由来の `analysis_deadline_seconds` や timeout 値は Laravel では `mixed` になりやすく、PHPStan level 10 で詰まりやすいです。
  修正提案: 生の `config()` を各所で読むのではなく、`int` を返す専用 accessor か小さな value object に寄せてください。`AnalysisFailedException::timedOut()` / `providerBusy()` も戻り型・理由コードを固定すると安全です。
- `[Suggestion]` 例外理由を文字列比較ではなく enum ないし明示的な reason code で持てば、Svelte 側はそのままでもサーバ側の分岐を型安全に保てます。

現状は「問題の芯は捉えているが、budget 算術と transient 判定の証明がまだ足りない」という評価です。そこを閉じれば、設計としてかなり強くなります。