<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

/* ---------------------- HELPER: FETCH ---------------------- */
function fetchData($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Wikimedia Commons Viewer 1.0');
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200) ? $res : false;
}

/* ---------------------- INPUT ---------------------- */
$action = $_GET['action'] ?? '';
$username = $_POST['username'] ?? $_GET['username'] ?? '';
$category = $_POST['category'] ?? $_GET['category'] ?? '';

/* ---------------------- AUTOCOMPLETE USERNAME ---------------------- */
if ($action === 'autocomplete_username') {
    $prefix = $_GET['prefix'] ?? '';
    if (strlen($prefix) < 2) {
        echo json_encode(['success' => true, 'users' => []]);
        exit;
    }

    $url =
        "https://commons.wikimedia.org/w/api.php?action=query&format=json" .
        "&list=allusers&formatversion=2&auprefix=" . urlencode($prefix) .
        "&aulimit=10&origin=*";

    $data = fetchData($url);
    $users = $data ? array_column(json_decode($data, true)['query']['allusers'] ?? [], 'name') : [];

    echo json_encode(['success' => true, 'users' => $users]);
    exit;
}

/* ---------------------- AUTOCOMPLETE CATEGORY ---------------------- */
if ($action === 'autocomplete_category') {
    $prefix = $_GET['prefix'] ?? '';
    if (strlen($prefix) < 2) {
        echo json_encode(['success' => true, 'categories' => []]);
        exit;
    }

    $url =
        "https://commons.wikimedia.org/w/api.php?action=query&format=json" .
        "&list=allcategories&acprefix=" . urlencode($prefix) .
        "&aclimit=10&origin=*";

    $data = fetchData($url);
    $res = json_decode($data, true);
    $cats = array_map(fn($c) => $c['*'], $res['query']['allcategories'] ?? []);

    echo json_encode(['success' => true, 'categories' => $cats]);
    exit;
}

/* ---------------------- VALIDATION ---------------------- */
if (!$username) {
    echo json_encode(["error" => "Username is required"]);
    exit;
}
if (!$category) {
    echo json_encode(["error" => "Category is required"]);
    exit;
}

/* ---------------------- STEP 1: FETCH USER UPLOADS ---------------------- */
/* Using usercontribs (correct modern API) */
/* STEP 1: Fetch ONLY files uploaded by the EXACT username */
$userUploads = [];
$continue = '';

do {
    $url = "https://commons.wikimedia.org/w/api.php?action=query&format=json"
        . "&list=usercontribs"
        . "&ucuser=" . urlencode($username)
        . "&ucnamespace=6"
        . "&uclimit=500"
        . ($continue ? "&uccontinue=" . urlencode($continue) : "")
        . "&origin=*";

    $resp = fetchData($url);
    if (!$resp) break;

    $decoded = json_decode($resp, true);

    foreach ($decoded["query"]["usercontribs"] ?? [] as $file) {

        // IMPORTANT: Match EXACT username (Commons is case-sensitive)
        if ($file["user"] === $username) {
            $userUploads[$file["title"]] = true;
        }
    }

    $continue = $decoded["continue"]["uccontinue"] ?? '';

} while ($continue);


/* ---------------------- STEP 2: RECURSIVE CATEGORY SCAN ---------------------- */
$allCategoryFiles = [];
$visitedCats = [];
$queue = ["Category:" . $category];

while ($queue) {
    $cat = array_shift($queue);
    if (isset($visitedCats[$cat])) continue;
    $visitedCats[$cat] = true;

    $continue = '';

    do {
        $url =
            "https://commons.wikimedia.org/w/api.php?action=query&format=json" .
            "&list=categorymembers&cmnamespace=6|14" .
            "&cmtitle=" . urlencode($cat) .
            "&cmlimit=500&origin=*" .
            ($continue ? "&cmcontinue=" . urlencode($continue) : "");

        $resp = fetchData($url);
        if (!$resp) break;

        $decoded = json_decode($resp, true);
        foreach ($decoded['query']['categorymembers'] ?? [] as $entry) {

            if ($entry['ns'] == 6) {  
                // File
                $allCategoryFiles[$entry['title']] = true;
            } elseif ($entry['ns'] == 14) {
                // Subcategory → add to queue
                $queue[] = $entry['title'];
            }
        }

        $continue = $decoded['continue']['cmcontinue'] ?? '';
    } while ($continue);
}

/* ---------------------- STEP 3: INTERSECTION ---------------------- */
$matched = array_intersect_key($userUploads, $allCategoryFiles);

if (empty($matched)) {
    echo json_encode([
        'success' => true,
        'files' => [],
        'message' => 'No files found for this user in this category (including subcategories).'
    ]);
    exit;
}

/* ---------------------- STEP 4: FETCH IMAGEINFO ---------------------- */
$output = [];
$chunks = array_chunk(array_keys($matched), 50);

foreach ($chunks as $chunk) {
    $titles = implode('|', array_map('urlencode', $chunk));

    $url =
        "https://commons.wikimedia.org/w/api.php?action=query&format=json" .
        "&titles=$titles&prop=imageinfo" .
        "&iiprop=url|size|mime|dimensions" .
        "&iiurlwidth=300&origin=*";

    $resp = fetchData($url);
    if (!$resp) continue;

    $decoded = json_decode($resp, true);
    foreach ($decoded['query']['pages'] ?? [] as $p) {
        $i = $p['imageinfo'][0] ?? [];
        $output[] = [
            "title" => $p['title'],
            "pageid" => $p['pageid'] ?? 0,
            "image" => $i['thumburl'] ?? $i['url'] ?? '',
            "fullImage" => $i['url'] ?? '',
            "width" => $i['thumbwidth'] ?? $i['width'] ?? 0,
            "height" => $i['thumbheight'] ?? $i['height'] ?? 0,
            "mime" => $i['mime'] ?? ""
        ];
    }
}

/* ---------------------- RETURN ---------------------- */
echo json_encode([
    "success" => true,
    "count" => count($output),
    "files" => array_values($output)
]);
?>
