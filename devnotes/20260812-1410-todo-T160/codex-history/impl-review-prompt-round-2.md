# Round 2: 新規 DTO 本体の提示

Round 1 で「新規 DTO 本体が diff に含まれていない」と指摘があったため、その差分だけを提示します
(untracked のため `git diff HEAD` に出ていませんでした)。設計どおり `readonly` /
`http()` / `nonHttp()` / 既定引数なし になっているか確認してください。

```diff
diff --git a/app/DataTransferObjects/Account/AccountDeletionAuditContext.php b/app/DataTransferObjects/Account/AccountDeletionAuditContext.php
new file mode 100644
index 0000000..13f96a8
--- /dev/null
+++ b/app/DataTransferObjects/Account/AccountDeletionAuditContext.php
@@ -0,0 +1,34 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Account;
+
+/**
+ * 削除の到達経路 (監査 metadata 用。T160 / bug-hunt F-4-Q1)。
+ *
+ * ★**観測専用**である。この値で分岐する処理は 1 つも作らない (防御ではない)。
+ *
+ * ★`deleteAccount()` は本 context を**必須引数**で受け取る。既定引数にすると
+ *   「HTTP 外なので null」と「HTTP 呼び出し元の渡し忘れ」が区別できなくなるため、
+ *   名前つきコンストラクタで**判断を明示させる** (deny-by-default)。
+ */
+final readonly class AccountDeletionAuditContext
+{
+    private function __construct(
+        public ?string $route,
+        public ?string $method,
+    ) {}
+
+    /** HTTP 経由の削除 (route 名と HTTP メソッドを残す) */
+    public static function http(?string $route, string $method): self
+    {
+        return new self($route, $method);
+    }
+
+    /** HTTP 外 (猶予期間の日次執行・コンソール)。route / method とも null が正常値 */
+    public static function nonHttp(): self
+    {
+        return new self(null, null);
+    }
+}
```
