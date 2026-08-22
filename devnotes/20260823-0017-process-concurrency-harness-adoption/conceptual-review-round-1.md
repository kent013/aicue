全体判定: **CHANGES_REQUESTED**

正典 v1 の境界を正しく読み替え、実プロセス 2 本・barrier 同期・見本 1 本という方向へ戻した点は妥当です。特に、既存の同一プロセス並行テストを置換しない判断、冪等 claim を対象に選ぶ判断、一次観測を行数より優先する判断は、使命・正典・最小スコープによく整合しています。

ただし、現設計のままでは「実際には並行していないのに緑になる／環境によって不安定になる」余地と、開発 DB 到達の安全境界に未確定部分があります。

## 1. 使命との整合性

[Suggestion] 冪等性の実証は、二重の撮影・レンダリング指示を防ぐ基盤として North Star に寄与します。ただし「外部連携の入口」という前提は、対象 route が実際にその責務を持つことを実装時に確認してください。テストの主張は「撮影ジョブ等が二重に走らない」ではなく、まず middleware が保証する「本処理を一度だけ通す」に限定するのが正確です。

## 2. 正典 v1 の 6 要素

[Critical] barrier の ready 合図が子ごとに区別される設計になっていません。ready ファイルが共有なら、片方だけが ready を作っても親が go を出せます。これは「2 本が準備完了後に解放された」という最重要前提を壊します。

修正提案: 子ごとに `ready-<nonce>-1`、`ready-<nonce>-2`、`out-<nonce>-1.json`、`out-<nonce>-2.json` を持たせ、親は両方の ready を確認してから単一の go を作成してください。ready 内容には nonce・child ID を入れ、親が一致検証するべきです。

[Critical] `in_progress` の 409 を安定して観測するための「本当に重なった」保証がありません。共通 go により同時解放しても、OS スケジューリング次第で勝者が本処理を完了した後に敗者が到着し、409 ではなく完了済み応答になる可能性があります。これはテストの不安定化だけでなく、「並行性を検証した」という主張を偶然に委ねます。

修正提案: アプリコードを変更せず、子プロセス起動時にのみ有効なテスト支援の注入点を用意し、claim 成功後・本処理完了前に勝者を明示的に待機させてください。その間に敗者が claim へ到達したことを確認してから解放します。注入点は production では登録されず、既存 production middleware 経路を通ること、アプリ側の共有ロックを導入しないことをテストで固定してください。これが難しいなら、409 を必須成果にする現在の主張は成立しません。

[Warning] 要素 (3) の「array store を渡す」だけでは、共有ロック不在の証明として弱いです。設定値と実際に解決された store が食い違う退行を検出できません。

修正提案: 子が JSON 観測として、設定値だけでなく解決済み cache store の具体クラス／driver と、プロセス間共有にならないことを示す値を返し、親が両子について検証してください。

[Warning] 締切超過時の子プロセスの停止・reap・一時ファイル削除が明文化されていません。特に paratest 下で子が残ると接続やロックが残り、以後のテストを汚染します。

修正提案: timeout 時に terminate、必要なら kill、必ず wait/reap を行う順序を定義し、全経路で `finally` による cleanup を保証してください。失敗経路テストには「ready が来ない」「go 後に出力が来ない」「不正 JSON」「子の異常終了」に加え、回収が実行されることを含めてください。

## 3. 開発 DB 保護・実現可能性

[Critical] 「専用 env ファイルだけを設定の出所にする」という主張に、Laravel がチェックアウト内の `.env` を読まない具体的な仕組みが示されていません。`env -i` は親の環境を消すだけで、子が Laravel bootstrap 時に `.env` を読むことまでは防ぎません。また `DB_DATABASE` と `DB_URL` だけを固定しても、host・port・user・password・connection の経路が残ります。

修正提案: framework bootstrap より前に、以下を明示してください。

