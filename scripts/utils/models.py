import csv
import json
import logging
import math
import operator
import os

import numpy as np
import requests

from .helpers import get_settings, get_model_labels, MODEL_PATH

os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3'
os.environ['CUDA_VISIBLE_DEVICES'] = ''
np.set_printoptions(legacy="1.21")

try:
    import tflite_runtime.interpreter as tflite
except ImportError:
    from tensorflow import lite as tflite

try:
    import onnxruntime as ort
except ImportError:
    ort = None

log = logging.getLogger(__name__)


def download_file(url, file_path):
    tmp_file = f"{file_path}_tmp"
    session = requests.Session()
    response = session.get(url, stream=True, timeout=60)
    response.raise_for_status()
    block_size = 1024 * 1024

    log.info('Downloading: %s', os.path.basename(file_path))
    try:
        with open(tmp_file, "wb") as outfile:
            for data in response.iter_content(block_size):
                outfile.write(data)
    except (requests.exceptions.RequestException, OSError) as e:
        if os.path.exists(tmp_file):
            os.unlink(tmp_file)
        raise e

    os.rename(tmp_file, file_path)


def get_model(model=None):
    conf = get_settings()
    if model is None:
        model = conf['MODEL']

    if model == 'BirdNET_6K_GLOBAL_MODEL':
        return BirdNetV1(conf.getfloat('SENSITIVITY'))
    elif model == 'BirdNET_GLOBAL_6K_V2.4_Model_FP16':
        return BirdNetV2_4(conf.getfloat('SENSITIVITY'))
    elif model == 'Perch_v2':
        return Perch()
    elif model == 'BirdNET-Go_classifier_20250916':
        return BirdNETGo20250916(conf.getfloat('SENSITIVITY'))
    elif model == BirdNETPlusV3.model_name:
        return BirdNETPlusV3(conf.getfloat('SENSITIVITY'), conf.getfloat('SF_THRESH'))


def get_meta_model(model=None, version=None):
    conf = get_settings()
    if model is None:
        model = conf['MODEL']
    if version is None:
        version = conf.getint('DATA_MODEL_VERSION')

    if model not in ['BirdNET_GLOBAL_6K_V2.4_Model_FP16', 'BirdNET-Go_classifier_20250916']:
        return None

    if version == 1:
        return MDataModel1(conf.getfloat('SF_THRESH'))
    elif version == 2:
        return MDataModel2(conf.getfloat('SF_THRESH'))


class Basemodel:
    chunk_duration = None
    sample_rate = None
    model_name = None
    _input_layer = 0
    _output_layer = 0

    def __init__(self):
        model_path = os.path.join(MODEL_PATH, f'{self.model_name}.tflite')
        self.interpreter = tflite.Interpreter(model_path)
        self.interpreter.allocate_tensors()
        input_details = self.interpreter.get_input_details()
        output_details = self.interpreter.get_output_details()

        self._input_layer_idx = input_details[self._input_layer]['index']
        self._output_layer_idx = output_details[self._output_layer]['index']

        self.labels = get_model_labels(self.model_name)

    def label(self, logits):
        p_labels = dict(zip(self.labels, logits))
        return sorted(p_labels.items(), key=operator.itemgetter(1), reverse=True)

    def predict(self, chunk):
        raise NotImplementedError

    def set_meta_data(self, lat, lon, week):
        pass

    def get_species_list(self):
        return []


class BirdNet(Basemodel):
    chunk_duration = 3
    sample_rate = 48000

    def __init__(self, sens):
        super().__init__()

        self._mdata_model = self._set_meta_model()

        self._sensitivity = max(0.5, min(1.0 - (sens - 1.0), 1.5))

    def scale(self, logits):
        return 1 / (1.0 + np.exp(-self._sensitivity * logits))

    def _set_meta_model(self):
        return None


