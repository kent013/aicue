# 全体判定: CHANGES_REQUESTED

中心仮説である「二段判定により残高を保存しつつ、繰越行が収束・有界化する」は妥当です。ただし現設計では、失効した繰越行だけが残る組織を処理できず、監視上も期限超過ゼロと誤判定します。最優先不変条件である残高保存そのものはよく設計されていますが、保持期限の完結性とセキュリティ gate への登録に修正が必要です。

## 施策 1: REQUEST_CHANGES

- [Critical] 失効済み繰越行だけを持つ組織が永久に処理されません。

  `organizationsWithExpiredDetails()` は `kind != carry_forward` の組織しか列挙しません。一度作られた繰越行が後日 `expires_at <= now` になっても、同組織に期限超過の取引明細がなければ `carryForwardOrganization()` 自体が呼ばれず、`expiredScope()` に到達しません。「失効窓の有界化」という説明と N4 を満たせない実装です。

  修正案は、次の決着対象を共通 scope にすることです。

  ```text
  created_at <= threshold
  AND (
      kind != carry_forward
      OR (
          kind = carry_forward
          AND expires_at IS NOT NULL
          AND expires_at <= now
      )
  )
  ```

  組織列挙、`countExpired()`、処理後の `expiredRemaining` がこの scope を共有する構造にしてください。寄与中の繰越行は除外し、失効して物理削除対象になった時点で対象へ戻します。

- [Critical] 主キー取得 gate の検出漏れを利用する形になっています。

  `Organization::withTrashed()->whereKey(...)` が `PrimaryKeyStaticQueryScanner` で 0 件になるという実測は、安全性の根拠ではなく既存 gate の盲点です。これはクラス起点の主キー取得なので、AGENTS.md の direct-fetch 不変条件上は分類が必要です。

  検出可能な構文へ寄せ、移設後のパスを既存 `DirectFetchInventory` に理由付きで登録してください。走査器側を変更する場合は、負例・未解決形・空振り検査・保証範囲 docblock の4点も同じPRに含める必要があります。

- [Warning] 「組織行ロックが二重繰越を構造で防ぐ」という説明は範囲が広すぎます。

  carry-forward 同士やロックを取る残高操作は直列化されますが、設計自身が記載しているとおり `grantMonthly` などの insert は同じロックを取りません。これらに対する保護は件数照合とトランザクション rollback です。

  修正案として、ロックが保証する経路と、件数照合で検知する無ロック insert を分けて記述してください。

- [Warning] 収束短絡は N3 では実行されません。

  2回目は取引明細がなく、組織列挙の段階で除外されるため、`rowCount === 1 && carryForwardRows === 1` の分岐へ到達しません。別集約キーに期限超過明細を置いて組織を選択させ、既存の繰越行のIDが維持されるテストが必要です。

## 施策 2: REQUEST_CHANGES

- [Warning] `natural()` は宣言した入力契約より緩く、overflow 時に fail-closed になりません。

  `Assert::integerish()` は整数相当の float や指数表記を受理し得ます。また PHP `int` へのキャストを先に行うため、PHP整数範囲を超える件数を正しい値として扱えません。

  修正案は、件数にも整数専用の正規化関数を用意し、`int` または10進数字列だけを許可して、PHP `int` の範囲を変換前に検査することです。少なくとも bool、float、指数表記、空白付き文字列を拒否してください。

- [Warning] 集約結果間の不変条件が不足しています。

  SQL上は成立するはずですが、DTO境界では次を検証すべきです。

  ```text
  rowCount >= 1
  0 <= carryForwardRows <= rowCount
  ```

  これにより、壊れた集計結果が収束判定へ流れることを防げます。

- [Warning] `fromRow(object)` と `propertyExists()` の組み合わせは契約が広すぎます。

  private property を持つ任意 object では `propertyExists()` が true でも `get_object_vars()` に値が現れない場合があります。実入力が `stdClass` なら、引数を `stdClass` に狭めるのが適切です。任意 object を許容するなら、配列化後にも `Assert::keyExists()` が必要です。

## 施策 3: REQUEST_CHANGES

- [Critical] `expiredRemaining` の定義と物理削除対象が矛盾しています。

  docblock は「物理削除または不可逆な明細除去の対象」を決着対象と定義しながら、すべての繰越行を一律除外しています。しかし `expires_at <= now` になった繰越行は継続状態ではなく、施策1で物理削除する対象です。

  修正案は、「寄与中の繰越行だけを除外し、失効済み繰越行は決着対象に含める」と定義することです。そのうえで次を固定してください。

  - 寄与中の繰越行だけなら `countExpired() === 0`
  - 失効済み繰越行だけなら `countExpired() === 1`
  - purge 後は `countExpired() === 0`

  全繰越行を監視対象外にするなら、失効済み繰越行専用の別カウンターと publication-ready 条件が必要ですが、共通 scope に含める方が単純です。

## 施策 4: REQUEST_CHANGES

- [Critical] 「デプロイ順序の制約はない」は誤りです。

  migration を先に適用して旧 v0 コードを動かすと、旧コードは `carried_forward_through` を SELECT・INSERT するため `Undefined column` になります。安全なのは新コード先行、drop migration 後行だけです。

  修正案として、次を明記してください。

  - デプロイ順序は「新コード → drop migration」
  - drop 後に旧コードへ単純 rollback できない
  - rollback が必要なら、先に down migration で列を戻してから旧コードへ戻す
  - migration-first の基盤なら maintenance window またはデプロイ手順の変更が必要

  migration の docblock、runbook、実装モードの記述を同時に訂正してください。

