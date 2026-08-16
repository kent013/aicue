# Round 2: Round 1 の指摘への対応と再レビュー依頼

Round 1 の全体判定は CHANGES_REQUESTED (Critical 3 / Warning 8 / Suggestion 2) でした。
**Critical 3 件・Warning 8 件・Suggestion 1 件をすべて対応しました** (反論・見送りは 0 件)。

対応マトリクスを先に示し、その後に修正後の詳細設計書の全文を添付します。
概念設計側も同期修正しています (適格条件の再編・案 A の表現弱化・台帳判断の一本化)。

---

# 対応マトリクス: design-review Round 1

**Codex 全体判定: CHANGES_REQUESTED** (Critical 3 / Warning 8 / Suggestion 2)。
**Critical 3 件・Warning 8 件をすべて対応した** (反論・見送りは 0 件)。

---

## [Critical] (C) 第 1 段「招待リンク手渡し + メール検証免除」が「メールを 1 通も送らずに撮影者を増やす」を成立させる設計として不足 (synthetic email 未整理)

- 判断: **対応する**
- 根拠: 指摘が完全に正しい。「識別子は email のままでダミーでよい」で止めると、
  **実在ドメインへの誤配送 / パスワード再設定の空振り / 検証メールの送信 / SES バウンス率の悪化**が
  未整理のまま残る。第 1 段は「メールを送らない」ことが目的なのに、
  メール系の配線を触らない設計になっていた = 目的を達成できない。
- 対応内容: **§6-2a「識別子として使う email の扱い」を新設**し、必須決定点 8 項目を列挙:
  (a) 合成 email のドメイン規則 (配送されない予約領域か MX なしの自社サブドメイン。
      利用者入力の任意ドメインを許さない) /
  (b) 合成 email であることの記録 (**導出でなく列で持つ**を既定に) /
  (c) メール送信の抑止 (`routeNotificationForMail`) /
  (d) 検証通知の抑止 /
  (e) パスワード再設定の扱い (**「送信しました」と嘘をつかない**) /
  (f) 通知の代替経路 (通知センター) /
  (g) 初回パスキー登録の促し (**disabled にしない** = 禁止事項 8) /
  (h) `email_verified_at` の由来。

## [Critical] (C) 平文 token の画面表示リスク評価が不足 (Inertia props / 履歴 / ログ / Referer / キャッシュ / bfcache / 監査)

- 判断: **対応する**
- 根拠: 指摘が正しい。旧版は「肩越しの盗み見・スクショ・チャット転送」しか挙げておらず、
  **技術的な露出面**を数え落としていた。とくに Inertia props はマークアップに埋め込まれるため、
  本アプリのドメイン規約 3 (no-store baseline / bfcache 秘匿 / Inertia history 暗号化) と
  直接関係する論点である。
- 対応内容: **§6-2c「平文 token の露出面」を新設**し、
  **10 個の必須条件**を「1 つでも欠けたら第 1 段を実装してはならない」として定義:
  1 回表示 / 平文非保存 / 短 TTL / single-use + 明示失効 / Inertia props と no-store の確認 /
  **URL に載せない** / サーバログに出さない / bfcache・キャッシュ対策 /
  発行・表示・コピー・再発行・失効の監査 / `manageMembers` の認可。
  §6-8 リスクにも露出面の列挙を追記した。

## [Critical] (G) 招待 token の秘匿設計が現行から後退する。実装条件としての最低ラインが曖昧

- 判断: **対応する** (上の Critical と同じ修正で解消)
- 根拠: セキュリティ不変条件に直結する。現行の「平文は DB に無くメールにしかない」を崩す以上、
  最低ラインを設計書に固定しておかないと、昇格時に「一覧に出すだけ」の実装が通ってしまう。
- 対応内容: §6-2c で **credential disclosure と明記**し、上記 10 条件を昇格時の必須条件とした。

---

## [Warning] (A) 「情報は満たされている」「日常ログインは入力ゼロ」が強すぎる

- 判断: **対応する**
- 根拠: AGENTS.md が繰り返し要求する「保証範囲を誇張しない」に反する。
- 対応内容: §4-1 の最終ログイン行を
  「**監査イベントから導出可能。ただし管理画面要件としては未表示 (= 未充足)**」へ変更。
  「入力ゼロ」は概念設計 Round 1 で既に条件付きへ弱めており、詳細設計 §4-2(f) にも
  成立条件 4 つを明記済みであることを確認した。

## [Warning] (A/Suggestion) 「削除 = 除名で満たす」は要件が users 行削除を意味していた場合に争点になる

- 判断: **対応する**
- 対応内容: 判定を「**v1 の組織運用上は除名で足りる** (要件が users 行の物理削除を
  意味していた場合は未充足)」へ限定した。

## [Warning] (B) 適格条件 C「TOTP 2FA を強制しない」を昇格の必須条件にするのは厳しすぎる

- 判断: **対応する**
- 根拠: 指摘が正しい。**「メールを受け取れないので入れない」問題と
  「日常ログインの入力を減らせる」問題は別**であり、混ぜると
  「2FA を強制する現場は永久に昇格しない」という判定不能な条件になる。
  Conditional の目的は「実需が来たら拾う」ことなので、拾えなくなる条件は誤りである。
- 対応内容: **適格条件から TOTP の項を削除し、適格条件を A / B / C の 3 つに再編**。
  2FA 方針は **§6-2 の決定点 8 (昇格後の設計における追加決定点)** へ移した。
  概念設計側 (§7-2 の適格条件表・判定例の表・§8 の参照) も同じ形へ同期した。

## [Warning] (B) 適格条件 D「メールなしで本人だけが回復する手段は存在しない」の断定が強い

- 判断: **対応する**
- 根拠: 指摘が正しい。リカバリコード / 管理者承認付き再発行 / パスキー再登録による回復など
  代替設計はあり得る。存在しないのは**現行の Fortify email broker での自力回復**だけである。
- 対応内容: 新しい適格条件 C を
  「**現行の Fortify email broker では自力回復が成立しないことを承知した上で、
  代替回復方式を設計対象に含めることが合意されている**」へ書き換え、
  断定を明示的に取り消す注記を詳細設計・概念設計の両方に置いた。

## [Warning] (C) `email_verified_at` に「メール所有確認」と「手渡しで業務利用許可」を畳んでいる

- 判断: **対応する**
- 根拠: 指摘が正しい。2 つの別概念を 1 列に畳むのは思考原則 4 に反する。
  既存 middleware (`verified`) の都合でこの列を使うのはやむを得ないが、由来は残せる。
- 対応内容: **§6-2b を新設**。最低限 `SecurityEventType` に手渡し招待による検証免除の case を足して
  監査イベントに残すこと (case 追加は `SecurityEventCoverageTest` により記録経路の同一 PR 配線が必須)、
  望ましくは招待種別から辿れるようにすること、
  **「由来を残さずに `email_verified_at` だけ立てる」ことを禁止**することを明記した。

## [Warning] (D) 「新規エントリは機械検査で作れない」は根拠として弱い

- 判断: **対応する**
- 根拠: 指摘が正しい。記録すべき逸脱なら台帳構造や D8 の側を直してでも記録するのが正しく、
  機械検査の都合は登録不要の理由にならない。旧版は根拠を並列 4 点にしていたため、
  弱い根拠が主根拠と同格に見えていた。
- 対応内容: **主根拠を「本件はテンプレートからの逸脱ではない」1 本に一本化**し、
  2〜4 を「**補助的事情 (単独では登録不要の根拠にならない)**」として明示的に格下げ。
  根拠 3 には「これは『登録すべきなのに登録できない』理由にはならない」と自ら注記した。

## [Warning] (D) 「台帳対象外」と「D8 登録済み」が噛み合っていない

- 判断: **対応する**
- 根拠: 指摘が正しい。D8 は管理 UI / ロール語彙の逸脱であり、
  認証識別子そのものをカバーしているとは言えない。
- 対応内容: 補助的事情 2 を「**関連する管理画面の差分**は既に記録済み」に限定し、
  「**D8 が認証識別子そのものをカバーしているとは主張しない**」と明記。
  認証識別子は主根拠のとおりそもそも逸脱ではないので、どの登録もカバーする必要が無い、
  という論理へ整理した。
  併せて **「主根拠が崩れる条件」** を新設し、第 1 段・第 3 段を実装する PR は
  その変更の中で登録の要否を判定することを義務づけた
  (台帳の原則「登録は逸脱を作る変更そのものに含める」との接続)。
  概念設計 §7-3 も同じ形へ同期した。

## [Warning] (F) 波及リストにメール送信系・回復系が足りない

- 判断: **対応する**
- 対応内容: §6-4 に 5 行追加 —
  **メール送信系** (`Registered` event / verification notification の抑止 /
  `routeNotificationForMail` / `EmailSuppression` + SES webhook) /
  **回復系** (password broker / `password_reset_tokens` / `Features::resetPasswords()` の画面) /
  **通知の代替経路** (`NotificationCenterService`) /
  **ログ・ヘッダ** (受諾 URL と平文 token がログ・`Referer` に出ないこと、no-store と
  Inertia history 暗号化の確認) /
  **route middleware のテスト** (`verified` を route 単位で外すなら deny-by-default 目録の更新)。

## [Warning] (F) 第 3 段の「特大」の内訳が未明示 (移行計画が無い)

- 判断: **対応する**
- 根拠: 「特大」とだけ書くと、昇格時に「思ったより小さい」と誤認されうる。
- 対応内容: **§6-3a「第 3 段は別設計必須。決定点だけ置く」を新設**し、8 項目を列挙:
  既存ユーザーの `login_id` 採番 / データ移行の順序 (**移行完了時に email 識別子を消す計画まで
  含めて 1 つの変更にする** = 思考原則 3 との整合) / 既存セッションと remember token /
  SSO の紐付け / 監査ログ上の識別子表示 / サポート運用の本人確認 / PII 判定 / 回復手段。
  §6-1 の段階表の第 3 段の行にも「本書では設計しない = 別設計必須」を明記した。

## [Warning] (G) 案 A の「管理者初期パスワード発行と同等以下」と言い切るのは危険

- 判断: **対応する**
- 根拠: 指摘が正しい。責任者がメールボックスを持ち続ける限り
  **恒久的になりすませる**状態が続くため、軽い代償ではない。
- 対応内容: 概念設計 §3-3 から「同等以下」という結論的表現を撤去し、
  言えるのは「**招待制だから固有になりすまし可能性が生まれる、という非対称は無い**」までに限定。
  案 A を「**監査上許容される場合にだけ採れる運用仮説**」と再定義し、
  §6 の案 A 行の代償欄も同じ表現へ揃えた。

---

## [Suggestion] (E) 「要件未充足の体感の相当部分が消える」は過大評価の可能性

- 判断: **対応する** (Suggestion だが主張の強さの問題なので直した)
- 対応内容: 概念設計 §7-1 根拠 6 と詳細設計 §6-5 の両方に
  「**これは一覧要件の一部を満たす小改善にすぎず、ユーザー登録方式そのものへの不満とは別物**」
  と明記した。

---

## 再レビュー依頼

Round 1 と同じ観点 (A)〜(G) で再判定してください。とくに次を重点的に見てください:

1. **§6-2a (合成 email の扱い) と §6-2c (平文 token の露出面) が、
   第 1 段を安全に実装するための条件として十分か。** まだ致命的な漏れがあるか。
2. **§5 の適格条件が A / B / C の 3 つになったこと**で、
   「厳しすぎて永久に昇格しない」問題が解消したか。逆に緩すぎて誤って昇格しないか。
3. **§7 の台帳判断の一本化 (主根拠 1 本 + 補助的事情 3 点への格下げ) が論理として成立しているか。**
   とくに新設した「主根拠が崩れる条件」(第 1 段・第 3 段を実装する PR は、その変更の中で
   登録の要否を判定する) が妥当か。
