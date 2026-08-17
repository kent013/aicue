# 対応マトリクス: conceptual-review Round 1

Codex 判定: **APPROVED** (Critical 0 / Warning 7 / Suggestion 7)。
APPROVED のため追加ラウンドは回さないが、Warning は全件を捌いて記録する。

## [Warning] OR 条件の括り漏れ → テナント境界破壊。別 project / 別 status / 別 creator の
ヒット語を持つデータで固定せよ
- 判断: **対応する**
- 根拠: 本改善で最も危険な失敗様式であり、概念設計でも「最も危険」と書いた箇所である。
  指摘は固定すべきデータの粒度 (別 project だけでなく **別 status・別 creator** も) を
  具体化しており、そのまま採る。`mine` / `progress` / PWA の ready/published 制限は
  すべて `orWhere` に外へ押し出されうる。
- 対応内容: 概念設計の「実装方針 4」に、テストで固定する 3 つの母集団条件
  (project / status / created_by) を明記した。詳細設計のテスト計画で 3 本のテストに落とす。

## [Warning] `ManualKeywordSearch::apply()` の Builder 型を PHPStan level 10 で通る形に寄せよ
- 判断: **対応する**
- 根拠: 呼び出し元が 2 種類ある。PC は `$project->manuals()` =
  `HasMany<VideoManual, Project>`、PWA は `->when()` のクロージャ引数
  `Illuminate\Contracts\Database\Eloquent\Builder` である。同じ関数で両方を受けるには
  型の置き方を決める必要があり、決めずに実装に入ると level 10 で必ず詰まる。
- 対応内容: 詳細設計で受け型を
  `Illuminate\Database\Eloquent\Builder<\App\Models\VideoManual>` に統一し、
  PWA 側の `when()` クロージャの型注釈もそれに合わせる (現行 PWA が
  `Contracts\...\Builder` を使っている点を含めて詳細設計で扱う)。

## [Warning] 「200 字切り詰めは広く当たるだけ」は UX 副作用を過小評価している
- 判断: **対応する**
- 根拠: 正しい。title (max 200) だけを対象にしていた頃は「201 文字目以降は一致に寄与しない」が
  **事実**だったが、narration / subtitle_secondary は max 2000 なので、
  201 文字目以降に意味のある語があるケースが原理的に発生する。
  「広く当たる方向にしか倒れない」は集合としては正しい (絞り込みが緩む) が、
  利用者から見れば「打った語と違う条件で検索された」ことに変わりはない。
- 対応内容: 概念設計の「改善アイデア 3」の根拠を「負荷制御のための上限であり、
  長文を貼ると先頭 200 文字で検索される」と**正当化せずに事実として書く**形へ改めた。

## [Warning] `EXISTS` の相関条件の書き方で索引効果が変わる。`video_manual_id` index を追加せよ
- 判断: **一部対応する / 一部反論する**
- 根拠: index 追加は既に設計に入っており同意。
  一方「相関条件を先頭に置く」は **PostgreSQL では意味を持たない** —
  プランナは WHERE 句の記述順で駆動表や索引を選ばない (順序依存があるのは
  一部の他 DBMS のヒント文化)。`whereHas` が生成する SQL では相関条件は自動的に
  副問い合わせの最初の条件になるため、そもそも書き分けの自由度も無い。
  誤った理由付けを設計へ残すと、後続が「順序を守っているから速いはず」と誤読する。
- 対応内容: index 追加は採用済み (概念設計「性能の見立て」)。
  条件の記述順に関する記述は**設計へ書かない**。代わりに
  「プランナが相関 nested-loop を選んだ場合に index が効く / semi-join を選んだ場合は
  cuts 側 1 回走査になる」という**両方の実行計画を許容する**書き方にした。
  「少量でも複数 manual/cut の結果正しさをテスト」は採用し、テスト計画へ入れる。

## [Warning] PWA が `.get()` で全件返す既存仕様は将来の性能リスク。Conditional を足せ
- 判断: **対応する**
- 根拠: 本改善の原因ではないが、キーワードで絞れる面が増える一方で無検索時の全件返却は
  残る。見立てを堅くするための Conditional 登録は安価で、思考原則 2 にも反しない
  (今作るのではなく引き金を書くだけ)。
- 対応内容: 概念設計「性能の見立て」の Conditional に PWA 一覧のページング条件を追加した。

## [Warning] T053 の台帳不整合を実装タスク完了時に放置するな
- 判断: **対応する**
- 根拠: 正しい。設計書だけに残すと、次に検索を触る担当が再び「T053 で実装済み」と誤認する。
  本タスクは `docs/TODO.md` を編集できないが、`docs/TODO-closed.md` への訂正注記は
  実装タスクの作業として指定できる。
- 対応内容: 概念設計に「実装タスクの完了条件」として
  `docs/TODO-closed.md` L71 の T053 行への訂正注記を**必須の施策**として明記した。

## [Warning] `normalize()` は文字数で切ること。200 文字か 200 bytes かをテストで固定せよ
- 判断: **対応する (ただし現行実装は既に正しい)**
- 根拠: `ManualListQuery::fromRequest()` は既に `mb_substr(trim($keyword), 0, 200)` を使っており
  文字数で切っている。既存テスト
  `tests/Feature/Projects/ProjectShowManualsTest.php`「q は先頭 200 文字で絞り込む」も
  `str_repeat('あ', 200)` で**文字数**を固定済み。
  ただし **PWA 側には上限も trim も無く、テストも無い**ため、共通化後に
  PWA 側でも同じ契約をテストで固定する必要がある。
- 対応内容: 詳細設計のテスト計画に「PWA でも先頭 200 **文字** (bytes ではない) で切られる」
  テストを入れる。

## [Suggestion] 7 件 (使命整合 / scene 採用 / 作成者名検索を作らない判断 / PC・PWA 共通化 /
placeholder 変更 / スコープ / DTO 影響)
- 判断: **いずれも設計の追認**であり変更不要。対応なし。
