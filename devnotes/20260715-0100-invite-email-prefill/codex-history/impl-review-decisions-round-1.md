# 対応マトリクス: impl-review Round 1

Codex 全体判定: **CHANGES_REQUESTED**（Critical 1 / Warning 2 / Suggestion 数点）

## [Critical] FortifyServiceProvider の no-store 条件が `!== null` のみで S2↔S3 契約が暗黙・脆い
- 判断: **対応する**
- 根拠: resolver 戻り型が `?string` のため、将来 `''` を返す変更が入ると PII 非含有応答にも `no-store` が付く。層間契約が `!== null` の暗黙依存になっていた。frontend の `isInvited`（`!= null && !== ""`）とも判定基準が非対称で、非空 email = 招待という意味論に揃えるのが正しい。
- 対応内容:
  1. `FortifyServiceProvider` の no-store 条件を `$invitationEmail !== null && $invitationEmail !== ''` に変更（frontend isInvited と対称化）。
  2. `resolveRegisterPrefillEmail` の PHPDoc に「**戻り契約: 非 null なら必ず非空 email 文字列**」を明記し、呼び出し側がこの契約に依存する旨を記述。
  3. 契約テストを追加（下記 Warning 対応と統合）。

## [Warning] 空文字返却時に no-store が付く分岐が理論上残る（S2）
- 判断: **対応する**（Critical と同一根因）
- 根拠: 上記 Critical の裏返し。resolver は既に `$email === '' → null` で潰しているが、契約をテストで固定していなかった。
- 対応内容: Feature テストに「空 email の active 招待 → resolver は null を返し token を forget する（S2↔S3 契約: 非 null=非空）」を追加。`ArraySessionHandler` backed の `Session\Store` に token を積み、`resolveRegisterPrefillEmail` を直接呼んで null + forget を assert。

## [Warning] `invitationEmail === ''` を明示的に禁止する仕様テストがあると堅牢（S5）
- 判断: **対応する**
- 根拠: 上記の契約テストがこれを満たす。
- 対応内容: 同上（契約テスト 1 件追加、計 9 Feature テスト）。

## [Suggestion] `toResponse($request)` 明示・header 操作位置は適切
- 判断: **見送る（変更不要）**
- 根拠: Codex も適切と評価。現状維持。

## その他ファイル判定
- Model / MatchesInvitationEmail / Register.svelte / JS テスト / Architecture テスト: すべて OK 評価。変更なし。

## セキュリティ判定
- token_hash 照合のみ・平文 email 探索なし・列挙面非拡大・bearer token 残余リスク受容・readonly 非境界、いずれも実装と整合と Codex が確認。方針変更なし。
