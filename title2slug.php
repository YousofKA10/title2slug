<?php
/**
 * generate_slugs.php
 * Reads Persian product names from a CSV file,
 * calls OpenAI API in chunks of 10 to generate SEO slugs,
 * retries on invalid JSON, and writes output to a new CSV.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

// ====== CONFIG ======
$inputCsv  = __DIR__ . '/input.csv';     // Input CSV path
$outputCsv = __DIR__ . '/output.csv';    // Output CSV path
$promptFile = __DIR__ . '/PROMPT.txt';   // Main prompt text file
$openaiApi = true;
$openaiApiKey = 'YOUR_OPENAI_API_KEY';   // Replace with your real key
$maxChunkSize = 10;

// ====== FUNCTIONS ======
/**
 * Reads CSV file into associative array.
 */
function readCsv($filePath)
{
    $rows = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        $headers = fgetcsv($handle);
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($headers, $data);
        }
        fclose($handle);
    }
    return $rows;
}

/**
 * Writes associative array to CSV file.
 */
function writeCsv($filePath, $rows)
{
    if (empty($rows)) return;
    $headers = array_keys($rows[0]);
    $fp = fopen($filePath, 'w');
    fputcsv($fp, $headers);
    foreach ($rows as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);
}

/**
 * Calls third-party AI API (talkai.info) and retries recursively if response is invalid JSON or empty.
 * Works exactly like fetchOpenAISlugs(), using $prompt as input.
 */
