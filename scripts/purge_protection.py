"""Automatic purge protection: rewrites the automatic part (##start .. ##end) of disk_check_exclude.txt.

Protected automatically, so the disk purge (disk_check.sh) and the per-species trim (disk_species_clean.sh)
never delete them:
  - the PURGE_PROTECT_TOP_N best detections (highest confidence) of every species (default 3, minimum 1 —
    upstream protected only the best one, and only when somebody opened the Best Detections page);
  - every detection reviewed "Yes, this bird" (detection_reviews, Review loop).
Rejected detections ("Not this bird") are never chosen as a species' best ones.
The manual locks (the padlock on a detection card) live after ##end and are kept as they are.

The lock file is shared with the purge scripts (flock), so the list is never rewritten while a purge reads it.
Exit code 0 = list refreshed; anything else = the purge must not run (fail closed).
Usage: purge_protection.py [--print]
"""
import fcntl
import os
import sqlite3
import sys

HOME = os.path.expanduser('~')
SCRIPTS = os.path.join(HOME, 'BirdNET-Pi', 'scripts')
DB = os.path.join(SCRIPTS, 'birds.db')
LIST = os.path.join(SCRIPTS, 'disk_check_exclude.txt')
LOCK = '/tmp/birdnet_purge.lock'


def conf_value(key, default):
    try:
        with open('/etc/birdnet/birdnet.conf') as f:
            for line in f:
                if line.startswith(key + '='):
                    return line.split('=', 1)[1].strip().strip('"')
    except OSError:
        pass
    return default


def folder(com_name):
    # the same folder name the extraction and the Best Detections page use
    return com_name.replace(' ', '_').replace("'", '')


def protected_files(top_n):
    con = sqlite3.connect(f'file:{DB}?mode=ro', uri=True, timeout=30)
    has_reviews = con.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='detection_reviews'").fetchone()
    rejected = "AND File_Name NOT IN (SELECT File_Name FROM detection_reviews WHERE Verdict = 'no')" if has_reviews else ''
    rows = con.execute(f"""
        SELECT Date, Com_Name, File_Name FROM (
          SELECT Date, Com_Name, File_Name,
                 ROW_NUMBER() OVER (PARTITION BY Sci_Name ORDER BY Confidence DESC, Date DESC, Time DESC) AS rank
          FROM detections WHERE 1 {rejected})
        WHERE rank <= ?""", (top_n,)).fetchall()
    if has_reviews:
        rows += con.execute("SELECT d.Date, d.Com_Name, d.File_Name FROM detection_reviews r "
                            "JOIN detections d ON d.File_Name = r.File_Name WHERE r.Verdict = 'yes'").fetchall()
    con.close()
    out = []
    for date, com_name, file_name in rows:
        path = f'{date}/{folder(com_name)}/{file_name}'
        out += [path, path + '.png']
    return list(dict.fromkeys(out))


def main():
    top_n = max(1, int(conf_value('PURGE_PROTECT_TOP_N', '3') or 3))
    with open(LOCK, 'a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        auto = protected_files(top_n)
        manual = []
        if os.path.isfile(LIST):
            with open(LIST) as f:
                text = f.read()
            manual = text[text.index('##end') + len('##end'):].strip('\n').splitlines() if '##end' in text else \
                [line for line in text.splitlines() if line and not line.startswith('##')]
        tmp = LIST + '.tmp'
        with open(tmp, 'w') as f:
            f.write('##start\n' + '\n'.join(auto) + '\n##end\n' + ''.join(line + '\n' for line in manual if line))
        os.replace(tmp, LIST)
    if '--print' in sys.argv:
        print(f'{len(auto) // 2} detections protected automatically (top {top_n} per species + confirmed), '
              f'{len([m for m in manual if m and not m.endswith(".png")])} manual locks')


if __name__ == '__main__':
    main()