- `.env` / `.env.testing` を発見・読込できない起動位置または環境ファイル指定にする。
- DB 接続種別・host・port・database・user・password・URL の全座標を専用 env で固定する。
- `DB_URL` は空文字固定、かつ child が実効接続設定を解決する前に既存の test DB 安全判定を実行する。
- provider 登録前に fail-fast する位置を定義する。
- 親・子双方で、実効 DB 名だけでなく接続先全体を検査する。

「子へ渡す DB 名が test DB」という事実だけでは、開発 DB 接続防止の根拠として不足です。

[Warning] `OutOfTransactionFixtures` が既定接続を一時差替えして Factory を動かす方式は、既存 Eloquent 接続・RefreshDatabase の transaction・接続キャッシュとの相互作用を起こし得ます。

修正提案: 元の既定接続名・設定・解決済み connection を保存し、別名接続を purge/reconnect した上で利用し、finally で確実に復元・disconnect してください。可能ならモデル／Factory 単位で別名接続を指定できる Laravel の標準経路を優先し、グローバルな既定接続差替えを最小化してください。

## 4. 期待効果の妥当性

[Warning] 「claim の commit と別接続からの可視性を埋める」は、409 を観測できるようにしただけでは完全には言い切れません。検証対象は「先行 claim が未完了として他接続から可視であり、unique 制約で後続が拒否されること」です。

修正提案: 観測の主張を明文化してください。

- 2 子が go 後に request を開始した。
- 合計 handler 実行数は 1。
- 敗者は completed ではなく in-progress 応答を受けた。
- 子の cache store は両方 array である。
- DB の idempotency 行は cleanup 前に期待状態である。

これらを同時に満たす場合だけ、並行 claim の実証と呼べます。

## 5. リスク

[Critical] PostgreSQL 接続・ロック枯渇への対策が「接続を最小にする」だけで、異常時の残存対策と並列実行時の上限管理が不足しています。

修正提案: 子を各 1 接続に抑えることに加え、timeout 後の強制回収を必須化してください。さらに paratest worker ごとに一度だけ走るのか、各 worker で同じ重いテストが走るのかを明確化し、後者なら想定同時子数を算出して PostgreSQL の設定余力を事前確認してください。

## 6. スコープの適切さ

[Suggestion] 実プロセス版を 1 本に絞り、既存 3 本を残す判断は正典 (6) に適合しています。`FakeWiringProbeRunner` との共通化をしない判断も、DB 接続の有無が安全境界になるため適切です。

[Warning] D7 の再判定を「docs 側に記録する」だけでは、どの登録簿のどの判断を更新するかが曖昧です。

修正提案: `docs/template-divergence.md` の D7 に、再判定日・「このハーネスは preview 上限の実証を行わない」理由・次回再判定条件を明記してください。D7 を完了扱いにしないことも明示すべきです。

## 7. 型安全性

[Warning] `ConcurrentProbeObservation` を型付き値にする方針はよいですが、`phpstan.neon` が `tests/` を解析しないため、これだけでは PHPStan level 10 による保証にはなりません。また probe JSON は外部入力なので、配列キャストだけでは型安全ではありません。

修正提案: `ConcurrentProbeObservation::fromDecodedJson(mixed $value)` のような fail-closed factory を設け、必須キー、厳密な scalar 型、child ID、nonce、HTTP status、handler count、cache 観測値を全検証してください。不正・欠損・余計な構造があれば例外にしてください。これは JsonResource の対象ではなくテスト支援プロトコルなので、DTO 相当の値オブジェクトとして扱うのが適切です。

[Suggestion] probe の JSON 出力は HTTP endpoint の `response()->json()` ではないため、`fwrite(STDOUT, ...)` または個別 out ファイルを使う限り禁止事項には抵触しません。

結論として、設計の方向性とスコープは正しい一方、**子別 ready の厳密化、決定的な重なりの作成、Laravel bootstrap 前からの DB 座標遮断、timeout 時の子回収**を設計に追加してから実装へ進むべきです。