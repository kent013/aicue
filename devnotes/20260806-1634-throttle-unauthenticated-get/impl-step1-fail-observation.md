# 施策 1 単独適用時の fail 観測 (テストファーストの証拠)

実行 (worktree `.claude/worktrees/tasks/T121`):

```
APP_ENV=testing php devnotes/20260806-1634-throttle-unauthenticated-get/measure-population.php
```

```
APP_ENV=testing
現行母集団 (S3 に $isMutating あり): 47
拡張後母集団 (S3 から $isMutating を除去): 70
増分: 23
増分のうち既に throttle 済み: 4   (verification.verify / passkey.login-options /
                                   passkey.confirm-options / passkey.registration-options)
増分のうち分類が要る (貼る or 免除): 19
```

→ 詳細設計の実測値 (47 → 70 / 増分 23 / 既 throttle 4) と**完全一致**。

```
composer test -- --filter=ThrottleCoverageInventoryTest
```

結果: `tests=5 passed=4 failed=1`。
「保護対象 route は throttle をちょうど 1 本持つか exemption inventory に明示分類されている (未知は fail)」
が以下の **19 本ちょうど**を列挙して fail した (設計の想定どおり)。

```
filament.admin.auth.login: throttle が 1 本も無く exemption inventory にも未登録
filament.admin.auth.profile: throttle が 1 本も無く exemption inventory にも未登録
filament.admin.auth.multi-factor-authentication.set-up-required: throttle が 1 本も無く exemption inventory にも未登録
login: throttle が 1 本も無く exemption inventory にも未登録
password.request: throttle が 1 本も無く exemption inventory にも未登録
password.reset: throttle が 1 本も無く exemption inventory にも未登録
register: throttle が 1 本も無く exemption inventory にも未登録
verification.notice: throttle が 1 本も無く exemption inventory にも未登録
password.confirm: throttle が 1 本も無く exemption inventory にも未登録
password.confirmation: throttle が 1 本も無く exemption inventory にも未登録
two-factor.login: throttle が 1 本も無く exemption inventory にも未登録
two-factor.qr-code: throttle が 1 本も無く exemption inventory にも未登録
two-factor.secret-key: throttle が 1 本も無く exemption inventory にも未登録
two-factor.recovery-codes: throttle が 1 本も無く exemption inventory にも未登録
social.redirect: throttle が 1 本も無く exemption inventory にも未登録
social.callback: throttle が 1 本も無く exemption inventory にも未登録
recent-auth.confirm: throttle が 1 本も無く exemption inventory にも未登録
recent-auth.status: throttle が 1 本も無く exemption inventory にも未登録
invitations.accept: throttle が 1 本も無く exemption inventory にも未登録
```

この 19 本を「throttle を貼る 5 本」と「exemption 14 本」に分類するのが施策 2〜9 である。
