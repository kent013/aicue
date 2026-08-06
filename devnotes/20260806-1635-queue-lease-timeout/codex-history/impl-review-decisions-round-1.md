# 実装レビュー Round 1 対応マトリクス (T122)

Codex 判定: **CHANGES_REQUESTED** (Critical 0 / Warning 1 / Suggestion 0)

| # | 分類 | 指摘 | 判断 | 対応内容 |
|---|---|---|---|---|
| 1 | Warning | 接続 pin の検査が「queued class 内に `$this->onConnection('リテラル')` が 1 件ある」ことまでしか見ておらず、その呼び出しが **dispatch 前に必ず実行される**ことを保証していない。未使用メソッドへ移しても gate は通り、実行時は既定接続へ流れて規則 2 の比較対象が空洞化する | **対応する** | `接続経路: 目録の接続宣言がソースと一致する` を強化。目録値が非 null のクラスについて (a) 自クラス宣言の constructor が存在すること (親クラス由来は不可)、(b) 検出した `onConnection` site の行が `ReflectionMethod::getStartLine()`〜`getEndLine()` の範囲内にあること、を追加検証した |

## 実装メモ

- トークン解析側 (`jobLeaseConnectionDeclarationSites()`) に関数スコープ追跡を足す案も検討したが、
  Reflection の行範囲で同じ保証が取れ、解析器の複雑度を上げない方を採った
  (AGENTS.md 思考原則 2「今必要なものだけ作る」)。
- 検査が空振りしていないことを実証済み: `DeleteTakeObjectsJob` の `onConnection()` を
  constructor 外の新メソッドへ一時的に移すと
  `接続経路: App\Jobs\Capture\DeleteTakeObjectsJob の onConnection() (L38) が constructor の外にある`
  で fail することを確認してから元に戻した。
- 施策 3/4/5 の 17 テスト再実行: 17 passed / 144 assertions。
