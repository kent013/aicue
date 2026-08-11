# 対応マトリクス: conceptual-review Round 1

Round 1 の全体判定は **APPROVED**。Critical なし。Warning 3 件・Suggestion 4 件に対する判断を記録する。

## [Warning] `$index` への依存を詳細設計で型・nullable 込みで明示せよ
- 判断: **対応する**
- 根拠: 妥当。`UniqueConstraintViolationException::$index` は vendor 実読で
  `public ?string $index = null;` と確認済み(`vendor/laravel/framework/src/Illuminate/Database/UniqueConstraintViolationException.php`)。
  PHPStan level 10 で `string|null !== string` の比較は問題ないが、
  「null も再送出」の意図が読み取れる書き方であることを設計に固定する必要がある。
- 対応内容: 詳細設計の PHPStan 適合チェックへ
  「`$e->index` は `string|null`。期待名との `!==` 比較で null は自動的に再送出側へ落ちる」を明記。
  併せて vendor の該当行を引用する。

## [Warning] 期待効果の表現が過大 (孤児 Stripe session 自体は防げない)
- 判断: **対応する**
- 根拠: 正しい。Stripe session 作成は DB insert より**前**の外部 I/O であり、
  insert が落ちれば孤児 session は残る。本設計が変えるのは
  「その状態が正常終了として扱われること」だけである。
- 対応内容: 概念設計「期待効果」を書き換え済み。
  「状態が発生しなくなる」→「正常終了として扱われなくなり既存の例外観測経路に乗る」。
  併せて「状態そのものは防げない」を明記した。

## [Warning] 件 2 の `forceFill` が cast 定義と一致することを詳細設計で確認せよ
- 判断: **対応する**
- 根拠: 妥当。`TakeUploadReservation::casts()` は
  `'status' => TakeUploadReservationStatus::class` を宣言済み(実コード確認済み)。
  したがって enum インスタンスを渡してよく、DB へは backing value が書かれる。
- 対応内容: 詳細設計に cast 宣言を引用し、
  「in-memory instance の status が **enum として**読めること」をテストの assertion に固定する。
  同時に「DB 実値が `'pending'` であること」も 1 assertion で残す
  (enum→backing value の往復が実際に効いていることの確認)。

## [Suggestion] 使命への貢献は間接的である
- 判断: **見送る**(記述として既に正しい)
- 根拠: 概念設計は既に「使命を支える課金基盤が壊れたときに壊れたと言うようになる」と
  間接的貢献として書いている。誇張していない。

## [Suggestion] 隠れていた障害が 500 相当で表面化する点を切っておけ
- 判断: **対応する**
- 根拠: レビュー観点として妥当。UX 改善と誤読されると
  「エラー文言を足す」方向の scope creep を招く。
- 対応内容: 概念設計の期待効果へ
  「観測可能性の改善であって UX 改善ではない。ユーザー向け文言は追加も変更もしない」を追記済み。

## [Suggestion] `SubscriptionService` を含める判断は妥当
- 判断: **維持**(変更なし)
- 根拠: 追認された。既存 docblock が宣言済みの契約に実装を合わせるだけで新抽象なし。

## [Suggestion] 2 件を共通化しない方針は適切
- 判断: **維持**(変更なし)
- 根拠: 追認された。

---

**Round 1 で APPROVED のため合議ループは終了**。Phase 2 (詳細設計) へ進む。