function fetchThirdPartySlugs(string $prompt, int $retryCount = 0)
{
    $type = "gemini";
    $model = "gemini-2.0-flash-lite";

    if ($retryCount > 3) {
        throw new Exception("Failed after 3 retries: Invalid or empty response");
    }

    $ch = curl_init();
    $base = "https://" . (empty($type) ? "" : $type . ".") . "talkai.info";

    curl_setopt($ch, CURLOPT_URL, $base . "/chat/send/");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        "type" => "chat",
        "messagesHistory" => [
            [
                "id" => uniqid(),
                "from" => "you",
                "content" => $prompt
            ]
        ],
        "settings" => [
            "model" => $model,
            "temperature" => 0.7
        ]
    ]));

    $headers = [];
    $headers[] = 'Accept: application/json, text/event-stream';
    $headers[] = 'Accept-Language: en-US,en;q=0.9,fa;q=0.8,it;q=0.7';
    $headers[] = 'Content-Type: application/json';
    $headers[] = 'Cookie: _ym_uid=1718619806960125609; _ym_d=1737055026; cf_clearance=Z58rmBj93tr1TtzDXqyk1AgKGuZ5_1bFRi4C40pHUiM-1738320457-1.2.1.1-s0FuIRWoeldi0ztWHkwuItnbNxCS1LQ6939p0jEg9gCtl_3YTYrN4GycKjkj49ukuQ60Ha0oZV8rgTyBodApQ1DzFmWt3pfZ644d3Pj6SikZdOed.bC7qQEQJ0Lhp9_oIgF5dwbEzRRD1cNRZpkUjWT1eNJdUqUtUlnIv5QSNueluTpMH9pRAhUTZBHI1sDege9NV9w9TTotEjOvhpeKuQHq0.UBlF6mJiz4ytteKt4b6dj0DhAimuZ9467S1mQ5hrP1Q3atHWrP7YaDY.nDRXIRxhLKx9Vu7dPl2tl5Xjmfj5218S2psfjKK74s.Tche79oZp3CSnvjAf8Iwpn3yA; _ga=GA1.1.421792472.1748545539; _ga_FB7V9WMN30=GS2.1.s1748545539$o1$g0$t1748545539$j60$l0$h0; FCCDCF=%5Bnull%2Cnull%2Cnull%2C%5B%22CQSLGMAQSLGMAEsACBENBsFoAP_gAEPgACY4INJD7C7FbSFCyD5zaLsAMAhHRsAAQoQAAASBAmABQAKQIAQCgkAYFASABAACAAAAICRBIQIECAAAAUAAQAAAAAAEAAAAAAAIIAAAgAEAAAAIAAACAIAAEAAIAAAAEAAAmAgAAIIACAAAgAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAAAAAAQNVSD2F2K2kKFkHCmwXYAYBCujYAAhQgAAAkCBMACgAUgQAgFJIAgCIEAAAAAAAAAQEiCQAAQABAAAIACgAAAAAAIAAAAAAAQQAABAAIAAAAAAAAEAQAAIAAQAAAAIAABEhAAAQQAEAAAAAAAQAAA%22%2C%222~70.89.93.108.122.149.184.196.236.259.311.313.314.323.358.415.442.486.494.495.540.574.609.723.864.981.1029.1048.1051.1095.1097.1126.1205.1276.1301.1365.1415.1449.1514.1570.1577.1598.1651.1716.1735.1753.1765.1870.1878.1889.1958.1960.2072.2253.2299.2373.2415.2506.2526.2531.2568.2571.2575.2624.2677.2778~dv.%22%2C%22D266500F-BCA9-421C-92E5-E64F91169294%22%5D%5D; _csrf-front=0c49b36ce8e66c7bd12737344deb4a557cc5333d345fa04eab76543700a1f59ca%3A2%3A%7Bi%3A0%3Bs%3A11%3A%22_csrf-front%22%3Bi%3A1%3Bs%3A32%3A%22XKcYua1BbAYCnh-mX3kT64sm86BMi_kb%22%3B%7D; fpestid=h1iOEzvAnY3_mu4cvMPPdrwgfgs3bDGq7OpZLNs7NPqqx-a3K3egrpNlvcHp-T_xjyt_cg; _clck=1qg5wx%7C2%7Cfwd%7C0%7C1975; _ym_isad=2; _ga_6G9H0HLVX8=GS2.1.s1748700519$o1$g1$t1748700527$j52$l0$h0; _clsk=uuwkig%7C1748700527975%7C2%7C1%7Ck.clarity.ms%2Fcollect; __gads=ID=6c311506645e2a70:T=1737055021:RT=1748700526:S=ALNI_MaRmFwbtU1BLZmGNskWG3gKQwHbsg; __gpi=UID=00000fbf6e26f6b5:T=1737055021:RT=1748700526:S=ALNI_MYeoLl-seoKcnnrbZcSuHuD7opmkw; __eoi=ID=349d021acebf209c:T=1737055021:RT=1748700526:S=AA-AfjbESdTDA6DJDlcuvdnIhchR; FCNEC=%5B%5B%22AKsRol8hOcczOr6YxZlNf5hcasn3Wah34V4Cro_fUcs_ZUrkxW96dROlSjiXhDdW65zeqncp5fHTf3UXfj6aSGS221xx2pWpzadKaYNU3mA9J_hQA2l_Fm0sYRrlG3bRRAS38EnYywNZrv-jTjwqmO_zUz2UgREwQw%3D%3D%22%5D%5D';
    $headers[] = 'Origin: ' . $base;
    $headers[] = 'Priority: u=1, i';
    $headers[] = 'Referer: '. $base .'/chat/';
    $headers[] = 'Sec-Ch-Ua: \"Google Chrome\";v=\"131\", \"Chromium\";v=\"131\", \"Not_A Brand\";v=\"24\"';
    $headers[] = 'Sec-Ch-Ua-Arch: \"x86\"';
    $headers[] = 'Sec-Ch-Ua-Bitness: \"64\"';
    $headers[] = 'Sec-Ch-Ua-Full-Version: \"131.0.6778.265\"';
    $headers[] = 'Sec-Ch-Ua-Full-Version-List: \"Google Chrome\";v=\"131.0.6778.265\", \"Chromium\";v=\"131.0.6778.265\", \"Not_A Brand\";v=\"24.0.0.0\"';
    $headers[] = 'Sec-Ch-Ua-Mobile: ?0';
    $headers[] = 'Sec-Ch-Ua-Model: \"\"';
    $headers[] = 'Sec-Ch-Ua-Platform: \"Windows\"';
    $headers[] = 'Sec-Ch-Ua-Platform-Version: \"19.0.0\"';
    $headers[] = 'Sec-Fetch-Dest: empty';
    $headers[] = 'Sec-Fetch-Mode: cors';
    $headers[] = 'Sec-Fetch-Site: same-origin';
    $headers[] = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        curl_close($ch);
        throw new Exception("cURL error: " . curl_error($ch));
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body       = substr($response, $headerSize);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo "Warning: HTTP code $httpCode. Retrying...\n";
        sleep(2);
        return fetchThirdPartySlugs($prompt, $type, $model, $retryCount + 1);
    }

    $lines = [];
    foreach (explode("\n", $body) as $line) {
        if (strpos($line, "data: ") === 0) {
            $raw_line = substr($line, 6);
            if (!is_numeric($raw_line)) {
                $lines[] = $raw_line;
            }
        }
    }

    $text = implode("", $lines);
    $text = str_replace(["\\n", "\t"], " ", $text);
    $text = preg_replace("/\s+/", " ", $text);
    $text = preg_replace('/^\s*[\*\-\•]\s*/m', '', $text);
    $text = trim($text);
    $text = preg_replace('/^```/', '', $text);
    $text = preg_replace('/```$/', '', $text);
    $text = trim($text);

    $decoded = json_decode($text, true);

    if ($decoded === null || !is_array($decoded)) {
        var_dump($text);
        var_dump($decoded);
        echo "Warning: Invalid JSON or empty array. Retrying...\n";
        sleep(2);
        return fetchThirdPartySlugs($prompt, $type, $model, $retryCount + 1);
    }

    return $decoded;
}

