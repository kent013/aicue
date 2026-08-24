# 実測用の一時スクリプト (設計の裏取り)

本ディレクトリは**設計の根拠を再現するための一時スクリプト**であり、アプリの一部ではない
(AGENTS.md「一時スクリプトは devnotes へ、恒久スクリプトのみ `scripts/` へ」)。

| ファイル | 中身 |
|---|---|
| `candidate-core.php` | 詳細設計 S1 / S2 の「変更後コード」(定数・純関数) をそのまま切り出したもの |
| `candidate-tests.php` | 詳細設計 S3 の「変更後コード (テスト本体)」= N-1〜N-5 |
| `verify-predicate.php` | `candidate-core.php` を実リポジトリへ当てる実測ハーネス (Pest を使わない素の PHP) |

再現手順 (リポジトリルートから):

```bash
php -l devnotes/20260824-1013-rename-residual-name-gate-v1/evidence/candidate-core.php
php -l devnotes/20260824-1013-rename-residual-name-gate-v1/evidence/candidate-tests.php
php devnotes/20260824-1013-rename-residual-name-gate-v1/evidence/verify-predicate.php
```

2026-08-24 / HEAD `b207bafa` での実測: N-1 は 9925 ファイル・違反 0 件・約 0.42 秒、
アサーション 51 件すべて緑 (`ALL OK`)。

★`verify-predicate.php` は `base_path()` を自前で定義して `/workspace` 直下を見る。
リポジトリの置き場所が違う環境では 1 行だけ直すこと。
★このスクリプトは**実装の代わりにはならない**。本番の検査は Pest の
`tests/Architecture/BughuntNamingResidualTest.php` として置く (実装フェーズの仕事)。
