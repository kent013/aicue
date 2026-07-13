**全体判定: CHANGES_REQUESTED**

提供された概念設計の範囲では、問題設定と基本方針は妥当です。特に「Show 内の sibling 操作で Inertia props だけ更新され、AnalysisPanel のローカル state が stale 化する」という仮説は筋が通っています。  
ただし、再同期トリガーとクリア対象の定義がまだ粗く、このままだと「直したい stale は消えるが、別の正当な状態まで消す」「サーバ props へ再同期すると言いながら一部の props 変化を取りこぼす」懸念が残るため、設計の明確化が必要です。

**1. 使命との整合性**
- [Critical] なし
- [Warning] なし
- [Suggestion] 使命との整合性は高いです。SOP アップロード直後に「手順書をアップロードしてください」が残るのは、SOP→解析→撮影という中核導線の信頼性を直接損ねます。v1 の主要体験に対する改善としてスコープも適切です。

**2. 禁止事項違反**
- [Critical] なし
- [Warning] なし
- [Suggestion] 設計上は禁止事項への抵触は見当たりません。frontend-only で `response()->json()` 追加もなく、LLM 呼び出しや prompt 直書きもありません。実装時も「エラー時にボタンを disabled にしない」という既存原則を維持する前提を明記するとより安全です。

**3. 実現可能性**
- [Critical] なし
- [Warning] `job.id / hasDocument / manualStatus` だけを監視条件にする案は、`job` の中身が変わっても `id` が同じなら再同期されない穴があります。設計文では「currentJob を最新の job prop に揃える」と言っているのに、検知条件がそれを十分に保証していません。  
  修正提案: 再同期判定は `job.id` 単体ではなく、UI が依存する `job` の識別子・状態・更新時刻相当を含む比較にしてください。更新時刻が無いなら「props の job オブジェクト参照変化」または「必要フィールドの署名比較」を明示した方がよいです。
- [Suggestion] Svelte 5 の `$effect` で reconciliation する方向自体は実現可能です。ただし「何を読んで、何を書き、どの条件で no-op にするか」を明文化して、effect が過剰発火しない設計にしておくと実装がぶれません。

**4. 期待効果の妥当性**
- [Critical] なし
- [Warning] 「props 変化時に `errorMessage / showPurchaseLink / sessionExpiredMessage` を全部クリアする」は、 stale 解消には効きますが、未解決の別種エラーまで隠す可能性があります。特に 402 の購入導線や 401/419 のセッション系メッセージは、「SOP がアップロードされた」こととは因果がありません。  
  修正提案: クリア方針を原因別に分けてください。少なくとも今回の finding に直結する 422 missing-document は `hasDocument: false -> true` を契機に消す、といった条件付きにした方が説明可能性が高いです。全消去にするなら、「新しい Inertia スナップショットが来た時点で overlay は常に破棄する」という UX 原則を明文化し、402/401/419 もその原則に従ってよい理由を補強してください。
- [Suggestion] 「他の Inertia reload 起因の stale も一括で解消」は少し言い過ぎです。今回の設計で確実に解けるのは Show 内 sibling 操作由来の stale です。効果の記述はそこに寄せた方が設計として堅いです。

**5. リスク**
- [Critical] なし
- [Warning] `hasDocument` の変化を契機に `currentJob/status` まで毎回 props に戻す設計は、局所的な stale 修正に対して影響範囲が広めです。問題の本質は「古いエラー overlay の残留」なので、job/status 再同期まで同時にやる必要性はもう一段整理した方がよいです。  
  修正提案: 責務を分けてください。最低限の修正は「overlay のクリア」で、`currentJob/status` 再同期は本当に stale が確認されている場合だけ入れる、という二段構えにすると後退リスクを下げられます。
- [Suggestion] `failedJob alert` は対象外と書かれていますが、同じ panel 内で「何が transient overlay で、何が server truth 由来なのか」の区別を実装コメントか設計文で明確にしておくと、将来の改修で混線しにくくなります。

**6. スコープの適切さ**
- [Critical] なし
- [Warning] なし
- [Suggestion] 1 ファイル・frontend-only で閉じる方針は適切です。ただしスコープを守るために、成功条件を明示してください。例: 「Show 画面で 422 missing-document を出した後、同画面で SOP アップロード成功すると alert が即時消える」「ポーリング中の job 表示は壊れない」。ここまで定義しておくと過不足がなくなります。

**7. 型安全性**
- [Critical] なし
- [Warning] なし
- [Suggestion] backend 変更がないため DTO/JsonResource/PHPStan への直接影響はありません。Svelte 側では `job` と `status` のローカル state を props 型から逸脱させないこと、`null` 許容の扱いを既存型に合わせることを設計上の注意点として追記すると十分です。

**総評**
方向性は正しいです。  
ただし承認前に、少なくとも次の 2 点は設計に反映してください。

1. 再同期トリガーを `job.id` 単体より厳密にする。  
2. transient メッセージのクリアを一律全消去ではなく、原因別に定義する。  

この 2 点が固まれば、North Star に対して小さく効く、実装可能で副作用の読める改善になります。