# Step C: mutation 実測 (T124 gate が空振り green でないことの証拠)

実行環境: worktree `/workspace/.claude/worktrees/tasks/T124` (branch `todo/T124`)
実行コマンド (各 mutation ごとに 1 回):

```bash
composer test -- --filter='TwoFactorStepUpInventory|RecentAuthRoute'
```

手順は「mutation を **1 つずつ**適用 → 実行 → **必ず revert**」。
自動化スクリプトは `finally` で必ず原本を書き戻す形にしてある
(適用中に中断しても mutation が残らない)。

**適用前の baseline**: 上記 filter で **11 tests / 11 passed / 0 failed** (実測)。

## 結果一覧

| # | mutation | 期待 | 実測 |
|---|---|---|---|
| m1 | RECENT_AUTH_ROUTE_NAMES から 'two-factor.secret-key' を 1 本抜く | 「未分類」検査が two-factor.secret-key を列挙して fail + 「免除にできない route」検査も fail | **failed** (3 件 fail) |
| m2 | twoFactorStepUpExemptions() に 'two-factor.nonexistent' を足す | stale 検出が fail | **failed** (3 件 fail) |
| m3 | twoFactorStepUpExemptions() に 'two-factor.disable' (recent-auth 済み) を足す | 死んだ exemption 検出が fail | **failed** (4 件 fail) |
| m4 | 'two-factor.confirm' の理由を 'N/A' に短縮 | 30 文字検査が fail | **failed** (1 件 fail) |
| m5 | 'two-factor.qr-code' を exemption へ移し RECENT_AUTH_ROUTE_NAMES から外す | 「この route は exemption にできません」が fail (全体 cap 超過も同時に fail) | **failed** (4 件 fail) |
| m6 | セレクタを str_contains($name, 'two-factor.') (ドット付き) に狭める | 母集団 exact fit が fail (設計の予測件数は 9 だが実測は 10。下記注記) | **failed** (1 件 fail) |
| m7 | twoFactorStepUpPopulationSize() を 12 にする | 母集団 exact fit が fail (数値だけ書き換える運用を防げることの確認) | **failed** (1 件 fail) |
| m8 | CONDITIONAL_RECENT_AUTH_ROUTES に 'two-factor.disable' => 'recent-auth.on-email-change' を足す (別種同居) | 「1 種類ちょうど」検査が「別種の recent-auth middleware が 2 本同居している」で fail | **failed** (2 件 fail) |

全 8 mutation が期待どおり fail した (green のまま通り抜けたものは **0 件**)。

## 各 mutation の実測メッセージ (先頭 1 行 + 列挙内容)

### m1: RECENT_AUTH_ROUTE_NAMES から 'two-factor.secret-key' を 1 本抜く

期待: 「未分類」検査が two-factor.secret-key を列挙して fail + 「免除にできない route」検査も fail

実測: `failed` / fail 3 件

- **母集団の各_route_は_recent_auth_系_middleware_をちょうど_1_種類持つか_exemption_inventory_に明示分類されている__未知は_fail_**
  - `2FA 面の step-up 付与が不正です。recent-auth を貼るか、貼らないことが正しい理由を twoFactorStepUpExemptions() に TwoFactorStepUpExemption + 具体的根拠付きで登録してください。`
  - `two-factor.secret-key: recent-auth が無く exemption inventory にも未登録`
- **免除にできない_route_は必ず_recent_auth_系_middleware_をちょうど_1_種類持つ__免除側へ移されたら_fail_**
  - `秘密開示 / 第二要素差し替え route の step-up は免除できません (T124 の存在理由そのものです)。`
  - `two-factor.secret-key: 秘密の開示 / 第二要素の差し替え経路なのに recent-auth 系 middleware が 1 種類ではありません`
- **機微操作_route_全件に_recent_auth_middleware_が付与されている**
  - `route 'two-factor.secret-key' に recent-auth middleware が付与されていない (付け忘れ)`

### m2: twoFactorStepUpExemptions() に 'two-factor.nonexistent' を足す

期待: stale 検出が fail

実測: `failed` / fail 3 件

- **exemption_inventory_の_key_は現存する母集団_route__stale_検出_**
  - `exemption inventory に現存しない route 名があります (削除/rename 済み): two-factor.nonexistent`
- **exemption_件数が上限ちょうどを超えない__形骸化ガード_**
  - `exemption が 4 件あります。免除を増やす前に、その route に step-up を課せない構造的理由が本当にあるかを再検討してください。`
