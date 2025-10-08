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
        $slugs = fetchOpenAISlugs($prompt, $openaiApiKey);
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