4. **§6-3a (第 3 段の決定点 8 項目) に致命的な漏れが無いか。**
5. 見送り結論 (should_implement=false) そのものを支持できるか。

全体判定 (APPROVED / CHANGES_REQUESTED) を必ず出してください。

---

## 修正後の詳細設計書 (全文)

# 詳細設計: user-provisioning-model-divergence (ユーザー登録方式の要件差の評価)

> **本タスクの成果物は「実装しない」という判断そのものである。**
> アプリのコード変更は **0 件**。本書は「なぜ今作らないのか」「どうなったら作るのか」
> 「作るときは何をどの順で作るのか」を、実装できる粒度で残す設計書である。
> 作法は T193 (`devnotes/20260816-1754-video-manual-visibility-scope/detailed-design.md`) に揃える。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → `PromptDefense` → `GuardedPrompt` の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール (将来実装する場合に適用されるもの)

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase** はグローバル適用、個別 `DatabaseTransactions` 禁止
- テストデータは必ず Factory で生成。新モデルには Factory 作成も施策に含める
- **DTO + JsonResource** パターン
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- **PII(email/name)は CipherSweet。検索は `whereBlind()`**(セキュリティ不変条件)
- **後方互換の並走を残さない**(思考原則 3)

## 概念設計リファレンス

- `devnotes/20260817-0003-user-provisioning-model-divergence/conceptual-design.md`
  (Codex 概念設計レビュー **Round 1 で APPROVED**。Warning 4 件は全件反映済み)

---

## 1. 判断

**今は作らない (テンプレート基盤の設計差として維持し、Conditional として条件付き保留する)。**

- `should_implement = false`
- **アプリコードの変更は 0 件**。`app/` `resources/` `routes/` `database/` `config/` `tests/` を
  1 行も触らない。
- `docs/template-divergence.md` への**新規登録も行わない** (§7 で根拠を示す)。
- 本タスクが生む Open タスクは無い。`docs/TODO.md` へは **Conditional** として登録する
  (登録操作は後続の別エージェントの責務。本文の草案は §7-1)。
- 別途起票できる**独立した小改善が 1 件**ある (最終ログイン日時の表示。§6-5)。
  本タスクの結論には紐付けない。

---

## 2. 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| — | **実装施策は 0 件** | なし | — |

### 非実装の成果物 (本タスクが残すもの)

| # | 成果物 | 置き場所 | 目的 |
|---|--------|---------|------|
| N1 | 概念設計 (判断の根拠・現行コードの実読事実) | `devnotes/20260817-0003-user-provisioning-model-divergence/conceptual-design.md` | 同じ議論の再燃時に、コードを読み直さずに前提へ到達できるようにする |
| N2 | 詳細設計 (本書。参照設計・昇格手順・登録判断) | 同 `detailed-design.md` | 昇格したときに設計をやり直さない |
| N3 | Codex 合議履歴 | 同 `codex-history/` | 判断の外部レビュー痕跡 |
| N4 | Conditional 登録用の本文 | 本書 §7-1 | 後続の登録エージェントがそのまま使う |
| N5 | ブリーフ前提の訂正 4 件 | 概念設計 §0 | 誤った前提のまま次の判断が積み上がるのを防ぐ |

---

## 3. 変更箇所 / 波及変更

### 変更箇所

**なし。** 本タスクで書き換えるファイルは `devnotes/` 配下のみである。

### 波及変更

| 種別 | 内容 |
|---|---|
| TypeScript 型定義 | **なし** (`resources/js/types/admin.ts` の `MemberRow` も触らない) |
| Inertia Props | **なし** |
| API Resource / DTO | **なし** (`MemberRowData` / `InvitationRowData` とも不変) |
| テストファイル | **なし** (既存テストの追加・変更・削除をいずれも行わない) |
| migration | **なし** (`users` へ `login_id` / `last_login_at` を追加しない) |
| config | **なし** (`config/fortify.php` の `'username' => 'email'` を維持) |
| ドキュメント | **なし** (`docs/template-divergence.md` への登録も行わない。§7) |

> **これは「変更が無いこと」自体が成果物である**。要件書 (`doc/02` `doc/04` `doc/05`) と
> 実装の差を見て反射的にカラムを足す変更を**入れない**ことが本タスクの結論である。

---

## 4. 何が満たされているから不要なのか (現行コードによる裏付け)

### 4-1. 要件項目ごとの充足マトリクス

「満たされている」と「別形式で満たされている」と「意図的に採らない」を区別する。

| 要件 (出典) | 現行の状態 | 判定 | 根拠 (実読) |
|---|---|---|---|
| ユーザー一覧表示 (`doc/04 §4.2`) | `/manage/users` が name / email / 役割 / 2FA 状態を表示 | **満たされている** | `app/Http/Controllers/Admin/UserManagementController::index` / `app/DataTransferObjects/Admin/MemberRowData` |
| 新規登録 (`doc/04 §4.2`) | 招待 → 本人登録。管理者の直接発行は無い | **意図的に採らない** | `OrganizationMembershipService::inviteMember` / `Actions/Fortify/CreateNewUser`。`docs/template-divergence.md` D8 が reconcile 済み |
| 編集 = 役割 (`doc/04 §4.2`) | 3 値遷移コマンドで変更可 | **満たされている** | `OrganizationMembershipService::applyConsoleRole` / `App\Enums\AdminConsoleRole` |
| 編集 = 表示名・メール (`doc/04 §4.2`) | 管理者からは不可 (本人の Settings のみ) | **意図的に採らない** | D8 の PII 最小化判断 |
| 削除 (`doc/04 §4.2`) | 組織からの除名は可。users 行の削除は本人の退会予約のみ | **v1 の組織運用上は除名で足りる** (要件が users 行の物理削除を意味していた場合は未充足) | `OrganizationMembershipService::removeMember` / `requestAccountDeletion` |
| `ユーザーID` 英数 20 字・重複不可 (`doc/02 §2.4`) | 列が無い。識別子は email | **表現できない** | `database/migrations/0001_01_01_000000_create_users_table.php` / `config/fortify.php` `'username' => 'email'` |
| パスワード 半角英数 8〜16 字 (`doc/04 §4.2`) | 12 字以上 + 大小混在 + 数字 + HIBP | **意図的に上書き (要件より強い)** | `app/Support/PasswordPolicy` |
| メールアドレス = 任意 (`doc/02 §2.4`) | **必須**かつ認証識別子 | **逆転している** | `CreateNewUser` の rules / `EncryptedUserProvider` |
| 最終ログイン日時 (`doc/02 §2.4`) | カラムは無いが **`security_audit_events` から索引付きで導出可能**。UI 露出は無い | **監査イベントから導出可能。ただし管理画面要件としては未表示 (= 未充足)** | `2026_06_11_071300_create_security_audit_events_table.php` (index `['user_id','event_type']` と `occurred_at`) / `app/Listeners/RecordSecurityEvent` (`SecurityEventType::Login`) |
| 所属 ID (`doc/02 §2.4`) | `Organization` へ写像済み | **満たされている** | `User::organizations()` |
| 権限 (管理者・一般) (`doc/02 §2.4`) | `OrganizationRole` + `ProjectRole` + Policy | **満たされている (doc/10 §10.5 の確定値)** | `App\Enums\OrganizationRole` / `App\Enums\ProjectRole` |
| ユーザー ID + パスワードでログイン (`doc/05 §5.2`) | email + パスワード / パスキー / SSO | **識別子が違う** | `config/fortify.php` |

**確定仕様との関係**: `doc/10 §10.8` が「実装時はこの節が §10.1〜§10.7 に優先する」と宣言する
確定仕様であるにもかかわらず、doc/10 は認証について
「撮影アプリは PWA/Web（同一オリジン・セッション認証）」と「§10.5 ロール」しか定めていない。
**識別子方式は確定仕様に含まれていない。**

### 4-2. 現行コード (認可・認証の入口の全体像)

#### (a) 認証識別子は email に固定されている

`config/fortify.php`:

```php
'username' => 'email',
'email' => 'email',
'lowercase_usernames' => true,
'passwords' => 'users',
```

`app/Auth/EncryptedUserProvider.php` (email だけが blind index 検索になる):

```php
foreach ($credentials as $key => $value) {
    if (str_contains($key, 'password')) { continue; }
    if ($key === 'email') {
        Assert::string($value);
        $query->whereBlind('email', 'email_index', $value);
    } else {
        $query->where($key, $value);
    }
}
```

#### (b) `users` に識別子列も最終ログイン列も無い

`database/migrations/0001_01_01_000000_create_users_table.php` が持つのは
`id` / `name`(text, 暗号化) / `email`(text, 暗号化) / `email_verified_at` /
`password`(nullable) / `terms_accepted_at` / `consent_version` / `rememberToken` / timestamps のみ。
email の一意性はマイグレーションのコメントどおり
**`blind_indexes` テーブルの partial unique + 登録時の `whereBlind` 明示チェック**で担保している。

#### (c) 撮影 PWA はメール検証済みでなければ 1 画面も開けない

`routes/web.php` L191:

```php
Route::middleware(['auth', 'verified', 'not-pending-deletion'])->group(function (): void {
```

撮影 PWA の group (`->prefix('app')->as('capture.')`) はこの内側にあり、
さらに `require-active-subscription` / `project.in-current-org` が重なる。
`User implements MustVerifyEmail` かつ `config/fortify.php` に `Features::emailVerification()`。

#### (d) 招待の受け渡し手段はメールだけ (平文 token は DB に無い)

`OrganizationMembershipService::inviteMember()`:

```php
$plainToken = OrganizationInvitation::generateToken();
$invitation->forceFill([
    'role' => $role->value,
    'token_hash' => OrganizationInvitation::hashToken($plainToken),
    'expires_at' => now()->addDays(self::EXPIRES_DAYS),
]);
$invitation->save();

Notification::route('mail', $email)->notify(new OrganizationInvitationNotification(
    organizationName: $organization->name,
    acceptUrl: url('/invitations/accept?token='.$plainToken),
));
```

アプリ内受諾 (`AcceptInvitationInAppController` / 裁定 AG-113) の受諾根拠は
**「auth 済み ∧ email 確認済み ∧ ログイン者 email = 招待宛先」** (`pendingInvitationsQuery`) であり、
`routes/web.php` のコメントが明記するとおり
**「未登録の人にはメールが唯一の入口」**である。

#### (e) パスキーは email に依存していない (ブリーフ前提の訂正)

`vendor/laravel/passkeys/src/PasskeyAuthenticatable.php`:

```php
public function getPasskeyUserHandle(): string
{
    return hash_hmac('sha256', $this->getTable().'|'.$this->getKey(),
        Config::string('passkeys.user_handle_secret'), binary: true);
}
```

= **テーブル名 + 主キー**の hmac。識別子を email から別の列へ変えても
**登録済みパスキーは 1 件も無効にならない**。`PASSKEYS_USER_HANDLE_SECRET` の運用要件
(AGENTS.md) が守っているのは `APP_KEY` ローテートに対する耐性であり、識別子の話ではない。
TOTP 2FA も `users.two_factor_secret` に閉じており email 非依存。

#### (f) 日常ログインはパスキーで入力を大きく減らせる (条件付き)

`vendor/laravel/passkeys/src/Http/Controllers/PasskeyLoginController::index` は
`GenerateVerificationOptions` を**引数なし**で呼び、
`allowCredentials(null)` が `[]` を返すため **discoverable credential (ユーザー名入力なし) ログイン**になる。
`config/fortify.php` に `Features::passkeys(['confirmPassword' => false])`。

