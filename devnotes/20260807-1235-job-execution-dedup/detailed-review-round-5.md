# 詳細設計レビュー Round 5

## S1: APPROVE

ログ schema、固定 event、PII 非包含の契約は整合しています。

## S2: APPROVE

LLM リトライ単位の preflight、条件付き進捗更新、型制約、テスト計画に問題ありません。

## S3: APPROVE

S3 PUT 直前の再検証と、terminal 化後の進捗抑止が behavioral test まで含めて設計されています。

## S4: REQUEST_CHANGES

[Critical] Stripe の preflight 配置を保証するとされている Feature テストに、必要な競合挿入点がありません。

現在追加される `duringCreateInvoice` は、次の順序を再現します。

```text
preflight 1
→ createAutoRechargeInvoice()
  → duringCreateInvoice で terminal 化
→ attach が 0 行
→ terminateUnattachedInvoice()
```

これは attach 競合のテストとしては正しいですが、`preflight 1` を実装から削除しても結果が変わりません。冒頭の Pending guard が通った後に terminal 化されるため、invoice は同じく作成され、attach 0 行、終端となります。

また、preflight 2 の抑止を試すには次の競合が必要です。

```text
invoice_id attach 成功
→ terminal 化
→ preflight 2
→ pay を抑止
```

しかし `duringCreateInvoice` は attach より前に発火するため、attach が0行となり preflight 2 へ到達しません。既存 invoice 経路でも、冒頭 guard と preflight 2 の間で terminal 化する注入点が示されていません。

したがって、S7 の「Feature テストが Stripe preflight の配置を保証する」という主張は現状では成立しません。

修正案: 以下の2つのインターリーブを決定論的に作れるテスト設計を明記してください。

- preflight 1: 冒頭 Pending guard 後、`stillPending(...StripeInvoiceCreate)` 前に terminal 化
- preflight 2: invoice attach 後、`stillPending(...StripeInvoicePay)` 前に terminal 化

本番コードへテスト専用 closure を追加するのは避けるべきです。候補は次のいずれかです。

- ownership verifier を小さな注入可能 collaborator にし、テスト fake が呼び出し回数ごとに terminal 化する
- 既存の注入可能 collaborator が各区間に存在するなら、その fake のフックを使う
- 配置だけを Architecture テスト側でソース構造として固定し、Feature テストは verifier 自体の挙動を担当する

重要なのは、実装の各 `stillPending()` 呼び出しを削除する mutation が、対応するテストで実際に赤になることです。

[Warning] S4 の変更箇所に、新設する次のメソッドも列挙してください。

- `terminateUnattachedInvoice()`
- `terminateInvoiceBestEffort()`

実装漏れ防止のための文書修正です。

## S5: APPROVE

排他時間の序列と接続前提が独立して固定されています。

## S6: APPROVE

Round 4 の completeness 問題は解消されています。

独立した期待集合との照合により、checkpoint の単独削除、期待値の単独削除、余剰登録、`NoExternalCall` の誤登録を検出できます。また、期待値と目録を同時に変更した場合は宣言的 gate では検出できないことも正確に明記されています。

[Suggestion] 期待集合側にも同一 `ExternalCallKind` の重複検査を入れると、誤って期待値と checkpoint の両方を重複登録した場合も読みやすく失敗します。承認条件ではありません。

## S7: REQUEST_CHANGES

[Warning] Architecture gate と Feature テストの分担は正しいですが、Stripe については S4 の競合挿入点が設計されるまで「配置を保証する」とは言えません。

修正案: S4 の2つの mutation を受け入れ手順へ追加してください。

```text
M16: StripeInvoiceCreate 直前の stillPending を削除
     → create 抑止 Feature テストが赤

M17: StripeInvoicePay 直前の stillPending を削除
     → pay 抑止 Feature テストが赤
```

この赤化を成立させるテストシームをS4へ明記したうえで、現在の対応表を維持できます。

## 全体判定

**CHANGES_REQUESTED**

S6 の宣言的 completeness は解決しており、実装本体にも新しい状態機械上の問題は見当たりません。残る問題は、Stripe の2つの preflightについて「外部呼び出し直前に置かれていること」を検証するテストが、現在のフックでは実際には赤化できない点です。

create前とpay前の2区間へ決定論的に terminal 化を差し込めるテスト設計を追加すれば、承認可能です。