<?php

namespace Porro\Laravel\Activity;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Porro\Core\Activity\Cidr;
use Porro\Core\Activity\Countries;
use Porro\Core\Activity\Entry;
use Porro\Core\Activity\FlaggedIp;
use Porro\Core\Activity\IpDetails;
use Porro\Core\Activity\NginxBlocklist;
use Porro\Core\Activity\NginxStatus;
use Porro\Core\Activity\ProtectedRanges;
use Porro\Core\Activity\Recorder;
use Throwable;

/**
 * Activity and blocklist data shaped for a page: labels, countries, "2
 * minutes ago". Untrusted input (the cached activity) is checked field by
 * field, so anything odd is left out rather than breaking the page.
 */
class Presenter
{
    /** How each signal reads on a chip. */
    public const SIGNALS = [Recorder::PROBE => 'probe', Recorder::LOGIN_FAILED => 'login', Recorder::NOT_FOUND => '404', Recorder::POST_FLOOD => 'form'];

    public function __construct(private Activity $activity) {}

    /**
     * The Suspicious activity section, from SiteHealth's "activity".
     *
     * @return array<string, mixed>|null
     */
    public function activity(mixed $activity): ?array
    {
        if (! is_array($activity)) {
            return null;
        }

        $flagged = [];

        foreach (is_array($activity['flagged'] ?? null) ? $activity['flagged'] : [] as $ip) {
            if (! is_array($ip) || ! is_string($ip['ip'] ?? null) || filter_var($ip['ip'], FILTER_VALIDATE_IP) === false) {
                continue;
            }

            $signals = [];

            foreach (self::SIGNALS as $signal => $label) {
                if (($n = (int) ($ip['counts'][$signal] ?? 0)) > 0) {
                    $signals[] = ['label' => $label, 'count' => $n];
                }
            }

            usort($signals, fn ($a, $b) => $b['count'] <=> $a['count']);

            $flagged[] = [
                'ip' => $ip['ip'],
                'country' => self::country($ip['country'] ?? null),
                'signals' => $signals,
                'request' => trim(((string) ($ip['last_method'] ?? '')).' '.((string) ($ip['last_path'] ?? ''))),
                'seen' => isset($ip['last_seen']) ? Carbon::createFromTimestamp((int) $ip['last_seen'])->diffForHumans() : null,
                'blocked' => $this->isBlocked($ip['ip']),
                'badges' => self::tableBadges((array) ($ip['badges'] ?? [])),
                'reason' => __('Flagged: :signals', ['signals' => implode(', ', array_map(fn ($s) => "{$s['label']} ×{$s['count']}", $signals))]),
            ];
        }

        $totals = is_array($activity['totals'] ?? null) ? $activity['totals'] : [];

        return [
            'recording' => (bool) ($activity['recording'] ?? false),
            'hours' => (int) ($activity['hours'] ?? 24),
            'totals' => [
                ['label' => __('Probes for secrets and admin tools'), 'value' => (int) ($totals[Recorder::PROBE] ?? 0)],
                ['label' => __('Failed logins'), 'value' => (int) ($totals[Recorder::LOGIN_FAILED] ?? 0)],
                ['label' => __('404s'), 'value' => (int) ($totals[Recorder::NOT_FOUND] ?? 0)],
                ['label' => __('Form posts'), 'value' => (int) ($totals[Recorder::POST_FLOOD] ?? 0)],
            ],
            'flagged' => $flagged,
            'blocked' => (int) ($activity['blocked'] ?? 0),
            'safelisted' => (int) ($activity['safelisted'] ?? 0),
            'countries' => is_array($activity['countries'] ?? null) ? $activity['countries'] : null,
        ];
    }

