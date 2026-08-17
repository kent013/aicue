# 対応マトリクス: design-review Round 1

Codex 全体判定: **CHANGES_REQUESTED** (Critical 1 / Warning 8 / Suggestion 1)。
施策 1 / 5 / 6 は APPROVE、施策 2 / 3 / 4 が REQUEST_CHANGES。

## [Critical] モデルを `new $fqcn()` でインスタンス化する設計は粗い。`newInstanceWithoutConstructor()` へ寄せるべき

- 判断: **反論する** (指摘の後半 = 提案する修正案は採れない。前半 = 壊れ方の扱いが粗いという
  指摘は正しいので、そちらは対応する)
- 根拠: vendor を実読した。Eloquent は `casts()` メソッドの戻り値を
  `HasAttributes::initializeHasAttributes()` の中で
  `array_merge($this->casts, $this->casts())` として `$this->casts` へ畳み込み、
  この初期化は `Model::__construct` → `initializeTraits()` からしか呼ばれない。
  `getCasts()` が返すのは `$this->casts` だけである
  (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php`
  の該当 2 箇所を実読)。
  本リポジトリの全モデルは `protected function casts(): array` で宣言する形なので、
  `newInstanceWithoutConstructor()` にすると **(b) の系統が一斉に空になり、母集団が静かに縮む**。
  これは i5 (空振りを合格にしない) がもっとも避けたい壊れ方であり、提案は本末転倒になる。
- 対応内容: 通常のコンストラクタを維持したうえで、指摘の趣旨 (壊れ方が粗い) を 3 点で受けた。
  (1) インスタンス化を `try`/`catch (Throwable)` で囲み、捕まえたら **FQCN と例外の型を出して
  その場で fail** する (握り潰して母集団を縮めない)、
  (2) **走査したモデルの件数と表名の一覧を NI-3 で pin** する (1 件落ちても赤くなる)、
  (3) この判断が Laravel 本体の実装に依存していることを検査の docblock に書き、
  **Laravel を更新したら人手で再確認する**と明記する (`ClaudeHooksWiringTest` と同じ作法)。
  vendor 実読の位置も設計に残した。

## [Warning] `mb_strlen()` の前提を明記するか先例と揃えるべき

- 判断: 対応する
- 根拠: 日本語の根拠を文字数で数えるので `mb_strlen()` が正しい。先例
  (`RetentionTableClassificationTest` の RC-3) も `mb_strlen()` である。
- 対応内容: 施策 2 に「`mb_strlen()` を使う。先例も同じ。`mbstring` は既存の検査が既に依存して
  いる実行環境の前提であり、本施策で新しい前提を増やすわけではない」と明記した。

## [Suggestion] `table` / `column` の空文字検査を constructor に入れる

- 判断: 対応する
- 根拠: 打ち間違いが「集合の食い違い」ではなく「読める失敗」になる。安い。
- 対応内容: コンストラクタの検査に追加した。

## [Warning] `subscriptions.ends_at` の `InitialStateMarker` 固定は危険

- 判断: 対応する (指摘が正しい)
- 根拠: `app/Services/Billing/SubscriptionService.php` を実読した。決済事業者の snapshot から
  `'ends_at' => $snap->endsAt` を含む形で行を upsert しており、**生成時に非 NULL がありうる**。
- 対応内容: 区分を `SetAtCreation` (外部が決めた値の写し) へ移し、実読の根拠を設計に書いた。
  時刻型の内訳は 初期状態の目印 30 → 28 / 生成時に決まりうる値 20 → 22 になった。

## [Warning] `ticket_reservations.consume_expires_at` は生成時に決まる期限列の可能性

- 判断: 対応する (指摘が正しい)
- 根拠: `app/Services/Billing/TicketLedgerService::reserve()` を実読した。
  「どの出所をどの期限で消費するか」を **予約行の INSERT 時に固定**しており
  (`consume_source` / `consume_expires_at` を `save()` の前に代入)、
  NULL は「無期限 monthly からの消費」を意味する。進行段階ではない。
- 対応内容: `consume_expires_at` と、同じ経路で同時に確定する `consume_source` (列挙側) の
  2 列を `SetAtCreation` へ移した。列挙 9 列の内訳は 初期状態の目印 6 → 5 /
  生成時に決まりうる値 3 → 4 になった。
  なおこの経路は v1 の「初期状態は INSERT 時に明示代入する」の準拠例そのものなので、
  その事実も設計に書いた。

## [Warning] `analysis_jobs.step` / `render_jobs.step` は「NULL が初期状態」か確認が要る

- 判断: 対応する (確認したうえで区分は据え置き)
- 根拠: `app/Services/Manual/AnalysisJobService::trigger()` を実読した。行を作るときに代入するのは
  `status` (= `Queued`) と関連だけで、**`step` には何も代入しない**。`step` に最初に値が入るのは
  `AnalysisPipeline` / `RenderPipeline` の進捗書き込みであり、NULL は「まだどの段階にも
  入っていない」を意味する。
- 対応内容: 区分は `InitialStateMarker` のまま据え置き、実読の根拠 (どのファイルの何を読んだか) を
  設計に明記した。

## [Warning] BackedEnum cast の検出仕様が不足している

- 判断: 対応する
- 根拠: `AsEnumCollection` / 引数付き cast / `Castable` / 裏付けの値を持たない列挙が
  区別されていないと、母集団が実装者ごとにぶれる。
- 対応内容: 対象を「文字列の cast で `enum_exists()` が真かつ `BackedEnum` 実装」だけに限ると
  明文化し、対象外の 5 形を実名で列挙した。**保証しない範囲にも同じ 5 形を書いた**。
  負のコントロール NC-7 で 5 形すべてが (b) に入らないことを固定する。

## [Warning] `getColumns()` の返却 shape に依存している (キー未定義で runtime failure になる)

- 判断: 対応する
- 根拠: i6 の「走査で証明できない受け手は適合と判定せず未解決として扱う」に照らして、
  未知の shape を黙って通す形も落ちる形も避けるべきである。
- 対応内容: 正規化の純関数を 1 つ置き、必要なキーが欠けている要素は
  **列名と実際のキー一覧を出して fail** する fail-closed 設計にした。
  負のコントロール NC-8 を追加した。

## [Warning] NC-3 は既定値の表現ゆれを検証できていない

- 判断: 対応する
- 根拠: 判定は「`default` が `null` でないこと」だけであり中身を見ないが、
  **それが本当に中身に依存していないこと**を代表値で示すのは安く、誤読も防ぐ。
- 対応内容: NC-3 を `now()` / `CURRENT_TIMESTAMP` / `'pending'` /
  `'pending'::character varying` / `0` / 空文字 の 6 代表値で点灯を確認する形へ広げた。

## [Warning] NI-7 にモデルを持つ表側の lifecycle 除外の pin が無い

- 判断: 対応する
- 根拠: 除外は片側だけ pin しても、もう片側から無音で広がる。
- 対応内容: NI-7 を「**モデル由来の件数**と**モデルを持たない表由来の列一覧 (完全一致)** の
  両方を現在値ちょうどで pin する」へ広げた。
