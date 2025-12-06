<?php
header("Content-Type: application/json");

// Read parameters
$user = $_GET["username"] ?? "";
$category = $_GET["category"] ?? "";

if (!$user || !$category) {
    echo json_encode(["files" => []]);
    exit;
}

// Helper function (cURL)
function api($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "WTW25 Tool");
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $out = curl_exec($ch);
    curl_close($ch);
    return json_decode($out, true);
}

/* ------------------------------------------
   1️⃣ GET ALL FILES UPLOADED BY THIS USER
------------------------------------------ */
$uURL = "https://commons.wikimedia.org/w/api.php?action=query&list=allimages&aiuser=" .
    urlencode($user) . "&ailimit=500&format=json";

$userData = api($uURL);

$userUploads = [];
foreach ($userData["query"]["allimages"] ?? [] as $f) {
    $userUploads[$f["title"]] = $f["url"];
}

/* ------------------------------------------
   2️⃣ GET ALL FILES IN CATEGORY
------------------------------------------ */
$cURL = "https://commons.wikimedia.org/w/api.php?action=query&list=categorymembers" .
    "&cmtitle=Category:" . urlencode($category) .
    "&cmtype=file&cmlimit=500&format=json";

$catData = api($cURL);

$result = [];

/* ------------------------------------------
   3️⃣ INTERSECT: (USER UPLOADS) ∩ (CATEGORY FILES)
------------------------------------------ */
foreach ($catData["query"]["categorymembers"] ?? [] as $f) {
    $title = $f["title"];

    if (isset($userUploads[$title])) {
        $url = $userUploads[$title];
        $thumb = $url . "?width=400";

        $result[] = [
            "title" => $title,
            "url" => $url,
            "thumb" => $thumb
        ];
    }
}

echo json_encode(["files" => $result]);
?>