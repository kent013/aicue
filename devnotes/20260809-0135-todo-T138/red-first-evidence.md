# テストファースト: gate を先に置いた時点の赤 (実測)

`ExternalSeamInventory::entries()` が `[]` の状態で
`php artisan test --filter=ExternalSeamInventory` を実行した結果:

```
tests=15 passed=11 failed=4
```

赤になった 4 本 (設計の予測どおり):

1. `外部到達: 走査 site と目録は (クラス, 種別) で双方向に一致する`
   → 31 site (= 12 個の `(class, kind)`) を「未登録 (一致 entry 0 件)」として列挙
2. `外部到達: 走査母集団が空でない` → `entries()` が空
3. `外部到達: SocialLogin は SocialAuthController 1 クラスに固定される` → 登録側が空集合
4. `外部到達: 種別 × 次元は目録か委譲のちょうど一方で覆われる`
   → `payment|code_reach_point` / `social_login|code_reach_point` /
     `captcha|code_reach_point` / `mail|code_reach_point` / `market_data|code_reach_point`
     が「覆われていない」

走査で出た `(class, kind)` は設計の「実測母集団」12 件と完全一致した (抑制 0 件):

| 件数 | class \| rule |
|---|---|
| 1 | App\Actions\Fortify\UpdateUserProfileInformation \| mail_facade_reference |
| 2 | App\Actions\Inquiry\CreateInquiryAction \| mail_facade_reference |
| 2 | App\Console\Commands\Billing\EnsurePortalConfiguration \| payment_client_call |
| 2 | App\Http\Controllers\Auth\SocialAuthController \| socialite_facade_reference |
| 1 | App\Providers\AppServiceProvider \| payment_client_call |
| 8 | App\Services\Billing\CashierAutoRechargeGateway \| payment_client_call |
| 4 | App\Services\Billing\CashierStripeGateway \| payment_client_call |
| 2 | App\Services\Billing\CashierTicketCheckoutGateway \| payment_client_call |
| 6 | App\Services\Billing\StripeScheduleGateway \| payment_client_call |
| 1 | App\Services\Captcha\RecaptchaVerifier \| http_facade_reference |
| 1 | App\Services\FxRateService \| http_facade_reference |
| 1 | App\Services\Organization\OrganizationMembershipService \| mail_facade_reference |

`suppressed`: 0 件。