- **exemption_の_case_別件数が上限を超えない__分類の偏り検出_**
  - `pre_auth_challenge_surface: 3 件 (上限 2)`

### m3: twoFactorStepUpExemptions() に 'two-factor.disable' (recent-auth 済み) を足す

期待: 死んだ exemption 検出が fail

実測: `failed` / fail 4 件

- **exemption_登録された_route_は_recent_auth_を_1_本も持たない__死んだ_exemption_の検出_**
  - `recent-auth が付いているのに exemption が残っています (免除が形骸化しています)。inventory から削除してください: two-factor.disable`
- **exemption_件数が上限ちょうどを超えない__形骸化ガード_**
  - `exemption が 4 件あります。免除を増やす前に、その route に step-up を課せない構造的理由が本当にあるかを再検討してください。`
- **exemption_の_case_別件数が上限を超えない__分類の偏り検出_**
  - `proof_of_secret_possession_required: 2 件 (上限 1)`
- **免除にできない_route_は必ず_recent_auth_系_middleware_をちょうど_1_種類持つ__免除側へ移されたら_fail_**
  - `秘密開示 / 第二要素差し替え route の step-up は免除できません (T124 の存在理由そのものです)。`
  - `two-factor.disable: この route は exemption にできません`

### m4: 'two-factor.confirm' の理由を 'N/A' に短縮

期待: 30 文字検査が fail

実測: `failed` / fail 1 件

- **exemption_inventory_の値は_enum___実質的な理由文字列**
  - `two-factor.confirm: 理由が 30 文字未満です`

### m5: 'two-factor.qr-code' を exemption へ移し RECENT_AUTH_ROUTE_NAMES から外す

期待: 「この route は exemption にできません」が fail (全体 cap 超過も同時に fail)

実測: `failed` / fail 4 件

- **機微操作_route_全件に_recent_auth_middleware_が付与されている**
  - `route 'two-factor.qr-code' に recent-auth middleware が付与されていない (付け忘れ)`
- **exemption_件数が上限ちょうどを超えない__形骸化ガード_**
  - `exemption が 4 件あります。免除を増やす前に、その route に step-up を課せない構造的理由が本当にあるかを再検討してください。`
- **exemption_の_case_別件数が上限を超えない__分類の偏り検出_**
  - `proof_of_secret_possession_required: 2 件 (上限 1)`
- **免除にできない_route_は必ず_recent_auth_系_middleware_をちょうど_1_種類持つ__免除側へ移されたら_fail_**
  - `秘密開示 / 第二要素差し替え route の step-up は免除できません (T124 の存在理由そのものです)。`
  - `two-factor.qr-code: 秘密の開示 / 第二要素の差し替え経路なのに recent-auth 系 middleware が 1 種類ではありません`
  - `two-factor.qr-code: この route は exemption にできません`

### m6: セレクタを str_contains($name, 'two-factor.') (ドット付き) に狭める

期待: 母集団 exact fit が fail (設計の予測件数は 9 だが実測は 10。下記注記)

実測: `failed` / fail 1 件

- **母集団が_exact_fit_である__セレクタの空振り___vendor_の_route_追加を検出_**
  - `2FA route の母集団が 10件 (期待 11 件) です。セレクタの空振り、または Fortify / アプリ側の route 増減が起きています。増えた route を分類してからこの数値を更新してください。`
  - `organizations.members.two-factor.reset`
  - `two-factor.confirm`
  - `two-factor.disable`

### m7: twoFactorStepUpPopulationSize() を 12 にする

期待: 母集団 exact fit が fail (数値だけ書き換える運用を防げることの確認)

実測: `failed` / fail 1 件

- **母集団が_exact_fit_である__セレクタの空振り___vendor_の_route_追加を検出_**
  - `2FA route の母集団が 11件 (期待 12 件) です。セレクタの空振り、または Fortify / アプリ側の route 増減が起きています。増えた route を分類してからこの数値を更新してください。`
  - `organizations.members.two-factor.reset`
  - `organizations.two-factor-requirement.update`
  - `two-factor.confirm`

### m8: CONDITIONAL_RECENT_AUTH_ROUTES に 'two-factor.disable' => 'recent-auth.on-email-change' を足す (別種同居)

期待: 「1 種類ちょうど」検査が「別種の recent-auth middleware が 2 本同居している」で fail

実測: `failed` / fail 2 件

