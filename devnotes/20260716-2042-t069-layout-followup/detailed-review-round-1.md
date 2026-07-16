全体判定: **CHANGES_REQUESTED**

- 理由は主に **S3 の Architecture テスト設計の実効性不足** と、**S2/S3 の責務境界が実装者依存で揺れる点**です。  
- S1 は概ね妥当で、そのまま進めて問題ない水準です。

**S1 設定 nav 二重掲載の解消: APPROVE**
- [Suggestion] `Settings` アイコン import 削除まで設計に明記しており、`pnpm lint` 整合まで考慮できている点は良いです。
- [Suggestion] 「/settings は左 nav から除去、ユーザーメニューに一本化」は情報設計として一貫しています（既存導線維持も明記済み）。
- [Warning] テスト観点は `desktop()` のみだと将来レイアウト分岐で取りこぼしが出る可能性。  
  **修正案**: 既存 test ヘルパにモバイル/レスポンシブ分岐があるなら同等の存在/非存在も 1 ケース追加し、UI 変形時の回帰耐性を上げる。

**S2 PageContent primitive 新設: REQUEST_CHANGES**
- [Warning] `maxWidth` を必須化する方針は良いが、**デフォルト幅ポリシー不在**により将来の新規ページ追加時に意思決定が分散します。  
  **修正案**: `PageContent` 側でデフォルトを持たせず現方針を維持するなら、`docs` かテストメッセージで「標準は 2xl、例外は理由付き」を明文化して運用規約化してください。
- [Warning] `data-testid="page-content"` を primitive 固定で付与する設計はテスト容易性は高い一方、DOM 契約の固定化リスクがあります。  
  **修正案**: テストは class/assertion 優先にし、`data-testid` は「必要ページのみ付与」または「`testId?` prop」で任意化を検討（過剰でなければ現状維持でも可）。
- [Suggestion] `MAX_W` の union 制約は DS/型安全の観点で適切。Svelte5 runes 文法にも整合しています。

**S3 23ページ移行 + Architecture テスト: REQUEST_CHANGES**
- [Critical] Architecture テストの `<PageContent` 検出が正規表現ベースのみだと、`{#if}` 分岐・別名 import・改行/属性順で誤判定しやすく、**強制力が不安定**です。  
  **修正案**: 最低限以下を仕様に追加してください。  
  1) `import PageContent from "@/components/templates/PageContent.svelte"` の**識別子名を capture**  
  2) テンプレート側はその識別子の開始タグ出現を検査（固定文字列 `<PageContent` 依存を避ける）  
  3) 失敗時メッセージに「不足 import / 未使用 / allowlist 対象外」を分離表示
- [Critical] 「AppLayout 直下〜PageContent まで max-w 禁止」soft check は、Svelte テンプレートの構文上「直下」判定が曖昧で、誤検知/見逃しのどちらも起こり得ます。  
  **修正案**: soft check は今回スコープから外し、レビュー観点へ格下げしてください。強制は「AppLayout 使用ページに PageContent 必須」までに限定。
- [Warning] allowlist が `Capture/Show` のみ記載ですが、将来追加時の手続きが未定義です。  
  **修正案**: テストファイル先頭に「allowlist 追加時は理由コメント必須」の規約を記述し、無理由追加を防止。
- [Warning] 23ページ移行で「既存実効幅維持」とある一方、`7xl` 付与は厳密には幅上限導入です。  
  **修正案**: 文言を「実効上ほぼ全幅を維持（超広幅のみキャップ）」に統一し、期待挙動を明確化。
- [Suggestion] 代表3ページの描画確認（2xl/3xl/4xl）は妥当。`Manuals/Edit` の二段 max-w 意図をテスト名に残すと保守性が上がります。

**観点別サマリ**
- 正確性/既存整合: S1良好、S2/S3は運用仕様の明文化が不足  
- PHPStan: BE変更なしで問題なし  
- テスト網羅: 方針は十分だが S3 の機械保証設計を要補強  
- DTO/JsonResource・Inertia/API: 本件スコープ外、違反なし  
- セキュリティ: 本件で新規リスクは小  
- DESIGN.md/Atomic Design: 方針は整合、`templates` primitive 化も妥当

必要なら次に、**この設計書を「修正版（Critical/Warning 解消済み）」としてそのまま差し替え可能な文章**で起こします。