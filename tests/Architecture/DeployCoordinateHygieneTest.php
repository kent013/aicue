<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

/*
 * デプロイ基盤の「座標 hygiene」gate (家系正典 deployer-pipeline v1 の aicue 適合形)。
 *
 * **追跡下のファイルに実座標を焼き付けない**ことを機械的に強制する。実座標
 * (実 IPv4 / cloud リソース ID / AWS アカウント ID / 開発機の絶対パス / 他アプリの座標) は
 * `deploy/hosts.yml` (gitignore) にだけ置き、追跡下の成果物は placeholder で書く。
 * こうしておくと「サーバーを差し替えたのに追跡下のどこかに旧 IP が残る」事故が起きない。
 *
 * ★正典 (テンプレート本体) との差は 4 点。いずれも「向きを変える」だけで検査は緩めていない:
 *   (1) 走査根が aicue の実在資産に合わせてある。**存在しない資産 (infra /
 *       .env.production-template) は目録から消さず `required=false` + 反転条件付きの reason で
 *       「不在の申告」として残す** (deploy-terraform feature 未取り込みの記録)
 *   (2) runbook のパスが `docs/deployment-runbook.md`
 *   (3) REPO_URL に **自リポジトリだけ**の allowlist がある
 *       (deploy/deploy.php の `set('repository', ...)` は実値でなければ動かない。
 *       他 org / 他 repo の URL は従来どおり違反)
 *   (4) DOMAIN allowlist に自アプリの公開ドメインがある (他アプリのドメインは従来どおり違反)
 *
 * 構成は 2 軸:
 *   軸 1: 走査対象の台帳 (root x category マトリクス。deny-by-default)
 *   軸 2: カテゴリ別 needle (純関数 deployCoordinateHits。自己テストを持つ)
 *
 * **秘密 (API キー / 鍵) の検出は本 gate の責務外**である (.github/workflows/secret-scan.yml が
 * 担う。重複させない)。本 gate が見るのは「どこに繋がるか」= 座標だけである。
 *
 * category を root ごとに選べる形にしているのは、既存ドキュメントには家系の兄弟アプリ名を
 * 引く正当な散文があるため (AGENTS.md の bug-hunt 節が実例)。「別アプリ名の literal」と
 * 「座標形状」を同じカテゴリに混ぜると、既存ドキュメントを走査対象にした瞬間に赤くなる。
 */

/** 座標カテゴリの全集合 (typo で検査が空振りするのを防ぐため定数化し、台帳の値を全数照合する)。 */
const DEPLOY_COORDINATE_CATEGORIES = [
    'ACCOUNT_ID',   // 12 桁 AWS アカウント ID
    'CLOUD_ID',     // i-/sg-/ami-/vol-/subnet-/vpc- + Route53 Z… + CloudFront E…
    'IPV4',         // private / loopback / TEST-NET 等を除いた実 IPv4
    'DOMAIN',       // allowlist 外の実ドメイン
    'DONOR_NAME',   // テンプレートの元になった別アプリ名 / org 名の literal
    'REPO_URL',     // git@github.com:<org>/… / https://github.com/<org>/…
    'LOCAL_PATH',   // /Users/<name> / /home/<name> (/home/app は Docker 標準として許す)
];

/** deploy 成果物に適用する全カテゴリ。 */
const DEPLOY_ALL_CATEGORIES = DEPLOY_COORDINATE_CATEGORIES;

/**
 * 既存ドキュメントに適用する「座標形状のみ」のカテゴリ。
 *
 * DONOR_NAME / ACCOUNT_ID / DOMAIN を適用しない理由:
 *  - DONOR_NAME: 兄弟アプリのパターン参照は正当な散文 (AGENTS.md §bug-hunt が実例)
 *  - ACCOUNT_ID (\b\d{12}\b) と DOMAIN: 一般文書では誤検知が多い
 */
const DEPLOY_DOC_CATEGORIES = ['CLOUD_ID', 'IPV4', 'REPO_URL', 'LOCAL_PATH'];

/**
 * 走査対象の台帳 (root => 仕様)。
 *
 *  - `required=false` は「まだ作られていない成果物」に限り許され、reason に
 *    **どの TODO が required へ反転させるか**を必ず書く (腐った除外を残さない)。
 *  - `categories` は 1 つ以上必須。
 *
 * const ではなく関数にしているのは、台帳の健全性検査 (categories 非空 / 既知カテゴリ) が
 * PHPStan の定数畳み込みで「常に真」と判定されて空洞化するのを避けるため。
 *
 * @return array<string, array{required: bool, categories: list<string>, reason: string}>
 */
