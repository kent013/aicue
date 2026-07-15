**施策1（フロント: enabled のみに解除ボタン表示）: APPROVE**
- [Suggestion] `canResetTwoFactor()` の条件を `member.twoFactorStatus !== "enabled"` にする方針は、既存バッジ条件（`=== "enabled"`）と意味論が一致し、バグ根因に直接効いています。  
- [Suggestion] コメントで `pending` の扱い理由を明記している点は保守性に有効です。実装時は `DESIGN.md` の「disabled禁止」規約どおり、ボタン非活性化ではなく非表示/押下時エラー方針を維持してください。  

**施策2（サーバ: enabled 以外拒否）: APPROVE**
- [Warning] 現在案だと `pending` でも `ValidationException` で同一文言を返す設計ですが、運用監査上 `disabled` と `pending` の切り分け需要が将来出る可能性があります。  
  修正案: 今回は文言据え置きで可。ただし最低限、`SecurityEventRecorder` 側の記録前に拒否されることをテストで固定し、「拒否時は通知・監査イベントを発火しない」ことを明示してください（副作用抑止の仕様化）。
- [Suggestion] `lockForUpdate()` 内で状態判定しているのは TOCTOU 耐性として妥当。AGENTS の「子は親に属する」404先行も `abort_unless(...exists(), 404)` で満たしています。  

**施策T1（vitest: pending 非表示 / enabled 表示）: REQUEST_CHANGES**
- [Critical] 提案テストの `queryAllByText("2FA").toHaveLength(2)` は fixture 変更に脆く、将来 unrelated な fixture 追加で壊れやすいです。  
  修正案: 件数固定ではなく対象行スコープで検証する。例として `member-row-5` 内に `2FA` バッジが無いこと、`member-row-2` には解除ボタンがあることを `within(row)` で確認してください。  
- [Warning] `baseProps()` 依存が強いので、既存テストの前提（閲覧者 role が owner）を明示しないと失敗原因が不透明になります。  
  修正案: テスト名または arrange で `viewerIsOwner` 前提を明示し、role由来失敗と2FA状態由来失敗を分離。  

**施策T2（Feature: pending reset 拒否）: APPROVE**
- [Suggestion] `assertSessionHasErrors(['two_factor'])` に加えて、`two_factor_confirmed_at` が `null` のまま、`two_factor_secret` が不変であることを合わせて検証すると回帰耐性が上がります。  
- [Suggestion] 既存の enabled 成功テストと対にして、「pending は拒否・enabled は成功」の状態機械をテスト名で対比させると仕様が読みやすいです。  

**横断レビュー（観点2〜11）**
- [Suggestion] DTO/JsonResource: 今回は既存 props 再解釈で完結しており追加不要判断は妥当。`response()->json()` 直書き回避も維持。  
- [Suggestion] Inertia vs API: 本件は画面表示制御＋POST結果のセッションエラー処理なので Inertia 境界で十分、API拡張不要。  
- [Suggestion] PHPStan Lv10: enum 比較のみで null 安全性に問題なし。  
- [Warning] 副作用リスクとして「pending を管理者がクリア不可」は仕様変更点です。  
  修正案: リリースノート/運用手順に「pending は本人再設定で解消」の1行追記を推奨。  
- [Suggestion] Atomic Design / DESIGN.md: 新規コンポーネント追加なし、DS token 変更なし、Lucide 追加なしで準拠。  
- [Suggestion] セキュリティ: cross-org/tenant不信/404先行/権限境界の既存防御を壊していません。  

**全体判定: CHANGES_REQUESTED**
- 主理由は **T1テストの脆弱な件数アサーション（Critical）** のみです。  
- ここを行スコープ検証へ直せば、全体は **APPROVED 相当** です。