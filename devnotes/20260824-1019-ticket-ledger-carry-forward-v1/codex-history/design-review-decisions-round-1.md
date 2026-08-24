# 対応マトリクス: design-review Round 1

## 施策 1

### [Critical] 失効済み繰越行だけを持つ組織が永久に処理されない

- 判断: **対応する** (指摘が正しい。設計の中心的な穴だった)
- 根拠: `organizationsWithExpiredDetails()` が `kind != carry_forward` で組織を絞るので、
  繰越行が後日 `expires_at <= now` になっても、同組織に期限超過の取引明細が無ければ
  `carryForwardOrganization()` が呼ばれず `expiredScope()` に到達しない。
  「失効窓の有界化」が成立しない。
- 対応内容: **決着対象 (settlement scope) を 1 つの述語に定義し、
  組織の列挙・件数・処理・監視の 4 か所で共有する**。

  ```
  created_at <= 閾値
  AND ( kind != carry_forward
        OR (expires_at IS NOT NULL AND expires_at <= now) )
  ```

  実装は `settlementScope($threshold, $now)` と `settlementPredicate($query, $now)` の 2 つに切り、
  `whereHas` からも同じ述語を使う。`carryForward()` は自分が確定した `$now` を
  候補数と残数の両方へ渡す (実行中に時計が進んでも母集団がずれない)。
  `countExpired()` は purger の署名を保つため `$now` を受け取らず、呼び出し時点の現在時刻で判定する。

### [Critical] 主キー取得 gate の検出漏れを利用する形になっている

- 判断: **反論する** (根拠を設計へ明記して残す)
- 根拠: これは走査器の盲点ではなく、`DirectFetchInventory` が**自分で宣言している母集団の定義**である。
  同クラスの docblock に
  「ノイズは走査器の provenance フィルタ (**識別子引数が解決済みモデル由来のものを外す**) で落とす」
  と書かれている。本経路の識別子は payload / route parameter / token claim ではなく、
  **同一実行内の列挙で解決済みの `Organization` モデルの主キー**であり、外部入力に由来しない。
  同じ形は本リポジトリの既存経路に多数あり、代表例は
  **`TicketLedgerService::lockOrganizationRow()`** で**目録に登録されていない**
  (現行 v0 の畳み込みも同様)。ここだけ登録すると目録の意味が
  「解決済みモデル由来も載せる」へ変わり、`app/` 全域の `->whereKey($model->getKey())` の
  洗い出しが付いてくる (本 feature の射程外。思考原則 2 に反する)。
  走査器の変更も同じ理由で行わない (変更すれば負例・未解決形・空振り・docblock の 4 点が
  付いてくるが、それは別 TODO の仕事である)。
- 対応内容: 施策 1 に「主キー取得 gate との関係 — 登録しない判断の根拠」節を新設し、
  (1) 識別子の出自が外部入力でないこと (2) 既存の同形の先例 (3) `withTrashed()` を足しても
  候補 0 件のままである実測 (4) **将来 id 起点へ書き換えるならその時点で登録が要る**ことを
  実装の docblock にも書く、を明記した。

### [Warning] 「組織行ロックが二重繰越を構造で防ぐ」は範囲が広すぎる

- 判断: 対応する
- 対応内容: サービスの docblock に「ロックが守る範囲を誇張しない」節を追加し、
  **ロックが直列化するのは同じロックを取る経路だけ** (畳み込み同士 / reserve・commit・release・grant) /
  **冪等 insert はロックを取らない** / **その窓を閉じるのは件数照合とトランザクションの巻き戻し** /
  **二重の繰越行を防ぐのは「同一トランザクション内で削除 → 追記」という順序**、と書き分けた。

### [Warning] 収束短絡は N3 では実行されない

- 判断: 対応する
- 対応内容: N3 を「回帰 (v0 でも緑になる)」と明記し、**N3b** を新設した
  (別の集約キーに期限超過の明細を置いて組織を列挙させ、既に繰越 1 行だけの集約キーの
  **id が不変**であることを見る。短絡条件を一時的に壊して赤を確認する手順も書いた)。

