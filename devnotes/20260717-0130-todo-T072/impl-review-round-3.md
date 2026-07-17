## ファイル別判定

- `app/Services/Billing/StripeWebhookProcessor.php` — **APPROVE**
- `tests/Feature/Billing/SignupGrantOncePerOrgTest.php` — **APPROVE**

org 行ロック下で marker claim と grant が同一 transaction に閉じられ、失敗時 rollback も負のコントロール付きテストで固定されています。並行性・再送時の冪等性にも残る穴は見当たりません。

## 全体判定

**APPROVED**