<?php
interface iRadovi {
    public function create(array $items): void;     // napuni kolekciju radova iz dobivenih podataka
    public function save(PDO $pdo): void;           // spremi sve u bazu
    public function read(PDO $pdo): array;          // vrati sve iz baze
}

final class DiplomskiRadovi implements iRadovi
{
    // Jedan rad
    public string $naziv_rada;
    public ?string $tekst_rada;
    public string $link_rada;
    public ?string $oib_tvrtke;

    // Kolekcija radova
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

    // Napuni kolekciju radova
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

    // Spremi kolekciju u bazu
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

    // Dohvati sve iz baze
    public function read(PDO $pdo): array
    {
        $sql = "SELECT id, naziv_rada, tekst_rada, link_rada, oib_tvrtke, created_at
                FROM diplomski_radovi
                ORDER BY created_at DESC, id DESC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}

// -------------------- Helperi za dohvat i parsiranje --------------------

function make_pdo(): PDO {
    $host = '127.0.0.1';
    $db   = 'radovi';
    $user = 'root';
    $pass = 'Lionel#123';
    $charset = 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
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
// Iz liste rada izdvoji: naziv, link, OIB (ako postoji)
function parse_list_items(string $html): array {
    $items = [];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    $xp = new DOMXPath($dom);

    // svi članci s id="blog-1-post-XXXXX"
    $articles = $xp->query("//*[starts-with(@id,'blog-1-post-')]");
    foreach ($articles as $art) {
        // naslov i link
        $a = (new DOMXPath($art->ownerDocument))->query(".//div[2]//h2/a", $art)->item(0);
        if (!$a) continue;
        $title = trim(preg_replace('/\s+/', ' ', $a->textContent));
        $href  = normalize_url($a->getAttribute('href'));

        // opis (spoji sve p tekstove)
        $descNodes = (new DOMXPath($art->ownerDocument))->query(".//div[2]//div/p", $art);
        $descParts = [];
        foreach ($descNodes as $p) {
            $descParts[] = trim(preg_replace('/\s+/', ' ', $p->textContent));
        }
        $opis = trim(implode(' ', array_filter($descParts)));

        // slika -> src -> OIB (11 znamenki u nazivu datoteke)
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


// Sa stranice detalja rada dohvatiti tekst rada (excerpt/sadržaj)
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

    // Pokušaj dohvatiti glavni sadržaj članka
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

    // Fallback: cijeli vidljivi tekst stranice
    $fallback = trim(preg_replace('/\s+/', ' ', $dom->textContent));
    return $fallback ? mb_substr($fallback, 0, 2000) : null;
}
function paged_url(int $page): string {
    return $page === 1
        ? 'https://stup.ferit.hr/zavrsni-radovi/'
        : "https://stup.ferit.hr/zavrsni-radovi/page/{$page}/";
}

// Orkestracija: scrapa stranice 2..6, parsira listu, obogati tekstom detalja
function scrape_radovi(): array {
    $all = [];
    for ($page = 1; $page <= 6; $page++) {
        $url = paged_url($page);
        $html = curl_get($url);
        $items = parse_list_items($html);
        // stani kad više nema rezultata
        if (count($items) === 0) break;
        $all = array_merge($all, $items);
    }
    // deduplikacija po linku
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


// -------------------- CLI/Web izvršavanje --------------------

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
