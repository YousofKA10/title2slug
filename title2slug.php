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
$openaiApi = false;
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

    $headers = [
        'Accept: application/json, text/event-stream',
        'Content-Type: application/json'
    ];
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

    $decoded = json_decode($text, true);

    if ($decoded === null || !is_array($decoded)) {
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