function deployScanRoots(): array
{
    return [
        // ── まだ持っていない deploy 成果物 (不在の申告) ──
        // 家系の deploy-terraform feature を **意図して取り込んでいない** (Lightsail を手で作った)。
        // 目録から消すと「検討していない」と区別が付かなくなるので、反転条件付きで残す。
        'infra' => [
            'required' => false,
            'categories' => DEPLOY_ALL_CATEGORIES,
            'reason' => 'deploy-terraform feature 未取り込み (docs/TODO.md T267 の Conditional)。'.
                'infra/terraform を追加する PR が required=true へ反転させる',
        ],
        '.env.production-template' => [
            'required' => false,
            'categories' => DEPLOY_ALL_CATEGORIES,
            'reason' => 'deploy-terraform feature 未取り込み (docs/TODO.md T267 の Conditional)。'.
                'production 環境を作る PR が required=true へ反転させる '.
                '(現在の staging の設定正本は server 上の shared/.env だけである)',
        ],

        // ── deploy 成果物: 全カテゴリ ──
        'docs/deployment-runbook.md' => [
            'required' => true,
            'categories' => DEPLOY_ALL_CATEGORIES,
            'reason' => '',
        ],
        // deployer-pipeline v1 取り込みで作成済み (DeployPipelineWiringTest W25 が反転を pin する)。
        'deploy' => [
            'required' => true,
            'categories' => DEPLOY_ALL_CATEGORIES,
            'reason' => '',
        ],
        'scripts/deploy.sh' => [
            'required' => true,
            'categories' => DEPLOY_ALL_CATEGORIES,
            'reason' => '',
        ],
        // skill-deploy v1 取り込みで作成済み (DeploySkillContractTest S8 が反転を pin する)。
        '.claude/skills/app-deploy' => [
            'required' => true,
            'categories' => DEPLOY_ALL_CATEGORIES,
            'reason' => '',
        ],

        // ── 既存ドキュメント: 座標形状のみ ──
        'AGENTS.md' => [
            'required' => true,
            'categories' => DEPLOY_DOC_CATEGORIES,
            'reason' => '',
        ],
        'README.md' => [
            'required' => true,
            'categories' => DEPLOY_DOC_CATEGORIES,
            'reason' => '',
        ],
        'docs/architecture.md' => [
            'required' => true,
            'categories' => DEPLOY_DOC_CATEGORIES,
            'reason' => '',
        ],
    ];
}

/**
 * placeholder として素通しする literal の台帳。
 *
 * 実体の判定は「prefix + 全 0」という**形**で行う (長さを台帳に焼くと桁を 1 つ間違えた
 * placeholder が黙って実 ID 扱いになる)。本台帳は誠実性テストの入力として使う:
 *  - 各 literal が実際に素通しされること
 *  - 各 literal が prefix + 0 のみで構成されていること (実 ID を allowlist に紛れ込ませない)
 */
const DEPLOY_COORDINATE_PLACEHOLDERS = [
    '000000000000',
    'i-0000000000000000000',
    'sg-00000000000000000',
    'ami-00000000000000000',
    'vol-0000000000000000000',
    'subnet-00000000000000000',
    'vpc-00000000000000000',
    'Z0000000000000000000',
    'E0000000000000000000',
];

/**
 * ドメイン allowlist (登録可能ドメイン = 末尾 2 ラベル単位)。
 * 「座標」ではなくベンダーのサービス endpoint / 予約ドメインのみを載せる。
 */
const DEPLOY_DOMAIN_ALLOWLIST = [
    // RFC 2606 / 6761 予約
    'example.com', 'example.org', 'example.net',
    // AWS / ツールチェーン (座標ではなくサービスエンドポイント)
    'amazonaws.com', 'amazonses.com', 'amazon.com',
    'hashicorp.com', 'terraform.io',
    'github.com', 'getcomposer.org', 'php.net', 'laravel.com', 'letsencrypt.org',
    // **自アプリの公開ドメイン** (正典との差)。テンプレートは自分のドメインを持たないので
    // 全ドメインを placeholder に強制できたが、aicue は runbook (HTTPS 切替手順 / CORS の
    // AllowedOrigins) で自ドメインを名指しする必要がある。他アプリのドメインは従来どおり違反。
    'aicue.jp',
];

/**
 * 自リポジトリの URL (REPO_URL カテゴリの唯一の allowlist。正典との差)。
 *
 * `deploy/deploy.php` の `set('repository', ...)` は **実値でなければ Deployer が clone できない**
 * ため placeholder に強制できない (テンプレート本体は clone 先を持たないので強制できていた)。
 * 自リポジトリだけを素通しにして、**他 org / 他 repo の URL は従来どおり違反**にする
 * (家系の兄弟アプリの repo URL が混ざる事故を止めるのが本カテゴリの目的なので、
 * 自分自身の URL を許しても目的は損なわれない)。
 *
 * @var list<string> 検出形 (`git@github.com:<owner>/` / `https://github.com/<owner>/`) に合わせた prefix
 */
