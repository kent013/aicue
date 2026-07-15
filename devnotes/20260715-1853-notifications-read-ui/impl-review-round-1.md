**総合判定**
- **APPROVED**

**ファイル別レビュー**

- `resources/js/components/features/notifications/NotificationListItem.svelte`
  - 判定: **OK**
  - [Suggestion] `markRead` の `event.stopPropagation()` は現状DOM構造では必須ではないですが、防御的実装として妥当です。コメントの意図も明確で保守性が高いです。
  - [Suggestion] `showReadButton = unread || reading` は「成功直後も busy 表示を維持しつつ DOM 消失タイミングを制御する」意図に合致しており良いです。将来 `reading` と `optimisticallyRead` の状態遷移が増える場合は、状態遷移表をコメント化するとさらに安全です。

- `tests/js/components/features/NotificationListItem.test.ts`
  - 判定: **OK**
  - [Suggestion] `lastReadOptions()` は最初の `/read` 呼び出しを返す実装なので、将来複数回 read を打つテストが増えたら「最後の `/read` 呼び出し」を返す形（`findLast` 相当）にしておくと誤検知を防げます。

**観点別評価**

- 設計との一致性: **一致**（open/read-all 維持、個別 read 追加、DOM分離、back完結前提、楽観state単調、相互排他、focus復帰）
- 正確性(ロジック・エッジ・null安全): **良好**（`unread` 導出・二重送信ガード・onError復帰が妥当）
- PHPStan L10 適合: **問題なし**（TS側も `ReadVisitOptions` 導入で型意図が明確）
- DTO・JsonResource パターン: **非該当変更**（フロントのみ、逸脱なし）
- テスト網羅性: **十分**（表示条件、成功/失敗、排他、二重送信、フォーカスまで検証）
- セキュリティ: **懸念なし**（IDを使った既存POST呼び出しのみ、権限境界を弱める変更なし）
- DESIGN.md 準拠: **準拠**（tokenユーティリティのみ、hex直書きなし）
- Atomic Design 準拠: **準拠**（features配下で完結、Lucide使用、SVG直書きなし）

**Critical / Warning**
- 該当なし（0件）