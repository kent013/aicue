# 全体判定: APPROVED

Round 4 の Critical は解消されています。P1 の total inventory、P2 の復旧条件、P3 のサーバ・クライアント両面の防御と恒久テスト契約は、概念設計として実装可能な水準です。

## 1. 使命との整合性

[Suggestion] iOS Safari を主要プラットフォームとして防御対象へ含め、撮影フローの非破壊も不変条件にしたため、North Star と整合しています。

## 2. 禁止事項違反

[Suggestion] Feature、Architecture、自動 Browser E2E の責務が明確で、テストなしの完了報告を許さない構成です。その他の禁止事項への抵触もありません。

## 3. 実現可能性

[Warning] P3-b の状態表には「セッション有効なら表示を戻す」とありますが、第一候補の hard reload では旧DOMを再表示せず、新しいDocumentへ遷移します。

修正提案: 詳細設計では第一候補を次の状態遷移に統一してください。

`pagehideで秘匿 → persisted復元時も秘匿 → hard reload → 認証済みなら新Document表示 / 未認証ならloginへredirect`

旧DOMの「表示を戻す」は、専用再検証endpointを採用する場合にのみ必要です。

## 4. 期待効果の妥当性

[Suggestion] 自動回帰とiOS実機受入確認を区別しており、効果の過大申告は解消されています。

## 5. リスク

[Warning] 全 `pagehide` で秘匿すると通常遷移時にちらつく可能性があります。

修正提案: 詳細設計で `PageTransitionEvent.persisted` を利用できる場合はbfcache対象時だけ秘匿し、利用できない環境では安全側へ倒す方針を決めてください。4本のE2Eで通常遷移への副作用も固定する現在の方針は妥当です。

## 6. スコープの適切さ

[Suggestion] P3-b/P3-c の追加は使命上必要であり、過大ではありません。3トラックへの分割も適切です。

## 7. 型安全性

[Suggestion] DTO/JsonResource規約への抵触はありません。`PageTransitionEvent`、Response型、total inventoryの分類を明示する方針でPHPStan level 10にも対応可能です。

上記Warningは詳細設計で確定すべき事項であり、概念設計の承認を妨げません。