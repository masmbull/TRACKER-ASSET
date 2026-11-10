#!/usr/bin/env bash
set -euo pipefail

blocked=()

while IFS= read -r -d '' path; do
    case "$path" in
        .env.example)
            ;;
        .env|.env.*)
            blocked+=("$path")
            ;;
        agent/TrackerAgent/InstallerWindow.xaml.cs.bak)
            ;;
        storage/app/private/.gitignore)
            ;;
        storage/app/private/*|storage/app/imports/*|storage/app/documents/*|storage/app/attachments/*)
            blocked+=("$path")
            ;;
        "IT Assets.xlsx"|*.sqlite|*.sqlite3|*.db|*.dump|*.sql|*.sql.gz|*.bak|*.pem|*.key|*.p12|*.pfx|*.kdbx|*.log)
            blocked+=("$path")
            ;;
        # The RustDesk setup script carried the self-hosted server key and the
        # default password. It is served from public/downloads/, so the key was
        # downloadable by anyone who could reach the app - it must never be
        # tracked again, by any name.
        public/downloads/rustdesk-setup.bat|public/downloads/*rustdesk*)
            blocked+=("$path")
            ;;
    esac
done < <(git ls-files --cached -z)

if ((${#blocked[@]} > 0)); then
    printf 'Sensitive paths must not be tracked:\n' >&2
    printf '  %s\n' "${blocked[@]}" >&2
    exit 1
fi

printf 'Sensitive path check passed.\n'
