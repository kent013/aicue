# Round 2: Round 1 の指摘への対応報告

Round 1 の [Warning] 4 件 / [Suggestion] 2 件はすべて対応しました。以下が対応内容です。
再レビューし、全体判定 (APPROVED / CHANGES_REQUESTED) を出してください。

## 対応マトリクス

# 対応マトリクス: design-review Round 1

Codex 全体判定: **CHANGES_REQUESTED** (Critical 0 / Warning 4 / Suggestion 2)。
施策別: 1・2・4・6 = APPROVE / 3・5 = REQUEST_CHANGES。

## [Warning] 施策 1 のリスク説明が過小評価 (「新たに増えるものはない」)

- 判断: **対応する**
- 根拠: 指摘が正しい。allowlist 追加は**能力の新規付与**であり、「増えない」と書くのは誇張の逆方向
  (過小評価) にあたる。本タスクの必須事項「誇張しない」に反する。
- 対応内容: 施策 1 の「リスク」節を書き換え、**能力が 1 つ増えることを明記**したうえで受容根拠を
  4 点 (self スコープのみ / CSRF 保護下 / 権限・認証手段・準拠判定を動かさない / 業務面は遮断のまま) に整理した。

## [Warning] 施策 3 の enum 例が `use LogicException;` 欠落・`RescueRouteGateKind` が変更ファイル一覧に無い

- 判断: **対応する**
- 根拠: 正しい。同一ファイルへの 2 enum 定義は PSR-4 / autoload の観点でも避けるべき。
- 対応内容: `app/Enums/Security/RescueRouteGateKind.php` を**独立ファイル**として変更ファイル一覧・施策一覧に追加。
  enum 例に `use LogicException;` を追記。

## [Warning] 「ゲート通過性」という不変条件名が保証範囲より強い

- 判断: **対応する**
- 根拠: 正しい。母集団から CSRF / session / cookie / binding / Inertia 履歴暗号化を外している以上、
  「全 middleware を通過できる」とは言えない。名前と保証範囲の不一致は将来の誤読を生む。
- 対応内容: 不変条件の文言を「**扱いを目録に宣言してあること**」に限定し、
  「これは通過の全称証明ではない」を ⚠ 付きで明記。施策名も「ゲート通過性を固定」→
  「ゲート分類を deny-by-default 目録で固定」に変更。テスト名が `…Inventory` (目録) であって
  `…Passage` ではないことも明示した。

## [Warning] 施策 5 の XHR テストは recent-auth に先に遮断されうる

- 判断: **対応する** (ただし事実関係は補正して記録する)
- 根拠: 実測 (`probe-middleware.php` の resolve 済み middleware 列) では
  `RequireTwoFactorForEnforcedOrganizations` が `RequireRecentAuth` より**先**に走るため、
  現状では先に遮断されることはない。ただし **priority list の変更に対してテストを脆くしない**という点で
  指摘は有益であり、step-up 済みセッションを与える方が堅い。
- 対応内容: XHR 版・HTML 版とも `withSession(freshRecentAuthSession())` を明示。
  併せて「実測順では 2FA が先だが、順序非依存にするため付ける」と根拠をテスト計画に残した。

## [Suggestion] 「退会取消は allowlist 経由で通る」テストに `from('/settings')` を付ける

- 判断: **対応する**
- 根拠: controller は `back()` を返すので Referer 無しだと redirect 先が副作用的になる。UI 導線と一致させる方が良い。
- 対応内容: `from('/settings')->delete(...)` + `assertRedirect('/settings')` に変更。

## [Suggestion] 「禁止事項 3」の番号が混線している

- 判断: **対応する**
- 根拠: 正しい。AGENTS.md の禁止事項 3 は dev DB 破壊操作であり、「既存テストの削除・上書き」は
  `app-design` スキルの禁止事項表 #3 である。番号で指すと誤読する
  (AGENTS.md 自身も「番号ではなく項目名で指せ」と規定している)。
- 対応内容: 参照元を「`app-design` スキルの禁止事項表 #3」に訂正し、AGENTS.md の 3 と別物である旨を注記。

## [Suggestion] 「保証しないもの」に「取消だけを許し他の状態変更は許さない」を追加せよ

- 判断: **対応する**
- 対応内容: 保証しないもの 11 として追加 (「未準拠でもアカウント操作ができる」と要約しないこと)。

