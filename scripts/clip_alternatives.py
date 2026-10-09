"""Which bird was it? Re-analyses one extracted detection clip with the station's model and prints, as JSON, the
species the model scored highest in it (the review player offers them when a detection is "Not this bird").

The clip is split like the live analysis (model chunk length, no overlap); for every species the best chunk score is
kept. Each alternative also carries the location model's probability for the clip's week, so an unlikely species is
easy to spot. The detected species itself is left out.
Usage: clip_alternatives.py <clip path> <detected scientific name> [count]
"""
import datetime
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from utils.analysis import readAudioData  # noqa: E402
from utils.classes import week48  # noqa: E402
from utils.helpers import get_settings, get_language  # noqa: E402
from utils.models import get_model  # noqa: E402


def main():
    clip, detected = sys.argv[1], sys.argv[2]
    count = int(sys.argv[3]) if len(sys.argv) > 3 else 5
    conf = get_settings()
    model = get_model()
    chunks = readAudioData(clip, 0.0, model.sample_rate, model.chunk_duration)
    best = {}
    for chunk in chunks:
        for label, score in model.predict(chunk)[:20]:
            if score > best.get(label, 0.0):
                best[label] = float(score)
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
    print(json.dumps(out, ensure_ascii=False))


if __name__ == '__main__':
    main()