/**
 * Calls OpenAI API and retries recursively if response is invalid JSON.
 */
function fetchOpenAISlugs($prompt, $openaiApiKey, $retryCount = 0)
{
    if ($retryCount > 3) {
        throw new Exception("Failed after 3 retries: Invalid or empty response");
    }

    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer $openaiApiKey"
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            "model" => "gpt-5",
            "messages" => [
                ["role" => "user", "content" => $prompt]
            ],
            "temperature" => 0.3
        ])
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        throw new Exception("cURL error: " . curl_error($ch));
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $body       = substr($response, $headerSize);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo "Warning: HTTP code $httpCode. Retrying...\n";
        sleep(2);
        return fetchOpenAISlugs($prompt, $openaiApiKey, $retryCount + 1);
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "Warning: Invalid JSON in API response. Retrying...\n";
        sleep(2);
        return fetchOpenAISlugs($prompt, $openaiApiKey, $retryCount + 1);
    }

    $content = $data['choices'][0]['message']['content'] ?? '';
    if (empty($content)) {
        echo "Warning: Empty content in response. Retrying...\n";
        sleep(2);
        return fetchOpenAISlugs($prompt, $openaiApiKey, $retryCount + 1);
    }

    $decoded = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        echo "Warning: Model output not valid JSON. Retrying...\n";
        sleep(2);
        return fetchOpenAISlugs($prompt, $openaiApiKey, $retryCount + 1);
    }

    return $decoded;
}

// ====== MAIN SCRIPT ======
$promptTemplate = file_get_contents($promptFile);
if (!$promptTemplate) {
    die("Error: PROMPT.txt not found or empty.\n");
}

$rows = readCsv($inputCsv);
if (empty($rows)) {
    die("Error: No data found in CSV.\n");
}

$chunks = array_chunk($rows, $maxChunkSize);
$allResults = [];

foreach ($chunks as $chunkIndex => $chunk) {
    $titles = array_column($chunk, 'نام');
    $prompt = str_replace('$INPUTS', json_encode($titles, JSON_UNESCAPED_UNICODE), $promptTemplate);

    echo "Processing chunk #" . ($chunkIndex + 1) . "...\n";

    try {
        if ($openaiApi) $slugs = fetchOpenAISlugs($prompt, $openaiApiKey);
        else $slugs = fetchThirdPartySlugs($prompt);
    } catch (Exception $e) {
        echo "❌ Error in chunk #" . ($chunkIndex + 1) . ": " . $e->getMessage() . "\n";
        $slugs = array_fill(0, count($chunk), "");
    }

    if (!is_array($slugs) || count($slugs) !== count($chunk)) {
        echo "⚠️ Warning: Slug count mismatch in chunk #" . ($chunkIndex + 1) . ". Filling missing items.\n";
        $missingCount = count($chunk) - count($slugs);
        for ($i = 0; $i < $missingCount; $i++) {
            $slugs[] = "";
        }
    }

    foreach ($chunk as $i => $row) {
        $row['نامک'] = $slugs[$i] ?? "";
        $allResults[] = $row;
    }
}

writeCsv($outputCsv, $allResults);

echo "✅ Done! Output written to: $outputCsv\n";

