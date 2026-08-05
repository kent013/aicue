# impl-review Round 2 対応マトリクス

Codex 判定: **CHANGES_REQUESTED** ([Critical] 0 / [Warning] 2 = 実質 1 論点 / [Suggestion] 0)

Round 1 の #1 / #3 / #4 / #5 は解消済みと確認された。残ったのは #2 の掘り下げ。

| # | 分類 | 指摘 | 判断 | 対応内容 |
|---|------|------|------|---------|
| 1 | Warning | `plansMatch` の `unlocatable.reason` 比較では不十分。`ftp://a` → `ftp://b` は `canonicalOrigin` のエラー文言が同一なので `reason` が潰れ、古い計画のまま削除が進む。**状態識別子として `reason` を使うのが誤り**で、計画に元の `api_url` 自体を持つべき | **対応する** | `ProfileDeletionPlan` に `apiUrl: string \| undefined` (計画時に観測した生値) を追加し、`plansMatch` の**第一条件**を `a.apiUrl !== b.apiUrl` にした。多対一に潰れる派生値 (`reason` / `origin`) は状態識別子から降格し、`kind` / `origin` は「api_url が同じなら一致するはず」の派生整合チェックとして残した (`reason` 比較は不要になったので削除) |
| 2 | Warning | 5c-f は `reason` が異なるケースしか扱っておらず、多対一問題を固定できていない | **対応する** | テストを 2 本追加。5c-g = `ftp://a` → `ftp://b` (`reason` が同一に潰れるケース)、5c-h = `https://a.example.com/v1` → `/v2` (`origin` が同一に潰れるケース)。既存 5c-f も残した |

## 逆確認 (追加分。実測後 revert 済み)

| # | 改悪 | 期待 | 実測 |
|---|------|------|------|
| M8 | `plansMatch` から `if (a.apiUrl !== b.apiUrl) return false;` を削除 | 5c-f / 5c-g / 5c-h が赤 | **3 failed**: `f. unlocatable 同士でも理由が変われば競合終了する` / `g. 同じ reason になる別 api_url へ変わっても競合終了する` / `h. 同じ origin になる別 api_url (path 違い) でも競合終了する` |

`apiUrl` 比較は `reason` 比較を包含するため、M7 (reason 比較の削除) はミューテーションとして
成立しなくなった。代わりに M8 が同じ不変条件をより強くカバーする。

## 副作用の自己申告

`api_url` の生値で比較するため、**origin が同じでも path だけ変わった書き替え**でも
競合終了するようになった (5c-h)。credential の在り処 (origin ベース) は同じなので
「消しても実害は無い」ケースだが、設計書の収束契約は
「確認待ちの間に config が書き替わった → **何も触らず exit 10**」であり、
fail-closed 側に倒すのが設計意図に一致する。ユーザーはそのまま再実行すれば収束する。

## 見送った指摘

なし (全件対応)。

## 検証結果 (対応後)

```
pnpm typecheck:packages     : OK
pnpm test:packages          : 10 files / 106 passed / 0 failed  (Round 2 開始時 104)
pnpm -F "./packages/*" lint : OK
pnpm lint / pnpm typecheck  : OK
```
