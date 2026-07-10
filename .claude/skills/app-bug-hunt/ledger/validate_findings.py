#!/usr/bin/env python3
"""Bug-hunt Finding Ledger validator / KPI reporter (Stage 0).

findings.jsonl を検証し、success/kill 判定に使う KPI を出力する。
依存は標準ライブラリのみ (jsonschema 等は使わない)。

使い方:
    python3 validate_findings.py findings.jsonl
    python3 validate_findings.py findings.jsonl --json
    python3 validate_findings.py findings.jsonl --strict   # 必須欠損が閾値超で exit 1

設計根拠: .claude/skills/app-bug-hunt/SKILL.md / coverage-audit.md
  (最小スキーマ / success-kill 基準)。app bug-hunt は直列 :8010 (shard 0) /
  並列 :8011..8018 (shard 1..8) の専用 bughunt 環境で走る。
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from dataclasses import dataclass, field

REQUIRED = [
    "finding_id", "run_id", "story_id", "capability_tag", "principal",
    "tenant_relation", "failure_class", "resource_type", "operation",
    "species_key", "oracle_attribution", "evidence_ref", "triage_status",
]
TENANT_RELATIONS = {"self", "same_tenant", "cross_tenant", "guest", "n/a"}
# よくある誤用 (自由文) → 正規 failure_class の早見表。
#   unresponsive_ui/stale_ui -> broken_flow
#   ux_defect/ux_degradation/ux_regression -> ux_dead_end か broken_flow
#   a11y -> ux_dead_end / other
#   info_disclosure -> error_exposure / authz_bypass / other
#   external_egress -> test_env
# 迷ったら新語を作らず "other"。enum は findings.schema.json が正本。
FAILURE_CLASSES = {
    "authz_bypass", "idor", "ux_dead_end", "claimed_success_no_change",
    "validation_gap", "broken_flow", "error_exposure", "data_integrity",
    "perf", "test_env", "other",
}
# bad failure_class 検出時に近い正規候補を suggest する (error 判定は不変、メッセージのみ追加)。
MISUSE_TO_FAILURE_CLASS = {
    "unresponsive_ui": "broken_flow", "stale_ui": "broken_flow",
    "ux_defect": "broken_flow", "ux_degradation": "broken_flow",
    "ux_regression": "broken_flow", "a11y": "other",
    "info_disclosure": "error_exposure", "external_egress": "test_env",
    "perf_issue": "perf", "slow": "perf",
}
TRIAGE_STATUSES = {
    "proposed", "confirmed", "same_as", "split", "fixed", "test_env",
    "wont_fix", "needs_spec",
}
SEVERITIES = {"critical", "high", "medium", "low", "needs_review"}
TOKEN_FIELDS = ("resource_type", "operation")  # 正規化済み小文字 token


def expected_species_key(rec: dict) -> str:
    return ":".join(str(rec.get(k, "")) for k in
                    ("failure_class", "resource_type", "operation", "tenant_relation"))


# species_key を構成する token の許容文字集合 (単一 SoT)。finding 側 (is_token) と
# adjudication 側 (_ADJ_SPECIES_KEY_RE) の双方がこの 1 定義を参照し、両者のドリフトを構造的に防ぐ。
# 小文字英数 + '_' + '-' (例: resource_type 'admin-organization' / 'answer_signals')。
# 旧 is_token ([a-z0-9_]+) / 旧 adjudication regex ([a-z0-9_]+) の厳密な superset として '-' のみ追加する
# (既存 valid token を壊さない invariant を優先)。先頭/末尾記号禁止等の taxonomy 品質 tightening は
# superset 破壊を伴うため本 PR では入れず別 lint に段階導入する (Codex impl-review R1 [Critical])。
_SPECIES_TOKEN = r"[a-z0-9_-]+"
_SPECIES_TOKEN_RE = re.compile(rf"^{_SPECIES_TOKEN}$")


def is_token(v) -> bool:
    return isinstance(v, str) and bool(_SPECIES_TOKEN_RE.match(v))


@dataclass
class Report:
    total: int = 0
    parse_errors: list = field(default_factory=list)        # (lineno, msg)
    record_errors: list = field(default_factory=list)       # (lineno, finding_id, [msgs])
    complete: int = 0                                       # 必須欠損なし件数
    species_consistent: int = 0                             # species_key が導出値と一致
    species: dict = field(default_factory=dict)             # species_key -> count
    oracle_gap: int = 0
    high_crit: int = 0
    high_crit_with_regression: int = 0
    same_as_count: int = 0

    @property
    def valid(self) -> int:
        return self.total - len(self.record_errors)

    def completeness(self) -> float:
        return self.complete / self.total if self.total else 0.0

    def species_consistency(self) -> float:
        return self.species_consistent / self.total if self.total else 0.0

    def duplicate_rate(self) -> float:
        # 重複率 = 1 - 異種数/総数 (種が偏るほど高い = 掘り尽くし接近)
        return 1 - (len(self.species) / self.total) if self.total else 0.0

    def regression_coverage(self) -> float:
        return self.high_crit_with_regression / self.high_crit if self.high_crit else 1.0


def validate_record(rec: dict) -> list:
    errs = []
    for f in REQUIRED:
        if f not in rec or rec[f] in (None, ""):
            errs.append(f"missing required: {f}")
    if rec.get("tenant_relation") not in TENANT_RELATIONS and "tenant_relation" in rec:
        errs.append(f"bad tenant_relation: {rec.get('tenant_relation')!r}")
    if rec.get("failure_class") not in FAILURE_CLASSES and "failure_class" in rec:
        fc = rec.get("failure_class")
        msg = f"bad failure_class: {fc!r}"
        hint = MISUSE_TO_FAILURE_CLASS.get(fc)
        if hint:
            msg += f" (did you mean {hint!r}? use one of the 11 enum values, no free text)"
        errs.append(msg)
    if rec.get("triage_status") not in TRIAGE_STATUSES and "triage_status" in rec:
        errs.append(f"bad triage_status: {rec.get('triage_status')!r}")
    if "severity" in rec and rec["severity"] not in SEVERITIES:
        errs.append(f"bad severity: {rec.get('severity')!r}")
    for f in TOKEN_FIELDS:
        if f in rec and not is_token(rec[f]):
            errs.append(f"{f} must be lower snake token: {rec.get(f)!r}")
    if "finding_id" in rec and not str(rec["finding_id"]).startswith("F-"):
        errs.append(f"finding_id must start with F-: {rec.get('finding_id')!r}")
    # species_key は自由文禁止 = 4 フィールドからの導出と一致必須
    if all(k in rec for k in ("failure_class", "resource_type", "operation", "tenant_relation", "species_key")):
        exp = expected_species_key(rec)
        if rec["species_key"] != exp:
            errs.append(f"species_key {rec['species_key']!r} != derived {exp!r}")
    return errs


def analyze(path) -> Report:
    rep = Report()
    lines = sys.stdin if path == "-" else open(path, encoding="utf-8")
    with lines as fh:
        for lineno, raw in enumerate(fh, 1):
            raw = raw.strip()
            if not raw or raw.startswith("#"):
                continue
            rep.total += 1
            try:
                rec = json.loads(raw)
            except json.JSONDecodeError as e:
                rep.parse_errors.append((lineno, str(e)))
                rep.record_errors.append((lineno, "?", ["json parse error"]))
                continue
            errs = validate_record(rec)
            if errs:
                rep.record_errors.append((lineno, rec.get("finding_id", "?"), errs))
            if not any(e.startswith("missing required") for e in errs):
                rep.complete += 1
            if not any("species_key" in e for e in errs) and "species_key" in rec:
                rep.species_consistent += 1
                rep.species[rec["species_key"]] = rep.species.get(rec["species_key"], 0) + 1
            if rec.get("oracle_attribution") == "oracle_gap":
                rep.oracle_gap += 1
            if rec.get("same_as"):
                rep.same_as_count += 1
            if rec.get("severity") in ("critical", "high"):
                rep.high_crit += 1
                if rec.get("regression_link"):
                    rep.high_crit_with_regression += 1
    return rep


def to_summary(rep: Report) -> dict:
    return {
        "total": rep.total,
        "valid": rep.valid,
        "record_errors": len(rep.record_errors),
        "completeness": round(rep.completeness(), 3),
        "species_consistency": round(rep.species_consistency(), 3),
        "distinct_species": len(rep.species),
        "duplicate_rate": round(rep.duplicate_rate(), 3),
        "oracle_gap": rep.oracle_gap,
        "same_as": rep.same_as_count,
        "high_critical": rep.high_crit,
        "high_critical_regression_coverage": round(rep.regression_coverage(), 3),
    }


# ───────────────────────── adjudication registry (T877) ─────────────────────
# cross-session の「誤検知 / 意図的仕様 / won't-fix」台帳。Phase4 統合 (親) のみが consult し、
# 一致 finding を annotate + downrank する (drop しない)。過剰抑制 (同 species の新規 real bug の
# 取りこぼし) を多層ゲートで構造的に防ぐ。設計: devnotes/20260624-1035-bughunt-adjudication-registry/。
import fnmatch

ADJ_VERDICTS = {"false_positive", "intentional", "wont_fix"}
SCOPE_KINDS = {"route_name", "screen_id", "path_glob"}
COND_KEYS = {"viewport", "auth_role", "browser", "feature_flag", "precondition"}
ADJ_REQUIRED = [
    "adjudication_id", "species_key", "scope", "conditions", "symptom", "verdict",
    "rationale_ref", "source_finding_ids", "adjudicated_at_run", "adjudicated_at_commit",
    "watch_globs", "review_after_days",
]
_ADJ_ID_RE = re.compile(r"^A-[0-9]{3,}$")
_RUN_ID_RE = re.compile(r"^[0-9]{8}-[0-9]{6}$")
_FIND_ID_RE = re.compile(r"^F-")
# adjudication species_key: finding 側 is_token と同一 SoT (_SPECIES_TOKEN) から導出する
# (3 segment の token + tenant_relation)。ハイフン付き resource_type (admin-organization 等) を許容。
_ADJ_SPECIES_KEY_RE = re.compile(
    rf"^{_SPECIES_TOKEN}:{_SPECIES_TOKEN}:{_SPECIES_TOKEN}:(self|same_tenant|cross_tenant|guest|n/a)$"
)
_NOVELTY_STOP = {
    "the", "and", "for", "with", "without", "but", "not", "this", "that", "から", "では",
    "して", "する", "した", "ない", "ます", "page", "url", "test", "step",
}


def normalize(s) -> str:
    return re.sub(r"\s+", " ", str(s).strip().lower())


def _overbroad_glob(v: str) -> bool:
    # path_glob は literal path segment >= 2 必須 (例 /billing/checkout/* / /organizations/*/settings は可、
    # /organizations/* や単独 */**//* は reject)。
    if v in ("", "*", "**", "/*", "/**"):
        return True
    literal_segs = [seg for seg in v.split("/") if seg and "*" not in seg]
    return len(literal_segs) < 2


def validate_adjudications(adjs: list) -> list:
    """adjs: [(lineno, dict|None, raw)] を検証。errors の list[(lineno, adj_id, [msg])] を返す。"""
    errors = []
    ids = {}
    active_keys = {}
    all_ids = {a.get("adjudication_id") for _, a, _ in adjs if isinstance(a, dict)}
    superseded = {a["supersedes"] for _, a, _ in adjs if isinstance(a, dict) and a.get("supersedes")}
    for lineno, adj, _raw in adjs:
        if adj is None:
            errors.append((lineno, "?", ["json parse error"]))
            continue
        errs = []
        for f in ADJ_REQUIRED:
            missing = f not in adj
            if not missing and f != "conditions" and adj[f] in (None, "", [], {}):
                missing = True
            if missing:
                errs.append(f"missing required: {f}")
        aid = adj.get("adjudication_id", "?")
        if "adjudication_id" in adj and not _ADJ_ID_RE.match(str(adj["adjudication_id"])):
            errs.append(f"bad adjudication_id: {adj.get('adjudication_id')!r}")
        if aid in ids:
            errs.append(f"duplicate adjudication_id: {aid}")
        ids[aid] = True
        sk = adj.get("species_key", "")
        if not _ADJ_SPECIES_KEY_RE.match(str(sk)):
            errs.append(f"bad species_key: {sk!r}")
        scope = adj.get("scope") or {}
        sk_kind = scope.get("scope_kind")
        sk_val = scope.get("scope_value", "")
        if sk_kind not in SCOPE_KINDS:
            errs.append(f"bad scope_kind: {sk_kind!r}")
        if not sk_val:
            errs.append("empty scope_value")
        elif sk_kind == "path_glob" and _overbroad_glob(sk_val):
            errs.append(f"overbroad path_glob (need >=2 literal segments): {sk_val!r}")
        conds = adj.get("conditions", {})
        if not isinstance(conds, dict):
            errs.append("conditions must be object")
        else:
            for k in conds:
                if k not in COND_KEYS:
                    errs.append(f"bad condition key: {k!r}")
        sym = adj.get("symptom") or {}
        if not sym.get("required_tokens"):
            errs.append("symptom.required_tokens must be non-empty")
        if adj.get("verdict") not in ADJ_VERDICTS:
            errs.append(f"bad verdict: {adj.get('verdict')!r}")
        sfi = adj.get("source_finding_ids") or []
        if not sfi or not all(_FIND_ID_RE.match(str(x)) for x in sfi):
            errs.append("source_finding_ids must be non-empty list of F-* ids")
        if "adjudicated_at_run" in adj and not _RUN_ID_RE.match(str(adj["adjudicated_at_run"])):
            errs.append(f"bad adjudicated_at_run: {adj.get('adjudicated_at_run')!r}")
        wg = adj.get("watch_globs") or []
        if not wg:
            errs.append("watch_globs must be non-empty")
        elif any(g in ("", "*", "**", "/*", "/**") for g in wg):
            errs.append("watch_globs contains overbroad glob")
        rad = adj.get("review_after_days")
        if not isinstance(rad, int) or rad <= 0:
            errs.append(f"review_after_days must be int>0: {rad!r}")
        sup = adj.get("supersedes")
        if sup is not None:
            if sup == aid:
                errs.append("supersedes self-reference (cycle)")
            elif sup not in all_ids:
                errs.append(f"supersedes unknown id: {sup}")
        # active (未 superseded) 多重: 同一 (species_key, scope, conditions, symptom)
        if aid not in superseded and not errs:
            akey = (sk, json.dumps(scope, sort_keys=True),
                    json.dumps(conds, sort_keys=True), json.dumps(sym, sort_keys=True))
            if akey in active_keys:
                errs.append(f"duplicate active adjudication for key (supersede instead): {active_keys[akey]}")
            else:
                active_keys[akey] = aid
        if errs:
            errors.append((lineno, aid, errs))
    # supersedes DAG: 循環検出 (A->B->A 等)。自己参照は上で個別検出済み。
    sup_map = {a["adjudication_id"]: a.get("supersedes")
               for _, a, _ in adjs if isinstance(a, dict) and a.get("adjudication_id")}
    cyc = set()
    for start in sup_map:
        seen, cur = [], start
        while cur in sup_map and sup_map[cur]:
            cur = sup_map[cur]
            if cur in seen or cur == start:
                cyc.add(start)
                break
            seen.append(cur)
            if len(seen) > len(sup_map):
                cyc.add(start)
                break
    if cyc:
        errors.append((0, "?", [f"supersedes cycle involving: {sorted(cyc)}"]))
    return errors


def load_jsonl(path) -> list:
    out = []
    with open(path, encoding="utf-8") as fh:
        for lineno, raw in enumerate(fh, 1):
            s = raw.strip()
            if not s or s.startswith("#"):
                continue
            try:
                out.append((lineno, json.loads(s), s))
            except json.JSONDecodeError:
                out.append((lineno, None, s))
    return out


def _run_date(run_id):
    if not _RUN_ID_RE.match(str(run_id or "")):
        return None
    try:
        from datetime import datetime
        return datetime.strptime(str(run_id)[:15], "%Y%m%d-%H%M%S")
    except ValueError:
        return None


def _num(s):
    # viewport/幅は非負。先頭以外のハイフン (例 "wide-500px") を負号と誤読しない (Adversarial R1)。
    m = re.search(r"\d+(?:\.\d+)?", str(s))
    return float(m.group()) if m else None


def viewport_satisfies(adj_v: str, obs_v) -> bool:
    """adj_v 例 '<=389px' / '>=768px' / '375px'。obs_v 例 '375px' / 375。"""
    obs = _num(obs_v)
    if obs is None:
        return False
    a = normalize(adj_v)
    n = _num(a)
    if n is None:
        return False
    if a.startswith("<="):
        return obs <= n
    if a.startswith(">="):
        return obs >= n
    if a.startswith("<"):
        return obs < n
    if a.startswith(">"):
        return obs > n
    return obs == n


def _surface(finding) -> dict:
    s = finding.get("surface")
    return s if isinstance(s, dict) else {}


def scope_matches(scope: dict, finding) -> bool:
    """True=一致 / False=不一致 or 判定不能 (= no match 安全側)。"""
    kind = scope.get("scope_kind")
    val = scope.get("scope_value", "")
    surf = _surface(finding)
    if kind == "route_name" and surf.get("route_name") is not None:
        return normalize(surf["route_name"]) == normalize(val)
    if kind == "screen_id" and surf.get("screen_id") is not None:
        return normalize(surf["screen_id"]) == normalize(val)
    if kind == "path_glob" and surf.get("path") is not None:
        return fnmatch.fnmatch(str(surf["path"]), val)
    # fallback: evidence_ref 部分文字列 (surface 欠落時のみ)
    ev = normalize(finding.get("evidence_ref", ""))
    needle = normalize(val).replace("*", "")
    return bool(ev and needle and needle in ev)


def _observed_conditions(finding) -> dict:
    oc = finding.get("observed_conditions")
    return oc if isinstance(oc, dict) else {}


def conditions_status(conds: dict, finding):
    """戻り: None(=ok) または ambiguity reason 文字列。"""
    obs = _observed_conditions(finding)
    for k, v in conds.items():
        if k not in obs:
            return f"condition_unverified:{k}"
        if k == "viewport":
            if not viewport_satisfies(v, obs[k]):
                return f"condition_mismatch:{k}"
        elif normalize(obs[k]) != normalize(v):
            return f"condition_mismatch:{k}"
    # finding が governed key を観測しているが adj が指定していない → 過広適用防止
    for k in obs:
        if k in COND_KEYS and k not in conds:
            return f"condition_unspecified:{k}"
    return None


def _finding_required_text(finding) -> str:
    toks = finding.get("symptom_tokens")
    if isinstance(toks, list) and toks:
        return normalize(" | ".join(str(t) for t in toks))
    # symptom_tokens 不在時のみ本文 fallback (否定誤一致を避けるため最小限)
    parts = [finding.get("summary", ""), finding.get("symptom", ""), finding.get("evidence_ref", "")]
    return normalize(" ".join(str(p) for p in parts if p))


def _is_noise_token(wn: str) -> bool:
    # novelty 判定に無意味な語 (stopword / 数字 / path 様 / hex・ID)。両経路で一貫適用。
    return (not wn or len(wn) < 3 or wn in _NOVELTY_STOP or wn.isdigit()
            or "/" in wn or bool(re.fullmatch(r"[0-9a-f]{6,}", wn)))


def _novelty_tokens(finding) -> list:
    toks = finding.get("symptom_tokens")
    if isinstance(toks, list) and toks:
        cand = [normalize(t) for t in toks]
    else:
        parts = [finding.get("summary", ""), finding.get("symptom", "")]
        cand = [normalize(w) for w in re.split(r"[^0-9A-Za-z぀-ヿ一-鿿-]+", " ".join(str(p) for p in parts if p))]
    # symptom_tokens 経路でも noise を落とす (stopword だけの token を novelty 扱いしない = 一貫性)。
    return [w for w in cand if not _is_noise_token(w)]


# 前置否定: 直前 ~24 字 window 内のどこかに否定語 (without/not/no/... "does not include" 等も拾う)。
# 後置否定: 直後 ~12 字 (日本語 "X 無し"/"X なし" 等)。over-negation は安全側 (ambiguous=actionable) に倒れる。
_NEG_BEFORE = re.compile(r"\b(without|not|no|none|lacks?|lacking|missing|absent)\b")
_NEG_AFTER = re.compile(r"^\s*(無し|なし|ない|無く|欠落|不在|absent|missing|not present|is not|isn't|not returned)")


def _phrase_present_nonneg(phrase: str, text: str) -> bool:
    """text 中に phrase の **否定されていない** 出現があるか (本文 fallback 用)。
    'without x-inertia-location' / 'does not include x-inertia-location' (前置否定) /
    'x-inertia-location 無し' (後置否定) を hit 扱いしない。"""
    p = normalize(phrase)
    if not p:
        return False
    i = text.find(p)
    while i >= 0:
        before = text[max(0, i - 24):i]
        after = text[i + len(p): i + len(p) + 12]
        if not _NEG_BEFORE.search(before) and not _NEG_AFTER.search(after):
            return True
        i = text.find(p, i + len(p))
    return False


def required_hits(adj, finding) -> bool:
    req = adj["symptom"]["required_tokens"]
    toks = finding.get("symptom_tokens")
    if isinstance(toks, list) and toks:
        # symptom_tokens は author が「観測した token」として明示 → presence は素直に substring。
        text = normalize(" | ".join(str(t) for t in toks))
        return all(normalize(r) in text for r in req)
    # 本文 fallback のみ否定誤一致ガードを適用 (Codex impl-R1 Critical)。
    text = _finding_required_text(finding)
    return all(_phrase_present_nonneg(r, text) for r in req)


def has_new_signal(adj, finding) -> bool:
    # required は肯定証拠なので coverage に残す (短くても可)。known_tokens は noise (stopword 等) を
    # 落とす: stopword だけの known_tokens が real な novel 語を「既知」と誤って覆うのを防ぐ (Adversarial R1)。
    known = [normalize(p) for p in adj["symptom"]["required_tokens"] if normalize(p)]
    known += [normalize(p) for p in adj["symptom"].get("known_tokens", [])
              if normalize(p) and not _is_noise_token(normalize(p))]
    for tok in _novelty_tokens(finding):
        if not any(p in tok or tok in p for p in known):
            return True
    return False


def specificity(adj) -> tuple:
    exact = 1 if adj["scope"].get("scope_kind") in ("route_name", "screen_id") else 0
    return (exact, len(adj.get("conditions", {})), len(adj["symptom"]["required_tokens"]))


def match_finding(finding, adj, *, run_id, changed, unresolvable) -> dict | None:
    if finding.get("species_key") != adj.get("species_key"):
        return None
    if not scope_matches(adj["scope"], finding):
        return None

    def res(status, reason=None, expired=False):
        return {
            "adjudication_id": adj["adjudication_id"],
            "adjudication_verdict": adj["verdict"],
            "adjudication_status": status,
            "adjudication_expired": expired,
            "adjudication_ambiguity_reason": reason,
            "_specificity": specificity(adj),
            "_run": adj.get("adjudicated_at_run", ""),
        }

    creason = conditions_status(adj.get("conditions", {}), finding)
    if creason:
        return res("ambiguous", creason)
    if not required_hits(adj, finding):
        return res("ambiguous", "required_token_missing")
    if has_new_signal(adj, finding):
        return res("ambiguous", "new_signal")
    if unresolvable:
        return res("ambiguous", "unresolvable")
    if changed:
        return res("ambiguous", "invalidated_asset")
    rd, ad = _run_date(run_id), _run_date(adj.get("adjudicated_at_run"))
    if rd is None or ad is None:
        return res("ambiguous", "unresolvable")
    if (rd - ad).days >= int(adj["review_after_days"]):  # 経過 N 日ちょうども期限到来 (Adversarial R1 off-by-one)
        return res("ambiguous", "review_window", expired=True)
    return res("known_accepted")


def compute_changed(adj, repo_root, changed_override=None):
    """戻り: (changed:bool, unresolvable:bool)。git 失敗系は必ず unresolvable=True。"""
    if changed_override is not None:
        return (adj["adjudication_id"] in changed_override, False)
    import subprocess
    commit = adj.get("adjudicated_at_commit")
    globs = adj.get("watch_globs") or []
    if not repo_root or not commit or not globs:
        return (False, True)
    try:
        subprocess.run(["git", "-C", repo_root, "cat-file", "-e", f"{commit}^{{commit}}"],
                       check=True, capture_output=True)
        for g in globs:
            ls = subprocess.run(["git", "-C", repo_root, "ls-files", "--", g],
                                capture_output=True, text=True)
            if ls.returncode != 0 or not ls.stdout.strip():
                return (False, True)  # 0-match / 削除 → unresolvable
        diff = subprocess.run(
            ["git", "-C", repo_root, "diff", "--name-only", f"{commit}..HEAD", "--"] + globs,
            capture_output=True, text=True)
        if diff.returncode != 0:
            return (False, True)
        return (bool(diff.stdout.strip()), False)
    except Exception:
        return (False, True)


def annotate_findings(findings, adjs, *, run_id, changed_map=None, repo_root=None):
    """findings (dict list) を非破壊で annotate。戻り: (annotated:list[dict], kpi:dict)。"""
    valid_adjs = [a for _, a, _ in adjs if isinstance(a, dict)]
    superseded = {a["supersedes"] for a in valid_adjs if a.get("supersedes")}
    active = [a for a in valid_adjs if a.get("adjudication_id") not in superseded]
    inval = {a["adjudication_id"]: compute_changed(a, repo_root, changed_map) for a in active}
    kpi = {"accepted_matched": 0, "accepted_high_sev_held": 0, "ambiguous": 0,
           "stale": 0, "rederive_errors": 0}
    out = []
    for f in findings:
        ann = dict(f)
        cands = []
        for a in active:
            ch, un = inval[a["adjudication_id"]]
            m = match_finding(f, a, run_id=run_id, changed=ch, unresolvable=un)
            if m:
                cands.append(m)
        chosen = None
        if cands:
            maxspec = max(c["_specificity"] for c in cands)
            top = [c for c in cands if c["_specificity"] == maxspec]
            amb = [c for c in top if c["adjudication_status"] == "ambiguous"]
            # 最大 specificity 群に ambiguous があれば accept しない (過剰抑制ガード)
            pool = amb if amb else top
            chosen = sorted(pool, key=lambda c: c["_run"], reverse=True)[0]
        sev = str(f.get("severity", "")).lower()
        is_high = sev in ("critical", "high")
        if chosen is None:
            ann.update({"adjudication_status": "none", "adjudication_id": None,
                        "adjudication_verdict": None, "adjudication_expired": False,
                        "adjudication_ambiguity_reason": None,
                        "must_remain_actionable": is_high,
                        "actionable_hold_reason": "high_severity" if is_high else None})
            out.append(ann)
            continue
        status = chosen["adjudication_status"]
        ann.update({k: chosen[k] for k in ("adjudication_id", "adjudication_verdict",
                    "adjudication_status", "adjudication_expired",
                    "adjudication_ambiguity_reason")})
        # 再導出ゲート: 採用 adj に match_finding を再評価し status 不一致なら error
        radj = next((a for a in active if a["adjudication_id"] == chosen["adjudication_id"]), None)
        if radj is not None:
            ch, un = inval[radj["adjudication_id"]]
            re_m = match_finding(f, radj, run_id=run_id, changed=ch, unresolvable=un)
            if re_m is None or re_m["adjudication_status"] != status:
                kpi["rederive_errors"] += 1
                status = "ambiguous"
                ann["adjudication_status"] = "ambiguous"
                ann["adjudication_ambiguity_reason"] = "rederive_mismatch"
        hold = status != "known_accepted" or is_high
        ann["must_remain_actionable"] = hold
        ann["actionable_hold_reason"] = (
            "high_severity" if is_high else ("ambiguous" if status != "known_accepted" else None))
        if status == "known_accepted":
            kpi["accepted_matched"] += 1
            if is_high:
                kpi["accepted_high_sev_held"] += 1
        else:
            kpi["ambiguous"] += 1
            if chosen.get("adjudication_expired") or chosen.get("adjudication_ambiguity_reason") in (
                    "unresolvable", "invalidated_asset", "review_window"):
                kpi["stale"] += 1
        out.append(ann)
    return out, kpi


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description="Bug-hunt Finding Ledger validator")
    ap.add_argument("path", help="findings.jsonl path, or - for stdin")
    ap.add_argument("--json", action="store_true", help="machine summary as JSON")
    ap.add_argument("--strict", action="store_true", help="exit 1 if completeness below --min-completeness")
    ap.add_argument("--min-completeness", type=float, default=0.9)
    ap.add_argument("--adjudications", help="adjudications.jsonl (cross-session registry)")
    ap.add_argument("--annotate", action="store_true",
                    help="emit findings annotated with adjudication status to stdout (non-destructive)")
    ap.add_argument("--run-id", help="current run_id (YYYYMMDD-HHMMSS) for review-window")
    ap.add_argument("--repo-root", help="repo root for invalidation git checks")
    ap.add_argument("--changed-globs-file", help="JSON list of adjudication_ids treated as asset-changed (test/CI)")
    args = ap.parse_args(argv)

    rep = analyze(args.path)
    summary = to_summary(rep)

    adj_errors = []
    if args.adjudications:
        adjs = load_jsonl(args.adjudications)
        adj_errors = validate_adjudications(adjs)
        summary["adjudications_total"] = sum(1 for _, a, _ in adjs if a is not None)
        summary["adjudications_invalid"] = len(adj_errors)
        if args.annotate:
            changed_map = None
            if args.changed_globs_file:
                changed_map = set(json.load(open(args.changed_globs_file, encoding="utf-8")))
            findings = [a for _, a, _ in load_jsonl(args.path) if isinstance(a, dict)]
            # fail-closed: registry に 1 件でも error (per-entry / cycle 等の global) があれば、
            # 壊れた台帳は**一切信頼しない**(抑制ゼロ=全 finding actionable のまま) + exit 1 で loud に失敗。
            # (line-based 除外だと lineno=0 の global error を落とせないため all-or-nothing にする。Codex impl-R2)
            registry = [] if adj_errors else adjs
            annotated, kpi = annotate_findings(
                findings, registry, run_id=args.run_id, changed_map=changed_map, repo_root=args.repo_root)
            summary.update(kpi)
            for rec in annotated:
                print(json.dumps(rec, ensure_ascii=False))
            for lineno, aid, errs in adj_errors:
                print(f"  ADJ L{lineno} [{aid}]: {'; '.join(errs)}", file=sys.stderr)
            return 1 if (adj_errors or kpi["rederive_errors"]) else 0

    if args.json:
        print(json.dumps(summary, ensure_ascii=False, indent=2))
    else:
        print(f"findings: {rep.total}  valid: {rep.valid}  errors: {len(rep.record_errors)}")
        print(f"completeness: {summary['completeness']:.0%}  "
              f"species_consistency: {summary['species_consistency']:.0%}")
        print(f"distinct_species: {summary['distinct_species']}  "
              f"duplicate_rate: {summary['duplicate_rate']:.0%}  "
              f"oracle_gap: {summary['oracle_gap']}")
        print(f"high/critical: {rep.high_crit}  "
              f"regression_coverage: {summary['high_critical_regression_coverage']:.0%}")
        if args.adjudications:
            print(f"adjudications: {summary.get('adjudications_total', 0)}  "
                  f"invalid: {summary.get('adjudications_invalid', 0)}")
        for lineno, fid, errs in rep.record_errors:
            print(f"  L{lineno} [{fid}]: {'; '.join(errs)}", file=sys.stderr)
        for lineno, aid, errs in adj_errors:
            print(f"  ADJ L{lineno} [{aid}]: {'; '.join(errs)}", file=sys.stderr)

    if args.strict and rep.completeness() < args.min_completeness:
        print(f"FAIL: completeness {rep.completeness():.0%} < {args.min_completeness:.0%}",
              file=sys.stderr)
        return 1
    if rep.parse_errors or adj_errors:
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
