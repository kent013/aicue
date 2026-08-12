# 実装メモ: T160 凍結中の即時削除の観測ギャップを閉じる (bug-hunt F-4-Q1)

設計: `devnotes/20260812-1410-freeze-destroy-xhr/` (概念 Round 4 APPROVED / 詳細 Round 5 APPROVE)。
実装レビュー: `impl-review-round-1.md` (APPROVE) / `impl-review-round-2.md` (DTO 本体の確認)。

## 何が問題だったのか

探索エージェントが、退会予約 (凍結) 中に `settings.account.destroy` へ**直 DELETE** して
アカウントが実際に削除された、と報告した。**ただし 2 回のクリーン再現では遮断された**。

実コードを読むと実装は正しく (allowlist に入っておらず group middleware で遮断)、
**遮断は既に Feature テストで固定されていた** — ただし **HTML 経路 (302) だけ**。
探索エージェントが通った **XHR/JSON の DELETE には遮断を固定するテストが 1 本も無かった**
(JSON 409 のテストは `getJson('/dashboard')` で代用されていた)。
**再現しなかったことは無罪証明ではない**ので、観測ギャップを閉じた。

## 実装したもの (防御は増やしていない)

| # | 施策 |
|---|---|
| 1 | `AccountDeletionAuditContext` (readonly DTO) を新設し、`deleteAccount()` が**必須引数**で受け取る。監査 metadata に `deletion_requested` / `route` / `method` を残す |
| 2 | 契約 9 件を `AccountDeletionFreezeTest` へ追加 |
| 3 | `docs/architecture.md` §退会の猶予期間つき削除 に順序の決定と監査 metadata を記載 |

**順序の決定**: 凍結中の即時削除は **recent-auth の有無にかかわらず 409** (凍結が step-up より先)。
理由は (a) 凍結状態を知るのは本人で `/settings` に既に表示しており秘匿すべき相手がいない、
(b) 再認証させてから断るのは体験として悪い。**実行順が変わっても 409 が正**である。

**context を必須引数にした理由**: 既定引数だと「HTTP 外なので null」と
「HTTP 呼び出し元の渡し忘れ」が区別できない。`http()` / `nonHttp()` の名前つき
コンストラクタで**判断を強制**する (deny-by-default)。

**これは観測であって防御ではない** — `deletion_requested` の値で分岐する処理は 1 つも無い。

## fail 先行 (仮説と実測)

仮説「契約 7a / 7b が赤、1..6 / 8 は緑」→ **実測一致** (27 件中 2 件赤)。
実装は既に正しく、**足りなかったのは観測**だったことが裏づけられた。

## mutation の実測 (予測との対比)

| # | mutation | 予測 | 実測 |
|---|---|---|---|
| M1 | allowlist に `settings.account.destroy` を足す | 契約 1・2・3・5・6 | 一致 (5 件) |
| M2 | middleware の `expectsJson()` 分岐を消す | 最低 1・3 | 一致 (6 件) |
| M3 | metadata の `deletion_requested` を落とす | 契約 7a・7b | 一致 (2 件) |
| M4 | metadata の `route` / `method` を落とす | 契約 7a | **予測より広く 2 件** (7a・7b)。7b も `route`/`method` が `null` であることを `toMatchArray` で見ているため。**予測の書き方が狭かった** |
| M5 | `deletion_requested` を常に `false` にする | **契約 7b のみ** | 一致 (1 件だけ) |

**M5 が 7b だけを赤くした**ことで、設計 Round 1 の Critical
(「7a では M5 を殺せないので service レベルの 7b が要る」) が実測で裏づけられた。

なお設計段階で **M6 (凍結判定を認証より前へ動かす) は mutation として成立しない**と判明し、
計画から外した — middleware は user 不在なら何もせず次へ渡すので、順序を変えても未認証要求は
素通りし、その後の `Authenticate` が同じ 401 を返す = 観測できない。

## 設計からの乖離 2 点

1. 契約 6 の 2FA 必須組織のフラグ名。設計時は `requires_two_factor` と書いていたが、
   実際の列は **`two_factor_required`**。テストを実列名へ修正した。
2. **`AccountDeletionPathGateTest` の `DELETION_PATH_CLOSURE` へ新 DTO の登録が必要**だった。
   退会経路の依存閉包 gate (T141) が exact-fit で赤くなった = **想定どおりの deny-by-default**。
   「観測専用 DTO で決済事業者 SDK への到達辺を持たない」旨のコメント付きで登録した。

## 検証コマンド (worktree 内)

`composer test` **4549 passed / 2 skipped (4551)** / `composer phpstan` No errors /
`vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck`: 全緑。

## 保証しないもの (誇張しない)

- **観測された 1 件の原因は特定していない**。本 TODO は契約テストと監査 metadata を足すだけで、
  原因特定や防御追加は行わない。
- **並行実行 (ブラウザ遷移と fetch の競合) は再現しない**。Feature テストは 1 リクエストずつ
  順に実行するため、探索エージェントが疑った競合そのものは検査できない。その代わりが監査 metadata。
- **防御は増えない**。`deletion_requested` の値で分岐する処理は作っていない。
