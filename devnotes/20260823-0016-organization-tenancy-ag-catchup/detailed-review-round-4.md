## 総評

Round 3の主要指摘はほぼ適切に解消されています。

特に施策7は、既存featureが明示していた「org文脈を得られなければアプリ内通知を作らない」というfallbackを、AG-037による入力消滅後にも適用するものです。この解釈であれば、新たなfan-outやnullable通知を設計するのとは異なり、他featureの新しい方針を勝手に決めたとは評価しません。メール継続と回帰テスト、auth側への申し送りも揃っているため受け入れ可能です。

ただし、施策10のgateが正規の `/app` と例外目録自身を扱えず、現状の設計では自己検出または正規入口の誤検出が起きます。

全体判定: **CHANGES_REQUESTED**

## 施策1: APPROVE

指摘ありません。

## 施策2: REQUEST_CHANGES

- [Warning] `OrganizationSlugWriteExemptions.php` が許可対象のSQLや書き込み構文を文字列として保持すると、その例外目録自身が書き込み検出器に拾われる可能性があります。  
  修正案: 例外は検出器が発行する安定したrule ID・path・件数で表現し、禁止SQLそのものを目録へ複写しないでください。構文文字列が必要なら、例外目録自身をメタデータファイルとして名指し分類し、その内容を別の自己検査で固定します。

exact-fit例外の考え方自体は妥当です。

## 施策3: APPROVE

ロック取得後の基準時刻、境界と`nextAvailableAt`の一致、認可、Service側同値競合の422変換まで閉じています。

## 施策4: REQUEST_CHANGES

- [Warning] 本文では契約を「1試行＝1 transaction境界」へ修正しましたが、全体リスクR10は依然として「1試行＝1 savepoint」と断定しています。外側transactionがない組織作成画面ではトップレベルtransactionです。  
  修正案: R10も「1試行＝1 transaction境界。外側transaction内ではsavepoint」に統一してください。

requested/derived/fallbackの遷移、有限再試行、422変換、Laratrust cache前の失敗順序は妥当です。

## 施策5: APPROVE

指摘ありません。

## 施策6: APPROVE

指摘ありません。

## 施策7: APPROVE

指定された論点について、次の理由から妥当な帰結の適用と判断します。

- 既存featureが「current orgがなければ作らない」と明示していた
- AG-037により、その入力が全利用者について構造的に消える
- 代替orgの選択、fan-out、nullable化のいずれも新設しない
- メール通知は維持する
- アプリ内通知非生成をFeatureテストで固定する
- auth/account-deletion側へ結果を申し送る

ただし実装時には、削除後のdocblockを「current orgがない場合」ではなく、「個人設定面から信頼できるorg文脈を導出できないため作らない」という最終アーキテクチャの言葉へ更新してください。

## 施策8: APPROVE

route名の型絞りとPWA/service worker scopeの3段検査まで閉じています。

## 施策9: APPROVE

指摘ありません。

## 施策10: REQUEST_CHANGES

- [Critical] `/app` は旧capture URLであると同時に、今後も残す正規の分岐入口です。文字列リテラル抽出だけでは、manifestの`start_url: "/app"`、`GET /app`のroute定義、正規入口へのリンクと、旧capture URL参照を区別できません。現状の「分岐入口以外」という条件には機械的な分類方法がありません。  
  修正案: 自己検出例外とは別に、正規入口 `/app` の許可目録を設けてください。少なくとも次をpath・構文・件数・理由の完全一致でpinします。

  - `manifest.webmanifest` の`start_url`
  - `capture.entry`のroute定義
  - 正規入口を生成するroute helper
  - 必要ならservice worker/navigation fallback

  それ以外の裸の`/app`は旧URLとして検出します。

- [Critical] `LegacyUrlSelfDetectionExemptions.php` 自身が `/projects`、`/dashboard`、`organizations.switch` 等を保持するため、現在の「gate本体とfixtureのみ登録可能」では例外目録自身を検出します。走査器のUnitテストが別ファイルなら、そこに置く負例も同様です。  
  修正案: 自己検査用語彙を持つ全メタデータファイルを明示的に分類してください。推奨は次のいずれかです。

  - 例外目録・fixture・抽出器自己テストを理由付きメタデータ分類へ入れ、ファイルと一致件数を別gateで完全固定する
  - 検出器が発行する安定IDを目録に保存し、旧URL文字列そのものを例外目録へ複写しない

  ファイルを黙って走査対象外にするのではなく、自己検査専用の名指し分類にしてください。

- [Warning] テスト計画が「3形と新URLの誤検出なし」だけで、今回追加したファイル種別別抽出の検出力を十分に固定していません。  
  修正案: PHP/TS/Svelte文字列、Blade/HTML属性、Markdownリンク、MarkdownプレーンURL、JSON値について、それぞれ最低1つの正例・負例と未分類形式のfail-closedを追加してください。

## 施策11: APPROVE

施策7の結論と共有ファイル判定が整合しました。D40のみ追加して37件とする方針は妥当です。

## 横断的な指摘

- [Warning] 「変更単位」節の進行順序は `単位A → 単位B → 施策9...`、実装モードは `単位A → 施策9 → 単位B...` で一致していません。  
  修正案: 固定順序を1つに統一してください。施策9が単位Bに依存しないなら、実装モード側の `単位A → 施策9 → 単位B → 施策3 → 施策10 → 施策11` で統一できます。

## 全体判定

**CHANGES_REQUESTED**

承認までの停止条件は施策10の2点です。

1. 正規入口として残る `/app` をexact-fitで旧URL検出から区別する。
2. 例外目録・fixture・抽出器自己テストによる自己検出を、再帰しないメタデータ分類で閉じる。

それ以外のRound 3再承認条件、とくに施策7の判断は受け入れ可能です。