class BirdNetV1(BirdNet):
    model_name = 'BirdNET_6K_GLOBAL_MODEL'

    def __init__(self, sens):
        super().__init__(sens)
        self._mdata = None
        self._mdata_params = None

    def _set_meta_model(self):
        input_details = self.interpreter.get_input_details()
        return input_details[1]['index']

    def predict(self, chunk):
        self.interpreter.set_tensor(self._input_layer_idx, np.array(chunk, dtype='float32')[np.newaxis, :])
        self.interpreter.set_tensor(self._mdata_model, np.array(self._mdata, dtype='float32'))

        self.interpreter.invoke()
        logits = self.interpreter.get_tensor(self._output_layer_idx)[0]

        return self.label(self.scale(logits))

    def _convert_metadata(self, m):
        # Convert week to cosine
        if 1 <= m[2] <= 48:
            m[2] = math.cos(math.radians(m[2] * 7.5)) + 1
        else:
            m[2] = -1

        # Add binary mask
        mask = np.ones((3,))
        if m[0] == -1 or m[1] == -1:
            mask = np.zeros((3,))
        if m[2] == -1:
            mask[2] = 0.0

        return np.concatenate([m, mask])

    def set_meta_data(self, lat, lon, week):
        if self._mdata_params != [lat, lon, week]:
            self._mdata_params = [lat, lon, week]
            # Convert and prepare metadata
            mdata = self._convert_metadata(np.array([lat, lon, week]))
            self._mdata = np.expand_dims(mdata, 0)


class BirdNetV2_4(BirdNet):
    model_name = 'BirdNET_GLOBAL_6K_V2.4_Model_FP16'

    def _set_meta_model(self):
        # ask for the range model of THIS model: as a shadow model it is not the MODEL of birdnet.conf
        return get_meta_model(self.model_name)

    def predict(self, chunk):
        self.interpreter.set_tensor(self._input_layer_idx, np.array(chunk, dtype='float32')[np.newaxis, :])

        self.interpreter.invoke()
        logits = self.interpreter.get_tensor(self._output_layer_idx)[0]

        return self.label(self.scale(logits))

    def set_meta_data(self, lat, lon, week):
        self._mdata_model.set_meta_data(lat, lon, week)

    def get_species_list(self):
        return self._mdata_model.get_species_list(self.labels)


class Perch(Basemodel):
    chunk_duration = 5
    sample_rate = 32000
    model_name = 'Perch_v2'
    _output_layer = 3

    def predict(self, chunk):
        self.interpreter.set_tensor(self._input_layer_idx, np.array(chunk, dtype='float32')[np.newaxis, :])

        self.interpreter.invoke()
        logits = self.interpreter.get_tensor(self._output_layer_idx)[0]

        exp_x = np.exp(logits - np.max(logits))  # Stabilizing to prevent overflow
        return self.label(exp_x / np.sum(exp_x))


class BirdNETGo20250916(BirdNetV2_4):
    model_name = 'BirdNET-Go_classifier_20250916'


class OnnxBasemodel(Basemodel):
    """Base for models that ship as ONNX and run on ONNX Runtime instead of TFLite."""
    _files = {}
    _base_url = None

    def __init__(self):
        if ort is None:
            raise RuntimeError(f'{self.model_name} needs ONNX Runtime: install it into the BirdNET-Pi venv '
                               f'(~/BirdNET-Pi/birdnet/bin/pip install onnxruntime)')
        self.model_path = os.path.join(MODEL_PATH, f'{self.model_name}.onnx')
        self.ensure_model()
        self.session = self._session(self.model_path)
        self.labels = get_model_labels(self.model_name)

    @staticmethod
    def _session(model_path):
        options = ort.SessionOptions()
        # two threads keep a Raspberry Pi 4 ahead of real time and leave room for the rest of the station
        options.intra_op_num_threads = 2
        return ort.InferenceSession(model_path, options, providers=['CPUExecutionProvider'])

    def ensure_model(self):
        for local, remote in self._files.items():
            file_path = os.path.join(MODEL_PATH, local)
            if not os.path.exists(file_path):
                download_file(f'{self._base_url}/{requests.utils.quote(remote)}', file_path)