    /**
     * The blocklist, safelist, enforcement and protected ranges.
     *
     * @return array<string, mixed>
     */
    public function blocking(): array
    {
        $now = time();

        try {
            $list = $this->activity->blocklist();
            $blocks = $list->blocks($now);
            $safe = $list->safe($now);
            $failed = false;
        } catch (Throwable $e) {
            report($e);
            [$blocks, $safe, $failed] = [[], [], true];
        }

        $nginxPath = (string) config('porro.blocklist.nginx_path');

        $cloudflare = app(CloudflareMode::class)->status();
        $cloudflare['synced'] = $cloudflare['synced_at'] ? Carbon::createFromTimestamp((int) $cloudflare['synced_at'])->diffForHumans() : null;

        return [
            'failed' => $failed,
            'cloudflare' => $cloudflare,
            // The newest few; the rest are on the list's own page.
            'blocks' => array_map(fn (Entry $e) => $this->entry($e), array_slice(self::newestFirst($blocks), 0, self::PREVIEW)),
            'safe' => array_map(fn (Entry $e) => $this->entry($e), array_slice(self::newestFirst($safe), 0, self::PREVIEW)),
            'blockCount' => count($blocks),
            'safeCount' => count($safe),
            'enforcement' => [
                'app' => $this->activity->enforcing(),
                'nginx' => (bool) config('porro.blocklist.nginx'),
                'production' => $this->activity->isProduction(),
                'nginxWritten' => is_file($nginxPath) ? Carbon::createFromTimestamp((int) filemtime($nginxPath))->diffForHumans() : null,
                'include' => "include {$nginxPath};",
                'realIp' => NginxBlocklist::realIp($this->activity->ranges()),
                'reload' => NginxStatus::reloadJob($nginxPath, (string) (config('porro.blocklist.nginx_stamp') ?: NginxStatus::STAMP)),
                'servers' => app(NginxServers::class)->present(),
            ],
            'protected' => array_map(fn (array $list) => [
                'source' => $list['source'],
                'label' => __($list['label']),
                'why' => __($list['why']),
                'from' => $list['from'],
                'ranges' => $list['ranges'],
                'count' => count($list['ranges']),
                'on' => $list['on'],
                // How to switch it off (or back on), for the lists that can be.
                'env' => $list['optional'] ? self::PROTECT_ENV[$list['source']] ?? null : null,
            ], array_values(array_filter($this->activity->ranges()->about(), fn ($list) => $list['ranges'] !== [] || ! $list['on']))),
        ];
    }

    /**
     * The nginx setup page: enforcement and servers, whether the site is
     * behind Cloudflare (so the real IP lines come first), and whether the
     * cache is shared, without which only this server can be listed.
     *
     * @return array<string, mixed>
     */
    public function nginx(?string $remoteAddr = null): array
    {
        $blocking = $this->blocking();
        $enforcement = $blocking['enforcement'];

        // Seen through Cloudflare: remember it, so the deny file carries the
        // real IP lines without anyone having to set anything.
        if ($remoteAddr !== null && $this->activity->ranges()->isCloudflare($remoteAddr)) {
            Cache::forever(Activity::BEHIND_CLOUDFLARE, true);
        }

        $servers = $enforcement['servers'];
        $count = static fn (array $states) => count(array_filter($servers, fn ($s) => in_array($s['state'], $states, true)));

        return [
            'enforcement' => $enforcement,
            'behindCloudflare' => $blocking['cloudflare']['configured'] || ($remoteAddr !== null && $this->activity->ranges()->isCloudflare($remoteAddr)),
            'realIpInFile' => $this->activity->nginxRealIp(),
            'sharedCache' => ! in_array(config('cache.default'), ['file', 'array', 'apc'], true),
            'example' => self::nginxExample($enforcement['include']),
            'progress' => [
                'servers' => count($servers),
                'included' => $count([NginxStatus::WAITING, NginxStatus::UNCONFIRMED, NginxStatus::LOADED]),
                'loaded' => $count([NginxStatus::LOADED]),
            ],
        ];
    }

    /**
     * A trimmed version of the site's nginx config, to show where the
     * include goes: inside server { }, above the first location. Each line
     * says whether it's the one to add.
     *
     * @return list<array{line: string, add: bool}>
     */
    public static function nginxExample(string $include): array
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'example.com';
        $lines = [
            ['server {', false],
            ['    listen 443 ssl;', false],
            ["    server_name {$host};", false],
            ['    root '.public_path().';', false],
            ['', false],
            ["    {$include}", true],
            ['', false],
            ['    location / {', false],
            ['        try_files $uri $uri/ /index.php?$query_string;', false],
            ['    }', false],
            ['    # … the rest of the config stays as it is', false],
            ['}', false],
        ];

