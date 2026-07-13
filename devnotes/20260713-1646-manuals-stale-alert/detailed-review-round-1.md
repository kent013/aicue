**全体判定: CHANGES_REQUESTED**

以下、提示された「詳細設計」に対するレビューです（実コード改変は未実施）。

### 施策1 `AnalysisPanel.svelte`（start error 種別 + stale overlay 破棄）
**判定: REQUEST_CHANGES**

- [Critical] `hasDocumentValidationError()` が「`errors.document` が配列かつ length>0」だけで `missing_document` 判定しており、将来 `document` フィールド由来の別 422（例: 形式/容量）も誤って stale 破棄対象になります。  
  修正案: `errors.document` の存在に加えて、サーバの機械可読コード（例 `code: "missing_document"`）で判定する設計に寄せる。backend変更を避けるなら暫定で `status===422 && hasDocument===false` を併用し、誤分類を最小化。

- [Warning] `let hadDocument = hasDocument;` を plain 変数で持つ方式は runes 的には成立しますが、props 由来の遷移検出をローカル可変で追うため、将来リファクタ時に見落としやすいです。  
  修正案: `const becameDocumentAvailable = $derived(!prev && hasDocument)` 相当の意図をコメントより明確化し、テストで「初回 hasDocument=true」「false→true」「true→true」を必ず固定化。

- [Warning] `startErrorKind` の reset が `startAnalyze()` 開始時のみで、外部要因 rerender 後の「古い generic/conflict」が残る意図がコード上で読み取りづらいです。  
  修正案: 「missing_document だけ自動破棄し、それ以外は保持」の仕様を関数化（例 `shouldClearStartErrorOnDocumentAvailable(kind)`）して可読性を上げる。

- [Suggestion] `handleStartResponse()` の 201 分岐で `startErrorKind = null` を明示すると、成功時状態がさらに自己記述的になります。

### 施策2 `AnalysisPanel.test.ts`（回帰テスト）
**判定: REQUEST_CHANGES**

- [Critical] ケース2の説明と前提が不自然です（402 は通常 hasDocument=true 文脈）。`hasDocument:false` で 402 を作ると、実運用ドメインから乖離したテストになり、将来「なぜこの前提？」問題が出ます。  
  修正案: 402 ケースは初期 props を `hasDocument:true` で開始し、`rerender` は `manualStatus` 等のみ変更して「missing_document 以外は消えない」を検証。

- [Warning] ケース1は「alert が消える」だけ検証で、`showPurchaseLink` や他表示への非干渉が未固定です。  
  修正案: 同テストで `analysis-start-error` の消失に加え、購入リンクが表示されていないことを確認（誤副作用防止）。

- [Warning] 非退行ケースで `failedJob` を見る方針は妥当ですが、`analysis-error` が server-truth であることを assertion 名/コメントに明記したほうが保守性が上がります。  
  修正案: テスト名に「start-errorのみ破棄、failedJobは維持」を明示。

### 観点別サマリ

- 正確性: stale 解消の主筋は妥当。ただし `missing_document` 判定条件が弱い。  
- 既存整合: 既存 fetch/error ハンドリング方針と整合。  
- PHPStan: frontend-only で影響なし。  
- テスト網羅: 方向性は良いが、1件前提修正が必要。  
- DTO/JsonResource: backend無変更方針は妥当。  
- Inertia Props vs API: 使い分けは妥当（props遷移でUI同期）。  
- 副作用/後退: poll/failedJob非干渉の設計意図は良い。  
- 波及変更: 型/props/backendを不用意に広げていない点は良い。  
- セキュリティ: 本変更で認可面の新規リスクは低い。  
- DESIGN.md / Atomic / runes: 禁止事項・階層方針には概ね適合。runesは実装可能だが遷移判定の堅牢化推奨。

必要なら次に、上記指摘を反映した**修正版ミニ設計（差分だけ）**をこちらで作成します。