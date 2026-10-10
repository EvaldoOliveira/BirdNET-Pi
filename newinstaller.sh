#!/usr/bin/env bash

if [ "$EUID" == 0 ]
  then echo "Please run as a non-root user."
  exit
fi

if [ "$(uname -m)" != "aarch64" ] && [ "$(uname -m)" != "x86_64" ];then
  echo "BirdnetPi++ requires a 64-bit OS.
It looks like your operating system is using $(uname -m),
but would need to be aarch64."
  exit 1
fi

PY_VERSION=$(python3 -c "import sys; print(f'{sys.version_info[0]}{sys.version_info[1]}')")
if [ "${PY_VERSION}" == "39" ] ;then
  echo "### BirdnetPi++ requires a newer OS. Bullseye is deprecated, please use Bookworm. ###"
  [ -z "${FORCE_BULLSEYE}" ] && exit
fi

# we require passwordless sudo: the installer and the running station call sudo without a
# terminal. Recent Raspberry Pi OS images (Trixie) ask the first user's password, so set it up
# once here (US-51a): a sudoers.d rule named zz-* so it is the last one to match, checked by visudo
sudo -K
if ! sudo -n true 2>/dev/null; then
  rule_file=/etc/sudoers.d/zz-birdnet-${USER}-nopasswd
  if (: < /dev/tty) 2>/dev/null; then
    echo "BirdnetPi++ needs passwordless sudo for '${USER}' (the station runs system commands unattended)."
    echo "Enter the password of '${USER}' once to set it up (${rule_file}):"
    if sudo -v < /dev/tty; then
      echo "${USER} ALL=(ALL) NOPASSWD: ALL" | sudo tee "${rule_file}.new" > /dev/null
      if sudo visudo -cf "${rule_file}.new" > /dev/null; then
        sudo chmod u=r,g=r,o= "${rule_file}.new" && sudo mv "${rule_file}.new" "${rule_file}"
      else
        sudo rm -f "${rule_file}.new"
      fi
    fi
    sudo -K
  fi
  if ! sudo -n true 2>/dev/null; then
    echo "Passwordless sudo is not working. Aborting"
    echo "Set it up with: echo \"${USER} ALL=(ALL) NOPASSWD: ALL\" | sudo tee ${rule_file}"
    exit 1
  fi
fi

# Simple new installer
HOME=$HOME
USER=$USER

export HOME=$HOME
export USER=$USER

PACKAGES_MISSING=
for cmd in git jq ; do
  if ! which $cmd &> /dev/null;then
      PACKAGES_MISSING="${PACKAGES_MISSING} $cmd"
  fi
done
if [[ ! -z $PACKAGES_MISSING ]] ; then
  sudo apt update
  sudo apt -y install $PACKAGES_MISSING
fi

# This edition: the fork, release channel 'stable' (the last released version, validated on the
# pilot first). BIRDNET_BRANCH=main installs the development line instead (owner 2026-10-07, US-51e).
branch=${BIRDNET_BRANCH:-stable}
export BIRDNET_BRANCH=$branch  # install_config.sh writes it as UPDATE_BRANCH
git clone -b $branch --depth=1 https://github.com/EvaldoOliveira/BirdnetPiPlusPlus.git ${HOME}/BirdNET-Pi &&

$HOME/BirdNET-Pi/scripts/install_birdnet.sh
if [ ${PIPESTATUS[0]} -eq 0 ];then
  echo "Installation completed successfully"
  sudo reboot
else
  echo "The installation exited unsuccessfully."
  exit 1
fi
