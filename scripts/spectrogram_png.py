"""Spectrogram image of a recording drawn with the colours of the live Spectrogram page.

The Overview's "Currently Analyzing" picture used sox, whose colour maps only approximate the
live canvas (and know no contrast). This draws the same palettes (SPECTROGRAM_PALETTE), the same
floor/range (SPECTROGRAM_FLOOR_DB / SPECTROGRAM_RANGE_DB) and the same contrast gamma
(SPECTROGRAM_CONTRAST) as scripts/spectrogram.php, 0-12 kHz, with the sox-like frame: title,
kHz and seconds axes and a dBFS colour bar.

Usage: spectrogram_png.py <recording> <output.png> [title] [--raw]
"""
import colorsys
import os
import sys

import numpy as np
import soundfile
from PIL import Image, ImageDraw, ImageFont

sys.path.insert(0, os.path.dirname(os.path.realpath(__file__)))
from utils.helpers import get_settings  # noqa: E402

# same stops as PALETTE_STOPS in scripts/spectrogram.php; 'birdnet' is the HSL ramp
PALETTE_STOPS = {
    'viridis': [[68, 1, 84], [59, 82, 139], [33, 145, 140], [94, 201, 98], [253, 231, 37]],
    'inferno': [[0, 0, 4], [87, 16, 110], [188, 55, 84], [249, 142, 9], [252, 255, 164]],
    'ocean': [[0, 0, 0], [0, 30, 90], [0, 110, 180], [0, 200, 230], [220, 255, 255]],
    'grayscale': [[0, 0, 0], [255, 255, 255]],
    'soxheat': [[0, 0, 0], [30, 0, 90], [120, 0, 140], [200, 40, 60], [240, 140, 0], [255, 240, 120], [255, 255, 255]],
}
MAX_HZ = 12000
WIDTH, HEIGHT = 800, 513          # spectrogram area, as sox draws it
LEFT, RIGHT, TOP, BOTTOM = 58, 110, 34, 56


def _float(conf, key, default, lo, hi):
    try:
        return max(lo, min(hi, float(conf.get(key) or default)))
    except ValueError:
        return default


def palette_lut(name, gamma):
    """256 RGB colours for magnitude 0..1, bent by the contrast gamma like paletteColor()."""
    rat = np.linspace(0, 1, 256) ** gamma
    if name not in PALETTE_STOPS:
        out = []
        for r in rat:
            hue = ((r * 120 + 280) % 360) / 360
            rgb = colorsys.hls_to_rgb(hue, (10 + 70 * r) / 100, 1.0)
            out.append([int(round(c * 255)) for c in rgb])
        return np.array(out, dtype=np.uint8)
    stops = np.array(PALETTE_STOPS[name], dtype=float)
    pos = rat * (len(stops) - 1)
    i = np.minimum(np.floor(pos).astype(int), len(stops) - 2)
    f = (pos - i)[:, None]
    return np.round(stops[i] * (1 - f) + stops[i + 1] * f).astype(np.uint8)


