# 対応マトリクス: conceptual-review Round 1

## [Critical] `indeterminate` を表現する DB スキーマ (response 系カラムの nullability)
- 判断: **対応する** (指摘は妥当。ただし対象列は 1 列だけ)
- 根拠: 実スキーマ (`2026_06_11_100100_create_idempotency_keys_table.php`) を全列確認した。
  `response_body` は既に nullable、`response_headers` に相当する列は**存在しない**
  (再生は保存 body + status のみで組み立て、`Content-Type` を固定付与している)。
  NOT NULL なのは `response_status` の 1 列のみ。
- 対応内容: 概念設計の結論 3 に「claim 行が満たすべき列の状態」表を追加し、全列の現行 nullability と
  claim 時の値、変更要否を明記した。Model 側は `?int $response_status` に更新することも明記。

## [Critical] finalize 失敗時の扱いが未定義 (processing が TTL まで残る)
- 判断: **対応する**
- 根拠: 停滞 claim は「409 が出続ける」形で利用者に見える一方、現行の設計案では無音だった。
  AI-CUE の運用アラート経路は `report()` のみなので、新しい監視機構を作らずに観測点を置くべき。
- 対応内容: 結論 8 を新設。(1) finalize は条件付き UPDATE の affected rows を見て 0 件なら `report()`、
  (2) finalize が例外を投げても**元の応答を返す** (500 に化けさせない。副作用は既に確定しているため
  500 は誤認と悪い再送を誘発する)、(3) `idempotency:prune` が削除行を **state 別に集計**し
  `processing` のまま期限切れになった件数が 1 件でもあれば `report()`、
  (4) fatal error による停滞窓は**閉じないと明記**する、の 4 点を追加。

## [Critical] 型安全性 — 状態分岐で nullable が混線する / DTO 境界を切るべき
- 判断: **一部対応し、一部反論する**
- 根拠: 判定結果の DTO 化は妥当 (match 1 段に落ちて level 10 が安定する)。
  一方「replay 専用 DTO (`StoredIdempotentResponse`)」は、保存応答が
  `response_status` + `response_body` の 2 値でしかなく、`completed` を返す唯一の分岐で
  `Assert::notNull()` を通せば level 10 を満たせる。写像を 1 段増やしても防げる誤りが増えず、
  禁止事項 6「やたらに複雑な案を提案する」/ AGENTS.md 思考原則 2 に抵触する。
- 対応内容: 結論 9 を新設。`IdempotencyClaimStatus` (enum) + `IdempotencyClaimOutcome` (readonly DTO)
  は導入する。replay 専用 DTO は作らない理由を明記した。

## [Warning] 並行テストは「後着 409」だけでなく「副作用が 1 回だけ」を検証すべき
- 判断: **対応する**
- 根拠: 二重実行の不在こそが本設計の目的であり、409 の観測だけでは不十分。
- 対応内容: 詳細設計のテスト計画で、409 系の全ケースについて `items()->count()` の不変を必ず併記する。
  加えて「claim が controller 実行より前に可視である」ことを、テスト内で登録した probe route の
  controller から `IdempotencyKey` の state を読んで応答に載せる形で直接固定する。

## [Warning] `response()->json()` 直書き禁止を Architecture 検査対象に入れるべき
- 判断: **見送る** (反論)
- 根拠: `tests/Architecture/` を全走査したが `response()->json()` を禁じる gate は**存在しない**。
  これはアプリ全域に及ぶ別課題であり、本 TODO (並行 claim + 配線漏れ検査) の範囲で
  片手間に導入すると、母集団設計・既存例外の棚卸しを伴う別作業を巻き込む (思考原則 2)。
- 対応内容: スコープ外に明記した上で、代償として Feature テストが 3 つのエラーコードそれぞれについて
  エラー envelope の形 (`error.code` / `error.status`) を固定することを設計に含める。

## [Warning] `api_key_id` / `user_id` の排他を不変条件として固定すべき
- 判断: **一部対応する** (DB CHECK は入れない)
- 根拠: 排他は現行実装でも構築点 1 箇所 (`storeResponse` の `forceFill`) が担保しており、
  DB 制約は元から無い。CHECK 制約の追加は別系統の不変条件で、観測された欠陥も無い (思考原則 2)。
- 対応内容: 結論 3 末尾に「単一の構築点 + テストで担保。DB は強制しない」を明記。
  Feature テストで「middleware 経由の行は常に片方だけ非 NULL」を固定する。

## [Warning] MCP を「据え置き」と言い切るのは不正確 (retention SoT / prune は触る)
- 判断: **対応する**
- 根拠: 指摘のとおり。据え置く範囲と含める範囲を混ぜると実装者が誤読する。
- 対応内容: 結論 5 の見出しを「状態機械化は据え置き / retention SoT・prune には含める」に変え、
  5 項目の対照表を追加した。

## [Warning] parity gate は retention だけでなく replay header 名も検査対象にすべき
- 判断: **対応する**
- 根拠: AG-122 のヘッダ名は家系統一の裁定であり、drift は retention と同じ重さ。
- 対応内容: gate 名を `IdempotencyContractParityTest` に改め、検査対象を
  「保持期間 (config) ↔ `docs/api-idempotency.md` ↔ 再生ヘッダ名定数 ↔ 決着写像表」に拡張した。

## [Warning] 3 つの error code を Feature テストで個別検証すべき
- 判断: **対応する**
- 対応内容: 上記のとおり詳細設計のテスト計画に含める。

## [Suggestion] 将来の業務 write 面 (SOP 取り込み / 撮影テイク登録 / レンダ要求) との接続を明記
- 判断: **対応する** (文書側で)
- 根拠: gate の価値は「これから増える面が漏れないこと」にあるので、規約として書いておく方が効く。
- 対応内容: `AGENTS.md` ドメイン規約と `docs/api-integration` 系文書への追記施策で、
  「`api/v1/*` に変更系 route を足すときは `idempotent` をちょうど 1 本持つか目録免除」を規約化する。

## [Suggestion] North Star との接続 / `Idempotent-Replayed` の方針は妥当
- 判断: **対応不要** (現状維持)
