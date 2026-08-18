## Round 5 判定

Round 4 の必須指摘はすべて解消されています。

- macroは`finally`で確実に復元される
- 残存macro検査は別経路へ分離されている
- Probeの静的契約は`void`へ修正されている
- custom driverの登録・解決対象が同一managerに統一されている
- S5/S7の変更ファイル・目録・検査名が同期している

また、実装時にしか判明しない露出やvendor経路をS8の反復計測へ委ねる分担も妥当です。検出結果を許可一覧で逃がさず、解消不能なら設計へ差し戻すため、未確定事項を無条件に先送りする構造にはなっていません。

## 施策別判定

| 施策 | 判定 |
|---|---|
| S1 値検査器と例外 | APPROVE |
| S2 guard付き受け皿とmanager | APPROVE |
| S3 guard本体 | APPROVE |
| S4 起動前結線と全レーンの後始末 | APPROVE |
| S5 実行時層の振る舞い検査 | APPROVE |
| S6 結線のpin | APPROVE |
| S7 静的層の訂正とL4 | APPROVE |
| S8 露出の計測と是正 | APPROVE |
| S9 パッケージキャッシュの無効化 | APPROVE |
| S10 規約の明文化 | APPROVE |
| S11 テンプレート差分登録 | APPROVE |

## 実装時の確認事項

以下は設計変更を求めるものではありません。

- [Suggestion] L4の直接生成結果には、例示コードの`$token->text`ではなく、必ず解決済みFQCNを格納してください。自己テスト目録もFQCNなので、alias・短名のままではexact-fitしません。
- [Suggestion] S2/S3に残る「`Cache::extend()`を0件でpin」という説明は、実装時に「通常経路0件＋`GuardedBoundaryProbe`の自己テストexact-fit」へ統一してください。
- [Suggestion] 「目録の全entryが1度ずつ対応」は、`count=2`のentryもあるため、「全entryが非空で、件数までexact-fit」と表現すると正確です。
- [Suggestion] 実装コードでは、S4の簡略表記`assert()`を予定どおり明示的な`instanceof`＋例外へ置き換えてください。

UI、DTO、JsonResource、Inertia、Atomic Designは本件では該当ありません。

## 全体判定

**APPROVED**

詳細設計として実装可能な水準に達しています。実装完了の判断は、S8の反復計測、S7目録の最終同期、全検証コマンドの成功を満たした時点で行うのが妥当です。