def magnitudes_db(path):
    data, sr = soundfile.read(path, dtype='float32', always_2d=True)
    audio = data[:, 0]
    n_fft = 1 << int(np.ceil(np.log2(2 * (HEIGHT - 1) * sr / (2 * MAX_HZ))))
    rows = int(round(MAX_HZ / (sr / n_fft))) + 1
    window = np.hanning(n_fft).astype('float32')
    hop = max(1, (len(audio) - n_fft) // (WIDTH - 1)) if len(audio) > n_fft else 1
    starts = np.arange(WIDTH) * hop
    audio = np.pad(audio, (0, max(0, starts[-1] + n_fft - len(audio))))
    frames = np.stack([audio[s:s + n_fft] for s in starts]) * window
    spec = np.abs(np.fft.rfft(frames, axis=1))[:, :rows].T
    # full-scale sine = 0 dBFS
    db = 20 * np.log10(np.maximum(spec / (window.sum() / 2), 1e-12))
    # one image row per output pixel (0 Hz at the bottom)
    idx = np.linspace(0, rows - 1, HEIGHT).round().astype(int)
    return db[idx][::-1], len(data) / sr


def render(path, out, title='', raw=False, comment=''):
    conf = get_settings()
    floor = _float(conf, 'SPECTROGRAM_FLOOR_DB', -100, -120, -40)
    rng = _float(conf, 'SPECTROGRAM_RANGE_DB', 70, 30, 120)
    gamma = _float(conf, 'SPECTROGRAM_CONTRAST', 1, 0.2, 3)
    lut = palette_lut((conf.get('SPECTROGRAM_PALETTE') or 'birdnet').strip(), gamma)

    db, seconds = magnitudes_db(path)
    top = min(0, floor + rng)
    level = np.clip((db - floor) / (top - floor), 0, 1)
    picture = Image.fromarray(lut[(level * 255).astype(np.uint8)])
    if raw:
        picture.save(out)
        return

    img = Image.new('RGB', (LEFT + WIDTH + RIGHT, TOP + HEIGHT + BOTTOM), (0, 0, 0))
    img.paste(picture, (LEFT, TOP))
    draw = ImageDraw.Draw(img)
    try:
        font = ImageFont.truetype('DejaVuSans.ttf', 11)
        title_font = ImageFont.truetype('DejaVuSans.ttf', 13)
    except OSError:
        font = title_font = ImageFont.load_default()
    grey = (200, 200, 200)
    draw.rectangle([LEFT - 1, TOP - 1, LEFT + WIDTH, TOP + HEIGHT], outline=grey)
    if title:
        draw.text((LEFT + WIDTH / 2, 8), title, fill=(255, 255, 255), font=title_font, anchor='mt')
    for khz in range(0, MAX_HZ // 1000 + 1):
        y = TOP + HEIGHT - 1 - khz * 1000 * (HEIGHT - 1) / MAX_HZ
        draw.line([LEFT - 5, y, LEFT - 1, y], fill=grey)
        draw.text((LEFT - 8, y), 'DC' if khz == 0 else str(khz), fill=grey, font=font, anchor='rm')
    draw.text((14, TOP + HEIGHT / 2), 'kHz', fill=grey, font=font, anchor='mm')
    for s in range(0, int(seconds) + 1):
        x = LEFT + s * (WIDTH - 1) / max(seconds, 1e-6)
        draw.line([x, TOP + HEIGHT, x, TOP + HEIGHT + 4], fill=grey)
        draw.text((x, TOP + HEIGHT + 7), str(s), fill=grey, font=font, anchor='mt')
    draw.text((LEFT + WIDTH / 2, TOP + HEIGHT + 24), 'Time (s)', fill=grey, font=font, anchor='mt')
    # dBFS colour bar
    bx, bw = LEFT + WIDTH + 28, 14
    bar = Image.fromarray(np.repeat(lut[::-1][:, None, :], bw, axis=1)).resize((bw, HEIGHT))
    img.paste(bar, (bx, TOP))
    draw.rectangle([bx - 1, TOP - 1, bx + bw, TOP + HEIGHT], outline=grey)
    for i in range(6):
        value = top - i * (top - floor) / 5
        y = TOP + i * (HEIGHT - 1) / 5
        draw.text((bx + bw + 6, y), f'{value:.0f}', fill=grey, font=font, anchor='lm')
    draw.text((bx + bw / 2, TOP + HEIGHT + 7), 'dBFS', fill=grey, font=font, anchor='mt')
    if comment:
        draw.text((2, img.size[1] - 2), comment, fill=grey, font=font, anchor='lb')
    out = os.path.realpath(out)  # Extracted/spectrogram.png is a link into StreamData: keep the link
    tmp = out + '.tmp.png'
    img.save(tmp)
    os.replace(tmp, out)


if __name__ == '__main__':
    args = [a for a in sys.argv[1:] if a != '--raw']
    if len(args) < 2:
        print(__doc__, file=sys.stderr)
        sys.exit(2)
    render(args[0], args[1], args[2] if len(args) > 2 else '', '--raw' in sys.argv)