**成立条件 (誇張しない)**: (1) パスキー登録済み / (2) 端末・ブラウザが対応 /
(3) TOTP 2FA が強制されていない (`app/Services/Auth/PasskeyLoginPolicy` が
TOTP confirmed を拒否する) / (4) `userVerification=REQUIRED` のため端末の生体・PIN が使える。

### 4-3. 入れた場合に壊れる既存の前提

| 前提 | 壊れ方 |
|---|---|
| `config/fortify.php` の `'username' => 'email'` と `EncryptedUserProvider` の email 分岐 | 識別子の置換で両方を同時に書き換える必要がある |
| email の一意性 = `blind_indexes` の partial unique | 新識別子を平文列にすると通常 unique が使えるが、識別子が PII 相当になった瞬間にセキュリティ不変条件 6 (PII は CipherSweet / 検索は `whereBlind`) と衝突する |
| `password_reset_tokens.email` (主キー) + Fortify `'passwords' => 'users'` broker | メールが任意になると**本人の自力回復手段が消える**。代替は管理者リセット = 権限集中の恒久化 |
| アプリ内受諾の根拠「ログイン者 email = 招待宛先」(裁定 AG-113) | email が任意になると**受諾根拠そのものが消える**。裁定の再設計が要る |
| `verified` が業務 group 全体に掛かる構造 | メール任意なら「検証を通せない利用者」が構造的に生まれる。group 設計の変更は `ManageRouteAuthGuardTest` 等の deny-by-default 目録に触れる |
| 通知経路 (`Notifiable` の mail channel / `EmailSuppression` / SES webhook) | メール未設定の層には通知が届かない |
| SSO (`SocialAccountService` / `SocialiteDriverResolver`) | IdP の identity は email 中心。ID 方式と併存させると突合規則の再設計が要る |
| **思考原則 3 (後方互換の並走を残さない)** | **「メール任意 + 管理者発行の識別子を併設」という中間案が規約上採れない**。採るなら email 識別子を消すことになり、上の全部を払う |
| `PasskeyLoginPolicy` / `getPasskeyUserHandle` / TOTP | **壊れない** (4-2 (e)) |

---

## 5. どうなったら必要になるのか (Conditional の昇格条件)

概念設計 §7-2 が正本。要点を再掲する。

### T-1 (主条件)

**記録条件 5 つ**がすべて書かれ、かつ**適格条件 A / B / C がすべて「はい」**である要求が 1 件でも来たとき。
**記録があるだけでは昇格しない。**

記録条件: (1) 対象組織・人数・役割と要求元 / (2) メールボックスを用意できない理由 /
(3) **共有メールボックス・サブアドレスでも不可である理由** / (4) 許す操作の範囲 /
(5) なりすまし許容度。

適格条件:

| # | 適格条件 | 満たさないときの行き先 |
|---|---|---|
| A | 要求の実体が **「認証の入口」** である | 「呼称が欲しい」→ 案 D (表示専用の識別子) / 「誰がいつ入ったか」→ 最終ログイン日時の表示 (§6-5) |
| B | **案 A が実地で不成立** = 概念設計 §3-4 の 6 条件のうち 1 つ以上が崩れている事実が記録されている | 成立するなら実装不要。運用手順書の整備で閉じる |
| C | **本人の自力パスワード回復について、現行の Fortify email broker では成立しないことを承知した上で、代替回復方式を設計対象に含めることが合意されている** | 「代替は要らない・管理者リセットで足りる」なら**権限集中を許容した設計**として進める。「本人だけが回復できねばならない」なら**回復方式の設計が第 1 段の前提条件**になる |

**適格条件は 3 つ (A / B / C) である。**
判定例 (「個人メールがないだけ」は B を満たさない 等) は概念設計 §7-2 の表が正本。

**適格条件から外したもの (Round 1 の指摘を反映)**:
**「対象利用者に TOTP 2FA を強制しない」は昇格の必須条件にしない。**
「メールを受け取れない利用者が入れない」問題と、「日常ログインの入力を減らせる」問題は
**別の問題**であり、混ぜると「2FA を強制する現場は永久に昇格しない」という
厳しすぎる条件になる。TOTP 強制下でもメールなし入口の必要性は独立に成立するため、
2FA 方針は**昇格後の設計における追加決定点** (§6-2 の決定点 8) として扱う。

**回復手段について (Round 1 の指摘を反映して断定を弱める)**:
「メールなしで本人だけが回復する手段は存在しない」とは**書かない**。
正確には **「現行の Fortify email broker (`password_reset_tokens.email` 主キー) では
自力回復できない」**であり、リカバリコード方式・管理者承認付き再発行・
パスキーの再登録による回復など、**代替設計はあり得る**。
ただしそれらは本設計の対象外であり、採るなら**別途設計する**。

### T-2 / T-3

- **T-2**: `verified` を業務 group から外す / メール検証の免除経路を作る判断が**別要件で**入ったとき
  (§6 の第 1 段のコストが大きく下がるので本設計を読み直す)。**自動昇格ではない。**
- **T-3**: `doc/04 §4.2` の入力制約が受入検査の対象として顧客と合意され、
  カラムの存在自体が契約要件になったとき。**案 D で足りるか**を最初に問う。
  **パスワードの「半角英数 8〜16 字」は契約要件であっても採らない** (セキュリティの後退)。

**昇格条件ではないもの**: 「ID の方が現場に馴染む」という選好のみ。

### 5-1. 昇格したときに最初にやる 4 手順 (設計のやり直しを防ぐ)

1. **要求の実体を §5 の適格条件 A で分類する**。案 D / 最終ログイン日時表示に落ちるなら
   そちらを起票して終わる (認証基盤に触らない)。
2. **§6 の第 1 段 (案 C) だけで足りるかを判定する。** 足りるなら第 2 段以降を設計しない。
3. 第 1 段を実装する場合、**「平文 token を画面に出す」ことのセキュリティ設計**
   (露出範囲・有効期限短縮・監査記録・再発行) を**先に**決める。ここが本体である。
4. 第 2 段以降 (識別子の置換) に進む場合、**旧識別子を同じ変更で消す計画**を最初に立てる
   (思考原則 3。並走させる設計は書かない)。

---

## 6. 将来実装する場合の参照設計 (**実装しない**)

> 本節は昇格時の出発点であり、**今回 1 行も実装しない**。
> コードは形を示すためのスケッチであり、そのまま使える保証はしない。

### 6-1. 段階の切り方 (認証基盤の作り替えを一度にやらない)

| 段 | 内容 | 何が成立するか | 規模 | 触る不変条件 |
|---|---|---|---|---|
| **第 1 段 (案 C)** | 招待リンクの**手渡し** + 招待経由登録の**メール検証免除** | メールを 1 通も送らずに撮影者を増やせる。**識別子は email のまま** (エイリアス / ダミーでよい) | 中 | 招待 token の秘匿 / `verified` の免除設計 |
| **第 2 段 (案 D)** | `users` または組織メンバーシップに**表示専用の識別子**を足す | 現場台帳との突合。**認証には使わない** | 小 | PII 判定 (CipherSweet 対象か) |
| **第 3 段 (案 E)** | 認証識別子を email → ID へ**置換** | 要件書の字面どおり | 特大。**本書では設計しない = 別設計必須** | §4-3 のほぼ全部 + §6-3a の移行計画 |
| 独立 | **最終ログイン日時の表示** (§6-5) | 要件の 1 項目が UI に出る | 小 | なし |

**第 1 段だけで §5 の要求が満たされるなら、第 2・3 段は作らない。**

### 6-2. 第 1 段 (案 C) の設計上の決定点

昇格時に最初に決めるべきことを列挙する。**答えは今出さない** (要求が確定していないため)。

| # | 決定点 | 選択肢と代償 |
|---|---|---|
| 1 | 平文 token をどこまで出すか | (あ) 発行直後 1 回だけ画面に出して以後は再表示不可 (再発行のみ) / (い) 一覧に常時表示。(い) は**肩越しの盗み見・スクショ・チャット転送**で資格情報が流出する。現行の「平文は DB に無くメールにしかない」という不変条件を崩す以上、(あ) が既定 |
| 2 | 平文 token を保存するのか | 保存しない (発行応答にだけ載せる) を既定にする。保存するなら**暗号化 + 短い有効期限 + 閲覧の監査記録**が必須。`OrganizationInvitation` の「token_hash のみ保存」を崩す判断になる |
| 3 | 有効期限 | 現行 `EXPIRES_DAYS = 7`。手渡し経路は**もっと短く**する (画面表示は流出面が広い) |
| 4 | メール検証の免除範囲 | (あ) 「手渡し招待から登録した利用者だけ `email_verified_at` を確定する」/ (い) `verified` を group から外す。(い) は**全利用者に波及する**ので採らない。(あ) でも「その email の所有者であることの証明を捨てた」事実は残るので、**監査イベントに残す**こと |
| 5 | 招待メールを送るかどうかの選択 | 「メールを送らない招待」を新設するのか、既存の `inviteMember` に分岐を足すのか。**後者は 1 メソッドが 2 つの意味を持つ**ので、招待の種別を型 (enum) で表す方が良い |
| 6 | 認可 | 手渡し招待の発行は `manageMembers` (owner / admin)。`Gate::authorize` を通す (セキュリティ不変条件 9) |
| 7 | 流量制限 | 変更系かつ認証面に近いので named limiter を 1 本持つ (ドメイン規約 5。inline throttle は使えない) |
| 8 | 2FA 方針 | 対象利用者に TOTP 2FA を強制するか。強制するとパスキーログインが `PasskeyLoginPolicy` で塞がれ、日常ログインの入力削減が消える。**昇格の必須条件ではないが、第 1 段の設計時に必ず決める** |

#### 6-2a. **識別子として使う email の扱い (Round 1 Critical。第 1 段の中核)**

第 1 段は「識別子は email のまま (エイリアス / ダミーでよい)」とするが、
**「ダミーでよい」で済ませると壊れる**。実在ドメインへの誤配送・回復導線の空振り・
検証メールの送信が未整理のままになるため、次を**第 1 段の必須決定点**とする。

| # | 決定点 | 何を決めるのか / 既定案 |
|---|---|---|
| a | **合成 email のドメイン規則** | 実在ドメインへ誤配送しないことが第一条件。RFC 2606 / RFC 6761 の予約領域 (`.invalid` / `.test` など配送されないことが保証される領域) か、組織が所有し MX を持たないサブドメインを使う。**利用者が入力した任意のドメインを許さない** (誤配送とアカウント乗っ取りの両方の口になる) |
| b | **合成 email であることの記録** | 「配送先として無効」を後から機械で判別できる必要がある。**フラグ列 (例: `users.email_deliverable`) を持つか、ドメイン規則から導出するか**を決める。導出は規則変更で壊れるので、**列で持つ方を既定**とする |
| c | **メール送信の抑止** | 合成 email へ**一切送らない**。`Notifiable` の mail channel を経路ごとに塞ぐ (`routeNotificationForMail` が null を返す形が素直)。抑止しないと SES のバウンス率が上がり、`EmailSuppression` に無関係のアドレスが積まれる |
| d | **メール検証通知の抑止** | `MustVerifyEmail` の verification notification を送らない。第 1 段では**招待の手渡し受諾をもって `email_verified_at` を確定**するため、そもそも送る必要が無い |
| e | **パスワード再設定の扱い** | 合成 email には送れないので、`/forgot-password` は**その利用者に対して沈黙してはならない** (「送信しました」と嘘をつくと詰む)。**管理者へ依頼する導線を出す**か、代替回復方式 (リカバリコード等) を設計する。**どちらも作らないなら「回復手段が無い」ことを運用に明記する** |
| f | **通知経路の代替** | メールが届かない層には**アプリ内通知センター** (`NotificationCenterService`) が唯一の経路になる。第 1 段の対象利用者に必要な通知が通知センターに載っているかを確認する |
| g | **初回パスキー登録を必須にするか** | 合成 email の利用者はパスワードを忘れると回復できない。**初回ログイン後にパスキー登録を促す**導線を持つかを決める。ただし **必須条件未充足でボタンを disabled にしない** (禁止事項 8) |
| h | **`email_verified_at` に由来を残すか** | 下記 6-2b |

