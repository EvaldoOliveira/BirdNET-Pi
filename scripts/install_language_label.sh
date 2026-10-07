#!/usr/bin/env bash

source /etc/birdnet/birdnet.conf
cd /home/$BIRDNET_USER/BirdNET-Pi/scripts

# The label file is built from the model's own class list. Models fetched on first use (BirdNET+ V3)
# only have it after their download, which used to happen at the first analysis start — after the
# installer had already tried (and silently failed) to write model/labels.txt (US-51e acceptance
# install, 2026-10-07). Fetch the model first, with the station's Python environment.
venv_python=/home/$BIRDNET_USER/BirdNET-Pi/birdnet/bin/python3
if [ -x "$venv_python" ]; then
  "$venv_python" -c 'from utils.models import get_model; get_model()' > /dev/null 2>&1 \
    || echo "Could not prepare the model ${MODEL} (no internet?) - the label file may be incomplete"
fi

python3 -c 'from utils.helpers import set_label_file; set_label_file()'
ret=$?
[ $ret -ne 0 ] && echo "The label file model/labels.txt could not be written for ${MODEL} / ${DATABASE_LANG}"

cd - > /dev/null
exit $ret
