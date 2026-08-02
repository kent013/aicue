**全体判定: CHANGES_REQUESTED**

**1. 使命との整合性**
- [Warning] P2/P4/P5 の説明で「bug-hunt の検出能力そのものを回復・維持」と一括表現しているが、P2 の `seed を空にする` 直後に回復するのは「registry 検証と annotate 経路の再稼働」であって、偽陽性抑制の知見そのものは 0 件のままです。期待効果がやや強すぎます。  
  修正提案: 期待効果を「機構の再稼働」と「運用知見の再蓄積」に分離し、P5 に `adjudication を再登録する条件と手順` を明記してください。
- [Suggestion] P1 と P3 は North Star に対する寄与が明確です。概念設計の冒頭で「現場利用時の詰まりにくさ」と「共用端末での安全性」を主便益として先に出すと、P4/P5 の優先順位も通しやすくなります。

**2. 禁止事項違反**
- [Warning] 明示的な禁止事項違反は見当たりませんが、P1〜P5 を一括で実装すると「今必要なものだけ作る」と「テストファースト」の運用がぼやけます。特に実バグ修正・セキュリティ・gate 移植・文書整備を同一変更集合に載せるのは、fail-first の確認責務を曖昧にします。  
  修正提案: 少なくとも `P1+P3`, `P2`, `P4+P5` を分割し、各トラックで先に落ちるテストを固定してから実装する前提を設計に追記してください。
- [Suggestion] `response()->json()` 直書きや Prism 直呼びを増やす設計ではなく、その点は問題ありません。

**3. 実現可能性**
- [Critical] P1 の第一候補を `Route::pattern` の global 既定に置く設計は、現時点の記述だと成立条件が不足しています。本文自身が触れている `organization` の custom binder のように、同名 param が数値 PK 以外の解決規約を持つ場合に破壊的です。Laravel 12 で実装は可能ですが、「何に global 制約を掛けてよいか」の inventory が概念設計に未固定です。  
  修正提案: 概念設計の段階で「数値 PK param 名の allowlist を先に確定し、その集合にだけ global pattern または共通 helper を適用する。custom binder / slug / UUID は明示除外する」と成立条件を追記してください。
- [Warning] P3 の middleware は技術的には可能ですが、「認証済みなら全 response」に近い説明のままだと `StreamedResponse`、`BinaryFileResponse`、署名 URL リダイレクト、将来の特殊 response を巻き込みます。  
  修正提案: 適用対象を「既存 `Cache-Control` 未設定の通常 HTML/Inertia 応答」に寄せるか、少なくとも除外条件を `response class` と `既存 header 有無` の両面で明文化してください。
- [Suggestion] P2 の `stdin 2-pass` 修正、`COND_KEYS` 追加、registry schema 修復は実現可能性に問題ありません。

**4. 期待効果の妥当性**
- [Warning] P1 の「約 120 の route bind param に対する生 500 経路の解消」は、param 数と実際の到達可能な障害経路を同一視しており、効果指標として粗いです。  
  修正提案: 成果指標を `数値 PK binding route の未制約数 = 0` と `非数値セグメントが 404 を返す Feature test の成立` に置き換えてください。
- [Warning] P4 の「Architecture テスト 33 本 → 40 本前後」は本数指標に寄りすぎています。本数が増えても load-bearing invariant を外すと価値がありません。  
  修正提案: 各 gate について「どの事故/不変条件を固定するか」を成果指標にし、テスト本数は副次指標に下げてください。
- [Suggestion] P3 は `logout -> browser back -> 認証済み画面が再表示されない` シナリオを代表テストとして据えると、効果が伝わりやすいです。

**5. リスク**
- [Critical] P2 の `seed を空にする` 方針は現状復旧としては合理的ですが、そのままだと「壊れた registry を除去した」だけで、人間側が再構築すべき知見の受け皿が弱いです。機械台帳を空にしたままにすると、将来また場当たりで schema drift を起こしやすくなります。  
  修正提案: 空 seed 化を採るなら、同じ変更集合で `species_key 4-segment 規約`、`governed COND_KEYS`、`新規 adjudication 登録手順` を `README/spec-ledger` に固定し、削除理由も明記してください。
- [Warning] P4 の gate 移植は aigenba 起源の前提を持ち込みやすく、AI-CUE 側の規約とずれた brittle test になるリスクがあります。  
  修正提案: 各 invariant test の source of truth を AI-CUE の `AGENTS.md` / `docs` に置き、aigenba の文言や path をそのまま比較対象にしない設計にしてください。
- [Warning] P3 は認証済み画面の bfcache を抑止するため、戻る/進むの体感は一部で変わります。セキュリティ上妥当ですが、撮影フローに影響しない確認が必要です。  
  修正提案: capture 系の代表画面で「アプリ内遷移 UX は維持される」ことを確認するテスト観点を追加してください。

**6. スコープの適切さ**
- [Warning] P1〜P5 は方向性自体は妥当ですが、1 本の「整列」テーマに対して修正対象が広すぎます。特に runtime bug 修正と運用文書整備を同列に扱うと、レビューの焦点が散ります。  
  修正提案: 優先順位どおりに `P1+P3`、`P2`、`P4+P5` の段階リリース前提へ落とし込み、各段で「何が直るか」を単純化してください。
- [Suggestion] `aigenba へ返す差分` を handoff 文書に留める判断は適切です。本設計の主目的からは切り離したままでよいです。

**7. 型安全性**
- [Warning] P1 の数値 param 集合を ad-hoc な文字列配列で持つと、param rename 時に静的保証が弱く、PHPStan 10 では守り切れません。  
  修正提案: `param 名の source-of-truth を定数化` するか、Architecture test が route 定義と inventory を突合する構成にして、文字列散在を避けてください。
- [Suggestion] P3 の middleware は `Response` 系型を明示し、header 付与判定を小さな純粋メソッドに分けると PHPStan を通しやすいです。
- [Suggestion] DTO/JsonResource パターンを壊す設計ではありません。新規 API 応答を足さない限り、この観点のリスクは低いです。

修正すべき本質は 2 点です。`P1 の適用対象確定条件がまだ曖昧` なこと、`P2 の空 seed 化に対する運用ガードがまだ弱い` ことです。ここを概念設計の時点で締めれば、全体の方向性は妥当です。