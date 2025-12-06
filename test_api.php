<?php
header("Content-Type: text/plain");

echo "Testing API...\n\n";

$url = "https://commons.wikimedia.org/w/api.php?action=query&list=categorymembers&cmtitle=Category:Photographs&format=json";

echo "URL: $url\n\n";

echo "---- cURL TEST ----\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

// REQUIRED → Wikimedia API will BLOCK without this
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "User-Agent: WTW25-CatUserinfo-Test/1.0 (https://localhost; user@example.com)"
]);

$out = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($out === false) {
    echo "cURL FAILED: $err\n";
} else {
    echo "SUCCESS! Showing first 500 chars:\n\n";
    echo substr($out, 0, 500);
}
?>