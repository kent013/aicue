# 対応マトリクス: impl-review Round 2

Round 2 の返答は **APPROVED**。新規の [Critical] / [Warning] / [Suggestion] は 0 件。

## 反論 2 件の帰結

| Round 1 の指摘 | 判断 | Round 2 でのレビュアーの結論 |
|---|---|---|
| [Critical] migration / factory が差分に無い (施策 7 未完了) | 反論する | **反論成立**。pathspec に `database/` が欠けていたことによる確認不能であり、実装は完了していると確認された |
| [Warning] `role=organization_owner` の 422 テストが無い | 反論する | **反論成立**。既存テストの見落とし。Inertia レーンでは `assertSessionHasErrors('role')` が正確な検証形であることも追認された |

## 対応 1 件の帰結

| Round 1 の指摘 | 判断 | Round 2 でのレビュアーの結論 |
|---|---|---|
| [Warning] `LockedRowReload` が構造を機械検証していない | 対応する | 「分類したもの勝ち」の問題は解消。M8a / M8b で各条件が実際に赤化することも確認済みと認定された |

## 残課題

- なし (APPROVED)。
- レビュアーが付記した「正規表現は字句形を保証するもので、モデルの由来そのものを意味解析しない」は
  保証範囲の確認であり、テスト docblock に既に「抽出は字句 (文字列一致) ベース」と明記済み
  (誇張しない方針と整合)。追加対応は不要。
