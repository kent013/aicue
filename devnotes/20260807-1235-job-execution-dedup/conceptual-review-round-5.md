全体判定: **APPROVED**

Round 4 の指摘はすべて実質的に解消されています。CAS が閉じる送信権競合と、CAS 後にも残る送信結果不明が分離され、CAS を採らない判断も明示的なリスク受容として一貫しています。概念設計から詳細設計へ進めます。

## 1. 使命との整合性

[Suggestion] 問題ありません。

LLM課金、Stripe課金、レンダ成果物の不整合を抑え、動画マニュアル生成への信頼を支える改善として North Star に整合します。

## 2. 禁止事項違反

[Suggestion] 違反は確認できません。

Feature/Architecture テスト、mutation、規約とテストの対応表まで完了条件に含まれています。PHPStan の型を緩める設計や、禁止されたレスポンス・LLM呼び出し・DB操作もありません。

## 3. 実現可能性

[Suggestion] Laravel 12 のトランザクション、状態再読込、条件付き UPDATE、キュージョブ、例外処理の範囲で実現可能です。

CAS を不採用とする理由も、効果の否定ではなく次の比較として成立しています。

- 送信権競合を閉じる便益
- `sending` 導入後の結果不明回収契約
- 状態機械、UI、TS型、回収処理への波及
- 現行 timeout/stale 序列下での発生可能性

## 4. 期待効果の妥当性

[Suggestion] 妥当です。

効果が「再検証時点で terminal を検出できた場合」に限定され、外部呼び出しの exactly-once を主張していません。LLM、S3、Stripe それぞれについて防げる範囲と残余窓も区別されています。

[Warning] 次の一文だけは詳細設計へ進む前に表現を修正してください。

> 現状は `$timeout < stale 閾値` の序列で実質同じ効果を、追加状態ゼロで得ている

timeout 序列は競合を「起きにくくする」もので、CASと実質同じ効果ではありません。

修正提案:

> 現状は `$timeout < stale 閾値` の序列により、生存ワーカーと `recoverStale` の競合可能性を追加状態なしで低減している。

## 5. リスク

[Suggestion] 主要リスクは適切に記録されています。

特に次の2種類の open invoice を reconcile 母集団外として認識し、監視・手動収束の所有者を要求した点は妥当です。

- Canceled 検出後の void 失敗
- invoice 作成後、`stripe_invoice_id` 保存前のワーカー死亡

[Warning] 「所有権を何で表すか」にある次の説明は、claim token 一般の必要条件としては狭すぎます。

> claim token は同じ行を複数の担当が奪い合うモデルで必要

claim token は worker 間だけでなく、worker と stale recovery の世代識別にも利用できます。

修正提案: 「本設計では claim token を導入しても外部送信結果不明の回収契約が別途必要であり、現行リスクに対して導入コストが便益を上回る」としてください。「該当モデルが存在しない」ことを主理由にしない方が、後段のリスク受容と完全に整合します。

## 6. スコープの適切さ

[Suggestion] 適切です。

入口排他、claim token/CAS、専用キュー、恒久的なStripe逆走査、ffmpeg前の検査を別課題として除外する境界に合理性があります。S4 の横断目録も18件の完全母集団を維持しており、deny-by-default を損ないません。

## 7. 型安全性

[Suggestion] PHPStan level 10 を通せる設計です。

以下が明確に固定されています。

- `final readonly` の目録エントリ
- typed enum
- `class-string` / `non-empty-string`
- 非nullableな `PreflightRequirement`
- 実装型集合の deny-by-default 検査
- Reflection によるメソッド実在・`void` 戻り値検査
- constructor と gate の二重検証

結論として、**概念設計は承認**です。上記2箇所は設計判断の変更ではなく、本文後半と完全に整合させるための表現修正として、詳細設計着手前に反映してください。