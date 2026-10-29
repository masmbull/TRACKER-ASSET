# Bundled third-party installers

## rustdesk-setup-1.5.0.exe

Upstream: https://github.com/rustdesk/rustdesk/releases/download/1.5.0/rustdesk-1.5.0-x86_64.exe
SHA-256: 8555777215510D83D2D61C9DC984E4FCC838BD7E79F9D18A42585431F5E8BB47

Embedded into TrackerAgent.exe and installed silently during setup, so a managed
laptop has unattended remote access without anyone installing software first.

Committed rather than downloaded at build time on purpose: a build has to be
reproducible, and a fresh upstream release must never appear in a build nobody
reviewed. Updating this file is a deliberate act - replace the file, update the
hash above and the `EmbeddedResource` entry in TrackerAgent.csproj.
