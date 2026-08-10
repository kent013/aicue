全体判定: **CHANGES_REQUESTED**

直前の [Critical] だった `failClosed` 除外の文書内矛盾は、提示文面上は解消されています。§3 / §4-3b / §4-3c / §6 で一貫して「`failClosed` を含む、分類を問わない」になっています。

## 使命との整合性

[Warning] `notifications.open` を凍結 allowlist に入れる設計が、deny-by-default を迂回する可能性があります。  
`notifications.open` が通知のリンク先へ redirect する実装なら、凍結中ユーザーが通知経由で `dashboard` / manuals / capture / billing checkout 等へ到達できる抜け道になります。これは「予約中は新しい損失やブロッカーを増やさない」という凍結の目的を崩します。

修正提案: 概念設計段階で次のどちらかを明記してください。

- `notifications.open` は凍結中、allowlist 内 route への遷移だけ許可し、それ以外は `/settings` へ落とす
- あるいは `notifications.open` を allowlist から外し、通知本文閲覧だけを許可する

この点は詳細実装ではなく、凍結境界そのものの設計判断なので Warning です。

## 禁止事項違反

[Suggestion] 禁止事項への明確な抵触は見当たりません。  
`response()->json()` 直書き回避、disabled 回避、Prism 直呼び回避、DTO 化、route:cache 前提などは設計上かなり意識されています。

## 実現可能性

[Suggestion] Laravel 12 + Svelte 5 + Inertia.js で実現可能です。  
ただし依存閉包 gate と ledger reader 目録は実装難度が高いので、詳細設計では「検出できる形 / 検出しない形」の fixture を先に固定するのが妥当です。

## 期待効果の妥当性

[Warning] 「凍結中でも通知を読める」効果は妥当ですが、`notifications.open` が遷移機能を持つ場合は副作用が効果を上回ります。  
通知センターは rescue surface である一方、route trampoline になりやすい面です。

修正提案: 期待効果の成功条件に「通知経由でも凍結対象 route へ到達できない」を追加してください。

## リスク

[Warning] 永久凍結リスクへの対策は概ね整理されていますが、`settings` に到達できるだけでは足りず、`settings` 内でブロッカー解消先へ確実に遷移できる必要があります。設計文では allowlist は十分に見えますが、UI 導線の postcondition が「取消」寄りで、「移譲・解約・招待取消」への導線確認が少し弱いです。

修正提案: 完了条件に「予約バナーまたは settings から、解約 / ownership 移譲 / メンバー整理 / 招待取消の各ブロッカー解消画面へ到達できる」behavioral または browser テストを入れてください。

## スコープの適切さ

[Suggestion] 5 PR 分割は妥当です。  
A/B/C1/C2/C3 はそれぞれ main に入った時点の一貫性が説明されており、C2 と C3 の順序も「コード存在」と「実データ準拠」を分けていて筋が通っています。

## 型安全性

[Suggestion] DTO / enum / interface に寄せる方針は PHPStan level 10 と相性が良いです。  
`BillingRetentionPurgeResultDto` に任意 metadata を持たせない判断も妥当です。詳細設計では `target` 識別子を enum 型で保持し、string 化は表示境界だけに閉じるとより安全です。