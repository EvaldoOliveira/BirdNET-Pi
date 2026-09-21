<h1 align="center"><a href="https://github.com/mcguirepr89/BirdNET-Pi/blob/main/LICENSE">Review the license!!</a></h1>
<h1 align="center">You may not use BirdNET-Pi to develop a commercial product!!!!</h1>
<h1 align="center">
  BirdNET-Pi
</h1>
<p align="center">
A realtime acoustic bird classification system for the Raspberry Pi 5, 4B, 400, 3B+, and 0W2
</p>
<p align="center">
  <img src="https://user-images.githubusercontent.com/60325264/140656397-bf76bad4-f110-467c-897d-992ff0f96476.png" />
</p>
<p align="center">
Icon made by <a href="https://www.freepik.com" title="Freepik">Freepik</a> from <a href="https://www.flaticon.com/" title="Flaticon">www.flaticon.com</a>
</p>

## About this edition (EvaldoOliveira/BirdNET-Pi)

This repository is an independent edition of BirdNET-Pi, maintained as a hard fork of
[Nachtzuster/BirdNET-Pi](https://github.com/Nachtzuster/BirdNET-Pi) — itself the maintained continuation of
[mcguirepr89/BirdNET-Pi](https://github.com/mcguirepr89/BirdNET-Pi) — from upstream commit `88985a3` (v0.11 line,
forked on 2026-08-29). It is developed against a permanently operated field station and released as tagged,
documented versions.

### Objectives

This edition extends BirdNET-Pi with capabilities intended for long-term acoustic monitoring and for the people who
review its results:

1. **Support for new model generations, and models operated in parallel.** Additional classifiers can be selected
   beside the BirdNET models shipped upstream — currently BirdNET+ V3.0, the developer-preview model of the BirdNET
   Live application, running on ONNX Runtime. A second model may analyse every recording in parallel with the official
   one (*shadow mode*), so that two model generations are compared on identical field audio before any change of the
   official model. *(In verification on the pilot station — see below.)*
2. **Collection of sounds into an external database.** Each detection can be deposited, at detection time, in an
   external repository as an audio clip with a machine-readable record (station, location, time, species, model and
   analysis parameters, checksum), in a form suitable for validation and for the training of future models.
3. **Continuous raw recording of the dawn chorus.** Long, unprocessed recordings covering the whole dawn period are
   made while the analysis keeps running on the same microphone, so that the complete soundscape is preserved for
   later study and not only the detected segments and also to be processed by Raven.
4. **Identification from a mobile device.** Detections are announced by Telegram, e-mail or any other messaging
   channel supported by Apprise, **with the recording attached**, so that a detection can be heard and confirmed on a
   telephone within moments. Species are assigned to notification tiers, each with its own policy.
5. **A configurable live spectrogram.** Colour palettes and colour sensitivity (floor, range, contrast) are adjustable
   while the display runs.
6. **Regional fine-tuning of the species list.** Station lists and common names can be curated per region. The first
   curated base follows the checklist of the *Comitê Brasileiro de Registros Ornitológicos* (**CBRO**), with Portuguese
   names; the reference list is to become selectable, with the **eBird/Clements Checklist** and **AviList** supported
   as well, followed by IOC and SACC.

Three principles govern the work. The product remains **generic**: no script is tied to a particular station, and
site-specific material (species lists, labels, thresholds, notification channels, credentials) is confined to a
*station layer* (`custom/<station>/`) or to the configuration. Every change is a **user story with acceptance
criteria**, verified on a pilot station under continuous operation before it is merged and tagged. Upstream changes
are adopted individually, and corrections of general interest are **returned upstream** as pull requests (the first:
[Nachtzuster#650](https://github.com/Nachtzuster/BirdNET-Pi/pull/650)).

**Pilot station.** *SaoPaulo*, São Paulo, Brazil: Raspberry Pi 4 Model B, USB microphone, Debian 13, Python 3.13,
recording and analysing continuously.

**Status.** Versions `v0.x` are published as GitHub pre-releases. They operate the pilot station daily but have not yet
been evaluated by third parties; reports are welcome through the issue tracker. Support is provided on a best-effort
basis.

**Project record.** Requirements, defects and their history are kept in the
[issue tracker](https://github.com/EvaldoOliveira/BirdNET-Pi/issues) (issue `#nn` = user story `US-nn`) and on the
[project board](https://github.com/users/EvaldoOliveira/projects/7); each release carries its notes, an upgrade
package and a checksum.

### Delivered user stories

| Story | Title | Outcome, as verified on the pilot station | Release |
|---|---|---|---|
| **Collection of sounds and raw recording** | | | |
| [US-38](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/38) | Sound repository fed at detection time, in a training-ready format | Optional and disabled by default. Each detection is written, beside the BirdWeather upload, as an audio clip with a sidecar JSON record — unique identifier, station and location, timestamp, species, model and analysis parameters, tier, checksum — under contributor terms. A collector validates every deposit (format, duration, record, checksum) before archiving it. | v0.1.0 |
| [US-34](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/34) | Simultaneous capture by BirdNET-Pi and a field recorder | The microphone is shared through an ALSA `dsnoop` device, so that long unattended recordings are made while the analysis continues; verified over a six-hour dawn session with uninterrupted detection. *Station integration.* | — |
| **Notification with audio, for identification on a mobile device** | | | |
| [US-32](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/32) | Notification with the recording attached | A notification may carry the detection clip (`$audio`) and the species image (`$image`); attachments receive a stable, ASCII-safe name. Generic placeholders (`$email`, `$user`, `$hostname`, `$token1..$token20` from a private store) keep personal data out of the configuration shipped with the product. | v0.1.0 |
| [US-37](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/37) | Species tiers, each with its own notification policy | Every species is assigned to a tier — *Muted*, *Normal* or *Rare* — in Species Management. Each tier has its own channels, title and body; *Rare* reports every recording, independently of the filters and of the rate limit. | v0.1.0 |
| [US-17](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/17) | Notifications through Telegram | Telegram delivery through Apprise, with the credentials held outside the repository; first-detection-of-the-day and rare-in-the-week triggers, per-species rate limit, weekly report. | v0.1.0 |
| [US-10](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/10) | Alert for a species new to the station | Satisfied by US-17 and US-37: a first detection of the year is reported, and a species of the *Rare* tier is reported with its recording. | v0.1.0 |
| **Live spectrogram** | | | |
| [US-01](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/1) | Live spectrogram fits the page, with configurable height | The live spectrogram occupies a configurable share of the page and gains a frequency axis; the live species overlay, previously inoperative on stations with a USB microphone (non-RTSP), was corrected and the correction offered upstream (Nachtzuster#650). | v0.1.0 |
| [US-41](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/41) | Spectrogram palette and height on the Spectrogram page | Six named colour palettes for the live display and a height control, available on the page itself and in the settings. | v0.3.0 |
| [US-42](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/42) | Spectrogram colour sensitivity | Floor, range and contrast of the colour scale adjustable while the display runs; a silent mode that renders the display without audio output; species labels legible on every palette. | v0.3.0 |
| **Regional species lists and names** | | | |
| — | Curated regional species base with CBRO names | An optional package for São Paulo state, Brazil: include, exclude and whitelist lists, and Portuguese common names following the CBRO checklist for the BirdNET V2.4 classes. It is the content of the pilot's station layer, published for other stations of the region. | v0.2.0 |
| [#43](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/43) | Defect: an update discarded the station labels | `update_birdnet.sh` restored the upstream label files, after which detections were stored under the upstream (European Portuguese) names. The station layer is now re-applied after every update. | v0.3.0 |
| **Operation and reliability** | | | |
| — | Self-describing, colon-free file names | Recordings `<date>_<HHhMMmSSs>-birdnet.wav` and extractions `<Species>-<date>_<HHhMMmSSs>-birdnet-conf-<NN>.<ext>`: valid on SMB/Windows file systems, with the confidence readable by programs; all earlier naming generations remain readable. | v0.2.0 |
| [US-44](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/44) | Microphone hot-plug | Recording, analysis and live stream stop when the USB microphone is removed and resume, with the audio configuration refreshed, when it returns; the operator is notified. *Station integration.* | — |
| — | Code review of the inherited code base | More than 25 defects corrected, among them: `disk_species_clean.sh` deleting the recordings of the **wrong species** when a name contains more than one hyphen; `update_birdnet.sh -b <tag>` silently failing to roll back; controls on the Spectrogram page resetting four advanced settings; File Manager authentication never matching; `disk_check.sh` failing precisely when the disk is full; species deletion reporting success after a failure. | v0.1.0 |

*Station integration* denotes stories delivered in the deployment scripts of the pilot station rather than in this
repository; a product-native implementation is tracked separately
([US-14](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/14),
[US-35](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/35)).

### In verification

[US-45](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/45) — **BirdNET+ V3.0**, the developer-preview model
distributed with the BirdNET Live application (32 kHz input, approximately 10,000 classes including amphibians,
mammals and insects, with its own location model), as a selectable model running on ONNX Runtime; and a **shadow
mode**, in which a second model analyses every recording beside the official one and writes exclusively to a database
of its own, without extraction or notification. The purpose is a side-by-side evaluation of two model generations on
identical field audio before any change of the official model. Branch `feat/US-45`, on the pilot station since
2026-09-21; scheduled for the release following v0.3.0.

### Planned work

A selectable reference list — CBRO, eBird/Clements, AviList, IOC, SACC
([US-25](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/25)) and the CBRO names as a language of their own, with a `pt_BR` / `pt_PT` distinction
([US-12](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/12),
[US-33](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/33),
[US-39](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/39)); a recorder for long raw sessions built into the product
([US-35](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/35)); transport of external stations' deposits to the
central sound repository ([US-40](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/40)) and its consolidation by
state and biome ([US-27](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/27)); built-in regional species lists
([US-13](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/13)); internationalisation of the whole product
([US-31](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/31)); validation of records and curation of the recordings
before they enter a reference collection ([US-26](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/26),
[US-29](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/29),
[US-36](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/36)); export of checklists to eBird
([US-22](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/22)); installation on Debian 13 / Python 3.13 and USB
microphones without PulseAudio ([US-15](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/15),
[US-14](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/14)); objective post-deployment verification
([US-23](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/23)). The complete backlog is the
[list of open issues](https://github.com/EvaldoOliveira/BirdNET-Pi/issues).

### How to get this edition

**1. Upgrade package over a standard installation (the verified procedure).** Each
[release](https://github.com/EvaldoOliveira/BirdNET-Pi/releases) ships a zip of full-replacement files for a
Nachtzuster installation of the v0.11 line (base `88985a3`), its `sha256` and a README with the steps: back up,
`unzip -o` over `~/BirdNET-Pi`, run `update_birdnet_snippets.sh` (append-only backfill of the new configuration
keys), `restart_services.sh`. No service units, no database changes, no model changes. A later update against
upstream overwrites these files — re-apply the pack, or use option 2.

**2. Run this repository directly.** On an installed BirdNET-Pi:
```
cd ~/BirdNET-Pi
git remote add evaldo https://github.com/EvaldoOliveira/BirdNET-Pi.git
./scripts/update_birdnet.sh -r evaldo -b main        # or -b v0.3.0 for a fixed release
```
Rollback is the same command with the previous tag (or with your original remote).

**3. New installation.** The installer described below clones Nachtzuster's repository; complete that installation,
then follow option 1 or 2.

Back up first (`Tools` > `System Controls` > `Backup`). The optional **Brazilian species base (BR-SP)** published
with [v0.2.0](https://github.com/EvaldoOliveira/BirdNET-Pi/releases/tag/v0.2.0) — regional include/exclude/whitelist
lists and CBRO Portuguese labels for the V2.4 model — overwrites your lists and labels: read its README before
applying it.

### Reporting issues

- Matters specific to **this edition** (the stories above, the upgrade package): please open an
  [issue in this repository](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/new/choose) — user story, defect or epic.
- Behaviour that also occurs on a standard installation belongs to
  [Nachtzuster's tracker](https://github.com/Nachtzuster/BirdNET-Pi/issues); corrections of general interest made
  here are offered upstream as pull requests.
- Neither project adjudicates detections: questions about the classification performance of the BirdNET models
  should be addressed to the [BirdNET team](https://github.com/birdnet-team).

### Licence and credits

[CC BY-NC-SA 4.0](LICENSE), inherited from BirdNET-Pi and from the BirdNET models — **non-commercial use only**,
share alike, with attribution. This edition stands on the work of
[@mcguirepr89](https://github.com/mcguirepr89) (BirdNET-Pi), [@Nachtzuster](https://github.com/Nachtzuster) (the
maintained fork this edition is based on), [@kahst](https://github.com/kahst) and the
[BirdNET team](https://github.com/birdnet-team) at the K. Lisa Yang Center for Conservation Bioacoustics (Cornell Lab
of Ornithology) and Chemnitz University of Technology (the BirdNET models), and every contributor credited in the
sections below.

---

# BirdNET-Pi — standard information (from the upstream README)

The sections below come from Nachtzuster's README and apply to this edition unchanged, unless a note says otherwise.

## About Nachtzuster's fork
Nachtzuster built on [mcguirepr89's](https://github.com/mcguirepr89/BirdNET-Pi) work to update and improve
BirdNET-Pi. His changes, all included here:

 - Backup & Restore
 - Web ui is much more responsive
 - Daily charts now include all species, not just top/bottom 10
 - Bump apprise version, so more notification type are possible
 - Swipe events on Daily Charts (by @croisez)
 - Support for 'Species range model V2.4 - V2'
 - Bookworm and Trixie support
 - Experimental support for writing transient files to tmpfs
 - Rework analysis to consolidate analysis/server/extraction. Should make analysis more robust and slightly more efficient, especially on installations with a large number of recordings
 - Bump tflite_runtime to 2.17.1, it is faster
 - Rework daily_plot.py (chart_viewer) to run as a daemon to avoid the very expensive startup
 - Lots of fixes & cleanups

## Introduction
BirdNET-Pi is built on the [BirdNET framework](https://github.com/kahst/BirdNET-Analyzer) by [**@kahst**](https://github.com/kahst) <a href="https://creativecommons.org/licenses/by-nc-sa/4.0/"><img src="https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-lightgrey.svg"></a> using [pre-built TFLite binaries](https://github.com/PINTO0309/TensorflowLite-bin) by [**@PINTO0309**](https://github.com/PINTO0309) . It is able to recognize bird sounds from a USB microphone or sound card in realtime and share its data with the rest of the world.

Check out birds from around the world
- [BirdWeather](https://app.birdweather.com)<br>

## Features
* **24/7 recording and automatic identification** of bird songs, chirps, and peeps using BirdNET machine learning
* **Automatic extraction and cataloguing** of bird clips from full-length recordings
* **Tools to visualize your recorded bird data** and analyze trends
* **Live audio stream and spectrogram**
* **Automatic disk space management** that periodically purges old audio files
* [BirdWeather](https://app.birdweather.com) integration -- you can request a BirdWeather ID from BirdNET-Pi's "Tools" > "Settings" page
* Web interface access to all data and logs provided by [Caddy](https://caddyserver.com)
* [GoTTY](https://github.com/yudai/gotty) and [GoTTY x86](https://github.com/sorenisanerd/gotty) Web Terminal
* [Tiny File Manager](https://tinyfilemanager.github.io/)
* FTP server included
* SQLite3 Database
* [Adminer](https://www.adminer.org/) database maintenance
* [phpSysInfo](https://github.com/phpsysinfo/phpsysinfo)
* [Apprise Notifications](https://github.com/caronc/apprise) supporting 90+ notification platforms
* Localization supported

## Requirements
* A Raspberry Pi 5, Raspberry 4B, Raspberry Pi 400, Raspberry Pi 3B+, or Raspberry Pi 0W2 (The 3B+ and 0W2 must run on RaspiOS-ARM64-**Lite**)
* An SD Card with the **_64-bit version of RaspiOS_** installed (please use Trixie) -- Lite is recommended, but the installation works on RaspiOS-ARM64-Full as well. Downloads available within the [Raspberry Pi Imager](https://www.raspberrypi.com/software/).
* A USB Microphone or Sound Card

## Installation
[A comprehensive installation guide is available here](https://github.com/mcguirepr89/BirdNET-Pi/wiki/Installation-Guide). This guide is slightly out-dated: make sure to pick Bookworm, also the curl command is still pointing to mcguirepr89's repo.

Please note that installing BirdNET-Pi on top of other servers is not supported. If this is something that you require, please open a discussion for your idea and inquire about how to contribute to development.

[Raspberry Pi 3B[+] and 0W2 installation guide available here](https://github.com/mcguirepr89/BirdNET-Pi/wiki/RPi0W2-Installation-Guide)

The system can be installed with:
```
curl -s https://raw.githubusercontent.com/Nachtzuster/BirdNET-Pi/main/newinstaller.sh | bash
```
The installer takes care of any and all necessary updates, so you can run that as the very first command upon the first boot, if you'd like.

The installation creates a log in `$HOME/installation-$(date "+%F").txt`.

> **Note for this edition:** the command above installs Nachtzuster's BirdNET-Pi. To move the installation to this
> edition afterwards, see [How to get this edition](#how-to-get-this-edition).

## Access
The BirdNET-Pi can be accessed from any web browser on the same network:
- http://birdnetpi.local OR your Pi's IP address
- Default Basic Authentication Username: birdnet
- Password is empty by default. Set this in "Tools" > "Settings" > "Advanced Settings"

Please take a look at the [wiki](https://github.com/mcguirepr89/BirdNET-Pi/wiki) and [discussions](https://github.com/mcguirepr89/BirdNET-Pi/discussions) for information on
- [BirdNET-Pi's Deep Convolutional Neural Network(s)](https://github.com/mcguirepr89/BirdNET-Pi/wiki/BirdNET-Pi:-some-theory-on-classification-&-some-practical-hints)
- [making your installation public](https://github.com/mcguirepr89/BirdNET-Pi/wiki/Sharing-Your-BirdNET-Pi)
- [backing up and restoring your database](https://github.com/mcguirepr89/BirdNET-Pi/wiki/Backup-and-Restore-the-Database)
- [adjusting your sound card settings](https://github.com/mcguirepr89/BirdNET-Pi/wiki/Adjusting-your-sound-card)
- [suggested USB microphones](https://github.com/mcguirepr89/BirdNET-Pi/discussions/39)
- [building your own microphone](https://github.com/DD4WH/SASS/wiki/Stereo--(Mono)-recording-low-noise-low-cost-system)
- [privacy concerns and options](https://github.com/mcguirepr89/BirdNET-Pi/discussions/166)
- [beta testing](https://github.com/mcguirepr89/BirdNET-Pi/discussions/11)
- [and more!](https://github.com/mcguirepr89/BirdNET-Pi/discussions)

## Updating 

Use the web interface and go to "Tools" > "System Controls" > "Update". If you encounter any issues with that, or suspect that the update did not work for some reason, please save its output and post it in an issue where we can help.

> **Note for this edition:** the web updater follows the `origin` remote of the installation. An installation that
> uses the upgrade pack still updates from Nachtzuster (and loses the pack's files on update); one switched with
> `update_birdnet.sh -r evaldo -b main` is updated by running that same command again.

## Backup and Restore
Use the web interface and go to "Tools" > "System Controls" > "Backup" or "Restore". Backup/Restore is primary meant for migrating your data for one system to another. Since the time required to create or restore a backup depends on the size of the data set and the speed of the storage, this could take quite a while.

Alternatively, the backup script can be used directly. These examples assume the backup medium is mounted on `/mnt`

To backup:
```commandline
./scripts/backup_data.sh -a backup -f /mnt/birds/backup-2024-07-09.tar
```
To restore:
```commandline
./scripts/backup_data.sh -a restore -f /mnt/birds/backup-2024-07-09.tar
```

## x86_64 support
x86_64 support is mainly there for developers or otherwise more Linux savvy people.
That being said, some pointers:
- Use Debian 12 or 13
- The user needs passwordless sudo

For Proxmox, a user has reported adding this in their `cpu-models.conf`, in order for the custom TFLite build to work.
```
cpu-model: BirdNet
    flags +sse4.1
    reported-model host
```

## Uninstallation
```
/usr/local/bin/uninstall.sh && cd ~ && rm -drf BirdNET-Pi
```

## Migrating
Before switching, make sure your installation is fully up-to-date. Also make sure to have a backup, that is also the only way to get back to the original BirdNET-Pi.
Please note that upgrading your underlying OS to Bookworm is not going to work. Please stick to Bullseye. If you do want Bookworm, you need to start from a fresh install and copy back your data. (remember the backup!)

Run these commands to migrate to this repo:
```
git remote remove origin
git remote add origin https://github.com/Nachtzuster/BirdNET-Pi.git
./scripts/update_birdnet.sh
```

## Troubleshooting and Ideas
*Hint: A lot of weird problems can be solved by simply restarting the core services. Do this from the web interface "Tools" > "Services" > "Restart Core Services"*

For this edition see [Reporting issues](#reporting-issues). For a standard installation, Nachtzuster asks: submit an *issue for trouble* and a *discussion for ideas*, search the repository before creating a new one, and do not open issues about "false positives" — the repository has nothing to do with the validity of the detection results.

## Sharing
Please join a Discussion!! and please join [BirdWeather!!](https://app.birdweather.com)
I hope that if you find BirdNET-Pi has been worth your time, you will share your setup, results, customizations, etc. [HERE](https://github.com/mcguirepr89/BirdNET-Pi/discussions/69) and will consider [making your installation public](https://github.com/mcguirepr89/BirdNET-Pi/wiki/Sharing-Your-BirdNET-Pi).

## Homeassistant addon

BirdNET-Pi can also be run as a [Homeassistant](https://www.home-assistant.io/) addon through docker.
For more information : https://github.com/alexbelgium/hassio-addons/blob/master/birdnet-pi/README.md

## Docker

BirdNET-Pi can also be run as as a docker container.
For more information : https://github.com/alexbelgium/hassio-addons/blob/master/birdnet-pi/README_standalone.md

## Cool Links

- [Marie Lelouche's <i>Out of Spaces</i>](https://www.lestanneries.fr/exposition/marie-lelouche-out-of-spaces/) using BirdNET-Pi in post-sculpture VR! [Press Kit](https://github.com/mcguirepr89/BirdNET-Pi-assets/blob/main/dp_out_of_spaces_marie_lelouche_digital_05_01_22.pdf)
- [Research on noded BirdNET-Pi networks for farming](https://github.com/mcguirepr89/BirdNET-Pi-assets/blob/main/G23_Report_ModelBasedSysEngineering_FarmMarkBirdDetector_V1__Copy_.pdf)
- [PixCams Build Guide](https://pixcams.com/building-a-birdnet-pi-real-time-acoustic-bird-id-station/)
- [Core-Electronics](https://core-electronics.com.au/projects/bird-calls-raspberry-pi) Build Article
- [RaspberryPi.com Blog Post](https://www.raspberrypi.com/news/classify-birds-acoustically-with-birdnet-pi/)
- [MagPi Issue 119 Showcase Article](https://magpi.raspberrypi.com/issues/119/pdf)


### Internationalization:
The bird names are in English by default, but other localized versions are available thanks to the wonderful efforts of [@patlevin](https://github.com/patlevin) and Wikipedia. Use the web interface's "Tools" > "Settings" and select your "Database Language" to have the detections in your language.

[Internationalization](docs/translations.md)


## Screenshots
![Overview](docs/overview.png)
![Spectrogram](docs/spectrogram.png)


## :thinking:
Are you a lucky ducky with a spare Raspberry Pi? [Try Folding@home!](https://foldingathome.org/)
