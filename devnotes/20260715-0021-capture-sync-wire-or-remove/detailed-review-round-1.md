ありがとうございます。提示された詳細設計は、**削除リファクタとしてかなり筋が良く、実装可能性も高い**です。  
（前提どおり、提供テキストベースでレビューします）

## 施策別判定

- **施策0 参照監査**: **APPROVE**
  - 三系統監査 + `route:list` + call graph確認まであり、削除前ゲートとして妥当。

- **施策1 syncバックエンド削除**: **APPROVE**
  - 削除対象が具体化され、共有DTO保持も明記。閉じた集合での削除方針は適切。

- **施策2 route/import削除**: **APPROVE**
  - ルート削除とimport削除の対応関係が明確。隣接ルートへの非影響評価も妥当。

- **施策3 IDOR inventory更新**: **APPROVE**
  - `NestedRouteIdorDefenseTest` と route の同時更新前提が明確で、drift回避設計として正しい。

- **施策4 bug-hunt operations更新**: **APPROVE**
  - `scripts/bug-hunt-inventory-check.sh` の forward/reverse 両面に触れており、運用上の整合が取れている。

- **施策5 canonical doc整合**: **APPROVE**
  - 仕様記述の削除範囲が sync 固有に限定され、過剰改変を抑えている。

- **施策6 TS型 `SyncResult` 削除**: **APPROVE**
  - 未参照である前提が明示され、型削除の波及リスクは低い。

- **施策7 Featureテスト削除 + 全検証**: **APPROVE**
  - 「廃止endpoint専用テストの削除」であり、代替経路の既存担保を前提化している点は妥当。

## 指摘事項

- **[Warning] 共有DTO非削除の検証を“手順”として固定化した方がよい**
  - 現状でも保持リスト記載は良いが、実装者依存を減らすため、施策0に  
    `rg "CaptureManualDetailData|CaptureTakeData|TakeObjectStorage|UploadTicketCodec" app tests`
    を**必須チェック項目として明文化**すると安全性が上がります。
  - **修正案**: 施策0の監査コマンド群に上記grepを追記し、「保持DTOの参照が残っていること」をPRテンプレ記録項目に追加。

- **[Suggestion] ProhibitsProtectedKeys系の“動的走査”根拠を1行補強**
  - 主張は妥当です（FormRequest削除で走査対象から自然脱落）。
  - ただし将来レビューのために、設計書に「当該3テストはクラス名の固定期待を持たない」旨を1行追記すると再検証コストが下がります。

## 特に確認依頼の論点への回答

- 閉じた参照集合の漏れ・巻き込み: **妥当**。共有DTO誤削除防止も設計上は成立。
- `ProhibitsProtectedKeys` 系 fail しない主張: **妥当**（動的収集前提）。
- route/inventory/operations 同時削除で drift 0: **妥当**。同一PR不可分として正しい。
- standalone 実装モード: **妥当**。この変更は分割より一括の方が安全。

## 全体判定

- **APPROVED**（上記 Warning 1件の手順明文化を推奨）