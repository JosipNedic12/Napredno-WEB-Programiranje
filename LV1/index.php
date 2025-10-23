<?php
interface iRadovi {
    public function create(array $items): void;     
    public function save(PDO $pdo): void;           
    public function read(PDO $pdo): array;          
}

final class DiplomskiRadovi implements iRadovi
{
    
    public string $naziv_rada;
    public ?string $tekst_rada;
    public string $link_rada;
    public ?string $oib_tvrtke;

    
    private array $radovi = [];

    public function __construct(
        string $naziv_rada = '',
        ?string $tekst_rada = null,
        string $link_rada = '',
        ?string $oib_tvrtke = null
    ) {
        $this->naziv_rada = $naziv_rada;
        $this->tekst_rada = $tekst_rada;
        $this->link_rada = $link_rada;
        $this->oib_tvrtke = $oib_tvrtke;
    }

    
    public function create(array $items): void
    {
        foreach ($items as $it) {
            $this->radovi[] = new self(
                $it['naziv_rada'] ?? '',
                $it['tekst_rada'] ?? null,
                $it['link_rada'] ?? '',
                $it['oib_tvrtke'] ?? null
            );
        }
    }

    
    public function save(PDO $pdo): void
    {
        $sql = "INSERT INTO diplomski_radovi (naziv_rada, tekst_rada, link_rada, oib_tvrtke)
                VALUES (:naziv_rada, :tekst_rada, :link_rada, :oib_tvrtke)
                ON DUPLICATE KEY UPDATE
                    naziv_rada = VALUES(naziv_rada),
                    tekst_rada = VALUES(tekst_rada),
                    oib_tvrtke = VALUES(oib_tvrtke)";
        $stmt = $pdo->prepare($sql);

        foreach ($this->radovi as $r) {
            $stmt->execute([
                ':naziv_rada' => $r->naziv_rada,
                ':tekst_rada' => $r->tekst_rada,
                ':link_rada'  => $r->link_rada,
                ':oib_tvrtke' => $r->oib_tvrtke
            ]);
        }
    }

    
    public function read(PDO $pdo): array
    {
        $sql = "SELECT id, naziv_rada, tekst_rada, link_rada, oib_tvrtke, created_at
                FROM diplomski_radovi
                ORDER BY created_at DESC, id DESC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}

function envv(string $key, ?string $default = null): ?string {
    // redoslijed: $_ENV → $_SERVER → getenv → default
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? $default : $v;
}

function load_dotenv(string $path): void {
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;

        // podrži export KEY=VAL i komentare na kraju linije
        if (str_starts_with($line, 'export ')) $line = trim(substr($line, 7));
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;

        [$k, $v] = array_map('trim', $parts);

        // ukloni inline komentare koji počinju # izvan navodnika
        if ($v !== '' && $v[0] !== '"' && $v[0] !== "'") {
            $v = preg_split('/\s+#/', $v, 2)[0];
        } else {
            $v = trim($v, "\"'");
        }

        // handle escaped newlines \n -> stvarni novi red
        $v = str_replace(['\\n', '\\r'], ["\n", "\r"], $v);

        // set u env
        $_ENV[$k] = $_SERVER[$k] = $v;
        putenv("$k=$v");
    }
}

load_dotenv(__DIR__ . '/.env');

