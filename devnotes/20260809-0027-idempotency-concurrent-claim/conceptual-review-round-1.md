全体判定: **CHANGES_REQUESTED**

設計の方向性は North Star に整合しています。撮影・SOP 取り込み・外部連携の書き込み再送で二重実行を防ぐことは、現場に重複カットや重複データとして見える事故を減らすため、本質的な信頼性改善です。ただし、概念設計としては DB スキーマと状態遷移の詰めがまだ不足しています。

## 1. 使命との整合性

[Suggestion]  
North Star との接続は妥当です。特に「同じカットが 2 本登録される」壊れ方を防ぐ説明は、AI-CUE の撮影 PWA / 外部連携の信頼性に直結しています。

[Suggestion]  
さらに強めるなら、将来の対象 route を「SOP 取り込み」「シナリオ生成要求」「撮影テイク登録」「レンダ要求」などの業務 write 面にどう広げるかを、`api/v1/*` の gate 方針と結びつけて明記するとよいです。

## 2. 禁止事項違反

[Warning]  
禁止事項 1「テストなしの実装完了報告」に対して、Architecture / Feature / premise test の方針は十分あります。ただし、並行 claim の保証は通常の Feature テストだけでは弱くなりがちです。

修正提案: `IdempotencyConcurrentClaimTest` では、controller 実行回数を DB カウンタや専用テスト controller で観測し、「後着 409」だけでなく「副作用が 1 回だけ」を必ず検証してください。

[Warning]  
禁止事項 4 の `response()->json()` 直書き禁止について、方針では `ApiErrorResource` を使うと書かれており妥当です。ただし middleware 内の 409 応答は実装時に安易に直書きされやすい箇所です。

修正提案: Architecture test か既存 guard に、`IdempotentRequest` も `response()->json()` 禁止の検査対象として入ることを明記してください。

## 3. 実現可能性

[Critical]  
`indeterminate` 行を保存するには、`response_status` だけでなく、既存の `response_body` / `response_headers` など保存応答系カラムの nullability も確認が必要です。設計では `response_status` nullable 化しか明記されていません。現行スキーマが response 系を NOT NULL にしている場合、`indeterminate` を表現できません。

修正提案: migration 方針に「`indeterminate` で保存しない response 系カラムをすべて nullable にする、または `completed` 専用の保存値として明示的な空値を許容する」を追加してください。あわせて Model の PHPDoc / casts も `?int` だけでなく該当フィールドを nullable として定義してください。

[Warning]  
`insertOrIgnore` 後に既存行を SELECT し、期限切れなら DELETE → retry する流れは実現可能ですが、prune や他リクエストとの競合時の retry 回数・fail-closed 条件がやや曖昧です。

修正提案: claim 処理を小さな service に寄せ、結果型を `claimed / replay / conflict / in_progress / indeterminate` のような enum DTO で返す設計にすると、PHPStan level 10 とテストの両方が安定します。

## 4. 期待効果の妥当性

[Warning]  
「controller が 2 回走る穴が閉じる」は妥当ですが、保証範囲は「同一 actor scope + 同一 route_name + 同一 key」に限られます。API key actor と user actor の scope が混在する場合、unique 制約の NULL distinct 前提に依存します。

修正提案: 設計に「claim 時点で api_key_id / user_id のどちらか一方だけが必ず非 nullであること」を不変条件として追加し、テストで固定してください。

[Suggestion]  
`Idempotent-Replayed: true` は AG-122 に沿っており妥当です。再生時だけ付与する方針も明確です。

## 5. リスク

[Critical]  
`$next()` から例外が抜けた場合に `finally` で `indeterminate` に倒す方針は正しい一方、DB update 自体が失敗した場合の扱いが未定義です。ここが失敗すると `processing` が TTL まで残り、以後は `in_progress` になり続けます。

修正提案: finalize 失敗時の観測・ログ・メトリクス方針を設計に追加してください。少なくとも `report()` だけで握り潰さず、再送出する例外と別に「claim 行が processing のまま残った」ことを検出可能にする必要があります。

[Warning]  
4xx 後の同一キー再送が 409 になる破壊的変更はオーナー許容済みですが、既存 Feature テストの期待変更だけでなく、内部 docs に明確な API 契約として残す必要があります。

修正提案: `docs/api-idempotency.md` に「2xx JSON のみ replay、その他は indeterminate 409」を表形式で固定し、parity gate では retention だけでなく replay header 名も検査対象にしてください。

## 6. スコープの適切さ

[Warning]  
MCP write tool を据え置く判断は妥当ですが、`McpIdempotencyService::TTL_HOURS` を config 化し、prune 対象に `mcp_idempotency_keys` を含めるなら、MCP 側にも一部変更が入ります。「MCP は据え置き」と言い切るには少し不正確です。

修正提案: 「MCP の状態機械化は据え置く。ただし retention SoT 化と prune の対象には含める」とスコープを分けて表現してください。

[Suggestion]  
`DELETE /api/v1/me/session` を免除にする判断は合理的です。前提テストで「再送は idempotent middleware より前に 401」を固定する方針も良いです。

## 7. 型安全性

[Critical]  
middleware が複数状態を直接分岐して JsonResponse / ResourceResponse / Response を扱う設計は、実装が膨らむと PHPStan level 10 で型が崩れやすいです。特に `completed` replay 用の保存 body/header/status と、`indeterminate` の nullable 値が混在します。

修正提案: `IdempotencyClaimResult` のような DTO と、`StoredIdempotentResponse` のような replay 専用 DTO を分けてください。`completed` のときだけ `StoredIdempotentResponse` を持つ形にすると、nullable 乱用を避けられます。

[Warning]  
`ApiErrorCode::fromHttpStatus(409)` を据え置く判断は妥当ですが、middleware が必ず明示コードを使うことをテストで固定しないと、将来の実装者が汎用 409 に戻す余地があります。

修正提案: Feature テストで `idempotency_in_progress` / `idempotency_indeterminate` / `idempotency_conflict` の error code をそれぞれ検証してください。

結論として、設計方針は採用可能ですが、`indeterminate` を表現する DB スキーマ、finalize 失敗時の扱い、型付き DTO 境界を明確化してから詳細設計へ進めるべきです。