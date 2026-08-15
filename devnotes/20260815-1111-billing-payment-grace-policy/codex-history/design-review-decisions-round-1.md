# 対応マトリクス: design-review Round 1

## [Critical] 施策 11: 正規表現が施策 1 の cast (`'past_due_since' => 'datetime'`) を誤検出する
- 判断: 対応する (指摘どおり、そのままでは 1 PR 内で必ず赤くなる)
- 根拠: `Subscription::casts()` に足す型宣言は「書き込み」ではないが、汎用の array key 検出は
  区別できない。model をまるごと allowlist に入れると model 内の将来の直書きを見逃す。
- 対応内容: 検査を 2 段にした。ファイル走査で候補を拾い、allowlist 外のファイルは
  **行ごとに判定**して「docblock 行」と「`'past_due_since' => 'datetime',` に完全一致する
  cast 行」だけを許す。これにより model の cast は通り、model 内の
  `forceFill(['past_due_since' => …])` は落ちる。負のコントロールを 2 本
  (単一 writer が検出されること / cast 以外の array key 代入が違反として拾われること) に増やした。

## [Critical] 施策 10: `$e->getPrevious()::class` が previous 無しで fatal になる
- 判断: 対応する
- 根拠: 指摘のとおり。gateway 自身が投げるケース (id 欠落) では previous が無い。
- 対応内容: `$previous = $e->getPrevious();` を取り、
  `$previous !== null ? $previous::class : $e::class` に直した (例外 message は引き続き載せない)。

## [Warning] 施策 10: `needsSnapshotConvergence()` が status / 起点 / PM しか見ていない
- 判断: 対応する
- 根拠: status が同じまま `current_period_end` だけが動いた webhook を落とすと、更新予告
  (`billing:send-billing-reminders`) の真実源がずれたまま永久に収束しない。
- 対応内容: 比較対象を `applySubscriptionSnapshot` が書く列すべて
  (`stripe_status` / `stripe_price` / `quantity` / `trial_ends_at` / `ends_at` /
  `current_period_end`) + 猶予起点 + PM に拡張した。`current_period_end` は
  **snapshot 側が null のときは比較しない** (「period 欠落 payload では既存値を維持する」
  という書込規則と同じ扱い)。日時比較は null 安全な `timesDiffer()` に切り出し、秒精度で見る。
  `organizations.plan_code` は同一トランザクションで同期されるため比較対象にしない旨も明記した
  (未知 Price のときだけ据え置かれることは docs の「保証しないもの」へ)。
  テスト計画に「status 以外の差分も収束する」「period 欠落は既存値を維持」を追加した。

## [Warning] 施策 9: snapshot の 7 フィールド抽出が「webhook と同じ規則」としか書かれていない
- 判断: 対応する (規則を書くだけでなく、**写像そのものを 1 か所に統合**する)
- 根拠: 規則を 2 か所に書き写す形では、指摘どおり突き合わせ経路だけ別挙動になる余地が残る。
- 対応内容: `app/Services/Billing/SubscriptionSnapshotMapper.php` を新設し、
  **Stripe の subscription オブジェクト (連想配列) → `SubscriptionSnapshot`** の写像を 1 本化した。
  webhook は `data.object` の配列を、gateway は SDK オブジェクトの `toArray()` を渡す
  (SDK 型は gateway の中に閉じたまま)。各フィールドの exact mapping を表で明記し、
  PM 観測も mapper の三値メソッドに寄せた (webhook 側は `$observed === true` を渡す = 現行と同値)。
  テストは mapper 単体 (7 フィールド + 三値 + 新旧 API 両系 + 優先順位) と、
  **同一配列から webhook 経路と gateway 経路で同一 snapshot になること**、および既存 webhook
  テストが緑のままであること (挙動を変えていない回帰) を必須にした。

## [Warning] 施策 6: `EntitlementDeniedReason` の露出有無が文書内で矛盾
- 判断: 対応する (実読で確認し、非露出を機械固定する)
- 根拠: 実読の結果、`EntitlementDeniedReason` / `SubscriptionEntitlementDto` は `app/Http/` にも
  `resources/js/` にも 1 件も無い (露出していない)。矛盾しているのは現行 enum の docblock の
  「フロントは reason 別に状態説明を出し分ける」という記述のほうだった。
- 対応内容: enum の docblock を実装に合わせて直す (現時点では props に出さない / 出すときは
  TypeScript の union と表示テストを同時に足す) ことを波及変更に明記し、
  新規 `tests/Architecture/EntitlementReasonExposureTest.php` で非露出を固定する
  (負のコントロール付き)。

## [Suggestion] 施策 1: migration の import 漏れ / 施策 5: docs への順序保証の明記
- 判断: どちらも対応する
- 対応内容: 施策 1 のリスクに import 漏れの注意を追記。施策 5 の docs 反映 (最終収束であり
  即時の順序保証ではない) は施策 12 の記載項目に既に含まれている。
