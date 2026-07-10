# ドナー(aigenba)新規登録画面のフィードバック → テンプレートへの反映事項

> 2026-06-11 オーナーフィードバック。aigenba 本体の不具合だが、テンプレートの認証画面
> (Phase 1)・コンポーネント(Phase 0.5)が同じ問題を継承しないよう対応方針を確定しておく。

## 1. 規約未同意時に Google 登録ボタンが非活性 → わかりにくい

- 症状: 利用規約チェックを入れるまでボタンが disabled。なぜ押せないのか伝わらない。
- **テンプレ方針(Phase 1 Register 画面)**: ボタンは常に活性。押下時に同意チェックを検証し、
  未同意ならチェックボックス横にエラーメッセージを表示してフォーカス移動する
  (サーバ側でも `accepted` バリデーション必須 = クライアント表示は補助)。
  disabled でユーザーを止める UX は採らない、を DESIGN.md の Do/Don't に明記。

## 2. チェックボックスと同意文言の align ずれ(コンポーネント未使用疑い)

- 症状: checkbox の上端と「利用規約 および プライバシーポリシー に同意します。」の行頭が揃わない。
- **テンプレ方針(Phase 0.5)**: Checkbox atom + ラベル/エラー配線を持つ CheckboxField
  (または FormField)を必ず通す。チェックボックス+複数行ラベルの行揃え
  (`items-start` + checkbox 側の `mt-[行高調整]` を atom 内で解決)を atom の責務にし、
  ページ側で素の `<input type="checkbox">` を書くことを ds-purity/レビュー規約で禁止。

## 3. CSP form-action 違反で Google 登録がブロック

- 症状: `form-action 'self' https://checkout.stripe.com` の CSP 下で
  `/auth/google/redirect/register` への form 送信がブロック。
- 原因(推定): SSO 開始を form POST にしており、サーバが 302 で accounts.google.com へ
  リダイレクトする。Chrome は **form 送信後のリダイレクト先にも form-action を適用**するため、
  same-origin への POST でもリダイレクト先がブロック対象になる。
- **テンプレ方針(Phase 1)**: SSO 開始導線は form POST ではなく **GET の anchor リンク**にする
  (Button の anchor モードを使用。CSRF が不要な「外部 IdP への遷移開始」は GET で問題ない。
  state/PKCE は Socialite 側で担保)。やむを得ず POST にする場合は CSP `form-action` に
  IdP ドメインを追加する手順を docs に書く。SecurityHeaders 雛形の CSP コメントにも注記する。

## 4. 招待メール送信後に /email/verify へ遷移する(2026-06-11 追加)

- 症状: メンバー招待メールを送ると `https://aigenba.com/email/verify` に遷移する。
  「送信しました」の表示で完結すべき操作で画面遷移が起きており、ロジック誤りの疑い。
- 原因(推定): `redirect()->intended()` の誤用(session に残った intended URL が
  /email/verify を指している)、または招待 POST 後のリダイレクト先が `verified`
  ミドルウェアに弾かれて検証画面へ飛ばされている。
- **テンプレ方針(Phase 2 招待実装時)**:
  - 招待送信は `back()->with('success', '招待メールを送信しました')`(flash → toast)で完結。
    画面遷移しない
  - `redirect()->intended()` は **ログイン直後のフローでのみ使用可**とする規約を
    07 ガイド/AGENTS.md 雛形に明記(操作系 POST の応答で intended を使わない)
  - 招待フローの Feature テストに「送信後に元画面へ戻る」アサーションを含める
