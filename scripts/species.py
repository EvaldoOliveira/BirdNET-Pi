import argparse
import datetime
import os

from utils.classes import week48
from utils.helpers import get_settings, get_language, MODEL_PATH
from utils.models import BirdNETPlusV3, GeoModelV3, MDataModel1, MDataModel2, get_model

# models whose location filter is the separate meta model (utils/models.get_meta_model)
META_MODEL_USERS = ['BirdNET_GLOBAL_6K_V2.4_Model_FP16', 'BirdNET-Go_classifier_20250916']

if __name__ == '__main__':
    parser = argparse.ArgumentParser(
        description='Get list of species for a given location with BirdNET. Sorted by occurrence frequency.'
    )
    parser.add_argument('--threshold', type=float, default=None,
                        help='Occurrence frequency threshold. Defaults to the station location threshold (SF_THRESH).')
    args = parser.parse_args()

    conf = get_settings()
    lat = conf.getfloat('LATITUDE')
    lon = conf.getfloat('LONGITUDE')
    # same week number the analysis hands to the location filter (utils/classes.py week48)
    week = week48(datetime.datetime.today())
    threshold = conf.getfloat('SF_THRESH') if args.threshold is None else args.threshold

    if conf['MODEL'] == BirdNETPlusV3.model_name:
        # The V3 model filters by location with its own geo model: list exactly what the analysis uses
        print(f'Getting species list for {lat}/{lon}, Week {week}, {BirdNETPlusV3.model_name} geo model, '
              f'threshold {threshold}...', flush=True)
        names = get_language(conf['DATABASE_LANG'])
        if not os.path.exists(os.path.join(MODEL_PATH, f'{BirdNETPlusV3.model_name}_Geo_Labels.txt')):
            get_model()  # the model files arrive on first use: fetch them like the analysis does
        geo_names = {}
        with open(os.path.join(MODEL_PATH, f'{BirdNETPlusV3.model_name}_Geo_Labels.txt'), encoding='utf-8') as f:
            for line in f:
                fields = line.rstrip('\n').split('\t')
                if len(fields) >= 3:
                    geo_names[fields[1]] = fields[2]
        model = GeoModelV3(BirdNETPlusV3.model_name, threshold)
        model.set_meta_data(lat, lon, week)
        species_list = [(score, f'{sci_name}_{names.get(sci_name, geo_names.get(sci_name, sci_name))}')
                        for score, sci_name in model.get_species_list_details()]
    elif conf['MODEL'] in META_MODEL_USERS:
        # V2.4 family: the separate meta model (the same choice as utils/models.get_meta_model)
        print(f'Getting species list for {lat}/{lon}, Week {week}, {conf["MODEL"]} meta model '
              f'v{conf.getint("DATA_MODEL_VERSION")}, threshold {threshold}...', flush=True)
        labels_path = os.path.join(MODEL_PATH, 'labels.txt')
        with open(labels_path, 'r') as lfile:
            labels = [line.strip() for line in lfile]

        model = MDataModel1(threshold) if conf.getint('DATA_MODEL_VERSION') == 1 else MDataModel2(threshold)
        model.set_meta_data(lat, lon, week)
        species_list = model.get_species_list_details(labels)
    else:
        print(f'{conf["MODEL"]} has no location filter: every species of the model can be detected.')
        raise SystemExit(0)

    for species in species_list:
        print(f'{species[1]} - {species[0]:.4f}')

    print(f"""
{len(species_list)} species. The above species list describes all the species that the model will attempt to detect.
If you don't see a species you want detected on this list, decrease your threshold
(species in the whitelist are detected whatever their place on this list).

NOTE: no actual changes to your BirdnetPi++ species list were made by running this command.
To set your desired frequency threshold, do it through the BirdnetPi++ web interface (Tools -> Settings -> Model)
""")
