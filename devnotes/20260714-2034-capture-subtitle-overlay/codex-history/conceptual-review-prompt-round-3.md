# 概念設計レビュー Round 3

Round 2 の残 2 点（Critical: 中央領域侵食、Warning: 端 inset 値）を反映しました。再判定をお願いします。

## 対応

- **[Critical] 中央領域侵食・帯の重なり**: overlay を `absolute inset-0 flex flex-col justify-between p-3 pointer-events-none` にし、primary=上端・secondary=下端に**構造的に固定**（`justify-between` で両帯は重ならず中央は常に空く）。各帯に**最大行数**を設定 — primary `line-clamp-2` / secondary `line-clamp-3`（超過は省略記号）。本文は `whitespace-pre-line` で改行保持しつつ line-clamp で高さ上限化。契約に「**位置・占有領域の確認用であり全文確認用ではない**（長文は line-clamp で省略。全文は焼込結果で確認）」と明記。vitest に「長文 JP・多数改行の同時表示でも primary(上)/secondary(下) が別要素として存在し line-clamp クラスが適用される（中央侵食しない構造）」ケースを追加。

- **[Warning] 端からの inset 値**: プレビュー端 inset は overlay コンテナの `p-3`（0.75rem, DS spacing ramp）で担保すると明記（ASS MarginV=36〜48px/1080 ≒ 3.3〜4.4% に対応する上下余白の役割）。帯内部余白は `px-3 py-1` として役割を分離。

- **[Suggestion] trim の用途**: 「trim は**空判定のみ**。描画は元文字列をそのまま使う（内容を書き換えない）」と明記。vitest に「描画文字列が trim で書き換えられない」ケースを追加。

## 反映後の「表示レイアウト契約」（全文）

- overlay コンテナ: `absolute inset-0 flex flex-col justify-between p-3 pointer-events-none`。`p-3` がプレビュー端 inset（DS spacing ramp、ASS MarginV 相当）。`justify-between` で primary 上端・secondary 下端に固定 → 両帯は構造的に重ならない。
- 位置: primary→上端帯、secondary→下端帯（ASS Alignment 8/2）。中央領域は常に空く。
- 帯: `max-w-[90%] mx-auto text-center`、内側 `px-3 py-1`、`bg-text/70`（空帯は非描画）、`text-surface`、`text-body`。
- 表示上限: primary `line-clamp-2` / secondary `line-clamp-3`（超過は省略記号）。`whitespace-pre-line` で改行保持しつつ高さ上限化。
- 表示 vs 空判定: `trim()` は空判定のみ。描画は元文字列。
- 契約の性質: 位置・占有領域確認用（全文確認用ではない）。占有領域の目安でありピクセル一致は非保証。
- 被写体非隠蔽: 上下帯のみ + line-clamp 高さ上限で中央を覆わない。
- safe-area: カード内 `aspect-video` は非該当。フルスクリーン UI はスコープ外。

残 Critical/Warning があれば具体修正案付きで指摘してください。無ければ APPROVED をお願いします。
