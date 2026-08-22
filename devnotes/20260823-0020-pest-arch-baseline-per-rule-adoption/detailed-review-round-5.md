## 全体判定: CHANGES_REQUESTED

設計上の機構・テスト計画に本質的な残件はありません。Round 4の3指摘も適切に解消されています。

ただし、V6と矛盾する保証表現が施策2に1か所残っています。

## 施策1: `ArchBaseline`

判定: APPROVE

V2の表と本文は整合しました。

## 施策2: `GlobalFunctionCallScanner`

判定: REQUEST_CHANGES

- [Warning] 「リスク」に、例外の波及範囲が「その1シンボルの、その1クラス」と残っています。

  V6で確定したとおり、Pestの解析・除外単位はファイルです。この表現は例外の実効範囲を過小評価します。

  修正案:

  > I2 がシンボル方向の blast radius を1シンボルに抑えているため、余った例外が隠せるのは「その1シンボルの、その例外オブジェクトに対応する1ファイル内での使用」だけである。

  これによりV6およびgate docblock第8項と一致します。

## 施策3: `ArchSurfaceScanner`

判定: APPROVE

## 施策4: `VendorArchPresetReader`

判定: APPROVE

## 施策5: `ArchBaselineTest`

判定: APPROVE

正規化済みの同一集合を包含検査、接頭辞衝突検査、床値検査へ渡す設計で問題ありません。厳密比較もPestの挙動と整合します。

## 施策6: 検出力テスト

判定: APPROVE

No.37により、重複による母集団床値の水増しを防ぐ契約が固定されています。実装時はNo.37が本番コードと同じ正規化関数を呼び、テスト側に `array_unique()` と `sort()` を複写しないようにしてください。

## 施策7: D40登録

判定: APPROVE

## 施策8: 概念設計訂正

判定: APPROVE

施策2の「1クラス」を「1ファイル」へ直せば、全体判定はAPPROVEDです。