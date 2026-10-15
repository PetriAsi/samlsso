# ADR 0015: Automated Translation Tooling

## Status
Accepted

## Context
As the plugin grows and supports more international users, maintaining translations across multiple locales becomes tedious and error-prone. The translation workflow requires:
1. Scanning all PHP and Twig files to extract translatable strings into a template (`samlSSO.pot`).
2. Creating and updating language-specific catalog files (`.po` files) for configured locales.
3. Automatically translating missing translation strings (specifically keeping system icons like `⚠️`, `🔒`, `⭕` intact and separate from translation logic).
4. Ensuring placeholders (e.g., `%s`, `{var}`, HTML tags) are not broken or translated by translation engines.
5. Compiling human-readable `.po` files to binary `.mo` files, which GLPI core loads.
6. Ensuring compatibility with gettext validation constraints (e.g., matching leading/trailing newlines between `msgid` and `msgstr`).

## Decision
We decided to:
1. Create a configurations file at [languages.json](file:///var/www/glpi-dev_quinquies_nl/plugins/samlsso/tools/translation/languages.json) containing the target locales.
2. Implement a standalone, dependency-free Python automation script at [generate_translations.py](file:///var/www/glpi-dev_quinquies_nl/plugins/samlsso/tools/translation/generate_translations.py) to manage the end-to-end translation lifecycle:
   - Run `xgettext` to extract translatable strings.
   - Run `msgmerge` to merge new strings into target `.po` files.
   - Parse `.po` files, identify missing translations, and translate them using the Google Translate API.
   - Safely extract and restore visual icons (`⚠️`, `🔒`, `⭕`, `🆗`, `🤔`) before/after API calls.
   - Protect placeholders (`%s`, `{var}`, tags) via tokenization during API calls.
   - Copy English strings directly to `en_GB` instead of translating.
   - Validate newline structure and compile output using `msgfmt`.
3. Keep the script completely free of external Python packages (using only the standard library).

## Environment & OS Requirements
To execute the translation tool, the host environment must meet the following requirements:
* **Operating System**: Linux/Unix-based OS (recommended) or macOS/Windows (with `gettext` CLI tools installed on the system PATH).
* **System Utilities**: The GNU `gettext` package must be installed and available in the shell path. Specifically, the script executes:
  - `xgettext`
  - `msgmerge`
  - `msginit`
  - `msgfmt`
* **Python Runtime**: Python 3.x (specifically tested with Python 3.12+).
* **Python Dependencies**: None. The script relies entirely on standard built-in modules (`os`, `sys`, `re`, `subprocess`, `urllib.request`, `urllib.parse`, `json`, `time`).

### Installation of Prerequisites
* **Debian/Ubuntu**:
  ```bash
  apt-get install gettext python3
  ```
* **RedHat/CentOS/Fedora**:
  ```bash
  dnf install gettext python3
  ```
* **macOS (via Homebrew)**:
  ```bash
  brew install gettext
  ```

## Consequences
* **Positive**:
  - Translating new strings and compiling locales is now a single-command process (`python3 tools/translation/generate_translations.py`).
  - Safe extraction rules protect placeholders and visual aids from being mangled by translation APIs.
  - Zero Python dependencies (`pip install`) makes it highly portable across developer machines.
* **Negative**:
  - Requires the `gettext` suite to be installed locally, which is not always present by default on developer machines.
