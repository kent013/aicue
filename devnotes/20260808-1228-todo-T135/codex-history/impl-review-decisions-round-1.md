# Round 1 対応マトリクス

Codex 判定: **CHANGES_REQUESTED** (Critical 0 / Warning 4 / Suggestion 1)。
コード挙動・テスト網羅には指摘なし。指摘は**すべて記述精度**であり、本 TODO の中心が
「誤った機序記述の是正」であるため 5 件すべて対応した。

| # | 指摘 | 種別 | 対応 | 内容 |
|---|---|---|---|---|
| 1 | `AGENTS.md` ドメイン固有規約 5 の「route 名が消えたら**起動時** fail-fast」が今回の契約 (cached 起動は skip) と衝突 | Warning | **対応** | 「効くのは非 cached 起動 = `route:cache` 生成時。cached 起動では後付けごと skip される。そこで例外を投げると `route:list` が必ず落ちる (T120)」へ限定 |
| 2 | `RouteMiddlewareBinder` の docblock「throttle の後付けは RouteThrottleBinder が担当」が実装と矛盾 (本 binder は `throttle:passkeys` を付ける) | Warning | **対応** | 責務境界を書き直した。RouteThrottleBinder = limiter 形式検証・二重付与検出を持つ throttle 専用、こちら = 任意 alias を列順に冪等付与。**「throttle は必ず向こう」ではない**ことを明示し、passkey 系を 1 route の alias 列として扱う理由 (throttle → recent-auth → 手段保持 の順序契約が割れるため) を書いた |
| 3 | 「現行 2 経路は判定タイミングが異なる (Fortify = boot 内 / Passkey = booted 内)」が現在の配線では不正確 (どちらも `attachOnBooted()` 内の booted callback で評価される) | Warning | **対応** | 詳細設計の文面をそのまま持ち込んでいた箇所。「spec の構築 (feature flag 判定を含む) を `boot()` へ前倒し評価しない。resolver は booted callback の中でだけ評価される」へ差し替えた。**詳細設計の文面からの意図的な逸脱** (設計時の記述が実装後の事実と合わなくなったため) |
| 4 | `docs/app-integration-guide.md` §7b の「route 名が消えていれば**起動時に** fail-fast」が §7c とズレる | Warning | **対応** | #1 と同じ限定を §7b に入れた (§7c への導線つき)。§7c 側 (L457「fail-fast が効くのはここだけ」) は元から正しいので触っていない |
| 5 | `PasskeyServiceProvider::withAlias()` の「型を緩めず」が強すぎる | Suggestion | **対応** | 「`mixed` 化や `@phpstan-ignore` に逃げず、公開契約の型をそのまま保ったまま具体 shape の推論だけを切る」へ書き換えた |

## 触っていないもの (意図)

- `app/Support/Http/RouteThrottleBinder.php` — L26 の fail-fast 記述は元から正しい
  (「ここで**デプロイが止まる**」= 生成時)。詳細設計どおり **1 行も変更しない**。
- 既存の route 保護系テスト群 — 1 行も変更しない (差分なしで green = 振る舞い不変の直接証拠)。
