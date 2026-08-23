# 対応マトリクス: impl-review Round 5

Codex の全体判定は **`APPROVED`**。

Round 1〜4 の承認阻害事項 (Critical 1 / Warning 13) はすべて解消し、
Round 5 では**承認を阻む指摘は 0 件**だった。残ったのは Suggestion 1 件である。

Codex が付けた完了条件は「自主修正後の**全レーンが green** であることを確認してから
完了・コミットすること」であり、これはコードレビュー上の変更要求ではない。

---

## [Suggestion] `Event::forget('eloquent.created: …')` も「今回登録した closure だけ」の解除ではない

- **判断**: **今回は変更しない** (記録だけ残す)
- **根拠**: 指摘は正しい。`Event::forget()` はその event 名に登録された listener を
  **全部**忘れさせるので、「自分が張った closure だけ」を外すものではない。
  ただし Codex 自身が
  「現時点では `SecurityAuditEvent` に他の `created` listener がないとの調査があるため問題ない」
  「**承認阻害ではない**」と明示している。
  Round 4 の Suggestion から `flushEventListeners()` (そのモデルの**全 event**) →
  `Event::forget()` (**1 つの event 名だけ**) へ狭めたところであり、
  さらに専用の collaborator へ置き換えるのは**今回の受入条件の外**である。
- **将来の条件**: `SecurityAuditEvent` に observer / model event を張る trait が足された時点で、
  このテストを専用の失敗 collaborator へ置き換える。その旨はテストのコメントに書いてある。

---

## 全レーン確認で見つかった 1 件 (レビュー指摘外・自主修正)

Codex が付けた完了条件 (全レーン green) を確認したところ、
**`AccountDeletionPathGateTest` 検査 1 (退会経路の依存閉包の exact-fit)** が赤になった。

- **原因**: Round 4 で `SecurityEventRecorder` の docblock に
  `{@see EmailPromotionService::applyConfirmedEmail()}` と書いたところ、
  `pint` の `fully_qualified_strict_types` が
  **`use App\Services\Auth\EmailPromotionService;` を生成した**。
  この窓口は退会経路の依存閉包に入っているため、その 1 行で
  **昇格まわりのクラス 14 件が閉包へ流れ込んだ**
  (`EmailPromotionService` / `EmailPromotionStageBoundary` / `InertEmailPromotionStageBoundary` /
  `VerifiedEmail` / `EmailPromotion` / `EmailPromotionMail` / `UpdateUserProfileInformation` ほか)。
- **判断**: **説明のための言及が本物の依存になってはいけない**。
  gate は正しく鳴っている (依存閉包が黙って広がる形を検出するのが役目である)。
- **対応**: 2 件目の呼び出し元の言及を `{@see}` から**バッククォートの平文**へ替え、
  `use` 文を消した。**なぜ `{@see}` で書かないのか**を同じ docblock に書いた
  (次に読む人が「統一されていない」と見て `{@see}` へ戻すのを防ぐ)。
  1 件目 (`OrganizationAccessRevoker`) は**元から import 済み**なので変えていない。
- **確認**: 検査 1 を含む全レーンを再走して green を確認した。

---

## Round 5 で `APPROVE` された点 (最終形)

| 対象 | 判定 |
|---|---|
| `EmailPromotionService` (2 段を private へ戻し、公開入口は `confirm()` 1 本) | `APPROVE` |
| `EmailPromotionStageBoundary` / `InertEmailPromotionStageBoundary` (継ぎ目) | `APPROVE` |
| `AppServiceProvider` の binding (環境分岐を本番へ入れていない) | `APPROVE` |
| `InterferingEmailPromotionStageBoundary` (transaction level で「段を抜けた」を固定) | `APPROVE` |
| `EmailPromotionTest` (両方向 + reflection による退行検出 + 監査の巻き戻し) | `APPROVE` |
| `EnterpriseSsoSourceScanner` (区切りの stack 化 + fail-closed) | `APPROVE` |
| 見本 / 自己検査 (負例 10 形 / 正例 4 形) | `APPROVE` |
| `SecurityEventRecorder` の docblock (書き分けの軸) + caller gate の見送り | `APPROVE` |

---

## 合議の総括

| ラウンド | 判定 | 指摘 | 対応 |
|---|---|---|---|
| 1 | `CHANGES_REQUESTED` | Critical 1 / Warning 6 | 全 7 件 対応 |
| 2 | `CHANGES_REQUESTED` | Warning 3 / 空振り 1 / Suggestion 3 | 全 7 件 対応 |
| 3 | `CHANGES_REQUESTED` | Warning 4 / Suggestion 1 | 全 5 件 対応 |
| 4 | `CHANGES_REQUESTED` | 承認阻害 2 / Suggestion 4 | 5 件 対応 / 1 件 見送り (理由付き) |
| 5 | **`APPROVED`** | Suggestion 1 | 記録のみ |

反論は全ラウンドを通じて 0 件。見送りは 1 件 (`recordOrFail()` の caller gate) で、
Codex が承認阻害でないと明示したうえで「別 TODO 候補として残す判断で十分」と同意している。

ラウンド上限は当初 3 だったが、Round 3 の修正が未レビューのまま残っていたため
**監督者の裁量で +2 (Round 5 まで) 延長**した。
