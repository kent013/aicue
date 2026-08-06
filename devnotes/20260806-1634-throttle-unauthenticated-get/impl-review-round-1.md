**tests/Feature/Security/AuthThrottleCoverageTest.php**

[Warning] `2FA 秘密 GET 3 本は 1 つのレーンを共有する` が、実際には `qr-code` と `secret-key` の 2 本しか叩いていません。`two-factor.recovery-codes` が `throttle:10,1` など別 bucket に戻っても、このテストは落ちません。施策 8 は 2FA 秘密 GET 3 本すべての behavioral proof を要求しているため、`recovery-codes` も同一レーン消費を確認対象に入れるべきです。

[Suggestion] `social.callback の throttle は Socialite を一切呼ばずに枠を消費する` は intent 不在の短絡を見ており、単独では「throttle が外向き HTTP より前に効く」証明にはなっていません。8-1 と組み合わせれば大筋は読めますが、名前より検査内容が弱いです。

**tests/Feature/Security/ThrottleExemptionPremiseTest.php**

[Suggestion] `AuthViewRenderOnly` の `/register` は invitation token が session にある分岐を持つのに、代表 GET の DB 書込 0 件テストは token 無しだけを見ています。現状の実装確認としては許容できますが、前提 drift 検出としては token あり分岐も 1 本足すと網が締まります。

**app/Enums/Security/ThrottleCoverageExemption.php**

問題なし。新 case の適用条件は設計意図と一致しています。

**app/Providers/AppServiceProvider.php**

問題なし。named limiter、キー形式、閾値とも設計どおりです。

**app/Providers/FortifyServiceProvider.php**

問題なし。2FA 秘密 GET を named limiter に分離した判断は妥当で、inline bucket 共有の補足も必要な修正です。

**routes/web.php**

問題なし。未認証 GET 2 本への named throttle 付与は設計どおりです。

**tests/Architecture/RateLimiterKeyConventionTest.php**

問題なし。新 limiter 3 本が inventory に登録されています。

**tests/Architecture/ThrottleCoverageInventoryTest.php**

問題なし。exemption cap、case 別 cap、dead exemption 検出はいずれも有効です。

**docs/app-integration-guide.md**

問題なし。運用上重要な inline bucket 共有と S3 の GET 拡張が明文化されています。

CHANGES_REQUESTED