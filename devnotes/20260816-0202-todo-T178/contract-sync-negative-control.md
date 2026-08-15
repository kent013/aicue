# 契約ずれ検査の負のコントロール実測 (T178)

対象: `tests/Architecture/BfcacheGuardClientContractSyncTest.php`

目的: 「片側だけ変えると赤になる」ことを手で 1 度確かめ、**どこまで赤くなるか**を記録する
(詳細設計 S7 のテスト計画)。

実施日: 2026-08-15 (UTC)。Codex 実装レビュー Round 1 の指摘を受けた強化の後に再実施。

## 手順

対象ファイルを一時的に書き換えて `vendor/bin/pest --filter="BfcacheGuardClientContractSync"` を
走らせ、直後に書き戻した。

## 照合の 2 方式

| 対象 | 照合方式 | 理由 |
|---|---|---|
| cookie 名 / ヘッダ名 | 二重引用符ごとの完全一致 (`"session_epoch"`) | `-` や `_` は識別子の境界にならないので、接尾辞を足した改名を境界照合では見逃す。画面側ではこの 2 つを文字列としてしか書けない |
| 共有 prop のキー / 応答キー / 状態値 / 配線の関数名 | 識別子の境界での一致 | 画面側では属性アクセスや型宣言として現れるため、引用符が付かない |

## 結果 1: 宣言 1 行だけを改名した場合

| 改変 | 結果 |
|---|---|
| `resources/js/lib/bfcache-guard.ts` の cookie 名を `session_epoch_renamed` へ | **赤** |
| 同ファイルのヘッダ名を `X-Session-Epoch-Renamed` へ | **赤** |
| 同ファイルの応答キー参照を `record.sessionEpochMatched` へ | 緑のまま |
| `resources/js/lib/shared-props.ts` の読み取りを `sessionEpochX` へ | 緑のまま |
| `resources/js/app.ts` から `readRenderedEpoch:` の配線を削除 | **赤** |
| `resources/js/lib/debug/bfcache-trial.ts` の型の `reloading` を `reloadingX` へ | 緑のまま |

緑のままになった 3 つは、**同じ語がそのファイルの別の場所 (docblock・型宣言・許可値の配列) に
残っている**ためである。検査はファイル単位で語の実在を見るので、これは仕様どおりの挙動である。

## 結果 2: 語をファイルから全消しした場合

| 改変 | 結果 |
|---|---|
| guard から `session_epoch` を全消し | **赤** |
| guard から `X-Session-Epoch` を全消し | **赤** |
| guard から `sessionEpochMatches` を全消し | **赤** |
| shared-props から `sessionEpoch` を全消し | **赤** |
| guard から `[0-9a-f]{32}` を全消し | **赤** |
| 検証ページの観測ライブラリから `reloading` を全消し | **赤** |

## 併せて確認した behavioral な負のコントロール

`app/Http/Middleware/HandleInertiaRequests.php` の共有 prop を closure から即値
(`Inertia::always(SessionEpoch::current($request))`) へ戻すと、
`tests/Feature/Auth/SessionEpochSharedPropTest.php` の
「セッション ID が要求中に再生成される経路でも描画世代と世代 cookie が同値になる」が**赤**になる
(描画世代が要求前のセッション ID で固定され、cookie とずれるため)。

## 結論

契約ずれ検査が確実に捕まえるのは「**画面側からその語が消えた**」場合と、
「**cookie 名・ヘッダ名の文字列が変わった**」場合である。
それ以外で「使う場所だけ別名にして、説明文には古い語が残っている」状態は捕まえられない。
意味の正しさは vitest の分岐テストと Feature テストの応答契約が担う。
この限界はテスト本体の docblock にも書いてある。