---

## 修正後の該当箇所 (差分の実体)

### 施策 1「リスク」節 (書き換え後)

- **能力は 1 つ増える (過小評価しない)**。これまで遮断されていた「2FA 必須組織の未準拠ユーザーの
  認証済みセッションによる退会取消」が実行できるようになる。**新しい能力付与であることを認めた上で受容する**。
  受容の根拠:
  - 対象は **self スコープの取消 1 操作のみ** (route parameter が無く他者に届かない。
    `ControllerAuthorizationGateTest` の `SelfScopedResource` 登録済み)
  - **CSRF 保護下**にある (`PreventRequestForgery` は本ゲートより前で走る)
  - **権限・認証手段・2FA 準拠判定 (`two_factor_confirmed_at`) を一切増減させない**
  - 取消後も全業務面は遮断されたまま (施策 5 の負のコントロールで実測固定)
  - セッション奪取者が取消できる点は controller docblock で既に受容済み

### 施策 3 の不変条件 (書き換え後)

> **救済 route (`settings.account.deletion-request.destroy`) の経路上にある**
> **「自前 middleware すべて」と「名指しした認証系 vendor gate 3 本」は、その救済 route に対する扱いを**
> **目録に宣言してあること。**

⚠ これは「route 上の全 middleware を通過できる」という保証ではない (CSRF 419 / session 開始 /
binding 404 / Inertia 履歴暗号化は母集団外)。目録が守るのは分類の網羅であって通過の全称証明ではない。
命名もそれに合わせる (`RescueRouteGateInventoryTest` = 目録であり `…PassageTest` ではない)。

変更ファイル (新規 3 本):
- `app/Enums/Security/RescueRouteGateDisposition.php` (`use LogicException;` を明記)
- `app/Enums/Security/RescueRouteGateKind.php` (**独立ファイル**)
- `tests/Architecture/RescueRouteGateInventoryTest.php`

### 施策 5 のテスト計画 (該当 2 件、修正後)

- [追加] `test('XHR で遮断された書き込みも 409 本文に「実行されていません」を含む')`
  - `withSession(freshRecentAuthSession())->postJson('/settings/account/deletion-request')` → 409 /
    `message` が prefix で始まる / `code` = `two_factor_required` / `redirect` = `settings.security` /
    `Cache-Control: no-store, private`
  - ★実測した resolve 順では 2FA ゲートが `RequireRecentAuth` より**先**に走るが、
    **順序に依存しないテストにする**ため step-up 済みセッションを与える (HTML 版も同様)
- [追加] `test('退会取消は allowlist 経由でゲートを通り 2FA 状態を変えない')`
  - 未準拠 + 未予約で `from('/settings')->delete(...)` → `assertRedirect('/settings')` (冪等 no-op)
  - `two_factor_confirmed_at` が null のまま

### 「保証しないもの」への追記 (11 番)

11. **2FA 未準拠セッションに許すのは「退会予約の取消」だけ**である。他のいかなる状態変更も許さない
    (予約作成・即時削除・2FA 無効化・業務操作はすべて遮断のまま)。この非対称を
    「未準拠でもアカウント操作ができる」と要約しない。

### 禁止事項の参照訂正

「既存テストの削除・上書き」は **`app-design` スキルの禁止事項表 #3** であり、
AGENTS.md の禁止事項 3 (dev DB への破壊操作) とは別物である旨を注記しました。

---

## 補足: Round 1 の [Warning] 4 件目についての事実補正

`devnotes/20260811-0146-blocked-action-context/probe-middleware.php` を実行して取得した
`settings.account.deletion-request.store` の resolve 済み middleware 列 (priority 適用後) は次のとおりで、
`RequireTwoFactorForEnforcedOrganizations` は `RequireRecentAuth` より**先**に走ります。

```
… App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations
… App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations
… App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages
… Inertia\Middleware\EncryptHistory
… Illuminate\Auth\Middleware\EnsureEmailIsVerified
… App\Http\Middleware\EnsureAccountNotPendingDeletion
… App\Http\Middleware\RequireRecentAuth        ← 最後
```

したがって「recent-auth に先に遮断される」ことは現状では起きませんが、priority list の変更に対して
テストを脆くしないという趣旨は妥当なので、指摘どおり step-up 済みセッションを与える形に修正しました。
