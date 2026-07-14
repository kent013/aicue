# 詳細設計レビュー Round 3

Round 2 の残 Warning 1 件 + Suggestion 2 件を反映しました。「これを追加すれば APPROVED 相当」との評価を受けての再提出です。

## Round 2 指摘への対応

### [Warning] S5: active token の session 維持を明示検証 → 追加
active token ケースに `$response->assertSessionHas('invitation_token', $token)` を追加。
これにより「prefill は表示できるが POST で招待参加できない」回帰を検出可能にした。

改訂後の active token ケース:
> active token を session に持つ GET /register → props invitationEmail = 招待先 email、かつ応答が no-store、
> かつ **active token は session に維持される** (`assertSessionHas('invitation_token', $token)`)、
> かつ Cache-Control が `no-store` ディレクティブを含む (str_contains 相当・別ディレクティブ許容)。

### [Suggestion] S3: 「no-store が bf-cache を防ぐ」→「HTTP キャッシュへの保存禁止」に正確化 → 反映
断定記述を「HTTP キャッシュ (共有/中間プロキシ/ブラウザ HTTP キャッシュ) への保存を禁止」に修正。

### [Suggestion] S3: ヘッダテストを directive 追加許容へ → 反映
S5 の Cache-Control assert を完全一致でなく「`no-store` を含む」検証に変更。

---

## 最終確認事項 (使命・禁止事項)
- 使命寄与: 招待オンボーディング摩擦の低減。禁止事項 #4 (Inertia props)・#8 (readonly ≠ ボタン disabled) 非該当。テストなし完了なし (S5)。PHPStan widen なし。
- セキュリティ不変条件 #6: 平文 email 検索を新設せず token_hash 照合のみ。bearer token PII 開示は明示受容 + no-store。

全体判定の再評価をお願いします。差分箇所のみ上記に記載しています (他は Round 2 提出の全文から変更なし)。