const DEPLOY_OWN_REPO_ALLOWLIST = [
    'git@github.com:kent013/',
    'https://github.com/kent013/',
];

/*
 * ドメイン検出は **2 段**にする。
 *
 * 単純に「TLD らしければドメイン」とすると `main.tf` / `deploy.sh` / `t4g.medium` /
 * `users.email` のようなファイル名・識別子・インスタンスタイプが全部誤検知する
 * (実測: `users.email` が runbook で、`ec2_generated.tf` が README の
 * `-generate-config-out=` で誤検知した)。逆に TLD を保守的に絞るだけだと
 * `.page` / `.link` / `.email` のような実ドメインを取りこぼす。
 *
 * そこで **どこに書かれているか**で強度を変える:
 *
 *  - 段 1 (強い): **値コンテキスト** = 引用符の中 / `=` の右辺 / `://` の後 / `@` の後。
 *    座標が実際に置かれる位置なので **deny-by-default** にする (英字 2 文字以上はすべて TLD
 *    として扱い、既知の衝突 suffix だけ除外する)。TLD の allowlist にすると `.academy` /
 *    `.finance` のような未収載 TLD がそのまま bypass になる。
 *  - 段 2 (弱い): **散文** = 本文どこでも。ファイル名との衝突が避けられないので
 *    保守的な TLD 一覧に絞る (実ドメインが散文に書かれる現実的なケースを拾う)。
 *
 * どちらの段でも、拡張子と衝突するトークンは TLD として扱わない。
 */

/** 拡張子・識別子と衝突するため TLD として扱わないトークン (実在 ccTLD であっても除外する)。 */
const DEPLOY_NON_TLD_SUFFIXES = [
    'tf', 'tfvars', 'tfstate', 'hcl', 'sh', 'bash', 'zsh', 'md', 'php', 'ts', 'js',
    'mjs', 'cjs', 'json', 'yml', 'yaml', 'toml', 'neon', 'lock', 'log', 'css',
    'scss', 'svelte', 'html', 'xml', 'sql', 'env', 'txt', 'png', 'svg', 'ico',
    'jsonl', 'dist', 'map', 'py', 'rb', 'go', 'sql', 'conf', 'ini', 'bak',
    'medium', 'small', 'large', 'micro', 'nano', 'xlarge',
    // 正典に無い追加。`git@github.com:<owner>/<repo>.git` の末尾が値コンテキストで
    // `<repo>.git` というドメインに見える (`.git` は委任 TLD ではないので誤検知である)。
    // repo URL の検査は REPO_URL カテゴリが専用に持っているので、DOMAIN 側から外して二重に見ない。
    'git',
    // systemd unit / UNIX socket の suffix (いずれも委任 TLD ではない)。
    // runbook が unit 名と socket 名を書くため、値コンテキストで踏むと誤検知になる。
    'service', 'timer', 'socket', 'target', 'sock',
];

/** 段 2 (散文) で TLD として扱うトークン (保守的。英単語 TLD は誤検知源なので入れない)。 */
const DEPLOY_DOMAIN_PROSE_TLDS = [
    'com', 'net', 'org', 'info', 'biz', 'io', 'ai', 'app', 'dev', 'cloud',
    'tv', 'xyz', 'site', 'online', 'store', 'shop', 'tech',
    'jp', 'us', 'uk', 'fr', 'eu', 'cn', 'kr', 'au', 'ca', 'nl', 'ru', 'br',
    'asia', 'tokyo',
];

/** RFC 2606 / 6761 の予約 TLD (これで終わるものはドメイン検出しない)。 */
const DEPLOY_RESERVED_TLDS = ['example', 'invalid', 'test', 'localhost'];

/**
 * 別アプリ名 / org 名の needle。
 *
 * gate 自身が needle を素の literal で持つと本ファイルが自分の検査対象になったときに
 * 赤くなるため **文字列連結**で書く (self-allowlist を作らない)。
 */
const DEPLOY_DONOR_NEEDLES = ['aigen'.'ba', 'spi'.'rux', 'engra'.'phia'];

/**
 * placeholder (prefix + 全 0) を除去する。
 *
 * 長さを固定しないのが要点 (桁を間違えた placeholder も placeholder として素通しし、
 * 逆に非 0 を含む実 ID は必ず残る)。
 */
function deployCoordinateStripPlaceholders(string $contents): string
{
    $patterns = [
        '/\b(?:i|sg|ami|vol|subnet|vpc)-0+\b/',
        '/\b[ZE]0+\b/',
        '/\b0{12,}\b/',
    ];

    foreach ($patterns as $pattern) {
        $contents = preg_replace($pattern, '', $contents) ?? $contents;
    }

    return $contents;
}

/**
 * 正規表現の全マッチ (グループ 0) を返す。
 *
 * @return list<string>
 */
