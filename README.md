# Title2Slug

**Title2Slug** is a PHP tool to generate SEO-friendly slugs for Persian product names. It reads names from a CSV file, calls an AI API (OpenAI or a third-party AI) in chunks, retries on invalid responses, and writes the results to a new CSV file.

---

## Features

- Reads Persian product names from a CSV (`input.csv`).
- Generates SEO-friendly slugs using AI in **chunks of 10**.
- Supports **OpenAI API** or a **third-party AI API** (`talkai.info`).
- Automatic retry on invalid JSON or empty responses.
- Writes results to `output.csv` with an added `نامک` column.
- Fully configurable via `PROMPT.txt` and script variables.
- Easy to extend and integrate into other workflows.

---

## Installation

1. Clone the repository:

```bash
git clone https://github.com/BaseMax/title2slug.git
cd title2slug
````

2. Place your input CSV file as `input.csv`. The CSV must contain a column `نام` with product names.

3. Edit `PROMPT.txt` to customize the AI prompt. Use `$INPUTS` as a placeholder for the product names array.

4. (Optional) Configure your OpenAI API key in `title2slug.php`:

```php
$openaiApiKey = 'YOUR_OPENAI_API_KEY';
$openaiApi = true; // set to true to use OpenAI
```

---

## Usage

Run the script from the command line:

```bash
php title2slug.php
```

The script will:

1. Read `input.csv`.
2. Generate slugs in chunks.
3. Retry if any chunk returns invalid JSON.
4. Write the output to `output.csv` with an additional `نامک` column.

Example output CSV:

| نام            | نامک          |
| -------------- | ------------- |
| لپ‌تاپ ایسوس   | asus-laptop   |
| گوشی سامسونگ   | samsung-phone |
| میز تحریر ساده | simple-desk   |

---

## Configuration

* **$inputCsv**: Path to input CSV.
* **$outputCsv**: Path to output CSV.
* **$promptFile**: Path to AI prompt text file.
* **$maxChunkSize**: Number of items processed per AI request (default: 10).
* **$openaiApi**: Boolean to switch between OpenAI API and third-party AI.
* **$openaiApiKey**: Your OpenAI API key.

---

## Notes

* The script handles JSON wrapped in triple backticks (```) from the third-party API.
* Slug count mismatches are automatically padded with empty strings.
* Ensure your CSV uses UTF-8 encoding to prevent Persian character issues.

---

## License

This project is licensed under the [MIT License](LICENSE).

---

## Copyright

© 2025 Max Base
