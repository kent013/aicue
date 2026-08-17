# 実装レビュー Round 1 の対応マトリクス (T223)

Codex 判定: **CHANGES_REQUESTED** (Critical 0 / Warning 6)

| # | 指摘 | 判断 | 対応内容 |
|---|---|---|---|
| 1 | [Warning] `--check` が byte 一致になっていない (`read_text()` は CRLF を LF へ畳むため、改行だけ変えた手編集を検出できない) | **対応する** | `main()` の比較を `read_bytes()` と `text.encode("utf-8")` に変更。`sha256_of_text()` を `sha256_of_bytes()` へ置き換えた (後方互換の別名は残さない = AGENTS.md 思考原則 3)。diff 表示だけは `decode(errors="replace")` した文字列で行う |
| 2 | [Warning] `spec-ledger-migration.json` の `source_lines` が `"81-113"` だが移行元は全 112 行 | **対応する** | 移行元 (`HEAD:.claude/skills/app-bug-hunt/spec-ledger.md`) を実測し、run 節の開始 81 行目 / ファイル末尾 112 行目を確認して `"81-112"` へ訂正した (詳細設計の値が誤っていた) |
| 3 | [Warning] byte 不変性テストも `read_text()` 後の文字列を hash 化しており、契約を固定できていない | **対応する** | `_Stage.output_sha()` を `read_bytes()` の sha256 に変更。`test_generated_output_matches_committed_file` も byte 比較へ。あわせて **改行コードだけ変えた差分**を検出する `test_newline_only_edit_is_detected` を新設した |
| 4 | [Warning] `test_duplicate_key_in_manifest_fails` が `block_count` の pin で先に落ち、鍵の重複検査へ到達しない。見出しの一意性検査も同様に到達しない | **対応する** | `load_migration()` の検査順を「1 件ずつ見れば分かること → 件数の突き合わせ → 件数の pin」に組み替え、順序が意図であることを docstring に明記。テスト側は `assertRaisesRegex` で**失敗理由**まで固定し、`test_heading_count_mismatch_fails` を分離。`test_block_count_change_fails` は entries と見出しの件数も揃えて pin だけが食い違う入力にした |
| 5 | [Warning] CR/LF 注入テストが一部の欄だけ (`scope_kind` / `adjudicated_at_run` / `supersedes` / `context.spec_basis` / `context.reopen_condition` が退行しても緑) | **対応する** | `test_newline_in_one_line_fields_is_rejected` として**出力の 1 行に出る全 10 欄**の表駆動テストに置き換え、欄ごとに `subTest` を分けた (`narrative` は複数行 markdown なので対象外であることを docstring に書いた) |
| 6 | [Warning] `SPEC_BASIS_FORM_RE` が詳細設計の閉じた集合より広く `tsx` / `jsx` / `jsonl` を許す | **対応する** | 根拠のない `tsx` / `jsx` を削除。`jsonl` は A-003 の根拠 (`devnotes/.../findings-merged.jsonl`) に必要なので**設計の 9 種へ意図して 1 つ足した**ものとしてコメントで理由を明示し、許可側 11 種を `SPEC_BASIS_EXTENSIONS` に列挙して `test_spec_basis_extension_vocabulary_is_pinned` で許可・拒否の両側から pin した |
| 7 | [Warning] `composer test` ほかの検証コマンドが実行中で全 green を提示できていない | **対応する** | Round 2 で実測値を提示する (`composer test` 5770 tests / 5768 passed / 2 skipped、`composer phpstan` 0 errors (988 files)、`vendor/bin/pint --test` passed、`pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` 完走、`pnpm test` は結果待ち) |

反論・見送りはゼロ件である。
