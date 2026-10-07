# Releasing this edition

Branch model (decided 2026-10-07, US-51e):

| Branch | Role | Who follows it |
|---|---|---|
| `main` | development line; every story lands here (from `feat/US-nn`) | the pilot station (`UPDATE_BRANCH=main`) |
| `stable` | the last released version — only moved to a release tag after the pilot validated it | the installer (`newinstaller.sh` clones `stable`) and every installed station (`UPDATE_BRANCH=stable`, the default) |
| `feat/US-nn` | one story; born on `main`, merged back with `--no-ff` | nobody |

Versions are tags `vMAJOR.MINOR.PATCH` on `main` (`v0.x` = test releases, marked *pre-release* on GitHub).

## Cutting a release

1. Everything for the release is merged into `main` and has run on the pilot (services active, web UI 200, new detections with the right names, no tracebacks).
2. Tag `main`: `git tag -a vX.Y.Z -m "vX.Y.Z — <one line>" && git push origin vX.Y.Z`.
3. GitHub release for the tag: notes in English (new since the last release, fixes, notes/caveats); attach the assets the installer downloads (e.g. the cp313 `tflite_runtime` wheel — US-51a).
4. **Acceptance install**: flash a clean Raspberry Pi OS (64-bit), log in as `pi`, run `BIRDNET_BRANCH=main` (or the tag) install, answer the first-run questions; it must record, detect, show the configured language names and serve the web UI. No hand-copied file, no access to the maintainer's network.
5. Move the channel: `git branch -f stable vX.Y.Z && git push --force-with-lease origin stable` (`stable` only ever moves forward to a newer release tag).
6. Installed stations pick it up with the next update (UI *Update*, weekly auto-update, or `update_birdnet.sh`).

Rollback: point `stable` back to the previous tag (stations follow on their next update), or on one station `update_birdnet.sh -b vX.Y.Z`.
