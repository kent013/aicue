全体判定: **CHANGES_REQUESTED**

設計の方向性は妥当です。inline throttle の共有 bucket を named limiter に移す判断、閾値を変えない方針、deny-by-default の機械検査化は、このリポジトリの規約とよく合っています。ただし、いくつかの安全性主張が強すぎるため、そのまま詳細設計へ進むには修正が必要です。

**1. 使命との整合性**

[Suggestion] 再認証・招待受諾・パスワード設定が撮影導線の前提になる、という位置づけは妥当です。巻き添え 429 の除去は North Star に対して間接的だが実質的に貢献します。

[Suggestion] 期待効果は「撮影 PWA の主機能改善」ではなく「到達不能・回復不能な認証導線の除去」と表現した方がよいです。使命との接続が過剰に見えにくくなります。

**2. 禁止事項違反**

[Suggestion] 現時点の概念設計には明確な禁止事項違反は見当たりません。

[Warning] 実装時に Fortify / Passport / Livewire の route 応答や middleware を触る場合、`response()->json()` 直書きや vendor route の上書きで仕様固定 endpoint 例外を曖昧にしないでください。今回の主戦場は middleware と RateLimiter 登録に閉じるべきです。

修正提案: 設計書に「controller response は変更しない」「route の throttle middleware のみ変更する」を明記してください。

**3. 実現可能性**

[Warning] `password-credential` に「照合」と「設定」を同居させる判断は再検討が必要です。`recent-auth.password` / `password.confirm.store` / `user-password.update` は credential 照合を含むため同一レーンにする根拠があります。一方、`settings.password.store` が step-up 済みのパスワード新規設定で現在パスワード照合を含まないなら、これは「秘密の推測試行」ではなく「credential mutation」です。

このままだと、パスワード設定操作が `recent-auth.password` を 429 にする余地が残ります。現状の 13 route 共有よりは改善ですが、「無関係な面を分ける」という設計原則から見ると説明が弱いです。

修正提案: 次のどちらかに寄せてください。

- `password-credential-check` 6/min: `recent-auth.password` / `password.confirm.store` / `user-password.update`
- `password-credential-set` 6/min または既存同値の適切な named limiter: `settings.password.store`

または、`settings.password.store` が実際に current password 検証を含むなら、その事実を前提として明記してください。

[Warning] Fortify の `verification.send` と `verification.verify` を 1 knob に留める判断は実装コスト面では妥当ですが、「メール送信」と「署名付き検証」は数える対象が異なります。特に `verification.verify` が GET で、メールクライアント・セキュリティ製品・ブラウザの再アクセスの影響を受ける可能性があるなら、送信 quota と同じ bucket に置くのは概念的にきれいではありません。

修正提案: Round 1 では第 2 段に留める方針でよいですが、設計書には「Fortify 制約を優先する暫定判断」「外向き送信コストの制御は `verification.send` 側の既存 6/min で維持」「将来分離する場合の条件」を明記してください。

**4. 期待効果の妥当性**

[Critical] 「後退リスクは構造的にゼロ」は言い切り過ぎです。429 条件だけを見ると単調緩和に近いですが、セキュリティ・運用上は次の変化があります。

- 同一 actor が 1 分間に実行できる認証関連操作の総量は増える
- 複数レーンを並行して叩くことでログ量・メール送信・状態変更試行の総量が増える
- `email-verification` や `two-factor-manage` など、秘密推測以外のコスト面では天井が変わる可能性がある

「新たに 429 になる状況は増えない」は概ね正しいですが、「後退リスクゼロ」は成立しません。

修正提案: 表現を次に弱めてください。

> 429 の巻き添えについては単調緩和であり、新たに巻き添え 429 になる経路は増えない。一方で、認証関連操作の合算実行量は増えるため、秘密推測・外向き送信・状態変更コストごとに上限維持を確認する。

[Warning] 「推測可能な秘密への試行回数の天井は不変」は、`password-credential` の route 群が本当に同じ秘密を同じ条件で検証している場合に限って成立します。`settings.password.store` が秘密推測でないなら、同じレーンに入れる根拠にはなりません。

