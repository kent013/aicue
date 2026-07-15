**全体判定**
- **CHANGES_REQUESTED**

**施策1（onSuccess で close）: REQUEST_CHANGES**
- [Warning] `onSuccess` で `open = false` は方向性として正しいですが、`onSuccess` 実行タイミング依存の説明がやや強いです。Inertia 実装差異や将来変更を考えると、「タイミング保証」より「同一コンポーネント再利用時に open を明示的に閉じる」という不変条件で記述した方が堅いです。  
  修正案: 設計書のリスク節を「`onSuccess` で close を必ず実行し、同一ページ再利用時の開きっぱなしを防ぐ」に修正（“visit 完了後に必ず呼ばれる”断定を弱める）。
- [Suggestion] `onSuccess` 内で `form.clearErrors()` も入れると、再オープン時の視覚ノイズをさらに減らせます（施策3でも clear するため必須ではない）。

**施策2（多重送信ガード）: APPROVE**
- [Suggestion] 設計意図（禁止事項8との境界）が明確で妥当です。`if (form.processing) return;` を `submit()` 冒頭に置く判断は `onclick`/`onsubmit` 両経路を一括で抑止でき、実装位置として最適です。

**施策3（false→true エッジで再seed）: REQUEST_CHANGES**
- [Critical] `$effect` で `prevOpen` を更新する方式は成立しますが、`open` が連続変化するケースでの可読性と安全性が弱く、レビュー観点の「Svelte5 edge 検知」に対してやや脆いです。  
  修正案: `let prevOpen = $state(open);` で初期同期し、`$effect` 冒頭で `const wasOpen = prevOpen; prevOpen = open; if (open && !wasOpen) seedFromDefaults();` の順にして、依存と遷移判定を明確化。
- [Warning] `seedFromDefaults()` が `form` へ代入する前提は `useForm` の shape に依存します。テストダブルでは通っても本体 `useForm` 型とズレる可能性があります。  
  修正案: `const form = useForm<{ title: string; category: string }>(...)` の型を維持しつつ、`seedFromDefaults` 内で代入対象をこの2キーのみに限定する旨をコメントで明記（他キー拡張時の事故防止）。

**施策4（vitest 追加）: REQUEST_CHANGES**
- [Critical] 「送信中は二重送信されない」テストの前提が不足しています。`holder.last.processing = true` だけでは、クリック対象が既に disabled のため「UI による抑止」なのか「submit 冒頭 guard」なのか判別できません。  
  修正案: 2ケースに分離  
  1) `submit` 直接経路（`form` submit event）で `post` 未実行を検証（関数ガード確認）  
  2) ボタン disabled/`aria-busy` を検証（UIガード確認）
- [Warning] 再seedテストで `clearErrors` 呼び出し確認は良いですが、実害観点として「既存エラー文言が消えるDOM観測」も1 assertionあると回帰耐性が上がります。  
  修正案: 初期errorsを持つ mocked form を用意し、再オープン後に該当エラーが非表示になることを `queryByText` で確認。
- [Suggestion] `onSuccess` close テストは `queryByTestId(...) === null` だけでなく、`open` bind の副作用として再レンダー後に reopen できることまで入れると、施策1+3の統合回帰を拾えます。

**施策5（reactiveUseForm の processing 反応化）: APPROVE**
- [Warning] `...initial` の後に accessor を置く設計は妥当ですが、「initial に processing が来ない」前提は暗黙です。  
  修正案: 型で防止（`TData extends Record<string, unknown> & { processing?: never; errors?: never }` など）し、テストヘルパー側で衝突をコンパイル時に禁止。
- [Suggestion] `transform(fn)` が `fn` を未使用のままなのは既存互換上やむなしですが、将来の検証性向上のため `lastTransformed` を保持する拡張を検討余地あり。

**レビュー観点別サマリ**
- 正確性: 主方向は正しいが、施策3のエッジ検知記述をより堅牢に。
- 既存整合: Inertia `useForm`/Button/Modal パターンとの整合は概ね良好。
- 型安全: `defaultCategory: number | null` の扱いは妥当。ヘルパー型制約を追加するとより安全。
- テスト網羅: 主要ケースを押さえているが、ガード検証の分離が必要。
- Inertia Props vs API: redirect前提の整理は適切。
- 副作用/後退: `onSuccess` close + reopen reseed の設計は妥当。
- 波及: `Manuals/Show.svelte` 非変更判断は合理的。`reactiveUseForm` 影響確認は必須。
- セキュリティ: フロントガードが冪等性代替でない整理は適切。
- DESIGN/Atomic: 逸脱なし。
- 禁止事項8: 「未充足では無効化しない」「送信中のみ無効化」の境界は正しい。

必要なら、このまま**修正版のテストケース粒度（具体的な `it` 分割案）**まで落として提案します。