# Round 2: 概念設計の修正反映

Round 1 の指摘（Critical なし / Warning 5）に全対応しました。対応マトリクスと修正後の概念設計を提示します。
全体判定を再度お願いします。

## 対応マトリクス

| 指摘 | 対応 |
|------|------|
| [W] 禁止事項8境界の明文化 | disabled は form.processing だけを理由に使い入力未充足では使わない旨を制約に明記 |
| [W] テスト観点欠落 | 「テスト計画（概要）」節を追加（close / 多重送信抑止 / 再オープン seed+error clear / 既存禁止事項8維持の4テスト） |
| [W] 効果の言い切り過ぎ | 「少なくとも同一画面上の accidental re-submit を防ぐ。サーバ側冪等化は別タスク」に限定 |
| [W] $effect の追従が粗い | seed は open の false→true エッジのみ。prevOpen 検知 or 明示 seedFromDefaults()、依存は boolean open のみ、form 全体を依存にしない。open=true 中の props 変化で入力途中値を上書きしない |
| [W] 再オープン時 errors が古い | seed 時に form.clearErrors() でエラーも初期化 |
| [S] 型の閉じ込め | seedFromDefaults(): void を型付き小関数に閉じ defaultCategory: number|null を崩さない |

## 修正後の概念設計（該当箇所抜粋）

### 改善アイデア 施策3（更新）

再オープン時の defaults 追従（open の false→true エッジのみ）: ダイアログが閉→開に遷移した瞬間だけ、
その時点の props（defaultTitle / defaultCategory）でフォーム値を seed し直し、合わせて
form.clearErrors() で前回のエラー状態も初期化する。open=true のまま props が変わっても入力途中の値は
上書きしない（seed 契機はエッジに限定）。実装は汎用 $effect(props 依存)にせず、prevOpen を保持した
エッジ検知、または明示的な seedFromDefaults(): void 関数に寄せる（依存は boolean open のみ、form
オブジェクト全体を effect 依存に含めない）。seedFromDefaults は props 型（defaultCategory: number | null）を
崩さず useForm の shape と一致させる。

### 期待効果（更新）

少なくとも同一画面・同一 UI インスタンス上の accidental re-submit（二重クリック・Enter 連打・redirect
完了前の再入）を防ぐ。サーバ側の複製冪等化は本タスク対象外（別タスク）であり、効果はフロントの多重送信
ガードで防げる範囲に限る。

### テスト計画（概要・新設）

vitest（DuplicateManualDialog.test.ts）に追加:
1. 複製 submit → onSuccess 発火でダイアログが閉じる（open=false）。
2. 送信中（form.processing=true）は confirm ボタンが disabled で、二重クリックしても form.post が 2 回目は
   発火しない（ハンドラ冒頭ガード）。
3. open を false→true に再遷移させると現 props defaultTitle/defaultCategory が再 seed され前回エラーがクリアされる。
4. （既存維持）「送信ボタンは必須未充足でも disabled にしない（禁止事項 8）」を壊さない。

### 制約（更新）

disabled は form.processing（送信中）だけを理由に使い、入力未充足では一切使わない。空タイトルでも押下でき、
押下時にエラー表示。