        return array_map(fn ($l) => ['line' => $l[0], 'add' => $l[1]], $lines);
    }

    /**
     * Short badges for the activity table. A research scanner says it's
     * low risk, which is why it sits at the bottom.
     *
     * @param  array<mixed>  $badges
     * @return list<array{label: string, tone: string}>
     */
    private static function tableBadges(array $badges): array
    {
        $labels = [
            IpDetails::SPAMHAUS => ['Spamhaus', 'danger'],
            IpDetails::TOR => ['Tor', 'danger'],
            'risk' => ['Cloudflare risk', 'danger'],
            IpDetails::FAKE_CRAWLER => ['Fake crawler', 'danger'],
            IpDetails::HOSTING => ['Hosting', 'warning'],
            IpDetails::SCANNER => ['Scanner, low risk', 'neutral'],
        ];
        $out = [];

        foreach ($labels as $key => [$label, $tone]) {
            if (in_array($key, $badges, true)) {
                $out[] = ['label' => __($label), 'tone' => $tone];
            }
        }

        return $out;
    }

    /** The setting that switches each optional protected list off. */
    public const PROTECT_ENV = [
        ProtectedRanges::FORGE => 'PORRO_PROTECT_FORGE',
        ProtectedRanges::FORGE_HEALTH => 'PORRO_PROTECT_FORGE',
        ProtectedRanges::CRAWLERS => 'PORRO_PROTECT_CRAWLERS',
    ];

    /** How each badge reads, and how worrying it is. */
    public const BADGES = [
        IpDetails::TOR => ['label' => 'Tor exit', 'tone' => 'danger'],
        IpDetails::SPAMHAUS => ['label' => 'Spamhaus DROP', 'tone' => 'danger'],
        IpDetails::FAKE_CRAWLER => ['label' => 'Fake crawler', 'tone' => 'danger'],
        IpDetails::HOSTING => ['label' => 'Hosting provider', 'tone' => 'warning'],
        IpDetails::SCANNER => ['label' => 'Research scanner', 'tone' => 'neutral'],
    ];

    /**
     * The IP details drawer: who it is, what it did here in the last 48
     * hours, and what can be done about it.
     *
     * @param  array<string, mixed>|null  $reputation  AbuseIPDB's answer, when someone asked for it
     * @return array<string, mixed>
     */
    public function ip(string $ip, ?array $reputation = null): array
    {
        $ip = (string) Cidr::parse($ip);
        $flagged = null;

        foreach ($this->activity->summary(48)->flagged as $candidate) {
            if ($candidate->ip === $ip) {
                $flagged = $candidate;
            }
        }

        $details = $this->activity->details($ip, $flagged?->userAgent);
        $crawler = $this->crawler($details);
        $badges = array_values(array_map(fn ($b) => (self::BADGES[$b] ?? ['label' => $b, 'tone' => 'neutral']) + ['key' => $b], $details['badges']));

        foreach ($badges as $i => $badge) {
            if ($badge['key'] === IpDetails::FAKE_CRAWLER && $crawler) {
                $badges[$i]['label'] = __('Fake :crawler', ['crawler' => $crawler['name']]);
            }
        }

        foreach ($details['risks'] as $risk) {
            $badges[] = ['key' => 'risk', 'label' => "Cloudflare: {$risk}", 'tone' => 'danger'];
        }

        $range = Cidr::parse($ip)->isV4() ? (string) Cidr::parse($ip.'/24') : null;

        return [
            'ip' => $ip,
            'country' => self::country($details['country'] ?? ($flagged?->country)),
            'badges' => $badges,
            'network' => array_filter([
                'Network' => trim(($details['network'] ?? '').($details['asn'] ? ' · AS'.$details['asn'] : '')) ?: null,
                'Type' => match ($details['network_type'] ?? null) {
                    'hosting_provider' => __('Hosting provider'),
                    'isp' => __('Internet provider'),
                    'organization' => __('Organisation'),
                    default => null,
                },
                'Hostname' => $details['hostname'] ? $details['hostname'].' · '.__('verified') : null,
                'User agent' => $flagged?->userAgent,
            ]),
            'crawler' => $crawler,
            'activity' => $flagged ? $this->did($flagged) : null,
            'blocked' => $this->isBlocked($ip),
            'safelisted' => $this->activity->blocklist()->isSafelisted($ip),
            // On an always-protected list: can't be blocked, so no buttons.
            'protected' => ($source = $this->activity->ranges()->match($ip)) !== null ? __(ProtectedRanges::label($source)) : null,
            // A whole /24 only for hosting networks: on an ISP it would catch neighbours.
            'range' => $range && ($details['network_type'] ?? null) === 'hosting_provider' ? $range : null,
            'reason' => $flagged ? __('Flagged: :signals', ['signals' => implode(', ', array_map(fn ($s, $n) => (self::SIGNALS[$s] ?? $s)." ×{$n}", array_keys($flagged->signals()), $flagged->signals()))]) : __('Blocked from IP details'),
            'reputation' => $reputation,
            'canCheckReputation' => trim((string) config('porro.activity.abuseipdb_key')) !== '',
            'sources' => $details['sources'],
            'fetched' => Carbon::createFromTimestamp((int) $details['fetched_at'])->diffForHumans(),
            'attribution' => in_array('DB-IP', $details['sources'], true) ? ['text' => Countries::ATTRIBUTION, 'url' => Countries::ATTRIBUTION_URL] : null,
        ];
    }

    /** How each crawler is named, and where a real one's addresses resolve. */
    private const CRAWLER_NAMES = ['googlebot' => 'Googlebot', 'bingbot' => 'Bingbot', 'applebot' => 'Applebot', 'yandexbot' => 'YandexBot'];

    /**
     * When the IP says it's a search engine's crawler: whether that's true,
     * in words, with what blocking it would mean.
     *
     * @param  array<string, mixed>  $details
     * @return array{name: string, real: bool, title: string, body: string}|null
     */
    private function crawler(array $details): ?array
    {
        $key = $details['crawler'] ?? null;

        if (! $key) {
            return null;
        }

        $name = self::CRAWLER_NAMES[$key] ?? ucfirst($key);
        $domain = IpDetails::CRAWLERS[$key][0] ?? null;
        $real = ! in_array(IpDetails::FAKE_CRAWLER, $details['badges'], true);

        return $real ? [
            'name' => $name,
            'real' => true,
            'title' => __('Real :crawler', ['crawler' => $name]),
            'body' => __('This address belongs to the search engine: it resolves to :host. Blocking it would stop the site being crawled, so leave it unless you mean to.', ['host' => $details['hostname']]),
        ] : [
            'name' => $name,
            'real' => false,
            'title' => __('Pretending to be :crawler', ['crawler' => $name]),
            'body' => ($details['hostname']
                ? __('Its user agent says :crawler, but this address isn’t the search engine’s: real :crawler addresses resolve to :domain, this one is :host.', ['crawler' => $name, 'domain' => $domain, 'host' => $details['hostname']])
                : __('Its user agent says :crawler, but this address isn’t the search engine’s: real :crawler addresses resolve to :domain, and this one has no matching name.', ['crawler' => $name, 'domain' => $domain]))
                .' '.__('Scanners do this because many sites let crawlers through. Blocking it won’t affect search.'),
        ];
    }

    /**
     * Signals per hour over 48 hours, and the last requests.
     *
     * @return array<string, mixed>
     */
    private function did(FlaggedIp $ip): array
    {
        $hours = [];

        for ($i = 47; $i >= 0; $i--) {
            $hours[] = (int) ($ip->hourly[gmdate('YmdH', time() - $i * 3600)] ?? 0);
        }

        return [
            'hours' => $hours,
            'peak' => max(1, ...$hours),
            'first' => $ip->recent ? Carbon::createFromTimestamp((int) $ip->recent[array_key_last($ip->recent)][3])->diffForHumans() : null,
            'requests' => array_map(fn ($r) => [
                'method' => (string) $r[0],
                'path' => (string) $r[1],
                'status' => (int) $r[2],
                'time' => Carbon::createFromTimestamp((int) $r[3])->format('H:i'),
            ], $ip->recent),
        ];
    }

    /**
     * "🇩🇪 DE", or "Tor" for Cloudflare's T1. Null when unknown.
     *
     * @return array{code: string, label: string}|null
     */
    public static function country(mixed $code): ?array
    {
        $code = Recorder::country(is_string($code) ? $code : null);

        if ($code === null) {
            return null;
        }

        if ($code === 'T1') {
            return ['code' => 'T1', 'label' => 'Tor'];
        }

        $flag = ctype_alpha($code) ? mb_chr(0x1F1E6 + ord($code[0]) - 65).mb_chr(0x1F1E6 + ord($code[1]) - 65) : '';

        return ['code' => $code, 'label' => trim("{$flag} {$code}")];
    }

    /**
     * @return array<string, mixed>
     */
    /** Entries shown on the Blocking page; the full lists have their own pages. */
    public const PREVIEW = 10;

    public const PER_PAGE = 50;

    /** Lists with their own page, and the filters each offers. */
    public const LISTS = [
        'blocklist' => ['all', Entry::MANUAL, Entry::SUGGESTED, Entry::CONFIG, 'expiring'],
        'safelist' => ['all', Entry::MANUAL, Entry::CONFIG, 'expiring'],
    ];

    /**
     * One page of the blocklist or safelist, newest first: matching $search
     * (an IP finds the entries that cover it; anything else matches the
     * address, reason or who added it) and $filter (a source, or entries
     * expiring within 7 days).
     *
     * @return array<string, mixed>
     */
    public function entries(string $list, string $search = '', string $filter = 'all', int $page = 1): array
    {
        $now = time();
        $filters = self::LISTS[$list] ?? self::LISTS['blocklist'];
        $filter = in_array($filter, $filters, true) ? $filter : 'all';
        $search = trim($search);

        try {
            $all = $list === 'safelist' ? $this->activity->blocklist()->safe($now) : $this->activity->blocklist()->blocks($now);
            $failed = false;
        } catch (Throwable $e) {
            report($e);
            [$all, $failed] = [[], true];
        }

        $matching = array_values(array_filter(self::newestFirst($all), fn (Entry $e) => self::matches($e, $search)));
        $counts = [];

        foreach ($filters as $key) {
            $counts[$key] = count(array_filter($matching, fn (Entry $e) => self::inFilter($e, $key, $now)));
        }

        $shown = array_values(array_filter($matching, fn (Entry $e) => self::inFilter($e, $filter, $now)));
        $pages = max(1, (int) ceil(count($shown) / self::PER_PAGE));
        $page = min(max(1, $page), $pages);

        return [
            'list' => $list,
            'failed' => $failed,
            'entries' => array_map(fn (Entry $e) => $this->entry($e), array_slice($shown, ($page - 1) * self::PER_PAGE, self::PER_PAGE)),
            'total' => count($all),
            'shown' => count($shown),
            'search' => $search,
            'filter' => $filter,
            'filters' => array_map(fn (string $key) => ['key' => $key, 'label' => __(self::FILTER_LABELS[$key]), 'count' => $counts[$key]], $filters),
            'page' => $page,
            'pages' => $pages,
        ];
    }

    private const FILTER_LABELS = [
        'all' => 'All',
        Entry::MANUAL => 'Manual',
        Entry::SUGGESTED => 'Suggested',
        Entry::CONFIG => 'From config',
        'expiring' => 'Expiring within 7 days',
    ];

    /**
     * @param  list<Entry>  $entries
     * @return list<Entry>
     */
    private static function newestFirst(array $entries): array
    {
        usort($entries, fn (Entry $a, Entry $b) => ($b->addedAt <=> $a->addedAt) ?: strnatcmp($a->cidr, $b->cidr));

        return $entries;
    }

    private static function matches(Entry $entry, string $search): bool
    {
        if ($search === '') {
            return true;
        }

        if (Cidr::isValid($search) && ! str_contains($search, '/')) {
            return $entry->range()->contains($search);
        }

        $needle = mb_strtolower($search);

        foreach ([$entry->cidr, $entry->note, (string) $entry->addedBy] as $haystack) {
            if (str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function inFilter(Entry $entry, string $filter, int $now): bool
    {
        return match ($filter) {
            'all' => true,
            'expiring' => $entry->expiresAt !== null && $entry->expiresAt <= $now + 7 * 86400,
            default => $entry->source === $filter,
        };
    }

    private function entry(Entry $entry): array
    {
        return [
            'cidr' => $entry->cidr,
            'note' => $entry->note,
            'source' => match ($entry->source) {
                Entry::SUGGESTED => __('Suggested'),
                Entry::CONFIG => __('From config'),
                default => __('Manual'),
            },
            'by' => $entry->addedBy,
            'expires' => $entry->expiresAt ? Carbon::createFromTimestamp($entry->expiresAt)->diffForHumans(['parts' => 1]) : __('never'),
            'fromConfig' => $entry->source === Entry::CONFIG,
        ];
    }

    private function isBlocked(string $ip): bool
    {
        try {
            return $this->activity->blocked($ip) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
