# bug-hunt shard-2 特化検証 (run 20260823-001445)

> 本走行はフルストーリー走行ではなく、前回 run (20260821-095643-bug-hunt) の F-2-03 (メンバー削除の
> fail-closed 実挙動) と F-2-02 (招待受諾の宛先メール照合。T237 で修正済み) の 2 点に特化した再検証。
> 対象 URL: http://127.0.0.1:8012 (shard 2 専用)。DB: bug_hunt_2。ブラウザ: `playwright-cli -s=bughunt2`
> (`--browser chromium` 指定が必要だった。既定 `chrome` チャンネルは環境に未インストールで
> `open` が daemon exit code 1 で失敗する。以降 `--browser chromium` を都度指定した)。

## 検証目的1: F-2-03 (メンバー削除後のアクセス実挙動) — 結論: **(B) fail-closed。実害なし**

前回観察 (削除後もダッシュボード/プロジェクト/請求へアクセスでき、メンバー一覧に「未割当」で再出現する) は
**本走行では再現しなかった**。T237 の「除名は現行コードで既に fail-closed」という判定と一致する。

### 手順と実際の観測
1. owner-personal@example.com (組織「Personalプラン組織」) で `/manage/users` を開き、
   member-personal@example.com (削除前は「未割当」ロール表示) の「削除」→ 確認モーダル「この操作は
   取り消せません」→「削除する」を確定。
   - feedback-probe: `installed_now=false seen=1(visible:true, testid=toast-success,
     text="メンバーを削除しました") present_new=1 pending=0 errors=0` → トーストは正常に確認できた。
   - 削除直後の一覧から member-personal@example.com の行が消えたことを確認 (owner-personal, admin-personal,
     multi-org, unverified の 4 行のみ残存)。
2. cookie-clear で完全ログアウトし、削除された member-personal@example.com で再ログイン。
   - `/dashboard` は 200 で表示されるが、内容は「組織未選択」「Personal Member さん、ようこそ」
     「まずは組織を作成しましょう」の**組織なしユーザーの初期状態**。サイドバー nav も
     「ダッシュボード」のみ (プロジェクト/メンバー/API キー/請求のリンクが消えている)。
     組織のデータは一切露出していない。
3. その状態のまま `/projects` `/billing` `/manage/users` へ直 URL アクセス:
   - `/projects` → **404 Not Found** (「ページが見つかりません」)
   - `/billing` → **404 Not Found**
   - `/manage/users` → **404 Not Found**
   - console は各回 1 件の `Failed to load resource: 404` のみ (アプリ側エラーではなく期待どおりの404)。
   - つまり、組織のプロジェクト名・請求情報等の**実データには一切到達できなかった**。
4. owner-personal に戻り (cookie-clear→再ログイン) `/manage/users` を再確認: member-personal@example.com は
   一覧に**再出現しない** (4 行のまま)。「未割当」としての残存は今回発生しなかった。

### 結論の根拠
- pivot 解除 (組織メンバーシップの実体) は正しく行われている: 削除直後にダッシュボードの
  current_organization が失われ「組織未選択」状態になった。これは pivot が残っていれば起こらない挙動。
- 保護ルート (`/projects` `/billing` `/manage/users`) は全て 404 (403 ですらなく、存在を漏らさない
  fail-closed) で、実データへの到達は一切できなかった。
- よって **前回 run (20260821-095643) の F-2-03 観察は本 run では再現しない**。T237 の判定
  (production コード変更なし、既に fail-closed) を実ブラウザで裏付ける結果になった。
- 前回観察と食い違う理由は未特定 (本走行の守備範囲外)。考えられる仮説 (未検証、あくまで推測):
  前回走行時の一時的な状態 (二重送信テスト中の並行操作・reseed タイミング・ブラウザキャッシュされた
  古い Inertia ページの再利用など) が誤って「アクセス可能」に見えた可能性。本走行では
  cookie-clear を挟んだ完全な別ログインで確認しており、キャッシュ由来の誤検知は排除している。