#### 6-2b. **`email_verified_at` の由来を残す (Round 1 Warning)**

第 1 段で `email_verified_at` を立てると、
**「メールの所有を確認した」状態と「手渡し招待で業務利用を許可した」状態が同じ列に畳まれる。**
既存 middleware (`verified`) との兼ね合いでこの列を使うのはやむを得ないが、
**由来を必ず別の場所に残す**:

- 最低限: `SecurityEventType` に**手渡し招待による検証免除**の case を足し、監査イベントに残す
  (case 追加は**記録経路の同一 PR 配線が必須**。`SecurityEventCoverageTest` が deny-by-default)。
- 望ましくは: `organization_invitations` 側に招待種別を持ち、
  「その利用者がどの種別の招待で入ったか」を辿れるようにする。
- **やってはいけないこと**: 由来を残さずに `email_verified_at` だけ立てること。
  後から「この利用者のメールは本当に本人のものか」を判定できなくなる。

#### 6-2c. **平文 token の露出面 (Round 1 Critical。昇格時の必須条件)**

平文 token を画面に出すことは **credential disclosure (資格情報そのものの開示)** である。
「肩越しの盗み見・スクショ」だけでは評価として不足で、
**次のすべてを第 1 段の必須設計とする** (1 つでも欠けたら第 1 段を実装してはならない):

| # | 必須条件 | 理由 / 注意 |
|---|---|---|
| 1 | **発行直後 1 回のみ表示。再表示は不可** (必要なら再発行 = 旧 token は失効) | 一覧に常時出すと露出面が恒久化する |
| 2 | **平文を保存しない** | 現行の「平文は DB に無い」を維持する。保存するなら暗号化 + 短い TTL + 閲覧の監査が必須 |
| 3 | **短い TTL** (現行のメール招待は `EXPIRES_DAYS = 7`。手渡しはこれより**明確に短く**) | 画面表示は流出面が広い |
| 4 | **single-use** (受諾で即失効) + **明示的な失効操作** | 現行の `revokeInvitation` (論理失効) を使う |
| 5 | **Inertia props に載る事実を意識する** | props は**ページのマークアップに埋め込まれて履歴に残る**。表示するページに `no-store` が掛かっていること、Inertia の history 暗号化 (ドメイン規約 3 の (C)) の対象であることを確認する |
| 6 | **URL に載せない** | ブラウザ履歴・`Referer`・アクセスログ・プロキシログへ漏れる。受諾 URL を**画面に文字列として出す**のと、**その URL へ遷移する**のは別物である |
| 7 | **サーバログに出さない** | 発行応答・例外・デバッグログのいずれにも平文を出さない |
| 8 | **bfcache / キャッシュ対策** | 表示ページは `no-store` baseline の対象に入れる (ドメイン規約 3 の (A))。撮影 PWA の主戦場 iOS Safari は `no-store` でも bfcache に入りうるため、(B) の bfcache guard の対象かも確認する |
| 9 | **監査**: 発行 / 表示 (閲覧) / コピー / 再発行 / 失効 | 「誰がその資格情報を見たか」が残らないと、流出時に追跡できない |
| 10 | **認可**: 発行・表示ともに `manageMembers` (`Gate::authorize`) | セキュリティ不変条件 9 |

### 6-3. 第 2 段 (案 D) の注意

- 「表示専用の識別子」は **`users` に置くとは限らない**。
  社員番号・呼称は**組織ごとに違う**ので、`organization_user` pivot 側の属性が素直な場合がある
  (別組織に同じ人が別の呼称で入れる)。**どちらかを先に決めてから列を足す**。
- 識別子が氏名の略称・社員番号になるなら **PII 相当**である。
  セキュリティ不変条件 6 に従い CipherSweet + blind index の対象にするかを判断する
  (すると通常の unique が使えなくなり、email と同じ `blind_indexes` の partial unique 方式になる)。
- **認証には絶対に使わない**。使った瞬間に第 3 段になる。

### 6-3a. 第 3 段 (案 E) は**別設計必須**。決定点だけ置く (Round 1 Warning)

第 3 段は本書では設計しない。**「特大」の内訳**を列挙して、
昇格時に「思ったより小さい」と誤認されないようにする。

| # | 決定点 | 内容 |
|---|---|---|
| 1 | **既存ユーザーの `login_id` 採番** | 既存の全ユーザーに識別子を割り当てる必要がある。自動採番 (衝突と可読性) か、管理者が手で入れるか (移行が止まる)。**「英数 20 字以内・重複不可」を既存データに対して満たせるか**を先に確かめる |
| 2 | **データ移行の順序** | 列追加 → backfill → unique 制約 → 認証切替の 4 段。**認証を切り替えるまで新旧の識別子が並存する**が、これは移行期間の話であり恒久的な並走ではない (思考原則 3 に反しない)。移行完了時点で email 識別子を**消す**計画まで含めて 1 つの変更にする |
| 3 | **既存セッション / remember token** | 識別子の切替で既存セッションを無効化するのか維持するのか。`remember_token` は user 行に紐づくので技術的には維持できるが、**移行時に全員ログアウトさせる方が事故が少ない**場合がある |
| 4 | **SSO の紐付け** | `social_accounts` は provider + provider_user_id で紐づく。IdP が返す email との突合ルールが識別子切替でどう変わるか |
| 5 | **監査ログ上の識別子表示** | `security_audit_events` / Filament の一覧が「誰の行為か」を何で表示するか。email 表示をやめるなら Filament 側も同時に直す |
| 6 | **サポート運用** | 問い合わせ時の本人確認を何で行うか (現行は email)。**運用手順書の変更まで含めて完了**とする |
| 7 | **PII 判定** | 識別子が氏名の略称・社員番号なら PII 相当。CipherSweet + blind index の対象にするか (§6-3) |
| 8 | **回復手段** | §5 の適格条件 C。email broker が使えなくなるので代替方式が必須 |

### 6-4. 波及変更 (昇格時に必ず一緒に直すもの)

第 1 段を実装する場合の最小セット。**今回は 1 件も行わない。**

| 種別 | 対象 |
|---|---|
| Service | `app/Services/Organization/OrganizationMembershipService.php` (招待の種別分岐 / 平文 token の返し方) |
| Model | `app/Models/OrganizationInvitation.php` (種別列 / 期限の別値) |
| migration | `organization_invitations` への種別列追加 + Factory (`database/factories/OrganizationInvitationFactory.php`) の更新 |
| Controller | `app/Http/Controllers/Organizations/OrganizationInvitationController.php` (発行) / `app/Http/Controllers/Admin/UserManagementController.php` (表示) |
| FormRequest | `app/Http/Requests/Organizations/StoreOrganizationInvitationRequest.php` (種別の受付。`Rule::enum`) |
| DTO | `app/DataTransferObjects/Admin/InvitationRowData.php` (種別 / 手渡し URL の 1 回表示) |
| TypeScript 型 | `resources/js/types/admin.ts` の `InvitationRow` (DTO と対で保守する契約がコメントに明記されている) |
| Svelte | `resources/js/pages/Admin/Users.svelte` (発行 UI。**必須条件未充足で disabled にしない** = 禁止事項 8) |
| 監査 | `App\Enums\SecurityEventType` への case 追加は**記録経路の同一 PR 配線が必須** (`SecurityEventCoverageTest` が deny-by-default) |
| throttle | named limiter 新設 + `ThrottleCoverageInventoryTest` の目録 |
| 認可 | `Gate::authorize` (`ControllerAuthorizationGateTest` の deny-by-default) |
| ドキュメント | `docs/architecture.md` の招待の節 / `docs/auth-security-mechanisms.md` |
| **メール送信系 (Round 1 Warning で追加)** | `Illuminate\Auth\Events\Registered` の購読 (検証メールの発火点) / `MustVerifyEmail` の verification notification の抑止 / `User` の `routeNotificationForMail` (合成 email への送信抑止) / `app/Models/EmailSuppression.php` と SES webhook (`app/Http/Controllers/Webhooks/SesNotificationController.php` — 合成 email のバウンスを積ませない) |
| **回復系 (Round 1 Warning で追加)** | `config/fortify.php` の `'passwords' => 'users'` broker / `password_reset_tokens` / `Features::resetPasswords()` の画面 (合成 email に対して「送信しました」と嘘をつかない) / 代替回復方式を採る場合はその設計一式 |
| **通知の代替経路** | `app/Services/Notification/NotificationCenterService.php` (メールが届かない層の唯一の経路になる) |
| **ログ / ヘッダ** | 受諾 URL・平文 token がアクセスログ / 例外ログ / `Referer` に出ないこと。表示ページの `no-store` (`NoStoreCacheHeadersForAuthenticatedPages`) と Inertia history 暗号化 (ドメイン規約 3) の対象確認 |
| **route middleware のテスト** | `verified` を route 単位で外す設計にするなら `ManageRouteAuthGuardTest` / `RecentAuthRouteTest` 等の deny-by-default 目録に影響する。**目録側の更新を施策に含める** |

**注意 (Codex Round 1 の Suggestion を反映)**: 第 1 段・第 3 段へ進む場合は、
**FormRequest / DTO / JsonResource の境界を最初に設計対象へ入れる**。
招待の種別が増えると入力・出力の両方の型が増え、後から足すと `response()->json()` 直書きや
配列返却へ流れやすい (禁止事項 4)。

### 6-5. 独立して起票できる小改善: 最終ログイン日時の表示

**本タスクの結論とは無関係**に実施できる (実施の可否は別途判断する)。

- 現行: `security_audit_events` に `login` が記録され索引もあるが、
  `/manage/users` には出ていない (`MemberRowData` に列が無い)。
- **位置づけ**: これは**一覧要件の一部を満たす小改善**であり、
  ユーザー登録方式そのものへの不満とは別物である
  (Round 1 の指摘。「これで要件未充足の体感の大半が消える」とは書かない)。
- 変更点: `UserManagementController::index` で
  メンバー分の最終 login イベントを**1 クエリで**取り (N+1 を作らない)、
  `MemberRowData` に `lastLoginAt` を足し、`resources/js/types/admin.ts` の `MemberRow` と
  `Admin/Users.svelte` に列を足す。
- 注意: **`users` に `last_login_at` カラムを足さない**。
  監査イベントが唯一の記録点であり、可変カラムを足すと同じ事実の記録が 2 か所になる。
- テスト: `tests/Feature/Admin/UserManagementPageTest.php` に
  「login イベントがある / 無い の 2 ケースで props が期待どおり」を追加。
  クエリ数の行数非依存も固定する (メンバー数を変えても発行クエリが増えないこと)。

### 6-6. PHPStan 適合チェック (将来実装時の観点)

- [ ] 戻り値の型が明示されている (招待発行が返す平文 token は `?string` ではなく専用 DTO)
- [ ] null 安全 (`Webmozart\Assert\Assert` を使う)
- [ ] DTO を返している (配列返却なし / `response()->json()` 直書きなし)
- [ ] enum の網羅 `match` (招待種別に `default` を置かず、case 追加で落ちるようにする)
- [ ] Generics の型パラメータ (`Builder<OrganizationInvitation>` 等)

### 6-7. テスト計画 (将来実装時。**今回は 1 件も書かない**)

