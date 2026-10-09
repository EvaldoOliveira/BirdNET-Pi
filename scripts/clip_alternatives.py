"""Which bird was it? Re-analyses one extracted detection clip with the station's model and prints, as JSON, the
species the model scored highest in it (the review player offers them when a detection is "Not this bird").

Only the detected window is scored: the model's chunk in the middle of the clip, where the extraction puts the
detection (one prediction instead of one per chunk, owner 2026-10-09: the answer must come at once). Each alternative also carries the location model's probability for the clip's week, so an unlikely species is
easy to spot. The detected species itself is left out.
Usage: clip_alternatives.py <clip path> <detected scientific name> [count]
The review player normally asks scripts/review_worker.py, which keeps the model loaded and calls alternatives().
"""
import datetime
import json
import os
import sys

import numpy as np
import soundfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from utils.analysis import readAudioData  # noqa: E402
from utils.classes import week48  # noqa: E402
from utils.helpers import get_settings, get_language  # noqa: E402
from utils.models import get_model  # noqa: E402


def alternatives(clip, detected, count=5, model=None):
    conf = get_settings()
    model = model or get_model()
    chunks = readAudioData(clip, 0.0, model.sample_rate, model.chunk_duration)
    audio = np.concatenate(chunks)[:int(soundfile.info(clip).duration * model.sample_rate)]
    size = int(model.chunk_duration * model.sample_rate)
    start = max(0, (len(audio) - size) // 2)
    window = audio[start:start + size]
    window = np.pad(window, (0, size - len(window)))
    best = {label: float(score) for label, score in model.predict(window)[:20]}
    names = get_language(conf['DATABASE_LANG'])
    # location model probability in the clip's week (rarity.py profile: every species, every week); the date is in
    # the folder name: .../<date>/<species>/<file>
    probs = {}
    try:
        from utils.rarity import get_profile
        profile = get_profile() or {}
        date = datetime.date.fromisoformat(os.path.basename(os.path.dirname(os.path.dirname(clip))))
        week = week48(date)
        probs = {sci: weeks[week - 1] for sci, weeks in profile.get('data', {}).items()}
    except Exception:
        pass
    # species expected here this week first (location probability >= 5 %), then the others, each by score
    def rank(item):
        sci = item[0].split('_')[0]
        return (0 if probs.get(sci, 0.0) >= 0.05 else 1, -item[1])
    out = []
    for label, score in sorted(best.items(), key=rank):
        sci = label.split('_')[0]
        if sci == detected or sci in ('Human', 'Dog', 'Engine', 'Noise'):
            continue
        out.append({'sci': sci, 'com': names.get(sci, label.split('_', 1)[-1]), 'label': f"{sci}_{names.get(sci, label.split('_', 1)[-1])}",
                    'score': round(score, 3), 'prob': round(probs[sci], 3) if sci in probs else None})
        if len(out) >= count:
            break
    for o in out:
        o['expected'] = (o['prob'] or 0) >= 0.05
    return out


def main():
    count = int(sys.argv[3]) if len(sys.argv) > 3 else 5
    print(json.dumps(alternatives(sys.argv[1], sys.argv[2], count), ensure_ascii=False))


if __name__ == '__main__':
    main()
