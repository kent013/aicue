# 概念設計レビュー Round 4（残 Warning 1 件の対応確認）

Round 3 の Warning 1 件に対応しました。残指摘が無ければ APPROVED を明記してください。

## 対応サマリ

**[W] 「広幅への復帰で閉じる」は CSS `sm:` 切替だけでは `menuOpen=false` にならない**

ご指摘のとおり CSS ブレークポイントは Svelte state を変えないため、当該記述を訂正しました。

- 「確定事項 4」から「広幅への復帰で閉じる」を削除し、閉じる導線は **Escape + パネル内リンク押下**
  のみに限定。
- 「確定事項 5」を新設:
  - 展開パネルは `{#if menuOpen}` に加えて **`sm:hidden`** を付け、広幅 (`sm` 以上) では
    `menuOpen` の値にかかわらず**必ず非表示**（状態ではなく表示で保証）。
  - **resize listener は追加しない**。「狭幅で開く → 広幅 → 再び狭幅」で `menuOpen=true` が残り
    開いた状態が復元される挙動は**許容**（実害が小さく、監視追加はオーバーエンジニアリング）。
  - ハンバーガーボタン=`sm:hidden` / 広幅ナビ=`hidden sm:flex`。

## 反映後「確定事項 4・5」全文

4. **キーボード UX / フォーカス復帰**: `Escape` で閉じた後、`element` bindable で保持した
   トグルボタン (`HTMLButtonElement`) に `.focus()` でフォーカスを戻す。パネル内リンク押下でも
   `close()`。outside-click は今回スコープ外。

5. **広幅での表示保証 / リサイズ時の state**: 展開パネルには `{#if menuOpen}` に加えて
   `sm:hidden` を付け、広幅では `menuOpen` の値にかかわらず必ず非表示にする。resize listener は
   追加しない。狭幅→広幅→狭幅で開いた状態が残る挙動は許容する。
   ハンバーガーボタンは `sm:hidden`、広幅ナビは `hidden sm:flex`。
