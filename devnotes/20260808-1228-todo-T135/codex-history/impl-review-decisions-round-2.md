# Round 2 対応マトリクス

Codex 判定: **CHANGES_REQUESTED** (Critical 0 / Warning 4 / Suggestion 0)。
コード・テストは全ファイル APPROVED。**4 件の Warning はすべて同一の根本原因**である:

> Round 1 の修正が**逆方向に過剰修正**していた。
> 「fail-fast が効くのは非 cached 起動 **=** `route:cache` 生成時」と等号で結んだが、
> fail-fast は **binder が実際に走る起動すべて** (route cache が無い起動 =
> ローカル開発起動・テスト・`route:cache` 生成時の再 bootstrap) で効く。
> `route:cache` 生成時**だけ**ではない。

Codex の指摘は正しい。本 TODO の中心は「機序の記述が実態と一致していること」であり、
不足方向 (Round 1 前) も過剰方向 (Round 1 後) も等しく失敗である。4 件すべて対応した。

## 採用した正確な記述 (4 箇所で統一)

1. fail-fast が効くのは **後付けが実際に走る起動すべて** = route cache が無い起動。
2. **cached 起動では後付けごと skip されるので効かない**
   (そこで例外を投げると `route:list` が必ず落ちる = T120)。
3. ただし **cached 運用の本番で意味を持つ検出点は `route:cache` 生成時**である
   (そこで止まらなければ、cached 起動は skip するのでサービス投入まで誰も気づかない)。

| # | 指摘箇所 | 判断 | 対応内容 |
|---|---|---|---|
| 1 | `AGENTS.md:375` 「非 cached 起動 = `route:cache` 生成時」の等号 | **対応する** | 等号を外し、上記 1/2/3 の 3 段で書き直した |
| 2 | `RouteMiddlewareBinder.php:34` 「fail-fast が効くのはここ」の過剰限定 | **対応する** | 「ここでデプロイが止まる」は残し、fail-fast の作用範囲 (1) と本番での意味 (3) を分けて併記した |
| 3 | `docs/app-integration-guide.md:318` (§7b) 同じ等号 | **対応する** | §7b も同じ 3 段へ |
| 4 | `docs/app-integration-guide.md:462` (§7c) 「fail-fast が効くのはここだけ」 | **対応する** | 「fail-fast 自体は cache 無し起動すべてで効く」+「**cached 運用の本番で意味を持つ検出点は**ここだけ」へ分離。"ここだけ" の限定を消さず、何に対する "だけ" なのかを明示した |

## 触っていないもの (意図)

- `app/Support/Http/RouteThrottleBinder.php` L26 にも同種の
  「fail-fast はここで効く」がある。**詳細設計が「1 行も変更しない」と確定している**ため
  今回は触らない (差分ゼロであることが振る舞い不変の証拠として機能している)。
  Codex も差分外のため指摘していない。次に同ファイルを触る TODO で是正する候補として
  ここに記録しておく。
- 既存の route 保護系テスト群 — 1 行も変更しない。
