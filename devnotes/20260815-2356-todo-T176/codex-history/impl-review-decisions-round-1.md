# 対応マトリクス: impl-review Round 1

## [Warning] `check_catalog()` が `/` を含む token をすべて無視している (段 4 が空振りする)

- 判断: **対応する**
- 根拠: 指摘のとおり。`routes/api.php` を候補から外したかっただけなのに、`projects/store` の
  ような打ち間違いまで無視していた。段 4 の目的 (代表機構が実在すること) に穴が開く。
- 対応内容: `PATH_TOKEN_RE`(区切りを含み、かつ拡張子で終わるもの) に一致する token だけを
  パスと見なすよう変更した。負の対照 `test_パスに見えないスラッシュ入りの記載はドリフト` を追加。
  詳細設計 §施策 3「段 4 の契約」にも是正の経緯を書いた。

## [Warning] 未知のトップレベル項目が exit 2 になっている (設計は段 2 の drift = exit 3)

- 判断: **対応する**
- 根拠: 指摘のとおり終了コード規約と食い違っていた。fail-closed ではあるが、
  「注釈の書き間違い」は drift の側に属するので 3 が正しい。
- 対応内容: `load_annotations()` の戻りを `Annotations` (routes + unknown_top_level) にして
  持ち回り、`validate_annotations()` が drift 行として列挙するようにした。
  自己テストも exit 3 期待へ直し、詳細設計にも是正を書いた。

## [Suggestion] `notes-screens.md` にも「表を書かない」と書いてあるが検査は operations だけ

- 判断: **対応する** (弱める側ではなく、検査を両方へ広げる)
- 根拠: 連結先ごとに規則が変わる方が事故のもとになる。実装コストは 2 行で、
  「表そのものを置かない」という 1 つの規則で説明が閉じる。
- 対応内容: `check_notes()` を 2 ファイル分の写像で受け取る形にし、自己テストも
  2 ファイルの subTest で回すようにした。`notes-screens.md` の冒頭注記に
  「あちらは correlate.py が読むから / こちらは規則を揃えるため」と理由の違いを明記した。

## [Warning] 「webhook には沈黙する」は誇張 (`webhooks.ses` は目録に載っている)

- 判断: **対応する**
- 根拠: 指摘のとおり。沈黙するのは「`web` group を宣言していない面」であって
  「webhook 一般」ではない。実際 `webhooks.ses` は操作表に区分 `外` として載っている。
- 対応内容: `AGENTS.md` §bug-hunt / `docs/template-divergence.md` D20 /
  `.claude/skills/app-bug-hunt/SKILL.md` Phase 1 / 生成器の docstring の 4 か所を
  「`web` を宣言していない面には沈黙する。`web` を宣言していれば webhook でも目録に入る
  (実例: `webhooks.ses`)」へ直した。
