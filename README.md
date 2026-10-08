<h1 align="center"><a href="https://github.com/mcguirepr89/BirdNET-Pi/blob/main/LICENSE">Review the license!!</a></h1>
<h1 align="center">You may not use BirdNET-Pi to develop a commercial product!!!!</h1>
<h1 align="center">
  BirdNET-Pi
</h1>
<p align="center">
A realtime acoustic bird classification system for the Raspberry Pi 5, 4B, 400, 3B+, and 0W2
</p>
<p align="center">
  <img src="homepage/images/BirdNetBr.png" alt="BirdNetBr" width="200" />
</p>

## About this edition (EvaldoOliveira/BirdNET-Pi)

This repository is an independent edition of BirdNET-Pi, maintained as a hard fork of
[Nachtzuster/BirdNET-Pi](https://github.com/Nachtzuster/BirdNET-Pi) — itself the maintained continuation of
[mcguirepr89/BirdNET-Pi](https://github.com/mcguirepr89/BirdNET-Pi) — from upstream commit `88985a3` (v0.11 line,
forked on 2026-08-29).

This edition extends BirdNET-Pi with capabilities intended for long-term acoustic monitoring.

1. **Support for V3 model, and pararel use of shadow models.** Additional classifiers can be selected
   beside the BirdNET models shipped upstream — currently BirdNET+ V3.0, the developer-preview model of the BirdNET
   Live application, running on ONNX Runtime. A second model may analyse every recording in parallel with the official
   one (*shadow mode*), if the Pi Hardware supports, so that two model generations are compared on identical field audio before any change of the
   official model. *(In verification on the pilot station — see below.)*
2. **Support for paralel Continuous raw recording of the dawn chorus.** Long, unprocessed recordings covering the whole dawn period are
   made while the analysis keeps running on the same microphone, so that the complete soundscape is preserved for
   later study and not only the detected segments and also to be processed by Raven.
3. **Sound from detection sent in notification.** Detections are announced by Telegram, e-mail or any other messaging
   channel supported by Apprise, **with the recording attached**, so that a detection can be heard and confirmed on a
   telephone within moments. Species are assigned to notification tiers, each with its own policy.
4. **A configurable live spectrogram.** Colour palettes and colour sensitivity (floor, range, contrast) are adjustable
   while the display runs.
5. **Custom Bird by bird Confidence Override.** Adjust levels for common incorrect detections so that a real bird is detected, instead of being always ignored or blacklisted
6. **Normal and Prio Notification.** Separation of notifications so that if for e.g. in Telegram they can be sent to separate channels, so
   that a Normal is muted and Prio is noisy.
7. **Auto stop/restore services when microphone is removed/inserted.** Recording and analysis stop when the USB
   microphone is unplugged and start again, with the microphone reconfigured, as soon as it is plugged back in.

### How to get this edition

There are two ways:

- **Option 1 — New installation:** you start from a blank microSD card. Recommended.
- **Option 2 — Upgrade:** you already run Nachtzuster's BirdNET-Pi and want to switch it to this edition.

---

#### Option 1 — New installation (blank microSD card)

**You need:** a Raspberry Pi 5 or 4B, a microSD card of 32 GB or more, the official power supply, a USB microphone,
internet access (Ethernet cable or Wi-Fi), a **keyboard, a mouse and a monitor** for the Pi (micro-HDMI cable), and —
only to prepare the card — any computer with a card reader. It takes about 30–45 minutes.

**1. Prepare the card** (on any computer).
Install [Raspberry Pi Imager](https://www.raspberrypi.com/software/), insert the card and choose:
- **Device:** your Raspberry Pi model.
- **Operating system:** *Raspberry Pi OS (64-bit)* — the normal version **with desktop** (it has a web browser,
  so you can do everything on the Pi itself).
- **Storage:** your microSD card.

When the Imager asks about **OS customisation**, click **Edit settings** and set:
- a **hostname**, e.g. `birdnetpi`;
- a **username and password** (remember the password);
- your **Wi-Fi** name, password and country (skip if you use a cable);
- your **time zone** and **keyboard layout**.

Write the card.

**2. Start the Raspberry Pi.**
Connect the keyboard, mouse, monitor and USB microphone, put the card in the Pi and plug in the power.
Wait until the desktop appears (the first start takes a few minutes).

**3. Open this page on the Pi.** Start the web browser (*Chromium*, in the menu at the top left or the globe icon)
and open **github.com/EvaldoOliveira/BirdNET-Pi**, so you can copy the command below instead of typing it.

**4. Install.** Open the **Terminal** (the black window icon at the top, or menu › *Accessories* › *Terminal*),
copy this command from the browser, paste it into the Terminal (right click › *Paste*, or Ctrl+Shift+V) and press
**Enter**:
```
curl -fsSL https://raw.githubusercontent.com/EvaldoOliveira/BirdNET-Pi/stable/newinstaller.sh | bash
```
> Until version 0.5.0 is released, use this command instead:
> `curl -fsSL https://raw.githubusercontent.com/EvaldoOliveira/BirdNET-Pi/main/newinstaller.sh | BIRDNET_BRANCH=main bash`

The installer asks your password once, then a few questions. Press **Enter** to accept each suggested answer:
station name, location (check the latitude/longitude — get yours at [latlong.net](https://www.latlong.net)),
time zone, model (**V3** recommended), language of the bird names, web password, and optionally a BirdWeather ID
and a notification address. The microphone is set up automatically. At the end the Pi restarts by itself.

**5. Open the station.** After the restart, open the web browser on the Pi and go to **`http://localhost`**.
From a phone, tablet or computer on the same network, use **`http://birdnetpi.local`** (or the Pi's IP address).
If the installer did not ask the questions, a setup page asks them now. Detections appear on the *Overview* within
a few minutes.

**Updates:** *Tools › System Controls › Update* installs new released versions.

*Without keyboard and monitor:* choose *Raspberry Pi OS (64-bit) Lite* in step 1, also turn on **Enable SSH** in
the **Services** tab, and do step 4 from another computer on the same network with `ssh <username>@birdnetpi.local`.

*To prepare several stations without typing:* copy
[`docs/birdnet-setup.conf.example`](https://raw.githubusercontent.com/EvaldoOliveira/BirdNET-Pi/main/docs/birdnet-setup.conf.example)
to the card's *bootfs* drive as `birdnet-setup.conf`, fill in the answers, then do steps 2–5.

---

#### Option 2 — Upgrade an existing Nachtzuster installation

1. Make a backup: *Tools › System Controls › Backup*.
2. Connect to the station (`ssh <username>@<station address>`) and run:
   ```
   cd ~/BirdNET-Pi
   git remote set-url origin https://github.com/EvaldoOliveira/BirdNET-Pi.git
   ./scripts/update_birdnet.sh -b stable
   ```
3. Open the station in the browser. Your detections and settings are kept. From now on
   *Tools › System Controls › Update* installs the new versions of this edition.

To go back to Nachtzuster's version:
```
cd ~/BirdNET-Pi
git remote set-url origin https://github.com/Nachtzuster/BirdNET-Pi.git
./scripts/update_birdnet.sh -b main
```

### Reporting issues

- Matters specific to **this edition** (the stories above, the upgrade package): please open an
  [issue in this repository](https://github.com/EvaldoOliveira/BirdNET-Pi/issues/new/choose) — user story, defect or epic.
- Behaviour that also occurs on a standard installation belongs to
  [Nachtzuster's tracker](https://github.com/Nachtzuster/BirdNET-Pi/issues).
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

To install this edition, follow [How to get this edition](#how-to-get-this-edition). The installer takes care of
any and all necessary updates, so you can run it as the very first command upon the first boot.

The installation creates a log in `$HOME/installation-$(date "+%F").txt`.

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

> **Note for this edition:** the web updater follows the release channel set by `UPDATE_BRANCH` in `birdnet.conf`
> (`stable` by default) on the `origin` remote of the installation; an installation switched with
> `update_birdnet.sh -r evaldo -b stable` is updated by running that same command again.

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

To move an existing installation to this edition, see
[Option 2 — Upgrade an existing Nachtzuster installation](#option-2--upgrade-an-existing-nachtzuster-installation).

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
