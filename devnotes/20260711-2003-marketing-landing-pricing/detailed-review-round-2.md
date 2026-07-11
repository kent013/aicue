## 再判定

**全体判定: CHANGES_REQUESTED**

Round 1 の Critical / Warning への対応は妥当です。特に Seeder の登録維持、2段クエリ、`org_ref` 化、attempt token 見送り理由は承認できます。残件は以下1点です。

### 施策3: 料金表実装

判定: **REQUEST_CHANGES**

- [Warning] `PricingPlanDto::baseAmountJpy` は Free で `null` と定義されていますが、`PricingPlanCard` は「`priceAmount=0` の場合に無料表示」とされており、受け渡し規約が不一致です。`null` をそのまま渡すと型不整合または価格未設定表示になり、フロントで `null → 0` にすると「価格未設定」と「無料」を区別できません。  
  修正案: DTOの意味を確定してください。推奨は `baseAmountJpy: int|null` を維持し、カードも `number|null` を受けて `null` を「無料」ではなく「お問い合わせ／申込不可」とすることです。Freeを無料表示するなら、Freeにも価格 `0` を正本として供給するか、`isFree: bool` または明示的な表示状態をDTOへ持たせ、Vitestで `null` と `0` の挙動を固定してください。

### 軽微な提案

- [Suggestion] `resolveForSource()` は fragment を持つ内部URLにも備え、queryを `#fragment` より前へ挿入するテストを追加すると堅牢です（例: `/contact?foo=1#form`）。

上記の価格null規約を確定すれば、全体 **APPROVED** と判定できます。