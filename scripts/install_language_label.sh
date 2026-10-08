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
# not fatal: without internet the model (and so its label file) arrives at the first analysis start,
# which writes model/labels.txt itself when it is missing
[ $ret -ne 0 ] && echo "The label file model/labels.txt could not be written for ${MODEL} / ${DATABASE_LANG} - it will be written when the analysis first starts"

# the species list chosen at install (SPECIES_LIST, e.g. a Brazilian state) is built and activated
python3 ./select_species_list.py || echo "The species list could not be prepared - it is completed when the analysis first starts"

cd - > /dev/null
exit 0
