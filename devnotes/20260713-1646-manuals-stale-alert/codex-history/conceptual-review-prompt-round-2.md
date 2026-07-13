# 概念設計レビュー Round 2

Round 1 の 2 つの [Warning] と Suggestion を反映した。判定を再確認してほしい。

## 対応サマリ

### [Warning] 観点3: `job.id` 単体監視の穴 → 対応
再同期トリガーを **署名比較**に変更した。
`signature = `${hasDocument}|${job?.id ?? "none"}|${job?.status ?? "none"}|${manualStatus}``
`job.status` を署名に含めることで、同一 job id の状態遷移 (queued→running→failed) も新スナップショットとして検知する。前回署名は非リアクティブなローカル変数で保持し、一致なら早期 return。

### [Warning] 観点4: overlay 全消去が別種エラーを隠す → 対応 (UX 原則の明文化 + 効果範囲の限定)
「新しいサーバスナップショットが届いたら transient overlay を無条件に破棄する」という UX 原則を明文化した。
- 402: overlay は消えるが、再度 AI 解析を押せばサーバが残高を再判定して再表示 (押下時エラー表示の原則は維持)。
- 401/419: sibling の Inertia POST が成功した事実は session 有効を含意するので、失効案内の破棄が正 (残すと矛盾)。
期待効果の「他 reload 起因の stale も一括解消」という誇張は削り、「Show 内 sibling 操作由来の stale」に限定した。

### [Warning] 観点5: `currentJob/status` まで毎回 props に戻すのは影響範囲が広い → 対応 (broad re-sync を除外)
設計を **overlay (errorMessage/showPurchaseLink/sessionExpiredMessage) のクリアのみ**に縮小した。
`currentJob`/`status` の seed-once は温存 (XHR/reload 経路が既に更新済み。422 は job を作らないので job は null のまま stale にならない)。broad な再同期は poll 駆動 state と干渉するため最小責務から除外 (禁止事項#6)。実装コメントで transient overlay と server-truth 由来 (currentJob/failedJob) の区別を明記する。

### Suggestion 反映
- 受け入れ基準 (acceptance criteria) を明記: (1) 422→SOP upload成功で alert 即時消失, (2) ポーリング中の進捗/step/2.5秒間隔は不変, (3) failedJob alert は非退行, (4) typecheck/lint/build/vitest green。
- エラー時ボタン disabled にしない原則 (禁止事項#8) 維持を明記。
- errorMessage/showPurchaseLink/sessionExpiredMessage の型は現状維持、currentJob/status を props 型から逸脱させない旨を明記。

## 更新後の要点 (差分の実体)

- 変更は AnalysisPanel.svelte 1 ファイル。
- overlay-clear `$effect` を追加。署名 (hasDocument | job.id | job.status | manualStatus) の変化時のみ
  errorMessage=null / showPurchaseLink=false / sessionExpiredMessage=null。
- currentJob/status は触らない (seed-once 温存)。
- SourceDocumentUpload / backend は変更不要。

これで APPROVED か、残る Critical/Warning があれば指摘してほしい。
