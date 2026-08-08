# 対応マトリクス: conceptual-review Round 5 (APPROVED)

Round 5 で **APPROVED**。Critical / Warning はゼロ。以下の Suggestion は
**詳細設計フェーズへ持ち越して具体化する**ものとして受理した。

| # | Suggestion | 持ち越し先 |
|---|---|---|
| 1 | R5 の判定を「`sync` / 未定義接続 / `redis` を拒否し `database` を許可」のテーブルテストで固定する。config cache 後も同じ判定になることの確認も有用 | 詳細設計 M6 のテスト計画 |
| 2 | 一回性テストの 3 番目 (競合例外の no-op 化) では、一般的な `QueryException` をすべて握らず **対象 partial unique 制約の違反だけ**を競合として扱うことを固定する (接続障害や別制約違反を no-op にすると本当の障害が隠れる) | 詳細設計 M3 / M9 |
| 3 | fake channel 方式はアプリケーション層の例外分離だけを保証し、PostgreSQL の tx abort までは保証しない (§8 の記述と一致) | 詳細設計 §保証しないもの に再掲 |
| 4 | §5-1 の 3 層で 0 件 pin の空振り経路は閉じている | 変更なし |

APPROVED の根拠 (Codex の言): 禁止事項 / North Star / Laravel 12 での実現可能性 /
PHPStan level 10 の型方針に残る Critical・Warning は無い。