- [Warning] `down()` が値を復元しない点は適切に説明されていますが、旧コードを再稼働させる場合、null 復元で意味が完全には戻らないことも rollback 手順に明記すべきです。

## 施策 5: REQUEST_CHANGES

- [Critical] TLM-5 の現在の契約では、実際の変更操作すべてがトランザクション内にあることを証明できません。

  `save()` は `appendCarryForward()` に分離されています。`carryForwardOrganization()` 内の `delete()` とロック順だけを見ても、将来 `appendCarryForward()` 呼び出しが transaction closure の外へ移動した変更を見逃す可能性があります。

  修正案として、少なくとも次を検査してください。

  - transaction closure の中にロックがある
  - ロック後に2つの delete 呼び出しがある
  - `appendCarryForward()` 呼び出しも同じ closure 内かつロック後にある
  - transaction の外に同じ変更呼び出しがない

  「append 呼び出しだけ transaction の外へ移す」負例も追加してください。

- [Warning] `Organization::query()->withTrashed()` へ変更した場合に、「同じファイルに `Organization::query()` が存在する」だけで受け手を認定する案は fail-open です。

  無関係な `Organization::query()` と別モデルの `withTrashed()` が同居しても通ります。受理する構文を一つに固定するか、チェーンの起点まで解決できる走査を実装してください。解決できないチェーンは未解決として gate を落とす必要があります。

- [Warning] TLM-3 の説明は「台帳モデルまたは表名の候補ファイルにおいて削除語彙を持つのは1ファイル」と明記してください。app 全体の削除語彙を対象にするようにも読めます。

## 施策 6: APPROVE

移設による phantom/missing の解消と、`DataTransferObjects/Billing` への走査域拡張は目録の目的に合っています。内部集約 DTO を `aggregate` に分類する判断も妥当です。

## 施策 7: REQUEST_CHANGES

- [Critical] 「失効済み繰越行だけが残った組織」の回帰テストがありません。

  次のテストを独立して追加してください。

  1. 古い明細から、将来失効する繰越行を作る
  2. 時刻を失効後へ進める
  3. 同組織には取引明細が一件もない状態で再実行する
  4. 繰越行が物理削除される
  5. `candidates`/`expiredRemaining` が修正後の定義どおりになる

  N4の初期明細を削除するだけでは、今回の列挙漏れは検出できません。

- [Warning] N3 は v0 でも緑になります。

  v0 は初回の繰越行に `created_at = now` を設定するため、同じ過去閾値の2回目では候補になりません。したがって「v0で赤になるテストファーストの起点」にはなりません。

  N3は回帰テストとして残しつつ、短絡分岐を検証する別テストを追加し、短絡条件を一時的に壊して赤を確認してください。

- [Warning] 時刻境界を扱うテストは時計を固定してください。

  サービス内の `$now`、`ledgerBalanceByGroup()`、`balance()` が別々の現在時刻を取ると、実行中に失効境界を跨いだ場合に残高保存テストが不安定になります。`CarbonImmutable::setTestNow()` 相当を各テストで局所的に設定し、後始末も保証してください。

- [Warning] DTO修正に合わせ、次のケースも挙動テストへ追加してください。

  - 失効済み繰越行の削除失敗は `unexpectedFailures` に現れる
  - その場合、他組織は処理される
  - publication-ready が誤って true にならない

## 施策 8: REQUEST_CHANGES

- [Warning] DTOの入力契約を固定する負例が不足しています。

  次を追加してください。

  - `row_count = 1.0`
  - `row_count = '1e3'`
  - `row_count = true`
  - PHP整数範囲を超える `row_count`
  - `row_count = 0`
  - `carry_forward_rows > row_count`
  - `carry_forward_rows < 0`

  施策2で引数を `stdClass` に狭めるなら、テスト入力も明示的に `stdClass` を使ってください。

## 施策 9: REQUEST_CHANGES

- [Critical] 「append-only の例外は畳み込み1ファイルだけ」という規約案は現行実装と矛盾します。

  `TicketLedgerService` には `payment_intent_id` の backfill UPDATE があり、目録案自身もその変更経路を登録しています。したがって「変更の例外が畳み込みだけ」は事実ではありません。

  修正案として、次のように分けてください。

  - 行の物理削除・残高スナップショットへの置換を許すのは carry-forward サービスだけ
  - 台帳の通常追記と既存の限定 backfill は `TicketLedgerService`
  - 許容される変更サイトの正本は mutation inventory
  - 削除語彙の許容は carry-forward 1ファイルだけ

- [Critical] デプロイ順序の文書も施策4に合わせて訂正が必要です。「順序制約なし」を architecture/runbook/migration のいずれにも残さないでください。

- [Warning] 最終検証コマンドが AGENTS.md の必須一覧を満たしていません。

  詳細設計の段12には次が不足しています。

  - `pnpm build`
  - `pnpm typecheck:packages`
  - `pnpm build:packages`
  - `pnpm test:packages`

  AGENTS.md のマーカー内にある全コマンドを実行対象に戻してください。

- [Suggestion] 新規作成する `mutation-evidence.md` も施策一覧と変更ファイル一覧に明記すると、レビュー時の変更漏れを防げます。

設計の骨格、特に「集計と削除の件数一致」「int4変換前検査」「論理削除済み組織を含める」「繰越行の `created_at` を最大値にする」は良好です。上記の中心修正は、決着対象 scope を一つに定義して、組織列挙・件数・処理・監視で共有することです。