# 対応マトリクス: impl-review Round 1

## [Warning] 「2FA 秘密 GET 3 本は 1 つのレーンを共有する」が 2 本しか叩いていない
- 判断: **対応する**
- 根拠: 指摘のとおり `two-factor.recovery-codes` が別 bucket (inline `10,1` 等) へ戻っても
  このテストは落ちなかった。施策 8 が要求しているのは 3 本すべての固定であり、
  「1 本だけ検出できない穴」は deny-by-default の gate として不完全である。
- 対応内容: 3 本を順に叩き、`X-RateLimit-Remaining` が連続して 1 ずつ減ることを確認する形へ変更。
  併せて各応答に `X-RateLimit-*` が存在すること (= throttle が実際に効いていること) も
  assert し、「ヘッダが無いので (int) null = 0 同士で偶然通る」経路を塞いだ。

## [Suggestion] 8-3 が「throttle が外向き HTTP より前」の証明として弱い
- 判断: **対応する**
- 根拠: 「Socialite を呼ばない」だけでは半分で、**枠を消費している**ことまで示さないと
  「無効リクエストは無制限に踏める」状態と区別がつかない。テスト名 (増幅が有界) に
  検査内容が追いついていないという指摘は正しい。
- 対応内容: 同テスト内で 2 回叩き、`X-RateLimit-Remaining` が 1 減ることを assert に追加。
  「Socialite 未到達」+「枠は減る」の 2 点でテスト名の主張が完結する。

## [Suggestion] /register の invitation token 分岐が DB 書込 0 件テストの対象外
- 判断: **対応する**
- 根拠: exemption 理由に「token がある場合のみ招待を 1 件 read する」と明記しており、
  その分岐だけが検査されていないのは前提 drift の穴になる (prefill のついでに書く実装へ
  変わっても無音で通る)。
- 対応内容: 前提テストへ 1 本追加 (`register の invitation token 分岐も DB 書込を発行しない`)。
  空振り防止のため「`organization_invitations` への read が実際に発行された」ことも
  同時に assert し、分岐に入らずに green になる経路を塞いだ。
