全体判定: CHANGES_REQUESTED

### 1. 使命との整合性

[Suggestion] PC 管理画面から撮影ナビへの再選択を除去しており、North Star に直接貢献する。PC→スマホ実機のハンドオフは対象外と明記され、期待範囲も適切になった。

### 2. 禁止事項違反

[Warning] テスト方針が概念設計に含まれていない。思考原則の「テストファースト」と、テストなしの実装完了報告禁止を満たすため、実装方針に以下を追加すること。

- predicate の `ready/published=true`、その他の全状態=`false`を検証する単体テスト
- Show/Edit で対象状態のみリンクが表示されるコンポーネントテスト
- Show で `canManage=false` でもリンクが表示されるテスト
- リンク先URLが対象project/manualを使用するテスト

非撮影状態でリンクを非表示にする方針は、disabledを禁止する事項 #8 に抵触しない。

### 3. 実現可能性

[Suggestion] Laravel 12、Svelte 5、Inertia.jsの既存構成で実現可能。サーバ変更を伴わず、既存URL生成方式にも整合している。

### 4. 期待効果の妥当性

[Suggestion] 「アプリ内導線を3〜4クリックから1クリックへ短縮」という効果は合理的。スマホへの端末間移行を解決しない点も明確になった。

### 5. リスク

[Suggestion] Editの未保存変更破棄は既存キャンセルと同一セマンティクスであり、本変更固有の回帰とは扱わない判断を支持する。アプリ全体のdirty-navigation対応を別課題とするスコープ分離も妥当。

### 6. スコープの適切さ

[Suggestion] 逆方向リンク、Ziggy導入、撮影権限DTO追加を除外した範囲は適切。Low-Medの導線改善として過大ではない。

### 7. 型安全性

[Warning] predicateの集約は画面間の重複を防ぐが、「将来statusが増えても見落としを防ぐ」とまでは保証しない。単なる配列では新しいcase追加時にコンパイルエラーにならないため、記述を「判定箇所を一元化する」に修正すること。

完全性まで保証するなら、全`VideoManualStatus`をキーに持つ `satisfies Record<VideoManualStatus, boolean>` の対応表とし、新しいstatus追加時に型エラーを発生させる設計が適切。PHP側変更がないため、PHPStan level 10への新たな影響はない。