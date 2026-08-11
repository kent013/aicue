# 詳細設計レビュー Round 6 (deferred-robustness)

Round 5 の Warning 1 件(fail-first の赤・緑集計)を訂正しました。**反論はありません。**

---

## 対応マトリクス (Round 5)

# 対応マトリクス: design-review Round 5

Round 5 判定: **CHANGES_REQUESTED**(Critical 0 / Warning 1 / Suggestion 0)。
施策 1a・1b・1c・2 は APPROVE。残るは fail-first の集計誤り 1 件のみ。**反論なし。**

## [Warning] 施策 3: fail-first の赤・緑集計が表と矛盾している

- 判断: **対応する(指摘が正しい。単純な数え間違い)**
- 根拠: 期待マトリクスの表は R-1a テスト 1=赤 / 2=緑 / 3=赤 / 4=緑、R-1b=赤、R-2=赤 なので、
  **赤 4 本・緑 2 本**である。「赤 3 本・緑 3 本」は表自身と矛盾していた。
- 対応内容: 実装順序 1 の実装開始条件を
  「**赤 4 本(R-1a テスト 1・3 / R-1b / R-2)・緑 2 本(R-1a テスト 2・4)が
  期待マトリクスどおりに揃ってから**実装に入る」へ訂正した。
  Round 4 の対応マトリクスにも同じ誤記があったので併せて訂正した。

## 見出しの重複について

- Round 4 で「抜粋提示の副作用」と反論した点は、Round 5 で見出し一覧を提示した結果
  **Codex が「問題がないことを確認できます。変更不要です」と追認**した。

---

## Round 5 で反論した点

**なし。**


---

## 訂正後の実装モード + 実装順序 (全文)

```
## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | **アプリコードの変更は 2 ファイル・計 3 箇所**(ほかに文書 2 ファイルとテスト 3 ファイル)の小さな置換で、新モデル・新 migration・新 gate・**新しい共有抽象や制約名台帳**を伴わない(施策 1b が `private const string ATTEMPT_ORG_PENDING_UNIQUE` を 1 本追加するのみ)。DB スキーマも API 形も変えないので main への追従コストが低い。施策 1a / 1b / 2 は互いに独立しており、片方だけ先に入っても他方は壊れない |
| 競合リスク | `AutoRechargeService.php` は auto-recharge 系 TODO と競合しうる(直近では aicue:T137/T140 が触っている)。ただし本変更は catch 節 2 個と private メソッド 1 個の削除に閉じるため衝突しても解決は容易。`TakeUploadService.php` は撮影 PWA 系の TODO と競合しうるが、変更は `forceFill` の 1 キー追加のみ。**`SubscriptionService.php` には触らない**(施策 1c 撤回) |
| 実装順序 | 下表のとおり(Codex Round 3 [Warning] を反映。**検証を済ませてから基準コミットを打つ**) |

### 実装順序(確定)

1. 全テストを先に追加し、**修正前の期待マトリクスと一致することを実測して
   `red-before-fix.txt` に残す**。「全部赤にする」のではない —— 期待は次のとおり:

   | テスト | 修正前 | 意味 |
   |---|---|---|
   | R-1a テスト 1(`stripe_session_id`-only 衝突) | **赤** | 別制約の握り潰しの再現 |
   | R-1a テスト 2(正規 replay) | **緑** | 成功時の振る舞いを変えないことの基準 |
   | R-1a テスト 3(既存行の session id 食い違い) | **赤** | 壊れた台帳を飲まないことの再現 |
   | R-1a テスト 4(別 actor の同 token) | **緑** | actor 非検証の契約の基準 |
   | R-1b(`attempt_ulid` 衝突) | **赤** | 別制約の no-op 収束の再現 |
   | R-2(in-memory `status`) | **赤** | 既定値依存の再現 |

   **赤 4 本(R-1a テスト 1・3 / R-1b / R-2)・緑 2 本(R-1a テスト 2・4)が
   期待マトリクスどおりに揃ってから**実装に入る
   (緑であるべきものが赤なら前提が誤っているので設計を見直す)
2. 施策 1a → 1b → 2 → 3(文書)を実装する
3. **`composer fix && composer phpstan && composer test` を通す**(全 green)
4. **基準コミットを打つ**(mutation の復帰基準。`composer fix` の差分もここに含める)
5. mutation **M-1 / M-2 / M-2c / M-3 / M-4 / M-6** を**1 箇所ずつ**実施し `mutation.txt` に残す
6. 代替実装 probe **P-1** を実施し `alternative-probe.txt` に残す
7. **M-7**: すべて戻し `git diff --stat app/` が**空**(= 基準コミットと同一)であることを確認
8. 最終確認: `composer phpstan && composer test`

**`composer fix` をコミット後に走らせない**(差分が出て基準がずれる)。
```

---

判定を出してください。残る指摘が Suggestion のみなら **APPROVED** としてください。
