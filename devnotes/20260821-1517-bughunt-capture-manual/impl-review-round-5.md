## 再評価結果

### `tests/Browser/CaptureAppBoundaryTest.php`

判定: 問題なし

Round 3の残件はすべて解消されています。

- reloadと同じpartial requestヘッダを再現している。
- version一致の200経路で`X-Inertia: true`、locationなし、`Capture/Show`本文を確認している。
- version不一致の409経路で、非空の`X-Inertia-Location`実値と固定origin内の`/app`境界を確認している。
- `inApp(null)`によるfail-openは解消された。
- 固定originをnavigation、resource、responseの全判定で使用している。
- 受動観測と能動response観測の保証範囲も正確に記述されている。
- Chromium／WebKitの両レーンで実測されており、Phase Aの証拠として十分。

[Suggestion] 409を「現在URLのハードリロード」とさらに厳密に固定するなら、`bad.loc`を単に`/app`配下とするだけでなく、観測開始時の完全なURLと一致させられます。ただし、今回の不変条件である「`/app`外へ離脱しない」は現状で固定されているため、非ブロッキングです。

### 施策4

完了と判断します。

- 静的走査
- 空振りしないJS配線回帰
- 実ブラウザでのdocument／resource観測
- 実responseの200／409両経路
- statusとInertiaヘッダ実値
- 固定originによる境界判定
- 原因未確定を維持した分岐(c)の記録

が揃っています。

### 施策5

条件付きスキップは成立しています。

アプリ起因の自動`/app`離脱は静的・動的の双方で再現されず、唯一の外向き経路は利用者が明示的にクリックするPC詳細リンクです。この状態で包括的なnavigation guardを追加しない判断は、詳細設計と「今必要なものだけ作る」原則に合致します。

施策1〜3もRound 2で承認済みであり、新たな退行・セキュリティ・型・DTO/Inertia・DESIGN・Atomic Design上の問題はありません。

**全体判定: APPROVED**