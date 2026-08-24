# 対応マトリクス: impl-review Round 2

## [Critical] `expires_at > now` → `>= now` は等価変異ではない

- 判断: **対応する (指摘が正しい)**
- 根拠: Round 1 で「等価変異」と結論したのは誤りだった。削除と集約は別 SQL 文であり、
  **組織行ロックを取らない追記経路** (`grantMonthly` / `grantPurchased`) が
  その間に commit しうることはサービス自身の docblock が明記している。
  その窓に `expires_at = now` の行が入ると `>` と `>=` で挙動が分かれる。
  N10 と同じ `DB::listen` 差し込みで固定でき、別 connection も barrier も要らない。
- 対応内容: **N1c** を追加 (失効 DELETE を観測した直後 = 集約 SELECT より前に境界行を差し込む)。
  - `>` (正) … 割り込んだ行は寄与側に入らず**手つかずで残る**
  - `>=` (誤) … 集約に取り込まれ**既に失効している繰越行**へ置き換わる
  実測で `>=` の変異が **N1c を赤にする**ことを確認した。
  N1b のコメントを「ここで赤になるのは削除枝の `<=` → `<` だけ / 寄与枝は N1c が担当」へ訂正。

## [Critical] `mutation-evidence.md` の「等価変異」の結論

- 判断: **対応する**
- 対応内容: 訂正の記録として残し (消さない)、「静止した fixture では観測できなかっただけ」
  「N1c が窓を作って固定する」へ書き換えた。変異数の表記も 7 → 9 へ。

## [Warning] 恒等式に「決着対象集合が実行中に変化しない」前提が要る

- 判断: **対応する (前者の選択肢 = 前提を明記)**
- 対応内容: DTO docblock / runbook §2 / 検証 1〜4・7 のコメントの 3 か所に
  「**想定外の失敗が 0 件で、かつ実行中に決着対象が増えていない**なら成り立つ」を明記。
  runbook の「崩れたら述語ずれ」を「**(a) 述語ずれ**か**(b) 実行中の母集団変化**の
  どちらかであり **(a) と断定しないこと**」へ修正した。

## [Warning] v0 行の「どの環境にも存在しえない」は断定が強い

- 判断: **対応する**
- 対応内容: runbook / migration docblock とも
  「**通常のアプリ経路では**生成されない (2033-06-11 以降)。
  **手動投入・DB 復元・古い `created_at` を持つ移行データは保証外**」へ狭めた。

## [Suggestion] migration と runbook の重複

- 判断: **対応する**
- 対応内容: migration docblock は前提と判断の要約 (4 行) だけにし、
  限界・自己修復・監視の詳細は runbook を正本と明記した。

## [Warning] gate が短名のみの候補を失敗させない

- 判断: **対応する**
- 対応内容: **TLM-2b** を新設 —
  「変更語彙を持つファイルのモデル参照が**短名一致だけで当たっている** (`fqcn=false`) なら
  曖昧として失敗させる」。これで登録済みファイルの本物の参照を同名の別クラスへ
  差し替える書き換えが exact-fit を通らなくなる。gate の docblock にも追記した。

## [Warning] `T_STATIC` を単独で closure として受理している

- 判断: **対応する**
- 対応内容: `startsClosure()` を切り出し、`static` の**直後**が `function` / `fn` である
  ことまで確認する形にした。負例 (`DB::transaction(static::$callback, 3)`) を
  gate (負例 9 変異へ) と走査器の自己検査の両方に追加し、
  正例 (`static function` / `static fn`) が誤検出されないことも固定した。

## [Warning] docblock の「負例 7 変異」と実数の不一致 / 「同一 closure 内」の主張

- 判断: **対応する**
- 対応内容: 「負例 **9** 変異」へ更新。主張を
  「同一の `DB::transaction(` の**引数範囲**の内側」へ狭め、
  「引数範囲は closure 本体そのものではなく `transaction(` の引数全体である」と明記した。

## 検証コマンドの扱い (最終報告に明示する)

- `composer test` / `composer phpstan` は green。
- `vendor/bin/pint --test` の唯一の fail は
  `devnotes/20260824-1013-rename-residual-name-gate-v1/evidence/verify-predicate.php` で、
  **main 側に既存の別 TODO の証跡ファイル**である (本 PR は 1 バイトも触っていない。
  main のチェックアウトでも同じ fail が再現することを確認済み)。
  本 PR で直すと他 TODO の証跡を書き換えることになるため触らず、最終報告で申し送る。
- pnpm 系 (lint / typecheck / test / build / packages) の結果も最終報告に明示する。
