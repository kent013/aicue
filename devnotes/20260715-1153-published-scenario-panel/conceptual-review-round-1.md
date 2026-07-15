全体判定: **CHANGES_REQUESTED**

1. 使命との整合性
- [Warning] `published` / `rendering` で「未生成」案内を消す方向性自体は、シナリオ起点で撮影を進める製品の信頼性回復に直結しており妥当です。ただし、説明文だけ直しても `published` / `rendering` で解析 CTA が残り、押すと `409` になるなら「思考ゼロ」の体験はまだ崩れます。修正提案: この PR で CTA 露出も見直すか、少なくとも期待効果を「文言整合の回復」に限定して記述してください。

2. 禁止事項違反
- [Suggestion] 概念設計の範囲では禁止事項への抵触は見当たりません。実装完了条件にはフロントの回帰テスト追加を明記してください。

3. 実現可能性
- [Critical] `SCENARIO_PRESENT_BY_STATUS` / `hasScenario(status)` という抽象は、設計本文自身が認めている `draft + cuts` 到達可能状態と意味論が矛盾しています。これは「status から UI 文言を決める」だけの最小修正を、「cuts 実在判定」という誤った共通概念に拡張してしまっています。修正提案: status ベース案を採るなら、名前を `SCENARIO_ESTABLISHED_BY_STATUS` / `isScenarioEstablishedPhase(status)` などに改め、「UI 表示相の判定」であることを明示してください。真の cuts 実在を扱う必要が出た時だけ server prop 案へ進むのが筋です。

4. 期待効果の妥当性
- [Warning] 状態バッジと説明文の矛盾解消は合理的に期待できますが、「シナリオ有無を正しく判定できる」という効果まで主張すると過大です。複製直後の `draft + cuts` はなお拾えません。修正提案: 効果は「`ready/rendering/published` における誤案内の解消」に限定し、汎用的な “scenario presence” 解決とは言わないでください。

5. リスク
- [Warning] status invariant 依存は今回の最小修正としては許容できますが、将来 status が増えた時に “cuts 実在” と “UI 文言相” が再び混線するリスクがあります。修正提案: `satisfies Record<VideoManualStatus, boolean>` に加えて、コメントやテスト名で「表示相の判定」であることを固定してください。
- [Warning] `published/rendering` で再解析不可なのに、別 UI 要素が再解析可能に見えるままだと、今回の文言変更が逆に新しい混乱を生みます。修正提案: `AnalysisPanel` 全体の affordance を状態別に棚卸しし、少なくとも非解析状態の CTA は表示しないか別メッセージに差し替えてください。

6. スコープの適切さ
- [Warning] 複製直後の `draft + cuts` を今回スコープ外にする判断自体は、F-1-03 を最小 blast radius で直す観点では妥当です。ただし、その前提なら helper を汎用化しすぎてはいけません。修正提案: スコープを「公開済み/書き出し中で未生成案内が出る不整合」に限定すると明記し、複製直後表示は別 finding / TODO として起票前提にしてください。

7. 型安全性
- [Suggestion] 純フロントで server prop を増やさない判断は、DTO / JsonResource / Inertia props への波及を避ける点で妥当です。`satisfies Record<VideoManualStatus, boolean>` で union の分岐漏れを検出する方針も良いです。実装時は `ready / rendering / published / draft / analyzing` の表示分岐を固定するテストを追加してください。

特に判断してほしい論点への回答:
- status ベース判定に留める設計判断は、**この不具合を最小変更で直す**という目的には妥当です。ただし、その場合の概念名は “cuts 実在” ではなく “状態相に基づく表示判定” に寄せるべきです。`hasScenario` という命名のまま進めるのは不適切です。
- 複製直後の `draft + cuts` をスコープ外にする判断は、**別 issue として明示管理するなら**妥当です。逆に、これを認識しつつ `SCENARIO_PRESENT_BY_STATUS` のような命名で吸収したことにするのは妥当ではありません。