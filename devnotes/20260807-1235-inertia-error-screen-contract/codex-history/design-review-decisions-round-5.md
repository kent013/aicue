# 対応マトリクス: design-review Round 5

Codex 全体判定: CHANGES_REQUESTED
(S1・S2・S3 APPROVE / S4 REQUEST_CHANGES / S5・S6 APPROVE)
[Critical] 0 件・[Warning] 1 件・[Suggestion] 0 件。

## [Warning] S4: 「既存の Cache-Control directive を落とさない」テストが成立していない

- 判断: **対応する** (Codex 提示の 3 案のうち「小さな Support クラスへ切り出す」を採用)
- 根拠: 指摘は完全に正当で、こちらのテスト設計の誤りだった。
  `render()` は**原応答を変更せず新しい Inertia 応答を生成**するため
  (`Inertia::render(...)->toResponse($request)`)、原応答へ `must-revalidate` を積んでも
  `$rendered` には移植されない。つまり前案のテストは
  「`set()` への退行」ではなく「原応答の Cache-Control を allowlist 移植していない」という
  **別契約**で失敗する。テストが検出したい対象と、失敗する理由が食い違っていた。
- 案の選択:
  - 「原応答の Cache-Control も移植する」案は**採らない**。ヘッダ移植は allowlist
    (deny-by-default) で `Retry-After` のみと決めており、テストを通す目的で
    契約を広げるのは本末転倒 (Codex 自身も「単にテストを通す目的では追加しない」と付言)
  - 「Inertia 生成応答が持つ実 directive の保持を Feature で固定する」案も採らない。
    vendor が何を積むかに依存するテストになり、Inertia のバージョン更新で壊れる
  - よって **キャッシュポリシーを独立した小さな Support クラスへ切り出す**案を採る。
    Codex も「既存 directive 保持を明確な契約にする場合に限り妥当」としており、
    本設計はまさにそれを契約として書いてしまっているので条件を満たす。
    reflection で private メソッドを叩く回避策は採らない
- 対応内容:
  - 新規 `app/Support/Http/ErrorScreenCachePolicy.php` を追加し、
    `apply(Response $response): void` に Vary / no-store / private の適用を集約。
    「加算方式で既存 directive を落とさない」ことをクラスの契約として docblock に明記
  - `InertiaExceptionRenderer::render()` は `ErrorScreenCachePolicy::apply($rendered)` を
    呼ぶだけにし、**原応答ではなく生成した応答に適用する**ことをコメントで明示
  - S4 の変更箇所 / 施策一覧 / 波及変更 / PHPStan チェックに新ファイルを追加
  - テスト計画を修正:
    - Feature 側から「既存 directive を落とさない」を**削除**し、削除理由
      (原応答と生成応答の混同) を設計書に残す
    - 新規 `tests/Unit/Http/ErrorScreenCachePolicyTest.php` に 5 ケース
      (no-store と private / public を残さない / 既存 directive を落とさない /
       二重適用しても矛盾しない / 既存 Vary を落とさず 3 ヘッダ追加) を置く
  - mutation M17 の対象テストを `ErrorScreenCachePolicyTest` へ付け替え

## ラウンド上限に関する記録

app-design SKILL.md の規定により詳細設計レビューは**最大 5 ラウンド**。本ラウンドで上限に達した。
Round 5 の全体判定は CHANGES_REQUESTED で、残件は S4 の [Warning] 1 件のみ。
その 1 件は本マトリクスのとおり全面的に反映済み (Codex 提示の選択肢から根拠を添えて 1 案を採用) だが、
**反映後の再レビューは上限のため実施していない**。
未解消の Critical は 0 件。S1/S2/S3/S5/S6 は Round 5 時点で APPROVE 済み。
