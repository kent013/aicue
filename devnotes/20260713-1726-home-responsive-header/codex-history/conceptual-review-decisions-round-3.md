# 対応マトリクス: conceptual-review Round 3

Round 3 全体判定: **CHANGES_REQUESTED**（Critical なし / Warning 1）。「対応する」。

## [Warning] 観点4: 「広幅への復帰で閉じる」は CSS sm: 切替だけでは menuOpen=false にならない
- 判断: 対応する
- 根拠: 指摘のとおり。CSS ブレークポイントは Svelte state を変えない。
  「広幅復帰で閉じる」は誤った導線記述。
- 対応内容:
  - 「確定事項 4」から「広幅への復帰で閉じる」を削除（Escape + リンク押下のみに）。
  - 「確定事項 5」を新設: 展開パネルに `sm:hidden` を付与し、広幅では menuOpen に
    かかわらず必ず非表示（表示で保証）。resize listener は追加しない。狭幅→広幅→狭幅で
    開いた状態が残る挙動は許容（監視追加はオーバーエンジニアリング）。
    ハンバーガー=`sm:hidden` / 広幅ナビ=`hidden sm:flex` を明記。

## Suggestion 群
- 判断: 対応不要（全観点で方針追認）。
