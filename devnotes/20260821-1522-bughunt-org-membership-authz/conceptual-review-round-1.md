全体判定: CHANGES_REQUESTED

1. 使命との整合性

- [Suggestion] F-2-02 は、SOP・撮影データの管理単位である組織への不正参加を防ぐため、North Star に直接貢献する。F-2-03 の退行テスト化も、現場データのアクセス境界を維持するうえで妥当。

2. 禁止事項違反

- [Suggestion] F-2-01 で option を disabled 化せず、選択可能なまま要件を明示する判断は、禁止事項 8 に適合している。既存の注記・作成 CTA・サーバ側 validation を維持する点もよい。
- [Suggestion] F-2-03 を production 変更なし・テスト固定に留めるのは、既存実装が安全であることを確認済みなら禁止事項 1 の趣旨にも合う。

3. 実現可能性

- [Warning] `show` の不一致表示は補助的 UX に留め、POST の Service 側検証を唯一の権威的防御として実装・テストすべき。表示状態だけでは直接 POST を防げない。  
  修正提案: `acceptInvitation()` の招待解決直後・参加処理直前で必ず検証し、不一致 POST が membership/pivot/role を一切変更しない Feature テストを置く。

- [Warning] 宛先 email 比較を `!==` の生文字列比較にすると、メールアドレスの大文字小文字や登録時の正規化差異により正規の受諾者を拒否しうる。  
  修正提案: 既存の `MatchesInvitationEmail` と同じ正規化・比較規約を共通化して利用する。少なくとも「登録経路と token POST 経路が同じ email 同一性規則である」ことをテストで固定する。

4. 期待効果の妥当性

- [Suggestion] F-2-02 の効果は合理的である。リンクを知るだけの第三者による組織参加を、サーバ側で遮断できる。
- [Suggestion] F-2-01 はエラー往復を完全になくすものではないが、選択時点で制約を可視化するため、誤操作の減少は期待できる。効果表現は「減らす可能性がある」程度に留めると正確。

5. リスク

- [Critical] T055 の register 誘導と AG-113 のアプリ内受諾を壊さないことが設計上の重要な退行リスクだが、現状は「変えない」との宣言に留まっている。  
  修正提案: 少なくとも以下を Feature テストで明示固定する。
  - guest が招待 URL を開いた場合、従来どおり register/login 導線と招待 email の引継ぎが機能する。
  - 招待先 email でログインした token POST は成功する。
  - 別 email のログイン者は show で受諾操作を提示されず、直接 POST も失敗する。
  - AG-113 の pending invitation は宛先本人にのみ表示・受諾可能である。

- [Warning] mismatch 画面にログアウト導線を出す場合、招待 token が URL に残ったままログアウト・ログイン遷移を跨ぐ設計になる。遷移後にも token が保持され、正しいアカウントで再開できることを確認しないと UX 後退になる。  
  修正提案: logout/login 後の復帰先と token 継続を既存フローに合わせ、ブラウザまたは Feature テストで確認する。

6. スコープの適切さ

- [Warning] F-2-03 の「未割当は全経路 403」をテストで固定するなら、「全経路」の範囲を曖昧にしない必要がある。テスト対象が少数 URL のみでは、過大な安全主張になる。  
  修正提案: 組織コンテキストを要求する代表的な read/write route を明示した inventory または既存認可 gate に接続し、「検証した経路」を正確に記述する。全数保証ができないなら「主要な組織保護経路」と表現を狭める。

- [Suggestion] 並行受諾レースを今回の production 修正対象外とする判断は妥当。現状 fail-closed で修復経路もあり、F-2-02 の認可境界修正に混ぜるべきではない。

7. 型安全性

- [Warning] Accept ページへ mismatch 状態を渡す場合、曖昧な任意 props や生配列ではなく、既存の Inertia page data / DTO の型付け規約に従う必要がある。  
  修正提案: boolean と表示に必要な最小情報だけを型付き props として定義し、Svelte 側もその型から受ける。email や招待詳細を不要に露出しない。

- [Suggestion] `ValidationException` は Laravel の標準的な Form error bag 経路に乗るため、DTO/JsonResource を迂回する `response()->json()` を増やさずに済む。PHPStan level 10 向けには、比較対象が nullable になり得るなら招待・ユーザー email の非 null 性を型と事前条件で明確にすること。