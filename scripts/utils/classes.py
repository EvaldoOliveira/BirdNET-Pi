import datetime
import os
import re

from tzlocal import get_localzone


class Detection:
    def __init__(self, file_date, start_time, stop_time, scientific_name, common_name, confidence):
        self.start = float(start_time)
        self.stop = float(stop_time)
        self.datetime = file_date + datetime.timedelta(seconds=self.start)
        self.date = self.datetime.strftime("%Y-%m-%d")
        self.time = self.datetime.strftime("%H:%M:%S")
        # colon-free variant for FILE NAMES (colons are illegal on SMB/Windows
        # and get mangled by CIFS); the DB keeps the standard %H:%M:%S above
        self.time_safe = self.datetime.strftime("%Hh%Mm%Ss")
        self.iso8601 = self.datetime.astimezone(get_localzone()).isoformat()
        self.week = self.datetime.isocalendar()[1]
        self.confidence = round(float(confidence), 4)
        self.confidence_pct = round(self.confidence * 100)
        self.species = scientific_name
        self.scientific_name = scientific_name
        self.common_name = common_name
        self.common_name_safe = self.common_name.replace("'", "").replace(" ", "_")
        self.file_name_extr = None

    def __str__(self):
        return f'Detection({self.species}, {self.common_name}, {self.confidence}, {self.iso8601})'


class ParseFileName:
    def __init__(self, file_name):
        self.file_name = file_name
        name = os.path.splitext(os.path.basename(file_name))[0]
        date_created = re.search('^[0-9]+-[0-9]+-[0-9]+', name).group()
        # accepts both 14:13:09 (legacy) and 14h13m09s (colon-free) names,
        # anywhere in the name (new format: <date>_<time>-birdnet)
        time_created = re.search('[0-9]+h[0-9]+m[0-9]+s|[0-9]+:[0-9]+:[0-9]+', name).group()
        time_norm = re.sub('[hm]', ':', time_created).rstrip('s')
        self.file_date = datetime.datetime.strptime(f'{date_created}T{time_norm}', "%Y-%m-%dT%H:%M:%S")
        self.root = name

        ident_match = re.search("RTSP_[0-9]+-", file_name)
        self.RTSP_id = ident_match.group() if ident_match is not None else ""

    @property
    def iso8601(self):
        current_iso8601 = self.file_date.astimezone(get_localzone()).isoformat()
        return current_iso8601

    @property
    def week(self):
        week = self.file_date.isocalendar()[1]
        return week
