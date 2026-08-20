# Round 4 対応マトリクス

Round 4 はコード上の懸念 (Critical) が無く、文書の食い違いのみでした。すべて対応しました。

| # | 指摘 | 分類 | 対応 |
|---|------|------|------|
| 1 | `enum-ts-sync-discovery-extractor.test.ts`: テスト名「tsconfig の include が母集団の単一出典」が、実際に不変条件を固定しているファイルシステム完全一致テストと矛盾する | Warning | **対応**。テスト名を「tsconfig の include が実際に決める」へ改め、コメントに「母集団の単一出典が tsconfig だと主張するものではない」旨を明記した |
| 2 | `ts-candidates.ts`: docblock の「母集団の単一出典」節が同じ矛盾を持つ | Warning | **対応**。「母集団の実体」という見出しへ改め、tsconfig は program 構築の入力であって、ファイルシステム直接走査との完全一致テストが不変条件の実体である旨へ書き換えた |
| 3 | `docs/architecture.md`: 本文 (発見の段の直後) は正しく更新されていたが、「保証しないもの」節末尾に矛盾する古い説明 (「tsconfig の include/exclude が単一の出典」) が残っていた | Warning | **対応**。本文と同じ「ファイルシステム完全一致が不変条件の実体」という表現へ揃えた |
| 4 | `php-enum-catalog.ts`: catch 内のコメントに「enum 宣言らしい並び」という古い表現が残っている (実装は任意の `enum` の語を拾う) | Suggestion | **対応**。「`enum` の語が無ければ」という表現へ揃えた |

## 再検証

- `pnpm typecheck`: エラー無し
- `pnpm exec vitest run tests/js/architecture/enum-ts-sync*.test.ts`: 4 files / 170 tests passed (件数に変化無し。文書・コメントのみの変更のため)