## 施策 2

### [Warning] `natural()` が緩く overflow で fail-closed にならない

- 判断: 対応する
- 対応内容: `Assert::integerish()` を**使わない**方針に変え、
  `decimalInt(mixed, property, positiveLimit, negativeLimit, message)` を共通の入口にした。
  `int` か `/\A-?[0-9]+\z/` に完全一致する 10 進文字列だけを受け、
  **PHP `int` へ変換する前に**上下限を 10 進文字列のまま比較する。
  件数は `PHP_INT_MAX` / `PHP_INT_MIN` を境界にしたうえで `Assert::natural`。
  bool / float / 指数表記 / 小数 / 空文字 / 前後空白はすべて例外。

### [Warning] 集約結果間の不変条件が不足

- 判断: 対応する
- 対応内容: `fromRow()` で `rowCount >= 1` と `0 <= carryForwardRows <= rowCount` を検査する
  (`Assert::greaterThanEq` / `Assert::lessThanEq` / `Assert::natural`)。

### [Warning] `fromRow(object)` + `propertyExists()` は契約が広すぎる

- 判断: 対応する
- 対応内容: 引数を **`stdClass`** に狭め、読み出しを `get_object_vars()` + `Assert::keyExists()` の
  2 段にした (private property の穴が構造的に消える)。
  呼び出し側 (`contributingGroups()`) で `Assert::isInstanceOf($row, stdClass::class)` を通す。

## 施策 3

### [Critical] `expiredRemaining` の定義と物理削除対象が矛盾

- 判断: 対応する (施策 1 の C1 と同一の是正)
- 対応内容: 共通定義を「**いま継続状態を表している**集約レコードは含まない」へ改め、
  **失効した繰越行は決着対象に含まれる**と明記した (除外すると fail-open になる理由も書いた)。
  固定するテストを 3 本に増やした
  (寄与中の繰越行だけなら 0 / 失効した繰越行だけなら 1 / 決着後は 0)。

## 施策 4

### [Critical] 「デプロイ順序の制約はない」は誤り

- 判断: **対応する** (指摘が正しい。旧コードは同列を SELECT / INSERT する)
- 対応内容: migration の docblock・施策 4 のリスク節・runbook の新節の 3 か所を
  「**新コード → drop migration** に固定 / drop 後に旧コードへ単純 rollback できない /
  戻すなら先に `down()` で列を戻す / migration 先行が避けられない基盤なら
  maintenance window か手順変更が必要」へ書き換えた。
  **順序の正本は runbook の手順節**とし、`docs/architecture.md` には順序を書かない
  (「順序制約なし」も書かない)。

### [Warning] `down()` の値非復元を rollback 手順にも明記

- 判断: 対応する
- 対応内容: runbook の新節に「`down()` は列を戻すが値は復元しない
  (既存の繰越行は終端が null として扱われる)」を書く。

## 施策 5

### [Critical] TLM-5 では変更操作すべてがトランザクション内にあることを証明できない

- 判断: 対応する
- 対応内容: TLM-5 を **5 条**に拡張した。
  (1) メソッド本体に `DB::transaction(` がちょうど 1 つ /
  (2) closure 内に `lockForUpdate(` /
  (3) closure 内に変更操作が 2 種類以上 (`delete(` 2 つ以上 + `appendCarryForward(` 1 つ) = 空振り検出 /
  (4) ロックが最初の変更操作より前 (語彙は変更語彙 ∪ `{appendCarryForward}`) /
  (5) closure の外側に変更操作が 1 つも無く、ファイル全体で `appendCarryForward(` の呼び出しは 1 件。
  負例に「**追記の呼び出しだけを closure の外へ移す**」を追加して 7 変異にした。

### [Warning] `Organization::query()->withTrashed()` の受け手認定案が fail-open