- 手渡し招待で発行した token が**発行応答にだけ**現れ、一覧の再取得では出ないこと
- 平文 token が **DB に保存されていない**こと
- 手渡し招待の期限がメール招待より短いこと / **single-use** (受諾で即失効) であること
- 手渡し招待経由の登録で `email_verified_at` が確定し、撮影 PWA に到達できること
- **`email_verified_at` の由来が監査イベントに残る**こと (§6-2b)
- **合成 email へメールが 1 通も送られない**こと (`Notification::fake()` で 0 件を固定。
  招待通知 / 検証通知 / パスワード再設定のすべて)
- **合成 email に対して `/forgot-password` が嘘の成功を返さない**こと (§6-2a-e で決めた挙動)
- 平文 token が**アクセスログ / 例外 / URL / `Referer` に出ない**こと
- 表示ページに **`no-store`** が付いていること
- 手渡し招待の発行・表示が `manageMembers` 非保持者から 403 になること
- named limiter が効くこと (連打で 429、他レーンを巻き添えにしないこと)
- 監査イベント (発行 / 表示 / 失効) が記録されること
- 既存のメール招待経路が**一切変わっていない**こと (回帰)

### 6-8. リスク (将来実装時)

- **平文 token の画面表示が最大のリスク**。URL がそのまま資格情報 (credential disclosure) であり、
  現行の「平文は DB に無くメールにしかない」という秘匿設計を崩す。
  露出面は肩越し・スクショだけでなく **Inertia props / ブラウザ履歴 / `Referer` /
  サーバログ / キャッシュ・bfcache** に及ぶ (§6-2c の 10 条件が必須)。
- **メール検証の免除**は「その email の所有者であることの証明」を捨てる。
  由来を残さないと後から本人性を判定できなくなる (§6-2b)。
- **合成 email の扱いを詰めないと、実在ドメインへの誤配送・回復導線の空振り・
  SES バウンス率の悪化**が起きる (§6-2a)。とくに「送信しました」と表示して
  実際には届かない導線は、利用者を無言で詰ませる。
- **回復手段が無い層が生まれる**。パスワードを忘れた合成 email の利用者は
  管理者の助けなしには戻れない (§5 の適格条件 C)。
- 招待の種別が増えると **`OrganizationMembershipService` の受諾経路 3 本**
  (`acceptInvitation` / `acceptInvitationIfValid` / `acceptPendingInvitation`) の
  組み合わせが増える。共通コア `joinOrganization` の外に条件を散らさないこと。

---

## 7. 差分記録の所在と `docs/template-divergence.md` の判断

### 7-0. 結論: **新規登録は不要。D8 への追記も行わない。**

**主根拠は 1 つである** (Round 1 の指摘を受けて一本化した)。2 以下は補助的事情であり、
主根拠が崩れたら 2 以下では登録不要を支えられない。

**主根拠: 本件はテンプレートからの逸脱ではない。**

1. `docs/template-divergence.md` 冒頭の定義は
   「テンプレート (laravel-claude-template) の構造から**意図的に逸脱**した箇所の正本記録」である。
   本件で問題になっている email 認証 / Fortify / CipherSweet / passkey / 招待制は
   **テンプレートが提供する形そのもの**であり、テンプレートからは逸脱していない。
   **`doc/` 要件との差は台帳の記録対象ではない。**
**補助的事情 (主根拠を補強するが、単独では登録不要の根拠にならない)**

2. **関連する管理画面の差分は既に D8 に記録されている。**
   D8 の観点表に「ユーザー作成 | (doc/04 レガシーモック: 管理者がパスワード直接発行・平文一覧表示) |
   **招待一本化** (ユーザー ID → email へマッピング)。パスワードは本人設定のみ」という行があり、
   `根拠 T006` / `状態 恒久` で登録済みである。
   **ただし D8 が押さえているのは「管理メニューの UI とロール語彙」であり、
   認証識別子そのものを D8 がカバーしているとは主張しない** (Round 1 の指摘)。
   認証識別子は主根拠 1 のとおり**そもそも逸脱ではない**ので、どの登録もカバーする必要が無い。
3. **既存登録との対象パス重複** (補助的事情にすぎない)。
   登録メタ表の規約は「対象パスは全登録の和集合で重複しないこと」を要求し、
   `app/Services/Organization/OrganizationMembershipService.php` と
   `app/Http/Controllers/Admin/UserManagementController.php` は D8 が既に押さえている。
   **これは「登録すべきなのに登録できない」理由にはならない** — 記録すべき逸脱であれば
   台帳構造や D8 の側を直してでも記録するのが正しい。よって本項は
   **「主根拠が成立しているときに、新エントリを作ると機械検査が落ちる」という付随事実**として
   のみ扱う (Round 1 の指摘を受けて格下げ)。
4. **D8 本文への追記もしない。** D8 の射程は「管理メニューの UI とロール語彙」であり、
   再判定の条件も「役割を保存概念へ戻す要件が出たとき / 家系の裁定が役割の語彙を変えたとき」である。
   認証識別子の論点を同居させると、再判定の条件と本文の内容が対応しなくなる。

**主根拠が崩れる条件 (将来ここを読み直す人へ)**: もし本アプリが
「テンプレートが提供する email 認証・招待制の形そのものから外れる」変更
(第 1 段の検証免除・第 3 段の識別子置換のいずれも該当しうる) を入れたなら、
**そのときは登録が要る**。第 1 段・第 3 段を実装する PR は
`docs/template-divergence.md` への登録の要否判定を**その変更の中で**行うこと
(台帳の原則「登録は逸脱を作る変更そのものに含める」)。

> **「登録するか迷ったら登録する」という原則との関係**: この原則は
> 「テンプレートの実物が手元に無いので判定に迷うことがある」ことを理由にしている。
> 本件は迷いではなく、**テンプレートの標準機能をそのまま使っている**と確定できるため、
> 原則の前提に当たらない。

### 7-1. Conditional 登録用の本文 (後続の登録エージェントはこれをそのまま使う)

```
### ユーザー登録方式の要件差 (ユーザー ID 発行 vs 招待制) — Conditional

**結論**: 今は作らない。テンプレート由来の認証基盤 (Fortify の email 識別子 / 招待制 /
CipherSweet / passkey) を維持する。設計は
devnotes/20260817-0003-user-provisioning-model-divergence/ が正本。

**根拠 (要約)**:
- 確定仕様 doc/10 は識別子方式を規定していない (doc/02 §2.4 / doc/04 §4.2 / doc/05 §5.2 は
  Excel 起源の要件章)。
- 日常ログインはパスキー (ユーザー名入力なし) で入力を大きく減らせるため、
  ID + パスワード方式へ寄せると現場作業者の入力は増える。
- 「メールボックスを持たない作業者」は共有メールボックス / サブアドレスでの代行
  オンボーディング (コード変更ゼロ) で参加できる可能性がある。**未検証**。
- 寄せると本人の自力パスワード回復が消え、管理者へ権限が集中する。
  かつ思考原則 3 (後方互換の並走を残さない) により中間案 (併設) が採れない。
- 最終ログイン日時は security_audit_events から導出可能 (UI 表示のみ無い。
  管理画面要件としては未充足だが、認証基盤とは独立の小改善で満たせる)。

**昇格条件 (T-1)**: 記録条件 5 つが揃い、適格条件 A / B / C がすべて「はい」の要求が来たとき。
とくに「共有メールボックス / サブアドレスでも不可である理由」が書かれていない要求は昇格しない。
**T-2**: メール検証の免除経路を作る判断が別要件で入ったとき (再評価の開始。自動昇格ではない)。
**T-3**: doc/04 §4.2 の入力制約が受入検査の契約要件として合意されたとき
(パスワードの「半角英数 8〜16 字」は契約要件であっても採らない)。

**昇格条件ではないもの**: 「ID の方が現場に馴染む」という選好のみ。

**docs/template-divergence.md**: 新規登録は不要。主根拠は「本件はテンプレートからの逸脱ではない」
(email 認証 / Fortify / CipherSweet / passkey / 招待制はテンプレートが提供する形そのもの)。
関連する管理画面の差分は D8 に記録済み。ただし**昇格して第 1 段・第 3 段を実装する PR は、
その変更の中で登録の要否を判定すること** (テンプレートの形から外れる変更になるため)。
```

### 7-2. 別途起票できるもの (Conditional ではない)

- **最終ログイン日時の管理画面表示** (§6-5)。要件の 1 項目を、認証基盤に触らずに満たせる。
  実施するかは別途判断する。本タスクは Open タスクを生まない。

---

## 8. 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | アプリコードの変更が 0 件であり、成果物は `devnotes/` 配下の設計書のみである。他施策と競合するファイルを 1 つも触らないため、worktree を切る必要も他タスクとの調停も無い。TODO への登録も Open ではなく **Conditional** であり、実装レーンに載らない |
| 競合リスク | **なし** (`app/` `resources/` `routes/` `database/` `config/` `tests/` `docs/` のいずれも変更しない) |

---

## 9. 最終確認 (使命・禁止事項チェック)

- **使命への寄与**: 「作らない」判断そのものが寄与である。要件書の字面に合わせて
  ID + パスワードのログインを作ると、**現場作業者の入力を増やす**方向の後退になる
  (日常ログインはパスキーで入力を減らせる)。「思考ゼロ」の原則は
  ログインの手前から始まる。
- **禁止事項**: 実装を行わないため直接の抵触は無い。
  - 禁止事項 2 (テストなしの実装完了報告): **実装が 0 件**なのでテストも 0 件である。
    「実装したがテストが無い」状態を作らない。
  - 思考原則 2 (今必要なものだけ作る): 実需が確認されていない段階で認証基盤を作り替えない。
  - 思考原則 3 (後方互換の並走を残さない): 中間案 (識別子の併設) を**採らないと明言**した。
  - 思考原則 4 (別物の概念を似ているからで統合しない): 「呼称としての ID」「認証識別子」
    「最終ログインの表示」を**3 つの別概念として分離**した。
- **コーディングルール**: 将来実装する場合の適用対象として §6-6 / §6-7 に明記した。
- **セキュリティ不変条件**: 現行の PII CipherSweet / `whereBlind` 検索 / 認可 gate /
  throttle 目録のいずれも**変更しない**。§4-3 で「寄せた場合にどれと衝突するか」を明示した。

---

## 修正後の概念設計 (同期修正した箇所を含む全文)

# 概念設計: user-provisioning-model-divergence (ユーザー登録方式の要件差の評価)

> **このタスクは「作るかどうかの判断」から始まる。** 結論は §7。
> 現時点の暫定結論は **「今は作らない (Conditional として登録)」** であり、本書はその根拠を
> 現行コード・vendor コードの実読に基づいて示す。Codex 合議では **この判断そのもの**を
> 明示的な論点として問う。作法は T193 (`devnotes/20260816-1754-video-manual-visibility-scope/`)
> に揃える。

---

## 0. ブリーフの前提の検証 (訂正 4 件)

ブリーフの前提を鵜呑みにせず現行コードを実読した。**4 件が事実と食い違っていた**ので先に訂正する。

### 訂正 1: 「docs/template-divergence.md への登録が要るか」— **既に D8 が登録している。かつ本件は台帳の対象外である**

`docs/template-divergence.md` の **D8「管理メニューのユーザー管理 = 招待一本化 + 遷移コマンドロール +
Settings からの UI 移設」** の観点表に、次の行が**既にある**:

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| ユーザー作成 | (doc/04 レガシーモック: 管理者がパスワード直接発行・平文一覧表示) | **招待一本化** (ユーザー ID → email へマッピング)。パスワードは本人設定のみ |

さらに D8 の「なぜ正当な差分か」には
「doc/04 レガシーモックの直接発行・平文パスワード表示はセキュリティ不変条件
(PasswordPolicy / CipherSweet) と衝突するため招待一本化に reconcile した」と書かれている。
根拠は `T006`、状態は `恒久`。

