# 対応マトリクス: conceptual-review Round 2

## [Warning] 観点2: 実装完了条件を「列挙テスト green」と明示 / register GET を通す Feature 必須
- 判断: 対応する
- 対応内容: 制約・前提に「実装完了条件」節を追加。Fortify register GET を実際に通す Feature テストを必須と明記 (resolver 単体で代替しない)。

## [Warning] 観点3/5/8: PII リスク受容の主体を「本人」と仮定 = 事実より楽観的。単回使用も不正確
- 判断: 対応する (指摘の置換文言を採用)
- 根拠: 妥当。転送/誤送信で本人前提は崩れる。token は受諾前は複数回閲覧可能。
- 対応内容: 判定 (b) を bearer token モデルに書き換え。「開示相手が本人であることは保証しない/第三者開示は残余リスクとして受容/受諾後無効化だが受諾前は複数回閲覧可能」を明記。受容根拠を (email 1 件のみ/推測不可・期限付き token 所持が条件/業界標準/不変条件 #6 非抵触/平文検索非導入) に整理。

## [Warning] 観点3: GET forget だけでは GET→POST 間失効時の契約が未確定
- 判断: 対応する
- 根拠: 妥当。POST 時の最終挙動 (通常登録成立 or 招待失効エラー) を固定すべき。
- 対応内容: 実装方針 2 に「POST 契約」を追記。GET→POST 間失効時は**登録を止めず通常登録成立 (個人組織 fallback)**。POST 順序 (MatchesInvitationEmail no-op → user 作成 → acceptInvitationIfValid null → 個人組織 fallback → token forget) を固定。Feature テスト化。

## [Suggestion] 観点7: Session contract 型 + 復号後 email が string 確定を PHPStan で検証
- 判断: 対応する
- 対応内容: resolver 引数型を `Illuminate\Contracts\Session\Session` に明示。復号後 email 型 string を実装方針に明記。

## [Suggestion] 観点1/6: onboarding 限定評価・スコープ外判断は妥当 (既知制約は残る)
- 判断: 見送り (既に反映済み)
- 対応内容: 判定 (c)・スコープ外に既知制約として明記済み。追加対応不要。
