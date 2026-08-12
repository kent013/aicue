全体判定は **REQUEST_CHANGES** です。Round 1 の中心課題は解消されていますが、追加した契約 8 / M6 に mutation-killing 上の穴があります。

**施策 1: REQUEST_CHANGES**

[Warning] `AccountDeletionAuditContext` の DTO 化は妥当ですが、既定引数により将来の HTTP 呼び出し元が context を渡し忘れても検出できません。`route=null / method=null` が正常な非 HTTP 呼び出しと、単なる渡し忘れを区別できなくなります。

`deleteAccount()` の context は必須引数にして、3 呼び出し元すべてで明示する方が堅牢です。例えば `AccountDeletionAuditContext::http(...)` と `::nonHttp()` の named constructor を用意すれば、PHPStan level 10 が新規呼び出し元の判断漏れを検出できます。「既存2箇所は無変更」は利点ではなく、監査情報の deny-by-default 性を弱めています。

[Suggestion] HTTP method は任意文字列より、可能なら既存の型または enum を利用してください。ただし監査 metadata 用であり、値による分岐をしないなら `?string` でも承認可能です。

**施策 2: REQUEST_CHANGES**

[Critical] 契約 8 は、記載された M6 を確実には殺せません。

未認証リクエストには凍結中 user が存在しないため、凍結 middleware を認証 middleware より前へ移しても、通常は凍結判定が何もせず通過し、その後の認証で同じ 401 になります。

```text
未認証 DELETE
→ 凍結 middleware（user=null のため通過）
→ Authenticate
→ 401
```

したがって契約 8 は「未認証リクエストが 409 を返さない」という有用な境界契約ですが、middleware 順序の mutation test にはなっていません。

M6 を維持するなら、`bootstrap/app.php` の priority list において認証が凍結判定より前であることを Architecture テストで固定する必要があります。あるいは M6 の予測を削除し、契約 8 を単独のセキュリティ回帰契約として位置付けてください。

[Suggestion] 7a/7b の分割は妥当です。7b は次を厳密に検査すれば M5 を殺せます。

- service が内部で再取得した凍結状態から `true` を記録する
- `AccountDeleted` の event type を限定する
- metadata のキー存在だけでなく値と型を検査する
- transaction 完了後の永続化結果を検査する

7b が実際に user を削除することは問題ありません。これは公開 HTTP 契約ではなく、削除 service の監査契約だからです。

M1、M3、M4、M5 の予測は改訂後の内容で妥当です。M2 の「最低限 1・3」も保守的な記述として妥当です。

[Warning] 文書内の件数と変更範囲が古いままです。

- 施策一覧の「契約6件」は、現在は `1〜6 + 7a + 7b + 8` です
- 変更ファイルに DTO と `Settings\AccountController` が含まれていません
- 「1サービス1箇所 + テスト + docs」「競合リスクなし」も実際の変更範囲と一致しません

**施策 3: APPROVE**

[Suggestion] 運用文書には、`deletion_requested=true` の監査記録が通常の HTTP 経路では生成されず、service の誤用・将来の非 HTTP 経路・防御の迂回を観測するための値であることを明記してください。

**承認条件**

1. M6 を検出可能な Architecture テストへ変更するか、M6 の mutation 予測を撤回する
2. audit context を必須引数として全呼び出し元で明示する
3. 施策一覧の契約件数・変更ファイル・実装範囲を更新する

この3点が反映されれば、詳細設計として **APPROVE** にできます。