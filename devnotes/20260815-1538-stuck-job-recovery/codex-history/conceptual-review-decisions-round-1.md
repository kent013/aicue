# 対応マトリクス: conceptual-review Round 1

## [Critical] Schedule 側の `--apply` 付け忘れで回収が無音で全面停止する
- 判断: 対応する
- 根拠: 既定 dry-run は運用上の価値があるが、付け忘れが「回収が 1 件も走らない」に直結し、
  しかも成功終了するので無音である。滞留回収の存在理由そのものを壊す
- 対応内容: 契約の確定事項 5 を追加。Schedule に載る `work:recover-stuck` は全 stream で
  `--apply` + `onOneServer()` + `withoutOverlapping()` + `onFailure()` を持つことを
  Architecture テストで固定する

## [Critical] 失敗通知の具体策が実装方針に無い
- 判断: 対応する
- 根拠: 「全 stream で運用アラートに載る」と書きながら正本が未定だと実装で分岐する
- 対応内容: 正本を `Schedule::onFailure()` → `report()` と明記 (aicue の運用アラート経路は
  `report()` のみで、webhook 回収・オートリチャージ突き合わせが既にこの形)。
  上の gate で配線ごと固定する

## [Critical] `release()` の `LogicException` で掃引全体が失敗扱いになる恐れ
- 判断: 対応する
- 根拠: 競合は正常な事象であり、失敗として扱うと運用アラートが常時鳴る (狼少年になる)
- 対応内容: 契約の確定事項 2 を追加。競合・述語不成立は `Skipped` を返す契約とし、
  `LogicException` は stream 側で `Skipped` へ変換する。例外を通すのは本当の不変条件違反だけ。
  あわせて確定事項 3 で「1 件の例外は report して継続、実行終了時に終了コードを失敗にする」を
  決めた (全件失敗を成功で隠さない既存規約と整合)

## [Warning] 撤去 gate が devnotes / 過去記録まで落とす
- 判断: 対応する
- 根拠: 歴史記録を書き換えさせる gate は有害
- 対応内容: 確定事項 6 で走査範囲を `app/` `routes/` `config/` `tests/` + docs 運用正本に限定し、
  `devnotes/` と `docs/TODO-closed.md` を対象外と明記

## [Warning] フロント影響の明記がない
- 判断: 対応する
- 対応内容: 実装方針に「フロントへの影響は無い (Svelte / Inertia props / TS 型 / API 表面は不変)」を追記

## [Warning] `candidateIds()` が全 ID をメモリへ載せうる
- 判断: 対応する
- 根拠: 現行 4 経路は全件 `pluck` しており、滞留が積み上がったときの実行時間・メモリが青天井
- 対応内容: 確定事項 1。`candidateIds(CarbonImmutable $sweptAt, int $limit)` が
  主キー昇順で limit 件までを返す契約にする

## [Warning] dry-run が何を数えるか曖昧
- 判断: 対応する (ただし提案の `would_recover` は採らない)
- 根拠: webhook の回収は「受理」そのものが書き込みなので、副作用なしに回収可否は出せない。
  出せないものを出すふりをしない
- 対応内容: 確定事項 4。dry-run は候補件数だけを数え、出力に「実際に回収される件数の上界」と明記

## [Warning] S3 削除の副作用設計
- 判断: 対応する
- 対応内容: 候補の正本は DB のみ / CAS に勝った実行だけが削除へ進む / 削除失敗は解放を
  巻き戻さず `report()` に載せる / 未削除オブジェクトは再実行で拾えないことを
  「保証しないもの」として明記する、を概念設計へ追記

## [Warning] 1 PR の規模が大きい
- 判断: 対応する (分割はしない)
- 根拠: 思考原則 3 (後方互換の並走を残さない) により、寄せ替えと旧実装撤去は同じ PR に入る
- 対応内容: 実装順序を fail-first で固定 (共通契約 → sweeper → 低リスク 3 本 → webhook →
  撮影アップロードの分割 → 旧入口撤去 → Schedule 配線と 2 gate)

## [Warning] 使命への貢献の書き方
- 判断: 対応する
- 対応内容: 期待効果を「パイプラインの滞留を人手なしで前へ進める性質」と定義し直し、
  課金系 2 本を含める理由を「押さえたままの利用枠の解放」「支払い済み・未付与の回収」と明記

## [Warning] outcome を自由文字列にすると型が緩む
- 判断: 対応する
- 対応内容: `RecoveryOutcome` enum + 結果 DTO 経由に固定する旨は概念設計に既出。詳細設計で
  DTO の形 (stream ごとの outcome→件数) を確定させる

## [Suggestion] `capture:purge-upload-reservations` は主目的から外れる
- 判断: 一部対応
- 対応内容: 「3 責務の解体に必要な最小限であって機能追加ではない」と概念設計に明記

## [Suggestion] id の型を厳しくする
- 判断: 対応する
- 根拠: 対象 5 テーブルの主キーはすべて bigint auto-increment であることを実読で確認した
- 対応内容: 契約の id 型を `positive-int` に閉じる (`int|string` の union にしない)
