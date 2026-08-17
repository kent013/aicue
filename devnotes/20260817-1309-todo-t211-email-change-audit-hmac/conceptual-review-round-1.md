全体判定: **APPROVED**

この追従設計は、AG-195 の「メール変更監査に HMAC 2 値を載せる」という範囲に収まっており、aicue 側の既存資産 `EmailHash` / `SecurityEventRecorder` を使う方針も妥当です。概念設計としては実装に進めてよいです。

**1. 使命との整合性**

[Suggestion] 使命への寄与は直接の動画生成機能ではなく、アカウント保護・復旧調査の土台としての寄与です。設計本文はその位置づけを誇張せず説明できています。

**2. 禁止事項違反**

[Suggestion] 明確な禁止事項違反は見当たりません。  
`response()->json()`、Prism 直呼び、prompt 直書き、DB 破壊操作、disabled UI などには触れていません。

**3. 実現可能性**

[Suggestion] Laravel 12 + Fortify + 既存の `SecurityEventRecorder` で十分実現可能です。migration 不要、記録箇所 1 箇所のみという判断も妥当です。

**4. 期待効果の妥当性**

[Suggestion] 効果は合理的です。平文を残さず、候補メールアドレスとの照合を可能にする、という主張は HMAC の性質と一致しています。  
「復元できない」「APP_KEY ローテーションで突合不能」「best-effort のまま」という限界も明記されており、過大主張になっていません。

**5. リスク**

[Warning] `APP_KEY` 依存により、キー漏えい時の照合可能性と、キー rotation 時の過去監査との突合不能が残ります。設計では認識済みですが、監査用途としては将来の専用鍵化の判断材料が必要です。  
修正提案: 今回は実装を増やさず、設計または TODO に「監査専用 HMAC 鍵への切り出しは、APP_KEY rotation 運用が具体化した時点で再評価」と明記するとよいです。

[Warning] テストで「保存された JSON に平文アドレスが現れない」を確認する場合、Eloquent cast 後の `metadata` 配列だけを見ると不十分です。実際の DB JSON 文字列に平文が含まれないことを見た方が安全です。  
修正提案: `SecurityAuditEvent` の raw value、または `DB::table(...)->value('metadata')` を使って、`before@example.com` / `after@example.com` が含まれないことを直接 assert してください。

**6. スコープの適切さ**

[Suggestion] スコープは適切です。  
正規化された変更判定、監査専用鍵、流量制限、再認証鮮度失効、過去行の遡及更新を外している判断は、AG-195 追従として妥当です。

**7. 型安全性**

[Warning] metadata の形が配列リテラルだけに埋もれると、将来キー名や値型が崩れても PHPStan では拾いにくいです。  
修正提案: 最低限 Feature test で「キーが `old_email_hash` / `new_email_hash` の 2 つだけ」「両方 64 桁 hex」「`EmailHash::compute()` と一致」を固定してください。小さな private helper または定数化は任意ですが、今回の狭い変更ならテスト固定で十分です。

Critical はありません。実装時は `EmailHash::compute()` を保存前に呼ぶ方針と、metadata の exact shape をテストで固定する点を守れば、この設計で問題ありません。