加えて、**台帳の対象そのものが本件と一致しない**。同ファイル冒頭は
「テンプレート (laravel-claude-template) の構造から**意図的に逸脱**した箇所の正本記録」と定義しており、
**記録するのは「テンプレートからの逸脱」であって「`doc/` 要件との差」ではない**。
本件で問題になっている email 認証 / Fortify / CipherSweet / passkey は
**テンプレートが提供している形そのもの**であり、テンプレートからは 1 ミリも逸脱していない。
D8 が登録されているのは「テンプレートの `Organizations/Settings.svelte` 同居 UI を
`/manage/users` へ移設し、ロール語彙を遷移コマンドへ変えた」という**テンプレート相対の逸脱**が
実在したからで、上記の行はその文脈の付随情報である。

→ **判断: 新規の D 番号は作らない**(§7-3 で詳述)。要件差の記録の正本は
**本設計ディレクトリ + `docs/TODO.md` の Conditional 項目**とする (T193 と同じ作法)。

### 訂正 2: 「`最終ログイン日時` カラムも無く」— カラムは無いが**情報は失われていない**

`security_audit_events` (`database/migrations/2026_06_11_071300_create_security_audit_events_table.php`) が
`user_id` / `event_type` / `occurred_at` を持ち、`app/Listeners/RecordSecurityEvent.php` が
`SecurityEventType::Login` を記録している。索引も
`$table->index(['user_id', 'event_type'])` と `$table->index('occurred_at')` の 2 本があり、
**「その利用者の最後の login イベント」は索引の効くクエリで導出できる**。
Filament 側にも一覧 (`app/Filament/Resources/SecurityAuditEventResource.php`) がある。

つまり要件が求める**情報**は存在し、**保持形式が違うだけ**である
(可変の 1 カラム vs 不可変の監査イベント列)。しかも監査イベント側の方が
「いつ・どこから (ip_address)・何回」まで残るので情報量は多い。
**欠けているのは「組織管理画面 (`/manage/users`) にその値を出す UI」だけ**である
(`app/Http/Controllers/Admin/UserManagementController.php` は現在 name / ロール / 招待しか返さない)。

### 訂正 3: 「passkey の利用者ハンドル」への影響 — **識別子を変えても壊れない**

ブリーフは ID 方式へ寄せた場合の破壊対象に「passkey の利用者ハンドル」を挙げているが、
vendor 実読 (`vendor/laravel/passkeys/src/PasskeyAuthenticatable.php`) では

```php
public function getPasskeyUserHandle(): string
{
    return hash_hmac('sha256', $this->getTable().'|'.$this->getKey(),
        Config::string('passkeys.user_handle_secret'), binary: true);
}
```

= **テーブル名と主キーの hmac** であり、**email に一切依存していない**。
`PASSKEYS_USER_HANDLE_SECRET` の運用要件 (AGENTS.md) も同じ理由で無関係である
(危険なのは `APP_KEY` ローテートであって識別子の変更ではない)。
同様に TOTP 2FA も `users.two_factor_secret` に閉じており email 非依存である。

→ **識別子方式の変更で壊れるのは passkey / 2FA ではなく、「メールを前提にした回復・招待・通知」である**
(§5 で具体化する)。ブリーフの心配の向きを 1 つ訂正する。

### 訂正 4: doc/10 (確定仕様) は識別子方式を規定していない

`doc/10_実装仕様.md` は §10.8 の冒頭で **「実装時はこの節が §10.1〜§10.7 に優先する」**と宣言する
確定仕様である。その doc/10 が認証について書いているのは 2 行だけで、

- 「v1 スコープ（確定）: … 撮影アプリは PWA/Web（同一オリジン・セッション認証）…」
- 「§10.5 ロール: project_admin=編集者、project_member=撮影者」

であり、**識別子が「ユーザー ID」か「メールアドレス」かは確定仕様に含まれていない**。
`doc/02 §2.4` / `doc/04 §4.2` / `doc/05 §5.2` は
「PC サイト Excel の概要設計書」を写した**要件の出自の章**である (doc/02 が自ら明記している)。

→ T193 と同じ構造である。**確定仕様が定めていない領域について、出自の章の記述を
そのまま実装契約として扱わない**。

---

## 1. 背景・課題 (訂正後の正しい問題設定)

`doc/` 側の要件:

- `doc/02 §2.4 ユーザー`: `ユーザーID`(半角英数 1〜20 字, ユニーク) / `パスワード`(8〜64 字) /
  `表示名`(1〜50 字) / `メールアドレス`(**任意**) / `所属ID` / `権限` / `作成日時` / `最終ログイン日時`
- `doc/04 §4.2 ユーザー管理画面(管理者)`: 一覧 / 新規登録 / 編集 / 削除。
  入力制約: ユーザー ID = 英数字 20 字以内・重複不可、表示名 = 全角 20 字以内、
  パスワード = **半角英数 8〜16 字**、メール = 形式チェック
- `doc/05 §5.2 ログイン画面`: ユーザー ID・パスワードでログイン (PC 版と共通 ID)

現行実装:

- 認証識別子は **email** (`config/fortify.php` の `'username' => 'email'`)。
- ユーザーの入り口は **組織招待 (`OrganizationInvitation`) → 本人登録** か、**公開セルフ登録**
  (`Features::registration()`)。
- `users` に `login_id` 相当の列は無い (`database/migrations/0001_01_01_000000_create_users_table.php`
  は `name` / `email` / `email_verified_at` / `password`(nullable) / 同意証跡 / remember_token のみ)。
- `email` / `name` は CipherSweet 暗号化 + blind index (`User::configureCipherSweet`)。
  email の一意性は平文 unique ではなく **`blind_indexes` の partial unique** で担保する
  (migration コメントと `2026_06_11_071100_add_unique_to_blind_indexes_table.php`)。
- パスワードは**本人だけが設定する** (`CreateNewUser` / `PasswordSetupController`)。
  強度は `App\Support\PasswordPolicy` = **12 文字以上 + 大文字小文字混在 + 数字 + HIBP 漏洩照合**
  (production は無条件で照合 ON)。

**本タスクが判定するのは「この差を埋める実装を今作るか」である。**
本質的な論点はブリーフのとおり **「現場の運用が招待制で成立するか」**であり、
`ユーザーID` カラムの有無は表層である。

---

## 2. 撮影者が撮影 PWA に到達するまでの必須条件 (実読した事実)

### 2-1. `verified` は撮影 PWA にも掛かっている

`routes/web.php` の L191 が

```php
Route::middleware(['auth', 'verified', 'not-pending-deletion'])->group(function (): void {
```

で始まり、撮影 PWA の group (`->prefix('app')->as('capture.')`, L609 付近) は**この group の内側**にある
(さらに `require-active-subscription` / `project.in-current-org` が重なる)。
`User` は `MustVerifyEmail` を実装し、`config/fortify.php` は `Features::emailVerification()` を有効にしている。

→ **メール検証を通していない利用者は、撮影 PWA に 1 画面も到達できない。**

### 2-2. 招待経由の登録は「招待先 email と登録 email の一致」を強制する

- `InvitationAcceptanceController::show` は未ログイン + 有効招待なら
  `session()->put('invitation_token', $token)` して `register` へ送る。
- `CreateNewUser::create` は `MatchesInvitationEmail($invitationToken)` を email の rule に載せる。
  `App\Rules\MatchesInvitationEmail` は `$invitation->email !== $value` で失敗させる。
- 二重防御として `OrganizationMembershipService::acceptInvitationIfValid()` も
  `if ($invitation->email !== $user->email) { return null; }` を持つ。

### 2-3. 招待の受け渡し手段は現在**メールだけ**である

`OrganizationMembershipService::inviteMember()`:

```php
$plainToken = OrganizationInvitation::generateToken();
// …token_hash だけ保存…
Notification::route('mail', $email)->notify(new OrganizationInvitationNotification(
    organizationName: $organization->name,
    acceptUrl: url('/invitations/accept?token='.$plainToken),
));
```

**平文 token は DB に保存されず、メール本文にしか載らない。**
`UserManagementController::index` が返す `invitations` prop も
`InvitationRowData` であり、受諾 URL は含まない (含められない — 平文が無い)。
アプリ内受諾 (`AcceptInvitationInAppController`) は
**「auth 済み ∧ email 確認済み ∧ ログイン者 email = 招待宛先」**が受諾根拠なので、
**まだアカウントを持たない人には使えない** (routes/web.php のコメントが
「未登録の人にはメールが唯一の入口」と明記している)。

### 2-4. したがって (事実の確定)

**「メールを 1 通も受け取れない利用者」は、現行実装では撮影者として参加できない。**
ブリーフの懸念はこの一点については**正しい**。

ただし「**個人の**メールアドレスを持たない」と「**メールを受け取れない**」は別である。§3 で分ける。

---

## 3. 現行のまま成立**しうる**運用があるか (現場検証前の第一候補)

> **本節の位置づけ (Round 1 の指摘を受けて明示)**: 以下は
> **「コード変更ゼロで届きうる運用仮説」であって、現場で成立することの実証ではない。**
> 本設計はこれを **現場検証前の第一候補運用 (案 A)** として扱い、
> 「解決済み」とは書かない。検証すべき成立条件は §3-4 に列挙する。

### 3-1. 日常ログインの入力を大きく減らせる (パスキー)

vendor 実読 (`vendor/laravel/passkeys/src/Actions/GenerateVerificationOptions.php`):

```php
public function __invoke(?PasskeyUser $user = null): PublicKeyCredentialRequestOptions
{
    return PublicKeyCredentialRequestOptions::create(
        challenge: random_bytes(32),
        rpId: Passkeys::relyingPartyId(),
        allowCredentials: $this->allowCredentials($user),   // user 無し ⇒ []
        userVerification: …USER_VERIFICATION_REQUIREMENT_REQUIRED,
        …);
}
```

`PasskeyLoginController::index` は `$generate()` を**引数なし**で呼ぶため
`allowCredentials: []` = **discoverable credential によるユーザー名入力なしのログイン**である。
`config/fortify.php` は `Features::passkeys(['confirmPassword' => false])` を有効にしている。

→ **下の条件が揃う端末では、オンボーディング後の日常ログイン入力を大きく減らせる**
(操作は「パスキーで入る」のタップ + 端末の生体/PIN)。
条件は **(1) その利用者のパスキーが登録済み / (2) 端末とブラウザが WebAuthn の
discoverable credential に対応 / (3) その利用者に TOTP 2FA が強制されていない (下記) /
(4) 端末の生体・PIN による本人性を運用として許容できる** の 4 つである。
**「毎日のログインに ID もメールもパスワードも要らない」と無条件に書くことはできない。**

条件が揃う限りにおいては、要件の「ユーザー ID (英数 20 字) + パスワード (英数 8〜16 字) を毎回入力」より
**現場作業者の入力負担は小さい**。使命 (「専門知識ゼロの現場作業者でも」) の観点では、
要件どおりに寄せることが**入力を増やす方向の後退**になりうる。

**射程の限定**: `PasskeyLoginPolicy::allowsPasskeyLogin()` は
**TOTP 2FA を confirmed 済みの利用者を拒否する** (vendor がパスキーログインで
2FA challenge を通らないため)。組織が `two_factor_required` を立てて撮影者に TOTP を強制すると、
撮影者はパスキーで入れなくなり email + パスワード + TOTP に戻る。
現場運用でこれを選ぶと上の利点は消える。

### 3-2. メールボックスの用意は「1 人 1 個」でなくてよい

`config/fortify.php` は `'lowercase_usernames' => true` だが、サブアドレス
(`shopfloor+worker01@example.co.jp`) は別の email として扱われ、blind index の unique も別値になる。
`Notification::route('mail', $email)` は素直に配送先へ送る。

