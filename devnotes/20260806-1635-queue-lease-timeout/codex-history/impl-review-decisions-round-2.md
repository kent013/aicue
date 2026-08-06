# 実装レビュー Round 2 対応マトリクス (T122)

Codex 判定: **CHANGES_REQUESTED** (Critical 0 / Warning 1 / Suggestion 0)

| # | 分類 | 指摘 | 判断 | 対応内容 |
|---|---|---|---|---|
| 1 | Warning | `onConnection()` が constructor の**行範囲内**にあることは「dispatch 前に必ず実行される」保証にならない。`$pin = fn () => $this->onConnection(...)` (評価されない) / `if (...) { $this->onConnection(...) }` (条件付き) は行範囲検査を素通りする | **対応する** | トークン解析の site に `statementStart` を追加した。`$this->` 呼び出しであること + 直前のトークンが文の境界 (`{` / `}` / `;`) であること + `parenDepth === 0` かつ波括弧深さが「クラス本体 + 1」であること、の 3 条件で「メソッド本体の直下に置かれた実行文」だけを許可する。`接続経路: 接続の指定は …` (app/ 全体の deny-by-default) と `接続経路: 目録の接続宣言がソースと一致する` (目録クラス個別) の両方で必須化した |

## 検証 (mutation test。いずれも実施後に元へ戻し済み)

`app/Jobs/Capture/DeleteTakeObjectsJob.php` を一時改変して gate が赤くなることを確認した。

| 改変 | 結果 |
|---|---|
| `$pin = fn () => $this->onConnection('database-media');` (クロージャ内 = 評価されない) | **2 tests failed**。`接続経路: … onConnection() が「必ず実行される文」の形になっていない` |
| `if ($paths !== []) { $this->onConnection('database-media'); }` (条件付き) | **2 tests failed**。同上 |
| `onConnection()` を constructor 外の別メソッドへ移動 (Round 1 の対応分) | **1 test failed**。`… onConnection() (L38) が constructor の外にある` |

復元後: 施策 3/4/5 の 3 ファイル **17 tests / 17 passed / 148 assertions**、`vendor/bin/pint --test` は本差分 green。