- 判断: 対応する
- 対応内容: **受理する構文を 2 形に固定**した。
  (A) `Organization::withTrashed()` (StaticCall の受け手が FQCN 解決) /
  (B) `Organization::query()->withTrashed(` の**トークン列そのものの一致**
  (`Organization` は import 表で `App\Models\Organization` に解決できること)。
  変数受け手・長い連鎖は**未解決として gate を落とす**。
  「同じファイルに `Organization::query()` が在る」を根拠にする案は撤回した。

### [Warning] TLM-3 の対象範囲の書き方

- 判断: 対応する
- 対応内容: 「**TLM-2 の候補ファイル**のうち削除語彙を持つのは 1 ファイルだけ
  (`app/` 全体の `delete(` を対象にするのではない)」へ明記した。

## 施策 6

- 判定: APPROVE。追加対応なし。

## 施策 7

### [Critical] 「失効済み繰越行だけが残った組織」の回帰テストが無い

- 判断: 対応する
- 対応内容: **N18** を新設した (指摘の 5 段をそのまま採用。
  繰越行は Factory で直に作らず**畳み込みの出力を使う**ことも明記した)。

### [Warning] N3 は v0 でも緑になる

- 判断: 対応する
- 対応内容: N3 を「回帰。テストファーストの赤の起点にしない」と明記し、
  短絡を検証する **N3b** を追加した。テストファースト手順の段 1 の一覧からも N3 を外した。

### [Warning] 時刻境界を扱うテストは時計を固定する

- 判断: 対応する
- 対応内容: 「時計の固定」節を新設し、`$this->freezeTime()` /
  `$this->travelTo(...)` (`InteractsWithTime`。テスト終了時に自動で戻る) を使うことと、
  既存の作法 (`$this->travelTo`) に揃えることを書いた。

### [Warning] DTO 修正に合わせた挙動テストの追加

- 判断: 一部対応する
- 根拠: 「失効済み繰越行の**削除失敗**」は stub を挟まないと作れない
  (DB レベルの delete を失敗させる手段が無い)。無理に作ると実装の内部へ結合したテストになる。
- 対応内容: **N19** として「失敗した組織があるとき publication-ready が誤って true にならない /
  他組織は処理される」を置き、**失敗の注入は範囲検査 (N8) で行う**。
  「DB レベルの削除失敗は再現しない」という限界をテストのコメントに書く。

## 施策 8

### [Warning] DTO の入力契約の負例が不足

- 判断: 対応する
- 対応内容: ケースを 18 → 26 に増やした
  (`row_count` の float / 指数表記 / bool / PHP 整数範囲超 / 0 /
  `carry_forward_rows > row_count` / `carry_forward_rows < 0` / 入力はすべて `stdClass`)。

## 施策 9

### [Critical] 「append-only の例外は畳み込み 1 ファイルだけ」が現行実装と矛盾

- 判断: 対応する
- 対応内容: AGENTS.md の規約案を 4 分割した
  (行の物理削除と残高スナップショットへの置換 = 畳み込み 1 ファイル /
  通常追記と限定 backfill = `TicketLedgerService` /
  許容される変更サイトの正本 = mutation inventory /
  削除語彙の許容 = 畳み込み 1 ファイル)。

### [Critical] デプロイ順序の文書も訂正

- 判断: 対応する (施策 4 と同一)
- 対応内容: 「順序制約なし」を architecture / runbook / migration のどこにも残さない。
  正本は runbook の新節。

### [Warning] 最終検証コマンドが AGENTS.md の必須一覧を満たしていない

- 判断: 対応する
- 対応内容: 段 12 を AGENTS.md のマーカー内の**全 10 コマンド**へ差し替えた
  (`pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` を追加)。

### [Suggestion] `mutation-evidence.md` を施策一覧へ

- 判断: 対応する
- 対応内容: 施策 10 として一覧へ追加し、テストファースト手順の段 13 に置いた。
  変異は 7 つ (決着対象から失効した繰越行を外す変異を追加)。
