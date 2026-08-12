# 対応マトリクス: design-review Round 2

判定 REQUEST_CHANGES。Critical 1 / Warning 2 / Suggestion 1。**すべて対応**(反論なし)。

## [Critical] 契約 8 は M6 を殺せない

- 判断: **対応する** (M6 を計画から外す)
- 根拠: 完全に正しい。`EnsureAccountNotPendingDeletion` は `$request->user()` が `User` でなければ
  **何もせず次へ渡す**ので、認証より前に置いても未認証要求は素通りし、
  その後の `Authenticate` が同じ 401 を返す = **観測できない**。
- 対応内容: **M6 を削除**し、理由を設計へ明記した。順序の不変条件は middleware の
  **早期 return そのもの**が構造的に満たしていると整理し、契約 8 が固定するのは
  「凍結が未認証要求を 409 で横取りしない」ことだ、と主張を正確にした。

## [Warning] context の既定引数は渡し忘れを検出できない

- 判断: **対応する**
- 根拠: そのとおり。「非 HTTP なので null」と「渡し忘れの null」が区別できない。
- 対応内容: context を **必須引数**にし、`AccountDeletionAuditContext::http(...)` /
  `::nonHttp()` の **named constructor** を用意した (private constructor)。
  既存 2 箇所も明示的に `nonHttp()` を渡すよう変更する。
  「既存 2 箇所は無変更」を利点として書いていたのを撤回した。

## [Warning] 文書内の件数・変更範囲が古い

- 判断: **対応する**
- 対応内容: 施策一覧を「契約 8 件 (1〜6 + 7a + 7b + 8)」に、変更ファイルへ DTO と
  `Settings\AccountController` を追加。実装モードの記述も実際の変更範囲へ揃えた。

## [Suggestion] HTTP method は enum でもよい

- 判断: **見送る** (`?string` のまま)
- 根拠: レビュアー自身が「監査 metadata 用で値による分岐をしないなら `?string` でも承認可能」と
  述べている。enum 化は分岐しない値に型を足すだけで、思考原則 2 に照らして今は要らない。
