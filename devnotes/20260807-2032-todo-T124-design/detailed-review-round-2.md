全体判定: **CHANGES_REQUESTED**

Round 1 の主要指摘は概ね適切に反映されています。特に施策 4 の自動再実行ループ対策は、delegated 分岐と鮮度判定不整合の両方を有界化できています。ただし、Architecture gate の「ちょうど1本」検査に実装上の空振りがあり、設計書内にも修正漏れがあります。

## 施策 1: REQUEST_CHANGES

[Warning] `AuthThrottleCoverageTest` の扱いが設計書内で矛盾しています。

「波及変更」では計画された変更へ格上げされていますが、「既存テストの更新」では依然として「実行して落ちる場合のみ」と書かれています。

修正案: 後者も次の契約に統一してください。

- 2FA秘密GETレーンには計画どおりfresh sessionを付与する
- テスト失敗の有無を変更条件にしない
- throttleの検査意図、閾値、limiter名、アサーションは変更しない

`force=true` の3カラム検査とconfirmed状態の前提固定は妥当です。

## 施策 2: REQUEST_CHANGES

[Critical] `gatherMiddleware()` を使った `countAttached()` では、二重付与を検出できない可能性が高いです。

Laravelの `Route::gatherMiddleware()` は、route middlewareとcontroller middlewareを集約する過程で重複を除去する実装です。そのため、同じ `'recent-auth'` がraw middlewareへ2回登録されても、返却時には1本へ正規化され、m8が偽グリーンになる可能性があります。

修正案:

- raw route middlewareとcontroller middlewareを重複除去前に取得して数える
- その取得方法がLaravel 12で実際に重複を保持することを実査する
- m8では同じRoute instanceへ実際に2本付与し、`countAttached() === 2`になることを先に確認する
- raw取得でcontroller middlewareを含められない場合は、「付与元別の重複検査」と「実効付与の存在検査」を分離する

少なくとも、現状の `gatherMiddleware()` ベースのまま「ちょうど1本を保証する」とは記載できません。

[Warning] middleware aliasの判定が広すぎます。

`str_starts_with($middleware, 'recent-auth')` は、将来の `recent-authentication` のような別aliasまで数えます。

修正案: 次だけを受理してください。

```php
$middleware === 'recent-auth'
|| str_starts_with($middleware, 'recent-auth:')
|| str_starts_with($middleware, 'recent-auth.')
|| $middleware === RequireRecentAuth::class
```

[Warning] Step Aに `vendor/bin/pest` の直叩きが残っています。

対応マトリクスとDoDでは禁止していますが、設計本文のコードブロックは未修正です。

修正案:

```bash
composer test -- tests/Architecture/TwoFactorStepUpInventoryTest.php
```

worktree内で実行するため、`cd /workspace` も固定しない方が安全です。

[Warning] DoDがm8を含んでいません。

mutation計画はm1〜m8ですが、DoD 6は「m1〜m7」のままです。

修正案: `m1〜m8` に変更してください。

non-exemptibleを6本へ拡張した判断と、組織管理routeを既存allowlist側へ残す境界設定は妥当です。

## 施策 3: APPROVE

passkey satisfierだけを通し、credential管理routeを通さない設計は適切です。正のケースと負のコントロールも揃っています。

[Suggestion] 「200以外が正なら実測に合わせる」は、実装時の閾値調整に見えないよう、実装前に現行Fortifyの契約を確定してください。期待値はテストを走らせて通る値へ合わせるのではなく、controllerの正常契約を確認してから決めるべきです。

## 施策 4: APPROVE

Round 1のCriticalは解消されています。

- delegated時に自動再取得しない
- stale確認後の自動再開を1回に制限
- サーバ判定不整合でも停止
- 人間の操作で再試行可能
- fetch回数を直接検査

この組み合わせなら、無限ループと行き止まりの両方を防げます。既知pathをexportして使用し、サーバ由来redirectを持ち回らない判断も妥当です。

[Suggestion] コード例のstep-up Alertでは一度 `testId="retry-enrollment-assets-button"` と記載した後、注記で `retry-enrollment-step-up-button` に訂正されています。実装者の取り違えを防ぐため、コード例そのものを最終IDへ直してください。

## 施策 5: REQUEST_CHANGES

[Warning] 「ちょうど1本」の記録は、施策2のcount実装が重複除去前の本数を検査できる形へ直るまで成立しません。

修正案: 施策2をraw middlewareベースの検査へ修正してから、この文言を採用してください。

[Warning] Definition of DoneがAGENTS.mdのcanonical verification commandsを網羅していません。

現在のDoDには以下がありません。

- `pnpm build`
- `pnpm typecheck:packages`
- `pnpm build:packages`
- `pnpm test:packages`

修正案: AGENTS.mdのマーカー領域にある全検証コマンドをDoDへそのまま反映してください。テスト系コマンドは各ラッパー経由でグローバルロックを維持します。

worktree上のstandalone実装と、main直実装禁止の明記は適切です。

## 最終判断

主な残課題は次の4点です。

1. `gatherMiddleware()`の重複除去を回避し、exact-one gateを実効化する
2. Step Aに残った`vendor/bin/pest`直叩きを修正する
3. mutationのDoDをm8まで揃える
4. DoDへcanonical verification commandsを全件反映する

施策4のCriticalは解消済みです。上記を直せば、設計全体は承認可能な状態になります。