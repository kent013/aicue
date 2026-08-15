## 全体判定: APPROVED

Round 3 の指摘はすべて解消されています。

- `TooLarge`はテスト内config overrideで、前段のmanual上限を壊さず窓口拒否を再現できる
- `InvalidEncoding`は既存container境界のtest doubleで、抽出器の保証喪失を適切に再現できる
- 本番コードへテスト専用の分岐・脱出口を追加していない
- 3拒否について、再試行回数・ジョブ状態・利用者向け文言・チケットreleaseが固定される
- 実バイト数による上限の追認へ修正されている
- 文書の予約変数保証が実装の保証範囲と一致している

### 施策別判定

| 施策 | 判定 |
|---|---|
| A. 防御設定の集約 | APPROVE |
| B. 入力の無害化 | APPROVE |
| C. 応答カナリア | APPROVE |
| D. 窓口と実行単位 | APPROVE |
| E. factory・YAMLの窓口化 | APPROVE |
| F. パイプラインの失敗写像 | APPROVE |
| G. 窓口通過の構造検査 gate | APPROVE |
| H. 集約設定の gate | APPROVE |
| I. 既存 gate 4本の更新 | APPROVE |
| J. 実行時テストと攻撃コーパス | APPROVE |
| K. 規約文書の更新 | APPROVE |

実装時には、`SopTextExtractor`が継承可能で`extract()`をoverride可能であること、および`Prompt::fake()`のcallbackから実行対象promptのカナリアを取得できることをfail-firstで確認してください。これは設計変更要求ではなく、記載した注入経路がvendor・現行クラスの実APIと一致することの実装時確認です。