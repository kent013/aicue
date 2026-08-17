#!/usr/bin/env bash
# Codex 実装レビュー (T221) のプロンプトを組み立てる一時スクリプト (devnotes 配下)。
# 使命・禁止事項は AGENTS.md から機械的に抜き出す (二重管理をしない)。
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DIR="$ROOT/devnotes/20260818-0248-design-token-t1-tests/codex-history"
mkdir -p "$DIR"
OUT="$DIR/impl-review-prompt-round-1.md"

section() { # $1=開始見出し $2=次の見出し
    awk -v s="$1" -v e="$2" 'index($0,s)==1{f=1} f&&index($0,e)==1{f=0} f' "$ROOT/AGENTS.md"
}

{
    echo "# 前提 (AGENTS.md が正本)"
    echo
    section "## 使命 (North Star)" "## 思考原則"
    section "## 思考原則" "## 禁止事項"
    section "## 禁止事項" "## セキュリティ不変条件"
    cat <<'PRINCIPLES'

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system

あなたは Laravel + Svelte アプリのコードレビュアーである。本バッチは **フロントエンドの検査テストと文書のみ**の変更で、PHP / DB / LLM / 課金には触れていない。

レビュー観点:

1. **設計との一致性** — 添付の詳細設計 (施策 1〜7) と実装がずれていないか。設計に無い追補 (描画されない Markdown 領域の除去) が妥当か
2. **検査としての正確性** — 走査・抽出のロジックに、狙った故障を見逃す形 (fail-open) や、正常な変更で誤検知する形が無いか。特に postcss 構文木の走査範囲、Markdown の fenced code / HTML コメントの終端判定
3. **TypeScript 適合性** — `any` / 非 null 断定 / 型アサーションで黙らせていないか。strict で通る形か
4. **テスト網羅性** — 空振り (母集団 0 件で緑) を防いでいるか。負のコントロールがあるか
5. **DESIGN.md 準拠** — `/DESIGN.md` が design token の canonical source。本バッチはトークンの値を 1 つも変えていないはずである。hex 直書きを増やしていないか
6. **文書の整合** — `docs/design-system.md` の新設節と `docs/template-divergence.md` の D27 が、実装が実際に保証している範囲と一致しているか (誇張していないか)

出力形式: ファイルごとに判定を書き、指摘は [Critical] / [Warning] / [Suggestion] に分類する。最後に全体判定を **APPROVED** か **CHANGES_REQUESTED** で 1 行書くこと。

---

# user

## 詳細設計書

PRINCIPLES
    cat "$ROOT/devnotes/20260818-0248-design-token-t1-tests/detailed-design.md"
    cat <<'MID'

## 実装時の追補 (設計との差分)

MID
    cat "$ROOT/devnotes/20260818-0248-design-token-t1-tests/implementation-addendum.md"
    cat <<'MID2'

## 家系の機能台帳が定めた追従の判定基準 (lctl feature design-token-system, 2026-08-17 の報告より抜粋)

> 逐語移植は要らない。自前の検査が次の 2 つを確かめていれば充足である。
> 1. 宣言した token が実際に配布される CSS に届いているか (定義から出力への向き)。ソースの文字列だけを読む検査では足りない — @theme の外へ出す・取り込み順を壊す・コンパイラの版が変わる、のいずれも文字列は無傷のまま画面だけが崩れる
> 2. 運用ガイドの同期契約の本文が空・改変されていないか。見出しだけ残って中身が消える形と、描画されない場所 (コメント / コードブロック) への退避の両方を塞ぐこと

## 実装差分 (git diff)

```diff
MID2
    (cd "$ROOT" && git add -N tests/ docs/ devnotes/ && git diff HEAD --no-color -- tests/ docs/ devnotes/20260818-0248-design-token-t1-tests/implementation-addendum.md)
    cat <<'TAIL'
```

## 感度確認の実測 (故障を 1 件ずつ注入して、狙った assertion が赤くなることを確認)

TAIL
    cat "$ROOT/devnotes/20260818-0248-design-token-t1-tests/red-verification.md"
    cat <<'TAIL2'

## 検証コマンドの結果

TAIL2
    cat "$ROOT/devnotes/20260818-0248-design-token-t1-tests/verification-results.md"
} > "$OUT"

echo "wrote $OUT ($(wc -l < "$OUT") lines)"
