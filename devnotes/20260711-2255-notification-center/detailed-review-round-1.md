以下、提示された**詳細設計書のみ**を対象にしたアーキテクトレビューです（未実装のため、実コード差分に対する最終判定ではなく**設計妥当性判定**）。

## 施策別判定

- 施策1（migrations + 保護キー）: **REQUEST_CHANGES**
- 施策2（型付き通知契約）: **APPROVE**
- 施策3（NotificationCenterService）: **REQUEST_CHANGES**
- 施策4（ジョブ終端配線 + triggered_by）: **APPROVE**
- 施策5（招待・残高低下配線）: **REQUEST_CHANGES**
- 施策6（ルート + Controller + DTO + shared props）: **REQUEST_CHANGES**
- 施策7（フロント）: **APPROVE**
- 施策8（テスト計画）: **REQUEST_CHANGES**

## 指摘事項

- [Critical] `notifications.type` に enum値を書き込む設計は、Laravel標準 `DatabaseNotification` の `type`（通常はクラス名）との互換性差分が大きく、既存コードや将来の通知読取ロジックで暗黙前提を壊すリスクがあります（施策1/2）。
  - 修正案: `type` は標準どおりクラス名を保持し、別カラム `notification_type`（enum値）を追加してUI/DTOはそちら参照。もしくは「このアプリではtype=enum値」を**全通知で強制**するArchitectureテストを追加し、運用規約として固定。

- [Critical] `NotificationListItemData` の説明に「未知 type は fromNotification が rawType のみで組む」とある一方、コンストラクタ型が `NotificationType $type` で矛盾しています（施策6）。
  - 修正案: `public ?NotificationType $type` に変更、`rawType` と排他的に扱う。`toArray()` は `type: string` を返し、`type ?? rawType ?? 'unknown'` を明示。

- [Critical] `open()` の manual遷移判定で `manualExists()` を事前確認する設計は、既存の `projects.manuals.show` 側の認可/404責務と二重化し、TOCTOU差異を招きます（施策6）。
  - 修正案: `open()` は `markRead` 後に `redirect()->route('projects.manuals.show', ...)` を基本とし、到達先の既存ガードへ委譲。削除済み等のフォールバックは到達先側の404処理方針に合わせて一本化。

- [Warning] `organization_id` を nullable にしているが、本機能の通知は全て組織文脈付きです。nullable はデータ品質の揺らぎ源です（施策1/2）。
  - 修正案: 本機能対象通知は `organization_id` 必須（DBは当面nullableでも、`AppNotification::organizationId(): int` へ寄せる）。nullableを残すなら「null許容通知種別」を明文化しテスト固定。

- [Warning] `notifyAnalysisFinished()` / `notifyRenderFinished()` で relation を都度辿るため、ジョブに紐づく manual/project/org が消えた際の分岐が曖昧です（施策3）。
  - 修正案: 「削除競合時は通知スキップ」を仕様として明文化し、Featureテストを追加。`Assert::isInstanceOf` 依存だけでなく明示的ガードで可読化。

- [Warning] `TicketLedgerService::commit()` の閾値クロス判定式は妥当ですが、`balance()` が Reserved控除を含むため将来仕様変更に脆いです（施策5）。
  - 修正案: `effectiveBalanceBeforeCommit()` 相当の専用privateメソッドを導入して意図を固定し、テスト名にも「Reserved含む実効残高」を明記。

- [Warning] `readAll` が未読0でも成功flashを返す設計は良い一方、連打時のUXノイズが出ます（施策6/7）。
  - 修正案: サーバは現状維持でOK。フロント側で「直近実行中」のみ抑制（disabledではなく二重送信防止）を追加。

- [Suggestion] `notifications.unreadCount` を全レスポンスshareするより、将来ポーリングを見据えて partial reload専用キーとして利用する運用をREADMEに追記すると保守性が上がります（施策6）。
- [Suggestion] `NotificationTypeTsSyncInvariantTest` は既存 `ManualEnumTsSyncInvariantTest` の抽象化ヘルパを再利用して重複削減を推奨（施策8）。
- [Suggestion] `NotificationCenterService::jobRecipients` は `creator`/`triggeredBy` 以外の将来拡張（watcher等）を想定し、`resolveRecipientsForManualJob()` など命名をより責務指向にするとよいです（施策3）。

## レビュー観点別サマリ

- 正確性: 主要フローは良好。ただし `type` 互換性とDTO型矛盾は要修正。
- 既存整合性: Service委譲・Inertia利用は整合。`open()` の責務重複が課題。
- PHPStan Lv10: 方向性は良い。`NotificationListItemData` の型矛盾解消が必須。
- テスト網羅: 量は十分。**互換性回帰（DatabaseNotificationのtype前提）**テスト追加を推奨。
- DTO/JsonResource: 生配列境界を絞る方針は良い。
- Inertia vs API: 本件はInertia中心で妥当。
- 副作用/後退: `DatabaseChannel` 差し替え影響面の回帰テストを厚くすべき。
- 波及変更: TS同期・Architecture test計画は適切。
- セキュリティ: cross-user 404、POST open、payload不信任方針は良好。
- DESIGN/Atomic: token/Lucide/階層方針は概ね準拠。

## 全体判定

- **CHANGES_REQUESTED**

必要な修正の中核は3点です。  
1) `notifications.type` 戦略（互換性）を固定、2) `NotificationListItemData` の型矛盾解消、3) `open()` の責務重複解消。  
この3点が解消されれば、全体はAPPROVE可能な設計品質です。