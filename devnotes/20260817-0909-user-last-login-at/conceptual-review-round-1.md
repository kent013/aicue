全体判定: **APPROVED**

**使命との整合性**
[Suggestion] 使命への貢献は直接ではなく運用支援だが、設計自身がそれを正直に位置づけている。T201 の受け皿、オンボーディング不全検出、休眠アカウント棚卸しという用途は North Star を支える周辺機能として妥当。

**禁止事項違反**
[Suggestion] 明確な違反は見当たらない。カラム追加なし、書き込み経路追加なし、操作 route 追加なし、`response()->json()` なし、disabled UI なしという整理は規約に沿っている。

**実現可能性**
[Warning] `LastLoginLookup` の戻り値型は設計段階で明確にしておくべき。`max(occurred_at)` は DB/Query Builder 経由だと文字列・Carbon・DB 固有型に揺れやすい。

修正提案: サービス境界で `array<int, CarbonImmutable>` または `array<int, string>` のどちらかに正規化し、DTO 側では `?string` の ISO8601 だけを受ける形に固定する。PHPStan 用に `@return array<int, CarbonImmutable>` などを明記する。

**期待効果の妥当性**
[Suggestion] 「新しい状態を増やさずに表示できる」「N+1 を避ける」「休眠検出に使える」は合理的。ただし「記録なし」は設計どおり断定しない文言にする必要がある。

**リスク**
[Warning] 導出元を `security_audit_events` にする判断は妥当だが、保持期間への依存は実装時に機械的なトリップワイヤがほしい。台帳の根拠文追記だけでもレビュー導線にはなるが、将来の purger 追加時に見落とされる余地は残る。

修正提案: `RetentionTableRegistry` の `security_audit_events` 根拠文に依存を明記するだけでなく、可能なら Architecture test でその文言または分類理由が消えたら落ちるように pin する。少なくとも実装 PR のテストに「保持期間未確定のまま、この表示は `記録なし` と表現する」ことを固定する。

**スコープの適切さ**
[Suggestion] スコープは適切。最終活動、履歴、絞り込み、CSV、自動通知を入れない判断はよい。

**型安全性**
[Warning] DTO/TS の nullability 契約を明示する必要がある。

修正提案: `MemberRowData` に `public ?string $lastLoginAt` のような ISO8601 文字列を追加し、`resources/js/types/admin.ts` も `lastLoginAt: string | null` に揃える。Svelte 側では `null` 分岐後にのみ `formatDateTime()` を呼ぶ。

**重点論点**
- `users.last_login_at` を足さない判断: **妥当**。既存の `login` 監査行が事実の正本として機能しているなら、列追加は二重記録になる。決定的にカラムが必要になるのは、監査表の保持期間が短く確定した場合、または一覧表示の性能要件が監査表集計で満たせないと実測された場合。
- 保持期間との結合: **現時点では十分。ただしテストで可視化推奨**。今すぐカラムを足す理由にはならない。
- 数え方の網羅性: **概ね妥当**。remember me を数える判断も、「最終認証」ではなく「最後に入った時刻」として扱うなら筋が通っている。
- 認可とプライバシー: **妥当**。owner/admin が既に氏名・メール・ロール・削除・2FA リセットを扱える境界なら、最終ログインを同じ境界に置くのは合理的。
- 索引置き換え: **妥当**。`['user_id','event_type','occurred_at']` は既存索引の用途を包含し、この機能の `max(occurred_at)` にも効く。追加ではなく置き換えにしている点もよい。実装時は index 名と migration の lock 影響だけ確認すること。