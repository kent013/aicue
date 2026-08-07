# 対応マトリクス: design-review Round 3

Codex 全体判定: CHANGES_REQUESTED
(S1 APPROVE / S2 APPROVE / S3 APPROVE / S4 REQUEST_CHANGES / S5 APPROVE / S6 APPROVE)
[Critical] 0 件・[Warning] 1 件・[Suggestion] 2 件。

## [Warning] S4: キャッシュ表現の分岐に対して `Vary: Accept` が不足

- 判断: **対応する** (指摘より一段広く閉じる)
- 根拠: 指摘のとおり `Vary: X-Inertia` だけでは足りない。実際に調べたところ分岐入力は 4 つある:
  1. `X-Inertia`          … Blade か Inertia page か
  2. `X-Inertia-Version`  … 差し替えるか (配備境界。Codex の指摘には無いが同じ問題)
  3. `Accept`             … JSON か画面か (expectsJson)
  4. **セッション (Cookie)** … 戻り先が `/dashboard` か `/login` か (`destinations`)
  さらに `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php` を読んで確認した結果、
  同 middleware は `hasSession() && user() !== null` のときだけ `no-store` を付ける
  = **guest の 4xx/5xx は素通し**であり、Codex が懸念したとおり共有キャッシュの余地が残る。
  4 番目 (セッション由来) は Vary では宣言できないため、Vary だけでは閉じない。
- 対応内容:
  - 差し替え応答に `Vary: X-Inertia, X-Inertia-Version, Accept` を付与
    (ヘッダ由来の分岐入力を過不足なく宣言する)
  - **かつ** `Cache-Control: no-store, private` を付与してセッション由来の分岐を閉じる。
    既に `no-store` を持つ応答は上書きしない (directive を縮めない既存作法に合わせる)
  - **素通し側 (Blade) は変更しない**と明記。Blade のエラーページは全クライアントで
    同一の固定文言であり、共有キャッシュのヒットで再現するのは今日と同じ UX
    (モーダル表示) だけで、後退にも情報漏えいにもならない。
    ここへ手を入れると 500 経路の最後の砦 (自己完結契約) に副作用のリスクを持ち込む
  - テスト追加: `it('Error 応答のキャッシュ表現契約 (no-store + Vary) を満たす')` —
    `Cache-Control` に no-store、`Vary` に 3 ヘッダすべて。**未認証 (guest) でも成立**すること
    (guest ケースが本契約の主戦場である旨も明記)
  - リスク表 6 番を更新、mutation 表に M16 を追加

## [Suggestion] S4: 419 の基本テストに `/login` の literal 期待が残っている

- 判断: **対応する** (Round 1 対応の反映漏れ)
- 対応内容: `route('login', absolute: false)` との比較に統一。

## [Suggestion] S6: mutation 説明の「残る M4〜M12」が表と不一致

- 判断: **対応する**
- 対応内容: M16 追加も踏まえて「残る M4〜M16」に更新。
