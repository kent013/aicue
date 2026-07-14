# 対応マトリクス: conceptual-review Round 1

## [Critical] 観点5: GET の active 再判定と POST の session token の不整合 (stale token)
- 判断: 対応する
- 根拠: 妥当。GET で prefill しない (null) 判定をしても session に token が残ると「UI は通常登録・サーバは招待フロー」の不整合が生じる。
- 対応内容: resolver (`resolveRegisterPrefillEmail`) が stale/invalid token を **GET 時点で session から forget** する契約を明文化。GET/POST の契約一致を保証。stale token の Feature テストを詳細設計のテスト計画に追加する。

## [Critical] 観点8: 「新たな漏洩なし」は楽観的すぎる (exact email を props で返す = PII 開示面の追加)
- 判断: 対応する (主張を撤回・リスク受容へ書き換え)
- 根拠: 妥当。従来は招待先 email 平文を返していない。props で exact email を返すのは PII 開示面の追加であり、token 保持者=本人の前提はリンク転送・共有端末・覗き見で崩れる。
- 対応内容: 判定 (b) を「漏洩ゼロ」から「**有効 token 所持者への招待先 email 開示という限定的リスクを受容**」に書き換え。受容根拠 (自分の email/推測不可 token/期限付き単回/業界標準 onboarding パターン/追加平文検索なし) を明記。列挙面は広げないことは維持。

## [Warning] 観点2: テスト粒度不足 (JS だけでは不十分、Feature テスト必須)
- 判断: 対応する
- 根拠: 妥当。AGENTS.md 禁止事項1 に照らしても Feature テスト必須。
- 対応内容: 詳細設計のテスト計画に Feature テスト (active→prop あり / expired・revoked・accepted→null かつ session forget / 通常登録非退行 / SSO 表示非退行 / stale token) を含める。

## [Warning] 観点3: Input atom の readonly 透過が前提
- 判断: 対応する (確認済み)
- 根拠: `Input.svelte` は `{...rest}` を native input に spread しており `readonly` (HTMLInputAttributes) が透過する。
- 対応内容: 「atom 変更不要 (確認済)」を実装方針に明記。

## [Warning] 観点4: 「422 を構造的に排除」は言い過ぎ
- 判断: 対応する
- 根拠: 妥当。SSO 不一致・stale token では依然 fallback し得る。
- 対応内容: 効果記述を「主経路の手入力ミス起因 422 を削減」に下げ、SSO/stale の限界を明記。stale は session forget で通常登録に一本化することも記載。

## [Warning] 観点5b/8c: readonly の根拠「通常 /register を開けばよい」は session モデルと不整合 / 破棄導線 or 明文化が必要
- 判断: 一部対応 (明文化する。破棄導線は out-of-scope として意識的に明記)
- 根拠: 指摘通り、active token が session に残る限り /register でも再 lock される。誤った根拠は撤回。ただし「別 email 登録の切替導線」は現行サーバ契約にも無い別機能で v1 過剰実装になる。
- 対応内容: 判定 (c) の誤記述を撤回し「招待リンク経由セッションは招待先 email に固定 = 既存サーバ契約と一致」と明文化。切替導線は既知制約として out-of-scope に明記。

## [Warning] 観点7/8b: 独自 active 判定 resolver は既存ルールと判定ドリフトの危険
- 判断: 対応する
- 根拠: 妥当。DRY / ドリフト防止。
- 対応内容: `OrganizationInvitation::findActiveByPlainToken(): ?self` に「token_hash 照合 + active 判定」を単一化し、`MatchesInvitationEmail` / `acceptInvitationIfValid()` の重複クエリも寄せる (granular メッセージが要る `acceptInvitation()` は対象外)。追加の平文 email 検索は導入しないことを明記。

## [Suggestion] 観点1: 効果の射程を onboarding 摩擦低減に留めよ
- 判断: 対応する
- 対応内容: 期待効果の使命貢献を「オンボーディング摩擦低減 (本丸機能そのものの改善ではない)」と粒度調整。
