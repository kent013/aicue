# 対応マトリクス: conceptual-review Round 1

全体判定: APPROVED (Round 1)。Critical なし。Warning 2 件を詳細設計に反映する。

## [Warning] vendor の bag 名に文字列依存 (typo/契約変更に弱い)
- 判断: 対応する
- 根拠: マジック文字列の局所定数化は低コストで typo 耐性を上げる。概念設計でも定数化を検討事項に挙げていた。
- 対応内容: Security.svelte に `const CONFIRM_TWO_FACTOR_ERROR_BAG = "confirmTwoFactorAuthentication" as const;` を宣言し、`confirmForm.post()` の errorBag と (必要なら) テストの期待値の双方から参照する。詳細設計の施策1に明記。

## [Warning] 「POST に errorBag 含む」というテスト表現が曖昧
- 判断: 対応する
- 根拠: errorBag は payload ではなく Inertia visit option。検証を「option が渡ること」と「named bag のエラーから errors.code が表示されること」に分離すべき、という指摘は妥当。
- 対応内容: テスト計画を 3 本に分離:
  (a) 確認 POST の visit options に errorBag: "confirmTwoFactorAuthentication" が含まれる (回帰固定)
  (b) errors.code が載った状態で入力直下にエラーメッセージが描画される (誤コード UX)
  (c) 正コードで onSuccess 経路 (有効化完了 → confirming 解除) が走る
  詳細設計のテスト計画に明記。

## [Suggestion] サーバ側 override 案は blast radius が広く劣る / TS 側 literal const 化
- 判断: 見送る (サーバ override) / 対応する (literal const)
- 根拠: サーバ override は Fortify/Jetstream の既定契約から外れる。literal const は Warning 1 と同一対応。
- 対応内容: サーバ変更なしを維持。const 化は上記で対応。
</content>
