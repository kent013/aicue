# Round 3: Round 2 指摘への対応

## 対応マトリクス

### 施策 2 [Critical] `gatherMiddleware()` では二重付与を検出できない → **指摘は正しい。ただし対応は「raw で数える」ではなく「契約を実態に合わせる」**

実査しました。`Illuminate\Routing\Route::gatherMiddleware()` は

```php
return $this->computedMiddleware = Router::uniqueMiddleware(array_merge(
    $this->middleware(), $this->controllerMiddleware()
));
```

で、`Router::uniqueMiddleware()` は**値をキーにした `$seen` で重複を除去**します（Laravel 12 実査）。
ご指摘のとおり、同一文字列 `'recent-auth'` を 2 回登録しても gatherMiddleware は 1 本を返します。

ここで「raw middleware を重複除去前に数える」対応は**採りませんでした**。根拠:

- 同一 alias の重複は framework が畳むため、**実行時に観測できず振る舞いにも差が出ません**。
  観測できない差分に gate を置くと、**偽陽性しか生まない検査**になります
  （`RouteThrottleBinder` が throttle で「2 本以上は fail」にしているのは、
  `throttle:6,1` と `throttle:named` が**別文字列で実効上限が半減する**からで、事情が違います）。
- raw で数えると controller middleware を取りこぼし、「付与元別の重複検査」と
  「実効付与の存在検査」を分離することになり、判定点が増えます。

代わりに、**検査する価値があり、かつ dedup されない**状態を検査対象に確定しました:

> **別種の recent-auth が同居している**状態（例: `recent-auth`（無条件 step-up）と
> `recent-auth.on-email-change`（条件付き step-up）が同一 route に付く = どちらが真の契約か読めない）

これは別文字列なので dedup されず `countAttached()` が 2 を返します。
契約文言も「ちょうど 1 本」→「**ちょうど 1 種類**」へ直し、AGENTS.md 追記案にも
「同一 alias の重複は `Router::uniqueMiddleware()` が畳むため検査対象にしていない（誇張しない）」を明記しました。

mutation m8 も実効あるものへ差し替えました:
`CONDITIONAL_RECENT_AUTH_ROUTES` に `'two-factor.disable' => 'recent-auth.on-email-change'` を足して
別種同居を作り、「別種の recent-auth middleware が 2 本同居している」で赤化することを確認します。
観測記録には「同一 alias の重複では赤にならない」非対称も残します。

この判断が受け入れられない場合は理由をお聞かせください。

### 施策 2 [Warning] alias 判定が広すぎる → **対応**

`str_starts_with($middleware, 'recent-auth')` をやめ、提案どおり
`'recent-auth'` 完全一致 / `'recent-auth:'` 前方一致 / `'recent-auth.'` 前方一致 /
`RequireRecentAuth::class` 完全一致 の 4 形だけを受理する形にしました
（`recent-authentication` のような将来の別 alias を巻き込まない）。

### 施策 2 [Warning] Step A に `vendor/bin/pest` 直叩きが残っている → **対応**

本文のコードブロックを `composer test -- tests/Architecture/TwoFactorStepUpInventoryTest.php` に直し、
`cd /workspace` の固定も外しました（worktree 内で実行するため）。

### 施策 2 [Warning] DoD が m8 を含んでいない → **対応**（m1〜m8 に修正）

### 施策 1 [Warning] `AuthThrottleCoverageTest` の扱いが設計内で矛盾 → **対応**

「実行して落ちる場合のみ」を削除し、「計画どおり fresh session を付与する。
テストが落ちるかどうかを変更の条件にしない。検査意図・閾値・limiter 名・アサーションは
1 文字も変えない」に統一しました。

### 施策 3 [Suggestion] 期待値は controller の正常契約から決めよ → **対応**

「実測に合わせて調整」という書き方をやめ、
「実装着手時に vendor の passkey confirm-options controller を読んで正常系 status を確定してから書く。
走らせて赤かったから緩める、はしない」に直しました。

### 施策 4 [Suggestion] コード例の testId を最終形に → **対応**（コード例を `retry-enrollment-step-up-button` に修正）

### 施策 5 [Warning] DoD が canonical verification commands を網羅していない → **対応**

AGENTS.md のマーカー領域にある 10 コマンド
（`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` /
`pnpm test` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`）
を全件 DoD に反映しました。

### 施策 5 [Warning] 「ちょうど1本」の記録は gate が直るまで成立しない → **対応**（「ちょうど 1 種類」+ 非検査範囲の明記）

---

判定をお願いします。反論した 1 点（raw counter を作らず契約を「1 種類」に確定した判断）を含め、
残る懸念があれば指摘してください。

## 修正後の詳細設計書（全文）

