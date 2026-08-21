全体判定: CHANGES_REQUESTED

Round 1 の Critical は解消されています。F-2-02 の防御境界、T055・AG-113 の回帰、型付き props、email 同一性規則が具体的なテスト計画まで落ちており、実装へ進める水準です。ただし、設計文書内の保証表現と F-2-01 のテスト計画に残件があります。

1. 使命との整合性

- [Suggestion] F-2-02 は、SOP・撮影データを保持する組織への参加者を招待宛先に限定するため、North Star に本質的に貢献する。
- [Suggestion] F-2-03 の除名不変条件固定、F-2-01 の制約可視化も、本体目的を阻害せず信頼性と操作性を補強する適切な改善である。

2. 禁止事項違反

- [Suggestion] F-2-01 で option を選択可能なまま維持し、押下後のサーバ validation も残す方針は、禁止事項 8 に適合している。
- [Warning] F-2-01 のテスト計画 8 は `hasDefaultProject` のサーバ側データ契約しか検証しておらず、今回変更する「注記付きラベル」と「option を disabled にしないこと」を固定していない。このままではフロントエンド変更について禁止事項 1 の完了条件が弱い。  
  修正提案: Svelte テストに、プロジェクトなしの場合は編集者・撮影者に注記が付き、管理者には付かず、3 option とも選択可能であることを追加する。プロジェクトありの場合に注記が消えることも対になる正例として固定する。

3. 実現可能性

- [Suggestion] Service での宛先検証、Inertia の `canAccept: boolean`、Svelte での条件表示はいずれも Laravel 12・Svelte 5・Inertia で無理なく実現できる。
- [Suggestion] token 解決後、参加処理前に `ValidationException` を投げる位置も適切。直接 POST を含め、状態変更前に拒否できる。

4. 期待効果の妥当性

- [Suggestion] F-2-02 による第三者参加の遮断、F-2-03 による退行検知という期待効果は妥当。
- [Suggestion] F-2-01 の効果を「手戻りを減らす」とした表現も適切。サーバ往復が構造的になくなるとは主張していない。

5. リスク

- [Suggestion] T055 について guest 誘導、session token、email prefill を一体で固定し、AG-113 も別経路として固定する計画により、Round 1 の重大な回帰リスクは解消されている。
- [Suggestion] 不一致 POST で membership・pivot・role の不変を確認するため、「UI では隠したが API は通る」という後退も検出できる。
- [Suggestion] logout を跨いだ session token の保持に依存せず、元の招待リンクの再オープンを明示する判断は堅実。招待 email を表示しない点も情報露出を抑えている。

6. スコープの適切さ

- [Warning] F-2-03 の本文に、修正前の「全経路 403」という表現が2か所残っている。

  - 事前検証表の F-2-03 (c)
  - 背景・課題の「この状態はアクセスが全経路 403」

  後段では「主要な組織保護 route」に正しく限定しているため、文書内部で保証範囲が矛盾している。  
  修正提案: 上記2か所も「検証した主要 route では 403」などに統一し、必要なら `dashboard / projects / billing / manage-users` を明記する。

- [Suggestion] F-2-03 で production コードを変更せず、再現した既存不変条件だけをテスト固定する判断は妥当。観測されていない障害に追加機構を導入せず、今回の実在する脆弱性へ集中できている。
- [Suggestion] 並行受諾レースの構造的解消をスコープ外とする判断も、未割当状態が少なくとも列挙経路で fail-closed かつ修復可能という前提の範囲では妥当。

7. 型安全性

- [Suggestion] Inertia props を `canAccept: boolean` に限定し、Svelte の `Props` にも反映する方針は型安全かつ露出最小である。
- [Suggestion] CipherSweet 復号後の値を既存経路と同じ規則で比較し、必要な narrow を明示する方針なら PHPStan level 10 に対応可能。
- [Suggestion] `ValidationException` と標準 error bag を使うため、`response()->json()` の直書きや新たな非型付き JSON 応答も発生しない。

承認に必要な修正は、残存する「全経路」表現の統一と、F-2-01 の実際の UI 挙動を固定するフロントエンドテストの追記です。