function make_pdo(): PDO {
    $host = envv('DB_HOST', '127.0.0.1');
    $db   = envv('DB_NAME', 'radovi');
    $user = envv('DB_USER', 'root');
    $pass = envv('DB_PASS', '');
    $charset = envv('DB_CHARSET', 'utf8mb4');
    $port = (int)envv('DB_PORT', '3306');

    // sigurnosna provjera u produkciji
    if (envv('APP_ENV') === 'production' && $pass === '') {
        throw new RuntimeException('DB_PASS is empty in production');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
    $opt = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    return new PDO($dsn, $user, $pass, $opt);
}

function normalize_url(string $href): string {
    if (strpos($href, 'http') === 0) return $href;
    if ($href === '') return '';
    return 'https://stup.ferit.hr' . (str_starts_with($href,'/') ? '' : '/') . $href;
}

function parse_list_items(string $html): array {
    $items = [];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $xp = new DOMXPath($dom);

    
    $articles = $xp->query("//*[starts-with(@id,'blog-1-post-')]");
    foreach ($articles as $art) {
        
        $a = (new DOMXPath($art->ownerDocument))->query(".//div[2]//h2/a", $art)->item(0);
        if (!$a) continue;
        $title = trim(preg_replace('/\s+/', ' ', $a->textContent));
        $href  = normalize_url($a->getAttribute('href'));

        
        $descNodes = (new DOMXPath($art->ownerDocument))->query(".//div[2]//div/p", $art);
        $descParts = [];
        foreach ($descNodes as $p) {
            $descParts[] = trim(preg_replace('/\s+/', ' ', $p->textContent));
        }
        $opis = trim(implode(' ', array_filter($descParts)));

        $img = (new DOMXPath($art->ownerDocument))->query(".//div[1]//ul[1]//img", $art)->item(0);
        $oib = null;
        if ($img) {
            $src = $img->getAttribute('src');
            if (preg_match('/\/(\d{11})\.(?:png|jpe?g|webp)$/i', parse_url($src, PHP_URL_PATH) ?? '', $m)) {
                $oib = $m[1];
            }
        }

        $items[] = [
            'naziv_rada' => $title,
            'tekst_rada' => $opis ?: null,
            'link_rada'  => $href,
            'oib_tvrtke' => $oib
        ];
    }
    return $items;
}


function curl_get(string $url): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/123 Safari/537.36',
        CURLOPT_REFERER => 'https://stup.ferit.hr/',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
    ]);
    $html = curl_exec($ch);
    if ($html === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("cURL error: {$err}");
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) {
        throw new RuntimeException("HTTP {$code} for {$url}");
    }
    return $html;
}


function fetch_text_from_detail(string $detailUrl): ?string {
    try {
        $html = curl_get($detailUrl);
    } catch (Throwable $e) {
        return null;
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $cands = [
        "//div[contains(@class,'entry-content')]",
        "//article//div[contains(@class,'content')]",
        "//div[contains(@class,'post-content')]",
        "//div[contains(@class,'single-content')]",
        "//section[contains(@class,'content')]",
    ];

    foreach ($cands as $q) {
        $nodes = $xpath->query($q);
        if ($nodes && $nodes->length) {
            $text = trim(preg_replace('/\s+/', ' ', $nodes->item(0)->textContent));
            if ($text !== '') return mb_substr($text, 0, 2000); // limit
        }
    }

    
    $fallback = trim(preg_replace('/\s+/', ' ', $dom->textContent));
    return $fallback ? mb_substr($fallback, 0, 2000) : null;
}
function paged_url(int $page): string {
    return $page === 1
        ? 'https://stup.ferit.hr/zavrsni-radovi/'
        : "https://stup.ferit.hr/zavrsni-radovi/page/{$page}/";
}

function scrape_radovi(): array {
    $all = [];
    for ($page = 1; $page <= 6; $page++) {
        $url = paged_url($page);
        $html = curl_get($url);
        $items = parse_list_items($html);
        
        if (count($items) === 0) break;
        $all = array_merge($all, $items);
    }
    
    $seen = [];
    $out = [];
    foreach ($all as $it) {
        $k = $it['link_rada'];
        if (!$k || isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $it;
    }
    return $out;
}




if (php_sapi_name() === 'cli-server' || php_sapi_name() === 'cli' || isset($_GET['run'])) {
    try {
        $pdo = make_pdo();

        $scraped = scrape_radovi();

        $dr = new DiplomskiRadovi();
        $dr->create($scraped);
        $dr->save($pdo);

        $rows = $dr->read($pdo);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'inserted_count' => count($scraped),
            'rows_in_db' => $rows
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
