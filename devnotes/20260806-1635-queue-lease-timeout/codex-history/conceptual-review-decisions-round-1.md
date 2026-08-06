# 対応マトリクス: conceptual-review Round 1

## [Critical] 接続決定経路が `onConnection('リテラル')` 限定で網羅性が足りない (観点 3)

- 判断: **対応する** (指摘は正しい)
- 根拠: 実査すると現状 aicue には `onConnection()` リテラル 4 箇所しか無く
  (`$connection` プロパティ代入 / `viaConnections()` / dispatch 側の `->onConnection()` は 0 件)、
  「今の実装は網羅できている」が「**将来別経路が足されたら黙って検査外に落ちる**」のは事実。
  それは本 feature が閉じようとしている欠落 (a) そのもの。
- 対応内容: 目録 gate に「**接続決定経路の deny-by-default 走査**」を追加する。
  `app/` 全体を対象に `onConnection(` / `viaConnections(` / `viaConnection(` /
  `public .*\$connection` / `$this->connection =` / `Queue::connection(` /
  `->onConnection(` (dispatch 側チェーン) を走査し、**目録に登録された
  「クラス内の `onConnection('リテラル')`」以外の hit はすべて fail** させる。
  非リテラル引数 (`onConnection($x)`) も fail (「実行時に決まる接続は静的検査できない」)。

## [Critical] `database` の worker timeout 60 秒への引き下げの影響評価が不足 (観点 5)

- 判断: **対応する** (指摘は正しく、実測したら想定より深刻だった)
- 根拠: 実測 —
  - Stripe SDK の `CurlClient::DEFAULT_TIMEOUT = 80` (`CURLOPT_TIMEOUT`)、
    `DEFAULT_CONNECT_TIMEOUT = 30`。アプリ側で上書きしていない。
  - `ExecuteAutoRechargeAttemptJob` → `AutoRechargeService::executeAttempt()` →
    `createOrGetStripeCustomer` + `invoices->create` + `invoiceItems->create` +
    `invoices->finalizeInvoice` + `invoices->pay` = **1 ジョブで 4〜5 回の Stripe 呼び出し**。
    最悪 400 秒。**現行の `retry_after = 90` はこの面に対して既に小さすぎる**。
  - つまり 60 秒案は「規則 1 は満たすが実運用で誤 kill が出る」値だった。
- 対応内容: `database` 接続の `retry_after` を **90 → 300 (リテラル化)**、
  worker timeout を **240** にする。あわせて
  - 覆えない最悪ケース (5 呼び出しすべてが 80s 上限に張り付く 400 秒) を設計に明記し、
    その場合の挙動が「240 秒で kill → `$tries=1` で failed → リコンサイルが回収。
    **二重実行にはならない**」ことを示す。
  - 真の worst-case を覆うには Stripe client timeout の上限固定 (既存
    `PromptClientTimeoutInvariantTest` と同型) が要るが、これは課金経路の挙動変更なので
    **後続 TODO 候補**として分離する。
- 補足: `retry_after` を上げてよいのは `database` だけである
  (`database-analysis` / `database-render` は予約 TTL 1800 との連鎖に縛られる。
  `database-media` は 300 のままで 240 に十分)。

## [Warning] 「値を先に決める」比重が強い / 外部 I/O 上限との照合が弱い (観点 2)

- 判断: **対応する**
- 対応内容: 上記の Stripe SDK timeout 実測を「現状」節に組み込み、
  値の根拠を「十分と思われる」から「実測した外部 I/O 上限との関係」に置き換えた。

## [Warning] mprocs の接続集合完全一致は将来「dev では起動しない接続」で過剰に落ちる (観点 3)

- 判断: **対応する (明文化する側を採る)**
- 根拠: exemption 機構を今作るのは思考原則 2 違反 (除外したい接続が現時点で 0 件)。
  Codex の 2 択のうち「アーキテクチャ規約として明文化する」を採る。
- 対応内容: `docs/architecture.md` に
  「**`driver=database` の接続は dev ワーカーペインを必ず持つ**」を運用契約として明記し、
  gate はその写しであると設計に書く。除外が本当に必要になった時点で
  理由付き目録を足す (今は作らない) と明示。

## [Warning] 本番 supervisor は gate の外なので効果は限定的 (観点 4)

- 判断: **対応する**
- 対応内容: 「本番の二重実行を直接 gate するものではない」を設計本文に明記し、
  `docs/architecture.md` の運用契約に**接続ごとの具体値表**まで載せる。

## [Warning] `queue:listen` と `queue:work` の運用特性の違い (観点 5)

- 判断: **対応する**
- 対応内容: `queue:listen --timeout` は「子 (`queue:work --once`) を kill する天井」であり
  規則 1 における役割は `queue:work --timeout` と同一であることを設計に明記
  (両サブコマンドの既定値はいずれも 60 = `ListenCommand` / `WorkCommand` で実確認)。
  gate は**両サブコマンドを等しくワーカーとして扱う**。

## [Warning] 施策 3 / 4 の変更範囲差 — 失敗理由を規則ごとに分離せよ (観点 6)

- 判断: **対応する**
- 対応内容: テストファイルを規則ごとに 2 本に分け
  (`QueueWorkerLeaseInvariantTest` = 規則 1 / `QueuedJobLeaseInventoryTest` = 規則 2 + 網羅)、
  失敗メッセージの冒頭に「規則 1:」「規則 2:」を付けることを詳細設計で規定する。

## [Warning] `getDefaultProperties()['timeout']` の型崩れ (観点 7)

- 判断: **対応する**
- 対応内容: `int|null` へ正規化する純関数 helper に閉じ、
  非 int / 0 以下は明示的に fail させる規定を詳細設計に書く。

## [Suggestion] 使命への位置づけ (観点 1)

- 判断: **対応する (文言のみ)**
- 対応内容: 「機能改善ではなく信頼性の下支え」であることを冒頭に明記した。
