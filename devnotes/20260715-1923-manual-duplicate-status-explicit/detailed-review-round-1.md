**全体判定**
- **CHANGES_REQUESTED**

**施策1 `VideoManualService::duplicate()`**
- **REQUEST_CHANGES**
- [Warning] `status` の代入値が `VideoManualStatus::Draft`（enumオブジェクト）で、既存コードが `->value` を使う流儀と混在しています。Eloquent cast で動く可能性は高いですが、コードベース整合と静的解析の一貫性のため、`VideoManualStatus::Draft->value` に揃える方が安全です。  
  修正案: `forceFill(['status' => VideoManualStatus::Draft->value, ...])` に変更。
- [Suggestion] docblock に「新規行なので lock 対象外」を書いている点は妥当です。加えて「source manual は lockForUpdate 済みで read/copy 一貫性を確保」と1文補強すると監査性が上がります。

**施策2 `ScenarioWritePathInventoryTest` 更新**
- **REQUEST_CHANGES**
- [Critical] allowlist に `Services/Manual/VideoManualService.php` を追加すると、同ファイル内の将来の `status` 書き込みが広く許可されるため、deny-by-default の粒度が粗くなります。今回の目的は `duplicate()` の INSERT 初期化のみなので、ファイル単位許可は過剰です。  
  修正案:  
  - 可能なら検出ロジックを「ファイル単位 → メソッド単位（`VideoManualService::duplicate` のみ）」に狭める。  
  - すぐ難しければ暫定でコメント規約（例: `@inventory-approved duplicate-init-only`）を伴う最小許可条件を検出2に追加し、無制限許可を避ける。
- [Warning] `SCENARIO_VERSION_ALLOWED` 側コメントに read/write 混在理由を書いていますが、運用上は「read-only 許可」と「write 許可」を分離した方が誤解を防げます。  
  修正案: read用 allowlist と write用 allowlist を別定数・別docblock行で明示。

**施策3 `ManualDuplicateTest` 追加**
- **APPROVE**
- [Suggestion] テスト名・意図は明確で、元manual不変まで押さえており良いです。さらに堅くするなら、`created_by` が複製実行者由来であることも同時に検証すると、duplicate の契約がより明文化されます。

**観点別レビュー要約**
- 正確性: 振る舞い自体は妥当。ただし allowlist 粒度に懸念。
- 既存整合: enum代入の書式統一（`->value`）を推奨。
- PHPStan L10: 大きな懸念なし。型一貫性のため `->value` が無難。
- テスト網羅: Feature回帰は十分。Architecture は設計をもう一段厳密化推奨。
- DTO/JsonResource: 本件は該当なし（問題なし）。
- Inertia vs API: 既存POST + session flash想定で整合。
- 副作用/後退: file-level allowlist拡大が主リスク。
- 波及変更網羅: ほぼ網羅。inventory運用方針だけ追加検討必要。
- セキュリティ/ロック規約: 新規行初期化の説明は成立。ただし監査境界は狭く保つべき。
- DESIGN.md / Atomic: 今回フロント変更なしで該当薄。

必要なら、次に「検出2をメソッド粒度に狭める最小設計（差分案）」まで具体化します。