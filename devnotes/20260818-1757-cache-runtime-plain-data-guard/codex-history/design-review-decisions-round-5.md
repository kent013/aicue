# 対応マトリクス: design-review Round 5 (APPROVED)

Round 5 で全体判定 APPROVED、全 11 施策が APPROVE。指摘はすべて [Suggestion]（実装時の確認事項）で、
4 件とも**設計本文へ反映済み**である。

| # | 指摘 | 対応 |
|---|---|---|
| 1 | L4 の直接生成の記録は `$token->text` ではなく**解決済み完全修飾名**を格納する（自己テスト目録も完全修飾名なので、別名・短名では exact-fit しない） | S7 の擬似コードを `$resolved` を使う形へ直し、`$bypassCounts` の key も完全修飾名で作るようにした |
| 2 | S2 / S3 に残る「`Cache::extend()` を 0 件で pin」の説明を「通常経路 0 件 + `GuardedBoundaryProbe` の自己テスト exact-fit」へ統一する | `PlainDataGuardedCacheManager` の docblock と `CACHE_PAYLOAD_BYPASS_METHODS` の説明を直した |
| 3 | 「目録の全 entry が 1 度ずつ対応」は `count = 2` の entry があるため不正確。「全 entry が非空で、件数まで exact-fit」と書く | S7 の L4a / L4b の説明を直した |
| 4 | S4 の簡略表記 `assert()` は、実装では明示的な `instanceof` + 例外へ置き換える | S4 の変更後コードを `if (! $app instanceof Application) { throw … }` へ書き換えた（`zend.assertions` の設定に依存させない） |

## 実装完了の判断（レビューの合意）

S8 の反復計測が終わり、S7 の目録が最終同期され、全検証コマンドが成功した時点で完了とする。
