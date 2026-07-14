全体判定: **CHANGES_REQUESTED**

Round 1 の指摘自体には対応できていますが、MAX_STEPS 境界に新たな統合上の問題があります。

### 施策1: config / ScenarioLimits

判定: **REQUEST_CHANGES**

- [Critical] 導入・総括が通常の `Step` である以上、materialize 後の102件を手動編集でそのまま送信すると、既存の「手動編集 step 上限100」に抵触する可能性があります。独立 `CutType` がないため、保存側は定型2件を判別できません。  
  修正案: 100件生成→102件materialize後に、編集画面相当の全件を `ScenarioService::save()` できることを仕様化し、Featureテストで保証してください。失敗するなら、保存入力上限・検証単位を見直す必要があります。単なる定数コメント再定義では不十分です。

### 施策2: lang

判定: **APPROVE**

- `Lang::has()` と `Assert::string()` による fail-fast は妥当です。

### 施策3: ScenarioBookendBuilder

判定: **REQUEST_CHANGES**

- [Warning] 件数削減時の長さ判定が recap 本文だけで、`subtitle_secondary_recap` の接頭辞「要点の再確認：」を含みません。そのため複数候補の状態で完成文が上限超過し、最後の `clamp()` が末尾候補を途中切断します。「件数削減後、1件のみなら文字truncate」という仕様を満たしません。  
  修正案: 各ループで `line('...subtitle_secondary_recap', ['points' => implode(...)])` の完成文を測定してください。1件でも超過する場合のみ完成文をtruncateします。

- [Suggestion] `preg_replace()` の失敗を `(string)` キャストで空文字化せず、結果を `Assert::string()` で閉じると異常を候補欠落として隠しません。

### 施策4: Pipeline 組み込み

判定: **APPROVE**

- terminal transaction、ロック済みmanual、今回生成stepsのみの参照は適切です。

### 施策5: Unitテスト

判定: **REQUEST_CHANGES**

- [Warning] 長さ境界テストは、recapだけでなくlang接頭辞を含む完成済み `subtitleSecondary` を基準にしてください。  
  修正案: 「複数件では完成文超過→件数削減」「1件でも完成文超過→文字truncate」を分離して検証してください。

### 施策6: Featureテスト

判定: **REQUEST_CHANGES**

- [Critical] 100→102のmaterialize確認だけでは編集可能性を保証できません。  
  修正案: 102件を取得して `ScenarioService::save()` 相当の入力へ変換し、保存成功・順序維持・共有ロック規約準拠を検証してください。

### 施策7: 既存テスト更新

判定: **APPROVE**

- 位置・型・親子関係まで確認する改訂で十分です。
- `$generatedStep` と `$point` のnull型がPHPStan/Pestで残る場合は、既存方針に従い `Assert::isInstanceOf()` 等で型を閉じてください。

Dのinventory登録不要判断は引き続き妥当です。上記2点、特に「102件を手動保存できるか」を解消すれば承認可能です。