→ **現場責任者が管理する 1 つのメールボックス (catch-all / サブアドレス) で、
撮影者 N 人分の招待受信・登録・メール検証を代行できる** (技術的には成立する)。
その後は 3-1 のパスキーで本人が入る。**コード変更は 1 行も要らない。**
ただし「技術的に成立する」ことと「その現場の規程・監査要件の下で採れる」ことは別である (§3-4)。

### 3-3. 3-2 の代償 (誇張しないために明示する)

- **メールボックス保持者 (責任者) は、その撮影者のパスワード再設定を実行できる**
  = いつでもそのアカウントになりすませる。監査ログ上は本人の行為に見える。
- 撮影者本人は自力でパスワードを回復できない (回復はメールボックスを持つ人の作業になる)。
- 共有端末のパスキーは OS アカウント単位に保存され、`userVerification=REQUIRED` のため
  端末の PIN / 生体が要る。**端末の PIN を全員で共有すると、パスキーの本人性は端末 PIN と同じ強さになる**。

**案 A の代償を「要件どおりの管理者発行方式と同等以下」と言い切らない**
(詳細設計 Round 1 の指摘)。比較して言えるのは
**「招待制だから固有になりすまし可能性が生まれる、という非対称は無い」**ことまでである
(要件どおりの方式でも発行者は初期パスワードを知っており、`doc/04` のレガシーモックは
平文一覧表示まで含む)。

**案 A は「監査上許容される場合にだけ採れる運用仮説」**として扱う。
とくに、責任者がメールボックスを持ち続ける限り**その撮影者のパスワード再設定を恒久的に実行できる**
= いつでもなりすませる状態が続くため、これは軽い代償ではない。
顧客の監査要件が「アカウントの受け渡し経路にメールを介在させてはならない」
「共有メールボックスの使用を禁ずる」といった**経路そのものの制約**を課している場合、
案 A は代償の大小に関係なく**採れない**。その場合は §7-2 の T-1 が発火する。

### 3-4. 案 A が成立するための条件 (現場検証で確かめること)

案 A は**検証前の第一候補**であり、次がすべて満たされてはじめて成立する。
1 つでも崩れたら案 A は不成立であり、§7-2 の T-1 適格条件 B を満たす。

1. **責任者がメールを受け取れる** — 共有メールボックスまたはサブアドレス / catch-all が
   その組織の IT 規程で利用可能である
2. **責任者による代行登録・代行メール検証が、監査要件上許される**
3. **作業者ごとにパスキーを登録できる** — 端末とブラウザが対応し、
   1 端末に複数利用者のパスキーを置ける (または利用者ごとに端末を割り当てられる)
4. **端末 PIN / OS アカウント運用で本人性を許容できる** — 端末 PIN の共有が禁じられている場合、
   利用者ごとに OS アカウントか端末を分けられる
5. **対象の撮影者に TOTP 2FA を強制しない** (強制すると §3-1 の利点が消える)
6. **退職・異動時の回収手順がある** — アカウント除名 (`removeMember`) と
   端末上のパスキー削除の両方を運用手順に持つ

---

## 4. 「作るべきか」の一次判定 — 何が本当に足りないのか

| 要件の要素 | 現行での状態 | 判定 |
|---|---|---|
| ユーザー一覧表示 | `/manage/users` (`UserManagementController::index`) にある | **満たされている** (表示項目は §4-1) |
| 新規登録 | 招待 → 本人登録。**管理者が直接作る経路は無い** | 意図的に reconcile 済み (D8) |
| 編集 (ロール) | `applyConsoleRole` の 3 値遷移コマンドで可能 | **満たされている** |
| 編集 (表示名 / メール) | 管理者からは不可 (本人の Settings のみ) | 未実装。ただし PII 最小化の設計判断 (D8) |
| 削除 | `removeMember` (組織からの除名) はある。users 行の削除は本人の退会予約のみ | 組織運用としては**満たされている** |
| ユーザー ID (英数 20 字・重複不可) | 列が無い。識別子は email | **表現できない** |
| パスワード 半角英数 8〜16 字 | `PasswordPolicy` = 12 字以上 + 大小混在 + 数字 + HIBP | **意図的に上書き** (要件より強い。弱める方向に寄せてはならない) |
| メール = 形式チェック (任意) | **必須**かつ認証識別子 | 逆転している |
| 最終ログイン日時 | `security_audit_events` から導出可能。**UI に出ていない** | 情報はある / **表示が無い** |
| 所属 ID | `Organization` へ写像済み | **満たされている** |
| 権限 (管理者・一般) | `OrganizationRole` + `ProjectRole` + Policy | **満たされている** (doc/10 §10.5 の確定値) |

**本当に足りないのは 3 つだけ**である:

1. **メールボックスを 1 つも用意できない現場が実在した場合の入口** (§2-4 の事実)
2. 管理画面に **最終ログイン日時が出ていない** (情報はあるので表示だけの話)
3. **`ユーザーID` という識別子そのもの** (認証識別子として使う要求なのか、
   単に現場の呼称・台帳の突合キーとして表示したい要求なのかで、必要な実装が桁違いに変わる)

### 4-1. 2 と 3 の「軽い方」は、そもそも本タスクの論点ではない

- 2 (最終ログイン日時の表示) は `UserManagementController` + `MemberRowData` +
  Svelte 側の列追加で完結する**独立した小改善**であり、認証基盤の設計差とは無関係である。
  本タスクの結論 (作る / 作らない) に紐付けず、**別タスクとして起票できる**状態にしておく (§8)。
- 3 の「表示用の呼称」だけが欲しいなら、`users` ではなく**組織メンバーシップの属性**
  (社員番号 / 呼称) の話であり、認証識別子の置換ではない。これも別概念である (§6-3)。

**認証基盤の作り替えが要るのは、3 が「認証識別子を email から ID へ置換する」要求であるときだけ**である。

---

## 5. 要件どおりの ID 発行方式へ寄せる場合、何がどこまで壊れるか

「`users.login_id` を認証識別子にし、管理者が初期パスワードを発行し、メールを任意にする」を
仮に採ったときの影響を、実読したファイル単位で挙げる。

| # | 対象 | 壊れ方 | 深さ |
|---|---|---|---|
| 1 | `config/fortify.php` `'username' => 'email'` / `'email' => 'email'` | `login_id` へ切替。`lowercase_usernames` の意味も変わる | 浅い (設定) |
| 2 | `app/Auth/EncryptedUserProvider::retrieveByCredentials` | `email` キーだけ `whereBlind` する分岐。`login_id` は平文 `where` になる | 浅い |
| 3 | **一意性の担保** | email の unique は `blind_indexes` の partial unique が唯一の担保。`login_id` を平文列にすれば通常 unique が使えるが、**ID が氏名の略称等になった時点で PII 相当**になり、不変条件 6 (PII は CipherSweet / 検索は `whereBlind`) と衝突する。「PII でない ID」を運用で保証できるかという**人の規律の問題**になる | **深い (不変条件)** |
| 4 | **パスワード再設定** | `password_reset_tokens.email` が主キー、`config/fortify.php` `'passwords' => 'users'` broker は email 前提。メールが任意になると**本人が自力で回復する手段が消える** → 管理者リセット経路の新設が必須になり、**「管理者が本人になりすませる」経路を恒久化する** | **深い (回復不能の代替が権限集中)** |
| 5 | **招待フロー** | `OrganizationInvitation.email` (CipherSweet + blind index) / `MatchesInvitationEmail` / `pendingInvitationsQuery` / `AcceptInvitationInAppController`。とくに**アプリ内受諾 (裁定 AG-113) の受諾根拠が「ログイン者 email = 招待宛先」**なので、email が任意になると**受諾根拠そのものが消える**。別の根拠を設計し直すことになる | **深い (裁定の再設計)** |
| 6 | **メール検証** | `verified` が業務 group 全体に掛かっている (§2-1)。メール任意なら「検証を通せない利用者」が構造的に生まれるので、group の設計を変えることになる。`ManageRouteAuthGuardTest` 等が deny-by-default で固定している | **深い** |
| 7 | 通知 | `Notifiable` の mail channel / `EmailSuppression` / SES webhook。メール未設定の利用者に**通知が届かない層**が生まれる (通知センターは残るが push は無い) | 中 |
| 8 | SSO | `SocialAccountService` / `SocialiteDriverResolver`。IdP が返す identity は email 中心なので、ID 方式と併存させると突合規則の再設計が要る | 中 |
| 9 | passkey / TOTP 2FA | **壊れない** (§0 訂正 3)。利用者ハンドルは `table\|id` の hmac、TOTP は users 行の secret | **影響なし** |
| 10 | `PASSKEYS_USER_HANDLE_SECRET` 運用要件 | **影響なし** (`APP_KEY` ローテートの話であり識別子の話ではない) | **影響なし** |
| 11 | パスワード字種要件 | `doc/04` の「半角英数 8〜16 字」は `PasswordPolicy` (12 字以上 + 混在 + 数字 + HIBP) より**弱い**。寄せるのは禁止事項 (セキュリティの後退) なので、要件のこの行は**採らない**と明言する必要がある | 採らない |

### 5-1. 構造的な障害: 中間案 (併設) が規約上採れない

AGENTS.md 思考原則 3 は **「後方互換の並走を残さない。書き換えると決めたら同じ変更で旧実装を消す」**。
「email ログインを残したまま ID ログインを併設する」は**まさに並走**であり、
`LoginMethodInventory` (「ログイン画面から本人がアカウントに入れる手段」の集合) と
`EnsureLoginMethodRemains` (手段を減らす操作の単一直列化点) が
**手段の集合を 1 箇所で数える設計**になっていることとも噛み合わない
(識別子の二重化は「手段」ではなく「入口の二重化」であり、この機構の外側に新しい軸を作る)。

→ **「メールを任意にして管理者発行の識別子を併設する」というブリーフの中間案は、
本リポジトリの規約下では素直に採れない。** 採るなら email 識別子を**消す**ことになり、
§5 の 3〜8 を全部払うことになる。

---

## 6. 中間案の検討 (それぞれのセキュリティ上の代償)

| # | 案 | コード変更 | 成立する運用 | セキュリティ上の代償 | 評価 |
|---|---|---|---|---|---|
| A | **現行のまま。責任者のメールボックス (サブアドレス) で代行オンボーディング + 以後パスキー** | **ゼロ** | 個人メールを持たない作業者が撮影者になれる **(§3-4 の 6 条件が揃う場合)** | **責任者が恒久的にパスワード再設定でなりすませる** / 本人の自力回復が無い。**監査上許容される場合にだけ採れる** (経路そのものを禁ずる監査要件の下では不可) | **現場検証前の第一候補の運用仮説。実証済みではない。まずこれで足りるかを現場に問う** |
| B | 組織単位の**共有アカウント**を認める | ゼロ (運用判断のみ) | 1 アカウントを班で共有 | `takes.uploaded_by` / `video_manuals.created_by` の帰属が壊れ**誰が撮ったか分からなくなる**。2FA / パスキーも共有。監査ログが意味を失う | **棄却**。使命 (標準化・品質責任の所在) と正面から衝突する |
| C | **招待リンクの手渡し** (管理画面に受諾 URL を出す) + 招待経由登録の**メール検証免除** | 中 (招待の平文 token の扱い / `verified` の例外設計 / 検証免除の監査) | メールを 1 通も送らずに撮影者を増やせる | 平文 token を画面に出す = **URL がそのまま資格情報**になる (肩越しの盗み見・スクショ・チャット転送)。現行は「平文は DB に無い / メールにしかない」が不変条件。検証免除は「その email の所有者であること」の証明を捨てる | **段階を切る場合の第 1 段**。ただし現時点で必要性が確認されていない |
| D | `users` に**表示専用の識別子** (社員番号 / 呼称) を足す。認証には使わない | 小 | 現場台帳との突合が楽になる | ほぼ無い (PII 相当なら CipherSweet 対象になる判断が要る) | **要求が「呼称が欲しい」ならこれで足りる**。認証の話ではない |
| E | 認証識別子を email → ID へ**置換**する (要件どおり) | 特大 | 要件書の字面どおり | §5 の 3〜8。とくに**本人の自力回復が消え、管理者へ権限が集中する** | **今は採らない** |