class BirdNETPlusV3(OnnxBasemodel):
    """BirdNET+ V3.0 developer preview: the model pair of the BirdNET Live app.

    Raw 32 kHz audio in, sigmoid scores out (birds, amphibians, mammals and insects). The preview fires on
    non-target sound, so it is always combined with its own geo model and with the per-species minimum scores
    the app ships. It has no human class yet: the privacy filter has nothing to match with this model.
    """
    chunk_duration = 3
    sample_rate = 32000
    model_name = 'BirdNET-Plus_V3.0-preview3.1_Global_10K'
    _base_url = 'https://media.githubusercontent.com/media/birdnet-team/birdnet-live-app/main/assets/models'
    _raw_url = 'https://raw.githubusercontent.com/birdnet-team/birdnet-live-app/main/assets/models'
    _files = {
        f'{model_name}.onnx': 'BirdNET+_V3.0-preview3.1_Global_10K-pruned_FP16.onnx',
        f'{model_name}_Geo.onnx': 'BirdNET+_Geomodel_V3.0.4_Global_10K-pruned_FP16.onnx',
    }
    _text_files = {
        f'{model_name}_Labels.csv': 'BirdNET+_V3.0-preview3.1_Global_10K-pruned_Labels.csv',
        f'{model_name}_Geo_Labels.txt': 'BirdNET+_Geomodel_V3.0.4_Global_10K-pruned_Labels.txt',
        f'{model_name}_ScoreBlacklist.json': 'BirdNET+_V3.0-preview3.1_Global_10K-pruned_ScoreBlacklist.json',
    }

    def __init__(self, sens, sf_thresh):
        super().__init__()
        self._sensitivity = max(0.5, min(1.0 - (sens - 1.0), 1.5))
        self._geo_model = GeoModelV3(self.model_name, sf_thresh)
        self.common_names, self._min_score = self._load_names_and_minimums()

    def ensure_model(self):
        super().ensure_model()
        for local, remote in self._text_files.items():
            file_path = os.path.join(MODEL_PATH, local)
            if not os.path.exists(file_path):
                download_file(f'{self._raw_url}/{requests.utils.quote(remote)}', file_path)
        labels_file = os.path.join(MODEL_PATH, f'{self.model_name}_Labels.txt')
        if not os.path.exists(labels_file):
            # same "Scientific name_Common name" lines as every other model, in model output order
            with open(os.path.join(MODEL_PATH, f'{self.model_name}_Labels.csv'), encoding='utf-8-sig') as f:
                rows = sorted(csv.DictReader(f, delimiter=';'), key=lambda row: int(row['idx']))
            with open(labels_file, 'w', encoding='utf-8') as f:
                f.writelines(f"{row['sci_name']}_{row['com_name']}\n" for row in rows)

    def _load_names_and_minimums(self):
        with open(os.path.join(MODEL_PATH, f'{self.model_name}_Labels.txt'), encoding='utf-8') as f:
            pairs = [line.strip().split('_', 1) for line in f if line.strip()]
        common_names = {pair[0]: pair[-1] for pair in pairs}
        with open(os.path.join(MODEL_PATH, f'{self.model_name}_ScoreBlacklist.json'), encoding='utf-8') as f:
            by_common_name = json.load(f)
        min_score = np.zeros(len(self.labels), dtype='float32')
        for idx, label in enumerate(self.labels):
            min_score[idx] = by_common_name.get(common_names.get(label), 0.0)
        return common_names, min_score

    def scale(self, scores):
        # the model already applies a sigmoid: go back to logits to honour the station sensitivity
        if self._sensitivity == 1.0:
            return scores
        scores = np.clip(scores, 1e-7, 1 - 1e-7)
        return 1 / (1.0 + np.exp(-self._sensitivity * np.log(scores / (1 - scores))))

    def predict(self, chunk):
        scores = self.session.run(['predictions'], {'input': np.array(chunk, dtype='float32')[np.newaxis, :]})[0][0]
        scores = np.where(scores >= self._min_score, scores, 0.0)
        return self.label(self.scale(scores))

    def set_meta_data(self, lat, lon, week):
        self._geo_model.set_meta_data(lat, lon, week)

    def get_species_list(self):
        return self._geo_model.get_species_list(self.labels)


