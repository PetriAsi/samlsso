#!/bin/sh
#
#  ------------------------------------------------------------------------
#  samlSSO
#
#  samlSSO was inspired by the initial work of Derrick Smith's
#  PhpSaml. This project's intend is to address some structural issues
#  caused by the gradual development of GLPI and the broad amount of
#  wishes expressed by the community.
#
#  Copyright (C) 2024 by Chris Gralike
#  ------------------------------------------------------------------------
#  This script is part of the samlSSO package for GLPI.

# This script requires gettext to be installed.
# in debian install it via apt install gettext first.

script_dir=$(cd "$(dirname "$0")" && pwd)
dirplugin=$(cd "${script_dir}/.." && pwd)

# 1. Extract the version dynamically from setup.php
VERSION=$(grep "define('PLUGIN_SAMLSSO_VERSION'" "${dirplugin}/setup.php" | awk -F "'" '{print $4}')

# Download GLPI core en_US.po to a temporary directory to exclude its strings
pathGLPIenUSpo="${dirplugin}/locales/tmp"
mkdir -p "${pathGLPIenUSpo}"

# Download the file, suppressing progress but following redirects
curl -sL https://raw.githubusercontent.com/glpi-project/glpi/refs/heads/main/locales/en_US.po -o "${pathGLPIenUSpo}/en_US.po"

cd "${dirplugin}" || exit 1

find . -type f -name "*.php" | xgettext -f - -o "locales/samlSSO.pot" -L PHP \
    --package-name="samlSSO" \
    --package-version="${VERSION}" \
    --copyright-holder="Chris Gralike" \
    --msgid-bugs-address="https://github.com/DonutsNL/samlSSO/issues" \
    --exclude-file="locales/tmp/en_US.po" \
    --from-code=UTF-8 \
    --force-po \
    --keyword=__

# 2. Extract strings from Twig templates using Python mode and join them to the POT file
find templates -type f -name "*.twig" | xgettext -f - -j -o "locales/samlSSO.pot" -L Python \
    --from-code=UTF-8 \
    --keyword=__

# Clean up temporary files
rm -rf "${pathGLPIenUSpo}"

