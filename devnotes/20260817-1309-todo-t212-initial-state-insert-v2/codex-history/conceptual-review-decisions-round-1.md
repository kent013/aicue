# 対応マトリクス: conceptual-review Round 1

Codex 全体判定: **APPROVED** (Critical 0 / Warning 4 / Suggestion 4)。
APPROVED のため Round 2 は起こさず、Warning 4 件を概念設計へ反映して Phase 2 へ進む。

## [Warning] 母集団の (b) 「所有する Eloquent モデルが BackedEnum cast を宣言している列」が曖昧

- 判断: 対応する
- 根拠: 指摘のとおり、対応の取り方を書かないと「実スキーマ起点」のつもりが
  「見つけられたモデル起点」に寄り、i5 (母集団は実スキーマから取る) の主張が弱くなる。
- 対応内容: 概念設計の「実装方針」へ 4 点を明文化した —
  (1) モデルの母集団は `app/Models` 配下の具象 Eloquent クラス全数、対応は `getTable()` から取る、
  (2) 同じ表を指すモデルが複数あるときは cast 宣言の**和集合**を取る (見落としの出ない側へ倒す)、
  (3) cast は `getCasts()` (`$casts` と `casts()` を枠組みが畳んだ結果) から取り、
  `enum_exists()` かつ `BackedEnum` の実装だけを採る、
  (4) モデルを持たない表は (b) が空になることを保証しない範囲として明記する。
  詳細設計では抽出を純関数に切り、合成入力の負のコントロールで固定する。

## [Warning] `created_at` / `updated_at` を列名の文字列一致で外すのは危うい

- 判断: 対応する
- 根拠: 列名だけで外すと、custom timestamp 名を持つモデルや同名のドメイン列で誤分類しうる。
  除外は「枠組みが必ず書く列」という**意味**に紐づけるべきである。
- 対応内容: 除外の条件を「そのモデルが `usesTimestamps()` で、列名が
  `getCreatedAtColumn()` / `getUpdatedAtColumn()` と一致するとき」に限定した。
  モデルを持たない表だけは枠組みの既定名との一致で外し、**その件数を完全一致で pin** する
  (除外が無音で広がらない)。`deleted_at` は除外しないことも明記した。
  詳細設計では NI-8 (除外件数の pin) と NC-4 (custom timestamp 名の列が母集団へ残る負のコントロール)
  として機械化する。

## [Warning] 保証範囲が概念設計本文だけ読むと広く読める

- 判断: 対応する
- 根拠: i6 が求めるのは「保証しない範囲を明文で持つ」ことであり、誤読を招く書き方は
  その趣旨に反する。
- 対応内容: 概念設計へ「保証しない範囲 (概念の段階での明示)」節を追加し、
  母集団が時刻型と BackedEnum cast 列に限られること、nullable な文字列・数値・外部キーで
  状態を表す列 (`funding_choice` / `output_path` / `adopted_take_id`) には沈黙することを
  実名で書いた。正本は gate の docblock に置き、`docs/architecture.md` は複写せず参照する
  (二重管理を作らない)。

## [Warning] 手書き 59 行の台帳は型の崩れ・キーの取り違えを防ぐ作りが要る

- 判断: 対応する
- 根拠: PHPStan の走査根に `tests` が入っていない以上、型の局所化と実行時検査で堅くするのは妥当。
  提案 4 点はいずれも既存の家系の作法 (`RetentionTableEntry`) と同じ形である。
- 対応内容: 詳細設計で
  (1) `NullableStateColumnEntry` を `final readonly` + private constructor + 区分ごとの
  名前付き生成子にする、
  (2) 根拠 30 文字以上をコンストラクタでも検査する (gate の規則とは別に、台帳を作る時点で落とす)、
  (3) 集合比較のキーは `NullableStateColumnEntry::key()` (`表名.列名`) の 1 メソッドに寄せ、
  gate 側で文字列連結を書かない、
  (4) 台帳は**連想配列にせず並びのまま返し**、二重宣言の検出は gate の純関数が行う
  (`RetentionTableRegistry` と同じ理由 = 後の 1 件で上書きされる形の消失を防ぐ)
  ことを施策として明記する。

## [Suggestion] 使命との整合・期待効果・スコープ・禁止事項

- 判断: 対応不要 (肯定的評価)
- 根拠: 指摘なし。
