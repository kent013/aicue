# 対応マトリクス: conceptual-review Round 5 (APPROVED)

Round 5 で全体判定 APPROVED。指摘はすべて [Suggestion] で、内容は
「Phase 2 (詳細設計) で確定すること」の確認である。詳細設計へ引き継ぐ項目は次の 4 点。

| # | 引き継ぐ内容 | 詳細設計での扱い |
|---|---|---|
| 1 | 第 2 アプリ生成時の `Container` の singleton・facade の解決済みインスタンス・例外時の復元を `finally` で保証する | 負例の節に退避と復元の手順を書く |
| 2 | 前テストが異常終了して macro の状態が残った場合に備え、bootstrap 前に accumulator だけでなく **macro の初期状態も検証するか**を決める | **決める** — 起動前に `Repository::$macros` を検査し、空でなければ違反として記録したうえで既定へ復元する (accumulator の初期化と同じ位置) |
| 3 | 反射・extender・第 2 アプリの退避値の型絞り込み | 各クラスの節に PHPStan level 10 を通す形で書く |
| 4 | override は vendor の可視性・シグネチャをそのまま固定する | vendor 実読の宣言を詳細設計へ転記する |
