# Round 4: Round 3 指摘への対応報告

Round 3 の Warning 2 件に対応した。再レビューし、全体判定を出してほしい。

## 対応マトリクス

### [Warning] 条件付き UPDATE の不変条件を固定するテスト不足 → 対応済み
Feature テスト（Resolver の競合契約）を以下 5 ケースに拡充して概念設計に明記した:
1. org はあるが current null → 自己修復して 200 + 当該 org のデータ表示
2. current が非所属 org を指す（手動作成の dangling）→ 当該 org のデータを描画しない
3. 候補 membership が UPDATE 前に消失（EXISTS 偽）→ current を設定しない
4. 観測後に current が別 org へ変更済み → その変更を上書きしない
5. 条件付き UPDATE が 0 件 → fresh 再取得した最新状態で解決する（1 回のみ・解決不能なら null）

### [Warning] UPDATE 0 件時の再解決契約が曖昧 → 対応済み
解決契約 2 を以下のとおり確定した:

> UPDATE の**成否にかかわらず** relation キャッシュを破棄して User を DB から fresh 再取得し、その最新値に対して「所属再確認つき読み出し」（契約 1 と同一）を行って返す。再確認は **1 回のみ**。それでも解決不能なら null を返す（無限再試行しない。null 時の画面は setup 表示）。
> また条件付き UPDATE の WHERE は「current IS NULL または観測した dangling 値のまま」に限定するため、観測後に別 org へ変更された current を上書きしない。

以上で概念設計への指摘は全件対応済みと考える。APPROVED 可否の判定を求む。
