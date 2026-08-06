# 対応マトリクス: design-review Round 3 (最終)

**全体判定: APPROVED** (全 10 施策 APPROVE)。Critical / Warning とも 0 件。

Round 3 で出た Suggestion 5 件は、詳細設計の
「実装者への申し送り (Codex 詳細設計レビュー Round 3)」節に**全件転記済み**。

| # | Suggestion | 判断 | 転記先 |
|---|-----------|------|--------|
| 1 | `parseThrottleEntry()` 自身で既存 params の形式検証まで完結させる | 対応する | 申し送り 1 |
| 2 | 施策 3 は `private const` ではなく `throttledFortifyRoutes()` を実装する | 対応する | 申し送り 2 |
| 3 | import 解析でトップレベル import / クロージャ `use (...)` / trait `use` を区別する。禁止 alias は実際に `::for()` で使われた場合のみ `unresolved` | 対応する | 申し送り 3 |
| 4 | `RateLimiter::clear()` はキー指定が必要。引数なしで呼ばない | 対応する | 申し送り 4 |
| 5 | route cache 検証は失敗時にも必ず `route:clear` を実行する | 対応する | 申し送り 5 |

## レビュー履歴の要約

- 概念設計: 3 ラウンド (Round 2 で Critical 1 件 = webhook 全体天井の DoS 化 / route:cache 自己矛盾 を解消)
- 詳細設計: 3 ラウンド (Round 1 Warning 11 件・Round 2 Warning 3 件をすべて反映し Round 3 で APPROVED)
- 打ち切り: レビュー上限 3 ラウンドに到達したのは概念設計のみ。詳細設計は 3 ラウンド目で APPROVED。
  **未解決の Critical / Warning は 0 件**。
