#!/usr/bin/env bash
set -euo pipefail

FROM="spechshop/softphone"
TO="spechshop/softphone"

ROOT="${1:-.}"

find "$ROOT" \
  -type d \( -name .git -o -name vendor -o -name node_modules \) -prune -o \
  -type f -print0 |
while IFS= read -r -d '' file; do
    # ignora binários
    if grep -Iq . "$file"; then
        if grep -q "$FROM" "$file"; then
            echo "Alterando: $file"
            sed -i "s#${FROM}#${TO}#g" "$file"
        fi
    fi
done

echo "Finalizado."