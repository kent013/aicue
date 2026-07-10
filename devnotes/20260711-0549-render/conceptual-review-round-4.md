全体判定: **CHANGES_REQUESTED**

Criticalはありません。設計本体はほぼ収束していますが、責務分離と並行テストの実証方法に修正が必要です。

### 1. 使命との整合性

[Suggestion] 完成動画生成とpreviewの品質・安全性を維持しており、North Starとの整合性に問題ありません。

### 2. 禁止事項違反

[Warning] 出力reconciliationを`render:recover-stale-jobs`へ追加すると、「stale jobを回復する」という機能名と責務から外れます。異なる概念を運用上都合がよいという理由で統合する形になります。

修正提案: `render:reconcile-outputs`などの専用commandとして分離してください。schedule周期や内部Serviceの共用は可能ですが、コマンドの責務とテストは分けるべきです。

### 3. 実現可能性

[Warning] DB connectionを2本用意するだけでは、2本目の同期呼び出しがOrganizationロック待ちで停止し、1本目がロックを解放できません。並行実行主体が必要です。

修正提案: subprocessなどを使い、次の順序を同期ポイント付きで検証してください。

1. connection AがOrganization行ロックを保持する。
2. process Bで`triggerPreview()`を開始する。
3. Bが完了していないことを確認する。
4. Aをcommitする。
5. Bが完了し、解放後のin-flight件数を基に判定したことを確認する。

時間依存だけにせず、開始通知や短い`lock_timeout`も利用してテストのハングを防いでください。

### 4. 期待効果の妥当性

[Suggestion] 即時削除jobと定期reconciliationの二重経路により、「最新1世代へ収束」という表現に見合う効果を期待できます。

### 5. リスク

[Suggestion] ASS serializerでは`{}`だけでなく、リテラルの`\N`、`\n`、`\h`、CR、制御文字も正規化対象として明記すると安全です。字幕生成を専用Serviceへ隔離し、入力と生成ファイルの双方をテストするのが適切です。

### 6. スコープの適切さ

[Suggestion] reconciliationを専用commandに分離しても小規模な運用保証に留まり、v1スコープを不当に広げるものではありません。

### 7. 型安全性

[Suggestion] 3値で閉じたPHP enum、DB cast、readonly DTO、TS union、値集合同期テストで十分です。PHPStan level 10を阻害する設計上の問題は見当たりません。