修正提案: route ごとに「保護している資産」「推測可能な秘密の有無」「外部コストの有無」「状態変更の有無」を表に足すと、レーン設計の妥当性を検証しやすくなります。

**5. リスク**

[Warning] `InlineThrottleInventoryTest` で「認証済み actor のキーで数えられる inline は 1 本まで」とする案は方向性としてよいですが、Livewire のように auth 有無でキー種別が変わる route をどう分類するかを明確にしないと、テストが実装都合の例外集になりやすいです。

修正提案: enum の分類を route 単位ではなく「bucket signature の性質」で分けてください。例:

- `VendorAuthenticatedUserBucket`
- `VendorStatelessIpBucket`
- `VendorMixedUserOrIpBucket`

そのうえで `VendorMixedUserOrIpBucket` の authenticated 到達可能 route は 1 本まで、と cap をかけるのがよいです。

[Warning] Passport 2 本と Livewire 未認証分岐の IP bucket 共有を「詰みを作らない」とする判断は少し粗いです。OAuth token endpoint の 429 はログイン不能・API 利用不能に直結しうるため、詰みではないが影響はあります。

修正提案: 「今回の主障害である認証済み actor の step-up 巻き添えとは別問題」と位置づけ、Passport / Livewire の IP bucket 共有は unresolved risk として明示してください。

**6. スコープの適切さ**

[Suggestion] `api-read` / `api-write` / `api-status` を今回は分けない判断は妥当です。分離すると総量上限が変わるため、T125 に混ぜると設計目的がぶれます。共有グループ目録に明示して後続 TODO 化するのがよいです。

[Warning] 新設 gate の量はやや多めですが、このリポジトリの deny-by-default 方針には合っています。ただし、`InlineThrottleInventoryTest` と既存 `ThrottleCoverageInventoryTest` の責務境界を曖昧にすると保守負荷が上がります。

修正提案: 責務を次のように分けてください。

- `ThrottleCoverageInventoryTest`: 保護対象 route が throttle をちょうど 1 本持つこと
- `InlineThrottleInventoryTest`: inline throttle の残存理由と bucket 共有上限
- `RateLimiterKeyConventionTest`: named limiter のキー形式と衝突
- Feature test: 実際の巻き添え 429 が消えていること

**7. 型安全性**

[Suggestion] enum で inline 残置理由を型化する方針は PHPStan level 10 と相性がよいです。

[Warning] RateLimiter closure の戻り値、request user の nullability、IP fallback の扱いは PHPStan で詰まりやすい箇所です。

修正提案: limiter key 生成は closure 内にベタ書きせず、既存 `passkeys` / `two-factor-secret-read` と同じ helper か private method に寄せて、戻り値を明確に `string` にしてください。`$request->user()` は null を前提に分岐し、`$request->ip()` の nullable 扱いも明示してください。

**論点への回答**

1. レーンの切り方は「数える対象ごと」で妥当です。ただし `settings.password.store` は `password-credential` 同居の根拠が不足しています。照合なしなら分けるべきです。

2. `email-verification` 同居は、Fortify の第 2 段を優先する判断として許容できます。ただし概念的には send / verify は別物なので、暫定判断として明記してください。

3. 単調緩和の主張は「巻き添え 429」に限定すれば妥当です。「後退リスクゼロ」「全コストの天井不変」までは言い過ぎです。

4. inline 3 本を残す判断は妥当です。case 別 cap はやり過ぎではなく、vendor 由来 inline を制御するには必要な機械化です。

5. API limiter 共有を今回は分けない判断は正しいです。総量上限の緩和を伴うので、別 TODO に分離すべきです。

6. gate 群は適量です。ただし責務境界を明文化しないと重複検査になります。

結論として、基本方針は承認可能ですが、`password-credential` のレーン定義と「単調緩和 / 後退リスクゼロ」の主張を修正してから Round 2 に進めるべきです。