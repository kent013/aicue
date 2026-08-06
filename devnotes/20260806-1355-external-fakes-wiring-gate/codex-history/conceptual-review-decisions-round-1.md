# 対応マトリクス: conceptual-review Round 1

## [Critical] 同一 container 上での「flag off → provider 再実行 → real に戻る」は成立しない
- 判断: **対応する** (指摘は正しい)
- 根拠: `FakeExternalsServiceProvider` は flag off なら early return するだけで、既に上書きされた
  binding を巻き戻さない。`bind()` の巻き戻し API も無い。往復を同一 app の provider 再実行で
  見る設計は偽レッド/偽グリーンの温床。
- 対応内容: §4 施策 1 の「往復」行を全面書き換え。
  1. container binding の復元は **fresh Application 単位**で見る
     (`$this->refreshApplication()` = 再 bootstrap で config も binding も素の状態に戻る。
     Architecture lane は `RefreshDatabase` を使わないため mid-test の refresh が安全)。
  2. 「fake を触ったテストの後で real に戻る」は **Pest がテスト毎に app を作り直す**という
     フレームワーク保証に乗せ、gate 側は「fake を install する test case の**後続**に
     対照 test case を置く」のではなく、**test case ごとに独立した app で対照を取る**形にする
     (テスト順序に依存させない)。
  3. 真に往復が必要なのは **static (`Prompt::$fake`)** と **route collection** の 2 つだけ。
     ここだけ明示的な復元検査を持つ。

## [Critical] static / config / env / route / container の同一プロセス内リーク
- 判断: **対応する**
- 根拠: Architecture lane には `StrayLlmCallGuard` も `RefreshDatabase` も無い (`tests/Pest.php`)。
  LLM fake の install がリークすると他 Architecture テストを壊す。
- 対応内容: §9 制約に「gate 専用の `beforeEach` / `afterEach` を**このテストファイル内に限定して**
  定義し、`afterEach` で `Prompt::stopFaking()` を必ず実行する」「env/config を書き換える
  test case は try/finally で原値復元し、加えて `refreshApplication()` で締める」を追加。
  storage signed route は provider が `Route::has()` で冪等化済みだが、gate 側でも
  「route 二重登録が起きないこと」を 1 assertion で見る。

## [Warning] 禁止事項 3 の参照ずれ (dev DB 破壊操作であって既存テスト改変禁止ではない)
- 判断: **対応する** (事実誤認)
- 根拠: AGENTS.md 禁止事項 3 は dev DB 破壊操作。app-design スキル側の禁止事項表 #3 が
  「既存テストの削除・上書き」であり、番号を混同した。
- 対応内容: §6.4 の理由を「既存 Feature テストは振る舞い回帰として残し、Architecture 側を
  不変条件の正本にする (AGENTS.md 禁止事項 1 = 不変条件は Architecture/Feature テストへの
  登録まで含めて実装済み)」へ書き換え。番号参照をやめる。

## [Warning] テストファーストの成功判定が曖昧 (新 gate は実装前に素で赤にならない)
- 判断: **対応する**
- 根拠: 指摘どおり。新規 gate は「穴を塞ぐ」類なので素の main では緑になる。
  fail を先に見るには mutation が要る。
- 対応内容: §1 成功判定と §8 を書き換え、**mutation を受入条件へ格上げ**。
  「実装前に mutation を当てて現行検査が緑のままであること (= 穴の実在確認)」→
  「gate 追加後に同じ mutation で赤になること」の 2 段を必須手順にする。

## [Warning] `production` env 一時差し替えの意味が揺れる
- 判断: **対応する**
- 根拠: `$app['env']` の差し替えは既に走り終わった `AppServiceProvider::boot()` の
  `ProductionEnvGuard` を再実行しない。app 全体の production 相当 boot ではない。
- 対応内容: allowlist 外の検査を「**provider 単体の条件分岐検査**」と明示し、テスト名にも
  その限界を出す (`provider 単体: flag=true でも allowlist 外 env では bind しない`)。
  本番混入防止そのものは `ProductionEnvGuard` + `ProductionEnvGuardTest` の担当と
  責務境界を書く。

## [Warning] `bind(A,B)` だけの走査では singleton / instance / extend / contextual に漏れる
- 判断: **対応する** (これが網羅性 gate の要)
- 根拠: 将来 `singleton` へ変えたら inventory 突合をすり抜ける。
- 対応内容: 網羅性走査を 2 条件に強化する。
  1. `FakeExternalsServiceProvider` 内の `$this->app-><api>(` 呼び出しのうち、
     container 差し替え系 API (`bind` / `bindIf` / `singleton` / `singletonIf` / `scoped` /
     `scopedIf` / `instance` / `extend` / `when` / `alias` / `resolving` / `afterResolving`) を
     **すべて検出**し、`bind` 以外が使われていたら fail (= 差し替え API を `bind` に固定する規約)。
  2. `bind(A::class, B::class)` の (A, B) 組が inventory と**集合一致**すること。
  これで API を変えた瞬間に赤くなるので、網羅性の抜け道が塞がる。

## [Warning] interface 系も厳密クラス一致に統一せよ
- 判断: **対応する** (元々そのつもりだが明記が弱かった)
- 対応内容: §2.3 / §4 に「inventory 全 entry で `$resolved::class === $expected` を唯一の判定にする
  (`toBeInstanceOf` は使わない)」と明記。

## [Warning] `--parallel` より test order 依存が主リスク
- 判断: **対応する**
- 対応内容: §8 の検証に「Architecture suite を 2 回連続実行」「`--order-by=random` で実行」を追加。

## [Warning] 柱 3b を外す理由が弱い / 後続 TODO の発火条件を明示せよ
- 判断: **対応する**
- 対応内容: §6.2 に発火条件 3 つ (production で fake flag の incident / near-miss が出た /
  config cache 前提の deploy 手順を変更した / 外部 fake flag が増えた) を明記。

## [Warning] inventory が「一覧を満たすだけの台帳」に形骸化するリスク
- 判断: **対応する**
- 対応内容: inventory entry に `risk` (なぜ外部副作用として危険か) と
  `mutation` (この entry の bind を消すとどの検査が赤になるか) の 2 フィールドを追加し、
  詳細設計の mutation 手順と 1:1 対応させる。

## [Suggestion] 柱 2 を外す判断は妥当 / 使命への貢献は十分
- 判断: **見送る** (指摘なし。現状維持)
