**全体判定: REQUEST_CHANGES**

主な理由は、契約 7 の検査設計と mutation M5 の見立てが噛み合っていないためです。通常削除だけを見る限り、`deletion_requested` の期待値は `false` なので、「常に false にする」mutation は生き残ります。

## 施策 1: 監査 metadata

**REQUEST_CHANGES**

[Warning] `request()` を service 内で直接呼ぶ設計は避けたいです。  
削除 service が HTTP 経路に依存し、CLI / job / テストからの呼び出しで観測値の意味が曖昧になります。実害は小さいですが、純粋性とテスト容易性は落ちます。

推奨は、controller 側または action 呼び出し元で `route` / `method` を含む小さな DTO / context を渡す形です。HTTP 外なら `null` を渡せばよいです。

```php
new AccountDeletionAuditContext(
    route: $request->route()?->getName(),
    method: $request->method(),
)
```

[Suggestion] どうしても `request()` を使うなら、少なくとも「これは観測専用で HTTP 非依存の削除経路では null になる」ことを service の近くに明記してください。

metadata の内容自体は妥当です。PII もなく、`deletion_requested_at` をロック済み `$freshUser` から読む方針も正しいです。

## 施策 2: 契約テスト

**REQUEST_CHANGES**

[Critical] 契約 7 は現設計では M5 を殺せません。  
通常の凍結していない削除で検査すると、期待される `deletion_requested` は `false` です。実装を「常に false」に壊してもテストは緑のままです。

「凍結中は削除されないので metadata が残らない」こと自体は正しいです。ただし、それなら契約 7 は次のどちらかに分ける必要があります。

1. Feature test: 通常削除で `route` / `method` / `deletion_requested=false` が載ることを固定する  
2. Service-level test: 凍結済み user を直接 service に渡し、`deletion_requested=true` が記録されることを固定する

後者は「route 防御を迂回した時に観測できる」ことのテストです。防御を増やさない方針とも両立します。ただし、テスト名で「public route の許可ではなく audit metadata の検査」と明確にするべきです。

[Warning] 「赤くなるのは契約 7 だけ」という fail 先行の見立ては未確認前提としては強すぎます。  
契約 1..4 / 6 が現行で緑という仮説はよいですが、recent-auth middleware、2FA middleware、JSON 期待値、route priority の既存状態次第で赤くなる可能性があります。設計書では「想定」ではなく「fail-first 実測で記録する」に落とすべきです。

[Warning] 契約 3 の「recent-auth 未充足でも 409」は、セキュリティ上の順序を明確に固定する強い契約です。  
設計判断としては理解できますが、「凍結状態の存在が認証強化より先に返る」ことになります。本人の認証済み settings 配下リクエストなら許容し得ますが、認可・認証境界より前に凍結判定が走らないことは確認対象に含めるべきです。

## mutation M1..M5

**REQUEST_CHANGES**

[Critical] M5 の予測は誤りです。  
現状の契約 7 だけでは赤くなりません。`deletion_requested=true` の audit 経路を service-level で作る必要があります。

[Warning] M2 は「常に redirect」にした場合、`deleteJson()` なら契約 1 / 3 に加えて 2 / 6 も赤くなる可能性があります。最低赤くなるものとして 1 / 3 はよいですが、期待範囲は少し狭いです。

[Suggestion] M1 は妥当です。allowlist に `settings.account.destroy` を足すと、凍結 middleware が通過して削除側へ進むため、1 / 2 / 3 / 5 / 6 が赤くなる見立ては自然です。

M3 / M4 は妥当です。metadata key の存在を厳密に assert するなら赤くなります。

## 施策 3: docs

**APPROVE**

[Suggestion] docs には「この対応は防御追加ではなく、再発時の監査粒度を上げるもの」と明記してください。  
また、凍結中の削除成功は通常到達不能であり、`deletion_requested=true` metadata は防御迂回・バグ・非 HTTP 経路の異常観測用、という位置づけを書くと運用時に誤読されにくいです。

**結論**

方向性はよいですが、契約 7 と M5 が成立していません。`request()` は service 直呼びではなく context 注入に寄せ、`deletion_requested=true` を service-level で検査できる形に直してから APPROVE できます。