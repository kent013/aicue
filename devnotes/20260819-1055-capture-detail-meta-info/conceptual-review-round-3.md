全体判定: **CHANGES_REQUESTED**

Round 2 の指摘は設計上解消されています。残る論点は2点です。

## 1. 使命との整合性

- [Suggestion] 問題ありません。表示値を「確定している素材尺」に限定し、完成見込み尺と区別できています。

## 2. 禁止事項違反

- [Warning] 新設する `DeterminedScenarioDuration` 自体のPHPテストが、テスト項目に明記されていません。UIの4パターンは渡されたpropsの表示テストであり、サーバ側集計式の検証にはなりません。このままでは「テストなしの実装完了報告」につながる余地があります。

  修正提案: PHP側のテストへ次の集計分岐を追加してください。

  - 空リスト → `totalDurationMs = null`、未確定0件
  - 全件null → `null`、未確定件数は全件
  - 確定・未確定混在 → 確定分だけの合計とnull件数
  - 全件確定 → 全件合計、未確定0件

## 3. 実現可能性

- [Warning] `list<int|null>` はPHPStan/PHPDocの型表記であり、PHPの実引数型宣言には書けません。記載された `fromCutDurations(list<int|null> $perCutDurationsMs): self` をそのまま実装すると構文エラーになります。

  修正提案: 実装契約を次のように記載してください。

  ```php
  /**
   * @param list<int|null> $perCutDurationsMs
   */
  public static function fromCutDurations(array $perCutDurationsMs): self
  ```

## 4. 期待効果の妥当性

- [Suggestion] 部分和と未確定件数を隣接表示するため、主張する効果は合理的です。

## 5. リスク

- [Suggestion] relation参照を集計結果型から構造的に排除し、ready判定と60秒補完を既存の責務に残す設計は妥当です。

## 6. スコープの適切さ

- [Suggestion] 5キーの内訳も整合し、過大・過小な範囲は見当たりません。

## 7. 型安全性

- [Suggestion] `final readonly class` による結果型、`?int` と `int` の明示、DTO・TS間のnullable契約はPHPStan level 10に適合可能です。上記のPHPDocによるリスト要素型の固定を反映すれば問題ありません。