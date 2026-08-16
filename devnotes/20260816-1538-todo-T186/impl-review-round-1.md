[Warning] `tests/js/pages/CaptureShow.test.ts`

`inline レイアウト固有の見出しが一度も DOM に現れない` のテストが、`observer.takeRecords().forEach(() => undefined)` で保留中の MutationRecord を捨てています。  
このため、`capture-recording-heading` の追加が保留分に残っていた場合、検査対象を見ずに通る可能性があります。設計書が明示している「保留分を回収して子孫まで見る」契約からも外れています。

直し方: MutationRecord 処理を helper 化し、callback と `observer.takeRecords()` の両方に同じ処理を通してください。`addedElements` の空振り防止はその helper 内で更新する形にするとよいです。

[Warning] `resources/js/components/features/capture/ShootingGuideOverlay.svelte`

`line-clamp-2` と `flex` が同じ `<p>` に付いています。`line-clamp` は `display: -webkit-box` 前提なので、`display: flex` と競合します。生成 CSS の順序次第で、2 行制限か flex 配置のどちらかが効かず、長い撮影ガイドが字幕帯と交差するリスクがあります。現在の Browser テストは fixture 文言が短いため、この退行を捕まえません。

直し方: 外側を flex のパネルにし、テキスト側の `<span>` に `line-clamp-2 min-w-0` を移してください。例: `<p class="flex ..."><Lightbulb ... /><span class="min-w-0 line-clamp-2">{text}</span></p>`。

[Suggestion] `tests/Browser/CaptureLandscapeFullscreenTest.php`

`landscapeCaptureMediaQuery()` が TS 側の `LANDSCAPE_CAPTURE_MEDIA_QUERY` と同じ文字列を PHP 側に複製しています。Browser テスト上は避けにくいですが、「正本は TS」とコメントするだけでは drift を機械検出できません。

直し方: 可能なら Browser テストの JS 評価内で `window.matchMedia` の対象文字列を固定するだけでなく、JS unit 側の完全一致テストを正本として扱う方針をコメントに明記してください。現状でも致命的ではありません。

その他のファイルは設計意図と概ね一致しています。サーバ側 DTO / JsonResource / 認可境界に触れていない判断も妥当です。DESIGN.md / Atomic Design についても、新規 hex、任意 z-index、SVG 直書き、逆向き import は見当たりません。

CHANGES_REQUESTED