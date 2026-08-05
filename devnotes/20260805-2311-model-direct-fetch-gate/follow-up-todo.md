# 後続 TODO (未起票): payload 由来 id の org 相対化 + `exists:` rule の見直し

`ModelDirectFetchInvariantTest` の `PayloadIdWithGlobalExistenceRuleDebt` 3 件が指す是正タスク。
**本ファイルは `DirectFetchInventory` の `todoRef` が指す追跡先である** (gate が実在を機械検証する)。

## なぜ TODO ID ではなくこのファイルなのか

T116 の実装セッションは `docs/TODO.md` の変更を禁止されている
(TODO のクローズは別担当が直列で行うため、同一ファイルを触ると必ず競合する)。
そのため後続 TODO を起票できず、`todoRef` に TODO ID を書けなかった。

**T116 を main へ取り込む担当者へ**: 下記の内容で `/app-todo-add` を実行し、
採番された ID (`aicue:T<番号>`) で本ファイルへの参照 3 箇所
(`tests/Support/Security/DirectFetchInventory.php` の `todoRef:`) を置き換えること。
gate は `aicue:T<番号>` が `docs/TODO.md` / `docs/TODO-closed.md` に実在することを検証するので、
置き換え後もテストは緑のままになる (プレースホルダは fail する)。

## 起票内容

| 項目 | 値 |
|---|---|
| タイトル | payload 由来 id の org 相対化 (直 fetch 債務 3 件の解消) |
| テーマ | backend |
| 概要 | `PayloadIdWithGlobalExistenceRuleDebt` の 3 件を relation 起点へ寄せ、`exists:` rule とセットで存在オラクルを閉じる |
| 優先度 | Medium |
| モード | standalone |
| 設計 | `devnotes/20260805-2311-model-direct-fetch-gate/` |

## 対象 (gate が inventory に固定している 3 件)

| 箇所 | 現状 | 是正方針 |
|---|---|---|
| `OrganizationOwnershipController::store` | `User::query()->findOrFail((int) $request->input('user_id'))` + `exists:users,id` | `$organization->users()->whereKey($userId)->firstOrFail()` へ寄せる |
| `ProjectMemberController::store` | 同上 (組織メンバー判定は fetch 後の `organizationRole()`) | 同上。403 の代わりに 404 になる挙動変更を受け入れるか判断する |
| `McpConsentOrganizationBinder::handle` | `Organization::query()->find($orgId)` (membership 確認は fetch 後) | `$user->organizations()->whereKey($orgId)->firstOrFail()` へ寄せる |

## 注意 (単独 TODO に切った理由)

**fetch 側だけ直しても `exists:users,id` が同じ情報を漏らす**。
「そのユーザーが存在するか」を組織非メンバーに対して 422 で答えてしまうため、
validation rule の見直しとセットでなければ存在オラクルは閉じない。
また 403 → 404 / 422 → 404 の**振る舞い変更**を伴うので、
機械検出の導入 (T116) とは分けてある (概念設計 §6-1 / §7-1)。

是正が完了したら、`DirectFetchInventory` の当該 3 エントリを削除し
(削除しないと stale 検出が fail する)、`modelDirectFetchDebtCap()` を 0 に下げること。