- **母集団の各_route_は_recent_auth_系_middleware_をちょうど_1_種類持つか_exemption_inventory_に明示分類されている__未知は_fail_**
  - `2FA 面の step-up 付与が不正です。recent-auth を貼るか、貼らないことが正しい理由を twoFactorStepUpExemptions() に TwoFactorStepUpExemption + 具体的根拠付きで登録してください。`
  - `two-factor.disable: 別種の recent-auth middleware が 2 本同居している (無条件 step-up と条件付き step-up の混在。契約は 1 種類ちょうど)`
- **免除にできない_route_は必ず_recent_auth_系_middleware_をちょうど_1_種類持つ__免除側へ移されたら_fail_**
  - `秘密開示 / 第二要素差し替え route の step-up は免除できません (T124 の存在理由そのものです)。`
  - `two-factor.disable: 秘密の開示 / 第二要素の差し替え経路なのに recent-auth 系 middleware が 1 種類ではありません`

## m6 の実測件数が設計の予測と違う点 (記録に残す)

設計は「ドット付きセレクタなら母集団は 9 件」と予測していたが、実測は **10 件**だった。
`organizations.members.two-factor.reset` は route 名に `two-factor.` (ドット付き) を
**含む**ため、狭めたセレクタでも母集団に残る。落ちるのは
`organizations.two-factor-requirement.update` の 1 本だけである。

検出そのものは期待どおり成立している (exact fit が fail し、取りこぼしを機械的に知らせる)。
予測件数が違っただけなので gate の設計は変えていない。

## m8 の非対称 (誇張しないための注記)

m8 は **別種**の recent-auth alias (`recent-auth` と `recent-auth.on-email-change`) を
同一 route に同居させた mutation であり、期待どおり
「別種の recent-auth middleware が 2 本同居している」で fail した。

一方で **同一 alias の重複登録は検査していない**。理由は 2 段ある:

1. **そもそも重複を作れない**: 配線点の
   `FortifyServiceProvider::appendMiddlewareIfMissing()` は
   `! in_array($alias, $route->middleware(), true)` を条件に append する idempotent 実装である。
2. **仮に作れても実行時に観測できない**: `Illuminate\Routing\Route::gatherMiddleware()`
   (vendor 実査 L1065-1076) は結果を `Router::uniqueMiddleware()` (同 L1472-1484) に通し、
   `$seen[$key]` で**同一文字列を畳む**。したがって重複は dispatch にも差を生まない。

観測できない差分に gate を置くと偽陽性しか生まないため、意図的に検査対象から外している。
`RouteThrottleBinder` が throttle 側で「2 本以上は fail」にしているのは、
`throttle:6,1` と `throttle:named` が**別文字列で実効上限が半減する**ためであり事情が異なる。

## revert 確認

全 mutation 適用後に `git status --short` / `git diff` を確認し、
`app/Providers/FortifyServiceProvider.php` と
`tests/Architecture/TwoFactorStepUpInventoryTest.php` の差分が
**T124 の意図した変更だけ**であることを目視確認済み (mutation の残置ゼロ)。

## main 取り込み後の再実行 (2026-08-07 15:3x JST)

セッション中断を挟み、`git merge main` (T133 キャッシュ gate / T125 inline throttle 分離を含む)
を取り込んだ**後**に、同じ `mutate.py` で **m1〜m8 を全件再実行**した。

| # | 実測 (再実行) | 前回 | 一致 |
|---|---|---|---|
| m1 | failed (3 件) | failed (3 件) | ○ |
| m2 | failed (3 件) | failed (3 件) | ○ |
| m3 | failed (4 件) | failed (4 件) | ○ |
| m4 | failed (1 件) | failed (1 件) | ○ |
| m5 | failed (4 件) | failed (4 件) | ○ |
| m6 | failed (1 件) | failed (1 件) | ○ |
| m7 | failed (1 件) | failed (1 件) | ○ |
| m8 | failed (2 件) | failed (2 件) | ○ |

fail したテスト名も前回と同一 (上の各節の記録どおり)。
T125 は `throttledFortifyRoutes()` と `AppServiceProvider::configureActorScopedRateLimiters()`
を触ったが、**本 gate が見る母集団 (route 名) と recent-auth 配線には触れていない**ため
検出能力は変わっていない (母集団 exact fit 11 件も維持)。

再実行後に `git diff` / `grep -rn 'mutation m[0-9]' app/ tests/` を確認し、
mutation の残置がゼロであることを確認済み。
