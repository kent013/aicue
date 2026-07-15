# 対応マトリクス: design-review Round 1

全体判定: **CHANGES_REQUESTED**。Critical 1・Warning 3・Suggestion 3。

## [Critical] 施策2: クラス文字列依存で実レイアウト崩れ（重なり）を直接検知できない
- 判断: 一部対応（受け入れ基準を明文化）＋ 一部反論（Playwright CI 追加は見送り）
- 根拠: 現状アプリに**アプリ級 E2E/Playwright harness が存在しない**（playwright は bug-hunt スキルの隔離環境のみ、
  package.json に e2e スクリプト無し、tests/e2e 無し）。回帰 1 件のためにビジュアル回帰 CI 基盤を新設するのは
  思考原則 2「今必要なものだけ作る（オーバーエンジニアリング禁止）」に反する。
  代わりに **二段構えの受け入れ基準**を設計に明文化する:
  (a) 自動回帰ガード = vitest の構造契約テスト（wrap/w-full/min-w-0/sm: の存在）、
  (b) 受け入れゲート = 実装時に 375/320/768px（採用中+DL済み 両バッジ）で実ブラウザ screenshot を取得し
  「重なり 0・アイコン/テキスト非切れ」を目視確認（brief 指定の証跡運用を必須ゲート化）。
- 対応内容: detailed-design 施策2 に「受け入れ基準（二段構え）」節を追加。Playwright CI 追加はスコープ外と明記。

## [Warning] 施策1: 640-767px 帯で従来 1 行が本当に成立するか根拠が弱い
- 判断: 対応する（根拠を設計本文で明示）
- 根拠: 操作列 ≈190px + chevron ≈30px = ≈220px。640px でも残り ≈400px がラベル列に確保され窮屈化しない。
  `md`（768）採用だと 640-767 の小型端末まで冗長に 2 段化するため `sm`（640）が最適。
- 対応内容: detailed-design 施策1 に「ブレークポイント根拠」を明記（conceptual 検証マトリクスと整合）。

## [Warning] 施策1: 操作列 w-full justify-end はボタン総幅増（翻訳/将来追加）で右端詰まりの恐れ
- 判断: 対応する（failsafe を追加）
- 根拠: 将来退行防止のフェイルセーフとして操作列にも wrap を許可するのは低コストで妥当。
- 対応内容: 操作列を `flex-wrap gap-y-1` 許可（mobile のみ）、tablet は `sm:flex-nowrap` で 1 行維持。

## [Warning] 施策2: testid 依存で DOM リファクタ耐性が低下
- 判断: 対応する（テスト方針を明記）
- 根拠: testid は「レイアウト契約点」に限定し、文言/表示有無は role/text クエリ優先が健全。
- 対応内容: detailed-design 施策2 のテスト方針に「testid は契約点のみ・内容は role/text 優先」を追記。

## [Suggestion] 施策2: adopted=false/downloaded=false の最小ケースを追加
- 判断: 対応する
- 対応内容: バッジ非表示ケース（過剰 DOM 混入防止）のテストを 1 件追加。

## [Suggestion] 施策1: ラベル列に pr-1 の余白
- 判断: 見送り（任意）
- 根拠: 2 段化＋wrap で視覚衝突は構造的に解消済み。余白追加は DS 由来の gap で足りており、任意調整に留める。

## [Suggestion] 施策2: 「両バッジ同一ラベル内」検証は有効
- 判断: 対応済み（既に計画に含む）
</content>
