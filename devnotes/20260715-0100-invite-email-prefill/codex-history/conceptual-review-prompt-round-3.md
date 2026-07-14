# 概念設計レビュー Round 3

Round 2 の Warning/Suggestion をすべて概念設計へ反映しました。「上記を反映すれば APPROVED にできる」との評価を受けての再提出です。

## Round 2 指摘への対応

### [Warning] PII リスク受容の主体が「本人」= 楽観的 / 単回使用も不正確 → 対応
判定 (b) を **bearer token モデル**に全面書き換え:
- 「開示相手が招待相手本人であることは保証しない。リンク転送・誤送信時の第三者開示を残余リスクとして受容」
- 「token は受諾後に無効化されるが、受諾前は複数回閲覧可能 (単回使用ではない)」
- 受容根拠を「招待先 email 1 件のみ開示 / 推測不可・期限付き active token 所持が条件 / 業界標準 onboarding / 不変条件 #6 非抵触 / 平文検索非導入」に整理。

### [Warning] GET forget だけでは GET→POST 間失効の契約が未確定 → POST 契約を明文化
GET→POST 間で失効した場合は **登録を止めず通常登録として成立** (個人組織 fallback)。POST 順序を固定:
①`MatchesInvitationEmail` (inactive は no-op pass) →②user 作成→③`acceptInvitationIfValid()` null→④個人組織 fallback (signup grant)→⑤`invitation_token` forget。Feature テスト化。

### [Warning] 実装完了条件を明示 / register GET を通す Feature 必須 → 対応
「実装完了条件」節を追加し、Fortify register GET を実際に通す Feature テストを必須化 (resolver 単体で代替しない)。列挙:
active→prop / expired・revoked・accepted→null かつ session forget / token 無し→null かつ SSO 非退行 / GET active→POST 前失効→登録成立(個人組織) / JS readonly+prefill。

### [Suggestion] Session contract 型 + 復号後 email string 確定 → 対応
resolver 引数型を `Illuminate\Contracts\Session\Session`、復号後 email 型 `string` (PHPStan L10 確定) と明記。

---

## 該当の改訂差分 (判定 b / 実装方針 2 / 実装完了条件)

### 判定 (b) 改訂後
（前略）リスク受容の判断 (bearer token モデル): 開示権限は「active な bearer token の所持」として扱う。開示相手が招待相手本人であることは保証しない — リンク転送・メール誤送信時の第三者開示は残余リスクとして受容する。token は受諾後に無効化されるが、受諾前は複数回閲覧可能 (単回使用ではない)。根拠: (1) 開示は招待先 email 1 件のみ (2) 推測不可・期限付き active token 所持が条件 (3) 業界標準 onboarding (4) 不変条件 #6 非抵触・平文 email 検索非導入。結論: 「漏洩ゼロ」ではなく「active bearer token 所持者への招待先 email 開示 (第三者開示の残余リスクを含む) を受容した上で妥当」。

### 実装方針 2 (POST 契約) 改訂後
resolver は GET 時点で既に stale/invalid な token を forget。GET→POST 間で失効した場合は登録を止めず通常登録成立 (個人組織 fallback)。POST 順序を①〜⑤で固定 (上記)。

### 実装完了条件 (新設)
Fortify register GET を通す Feature 必須。列挙テスト全 green を完了条件とする (上記一覧)。

全体判定の再評価をお願いします。