function deployCoordinateMatches(string $pattern, string $subject): array
{
    $count = preg_match_all($pattern, $subject, $found);
    if ($count === false || $count === 0) {
        return [];
    }

    $matches = [];
    foreach ($found[0] as $match) {
        if (is_string($match)) {
            $matches[] = $match;
        }
    }

    return $matches;
}

/** IPv4 が private / loopback / 文書用 / 予約レンジかを判定する。 */
function deployCoordinateIsReservedIpv4(string $ip): bool
{
    $octets = array_map('intval', explode('.', $ip));
    if (count($octets) !== 4) {
        return true;
    }

    foreach ($octets as $octet) {
        if ($octet > 255) {
            return true; // IPv4 ではない (版番号等)
        }
    }

    [$a, $b, $c] = $octets;

    // 文書用レンジは **/24 で厳密に**判定する (/16 相当まで許すと 192.0.x.x / 198.51.x.x /
    // 203.0.x.x の実 public IP が偽グリーンになる)。
    return match (true) {
        $a === 0, $a === 10, $a === 127, $a === 255 => true,
        $a === 192 && $b === 168 => true,
        $a === 172 && $b >= 16 && $b <= 31 => true,
        $a === 169 && $b === 254 => true,
        $a === 192 && $b === 0 && $c === 2 => true,     // 192.0.2.0/24 (TEST-NET-1)
        $a === 198 && $b === 51 && $c === 100 => true,  // 198.51.100.0/24 (TEST-NET-2)
        $a === 203 && $b === 0 && $c === 113 => true,   // 203.0.113.0/24 (TEST-NET-3)
        default => false,
    };
}

/**
 * 座標が実際に置かれる「値コンテキスト」の断片を抽出する。
 *
 * 引用符の中 / `=` の右辺 / `://` の後 / `@` の後。散文 (説明文・パス列挙) を除外することで、
 * 広い TLD 判定を安全に使えるようにするのが目的。
 *
 * @return list<string>
 */
function deployCoordinateValueContexts(string $contents): array
{
    $chunks = [];

    // HCL の補間 `${...}` は**式**であって literal ではない。先に落とす。
    // これをしないと `"${var.project}-${var.env}"` の `var.project` が
    // deny-by-default の TLD 判定で `.project` ドメインとして誤検知する (実測)。
    //
    // 「先頭ラベルが var / module / data … なら素通し」という形は採らない —
    // それだと `app_host = "data.jp"` / `"module.ai"` のような **2 ラベルの実ドメイン**まで
    // 素通しになり偽グリーンになる (Codex 指摘)。除外は構文 (`${...}`) に限定する。
    $contents = preg_replace('/\$\{[^}]*\}/', '', $contents) ?? $contents;

    foreach (['/"([^"\n]*)"/', "/'([^'\n]*)'/"] as $pattern) {
        $count = preg_match_all($pattern, $contents, $found);
        if ($count !== false && $count > 0) {
            foreach ($found[1] as $chunk) {
                if (is_string($chunk)) {
                    $chunks[] = $chunk;
                }
            }
        }
    }

    // 代入の右辺は **列 0 から始まる `KEY = value`** に限る (dotenv / tfvars の形)。
    // インデントされた HCL の body を含めると `value = [for record in x : record.name]` の
    // ような **属性参照**がドメイン誤検知になる (実測)。HCL body 中の実座標は必ず引用符の
    // 中にあるので、上の quoted 抽出で拾える。
    foreach (explode("\n", $contents) as $line) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*=\s*(\S.*)$/', $line, $matches) === 1
            && is_string($matches[1] ?? null)) {
            $chunks[] = $matches[1];
        }
    }

    foreach (['#://[^\s"\'<>`)\]]+#', '/@[A-Za-z0-9._-]+/'] as $pattern) {
        foreach (deployCoordinateMatches($pattern, $contents) as $chunk) {
            $chunks[] = $chunk;
        }
    }

    return $chunks;
}

/**
 * 与えられた TLD をドメインの TLD として扱うか (段 1 = 値コンテキスト用)。
 *
 * **deny-by-default**: 英字 2 文字以上のものはすべて TLD として扱い、
 * 既知の衝突 suffix (拡張子・インスタンスタイプ) だけを除外する。
 * gTLD の allowlist 方式にすると `.academy` / `.finance` / `.restaurant` のような
 * 未収載の実 TLD がそのまま bypass になるため (Codex 指摘)。
 */
function deployCoordinateIsValueTld(string $tld): bool
{
    if (in_array($tld, DEPLOY_NON_TLD_SUFFIXES, true)) {
        return false;
    }

    return preg_match('/^[a-z]{2,}$/', $tld) === 1;
}