**A → (足りなければ) D → (それでも足りなければ) C → (最後に) E** の順で距離が近い。
順序は固定の優先順位ではなく、**「要求の実体がどれか」で行き先が決まる** (§7 のトリガー条件で切り分ける)。

---

## 7. 結論と、必要になる条件

### 7-1. 結論: **今は作らない (Conditional として条件付き保留)**

根拠は 6 点:

1. **確定仕様 (doc/10) は識別子方式を規定していない。**
   `doc/02 §2.4` / `doc/04 §4.2` / `doc/05 §5.2` は Excel 起源の要件章であり、
   doc/10 §10.8 が「実装時はこの節が §10.1〜§10.7 に優先する」と宣言している
   (T193 と同じ構造)。
2. **使命の観点では、現行の方が現場作業者の入力負担を下げうる。**
   パスキーの discoverable credential ログイン (vendor 実読で確認) により、
   §3-1 の 4 条件が揃う端末では日常ログインの入力を大きく減らせる。
   要件どおりの「英数 20 字 ID + 英数 8〜16 字パスワード」は毎回の入力を要求するため、
   その条件下では**後退**になる。
3. **「メールボックスを持たない作業者」は、コード変更ゼロの運用 (案 A) で参加できる可能性がある。**
   ただし **案 A が実地で成立するかは未検証**であり (§3-4 の 6 条件)、
   本設計はこれを「解決済み」とは扱わない。これが唯一かつ最大の未確認点であり、
   トリガー条件の中核になる (§7-2)。
4. **寄せた場合に壊れる範囲が広く、代償が「本人の自力回復の消滅 + 管理者への権限集中」である** (§5)。
   これは要件が想定していたであろう「社内システム・単一テナント」の前提に乗った設計であり、
   組織で分離された SaaS では権限集中がそのままリスクになる。
5. **中間案 (併設) が規約上採れない** (思考原則 3 の後方互換並走禁止)。
   よって「小さく試す」ができず、判断は「やる / やらない」の二値になる。
   二値であるなら、実需の確認前に大きい方を選ぶ理由は無い (思考原則 2)。
6. **要件のうち実際に欠けている軽い項目 (最終ログイン日時の表示) は、
   認証基盤とは無関係に別タスクで満たせる** (§4-1)。
   ただし**これは一覧要件の一部を満たす小改善にすぎない**。
   ユーザー登録方式そのものへの不満とは別物であり、「これで体感の大半が消える」とは書かない。

### 7-2. Conditional 登録のトリガー条件 (Open へ昇格する条件)

**T-1 (主条件)**: 下の**記録条件 5 つ**がすべて書かれ、かつ**適格条件 A / B / C がすべて「はい」**である
要求が 1 件でも来たとき。**記録があるだけでは昇格しない** (記録は判定の入力である)。

**記録条件 (何が書かれているか)**

1. 対象 (どの組織 / 何名 / どの役割 = 撮影者のみか編集者も含むか) と要求元 (顧客名・運用責任者)
2. メールボックスを用意できない理由 (IT ポリシー / 端末制約 / 委託先 / 個人情報の持ち出し禁止 等)
3. **共有メールボックス・サブアドレス (案 A) でも不可である理由** —
   ここが埋まっていない要求は案 A で解決するので昇格しない
4. その利用者に許す操作の範囲 (撮影のみ / 閲覧 / 採用・編集まで)
5. **なりすまし許容度** — 責任者が代行してよいのか、本人以外が入れてはならないのか
   (後者なら案 A も案 E も同じ問題を抱えるので、要求は「識別子」ではなく「本人性」の話になる)

**適格条件 A / B / C (この Conditional の対象要求か。1 つでも「いいえ」なら不昇格)**

| # | 適格条件 | 満たさないときの行き先 |
|---|---|---|
| A | 要求の実体が **「認証の入口」** である (「メールを受け取れないので入れない」) | 「台帳と突合する呼称が欲しい」なら**案 D (表示専用の識別子)**。「誰がいつ入ったか見たい」なら**最終ログイン日時の表示** (別タスク) |
| B | **案 A が実地で不成立**と確認されている = **§3-4 の 6 条件のうち少なくとも 1 つが崩れている**ことが、具体的な事実として記録されている | 成立するなら実装は不要。運用手順書の整備で閉じる |
| C | **本人の自力パスワード回復について、現行の Fortify email broker では成立しないことを承知した上で、代替回復方式を設計対象に含めることが合意されている** | 「管理者リセットで足りる」なら権限集中を許容した設計として進める。「本人だけが回復できねばならない」なら**回復方式の設計が前提条件**になる |

> **適格条件は A / B / C の 3 つである。**
> **「対象利用者に TOTP 2FA を強制しない」は昇格の必須条件にしない** (詳細設計 Round 1 の指摘)。
> 「メールを受け取れないので入れない」問題と「日常ログインの入力を減らせる」問題は別であり、
> 混ぜると「2FA を強制する現場は永久に昇格しない」という厳しすぎる条件になる。
> 2FA 方針は**昇格後の設計における追加決定点**として扱う。
>
> また **「メールなしで本人だけが回復する手段は存在しない」とは書かない**。
> 正確には「現行の Fortify email broker (`password_reset_tokens.email` 主キー) では
> 自力回復できない」であり、リカバリコード方式・管理者承認付き再発行・
> パスキー再登録による回復など**代替設計はあり得る** (本設計の対象外。採るなら別途設計する)。

**適格条件 B の判定例 (曖昧さを残さないため)**

| 記録された事実 | B の判定 |
|---|---|
| 共有メールボックス / サブアドレスの利用が社内規程で**禁止**されている | **はい** (不成立) |
| メール基盤側が catch-all / サブアドレスを**提供できない** | **はい** (不成立) |
| 責任者による代行登録・代行メール検証が**監査要件上禁止**されている | **はい** (不成立) |
| 共有端末の PIN 共有が禁止で、**かつ**利用者別のパスキー登録も端末分割もできない | **はい** (不成立) |
| 対象撮影者に TOTP 2FA を**強制する**方針が確定しており緩められない | **B の判定には使わない** (2FA 強制はパスキーの利点を消すが、メールを受け取れるかどうかとは独立。昇格後の設計における追加決定点として扱う) |
| **「作業者が個人のメールアドレスを持たない」だけ** | **いいえ** (案 A の対象そのもの。不成立にしない) |
| 「ID の方が現場に馴染む」「要件書にそう書いてある」 | **いいえ** (§7-2 末尾の「昇格条件ではないもの」) |

**昇格したときに最初にやること (設計のやり直しを防ぐ)**

1. 案 C (招待リンク手渡し + 招待経由登録のメール検証免除) を**第 1 段**として設計する。
   これだけで「メールを 1 通も送らずに撮影者を増やす」は成立する。
   認証識別子は email のまま (ダミー / エイリアスでよい) にして、§5 の 3〜8 を**払わない**。
2. 第 1 段で不足が残った場合にのみ、識別子の置換 (案 E) を独立タスクとして起票する。
   その時点で `EncryptedUserProvider` / `password_reset_tokens` / AG-113 の受諾根拠 /
   `verified` の group 設計を**同一の変更で**書き換える計画を立てる (並走を残さない)。

**T-2 (再評価の開始条件。自動昇格ではない)**:
`verified` を業務 group から外す / メール検証の免除経路を作る判断が**別の要件で**入ったとき。
案 C のコストが大きく下がるため本設計を読み直す。

**T-3**: `doc/04 §4.2` の入力制約が**受入検査の対象として顧客と合意され**、
カラムの存在自体が契約要件になったとき。
そのときは業務要件ではなく契約要件として扱い、**案 D (表示専用の識別子列) で足りるか**を最初に問う。
**パスワードの「半角英数 8〜16 字」は、契約要件であっても採らない**
(セキュリティの後退であり、`PasswordPolicy` を弱める変更は禁止事項に当たる)。
この 1 行は要件側の訂正として顧客と合意する。

**昇格条件ではないもの**:
「ID の方が現場に馴染む」という選好のみ。日常ログインは既に入力ゼロ (パスキー) であり、
要件どおりに寄せる方が入力を増やす。

### 7-3. `docs/template-divergence.md` への登録は**不要** (判断と根拠)

**主根拠は 1 つ**である (詳細設計 Round 1 の指摘を受けて一本化した)。

- **本件はテンプレートからの逸脱ではない。** 台帳の対象は
  **テンプレート (laravel-claude-template) からの逸脱**である (同ファイル冒頭の定義)。
  email 認証 / Fortify / CipherSweet / passkey / 招待制は**テンプレートが提供する形そのもの**であり、
  テンプレートからの逸脱ではない。**`doc/` 要件との差は台帳の記録対象ではない。**
- (補助) 関連する**管理画面の**差分 (ユーザー作成が招待一本化であること) は
  **D8 の観点表に既に 1 行ある** (`根拠 T006` / `状態 恒久`)。
  **ただし D8 が認証識別子そのものをカバーしているとは主張しない** — 認証識別子は
  主根拠のとおり**そもそも逸脱ではない**ので、どの登録もカバーする必要が無い。
- **主根拠が崩れる条件**: 昇格して第 1 段 (検証免除) / 第 3 段 (識別子置換) を実装するなら、
  それはテンプレートの形から外れる変更なので**そのときは登録が要る**。
  判定は**その変更の PR の中で**行う (台帳の原則「登録は逸脱を作る変更そのものに含める」)。
- 「登録するか迷ったら登録する」という原則はあるが、本件は迷いではなく
  **対象範囲の外**であると確定できる。かつ新規登録には「対象パスが全登録の和集合で重複しないこと」の
  制約があり、`OrganizationMembershipService.php` / `UserManagementController.php` は
  **D8 が既に押さえている**ため、同じパスで新エントリを作ると
  `TemplateDivergenceLedgerFormatTest` が落ちる。
- **D8 本文への追記も本タスクでは提案しない。** D8 の射程は「管理メニューの UI とロール語彙」であり、
  認証識別子の話を混ぜると D8 の再判定条件 (「役割を保存概念へ戻す要件が出たとき」) と
  対応しない内容が同居する。要件差の記録の正本は**本設計ディレクトリ + `docs/TODO.md` の
  Conditional 項目**とする (T193 と同じ作法)。

---

## 8. スコープ外 (本タスクでは扱わない / 別タスクにできるもの)

- **最終ログイン日時の管理画面表示** (`UserManagementController` + `MemberRowData` + Svelte の列追加)。
  §4-1 のとおり認証基盤と無関係の小改善であり、本タスクの結論に紐付けない。
  実装するなら独立タスクとして起票する。
- 管理者によるメンバーの表示名 / メール編集 (D8 の PII 最小化判断の再評価)。
- 2FA 強制方針と撮影者のログイン手段の関係 (`PasskeyLoginPolicy` が TOTP confirmed を弾く件)。
  §7-2 は「昇格の必須条件にしない」と決めており、方針そのものは本タスクの対象外。
- 公開セルフ登録 (`Features::registration()`) の是非。
- `doc/04` のパスワード字種要件 (「半角英数 8〜16 字」) への追随。**採らないと結論済み**であり、
  検討対象として残さない。
