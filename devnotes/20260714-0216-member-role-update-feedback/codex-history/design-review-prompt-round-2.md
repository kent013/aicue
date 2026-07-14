# Round 2: Round 1 Warning 2 点の反映確認

Round 1 は APPROVED。残 Warning 2 点を堅牢化として反映した。全体判定を再確認してほしい。

## 対応
- [Warning] S1 `pageErrors.role` 配列化耐性 → 派生 `roleMessage` を追加。`pageErrors` を `Record<string, string | string[]>` とし、`const roleMessage = $derived.by(() => { const raw = pageErrors.role; return Array.isArray(raw) ? raw[0] : raw; });`。Select の `error` / `aria-describedby` / FormError `message` / 表示条件をすべて `roleMessage` に集約。
- [Warning] S2 ケース6 フォーカステスト安定化 → `await waitFor(() => expect(screen.getByTestId("member-role-2")).toHaveFocus())` に変更(手動 tick 依存を撤廃)。

これ以外の設計・スコープ・バックエンド回帰 assertion は Round 1 のまま。追加の Critical/Warning が無ければ APPROVED を明示してほしい。
