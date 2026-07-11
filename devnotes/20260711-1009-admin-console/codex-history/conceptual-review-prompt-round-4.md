# Round 4: Round 3 指摘（Warning 1 件）への対応

指摘の排他方式を設計へ明記した。判定を依頼する。

## 対応内容

**[Warning] resolver の tx 内呼び出しだけでは削除競合を防げない** → `DefaultProjectResolver` を read/write の 2 メソッドに分離し、D2 に以下を明記した:

> **read / write の 2 メソッドに分離**する: 表示・redirect 用の `resolve()`（ロックなし）と、書き込み用の `resolveForUpdate()`（**`lockForUpdate()` 付き解決**。呼び出し側トランザクション内で取得から pivot 更新完了まで Project 行ロックを保持し、解決直後の project 削除競合を排除する。CategoryService が Project 行ロックを直列化点とする既存規約とも整合）。ロール変更・招待受諾の pivot 書き込みは必ず `resolveForUpdate()` 経由。

- read 用（capture.home / nav 導線 / 一覧表示）はロック不要のため `resolve()` を使う。
- write 経路（ロール変更コマンド・招待受諾の pivot attach）は Service トランザクション内で `resolveForUpdate()` → pivot 更新までロック保持。不在（削除済み）は従来定義どおり: ロール変更/招待送信では `ValidationException`（error bag）、受諾時は org 参加 + 未割当。