/** 段 2 (散文) 用の保守的な TLD 判定。 */
function deployCoordinateIsProseTld(string $tld): bool
{
    if (in_array($tld, DEPLOY_NON_TLD_SUFFIXES, true)) {
        return false;
    }

    return in_array($tld, DEPLOY_DOMAIN_PROSE_TLDS, true);
}

/**
 * ドメインらしき literal のうち allowlist 外のものを返す (段の TLD 判定を差し替えて使う)。
 *
 * @param  callable(string): bool  $isTld
 * @return list<string>
 */
function deployCoordinateDomainHitsIn(string $contents, callable $isTld): array
{
    $hits = [];
    $pattern = '/\b(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}\b/i';

    foreach (deployCoordinateMatches($pattern, $contents) as $candidate) {
        $lower = strtolower($candidate);
        $labels = explode('.', $lower);
        $tld = $labels[count($labels) - 1];

        if (in_array($tld, DEPLOY_RESERVED_TLDS, true)) {
            continue;
        }

        if (! $isTld($tld)) {
            continue;
        }

        $registrable = implode('.', array_slice($labels, -2));
        if (in_array($registrable, DEPLOY_DOMAIN_ALLOWLIST, true)) {
            continue;
        }

        $hits[] = $candidate;
    }

    return $hits;
}

/**
 * 2 段 (値コンテキスト = 広い TLD / 散文 = 保守的 TLD) でドメイン literal を検出する。
 *
 * @return list<string>
 */
function deployCoordinateDomainHits(string $contents): array
{
    $hits = deployCoordinateDomainHitsIn($contents, 'deployCoordinateIsProseTld');

    foreach (deployCoordinateValueContexts($contents) as $chunk) {
        foreach (deployCoordinateDomainHitsIn($chunk, 'deployCoordinateIsValueTld') as $hit) {
            $hits[] = $hit;
        }
    }

    return array_values(array_unique($hits));
}

/**
 * 指定カテゴリ集合で座標 literal を検出する純関数。
 *
 * root を渡さず**カテゴリ集合を渡す**のが要点 (台帳のマトリクスと 1:1 に対応させ、
 * 検出器の自己テストがカテゴリ絞り込みまで被覆できるようにする)。
 *
 * @param  list<string>  $categories
 * @return list<string> 検出内容の説明 (カテゴリ: literal)
 */
