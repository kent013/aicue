# 実測ログ (設計時 2026-08-24)

## probe2.ts — 詳細設計レビュー Round 1/3 を反映した最終形の判定式

`.svelte` は 1 ファイル 1 仮想 TS + 末尾 `export {};` / パッケージごとに自前 tsconfig で program /
`as const`・`satisfies`・丸括弧の剥がし / 定数配列は const 束縛 / 派生除外は 3 集合一致 + 対応表以外の証人 /
語の対応は候補形の集合 + 最大マッチング (レビュー Round 3 反映) / 型が解決できない候補は判定保留として数える。

```
population .ts=377 (tracked .ts incl .d.ts=378) .svelte=130
php resolved=123 unresolvable=3
programs=<root>,packages/cli build ms=4695
```
population .ts=377 (tracked .ts incl .d.ts=378) .svelte=130
php resolved=123 unresolvable=3
programs=<root>,packages/cli build ms=4494
scanned files=507 unresolvable=3
  [unresolvable] tests/js/support/enum-ts-sync/fixtures/t22-circular.ts:1::X (型別名が解決できない)
  [unresolvable] tests/js/support/enum-ts-sync/fixtures/t22-circular.ts:2::Y (型別名が解決できない)
  [unresolvable] tests/js/support/enum-ts-sync/fixtures/t23-unresolved-import.ts:3::X (型別名が解決できない)
derived pending=86 excluded(witnessed)=40 kept=46
candidates total=345 {"literal-union":106,"object-keys":172,"const-array":54,"switch-cases":13}
undecidable(名前解決不能かつ交差あり)=0
hits total=10 {"1":6,"2b":3,"2a":1}
  [規則1] app/Enums/Manual/CutType.php <-> resources/js/components/features/manual/ScenarioEditor.svelte:401::DragOwner (literal-union) 完全一致
  [規則1] app/Enums/Notification/NotificationType.php <-> resources/js/components/features/notifications/NotificationListItem.svelte:67::switch:notification.type (switch-cases) 完全一致
  [規則1] app/Enums/ApiKeyAbility.php <-> resources/js/pages/Organizations/ApiKeys/Index.svelte:61::ABILITY_LABELS (object-keys) 完全一致
  [規則1] app/Enums/OAuth/OAuthClientKind.php <-> resources/js/pages/Organizations/ApiKeys/Sessions.svelte:41::CLIENT_KIND_LABELS (object-keys) 完全一致
  [規則1] app/Enums/Manual/TakeStatus.php <-> resources/js/types/manual.ts:409::SelectableTakeStatus (literal-union) 完全一致
  [規則1] app/Enums/EnterpriseSso/OidcConnectionStatus.php <-> tests/js/components/features/sso/oidc-connection.test.ts:17::ALL_STATUSES (const-array) 完全一致
  [規則2a] app/Enums/ApiErrorCode.php <-> packages/cli/src/api/schemas.ts:310::ApiErrorCode (literal-union) 厳密名対応 (apierrorcode = apierrorcode) / 交差 6 値
  [規則2b] app/Enums/ApiErrorCode.php <-> packages/cli/src/api/schemas.ts:294::API_ERROR_CODES (const-array) 語対応 3/3 語 主要語=code / 交差 6 値
  [規則2b] app/Enums/OAuth/CliOAuthScope.php <-> packages/cli/src/oauth/login.ts:49::DEFAULT_CLI_SCOPES (const-array) 語対応 2/4 語 主要語=scope / 交差 4 値
  [規則2b] app/Enums/Manual/JobStatus.php <-> resources/js/types/dashboard.ts:10::DashboardJobStatus (literal-union) 語対応 2/2 語 主要語=status / 交差 2 値
```
## svelte-scope-probe.mjs — 仮想 TS をモジュール文脈にしないと型が別ファイルへ漏れる

A.ts が `type Shared = "a" | "b"` を宣言し、B.ts が (宣言せずに) `type Ref = Shared` と書いた場合の解決結果。

```
without export {}: [ '/v/A.ts::Shared = a|b', '/v/B.ts::Ref = a|b' ]
with export {}  : [ '/v/A.ts::Shared = a|b', '/v/B.ts::Ref = Shared' ]
```

`export {};` が無いと B.ts の `Ref` が **A.ts の型に解決されてしまう** (偽の候補が立つ)。
足すとモジュール文脈になり解決できなくなる = 本設計の「解決できない候補は解析不能として落とす」に掛かる。

## probe.ts — 概念設計時の初回計測 (履歴。判定式は上記より粗い)

```
# mode=excluded
tracked .ts=379 .svelte=130
population .ts=378 .svelte=130
php resolved=123 unresolvable=3
program build ms=3072 sourceFiles=5859
derived(object-keys)=63 witnessed(excluded)=10 witnessless(kept)=53
broken syntax files=0 
candidates total=304 {"union":106,"object-keys":163,"switch-cases":13,"const-array":22}
hits total=8 {"1":6,"2b":1,"2a":1}
  [rule 1] app/Enums/Manual/CutType.php <-> resources/js/components/features/manual/ScenarioEditor.svelte:401::DragOwner (union) exact
  [rule 1] app/Enums/Notification/NotificationType.php <-> resources/js/components/features/notifications/NotificationListItem.svelte:67::switch:notification.type (switch-cases) exact
  [rule 1] app/Enums/ApiKeyAbility.php <-> resources/js/pages/Organizations/ApiKeys/Index.svelte:61::ABILITY_LABELS (object-keys) exact
  [rule 1] app/Enums/OAuth/OAuthClientKind.php <-> resources/js/pages/Organizations/ApiKeys/Sessions.svelte:41::CLIENT_KIND_LABELS (object-keys) exact
  [rule 1] app/Enums/Manual/TakeStatus.php <-> resources/js/types/manual.ts:409::SelectableTakeStatus (union) exact
  [rule 1] app/Enums/EnterpriseSso/OidcConnectionStatus.php <-> tests/js/components/features/sso/oidc-connection.test.ts:17::ALL_STATUSES (const-array) exact
  [rule 2a] app/Enums/ApiErrorCode.php <-> packages/cli/src/api/schemas.ts:310::ApiErrorCode (union) apierrorcode = apierrorcode
  [rule 2b] app/Enums/Manual/JobStatus.php <-> resources/js/types/dashboard.ts:10::DashboardJobStatus (union) words[job+statu] head=statu
```
