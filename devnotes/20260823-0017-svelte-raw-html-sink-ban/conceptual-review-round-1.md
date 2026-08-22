全体判定: CHANGES_REQUESTED

1. 使命との整合性

[Suggestion] 撮影 PWA が同一オリジン・セッション認証であるため、XSS 防御は撮影データと操作権限の保護に直結する。North Star への寄与は妥当である。

2. 禁止事項違反

[Suggestion] 禁止事項への抵触は見当たらない。`response()->json()`、LLM、許可一覧、破壊的 DB 操作のいずれも本スコープに含まれない。テストを実装の一部として明記している点も適切である。

3. 実現可能性

[Warning] `QrCodeImage` の data URI 化に必要なエンコード方式と props 契約が未確定である。SVG 文字列をそのまま `data:image/svg+xml,${svg}` と連結すると、`#`、`%`、非 ASCII 文字などで URI として壊れ得る。

修正提案: `svg: string`、`alt: string` を明示した Svelte の型付き props とし、`src` は `data:image/svg+xml,${encodeURIComponent(svg)}` のように生成することを設計へ明記する。部品テストで URI エンコード、`img`、`alt`、生の `<svg>` 非出力を固定する。

[Suggestion] Laravel 12 / Svelte 5 / Inertia の範囲で無理なく実現できる。既存の QR 取得・再認証・状態遷移を変更せず表示部だけ置換する方針は、後退リスクも小さい。

4. 期待効果の妥当性

[Warning] 「`<img>` 経由で読まれた SVG は外部リソースも取得しない」という断定は強すぎる。少なくとも本 feature の安全性の根拠は、画像文脈の SVG でスクリプト実行をさせないことと、CSP の `img-src data:` を維持することに限定すべきである。

修正提案: 期待効果を「`{@html}` による DOM への HTML 挿入を廃し、SVG を画像リソースとして扱う」に修正する。外部取得までの包括的保証は主張しない。CSP の pin は正典どおり `img-src` の `data:` 維持に限定する。

[Suggestion] raw HTML sink を 1 件から 0 件へ下げ、将来の追加を lint と gate の二層で止める効果は合理的である。

5. リスク

[Warning] gate の「無効化コメント 3 形式」が文面上まだ曖昧であり、ESLint の実際のコメント構文・配置を誤ると、禁止が無効化できないことを実証できないテストになり得る。

修正提案: 正典実装と同じ三つの具体的な fixture を定義し、それぞれが実際に ESLint の inline configuration として解釈される構文・位置にする。各 fixture で `svelte/no-at-html-tags` の error が残ること、通常の `{@html}` も error になること、raw HTML を含まない正例が成功することを固定する。

[Suggestion] `resources/js` 全数走査の母集団非空チェック、読み取り・lint 実行失敗時の fail-closed は、AGENTS.md の gate 規約に整合する。

6. スコープの適切さ

[Suggestion] 正典 t1 の boundary にある四点を過不足なく含む。`innerHTML` 等へ拡張しないこと、CSP の組み立て機構自体を変更しないこと、例外 inventory を作らないことはいずれも適切である。

[Suggestion] `DESIGN.md` への追記は、禁止だけでなく代替部品を明示するために有用であり、過大化ではない。

7. 型安全性

[Warning] PHP の API DTO / JsonResource を変更しないため、Laravel 側の DTO 論点は直接は発生しない。ただし新規 Svelte atom の入力型と、画面側の `qrSvg` が `string | null` である境界を曖昧にすると typecheck が弱くなる。

修正提案: `QrCodeImage` は `svg: string` を必須にし、`Security.svelte` 側は `qrSvg !== null` の分岐内でのみ描画する設計を明記する。nullable 値を atom 内で吸収させない。これにより Svelte の型検査と既存状態機械の責務分離を保てる。

上記の URI エンコード契約と、実際に効く無効化コメント三形式の fixture を設計に明記すれば、正典 t1 の四点そろいを保ったまま APPROVED と判断できる。