function deployCoordinateHits(string $contents, array $categories): array
{
    $sanitized = deployCoordinateStripPlaceholders($contents);
    $hits = [];

    if (in_array('ACCOUNT_ID', $categories, true)) {
        foreach (deployCoordinateMatches('/\b\d{12}\b/', $sanitized) as $match) {
            $hits[] = 'ACCOUNT_ID: '.$match;
        }
    }

    if (in_array('CLOUD_ID', $categories, true)) {
        $patterns = [
            '/\b(?:i|sg|ami|vol|subnet|vpc)-[0-9a-f]{8,17}\b/',
            '/\bZ[0-9A-Z]{12,}\b/',
            '/\bE[0-9A-Z]{12,}\b/',
        ];
        foreach ($patterns as $pattern) {
            foreach (deployCoordinateMatches($pattern, $sanitized) as $match) {
                $hits[] = 'CLOUD_ID: '.$match;
            }
        }
    }

    if (in_array('IPV4', $categories, true)) {
        foreach (deployCoordinateMatches('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $sanitized) as $match) {
            if (! deployCoordinateIsReservedIpv4($match)) {
                $hits[] = 'IPV4: '.$match;
            }
        }
    }

    if (in_array('DOMAIN', $categories, true)) {
        foreach (deployCoordinateDomainHits($sanitized) as $match) {
            $hits[] = 'DOMAIN: '.$match;
        }
    }

    if (in_array('DONOR_NAME', $categories, true)) {
        foreach (DEPLOY_DONOR_NEEDLES as $needle) {
            if (stripos($sanitized, $needle) !== false) {
                $hits[] = 'DONOR_NAME: '.$needle;
            }
        }
    }

    if (in_array('REPO_URL', $categories, true)) {
        $patterns = [
            '/git@github\.com:[A-Za-z0-9_.-]+\//',
            '#https://github\.com/[A-Za-z0-9_.-]+/#',
        ];
        foreach ($patterns as $pattern) {
            foreach (deployCoordinateMatches($pattern, $sanitized) as $match) {
                // 自リポジトリ (owner 単位) だけ素通し。他 org / 他 repo は違反のまま。
                if (in_array($match, DEPLOY_OWN_REPO_ALLOWLIST, true)) {
                    continue;
                }
                $hits[] = 'REPO_URL: '.$match;
            }
        }
    }

    if (in_array('LOCAL_PATH', $categories, true)) {
        $patterns = [
            '#/Users/[A-Za-z0-9_.-]+#',
            '#/home/(?!app\b)[A-Za-z0-9_.-]+#',
        ];
        foreach ($patterns as $pattern) {
            foreach (deployCoordinateMatches($pattern, $sanitized) as $match) {
                $hits[] = 'LOCAL_PATH: '.$match;
            }
        }
    }

    return $hits;
}

/**
 * git が追跡している (または追跡予定の) ファイルだけを列挙する。
 *
 * FS を素で walk すると gitignore 済みの `.terraform/` (provider バイナリ数百 MB) まで
 * 読んでしまう。「テンプレートに焼き付いた座標」を見る gate なので**追跡対象**が正しい範囲。
 * `--others --exclude-standard` を付けるのは commit 前の新規ファイルも検査するため。
 *
 * @return list<string> base_path 相対のパス
 */
function deployCoordinateTrackedFiles(string $root): array
{
    $result = Process::path(base_path())->run([
        'git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard', '--', $root,
    ]);

    if (! $result->successful()) {
        return [];
    }

    $files = [];
    foreach (explode("\0", $result->output()) as $path) {
        if ($path !== '') {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

// ───────────────────────── 軸 1: 台帳の健全性 ─────────────────────────

test('scan root 台帳: required=true の root は実在する', function (): void {
    $missing = [];
    foreach (deployScanRoots() as $root => $spec) {
        if ($spec['required'] === true && ! file_exists(base_path($root))) {
            $missing[] = $root;
        }
    }

    expect($missing)->toBe([]);
});

test('scan root 台帳: required=false の root は reason 非空である', function (): void {
    $offenders = [];
    foreach (deployScanRoots() as $root => $spec) {
        if ($spec['required'] === false && trim($spec['reason']) === '') {
            $offenders[] = $root;
        }
    }

    expect($offenders)->toBe([]);
});

test('scan root 台帳: 全 root が既知カテゴリを 1 つ以上持つ', function (): void {
    $offenders = [];
    foreach (deployScanRoots() as $root => $spec) {
        $categories = $spec['categories'];
        if ($categories === []) {
            $offenders[] = $root.' (カテゴリ未指定)';

            continue;
        }

        foreach ($categories as $category) {
            if (! in_array($category, DEPLOY_COORDINATE_CATEGORIES, true)) {
                $offenders[] = $root.' (未知カテゴリ: '.$category.')';
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('scan root 台帳: 走査ファイル集合が空でない (空振り検出)', function (): void {
    $total = 0;
    $empty = [];
    foreach (deployScanRoots() as $root => $spec) {
        $count = count(deployCoordinateTrackedFiles($root));
        $total += $count;

        // **required=true の root は 1 件以上でなければならない** (正典より強い)。
        // 正典は合計の床値だけを見ていたが、aicue は母集団が小さい (terraform 不在) ため
        // 合計だけだと「1 本の root が改名で消えても合計が床を超えたまま」になりうる。
        if ($spec['required'] === true && $count === 0) {
            $empty[] = $root;
        }
    }

    expect($empty)->toBe([], 'required=true の走査根が 1 ファイルも持ちません (改名 / 移動を疑う)');

    // deploy/ 3 本 + scripts/deploy.sh + skill 1 本 + runbook + 既存ドキュメント 3 本 = 9 本。
    // 床値は正典 (terraform 込みで 20 超) と違って小さいが、上の per-root 検査と併せて
    // 「どの根が消えたか」まで分かる形にしている。
    expect($total)->toBeGreaterThanOrEqual(9);
});

// ───────────────────────── 軸 2: 走査本体 ─────────────────────────

test('deploy 成果物と対象ドキュメントに他アプリの実座標が混入していない', function (): void {
    $violations = [];

    foreach (deployScanRoots() as $root => $spec) {
        foreach (deployCoordinateTrackedFiles($root) as $relative) {
            $absolute = base_path($relative);
            if (! is_file($absolute)) {
                continue;
            }

            $contents = file_get_contents($absolute);
            if (! is_string($contents) || str_contains($contents, "\0")) {
                continue; // バイナリは対象外
            }

            foreach (deployCoordinateHits($contents, $spec['categories']) as $hit) {
                $violations[] = $relative.' -> '.$hit;
            }
        }
    }

    expect(array_values(array_unique($violations)))->toBe([]);
});

// ───────────────────────── 軸 3: 検出器の自己テスト ─────────────────────────

test('検出器: 各カテゴリの実座標を確実に検出する (positive)', function (): void {
    $all = DEPLOY_ALL_CATEGORIES;

    expect(deployCoordinateHits('account_id = "123456789012"', $all))->not->toBe([]);
    expect(deployCoordinateHits('id = "i-0abcdef1234567890"', $all))->not->toBe([]);
    expect(deployCoordinateHits('sg = "sg-0fedcba9876543210"', $all))->not->toBe([]);
    expect(deployCoordinateHits('zone = "Z0ABCDEF1234567890"', $all))->not->toBe([]);
    expect(deployCoordinateHits('allow 100.200.30.40/32;', $all))->not->toBe([]);
    // 文書用レンジは /24 のみ。同じ /16 内の実 public IP は落とす
    expect(deployCoordinateHits('ip = "192.0.50.7"', $all))->not->toBe([]);
    expect(deployCoordinateHits('ip = "198.51.42.9"', $all))->not->toBe([]);
    expect(deployCoordinateHits('ip = "203.0.42.9"', $all))->not->toBe([]);
    expect(deployCoordinateHits('app_host = "app.some-real-domain.jp"', $all))->not->toBe([]);
    // 値コンテキストでは 2 文字 ccTLD と広い gTLD も検出する (段 1)
    expect(deployCoordinateHits('app_host = "www.some-real-host.page"', $all))->not->toBe([]);
    expect(deployCoordinateHits('endpoint = "https://hook.some-real-host.link/ses"', $all))->not->toBe([]);
    expect(deployCoordinateHits('MAIL_FROM_ADDRESS="noreply@mail.some-real-host.email"', $all))->not->toBe([]);
    expect(deployCoordinateHits('app_host = "stg.some-real-host.co"', $all))->not->toBe([]);
    // deny-by-default: allowlist に載せていない実 TLD も値コンテキストなら検出する
    expect(deployCoordinateHits('app_host = "prod.some-real-host.academy"', $all))->not->toBe([]);
    expect(deployCoordinateHits('app_host = "prod.some-real-host.finance"', $all))->not->toBe([]);
    expect(deployCoordinateHits('app_host = "prod.some-real-host.restaurant"', $all))->not->toBe([]);
    // **2 ラベルの実ドメイン**も検出する (HCL 参照式と同形でも素通しにしない)
    expect(deployCoordinateHits('app_host = "data.jp"', $all))->not->toBe([]);
    expect(deployCoordinateHits('app_host = "module.ai"', $all))->not->toBe([]);
    expect(deployCoordinateHits('app_host = "terraform.dev"', $all))->not->toBe([]);
    expect(deployCoordinateHits('git@github.com:some'.'-org/x.git', $all))->not->toBe([]);
    expect(deployCoordinateHits('see https://github.com/some'.'-org/repo', $all))->not->toBe([]);
    // 自リポジトリの allowlist は **owner 単位**なので、別 owner は素通しにならない。
    expect(deployCoordinateHits('git@github.com:kent014/aicue.git', $all))->not->toBe([]);
    // 自アプリのドメイン allowlist は登録可能ドメイン単位。別ドメインは素通しにならない。
    expect(deployCoordinateHits('app_host = "aicue2.jp"', $all))->not->toBe([]);
    expect(deployCoordinateHits('cd /Users/someone/repo', $all))->not->toBe([]);
    expect(deployCoordinateHits('deploy_path: /srv/'.DEPLOY_DONOR_NEEDLES[1], $all))->not->toBe([]);
    expect(deployCoordinateHits('home = /home/someone/app', $all))->not->toBe([]);
});

test('検出器: placeholder / 予約値 / ベンダー endpoint は素通しする (negative)', function (): void {
    $all = DEPLOY_ALL_CATEGORIES;

    expect(deployCoordinateHits('ec2_instance_id = "i-0000000000000000000"', $all))->toBe([]);
    expect(deployCoordinateHits('route53_zone_id = "Z0000000000000000000"', $all))->toBe([]);
    expect(deployCoordinateHits('app_host = "app.example.com"', $all))->toBe([]);
    expect(deployCoordinateHits('records = ["10 feedback-smtp.ap-northeast-1.amazonses.com"]', $all))->toBe([]);
    expect(deployCoordinateHits('identifiers = ["ses.amazonaws.com"]', $all))->toBe([]);
    expect(deployCoordinateHits('private_ip = "10.0.1.5"', $all))->toBe([]);
    expect(deployCoordinateHits('loopback 127.0.0.1 and doc 203.0.113.7', $all))->toBe([]);
    expect(deployCoordinateHits("set('repository', 'git@github.com:<ORG>/<REPO>.git');", $all))->toBe([]);
    // **自リポジトリ / 自ドメインの allowlist** (正典との差) が本当に素通しになること。
    expect(deployCoordinateHits("set('repository', 'git@github.com:kent013/aicue.git');", $all))->toBe([]);
    expect(deployCoordinateHits('see https://github.com/kent013/aicue/settings', $all))->toBe([]);
    expect(deployCoordinateHits('APP_URL=https://aicue.jp', $all))->toBe([]);
    expect(deployCoordinateHits('useradd --home /home/app', $all))->toBe([]);
    expect(deployCoordinateHits('provider version 5.100.0 pinned', $all))->toBe([]);
    // ファイル名はドメインとして扱わない (TLD allowlist の効果)
    expect(deployCoordinateHits('see infra/terraform/main.tf and scripts/deploy.sh', $all))->toBe([]);
    expect(deployCoordinateHits('cp backend.tf.example backend.tf', $all))->toBe([]);
    // 英単語 TLD と衝突するコード識別子 (実測の誤検知ケース。散文なので段 2 = 保守的判定)
    expect(deployCoordinateHits('PII は users.email / users.name 列', $all))->toBe([]);
    // `.git` / systemd unit の suffix は TLD として扱わない (実測の誤検知ケース)
    expect(deployCoordinateHits('repo = "some-app.git"', $all))->toBe([]);
    expect(deployCoordinateHits('unit = "app-queue-default.service"', $all))->toBe([]);
    expect(deployCoordinateHits('unit = "app-scheduler.timer"', $all))->toBe([]);
    expect(deployCoordinateHits('fastcgi_pass = "unix:/run/php-fpm/app.sock"', $all))->toBe([]);
    // 値コンテキストでも拡張子・インスタンスタイプは TLD として扱わない (実測の誤検知ケース)
    expect(deployCoordinateHits('terraform plan -generate-config-out=ec2_generated.tf', $all))->toBe([]);
    expect(deployCoordinateHits('ec2_instance_type = "t4g.medium"', $all))->toBe([]);
    expect(deployCoordinateHits('key = "<APP>/<ENV>/terraform.tfstate"', $all))->toBe([]);
    expect(deployCoordinateHits('run: bash scripts/deploy.sh <host>', $all))->toBe([]);
    // インデントされた HCL body の属性参照 (実測の誤検知ケース)
    expect(deployCoordinateHits('  value = [for record in aws_route53_record.dkim : record.name]', $all))->toBe([]);
    expect(deployCoordinateHits('  public_ip = module.compute.public_ip', $all))->toBe([]);
    // 引用符の中の HCL 補間 `${...}` は式なので素通しする (実測の誤検知ケース)
    expect(deployCoordinateHits('  name = "${var.project}-${var.env}-ses-notifications"', $all))->toBe([]);
    expect(deployCoordinateHits('  name = "${var.dkim_tokens[count.index]}._domainkey.example.com"', $all))->toBe([]);
    // `aws_x.y` は label に `_` が入るためドメイン候補にならない (ホスト名に `_` は使えない)
    expect(deployCoordinateHits('  topic = "aws_sns_topic.ses_notifications"', $all))->toBe([]);
});

test('検出器: カテゴリ絞り込みが効く', function (): void {
    $all = DEPLOY_ALL_CATEGORIES;
    $docs = DEPLOY_DOC_CATEGORIES;
    $prose = DEPLOY_DONOR_NEEDLES[1].' の BASELINE_FEATURES 方式に従う';

    // 既存ドキュメント root では兄弟アプリ名の散文を許す
    expect(deployCoordinateHits($prose, $docs))->toBe([]);
    expect(deployCoordinateHits($prose, $all))->not->toBe([]);

    // 12 桁数値とドメインは既存ドキュメント root では落とさない
    expect(deployCoordinateHits('order id 123456789012', $docs))->toBe([]);
    expect(deployCoordinateHits('order id 123456789012', $all))->not->toBe([]);
    expect(deployCoordinateHits('host is app.some-real-domain.jp', $docs))->toBe([]);

    // 段の違い: 散文の `.page` は素通しするが、値コンテキストの `.page` は検出する
    expect(deployCoordinateHits('the some-real-host.page section', $all))->toBe([]);
    expect(deployCoordinateHits('app_host = "some-real-host.page"', $all))->not->toBe([]);

    // 空のカテゴリ集合では何も検出しない (絞り込みが本当に効いていることの裏)
    expect(deployCoordinateHits('cd /Users/someone/repo', []))->toBe([]);
});

test('placeholder 台帳の誠実性: 全 literal が素通しされ、かつ prefix + 全 0 である', function (): void {
    $notPassed = [];
    $notZeros = [];

    foreach (DEPLOY_COORDINATE_PLACEHOLDERS as $placeholder) {
        if (deployCoordinateHits('value = "'.$placeholder.'"', DEPLOY_ALL_CATEGORIES) !== []) {
            $notPassed[] = $placeholder;
        }

        // prefix ('i-' / 'sg-' / 'Z' 等) を落とした残りが 0 だけであること。
        $digits = preg_replace('/^[A-Za-z]+-?/', '', $placeholder) ?? $placeholder;
        if ($digits === '' || trim($digits, '0') !== '') {
            $notZeros[] = $placeholder;
        }
    }

    expect($notPassed)->toBe([]);
    expect($notZeros)->toBe([]);
});
