# 対応マトリクス: conceptual-review Round 2

## [Critical] 実測 400 秒を承知で 240 秒を採用しており、実測が採用値に反映されていない (観点 2)

- 判断: **対応する** (指摘のとおり。Codex 提示の 2 番目の選択肢を採る)
- 根拠: 「現行が `--timeout=0` だから何を入れても改善」は、実行可能時間を狭める判断の
  正当化にはならない。実測値を採用値へ反映するのが筋。
- 対応内容: `database` の値を **`retry_after = 600` / ワーカー timeout = 540** に改める
  (240/300 案は撤回)。既知の有限上限 (Stripe 5 呼び出し × SDK 上限 80s = 400s) を
  140 秒上回る。Stripe client timeout の短縮 (選択肢 3) は課金経路の挙動変更なので
  本 feature に混ぜず、後続 TODO 候補のまま据え置く
  (**選択肢 2 を採ったので、後続 TODO は「本 PR が狭めた時間を取り戻すための宿題」ではなく
  「回収遅延を縮めたくなったときの選択肢」に位置づけが変わる**)。

## [Critical] `database` に載る Mail / Notification の外部 I/O 上限が未実測 (観点 5)

- 判断: **対応する** (実測した。結論は「有限上限が存在しない」)
- 根拠 (実測):
  - Mail: `config/mail.php` の smtp mailer は `'timeout' => null`。
    production 想定の `ses` mailer (`ses-v2`) は `config/services.php` に
    **HTTP timeout 設定を持たない** (AWS SDK 既定は timeout 無制限)。
  - Notification 6 本はすべて `via() === ['mail']` = 上と同じ経路。DB channel なし。
  - `database-media` の削除ジョブが使う S3 disk (`config/filesystems.php`) にも
    timeout 設定は無い。
- 対応内容: 設計の論理を反転させて明記する ——
  **既定接続には SDK 由来の有限上限が存在しない。したがってワーカー timeout は
  「導出される値」ではなく「上限を作る運用 SLA」である**。導出できるのは
  「既知の有限上限 (Stripe 400s) を上回らなければならない」という下限だけ。
  540 はこの下限を満たす最小の切りのよい値として置く。
  現状は上限そのものが無い (`--timeout=0`) ので、**540 を置くことは後退ではなく
  初めて上限を与える変更**である、と明示する。
  Mail / S3 の client timeout 固定は後続 TODO 候補に追加する。

## [Critical] PHP の接続決定経路を正規表現で走査するのは網羅保証にならない (観点 3)

- 判断: **対応する** (指摘は正しい)
- 根拠: 別名 import・trait・改行・コメント・静的呼び出し・変数経由 dispatch で
  誤検出/検出漏れが起きるのは事実。とくに `->onConnection(` の
  「ジョブ内部 / dispatch 側」の分類は字句だけでは決まらない。
- 対応内容: PHP 側の走査を **`token_get_all()` によるトークン解析**に切り替える
  (nikic/php-parser は直接依存ではないので stdlib のトークナイザで足りる)。
  - 空白 / コメント / DocComment を除去したトークン列で
    `T_OBJECT_OPERATOR|T_NULLSAFE_OBJECT_OPERATOR|T_DOUBLE_COLON` + `T_STRING`
    (`onConnection` / `viaConnections` / `viaConnection`) を検出する。
  - 引数が `T_CONSTANT_ENCAPSED_STRING` 1 個 + `)` の形だけを「リテラル」と認め、
    それ以外は**解析不能として fail** させる (「動的接続は静的検査できない」)。
  - `$this->connection = ` 代入 / `connection` という名前のプロパティ宣言も
    トークンで検出して deny する。
  - 呼び出し元クラスは同じトークン列の `T_NAMESPACE` + `T_CLASS` から決める
    (ファイル → クラスの推測に頼らない)。
  - 正規表現は **bash (`scripts/bug-hunt-shard.sh`) と YAML (`mprocs.yaml`) の
    限定された構文にのみ**使う。

## [Warning] `ShouldQueue` 母集団は `implementsInterface()` を正本にせよ (観点 3)

- 判断: **対応する**
- 対応内容: 母集団判定を `ReflectionClass::implementsInterface(ShouldQueue::class)`
  + `isInstantiable()` に固定し、Job / Mail / Notification の 3 系統が実際に
  母集団へ入っていることを目録の内容で確認する (代表 3 クラスを名指しで assert)。

## [Warning] 効果の表現を「本番設定値の正本を提供する」に限定せよ (観点 4)

- 判断: **対応する**
- 対応内容: 期待効果の文言を
  「リポジトリ内の dev / bug-hunt 設定とジョブ契約を保証し、**本番設定値の正本を提供する**。
  本番プロセス定義とのドリフトは検知しない」に置き換える。

## [Warning] timeout 時の失敗遷移のタイミングが雑 (観点 5)

- 判断: **対応する** (実装を読んで正確化した)
- 根拠 (実測): `Illuminate\Queue\Worker::registerTimeoutHandler()` は SIGALRM ハンドラ内で
  **kill する前に** `markJobAsFailedIfWillExceedMaxAttempts()` を呼ぶ。
  したがって
  - `$tries = 1` のジョブ (課金 3 本 / 解析 / レンダ): timeout 時点で **同期的に failed 記録**
    (`failed()` フックも走る) → その後 kill。リコンサイルが回収する。
  - `$tries = 3` のジョブ (`ReuseSubscriptionPaymentMethodJob` /
    `SetDefaultPaymentMethodJob` / media 削除 2 本): failed にはならず予約が残り、
    **`retry_after` 経過後に再配布**される。再配布までの遅延 =
    `retry_after − ワーカー timeout` = 本設計では 60 秒。
    **規則 1 が守られているので、kill 済みプロセスと再配布先が同時に走ることはない**。
- 対応内容: 上記の 2 分岐を設計に明記する。あわせて詳細設計の Feature テストで
  「tries=1 は timeout で即 failed」「tries=3 は retry_after 経過後に再配布」を固定する。

## [Warning] `retry_after` 引き上げによる回収遅延 (観点 5)

- 判断: **対応する (許容できる理由を書く)**
- 対応内容: 90 → 600 で、**ワーカーが異常死した場合**の再取得が最大 510 秒遅くなることを明記し、
  許容理由を書く: (a) 遅延が効くのは worker のクラッシュ時のみで通常運転では発生しない、
  (b) 既定接続に載るのは Mail / Notification (遅延はユーザー操作をブロックしない) と
  課金ジョブ (`$tries=1` + リコンサイル回収が正規の再試行経路であり、
  `retry_after` による再配布に依存していない)。

## [Warning] 実装範囲が小さくない — 3 責務に分けて診断可能にせよ (観点 6)

- 判断: **対応する**
- 対応内容: テストを 2 ファイル・3 テスト群に分け、失敗メッセージの先頭に
  `規則 1:` / `規則 2:` / `接続経路:` を付ける規定を詳細設計に書く
  (ファイルを 3 本に割らないのは、規則 2 と接続経路走査が同じ目録定数を共有するため。
  診断可能性はメッセージ接頭辞と独立したテストケースで担保する)。

## [Suggestion] 未宣言と明示 `null` の区別 (観点 7)

- 判断: **対応する**
- 対応内容: `array_key_exists('timeout', $defaults)` を判定に含める規定を詳細設計に書く。