- 証跡: `.playwright-cli/page-2026-08-22T15-18-27-301Z.yml` (削除後ダッシュボード=組織未選択)、
  `.playwright-cli/page-2026-08-22T15-18-36-111Z.yml` (/projects 404)、
  `.playwright-cli/page-2026-08-22T15-18-39-726Z.yml` (/billing 404)、
  `.playwright-cli/page-2026-08-22T15-18-40-180Z.yml` (/manage/users 404)、
  `.playwright-cli/page-2026-08-22T15-19-05-855Z.yml` (owner 側で再出現しないことを確認)。

## 検証目的2: F-2-02 (招待受諾の宛先メール照合、T237 修正確認) — 結論: **修正OK。退行なし**

1. owner-personal@example.com が `/manage/users` から `shard2-f202-target@example.com` 宛にメンバー招待を
   送信 (「招待中」欄に出現を確認)。
2. `tmp/bug-hunt/shard-2-cmd.sh mail-urls --count 3` で受諾 URL
   (`/invitations/accept?token=fLHcgaVQeaOLtjOy8Of1oru1LPFa7eOUHkUQoW3BAl9JG9Gfoki01IVNFTquRpKE`) を取得。
3. **招待先とは全く別メールアドレス**の既ログインユーザー owner-starter@example.com
   (別組織「Starterプラン組織」のオーナー) としてログインし、上記 URL を直接開いた。
   - 画面には受諾ボタンが**表示されない**。代わりに「この招待は別のメールアドレス宛に送信されています。
     招待メールを受け取ったアドレスでログインし直してください。画面右上のメニューから ログアウトし、
     招待メールのリンクをもう一度開いてください。」という案内文のみ。
   - 招待先の組織名 (「Personalプラン組織」) はどこにも表示されていない
     (画面テキスト・初期 Inertia payload の両方で確認、下記参照)。
4. **サーバ側の照合も確認** (UI 上でボタンが消えているだけでないことの裏取り): devtools 相当で
   `fetch('/invitations/accept', {method:'POST', body: token=...})` を直接叩いても、
   `/dashboard` へリダイレクトされ (200)、reload 後も招待画面は「別のメールアドレス宛」の案内のまま。
   ヘッダーの組織切替メニューを開いても「Starterプラン組織」のみでメンバー一覧に加わっていないことを確認
   (= サーバ側の `joinOrganization` 相当のロック下再照合が実際に拒否している)。
5. 初期 Inertia payload の漏洩確認: `fetch(location.href, {headers:{Accept:'text/html'}})` の生レスポンス
   本文 (2691 文字) を全文検査し、`organizationName\":null` であることを確認。招待先組織名の文字列
   (「Personalプラン組織」「プラン組織」) はどこにも含まれていなかった (ヒットしたのは
   ログイン中ユーザー自身の所属組織一覧内の無関係な "Personal" というフィールド値の断片ではなく、
   全文 grep で当該組織名/`プラン組織`の文字列自体が 0 件)。**T237 の「organizationName を payload から
   null で落とす」修正が効いていることを確認**。
- 証跡: `screenshots/verify2-invite-mismatch-no-org-join.png` (別メールアドレスユーザーに拒否案内が表示され
  受諾ボタンが無い状態)、`.playwright-cli/page-2026-08-22T15-20-17-203Z.yml` (受諾画面初回表示)、
  `.playwright-cli/page-2026-08-22T15-21-38-365Z.yml` (直POST後もorg切替メニューにStarterのみ)。

## findings
(退行・実害は検出しなかった。findings.jsonl は作成していない。)

## インベントリ修正提案
なし (本走行は特化検証のためインベントリ走査は行っていない)。

## 補足: 環境メモ
- `playwright-cli open` は既定 (`--browser` 省略、chrome チャンネル) だと
  `Chromium distribution 'chrome' is not found at /opt/google/chrome/chrome` で daemon が exit code 1 になる。
  `--browser chromium` を指定することで正常に起動できた (chromium-1237 がキャッシュ済み)。
  環境ハザードとしては扱わず、本走行内で回避した (他 shard でも同様の詰まりが起き得るため申し送り)。
- `playwright-cli screenshot <path>` は position 引数が target セレクタ扱いになりエラーになる。
  `--filename <path>` オプションを使う必要がある。
