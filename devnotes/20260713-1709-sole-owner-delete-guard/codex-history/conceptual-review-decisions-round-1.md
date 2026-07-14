# 対応マトリクス: conceptual-review Round 1

## [Critical] 判定と削除がロック境界で直列化されておらず並行更新で不変条件を破れる (TOCTOU)
- 判断: 対応する
- 根拠: 妥当。既存の `transferOwnership` は行ロックで直列化しており、アカウント削除だけ非直列だと「判定時 Owner 2 人→並行降格→削除で Owner 0 人」が成立する。既存不変条件と対称化する本設計の主旨に照らしても閉じるべき。
- 対応内容: 判定と削除を service 内の同一 `DB::transaction` に入れ、対象組織の `organization_user` 行を `lockForUpdate` で取得してロック内で述語を再評価してから `$user->delete()` する（`transferOwnership` と同方式）。完全な直列化には peer 側 (`changeRole`/`removeMember`) の最終 Owner 再チェックも同じ行ロック下に置く必要があるため、共有 private helper で両経路にも `lockForUpdate` を適用する。概念設計の実装方針にロック要件を明記。

## [Warning] ValidationException を返すだけで UI 表示仕様が弱い (禁止事項8 の趣旨=押下後に理由が見える)
- 判断: 対応する
- 根拠: 禁止事項8 は disabled 禁止に加え「押下後に理由を理解できる」ことが本質。エラーバッグキー未固定だと表示できない。
- 対応内容: サーバーは固定キー `errors.account` でメッセージを返し、`Settings/Index.svelte` の DangerZone がそれを常時描画する仕様を概念設計に明記。

## [Warning] 「組織ロックを0件に」の言い切りが強すぎる
- 判断: 対応する
- 根拠: 本設計が直接防げるのは自己削除フロー起因の新規発生のみ。既存破損・別経路は対象外。
- 対応内容: 効果を「自己削除フロー起因の新規 Owner 不在組織を防止」に狭めて記述。

## [Warning] props の blocker 一覧はスナップショットで実挙動とズレ得る
- 判断: 対応する
- 根拠: 事前警告を真実の源泉に見せると説明と挙動が乖離する。サーバー判定が最終権威。
- 対応内容: UI 文言で「削除時にも再判定される・サーバーが最終判定」を明示する旨を制約に追記。

## [Warning] テスト観点が実装方針に明示されていない
- 判断: 対応する
- 根拠: 本リポジトリはテスト必須 (禁止事項1)。概念段階でも検証観点を固定すべき。
- 対応内容: テスト観点 5 項目を概念設計スコープに明記（拒否/許可/複数Owner許可/非Owner非対象/Inertia表示）。

## [Suggestion] 述語と例外送出を service 側に閉じる / Collection を view に漏らさず配列 shape へ変換
- 判断: 対応する
- 根拠: 保守性・PHPStan L10 通過性が上がる。
- 対応内容: 権威判定+例外+削除を service メソッドに集約。UI 用 props は controller/service で `list<array{name:string,slug:string}>` へ変換。

## [Suggestion] 使命整合・スコープ切り方・最小表示は妥当
- 判断: 見送る（肯定コメントのため対応不要）
