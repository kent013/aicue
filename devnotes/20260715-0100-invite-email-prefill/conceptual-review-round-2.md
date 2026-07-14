全体判定: **CHANGES_REQUESTED**

### 1. 使命との整合性

[Suggestion] North Star への寄与を「本丸機能ではなくオンボーディング摩擦低減」と限定した評価は妥当です。改善規模も Low 相当です。

### 2. 禁止事項違反

[Warning] Feature テスト追加は明記されましたが、実装完了条件を「列挙したテストが green」と明示すべきです。特に resolver 単体ではなく、Fortify の register GET を通した Feature テストを必須にしてください。

禁止事項 #4、#8への違反はありません。

### 3. 実現可能性

[Warning] GET 時の `forget()` だけでは、GET→POST 間で招待が失効したケースの契約は一致しません。POST 時にも再検証されるため、次のどちらになるかを明文化する必要があります。

- 通常登録として成立させる
- 招待失効エラーとして登録を止める

現状文面の「通常登録に一本化」と「GET→POST 間では fallback」が曖昧です。`MatchesInvitationEmail`、ユーザー作成、個人組織 fallback、session破棄の順序を詳細設計で固定し、その遷移を Feature テストにしてください。

### 4. 期待効果の妥当性

[Suggestion] 「主経路の手入力ミス起因422を削減」への修正は妥当です。readonly により、この経路では実質的に手入力ミスを防止できます。

### 5. リスク

[Warning] risk acceptance の根拠 `(1) 開示対象は当人の自分の email であり第三者 PII ではない` は、直前の「転送・誤送信で前提が崩れる」と矛盾します。また token は受諾前なら複数回利用できるため、厳密には「単回使用」でもありません。

修正提案: 根拠を次のように置き換えてください。

> 開示対象が本人の email であることは保証しない。active な bearer token の所持を開示権限として扱い、転送・誤送信時の第三者開示を残余リスクとして受容する。token は受諾後に無効化されるが、受諾前の閲覧は複数回可能である。

この修正なしでは、セキュリティ判断の中心部分が事実より楽観的です。

### 6. スコープの適切さ

[Suggestion] 切替導線とSSO改善をスコープ外にした判断は妥当です。ただし「同一ブラウザでは別email登録へ切り替えられない」ことは既知制約として残ります。

### 7. 型安全性

[Suggestion] `?string` resolver と Inertia props の plain array は許容できます。実装時は session の具象型ではなく適切な Session contract を引数型とし、PHPStan L10で model email 属性の復号後型が `string` と確定することを検証してください。

### 8. セキュリティ判定の妥当性

[Warning] 列挙リスクの評価、token hash照合、active再判定、平文email検索を追加しない判断、readonly採用は妥当です。一方、PIIリスク受容の主体を「本人」と仮定している点だけは未解消です。

修正提案:

- 「本人への開示」ではなく「bearer token所持者への開示」と定義する
- 「単回使用」を「受諾後無効化」に訂正する
- invalid/stale tokenで `invitationEmail` が返らないことをFeatureテストする
- GET後に revoked/expired となったPOSTの最終挙動をFeatureテストする

上記を概念設計へ反映すれば、APPROVED にできる状態です。