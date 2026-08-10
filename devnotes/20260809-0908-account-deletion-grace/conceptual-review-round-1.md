全体判定: **CHANGES_REQUESTED**

## 使命との整合性

[Warning] 即時削除 route を「一切変更しない」まま副導線として残す設計は、改善の主目的である「誤操作・乗っ取り時に取り返せる窓を作る」とやや衝突します。  
修正提案: 標準形として即時削除の併存が必須なら、即時削除は `recent-auth` より強い確認、明示文言、二段確認、SecurityEvent 記録を必須化し、「通常 UI の主導線は予約のみ」であることを設計条件に明記してください。

[Suggestion] North Star への貢献は妥当です。唯一 Owner や現場運用者の誤操作から動画マニュアル資産への到達手段を守る点は、本質的にアプリ価値を支えています。

## 禁止事項違反

[Warning] `deleteAccount()` をバッチからそのまま呼ぶ方針はよい一方、既存メソッドが HTTP セッション・認証ユーザー・FormRequest・redirect 前提を持っている場合、Console 経路から呼ぶと層の混在が起きます。  
修正提案: `deleteAccount()` の中核を Service の純粋なユースケースメソッドに寄せ、Controller と Command は同じ Service を呼ぶ、と明記してください。HTTP 応答や session flash は Controller 側に閉じるべきです。

[Suggestion] `response()->json()` 直書き、Prism 直呼び、prompt 直書き、disabled UI などの明示的な禁止事項違反は、この概念設計上は見当たりません。

## 実現可能性

[Warning] 凍結範囲を `require-active-subscription` group の構造に乗せる方針は実現可能ですが、`organizations.*` を丸ごと可にするのは粗いです。組織管理 route の中に、退会ブロッカー解消ではなく状態を増やす操作が混じる可能性があります。  
修正提案: 「予約中に許可する `organizations.*` の操作 inventory」を作り、移譲・メンバー整理など必要操作だけを behavioral test で固定してください。group 外なら全通し、ではなく「詰み回避に必要な変更系だけ可」が安全です。

[Warning] 依存閉包 gate を `PhpToken::tokenize` だけで実装する設計は、Laravel の container 解決、trait、facade、動的 call、interface 経由の到達を取りこぼす可能性があります。  
修正提案: gate の保証範囲を狭く明記した上で、起点クラスの constructor 型、method parameter、static call、facade call、`app()` / `resolve()` / container binding への最低限の検出を含めてください。可能なら正負 fixture に「型注入だけ」「facade 経由」「trait 経由」を入れるべきです。

## 期待効果の妥当性

[Warning] 「予約実行時に課金ガードを再評価する」が `deleteAccount()` 再利用で構造的に保証される、という主張はやや強いです。既存経路が HTTP 前提を含んでいる場合、再利用できるのは一部だけです。  
修正提案: 保証対象を「同一 Service の blocker 判定を Command と Controller が共有する」に言い換え、Feature test で「予約時は通ったが執行時に blocker が立った場合は削除されない」を固定してください。

[Suggestion] 保持年数の「照合」ではなく「単一出典化」で drift を減らす方針は妥当です。Blade の節消失を behavioral test で見る設計もよいです。

## リスク

[Critical] 保持期間 purge の対象が「課金取引記録」としか書かれておらず、対象テーブル・対象列・削除方式が未定義です。ここが曖昧なまま実装に進むと、削ってはいけない証跡を消す、または消すべき PII を残すリスクがあります。  
修正提案: PR-C の前提として `BillingRetentionTargetInventory` を設計してください。少なくとも対象モデル/テーブル、日時基準列、削除方式、保持すべき集計・監査情報、dry-run 出力形式を列挙する必要があります。

[Warning] 執行時 blocker が立った場合に「予約維持 + report()」だけだと、ユーザーが気づかず永久凍結に近い状態になる可能性があります。  
修正提案: 執行失敗時はアプリ内通知だけでなく、設定画面の予約バナーに「執行できない理由」と「次の再試行予定」を出す設計にしてください。少なくとも `last_deletion_attempt_at` / `last_deletion_blocked_reason` 相当を持つか、既存 blocker props で常に説明できることを明記してください。

[Warning] メール通知を完全にスコープ外にする判断は、乗っ取り起点の予約に対して弱いです。  
修正提案: 今回送らないなら、SecurityEvent と画面通知で足りる理由ではなく、「メール通知は別 PR の明示 TODO」として残す方が安全です。乗っ取り検知は画面内通知だけでは成立しません。

## スコープの適切さ

[Warning] A/B/C の分割自体は妥当ですが、PR-A の redaction 記録列は猶予削除本体と独立しすぎており、利用されない列だけが先に main に入る可能性があります。  
修正提案: PR-A 単独完結の定義に「runbook から実際にこの command を使う運用手順」「二重実行時の表示」「対象 organization の解決方法」を含めてください。

[Suggestion] 3 PR 直列、feature flag なし、各 PR 単独で main を壊さないという整理は適切です。

## 型安全性

[Warning] Inertia props / Svelte 型の設計が概要に留まっています。`deletion_requested_at` / `deletion_purge_after` / blockers / notifications を配列で直渡しすると PHPStan level 10 と DTO 方針から外れやすいです。  
修正提案: `AccountDeletionStateData` などの DTO または既存 Inertia props DTO に寄せ、Controller から Svelte まで nullable datetime の形式を固定してください。

[Warning] `billing:mark-stripe-customer-redacted {organization}` が route model binding 的に直接 `Organization` を取る場合、console 入力由来 ID の扱いと direct fetch inventory の分類が必要です。  
修正提案: Command 内の organization 解決方法を明記し、`ModelDirectFetchInvariantTest` / `DirectFetchInventory` へ登録する前提を PR-A に含めてください。

結論として、方向性はかなり妥当です。ただし **保持 purge 対象の未定義** と **凍結中に許可する変更系 route の粗さ** は、実装前に詰める必要があります。