class GeoModelV3:
    """Location filter of BirdNET+ V3.0: input [lat, lon, week 1-48], one probability per species."""

    def __init__(self, model_name, sf_thresh):
        self.session = OnnxBasemodel._session(os.path.join(MODEL_PATH, f'{model_name}_Geo.onnx'))
        with open(os.path.join(MODEL_PATH, f'{model_name}_Geo_Labels.txt'), encoding='utf-8') as f:
            # "id<TAB>Scientific name<TAB>Common name", in geo model output order (not the audio model order)
            self.labels = [line.rstrip('\n').split('\t')[1] for line in f if line.strip()]
        self._sf_thresh = sf_thresh
        self._mdata_params = None
        self._mdata = None

    def set_meta_data(self, lat, lon, week):
        if self._mdata_params != (lat, lon, week):
            self._mdata = None
        self._mdata_params = (lat, lon, week)

    def get_species_list_details(self, labels=None):
        if self._mdata is None:
            lat, lon, week = self._mdata_params
            sample = np.array([[lat, lon, week]], dtype='float32')
            l_filter = self.session.run(['probabilities'], {'input': sample})[0][0]
            l_filter = sorted(zip(l_filter, self.labels), key=lambda x: x[0], reverse=True)
            self._mdata = [s for s in l_filter if s[0] >= self._sf_thresh]
        return self._mdata

    def get_species_list(self, labels=None):
        return [s[1] for s in self.get_species_list_details(labels)]


class MDataModel:
    model_name = None

    def __init__(self, sf_thresh):
        model_path = os.path.join(MODEL_PATH, f'{self.model_name}.tflite')
        self.interpreter = tflite.Interpreter(model_path)
        self.interpreter.allocate_tensors()
        input_details = self.interpreter.get_input_details()
        output_details = self.interpreter.get_output_details()

        self._input_layer_idx = input_details[0]['index']
        self._output_layer_idx = output_details[0]['index']
        self._sf_thresh = sf_thresh

        self._mdata_params = None
        self._mdata = None

    def set_meta_data(self, lat, lon, week):
        if self._mdata_params != (lat, lon, week):
            self._mdata = None
        self._mdata_params = (lat, lon, week)

    def get_species_list_details(self, labels):
        if self._mdata is None:
            lat, lon, week = self._mdata_params
            sample = np.expand_dims(np.array([lat, lon, week], dtype='float32'), 0)

            # Run inference
            self.interpreter.set_tensor(self._input_layer_idx, sample)
            self.interpreter.invoke()

            l_filter = self.interpreter.get_tensor(self._output_layer_idx)[0]

            # Apply threshold
            l_filter = np.where(l_filter >= float(self._sf_thresh), l_filter, 0)

            # Zip with labels
            l_filter = list(zip(l_filter, labels))

            # Sort by filter value
            l_filter = sorted(l_filter, key=lambda x: x[0], reverse=True)

            self._mdata = [s for s in l_filter if s[0] >= self._sf_thresh]

        return self._mdata

    def get_species_list(self, labels):
        l_filter = self.get_species_list_details(labels)
        return [s[1].split('_')[0] for s in l_filter]


class MDataModel1(MDataModel):
    model_name = 'BirdNET_GLOBAL_6K_V2.4_MData_Model_FP16'


class MDataModel2(MDataModel):
    model_name = 'BirdNET_GLOBAL_6K_V2.4_MData_Model_V2_FP16'
