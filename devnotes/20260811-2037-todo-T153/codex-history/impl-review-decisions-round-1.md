# 対応マトリクス: impl-review Round 1

Codex 返答: `devnotes/20260811-2037-todo-T153/impl-review-round-1.md`
全体判定: **CHANGES_REQUESTED**（[Critical] 0 件 / [Warning] 2 件 / [Suggestion] 0 件）

---

## [Warning] `.env.bughunt.local.example:57-59` のコメントが施策 8 と矛盾している

- 判断: **対応する**
- 根拠: 指摘は正しい。`grep -n 'MODE_ENV=' scripts/bug-hunt-shard.sh` で確認したところ
  `MODE_ENV` に `TESTING_FAKE_EXTERNALS` は**一度も入らない**（入れると
  「スクリプトが入れた値をスクリプトが検証する」トートロジーになるので設計が意図的に外している）。
  にもかかわらず「以下 TESTING_FAKE_* の実効値は script 注入が正本」「コピー忘れでも既定は崩れない」
  と一括で書いてあったため、施策 8 で fail-fast させる**当の欠落**を「起きない」と読ませる嘘になっていた。
  施策 7 の趣旨（残すと嘘になる記述を同一 PR で直す）に照らしても直すべき。
- 対応内容: 見出しコメントを 2 系統へ分割した。
  - `TESTING_FAKE_LLM` / `TESTING_FAKE_STORAGE` → 従来どおり「script 注入が正本」
  - `TESTING_FAKE_EXTERNALS` → 「**例外で script 注入しない**。dotenv 側で true 宣言が必須で、
    欠落は provision の実効 env 検証が `('fake_externals', (None, True))` の形で fail-fast させる」
    と、注入しない**理由**（トートロジー回避）まで書いた。

## [Warning] 新規テスト #9 が「Socialite に触れず」を実証していない

- 判断: **対応する**
- 根拠: 指摘は正しい。強化前の #9 は `login` へのリダイレクトと `assertGuest()` しか見ておらず、
  「driver を解決してから login へ戻る」実装に壊れても緑のままだった
  （= テスト名が主張する内容を検査していない = 偽グリーンの一種）。
  これは詳細設計 §「検査が空振りしないことの保証」の趣旨に反する。
- 対応内容: `$enableSsoFake()` の**後**に、`driver()` が呼ばれたら `RuntimeException` を投げる
  無名サブクラスを `SocialiteDriverResolver::class` へ後勝ちで bind し、到達の有無そのものを
  検出する形へ強化した（Codex の修正案どおり）。
  さらに **強化が効いていることを mutation で実測**した（`mutation-evidence.md` の M14）:
  `callback()` が intent 判定より前に driver を解決するよう壊すと #9 のみが赤くなり、
  失敗メッセージに `intent 不在の callback が Socialite driver を解決しました: google` が出る。
  **強化前の #9 はこの mutation を検出できなかった**ことも併せて記録した。

---

## 反論・見送りはなし

Round 1 では [Critical] が 0 件で、[Warning] 2 件はいずれも実コードで裏が取れたため
両方とも対応した（設計判断の蒸し返しは 1 件も無かった）。
